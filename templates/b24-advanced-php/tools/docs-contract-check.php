<?php
declare(strict_types=1);
/**
 * Договор документации: актуальные доки не должны звать в архитектуру, которую
 * код и гейт запрещают.
 *
 * ЗАЧЕМ. Находка ревью R3-018 (09.08.2026): PORTING.md требовал брать личность
 * смотрящего фронтовым `BX24.callMethod` и заводить серверный `proxy.php`, тогда
 * как в шаблоне личность резолвится на сервере (`AUTH_ID` →
 * `resolveUserContextFromAuth()`), посредника нет вовсе, а гейт
 * `tests/uses_template_test.php` прямо запрещает фронту ходить в портал мимо
 * `ui.api()`. Три источника предлагали три взаимоисключающих решения, и агент,
 * который читает доки первым (а он читает их первым), уезжал в тупик.
 *
 * ПОЧЕМУ МАШИНОЙ. Противоречие «дока против кода» не видно ни в одном прогоне:
 * PHP валиден, тесты зелёные, дока связная. Замечает его только человек и только
 * когда уже написал лишний эндпоинт. Проверка стоит секунду и держит доки честными.
 *
 * ЧТО СЧИТАЕТСЯ НАРУШЕНИЕМ. Упоминание запрещённого приёма в АКТУАЛЬНОЙ доке без
 * пометки, что так делать не надо. Отрицательные упоминания («такого нет»,
 * «запрещён гейтом», «гейт ловит») законны и нужны — на них держится половина
 * объяснений. Исторические отчёты ревью не трогаем: они фиксируют прошлое.
 *
 *   php tools/docs-contract-check.php          # человеку
 *   php tools/docs-contract-check.php --quiet  # только код возврата
 */

$root = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);
$selfTest = in_array('--self-test', $argv, true);

/** Неквалифицированный абсолют о лимитах коробки. */
function docsUnqualifiedBoxLimitAbsolute(string $line): bool
{
    foreach ([
        '~(?:лимит(?:а|ов)?[^\n]{0,50}нет\s+вовсе|лимит(?:а|ов)?[^\n]{0,50}не\s+имеет\S*\s+вовсе)~iu',
        '~(?:предела\s+портала\s+не\s+существует|коробк\S*\s+без\s+предела)~iu',
        '~портал\S*\s+интенсивност\S*\s+не\s+огранич~iu',
        '~у\s+коробк\S*\s+корзин\S*\s+нет~iu',
    ] as $pattern) {
        if (preg_match($pattern, $line) === 1) return true;
    }
    return false;
}

/** Устаревшая навигация к удалённому экрану, включая перенос строки. */
function docsHasStaleHealthRoute(string $text): bool
{
    return preg_match(
        '~(?:экран(?:е|а|ом)?|вкладк(?:е|а|у|ой)?)\s+«Состояние»~iu',
        $text
    ) === 1;
}

/** Какие обязательные scopes отсутствуют в одной setup-подсказке. */
function docsMissingRequiredScopes(array $required, string $line): array
{
    $missing = [];
    foreach ($required as $scope) {
        $scope = (string)$scope;
        if ($scope === '') continue;
        $pattern = '~(?<![a-z0-9_.-])' . preg_quote($scope, '~') . '(?![a-z0-9_.-])~i';
        if (preg_match($pattern, $line) !== 1) $missing[] = $scope;
    }
    return $missing;
}

