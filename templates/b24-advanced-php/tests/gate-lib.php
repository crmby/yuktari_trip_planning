<?php
declare(strict_types=1);
/**
 * Предикаты гейта «пользуйся готовым» — одни и те же и для проверки проекта
 * (uses_template_test.php), и для проверки САМОГО ГЕЙТА (gate_test.php).
 *
 * Зачем отдельным файлом. Правило, которое можно обойти, правилом не является.
 * Первое стороннее ревью 03.08.2026 воспроизвело восемь обходов подстрочной
 * версии; повторное — ещё шесть, уже у версии «по структуре»: значение уезжало
 * в переменную, и проверка теряла след. Поэтому здесь не поиск подстрок, а
 * разбор кода: операторы верхнего уровня по порядку и прослеживание, ЧТО лежит
 * в переменной, которую подставили в вызов.
 *
 * Что предикаты по-прежнему не умеют (честно, чтобы на них не рассчитывали
 * больше, чем следует): значение, собранное в другом файле или пришедшее
 * аргументом функции, не раскрывается. Такой случай считается нарушением —
 * «не видно, куда пишем» безопаснее трактовать строго. Исключение нельзя
 * саморазрешить комментарием в business-коде: оно требует подтверждённой правки
 * защищённого гейта и отдельной регрессии.
 */

/**
 * Метка «файл что-то исполняет при загрузке, а имя вызова неизвестно».
 *
 * Возвращается gateTopLevelCalls() наравне с именами. Скобки в PHP-идентификатор
 * не входят, поэтому метка не может совпасть ни с одной функцией и не может быть
 * «разрешена» проектом как знакомое имя.
 */
const GATE_EXECUTION_MARK = '(исполнение)';

/** Токены файла (с кэшем: гейт проходит по каждому файлу несколько раз). */
function gateTokens(string $file): array {
    static $cache = [];
    if (!isset($cache[$file])) $cache[$file] = token_get_all((string)file_get_contents($file));
    return $cache[$file];
}

/** Код файла без комментариев и строковых литералов — по нему нельзя пройти «упоминанием». */
function gateCode(string $file): string {
    $out = '';
    foreach (gateTokens($file) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/**
 * Операторы ВЕРХНЕГО УРОВНЯ файла по порядку.
 *
 * Верхний уровень — то, что реально выполняется при запросе к файлу: тела
 * функций и классов сюда не попадают. Каждый оператор: строка, код и вид
 * (`require`, `declare`, `func`, `const`, `control`, `expr`).
 */
function gateTopStatements(string $file): array {
    $tokens = gateTokens($file);
    $n = count($tokens);
    $out = [];
    $cur = []; $curLine = 0; $depthCurly = 0; $depthRound = 0; $started = false;

    // Виды объявлений считаем по ЗНАЧАЩЕМУ токену, пропустив модификаторы.
    // `final class`, `abstract class`, `readonly class`, `enum` и атрибут `#[…]`
    // объявляют тип ровно так же, как голое `class`, но разбор смотрел на голый
    // токен и объявлял такой оператор выражением: нормальный эндпоинт получал
    // ложный красный «действие до apiBoot», а библиотека с атрибутом переставала
    // быть библиотекой (K4-40, ревью 09.08.2026).
    $declTypes = [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) $declTypes[] = T_ENUM;
    $modifiers = [T_FINAL, T_ABSTRACT];
    if (defined('T_READONLY')) $modifiers[] = T_READONLY;
    $kindOf = static function (array $stmt) use ($declTypes, $modifiers): string {
        $attr = 0;   // глубина блока атрибута: `#[` открывает, `]` закрывает
        foreach ($stmt as $t) {
            $isAttrOpen = is_array($t) && defined('T_ATTRIBUTE') && $t[0] === T_ATTRIBUTE;
            if ($attr > 0) {
                $text = is_array($t) ? $t[1] : $t;
                if ($isAttrOpen || $text === '[') $attr++;
                elseif ($text === ']') $attr--;
                continue;
            }
            if ($isAttrOpen) { $attr = 1; continue; }
            if (is_string($t)) return 'expr';
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if (in_array($t[0], $modifiers, true)) continue;
            if (in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) return 'require';
            if (in_array($t[0], [T_DECLARE, T_NAMESPACE, T_USE], true)) return 'declare';
            if (in_array($t[0], $declTypes, true)) return 'func';
            if ($t[0] === T_CONST) return 'const';
            if (in_array($t[0], [T_IF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_TRY, T_DO], true)) return 'control';
            if ($t[0] === T_STRING && strcasecmp($t[1], 'define') === 0) return 'const';
            return 'expr';
        }
        return 'expr';
    };
    $text = static function (array $stmt): string {
        $s = '';
        foreach ($stmt as $t) $s .= is_array($t) ? $t[1] : $t;
        return trim($s);
    };
    $flush = static function () use (&$cur, &$out, &$curLine, &$started, $kindOf, $text): void {
        if (!$started) return;
        $code = $text($cur);
        if ($code !== '') $out[] = ['line' => $curLine, 'code' => $code, 'kind' => $kindOf($cur)];
        $cur = []; $started = false; $curLine = 0;
    };

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) continue;
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        if (!$started && is_array($t) && $t[0] === T_WHITESPACE) continue;

        if (!$started) { $started = true; $curLine = is_array($t) ? (int)$t[2] : $curLine; }
        $cur[] = $t;

        if (is_string($t)) {
            if ($t === '{') { $depthCurly++; continue; }
            if ($t === '}') {
                $depthCurly--;
                // Закрылось тело функции/класса/условия верхнего уровня — оператор целиком.
                if ($depthCurly === 0 && $depthRound === 0) $flush();
                continue;
            }
            if ($t === '(') { $depthRound++; continue; }
            if ($t === ')') { $depthRound--; continue; }
            if ($t === ';' && $depthCurly === 0 && $depthRound === 0) $flush();
            continue;
        }
        if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $depthCurly++; }
    }
    $flush();
    return $out;
}

/**
 * Закрывающая `}` для тела, открытого токеном $open.
 *
 * Отдельно от gateMatchingToken: в интерполяции строки открывающая скобка
 * приходит токеном `${` (T_DOLLAR_OPEN_CURLY_BRACES), а закрывается обычной `}` —
 * сравнение по тексту закрыло бы на ней чужое тело функции.
 */
function gateBlockClose(array $tokens, int $open): ?int {
    $depth = 0;
    for ($i = $open, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        $text = $tokens[$i]['text'];
        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($text === '}' && --$depth === 0) return $i;
    }
    return null;
}

/**
 * Тела `class`/`interface`/`trait`/`enum` — `[индекс {, индекс }]` по плоским токенам.
 *
 * Внутри тела класса при загрузке файла не выполняется НИЧЕГО: инициализаторы
 * свойств и констант — константные выражения, а тела методов сами по себе не
 * зовутся. Поэтому это «инертная» зона и для разбора вызовов, и для метки
 * исполнения.
 */
function gateClassBodyRanges(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $tokens = gateFlatTokens($file);
    $n = count($tokens);
    $ids = [T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) $ids[] = T_ENUM;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        if (!in_array($tokens[$i]['id'], $ids, true)) continue;
        $prev = gatePrevToken($tokens, $i);
        if ($prev !== null && $tokens[$prev]['text'] === '::') continue;   // Foo::class
        for ($j = $i + 1; $j < $n; $j++) {
            if ($tokens[$j]['text'] === ';') break;
            if ($tokens[$j]['text'] !== '{') continue;
            $close = gateBlockClose($tokens, $j);
            if ($close !== null) $out[] = [$j, $close];
            break;
        }
    }
    return $cache[$file] = $out;
}

/**
 * Блоки атрибутов `#[…]` — `[индекс #[, индекс ]]`.
 *
 * Аргументы атрибута вычисляются только при рефлексии, а не при загрузке файла:
 * `#[Marker(new Foo())]` над объявлением ничего не выполняет.
 */
function gateAttributeRanges(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $out = [];
    if (!defined('T_ATTRIBUTE')) return $cache[$file] = $out;
    $tokens = gateFlatTokens($file);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_ATTRIBUTE) continue;
        $depth = 0;
        for ($j = $i; $j < $n; $j++) {
            $text = $tokens[$j]['text'];
            if ($tokens[$j]['id'] === T_ATTRIBUTE || $text === '[') { $depth++; continue; }
            if ($text !== ']') continue;
            if (--$depth === 0) { $out[] = [$i, $j]; break; }
        }
    }
    return $cache[$file] = $out;
}

/**
 * Тела функций, замыканий и стрелочных функций файла — и то, чем их можно позвать.
 *
 * `name` — глобальное имя (у метода класса его нет: `boot()` на верхнем уровне
 * метод не исполняет), `var` — переменная, в которую положили замыкание,
 * `iife` — замыкание вызвано прямо на месте объявления, `at` — сам токен
 * `function`/`fn` (по нему видно, ГДЕ объявление написано).
 *
 * @return array<int,array{open:int,close:int,name:?string,var:?string,iife:bool,at:int}>
 */
function gateCallableScopes(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $tokens = gateFlatTokens($file);
    $n = count($tokens);

    $classes = gateClassBodyRanges($file);

    $bodyOfFunction = static function (int $i) use ($tokens, $n): array {
        for ($j = $i + 1; $j < $n; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === ';') return [null, null];        // метод интерфейса, без тела
            if ($text !== '{') continue;
            $close = gateBlockClose($tokens, $j);
            return $close === null ? [null, null] : [$j, $close];
        }
        return [null, null];
    };
    // У стрелочной функции тела в скобках нет: оно кончается на `,`/`;` своего
    // уровня либо на чужой закрывающей скобке — как в gatePhp74SyntaxHits.
    $bodyOfArrow = static function (int $i) use ($tokens, $n): array {
        $round = 0; $square = 0; $arrow = null;
        for ($j = $i + 1; $j < $n; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === '(') { $round++; continue; }
            if ($text === ')') { $round--; continue; }
            if ($text === '[') { $square++; continue; }
            if ($text === ']') { $square--; continue; }
            if ($text === ';') break;
            if ($tokens[$j]['id'] === T_DOUBLE_ARROW && $round <= 0 && $square === 0) { $arrow = $j; break; }
        }
        if ($arrow === null) return [null, null];
        $round = 0; $square = 0; $curly = 0;
        for ($j = $arrow + 1; $j < $n; $j++) {
            $text = $tokens[$j]['text']; $id = $tokens[$j]['id'];
            if ($text === '(') { $round++; continue; }
            if ($text === '[') { $square++; continue; }
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $curly++; continue; }
            if ($text === ')' && $round > 0) { $round--; continue; }
            if ($text === ']' && $square > 0) { $square--; continue; }
            if ($text === '}' && $curly > 0) { $curly--; continue; }
            // Запятая и точка с запятой кончают тело ТОЛЬКО на своём уровне.
            // Без учёта вложенности тело обрывалось на первом же вызове с двумя
            // аргументами (`fn() => storeUpdate(FILE, $fn)`) и на любом литерале
            // массива: IIFE переставал опознаваться, и весь его код исчезал из
            // картины исполнения — работа при загрузке становилась невидимой, а
            // apiBoot внутри стрелки терялся (K4-03, ревью 09.08.2026).
            if (($text === ',' || $text === ';') && $round === 0 && $square === 0 && $curly === 0) {
                return [$arrow, $j - 1];
            }
            if (in_array($text, [')', ']', '}'], true)) return [$arrow, $j - 1];
        }
        return [$arrow, $n - 1];
    };

    $scopes = [];
    for ($i = 0; $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        $isArrow = defined('T_FN') && $id === T_FN;
        if ($id !== T_FUNCTION && !$isArrow) continue;
        [$open, $close] = $isArrow ? $bodyOfArrow($i) : $bodyOfFunction($i);
        if ($open === null || $close === null) continue;

        $name = null;
        $after = gateNextToken($tokens, $i);
        while ($after !== null && $tokens[$after]['text'] === '&') $after = gateNextToken($tokens, $after);
        if ($after !== null && $tokens[$after]['id'] === T_STRING) $name = (string)$tokens[$after]['text'];
        foreach ($classes as $range) {
            if ($i > $range[0] && $i < $range[1]) { $name = null; break; }
        }

        $before = gatePrevToken($tokens, $i);
        if ($before !== null && $tokens[$before]['id'] === T_STATIC) $before = gatePrevToken($tokens, $before);
        $var = null;
        if ($before !== null && $tokens[$before]['text'] === '=') {
            $target = gatePrevToken($tokens, $before);
            if ($target !== null && $tokens[$target]['id'] === T_VARIABLE) $var = (string)$tokens[$target]['text'];
        }
        // `(function () { … })()`: одной скобки слева мало. Так же начинается
        // замыкание, отданное аргументом (`array_map(function () { … }, $x)`) —
        // его исполняет чужой код, а не этот файл. Скобка наша, только если
        // слева от неё не имя вызова; сам вызов виден хвостом `)(` за телом.
        $groupParen = $before !== null && $tokens[$before]['text'] === '(';
        if ($groupParen) {
            $host = gatePrevToken($tokens, $before);
            if ($host !== null && (in_array($tokens[$host]['id'], [T_STRING, T_VARIABLE], true)
                || in_array($tokens[$host]['text'], [')', ']'], true))) $groupParen = false;
        }
        $afterBody = gateNextToken($tokens, $close);
        $callParen = $afterBody === null ? null : gateNextToken($tokens, $afterBody);
        $iife = $groupParen
            && $afterBody !== null && $tokens[$afterBody]['text'] === ')'
            && $callParen !== null && $tokens[$callParen]['text'] === '(';

        $scopes[] = ['open' => $open, 'close' => $close, 'name' => $name,
                     'var' => $var, 'iife' => $iife, 'at' => $i];
    }
    return $cache[$file] = $scopes;
}

/**
 * Имена функций, которые файл ФАКТИЧЕСКИ зовёт при обращении к нему.
 *
 * «Всё, что вне тела функции» — неверный признак, и он уже стоил красного
 * прогона. Ранняя защита шаблона живёт в замыканиях: `(static function (): void
 * { … })()` (www/index.php:31, :58) и `$holdIndexInstallation = static function
 * () { … hash_equals(…) … };`, вызванное ниже на верхнем уровне (www/index.php:155,
 * :626). По наивному признаку такой index.php «не аутентифицирует запрос», хотя
 * при каждой загрузке делает ровно это.
 *
 * Поэтому здесь прослеживается ИСПОЛНЕНИЕ: верхний уровень файла выполняется
 * всегда (включая ветки `if` — они часть загрузки), а тело функции — только
 * когда её позвали из уже исполняемого места: замыкание вызвано на месте,
 * замыкание взято из переменной, объявленная рядом функция вызвана по имени.
 * Имя из строкового литерала и имя после `->`/`::` вызовом не считаются.
 *
 * ИМЕНИ у вызова может не быть: вызов по строке (`$fn = 'apiBoot'; $fn();`),
 * метод объекта, статический метод, замыкание из элемента массива, конструктор.
 * Имя такого вызова предикат по-прежнему не выдаёт — иначе `$x->apiBoot()`
 * сошёл бы за единый вход, — но и молчать о нём нельзя: молчание означало
 * «файл при загрузке ничего не делает», и файл, спрятавший всю работу за
 * конструктором, уходил из-под требования apiBoot целиком (K4-02). Поэтому
 * такой случай возвращается меткой GATE_EXECUTION_MARK: «работа есть, имя
 * неизвестно». Колбэк, отданный чужой функции, работой этого файла не считается.
 *
 * @return string[] имена так, как написаны в коде, плюс, возможно,
 *   GATE_EXECUTION_MARK; PHP к регистру имён функций нечувствителен, поэтому
 *   сравнивать их лучше без учёта регистра
 */
function gateTopLevelCalls(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $tokens = gateFlatTokens($file);
    $n = count($tokens);
    $scopes = gateCallableScopes($file);

    // -1 — верхний уровень файла: он исполняется при каждом обращении.
    $scopeAt = static function (int $idx) use ($scopes): int {
        $best = -1; $width = PHP_INT_MAX;
        foreach ($scopes as $id => $scope) {
            if ($idx <= $scope['open'] || $idx >= $scope['close']) continue;
            $size = $scope['close'] - $scope['open'];
            if ($size < $width) { $width = $size; $best = $id; }
        }
        return $best;
    };

    $calls = [];
    for ($i = 0; $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        $isName = $id === T_STRING
            || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED);
        if (!$isName && $id !== T_VARIABLE) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || $tokens[$next]['text'] !== '(') continue;
        $prev = gatePrevToken($tokens, $i);
        // Метод объекта/класса — не эта функция; имя после `function` — объявление.
        if ($prev !== null && in_array($tokens[$prev]['text'], ['->', '?->', '::'], true)) continue;
        if ($prev !== null && $tokens[$prev]['text'] === '\\') $prev = gatePrevToken($tokens, $prev);
        if ($prev !== null && in_array($tokens[$prev]['id'], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) continue;
        $calls[] = [
            'scope' => $scopeAt($i),
            'name'  => $id === T_VARIABLE ? null
                : (string)preg_replace('~^.*\\\\~', '', ltrim((string)$tokens[$i]['text'], '\\')),
            'var'   => $id === T_VARIABLE ? (string)$tokens[$i]['text'] : null,
        ];
    }

    // Работа при загрузке БЕЗ ИМЕНИ: `new X()`, `$fn()`, `$reestr['k']()`,
    // `$obj->m()`, `Foo::m()`. Имя такого вызова предикату неизвестно и в список
    // имён по-прежнему не попадает — иначе `$x->apiBoot()` сошёл бы за единый
    // вход. Но САМ ФАКТ исполнения известен точно, и его метим отдельно: без
    // метки файл, у которого вся работа спрятана за конструктором или методом,
    // показывал только имена канонического забора и объявлял себя «ничего не
    // делающим при загрузке» — apiBoot с него не требовали, а анонимный запрос
    // успевал выполнить мутацию и лишь потом получал 404 (K4-02, ревью 09.08.2026).
    //
    // Инертные зоны, где `new` НЕ выполняется при загрузке: тело класса
    // (инициализаторы — константные выражения), блок атрибута (вычисляется
    // рефлексией) и сигнатура функции (значение параметра по умолчанию).
    $inert = array_merge(gateClassBodyRanges($file), gateAttributeRanges($file));
    foreach ($scopes as $scope) $inert[] = [$scope['at'], $scope['open']];
    $execScopes = [];
    for ($i = 0; $i < $n; $i++) {
        $hit = false;
        if ($tokens[$i]['id'] === T_NEW) {
            $hit = true;
        } elseif ($tokens[$i]['text'] === '(') {
            $prev = gatePrevToken($tokens, $i);
            $prevText = $prev === null ? '' : $tokens[$prev]['text'];
            $before = $prev === null ? null : gatePrevToken($tokens, $prev);
            if ($prev !== null && in_array($tokens[$prev]['id'], [T_STRING, T_VARIABLE], true)
                && $before !== null && in_array($tokens[$before]['text'], ['->', '?->', '::'], true)) {
                $hit = true;                                   // $obj->m(…), Foo::m(…)
            } elseif ($prev !== null && $tokens[$prev]['id'] === T_VARIABLE) {
                $hit = true;                                   // $fn(…)
            } elseif (in_array($prevText, [']', ')'], true)) {
                $hit = true;                                   // $a['k'](…), (…)(…)
            }
        }
        if (!$hit) continue;
        foreach ($inert as $range) {
            if ($i > $range[0] && $i < $range[1]) { $hit = false; break; }
        }
        if ($hit) $execScopes[$scopeAt($i)] = true;
    }

    $executed = [-1 => true];
    do {
        $changed = false;
        foreach ($scopes as $id => $scope) {
            if (!empty($executed[$id])) continue;
            $reached = $scope['iife'] && !empty($executed[$scopeAt($scope['at'])]);
            foreach ($calls as $call) {
                if ($reached) break;
                if (empty($executed[$call['scope']])) continue;
                $match = $call['var'] !== null
                    ? ($scope['var'] !== null && $scope['var'] === $call['var'])
                    : ($scope['name'] !== null && strcasecmp($scope['name'], (string)$call['name']) === 0);
                if ($match) { $reached = true; break; }
            }
            if (!$reached) continue;
            $executed[$id] = true; $changed = true;
        }
    } while ($changed);

    $names = [];
    foreach ($calls as $call) {
        if ($call['name'] === null || empty($executed[$call['scope']])) continue;
        $names[$call['name']] = true;
    }
    foreach (array_keys($execScopes) as $scopeId) {
        if (empty($executed[$scopeId])) continue;
        $names[GATE_EXECUTION_MARK] = true;
        break;
    }
    return $cache[$file] = array_keys($names);
}

/**
 * Единый вход вызван ДО первого действия — а не «где-то в файле».
 *
 * Прежняя проверка требовала только «вызов в основном теле», и её проходил
 * файл, который сперва писал состояние, ходил в сеть и отдавал ответ, а
 * apiBoot() звал последним, да ещё и под `if (...)` без фигурных скобок. Проверка
 * пропуска, роли и частоты после действия защищает ровно ничего.
 *
 * Перед входом разрешено только то, что не имеет побочных эффектов: подключения,
 * declare, объявления функций/констант и installFailureHandler() — он и должен
 * стоять раньше, чтобы поймать падение самого входа.
 */
function gateCallArgumentsAreDeclarative(string $code, string $fn): bool {
    $args = gateCallArgs($code, $fn);
    if ($args === null) return false;
    foreach ($args as $arg) {
        if (!gateExpressionIsDeclarative($arg)) return false;
    }
    return true;
}

/**
 * Кусок кода без побочных эффектов: ни вызова, ни `new`, ни переменной.
 *
 * Один разбор на всех, кому нужно отличить объявление от действия: аргументы
 * apiBoot/define и инициализатор глобальной константы. Раньше правило жило
 * только внутри проверки аргументов, и `const X = new Foo();` — с PHP 8.1
 * законный синтаксис, выполняющий конструктор прямо здесь — проходил как
 * объявление (K4-08, ревью 09.08.2026).
 */
function gateExpressionIsDeclarative(string $code): bool {
    $tokens = token_get_all("<?php " . $code);
    foreach ($tokens as $at => $token) {
        if (!is_array($token)) continue;
        $id = $token[0];
        if (in_array($id, [T_VARIABLE, T_FUNCTION, T_NEW, T_INCLUDE,
            T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_YIELD], true)) return false;
        if (defined('T_FN') && $id === T_FN) return false;
        if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === T_NULLSAFE_OBJECT_OPERATOR) return false;
        if (in_array($id, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) return false;
        if ($id !== T_STRING) continue;
        for ($next = $at + 1, $count = count($tokens); $next < $count; $next++) {
            if (is_array($tokens[$next]) && in_array(
                $tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
            )) continue;
            if ($tokens[$next] === '(') return false;
            break;
        }
    }
    return true;
}

/**
 * Оператор вида `const …` / `define(…)` ничего не выполняет.
 *
 * `define('X', beforeAuth())` сначала выполнит функцию, а `const X = new Foo()`
 * — конструктор: внешне это объявление, по факту работа до входа.
 */
function gateConstStatementIsDeclarative(string $code): bool {
    if (preg_match('~^define\s*\(~i', $code)) {
        return gateCallArgumentsAreDeclarative($code, 'define');
    }
    return gateExpressionIsDeclarative($code);
}

/** До гейта подключаются только статически названные локальные файлы. */
function gateRequireIsDeclarative(string $code): bool {
    if (strpos($code, '://') !== false || strpos($code, "\0") !== false) return false;
    $allowed = [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                T_REQUIRE, T_REQUIRE_ONCE, T_DIR, T_FILE, T_CONSTANT_ENCAPSED_STRING,
                T_LNUMBER];
    foreach (token_get_all("<?php " . $code) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], $allowed, true)) continue;
            if ($token[0] === T_STRING && strcasecmp($token[1], 'dirname') === 0) continue;
            return false;
        }
        if (!in_array($token, ['.', '(', ')', ',', ';'], true)) return false;
    }
    return true;
}

/** Строковая часть статического require-пути, без исполнения выражения. */
function gateRequireLiteralPath(string $code): ?string {
    if (!gateRequireIsDeclarative($code)) return null;
    $path = '';
    foreach (token_get_all("<?php " . $code) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $literal = $token[1];
        if (strlen($literal) < 2) return null;
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        $path .= $quote === "'"
            ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
            : stripcslashes($body);
    }
    return $path === '' ? null : $path;
}

/**
 * Любой include/require project-кода обязан статически вести в `.php`.
 *
 * Иначе PHP можно спрятать в `transport.dat`: webroot-инвентаризация не считает
 * его исходником, но endpoint выполнит содержимое через require и обойдёт все
 * обязательные проверки готовых модулей.
 */
function gateNonCanonicalRequireLines(string $file, array $allowedExactDynamic = []): array {
    $tokens = gateTokens($file); $hits = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array(
            $token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true
        )) continue;
        $line = (int)$token[2]; $code = '';
        for ($j = $i; $j < $n; $j++) {
            $part = $tokens[$j];
            $code .= is_array($part) ? $part[1] : $part;
            if ($part === ';') break;
        }
        $normalized = rtrim((string)preg_replace('~\s+~', ' ', trim($code)), ';');
        if (in_array($normalized, $allowedExactDynamic, true)) continue;
        $path = gateRequireLiteralPath($code);
        if ($path === null || preg_match('~\.php$~i', $path) !== 1) $hits[] = $line;
    }
    return array_values(array_unique($hits));
}

/**
 * env.php исполняется раньше auth, поэтому он не является свободным bootstrap.
 * Разрешаем только нужные шаблону чистые преобразования/чтение конфигурации;
 * сеть, запись, вывод, dynamic dispatch и произвольные helper-вызовы запрещены.
 */
function gateEnvPrivateStateReaderContract(string $file): bool {
    $source = (string)@file_get_contents($file);
    if ($source === '') return false;

    $singletons = [
        '$bxappReadPrivateState = static function (string $file, int $maxBytes, string $label): ?array',
        '@lstat($file)',
        "(int)(\$stat['size'] ?? (\$maxBytes + 1)) > \$maxBytes",
        'file_get_contents($file, false, null, 0, $maxBytes + 1)',
        'strlen($raw) > $maxBytes',
        "<?php http_response_code(404); exit; /* приватный файл bx-app */ ?>\\n",
        "<?php exit; /* private bx-app rollback bridge */ ?>\\n",
        '$readPrivateState(B24_CREDS_FILE, B24_CREDS_MAX_BYTES, \'creds\')',
        '$readPrivateState(STATE_FILE, STATE_MAX_BYTES, \'state\')',
    ];
    foreach ($singletons as $needle) {
        if (substr_count($source, $needle) !== 1) return false;
    }
    // Оба вызова обязаны быть именно двумя перечисленными tuple. Любой третий
    // variable call либо новый target возвращает обычный fail-closed вердикт.
    return substr_count($source, '$readPrivateState(') === 2
        && substr_count($source, 'file_get_contents($file') === 1
        && substr_count($source, 'class StateFileLimitException') === 1;
}

function gateEnvEffectLines(string $file): array {
    $privateStateReader = gateEnvPrivateStateReaderContract($file);
    $envSource = (string)@file_get_contents($file);
    $healthStateFailure = $privateStateReader
        && substr_count($envSource,
            '$categories = [1701 => \'too_large\', 1703 => \'invalid\', 1704 => \'unreadable\', 1705 => \'corrupt\'];') === 1
        && substr_count($envSource,
            '\'error\' => (string)($categories[$e->getCode()] ?? \'invalid\'),') === 1
        && substr_count($envSource, '$e->getCode()') === 1;
    $allowedCalls = array_fill_keys([
        'array_key_exists', 'clearstatcache', 'define', 'defined', 'dirname',
        'file_exists', 'file_get_contents', 'getenv', 'is_array', 'is_file',
        'is_link', 'is_string', 'json_decode', 'json_last_error', 'lstat',
        'preg_replace', 'realpath', 'rtrim', 'strncmp', 'strpos', 'strlen',
        'substr', 'trim',
    ], true);
    $tokens = gateFlatTokens($file); $hits = [];
    $forbidden = [T_ECHO, T_PRINT, T_EXIT, T_EVAL, T_INCLUDE, T_INCLUDE_ONCE,
                  T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_GOTO, T_YIELD];
    if (defined('T_HALT_COMPILER')) $forbidden[] = T_HALT_COMPILER;
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) $forbidden[] = T_NULLSAFE_OBJECT_OPERATOR;

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if ($token['id'] === null && $token['text'] === '`') {
            $hits[] = (int)$token['line'];
            continue;
        }
        if ($token['id'] === T_NEW) {
            $classAt = gateNextToken($tokens, $i);
            if ($classAt === null || $tokens[$classAt]['id'] !== T_STRING
                || $tokens[$classAt]['text'] !== 'StateFileLimitException') {
                $hits[] = (int)$token['line'];
            }
            continue;
        }
        if ($token['id'] === T_OBJECT_OPERATOR && $healthStateFailure) {
            $ownerAt = gatePrevToken($tokens, $i);
            $methodAt = gateNextToken($tokens, $i);
            if ($ownerAt !== null && $methodAt !== null
                && $tokens[$ownerAt]['id'] === T_VARIABLE && $tokens[$ownerAt]['text'] === '$e'
                && $tokens[$methodAt]['id'] === T_STRING && $tokens[$methodAt]['text'] === 'getCode') {
                continue;
            }
        }
        if ($token['id'] !== null && in_array($token['id'], $forbidden, true)) {
            $hits[] = (int)$token['line'];
            continue;
        }
        $next = gateNextToken($tokens, $i);
        if ($next === null || $tokens[$next]['text'] !== '(') continue;
        if ($token['id'] === T_VARIABLE) {
            if ($privateStateReader && $token['text'] === '$readPrivateState') continue;
            $hits[] = (int)$token['line'];
            continue;
        }
        if ($token['id'] !== T_STRING) continue;
        $previous = gatePrevToken($tokens, $i);
        if ($healthStateFailure && $token['text'] === 'getCode' && $previous !== null
            && $tokens[$previous]['id'] === T_OBJECT_OPERATOR) {
            $ownerAt = gatePrevToken($tokens, $previous);
            if ($ownerAt !== null && $tokens[$ownerAt]['id'] === T_VARIABLE
                && $tokens[$ownerAt]['text'] === '$e') continue;
        }
        if ($token['text'] === 'StateFileLimitException' && $previous !== null
            && $tokens[$previous]['id'] === T_NEW) continue;
        if (!isset($allowedCalls[strtolower($token['text'])])) $hits[] = (int)$token['line'];
    }

    // file_get_contents нужен штатному env только для private creds/state.
    // Само имя функции в allowlist не должно разрешать URL wrapper или чтение
    // произвольного пути через промежуточную переменную.
    $vars = gateVarMap($file);
    foreach (gateInstructions($file) as $instruction) {
        if (!preg_match('~(^|[^>\w$])file_get_contents\s*\(~', $instruction['code'])) continue;
        $arg = gateFirstArg($instruction['code'], 'file_get_contents');
        if ($privateStateReader && trim((string)$arg) === '$file'
            && strpos($instruction['code'],
                'file_get_contents($file, false, null, 0, $maxBytes + 1)') !== false) {
            continue;
        }
        $target = $arg === null ? '/*?*/' : gateResolve($arg, $vars);
        if (!preg_match('~\b(?:B24_CREDS_FILE|STATE_FILE|DATA_ROOT)\b|__DIR__~', $target)) {
            $hits[] = (int)$instruction['line'];
        }
    }

    // Единственное подключение env — сгенерированный release.php. Любой иной
    // файл превращает конфиг в раннюю точку исполнения до session/auth-гейта.
    foreach (gateTokens($file) as $i => $token) {
        if (!is_array($token) || !in_array(
            $token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true
        )) continue;
        $code = '';
        for ($j = $i, $n = count(gateTokens($file)); $j < $n; $j++) {
            $part = gateTokens($file)[$j];
            $code .= is_array($part) ? $part[1] : $part;
            if ($part === ';') break;
        }
        $path = gateRequireLiteralPath($code);
        if ($path === null || preg_match('~(?:^|/)release\.php$~i', $path) !== 1) {
            $hits[] = (int)$token[2];
        }
    }
    return array_values(array_unique($hits));
}

function gateBootBeforeEffects(string $file, string $fn = 'apiBoot'): bool {
    // Любой текст вне PHP реально уходит клиенту сразу при include. Он не может
    // стоять ни до auth, ни после него: ответы endpoint отдаёт только apiOk/apiFail.
    if (gateInlineHtmlLines($file) !== []) return false;
    foreach (gateTopStatements($file) as $st) {
        if ($st['kind'] === 'require') {
            if (!gateRequireIsDeclarative($st['code'])) return false;
            continue;
        }
        if ($st['kind'] === 'const') {
            // `define('X', beforeAuth())` сначала выполнит функцию — это действие
            // до apiBoot, несмотря на внешнюю похожесть на константу. С PHP 8.1 то
            // же верно и для голого `const`: `new` в инициализаторе выполняет
            // конструктор прямо здесь, а гейт пропускал такой оператор целиком.
            if (!gateConstStatementIsDeclarative($st['code'])) return false;
            continue;
        }
        if (in_array($st['kind'], ['declare', 'func'], true)) continue;
        if (preg_match('~^installFailureHandler\s*\(~', $st['code'])) {
            if (!gateCallArgumentsAreDeclarative($st['code'], 'installFailureHandler')) return false;
            continue;
        }
        // Первое же действие обязано быть вызовом входа, причём не внутри условия.
        if ($st['kind'] === 'control') return false;
        // Вызов должен быть самим первым действием, а не операндом выражения.
        // `false && apiBoot()` раньше проходил поиск имени, хотя гейт никогда
        // не выполнялся. Допускаем две штатные формы: apiBoot(...) и
        // `$ctx = apiBoot(...)`.
        $shapeOk = (bool)preg_match(
            '~^(?:\$\w+\s*=\s*)?' . preg_quote($fn, '~') . '\s*\(~',
            trim($st['code'])
        );
        if (!$shapeOk) return false;

        // Сам вызов тоже не должен быть контейнером для действия ДО гейта:
        // `apiBoot(['x' => saveSomething()])` сначала выполнит saveSomething(),
        // и лишь затем войдёт в apiBoot(). Опции входа — декларативный литерал:
        // массивы, числа, строки, bool/null и константы; вычисляемые переменные,
        // callbacks, include/new и любые вложенные вызовы считаем нарушением.
        return gateCallArgumentsAreDeclarative($st['code'], $fn);
    }
    return false;   // исполняемого кода нет вовсе — как эндпоинт файл не работает
}

/** Непустой HTML вне PHP — уже отправленный собственный HTTP-ответ. */
function gateInlineHtmlLines(string $file): array {
    $hits = [];
    foreach (gateTokens($file) as $token) {
        if (!is_array($token) || $token[0] !== T_INLINE_HTML || trim($token[1]) === '') continue;
        $hits[] = (int)$token[2];
    }
    return array_values(array_unique($hits));
}

/** Файл-библиотека: на верхнем уровне только объявления и подключения. */
function gateIsLibrary(string $file): bool {
    if (gateInlineHtmlLines($file) !== []) return false;
    foreach (gateTopStatements($file) as $st) {
        if ($st['kind'] === 'require') {
            if (!gateRequireIsDeclarative($st['code'])) return false;
            continue;
        }
        if ($st['kind'] === 'const') {
            // define() и `const` синтаксически похожи на объявление, но значение
            // у обоих — обычное выражение (у `const` с PHP 8.1 в нём разрешён
            // `new`). Вызов или конструктор здесь уже делает файл эндпоинтом и не
            // должен освобождать его от apiBoot/единого формата ответа.
            if (!gateConstStatementIsDeclarative($st['code'])) return false;
            continue;
        }
        if (in_array($st['kind'], ['declare', 'func'], true)) continue;
        return false;
    }
    return true;
}

/** Заведомо ложная ветка — способ «выполнить требование», ничего не выполнив. */
function gateHasDeadBranch(string $file): bool {
    $code = gateCode($file);
    if (preg_match('~\bif\s*\(\s*(false|0|null)\s*\)~i', $code)) return true;

    // Короткое замыкание проверяем по токенам. Подстрочная версия считала
    // мёртвой веткой нормальное `$found !== false && ...` и `$x !== null && ...`:
    // литерал там правая часть СРАВНЕНИЯ, а не заведомо ложный левый операнд.
    $tokens = gateFlatTokens($file);
    $comparisons = ['==', '===', '!=', '!==', '<', '>', '<=', '>=', '<=>'];
    foreach ($tokens as $i => $token) {
        $literal = strtolower(trim($token['text']));
        $wanted = in_array($literal, ['false', '0', 'null'], true) ? '&&'
            : (in_array($literal, ['true', '1'], true) ? '||' : '');
        if ($wanted === '') continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || $tokens[$next]['text'] !== $wanted) continue;
        $prev = gatePrevToken($tokens, $i);
        if ($prev !== null && in_array($tokens[$prev]['text'], $comparisons, true)) continue;
        return true;
    }
    return false;
}

/**
 * Локальные подмены обязательных модулей шаблона.
 *
 * По одному имени вызова невозможно отличить штатный apiBoot/runOnce от
 * объявленной рядом пустышки. Поэтому project-коду запрещено объявлять эти
 * имена и импортировать функции под ними; exact core исключается вызывающим
 * inventory-гейтом, а не редактируемым списком внутри файла.
 */
function gateProtectedHelperShadows(string $file): array {
    $protected = array_fill_keys([
        'apiboot', 'apiok', 'apifail', 'apifailfromthrowable', 'apibody',
        'runonce', 'auditlog', 'b24', 'storeupdate', 'storewrite',
        'masksecrets', 'installfailurehandler', 'applog', 'logapperror',
        'journalappend', 'journaltoday', 'httpjson', 'appisotimestamp',
        'b24day', 'b24range', 'b24today', 'b24portaltz',
        'money', 'moneystr', 'moneyforb24',
    ], true);
    $tokens = gateFlatTokens($file); $hits = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] === T_FUNCTION) {
            $name = gateNextToken($tokens, $i);
            while ($name !== null && $tokens[$name]['text'] === '&') $name = gateNextToken($tokens, $name);
            if ($name !== null && $tokens[$name]['id'] === T_STRING
                && isset($protected[strtolower($tokens[$name]['text'])])) {
                $hits[] = (int)$tokens[$name]['line'];
            }
            continue;
        }
        if ($tokens[$i]['id'] !== T_USE) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || $tokens[$next]['text'] === '(') continue; // closure use(...)
        $hasFunctionImport = false; $names = [];
        for ($j = $next; $j < $n && $tokens[$j]['text'] !== ';'; $j++) {
            if ($tokens[$j]['id'] === T_FUNCTION) $hasFunctionImport = true;
            if (preg_match('~(?:^|\\\\)([a-z_]\w*)$~i', trim($tokens[$j]['text']), $m)
                && isset($protected[strtolower($m[1])])) {
                $names[] = (int)$tokens[$j]['line'];
            }
        }
        if ($hasFunctionImport) array_push($hits, ...$names);
    }
    return array_values(array_unique($hits));
}

/**
 * Сырые примитивы дат/денег в project API.
 *
 * Это намеренно строгий fail-closed забор. У шаблона уже есть fmt.php, поэтому
 * прикладному endpoint не нужны собственные DateTime/strtotime/float/round.
 * Технические часы и журнал тоже завернуты в core-helper'ы. Иначе агенту проще
 * снова написать привычные две строки, чем заметить готовый модуль, а ошибка
 * обнаружится только на границе суток или в копейках.
 */
/**
 * Имя функции, в аргументах которой стоит токен $i (ближайший охватывающий вызов).
 *
 * Через тело вложенной функции НЕ проходим. Иначе `storeUpdate($f, function () {
 * … (float)$сумма … }, 25.0)` выдаёт «storeUpdate» для любого числа внутри
 * колбэка — и послабление для таймаута замка накрывает весь код в его теле,
 * то есть ровно ту прикладную логику, ради проверки которой правило и написано.
 */
function gateEnclosingCallName(array $tokens, int $i): ?string {
    $depth = 0;
    $braces = 0;
    for ($j = $i - 1; $j >= 0; $j--) {
        $text = $tokens[$j]['text'];
        if ($text === '}') { $braces++; continue; }
        if ($text === '{') {
            // Незакрытая фигурная скобка слева — мы внутри блока (тело функции,
            // условие, цикл), а не в списке аргументов вызова.
            if ($braces === 0) return null;
            $braces--;
            continue;
        }
        if ($text === ')') { $depth++; continue; }
        if ($text !== '(') continue;
        if ($depth > 0) { $depth--; continue; }
        // Нашли скобку своего уровня — имя вызова стоит прямо перед ней.
        $name = gatePrevToken($tokens, $j);
        if ($name === null) return null;
        // unset — языковая конструкция, у неё отдельный токен, а не T_STRING.
        // Без этой ветки `unset($fields['сумма'])` между записью и сверкой
        // оставался невидимым: значение вырезали, проверка этого не замечала.
        if (defined('T_UNSET') && $tokens[$name]['id'] === T_UNSET) return 'unset';
        if ($tokens[$name]['id'] !== T_STRING) return null;
        return (string)$tokens[$name]['text'];
    }
    return null;
}

/**
 * Есть ли в аргументах вызова, начинающегося на $i, обращение к функции $needle.
 *
 * @param int|null $onlyArg Ограничить поиск N-м аргументом (1 — первый). Без
 *   ограничения послабление легко обходится: достаточно дописать безобидный
 *   вызов вторым аргументом, чтобы снять проверку с первого.
 */
function gateCallArgsContain(array $tokens, int $i, string $needle, ?int $onlyArg = null): bool {
    $open = gateNextToken($tokens, $i);
    if ($open === null || $tokens[$open]['text'] !== '(') return false;
    $depth = 0;
    $arg = 1;
    for ($j = $open, $n = count($tokens); $j < $n; $j++) {
        $text = $tokens[$j]['text'];
        if ($text === '(') { $depth++; continue; }
        if ($text === ')') { $depth--; if ($depth === 0) return false; continue; }
        if ($text === ',' && $depth === 1) { $arg++; continue; }
        if ($onlyArg !== null && $arg !== $onlyArg) continue;
        if ($tokens[$j]['id'] === T_STRING && strcasecmp((string)$text, $needle) === 0) return true;
    }
    return false;
}

/**
 * Токены, где примитив заведомо служебный, а не доменный.
 *
 * Нужно, чтобы правило «даты и деньги только через fmt.php» можно было применить
 * и к www/bin/process.php — файлу, куда шаблон сам велит писать фоновую логику.
 * Раньше он из проверки просто выпадал, и любой `date()`/`(float)` в прикладном
 * коде фоновой обработки проходил молча. Но в самом каркасе есть три законных
 * случая: таймаут блокировки в секундах (25.0), замер длительности тика через
 * microtime() и ISO-метка времени запуска date('c').
 *
 * Прощается ТОЛЬКО сам служебный токен, а не строка целиком. Построчное
 * послабление было дырой ровно того размера, от которой правило и защищает:
 * достаточно было приписать сумму к строке с любым замком —
 * `storeUpdate($f, fn() => ['сумма' => (float)$raw], 25.0)` — и деньги считались
 * бы сырым float молча. Комментарий-саморазрешение в этом гейте по-прежнему
 * не действует: послабление даёт только смысл кода.
 *
 * @return array<int,bool> индексы токенов, где примитив служебный
 */
function gateServicePrimitiveTokens(array $tokens): array {
    $lockCalls = ['appLifecycleAcquire', 'appWithLifecycleReadLock', 'appWithLifecycleWriteLock',
        'appWithMatchingInstallationReadLock', 'appJournalMutationLockAcquire',
        'appWithJournalMutationLock', 'storeUpdate', 'runOnceLeaseAcquire', 'flock'];
    $excused = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        // Число с точкой прощаем только как аргумент замка: `storeUpdate(..., 25.0)`.
        // Это работает и для многострочного вызова с колбэком — баланс круглых
        // скобок доводит до имени функции, какой бы длинной ни была середина.
        if ($id === T_DNUMBER) {
            $owner = gateEnclosingCallName($tokens, $i);
            if ($owner !== null && in_array($owner, $lockCalls, true)) $excused[$i] = true;
            continue;
        }
        if ($id !== T_STRING) continue;
        $leaf = strtolower((string)$tokens[$i]['text']);
        // Технические часы: date('c'|'r'|'U') — метка времени, не дата Битрикса.
        if ($leaf === 'date') {
            $open = gateNextToken($tokens, $i);
            if ($open === null || $tokens[$open]['text'] !== '(') continue;
            $arg = gateNextToken($tokens, $open);
            // Кавычки любые: `date("c")` — та же техническая метка, что и date('c'),
            // а краснеть на ней значит учить обходить правило, а не соблюдать его.
            if ($arg !== null && $tokens[$arg]['id'] === T_CONSTANT_ENCAPSED_STRING
                && preg_match('~^[\'"](?:c|r|U)[\'"]$~', (string)$tokens[$arg]['text'])) {
                $excused[$i] = true;
            }
            continue;
        }
        // Замер длительности: round(microtime(true) - $started, 3) — секунды, не деньги.
        // microtime обязан быть в ПЕРВОМ аргументе: `round($сумма, microtime(true))`
        // не бывает, а вот спрятать деньги в первый аргумент, приписав microtime
        // вторым, — ровно тот обход, который такое послабление и создаёт.
        if ($leaf === 'round' && gateCallArgsContain($tokens, $i, 'microtime', 1)) $excused[$i] = true;
    }
    return $excused;
}

function gateRawDomainPrimitiveLines(string $file): array {
    $tokens = gateFlatTokens($file); $hits = [];
    $service = gateServicePrimitiveTokens($tokens);
    $calls = array_fill_keys([
        'date', 'gmdate', 'strtotime', 'strftime', 'mktime', 'gmmktime',
        'number_format', 'round', 'floatval', 'doubleval',
    ], true);
    $dateClasses = array_fill_keys(['datetime', 'datetimeimmutable', 'datetimezone'], true);

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        if ($id === T_DNUMBER || (defined('T_DOUBLE_CAST') && $id === T_DOUBLE_CAST)) {
            if (!isset($service[$i])) $hits[] = (int)$tokens[$i]['line'];
            continue;
        }
        if ($id === null) continue;

        $raw = trim($tokens[$i]['text']);
        $leaf = strtolower((string)preg_replace('~^.*\\\\~', '', ltrim($raw, '\\')));
        if ($leaf === 'float' || $leaf === 'double') {
            $hits[] = (int)$tokens[$i]['line'];
            continue;
        }

        $prev = gatePrevToken($tokens, $i);
        $next = gateNextToken($tokens, $i);
        if (isset($dateClasses[$leaf])) {
            if ($prev !== null && ($tokens[$prev]['id'] === T_NEW
                || $tokens[$prev]['text'] === '::')) {
                $hits[] = (int)$tokens[$i]['line'];
            }
            continue;
        }
        if (!isset($calls[$leaf]) || $next === null || $tokens[$next]['text'] !== '(') continue;
        // `$object->date()` и `Clock::date()` — методы, не глобальные PHP-примитивы.
        if ($prev !== null && in_array($tokens[$prev]['text'], ['->', '?->', '::'], true)) continue;
        if ($prev !== null && $tokens[$prev]['id'] === T_FUNCTION) continue;
        if (isset($service[$i])) continue;
        $hits[] = (int)$tokens[$i]['line'];
    }
    return array_values(array_unique($hits));
}

/** Те же опасные преобразования в прикладном JS: сервер должен отдать готовое значение. */
/**
 * Сырые даты и деньги во фронте.
 *
 * $allowMarked — только для файлов ЯДРА фронта (свои контролы, рамка окна): там
 * арифметика неизбежна, но не предметная — это геометрия кнопки и клетка
 * календаря. Раньше такие файлы исключались ЦЕЛИКОМ, то есть настоящая ошибка в
 * датах или деньгах внутри них не нашлась бы никогда. Теперь исключается не файл,
 * а конкретная строка — и только та, рядом с которой написано, почему это не
 * предметная арифметика.
 */
function gateFrontRawDomainPrimitiveLines(string $file, bool $allowMarked = false): array {
    $source = (string)file_get_contents($file);
    $code = gateJsPolicyCode($source);
    // Маркер ищем в ИСХОДНОМ тексте: gateJsPolicyCode вырезает комментарии.
    $rawLines = preg_split('~\r?\n~', $source) ?: [];
    $patterns = [
        '~\bnew\s+Date\s*\(~',
        '~\bDate\s*\.\s*parse\s*\(~',
        '~\b(?:parseFloat|moment|dayjs)\s*\(~',
        '~\.\s*toFixed\s*\(~',
        '~\bIntl\s*\.\s*NumberFormat\s*\(~',
        '~\bMath\s*\.\s*(?:round|ceil|floor)\s*\(~',
        '~(?<![\w$])(?:\d+\.\d+|\d+(?:\.\d+)?[eE][+-]?\d+)(?![\w$])~',
    ];
    $hits = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) === false) continue;
        foreach ($matches[0] as $match) {
            $line = 1 + substr_count(substr($code, 0, (int)$match[1]), "\n");
            if ($allowMarked && gateUiGeometryMarked($rawLines, $line)) continue;
            $hits[] = $line;
        }
    }
    return array_values(array_unique($hits));
}

/**
 * Разрешение по месту: строка (или строка над ней) объясняет, что арифметика
 * относится к интерфейсу, а не к предметной области. Слово-маркер намеренно
 * длинное и осмысленное — случайно его не напишешь, а в обзоре видно сразу.
 */
function gateUiGeometryMarked(array $rawLines, int $line): bool {
    foreach ([$line - 1, $line - 2] as $i) {
        $text = (string)($rawLines[$i] ?? '');
        if (preg_match('~(UI-геометрия|UI-календарь)~u', $text)) return true;
    }
    return false;
}

/** Имя credential целиком либо в последнем camel/snake-компоненте. */
function gateIsCredentialName(string $name): bool {
    $normalized = strtolower((string)preg_replace('~[^a-z0-9]+~i', '', $name));
    $exact = [
        'auth', 'authorization', 'token', 'accesstoken', 'refreshtoken',
        'applicationtoken', 'clientsecret', 'password', 'passwd', 'secret',
        'secretkey', 'credential', 'credentials', 'apikey', 'webhook',
        'webhookurl', 'privatekey', 'signingkey', 'encryptionkey',
    ];
    if (in_array($normalized, $exact, true)) return true;

    // oauthToken/B24_SECRET должны считаться credential, но token_ttl — нет:
    // семантический credential-компонент обязан завершать имя.
    $segmented = (string)preg_replace('~([a-z0-9])([A-Z])~', '$1_$2', $name);
    $parts = preg_split('~[^a-z0-9]+~i', strtolower($segmented), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $baseParts = $parts;
    $environmentSuffixes = [
        'prod', 'production', 'live', 'stage', 'staging', 'dev',
        'development', 'test', 'testing', 'local',
    ];
    while ($baseParts !== [] && in_array((string)end($baseParts), $environmentSuffixes, true)) {
        array_pop($baseParts);
    }
    if (in_array(implode('', $baseParts), $exact, true)) return true;
    // Последний СЕМАНТИЧЕСКИЙ сегмент — из имени БЕЗ суффикса среды. Раньше здесь
    // стоял $parts, то есть после срезания 'prod'/'stage'/'local' сравнивалось само
    // это слово: $B24_SECRET_PROD и $apiTokenProd проходили гейт целиком, хотя
    // $B24_SECRET и $apiToken он ловил. Имя такого вида — не выдумка, а конвенция
    // самого шаблона: в www/env.php слот секрета называется $B24_CLIENT_SECRET_LOCAL.
    $last = $baseParts === [] ? '' : (string)end($baseParts);
    return in_array($last, [
        'auth', 'authorization', 'token', 'secret', 'password', 'passwd',
        'apikey', 'webhook', 'privatekey', 'signingkey', 'encryptionkey',
    ], true);
}

/** Значение обычного PHP string literal без исполнения кода. */
function gatePhpLiteralValue(string $literal): ?string {
    if (strlen($literal) < 2 || !in_array($literal[0], ["'", '"'], true)
        || substr($literal, -1) !== $literal[0]) return null;
    $body = substr($literal, 1, -1);
    return $literal[0] === "'"
        ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
        : stripcslashes($body);
}

/** Literal, который сам выдаёт готовый credential независимо от имени переменной. */
function gateLiteralContainsCredential(string $value): bool {
    if (preg_match('~https?://[^\s/]+/rest/\d+/[A-Za-z0-9._\~-]{6,}(?:/|$)~i', $value)) return true;
    if (preg_match('~\b(?:Bearer|Basic)\s+[A-Za-z0-9._\~+/-]{8,}={0,2}\b~i', $value)) return true;
    return preg_match('~-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----~', $value) === 1;
}

/**
 * Вычислить только полностью статическое scalar-выражение: literal, их
 * конкатенацию либо alias, уже доказанный статическим. Частично динамическое
 * `Bearer . $runtimeToken` не выдаём за hardcode.
 */
function gateStaticLiteralExpression(string $expr, array $known = []): ?string {
    $raw = token_get_all('<?php ' . $expr . ';'); $tokens = [];
    foreach ($raw as $token) {
        if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        if ($token === ';') continue;
        $tokens[] = $token;
    }
    if ($tokens === []) return null;

    // base64_decode/hex2bin над полностью статической строкой — это всё ещё
    // захардкоженное значение, только слегка спрятанное.
    $first = $tokens[0];
    if (is_array($first) && $first[0] === T_STRING
        && in_array(strtolower($first[1]), ['base64_decode', 'hex2bin'], true)
        && ($tokens[1] ?? null) === '(' && ($tokens[count($tokens) - 1] ?? null) === ')') {
        $inner = '';
        for ($i = 2, $n = count($tokens) - 1; $i < $n; $i++) {
            $inner .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }
        $encoded = gateStaticLiteralExpression($inner, $known);
        if ($encoded === null) return null;
        $decoded = strtolower($first[1]) === 'hex2bin'
            ? @hex2bin($encoded) : base64_decode($encoded, true);
        return is_string($decoded) ? $decoded : null;
    }

    $value = ''; $hasPart = false;
    foreach ($tokens as $token) {
        if (is_string($token)) {
            if (in_array($token, ['.', '(', ')'], true)) continue;
            return null;
        }
        $id = $token[0]; $text = $token[1];
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $part = gatePhpLiteralValue($text);
            if ($part === null) return null;
            $value .= $part; $hasPart = true;
            continue;
        }
        if ($id === T_VARIABLE) {
            $name = ltrim($text, '$');
            if (!array_key_exists($name, $known)) return null;
            $value .= (string)$known[$name]; $hasPart = true;
            continue;
        }
        if (defined('T_STRING_CAST') && $id === T_STRING_CAST) continue;
        return null;
    }
    return $hasPart ? $value : null;
}

/** Захардкоженные credentials в PHP, включая alias и array/define/property. */
function gateHardcodedCredentialLines(string $file): array {
    $instructions = gateInstructions($file); $assignments = [];
    foreach ($instructions as $inst) {
        if (preg_match('~(?:^|[;\s])\$(\w+)\s*=\s*(.+)$~s', $inst['code'], $m)) {
            $assignments[] = ['name' => $m[1], 'expr' => $m[2], 'line' => (int)$inst['line']];
        }
    }
    $known = [];
    do {
        $changed = false;
        foreach ($assignments as $assignment) {
            if (array_key_exists($assignment['name'], $known)) continue;
            $value = gateStaticLiteralExpression($assignment['expr'], $known);
            if ($value === null) continue;
            $known[$assignment['name']] = $value; $changed = true;
        }
    } while ($changed);

    // Два слота в www/env.php — ЕДИНСТВЕННОЕ место, куда креды класть штатно
    // разрешено: сам файл не уезжает в git (.gitignore) и служит конфигом своего
    // тестового хостинга; на приложении клиента креды кладёт scripts/set-creds.sh
    // в приватный store вне webroot. Без этого исключения гейт краснел ровно у
    // того, кто выполнил инструкцию шаблона и вписал туда свои значения.
    // Исключение точечное: только эти два имени и только в этом файле.
    $envSlotFile = realpath(dirname(__DIR__) . '/www/env.php');
    $isEnvFile = $envSlotFile !== false && realpath($file) === $envSlotFile;
    $envSlots = ['B24_CLIENT_ID_LOCAL', 'B24_CLIENT_SECRET_LOCAL'];

    $hits = [];
    foreach ($assignments as $assignment) {
        if (!gateIsCredentialName($assignment['name'])) continue;
        if ($isEnvFile && in_array($assignment['name'], $envSlots, true)) continue;
        $value = gateStaticLiteralExpression($assignment['expr'], $known);
        if ($value !== null && $value !== '') $hits[] = $assignment['line'];
    }
    foreach ($instructions as $inst) {
        $code = $inst['code'];
        if (preg_match('~(?:->|::)\s*(?:\$)?(\w+)\s*=\s*(.+)$~s', $code, $m)
            && gateIsCredentialName($m[1])) {
            $value = gateStaticLiteralExpression($m[2], $known);
            if ($value !== null && $value !== '') $hits[] = (int)$inst['line'];
        }
        if (preg_match('~\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*(.+)$~s', $code, $m)
            && gateIsCredentialName($m[1])) {
            $value = gateStaticLiteralExpression($m[2], $known);
            if ($value !== null && $value !== '') $hits[] = (int)$inst['line'];
        }
        if (preg_match('~\bdefine\s*\(~i', $code)) {
            $args = gateCallArgs($code, 'define');
            $name = isset($args[0]) ? gateStaticLiteralExpression($args[0], $known) : null;
            $value = isset($args[1]) ? gateStaticLiteralExpression($args[1], $known) : null;
            if (is_string($name) && gateIsCredentialName($name)
                && $value !== null && $value !== '') $hits[] = (int)$inst['line'];
        }
    }

    $tokens = gateFlatTokens($file);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $value = gatePhpLiteralValue($tokens[$i]['text']);
        if ($value !== null && gateLiteralContainsCredential($value)) {
            $hits[] = (int)$tokens[$i]['line'];
        }

        // `'client_secret' => 'value'` и named argument `clientSecret: 'value'`.
        $next = gateNextToken($tokens, $i);
        if ($value === null || !gateIsCredentialName($value) || $next === null
            || !in_array($tokens[$next]['text'], ['=>', ':'], true)) continue;
        $start = gateNextToken($tokens, $next);
        if ($start === null) continue;
        $expr = ''; $round = 0;
        for ($j = $start; $j < $n; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === '(') $round++;
            elseif ($text === ')' && $round > 0) $round--;
            if ($round === 0 && in_array($text, [',', ']'], true)) break;
            $expr .= $text;
        }
        $hardcoded = gateStaticLiteralExpression($expr, $known);
        if ($hardcoded !== null && $hardcoded !== '') $hits[] = (int)$tokens[$i]['line'];
    }
    // Незакавыченный array key / named argument.
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_STRING || !gateIsCredentialName($tokens[$i]['text'])) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || !in_array($tokens[$next]['text'], ['=>', ':'], true)) continue;
        $valueAt = gateNextToken($tokens, $next);
        if ($valueAt === null) continue;
        $expr = $tokens[$valueAt]['text'];
        if ($tokens[$valueAt]['id'] === T_VARIABLE) {
            $name = ltrim($expr, '$');
            if (($known[$name] ?? '') !== '') $hits[] = (int)$tokens[$i]['line'];
        } elseif ($tokens[$valueAt]['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $hardcoded = gatePhpLiteralValue($expr);
            if ($hardcoded !== null && $hardcoded !== '') $hits[] = (int)$tokens[$i]['line'];
        }
    }
    return array_values(array_unique($hits));
}

/** JS без комментариев, но со string literals, заменёнными именованными маркерами. */
function gateJsCredentialModel(string $source): array {
    $code = ''; $values = []; $lines = []; $length = strlen($source); $line = 1;
    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i]; $next = $i + 1 < $length ? $source[$i + 1] : '';
        if ($char === '/' && $next === '/') {
            $code .= '  '; $i++;
            while ($i + 1 < $length && $source[$i + 1] !== "\n" && $source[$i + 1] !== "\r") {
                $code .= ' '; $i++;
            }
            continue;
        }
        if ($char === '/' && $next === '*') {
            $code .= '  '; $i++;
            while ($i + 1 < $length) {
                if ($source[$i + 1] === "\n") $line++;
                if ($source[$i] === '*' && $source[$i + 1] === '/') { $code .= '  '; $i++; break; }
                $code .= $source[$i + 1] === "\n" ? "\n" : ' '; $i++;
            }
            continue;
        }
        if (!in_array($char, ["'", '"', '`'], true)) {
            $code .= $char;
            if ($char === "\n") $line++;
            continue;
        }
        $quote = $char; $literalLine = $line; $value = ''; $dynamic = false;
        for ($i++; $i < $length; $i++) {
            $current = $source[$i];
            if ($current === "\n") $line++;
            if ($current === '\\' && $i + 1 < $length) {
                $value .= stripcslashes(substr($source, $i, 2)); $i++;
                continue;
            }
            if ($quote === '`' && $current === '$' && $i + 1 < $length && $source[$i + 1] === '{') {
                $dynamic = true;
            }
            if ($current === $quote) break;
            $value .= $current;
        }
        $index = count($values); $values[$index] = $dynamic ? null : $value; $lines[$index] = $literalLine;
        $code .= '__BXAPP_LIT_' . $index . '_' . $literalLine . '__';
    }
    return ['code' => $code, 'values' => $values, 'lines' => $lines];
}

/** Статическое JS-значение: literal/alias, + и concat() только из static atoms. */
function gateJsStaticValue(string $expr, array $values, array $known = []): ?string {
    $trimmed = trim($expr);
    $atom = '(?:__BXAPP_LIT_\d+_\d+__|[A-Za-z_$][\w$]*)';
    $resolveAtom = static function (string $value) use ($values, $known): ?string {
        if (preg_match('~^__BXAPP_LIT_(\d+)_\d+__$~', $value, $match)) {
            $index = (int)$match[1];
            return array_key_exists($index, $values) && $values[$index] !== null
                ? (string)$values[$index] : null;
        }
        return array_key_exists($value, $known) ? (string)$known[$value] : null;
    };
    if (!preg_match('~^\s*(' . $atom . ')~', $trimmed, $first)) return null;
    $out = $resolveAtom($first[1]);
    if ($out === null) return null;
    $rest = substr($trimmed, strlen($first[0]));
    while (trim($rest) !== '') {
        if (preg_match('~^\s*\+\s*(' . $atom . ')~', $rest, $next)) {
            $value = $resolveAtom($next[1]);
            if ($value === null) return null;
            $out .= $value; $rest = substr($rest, strlen($next[0]));
            continue;
        }
        if (preg_match('~^\s*\.\s*concat\s*\(\s*(' . $atom
            . '(?:\s*,\s*' . $atom . ')*)\s*\)~', $rest, $next)) {
            if (!preg_match_all('~' . $atom . '~', $next[1], $atoms)) return null;
            foreach ($atoms[0] as $part) {
                $value = $resolveAtom($part);
                if ($value === null) return null;
                $out .= $value;
            }
            $rest = substr($rest, strlen($next[0]));
            continue;
        }
        return null;
    }
    return $out;
}

/** Захардкоженные credentials в JS, включая object key, bracket key и alias. */
function gateFrontHardcodedCredentialLines(string $file): array {
    $source = (string)file_get_contents($file); $model = gateJsCredentialModel($source);
    $code = $model['code']; $values = $model['values']; $lines = $model['lines'];
    $literalExpr = '__BXAPP_LIT_\d+_\d+__(?:\s*\+\s*__BXAPP_LIT_\d+_\d+__)*';
    $staticEnd = '(?!\s*\+)';
    $assignments = [];
    if (preg_match_all(
        '~\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(' . $literalExpr . '|[A-Za-z_$][\w$]*)' . $staticEnd . '~',
        $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches as $match) {
            $assignments[] = ['name' => $match[1][0], 'expr' => $match[2][0], 'offset' => (int)$match[0][1]];
        }
    }
    $known = [];
    do {
        $changed = false;
        foreach ($assignments as $assignment) {
            if (array_key_exists($assignment['name'], $known)) continue;
            $value = gateJsStaticValue($assignment['expr'], $values, $known);
            if ($value === null) continue;
            $known[$assignment['name']] = $value; $changed = true;
        }
    } while ($changed);

    $hits = [];
    foreach ($assignments as $assignment) {
        if (!gateIsCredentialName($assignment['name'])) continue;
        $value = gateJsStaticValue($assignment['expr'], $values, $known);
        if ($value !== null && $value !== '') {
            $hits[] = 1 + substr_count(substr($code, 0, $assignment['offset']), "\n");
        }
    }
    $valuePattern = '(' . $literalExpr . '|[A-Za-z_$][\w$]*)';
    if (preg_match_all('~\b([A-Za-z_$][\w$]*)\s*=\s*' . $valuePattern . $staticEnd . '~',
        $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            if (!gateIsCredentialName($match[1][0])) continue;
            $value = gateJsStaticValue($match[2][0], $values, $known);
            if ($value !== null && $value !== '') {
                $hits[] = 1 + substr_count(substr($code, 0, (int)$match[0][1]), "\n");
            }
        }
    }
    // `{ client_secret: 'x' }` и `{ 'client_secret': 'x' }`.
    if (preg_match_all('~(__BXAPP_LIT_(\d+)_\d+__|[A-Za-z_$][\w$]*)\s*:\s*' . $valuePattern . $staticEnd . '~',
        $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $key = $match[2][0] !== '' ? (string)($values[(int)$match[2][0]] ?? '') : $match[1][0];
            if (!gateIsCredentialName($key)) continue;
            $value = gateJsStaticValue($match[3][0], $values, $known);
            if ($value !== null && $value !== '') {
                $hits[] = 1 + substr_count(substr($code, 0, (int)$match[0][1]), "\n");
            }
        }
    }
    // `config['client_secret'] = 'x'`.
    if (preg_match_all('~\[\s*__BXAPP_LIT_(\d+)_\d+__\s*\]\s*=\s*' . $valuePattern . $staticEnd . '~',
        $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $key = (string)($values[(int)$match[1][0]] ?? '');
            $value = gateJsStaticValue($match[2][0], $values, $known);
            if (gateIsCredentialName($key) && $value !== null && $value !== '') {
                $hits[] = 1 + substr_count(substr($code, 0, (int)$match[0][1]), "\n");
            }
        }
    }
    foreach ($values as $index => $value) {
        if (is_string($value) && gateLiteralContainsCredential($value)) $hits[] = (int)$lines[$index];
    }
    return array_values(array_unique($hits));
}

/** Hardcode в служебных shell-скриптах: секрет допустим только из env/stdin/runtime. */
function gateShellHardcodedCredentialLines(string $file): array {
    $hits = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $at => $line) {
        if (preg_match('~^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$~', $line, $m)
            && gateIsCredentialName($m[1])) {
            $value = trim($m[2]);
            if (strlen($value) >= 2 && in_array($value[0], ["'", '"'], true)
                && substr($value, -1) === $value[0]) $value = substr($value, 1, -1);
            if ($value !== '' && strpos($value, '$') === false && strpos($value, '`') === false) {
                $hits[] = $at + 1;
            }
        }
        if (gateLiteralContainsCredential($line)) $hits[] = $at + 1;
    }
    return array_values(array_unique($hits));
}

/**
 * Инструкции файла (весь код, включая тела функций) с номерами строк.
 * Режем по `;`, `{`, `}` — этого хватает, чтобы видеть вызов целиком, даже если
 * его растянули на несколько строк.
 */
function gateInstructions(string $file): array {
    $out = []; $cur = ''; $line = 0;
    foreach (gateTokens($file) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)) continue;
            // Пробел после комментария начинается на строке самого комментария.
            // Если взять его номер, следующая инструкция окажется на строку выше
            // и получит чужой номер. Опорная строка — первый значащий токен.
            if ($line === 0 && $t[0] !== T_WHITESPACE) $line = (int)$t[2];
            $cur .= $t[1];
            continue;
        }
        if ($t === ';' || $t === '{' || $t === '}') {
            if (trim($cur) !== '') $out[] = ['line' => $line, 'code' => trim($cur)];
            $cur = ''; $line = 0;
            continue;
        }
        $cur .= $t;
    }
    if (trim($cur) !== '') $out[] = ['line' => $line, 'code' => trim($cur)];
    return $out;
}

/**
 * Что лежит в переменных файла: `$var` → все присвоенные ему выражения.
 *
 * Без этого любая проверка обходится в одну строку: значение кладут в
 * переменную, а в вызов подставляют её. Повторное ревью прошло так и запись
 * состояния (`fopen($f)`), и выход в сеть (`file_get_contents($remote)`), и
 * сырую ошибку в журнале, и свой формат ответа.
 */
function gateVarMap(string $file): array {
    $map = [];
    foreach (gateInstructions($file) as $inst) {
        if (preg_match('~^\$(\w+)\s*(?:\.)?=\s*(.+)$~s', $inst['code'], $m)) {
            $map[$m[1]][] = $m[2];
            continue;
        }
        // Присваивание сразу за меткой ветки: `case 'deal': $m = 'crm.deal…';`.
        // Без этой ветки карта переменных теряла именно тот приём, которым в
        // шаблоне пишут литеральные имена методов по сущностям — и вызов снова
        // становился «безымянным» для проверки мутаций.
        if (preg_match('~^(?:case\s+[^:]+|default)\s*:\s*\$(\w+)\s*(?:\.)?=\s*(.+)$~s', $inst['code'], $m)) {
            $map[$m[1]][] = $m[2];
            continue;
        }
        // Переменная foreach получает элемент своего источника. Это важно для
        // уборки журналов: `foreach (glob($logDir...) as $file) unlink($file)`
        // остаётся видимым как путь logs/, а не превращается в «неизвестный файл».
        if (preg_match('~^foreach\s*\((.+)\s+as\s+(?:\$\w+\s*=>\s*)?\$(\w+)\s*\)$~s', $inst['code'], $m)) {
            $map[$m[2]][] = $m[1];
        }
    }
    return $map;
}

/** Подставить в выражение известные значения переменных (вглубь, но не бесконечно). */
function gateResolve(string $expr, array $vars, int $depth = 0): string {
    if ($depth > 3) return $expr;
    return (string)preg_replace_callback('~\$(\w+)~', static function (array $m) use ($vars, $depth): string {
        if (!isset($vars[$m[1]])) return '$' . $m[1] . '/*?*/';   // не видно, что внутри
        $parts = [];
        foreach ($vars[$m[1]] as $v) $parts[] = gateResolve($v, $vars, $depth + 1);
        return '(' . implode('|', $parts) . ')';
    }, $expr);
}

/** Аргументы вызова функции в инструкции (сырой текст, с учётом строк/скобок). */
function gateCallArgs(string $code, string $fn): ?array {
    if (!preg_match('~\b' . preg_quote($fn, '~') . '\s*\(~', $code, $m, PREG_OFFSET_CAPTURE)) return null;
    $pos = (int)$m[0][1] + strlen($m[0][0]);
    $depth = 1; $square = 0; $curly = 0; $arg = ''; $args = [];
    $quote = null; $escaped = false;
    for ($i = $pos, $len = strlen($code); $i < $len; $i++) {
        $c = $code[$i];

        if ($quote !== null) {
            $arg .= $c;
            if ($escaped) { $escaped = false; continue; }
            if ($c === '\\') { $escaped = true; continue; }
            if ($c === $quote) $quote = null;
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; $arg .= $c; continue; }
        if ($c === '(') { $depth++; $arg .= $c; continue; }
        if ($c === ')') {
            $depth--;
            if ($depth === 0) { $args[] = trim($arg); break; }
            $arg .= $c;
            continue;
        }
        if ($c === '[') $square++;
        elseif ($c === ']') $square--;
        elseif ($c === '{') $curly++;
        elseif ($c === '}') $curly--;
        if ($c === ',' && $depth === 1 && $square === 0 && $curly === 0) {
            $args[] = trim($arg); $arg = ''; continue;
        }
        $arg .= $c;
    }
    return $args;
}

/** Первый аргумент вызова функции в инструкции (сырой текст). */
function gateFirstArg(string $code, string $fn): ?string {
    $args = gateCallArgs($code, $fn);
    return $args === null ? null : ($args[0] ?? '');
}

/** Явно служебная цель, которую допустимо менять без state-store. */
function gateStateTargetIsExempt(string $target): bool {
    // Исключаем только СЕГМЕНТ имени, а не произвольную подстроку.
    // Прежнее `(log|lock|...)` считало `catalog.php` журналом из-за
    // букв `log` в конце и разрешало прямую запись состояния.
    if (strpos($target, '/*?*/') !== false) return false;
    // gateResolve() объединяет все присваивания через `|`. Разрешение должно
    // подходить КАЖДОМУ варианту, иначе безобидный `$f = cron.lock` прикрывает
    // последующее `$f = state.php` (flow-insensitive decoy).
    $variants = preg_split('~\|~', $target) ?: [$target];
    foreach ($variants as $variant) {
        // Служебным считается КАТАЛОГ (`data/logs/…`) или расширение
        // (`.lock.php`, `.tmp`), а не любой кусочек имени. Дефис в разделителях
        // делал служебным всё, что начинается на tmp-/log-/archive-: файл
        // `tmp-orders.php` проходил забор и позволял писать состояние мимо
        // хранилища — без замка, то есть с потерей чужих правок.
        // Цель приходит как выражение («(DATA_ROOT . '/cron.lock.php')»), поэтому
        // границей служит не только конец строки, но и кавычка или скобка.
        $edge = '(?:[\'"\)\s,;]|$)';
        if (!preg_match(
            // Служебный КАТАЛОГ: `data/logs/…`, `data/cron-logs/…`, `…/quarantine/…`.
            '~(?:^|/)[a-z0-9_-]*(?:logs?|locks?|journal|archive|tmp|temp|quarantine)(?:/|' . $edge . ')'
            // Служебное РАСШИРЕНИЕ файла: `cron.lock.php`, `2026-08-06.log.php`, `x.tmp`.
            . '|\.(?:lock|log|tmp|temp)(?:\.php)?' . $edge
            . '|STDERR|STDOUT|php://|\.bad-~i',
            $variant
        )) return false;
    }
    return true;
}

/**
 * Строки с прямой записью состояния — с раскрытием переменной.
 *
 * Журналы, замки, временные файлы и консоль состоянием не считаются: журнал
 * дописывается в конец, замок для того и открывается напрямую, чтобы взять
 * flock. Всё остальное, включая «не видно, что за путь», — нарушение: состояние
 * пишется storeUpdate/storeWrite, иначе теряется правка соседнего запроса.
 */
/**
 * Подавленная `@` операция записи, чей результат никто не проверил.
 *
 * Самый тихий из массовых дефектов: `@file_put_contents($tokens, $json);` —
 * диск кончился, права слетели, каталог исчез — приложение продолжает работать
 * и рапортует успех, а состояние потеряно. Улик нет ни в ответе, ни в журнале,
 * ни в логе хостинга: `@` гасит всё. Скан проектов, не проходивших ревью, нашёл
 * это в восьми из четырнадцати — в том числе на записи OAuth-токенов, где цена
 * молчания это потеря доступа к порталу.
 *
 * Ловим только операции, чей провал ТЕРЯЕТ данные или права. Чтение и уборка
 * (`@unlink` временного файла, `@scandir`, `@filesize`) — законный best-effort,
 * их не трогаем: там провал ничего не разрушает.
 *
 * Проверкой считается любое использование результата: присваивание, условие,
 * возврат, сравнение, `&&`/`||`, отрицание. Голый вызов с `@` и точкой с
 * запятой — нарушение.
 */
function gateSuppressedIoLines(string $file): array {
    $tokens = gateFlatTokens($file);
    $risky = array_fill_keys([
        'file_put_contents', 'fwrite', 'fputs', 'rename', 'copy', 'mkdir',
        'chmod', 'touch', 'fflush', 'move_uploaded_file', 'file_put_contents_atomic',
    ], true);
    $hits = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ((string)$tokens[$i]['text'] !== '@') continue;
        $call = gateNextToken($tokens, $i);
        if ($call === null || $tokens[$call]['id'] !== T_STRING) continue;
        if (!isset($risky[strtolower((string)$tokens[$call]['text'])])) continue;

        // Результат используют — значит сбой не проглочен молча.
        $before = gatePrevToken($tokens, $i);
        $beforeText = $before === null ? '' : (string)$tokens[$before]['text'];
        $beforeId   = $before === null ? null : $tokens[$before]['id'];
        $used = in_array($beforeText, ['=', '(', '!', '&&', '||', '?', ':', ',', '.', '==', '===', '!=', '!=='], true)
            || in_array($beforeId, [T_RETURN, T_ECHO, T_IF, T_ELSEIF, T_WHILE, T_BOOLEAN_AND, T_BOOLEAN_OR,
                T_LOGICAL_AND, T_LOGICAL_OR, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL,
                T_DOUBLE_ARROW, T_THROW], true);
        if ($used) continue;

        $hits[] = (int)$tokens[$i]['line'];
    }
    return array_values(array_unique($hits));
}

function gateDirectStateWrites(string $file): array {
    $vars = gateVarMap($file);
    $hits = [];
    foreach (gateInstructions($file) as $inst) {
        foreach (['file_put_contents', 'fopen', 'rename', 'unlink', 'copy', 'touch',
                  'move_uploaded_file'] as $fn) {
            if (!preg_match('~(^|[^>\w$])' . $fn . '\s*\(~', $inst['code'])) continue;
            $args = gateCallArgs($inst['code'], $fn);
            if ($args === null) continue;
            // rename() меняет и источник, и назначение; copy() — только
            // назначение. Иначе атомарный обход `write(tmp); rename(tmp,state)`
            // выглядел как две разрешённые операции и обходил storeWrite().
            $indexes = $fn === 'rename' ? [0, 1]
                : (in_array($fn, ['copy', 'move_uploaded_file'], true) ? [1] : [0]);
            $badTarget = false;
            foreach ($indexes as $index) {
                if (!array_key_exists($index, $args)) { $badTarget = true; break; }
                $target = gateResolve($args[$index], $vars);
                if (!gateStateTargetIsExempt($target)) { $badTarget = true; break; }
            }
            if (!$badTarget) continue;
            $hits[] = $inst['line'];
        }

        // SplFileObject с write-режимом обрезает/создаёт файл уже в конструкторе;
        // последующий ->fwrite() не содержит пути и потому раньше был невидим.
        if (preg_match('~\bnew\s+(?:\\\\)?SplFileObject\s*\(~i', $inst['code'])) {
            $args = gateCallArgs($inst['code'], 'SplFileObject');
            $mode = isset($args[1]) ? trim(gateResolve($args[1], $vars), " \t\n\r\0\x0B'\"") : 'r';
            $readOnly = (bool)preg_match('~^r(?:b)?$~i', $mode);
            $target = isset($args[0]) ? gateResolve($args[0], $vars) : '/*?*/';
            if (!$readOnly && !gateStateTargetIsExempt($target)) $hits[] = $inst['line'];
        }
    }
    return array_values(array_unique($hits));
}

/** Свой файловый журнал вместо journal/appLog, даже если путь служебный. */
function gateRawLogWriteLines(string $file): array {
    $vars = gateVarMap($file); $hits = [];
    foreach (gateInstructions($file) as $inst) {
        // Эти функции обходят journal/appLog целиком: нет generation-fence,
        // ротации, ref и обязательной маскировки. Project-коду они не нужны.
        if (preg_match('~(^|[^>:\w$])(error_log|syslog|trigger_error)\s*\(~i', $inst['code'])) {
            $hits[] = $inst['line'];
            continue;
        }
        foreach (['file_put_contents', 'fopen'] as $fn) {
            if (!preg_match('~(^|[^>\w$])' . $fn . '\s*\(~', $inst['code'])) continue;
            $arg = gateFirstArg($inst['code'], $fn);
            if ($arg === null) continue;
            $target = gateResolve($arg, $vars);
            if (!preg_match('~(?:^|[/_.\'"-])(logs?|journal|archive)(?:[/_.\'"-]|$)~i', $target)) continue;
            $hits[] = $inst['line'];
        }
    }
    return array_values(array_unique($hits));
}

/**
 * Динамическое исполнение скрывает имя готового helper от положительных
 * проверок. First-party код вызывает методы прямо; eval/reflection/
 * call_user_func/->{$name} здесь не нужны и потому запрещены fail-closed.
 */
function gateIndirectDispatchLines(string $file): array {
    $tokens = gateFlatTokens($file); $vars = gateVarMap($file); $hits = [];
    $functions = ['call_user_func', 'call_user_func_array', 'forward_static_call',
                  'forward_static_call_array'];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i]['id']; $text = strtolower($tokens[$i]['text']);
        if ($id === T_EVAL) {
            $hits[] = (int)$tokens[$i]['line'];
            continue;
        }
        if ($id === T_STRING && in_array($text, $functions, true)) {
            $next = gateNextToken($tokens, $i);
            if ($next !== null && $tokens[$next]['text'] === '(') $hits[] = (int)$tokens[$i]['line'];
        }
        if ($id === T_STRING && in_array($text, ['reflectionmethod', 'reflectionfunction'], true)) {
            $prev = gatePrevToken($tokens, $i);
            if ($prev !== null && $tokens[$prev]['id'] === T_NEW) $hits[] = (int)$tokens[$i]['line'];
        }
        if ($text !== '->' && !(defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === T_NULLSAFE_OBJECT_OPERATOR)) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null) continue;
        if ($tokens[$next]['id'] === T_VARIABLE || $tokens[$next]['text'] === '{') {
            $hits[] = (int)$tokens[$i]['line'];
        }
    }
    // `$fn(...)` скрывает имя любого sink/helper. Разрешены только локально
    // объявленные inline-closure: их тело остаётся видимым остальным гейтам.
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_VARIABLE) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || $tokens[$next]['text'] !== '(') continue;
        $name = ltrim($tokens[$i]['text'], '$');
        $values = $vars[$name] ?? [];
        $onlyInlineClosure = $values !== [];
        foreach ($values as $value) {
            if (!preg_match('~^\s*(?:static\s+)?(?:function|fn)\b~s', $value)) {
                $onlyInlineClosure = false;
                break;
            }
        }
        if (!$onlyInlineClosure) $hits[] = (int)$tokens[$i]['line'];
    }
    // `$handlers['write'](...)` и `$handlers[$name](...)` — тот же скрытый
    // callable, только взятый из массива. Его нельзя разрешать: остальные
    // проверки уже не видят фактическое имя sink/helper.
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_VARIABLE) continue;
        $open = gateNextToken($tokens, $i);
        if ($open === null || $tokens[$open]['text'] !== '[') continue;
        $depth = 0; $close = null;
        for ($j = $open; $j < $n; $j++) {
            if ($tokens[$j]['text'] === '[') $depth++;
            elseif ($tokens[$j]['text'] === ']' && --$depth === 0) { $close = $j; break; }
        }
        if ($close === null) continue;
        $call = gateNextToken($tokens, $close);
        if ($call !== null && $tokens[$call]['text'] === '(') {
            $hits[] = (int)$tokens[$i]['line'];
        }
    }
    // Callable-массив с именем защищённого B24 helper — ещё одна форма того же
    // обхода (`$f = [$b24, 'call']; $f(...)`). Прямому коду он не требуется.
    $src = (string)file_get_contents($file);
    if (preg_match_all(
        '~\[\s*\$\w+\s*,\s*[\'\"](?:call|callChecked|readBackChecked|deleteVerified|actionVerified|batch|batchAll|batchAllChecked|fetchAll|fetchAllBatched|loadByIds|listStats)[\'\"]\s*\]~i',
        $src, $matches, PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches[0] as $match) $hits[] = 1 + substr_count(substr($src, 0, (int)$match[1]), "\n");
    }
    return array_values(array_unique($hits));
}

/**
 * Свой выход в сеть в любой форме, включая адрес, собранный в переменной.
 *
 * Клиент b24() умеет повторы на лимитах портала, обновление токена под замком и
 * пакеты по 50; httpJson() — таймауты и разбор ответа. Свой curl не умеет
 * ничего из этого и ломается ровно тогда, когда портал отвечает медленно.
 */
function gateOwnHttpLines(string $file): array {
    $vars = gateVarMap($file);
    $hits = [];
    foreach (gateFlatTokens($file) as $token) {
        if ($token['id'] === null && $token['text'] === '`') $hits[] = (int)$token['line'];
    }
    foreach (gateInstructions($file) as $inst) {
        $bad = false;
        // Shell/process primitives запрещены first-party коду целиком: команда
        // `shell_exec('curl ...')` иначе обходит и B24-клиент, и httpJson(), а
        // статически надёжно доказать отсутствие сети внутри строки команды нельзя.
        if (preg_match('~(^|[^>:\w$])(shell_exec|exec|popen|proc_open|system|passthru)\s*\(~i', $inst['code'])) {
            $bad = true;
        }
        if (!$bad && preg_match('~\b(curl_init|curl_setopt|curl_exec|curl_multi_init|fsockopen|stream_socket_client|get_headers|socket_create)\s*\(~', $inst['code'])) {
            $bad = true;
        }
        // SoapClient скрывает HTTP внутри конструктора/методов и не использует
        // таймауты/маскировку готового клиента.
        if (!$bad && preg_match('~\bnew\s+(?:\\\\)?SoapClient\s*\(~i', $inst['code'])) $bad = true;
        foreach (['file_get_contents', 'fopen', 'simplexml_load_file', 'readfile', 'copy',
                  'file', 'gzopen', 'readgzfile'] as $fn) {
            if ($bad) break;
            if (!preg_match('~(^|[^>\w$])' . $fn . '\s*\(~', $inst['code'])) continue;
            $arg = gateFirstArg($inst['code'], $fn);
            if ($arg === null) continue;
            $target = gateResolve($arg, $vars);
            if (preg_match('~https?://|ftp://~i', $target)) { $bad = true; break; }
            // Локальный файл виден по опоре на путь проекта; всё прочее — «неизвестно куда».
            if (preg_match('~__DIR__|__FILE__|DATA_ROOT|dirname\s*\(|sys_get_temp_dir|php://|STDIN~', $target)) continue;
            $bad = true;
        }
        if (!$bad) continue;
        $hits[] = $inst['line'];
    }
    return array_values(array_unique($hits));
}

/** Generic httpJson не должен становиться запасным входом в REST Битрикс24. */
function gateGenericPortalHttpLines(string $file): array {
    $vars = gateVarMap($file); $hits = [];
    foreach (gateInstructions($file) as $inst) {
        if (!preg_match('~(^|[^>:\w$])httpJson\s*\(~i', $inst['code'])) continue;
        $arg = gateFirstArg($inst['code'], 'httpJson');
        $target = $arg === null ? '/*?*/' : gateResolve($arg, $vars);
        // Для внешнего API нужен видимый literal URL. Неизвестный адрес и REST
        // Б24 идут только через b24(), иначе теряются refresh/retry/runOnce.
        $literal = preg_match('~^\s*[\'"]https?://[^\'"]+[\'"]\s*$~i', $target) === 1;
        if (!$literal || preg_match('~(?:bitrix24|oauth\.bitrix\.info|/rest/)~i', $target)) {
            $hits[] = $inst['line'];
        }
    }
    return array_values(array_unique($hits));
}

/** Тело endpoint разбирается только apiBody(), а поля — api* validators. */
function gateRawInputLines(string $file): array {
    $vars = gateVarMap($file); $hits = [];
    foreach (gateFlatTokens($file) as $token) {
        if ($token['id'] === T_VARIABLE && in_array($token['text'], ['$_POST', '$_REQUEST'], true)) {
            $hits[] = (int)$token['line'];
        }
    }
    foreach (gateInstructions($file) as $inst) {
        foreach (['file_get_contents', 'fopen', 'file'] as $fn) {
            if (!preg_match('~(^|[^>\w$])' . $fn . '\s*\(~', $inst['code'])) continue;
            $arg = gateFirstArg($inst['code'], $fn);
            if ($arg !== null && stripos(gateResolve($arg, $vars), 'php://input') !== false) {
                $hits[] = $inst['line'];
            }
        }
    }
    return array_values(array_unique($hits));
}

/**
 * JS без комментариев и обычного текста строк, но со строками computed-property.
 * Длина и переводы строк сохраняются: найденный offset остаётся номером строки
 * исходника. Строки внутри `obj['name']` нужны, чтобы видеть bracket-dispatch.
 */
function gateJsTemplateExpressionEnd(string $source, int $start): ?int {
    $depth = 1; $length = strlen($source); $quote = '';
    for ($i = $start; $i < $length; $i++) {
        $char = $source[$i]; $next = $i + 1 < $length ? $source[$i + 1] : '';
        if ($quote !== '') {
            if ($char === '\\') { $i++; continue; }
            if ($char === $quote) $quote = '';
            continue;
        }
        if ($char === '/' && $next === '/') {
            $i += 2;
            while ($i < $length && $source[$i] !== "\n" && $source[$i] !== "\r") $i++;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $close = strpos($source, '*/', $i + 2);
            if ($close === false) return null;
            $i = $close + 1;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; continue; }
        if ($char === '{') $depth++;
        elseif ($char === '}' && --$depth === 0) return $i;
    }
    return null;
}

function gateJsPolicyCode(string $source): string {
    $source = (string)preg_replace_callback('~<!--[\s\S]*?-->~', static function (array $m): string {
        return preg_replace('~[^\r\n]~', ' ', $m[0]);
    }, $source);
    $out = ''; $length = strlen($source); $brackets = [];
    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i]; $next = $i + 1 < $length ? $source[$i + 1] : '';
        if ($char === '/' && $next === '/') {
            $out .= '  '; $i += 2;
            while ($i < $length && $source[$i] !== "\n" && $source[$i] !== "\r") {
                $out .= ' '; $i++;
            }
            if ($i < $length) $out .= $source[$i];
            continue;
        }
        if ($char === '/' && $next === '*') {
            $out .= '  '; $i += 2;
            while ($i < $length) {
                if ($i + 1 < $length && $source[$i] === '*' && $source[$i + 1] === '/') {
                    $out .= '  '; $i++; break;
                }
                $out .= ($source[$i] === "\n" || $source[$i] === "\r") ? $source[$i] : ' ';
                $i++;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $preserve = $char !== '`' && ($brackets[count($brackets) - 1] ?? false);
            $out .= $preserve ? $char : ' ';
            for ($i++; $i < $length; $i++) {
                $current = $source[$i];
                if ($char === '`' && $current === '$' && $i + 1 < $length && $source[$i + 1] === '{') {
                    $end = gateJsTemplateExpressionEnd($source, $i + 2);
                    if ($end === null) {
                        $out .= str_repeat(' ', $length - $i);
                        $i = $length;
                        break;
                    }
                    $expression = substr($source, $i + 2, $end - $i - 2);
                    $out .= '${' . gateJsPolicyCode($expression) . '}';
                    $i = $end;
                    continue;
                }
                if ($current === '\\' && $i + 1 < $length) {
                    $out .= $preserve ? $current . $source[$i + 1] : '  ';
                    $i++;
                    continue;
                }
                $out .= ($current === "\n" || $current === "\r")
                    ? $current : ($preserve ? $current : ' ');
                if ($current === $char) break;
            }
            continue;
        }
        if ($char === '[') {
            $previous = '';
            for ($at = strlen($out) - 1; $at >= 0; $at--) {
                if (!ctype_space($out[$at])) { $previous = $out[$at]; break; }
            }
            $brackets[] = $previous !== '' && preg_match('~[\w$\].)]~', $previous) === 1;
        } elseif ($char === ']' && $brackets !== []) {
            array_pop($brackets);
        }
        $out .= $char;
    }
    return $out;
}

/** Вызовы простых alias, которым присвоили запрещённую browser-capability. */
function gateJsConstantPropertyName(string $expression): ?string {
    if (!preg_match('~^\s*[\'"][^\'"]*[\'"](?:\s*\+\s*[\'"][^\'"]*[\'"])*\s*$~', $expression)) return null;
    return strtolower((string)preg_replace('~[\s+\'\"]~', '', $expression));
}

/** Dynamic global-property fail-closed; известное безопасное имя разрешается. */
function gateJsForbiddenGlobalBracketOffsets(string $code, array $forbiddenNames, bool $calledOnly): array {
    $tail = $calledOnly ? '\s*(?:\?\.)?\s*(?:\(|\.\s*(?:call|apply|bind)\s*\()' : '';
    $pattern = '~\b(?:window|globalThis|self|top|parent)\s*\[([^\]\r\n]+)\]' . $tail . '~m';
    if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
    $hits = [];
    foreach ($matches as $match) {
        $name = gateJsConstantPropertyName($match[1][0]);
        if ($name !== null && !in_array($name, $forbiddenNames, true)) continue;
        $hits[] = (int)$match[0][1];
    }
    return array_values(array_unique($hits));
}

/** Точная ссылка на browser-capability: голое имя либо свойство известного global. */
function gateJsCapabilityReferencePattern(
    array $bareNames,
    array $globalNames = [],
    array $qualifiedPatterns = []
): string {
    $parts = [];
    $quotedBare = array_map(static fn(string $name): string => preg_quote($name, '~'), $bareNames);
    if ($quotedBare !== []) {
        $parts[] = '(?<![\\w$.])(?:' . implode('|', $quotedBare) . ')(?![\\w$])';
    }
    $quotedGlobal = array_map(static fn(string $name): string => preg_quote($name, '~'), $globalNames);
    if ($quotedGlobal !== []) {
        $parts[] = '\\b(?:window|globalThis|self|top|parent)\\s*(?:\\?\\s*)?\\.\\s*(?:'
            . implode('|', $quotedGlobal) . ')(?![\\w$])';
    }
    foreach ($qualifiedPatterns as $pattern) $parts[] = '(?:' . $pattern . ')';
    return '(?:' . implode('|', $parts) . ')';
}

/** Вызов capability напрямую, через optional/call/apply или Reflect.apply. */
function gateJsReferenceCallOffsets(string $code, string $referencePattern): array {
    $callee = '(?:' . $referencePattern . '|\\(\\s*' . $referencePattern . '\\s*\\))';
    $patterns = [
        '~' . $callee . '\\s*(?:\\?\\.\\s*\\(|\\()~m',
        '~' . $callee . '\\s*(?:\\.|\\?\\.)\\s*(?:call|apply|bind)\\s*\\(~m',
        '~\\bReflect\\s*(?:\\.|\\?\\.)\\s*apply\\s*\\(\\s*' . $callee . '\\s*,~m',
    ];
    $hits = [];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as $match) $hits[] = (int)$match[1];
    }
    return array_values(array_unique($hits));
}

function gateJsAliasCallOffsets(string $code, string $capabilityPattern, array $destructuredNames): array {
    $aliases = [];
    do {
        $changed = false;
        $assignmentPatterns = [
            '~(?:^|[;{}\r\n])\s*(?:(?:const|let|var)\s+)?([A-Za-z_$][\w$]*)\s*=\s*(?!=)([^;\r\n]+)~m',
            '~(?:^|[;{}\r\n])\s*(?:(?:const|let|var)\s+)?([A-Za-z_$][\w$]*)\s*=\s*'
                . '\(\s*([A-Za-z_$][\w$]*(?:\s*(?:\?\.|\.)\s*[A-Za-z_$][\w$]*)*)\s*\)\s*(?:;|$)~m',
        ];
        foreach ($assignmentPatterns as $assignmentPattern) {
            if (!preg_match_all(
                $assignmentPattern, $code, $assignments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) continue;
            foreach ($assignments as $assignment) {
                $name = $assignment[1][0]; $rhs = $assignment[2][0];
                $tainted = preg_match($capabilityPattern, $rhs) === 1
                    || gateJsForbiddenGlobalBracketOffsets($rhs, $destructuredNames, false) !== [];
                if (!$tainted) {
                    foreach (array_keys($aliases) as $alias) {
                        if (preg_match('~(?<![\w$])' . preg_quote($alias, '~') . '(?![\w$])~', $rhs)) {
                            $tainted = true; break;
                        }
                    }
                }
                if ($tainted && !isset($aliases[$name])) { $aliases[$name] = true; $changed = true; }
            }
        }
    } while ($changed);

    if (preg_match_all(
        '~\b(?:const|let|var)\s*\{([^}\r\n]+)\}\s*=\s*(?:window|globalThis|self|top|parent)\b~',
        $code, $destructuring, PREG_SET_ORDER
    )) {
        foreach ($destructuring as $match) {
            foreach (explode(',', $match[1]) as $part) {
                if (!preg_match('~^\s*([A-Za-z_$][\w$]*)(?:\s*:\s*([A-Za-z_$][\w$]*))?~', $part, $names)) continue;
                if (!in_array(strtolower($names[1]), $destructuredNames, true)) continue;
                $aliases[$names[2] ?? $names[1]] = true;
            }
        }
    }

    $offsets = [];
    foreach (array_keys($aliases) as $alias) {
        $reference = '(?<![\\w$.])' . preg_quote($alias, '~') . '(?![\\w$])';
        array_push($offsets, ...gateJsReferenceCallOffsets($code, $reference));
    }
    return array_values(array_unique($offsets));
}

/** Offsets собственного browser-transport в уже очищенном JS-коде. */
function gateJsTransportOffsets(string $code): array {
    $hits = [];
    $transportReference = gateJsCapabilityReferencePattern(
        ['fetch', 'XMLHttpRequest', 'axios'],
        ['fetch', 'XMLHttpRequest', 'axios'],
        ['\\bdocument\\s*(?:\\?\\s*)?\\.\\s*defaultView\\s*(?:\\?\\s*)?\\.\\s*'
            . '(?:fetch|XMLHttpRequest)(?![\\w$])']
    );
    array_push($hits, ...gateJsReferenceCallOffsets($code, $transportReference));
    $patterns = [
        '~\b(?:axios\s*\(|axios\s*\.|\$\s*\.\s*ajax|jQuery\s*\.\s*ajax|navigator\s*\.\s*sendBeacon)~m',
        '~\b(?:window\s*\.\s*)?BX24\s*\.\s*(?:callMethod|callBatch)\s*\(~m',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as $match) $hits[] = (int)$match[1];
    }
    array_push($hits, ...gateJsForbiddenGlobalBracketOffsets(
        $code, ['fetch', 'xmlhttprequest', 'axios', 'sendbeacon'], true
    ));
    $capability = '~(?:' . $transportReference
        . '|\\bBX24\\s*\\.\\s*(?:callMethod|callBatch)\\b'
        . '|\\b(?:jQuery|\\$)\\s*\\.\\s*ajax\\b|\\bnavigator\\s*\\.\\s*sendBeacon\\b)~';
    array_push($hits, ...gateJsAliasCallOffsets(
        $code, $capability, ['fetch', 'xmlhttprequest', 'axios', 'sendbeacon']
    ));
    return array_values(array_unique($hits));
}

/** Frontend project-кода ходит в сеть только через готовый ui.api(). */
function gateFrontTransportLines(string $file): array {
    $src = (string)file_get_contents($file); $code = gateJsPolicyCode($src); $hits = [];
    foreach (gateJsTransportOffsets($code) as $offset) {
        $hits[] = 1 + substr_count(substr($src, 0, $offset), "\n");
    }
    // Код inline-handler находится внутри HTML-строки и потому вычищен выше.
    if (preg_match_all(
        '~\bon[a-z]+\s*=\s*([\'\"])([\s\S]*?)\1~i', $src, $handlers,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($handlers as $handler) {
            if (gateJsTransportOffsets(gateJsPolicyCode($handler[2][0])) === []) continue;
            $hits[] = 1 + substr_count(substr($src, 0, (int)$handler[0][1]), "\n");
        }
    }
    return array_values(array_unique($hits));
}

/** Свой формат ответа: любая печать из эндпоинта мимо apiOk/apiFail. */
/**
 * Строки канонического забора прямого HTTP к библиотеке.
 *
 * Под веб-корнем лежит ВЕСЬ каталог: деплой публикует его целиком, поэтому
 * файл, который вызывают только по `require`, всё равно доступен по прямой
 * ссылке и отвечает пустым 200 — то есть подтверждает свой путь и существование
 * (находка ревью 08.08.2026, BXREV-025). Забор ставит 404.
 *
 * Проверкам гейта эти строки надо ЗНАТЬ и не считать нарушением: формально там
 * и `exit`, и `http_response_code`, и раннее ветвление в env.php. Вид забора
 * ровно один и сверяется дословно — «похожий» код исключением не станет.
 *
 * @return int[] номера строк, занятых забором (пусто — забора нет)
 */
function gateDirectAccessGuardLines(string $file): array {
    $code = @file_get_contents($file);
    if (!is_string($code) || $code === '') return [];
    $kanon = "if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''))) {\n"
        . "    http_response_code(404);\n"
        . "    header('Cache-Control: no-store');\n"
        . "    exit;\n"
        . "}";
    $pos = strpos($code, $kanon);
    if ($pos === false) return [];
    $pervaya = substr_count(substr($code, 0, $pos), "\n") + 1;
    $vsego = substr_count($kanon, "\n") + 1;
    $stroki = [];
    for ($i = 0; $i < $vsego; $i++) $stroki[] = $pervaya + $i;
    return $stroki;
}

/** Есть ли у файла забор прямого HTTP (значит, это библиотека, а не эндпоинт). */
function gateHasDirectAccessGuard(string $file): bool {
    return gateDirectAccessGuardLines($file) !== [];
}

function gateOwnAnswerLines(string $file): array {
    $hits = gateInlineHtmlLines($file); $vars = gateVarMap($file);
    foreach (gateInstructions($file) as $inst) {
        $code = $inst['code'];
        $bad = (bool)preg_match('~(^|[^>\w$])(echo|print|printf|vprintf|var_dump|print_r)\s*[\s(\'"$]~', $code)
            || (bool)preg_match('~(^|[^>\w$])(die|exit)\b(?:\s*\(|\s|$)~', $code)
            || (bool)preg_match('~(^|[^>\w$])readfile\s*\(~', $code)
            || (bool)preg_match('~fwrite\s*\(\s*STDOUT~', $code)
            || (bool)preg_match('~header\s*\(\s*[\'"]Content-Type~i', $code);
        foreach (['file_put_contents', 'fopen', 'fwrite', 'fpassthru', 'stream_copy_to_stream'] as $fn) {
            if ($bad || !preg_match('~(^|[^>\w$])' . $fn . '\s*\(~', $code)) continue;
            $arg = gateFirstArg($code, $fn);
            if ($arg === null) continue;
            $target = gateResolve($arg, $vars);
            if (preg_match('~php://(?:output|stdout)|\bSTDOUT\b~i', $target)) $bad = true;
        }
        if (!$bad) continue;
        $hits[] = $inst['line'];
    }
    // Забор прямого HTTP — не «свой формат ответа»: это 404 и выход, один и тот
    // же во всех библиотеках шаблона (BXREV-025).
    $hits = array_diff($hits, gateDirectAccessGuardLines($file));
    return array_values(array_unique($hits));
}

/** Браузерный диалог в любой записи: голый, через окно, индекс или alias. */
function gateJsHasBrowserDialog(string $code): bool {
    $dialogReference = gateJsCapabilityReferencePattern(
        ['alert', 'confirm', 'prompt'], ['alert', 'confirm', 'prompt']
    );
    if (gateJsReferenceCallOffsets($code, $dialogReference) !== []
        || gateJsForbiddenGlobalBracketOffsets(
            $code, ['alert', 'confirm', 'prompt'], true
        ) !== []) return true;

    // Простейшая константная склейка свойства: window['con' + 'firm']().
    // Это не полноценный JS-eval — сознательно понимаем только строки и `+`,
    // а всё динамическое ниже ловит отдельный transport/dispatch-контур.
    if (preg_match_all('/\[([^\]\r\n]{1,120})\]\s*(?:\?\.)?\s*\(/', $code, $matches)) {
        foreach ($matches[1] as $expr) {
            if (!preg_match('~^\s*[\'"][^\'"]*[\'"](?:\s*\+\s*[\'"][^\'"]*[\'"])*\s*$~', $expr)) continue;
            $name = preg_replace('~[\s+\'\"]~', '', $expr);
            if (in_array(strtolower((string)$name), ['alert', 'confirm', 'prompt'], true)) return true;
        }
    }
    $capability = '~' . $dialogReference . '~';
    return gateJsAliasCallOffsets($code, $capability, ['alert', 'confirm', 'prompt']) !== [];
}

function gateBrowserDialog(string $file): bool {
    $src = (string)file_get_contents($file);
    if (gateJsHasBrowserDialog(gateJsPolicyCode($src))) return true;
    if (!preg_match_all('~\bon[a-z]+\s*=\s*([\'\"])([\s\S]*?)\1~i', $src, $handlers, PREG_SET_ORDER)) return false;
    foreach ($handlers as $handler) {
        if (gateJsHasBrowserDialog(gateJsPolicyCode($handler[2]))) return true;
    }
    return false;
}

/** Статически вычислимые JS-строки, включая alias и склейку literals. */
function gateJsKnownStaticStrings(array $model): array {
    $assignments = [];
    if (preg_match_all(
        '~\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*([^;\r\n]+)~',
        (string)$model['code'], $matches, PREG_SET_ORDER
    )) {
        foreach ($matches as $match) $assignments[] = ['name' => $match[1], 'expr' => $match[2]];
    }
    // Несколько declarator в одной инструкции: const ev='on'+'click', busy='dis'+'abled'.
    $atom = '(?:__BXAPP_LIT_\d+_\d+__|[A-Za-z_$][\w$]*)';
    $staticExpr = '(?:' . $atom . '(?:\s*\+\s*' . $atom
        . '|\s*\.\s*concat\s*\(\s*' . $atom . '(?:\s*,\s*' . $atom . ')*\s*\))*)';
    if (preg_match_all(
        '~(?:\b(?:const|let|var)|,)\s*([A-Za-z_$][\w$]*)\s*=\s*(' . $staticExpr . ')(?=\s*[,;\r\n])~',
        (string)$model['code'], $matches, PREG_SET_ORDER
    )) {
        foreach ($matches as $match) $assignments[] = ['name' => $match[1], 'expr' => $match[2]];
    }
    $known = [];
    do {
        $changed = false;
        foreach ($assignments as $assignment) {
            if (array_key_exists($assignment['name'], $known)) continue;
            $value = gateJsStaticValue($assignment['expr'], (array)$model['values'], $known);
            if ($value === null) continue;
            $known[$assignment['name']] = $value; $changed = true;
        }
    } while ($changed);
    return $known;
}

/** Парные JS-разделители в уже замаскированном от строк/комментариев коде. */
function gateJsMatchingDelimiter(string $code, int $open, string $left, string $right): ?int {
    $depth = 0;
    for ($at = $open, $length = strlen($code); $at < $length; $at++) {
        if ($code[$at] === $left) $depth++;
        elseif ($code[$at] === $right && --$depth === 0) return $at;
    }
    return null;
}

/** Block/function/arrow lexical scopes, включая parameters как собственные bindings. */
function gateJsCallableScopes(string $code): array {
    $rows = [];
    // Любая brace-пара образует lexical boundary для let/const. Object literals
    // тоже безопасно считать вложенным scope: declarations внутри них невозможны,
    // а lookup всё равно поднимается к родителю.
    for ($at = 0, $length = strlen($code); $at < $length; $at++) {
        if ($code[$at] !== '{') continue;
        $close = gateJsMatchingDelimiter($code, $at, '{', '}');
        if ($close === null) continue;
        $rows[$at] = ['start' => $at, 'end' => $close, 'parent' => 0, 'params' => []];
    }
    $patterns = [
        '~\bfunction(?:\s+[A-Za-z_$][\w$]*)?\s*\(([^)]*)\)\s*\{~',
        '~(?:\(([^)]*)\)|([A-Za-z_$][\w$]*))\s*=>\s*\{~',
    ];
    foreach ($patterns as $kind => $pattern) {
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches as $match) {
            $open = (int)$match[0][1] + strrpos($match[0][0], '{');
            $close = gateJsMatchingDelimiter($code, $open, '{', '}');
            if ($close === null) continue;
            $params = $kind === 0 ? (string)$match[1][0]
                : ((string)($match[1][0] ?? '') !== '' ? (string)$match[1][0] : (string)($match[2][0] ?? ''));
            $names = [];
            if (preg_match_all('~(?:^|,)\s*([A-Za-z_$][\w$]*)~', $params, $found)) {
                foreach ($found[1] as $name) $names[$name] = true;
            }
            $rows[$open] = ['start' => $open, 'end' => $close, 'parent' => 0,
                'params' => array_keys($names)];
        }
    }
    // Expression-bodied arrow: scope заканчивается вместе с expression, а не
    // поглощает одноимённые bindings окружающего кода.
    $arrow = '~(?:\(([^)]*)\)|([A-Za-z_$][\w$]*))\s*=>\s*+(?!\{)~';
    if (preg_match_all($arrow, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $start = (int)$match[0][1] + strlen($match[0][0]);
            $round = 0; $square = 0; $curly = 0; $end = strlen($code);
            for ($at = $start, $length = strlen($code); $at < $length; $at++) {
                $char = $code[$at];
                if ($char === ')' && $round === 0 && $square === 0 && $curly === 0) { $end = $at; break; }
                if ($char === ']' && $square === 0 && $round === 0 && $curly === 0) { $end = $at; break; }
                if (($char === ',' || $char === ';' || $char === "\n")
                    && $round === 0 && $square === 0 && $curly === 0) { $end = $at; break; }
                if ($char === '(') $round++;
                elseif ($char === ')') $round--;
                elseif ($char === '[') $square++;
                elseif ($char === ']') $square--;
                elseif ($char === '{') $curly++;
                elseif ($char === '}') $curly--;
            }
            $params = (string)($match[1][0] ?? '') !== ''
                ? (string)$match[1][0] : (string)($match[2][0] ?? '');
            $names = [];
            if (preg_match_all('~(?:^|,)\s*([A-Za-z_$][\w$]*)~', $params, $found)) {
                foreach ($found[1] as $name) $names[$name] = true;
            }
            $key = $start - 1;
            while (isset($rows[$key])) $key--;
            $rows[$key] = ['start' => $start - 1, 'end' => $end, 'parent' => 0,
                'params' => array_keys($names)];
        }
    }
    ksort($rows);
    $scopes = [['start' => -1, 'end' => strlen($code) + 1, 'parent' => null, 'params' => []]];
    foreach ($rows as $row) $scopes[] = $row;
    foreach ($scopes as $id => $scope) {
        if ($id === 0) continue;
        $parent = 0; $span = $scopes[0]['end'] - $scopes[0]['start'];
        foreach ($scopes as $candidate => $outer) {
            if ($candidate === $id || $outer['start'] >= $scope['start'] || $outer['end'] <= $scope['end']) continue;
            $outerSpan = $outer['end'] - $outer['start'];
            if ($outerSpan < $span) { $parent = $candidate; $span = $outerSpan; }
        }
        $scopes[$id]['parent'] = $parent;
    }
    return $scopes;
}

/** Самый внутренний callable scope для source-offset. */
function gateJsScopeAt(array $scopes, int $offset): int {
    $found = 0; $span = PHP_INT_MAX;
    foreach ($scopes as $id => $scope) {
        if ($offset <= $scope['start'] || $offset >= $scope['end']) continue;
        $candidate = $scope['end'] - $scope['start'];
        if ($candidate < $span) { $found = (int)$id; $span = $candidate; }
    }
    return $found;
}

/** Верхнеуровневые arguments call с их исходными offsets. */
function gateJsCallArguments(string $code, int $open, int $close): array {
    $out = []; $start = $open + 1; $round = 0; $square = 0; $curly = 0;
    for ($at = $start; $at < $close; $at++) {
        $char = $code[$at];
        if ($char === '(') $round++;
        elseif ($char === ')') $round--;
        elseif ($char === '[') $square++;
        elseif ($char === ']') $square--;
        elseif ($char === '{') $curly++;
        elseif ($char === '}') $curly--;
        elseif ($char === ',' && $round === 0 && $square === 0 && $curly === 0) {
            $out[] = ['expr' => substr($code, $start, $at - $start), 'offset' => $start];
            $start = $at + 1;
        }
    }
    $out[] = ['expr' => substr($code, $start, $close - $start), 'offset' => $start];
    return $out;
}

/**
 * ui.action() обязан запускаться из доказуемого пользовательского обработчика.
 * Комментарии и строки не считаются доказательством; именованный helper допустим,
 * только если все его достижимые вызовы идут из обработчика пользователя.
 */
function gateUiActionOutsideUserFlowLines(string $file): array {
    $src = (string)@file_get_contents($file);
    $model = gateJsCredentialModel($src); $code = (string)$model['code'];
    $values = (array)$model['values']; $known = gateJsKnownStaticStrings($model);
    $lineAt = static fn(int $offset): int => 1 + substr_count(substr($code, 0, $offset), "\n");
    $inside = static function (int $offset, array $ranges): bool {
        foreach ($ranges as $range) {
            if ($offset > $range[0] && $offset < $range[1]) return true;
        }
        return false;
    };
    $bodyRange = static function (string $masked, int $matchOffset, string $matchText): ?array {
        $relative = strrpos($matchText, '{');
        if ($relative === false) return null;
        $open = $matchOffset + $relative;
        $close = gateJsMatchingDelimiter($masked, $open, '{', '}');
        return $close === null ? null : [$open, $close];
    };

    $callback = '(?:async\s+)?(?:function(?:\s+[A-Za-z_$][\w$]*)?\s*\([^)]*\)'
        . '|(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>)\s*\{';
    $handlers = [];
    $handlerPatterns = [
        '~\.\s*(?:onclick|onchange|oninput|onsubmit|onkeydown|onkeyup)\s*=\s*'
            . '(?:[^;{}\r\n?]+\?\s*)?' . $callback . '~',
        '~\b(?:onRetry|onConfirm|onSelect|onSubmit)\s*:\s*' . $callback . '~',
    ];
    foreach ($handlerPatterns as $pattern) {
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches as $match) {
            $range = $bodyRange($code, (int)$match[0][1], (string)$match[0][0]);
            if ($range !== null) $handlers[] = $range;
        }
    }
    // Bracket property: имя должно статически вычисляться именно в user event.
    if (preg_match_all(
        '~\[\s*([^\]\r\n]+)\s*\]\s*=\s*(' . $callback . ')~',
        $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches as $match) {
            $event = strtolower((string)gateJsStaticValue($match[1][0], $values, $known));
            if (!in_array($event, ['onclick', 'onchange', 'oninput', 'onsubmit', 'onkeydown', 'onkeyup'], true)) continue;
            $range = $bodyRange($code, (int)$match[0][1], (string)$match[0][0]);
            if ($range !== null) $handlers[] = $range;
        }
    }
    // addEventListener: только интерактивные события и только реальный inline callback.
    if (preg_match_all(
        '~\.\s*addEventListener\s*\(\s*([^,\r\n]+)\s*,\s*(' . $callback . ')~',
        $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches as $match) {
            $event = strtolower((string)gateJsStaticValue($match[1][0], $values, $known));
            if (!in_array($event, ['click', 'change', 'input', 'submit', 'keydown', 'keyup'], true)) continue;
            $range = $bodyRange($code, (int)$match[0][1], (string)$match[0][0]);
            if ($range !== null) $handlers[] = $range;
        }
    }

    // Именованные declarations/expressions: нужны для helper вроде taskDo(),
    // который вызывается из нескольких onclick, а ui.action живёт в одном месте.
    $functions = [];
    $functionPatterns = [
        '~\bfunction\s+([A-Za-z_$][\w$]*)\s*\([^)]*\)\s*\{~',
        '~\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*'
            . '(?:async\s+)?(?:function(?:\s+[A-Za-z_$][\w$]*)?\s*\([^)]*\)'
            . '|(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>)\s*\{~',
    ];
    foreach ($functionPatterns as $pattern) {
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches as $match) {
            $range = $bodyRange($code, (int)$match[0][1], (string)$match[0][0]);
            if ($range === null) continue;
            $functions[$match[1][0]] = ['range' => $range, 'declaration' => (int)$match[1][1]];
        }
    }

    $safeFunctions = [];
    do {
        $changed = false;
        foreach ($functions as $name => $function) {
            if (isset($safeFunctions[$name])) continue;
            $offsets = [];
            if (preg_match_all(
                '~(?<![\w$.])' . preg_quote($name, '~') . '\s*\(~',
                $code, $calls, PREG_OFFSET_CAPTURE
            )) {
                foreach ($calls[0] as $call) {
                    $offset = (int)$call[1];
                    if (abs($offset - $function['declaration']) < 12) continue;
                    $offsets[] = $offset;
                }
            }
            // Named callback reference: button.onclick = helper и addEventListener(..., helper).
            $referencePatterns = [
                '~\.\s*(?:onclick|onchange|oninput|onsubmit|onkeydown|onkeyup)\s*=\s*'
                    . preg_quote($name, '~') . '\b~',
                '~\.\s*addEventListener\s*\(\s*__BXAPP_LIT_\d+_\d+__\s*,\s*'
                    . preg_quote($name, '~') . '\b~',
            ];
            $userReferences = [];
            foreach ($referencePatterns as $pattern) {
                if (!preg_match_all($pattern, $code, $refs, PREG_OFFSET_CAPTURE)) continue;
                foreach ($refs[0] as $ref) {
                    $offset = (int)$ref[1]; $offsets[] = $offset; $userReferences[$offset] = true;
                }
            }
            if ($offsets === []) continue;
            $allUser = true;
            foreach (array_unique($offsets) as $offset) {
                if ($inside($offset, $handlers)) continue;
                $viaSafe = false;
                foreach (array_keys($safeFunctions) as $safeName) {
                    if ($inside($offset, [$functions[$safeName]['range']])) { $viaSafe = true; break; }
                }
                // A named callback reference is itself the user-event binding.
                if (!$viaSafe && isset($userReferences[$offset])) $viaSafe = true;
                if (!$viaSafe) { $allUser = false; break; }
            }
            if ($allUser) { $safeFunctions[$name] = true; $changed = true; }
        }
    } while ($changed);

    $actionOffsets = [];
    if (preg_match_all('~\bui\s*\.\s*action\s*\(~', $code, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) $actionOffsets[] = (int)$match[1];
    }
    if (preg_match_all('~\bui\s*\[\s*([^\]\r\n]+)\s*\]\s*\(~', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            if (strtolower((string)gateJsStaticValue($match[1][0], $values, $known)) === 'action') {
                $actionOffsets[] = (int)$match[0][1];
            }
        }
    }
    $capability = '~\bui\s*(?:\.\s*action|\[[^\]\r\n]+\])~';
    array_push($actionOffsets, ...gateJsAliasCallOffsets($code, $capability, ['action']));

    $bad = [];
    foreach (array_unique($actionOffsets) as $offset) {
        if ($inside($offset, $handlers)) continue;
        $allowed = false;
        foreach (array_keys($safeFunctions) as $name) {
            if ($inside($offset, [$functions[$name]['range']])) { $allowed = true; break; }
        }
        if (!$allowed) $bad[] = $lineAt($offset);
    }
    sort($bad);
    return array_values(array_unique($bad));
}

/**
 * Дубль ready UI по типу/поведению, не по общему слову `input`.
 * Runtime-тип не угадываем: красны только статически доказуемые date/number/select,
 * custom people-search и async click с ручным busy-state.
 */
function gateDuplicateReadyUiLines(string $file): array {
    $src = (string)@file_get_contents($file);
    $model = gateJsCredentialModel($src); $code = (string)$model['code'];
    $values = (array)$model['values']; $known = gateJsKnownStaticStrings($model);
    $hits = [];
    $lineAt = static fn(int $offset): int => 1 + substr_count(substr($src, 0, $offset), "\n");
    $codeLineAt = static fn(int $offset): int => 1 + substr_count(substr($code, 0, $offset), "\n");
    $resolve = static fn(string $expr): ?string => gateJsStaticValue($expr, $values, $known);
    $atom = '(?:__BXAPP_LIT_\d+_\d+__|[A-Za-z_$][\w$]*)';
    $expr = '(?:' . $atom . '(?:\s*\+\s*' . $atom
        . '|\s*\.\s*concat\s*\(\s*' . $atom . '(?:\s*,\s*' . $atom . ')*\s*\))*)';

    foreach ($values as $index => $value) {
        if (!is_string($value)) continue;
        if (preg_match('~<\s*select(?:\s|>)~i', $value)
            || preg_match('~<\s*input\b[^>]*\btype\s*=\s*[\'"]?(?:date|number)\b~i', $value)) {
            $hits[] = (int)($model['lines'][$index] ?? 1);
        }
    }

    // Input identity follows lexical bindings, not a global variable spelling.
    // This catches aliases while a same-name parameter in a sibling function
    // remains unrelated. Every Object.assign source is inspected independently.
    $scopes = gateJsCallableScopes($code); $bindings = []; $created = [];
    foreach ($scopes as $scopeId => $scope) {
        foreach ($scope['params'] as $parameter) {
            $bindings[$scopeId][$parameter][] = [
                'offset' => $scope['start'], 'root' => null, 'api' => null,
            ];
        }
    }
    $canonicalPath = static function (string $raw) use ($resolve): ?string {
        $raw = trim($raw);
        if (!preg_match('~^([A-Za-z_$][\w$]*)~', $raw, $head)) return null;
        $path = $head[1]; $at = strlen($head[0]); $length = strlen($raw);
        while ($at < $length) {
            if (preg_match('~\G\s*\.\s*([A-Za-z_$][\w$]*)~', $raw, $part, 0, $at)) {
                $path .= '.' . $part[1]; $at += strlen($part[0]); continue;
            }
            if (preg_match('~\G\s*\[\s*([^\]]+)\s*\]~', $raw, $part, 0, $at)) {
                $keyExpr = trim($part[1]);
                $key = preg_match('~^\d+$~', $keyExpr) ? $keyExpr : $resolve($keyExpr);
                if (!is_string($key) || $key === '' || preg_match('~^[A-Za-z_$][\w$]*$|^\d+$~', $key) !== 1) {
                    return null;
                }
                $path .= preg_match('~^\d+$~', $key) ? '[' . $key . ']' : '.' . $key;
                $at += strlen($part[0]); continue;
            }
            return null;
        }
        return $path;
    };
    $bindingAt = static function (string $name, int $offset) use (&$bindings, $scopes): ?array {
        $scope = gateJsScopeAt($scopes, $offset);
        while ($scope !== null) {
            $latest = null;
            foreach (($bindings[$scope][$name] ?? []) as $binding) {
                if ($binding['offset'] <= $offset && ($latest === null || $binding['offset'] > $latest['offset'])) {
                    $latest = $binding;
                }
            }
            if ($latest !== null) return $latest;
            $scope = $scopes[$scope]['parent'];
        }
        return null;
    };

    // Declaration и самостоятельное assignment — один ordered transfer.
    // Child block хранит свою версию: условное присваивание не становится
    // доказательством для кода после ветки.
    $events = [];
    $declaration = '~\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)(?:\s*=\s*([^;\r\n]+))?\s*(?=;|\r?$)~m';
    if (preg_match_all($declaration, $code, $items, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($items as $item) {
            $events[] = ['target' => $item[1][0], 'rhs' => (string)($item[2][0] ?? ''),
                'offset' => (int)$item[1][1], 'declaration' => true];
        }
    }
    $pathPattern = '[A-Za-z_$][\w$]*(?:\s*\.\s*[A-Za-z_$][\w$]*|\s*\[\s*(?:\d+|' . $expr . ')\s*\])*';
    $assignment = '~(?:^|[;{}\r\n])\s*(' . $pathPattern . ')\s*=\s*(?!=|>)([^;\r\n]+)~m';
    if (preg_match_all($assignment, $code, $items, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($items as $item) {
            $events[] = ['target' => $item[1][0], 'rhs' => $item[2][0],
                'offset' => (int)$item[1][1], 'declaration' => false];
        }
    }
    usort($events, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);
    foreach ($events as $event) {
        $targetPath = $canonicalPath($event['target']);
        if ($targetPath === null) continue;
        $rhs = trim($event['rhs']); $offset = (int)$event['offset'];
        $scope = gateJsScopeAt($scopes, $offset); $root = null; $api = null; $tag = '';
        if (preg_match('~^document\s*\.\s*createElement\s*\(\s*(' . $expr . ')\s*\)$~', $rhs, $create)) {
            $tag = strtolower((string)$resolve($create[1]));
        } elseif (preg_match('~^document\s*\[\s*(' . $expr . ')\s*\]\s*\(\s*(' . $expr . ')\s*\)$~', $rhs, $create)
            && strtolower((string)$resolve($create[1])) === 'createelement') {
            $tag = strtolower((string)$resolve($create[2]));
        } else {
            $aliasPath = $canonicalPath($rhs);
            if ($aliasPath !== null) {
                $prior = $bindingAt($aliasPath, $offset);
                $root = $prior['root'] ?? null;
                $api = $prior['api'] ?? null;
                $directApi = strtolower($aliasPath);
                if (in_array($directApi, [
                    'object.assign', 'reflect.set', 'object.defineproperty', 'object.defineproperties',
                ], true)) $api = $directApi;
            }
        }
        if (in_array($tag, ['input', 'select'], true)) {
            $root = $scope . ':' . $targetPath . ':' . $offset;
            $created[$root] = ['tag' => $tag, 'line' => $codeLineAt($offset)];
            if ($tag === 'select') $hits[] = $codeLineAt($offset);
        }
        $bindings[$scope][$targetPath][] = [
            'offset' => $offset, 'root' => $root, 'api' => $api,
        ];
    }
    $inputRootAt = static function (string $target, int $offset)
        use ($bindingAt, $canonicalPath, &$created): ?string {
        $path = $canonicalPath($target);
        if ($path === null) return null;
        $binding = $bindingAt($path, $offset); $root = $binding['root'] ?? null;
        return is_string($root) && (($created[$root]['tag'] ?? '') === 'input') ? $root : null;
    };
    $recordType = static function (string $target, string $nameExpr, string $typeExpr,
        bool $computed, int $offset) use (&$hits, $resolve, $codeLineAt, $inputRootAt): void {
        if ($inputRootAt($target, $offset) === null) return;
        $rawName = trim($nameExpr);
        $name = $computed || strpos($rawName, '__BXAPP_LIT_') === 0
            ? strtolower((string)$resolve($rawName)) : strtolower($rawName);
        $type = strtolower((string)$resolve($typeExpr));
        if ($name === 'type' && in_array($type, ['date', 'number'], true)) $hits[] = $codeLineAt($offset);
    };

    $target = '(' . $pathPattern . ')';
    $assignments = [
        '~\b' . $target . '\s*\.\s*type\s*=\s*(' . $expr . ')~',
        '~\b' . $target . '\s*\[\s*(' . $expr . ')\s*\]\s*=\s*(' . $expr . ')~',
        '~\b' . $target . '\s*\.\s*setAttribute\s*\(\s*(' . $expr . ')\s*,\s*(' . $expr . ')~',
        '~\b' . $target . '\s*\[\s*(' . $expr . ')\s*\]\s*\(\s*(' . $expr . ')\s*,\s*(' . $expr . ')~',
    ];
    foreach ($assignments as $kind => $pattern) {
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches as $match) {
            if ($kind === 3 && strtolower((string)$resolve($match[2][0])) !== 'setattribute') continue;
            $name = $kind === 0 ? 'type' : $match[$kind === 3 ? 3 : 2][0];
            $value = $match[$kind === 0 ? 2 : ($kind === 3 ? 4 : 3)][0];
            $recordType($match[1][0], $name, $value, $kind !== 0, (int)$match[0][1]);
        }
    }

    // Property mutation calls: direct/bracket API member либо доказанный callable alias.
    $apiCall = '~\b(' . $pathPattern . ')\s*\(~';
    if (preg_match_all($apiCall, $code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($calls as $call) {
            $callPath = $canonicalPath($call[1][0]);
            if ($callPath === null) continue;
            $identity = strtolower($callPath);
            if (!in_array($identity, [
                'object.assign', 'reflect.set', 'object.defineproperty', 'object.defineproperties'
            ], true)) {
                $binding = $bindingAt($callPath, (int)$call[0][1]);
                $identity = strtolower((string)($binding['api'] ?? ''));
            }
            if (!in_array($identity, [
                'object.assign', 'reflect.set', 'object.defineproperty', 'object.defineproperties'
            ], true)) continue;
            [$owner, $method] = explode('.', $identity, 2);
            $open = (int)$call[0][1] + strrpos($call[0][0], '(');
            $close = gateJsMatchingDelimiter($code, $open, '(', ')');
            if ($close === null) continue;
            $args = gateJsCallArguments($code, $open, $close);
            $targetName = trim((string)($args[0]['expr'] ?? ''));
            if ($canonicalPath($targetName) === null
                || $inputRootAt($targetName, (int)$call[0][1]) === null) continue;
            if ($owner === 'reflect' && $method === 'set' && count($args) >= 3) {
                $recordType($targetName, $args[1]['expr'], $args[2]['expr'], true, (int)$call[0][1]);
                continue;
            }
            if ($method === 'assign') {
                foreach (array_slice($args, 1) as $source) {
                    $body = trim($source['expr']);
                    if (strlen($body) < 2 || $body[0] !== '{' || substr($body, -1) !== '}') continue;
                    $body = substr($body, 1, -1);
                    $property = '~(?:^|,)\s*(\[\s*)?(' . $expr . ')(\s*\])?\s*:\s*(' . $expr . ')~';
                    if (!preg_match_all($property, $body, $properties, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
                    foreach ($properties as $item) {
                        $recordType($targetName, $item[2][0], $item[4][0],
                            $item[1][0] !== '' || $item[3][0] !== '', (int)$source['offset'] + (int)$item[0][1]);
                    }
                }
                continue;
            }
            if ($method === 'defineproperty' && count($args) >= 3
                && preg_match('~\bvalue\s*:\s*(' . $expr . ')~', $args[2]['expr'], $value)) {
                $recordType($targetName, $args[1]['expr'], $value[1], true, (int)$call[0][1]);
                continue;
            }
            if ($method === 'defineproperties' && count($args) >= 2) {
                $descriptor = '~(\[\s*)?(' . $expr . ')(\s*\])?\s*:\s*\{([^{}]*)\}~';
                if (!preg_match_all($descriptor, $args[1]['expr'], $properties, PREG_SET_ORDER)) continue;
                foreach ($properties as $property) {
                    if (!preg_match('~\bvalue\s*:\s*(' . $expr . ')~', $property[4], $value)) continue;
                    $recordType($targetName, $property[2], $value[1],
                        $property[1] !== '' || $property[3] !== '', (int)$call[0][1]);
                }
            }
        }
    }

    if (preg_match_all('~(?:setAttribute\s*\(\s*[\'"]role[\'"]\s*,\s*[\'"]|role\s*=\s*[\'"])(?:listbox|combobox)~i',
            $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) $hits[] = $lineAt((int)$match[1]);
    }

    // Action/people признаки считаются только внутри одного inline-handler. Иначе
    // onclick, await и disabled из трёх независимых функций складывались в ложный дубль.
    $braceEnd = static function (string $source, int $open): ?int {
        $depth = 0; $length = strlen($source);
        for ($at = $open; $at < $length; $at++) {
            if ($source[$at] === '{') $depth++;
            if ($source[$at] !== '}') continue;
            $depth--;
            if ($depth === 0) return $at;
        }
        return null;
    };
    $handlers = [];
    $rememberHandler = static function (array $match, string $control, string $event, string $prefix)
        use (&$handlers, $code, $braceEnd): void {
        $open = (int)$match[0][1] + strrpos($match[0][0], '{');
        $end = $braceEnd($code, $open);
        if ($end === null) return;
        $handlers[] = [
            'control' => $control,
            'event' => strtolower($event),
            'async' => preg_match('~\basync\b~i', $prefix) === 1,
            'body' => substr($code, $open + 1, $end - $open - 1),
            'offset' => (int)$match[0][1],
        ];
    };
    $callable = '((?:async\s*)?(?:function(?:\s+[A-Za-z_$][\w$]*)?\s*\([^)]*\)'
        . '|(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>))';
    $propertyHandler = '~\b([A-Za-z_$][\w$]*)\s*(?:\.\s*(on(?:click|input))'
        . '|\[\s*(' . $expr . ')\s*\])\s*=\s*' . $callable . '\s*\{~i';
    if (preg_match_all($propertyHandler, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $property = $match[2][0] !== '' ? $match[2][0] : (string)$resolve($match[3][0]);
            if (!in_array(strtolower($property), ['onclick', 'oninput'], true)) continue;
            $rememberHandler($match, $match[1][0], substr(strtolower($property), 2), $match[4][0]);
        }
    }
    $dotListener = '~\b([A-Za-z_$][\w$]*)\s*\.\s*addEventListener\s*\(\s*('
        . $expr . ')\s*,\s*' . $callable . '\s*\{~i';
    if (preg_match_all($dotListener, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $event = strtolower((string)$resolve($match[2][0]));
            if (!in_array($event, ['click', 'input', 'search'], true)) continue;
            $rememberHandler($match, $match[1][0], $event, $match[3][0]);
        }
    }
    $bracketListener = '~\b([A-Za-z_$][\w$]*)\s*\[\s*(' . $expr . ')\s*\]\s*\(\s*('
        . $expr . ')\s*,\s*' . $callable . '\s*\{~i';
    if (preg_match_all($bracketListener, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            if (strtolower((string)$resolve($match[2][0])) !== 'addeventlistener') continue;
            $event = strtolower((string)$resolve($match[3][0]));
            if (!in_array($event, ['click', 'input', 'search'], true)) continue;
            $rememberHandler($match, $match[1][0], $event, $match[4][0]);
        }
    }
    $peopleValue = static fn(string $value): bool => preg_match(
        '~(?:^|/)users\.php(?:\?|$)|\b(?:user\.get|user\.search|department\.get)\b~i', $value
    ) === 1;
    foreach ($handlers as $handler) {
        $body = (string)$handler['body']; $control = preg_quote((string)$handler['control'], '~');
        if ($handler['event'] === 'click') {
            $isAsync = $handler['async'] || preg_match('~\bawait\b~', $body) === 1;
            $hasBusy = preg_match('~\b' . $control . '\s*\.\s*disabled\s*=\s*(?:true|false)~i', $body) === 1;
            if (!$hasBusy && preg_match_all(
                '~\b' . $control . '\s*\[\s*(' . $expr . ')\s*\]\s*=\s*(?:true|false)~i',
                $body, $busyAssignments, PREG_SET_ORDER
            )) {
                foreach ($busyAssignments as $assignment) {
                    if (strtolower((string)$resolve($assignment[1])) === 'disabled') { $hasBusy = true; break; }
                }
            }
            if (!$hasBusy && preg_match_all(
                '~\b' . $control . '\s*(?:\.\s*([A-Za-z_$][\w$]*)|\[\s*(' . $expr
                . ')\s*\])\s*\(\s*(' . $expr . ')~i', $body, $busyCalls, PREG_SET_ORDER
            )) {
                foreach ($busyCalls as $call) {
                    $method = $call[1] !== '' ? $call[1] : (string)$resolve($call[2]);
                    $attribute = strtolower((string)$resolve($call[3]));
                    if (in_array(strtolower($method), ['setattribute', 'removeattribute'], true)
                        && in_array($attribute, ['disabled', 'aria-busy'], true)) {
                        $hasBusy = true; break;
                    }
                }
            }
            if (!$hasBusy) {
                $hasBusy = preg_match('~\b' . $control
                    . '\s*\.\s*classList\s*\.\s*(?:add|toggle)\s*\(\s*__BXAPP_LIT_\d+_\d+__~i', $body) === 1;
            }
            if ($isAsync && $hasBusy) $hits[] = $codeLineAt((int)$handler['offset']);
            continue;
        }
        if (!in_array($handler['event'], ['input', 'search'], true)) continue;
        $hasPeopleSource = false;
        if (preg_match_all('~__BXAPP_LIT_(\d+)_\d+__~', $body, $literalRefs)) {
            foreach ($literalRefs[1] as $index) {
                $value = $values[(int)$index] ?? null;
                if (is_string($value) && $peopleValue($value)) { $hasPeopleSource = true; break; }
            }
        }
        if (!$hasPeopleSource) {
            foreach ($known as $name => $value) {
                if (!preg_match('~\b' . preg_quote((string)$name, '~') . '\b~', $body)) continue;
                if ($peopleValue((string)$value)) { $hasPeopleSource = true; break; }
            }
        }
        $renders = preg_match('~\b(?:append|appendChild|insertAdjacentHTML|replaceChildren)\s*\(~i', $body) === 1;
        if ($hasPeopleSource && $renders) $hits[] = $codeLineAt((int)$handler['offset']);
    }
    sort($hits);
    return array_values(array_unique($hits));
}

/**
 * Копия ready endpoint определяется по узкой связке его helper/method contracts,
 * а не по basename. Один вызов готового helper — законный reuse, не копия.
 */
function gateDuplicateReadyEndpoint(string $file): bool {
    if (!is_file($file)) return false;
    $tokens = token_get_all((string)file_get_contents($file));
    $symbols = []; $strings = []; $declared = []; $declarationIndexes = [];
    $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
    if (defined('T_NAME_RELATIVE')) $nameTokens[] = constant('T_NAME_RELATIVE');
    $nextSignificant = static function (array $all, int $at): ?int {
        for ($i = $at + 1, $n = count($all); $i < $n; $i++) {
            if (is_array($all[$i])
                && in_array($all[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return $i;
        }
        return null;
    };
    $prevSignificant = static function (array $all, int $at): ?int {
        for ($i = $at - 1; $i >= 0; $i--) {
            if (is_array($all[$i])
                && in_array($all[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return $i;
        }
        return null;
    };
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = gatePhpLiteralValue($token[1]);
            if ($value !== null) $strings[strtolower($value)] = true;
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
        $nameAt = $nextSignificant($tokens, $i);
        if ($nameAt !== null && $tokens[$nameAt] === '&') $nameAt = $nextSignificant($tokens, $nameAt);
        if ($nameAt !== null && is_array($tokens[$nameAt]) && $tokens[$nameAt][0] === T_STRING) {
            $name = strtolower($tokens[$nameAt][1]);
            $declared[$name] = true; $declarationIndexes[$nameAt] = true;
        }
    }
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || !in_array($token[0], $nameTokens, true)
            || isset($declarationIndexes[$i])) continue;
        $open = $nextSignificant($tokens, $i);
        if ($open === null || $tokens[$open] !== '(') continue;
        $prev = $prevSignificant($tokens, $i);
        $prevId = ($prev !== null && is_array($tokens[$prev])) ? $tokens[$prev][0] : null;
        $prevText = $prev === null ? '' : (is_array($tokens[$prev]) ? $tokens[$prev][1] : $tokens[$prev]);
        if (in_array($prevId, [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            || in_array($prevText, ['->', '::'], true)) continue;
        $qualified = ltrim($token[1], '\\');
        $name = strtolower((string)preg_replace('~^.*\\\\~', '', $qualified));
        $symbols[$name] = true;
    }
    $count = static function (array $needles, array $haystack): int {
        $n = 0;
        foreach ($needles as $needle) if (isset($haystack[strtolower($needle)])) $n++;
        return $n;
    };
    if ($count(['settingsForClient', 'settingsEffective', 'settingsValidate'], $symbols) >= 2) return true;
    if (isset($symbols['taskforclient'])
        && $count(['taskStart', 'taskCancel', 'taskForget'], $symbols) >= 1) return true;
    if (isset($strings['user.get']) && isset($strings['department.get'])) return true;
    if ($count(['csvLine', 'csvWrite', 'csvDownload'], $declared) >= 2) return true;
    if ($count(['planRead', 'capRead', 'placementsRead', 'journalQuery', 'queueStats'], $symbols) >= 3) return true;
    if (isset($symbols['journalquery']) && isset($symbols['journaldays'])) return true;
    return isset($symbols['appwithkeylock']) && isset($symbols['auditlog'])
        && isset($symbols['storeread']) && isset($symbols['storeupdate']);
}

/**
 * Прямые вызовы named PHP-функции с аргументами и переменной результата.
 *
 * Comments/strings, объявления и object/static methods не считаются вызовом:
 * это даёт узкую структурную сигнатуру для journalQuery/apiStr/apiOk.
 */
function gateNamedFunctionCalls(string $file, string $name): array {
    if (!is_file($file)) return [];
    $tokens = gateFlatTokens($file);
    $out = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) continue;
        $called = (string)preg_replace('~^.*\\\\~', '', $tokens[$i]['text']);
        if (strcasecmp($called, $name) !== 0) continue;
        $prev = gatePrevToken($tokens, $i);
        if ($prev !== null && $tokens[$prev]['text'] === '&') $prev = gatePrevToken($tokens, $prev);
        if ($prev !== null && (
            in_array($tokens[$prev]['id'], [T_FUNCTION, T_NEW], true)
            || in_array($tokens[$prev]['text'], ['->', '::'], true)
        )) continue;
        $open = gateNextToken($tokens, $i);
        if ($open === null || $tokens[$open]['text'] !== '(') continue;
        $close = gateMatchingToken($tokens, $open);
        if ($close === null) continue;

        $result = '';
        if ($prev !== null && $tokens[$prev]['text'] === '=') {
            $lhs = gatePrevToken($tokens, $prev);
            if ($lhs !== null && $tokens[$lhs]['id'] === T_VARIABLE) $result = $tokens[$lhs]['text'];
        }
        $out[] = [
            'args' => gateCallArgTokens($tokens, $open, $close),
            'line' => (int)$tokens[$i]['line'],
            'offset' => (int)$tokens[$i]['offset'],
            'resultVar' => $result,
        ];
        $i = $close;
    }
    return $out;
}

/** Direct short-array либо единственное присвоение переменной этому массиву. */
function gatePolicyArrayMap(string $file, string $expr): ?array {
    $expr = gateTrimExpressionParens($expr);
    if (preg_match('~^\$(\w+)$~D', $expr, $match)) {
        $vars = gateVarMap($file);
        $values = $vars[$match[1]] ?? [];
        if (count($values) !== 1) return null;
        $expr = (string)$values[0];
    }
    return gateShortArrayMap($expr, []);
}

/** Direct expression либо единственное присвоение простой переменной. */
function gatePolicyExpression(string $file, string $expr): ?string {
    $expr = gateTrimExpressionParens($expr);
    if (!preg_match('~^\$(\w+)$~D', $expr, $match)) return $expr;
    $vars = gateVarMap($file);
    $values = $vars[$match[1]] ?? [];
    return count($values) === 1 ? (string)$values[0] : null;
}

/** Верхнеуровневые integer constants endpoint-а. */
function gateIntegerConstants(string $file): array {
    $out = [];
    foreach (gateTopStatements($file) as $statement) {
        if ($statement['kind'] !== 'const') continue;
        if (!preg_match('~^const\s+([A-Z_][A-Z0-9_]*)\s*=\s*([0-9]+)\s*;?$~i',
            trim((string)$statement['code']), $match)) continue;
        $out[strtoupper($match[1])] = (int)$match[2];
    }
    return $out;
}

/** Literal/constant и единственное +integer — ровно формы bounded page size. */
function gateBoundedInteger(string $expr, array $constants): ?int {
    $expr = preg_replace('~\s+~', '', gateTrimExpressionParens($expr)) ?? '';
    if (preg_match('~^[0-9]+$~D', $expr)) return (int)$expr;
    if (preg_match('~^([A-Z_][A-Z0-9_]*)$~i', $expr, $match)) {
        return $constants[strtoupper($match[1])] ?? null;
    }
    if (preg_match('~^([A-Z_][A-Z0-9_]*)\+([0-9]+)$~i', $expr, $match)) {
        $base = $constants[strtoupper($match[1])] ?? null;
        return $base === null ? null : $base + (int)$match[2];
    }
    if (preg_match('~^([0-9]+)\+([A-Z_][A-Z0-9_]*)$~i', $expr, $match)) {
        $base = $constants[strtoupper($match[2])] ?? null;
        return $base === null ? null : (int)$match[1] + $base;
    }
    return null;
}

/** JS literal/alias действительно передан в ui.api как этот exact endpoint. */
function gateJsCallsEndpoint(array $model, string $endpoint): bool {
    $code = (string)($model['code'] ?? '');
    $values = (array)($model['values'] ?? []);
    $lines = (array)($model['lines'] ?? []);
    $matchesEndpoint = static function ($value) use ($endpoint): bool {
        if (!is_string($value)) return false;
        $value = ltrim(trim($value), './');
        return $value === $endpoint
            || strncmp($value, $endpoint . '?', strlen($endpoint) + 1) === 0;
    };
    foreach ($values as $index => $value) {
        if (!$matchesEndpoint($value)) continue;
        $marker = '__BXAPP_LIT_' . $index . '_' . (int)($lines[$index] ?? 0) . '__';
        if (preg_match('~\bui\s*\.\s*api\s*\([\s\S]{0,500}'
            . preg_quote($marker, '~') . '~', $code)) return true;
    }
    foreach (gateJsKnownStaticStrings($model) as $name => $value) {
        if (!$matchesEndpoint($value)) continue;
        if (preg_match('~\bui\s*\.\s*api\s*\([\s\S]{0,500}\b'
            . preg_quote((string)$name, '~') . '\b~', $code)) return true;
    }
    return false;
}

/** Literal prev/back/next относится к реальному DOM lookup, не к сообщению. */
function gateJsHasPagerControl(array $model, string $direction): bool {
    $code = (string)($model['code'] ?? '');
    foreach ((array)($model['values'] ?? []) as $index => $value) {
        if (!is_string($value)) continue;
        $word = $direction === 'next' ? 'next' : '(?:prev|back)';
        if (!preg_match('~(?:^|[-_])' . $word . '(?:[-_]|$)~i', $value)) continue;
        $line = (int)(($model['lines'] ?? [])[$index] ?? 0);
        $marker = '__BXAPP_LIT_' . $index . '_' . $line . '__';
        if (preg_match('~\b(?:getElementById|querySelector)\s*\(\s*'
            . preg_quote($marker, '~') . '\s*\)~', $code)) return true;
    }
    return false;
}

/** Связанный journal UI имеет cursor request, response token и обе кнопки. */
function gateJournalPagerUiOk(string $file, string $endpoint): bool {
    $source = (string)@file_get_contents($file);
    if ($source === '') return false;
    $model = gateJsCredentialModel($source);
    if (!gateJsCallsEndpoint($model, $endpoint)) return false;
    $hasCursorQuery = false;
    foreach ((array)$model['values'] as $value) {
        if (is_string($value) && stripos($value, 'cursor=') !== false) {
            $hasCursorQuery = true;
            break;
        }
    }
    $code = (string)$model['code'];
    $clickAssignments = preg_match_all('~\.\s*onclick\s*=\s*function\b~', $code);
    $clickListeners = preg_match_all('~\.\s*addEventListener\s*\(~', $code);
    return $hasCursorQuery
        && preg_match('~\.\s*next_cursor\b~', $code) === 1
        && gateJsHasPagerControl($model, 'prev')
        && gateJsHasPagerControl($model, 'next')
        && (($clickAssignments !== false ? $clickAssignments : 0)
            + ($clickListeners !== false ? $clickListeners : 0) >= 2)
        && preg_match('~\.\s*push\s*\(~', $code) === 1
        && preg_match('~\.\s*pop\s*\(~', $code) === 1;
}

/**
 * journalQuery-result действительно опубликован как top-level rows экрана.
 * Bounded health-summary (last_errors, 5 строк) — другой контракт и pager не
 * требует; одно слово journalQuery не превращает его в полноразмерный журнал.
 */
function gateJournalRowsExposed(string $file, string $resultVar): bool {
    if ($resultVar === '') return false;
    $needle = gateNormalizeExpression($resultVar . "['rows']");
    foreach (gateNamedFunctionCalls($file, 'apiOk') as $answer) {
        $response = gatePolicyArrayMap($file, (string)(($answer['args'] ?? [])[0] ?? ''));
        if ($response === null || !isset($response['rows'])) continue;
        $rows = gatePolicyExpression($file, (string)$response['rows']);
        if ($rows === null) continue;
        if (strpos(gateNormalizeExpression($rows), $needle) !== false) return true;
    }
    return false;
}

/**
 * Экран существующего journal: response максимум 25, cursor — opaque rid/ref,
 * а каждый реально связанный ui.api экран имеет Back/Next. Обычные таблицы и
 * bounded health-summary без top-level rows вообще не входят в этот контракт.
 */
function gateJournalScreenProblems(string $root): array {
    $apiRoot = rtrim($root, '/\\') . '/www/api';
    $frontRoot = rtrim($root, '/\\') . '/www';
    $frontFiles = array_values(array_unique(array_merge(
        gateFilesUnder($frontRoot, 'js'),
        gateFilesUnder($frontRoot, 'mjs'),
        gateFilesUnder($frontRoot, 'cjs')
    )));
    $problems = [];
    foreach (gateFilesUnder($apiRoot, 'php') as $file) {
        $journalCalls = array_values(array_filter(
            gateNamedFunctionCalls($file, 'journalQuery'),
            static fn(array $call): bool => gateJournalRowsExposed(
                $file, (string)($call['resultVar'] ?? '')
            )
        ));
        if ($journalCalls === []) continue;
        $relative = gateRelativePath($root, $file);
        $endpoint = preg_replace('~^www/~', '', $relative) ?? $relative;
        $constants = gateIntegerConstants($file);

        $cursorVars = [];
        foreach (gateNamedFunctionCalls($file, 'apiStr') as $call) {
            $args = (array)$call['args'];
            $field = isset($args[1]) ? gateStaticLiteralExpression($args[1]) : null;
            if ($field !== 'cursor' || ($call['resultVar'] ?? '') === '') continue;
            if (gateNormalizeExpression((string)($args[0] ?? '')) !== '$_GET') continue;
            $cursorVars[(string)$call['resultVar']] = true;
        }

        foreach ($journalCalls as $journalCall) {
            $ok = true;
            $filter = gatePolicyArrayMap($file, (string)(($journalCall['args'] ?? [])[0] ?? ''));
            if ($filter === null || !isset($filter['limit'], $filter['cursor'])) {
                $ok = false;
            }
            $limit = $filter === null ? null : gateBoundedInteger((string)$filter['limit'], $constants);
            $cursorExpr = $filter !== null && isset($filter['cursor'])
                ? gateNormalizeExpression((string)$filter['cursor']) : '';
            if ($limit === null || $limit < 1 || $limit > 26 || !isset($cursorVars[$cursorExpr])) {
                $ok = false;
            }

            $journalResult = (string)($journalCall['resultVar'] ?? '');
            $pageRows = ''; $pageSize = null;
            foreach (gateNamedFunctionCalls($file, 'array_slice') as $slice) {
                $args = (array)$slice['args'];
                if (count($args) < 3 || ($slice['resultVar'] ?? '') === '') continue;
                if ($journalResult === ''
                    || gateNormalizeExpression((string)$args[0])
                        !== gateNormalizeExpression($journalResult . "['rows']")) continue;
                $candidate = gateBoundedInteger((string)$args[2], $constants);
                if ($candidate === null || $candidate < 1 || $candidate > 25) continue;
                $pageRows = (string)$slice['resultVar'];
                $pageSize = $candidate;
                break;
            }
            if ($pageRows === '' || $pageSize === null
                || $limit === null || $limit < $pageSize || $limit > $pageSize + 1) {
                $ok = false;
            }

            $responseOk = false;
            foreach (gateNamedFunctionCalls($file, 'apiOk') as $answer) {
                $response = gatePolicyArrayMap($file, (string)(($answer['args'] ?? [])[0] ?? ''));
                if ($response === null
                    || !isset($response['rows'], $response['pagesize'], $response['nextcursor'])) continue;
                if (gateNormalizeExpression((string)$response['rows'])
                    !== gateNormalizeExpression($pageRows)) continue;
                $answerSize = gateBoundedInteger((string)$response['pagesize'], $constants);
                if ($answerSize !== $pageSize) continue;
                $next = gatePolicyExpression($file, (string)$response['nextcursor']);
                if ($next === null
                    || preg_match('~[\'\"](?:rid|ref)[\'\"]~i', $next) !== 1) continue;
                $responseOk = true;
                break;
            }
            if (!$responseOk) $ok = false;
            if (!$ok) {
                $problems[] = $relative
                    . ': journalQuery обязан отдавать ≤25 rows, принимать string cursor '
                    . 'и возвращать opaque next_cursor из rid/ref';
            }
        }

        foreach ($frontFiles as $front) {
            $model = gateJsCredentialModel((string)@file_get_contents($front));
            if (!gateJsCallsEndpoint($model, $endpoint)) continue;
            if (!gateJournalPagerUiOk($front, $endpoint)) {
                $problems[] = gateRelativePath($root, $front) . ': экран ' . $endpoint
                    . ' обязан иметь связанный cursor stack и кнопки Back/Next';
            }
        }
    }
    sort($problems);
    return array_values(array_unique($problems));
}

/**
 * Project endpoint не изобретает поиск сотрудников: NAME_SEARCH в user.get
 * означает reuse готовых users.php + ui.pickPeople. Точечный FILTER[ID] сюда
 * не попадает и остаётся законным.
 */
function gateDirectEmployeeNameSearchLines(string $file): array {
    $hits = [];
    foreach (gatePortalCallDetails($file) as $call) {
        if (strcasecmp((string)($call['method'] ?? ''), 'user.get') !== 0) continue;
        $params = gatePolicyArrayMap($file, (string)($call['params'] ?? ''));
        if ($params === null || !isset($params['filter'])) continue;
        $filter = gatePolicyArrayMap($file, (string)$params['filter']);
        if ($filter === null || !array_key_exists('namesearch', $filter)) continue;
        $hits[] = (int)($call['line'] ?? 0);
    }
    sort($hits);
    return array_values(array_unique($hits));
}

/** CSS без comments/strings, с сохранением длины и строк для diagnostics. */
function gateCssPolicyCode(string $source): string {
    $out = ''; $length = strlen($source);
    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i];
        $next = $i + 1 < $length ? $source[$i + 1] : '';
        if ($char === '/' && $next === '*') {
            $out .= '  '; $i++;
            while ($i + 1 < $length) {
                if ($source[$i] === '*' && $source[$i + 1] === '/') {
                    $out .= '  '; $i++;
                    break;
                }
                $out .= in_array($source[$i + 1], ["\n", "\r"], true)
                    ? $source[$i + 1] : ' ';
                $i++;
            }
            continue;
        }
        if ($char !== "'" && $char !== '"') {
            $out .= $char;
            continue;
        }
        $quote = $char; $out .= ' ';
        for ($i++; $i < $length; $i++) {
            $current = $source[$i];
            if ($current === '\\' && $i + 1 < $length) {
                $out .= '  '; $i++;
                continue;
            }
            $out .= in_array($current, ["\n", "\r"], true) ? $current : ' ';
            if ($current === $quote) break;
        }
    }
    return $out;
}

/** Номера строк calc-arithmetic, где exact --motion-* был тайно изменён. */
function gateMotionCssArithmeticLines(string $file): array {
    $source = (string)@file_get_contents($file);
    if ($source === '') return [];
    $code = gateCssPolicyCode($source);
    if (!preg_match_all('~\bcalc\s*\(~i', $code, $matches, PREG_OFFSET_CAPTURE)) return [];
    $hits = [];
    foreach ($matches[0] as $match) {
        $open = strpos($code, '(', (int)$match[1]);
        if ($open === false) continue;
        $depth = 0; $close = null;
        for ($at = $open, $length = strlen($code); $at < $length; $at++) {
            if ($code[$at] === '(') $depth++;
            elseif ($code[$at] === ')' && --$depth === 0) {
                $close = $at;
                break;
            }
        }
        if ($close === null) continue;
        $body = substr($code, $open + 1, $close - $open - 1);
        if (preg_match('~var\(\s*--motion-[\w-]+~i', $body) !== 1) continue;
        if (preg_match('~[+*/]|\s-\s~', $body) !== 1) continue;
        $hits[] = 1 + substr_count(substr($code, 0, $open), "\n");
    }
    sort($hits);
    return array_values(array_unique($hits));
}

/** Независимая от JSON карта identity/ownership всех защищённых capabilities. */
function gateProtectedCapabilityContracts(): array {
    return [
        'portal-transport' => ['file' => 'www/api/b24.php', 'status' => 'ready',
            'entry' => ['b24'], 'tests' => ['tests/b24_test.php'],
            'policy_hash' => '5950fc41c4e5c9a489c3a708a48f4484261367c7aa3f0c0a48f9f8624ee5daf4',
            'checks_hash' => '3fdcc6771e6a938e7781460403c3ff0ad20665481a49f7ca66fba551fb260263'],
        'state-store' => ['file' => 'www/api/store.php', 'status' => 'ready',
            'entry' => ['storeRead', 'storeUpdate', 'storeWrite', 'withKeyLock'],
            'tests' => ['tests/store_test.php'],
            'policy_hash' => '6895f1a5d2b443afb890983641259f9ab0b6e807e57523422e785de8bc9c5c85',
            'checks_hash' => '80dc841d4e86a6a3d7c81f9d56dde6722a0ddc904f79cd5b881854887b1c2104'],
        'heavy-response-cache' => ['file' => 'www/api/cache.php', 'status' => 'ready',
            'entry' => ['cacheForget', 'cachePeek', 'cacheRemember'],
            'tests' => ['tests/modules_test.php', 'tests/store_test.php'],
            'policy_hash' => '75966f01bd111fde8bae61edf7f689dc9ed4b853629d99bb7e3da1a3ea9ac773',
            'checks_hash' => '26fc1d20401b8f4032af2313a09b13d236dd6749ef902fc8786a70507ac7fc9f'],
        'foreign-http' => ['file' => 'www/api/lib.php', 'status' => 'ready',
            'entry' => ['httpJson', 'httpRetryAfterSeconds'], 'tests' => ['tests/http_test.php'],
            'policy_hash' => '02738741888f9dea45e9a1db78b568c2052ad2589fb66ee4230f88a6f91ae37b',
            'checks_hash' => '27405156372cd946b8fb8fc8a53d41139db603cf3dabf784d7a60179b17ddb24'],
        'api-entry' => ['file' => 'www/api/http.php', 'status' => 'ready',
            'entry' => ['apiBoot', 'apiEnum', 'apiFail', 'apiOk'],
            'tests' => ['tests/http_test.php', 'tests/security_test.php'],
            'policy_hash' => '376113c8ec554300ae74ac5571ccc91e1c1895f22c4f8989f78d3a8570331ae1',
            'checks_hash' => '9822a50b8d638e0422a865ec196a9468d3a7dab746be28b138969cca8299a00a'],
        'journal' => ['file' => 'www/api/journal.php', 'status' => 'ready',
            'entry' => ['appLog', 'logRef'],
            'tests' => ['tests/gate_test.php', 'tests/resource_limits_test.php', 'tests/store_test.php'],
            'policy_hash' => '724b1e6744cb3c212b3ff884463a31f429d0c8b0a4300eb0c7d1ab111eab12e4',
            'checks_hash' => '1a53a187cdc101295bbd0610b3d191ab364b32ef04f34417d340343ffdeddbe1'],
        'portal-events' => ['file' => 'www/api/queue.php', 'status' => 'ready',
            'entry' => ['queueAppendLimited', 'queueEventLogLabel'],
            'tests' => ['tests/review_fixes_test.php'],
            'project_entry' => ['handleEvent (www/bin/process.php)'],
            'project_note' => 'Код проекта пишется в projectHandleEvent() файла www/api/project-hooks.php — его зовёт handleEvent() ядра, сам файл ядра не правят; функции из entry зовёт сам движок.',
            'policy_hash' => '08098f36aeee12221033b2fe0848dc8f97aeba8437f8768f9cb92a41a1fbedea',
            'checks_hash' => 'ff2e7dd4be2bac63ea57db9496000ba14567db9b9b076896a8783b215133ab89'],
        'events-bind' => ['file' => 'www/api/events-bind.php', 'status' => 'ready',
            'entry' => ['bindEvents', 'eventsReconcile', 'eventsSpec'],
            'tests' => ['tests/modules_test.php'],
            'policy_hash' => 'ada73a6e95e112980bb7e8484f4d5635be0c27cce7e97743810dc6ac7ffb4997',
            'checks_hash' => '90e0f6d09d37146e0553f719585add3471cfca501352ffd580e0610a60848744'],
        'long-task' => ['file' => 'www/api/task-state.php', 'status' => 'ready',
            'entry' => ['taskClaimStep', 'taskFinish', 'taskMarkUnknown', 'taskPendingStep', 'taskProgress',
                'taskReconcileStep', 'taskStart', 'taskStepKey'],
            'tests' => ['tests/task_test.php'],
            'project_entry' => ['taskReconcileUnknownStep (www/bin/process.php)',
                'taskStep (www/bin/process.php)'],
            'project_note' => 'Код проекта пишется в projectTaskStep() файла www/api/project-hooks.php — одна порция работы, её зовёт taskStep() ядра. Если исход unknown, projectReconcileUnknownStep() там же делает свежий project-specific read-back и возвращает доказанный исход; default null оставляет задачу fail-closed. Функции из entry держат состояние и применяют reconciliation CAS-ом.',
            'policy_hash' => 'b69e3df59197e6d3b0cb7f0a28a75e81558243fb89f6eb9802f88e8e86d2de06',
            'checks_hash' => '2e4f1b3763c1458745e2d9e0a7049385314433de733f5a9ce8d7d2506754a9f4'],
        'unknown-outcome' => ['file' => 'www/api/access.php', 'status' => 'ready',
            'entry' => ['runOnce', 'runOnceForget'], 'tests' => ['tests/store_test.php'],
            'policy_hash' => 'a07aebe625fe3170ebd2b6f0c11f0bd8c36b1b507431f977a91960a8069cc754',
            'checks_hash' => '537f3d1c43c717358602c3ec4b8900afcf4268daf81eeb1134760a858cfd2c0c'],
        'placements' => ['file' => 'www/api/placements.php', 'status' => 'ready',
            'entry' => ['placementHandlerUrl', 'placementSpec'],
            'tests' => ['tests/modules_test.php'],
            'policy_hash' => 'b71c12db6522368b378ca476f6d0074e048f2b856c0c200e0731b396043735ce',
            'checks_hash' => '8f1cf1beb71b4203a17094d8c11bddbc1ad0bda80131ae38117c130bd61a43c4'],
        'portal-plan' => ['file' => 'www/api/plan.php', 'status' => 'ready',
            'entry' => ['planBucket', 'planHuman', 'planRatePerSecond', 'planRefresh'],
            'tests' => ['tests/plan_test.php'],
            'policy_hash' => 'bb3382aeded5c1e5a0e64815e3ee538ca9d79c5e21be19e7872822c333b87573',
            'checks_hash' => '3d30c08f76903968c866b0b1a482da5838ace4eee940a5d14f11a7c49d3481b2'],
        'portal-capability' => ['file' => 'www/api/capability.php', 'status' => 'ready',
            'entry' => ['capAllowed', 'capHuman', 'capMissing', 'capMissingRequired', 'capRefresh'],
            'tests' => ['tests/capability_test.php'],
            'policy_hash' => '7ed5be5ea2ba83125228b07f71d03bee6866dd7c0a2dada71a952edf595271aa',
            'checks_hash' => 'b11f87f1e2652a2608064d5a180a2c5dd967d28550cc9e251215b08032aec9e0'],
        'crm-dictionary' => ['file' => 'www/api/crm-dict.php', 'status' => 'ready',
            'entry' => ['crmDict', 'crmDictStageName'], 'tests' => ['tests/crm_dict_test.php'],
            'policy_hash' => 'b16495610c4e8c5c99b57909eda9a5e1fcef81a540c57fd0069e036291ae1911',
            'checks_hash' => '6873d9e30118d5a88db6b696be2d01b285bdd66cc9515f42c5fb2284665ddb98'],
        'dates-money-csv' => ['file' => 'www/api/fmt.php', 'status' => 'ready',
            'entry' => ['b24Day', 'b24Range', 'money', 'moneyStr'],
            'tests' => ['tests/b24_test.php', 'tests/modules_test.php'],
            'policy_hash' => '92b51257f00e7442ef829053855a8754cf84d24c45f0f56fb29bef895f5e6a56',
            'checks_hash' => '73cd119fb756d795dc40defdfa4f91664182406db4594f4e213ee11cad58fb9a'],
        'access-acl' => ['file' => 'www/api/access.php', 'status' => 'ready',
            'entry' => ['aclLoad', 'aclUpdate', 'auditLog', 'roleAtLeast'],
            'tests' => ['tests/security_test.php', 'tests/store_test.php'],
            'policy_hash' => '3315f8f45fcd6c0ff77d75c65687cb0a0097f4fbe869266c58171a68e1e3651e',
            'checks_hash' => '407cdbe7fa072ab1246f32f667878ed27e46d8404f12c251d4a79c041a591027'],
        'portal-user-fields' => ['file' => 'www/api/uf.php', 'status' => 'experimental',
            'entry' => ['ufEnsure', 'ufFieldName'], 'tests' => ['tests/uf_test.php'],
            'policy_hash' => '46df50c045e4222daf4d2b735957b84a8a0f0c5894076b67f9cd5c35522818ad',
            'checks_hash' => '4ff08de039cfa9c871e0c9610d80e47727428483f67e463b70e9f04407e445a0'],
        'user-notifications' => ['file' => 'www/api/notify.php', 'status' => 'experimental',
            'entry' => ['notifyDrain', 'notifyFailed', 'notifyQueue'],
            'tests' => ['tests/notify_test.php'],
            'policy_hash' => '936ce77a2f8fa79488c689efb8f085af0320654250a509b2b37f165dc4a4ca9a',
            'checks_hash' => 'e0ad6065da6368b68a9943f17a091a724ce1133ffe7d5d24f7a185ee11e916aa'],
        'return-points' => ['file' => 'scripts/snap.sh', 'status' => 'experimental',
            'entry' => ['data-restore', 'data-save', 'diff', 'list', 'restore', 'save'],
            'tests' => ['tests/snap_test.php'],
            'policy_hash' => '266cba09a5aa118ebc1926c8cf2cba11167017faaa242eeb4ca1c49bc4899e06',
            'checks_hash' => '7d22413bb23b01bb31625e15e8e42017ebbb87d27375a846d18aa3113dd4aaad'],
        'fatal-response' => ['file' => 'www/api/lib.php', 'status' => 'ready',
            'entry' => ['appChangesMayBeApplied', 'appChangesStarted', 'installFailureHandler'],
            'tests' => ['tests/fatal_test.php'],
            'policy_hash' => '42a9b5ae67b35121f4416f53e7971de7d4daed1eb84875739b7d550d117ade34',
            'checks_hash' => '30af0937ed5452a494530b8f1e2867752f7d2662a1979c6c160b4c9cf9a9c271'],
    ];
}

/** Независимый от JSON защищённый состав template capabilities. */
function gateProtectedCapabilityIds(): array {
    return array_keys(gateProtectedCapabilityContracts());
}

/** Exact ownership template companion-файлов не выводится из изменяемого JSON. */
function gateProtectedCompanionOwners(): array {
    return [
        'www/api/acl.php' => 'access-acl',
        'www/api/bind.php' => 'events-bind',
        'www/api/event.php' => 'portal-events',
        'www/api/export-csv.php' => 'dates-money-csv',
        'www/api/fetch-all-compat.php' => 'portal-transport',
        'www/api/health.php' => 'portal-capability',
        'www/api/logs.php' => 'journal',
        'www/api/ops.php' => 'unknown-outcome',
        'www/api/session.php' => 'access-acl',
        'www/api/settings-schema.php' => 'api-entry',
        'www/api/settings.php' => 'api-entry',
        'www/api/task.php' => 'long-task',
        'www/api/users.php' => 'access-acl',
    ];
}

/** Public ready UI: symbol и файл-владелец — один защищённый контракт. */
function gateProtectedReadyUiContracts(): array {
    return [
        'ui.select' => 'www/js/controls.js',
        'ui.number' => 'www/js/controls.js',
        'ui.search' => 'www/js/controls.js',
        'ui.dateField' => 'www/js/controls.js',
        'ui.switchBox' => 'www/js/controls.js',
        'ui.chipGroup' => 'www/js/controls.js',
        'ui.pickPeople' => 'www/js/controls.js',
        'ui.action' => 'www/js/controls.js',
        'ui.confirmBox' => 'www/js/ui.js',
        'ui.toast' => 'www/js/ui.js',
        'ui.showState' => 'www/js/ui.js',
    ];
}

/** Реальное named function declaration, без совпадений в comments/strings. */
function gateFileDeclaresFunction(string $file, string $name): bool {
    if (!is_file($file)) return false;
    $tokens = token_get_all((string)file_get_contents($file));
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if ($tokens[$j] === '&') continue;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                && strcasecmp($tokens[$j][1], $name) === 0) return true;
            break; // anonymous function or another construct
        }
    }
    return false;
}

/**
 * ACL — обязательная часть каждого приложения, а не опциональный recipe.
 * Проверяется вся достижимая цепочка: state/roles → endpoint → session/search
 * → lifecycle cleanup → управляемый экран → protected capability registry.
 */
function gateMandatoryAclProblems(string $root): array {
    $required = [
        'www/api/access.php', 'www/api/acl.php', 'www/api/session.php',
        'www/api/users.php', 'www/api/http.php', 'www/api/lib.php',
        'www/template.html', 'www/js/app.js', 'docs/capabilities.json',
    ];
    $bad = [];
    foreach ($required as $relative) {
        if (!is_file($root . '/' . $relative)) $bad[] = 'нет обязательного ACL-звена ' . $relative;
    }
    if ($bad !== []) return $bad;

    $access = $root . '/www/api/access.php';
    foreach (['aclLoad', 'aclUpdate', 'aclUserRole', 'roleAtLeast'] as $name) {
        if (!gateFileDeclaresFunction($access, $name)) $bad[] = 'access.php не объявляет ' . $name;
    }
    $phpPolicy = static function (string $file): string {
        $out = '';
        foreach (token_get_all((string)file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    };
    $aclFile = $root . '/www/api/acl.php';
    $acl = $phpPolicy($aclFile);
    if (!gateBootBeforeEffects($aclFile)
        || preg_match('~apiBoot\s*\(\s*\[[\s\S]*?[\'\"]role[\'\"]\s*=>\s*[\'\"]admin[\'\"]~', $acl) !== 1) {
        $bad[] = 'acl.php не входит ранним admin-only apiBoot';
    }
    $http = $phpPolicy($root . '/www/api/http.php');
    $session = $phpPolicy($root . '/www/api/session.php');
    if (strpos($session, 'aclUserRole(') === false || strpos($http, 'roleAtLeast(') === false) {
        $bad[] = 'apiBoot не применяет ACL role';
    }
    $lifecycle = $phpPolicy($root . '/www/api/lib.php');
    if (substr_count($lifecycle, 'ACL_FILE') < 3
        || strpos($lifecycle, 'appLifecycleStoreWrite($file') === false) {
        $bad[] = 'lifecycle не очищает/переносит ACL state';
    }

    $template = (string)preg_replace('~<!--[\s\S]*?-->~', '',
        (string)file_get_contents($root . '/www/template.html'));
    foreach (['data-tab="access"', 'data-pane="access"', 'id="acl-table"', 'id="user-picker"'] as $marker) {
        if (strpos($template, $marker) === false) $bad[] = 'нет ACL UI marker ' . $marker;
    }
    $appModel = gateJsCredentialModel((string)file_get_contents($root . '/www/js/app.js'));
    $appCode = (string)$appModel['code'];
    $appValues = array_values(array_filter((array)$appModel['values'], 'is_string'));
    if (!in_array('api/acl.php', $appValues, true) || !in_array('api/users.php?q=', $appValues, true)
        || preg_match('~\bui\s*\.\s*pickPeople\s*\(~', $appCode) !== 1) {
        $bad[] = 'app.js не даёт управлять ACL через ready search/picker';
    }

    $registry = json_decode((string)file_get_contents($root . '/docs/capabilities.json'), true);
    $cap = null;
    foreach ((array)($registry['capabilities'] ?? []) as $candidate) {
        if (($candidate['id'] ?? '') === 'access-acl') { $cap = $candidate; break; }
    }
    if (!is_array($cap) || ($cap['status'] ?? '') !== 'ready'
        || ($cap['file'] ?? '') !== 'www/api/access.php') {
        $bad[] = 'protected capability access-acl отсутствует или не ready';
    }
    $companions = (array)($registry['companion_files'] ?? []);
    foreach (['www/api/acl.php', 'www/api/session.php', 'www/api/users.php'] as $file) {
        if (($companions[$file] ?? '') !== 'access-acl') $bad[] = $file . ' не принадлежит access-acl';
    }
    return $bad;
}

/** API-файлы template capabilities/companions, но не будущие project extensions. */
function gateTemplateCapabilityApiFiles(): array {
    return ['www/api/access.php', 'www/api/acl.php', 'www/api/b24.php', 'www/api/bind.php',
        'www/api/cache.php', 'www/api/capability.php', 'www/api/crm-dict.php',
        'www/api/event.php', 'www/api/events-bind.php', 'www/api/export-csv.php',
        'www/api/fetch-all-compat.php', 'www/api/fmt.php', 'www/api/health.php',
        'www/api/http.php', 'www/api/journal.php', 'www/api/lib.php', 'www/api/logs.php',
        'www/api/notify.php', 'www/api/ops.php', 'www/api/placements.php',
        'www/api/plan.php', 'www/api/queue.php', 'www/api/session.php',
        'www/api/settings-schema.php', 'www/api/settings.php', 'www/api/store.php',
        'www/api/task-state.php', 'www/api/task.php', 'www/api/uf.php', 'www/api/users.php'];
}

/** Exact core шаблона: project endpoints никогда не расширяют этот контракт. */
function gateTemplateCoreFiles(): array {
    $files = array_merge(gateTemplateCapabilityApiFiles(), [
        'www/bin/deploy-private-store.php', 'www/bin/logs.php', 'www/bin/process.php',
    ], gateTemplateExtraCoreFiles());
    sort($files);
    return $files;
}

/**
 * Ядро помимо .php в www/api и www/bin — из ОДНОГО манифеста tools/core-extra.list,
 * который читает и раздающая сверка ядра (ревью 23.09.2026, dubli-4). Берётся из
 * манифеста ЭТОГО шаблона/проекта, а не из проверяемого корня: фикстура без
 * манифеста иначе считала бы фронт «не ядром». Пропавший манифест ловят
 * GATE_FILES и испытание в uses_template_test.
 */
function gateTemplateExtraCoreFiles(): array {
    $out = [];
    foreach (preg_split('~\R~u', (string)@file_get_contents(dirname(__DIR__) . '/tools/core-extra.list')) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $out[] = $line;
    }
    return $out;
}

/** Project-owned proof/code is a real regular file inside this project, never a link. */
function gateProjectOwnedFileIdentity(string $root, string $relative): array {
    $rootReal = realpath(rtrim($root, '/\\'));
    $path = rtrim($root, '/\\') . '/' . $relative;
    if ($rootReal === false) return ['problem' => 'корень проекта не существует', 'identity' => ''];
    if (is_link($path)) return ['problem' => 'symlink запрещён', 'identity' => ''];
    $real = realpath($path);
    if ($real === false || !is_file($path)) return ['problem' => 'файл не существует', 'identity' => ''];
    $prefix = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;
    if (strpos($real, $prefix) !== 0) return ['problem' => 'realpath вне проекта', 'identity' => ''];
    $stat = @lstat($path);
    if (!is_array($stat) || (((int)$stat['mode'] & 0170000) !== 0100000)) {
        return ['problem' => 'нужен regular file', 'identity' => ''];
    }
    if ((int)($stat['nlink'] ?? 0) !== 1) {
        return ['problem' => 'hardlink запрещён (nlink=' . (int)($stat['nlink'] ?? 0) . ')', 'identity' => ''];
    }
    return ['problem' => '', 'identity' => (string)$stat['dev'] . ':' . (string)$stat['ino']];
}

/** Missing/extra core fail closed; валидные project_extensions исключены из core. */
function gateTemplateCoreInventoryProblems(string $root, array $registry): array {
    $actual = [];
    // Рекурсивно, как гейт размещения (ревью 23.09.2026, dubli-6): плоский glob
    // пропускал www/api/<подпапка>/*.php мимо требования объявить файл в
    // project_extensions — следующий агент о нём не узнавал.
    foreach (['/www/api', '/www/bin'] as $dir) {
        foreach (gateFilesUnder(rtrim($root, '/\\') . $dir, 'php') as $file) {
            $actual[] = substr($file, strlen(rtrim($root, '/\\')) + 1);
        }
    }
    foreach (gateTemplateExtraCoreFiles() as $file) {
        if (is_file(rtrim($root, '/\\') . '/' . $file)) $actual[] = $file;
    }
    $project = [];
    foreach ((array)($registry['project_extensions'] ?? []) as $extension) {
        foreach (array_merge([(string)($extension['file'] ?? '')],
                     array_map('strval', (array)($extension['companions'] ?? []))) as $file) {
            if ($file !== '' && gateProjectOwnedFileIdentity($root, $file)['problem'] === '') {
                $project[$file] = true;
            }
        }
    }
    $actual = array_values(array_diff(array_unique($actual), array_keys($project)));
    $expected = gateTemplateCoreFiles(); sort($actual);
    $bad = [];
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));
    if ($missing !== []) $bad[] = 'нет core: ' . implode(',', $missing);
    if ($extra !== []) {
        $bad[] = 'лишний/необъявленный core: ' . implode(',', $extra)
            . '; project helper/library укажите в project_extensions[].companions';
    }
    return $bad;
}

/** Exact template registry и отдельно явно доказанные project endpoints. */
function gateCapabilityContractProblems(array $registry, array $projectEndpoints, string $root): array {
    $bad = []; $ids = []; $capabilitiesById = []; $registryTemplateApi = [];
    $templateTests = []; $templateChecks = [];
    foreach ((array)($registry['capabilities'] ?? []) as $cap) {
        $id = (string)($cap['id'] ?? '');
        if ($id === '' || isset($ids[$id])) $bad[] = 'пустой/повторный capability id: ' . ($id ?: '?');
        $ids[$id] = true;
        if ($id !== '') $capabilitiesById[$id] = $cap;
        $file = (string)($cap['file'] ?? '');
        if (strpos($file, 'www/api/') === 0 && substr($file, -4) === '.php') $registryTemplateApi[$file] = true;
        foreach ((array)($cap['tests'] ?? []) as $test) $templateTests[(string)$test] = $id;
        foreach ((array)($cap['checks'] ?? []) as $check) $templateChecks[(string)$check] = $id;
    }
    $actualIds = array_keys($ids); $expectedIds = gateProtectedCapabilityIds();
    sort($actualIds); sort($expectedIds);
    if ($actualIds !== $expectedIds) {
        $bad[] = 'protected capability IDs: нет ' . implode(',', array_diff($expectedIds, $actualIds))
            . '; лишние ' . implode(',', array_diff($actualIds, $expectedIds));
    }
    $sortStrings = static function ($value): array {
        $items = array_values(array_map('strval', (array)$value)); sort($items); return $items;
    };
    $normalizeText = static function ($value): string {
        return str_replace(["\r\n", "\r"], "\n", trim((string)$value));
    };
    $policyHash = static function (array $capability): string {
        $policy = [];
        foreach (['title', 'when', 'forbids', 'why_experimental'] as $field) {
            $policy[$field] = str_replace(["\r\n", "\r"], "\n", trim((string)($capability[$field] ?? '')));
        }
        return hash('sha256', (string)json_encode(
            $policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    };
    foreach (gateProtectedCapabilityContracts() as $id => $expected) {
        if (!isset($capabilitiesById[$id])) continue;
        $actual = $capabilitiesById[$id];
        foreach (['file', 'status'] as $field) {
            if ((string)($actual[$field] ?? '') !== $expected[$field]) {
                $bad[] = $id . ': protected ' . $field . ' contract изменён';
            }
        }
        foreach (['entry', 'tests'] as $field) {
            if ($sortStrings($actual[$field] ?? []) !== $expected[$field]) {
                $bad[] = $id . ': protected ' . $field . ' ownership изменён';
            }
        }
        if (isset($expected['project_entry'])) {
            $actualProjectEntry = array_map($normalizeText, (array)($actual['project_entry'] ?? []));
            sort($actualProjectEntry);
            if ($actualProjectEntry !== $expected['project_entry']) {
                $bad[] = $id . ': protected project_entry contract изменён';
            }
            if ($normalizeText($actual['project_note'] ?? '') !== $expected['project_note']) {
                $bad[] = $id . ': protected project_note contract изменён';
            }
            foreach ($expected['project_entry'] as $hook) {
                $hookRoot = rtrim($root, '/\\');
                if (!preg_match('~^([A-Za-z_]\w*) \(([^()]+)\)$~', $hook, $match)
                    || (!is_file($hookRoot . '/' . $match[2])
                        && !is_file(dirname(__DIR__) . '/' . $match[2]))) {
                    $bad[] = $id . ': project hook ' . $hook . ' не объявлен в своём template file';
                    continue;
                }
                $hookFile = is_file($hookRoot . '/' . $match[2])
                    ? $hookRoot . '/' . $match[2]
                    : dirname(__DIR__) . '/' . $match[2];
                if (!gateFileDeclaresFunction($hookFile, $match[1])) {
                    $bad[] = $id . ': project hook ' . $hook . ' не объявлен в своём template file';
                }
            }
        } elseif (array_key_exists('project_entry', $actual) || array_key_exists('project_note', $actual)) {
            $bad[] = $id . ': неожиданный project extension hook contract';
        }
        if (!hash_equals($expected['policy_hash'], $policyHash($actual))) {
            $bad[] = $id . ': protected title/when/forbids/why_experimental contract изменён';
        }
        $checks = $sortStrings($actual['checks'] ?? []);
        $checksHash = hash('sha256', (string)json_encode(
            $checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        if (!hash_equals($expected['checks_hash'], $checksHash)) {
            $bad[] = $id . ': protected checks ownership изменён';
        }
    }
    $actualCompanionOwners = [];
    foreach ((array)($registry['companion_files'] ?? []) as $file => $ownerId) {
        $registryTemplateApi[(string)$file] = true;
        $actualCompanionOwners[(string)$file] = (string)$ownerId;
    }
    $expectedCompanionOwners = gateProtectedCompanionOwners();
    ksort($actualCompanionOwners); ksort($expectedCompanionOwners);
    if ($actualCompanionOwners !== $expectedCompanionOwners) {
        foreach ($expectedCompanionOwners as $file => $ownerId) {
            if (!isset($actualCompanionOwners[$file])) {
                $bad[] = 'template companion owner: нет ' . $file . ' → ' . $ownerId;
            } elseif ($actualCompanionOwners[$file] !== $ownerId) {
                $bad[] = 'template companion owner: ' . $file . ' ожидает ' . $ownerId
                    . ', указан ' . $actualCompanionOwners[$file];
            }
        }
        foreach (array_diff_key($actualCompanionOwners, $expectedCompanionOwners) as $file => $ownerId) {
            $bad[] = 'template companion owner: лишний ' . $file . ' → ' . $ownerId;
        }
    }
    $registryFiles = array_keys($registryTemplateApi); $expectedFiles = gateTemplateCapabilityApiFiles();
    sort($registryFiles); sort($expectedFiles);
    if ($registryFiles !== $expectedFiles) {
        $bad[] = 'template API contract: нет ' . implode(',', array_diff($expectedFiles, $registryFiles))
            . '; лишние ' . implode(',', array_diff($registryFiles, $expectedFiles));
    }

    $actualReadyUi = [];
    foreach ((array)($registry['ready_ui'] ?? []) as $symbol => $file) {
        $actualReadyUi[(string)$symbol] = (string)$file;
    }
    $expectedReadyUi = gateProtectedReadyUiContracts();
    ksort($actualReadyUi); ksort($expectedReadyUi);
    if ($actualReadyUi !== $expectedReadyUi) {
        foreach ($expectedReadyUi as $symbol => $file) {
            if (!isset($actualReadyUi[$symbol])) {
                $bad[] = 'ready UI contract: нет ' . $symbol . ' → ' . $file;
            } elseif ($actualReadyUi[$symbol] !== $file) {
                $bad[] = 'ready UI contract: ' . $symbol . ' ожидает ' . $file
                    . ', указан ' . $actualReadyUi[$symbol];
            }
        }
        foreach (array_diff_key($actualReadyUi, $expectedReadyUi) as $symbol => $file) {
            $bad[] = 'ready UI contract: лишний ' . $symbol . ' → ' . $file;
        }
    }

    $templateIdentities = [];
    foreach (array_merge($expectedFiles, array_keys($templateTests)) as $templateFile) {
        $identity = gateProjectOwnedFileIdentity($root, (string)$templateFile);
        if ($identity['problem'] === '' && $identity['identity'] !== '') {
            $templateIdentities[$identity['identity']] = (string)$templateFile;
        }
    }
    $extensions = []; $extensionIds = []; $extensionFiles = []; $projectOwnedIdentities = [];
    $extensionTestOwners = []; $extensionCheckOwners = [];
    foreach ((array)($registry['project_extensions'] ?? []) as $extension) {
        $file = (string)($extension['file'] ?? ''); $id = (string)($extension['id'] ?? '');
        if ($file === '' || isset($extensions[$file])) {
            $bad[] = 'project extension file пуст/повторён: ' . ($file ?: '?');
            continue;
        }
        $extensions[$file] = true;
        if ($id === '' || isset($ids[$id]) || isset($extensionIds[$id])) {
            $bad[] = $file . ': project id пуст, повторён или совпал с template capability';
        }
        $extensionIds[$id] = true;
        if (array_key_exists('companions', $extension) && !is_array($extension['companions'])) {
            $bad[] = $file . ': companions должен быть массивом путей';
        }
        $ownedFiles = array_merge([$file], array_map('strval', (array)($extension['companions'] ?? [])));
        foreach ($ownedFiles as $ownedFile) {
            if (!preg_match('~^www/api/[^/]+\.php$~', $ownedFile)) {
                $bad[] = $file . ': неверный project API path ' . ($ownedFile ?: '?');
                continue;
            }
            if (in_array($ownedFile, $expectedFiles, true)) {
                $bad[] = $file . ': template API заимствован как project file ' . $ownedFile;
            }
            if (isset($extensionFiles[$ownedFile])) {
                $bad[] = $file . ': project file уже принадлежит ' . $extensionFiles[$ownedFile];
            } else {
                $extensionFiles[$ownedFile] = $file;
            }
            $identity = gateProjectOwnedFileIdentity($root, $ownedFile);
            if ($identity['problem'] !== '') {
                $bad[] = $file . ': project API file ' . $ownedFile . ' — ' . $identity['problem'];
                continue;
            }
            if (isset($templateIdentities[$identity['identity']])) {
                $bad[] = $file . ': project API file заимствует template file '
                    . $templateIdentities[$identity['identity']];
            }
            $role = $ownedFile === $file ? 'primary' : 'companion';
            if (isset($projectOwnedIdentities[$identity['identity']])) {
                $bad[] = $file . ': project ' . $role . ' identity уже принадлежит '
                    . $projectOwnedIdentities[$identity['identity']];
            } else {
                $projectOwnedIdentities[$identity['identity']] = $role . ' ' . $ownedFile;
            }
        }
        $tests = (array)($extension['tests'] ?? []); $checks = (array)($extension['checks'] ?? []);
        if ($tests === [] || $checks === []) { $bad[] = $file . ': не названы tests/checks'; continue; }
        $testSource = '';
        foreach ($tests as $test) {
            $test = (string)$test;
            if (isset($templateTests[$test])) {
                $bad[] = $file . ': project test заимствован у template capability ' . $templateTests[$test];
            }
            if (isset($extensionTestOwners[$test])) {
                $bad[] = $file . ': project test уже принадлежит ' . $extensionTestOwners[$test];
            } else {
                $extensionTestOwners[$test] = $file;
            }
            if (!preg_match('~^tests/[^/]+_test\.php$~', $test)) {
                $bad[] = $file . ': нет project test ' . $test;
                continue;
            }
            $testIdentity = gateProjectOwnedFileIdentity($root, $test);
            if ($testIdentity['problem'] !== '') {
                $bad[] = $file . ': project test ' . $test . ' — ' . $testIdentity['problem'];
                continue;
            }
            if (isset($templateIdentities[$testIdentity['identity']])) {
                $bad[] = $file . ': project test заимствует template file '
                    . $templateIdentities[$testIdentity['identity']];
            }
            if (isset($projectOwnedIdentities[$testIdentity['identity']])) {
                $bad[] = $file . ': project test identity уже принадлежит '
                    . $projectOwnedIdentities[$testIdentity['identity']];
            } else {
                $projectOwnedIdentities[$testIdentity['identity']] = 'test ' . $test;
            }
            $testSource .= (string)file_get_contents($root . '/' . $test);
        }
        foreach ($checks as $check) {
            $check = (string)$check;
            if (isset($templateChecks[$check])) {
                $bad[] = $file . ': project check заимствован у template capability ' . $templateChecks[$check];
            }
            if (isset($extensionCheckOwners[$check])) {
                $bad[] = $file . ': project check уже принадлежит ' . $extensionCheckOwners[$check];
            } else {
                $extensionCheckOwners[$check] = $file;
            }
            if (strpos($testSource, "check('" . $check . "'") === false
                && strpos($testSource, 'check("' . $check . '"') === false) {
                $bad[] = $file . ': check «' . $check . '» нет в названных tests';
            }
        }
    }
    $expectedProject = array_values(array_unique(array_map('strval', $projectEndpoints)));
    sort($expectedProject); $actualProject = array_keys($extensions); sort($actualProject);
    if ($expectedProject !== $actualProject) {
        $bad[] = 'project endpoints: нет extension ' . implode(',', array_diff($expectedProject, $actualProject))
            . '; лишние ' . implode(',', array_diff($actualProject, $expectedProject));
    }
    return array_values(array_unique($bad));
}

/**
 * Убрать из выражения именно вызовы maskSecrets(...), а не всю инструкцию.
 *
 * Простое `strpos($code, 'maskSecrets')` разрешало рядом положить безопасный
 * вызов-приманку, а сырой `$e->getMessage()` продолжить писать в тот же лог.
 */
function gateStripMaskedCalls(string $code): string {
    $tokens = [];
    foreach (token_get_all("<?php " . $code) as $token) {
        if (is_array($token) && $token[0] === T_OPEN_TAG) continue;
        $tokens[] = ['id' => is_array($token) ? $token[0] : null,
                     'text' => is_array($token) ? $token[1] : $token];
    }
    $significant = static function (array $tokens, int $at, int $step): ?int {
        for ($i = $at + $step, $n = count($tokens); $i >= 0 && $i < $n; $i += $step) {
            if ($tokens[$i]['id'] !== null && in_array(
                $tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
            )) continue;
            return $i;
        }
        return null;
    };

    $out = '';
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] === T_STRING && strcasecmp($tokens[$i]['text'], 'maskSecrets') === 0) {
            $prev = $significant($tokens, $i, -1);
            $open = $significant($tokens, $i, 1);
            $isFunction = ($prev === null || !in_array($tokens[$prev]['text'], ['->', '::'], true))
                && $open !== null && $tokens[$open]['text'] === '(';
            if ($isFunction) {
                $depth = 0; $close = null;
                for ($j = $open; $j < $n; $j++) {
                    if ($tokens[$j]['text'] === '(') $depth++;
                    elseif ($tokens[$j]['text'] === ')' && --$depth === 0) { $close = $j; break; }
                }
                if ($close !== null) {
                    $out .= '/*masked*/';
                    $i = $close;
                    continue;
                }
            }
        }
        $out .= $tokens[$i]['text'];
    }
    return $out;
}

/** Код выражения без обычного текста строк: в интерполяции переменные остаются. */
function gateExecutableExpression(string $expr): string {
    $out = '';
    foreach (token_get_all("<?php " . $expr) as $token) {
        if (!is_array($token)) { $out .= $token; continue; }
        if (in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
        $out .= $token[1];
    }
    return $out;
}

/** Есть ли в выражении данные исключения, НЕ находящиеся внутри maskSecrets(). */
function gateHasUnmaskedExceptionTaint(string $expr, array $dirty): bool {
    $visible = gateExecutableExpression(gateStripMaskedCalls($expr));
    if (preg_match('~->\s*getMessage\s*\(~', $visible)) return true;
    foreach (array_keys($dirty) as $name) {
        if (preg_match('~\$' . preg_quote($name, '~') . '\b~', $visible)) return true;
    }
    return false;
}

/**
 * Сырой текст исключения в журнале — включая случай «через переменную».
 *
 * В сообщении портала и PHP лежат адреса с токенами: сырой текст в журнале —
 * это утечка секрета в файл, который потом смотрят всем отделом.
 */
function gateRawExceptionLines(string $file): array {
    $vars = gateVarMap($file);
    // Переменные, в которые положили текст исключения без маскировки. Следим
    // транзитивно: `$raw = $e->getMessage(); $alias = $raw; appLog($alias)` —
    // такой один дополнительный alias раньше полностью снимал проверку.
    $dirty = [];
    do {
        $changed = false;
        foreach ($vars as $name => $exprs) {
            if (isset($dirty[$name])) continue;
            foreach ($exprs as $e) {
                if (!gateHasUnmaskedExceptionTaint($e, $dirty)) continue;
                $dirty[$name] = true;
                $changed = true;
                break;
            }
        }
    } while ($changed);
    $hits = [];
    foreach (gateInstructions($file) as $inst) {
        $code = $inst['code'];
        if (!preg_match('~\b(logAppError|appLog|journalWrite|auditLog)\s*\(~', $code)) continue;
        if (!gateHasUnmaskedExceptionTaint($code, $dirty)) continue;
        $hits[] = $inst['line'];
    }
    return array_values(array_unique($hits));
}

/** Токены с абсолютным смещением — для проверки реального вложения вызовов. */
function gateFlatTokens(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $out = []; $offset = 0; $line = 1;
    foreach (gateTokens($file) as $t) {
        $text = is_array($t) ? $t[1] : $t;
        $at   = is_array($t) ? (int)$t[2] : $line;
        $out[] = ['id' => is_array($t) ? $t[0] : null, 'text' => $text,
                  'line' => $at, 'offset' => $offset];
        $offset += strlen($text);
        $line += substr_count($text, "\n");
    }
    return $cache[$file] = $out;
}

/** Следующий токен без пробелов и комментариев. */
function gateNextToken(array $tokens, int $i, int $limit = PHP_INT_MAX): ?int {
    $n = min(count($tokens), $limit);
    for ($i++; $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        if ($id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $i;
    }
    return null;
}

/** Предыдущий значащий токен. */
function gatePrevToken(array $tokens, int $i): ?int {
    for ($i--; $i >= 0; $i--) {
        $id = $tokens[$i]['id'];
        if ($id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $i;
    }
    return null;
}

/** Закрывающий парный символ для `(` / `{` / `[`. */
function gateMatchingToken(array $tokens, int $open): ?int {
    $pairs = ['(' => ')', '{' => '}', '[' => ']'];
    $left = $tokens[$open]['text'] ?? '';
    if (!isset($pairs[$left])) return null;
    $right = $pairs[$left]; $depth = 0;
    for ($i = $open, $n = count($tokens); $i < $n; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === $left) $depth++;
        elseif ($text === $right && --$depth === 0) return $i;
    }
    return null;
}

/** Диапазоны тел функций/замыканий — чтобы не смешивать соседние control-flow. */
function gateFunctionBodyRanges(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $tokens = gateFlatTokens($file); $out = [];
    // Один parser callable scopes уже умеет обычные closures И PHP 7.4 `fn`.
    // Дублировать здесь более узкую грамматику нельзя: тогда outer assignment
    // ошибочно становится provenance одноимённого параметра стрелки.
    foreach (gateCallableScopes($file) as $scope) {
        $open = (int)$scope['open']; $close = (int)$scope['close'];
        $isArrow = defined('T_FN') && $tokens[(int)$scope['at']]['id'] === T_FN;
        $out[] = [
            'start' => (int)$tokens[$open]['offset'],
            // У brace-body закрывающая `}` остаётся границей; у arrow последнему
            // token даём его полную ширину, иначе single-token body выпадает.
            'end' => (int)$tokens[$close]['offset'] + ($isArrow ? strlen($tokens[$close]['text']) : 0),
            'atIndex' => (int)$scope['at'],
            'openIndex' => $open,
            'closeIndex' => $close,
            'arrow' => $isArrow,
        ];
    }
    return $cache[$file] = $out;
}

/** Самая внутренняя функция, в control-flow которой лежит смещение. */
function gateFunctionScopeAt(string $file, int $offset): ?array {
    $best = null;
    foreach (gateFunctionBodyRanges($file) as $body) {
        if ($offset <= $body['start'] || $offset >= $body['end']) continue;
        if ($best === null || ($body['end'] - $body['start']) < ($best['end'] - $best['start'])) {
            $best = $body;
        }
    }
    return $best;
}

/** Одинаковый ли это поток выполнения (не тело вложенного callback). */
function gateSameFunctionScope(string $file, int $left, int $right): bool {
    return gateFunctionScopeAt($file, $left) === gateFunctionScopeAt($file, $right);
}

/** Безусловный выход между мутацией и аудитом в том же теле функции. */
function gateHasControlTransferBetween(string $file, int $from, int $to): bool {
    $scope = gateFunctionScopeAt($file, $from);
    if ($scope === null) return true;
    $ids = [T_RETURN, T_THROW, T_EXIT, T_BREAK, T_CONTINUE, T_GOTO];
    foreach (gateFlatTokens($file) as $token) {
        $offset = (int)$token['offset'];
        if ($offset <= $from) continue;
        if ($offset >= $to) break;
        if (!in_array($token['id'], $ids, true)) continue;
        if (gateFunctionScopeAt($file, $offset) !== $scope) continue;
        // `continue`/`break` внутри цикла, начавшегося ПОСЛЕ мутации, поток из
        // функции не уводят — это переход к следующему элементу. Прежде такой
        // цикл засчитывался как безусловный выход, и тогда аудит после цикла
        // «не виден»: разбор ответа пакета почти всегда написан циклом с
        // continue, то есть проверка следа не работала ровно там, где нужна.
        if (in_array($token['id'], [T_BREAK, T_CONTINUE], true)
            && gateInsideLoopStartedAfter($file, $offset, $from)) continue;
        return true;
    }
    return false;
}

/**
 * Лежит ли токен внутри цикла или switch, который НАЧАЛСЯ после указанной точки.
 * Нужно, чтобы отличать «выход из функции» от обычного перехода к следующему
 * элементу списка.
 */
function gateInsideLoopStartedAfter(string $file, int $offset, int $after): bool {
    $tokens = gateFlatTokens($file);
    $loops = [T_FOREACH, T_FOR, T_WHILE, T_DO, T_SWITCH];
    foreach ($tokens as $i => $token) {
        if (!in_array($token['id'], $loops, true)) continue;
        $start = (int)$token['offset'];
        if ($start < $after || $start > $offset) continue;
        // Тело цикла: ищем ближайшую фигурную скобку после заголовка.
        for ($j = $i; $j < count($tokens); $j++) {
            if ($tokens[$j]['text'] !== '{') continue;
            $end = gateMatchingToken($tokens, $j);
            if ($end === null) break;
            $bodyStart = (int)$tokens[$j]['offset'];
            $bodyEnd = (int)$tokens[$end]['offset'];
            if ($offset > $bodyStart && $offset < $bodyEnd) return true;
            break;
        }
    }
    return false;
}

/** Конец одного тела управляющей конструкции: блок либо инструкция до `;`. */
function gateControlBodyEndToken(array $tokens, int $start): ?int {
    if (($tokens[$start]['text'] ?? '') === '{') return gateMatchingToken($tokens, $start);
    $round = 0; $square = 0; $curly = 0;
    for ($i = $start, $n = count($tokens); $i < $n; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '(') $round++;
        elseif ($text === ')') $round--;
        elseif ($text === '[') $square++;
        elseif ($text === ']') $square--;
        elseif ($text === '{') $curly++;
        elseif ($text === '}') {
            if ($curly === 0) return $i - 1;
            $curly--;
            if ($curly === 0 && $round === 0 && $square === 0) return $i;
        } elseif ($text === ';' && $round === 0 && $square === 0 && $curly === 0) {
            return $i;
        }
    }
    return null;
}

/** Токен `:` тела управляющей конструкции в альтернативном PHP-синтаксисе. */
function gateAlternativeControlBodyToken(array $tokens, int $at): ?int {
    $conditionals = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH];
    $id = $tokens[$at]['id'] ?? null;
    $body = gateNextToken($tokens, $at);
    if (in_array($id, $conditionals, true)) {
        if ($body === null || $tokens[$body]['text'] !== '(') return null;
        $close = gateMatchingToken($tokens, $body);
        $body = $close === null ? null : gateNextToken($tokens, $close);
    } elseif ($id !== T_ELSE) {
        return null;
    }
    return $body !== null && $tokens[$body]['text'] === ':' ? $body : null;
}

/** Ветки альтернативного синтаксиса `if: ... endif` и `switch: ... endswitch`. */
function gateAlternativeControlBranchRanges(string $file): array {
    $tokens = gateFlatTokens($file); $out = [];
    $add = static function (int $start, int $end) use (&$out, $tokens): void {
        if ($end <= $start) return;
        $out[] = [
            'id' => 'alt:' . $tokens[$start]['offset'],
            'start' => (int)$tokens[$start]['offset'],
            'end' => (int)$tokens[$end]['offset'],
        ];
    };

    // В if/elseif/else каждая ветка отдельна: общий диапазон до endif снова
    // разрешил бы прикрыть мутацию в `if` аудитом только в `else`.
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_IF) continue;
        $branch = gateAlternativeControlBodyToken($tokens, $i);
        if ($branch === null) continue;
        $depth = 1;
        for ($j = $branch + 1; $j < $n; $j++) {
            if ($tokens[$j]['id'] === T_IF && gateAlternativeControlBodyToken($tokens, $j) !== null) {
                $depth++;
                continue;
            }
            if ($tokens[$j]['id'] === T_ENDIF) {
                if (--$depth === 0) { $add($branch, $j); break; }
                continue;
            }
            if ($depth !== 1 || !in_array($tokens[$j]['id'], [T_ELSEIF, T_ELSE], true)) continue;
            $add($branch, $j);
            $nextBranch = gateAlternativeControlBodyToken($tokens, $j);
            if ($nextBranch === null) break;
            $branch = $nextBranch;
        }
    }

    // Циклы альтернативного синтаксиса имеют одно управляемое тело.
    foreach ([[T_FOR, T_ENDFOR], [T_FOREACH, T_ENDFOREACH], [T_WHILE, T_ENDWHILE]] as [$begin, $finish]) {
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['id'] !== $begin) continue;
            $body = gateAlternativeControlBodyToken($tokens, $i);
            if ($body === null) continue;
            $depth = 1;
            for ($j = $body + 1; $j < $n; $j++) {
                if ($tokens[$j]['id'] === $begin && gateAlternativeControlBodyToken($tokens, $j) !== null) $depth++;
                elseif ($tokens[$j]['id'] === $finish && --$depth === 0) { $add($body, $j); break; }
            }
        }
    }

    // Как и у обычного switch, case/default — разные ветки.
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_SWITCH) continue;
        $body = gateAlternativeControlBodyToken($tokens, $i);
        if ($body === null) continue;
        $depth = 1; $labels = []; $end = null;
        for ($j = $body + 1; $j < $n; $j++) {
            if ($tokens[$j]['id'] === T_SWITCH && gateAlternativeControlBodyToken($tokens, $j) !== null) {
                $depth++;
                continue;
            }
            if ($tokens[$j]['id'] === T_ENDSWITCH) {
                if (--$depth === 0) { $end = $j; break; }
                continue;
            }
            if ($depth === 1 && in_array($tokens[$j]['id'], [T_CASE, T_DEFAULT], true)) $labels[] = $j;
        }
        if ($end === null) continue;
        foreach ($labels as $at => $label) $add($label, $labels[$at + 1] ?? $end);
    }
    return $out;
}

/**
 * Диапазоны отдельных управляющих веток (`if`, `else`, циклы, try/catch).
 *
 * Идентификатором служит конкретное тело, а не только тип конструкции. Поэтому
 * два независимых `if` не выглядят одной веткой. Для switch отдельно отмечаем
 * каждый case/default: общий блок switch сам по себе исполнения не гарантирует.
 *
 * @return array<int,array{id:string,start:int,end:int}>
 */
function gateControlBranchRanges(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $tokens = gateFlatTokens($file); $out = []; $switches = [];
    $conditionals = [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_CATCH];
    $direct = [T_ELSE, T_TRY, T_FINALLY, T_DO];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i]['id'];
        if ($id === T_SWITCH) {
            $open = gateNextToken($tokens, $i);
            if ($open !== null && $tokens[$open]['text'] === '(') {
                $close = gateMatchingToken($tokens, $open);
                $body = $close === null ? null : gateNextToken($tokens, $close);
                if ($body !== null && $tokens[$body]['text'] === '{') {
                    $bodyClose = gateMatchingToken($tokens, $body);
                    if ($bodyClose !== null) $switches[] = [$body, $bodyClose];
                }
            }
            continue;
        }
        if (!in_array($id, $conditionals, true) && !in_array($id, $direct, true)) continue;

        $body = gateNextToken($tokens, $i);
        if (in_array($id, $conditionals, true)) {
            if ($body === null || $tokens[$body]['text'] !== '(') continue;
            $conditionClose = gateMatchingToken($tokens, $body);
            $body = $conditionClose === null ? null : gateNextToken($tokens, $conditionClose);
        }
        // `else if (...)` получает точную ветку от вложенного T_IF; широкий
        // диапазон `else` здесь только склеил бы разные ветви вложенного if.
        if ($id === T_ELSE && $body !== null && $tokens[$body]['id'] === T_IF) continue;
        if ($body === null || $tokens[$body]['text'] === ':') continue; // альтернативный синтаксис ниже
        $end = gateControlBodyEndToken($tokens, $body);
        if ($end === null || $end < $body) continue;
        $out[] = [
            'id' => 'body:' . $tokens[$body]['offset'],
            'start' => (int)$tokens[$body]['offset'],
            'end' => (int)$tokens[$end]['offset'] + strlen($tokens[$end]['text']),
        ];
    }

    // Внутри switch именно case/default задаёт ветку. Fall-through намеренно
    // трактуется строго: гейт не должен доказывать неочевидный control-flow.
    foreach ($switches as [$open, $close]) {
        $depth = 0; $labels = [];
        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '{') { $depth++; continue; }
            if ($text === '}') { $depth--; continue; }
            if ($depth === 0 && in_array($tokens[$i]['id'], [T_CASE, T_DEFAULT], true)) $labels[] = $i;
        }
        foreach ($labels as $at => $label) {
            $end = $labels[$at + 1] ?? $close;
            $out[] = [
                'id' => 'case:' . $tokens[$label]['offset'],
                'start' => (int)$tokens[$label]['offset'],
                'end' => (int)$tokens[$end]['offset'],
            ];
        }
    }
    return $cache[$file] = array_merge($out, gateAlternativeControlBranchRanges($file));
}

/** Набор конкретных управляющих веток, внутри которых находится вызов. */
function gateControlBranchPathAt(string $file, int $offset): array {
    $path = [];
    foreach (gateControlBranchRanges($file) as $range) {
        if ($offset < $range['start'] || $offset >= $range['end']) continue;
        $path[$range['id']] = true;
    }
    return $path;
}

/**
 * Audit post-dominates mutation в консервативной структурной модели.
 * Любая ветка, управляющая auditLog, обязана также управлять мутацией. Тогда
 * одинаковая ветка разрешена, а безусловный audit после условной мутации тоже
 * разрешён; отдельный/вложенный только вокруг аудита `if` — нет.
 */
function gateAuditPostDominates(string $file, int $mutation, int $audit): bool {
    $mutationPath = gateControlBranchPathAt($file, $mutation);
    foreach (gateControlBranchPathAt($file, $audit) as $branch => $_) {
        if (!isset($mutationPath[$branch])) return false;
    }
    return true;
}

/** Индекс открывающей скобки для известной закрывающей. */
function gateMatchingOpenToken(array $tokens, int $close): ?int {
    $pairs = [')' => '(', '}' => '{', ']' => '['];
    $right = $tokens[$close]['text'] ?? '';
    if (!isset($pairs[$right])) return null;
    $left = $pairs[$right]; $depth = 0;
    for ($i = $close; $i >= 0; $i--) {
        $text = $tokens[$i]['text'];
        if ($text === $right) $depth++;
        elseif ($text === $left && --$depth === 0) return $i;
    }
    return null;
}

/**
 * Засчитывается только самостоятельная инструкция `auditLog(...);`.
 *
 * Вызов справа от `&&`, `?:`, `??`, в присваивании или аргументе другого
 * вызова может не выполниться либо не является явным обязательным следом.
 * Unbraced `if (...) auditLog(...);` синтаксически остаётся самостоятельной
 * инструкцией; гарантированность уже отдельно решает control-ancestor гейт.
 */
function gateAuditIsStandaloneStatement(string $file, int $audit): bool {
    $tokens = gateFlatTokens($file); $at = null;
    foreach ($tokens as $i => $token) {
        if ((int)$token['offset'] === $audit) { $at = $i; break; }
    }
    if ($at === null || $tokens[$at]['id'] !== T_STRING
        || strcasecmp($tokens[$at]['text'], 'auditLog') !== 0) return false;

    $open = gateNextToken($tokens, $at);
    if ($open === null || $tokens[$open]['text'] !== '(') return false;
    $close = gateMatchingToken($tokens, $open);
    if ($close === null) return false;
    $after = gateNextToken($tokens, $close);
    if ($after === null || $tokens[$after]['text'] !== ';') return false;

    $prev = gatePrevToken($tokens, $at);
    if ($prev === null || $tokens[$prev]['id'] === T_OPEN_TAG
        || in_array($tokens[$prev]['text'], ['{', '}', ';', ':'], true)) return true;
    if (in_array($tokens[$prev]['id'], [T_ELSE, T_DO], true)) return true;

    // Единственное допустимое отсутствие разделителя — тело управляющей
    // конструкции без `{}`: `if ($ok) auditLog(...);`.
    if ($tokens[$prev]['text'] !== ')') return false;
    $conditionOpen = gateMatchingOpenToken($tokens, $prev);
    if ($conditionOpen === null) return false;
    $control = gatePrevToken($tokens, $conditionOpen);
    return $control !== null && in_array(
        $tokens[$control]['id'], [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH], true
    );
}

/** Тексты аргументов вызова по индексам его скобок. */
function gateCallArgTokens(array $tokens, int $open, int $close): array {
    $args = []; $out = ''; $round = 0; $square = 0; $curly = 0;
    for ($i = $open + 1; $i < $close; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === ',' && $round === 0 && $square === 0 && $curly === 0) {
            $args[] = trim($out); $out = ''; continue;
        }
        if ($text === '(') $round++; elseif ($text === ')') $round--;
        elseif ($text === '[') $square++; elseif ($text === ']') $square--;
        elseif ($text === '{') $curly++; elseif ($text === '}') $curly--;
        $out .= $text;
    }
    if ($out !== '' || $args !== []) $args[] = trim($out);
    return $args;
}

/** Текст первого аргумента вызова по индексу открывающей скобки. */
function gateFirstArgTokens(array $tokens, int $open, int $close): string {
    return gateCallArgTokens($tokens, $open, $close)[0] ?? '';
}

/** Значащие токены произвольного PHP-выражения. */
function gateExpressionTokenTexts(string $expr): array {
    $out = [];
    foreach (token_get_all("<?php " . $expr) as $token) {
        if (is_array($token) && in_array(
            $token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
        )) continue;
        $out[] = is_array($token) ? $token[1] : $token;
    }
    return $out;
}

/** Разбить последовательность токенов по разделителю только на верхнем уровне. */
function gateSplitTopLevelTokens(array $tokens, string $separator = ','): array {
    $parts = []; $part = []; $round = 0; $square = 0; $curly = 0;
    foreach ($tokens as $text) {
        if ($text === $separator && $round === 0 && $square === 0 && $curly === 0) {
            $parts[] = $part; $part = []; continue;
        }
        if ($text === '(') $round++; elseif ($text === ')') $round--;
        elseif ($text === '[') $square++; elseif ($text === ']') $square--;
        elseif ($text === '{') $curly++; elseif ($text === '}') $curly--;
        $part[] = $text;
    }
    if ($part !== [] || $parts !== []) $parts[] = $part;
    return $parts;
}

/** Short-array как список пар key/value; null означает динамику/неоднозначность. */
function gateShortArrayEntries(string $expr, array $vars, int $depth = 0): ?array {
    if ($depth > 4) return null;
    $expr = gateTrimExpressionParens($expr);
    // Flow-insensitive `$params = [...]` небезопасен: присваивание может стоять
    // после вызова либо переменная может быть заменена между write/read. Пока
    // нет полноценного CFG, selector/fields должны быть видимым short-array.
    if (preg_match('~^\$\w+$~D', $expr)) return null;
    $tokens = gateExpressionTokenTexts($expr);
    if (count($tokens) < 2 || $tokens[0] !== '[' || $tokens[count($tokens) - 1] !== ']') return null;
    $parts = gateSplitTopLevelTokens(array_slice($tokens, 1, -1));
    $entries = [];
    foreach ($parts as $part) {
        if ($part === []) continue;
        $halves = gateSplitTopLevelTokens($part, '=>');
        if (count($halves) > 2) return null;
        if (count($halves) === 2) {
            $entries[] = ['key' => implode('', $halves[0]), 'value' => implode('', $halves[1])];
        } else {
            $entries[] = ['key' => null, 'value' => implode('', $part)];
        }
    }
    return $entries;
}

/** Только простой literal-key: динамический ключ доказательством не служит. */
function gateLiteralArrayKey(?string $expr): ?string {
    if ($expr === null) return null;
    $expr = gateTrimExpressionParens(trim($expr));
    if (preg_match('~^([\'\"])([A-Za-z0-9_-]+)\1$~D', $expr, $m)) return $m[2];
    if (preg_match('~^-?(?:0|[1-9]\d*)$~D', $expr)) return $expr;
    return null;
}

/** Ассоциативный short-array с простыми строковыми ключами. */
function gateShortArrayMap(string $expr, array $vars): ?array {
    $entries = gateShortArrayEntries($expr, $vars);
    if ($entries === null) return null;
    $map = [];
    foreach ($entries as $entry) {
        $key = gateLiteralArrayKey($entry['key']);
        if ($key === null) return null;
        $key = strtolower(str_replace('_', '', $key));
        if (array_key_exists($key, $map)) return null;
        $map[$key] = $entry['value'];
    }
    return $map;
}

/** Нормальная токенная форма выражения без пробелов и внешних скобок. */
function gateNormalizeExpression(string $expr): string {
    return implode('', gateExpressionTokenTexts(gateTrimExpressionParens($expr)));
}

/**
 * Snapshot можно доказать повторяемым только без вызовов/new/инкрементов и
 * присваиваний. Одинаковый текст `nextId()` или `makeFields()` — это два
 * вычисления, а не одно значение; результат надо сначала сохранить в переменную.
 */
function gateExpressionIsStableSnapshot(string $expr): bool {
    $expr = gateTrimExpressionParens($expr);
    $tokens = gateExpressionTokenTexts($expr);
    if ($tokens === []) return false;
    foreach ($tokens as $at => $token) {
        $lower = strtolower($token);
        if (in_array($token, ['(', ')', '->', '::', '++', '--', '&'], true)
            || in_array($lower, ['new', 'clone', 'yield', 'include', 'include_once',
                                  'require', 'require_once', 'eval'], true)) return false;
        if ($token === '=') return false;
        // `+=`, `??=`, `.=` и остальные assignment-токены.
        if (strlen($token) > 1 && substr($token, -1) === '='
            && !in_array($token, ['=>', '==', '===', '!=', '!==', '<=', '>=', '<=>'], true)) return false;
        // Ссылка на переменную как аргумент/элемент меняет доказуемость snapshot.
        if ($token === '...' || ($token === '?' && ($tokens[$at + 1] ?? '') === '?')) return false;
    }
    return true;
}

function gateSameStableExpression(string $left, string $right): bool {
    return gateExpressionIsStableSnapshot($left)
        && gateExpressionIsStableSnapshot($right)
        && gateNormalizeExpression($left) === gateNormalizeExpression($right);
}

/** Похоже ли строковое значение на имя метода REST Битрикс24. */
function gateLooksLikePortalMethod(string $method): bool {
    $method = strtolower($method);
    return in_array($method, ['profile', 'scope'], true)
        || (bool)preg_match('~^[a-z][a-z0-9_.]*\.[a-z][a-z0-9_]*$~i', $method);
}

/**
 * Разрешён ли REST-метод назначением core-файла.
 *
 * Запись с точкой на конце (`crm.`) — семейство; любая другая запись — exact.
 * Иначе разрешение `scope` молча разрешило бы будущий `scope.update`.
 *
 * @param string[] $allowed
 */
function gatePortalMethodAllowedByScope(string $method, array $allowed): bool {
    $method = strtolower(trim($method));
    foreach ($allowed as $rule) {
        $rule = strtolower(trim((string)$rule));
        if ($rule === '') continue;
        if (substr($rule, -1) === '.') {
            if (strpos($method, $rule) === 0) return true;
            continue;
        }
        if ($method === $rule) return true;
    }
    return false;
}

/** Снять только действительно внешнюю пару скобок у выражения. */
function gateTrimExpressionParens(string $expr): string {
    $expr = trim($expr);
    while (strlen($expr) >= 2 && $expr[0] === '(') {
        $depth = 0; $quote = null; $escaped = false; $close = null;
        for ($i = 0, $n = strlen($expr); $i < $n; $i++) {
            $c = $expr[$i];
            if ($quote !== null) {
                if ($escaped) { $escaped = false; continue; }
                if ($c === '\\') { $escaped = true; continue; }
                if ($c === $quote) $quote = null;
                continue;
            }
            if ($c === "'" || $c === '"') { $quote = $c; continue; }
            if ($c === '(') $depth++;
            elseif ($c === ')' && --$depth === 0) { $close = $i; break; }
        }
        if ($close !== strlen($expr) - 1) break;
        $expr = trim(substr($expr, 1, -1));
    }
    return $expr;
}

/**
 * Возможные методы скалярного первого аргумента.
 *
 * Извлекать «любую похожую строку где-то в выражении» нельзя: выражение
 * `$flag ? 'crm.item.get' : $unknown` тогда прикрывает динамическую ветку
 * read-only приманкой. Допускаем только литерал либо переменную, все известные
 * присваивания которой сами сводятся к таким литералам.
 */
function gateScalarPortalMethods(string $expr, array $vars, int $depth = 0): array {
    if ($depth > 4) return [''];
    $expr = gateTrimExpressionParens($expr);
    if (preg_match('~^([\'"])([a-z][a-z0-9_.]*)\1$~i', $expr, $m)) {
        return gateLooksLikePortalMethod($m[2]) ? [$m[2]] : [''];
    }
    if (!preg_match('~^\$(\w+)$~', $expr, $m) || !isset($vars[$m[1]])) return [''];
    $methods = [];
    foreach ($vars[$m[1]] as $value) {
        array_push($methods, ...gateScalarPortalMethods($value, $vars, $depth + 1));
    }
    return array_values(array_unique($methods === [] ? [''] : $methods));
}

/** Команды batch()/batchAll() вместе с params и literal-key результата. */
function gateBatchPortalCommands(string $expr, array $vars): array {
    $outer = gateShortArrayEntries($expr, $vars);
    if ($outer === null) return [['method' => '', 'params' => '', 'batchKey' => null]];
    $commands = [];
    foreach ($outer as $entry) {
        $batchKey = gateLiteralArrayKey($entry['key']);
        $tuple = gateShortArrayEntries($entry['value'], $vars);
        if ($batchKey === null || $tuple === null || count($tuple) !== 2
            || $tuple[0]['key'] !== null || $tuple[1]['key'] !== null) {
            $commands[] = ['method' => '', 'params' => '', 'batchKey' => null];
            continue;
        }
        foreach (gateScalarPortalMethods($tuple[0]['value'], $vars) as $method) {
            $commands[] = ['method' => $method, 'params' => $tuple[1]['value'], 'batchKey' => $batchKey];
        }
    }
    return $commands;
}

/**
 * Методы пакета, СОБРАННОГО В ЦИКЛЕ, а не записанного литералом.
 *
 * Так пакеты и пишут: `foreach (...) { $cmd[$k] = ['crm.item.add', [...]]; }`.
 * Пока разбор понимал только литеральный массив в самом вызове, любой такой
 * пакет был для гейта вызовом БЕЗ ИМЕНИ МЕТОДА — то есть контракт мутации к нему
 * не применялся вовсе. Проверено 08.08.2026: два модуля шаблона прошли гейт
 * именно так, а не потому, что написаны правильно.
 *
 * Ищем присваивания в элементы этой переменной и достаём из них литеральные
 * имена методов. Ничего не нашли — честно возвращаем пусто, и вызов остаётся
 * «безымянным», как раньше.
 *
 * ГРАНИЦА — ТЕЛО ФУНКЦИИ, ГДЕ СТОИТ ВЫЗОВ. Пока сборка искалась по всему файлу,
 * безопасный `$cmd['x'] = ['user.get', []];` в СОСЕДНЕЙ функции подменял собой
 * динамический мутатор рядом с вызовом: методы находились, «безымянность»
 * снималась, и `$cmd[$k] = [$method, $p]` проходил гейт как чтение. Проверено
 * 08.08.2026 подставной парой функций. Переменные в PHP не пересекают границу
 * функции — значит и разбор не вправе.
 *
 * @param int $callOffset Смещение вызова: по нему берётся область видимости.
 */
function gateBatchCommandsFromAssembly(string $file, string $varExpr, array $vars, int $callOffset = -1): array {
    $name = ltrim(trim($varExpr), '$');
    if ($name === '' || !preg_match('~^\w+$~', $name)) return [];
    $src = (string)@file_get_contents($file);
    if ($src === '') return [];
    // Область вызова: null — верхний уровень файла, и тогда сборка внутри любой
    // функции к нему отношения не имеет.
    $scope = $callOffset >= 0 ? gateFunctionScopeAt($file, $callOffset) : null;
    $inScope = static function (int $offset) use ($file, $scope, $callOffset): bool {
        if ($callOffset < 0) return true;   // старый вызов без привязки — как было
        $found = gateFunctionScopeAt($file, $offset);
        if ($scope === null) return $found === null;
        return $found !== null && $found['start'] === $scope['start'] && $found['end'] === $scope['end'];
    };
    $commands = [];
    // `$cmd[что-угодно] = [ 'метод', ... ];` и `$cmd[] = [ 'метод', ... ];`
    // Ключ пакета сам бывает выражением со скобками: `$cmd[$row['tag']] = [...]`.
    // Поэтому в ключе разрешаем одну вложенность — иначе такие сборки (а они
    // обычные) снова становятся невидимыми.
    $key = '\[[^\[\]]*(?:\[[^\[\]]*\][^\[\]]*)*\]';
    $re = '~\$' . preg_quote($name, '~') . '\s*' . $key . '\s*=\s*\[(.{0,400}?)\]\s*;~s';
    if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) return [];
    foreach ($m[1] as $i => [$tuple, $tupleOffset]) {
        // Присваивание из чужой функции к этому вызову отношения не имеет.
        if (!$inScope((int)($m[0][$i][1] ?? $tupleOffset))) continue;
        // Команда — это пара «метод, параметры». Имя метода лежит в ПЕРВОМ
        // элементе; разбираем пару тем же разборщиком, что и литеральный пакет,
        // иначе в проверку методов уезжает весь кортеж целиком и не совпадает
        // ни с чем.
        $pair = gateShortArrayEntries('[' . $tuple . ']', $vars);
        $first = is_array($pair) && isset($pair[0]) ? (string)$pair[0]['value'] : trim($tuple);
        $params = is_array($pair) && isset($pair[1]) ? (string)$pair[1]['value'] : '';
        $named = false;
        foreach (gateScalarPortalMethods($first, $vars) as $method) {
            if ($method === '') continue;
            $named = true;
            $commands[] = ['method' => $method, 'params' => $params, 'batchKey' => null];
        }
        // Имя не вытащили — это НЕ «команды нет». Молчать здесь значит разрешить
        // `$cmd[$k] = [$method, $p]` рядом с парой честных литералов: те дают
        // имена, а мутатор проезжает невидимым. Пустое имя даёт `dynamic-method`.
        if (!$named) $commands[] = ['method' => '', 'params' => $params, 'batchKey' => null];
    }
    return $commands;
}

/** Совместимый краткий список методов batch для остальных гейтов. */
function gateBatchPortalMethods(string $expr, array $vars, int $depth = 0): array {
    $methods = array_map(static fn(array $command): string => $command['method'],
        gateBatchPortalCommands($expr, $vars));
    return array_values(array_unique($methods === [] ? [''] : $methods));
}

/** Только прямое `$result = $b24->helper(...)`; скрытая provenance не принимается. */
function gateDirectCallAssignmentVar(array $tokens, int $arrow): string {
    $receiver = gatePrevToken($tokens, $arrow);
    if ($receiver === null || $tokens[$receiver]['id'] !== T_VARIABLE) return '';
    $equals = gatePrevToken($tokens, $receiver);
    $lhs = $equals === null ? null : gatePrevToken($tokens, $equals);
    if ($equals === null || $lhs === null || $tokens[$equals]['text'] !== '='
        || $tokens[$lhs]['id'] !== T_VARIABLE) return '';
    return $tokens[$lhs]['text'];
}

/**
 * Доказан ли receiver как результат b24() через prior assignments/aliases.
 * Имена `$client`/`$portal` сами по себе ничего не доказывают. Присваивания из
 * чужой функции не смешиваются, а условное присваивание перед безусловным
 * вызовом делает provenance неоднозначной и потому не принимается.
 */
/** Параметры самой внутренней function/fn: имя → написанный type expression. */
function gateCallableParameterTypesAt(string $file, int $callOffset): array {
    $scope = gateFunctionScopeAt($file, $callOffset);
    if ($scope === null) return [];
    $tokens = gateFlatTokens($file);
    $function = (int)($scope['atIndex'] ?? -1);
    $body = (int)($scope['openIndex'] ?? -1);
    if ($function < 0 || $body < 0) return [];
    $open = null;
    for ($i = $function + 1; $i < $body; $i++) {
        if ($tokens[$i]['text'] === '(') { $open = $i; break; }
    }
    if ($open === null) return [];
    $close = gateMatchingToken($tokens, $open);
    if ($close === null || $close >= $body) return [];

    $out = []; $segment = $open + 1; $round = 0; $square = 0;
    for ($i = $open + 1; $i < $close; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '(') $round++;
        elseif ($text === ')') $round--;
        elseif ($text === '[') $square++;
        elseif ($text === ']') $square--;
        if ($text === ',' && $round === 0 && $square === 0) { $segment = $i + 1; continue; }
        if ($tokens[$i]['id'] !== T_VARIABLE || $round !== 0 || $square !== 0) continue;
        $type = '';
        for ($j = $segment; $j < $i; $j++) {
            if ($tokens[$j]['id'] !== null && in_array(
                $tokens[$j]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
            )) continue;
            $type .= $tokens[$j]['text'];
        }
        $out[ltrim($tokens[$i]['text'], '$')] = str_replace(['&', '...'], '', $type);
    }
    return $out;
}

/** Class imports, которые доказуемо называют B24 type. */
function gatePortalClassAliases(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $tokens = gateFlatTokens($file); $aliases = ['b24' => true];
    $classes = gateClassBodyRanges($file);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_USE) continue;
        $insideClass = false;
        foreach ($classes as $range) {
            if ($i > $range[0] && $i < $range[1]) { $insideClass = true; break; }
        }
        if ($insideClass || gateFunctionScopeAt($file, (int)$tokens[$i]['offset']) !== null) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || $tokens[$next]['text'] === '(') continue; // closure use (...)
        $statement = '';
        for ($j = $next; $j < $n && $tokens[$j]['text'] !== ';'; $j++) $statement .= $tokens[$j]['text'];
        $split = static function (string $source): array {
            $items = []; $start = 0; $depth = 0;
            for ($at = 0, $length = strlen($source); $at < $length; $at++) {
                if ($source[$at] === '{') $depth++;
                elseif ($source[$at] === '}') $depth--;
                elseif ($source[$at] === ',' && $depth === 0) {
                    $items[] = trim(substr($source, $start, $at - $start)); $start = $at + 1;
                }
            }
            $items[] = trim(substr($source, $start));
            return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
        };
        $remember = static function (string $class, ?string $alias = null) use (&$aliases): void {
            $class = ltrim(trim($class), '\\');
            if (!preg_match('~(?:^|\\\\)B24$~i', $class)) return;
            $leaf = (string)preg_replace('~^.*\\\\~', '', $class);
            $aliases[strtolower($alias !== null && $alias !== '' ? $alias : $leaf)] = true;
        };
        foreach ($split(trim($statement)) as $item) {
            if (preg_match('~^(?:function|const)\s+~i', $item)) continue;
            if (preg_match('~^\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\\\\\{(.+)\}$~s', $item, $group)) {
                $prefix = rtrim($group[1], '\\');
                foreach ($split($group[2]) as $member) {
                    if (preg_match('~^(?:function|const)\s+~i', $member)) continue;
                    if (!preg_match('~^([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$~i', $member, $m)) continue;
                    $remember($prefix . '\\' . $m[1], $m[2] ?? null);
                }
                continue;
            }
            if (!preg_match('~^\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$~i', $item, $m)) continue;
            $remember($m[1], $m[2] ?? null);
        }
    }
    return $cache[$file] = $aliases;
}

/** Тип параметра/свойства разрешается через import alias, а не по spelling. */
function gatePortalTypeIsB24(string $file, string $type): bool {
    $type = trim(str_replace(['?', '&', '...'], '', $type));
    if ($type === '') return false;
    if (preg_match('~(?:^|\\\\)B24$~i', $type)) return true;
    return !empty(gatePortalClassAliases($file)[strtolower(ltrim($type, '\\'))]);
}

/** Явный `new B24(...)` — такой же доказанный portal receiver, как `b24()`. */
function gatePortalConstructsB24(string $file, string $expression): bool {
    $expression = trim(gateTrimExpressionParens($expression));
    if (!preg_match('~^new\s*\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\s*\(~i', $expression, $match)) {
        return false;
    }
    return gatePortalTypeIsB24($file, $match[1]);
}

/** B24-typed параметры самой внутренней function/fn, содержащей вызов. */
function gatePortalTypedParametersAt(string $file, int $callOffset): array {
    $out = [];
    foreach (gateCallableParameterTypesAt($file, $callOffset) as $name => $type) {
        if (gatePortalTypeIsB24($file, (string)$type)) $out[$name] = true;
    }
    return $out;
}

/** B24-provenance переменных, явно захваченных closure через `use (…)`. */
function gatePortalCapturedReceiversAt(string $file, int $callOffset): array {
    $scope = gateFunctionScopeAt($file, $callOffset);
    if ($scope === null) return [];
    $tokens = gateFlatTokens($file);
    $function = (int)($scope['atIndex'] ?? -1);
    $body = (int)($scope['openIndex'] ?? -1);
    $bodyClose = (int)($scope['closeIndex'] ?? -1);
    if ($function < 0 || $body < 0 || $bodyClose < 0) return [];

    // Arrow автоматически захватывает outer variables. Параметр с тем же именем
    // их затеняет, поэтому сначала исключаем ВСЕ parameters, не только B24-typed.
    if (!empty($scope['arrow'])) {
        $parameters = gateCallableParameterTypesAt($file, $callOffset);
        $out = [];
        for ($i = $body + 1; $i <= $bodyClose; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE) continue;
            $name = ltrim($tokens[$i]['text'], '$');
            if ($name === 'this' || array_key_exists($name, $parameters) || isset($out[$name])) continue;
            if (gatePortalReceiverProvenanceAt(
                $file, $tokens[$i]['text'], (int)$tokens[$function]['offset']
            )) $out[$name] = true;
        }
        return $out;
    }

    $use = null;
    for ($i = $function + 1; $i < $body; $i++) {
        if ($tokens[$i]['id'] === T_USE) { $use = $i; break; }
    }
    if ($use === null) return [];
    $open = gateNextToken($tokens, $use, $body);
    if ($open === null || $tokens[$open]['text'] !== '(') return [];
    $close = gateMatchingToken($tokens, $open);
    if ($close === null || $close >= $body) return [];
    $out = [];
    for ($i = $open + 1; $i < $close; $i++) {
        if ($tokens[$i]['id'] !== T_VARIABLE) continue;
        $name = ltrim($tokens[$i]['text'], '$');
        if (gatePortalReceiverProvenanceAt(
            $file, $tokens[$i]['text'], (int)$tokens[$function]['offset']
        )) $out[$name] = true;
    }
    return $out;
}

/** Доказанные B24 properties текущего class: typed или constructor-assigned. */
function gatePortalTypedPropertiesAt(string $file, int $callOffset): array {
    $tokens = gateFlatTokens($file); $callIndex = null;
    foreach ($tokens as $i => $token) {
        if ((int)$token['offset'] <= $callOffset) $callIndex = $i; else break;
    }
    if ($callIndex === null) return [];
    $class = null;
    foreach (gateClassBodyRanges($file) as $range) {
        if ($callIndex <= $range[0] || $callIndex >= $range[1]) continue;
        if ($class === null || ($range[1] - $range[0]) < ($class[1] - $class[0])) $class = $range;
    }
    if ($class === null) return [];

    $out = []; $visibility = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR];
    $modifiers = array_merge($visibility, [T_STATIC]);
    if (defined('T_READONLY')) $modifiers[] = constant('T_READONLY');
    foreach ($tokens as $i => $token) {
        if ($i <= $class[0] || $i >= $class[1] || $token['id'] !== T_VARIABLE) continue;
        $parts = []; $hasVisibility = false;
        for ($j = $i - 1; $j > $class[0]; $j--) {
            if ($tokens[$j]['id'] !== null && in_array(
                $tokens[$j]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
            )) continue;
            if (in_array($tokens[$j]['text'], [';', '{', '}', '(', ')', ','], true)) break;
            if (in_array($tokens[$j]['id'], $visibility, true)) $hasVisibility = true;
            if (!in_array($tokens[$j]['id'], $modifiers, true)) array_unshift($parts, $tokens[$j]['text']);
        }
        if (!$hasVisibility) continue;
        if (gatePortalTypeIsB24($file, implode('', $parts))) {
            $out[ltrim($token['text'], '$')] = true;
        }
    }

    // PHP 7.4-compatible constructor injection: `$this->p = $typedParameter;`.
    foreach (gateFunctionBodyRanges($file) as $scope) {
        $at = (int)($scope['atIndex'] ?? -1);
        if ($at <= $class[0] || $at >= $class[1] || !empty($scope['arrow'])) continue;
        $name = gateNextToken($tokens, $at);
        while ($name !== null && $tokens[$name]['text'] === '&') $name = gateNextToken($tokens, $name);
        if ($name === null || $tokens[$name]['id'] !== T_STRING
            || strcasecmp($tokens[$name]['text'], '__construct') !== 0) continue;
        $inside = (int)$scope['start'] + 1;
        $portalParameters = gatePortalTypedParametersAt($file, $inside);
        $body = substr((string)@file_get_contents($file), (int)$scope['start'],
            max(0, (int)$scope['end'] - (int)$scope['start']));
        if (preg_match_all('~\$this\s*->\s*([A-Za-z_]\w*)\s*=\s*([^;]+)\s*;~',
            $body, $factoryAssignments, PREG_SET_ORDER)) {
            foreach ($factoryAssignments as $assignment) {
                $factory = preg_replace('~\s+~', '', gateTrimExpressionParens($assignment[2]));
                if (strcasecmp((string)$factory, 'b24()') === 0
                    || strcasecmp((string)$factory, '\\b24()') === 0
                    || gatePortalConstructsB24($file, (string)$factory)) {
                    $out[$assignment[1]] = true;
                }
            }
        }
        if ($portalParameters === []) continue;
        if (!preg_match_all('~\$this\s*->\s*([A-Za-z_]\w*)\s*=\s*\$([A-Za-z_]\w*)\s*;~',
            $body, $assignments, PREG_SET_ORDER)) continue;
        foreach ($assignments as $assignment) {
            if (!empty($portalParameters[$assignment[2]])) $out[$assignment[1]] = true;
        }
    }

    // Current-method property transfer до конкретного call. Constructor/type
    // дают seed, но безусловный overwrite обязан его снять; b24()/alias — дать.
    $callPath = gateControlBranchPathAt($file, $callOffset);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $offset = (int)$tokens[$i]['offset'];
        if ($offset >= $callOffset) break;
        if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== '$this'
            || !gateSameFunctionScope($file, $offset, $callOffset)) continue;
        $arrow = gateNextToken($tokens, $i);
        $property = $arrow === null ? null : gateNextToken($tokens, $arrow);
        $operator = $property === null ? null : gateNextToken($tokens, $property);
        if ($arrow === null || $tokens[$arrow]['text'] !== '->'
            || $property === null || $tokens[$property]['id'] !== T_STRING
            || $operator === null || $tokens[$operator]['text'] !== '=') continue;

        $previous = gatePrevToken($tokens, $i);
        $previousText = $previous === null ? '' : $tokens[$previous]['text'];
        $previousId = $previous === null ? null : $tokens[$previous]['id'];
        if ($previous !== null && !in_array($previousText, [';', '{', '}', ':'], true)
            && $previousId !== T_OPEN_TAG) continue;

        $expr = ''; $round = 0; $square = 0; $curly = 0; $end = null;
        for ($j = $operator + 1; $j < $n; $j++) {
            if ((int)$tokens[$j]['offset'] >= $callOffset) break;
            $text = $tokens[$j]['text'];
            if ($text === ';' && $round === 0 && $square === 0 && $curly === 0) {
                $end = $j; break;
            }
            if ($text === '(') $round++;
            elseif ($text === ')') $round--;
            elseif ($text === '[') $square++;
            elseif ($text === ']') $square--;
            elseif ($text === '{') $curly++;
            elseif ($text === '}') $curly--;
            $expr .= $text;
        }
        if ($end === null) continue;
        $guaranteed = true;
        foreach (gateControlBranchPathAt($file, $offset) as $branch => $_) {
            if (!isset($callPath[$branch])) { $guaranteed = false; break; }
        }
        if (!$guaranteed) { $i = $end; continue; }

        $name = $tokens[$property]['text'];
        $expr = preg_replace('~\s+~', '', gateTrimExpressionParens($expr));
        if (strcasecmp((string)$expr, 'b24()') === 0
            || strcasecmp((string)$expr, '\\b24()') === 0
            || gatePortalConstructsB24($file, (string)$expr)) {
            $out[$name] = true;
        } elseif (preg_match('~^\$(\w+)$~', (string)$expr, $match)) {
            $out[$name] = gatePortalReceiverProvenanceAt(
                $file, '$' . $match[1], $offset
            );
        } elseif (preg_match('~^\$this->(\w+)$~', (string)$expr, $match)) {
            $out[$name] = !empty($out[$match[1]]);
        } else {
            $out[$name] = false;
        }
        $i = $end;
    }
    return $out;
}

/**
 * Доказан ли receiver как результат b24() через typed parameter или prior
 * assignments/aliases. Имена переменных доказательством не являются.
 */
function gatePortalReceiverProvenanceAt(string $file, string $receiver, int $callOffset): bool {
    $target = ltrim($receiver, '$');
    if ($target === '' || !preg_match('~^\w+$~', $target)) return false;
    $tokens = gateFlatTokens($file);
    $state = gatePortalTypedParametersAt($file, $callOffset)
        + gatePortalCapturedReceiversAt($file, $callOffset);
    $callPath = gateControlBranchPathAt($file, $callOffset);
    $assignmentIds = [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
        T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL,
        T_SL_EQUAL, T_SR_EQUAL];
    if (defined('T_POW_EQUAL')) $assignmentIds[] = constant('T_POW_EQUAL');
    if (defined('T_COALESCE_EQUAL')) $assignmentIds[] = constant('T_COALESCE_EQUAL');

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $offset = (int)$tokens[$i]['offset'];
        if ($offset >= $callOffset) break;
        if ($tokens[$i]['id'] !== T_VARIABLE) continue;
        $operator = gateNextToken($tokens, $i);
        if ($operator === null || (int)$tokens[$operator]['offset'] >= $callOffset) continue;
        $isPlain = $tokens[$operator]['text'] === '=';
        if (!$isPlain && !in_array($tokens[$operator]['id'], $assignmentIds, true)) continue;
        if (!gateSameFunctionScope($file, $offset, $callOffset)) continue;
        $name = ltrim($tokens[$i]['text'], '$');
        if (!$isPlain) { $state[$name] = false; continue; }

        // Только самостоятельное assignment statement. Присваивания внутри
        // short-circuit/condition/call требуют полноценного CFG и не являются proof.
        $previous = gatePrevToken($tokens, $i);
        $previousText = $previous === null ? '' : $tokens[$previous]['text'];
        $previousId = $previous === null ? null : $tokens[$previous]['id'];
        if ($previous !== null && !in_array($previousText, [';', '{', '}', ':'], true)
            && $previousId !== T_OPEN_TAG) {
            $state[$name] = false;
            continue;
        }

        $expr = ''; $round = 0; $square = 0; $curly = 0; $end = null;
        for ($j = $operator + 1; $j < $n; $j++) {
            if ((int)$tokens[$j]['offset'] >= $callOffset) break;
            $text = $tokens[$j]['text'];
            if ($text === ';' && $round === 0 && $square === 0 && $curly === 0) {
                $end = $j; break;
            }
            if ($text === '(') $round++;
            elseif ($text === ')') $round--;
            elseif ($text === '[') $square++;
            elseif ($text === ']') $square--;
            elseif ($text === '{') $curly++;
            elseif ($text === '}') $curly--;
            $expr .= $text;
        }
        if ($end === null) { $state[$name] = false; continue; }

        $guaranteed = true;
        foreach (gateControlBranchPathAt($file, $offset) as $branch => $_) {
            if (!isset($callPath[$branch])) { $guaranteed = false; break; }
        }
        if (!$guaranteed) { $state[$name] = false; $i = $end; continue; }

        $expr = preg_replace('~\s+~', '', gateTrimExpressionParens($expr));
        if (strcasecmp((string)$expr, 'b24()') === 0
            || strcasecmp((string)$expr, '\\b24()') === 0
            || gatePortalConstructsB24($file, (string)$expr)) {
            $state[$name] = true;
        } elseif (preg_match('~^\$(\w+)$~', (string)$expr, $match)) {
            $state[$name] = !empty($state[$match[1]]);
        } else {
            $state[$name] = false;
        }
        $i = $end;
    }
    return !empty($state[$target]);
}

/**
 * Все обращения через готовый B24-клиент.
 *
 * Динамический метод возвращается как пустая строка: его нельзя молча считать
 * чтением — иначе `$m = 'crm.item.delete'; $b24->call($m)` выключает весь
 * положительный гейт. batch()/batchAll() раскрываются по первому аргументу.
 *
 * @return array<int,array{method:string,helper:string,line:int,offset:int,endOffset:int,
 *     params:string,expected:string,receiver:string,receiverOffset:int,resultVar:string,
 *     batchKey:?string,literalMethod:bool}>
 */
function gatePortalCallDetails(string $file): array {
    $tokens = gateFlatTokens($file);
    $vars   = gateVarMap($file);
    // ЛЮБОЙ способ позвать портал обязан быть в этом списке. Пока помощника тут
    // нет, гейт не видит вызов ВООБЩЕ: ни мутацию без защиты, ни список без
    // пагинации. Проверено 08.08.2026: два новых помощника пакетов отсутствовали
    // здесь, и модули, которые ими пользуются, прошли гейт не потому, что
    // написаны правильно, а потому что были для него невидимы. Добавил помощник
    // в клиент — добавь его сюда ТЕМ ЖЕ заходом.
    $helpers = ['call' => 1, 'callChecked' => 1, 'fetchAll' => 1,
                'fetchAllBatched' => 1, 'loadByIds' => 1, 'readBackChecked' => 1,
                'deleteVerified' => 1, 'actionVerified' => 1,
                'batch' => 1, 'batchAll' => 1, 'batchAllChecked' => 1,
                'batchAllPartialChecked' => 1, 'batchAllIdempotent' => 1,
                // listStats() спрашивает у портала только два числа (сколько
                // всего и крайний ID) и записей наружу не отдаёт вовсе, поэтому
                // «список без пагинации» им не нарушить. В списке он всё равно
                // обязан быть: иначе гейт не увидит ни имени метода, ни мутации,
                // если однажды его позовут не на список.
                'listStats' => 1];
    $out = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['text'] !== '->') continue;
        $receiverAt = gatePrevToken($tokens, $i);
        $receiver = '';
        $portalReceiver = $receiverAt !== null
            && $tokens[$receiverAt]['id'] === T_VARIABLE
            && gatePortalReceiverProvenanceAt(
                $file,
                $tokens[$receiverAt]['text'],
                (int)$tokens[$receiverAt]['offset']
            );
        if ($portalReceiver && $receiverAt !== null) $receiver = $tokens[$receiverAt]['text'];
        // `$this->client->helper(...)`: property name — receiver второго `->`.
        // Оно portal только при typed declaration либо доказуемом constructor
        // assignment от B24-typed parameter в ЭТОМ ЖЕ class.
        if (!$portalReceiver && $receiverAt !== null && $tokens[$receiverAt]['id'] === T_STRING) {
            $propertyArrow = gatePrevToken($tokens, $receiverAt);
            $owner = $propertyArrow === null ? null : gatePrevToken($tokens, $propertyArrow);
            if ($propertyArrow !== null && $tokens[$propertyArrow]['text'] === '->'
                && $owner !== null && $tokens[$owner]['id'] === T_VARIABLE
                && $tokens[$owner]['text'] === '$this'
                && !empty(gatePortalTypedPropertiesAt(
                    $file, (int)$tokens[$receiverAt]['offset']
                )[$tokens[$receiverAt]['text']])) {
                $portalReceiver = true;
                $receiver = '$this->' . $tokens[$receiverAt]['text'];
            }
        }
        if (!$portalReceiver && $receiverAt !== null && $tokens[$receiverAt]['text'] === ')') {
            $receiverOpen = gateMatchingOpenToken($tokens, $receiverAt);
            $receiverName = $receiverOpen === null ? null : gatePrevToken($tokens, $receiverOpen);
            $portalReceiver = $receiverName !== null
                && $tokens[$receiverName]['id'] === T_STRING
                && strcasecmp($tokens[$receiverName]['text'], 'b24') === 0;
            if ($portalReceiver) $receiver = 'b24()';
        }
        $nameAt = gateNextToken($tokens, $i);
        if ($nameAt === null) continue;
        $helperEnd = $nameAt; $dynamicHelper = false; $helperExpr = '';
        if ($tokens[$nameAt]['id'] === T_STRING) {
            $helper = $tokens[$nameAt]['text'];
        } elseif ($tokens[$nameAt]['id'] === T_VARIABLE) {
            $dynamicHelper = true; $helperExpr = $tokens[$nameAt]['text']; $helper = '';
        } elseif ($tokens[$nameAt]['text'] === '{') {
            $helperEnd = gateMatchingToken($tokens, $nameAt) ?? $nameAt;
            if ($helperEnd === $nameAt) continue;
            for ($j = $nameAt + 1; $j < $helperEnd; $j++) $helperExpr .= $tokens[$j]['text'];
            $dynamicHelper = true; $helper = '';
        } else {
            continue;
        }
        if ($dynamicHelper) {
            $resolved = gateStaticLiteralExpression(gateResolve($helperExpr, $vars));
            if ($resolved !== null) $helper = $resolved;
        }
        // Известное имя helper само по себе не делает произвольный object
        // портальным клиентом: provenance обязательна для всех веток dispatch.
        if (!$portalReceiver) continue;
        if (!isset($helpers[$helper])) {
            // Неизвестный method expression на доказанном B24 receiver нельзя
            // терять: консервативно считаем его raw call, и semantic gates сами
            // классифицируют literal REST-method либо ставят dynamic-method.
            if (!$dynamicHelper) continue;
            $helper = 'call';
        }
        $open = gateNextToken($tokens, $helperEnd);
        if ($open === null || $tokens[$open]['text'] !== '(') continue;
        $close = gateMatchingToken($tokens, $open);
        if ($close === null) continue;
        $args = gateCallArgTokens($tokens, $open, $close);
        $firstArg = $args[0] ?? '';
        $common = [
            'helper' => $helper,
            'line' => (int)$tokens[$nameAt]['line'],
            'offset' => (int)$tokens[$nameAt]['offset'],
            'endOffset' => (int)$tokens[$close]['offset'] + strlen($tokens[$close]['text']),
            'expected' => $args[2] ?? '',
            'receiver' => $receiver,
            'receiverOffset' => $receiverAt === null ? -1 : (int)$tokens[$receiverAt]['offset'],
            'resultVar' => gateDirectCallAssignmentVar($tokens, $i),
            'literalMethod' => false,
        ];
        // Помощники, которым первым аргументом идёт ПАКЕТ команд. Их разбирают
        // иначе: методы лежат внутри массива, а не в первом аргументе. Забыть
        // добавить сюда новый помощник — значит сделать его вызовы «безымянными»,
        // и тогда контракт мутации к ним просто не применяется.
        if (in_array($helper, ['batch', 'batchAll', 'batchAllChecked',
                               'batchAllPartialChecked', 'batchAllIdempotent'], true)) {
            $commands = gateBatchPortalCommands($firstArg, $vars);
            // Пакет передан переменной — значит собран циклом. Достаём методы из
            // самой сборки, иначе вызов остаётся без имени и не проверяется.
            $noNames = true;
            foreach ($commands as $c) if (($c['method'] ?? '') !== '') $noNames = false;
            if ($noNames && preg_match('~^\s*\$\w+\s*$~', $firstArg)) {
                $assembled = gateBatchCommandsFromAssembly(
                    $file, $firstArg, $vars, (int)$tokens[$nameAt]['offset']);
                if ($assembled) $commands = $assembled;
            }
            foreach ($commands as $command) {
                $out[] = array_merge($common, $command);
            }
        } else {
            foreach (gateScalarPortalMethods($firstArg, $vars) as $method) {
                $out[] = array_merge($common, [
                    'method' => $method, 'params' => $args[1] ?? '[]', 'batchKey' => null,
                    'literalMethod' => (bool)preg_match(
                        '~^([\'"])[a-z][a-z0-9_.]*\1$~i', gateTrimExpressionParens($firstArg)
                    ),
                ]);
            }
        }
        $i = $helperEnd;
    }
    return $out;
}

/** Диапазоны тел inline-callback'ов runOnce и auditLog внутри каждого тела. */
function gateRunOnceBodies(string $file): array {
    $tokens = gateFlatTokens($file); $out = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_STRING || strcasecmp($tokens[$i]['text'], 'runOnce') !== 0) continue;
        $prev = gatePrevToken($tokens, $i);
        if ($prev !== null && ($tokens[$prev]['id'] === T_FUNCTION
            || in_array($tokens[$prev]['text'], ['->', '::'], true))) continue;
        $open = gateNextToken($tokens, $i);
        if ($open === null || $tokens[$open]['text'] !== '(') continue;
        $close = gateMatchingToken($tokens, $open);
        if ($close === null) continue;

        // Closure обязана быть именно ВТОРЫМ аргументом runOnce. Раньше поиск
        // брал первую closure где угодно: `runOnce((function(){ mutate(); })(),
        // function(){})` выполнял мутацию ещё при вычислении ключа и проходил.
        $comma = null; $round = 0; $square = 0; $curly = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === '(') $round++; elseif ($text === ')') $round--;
            elseif ($text === '[') $square++; elseif ($text === ']') $square--;
            elseif ($text === '{') $curly++; elseif ($text === '}') $curly--;
            elseif ($text === ',' && $round === 0 && $square === 0 && $curly === 0) { $comma = $j; break; }
        }
        if ($comma === null) continue;
        $fn = gateNextToken($tokens, $comma, $close);
        if ($fn !== null && $tokens[$fn]['id'] === T_STATIC) $fn = gateNextToken($tokens, $fn, $close);
        if ($fn === null || $tokens[$fn]['id'] !== T_FUNCTION) continue; // inline closure вторым аргументом обязательна
        $bodyOpen = null;
        for ($j = $fn + 1; $j < $close; $j++) {
            if ($tokens[$j]['text'] === '{') { $bodyOpen = $j; break; }
        }
        if ($bodyOpen === null) continue;
        $bodyClose = gateMatchingToken($tokens, $bodyOpen);
        if ($bodyClose === null || $bodyClose > $close) continue;
        $start = (int)$tokens[$bodyOpen]['offset'];
        $end   = (int)$tokens[$bodyClose]['offset'];
        $audits = [];
        for ($j = $bodyOpen + 1; $j < $bodyClose; $j++) {
            if ($tokens[$j]['id'] !== T_STRING || strcasecmp($tokens[$j]['text'], 'auditLog') !== 0) continue;
            $p = gatePrevToken($tokens, $j);
            if ($p !== null && ($tokens[$p]['id'] === T_FUNCTION || in_array($tokens[$p]['text'], ['->', '::'], true))) continue;
            $audits[] = (int)$tokens[$j]['offset'];
        }
        $out[] = ['start' => $start, 'end' => $end, 'audits' => $audits];
        $i = $bodyClose;
    }
    return $out;
}

/**
 * Доказуемо read-only метод портала.
 *
 * Mutation-имён у Битрикс24 нет конечного словаря: `workflow.start`,
 * `task.complete`, `folder.uploadfile`, `task.delegate` меняют данные без
 * привычных add/update/delete. Поэтому неизвестное — мутация; расширение
 * read-only allowlist требует подтверждённой правки гейта и регрессии.
 */
function gateIsReadOnlyMethod(string $method): bool {
    $method = strtolower($method);
    if ((bool)preg_match('~\.(get|list|search|fields)$~', $method)) return true;
    // crm.duplicate.findbycomm ничего не меняет: по телефону или почте он лишь
    // отдаёт ID лидов, контактов и компаний (crm/duplicates/crm-duplicate-find-by-comm.md).
    // Глагол findbycomm не попадает под общий шаблон чтения, поэтому назван прямо.
    return in_array($method, ['app.info', 'user.current', 'profile', 'scope',
        'crm.duplicate.findbycomm'], true);
}

/** Всё, что не доказано узким read-only allowlist, считаем мутацией. */
function gateIsMutatingMethod(string $method): bool {
    return !gateIsReadOnlyMethod($method);
}

/** Семейство REST-метода без конечного глагола: crm.item.update → crm.item. */
function gatePortalMethodFamily(string $method): string {
    $parts = explode('.', strtolower($method));
    if (count($parts) < 2) return '';
    array_pop($parts);
    return implode('.', $parts);
}

/** Контракт результата по последнему REST-глаголу. */
function gateMutationContract(string $method): string {
    // Реестр policy-helper'ов сильнее глагола. У семьи бывает add без всякого
    // *.get: bizproc.activity.add регистрирует действие приложения, а прочитать
    // его нечем — bizproc.activity.list отдаёт только коды. Пока контракт
    // выводился из глагола, такой метод требовал невозможного read-back, и
    // единственным выходом оставался обход гейта.
    if (gateVerifiedMutationMethodAllowed('action', $method)) return 'action';
    $parts = explode('.', strtolower($method));
    $verb = (string)end($parts);
    if (in_array($verb, ['add', 'update', 'set'], true)) return 'read-back';
    if (in_array($verb, ['delete', 'remove'], true)) return 'delete';
    return 'action';
}

/**
 * Реестр policy-helper'ов дублирует закрытый runtime-реестр B24.
 *
 * Список вынесен отдельно, чтобы регрессия могла сверить его с политиками
 * B24::verifiedMutationPolicy(): разойдутся — гейт пропустит вызов, который
 * клиент отвергнет исключением уже на бою.
 *
 * @return array<string, string[]>
 */
function gateVerifiedMutationRegistry(): array {
    return [
        'delete' => ['crm.item.delete', 'tasks.task.delete'],
        'action' => ['bizproc.workflow.start', 'disk.folder.uploadfile',
            // Действия БП: у всех трёх официальный ответ — result: true, а
            // перечитать результат нечем. Контракты живут в B24::verifiedMutationPolicy().
            'bizproc.activity.add', 'bizproc.activity.update', 'bizproc.event.send'],
    ];
}

function gateVerifiedMutationMethodAllowed(string $contract, string $method): bool {
    $allowed = gateVerifiedMutationRegistry();
    return in_array(strtolower($method), $allowed[$contract] ?? [], true);
}

/** Чтение проверяет именно то же семейство сущностей, которое только что меняли. */
function gateIsReadBackFor(string $mutationMethod, string $readMethod): bool {
    if ($mutationMethod === '' || $readMethod === '' || !gateIsReadOnlyMethod($readMethod)) return false;
    $mutationFamily = gatePortalMethodFamily($mutationMethod);
    return $mutationFamily !== '' && hash_equals($mutationFamily, gatePortalMethodFamily($readMethod));
}

/** Цепочка `$r['result']['x']`; любые вызовы/арифметика дают null. */
function gateArrayAccessChain(string $expr): ?array {
    $tokens = gateExpressionTokenTexts(gateTrimExpressionParens($expr));
    if ($tokens === [] || !preg_match('~^\$\w+$~D', $tokens[0])) return null;
    $chain = ['variable' => $tokens[0], 'keys' => []];
    for ($i = 1, $n = count($tokens); $i < $n;) {
        if ($tokens[$i] !== '[') return null;
        $close = array_search(']', array_slice($tokens, $i + 1), true);
        if ($close === false) return null;
        $close += $i + 1;
        $keyExpr = implode('', array_slice($tokens, $i + 1, $close - $i - 1));
        $key = gateLiteralArrayKey($keyExpr);
        if ($key === null) return null;
        $chain['keys'][] = strtolower($key);
        $i = $close + 1;
    }
    return $chain;
}

/** ID для add обязан происходить из результата именно этой команды. */
/** Присваивания переменных со смещением: `$name` → [['offset'=>…, 'code'=>…], …]. */
function gateVarAssignmentOffsets(string $file): array {
    $tokens = gateFlatTokens($file);
    $out = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_VARIABLE) continue;
        $next = gateNextToken($tokens, $i);
        if ($next === null || (string)$tokens[$next]['text'] !== '=') continue;
        $expr = '';
        for ($j = $next + 1; $j < $n; $j++) {
            $text = (string)$tokens[$j]['text'];
            if ($text === ';') break;
            $expr .= $text;
        }
        $out[ltrim((string)$tokens[$i]['text'], '$')][] = [
            'offset' => (int)$tokens[$i]['offset'],
            'code'   => trim($expr),
        ];
    }
    return $out;
}

/**
 * @param array|null $window ['after' => конец мутации, 'before' => начало read-back]
 */
function gateReadIdComesFromMutation(array $mutation, string $readId, array $vars = [], ?array $window = null): bool {
    if ($mutation['resultVar'] === '') return false;
    // Идентификатор созданной сущности разрешено положить в переменную:
    // `$id = $added['item']['id'];` — обычная форма, которой учит документация.
    // Раньше гейт принимал только подстановку выражения прямо в селектор, а
    // человеку сообщал «мутация без защиты от повтора, read-back или следа» —
    // то есть указывал не на ту причину и требовал того, что нигде не описано.
    if (preg_match('~^\s*\$(\w+)\s*$~D', $readId, $m)) {
        $name = $m[1];
        // Разворачиваем только присваивание, стоящее МЕЖДУ этой мутацией и этим
        // read-back'ом. Без окна проходила подмена: `$id` от ПРЕДЫДУЩЕЙ мутации
        // подставлялся в сверку следующей — гейт видел законную цепочку, а
        // проверялась чужая запись, то есть результат второй мутации никто не
        // сверял вовсе. Присваиваний должно быть ровно одно: переприсваиваемая
        // переменная в момент сверки уже ничего не доказывает.
        $assignments = $window['assignments'][$name] ?? null;
        if (is_array($assignments)) {
            // Считаем присваивания ТОЛЬКО в окне «эта запись → эта сверка».
            // По всему файлу считать нельзя: два честных блока мутации в одном
            // эндпоинте (создали сделку, потом задачу) дают по своему `$id = …`
            // каждый — и обе законные цепочки краснели, требуя того, чего в
            // документации нет.
            $after  = (int)($window['after'] ?? 0);
            $before = (int)($window['before'] ?? PHP_INT_MAX);
            $inWindow = array_values(array_filter($assignments, static function (array $a) use ($after, $before): bool {
                return (int)$a['offset'] > $after && (int)$a['offset'] < $before;
            }));
            if (count($inWindow) !== 1) return false;
            $only = $inWindow[0];
            $readId = (string)$only['code'];
        } elseif ($vars !== [] && isset($vars[$name])) {
            $candidates = is_array($vars[$name]) ? array_values($vars[$name]) : [$vars[$name]];
            if (count($candidates) === 1 && is_string($candidates[0])) $readId = $candidates[0];
        }
    }
    $chain = gateArrayAccessChain($readId);
    if ($chain === null || !hash_equals($mutation['resultVar'], $chain['variable'])) return false;
    $keys = $chain['keys'];
    if ($mutation['batchKey'] !== null) {
        if (($keys[0] ?? '') !== 'result' || ($keys[1] ?? '') !== strtolower($mutation['batchKey'])) return false;
        $keys = array_slice($keys, 2);
    } elseif ($mutation['helper'] === 'call') {
        if (($keys[0] ?? '') !== 'result') return false;
        $keys = array_slice($keys, 1);
    }
    return $keys === [] || $keys === ['id'] || $keys === ['item', 'id'] || $keys === ['task', 'id'];
}

/**
 * Имена функций файла, принимающих аргумент по ссылке: имя → номера позиций (с 1).
 *
 * Без этого подмена уезжала в собственный помощник проекта: `нормализовать($fields)`
 * с сигнатурой `function нормализовать(array &$fields)` меняет проверяемый
 * снимок ровно так же, как `sort()`, но список встроенных функций про него не знает.
 */
function gateByRefFunctionParams(string $file): array {
    $tokens = gateFlatTokens($file);
    $out = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_FUNCTION) continue;
        $name = gateNextToken($tokens, $i);
        if ($name === null || $tokens[$name]['id'] !== T_STRING) continue;   // анонимная — пропускаем
        $open = gateNextToken($tokens, $name);
        if ($open === null || (string)$tokens[$open]['text'] !== '(') continue;
        $depth = 0; $arg = 1; $positions = [];
        for ($j = $open, $m = count($tokens); $j < $m; $j++) {
            $text = (string)$tokens[$j]['text'];
            if ($text === '(') { $depth++; continue; }
            if ($text === ')') { $depth--; if ($depth === 0) break; continue; }
            if ($text === ',' && $depth === 1) { $arg++; continue; }
            if ($text === '&' && $depth === 1) $positions[$arg] = true;
        }
        if ($positions !== []) $out[strtolower((string)$tokens[$name]['text'])] = $positions;
    }
    return $out;
}

/**
 * Меняет ли вызов $owner именно тот аргумент, в котором стоит токен $i.
 *
 * Позиция важна: `preg_match($re, $строка, $m)` меняет ТРЕТИЙ аргумент, а вторым
 * стоит читаемая строка. Прежняя проверка судила по одному имени функции и
 * краснела на честном коде — «читаю поле» выглядело как «подменяю снимок».
 */
function gateCallMutatesArgument(array $tokens, int $i, string $owner): bool {
    $name = strtolower($owner);
    // Меняют весь массив/переменную целиком — позиция первая.
    $whole = [
        'unset' => [1], 'sort' => [1], 'rsort' => [1], 'usort' => [1], 'uasort' => [1],
        'uksort' => [1], 'ksort' => [1], 'krsort' => [1], 'asort' => [1], 'arsort' => [1],
        'natsort' => [1], 'natcasesort' => [1], 'shuffle' => [1], 'array_push' => [1],
        'array_pop' => [1], 'array_shift' => [1], 'array_unshift' => [1], 'array_splice' => [1],
        'array_walk' => [1], 'array_walk_recursive' => [1], 'array_multisort' => [1],
        'settype' => [1], 'extract' => [1],
    ];
    // Меняют конкретный по счёту аргумент — остальные позиции читаются честно.
    $byPosition = [
        'preg_match' => [3], 'preg_match_all' => [3], 'preg_replace' => [5],
        'preg_replace_callback' => [5], 'str_replace' => [4], 'str_ireplace' => [4],
        'similar_text' => [3], 'parse_str' => [2], 'sscanf' => [3], 'array_multisort' => [1],
        'openssl_sign' => [2], 'exec' => [2], 'preg_split' => [0],
    ];
    $positions = $whole[$name] ?? ($byPosition[$name] ?? null);
    if ($positions === null) {
        // Собственные функции файла с параметром по ссылке.
        $byRef = gateByRefFunctionParams($GLOBALS['__gate_current_file'] ?? '');
        if (!isset($byRef[$name])) return false;
        $positions = array_keys($byRef[$name]);
    }
    if ($positions === []) return false;
    return in_array(gateArgumentPosition($tokens, $i), $positions, true);
}

/** Номер аргумента (с 1), в котором стоит токен $i внутри своего вызова. */
function gateArgumentPosition(array $tokens, int $i): int {
    $depth = 0; $commas = 0;
    for ($j = $i - 1; $j >= 0; $j--) {
        $text = (string)$tokens[$j]['text'];
        if ($text === ')' || $text === ']') { $depth++; continue; }
        if ($text === '(' || $text === '[') {
            if ($depth > 0) { $depth--; continue; }
            break;                       // дошли до своей открывающей скобки
        }
        if ($text === ',' && $depth === 0) $commas++;
    }
    return $commas + 1;
}

/** Переменные выражений не должны переопределяться/использоваться между write и read-back. */
function gateExpressionsStayStable(string $file, array $expressions, int $after, int $before): bool {
    $watched = [];
    foreach ($expressions as $expr) {
        foreach (gateExpressionTokenTexts($expr) as $token) {
            if (preg_match('~^\$(\w+)$~D', $token, $m)) $watched[$m[1]] = true;
        }
    }
    if ($watched === []) return true;
    // Разбор by-ref параметров идёт по тому же файлу: помощник проекта,
    // меняющий аргумент по ссылке, объявлен рядом с использованием.
    $GLOBALS['__gate_current_file'] = $file;
    $tokens = gateFlatTokens($file);
    foreach ($tokens as $i => $token) {
        $offset = (int)$token['offset'];
        if ($offset <= $after || $offset >= $before || $token['id'] !== T_VARIABLE) continue;
        if (!isset($watched[ltrim($token['text'], '$')])) continue;
        // Само по себе ЧТЕНИЕ переменной ничего не подменяет: `$id = $added['item']['id'];`
        // между мутацией и read-back — штатный шаг. Нестабильность создаёт только
        // переприсваивание (в том числе +=, ??=) и передача по ссылке: после них
        // выражение в read-back уже не обязано означать то же, что при записи.
        $next = gateNextToken($tokens, $i);
        $prev = gatePrevToken($tokens, $i);
        $nextText = $next === null ? '' : (string)$tokens[$next]['text'];
        $prevText = $prev === null ? '' : (string)$tokens[$prev]['text'];
        $isAssignment = $nextText === '=' || $nextText === '.=' || $nextText === '+='
            || $nextText === '-=' || $nextText === '*=' || $nextText === '/='
            || $nextText === '??=';
        // Обращение к элементу — не подмена: `$added['item']['id']` только читает.
        // Подменой считается запись В элемент: `$added['item'] = ...`. Отличаем их,
        // дойдя до закрывающей скобки и посмотрев, что стоит следом.
        if (!$isAssignment && $nextText === '[') {
            $depth = 0;
            for ($j = $next; $j < count($tokens); $j++) {
                $text = (string)$tokens[$j]['text'];
                if ($text === '[') { $depth++; continue; }
                if ($text !== ']') continue;
                $depth--;
                if ($depth > 0) continue;
                $after = gateNextToken($tokens, $j);
                $afterText = $after === null ? '' : (string)$tokens[$after]['text'];
                if ($afterText === '[') { $j = $after - 1; $depth = 0; continue; }
                $isAssignment = in_array($afterText, ['=', '.=', '+=', '-=', '*=', '/=', '??='], true);
                break;
            }
        }
        $isByReference = $prevText === '&';
        $isIncrement = $nextText === '++' || $nextText === '--'
            || $prevText === '++' || $prevText === '--';
        // Знак «=» — не единственный способ подменить значение. `sort($ids)`,
        // `array_shift($ids)`, `preg_match($re, $s, $ids)` меняют переменную по
        // ссылке, а `unset($ids['id'])` вырезает из неё проверяемый элемент — и
        // всё это молча проходило, потому что искали только присваивание. После
        // любого из них выражение в read-back уже не означает то, что писали.
        $owner = gateEnclosingCallName($tokens, $i);
        $isMutatedByCall = $owner !== null && gateCallMutatesArgument($tokens, $i, $owner);
        if ($isAssignment || $isByReference || $isIncrement || $isMutatedByCall) return false;
    }
    return true;
}

/** Receiver не подменён между write и verifier; сам receiver read-вызова разрешён. */
function gateReceiverStaysStable(string $file, array $mutation, array $read): bool {
    foreach (gateFlatTokens($file) as $token) {
        $offset = (int)$token['offset'];
        if ($offset <= $mutation['endOffset'] || $offset >= $read['offset']) continue;
        if ($token['id'] !== T_VARIABLE || $token['text'] !== $mutation['receiver']) continue;
        if ($offset !== $read['receiverOffset']) return false;
    }
    return true;
}

/** Read-back той же сущности и, при наличии fields, тем же готовым verifier. */
function gateReadBackMatchesMutation(string $file, array $mutation, array $read, array $vars): bool {
    if (!gateIsReadBackFor($mutation['method'], $read['method'])) return false;
    if (!preg_match('~\.get$~i', $read['method'])) return false;
    if (!in_array($read['helper'], ['call', 'callChecked', 'readBackChecked'], true)) return false;
    if ($mutation['receiver'] === '' || !hash_equals($mutation['receiver'], $read['receiver'])
        || !gateReceiverStaysStable($file, $mutation, $read)) return false;

    $mutationParams = gateShortArrayMap($mutation['params'], $vars);
    $readParams = gateShortArrayMap($read['params'], $vars);
    if ($mutationParams === null || $readParams === null) return false;

    $primaryKeys = ['id', 'taskid', 'elementid', 'sectionid', 'entityid'];
    $contextKeys = ['entitytypeid', 'iblocktypeid', 'iblockid'];
    $verbParts = explode('.', strtolower($mutation['method']));
    $verb = (string)end($verbParts);
    $stable = [];

    foreach ($contextKeys as $key) {
        if (!array_key_exists($key, $mutationParams)) continue;
        if (!array_key_exists($key, $readParams)
            || !gateSameStableExpression($mutationParams[$key], $readParams[$key])) return false;
        $stable[] = $mutationParams[$key]; $stable[] = $readParams[$key];
    }

    if ($verb === 'add') {
        $readIds = [];
        foreach ($primaryKeys as $key) if (array_key_exists($key, $readParams)) $readIds[] = $readParams[$key];
        if (count($readIds) !== 1
            || !gateReadIdComesFromMutation($mutation, $readIds[0], gateVarMap($file), [
                'assignments' => gateVarAssignmentOffsets($file),
                'after'       => (int)$mutation['endOffset'],
                'before'      => (int)$read['offset'],
            ])) return false;
        $stable[] = $mutation['resultVar'];
    } else {
        $matchedId = false;
        foreach ($primaryKeys as $key) {
            if (!array_key_exists($key, $mutationParams)) continue;
            if (!array_key_exists($key, $readParams)
                || !gateSameStableExpression($mutationParams[$key], $readParams[$key])) return false;
            $matchedId = true;
            $stable[] = $mutationParams[$key]; $stable[] = $readParams[$key];
        }
        if (!$matchedId) return false;
    }

    if (array_key_exists('fields', $mutationParams)) {
        if ($read['helper'] !== 'readBackChecked' || trim($read['expected']) === '') return false;
        if (!gateSameStableExpression($mutationParams['fields'], $read['expected'])) return false;
        $stable[] = $mutationParams['fields']; $stable[] = $read['expected'];
    }
    return gateExpressionsStayStable($file, $stable, $mutation['endOffset'], $read['offset']);
}

/** Позиции всех вызовов auditLog() в файле — след действия человека или машины. */
function gateAuditOffsets(string $file): array {
    $tokens = gateFlatTokens($file);
    $out = [];
    foreach ($tokens as $i => $token) {
        if ($token['id'] !== T_STRING || strcasecmp($token['text'], 'auditLog') !== 0) continue;
        $prev = gatePrevToken($tokens, $i);
        if ($prev !== null && ($tokens[$prev]['id'] === T_FUNCTION
            || in_array($tokens[$prev]['text'], ['->', '::'], true))) continue;
        $out[] = (int)$token['offset'];
    }
    return $out;
}

/**
 * Все роли, которые файл называет требуемыми, — без оглядки на оформление.
 *
 * Раньше искалась ровно строка `'role' => 'что-то'` в одинарных кавычках. Любая
 * другая запись того же самого — двойные кавычки, перенос строки после стрелки,
 * лишние пробелы — проходила мимо, и пример `apiBoot(["role" => "user"])` в доке
 * оставался зелёным. Пример в доке опаснее ошибки в коде: его копируют дословно,
 * и эндпоинт закрывается ролью, которой у приложения нет.
 *
 * PHP разбираем ТОКЕНАМИ: там оформление вообще не имеет значения. Markdown —
 * регулярным поиском по обоим видам кавычек и с переносами, потому что токенов
 * у него нет.
 *
 * @return string[] Список названий ролей в порядке появления.
 */
function gateRequiredRoleNames(string $file): array {
    $out = [];
    $isPhp = strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) === 'php';
    if ($isPhp) {
        $tokens = gateFlatTokens($file);
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) continue;
            if (strtolower(trim($tokens[$i]['text'], "'\"")) !== 'role') continue;
            $arrow = gateNextToken($tokens, $i);
            if ($arrow === null || $tokens[$arrow]['id'] !== T_DOUBLE_ARROW) continue;
            $value = gateNextToken($tokens, $arrow);
            if ($value === null || $tokens[$value]['id'] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $out[] = trim($tokens[$value]['text'], "'\"");
        }
        return $out;
    }
    $src = (string)@file_get_contents($file);
    if (preg_match_all('~[\'"]role[\'"]\s*=>\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]~u', $src, $m)) {
        foreach ($m[1] as $role) $out[] = $role;
    }
    return $out;
}

/**
 * Куски каталога рецептов, внутри которых имя блока обязано стоять рядом с темой.
 *
 * Проверка шла ПОСТРОЧНО, поэтому обычный перенос строки разводил тему и имя
 * донора по разным строкам — и противоречие «есть готовое, а рецепт шлёт к
 * чужому коду» переставало находиться. Здесь текст режется на смысловые куски:
 * строка таблицы — это кусок, абзац — тоже кусок. Внутри куска перенос уже
 * ничего не прячет.
 *
 * @return string[] Куски текста как единые строки.
 */
function gateRecipeChunks(string $text): array {
    $chunks = [];
    $paragraph = [];
    foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            if ($paragraph !== []) { $chunks[] = implode(' ', $paragraph); $paragraph = []; }
            continue;
        }
        // Строка таблицы — самостоятельная запись: соседние строки таблицы к ней
        // отношения не имеют, склеивать их в один кусок нельзя.
        if ($trimmed[0] === '|') {
            if ($paragraph !== []) { $chunks[] = implode(' ', $paragraph); $paragraph = []; }
            $chunks[] = $trimmed;
            continue;
        }
        $paragraph[] = $trimmed;
    }
    if ($paragraph !== []) $chunks[] = implode(' ', $paragraph);
    return $chunks;
}

/**
 * Противоречия каталога рецептов: тема, у которой в шаблоне ЕСТЬ готовый блок,
 * а рецепт в том же куске шлёт к чужому коду и про готовое молчит.
 *
 * Слова тем подбираются узко. «Событие» встречается и в «очереди событий», и в
 * «приёме события» — по нему проверка ругалась бы на честные рецепты, а
 * предупреждение, которое горит зря, перестают читать.
 *
 * @param array<int,array{слова:string[],блок:string}> $temy
 * @return string[] Человеческие описания противоречий.
 */
function gateRecipeConflicts(string $text, array $temy): array {
    $out = [];
    foreach (gateRecipeChunks($text) as $chunk) {
        $low = mb_strtolower($chunk);
        // Донор называют и словом «донор», и просто путём вида «проект/www/…».
        if (mb_strpos($low, 'донор') === false && !preg_match('~[a-z0-9-]+/(www|tools)/~', $chunk)) continue;
        foreach ($temy as $t) {
            $hit = false;
            foreach ($t['слова'] as $w) if (mb_strpos($low, $w) !== false) $hit = true;
            if (!$hit) continue;
            if (strpos($chunk, $t['блок']) === false) {
                $out[] = $t['блок'] . ' ← «' . mb_substr(trim($chunk), 0, 60) . '…»';
            }
        }
    }
    return $out;
}

/**
 * Разрешён ли этот метод в «идемпотентном пакете» — по контракту ПОРТАЛА.
 *
 * Раньше пропуск давало объяснение словами: строка `$why` длиной 12+ знаков — и
 * `crm.deal.add` проходил гейт наравне с уведомлением. Свободный текст не может
 * быть доказательством: его пишет тот же, кто ошибается. Здесь список методов, у
 * каждого — условие из официальной документации и то, чем оно проверяется.
 *
 * `im.notify.system.add` / `im.notify.personal.add`: «Уникальный тег уведомления
 * в рамках приложения. При добавлении уведомления с существующим тегом другие
 * уведомления будут удалены» (api-reference/chats/notifications/). То есть повтор
 * безопасен ТОЛЬКО с непустым TAG — без него портал добавит второе сообщение.
 *
 * Незнакомый метод — отказ: список расширяется вручную и со ссылкой на документ.
 *
 * @return array{0:bool,1:string} [разрешено, причина отказа]
 */
function gateIdempotentBatchAllowed(string $method, string $params, string $scopeText): array {
    $policy = [
        'im.notify.system.add'   => 'TAG',
        'im.notify.personal.add' => 'TAG',
    ];
    $m = strtolower(trim($method));
    if ($m === '') return [false, 'имя метода не литерал'];
    if (!array_key_exists($m, $policy)) {
        return [false, 'метода нет в списке идемпотентных по контракту портала'];
    }
    $need = $policy[$m];
    if ($need === '') return [true, ''];
    // Параметры почти всегда собираются переменной выше по функции, поэтому
    // ищем обязательное поле и в самой команде, и в теле функции с вызовом.
    // Граница по функции обязательна: 'TAG' из соседнего метода не доказывает
    // ничего про этот вызов.
    // Правую часть достаём и смотрим глазами кода, а не отрицательным
    // просмотром вперёд: `\s*` перед ним откатывается на ноль пробелов, и
    // проверка «значение не пустое» тихо превращается в «всегда истина».
    // Ровно на этом первая версия пропустила `'TAG' => ''`.
    $needle = '~[\'"]' . preg_quote($need, '~') . '[\'"]\s*=>\s*([^,\]\)]*)~';
    $filled = static function (string $haystack) use ($needle): bool {
        if (!preg_match_all($needle, $haystack, $found)) return false;
        foreach ($found[1] as $value) {
            $value = trim($value);
            if ($value === '' || $value === "''" || $value === '""') continue;
            return true;
        }
        return false;
    };
    if (!$filled($params) && !$filled($scopeText)) {
        return [false, 'нет непустого параметра ' . $need . ', без которого портал добавит второе сообщение'];
    }
    return [true, ''];
}

/**
 * Мутация портала без защиты от повтора и без следа в журнале действий.
 *
 * Проверка положительная, а не запретительная: гейт до сих пор умел ловить
 * «написал своё», но никак не требовал взять готовое. Двойной клик, повтор
 * cron'а и ретрай портала — обычное дело, поэтому мутация обязана идти через
 * runOnce() (второй раз вернёт прежний результат), затем прочитать изменённую
 * сущность обратно готовым B24-клиентом и лишь после этого оставить достижимый
 * auditLog() в том же потоке выполнения. Один `result.ok` не доказывает, что
 * портал сохранил нужные поля; read-back делает проверку результата неизбежной.
 */
function gateUnprotectedMutations(string $file): array {
    $bodies = gateRunOnceBodies($file);
    $calls = gatePortalCallDetails($file);
    $vars = gateVarMap($file);
    $hits = [];
    foreach ($calls as $call) {
        $method = $call['method']; $line = $call['line'];
        if ($method === '') {
            $hits[] = 'dynamic-method:' . $line;
            continue;
        }
        if (!gateIsMutatingMethod($method)) continue;
        $contract = gateMutationContract($method);
        $directVerified = ((($contract === 'delete' && $call['helper'] === 'deleteVerified')
            || ($contract === 'action' && $call['helper'] === 'actionVerified'))
            && gateVerifiedMutationMethodAllowed($contract, $method));
        // batchAllPartialChecked — пакет, где часть команд может законно упасть
        // (завести десять полей, проставить признак пятистам сделкам). Он тоже
        // проходит: проверка перечитыванием у него не пожелание, а обязательный
        // аргумент, и неподтверждённые ключи он сам переводит в ошибки.
        // batchAllIdempotent — операции, которые нечем перечитать, но повтор
        // которых безопасен по контракту портала (уведомление с меткой портал
        // заменяет, а не дублирует). Идемпотентность там заявляется явно и с
        // объяснением, которое уходит в журнал, — молчаливого «наверное, можно»
        // помощник не принимает.
        $readBackMutation = $contract === 'read-back'
            && in_array($call['helper'], ['callChecked', 'batchAllChecked', 'batchAllPartialChecked', 'batchAllIdempotent'], true);
        // Идемпотентность заявляют не словами, а методом: `crm.deal.add` через
        // тот же помощник обязан краснеть. Проверяем ДО прочих послаблений.
        if ($call['helper'] === 'batchAllIdempotent') {
            $scope = gateFunctionScopeAt($file, (int)$call['offset']);
            $scopeText = $scope === null ? ''
                : substr((string)@file_get_contents($file), $scope['start'],
                         max(0, $scope['end'] - $scope['start']));
            [$allowedIdempotent, ] = gateIdempotentBatchAllowed(
                $method, (string)($call['params'] ?? ''), (string)$scopeText);
            if (!$allowedIdempotent) {
                $hits[] = $method . ':' . $line;
                continue;
            }
        }
        // CRUD требует строгий вызов + post-state; delete/action — только
        // готовый policy-helper с литеральным методом. Сырые batchAll partial.
        if ((!$directVerified && !$readBackMutation)
            || ($directVerified && empty($call['literalMethod']))) {
            $hits[] = $method . ':' . $line;
            continue;
        }
        $protected = false;
        // Помощники, которые САМИ отвечают за безопасность повтора, обёртки
        // runOnce не требуют: у одного перечитывание — обязательный аргумент, у
        // другого повтор безопасен по контракту портала и это заявлено словами.
        // Но след действия обязателен и здесь: без него через месяц никто не
        // ответит, откуда в портале взялось это изменение.
        if (in_array($call['helper'], ['batchAllPartialChecked', 'batchAllIdempotent'], true)) {
            foreach (gateAuditOffsets($file) as $auditOffset) {
                if ($auditOffset <= $call['offset']) continue;
                if (!gateSameFunctionScope($file, $call['offset'], $auditOffset)) continue;
                if (gateHasControlTransferBetween($file, $call['offset'], $auditOffset)) continue;
                if (!gateAuditIsStandaloneStatement($file, $auditOffset)) continue;
                if (!gateAuditPostDominates($file, $call['offset'], $auditOffset)) continue;
                $protected = true;
                break;
            }
            if ($protected) continue;
            $hits[] = $method . ':' . $line;
            continue;
        }
        foreach ($bodies as $body) {
            if ($call['offset'] <= $body['start'] || $call['offset'] >= $body['end']) continue;
            foreach ($body['audits'] as $auditOffset) {
                if ($auditOffset <= $call['offset']) continue;
                if (!gateSameFunctionScope($file, $call['offset'], $auditOffset)) continue;
                if (gateHasControlTransferBetween($file, $call['offset'], $auditOffset)) continue;
                if (!gateAuditIsStandaloneStatement($file, $auditOffset)) continue;
                if (!gateAuditPostDominates($file, $call['offset'], $auditOffset)) continue;
                if ($directVerified) {
                    $protected = true;
                    break 2;
                }
                foreach ($calls as $read) {
                    if ($read['offset'] <= $call['offset'] || $read['offset'] >= $auditOffset) continue;
                    if ($read['offset'] <= $body['start'] || $read['offset'] >= $body['end']) continue;
                    if (!gateReadBackMatchesMutation($file, $call, $read, $vars)) continue;
                    if (!gateSameFunctionScope($file, $call['offset'], $read['offset'])) continue;
                    if (gateHasControlTransferBetween($file, $call['offset'], $read['offset'])) continue;
                    if (!gateAuditPostDominates($file, $call['offset'], $read['offset'])) continue;
                    if (!gateAuditPostDominates($file, $read['offset'], $auditOffset)) continue;
                    $protected = true;
                    break 3;
                }
            }
        }
        if ($protected) continue;
        $hits[] = $method . ':' . $line;
    }
    return $hits;
}

/**
 * Списки портала без пагинации: сырой call/batch отдаёт первые 50 и молчит об
 * остальных. Готовое: fetchAll()/fetchAllBatched(); loadByIds() — для точных ID.
 */
/**
 * Список портала, у которого страниц не бывает вовсе.
 *
 * Требовать здесь fetchAll() значит требовать невозможного: курсора по ID у
 * такого ответа нет, и единственным выходом остаётся обход гейта. Реестр узкий
 * и поимённый — «похожий» метод в него не попадает, а расширение требует
 * сверки с официальной документацией и регрессии.
 *
 * bizproc.activity.list / bizproc.robot.list отдают коды действий и роботов
 * ОДНОГО приложения: их единицы, ответ приходит целиком, параметра start у
 * метода нет (api-reference/bizproc/bizproc-activity/bizproc-activity-list.md).
 */
function gateListMethodWithoutPages(string $method): bool {
    return in_array(strtolower($method), ['bizproc.activity.list', 'bizproc.robot.list'], true);
}

function gateUnpagedLists(string $file): array {
    $hits = [];
    foreach (gatePortalCallDetails($file) as $call) {
        if (!in_array($call['helper'], ['call', 'callChecked', 'readBackChecked', 'deleteVerified',
                                        'actionVerified', 'batch', 'batchAll', 'batchAllChecked'], true)) continue;
        $method = strtolower($call['method']);
        // У raw-helper неизвестный метод — тоже fail-closed: иначе достаточно
        // передать `$kind . '.list'` или аргумент функции, чтобы первая страница
        // снова выглядела полной. У fetchAll/fetchAllBatched/loadByIds до этой
        // точки не доходим: сам выбранный helper уже задаёт безопасную семантику.
        // Страничный ответ не выдаёт себя суффиксом: `.getlist` (семейство task.*),
        // `.getchildren` (disk.*) и просто `.get` у sonet_group и im.recent — всё
        // это страницы по 50 записей с параметром start. Прежний забор знал только
        // .list/.search и пять имён, поэтому такой вызов проходил молча, а
        // приложение выдавало первую страницу за весь список (K4-27, ревью
        // 09.08.2026). Сверено по /data/config/b24-rest-docs:
        // sonet-group-get.md — «Размер страницы результатов — 50 записей»,
        // disk-folder-get-children.md — «всегда статичный — 50 записей».
        $needsPaging = $method === ''
            || (bool)preg_match('~\.(list|search|getlist|getchildren)$~', $method)
            || in_array($method, ['user.get', 'department.get', 'lists.element.get',
                                  'lists.section.get', 'entity.item.get',
                                  'sonet_group.get', 'sonet_group.user.get',
                                  'im.recent.get'], true);
        if (!$needsPaging) continue;
        if (gateListMethodWithoutPages($method)) continue;
        // Наличие `start => 0` — всего одна страница. batchAll() режет только
        // число КОМАНД по 50 и тоже не обходит страницы списка; для этого есть
        // именно fetchAll()/fetchAllBatched().
        $hits[] = ($call['method'] !== '' ? $call['method'] : '<dynamic>') . ':' . $call['line'];
    }
    return $hits;
}

/** Raw `call(..., true)` у мутации: runOnce снаружи не делает transport retry безопасным. */
function gateUnsafeExplicitMutationRetry(string $file): array {
    $hits = [];
    $vars = gateVarMap($file);
    foreach (gatePortalCallDetails($file) as $call) {
        if ($call['helper'] !== 'call' || !gateIsMutatingMethod((string)$call['method'])) continue;
        $arg = trim((string)($call['expected'] ?? ''));
        if ($arg === '') continue;
        $resolved = strtolower(gateTrimExpressionParens(gateResolve($arg, $vars)));
        if ($resolved === 'true') $hits[] = (string)$call['method'] . ':' . (int)$call['line'];
    }
    return array_values(array_unique($hits));
}

/** Описан ли файл в карте — упоминание «такого нет» документацией не считается. */
function gateMapDescribes(string $mapText, string $fileName): bool {
    foreach (explode("\n", $mapText) as $line) {
        if (strpos($line, $fileName) === false) continue;
        if (preg_match('~\b(нет|не\s+использ|удал|устарел|отсутству|больше не)\b~iu', $line)) continue;
        return true;
    }
    return false;
}

/**
 * Путь файла относительно корня проекта — для БЕЛЫХ списков по точному месту.
 *
 * Сравнивать по basename нельзя: тогда `www/api/report/http.php` получает все
 * исключения штатного `www/api/http.php`. Путь снаружи корня возвращается как
 * абсолютный и потому заведомо не совпадёт ни с одним разрешённым относительным.
 */
function gateRelativePath(string $root, string $file): string {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $file = str_replace('\\', '/', $file);
    $prefix = $root . '/';
    return strncmp($file, $prefix, strlen($prefix)) === 0
        ? substr($file, strlen($prefix))
        : $file;
}

/** Входит ли файл именно в разрешённый project-relative путь, а не совпал именем. */
function gateIsExactPath(string $root, string $file, array $allowed): bool {
    return in_array(gateRelativePath($root, $file), $allowed, true);
}

/** Неожиданные PHP-файлы прямо в www/: они обходят контракт endpoint'ов www/api. */
function gateUnexpectedRootPhp(string $root, array $allowed): array {
    $files = glob(rtrim($root, '/\\') . '/www/*.php') ?: [];
    $bad = array_values(array_filter(
        $files,
        static fn(string $file): bool => !gateIsExactPath($root, $file, $allowed)
    ));
    sort($bad);
    return $bad;
}

/**
 * PHP вне разрешённой структуры webroot.
 *
 * Инвентаризация обязана начинаться с целого www/, иначе новый `www/lib/x.php`
 * не является ни API, ни bin, ни корневым endpoint и не попадает вообще ни в
 * одну проверку. Неизвестное место трактуем fail-closed.
 */
function gateUnexpectedPhpLocations(
    string $root,
    array $files,
    array $allowedExact,
    array $allowedPrefixes
): array {
    $bad = [];
    foreach ($files as $file) {
        $relative = gateRelativePath($root, $file);
        if (in_array($relative, $allowedExact, true)) continue;
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (strncmp($relative, $prefix, strlen($prefix)) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) $bad[] = $file;
    }
    sort($bad);
    return $bad;
}

/**
 * CLI-точка закрывает HTTP ДО любого исполняемого действия.
 *
 * Одного расположения в `www/bin/` недостаточно: этот каталог физически лежит
 * под DocumentRoot. Поэтому разрешён только один строгий пролог. Он намеренно
 * не допускает require, define, настройки ошибок, вывод или более сложное
 * условие перед 404 — все они либо уже выполняются из HTTP, либо дают простой
 * способ превратить штатный bin-файл в публичный endpoint.
 */
function gateHasEarlyCli404Guard(string $file): bool {
    if (gateInlineHtmlLines($file) !== []) return false;

    foreach (gateTopStatements($file) as $st) {
        $compact = preg_replace('~\\s+~', '', (string)$st['code']) ?? '';
        if ($compact === 'declare(strict_types=1);') continue;

        return $st['kind'] === 'control'
            && $compact === "if(PHP_SAPI!=='cli'){http_response_code(404);exit;}";
    }
    return false;
}

/**
 * Все файлы проекта с нужным расширением — РЕКУРСИВНО.
 *
 * Прежняя инвентаризация шла по `glob('www/api/*.php')`, и достаточно было
 * положить код на уровень глубже (`www/api/report/data.php`), чтобы гейт его
 * вообще не увидел. Зависимости держим в корневом vendor/ вне webroot:
 * `www/vendor/` намеренно НЕ исключается, иначе это готовая невидимая папка
 * для first-party endpoint с любым именем.
 */
function gateFilesUnder(string $dir, string $ext): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) !== $ext) continue;
        $path = $f->getPathname();
        $out[] = $path;
    }
    sort($out);
    return $out;
}

/**
 * PHP-исходники нельзя инвентаризировать только по `.php`.
 *
 * `.phtml` часто назначен тому же обработчику веб-сервера, а `.inc`/`.phar`
 * можно подключить из обычного PHP. Любое из этих расширений иначе становилось
 * слепой зоной и для синтаксиса, и для обязательных готовых модулей.
 */
function gatePhpSourceFilesUnder(string $dir): array {
    $files = [];
    foreach (['php', 'pht', 'phtm', 'phtml', 'php3', 'php4', 'php5', 'php6',
              'php7', 'php8', 'phar', 'phps', 'inc'] as $ext) {
        $files = array_merge($files, gateFilesUnder($dir, $ext));
    }
    $files = array_values(array_unique($files));
    sort($files);
    return $files;
}

/** Webroot не может сам переназначить обработчик и создать новый класс исполняемых файлов. */
function gateForbiddenWebConfigFiles(string $dir): array {
    if (!is_dir($dir)) return [];
    $forbidden = ['.htaccess', '.user.ini', 'php.ini', 'web.config'];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || !in_array(strtolower($f->getFilename()), $forbidden, true)) continue;
        $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

/**
 * Резервная копия PHP под другим последним расширением часто отдаётся как
 * обычный текст, а на Apache multi-extension может и исполниться. В обоих
 * случаях `env.php.bak`/`endpoint.php.jpg` не должен попасть в release.
 */
function gateForbiddenPhpBackupFiles(string $dir): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $phpLike = '(?:php(?:[0-9]+|s)?|pht|phtm|phtml|phar|inc)';
    $phpLikeFinal = ['php', 'pht', 'phtm', 'phtml', 'php3', 'php4', 'php5',
                     'php6', 'php7', 'php8', 'phar', 'phps', 'inc'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) continue;
        $name = strtolower($f->getFilename());
        $ext = strtolower($f->getExtension());
        // Канонический и отдельный PHP-like source проверяет соседний контур;
        // здесь ищем именно маскирующий хвост после него (`.php.bak/.php.jpg`).
        if (in_array($ext, $phpLikeFinal, true)) continue;
        if (preg_match('~\\.' . $phpLike . '(?:[^a-z0-9]|$)~', $name) !== 1) continue;
        $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

/**
 * ═══ КАТАЛОГ ГОТОВЫХ БЛОКОВ (docs/BLOCKS.md) НЕ ДОЛЖЕН ВРАТЬ ═══
 *
 * Правило шаблона: «прежде чем писать своё — открой каталог; нашёл — бери».
 * Оно работает ровно до первого имени, которого в коде нет: разработчик грепает
 * такое имя, не находит и делает вывод «блока нет, пишу своё» — то есть каталог
 * начинает ВРЕДИТЬ вместо того, чтобы экономить. Ревью 06.08.2026 нашло ровно
 * это: `moneyForHuman()` и `exportCsv()` не существовали никогда, при 807
 * зелёных проверках. Человеком такое ловится только чтением всего каталога, а
 * машиной — за миллисекунду, поэтому здесь.
 */

/**
 * Имена функций/методов, названные в каталоге: и во фрагментах в обратных
 * кавычках, и внутри блоков кода в тройных ограждениях.
 */
/**
 * Разбор markdown на куски: содержимое блоков кода отдельно, фрагменты в
 * одиночных обратных кавычках отдельно.
 *
 * Общая для ВСЕХ проверок каталога. Ограждение — три обратные кавычки ИЛИ три
 * тильды подряд:
 * разбор «текст между кавычками» съедает одну как закрывающую, ДВЕ остаются
 * непарными, и весь остаток файла читается со сдвигом — «фрагментами»
 * становятся куски прозы МЕЖДУ обещаниями каталога, а сами обещания не
 * проверяются вовсе. Улика 09.08.2026: выдуманное имя в хвосте docs/BLOCKS.md
 * (после ограждений на строках 363–389) не попало ни в один из 269 разобранных
 * фрагментов. Держим в одном месте намеренно: та же дыра сидела сразу в двух
 * проверках, и починка одной оставляла вторую слепой.
 */
function gateMarkdownKuski(string $markdown): array {
    $ograzhdeniya = [];
    // Ограждение бывает ДВУХ штатных видов: три обратные кавычки и три тильды.
    // Тильда-ограждение разбор не знал вовсе, и обещание внутри него не попадало
    // ни в один кусок — каталог мог обещать несуществующий блок, а проверка
    // молчала (K4-39, ревью 09.08.2026). Закрывается ограждение ТЕМ ЖЕ символом
    // (обратная ссылка \1): иначе тильда внутри ```-блока закрыла бы чужое
    // ограждение, и разбор снова поехал бы со сдвигом.
    $tekst = (string)preg_replace_callback(
        '/^[ \t]*(`{3,}|~{3,})[^\n]*\n(.*?)(?:^[ \t]*\1[ \t]*$|\z)/ms',
        static function (array $m) use (&$ograzhdeniya): string {
            // Незакрытое ограждение съедает текст до конца файла. Проверку это
            // не ослабляет: съеденное лежит отдельным куском и разбирается.
            $ograzhdeniya[] = $m[2];
            return '';
        },
        $markdown);
    // Двойные ограничители (``имя``) — законный markdown: так пишут фрагмент,
    // внутри которого сама обратная кавычка. Для разбора это та же беда, что и
    // тройные: одну кавычку съедает пара, вторая остаётся непарной, и ВСЁ, что
    // идёт следом, читается со сдвигом. Проверено 09.08.2026 на каталоге из
    // двух строк: имя после двойных кавычек проверка не видела вовсе. Поэтому
    // вынимаем их ДО одиночных — содержимое кладём куском, как у ограждений.
    $spany = [];
    $tekst = (string)preg_replace_callback(
        '/`{2,}([^\n]+?)`{2,}/',
        static function (array $m) use (&$spany): string {
            $spany[] = $m[1];
            return ' ';
        },
        $tekst);
    if (preg_match_all('/`([^`]+)`/', $tekst, $m)) $spany = array_merge($spany, $m[1]);
    return ['tekst' => $tekst, 'ograzhdeniya' => $ograzhdeniya, 'spany' => $spany];
}

function gateCatalogSymbols(string $markdown): array {
    // Ключевые слова языка и служебные конструкции — не блоки шаблона.
    static $skip = [
        'function', 'if', 'for', 'foreach', 'while', 'return', 'echo', 'array',
        'static', 'fn', 'new', 'catch', 'switch', 'isset', 'empty', 'list',
        'print', 'require', 'include', 'use', 'var', 'let', 'const', 'typeof',
    ];
    // Блок кода в тройном ограждении вынимаем ДО разбора фрагментов и разбираем
    // отдельно. Ограждение — три обратные кавычки подряд: разбор «текст между
    // кавычками» съедает одну как закрывающую, ДВЕ остаются непарными, и весь
    // остаток файла читается со сдвигом — «фрагментами» становятся куски прозы
    // МЕЖДУ обещаниями каталога, а сами обещания не проверяются вовсе. Улика
    // 09.08.2026: выдуманное имя, подсаженное в хвост docs/BLOCKS.md (после
    // ограждений на строках 363–389), не попало ни в один из 269 разобранных
    // фрагментов — хвост каталога мог обещать что угодно, гейт молчал.
    // Содержимое ограждения при этом НЕ выбрасываем: там уже готовый код или
    // команда, кавычки внутри ничего не размечают, поэтому имена берём из него
    // напрямую, целым куском. Молча вырезать нельзя — обещание, спрятанное в
    // примере, перестало бы проверяться, и одна лазейка сменилась бы другой
    // (ровно так вышло с прежним запретом «имя после слэша»).
    $razobrano = gateMarkdownKuski($markdown);
    $pieces = array_merge($razobrano['ograzhdeniya'], $razobrano['spany']);
    $out = [];
    foreach ($pieces as $span) {
        // Ссылка на файл — не вызов. В `js/app.js (кнопка «Обновить»)` иначе
        // берётся расширение «js», и от кода требуется функция с таким именем:
        // живой прогон агента 09.08.2026 дал красный гейт на его собственном
        // описании блока. Вырезаем ПУТЬ целиком — это и есть то, что мешает.
        // Прежний запрет «слэш слева от имени» решал другую задачу: расширения
        // он не касался вовсе (слева от него точка), зато прятал от проверки
        // любое имя после `/`, то есть каталог мог безнаказанно обещать
        // `helpers/чегоНетВКоде(...)` — вместо одной лазейки получалась вторая.
        $span = (string)preg_replace(
            '~[A-Za-z0-9_./-]*\.(?:php|js|mjs|css|html?|md|json|csv|txt|sh|ya?ml|sql|log|xml|png|svg)\b~i',
            ' ', $span);
        // Имя, за которым стоит скобка, — вызов функции. Пробел, таб и перенос
        // строки между ними ничего не отменяют: `moneyForHuman (копейки)` —
        // то же обещание каталога, что и без пробела. Пока проверка их
        // различала, у правила «пользуйся готовым» был готовый обход: имя с
        // отступом от скобки в коде искать переставали.
        if (!preg_match_all('/(?<!\w)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $span, $calls)) continue;
        foreach ($calls[1] as $name) {
            if (in_array(strtolower($name), $skip, true)) continue;
            $out[$name] = true;
        }
    }
    return array_keys($out);
}

/**
 * CSS-классы, названные в каталоге.
 *
 * Смотрим только раздел «Интерфейс»: классы живут там, а в остальных разделах
 * точка почти всегда часть имени файла (`api/store.php`, `отчёт.csv`) или
 * метода портала (`crm.item.list`) — и такие «классы» требовать от css нельзя.
 */
function gateCatalogClasses(string $markdown): array {
    $from = strpos($markdown, '## Интерфейс');
    if ($from === false) return [];
    $to = strpos($markdown, "\n---", $from);
    $markdown = substr($markdown, $from, $to === false ? null : $to - $from);

    $out = [];
    // Ограждённые блоки кода вынимаем тем же общим разбором, что и для имён:
    // без этого хвост раздела «Интерфейс» (ограждения внутри него) читался со
    // сдвигом, и каталог мог обещать несуществующий класс — гейт молчал.
    // Содержимое ограждений здесь НЕ разбираем: там команды оболочки и PHP,
    // селекторов оформления в них не бывает, а «.php» из пути превратился бы
    // в требование завести класс с таким именем.
    $spany = gateMarkdownKuski($markdown)['spany'];
    if ($spany === []) return [];
    foreach ($spany as $span) {
        // `.row-item.is-ok\|is-warn` — экранированная черта разделяет варианты
        // одного и того же класса-состояния, а точка — составной селектор.
        $span = str_replace(['\\|', '|'], ' ', $span);
        // Точка внутри слова — это НЕ класс: `api/bind.php`, `crm.item.list`,
        // `ui.select` иначе превращались в «классы» php, item, select, и
        // проверка требовала бы их от css. Класс начинается с точки, перед
        // которой нет буквы, цифры и косой черты; составной селектор
        // (`.btn.is-active`) разбираем целиком.
        // Требуем разделитель ПЕРЕД точкой, а не запрещаем отдельные символы:
        // «отчёт.csv» кириллицу в запрете не проходил, и расширение файла
        // превращалось в требование «заведите класс .csv».
        if (!preg_match_all('/(?:^|[\s(+,\'"])(\.[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)*)/', $span, $selectors)) continue;
        foreach ($selectors[1] as $selector) {
            foreach (explode('.', ltrim($selector, '.')) as $class) {
                if ($class !== '') $out[$class] = true;
            }
        }
    }
    return array_keys($out);
}

/** Объявлено ли имя в коде приложения: PHP-функция, метод класса или контрол фронта. */
function gateSymbolDeclaredIn(string $name, array $sources): bool {
    $quoted = preg_quote($name, '~');
    foreach ($sources as $file) {
        $code = (string)@file_get_contents($file);
        if ($code === '') continue;
        // PHP: обычная функция и метод класса. JS: `ui.name = function`,
        // `name: function` и обычное объявление.
        if (preg_match('~function\s+' . $quoted . '\s*\(~', $code)) return true;
        if (preg_match('~(?:^|[^A-Za-z0-9_])' . $quoted . '\s*[:=]\s*function~m', $code)) return true;
        // Точка расширения: функцию объявляет ПРОЕКТ в www/api/project-hooks.php,
        // а ядро зовёт её по имени через function_exists() (ревью 23.09.2026,
        // modulnost-2). Такое имя в каталоге — контракт ядра, а не выдумка.
        if (preg_match('~function_exists\(\s*[\'"]' . $quoted . '[\'"]\s*\)~', $code)) return true;
    }
    return false;
}

/** Имена каталога, которых в коде нет. Пусто — каталог не врёт. */
function gateCatalogMissingSymbols(string $markdown, array $sources): array {
    $missing = [];
    foreach (gateCatalogSymbols($markdown) as $name) {
        if (!gateSymbolDeclaredIn($name, $sources)) $missing[] = $name;
    }
    sort($missing);
    return $missing;
}

/** Классы каталога, которых нет ни в одном файле оформления. */
function gateCatalogMissingClasses(string $markdown, array $cssFiles): array {
    $css = '';
    foreach ($cssFiles as $file) $css .= (string)@file_get_contents($file);
    $missing = [];
    foreach (gateCatalogClasses($markdown) as $class) {
        if (strpos($css, '.' . $class) === false) $missing[] = $class;
    }
    sort($missing);
    return $missing;
}

/**
 * Обратная сторона того же правила: готовый блок, которого НЕТ в каталоге,
 * всё равно что не существует — его напишут заново. Модуль считается
 * представленным, если в каталоге назван либо его файл, либо хоть одна его
 * публичная функция.
 */
function gateModulesMissingFromCatalog(string $markdown, array $moduleFiles): array {
    $missing = [];
    foreach ($moduleFiles as $file) {
        if (strpos($markdown, basename($file)) !== false) continue;
        $code = (string)@file_get_contents($file);
        $named = false;
        if (preg_match_all('~^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(~m', $code, $fns)) {
            foreach ($fns[1] as $fn) {
                if (preg_match('~(?:^|[^A-Za-z0-9_])' . preg_quote($fn, '~') . '(?:[^A-Za-z0-9_]|$)~', $markdown)) {
                    $named = true;
                    break;
                }
            }
        }
        if (!$named) $missing[] = basename($file);
    }
    sort($missing);
    return $missing;
}

/**
 * ═══ НОВЫЙ ФАЙЛ СОСТОЯНИЯ ОБЯЗАН БЫТЬ ПОДКЛЮЧЁН К ЖИЗНЕННОМУ ЦИКЛУ ═══
 *
 * Класс ошибки, найденный ревью 06.08.2026 и стоивший критической находки.
 * Автор нового блока объявляет свой файл состояния в env.php, пишет чтение и
 * запись — и на этом останавливается, потому что НИЧТО не напоминает про третий
 * шаг: что с этим файлом делать при удалении приложения и при переносе на
 * другой портал. Списки уборки лежат в другом файле, тремя отдельными ручными
 * перечнями, и связи с объявлением у них нет никакой. Так долгая задача пережила
 * удаление приложения: её курсор достался следующей установке.
 *
 * Проверка требует одного: каждый объявленный файл состояния либо назван в
 * уборке, либо явно назван «обслуживается своим протоколом». Оба ответа
 * осознанные, забыть третий шаг молча — уже нельзя.
 *
 * @param string $envSource   исходник env.php
 * @param string $libSource   исходник api/lib.php (там живут списки уборки)
 * @param string[] $ownProtocol константы, у которых своя процедура (с причиной в тесте)
 * @return string[] имена констант, о судьбе которых никто не позаботился
 */
function gateStateFilesWithoutCleanup(string $envSource, string $libSource, array $ownProtocol): array {
    // Ищем объявления ВЕЗДЕ, а не только в env.php. Первая версия этой проверки
    // смотрела один файл — и пропустила справочник CRM, который объявлял себя
    // сам внутри модуля. Он не попал ни в один список уборки, и названия воронок
    // прежнего клиента доставались следующей установке. Ограничитель, который
    // видит не все объявления, опаснее отсутствующего: он создаёт уверенность.
    if (!preg_match_all("~define\(\s*'([A-Z][A-Z0-9_]*_FILE)'~", $envSource, $declared)) return [];
    // Файлы ПРОЕКТА в ядре не перечисляются и не должны: для них есть штатный
    // список APP_PURGE_EXTRA в env.php, и appPurgeExtraFiles() подмешивает его
    // И в уборку после удаления (appPurgeInstallStateLocked), И в план переноса
    // на другой портал (appPortalBoundResetTargets). Пока проверка искала имя
    // константы только в ядре, единственным способом пройти её было правкой
    // ядра — то есть отказом от механизма, заведённого ровно для этого случая.
    // Объявлений ищем ВСЕ: в шапке env.php стоит закомментированный пример, и
    // первое совпадение — это он. Проверка, читавшая только его, молча считала
    // список пустым и краснела на файле, который в нём как раз назван.
    $inExtra = [];
    if (preg_match_all("~define\(\s*'APP_PURGE_EXTRA'\s*,(.*?)\);~s", $envSource, $extraBlocks)) {
        foreach ($extraBlocks[1] as $extraBlock) {
            if (!preg_match_all('~\b([A-Z][A-Z0-9_]*_FILE)\b~', $extraBlock, $extraNames)) continue;
            foreach ($extraNames[1] as $extraName) $inExtra[$extraName] = true;
        }
    }
    $orphans = [];
    foreach ($declared[1] as $constant) {
        if (in_array($constant, $ownProtocol, true)) continue;
        if (isset($inExtra[$constant])) continue;
        // Уборка при удалении приложения: список $files в appPurgeInstallStateLocked().
        $inPurge = preg_match('~\$files\s*=\s*\[[^\]]*\b' . preg_quote($constant, '~') . '\b~s', $libSource) === 1;
        // Перенос на другой портал: план appPortalBoundResetTargets().
        $inReset = preg_match('~\$targets\s*=\s*\[[^;]*\b' . preg_quote($constant, '~') . '\b~s', $libSource) === 1;
        if (!$inPurge || !$inReset) $orphans[] = $constant;
    }
    sort($orphans);
    return $orphans;
}

/**
 * Разбор формата портального POST — в ОДНОМ файле.
 *
 * Второй класс ошибки того же ревью: инвариант починили в одном месте из двух.
 * Забор сессии научили коробочному SERVER_ENDPOINT, а установка продолжала
 * искать домен в DOMAIN и Referer — приложение ставилось с одним именем портала,
 * а проверялось по другому. Пока поля читают из разных файлов, такое расхождение
 * будет повторяться при каждом изменении формата.
 *
 * @param string[] $files       файлы приложения
 * @param string   $ownerSuffix файл, которому разбор разрешён
 * @return string[] «файл → поле» для каждого чтения на стороне
 */
function gatePortalPostFieldReaders(array $files, string $ownerSuffix): array {
    $fields = ['SERVER_ENDPOINT', 'APP_SID', 'DOMAIN'];
    $out = [];
    foreach ($files as $file) {
        if (substr($file, -strlen($ownerSuffix)) === $ownerSuffix) continue;
        $code = (string)@file_get_contents($file);
        if ($code === '') continue;
        // Считаем только настоящее чтение поля из массива запроса, не упоминание
        // в комментарии и не ключ, который сами же и кладём в форму.
        foreach ($fields as $field) {
            if (preg_match('~\[\s*[\'"]' . $field . '[\'"]\s*\]\s*\?\?~', $code)
                || preg_match('~\$get\(\s*[\'"]' . $field . '[\'"]\s*\)~', $code)) {
                $out[] = basename($file) . ' → ' . $field;
            }
        }
    }
    sort($out);
    return $out;
}

/**
 * ЧТО В ФАЙЛЕ ЕСТЬ ТАКОГО, ЧЕГО СТАРЫЙ ДВИЖОК НЕ ПОЙМЁТ.
 *
 * Зачем. Файл совместимости кладётся в ЧУЖОЙ проект на PHP 7.4 — ради него он и
 * существует. Одна функция из более новой версии превращает помощь в фатал
 * «Call to undefined function» на первой же странице выборки, и падает при этом
 * не выборка, а всё приложение целиком. Прочитать глазами это невозможно: на
 * здешнем движке 8.x код работает и выглядит правильным.
 *
 * Смотрим на то, что ломается именно ФАТАЛОМ — функция или синтаксис, которых на
 * 7.4 нет, — а не на стилевые новшества. Разбираем токенами, а не поиском по
 * тексту: слово «match» в комментарии и `$this->str_contains` нарушением не
 * являются, а регулярка спорит с обоими.
 *
 * @param string $code исходник файла
 * @return string[] названия найденных конструкций
 */
/**
 * Синтаксис новее 7.4, который поштучным обходом токенов не увидеть.
 *
 * Нужен контекст: `|` между типами — это union только в сигнатуре, а слово
 * `mixed` — тип только после двоеточия или в списке параметров. Поэтому здесь
 * отдельный проход с двумя счётчиками: где мы находимся и на какой глубине.
 *
 * @param array $tokens результат token_get_all
 * @param array<string,string> $novyeTipy тип => версия, с которой он существует
 * @return string[]
 */
function gatePhp74SyntaxHits(array $tokens, array $novyeTipy): array {
    $found = [];
    $znachimye = [];
    foreach ($tokens as $t) {
        if (is_array($t) && in_array(token_name($t[0]), ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true)) continue;
        $znachimye[] = $t;
    }
    $n = count($znachimye);
    $vSignature = false;   // внутри списка параметров или типа возврата
    $glubina = 0;
    $kvadratnye = 0;       // глубина [ ] внутри сигнатуры: значения по умолчанию
    $strelka = false;      // сигнатуру открыла fn — закроется на `=>`, а не на `{`
    for ($i = 0; $i < $n; $i++) {
        $t = $znachimye[$i];
        $imya = is_array($t) ? token_name($t[0]) : '';
        $tekst = is_array($t) ? $t[1] : $t;

        if ($imya === 'T_FUNCTION' || $imya === 'T_FN') {
            $vSignature = true;
            $glubina = 0;
            $kvadratnye = 0;
            // У стрелочной функции ТЕЛА В СКОБКАХ НЕТ: сигнатура заканчивается
            // на `=>`. Пока это не учитывалось, флаг «мы внутри сигнатуры» жил до
            // конца всего оператора, и первая же битовая маска в хвосте
            // (`JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`) читалась как
            // union-тип: забор краснел на законном коде, а починить его можно
            // было только переписав рабочую строку (находка ревью 09.08.2026).
            $strelka = ($imya === 'T_FN');
        }
        if ($vSignature && $tekst === '(') $glubina++;
        if ($vSignature && $tekst === ')') $glubina = max(0, $glubina - 1);
        // Значения по умолчанию бывают массивами: `fn(array $a = ['k' => 1]) => …`.
        // Стрелка ВНУТРИ такого массива сигнатуру не закрывает.
        if ($vSignature && $tekst === '[') $kvadratnye++;
        if ($vSignature && $tekst === ']') $kvadratnye = max(0, $kvadratnye - 1);
        if ($vSignature && $strelka && $imya === 'T_DOUBLE_ARROW'
            && $glubina === 0 && $kvadratnye === 0) {
            $vSignature = false;
            $strelka = false;
        }
        if ($vSignature && ($tekst === '{' || $tekst === ';')) { $vSignature = false; $strelka = false; }

        if ($vSignature) {
            // Union-тип: T_STRING|T_ARRAY '|' T_STRING. В 7.4 такого нет вовсе.
            if ($tekst === '|' && $i > 0 && $i + 1 < $n) {
                $do = $znachimye[$i - 1];
                $posle = $znachimye[$i + 1];
                $tipDo = is_array($do) && in_array(token_name($do[0]), ['T_STRING', 'T_ARRAY', 'T_CALLABLE'], true);
                $tipPosle = is_array($posle) && in_array(token_name($posle[0]), ['T_STRING', 'T_ARRAY', 'T_CALLABLE'], true);
                if ($tipDo && $tipPosle) $found[] = 'union-тип (int|string) — с PHP 8.0';
            }
            // Новые типы: mixed, never, static как тип возврата.
            if ($imya === 'T_STRING' && isset($novyeTipy[strtolower((string)$tekst)])) {
                $sled = $znachimye[$i + 1] ?? null;
                $predyd = $znachimye[$i - 1] ?? null;
                $poslePodpisi = is_string($predyd) && ($predyd === ':' || $predyd === '(' || $predyd === ',' || $predyd === '?');
                $peredPeremennoy = is_array($sled) && token_name($sled[0]) === 'T_VARIABLE';
                if ($poslePodpisi || $peredPeremennoy) {
                    $found[] = strtolower((string)$tekst) . ' как тип — с PHP ' . $novyeTipy[strtolower((string)$tekst)];
                }
            }
            if ($imya === 'T_STATIC' && is_string($znachimye[$i - 1] ?? null) && $znachimye[$i - 1] === ':') {
                $found[] = 'static как тип возврата — с PHP 8.0';
            }
            // Продвижение свойств в конструкторе: модификатор внутри параметров.
            if ($glubina > 0 && in_array($imya, ['T_PUBLIC', 'T_PRIVATE', 'T_PROTECTED', 'T_READONLY'], true)) {
                $found[] = 'продвижение свойств в конструкторе — с PHP 8.0';
            }
        }

        // Именованный аргумент: `f(имя: значение)`. Отличаем от `::` и от метки.
        if ($imya === 'T_STRING' && $i + 1 < $n && $znachimye[$i + 1] === ':'
            && !(is_string($znachimye[$i + 2] ?? null) && $znachimye[$i + 2] === ':')) {
            $predyd = $znachimye[$i - 1] ?? null;
            if (is_string($predyd) && ($predyd === '(' || $predyd === ',')) {
                $found[] = 'именованный аргумент (f(имя: 1)) — с PHP 8.0';
            }
        }

        // throw как ВЫРАЖЕНИЕ: `$x = throw ...`, `?? throw ...`, `?: throw ...`.
        if ($imya === 'T_THROW' && $i > 0) {
            $predyd = $znachimye[$i - 1];
            $predText = is_array($predyd) ? token_name($predyd[0]) : (string)$predyd;
            $vyrazhenie = in_array($predText, ['=', 'T_COALESCE', '?', 'T_DOUBLE_ARROW'], true);
            if ($predText === ':') {
                // Двоеточие бывает и меткой switch: `default: throw ...` и
                // `case 5: throw ...` — законный код 7.4. Поймано первым же
                // прогоном по боевому коду (uf.php:170), то есть проверка чуть
                // не начала краснеть на исправном файле.
                $metka = false;
                $do = $znachimye[$i - 2] ?? null;
                if (is_array($do) && token_name($do[0]) === 'T_DEFAULT') $metka = true;
                for ($k = $i - 2; $k >= 0 && $k > $i - 12 && !$metka; $k--) {
                    $tk = $znachimye[$k];
                    if (is_string($tk) && ($tk === ';' || $tk === '{' || $tk === '}')) break;
                    if (is_array($tk) && token_name($tk[0]) === 'T_CASE') $metka = true;
                }
                $vyrazhenie = !$metka;
            }
            if ($vyrazhenie) $found[] = 'throw как выражение — с PHP 8.0';
        }

        // catch без переменной: `catch (Exception) {`.
        if ($imya === 'T_CATCH') {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_string($znachimye[$j]) && $znachimye[$j] === ')') {
                    $predyd = $znachimye[$j - 1] ?? null;
                    if (is_array($predyd) && token_name($predyd[0]) === 'T_STRING') {
                        $found[] = 'catch без переменной — с PHP 8.0';
                    }
                    break;
                }
                if (is_array($znachimye[$j]) && token_name($znachimye[$j][0]) === 'T_VARIABLE') break;
            }
        }
    }
    return array_values(array_unique($found));
}

function gateNewerThanPhp74(string $code): array {
    $newFunctions = [
        'array_is_list' => '8.1', 'str_contains' => '8.0', 'str_starts_with' => '8.0',
        'str_ends_with' => '8.0', 'enum_exists' => '8.1', 'array_find' => '8.4',
        'array_any' => '8.4', 'array_all' => '8.4', 'json_validate' => '8.3',
    ];
    $newTokens = [
        'T_NULLSAFE_OBJECT_OPERATOR' => '?-> — с PHP 8.0',
        'T_MATCH'                    => 'match() — с PHP 8.0',
        'T_ENUM'                     => 'enum — с PHP 8.1',
        'T_READONLY'                 => 'readonly — с PHP 8.1',
        'T_ATTRIBUTE'                => '#[Атрибут] — с PHP 8.0',
    ];

    // СИНТАКСИС, а не только функции. Прежде проверялись имена функций и пять
    // токенов, поэтому union-тип, `mixed`, именованный аргумент и продвижение
    // свойств в конструкторе проходили молча (находка ревью 08.08.2026,
    // BXREV-017): на здешнем движке 8.x такой файл работает, а в чужом проекте
    // на 7.4 он падает при первом же подключении — целиком, а не частью.
    $novyeTipy = ['mixed' => '8.0', 'never' => '8.1', 'static' => '8.0'];

    $tokens = @token_get_all($code);
    if (!is_array($tokens)) return [];
    $found = [];
    $prevSignificant = null;
    // Разбор сигнатур и вызовов идёт отдельным проходом ниже: там нужен контекст
    // (мы внутри списка параметров? внутри вызова?), которого в поштучном обходе нет.
    foreach (gatePhp74SyntaxHits($tokens, $novyeTipy) as $hit) $found[] = $hit;
    foreach ($tokens as $i => $token) {
        if (is_array($token)) {
            $name = token_name($token[0]);
            if (isset($newTokens[$name])) $found[] = $newTokens[$name];
            if ($token[0] === T_STRING && isset($newFunctions[strtolower($token[1])])) {
                // Вызов, а не имя своей функции и не метод объекта.
                $isMethod = is_array($prevSignificant)
                    && in_array(token_name($prevSignificant[0]),
                        ['T_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_NULLSAFE_OBJECT_OPERATOR'], true);
                $next = null;
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && in_array(token_name($t[0]), ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true)) continue;
                    $next = $t; break;
                }
                if (!$isMethod && $next === '(') {
                    $fn = strtolower($token[1]);
                    $found[] = $fn . '() — с PHP ' . $newFunctions[$fn];
                }
            }
            if (in_array(token_name($token[0]), ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true)) continue;
        }
        $prevSignificant = $token;
    }
    return array_values(array_unique($found));
}