/** Текущий review и исторические ledger не должны менять роли местами. */
function docsReviewRoutingProblems(string $map, string $archive, string $research): array
{
    $current = 'review-2026-08-28.md';
    // Роль и её документ должны встретиться в одном утверждении. Склеиваем
    // переносы одного Markdown абзаца, но не соседние пункты/пустые quote-строки.
    $units = static function (string $text): array {
        $paragraphs = [];
        $paragraph = '';
        foreach (preg_split('~\R~u', $text) ?: [] as $raw) {
            $line = preg_replace('~^[ \t]*>[ \t]?~u', '', $raw);
            $startsBlock = preg_match('~^[ \t]*(?:#{1,6}\s|[-*+]\s)~u', $line) === 1;
            if (trim($line) === '' || $startsBlock) {
                if ($paragraph !== '') $paragraphs[] = $paragraph;
                $paragraph = '';
            }
            if (trim($line) !== '') $paragraph .= ($paragraph === '' ? '' : ' ') . trim($line);
        }
        if ($paragraph !== '') $paragraphs[] = $paragraph;
        $out = [];
        foreach ($paragraphs as $part) {
            $part = str_replace(['**', '*', chr(96)], '', $part);
            foreach (preg_split('~(?<=[.!?;])\s+~u', $part) ?: [] as $sentence) $out[] = $sentence;
        }
        return $out;
    };
    $hasRole = static function (string $text, string $role, ?string $document = null) use ($units): bool {
        $claim = '~\b(?:' . $role . ')~iu';
        $negation = '~(?:\b(?:не|нет)\b[^.!?;\n]{0,80}\b(?:' . $role . ')'
            . '|\b(?:' . $role . ')[^.!?;\n]{0,80}\b(?:не\s+(?:явля\p{L}*|счита\p{L}*)|отсутств\p{L}*))~iu';
        foreach ($units($text) as $unit) {
            if ($document !== null && strpos($unit, $document) === false) continue;
            if (preg_match($claim, $unit) === 1 && preg_match($negation, $unit) !== 1) return true;
        }
        return false;
    };
    $currentRole = 'текущ\p{L}*|действующ\p{L}*';
    $historyRole = 'архив\p{L}*|истори\p{L}*';
    $problems = [];
    if (!$hasRole($map, $currentRole, $current)
        || !$hasRole($map, $historyRole, 'CHEKLIST-KACHESTVA')
        || strpos($map, 'ЕДИНЫЙ список всех работ') !== false) {
        $problems[] = 'docs/MAP.md не отделяет текущий review от исторического CHEKLIST';
    }
    $archiveHead = mb_substr($archive, 0, 1800, 'UTF-8');
    if (!$hasRole($archiveHead, $currentRole, $current)
        || !$hasRole($archiveHead, 'архив\p{L}*')
        || strpos($archiveHead, 'ЕДИНЫЙ список всех работ') !== false) {
        $problems[] = 'docs/CHEKLIST-KACHESTVA.md не помечен архивом со ссылкой на current review';
    }
    $researchHead = mb_substr($research, 0, 2200, 'UTF-8');
    if (!$hasRole($researchHead, $currentRole, $current)
        || !$hasRole($researchHead, $historyRole, 'CHEKLIST-KACHESTVA')
        || strpos($researchHead, 'Канонический ledger') !== false) {
        $problems[] = 'box research всё ещё объявляет исторический CHEKLIST current ledger';
    }
    return $problems;
}

if ($selfTest) {
    $bad = [
        'Портального лимита там нет вовсе',
        'коробка без предела',
        'коробочный портал — портал интенсивность не ограничивает',
        'У коробки корзины нет',
    ];
    $good = [
        'К коробке не применяется облачная leaky-bucket корзина интенсивности.',
        'В REST-модуле коробки resource-cost guard по умолчанию выключен, но может быть включён администратором.',
        'Пакет сокращает HTTP-запросы, но не отменяет ресурсную стоимость и ёмкость сервера.',
    ];
    $failed = [];
    foreach ($bad as $line) if (!docsUnqualifiedBoxLimitAbsolute($line)) $failed[] = 'не поймано: ' . $line;
    foreach ($good as $line) if (docsUnqualifiedBoxLimitAbsolute($line)) $failed[] = 'ложный сбой: ' . $line;
    $staleHealthBad = [
        'Проверьте планировщик на экране «Состояние»',
        "счётчик показывает экран
«Состояние»",
        'откройте на вкладке «Состояние»',
    ];
    $staleHealthGood = [
        'Раздел «Состояние» убран 26.08.2026',
        '### Состояние',
        'Откройте «Настройки → Служебное»',
    ];
    foreach ($staleHealthBad as $line) {
        if (!docsHasStaleHealthRoute($line)) $failed[] = 'не пойман старый runtime-маршрут: ' . $line;
    }
    foreach ($staleHealthGood as $line) {
        if (docsHasStaleHealthRoute($line)) $failed[] = 'ложный stale runtime-маршрут: ' . $line;
    }
    $requiredScopes = ['crm', 'user', 'department', 'placement'];
    if (docsMissingRequiredScopes($requiredScopes, 'Скоупы минимум: crm user placement') !== ['department']) {
        $failed[] = 'не пойман пропущенный обязательный scope department';
    }
    if (docsMissingRequiredScopes($requiredScopes, 'Минимум: placement, department, user, crm') !== []) {
        $failed[] = 'ложный scope drift при другом порядке и разделителях';
    }
    if (docsMissingRequiredScopes($requiredScopes, 'crm user departmental placement') !== ['department']) {
        $failed[] = 'часть чужого слова ошибочно принята за scope department';
    }
    $badRouting = docsReviewRoutingProblems(
        'Что ещё не доделано → CHEKLIST-KACHESTVA: ЕДИНЫЙ список всех работ',
        '# Чеклист качества — ЕДИНЫЙ список всех работ',
        'Канонический ledger — docs/CHEKLIST-KACHESTVA.md'
    );
    if (count($badRouting) !== 3) $failed[] = 'не пойманы три старых review-route';
    $goodRouting = docsReviewRoutingProblems(
        'Текущий review: review-2026-08-28.md; CHEKLIST-KACHESTVA — исторический архив',
        '# Архив quality-работ; текущий статус: review-2026-08-28.md',
        'Текущий review: review-2026-08-28.md; CHEKLIST-KACHESTVA — исторический архив'
    );
    if ($goodRouting !== []) $failed[] = 'ложный review-route drift: ' . implode('; ', $goodRouting);
    $routingGood = [
        'Текущий review: review-2026-08-28.md; CHEKLIST-KACHESTVA — исторический архив',
        '# Архив quality-работ; текущий статус: review-2026-08-28.md',
        'Текущий review: review-2026-08-28.md; CHEKLIST-KACHESTVA — исторический архив',
    ];
    foreach ([
        [0, 'review-2026-08-28.md не является текущим. CHEKLIST-KACHESTVA — не архив.'],
        [1, 'Это не архив. review-2026-08-28.md не является текущим.'],
        [2, 'review-2026-08-28.md не является текущим. CHEKLIST-KACHESTVA — не архив.'],
        [0, "review-2026-08-28.md упоминается в справке.\n\nТекущий статус — другой документ.\n\nCHEKLIST-KACHESTVA — исторический архив"],
        [1, "> Архив quality. Действующий цикл:\n>\n> review-2026-08-28.md"],
    ] as $i => [$slot, $text]) {
        $fixture = $routingGood;
        $fixture[$slot] = $text;
        if (docsReviewRoutingProblems(...$fixture) === []) $failed[] = 'R08-DOC-01 отрицательный route ' . $i . ' принят';
    }
    $wrapped = [
        "- Текущий review →\n  review-2026-08-28.md\n- CHEKLIST-KACHESTVA — исторический\n  архив",
        "> Архив quality. Это не текущий долг. Действующий цикл,\n> локальные находки: review-2026-08-28.md",
        "> Текущий review — review-2026-08-28.md; CHEKLIST-KACHESTVA — исторический\n> архив, а не current ledger",
    ];
    if (docsReviewRoutingProblems(...$wrapped) !== []) $failed[] = 'R08-DOC-01 корректные переносы строк отвергнуты';
    $unicode = $routingGood;
    $unicode[1] = str_repeat('я', 1000) . "\n\n" . $routingGood[1];
    if (docsReviewRoutingProblems(...$unicode) !== []) $failed[] = 'R08-DOC-01 шапка обрезана байтами вместо UTF-8 символов';

    if ($failed !== []) {
        fwrite(STDERR, "СБОЙ self-test договора документации:\n  ✗ " . implode("\n  ✗ ", $failed) . "\n");
        exit(1);
    }
    if (!$quiet) echo "✓ Self-test: отрицательные fixtures договора документации ловятся\n";
    exit(0);
}

/** Актуальные доки: всё .md в корне и в docs/, кроме датированных отчётов. */
$docs = [];
foreach ([$root, $root . '/docs'] as $dir) {
    foreach (glob($dir . '/*.md') ?: [] as $f) {
        $rel = ltrim(substr($f, strlen($root)), '/');
        // Отчёты ревью и исследований — история, а не действующая инструкция.
        if (preg_match('~^docs/(review-|b24-.*-\d{4}-\d{2}-\d{2}\.md$)~u', $rel)) continue;
        $docs[$rel] = $f;
    }
}
ksort($docs);

/**
 * Запрещённые приёмы: что ищем и почему это противоречит коду.
 * Ключ — регулярка по строке, значение — объяснение для человека.
 */
$forbidden = [
    '~\bproxy~iu' =>
        'серверного посредника в шаблоне нет: данные портала берёт фабрика b24() под токеном '
        . 'установщика, а фронт ходит только в свои эндпоинты через ui.api()',
    '~BX24\.callMethod~u' =>
        'фронту запрещено ходить в портал напрямую (гейт tests/uses_template_test.php); '
        . 'личность смотрящего резолвится на сервере: AUTH_ID → resolveUserContextFromAuth()',
];

/**
 * Пометки, при которых упоминание законно — это разбор запрета, а не рецепт.
 * Список намеренно узкий и из целых слов: широкое «не» превращает проверку в
 * решето (в «Не-админам бизнес-вызовы идут через proxy.php» тоже есть «не»).
 */
$negation = [
    'нет', 'нельзя', 'запрещ', 'ловит', 'остаток', 'без единого',
    'не содержит', 'не нужен', 'не нужна', 'не бывает', 'не используется', 'не было',
];

$problems = [];
foreach ($docs as $rel => $path) {
    $text = (string)file_get_contents($path);
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        $low = mb_strtolower($line, 'UTF-8');
        foreach ($forbidden as $re => $why) {
            if (!preg_match($re, $line)) continue;
            $marked = false;
            foreach ($negation as $mark) {
                if (mb_strpos($low, $mark, 0, 'UTF-8') !== false) { $marked = true; break; }
            }
            if ($marked) continue;
            $problems[] = sprintf('%s:%d — %s%s   %s', $rel, $i + 1, trim($line), PHP_EOL, $why);
        }
    }
}

// BX11-05: узкий факт об облачной корзине не равен «коробка без лимитов».
// Серверная ёмкость и включаемый REST resource guard — отдельные ограничения.
foreach (['www/api/plan.php', 'docs/BLOCKS.md', 'docs/MAP.md', 'docs/capabilities.json'] as $rel) {
    $path = $root . '/' . $rel;
    $lines = explode("\n", (string)@file_get_contents($path));
    foreach ($lines as $i => $line) {
        if (!docsUnqualifiedBoxLimitAbsolute($line)) continue;
        $problems[] = sprintf(
            '%s:%d — %s%s   неквалифицированный абсолют: нет только облачной leaky-bucket '
            . 'корзины; ёмкость сервера и REST resource guard проверяются отдельно',
            $rel, $i + 1, trim($line), PHP_EOL
        );
    }
}

// Просто удалить абсолют недостаточно: агенту нужен положительный договор о трёх
// разных контурах. В plan.php пакеты названы в коде; в агентских картах они обязаны быть явными.
$boxContractRequired = [
    'www/api/plan.php' => ['облачн', 'корзин', 'сервер', 'resource guard'],
    'docs/BLOCKS.md' => ['облачн', 'корзин', 'сервер', 'resource guard', 'пакет'],
    'docs/MAP.md' => ['облачн', 'корзин', 'сервер', 'resource guard', 'пакет'],
    'docs/capabilities.json' => ['облачн', 'корзин', 'сервер', 'resource guard', 'пакет'],
];
foreach ($boxContractRequired as $rel => $needles) {
    $text = mb_strtolower((string)@file_get_contents($root . '/' . $rel), 'UTF-8');
    foreach ($needles as $needle) {
        if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) continue;
        $problems[] = "{$rel} не называет отдельный контур коробочных лимитов: {$needle}";
    }
}

/**
 * ПОЛИТИКА ССЫЛОК: шаблон owner-нейтральный, значит и ссылки в нём — общие.
 *
 * Находка ревью R3-023 (09.08.2026). В `.portal-meta.json` шаблона `owner: null`,
 * а доки вели в `/data/kb` — личный каталог заметок ОДНОГО владельца. У любого
 * другого владельца этого каталога нет: агент идёт по ссылке, упирается в
 * «нет файла» и остаётся без правила, ради которого ссылка и стояла. Общий
 * канон при этом существует и лежит в `/data/standards/bx-app/kb`.
 *
 * ОБЛАСТЬ ПРОВЕРКИ НАЗВАНА ЧЕСТНО. Здесь проверяются `PORTING.md` и `docs/*.md`.
 * Дополнительно проверяются `README.md`, `CLAUDE.md` и единственный актуальный
 * комментарий install-controller: после очистки их нельзя снова увести в
 * owner-private KB. Исторические отчёты по-прежнему исключены выше.
 */
$linkPolicyFiles = [];
if (isset($docs['PORTING.md'])) $linkPolicyFiles['PORTING.md'] = $docs['PORTING.md'];
foreach (['README.md', 'CLAUDE.md'] as $rel) {
    if (isset($docs[$rel])) $linkPolicyFiles[$rel] = $docs[$rel];
}
if (is_file($root . '/www/index.php')) $linkPolicyFiles['www/index.php'] = $root . '/www/index.php';
foreach ($docs as $rel => $path) {
    if (strpos($rel, 'docs/') === 0) $linkPolicyFiles[$rel] = $path;
}
foreach ($linkPolicyFiles as $rel => $path) {
    $lines = explode("\n", (string)file_get_contents($path));
    foreach ($lines as $i => $line) {
        if (strpos($line, '/data/kb') === false) continue;
        $problems[] = sprintf(
            '%s:%d — %s%s   шаблон owner-нейтральный (owner: null), а /data/kb — личный каталог одного '
            . 'владельца: у остальных ссылка ведёт в никуда. Общий канон — /data/standards/bx-app/kb, '
            . 'поиск правила — grep по /data/standards/bx-app/INDEX.md',
            $rel, $i + 1, trim($line), PHP_EOL
        );
    }
}

/**
 * Обратная сторона: действующий договор обязан быть НАЗВАН, а не подразумеваться.
 * Без этого «убрали противоречие» сводится к «вырезали абзац», и агент остаётся
 * вообще без ответа на вопрос «как тогда правильно».
 */
$porting = @file_get_contents($root . '/PORTING.md');
if ($porting === false) {
    $problems[] = 'PORTING.md не читается — договор портирования назвать нечем';
} else {
    foreach (['resolveUserContextFromAuth', 'ui.api'] as $must) {
        if (mb_strpos($porting, $must, 0, 'UTF-8') === false) {
            // Фигурные скобки обязательны: в PHP идентификатором может быть и байт
            // старше 0x7f, поэтому «$must»» разбирается как переменная $must» — имя
            // с кавычкой внутри. Прогон это и показал: два «нет упоминания «» подряд.
            $problems[] = "PORTING.md не называет действующий договор: нет упоминания «{$must}»";
        }
    }
}

// Названный в коде символ обязан существовать: дока, ссылающаяся на удалённую
// функцию, врёт так же, как дока, зовущая в чужую архитектуру.
$b24 = @file_get_contents($root . '/www/api/b24.php');
if ($b24 !== false && strpos($b24, 'function resolveUserContextFromAuth') === false) {
    $problems[] = 'www/api/b24.php больше не объявляет resolveUserContextFromAuth() — '
        . 'договор в PORTING.md указывает на несуществующую функцию';
}

// Датированное коробочное исследование — история, но его OPEN/backlog уже один
// раз заставил агента повторно проектировать закрытые блоки. Overlay обязан
// явно связать все закрытые ID с текущим ledger, а ключевые заголовки — не
// выглядеть текущими открытыми задачами.
$boxResearch = @file_get_contents($root . '/docs/b24-on-premise-research-2026-08-08.md');
if ($boxResearch !== false) {
    foreach (['BXBOX-01', 'BXBOX-02', 'BXBOX-05', 'BXR-01', 'BXR-02'] as $id) {
        if (!preg_match('~' . preg_quote($id, '~') . '[^\n]*(?:CLOSED|закрыт)~iu', $boxResearch)) {
            $problems[] = "коробочное исследование не помечает {$id} как CLOSED в текущем overlay/заголовке";
        }
    }
}

foreach (docsReviewRoutingProblems(
    (string)@file_get_contents($root . '/docs/MAP.md'),
    (string)@file_get_contents($root . '/docs/CHEKLIST-KACHESTVA.md'),
    (string)@file_get_contents($root . '/docs/b24-on-premise-research-2026-08-08.md')
) as $routingProblem) {
    $problems[] = 'R28-10: ' . $routingProblem;
}

// R28-08: точка расширения settingsSchema() намеренно пуста, поэтому ссылка
// «возьмите готовый пример в BLOCKS» обязана вести к настоящему копируемому
// массиву, а не к перечню названий типов. Маркеры делают проверку узкой: слова
// text/select встречаются в документе и в десятках других разделов.
$blocks = (string)@file_get_contents($root . '/docs/BLOCKS.md');
$schemaStart = '<!-- settings-schema-example:start -->';
$schemaEnd = '<!-- settings-schema-example:end -->';
$from = strpos($blocks, $schemaStart);
$to = strpos($blocks, $schemaEnd);
if ($from === false || $to === false || $to <= $from) {
    $problems[] = 'docs/BLOCKS.md не содержит отмеченный копируемый пример settingsSchema всех шести типов';
} else {
    $schemaExample = substr($blocks, $from, $to - $from);
    foreach (['text', 'multiline', 'number', 'bool', 'select', 'secret'] as $type) {
        if (strpos($schemaExample, "'type' => '" . $type . "'") === false) {
            $problems[] = "пример settingsSchema в docs/BLOCKS.md не содержит тип {$type}";
        }
    }
    foreach (["'key' =>", "'title' =>", "'default' =>", "'min' =>", "'max' =>", "'options' =>"] as $part) {
        if (strpos($schemaExample, $part) === false) {
            $problems[] = "пример settingsSchema в docs/BLOCKS.md неполон: нет {$part}";
        }
    }
}

// R28-09: Codex получает проектный self-check через установленный bx-app
// plugin. Это не безусловная гарантия: hooks должны быть доверены. Инструкция,
// которая отрицает сам hook или умалчивает trust-fallback, заставляет агента
// считать механическую защиту отсутствующей либо безусловной.
$agentsDoc = (string)@file_get_contents($root . '/AGENTS.md');
$selfCheckDoc = (string)@file_get_contents($root . '/tools/self-check.sh');
if (strpos($agentsDoc, 'У Codex проектного Stop-хука нет') !== false) {
    $problems[] = 'AGENTS.md ошибочно отрицает проектный Codex Stop из bx-app plugin';
}
foreach ([$agentsDoc, $selfCheckDoc] as $i => $hookDoc) {
    $rel = $i === 0 ? 'AGENTS.md' : 'tools/self-check.sh';
    if (strpos($hookDoc, 'project-self-check.sh') === false || mb_stripos($hookDoc, 'довер', 0, 'UTF-8') === false) {
        $problems[] = "{$rel} не объясняет Codex project-self-check.sh и зависимость от доверия hooks";
    }
}

// R28-11: экран «Состояние» удалён 26.08; действия health теперь находятся в
// карточке «Служебное» внутри настроек. Активные карты не должны возвращать
// агенту удалённую вкладку (датированные review-файлы сюда не входят).
$healthUiDocs = [
    'www/api/task.php' => (string)@file_get_contents($root . '/www/api/task.php'),
    'www/js/app.js' => (string)@file_get_contents($root . '/www/js/app.js'),
    'docs/MAP.md' => (string)@file_get_contents($root . '/docs/MAP.md'),
    'docs/RECIPES.md' => (string)@file_get_contents($root . '/docs/RECIPES.md'),
    'SECURITY.md' => (string)@file_get_contents($root . '/SECURITY.md'),
    'www/env.php' => (string)@file_get_contents($root . '/www/env.php'),
];
foreach ($healthUiDocs as $rel => $healthDoc) {
    if (docsHasStaleHealthRoute($healthDoc)) {
        $problems[] = "{$rel} всё ещё направляет к удалённому экрану «Состояние»";
    }
    // Cron настраивается на хостинге, а «Служебное» содержит только действия приложения.
    $expectedRoute = in_array($rel, ['www/api/task.php', 'www/js/app.js'], true)
        ? 'панели хостинга' : 'Настройки → Служебное';
    if (strpos($healthDoc, $expectedRoute) === false) {
        $problems[] = "{$rel} не содержит действующий маршрут: {$expectedRoute}";
    }
}

// R31-DOC-02: setup обязан повторять исполняемый APP_SCOPES, а не старый
// вручную переписанный минимум. Канон читается из env.php при каждом прогоне.
$scopeEnv = (string)@file_get_contents($root . '/www/env.php');
$scopeRequired = [];
if (preg_match("~define\\('APP_SCOPES'\\s*,\\s*\\[([^\\]]*)\\]\\s*\\);~s", $scopeEnv, $scopeMatch) !== 1) {
    $problems[] = 'www/env.php не содержит разбираемый define APP_SCOPES';
} else {
    preg_match_all("~['\\\"]([a-z][a-z0-9_.-]*)['\\\"]~i", $scopeMatch[1], $scopeNames);
    $scopeRequired = array_values(array_unique($scopeNames[1] ?? []));
    sort($scopeRequired);
    if ($scopeRequired === []) $problems[] = 'APP_SCOPES разобран как пустой список';
}
$scopeSetupFiles = ['CLAUDE.md', 'README.md', 'www/env.php', 'deploy-prod.sh'];
foreach ($scopeSetupFiles as $rel) {
    $scopeHintFound = false;
    $lines = explode("\n", (string)@file_get_contents($root . '/' . $rel));
    foreach ($lines as $i => $line) {
        if (preg_match('~(?:скоуп[^\\n]{0,100}миним|миним[^\\n]{0,100}скоуп)~iu', $line) !== 1) continue;
        $scopeHintFound = true;
        $missing = docsMissingRequiredScopes($scopeRequired, $line);
        if ($missing !== []) {
            $problems[] = sprintf('%s:%d — minimum scopes пропускают APP_SCOPES: %s',
                $rel, $i + 1, implode(', ', $missing));
        }
    }
    if (!$scopeHintFound) $problems[] = "{$rel} не содержит разбираемую подсказку minimum scopes";
}

// R28-12: BLOCKS объявляет полный ручной browser-checklist. Источник списка —
// сам self-check, чтобы добавленный сценарий нельзя было запустить машиной и
// забыть в инструкции человеку.
if (preg_match('~for scenario in ([^;]+); do~', $selfCheckDoc, $liveMatch) !== 1) {
    $problems[] = 'tools/self-check.sh не содержит разбираемый список живых сценариев';
} else {
    preg_match_all('~tests/[a-z0-9-]+\.js~', $liveMatch[1], $liveFiles);
    foreach (array_unique($liveFiles[0]) as $liveFile) {
        if (strpos($blocks, $liveFile) === false) {
            $problems[] = "docs/BLOCKS.md не перечисляет обязательный живой сценарий {$liveFile}";
        }
    }
}

if ($problems) {
    fwrite(STDERR, "ДОКИ ПРОТИВОРЕЧАТ КОДУ (" . count($problems) . "):\n");
    foreach ($problems as $p) fwrite(STDERR, "  ✗ $p\n");
    exit(1);
}
if (!$quiet) echo "✓ Договор документации: актуальные доки не зовут в запрещённую архитектуру\n";
exit(0);
