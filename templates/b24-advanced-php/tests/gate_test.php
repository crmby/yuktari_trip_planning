<?php
declare(strict_types=1);
/**
 * Проверка САМОГО ГЕЙТА: ловит ли он нарушение, написанное «умно».
 *
 * Зачем это отдельным набором. Гейт «пользуйся готовым» — единственное место,
 * которое заставляет агента брать готовые блоки вместо своих. Два сторонних
 * ревью 03.08.2026 показали, что обе прежние версии обходились: сначала восемь
 * способов у подстрочной, потом шесть у структурной — значение уезжало в
 * переменную, файл клали уровнем глубже, один комментарий снимал проверки со
 * всего файла, а два набора-забора удалялись «штатно», оставляя зелёный итог.
 *
 * Правило, которое обходится, не работает, а зелёный гейт врёт убедительнее,
 * чем его отсутствие. Поэтому здесь каждый обход воспроизведён образцом кода, и
 * набор требует, чтобы предикат его ПОЙМАЛ. Ломается это только вместе с самим
 * гейтом — то есть заметно.
 */

require_once __DIR__ . '/gate-lib.php';

suite('Гейт ловит обходы, а не только прямые нарушения');

// Focused-прогон этого набора обязан сам заметить, что его сопровождающий
// REQUIRED_SUITE_MIN_CHECKS отстал. Иначе isolated GREEN публикует новые checks,
// а рассинхронизация обнаруживается только поздним общим прогоном.
$gateSuiteChecksAtStart = (int)($GLOBALS['__tests_total'] ?? 0);

$dir = sys_get_temp_dir() . '/bxapp-gate-' . bin2hex(random_bytes(4));
@mkdir($dir, 0700, true);
// Уборки тут не было вовсе: замер 05.09.2026 нашёл в /tmp 207 брошенных
// каталогов bxapp-gate-*. Реестр из lib.php убирает и при обрыве сигналом.
testRegisterTemp($dir);
$made = [];
$sample = static function (string $name, string $code) use ($dir, &$made): string {
    // Старые semantic fixtures писали `$b24` как неявно известный receiver.
    // После provenance-гейта каждый positive fixture обязан сам доказать источник:
    // named functions получают type, closures — реальный outer assignment.
    if (substr($name, -4) === '.php' && strpos($code, '$b24->') !== false) {
        $code = (string)preg_replace(
            '~(function\s+[A-Za-z_]\w*\s*\(\s*)\$b24\b~', '$1B24 $b24', $code
        );
        $needsOuter = strpos($code, 'use ($b24') !== false
            || preg_match('~function\s+[A-Za-z_]\w*\s*\([^)]*\$b24~', $code) !== 1;
        $hasCanonical = preg_match('~\$b24\s*=\s*(?:\\\\)?b24\s*\(\s*\)~i', $code) === 1;
        $explicitNonPortal = preg_match('~\$b24\s*=\s*widget\s*\(\s*\)~i', $code) === 1;
        if ($needsOuter && !$hasCanonical && !$explicitNonPortal) {
            if (preg_match('~\bnamespace\s+[^;]+;~', $code, $namespace, PREG_OFFSET_CAPTURE)) {
                $after = (int)$namespace[0][1] + strlen($namespace[0][0]);
                $code = substr($code, 0, $after) . "\n\$b24 = b24();" . substr($code, $after);
            } else {
                $code = (string)preg_replace('~^<\?php\s*~', "<?php\n\$b24 = b24();\n", $code, 1);
            }
        }
    }
    $path = $dir . '/' . $name;
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $code);
    $made[] = $path;
    return $path;
};

// ── 1. Единый вход ───────────────────────────────────────────────────────────

// 1.1. Вход в заведомо ложной ветке — «вызов есть», а выполнения нет.
$deadGate = $sample('dead_gate.php', "<?php\nif (false) { apiBoot(); }\n\$x = 1;\n");
check('вызов гейта в мёртвой ветке не считается защитой', !gateBootBeforeEffects($deadGate));
check('мёртвая ветка ловится отдельно', gateHasDeadBranch($deadGate));

// 1.2. Вход ПОСЛЕ действий: проверка пропуска и роли после записи защищает ничто.
$lateGate = $sample('late_gate.php', "<?php\nrequire_once __DIR__ . '/http.php';\n\$data = ['x' => 1];\nstoreWrite(STATE_FILE, \$data);\n\$ctx = apiBoot(['methods' => ['POST']]);\napiOk([]);\n");
check('единый вход после первого действия не засчитывается', !gateBootBeforeEffects($lateGate));

// 1.3. Вход под условием без фигурных скобок — прежняя проверка считала его «в теле».
$ifGate = $sample('if_gate.php', "<?php\nrequire_once __DIR__ . '/http.php';\nif (\$_SERVER['REQUEST_METHOD'] === 'POST') \$ctx = apiBoot(['methods' => ['POST']]);\napiOk([]);\n");
check('единый вход под условием не засчитывается', !gateBootBeforeEffects($ifGate));

// Короткое замыкание: имя вызвано в исходнике, но сама функция недостижима.
$shortGate = $sample('short_gate.php', "<?php\nfalse && apiBoot(['methods' => ['POST']]);\napiOk([]);\n");
check('apiBoot в коротком замыкании не считается входом', !gateBootBeforeEffects($shortGate));
check('короткое замыкание с константой считается мёртвой веткой', gateHasDeadBranch($shortGate));
$comparisonBranch = $sample('comparison_branch.php', "<?php\nif (\$i !== false && \$old !== null && (\$ok === true || \$fallback)) { work(); }\n");
check('сравнения с false/null/true не выдаются за мёртвые ветки', !gateHasDeadBranch($comparisonBranch));
$bootArgEffect = $sample('boot_arg_effect.php', "<?php\n\$ctx = apiBoot(['rate' => [rememberAttempt(), 60]]);\n");
check('побочный эффект в аргументе apiBoot происходит до входа и ловится',
    !gateBootBeforeEffects($bootArgEffect));
$failureArgEffect = $sample('failure_arg_effect.php', "<?php\ninstallFailureHandler(beforeAuth());\n\$ctx = apiBoot();\n");
check('installFailureHandler не прячет действие до apiBoot', !gateBootBeforeEffects($failureArgEffect));
$requireArgEffect = $sample('require_arg_effect.php', "<?php\nrequire_once includePathWithEffect();\n\$ctx = apiBoot();\n");
check('вычисляемый require до apiBoot не разрешён', !gateBootBeforeEffects($requireArgEffect));
$defineArgEffect = $sample('define_arg_effect.php', "<?php\ndefine('PREBOOT', beforeAuth());\n\$ctx = apiBoot();\n");
check('define с вычислением не прячет действие до apiBoot', !gateBootBeforeEffects($defineArgEffect));
$defineEndpoint = $sample('define_endpoint.php', "<?php\ndefine('REPORT', reportMain());\nfunction reportMain(): string { return 'secret'; }\necho REPORT;\n");
check('define с вызовом не превращает эндпоинт в библиотеку', !gateIsLibrary($defineEndpoint));
// Брат define(): с PHP 8.1 `new` разрешён в инициализаторе глобальной константы,
// и конструктор выполняется прямо на этом операторе. Гейт же читал `const` как
// объявление и пропускал целиком — произвольная работа шла до проверки пропуска,
// роли и частоты, а прогон оставался зелёным (K4-08, ревью 09.08.2026).
$constNewEffect = $sample('const_new_effect.php', "<?php\nclass Warmup { public function __construct() { storeWrite(STATE_FILE, []); } }\nconst WARMUP = new Warmup();\n\$ctx = apiBoot(['methods' => ['GET']]);\n");
check('const с new не прячет действие до apiBoot', !gateBootBeforeEffects($constNewEffect));
$constCallEffect = $sample('const_call_effect.php', "<?php\nconst REPORT = REPORT_PREFIX . reportMain();\nfunction reportMain(): string { return 'secret'; }\n");
check('const с вызовом не превращает эндпоинт в библиотеку', !gateIsLibrary($constCallEffect));
$constLiteral = $sample('const_literal.php', "<?php\nconst PAGE_SIZE = 50, PAGE_MAX = 1000;\nconst MODES = ['view', 'edit'];\n\$ctx = apiBoot(['methods' => ['GET']]);\n");
check('обычные литеральные const до входа по-прежнему разрешены',
    gateBootBeforeEffects($constLiteral));

// 1.3-бис. Стрелочное замыкание, вызванное на месте, — такая же часть загрузки,
// как и `function`. Тело `fn` не ограждено скобками, и прежний разбор кончал его
// на ПЕРВОЙ запятой, не считая вложенности: у любого вызова с двумя аргументами
// тело обрывалось, IIFE переставал опознаваться, и весь его код исчезал из
// картины исполнения. Вред двусторонний: работа при загрузке становилась
// невидимой (файл объявлял себя не-эндпоинтом), а apiBoot внутри такой стрелки —
// наоборот, терялся, и настоящая точка входа объявлялась библиотекой (K4-03).
$arrowCommaWork = $sample('arrow_comma_work.php', "<?php\n(static fn(): array => storeUpdate(STATE_FILE, 'strtolower'))();\n");
check('работа в стрелочном IIFE видна, даже когда в вызове есть запятая',
    in_array('storeUpdate', gateTopLevelCalls($arrowCommaWork), true));
$arrowCommaBoot = $sample('arrow_comma_boot.php', "<?php\n(static fn() => apiBoot(['methods' => ['GET'], 'role' => 'view']))();\napiOk([]);\n");
check('apiBoot в стрелочном IIFE опознаётся как разбор запроса',
    in_array('apiBoot', gateTopLevelCalls($arrowCommaBoot), true));
$arrowCallback = $sample('arrow_callback.php', "<?php\nfunction rows(array \$x): array { return array_map(static fn(\$r) => secretWork(\$r, 2), \$x); }\n");
check('стрелка, отданная колбэком в чужую функцию, исполнением не считается',
    !in_array('secretWork', gateTopLevelCalls($arrowCallback), true));

// 1.3-третье. Работа при загрузке БЕЗ ИМЕНИ. Файл, у которого вся работа спрятана
// за конструктором, методом объекта, статическим методом или вызовом из
// переменной, показывал предикату только имена канонического забора — и объявлял
// себя «ничего не делающим при загрузке». Значит, apiBoot с него не требовали, а
// живой 404-контур был доволен: анонимный запрос успевал выполнить мутацию и
// только потом получал 404 (K4-02, ревью 09.08.2026). Имя такого вызова
// предикату неизвестно, но САМ ФАКТ исполнения известен точно — его и метим.
$zabor = "if (realpath(__FILE__) === realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(404); header('X-Robots-Tag: noindex'); exit; }\n";
$ctorWork = $sample('ctor_work.php', "<?php\nclass Pereschet { public function __construct() { storeWrite(STATE_FILE, []); } public function pusk(): void {} }\n(new Pereschet())->pusk();\n" . $zabor);
check('работа через конструктор перед забором видна как исполнение при загрузке',
    in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($ctorWork), true));
$staticWork = $sample('static_work.php', "<?php\nclass Pereschet { public static function pusk(): void { storeWrite(STATE_FILE, []); } }\nPereschet::pusk();\n" . $zabor);
check('статический вызов перед забором виден как исполнение при загрузке',
    in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($staticWork), true));
$varWork = $sample('var_work.php', "<?php\n\$vyzov = 'storeWrite';\n\$vyzov(STATE_FILE, []);\n" . $zabor);
check('вызов по имени из переменной виден как исполнение при загрузке',
    in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($varWork), true));
$arrayWork = $sample('array_work.php', "<?php\n\$reestr = ['pusk' => 'storeWrite'];\n\$reestr['pusk'](STATE_FILE, []);\n" . $zabor);
check('вызов из элемента массива виден как исполнение при загрузке',
    in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($arrayWork), true));
$methodWork = $sample('method_work.php', "<?php\n\$klient = b24();\n\$klient->call('crm.deal.add', []);\n" . $zabor);
check('вызов метода объекта перед забором виден как исполнение при загрузке',
    in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($methodWork), true));
// Контрпримеры: метка не должна лепиться к объявлениям. Иначе честная
// библиотека получит ложный красный «нет apiBoot» — ровно та беда, что в K4-40.
$libNewInside = $sample('lib_new_inside.php', "<?php\nfunction stamp(): string { return (new DateTimeImmutable('now'))->format('c'); }\nclass Row { public function make(): array { return (new Builder())->rows(); } }\n" . $zabor);
check('new внутри функции и метода исполнением при загрузке не считается',
    !in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($libNewInside), true));
$libAttrNew = $sample('lib_attr_new.php', "<?php\n#[Attribute]\nclass Marker { public function __construct(public string \$why = '') {} }\n#[Marker('нужен для примера')]\nfunction rows(): array { return []; }\n" . $zabor);
check('аргумент атрибута исполнением при загрузке не считается',
    !in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($libAttrNew), true));
$libDefaultNew = $sample('lib_default_new.php', "<?php\nfunction rows(?DateTimeImmutable \$at = new DateTimeImmutable('now')): array { return []; }\n" . $zabor);
check('значение параметра по умолчанию исполнением при загрузке не считается',
    !in_array(GATE_EXECUTION_MARK, gateTopLevelCalls($libDefaultNew), true));

// 1.4. Нормальный эндпоинт при этом проходит — гейт не должен «ловить всё».
$goodGate = $sample('good_gate.php', "<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/http.php';\ninstallFailureHandler(true);\n\$ctx = apiBoot(['methods' => ['GET']]);\napiOk([]);\n");
check('обычный эндпоинт с apiBoot до действий проходит', gateBootBeforeEffects($goodGate));
check('в обычном эндпоинте мёртвых веток нет', !gateHasDeadBranch($goodGate));

// 1.5. Библиотека проекта (только функции) эндпоинтом не считается и входа не требует.
$libFile = $sample('report_lib.php', "<?php\nrequire_once __DIR__ . '/http.php';\nfunction reportRows(array \$f): array { return []; }\n");
check('файл-библиотека отличается от эндпоинта', gateIsLibrary($libFile));
check('эндпоинт библиотекой не считается', !gateIsLibrary($goodGate));
$inlineAnswer = $sample('inline_answer.php', "<?php\nrequire_once __DIR__ . '/http.php';\n?>{\"ok\":true}<?php\n\$ctx = apiBoot();\n");
check('inline HTML не проходит ни как библиотека, ни как ответ после auth',
    !gateIsLibrary($inlineAnswer) && !gateBootBeforeEffects($inlineAnswer)
    && gateOwnAnswerLines($inlineAnswer) !== []);

// 1.5-бис. Модификатор объявления — не действие. `final class`, `abstract class`,
// `enum` и атрибут объявляют тип ровно так же, как голое `class`, но разбор
// смотрел только на голый токен и считал такой оператор выражением: нормальный
// эндпоинт получал ложный красный «действие до apiBoot», а библиотека с
// #[Deprecated] переставала быть библиотекой (K4-40, ревью 09.08.2026).
$finalBeforeBoot = $sample('final_before_boot.php', "<?php\ndeclare(strict_types=1);\nfinal class ReportHelper { public static function rows(): array { return []; } }\n\$ctx = apiBoot(['methods' => ['GET']]);\napiOk([]);\n");
check('final class перед входом не считается действием до apiBoot',
    gateBootBeforeEffects($finalBeforeBoot));
$abstractBeforeBoot = $sample('abstract_before_boot.php', "<?php\nabstract class ReportBase { abstract public function rows(): array; }\n\$ctx = apiBoot(['methods' => ['GET']]);\n");
check('abstract class перед входом не считается действием до apiBoot',
    gateBootBeforeEffects($abstractBeforeBoot));
$enumBeforeBoot = $sample('enum_before_boot.php', "<?php\nenum ReportKind: string { case Deals = 'deals'; case Leads = 'leads'; }\n\$ctx = apiBoot(['methods' => ['GET']]);\n");
check('enum перед входом не считается действием до apiBoot',
    gateBootBeforeEffects($enumBeforeBoot));
$attrLib = $sample('attr_lib.php', "<?php\nrequire_once __DIR__ . '/http.php';\n#[Deprecated('use reportRows2')]\nfunction reportRows(array \$f): array { return []; }\n");
check('атрибут над функцией не лишает файл звания библиотеки', gateIsLibrary($attrLib));
// Контрпример: пропуск модификаторов не должен глотать САМ оператор. Работа
// статическим вызовом до входа обязана остаться нарушением.
$finalThenWork = $sample('final_then_work.php', "<?php\nfinal class ReportHelper { public static function warm(): void { storeWrite(STATE_FILE, []); } }\nReportHelper::warm();\n\$ctx = apiBoot(['methods' => ['GET']]);\n");
check('работа статическим вызовом до входа остаётся действием до apiBoot',
    !gateBootBeforeEffects($finalThenWork) && !gateIsLibrary($finalThenWork));

// Одинаковое имя не доказывает использование готового helper: endpoint мог
// объявить пустышки в своём namespace и пройти все положительные проверки.
$shadowedHelpers = $sample('shadowed_helpers.php', "<?php\nnamespace Project\\Endpoint;\nfunction apiBoot(array \$x = []) { return []; }\nfunction runOnce(string \$key, callable \$fn) { return \$fn(); }\nfunction auditLog(string \$event, array \$data = []) {}\n\$ctx = apiBoot();\nrunOnce('x', function () use (\$b24) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    auditLog('deal.update', ['id' => 1]);\n    return \$r;\n});\n");
check('локальные пустышки apiBoot/runOnce/auditLog не считаются готовыми модулями',
    gateProtectedHelperShadows($shadowedHelpers) !== []);
$aliasedHelper = $sample('aliased_helper.php', "<?php\nnamespace Project\\Endpoint;\nuse function Evil\\fakeBoot as apiBoot;\n\$ctx = apiBoot();\n");
check('импорт функции под защищённым helper-именем ловится',
    gateProtectedHelperShadows($aliasedHelper) !== []);
$importedHelper = $sample('imported_helper.php', "<?php\nnamespace Project\\Endpoint;\nuse function Evil\\runOnce;\n");
check('прямой импорт защищённого helper-имени ловится',
    gateProtectedHelperShadows($importedHelper) !== []);
$ordinaryHelper = $sample('ordinary_helper.php', "<?php\nfunction projectReportRows(): array { return []; }\n");
check('обычный project-helper не считается подменой ядра',
    gateProtectedHelperShadows($ordinaryHelper) === []);
$rawMutationRetry = $sample('raw_mutation_retry.php', "<?php\n\$safe = true;\nrunOnce('x', function () use (\$b24, \$safe) {\n  return \$b24->call('crm.deal.add', ['fields' => []], \$safe);\n});\n");
$readRetry = $sample('read_retry.php', "<?php\n\$b24->call('crm.deal.get', ['id' => 1], true);\n");
check('raw safeToRepeat=true у мутации ловится даже внутри runOnce',
    gateUnsafeExplicitMutationRetry($rawMutationRetry) !== []);
check('явный retry чтения не выдаётся за опасную мутацию', gateUnsafeExplicitMutationRetry($readRetry) === []);
$securityShadows = $sample('security_shadows.php', "<?php\nfunction maskSecrets(\$x) { return \$x; }\nfunction installFailureHandler() {}\n");
check('подмена maskSecrets/installFailureHandler не отключает защиту',
    count(gateProtectedHelperShadows($securityShadows)) === 2);
$fmtShadows = $sample('fmt_shadows.php', "<?php\nfunction b24Day(\$x) { return date('Y-m-d'); }\nfunction money(\$x) { return (float)\$x; }\nfunction journalToday() { return '2026-01-01'; }\n");
check('подмена готовых helper дат/денег/журнала не засчитывается',
    count(gateProtectedHelperShadows($fmtShadows)) === 3);

// 1.6. Готовый fmt.php должен быть не рекомендацией, а машинным контрактом.
$rawDomainDate = $sample('raw_domain_date.php', "<?php\n\$stamp = strtotime(\$row['CREATED_TIME']);\n\$day = date('Y-m-d', \$stamp);\n\$at = new DateTimeImmutable(\$row['CREATED_TIME']);\n");
check('сырые date/strtotime/DateTime в business-коде ловятся',
    count(gateRawDomainPrimitiveLines($rawDomainDate)) >= 3);
$rawDomainMoney = $sample('raw_domain_money.php', "<?php\nfunction total(float \$amount): float {\n    \$value = (float)\$amount;\n    \$rate = 1.25;\n    \$converted = floatval(\$value);\n    return round(\$converted, 2);\n}\n");
check('float type/cast/literal/round в business-коде ловятся',
    count(gateRawDomainPrimitiveLines($rawDomainMoney)) >= 5);
$goodDomainFmt = $sample('good_domain_fmt.php', "<?php\n\$day = b24Day(\$row['CREATED_TIME']);\n\$range = b24Range(\$from, \$to);\n\$kopecks = money(\$row['OPPORTUNITY']);\n\$out = moneyForB24(\$kopecks);\n\$method = \$clock->date();\n");
check('готовые fmt-helper и одноимённый object method проходят',
    gateRawDomainPrimitiveLines($goodDomainFmt) === []);
$rawFrontFmt = $sample('raw_front_fmt.js', "const day = new Date(row.CREATED_TIME);\nconst sum = parseFloat(row.OPPORTUNITY).toFixed(2);\nconst ratio = 1.25;\n");
check('браузерное преобразование дат/денег ловится',
    count(gateFrontRawDomainPrimitiveLines($rawFrontFmt)) >= 3);
$duplicateUi = $sample('duplicate_ready_ui.js', "const s = document.createElement('select');\nbox.innerHTML = '<input type=\"date\">';\n");
$goodUi = $sample('ready_ui.js', "const period = ui.select(host, opts);\nconst date = ui.dateField(day, opts);\n");
check('самодельные native select/date вместо ready ui.* ловятся',
    count(gateDuplicateReadyUiLines($duplicateUi)) >= 2);
check('подключение готовых ui.select/ui.dateField проходит', gateDuplicateReadyUiLines($goodUi) === []);
$genericText = $sample('generic_dynamic_text.js', "const field = document.createElement('input');\nfield.type = secret ? 'password' : 'text';\nfield.value = String(value || '');\n");
check('обычный dynamic text input не выдаётся за дубль ready UI',
    gateDuplicateReadyUiLines($genericText) === []);
$dynamicDate = $sample('dynamic_date_ui.js', "const tag = 'in' + 'put';\nconst kind = 'da' + 'te';\nconst field = document['create' + 'Element'](tag);\nfield['type'] = kind;\n");
check('dynamic/bracket date input вместо ui.dateField ловится',
    gateDuplicateReadyUiLines($dynamicDate) !== []);
$customAction = $sample('custom_action.js', "button.onclick = async function () {\n  button.disabled = true;\n  try { await ui.api('report.php'); } finally { button.disabled = false; }\n};\n");
check('самодельная async action с ручным busy-state ловится',
    gateDuplicateReadyUiLines($customAction) !== []);
$customPeople = $sample('custom_people.js', "const search = document.createElement('div');\nsearch.contentEditable = true;\nsearch.oninput = async function () {\n  const people = await ui.api('users.php?q=' + encodeURIComponent(search.textContent));\n  people.users.forEach(function (person) { results.append(person.name); });\n};\n");
check('самодельный people picker по готовому users endpoint ловится',
    gateDuplicateReadyUiLines($customPeople) !== []);
$readyUiAliases = $sample('ready_ui_aliases.js', "const act = ui['action'];\nconst pick = ui.pickPeople;\nact(button, work);\npick(host, opts);\n");
check('alias/bracket вызовы ui.action/ui.pickPeople остаются готовым reuse',
    gateDuplicateReadyUiLines($readyUiAliases) === []);
$actionAtRender = $sample('ui_action_at_render.js', "function render() {\n  ui.action(button, work);\n}\nrender();\n");
$actionCommentDecoy = $sample('ui_action_comment_decoy.js', "// button.onclick = function () {\nconst note = 'addEventListener onclick';\nui.action(button, work);\n");
$actionInsideClick = $sample('ui_action_inside_click.js', "button.onclick = function () {\n  confirmBox().then(function (yes) {\n    if (yes) ui.action(button, work);\n  });\n};\n");
$actionNamedFromClick = $sample('ui_action_named_from_click.js', "function runAction() {\n  return ui.action(button, work);\n}\nbutton.onclick = function () { runAction(); };\n");
$actionNamedAlsoStartup = $sample('ui_action_named_also_startup.js', "function runAction() {\n  return ui.action(button, work);\n}\nbutton.onclick = function () { runAction(); };\nrunAction();\n");
$actionBracketInClick = $sample('ui_action_bracket_in_click.js', "const method = 'act' + 'ion';\nbutton.addEventListener('click', function () {\n  ui[method](button, work);\n});\n");
check('ui.action при отрисовке и comment/string decoy ловятся',
    gateUiActionOutsideUserFlowLines($actionAtRender) !== []
    && gateUiActionOutsideUserFlowLines($actionCommentDecoy) !== []);
check('ui.action внутри click и через user-only named helper проходит',
    gateUiActionOutsideUserFlowLines($actionInsideClick) === []
    && gateUiActionOutsideUserFlowLines($actionNamedFromClick) === []
    && gateUiActionOutsideUserFlowLines($actionBracketInClick) === []);
check('user helper с дополнительным startup-вызовом остаётся красным',
    gateUiActionOutsideUserFlowLines($actionNamedAlsoStartup) !== []);
$readyDecoy = $sample('ready_ui_decoy.js', "ui.action(okButton, work);\nui.pickPeople(host, opts);\ncustom.onclick = async function () {\n  custom.disabled = true;\n  try { await ui.api('report.php'); } finally { custom.disabled = false; }\n};\nsearch.oninput = async function () {\n  const people = await ui.api('users.php?q=' + search.value);\n  people.users.forEach(function (person) { results.append(person.name); });\n};\n");
check('вызов ready UI в соседней ветке не скрывает самодельные action/people',
    count(gateDuplicateReadyUiLines($readyDecoy)) >= 2);
$bracketAction = $sample('custom_action_bracket.js', "button['onclick'] = async function () {\n  button['disabled'] = true;\n  try { await ui.api('report.php'); } finally { button['disabled'] = false; }\n};\n");
$attributeAction = $sample('custom_action_attribute.js', "button.addEventListener('click', async function () {\n  button.setAttribute('disabled', 'disabled');\n  try { await ui.api('report.php'); } finally { button.removeAttribute('disabled'); }\n});\n");
check('bracket/setAttribute busy-state не скрывает самодельную async action',
    gateDuplicateReadyUiLines($bracketAction) !== []
    && gateDuplicateReadyUiLines($attributeAction) !== []);
$concatPeople = $sample('custom_people_concat.js', "const peopleEndpoint = 'users'.concat('.php');\nsearch.oninput = async function () {\n  const people = await ui.api(peopleEndpoint + '?q=' + search.value);\n  people.users.forEach(function (person) { results.append(person.name); });\n};\n");
$templatePeople = $sample('custom_people_template.js', "const peopleEndpoint = `users.php`;\nsearch.oninput = async function () {\n  const people = await ui.api(peopleEndpoint + '?q=' + search.value);\n  people.users.forEach(function (person) { results.append(person.name); });\n};\n");
$unrelatedInteractive = $sample('unrelated_interactive.js', "const reportEndpoint = 'reports'.concat('.php');\nsearch.oninput = async function () {\n  const rows = await ui.api(reportEndpoint + '?q=' + search.value);\n  rows.forEach(function (row) { results.append(row.title); });\n};\n");
$aliasAction = $sample('custom_action_alias.js', "const ev = 'on' + 'click';\nconst busy = 'dis' + 'abled';\nbutton[ev] = async () => {\n  button[busy] = true;\n  await ui.api('report.php');\n  button[busy] = false;\n};\n");
$aliasMethodAction = $sample('custom_action_method_alias.js', "button['add' + 'EventListener']('click', async () => {\n  button['set' + 'Attribute']('disabled', '');\n  await ui.api('report.php');\n  button['remove' + 'Attribute']('disabled');\n});\n");
$unrelatedActionParts = $sample('unrelated_action_parts.js', "button.onclick = () => openHelp();\nasync function load() { await ui.api('report.php'); }\nfunction lock() { other.disabled = true; }\n");
$unrelatedPeopleParts = $sample('unrelated_people_parts.js', "const source = 'users.php';\nsearch.oninput = () => filterLocal();\nfunction renderRows(rows) { rows.forEach((row) => box.append(row.title)); }\n");
check('static concat/template users endpoint не скрывает самодельный people picker',
    gateDuplicateReadyUiLines($concatPeople) !== []
    && gateDuplicateReadyUiLines($templatePeople) !== []);
check('интерактивный предметный поиск не считается people picker по одному concat',
    gateDuplicateReadyUiLines($unrelatedInteractive) === []);
check('static alias property/method не скрывает самодельную async action',
    gateDuplicateReadyUiLines($aliasAction) !== []
    && gateDuplicateReadyUiLines($aliasMethodAction) !== []);
check('несвязанные action/people признаки в разных функциях не склеиваются',
    gateDuplicateReadyUiLines($unrelatedActionParts) === []
    && gateDuplicateReadyUiLines($unrelatedPeopleParts) === []);
$namedListenerAction = $sample('custom_action_named_listener.js', "button.addEventListener('click', async function handleClick() {\n  button.disabled = true;\n  await ui.api('report.php');\n  button.disabled = false;\n});\n");
$namedPropertyAction = $sample('custom_action_named_property.js', "button.onclick = async function handleClick() {\n  button.disabled = true;\n  await ui.api('report.php');\n  button.disabled = false;\n};\n");
$namedBracketAction = $sample('custom_action_named_bracket.js', "button['add' + 'EventListener']('click', async function handleClick() {\n  button['disabled'] = true;\n  await ui.api('report.php');\n  button['disabled'] = false;\n});\n");
$unrelatedNamedAction = $sample('unrelated_named_action.js', "async function handleClick() {\n  button.disabled = true;\n  await ui.api('report.php');\n  button.disabled = false;\n}\nbutton.addEventListener('click', () => openHelp());\n");
check('named function expression не скрывает самодельную async action',
    gateDuplicateReadyUiLines($namedListenerAction) !== []
    && gateDuplicateReadyUiLines($namedPropertyAction) !== []
    && gateDuplicateReadyUiLines($namedBracketAction) !== []);
check('отдельная named function не склеивается с harmless listener',
    gateDuplicateReadyUiLines($unrelatedNamedAction) === []);
$bracketSetAttributeDate = $sample('custom_date_bracket_method.js', "const input = document.createElement('input');\nconst method = 'set' + 'Attribute';\nconst key = 'ty' + 'pe';\nconst value = 'da' + 'te';\ninput[method](key, value);\n");
$unrelatedBracketMethod = $sample('unrelated_bracket_method.js', "const input = document.createElement('input');\nconst method = runtimeMethod;\ninput[method]('aria-label', 'date');\n");
check('bracket setAttribute со static aliases не скрывает date input',
    gateDuplicateReadyUiLines($bracketSetAttributeDate) !== []);
check('dynamic unrelated bracket method не выдаётся за date input',
    gateDuplicateReadyUiLines($unrelatedBracketMethod) === []);
$objectAssignDate = $sample('custom_date_object_assign.js', "const input = document.createElement('input');\nObject.assign(input, {type: 'date'});\n");
$reflectNumber = $sample('custom_number_reflect_set.js', "const input = document.createElement('input');\nconst key = 'ty' + 'pe';\nconst value = 'num' + 'ber';\nReflect.set(input, key, value);\n");
$definePropertyDate = $sample('custom_date_define_property.js', "const input = document.createElement('input');\nObject.defineProperty(input, 'type', {value: 'date'});\n");
$definePropertiesNumber = $sample('custom_number_define_properties.js', "const input = document.createElement('input');\nconst key = 'ty' + 'pe';\nObject.defineProperties(input, {label: {value: 'amount'}, [key]: {enumerable: true, value: 'number'}});\n");
$unrelatedPropertyMutation = $sample('unrelated_property_mutation.js', "const input = document.createElement('input');\nconst model = {};\nObject.assign(model, {type: 'date'});\nReflect.set(model, 'type', 'number');\nObject.defineProperty(model, 'type', {value: 'date'});\nObject.defineProperties(model, {type: {value: 'number'}});\n");
check('property-mutation API не скрывает самодельный date/number input',
    gateDuplicateReadyUiLines($objectAssignDate) !== []
    && gateDuplicateReadyUiLines($reflectNumber) !== []
    && gateDuplicateReadyUiLines($definePropertyDate) !== []
    && gateDuplicateReadyUiLines($definePropertiesNumber) !== []);
check('property-mutation API на unrelated object не даёт ready UI false positive',
    gateDuplicateReadyUiLines($unrelatedPropertyMutation) === []);
$aliasedReadyUiTarget = $sample('custom_date_aliased_target.js', "const input = document.createElement('input');\nconst field = input;\nObject.assign(field, {type: 'date'});\n");
$bracketReadyUiApis = $sample('custom_bracket_ready_ui_apis.js', "const input = document.createElement('input');\nObject['assign'](input, {type: 'date'});\nReflect['set'](input, 'type', 'number');\n");
$laterAssignSource = $sample('custom_later_assign_source.js', "const input = document.createElement('input');\nObject.assign(input, {name: 'day'}, {type: 'date'});\n");
$crossScopeAssignShadow = $sample('ready_ui_assign_shadow.js', "function makeField() { const input = document.createElement('input'); return input; }\nfunction decorate(input) { Object.assign(input, {type: 'date'}); }\n");
$crossScopeDirectShadow = $sample('ready_ui_direct_shadow.js', "function makeField() { const input = document.createElement('input'); return input; }\nfunction decorate(input) { input.type = 'number'; }\n");
check('ready UI gate связывает alias/bracket/multi-source mutation с точным binding',
    gateDuplicateReadyUiLines($aliasedReadyUiTarget) !== []
    && count(gateDuplicateReadyUiLines($bracketReadyUiApis)) >= 2
    && gateDuplicateReadyUiLines($laterAssignSource) !== []);
check('ready UI binding не склеивается с одноимённым параметром другой функции',
    gateDuplicateReadyUiLines($crossScopeAssignShadow) === []
    && gateDuplicateReadyUiLines($crossScopeDirectShadow) === []);
$blockShadowBypass = $sample('ready_ui_block_shadow_bypass.js', "const input = document.createElement('input');\n{ const input = {}; }\ninput.type = 'date';\n");
$blockShadowFalsePositive = $sample('ready_ui_block_shadow_false_positive.js', "const input = {};\n{ const input = document.createElement('input'); }\ninput.type = 'date';\n");
$expressionArrowFalsePositive = $sample('ready_ui_expression_arrow_false_positive.js', "const input = document.createElement('input');\nconst decorate = input => Object.assign(input, {type: 'date'});\n");
check('ready UI lexical scopes покрывают block shadow и expression arrow',
    gateDuplicateReadyUiLines($blockShadowBypass) !== []
    && gateDuplicateReadyUiLines($blockShadowFalsePositive) === []
    && gateDuplicateReadyUiLines($expressionArrowFalsePositive) === []);
$assignmentAliasBypass = $sample('ready_ui_assignment_alias_bypass.js', "const input = document.createElement('input');\nlet field;\nfield = input;\nObject.assign(field, {type: 'date'});\n");
$assignmentAliasReassigned = $sample('ready_ui_assignment_alias_reassigned.js', "const input = document.createElement('input');\nlet field = input;\nfield = {};\nfield.type = 'date';\n");
$assignmentAliasUnrelated = $sample('ready_ui_assignment_alias_unrelated.js', "const input = document.createElement('input');\nlet field = {};\nfield.type = 'date';\n");
check('ready UI alias assignments переносят и инвалидируют identity',
    gateDuplicateReadyUiLines($assignmentAliasBypass) !== []
    && gateDuplicateReadyUiLines($assignmentAliasReassigned) === []
    && gateDuplicateReadyUiLines($assignmentAliasUnrelated) === []);
$objectApiAlias = $sample('ready_ui_object_api_alias.js', "const input = document.createElement('input');\nconst assign = Object.assign;\nassign(input, {type: 'date'});\n");
$reflectApiAlias = $sample('ready_ui_reflect_api_alias.js', "const input = document.createElement('input');\nconst set = Reflect.set;\nset(input, 'type', 'number');\n");
$definePropertyApiAlias = $sample('ready_ui_define_property_api_alias.js', "const input = document.createElement('input');\nconst define = Object.defineProperty;\ndefine(input, 'type', {value: 'date'});\n");
$definePropertiesApiAlias = $sample('ready_ui_define_properties_api_alias.js', "const input = document.createElement('input');\nconst defineAll = Object.defineProperties;\ndefineAll(input, {type: {value: 'number'}});\n");
$unrelatedApiAlias = $sample('ready_ui_unrelated_api_alias.js', "const input = document.createElement('input');\nconst assign = renderer.assign;\nassign(input, {type: 'date'});\n");
check('ready UI property-mutation API aliases разрешаются без broad false positive',
    gateDuplicateReadyUiLines($objectApiAlias) !== []
    && gateDuplicateReadyUiLines($reflectApiAlias) !== []
    && gateDuplicateReadyUiLines($definePropertyApiAlias) !== []
    && gateDuplicateReadyUiLines($definePropertiesApiAlias) !== []
    && gateDuplicateReadyUiLines($unrelatedApiAlias) === []);
$memberTarget = $sample('ready_ui_member_target.js', "const fields = {};\nfields.day = document.createElement('input');\nfields.day.type = 'date';\n");
$arrayTarget = $sample('ready_ui_array_target.js', "const fields = [];\nfields[0] = document.createElement('input');\nfields[0].setAttribute('type', 'number');\n");
$unrelatedMemberTarget = $sample('ready_ui_unrelated_member_target.js', "const fields = {day: {}};\nfields.day.type = 'date';\n");
$dynamicMemberTarget = $sample('ready_ui_dynamic_member_target.js', "const fields = {};\nconst key = runtimeKey();\nfields[key] = document.createElement('input');\nfields[key].type = 'date';\n");
check('ready UI member и constant-array targets имеют точную identity',
    gateDuplicateReadyUiLines($memberTarget) !== []
    && gateDuplicateReadyUiLines($arrayTarget) !== []
    && gateDuplicateReadyUiLines($unrelatedMemberTarget) === []
    && gateDuplicateReadyUiLines($dynamicMemberTarget) === []);

$renamedSettings = $sample('preferences.php', "<?php\nnamespace Project\\Prefs;\n\$ctx = apiBoot(['methods' => ['GET', 'POST']]);\nif (\$_SERVER['REQUEST_METHOD'] === 'GET') apiOk(['fields' => settingsForClient(), 'settings' => settingsEffective()]);\n\$checked = settingsValidate(apiBody()['settings'] ?? []);\napiOk(['settings' => settingsEffective()]);\n");
$renamedTask = $sample('jobs.php', "<?php\n\$ctx = apiBoot(['methods' => ['GET', 'POST']]);\nif (\$_SERVER['REQUEST_METHOD'] === 'GET') apiOk(['task' => taskForClient()]);\n\$body = apiBody();\nif ((\$body['do'] ?? '') === 'start') taskStart((int)\$ctx['uid']);\nelse taskCancel((int)\$ctx['uid']);\napiOk(['task' => taskForClient()]);\n");
$renamedUsers = $sample('staff.php', "<?php\n\$ctx = apiBoot(['methods' => ['GET']]);\n\$users = b24()->fetchAllBatched('user.get', ['FILTER' => ['NAME_SEARCH' => 'x']]);\n\$departments = b24()->fetchAllBatched('department.get', []);\napiOk(['users' => \$users, 'depts' => \$departments]);\n");
$renamedCsv = $sample('spreadsheet.php', "<?php\nfunction csvLine(array \$row): string { return implode(';', \$row); }\nfunction csvWrite(\$out, iterable \$rows): void { foreach (\$rows as \$row) fwrite(\$out, csvLine(\$row)); }\nfunction csvDownload(string \$name, iterable \$rows): void { \$out = fopen('php://output', 'wb'); csvWrite(\$out, \$rows); }\n");
$distinctReport = $sample('monthly-report.php', "<?php\n\$ctx = apiBoot(['methods' => ['GET']]);\n\$rows = b24()->fetchAll('crm.item.list', ['entityTypeId' => 2]);\napiOk(['rows' => \$rows]);\n");
$canonicalCsvReuse = $sample('report-download.php', "<?php\nrequire_once __DIR__ . '/export-csv.php';\n\$ctx = apiBoot(['methods' => ['GET']]);\ncsvDownload('report.csv', ['ID'], reportRows());\n");
check('тело ready settings endpoint ловится под произвольным именем',
    gateDuplicateReadyEndpoint($renamedSettings) && gateDuplicateReadyEndpoint($renamedTask)
    && gateDuplicateReadyEndpoint($renamedUsers) && gateDuplicateReadyEndpoint($renamedCsv));
check('отдельный report endpoint и тонкий reuse CSV не считаются копией',
    !gateDuplicateReadyEndpoint($distinctReport) && !gateDuplicateReadyEndpoint($canonicalCsvReuse));

// RED 31.08.2026: зелёный шаблон не ловил три сквозных обхода требований:
// новый экран журнала мог отдать полотно без cursor pager, project endpoint —
// заново искать сотрудника через user.get, а project CSS — умножать motion token.
$pagedJournalPhp = <<<'PHP'
<?php
$cursor = apiStr($_GET, 'cursor', ['required' => false, 'default' => '', 'max' => 32]);
const ACTIVITY_PAGE_SIZE = 25;
$filter = ['limit' => ACTIVITY_PAGE_SIZE + 1, 'cursor' => $cursor];
$found = journalQuery($filter);
$hasMore = count($found['rows']) > ACTIVITY_PAGE_SIZE;
$pageRows = array_slice($found['rows'], 0, ACTIVITY_PAGE_SIZE);
$nextCursor = $hasMore && $pageRows !== []
    ? (string)($pageRows[count($pageRows) - 1]['rid'] ?? '') : '';
apiOk(['rows' => $pageRows, 'page_size' => ACTIVITY_PAGE_SIZE, 'next_cursor' => $nextCursor]);
PHP;
$pagedJournalJs = <<<'JS'
var activityCursor = '', activityBack = [], activityNext = '';
function loadActivity() {
  return ui.api('GET', 'api/activity-log.php?cursor=' + encodeURIComponent(activityCursor))
    .then(function (data) { activityNext = String(data.next_cursor || ''); });
}
var activityPrev = document.getElementById('activity-prev');
var activityNextButton = document.getElementById('activity-next');
activityPrev.onclick = function () {
  if (!activityBack.length) return;
  activityCursor = activityBack.pop() || '';
  loadActivity();
};
activityNextButton.onclick = function () {
  if (!activityNext) return;
  activityBack.push(activityCursor);
  activityCursor = activityNext;
  loadActivity();
};
JS;
$wallJournalJs = <<<'JS'
ui.api('GET', 'api/activity-log.php').then(function (data) {
  document.querySelector('#activity tbody').innerHTML = data.rows.join('');
});
JS;

$journalBackendRoot = $dir . '/journal-backend';
$sample('journal-backend/www/api/activity-log.php', <<<'PHP'
<?php
$cursor = apiStr($_GET, 'cursor', ['default' => '']);
$found = journalQuery(['limit' => 500, 'cursor' => $cursor]);
apiOk(['rows' => $found['rows'], 'next_cursor' => '']);
PHP);
$sample('journal-backend/www/js/app.js', $pagedJournalJs);
$journalBackendProblems = function_exists('gateJournalScreenProblems')
    ? gateJournalScreenProblems($journalBackendRoot) : [];
check('образец: journalQuery с полотном/500 строк блокируется',
    function_exists('gateJournalScreenProblems') && $journalBackendProblems !== []);

$journalUiRoot = $dir . '/journal-ui';
$sample('journal-ui/www/api/activity-log.php', $pagedJournalPhp);
$sample('journal-ui/www/js/app.js', $wallJournalJs);
$journalUiProblems = function_exists('gateJournalScreenProblems')
    ? gateJournalScreenProblems($journalUiRoot) : [];
check('образец: bounded journal backend без связанных Назад/Дальше блокируется',
    function_exists('gateJournalScreenProblems') && $journalUiProblems !== []);

$journalGoodRoot = $dir . '/journal-good';
$sample('journal-good/www/api/activity-log.php', $pagedJournalPhp);
$sample('journal-good/www/js/app.js', $pagedJournalJs);
$journalGoodProblems = function_exists('gateJournalScreenProblems')
    ? gateJournalScreenProblems($journalGoodRoot) : ['predicate missing'];
check('journal reverse: limit 25 + opaque cursor + Back/Next проходит',
    function_exists('gateJournalScreenProblems') && $journalGoodProblems === [],
    implode(' · ', $journalGoodProblems));

$tableRoot = $dir . '/ordinary-table';
$sample('ordinary-table/www/api/report.php', "<?php\napiOk(['rows' => reportRows()]);\n");
$sample('ordinary-table/www/js/app.js',
    "ui.api('GET', 'api/report.php').then(function (data) {"
    . " document.querySelector('#report tbody').innerHTML = data.rows.join(''); });\n");
$tableProblems = function_exists('gateJournalScreenProblems')
    ? gateJournalScreenProblems($tableRoot) : ['predicate missing'];
check('journal reverse: обычная не-журнальная таблица без pager проходит',
    function_exists('gateJournalScreenProblems') && $tableProblems === [],
    implode(' · ', $tableProblems));

$journalSummaryRoot = $dir . '/journal-summary';
$sample('journal-summary/www/api/health.php', <<<'PHP'
<?php
$errorScan = journalQuery(['level' => 'error', 'limit' => 5]);
$lastErrors = array_slice($errorScan['rows'], 0, 5);
apiOk(['status' => 'warning', 'last_errors' => $lastErrors]);
PHP);
$sample('journal-summary/www/js/app.js',
    "ui.api('GET', 'api/health.php').then(function (data) {"
    . " document.querySelector('#health').textContent = data.status; });\n");
$journalSummaryProblems = function_exists('gateJournalScreenProblems')
    ? gateJournalScreenProblems($journalSummaryRoot) : ['predicate missing'];
check('journal reverse: bounded health-summary на 5 ошибок не требует pager',
    function_exists('gateJournalScreenProblems') && $journalSummaryProblems === [],
    implode(' · ', $journalSummaryProblems));

$employeeNameSearch = $sample('employee-name-search.php', <<<'PHP'
<?php
$query = apiStr($_GET, 'q');
$filter = ['NAME_SEARCH' => $query, 'USER_TYPE' => 'employee'];
$rows = b24()->fetchAllBatched(
    'user.get',
    ['FILTER' => $filter, 'IMAGE_RESIZE' => 'small']
);
apiOk(['users' => $rows]);
PHP);
$employeeIdLookup = $sample('employee-id-lookup.php', <<<'PHP'
<?php
$rows = b24()->call('user.get', ['FILTER' => ['ID' => $uid]]);
apiOk(['user' => $rows[0] ?? null]);
PHP);
$employeeBad = function_exists('gateDirectEmployeeNameSearchLines')
    ? gateDirectEmployeeNameSearchLines($employeeNameSearch) : [];
$employeeGood = function_exists('gateDirectEmployeeNameSearchLines')
    ? gateDirectEmployeeNameSearchLines($employeeIdLookup) : [0];
check('образец: project-owned NAME_SEARCH через user.get блокируется',
    function_exists('gateDirectEmployeeNameSearchLines') && $employeeBad !== []);
check('employee reverse: точечный user.get по ID проходит',
    function_exists('gateDirectEmployeeNameSearchLines') && $employeeGood === []);

$motionCalcCases = [
    'token * number' => '.card { transition: opacity calc(var(--motion-fast) * 2); }',
    'number * token' => '.card { transition: opacity calc(2 * var(--motion-fast)); }',
    'token / number' => '.card { transition: opacity calc(var(--motion-slow) / 2); }',
    'token + zero' => '.card { transition: opacity calc(var(--motion-fast) + 0ms); }',
    'token - zero' => '.card { transition: opacity calc(var(--motion-base) - 0ms); }',
    'nested calc' => '.card { transition: opacity min(200ms, calc((var(--motion-base)) * 1)); }',
];
foreach ($motionCalcCases as $label => $css) {
    $motionFile = $sample(
        'motion-calc-' . preg_replace('~[^a-z]+~', '-', $label) . '.css',
        $css . "\n"
    );
    $motionHits = function_exists('gateMotionCssArithmeticLines')
        ? gateMotionCssArithmeticLines($motionFile) : [];
    check('образец: motion calc arithmetic блокируется — ' . $label,
        function_exists('gateMotionCssArithmeticLines') && $motionHits !== []);
}
$motionSafe = $sample('motion-safe.css',
    "/* calc(var(--motion-fast) * 9) — только пример */\n"
    . ".card { width: calc(100% - 2rem); transition: opacity var(--motion-fast); }\n");
$motionSafeHits = function_exists('gateMotionCssArithmeticLines')
    ? gateMotionCssArithmeticLines($motionSafe) : [0];
check('motion reverse: безопасный layout calc и прямой motion token проходят',
    function_exists('gateMotionCssArithmeticLines') && $motionSafeHits === []);
$qualifiedSettings = $sample('qualified-preferences.php', "<?php\n\$ctx = \\apiBoot(['methods' => ['GET', 'POST']]);\nif (\$_SERVER['REQUEST_METHOD'] === 'GET') \\apiOk(['fields' => \\settingsForClient(), 'settings' => \\settingsEffective()]);\n\$checked = \\settingsValidate(\\apiBody()['settings'] ?? []);\n\\apiOk(['settings' => \\settingsEffective()]);\n");
$namespacedSettings = $sample('namespaced-preferences.php', "<?php\nnamespace Project\\Prefs;\n\$ctx = Ready\\apiBoot(['methods' => ['GET', 'POST']]);\nif (\$_SERVER['REQUEST_METHOD'] === 'GET') Ready\\apiOk(['fields' => Ready\\settingsForClient(), 'settings' => Ready\\settingsEffective()]);\n\$checked = Ready\\settingsValidate(Ready\\apiBody()['settings'] ?? []);\nReady\\apiOk(['settings' => Ready\\settingsEffective()]);\n");
$relativeSettings = $sample('relative-preferences.php', "<?php\nnamespace Project\\Prefs;\n\$ctx = namespace\\apiBoot(['methods' => ['GET', 'POST']]);\nif (\$_SERVER['REQUEST_METHOD'] === 'GET') namespace\\apiOk(['fields' => namespace\\settingsForClient(), 'settings' => namespace\\settingsEffective()]);\n\$checked = namespace\\settingsValidate(namespace\\apiBody()['settings'] ?? []);\nnamespace\\apiOk(['settings' => namespace\\settingsEffective()]);\n");
$relativeTask = $sample('relative-task.php', "<?php\nnamespace Project\\Jobs;\n\$ctx = namespace\\apiBoot(['methods' => ['GET', 'POST']]);\nnamespace\\taskForClient();\nnamespace\\taskStart(1);\n");
$relativeHealth = $sample('relative-health.php', "<?php\nnamespace Project\\Health;\nnamespace\\planRead();\nnamespace\\capRead();\nnamespace\\placementsRead();\n");
$relativeOps = $sample('relative-ops.php', "<?php\nnamespace Project\\Ops;\nnamespace\\appWithKeyLock('k', static function () {});\nnamespace\\auditLog('x', [], 1);\nnamespace\\storeRead('x');\nnamespace\\storeUpdate('x', static function (array \$s): array { return \$s; });\n");
check('qualified и fully-qualified имена не скрывают копию ready settings endpoint',
    gateDuplicateReadyEndpoint($qualifiedSettings)
    && gateDuplicateReadyEndpoint($namespacedSettings));
check('relative-qualified PHP names не скрывают копии ready endpoints',
    gateDuplicateReadyEndpoint($relativeSettings)
    && gateDuplicateReadyEndpoint($relativeTask)
    && gateDuplicateReadyEndpoint($relativeHealth)
    && gateDuplicateReadyEndpoint($relativeOps));
$endpointImportsOnly = $sample('endpoint-imports-only.php', "<?php\nuse Project\\settingsForClient;\nuse Project\\settingsEffective;\nclass taskForClient {}\nclass taskStart {}\n\$value = 1;\n");
check('class imports и type names без вызовов не считаются копией endpoint',
    !gateDuplicateReadyEndpoint($endpointImportsOnly));

$capRegistry = json_decode((string)file_get_contents(dirname(__DIR__) . '/docs/capabilities.json'), true);
$withoutReturnPoints = $capRegistry;
$withoutReturnPoints['capabilities'] = array_values(array_filter(
    $withoutReturnPoints['capabilities'], static fn(array $cap): bool => ($cap['id'] ?? '') !== 'return-points'
));
check('удаление любого из 20 protected capability IDs красное',
    gateCapabilityContractProblems($withoutReturnPoints, [], dirname(__DIR__)) !== []);
$swappedCapabilities = $capRegistry;
$firstCapabilityId = $swappedCapabilities['capabilities'][0]['id'];
$swappedCapabilities['capabilities'][0]['id'] = $swappedCapabilities['capabilities'][1]['id'];
$swappedCapabilities['capabilities'][1]['id'] = $firstCapabilityId;
$changedCapabilityStatus = $capRegistry;
$changedCapabilityStatus['capabilities'][0]['status'] = 'experimental';
$changedCapabilityEntry = $capRegistry;
$changedCapabilityEntry['capabilities'][0]['entry'] = ['storeRead'];
$changedCapabilityProof = $capRegistry;
$changedCapabilityProof['capabilities'][0]['tests'] = $capRegistry['capabilities'][1]['tests'];
$changedCapabilityProof['capabilities'][0]['checks'] = $capRegistry['capabilities'][1]['checks'];
$changedCapabilityPolicies = [];
foreach (['title', 'when', 'forbids'] as $policyField) {
    $changed = $capRegistry;
    $changed['capabilities'][0][$policyField] = 'Свой curl к порталу разрешён';
    $changedCapabilityPolicies[] = $changed;
}
$changedExperimentalPolicy = $capRegistry;
foreach ($changedExperimentalPolicy['capabilities'] as &$changedCapability) {
    if (($changedCapability['status'] ?? '') !== 'experimental') continue;
    $changedCapability['why_experimental'] = 'Готово без ограничений';
    break;
}
unset($changedCapability);
check('protected ID привязан к своим file/status/entry/tests/checks',
    gateCapabilityContractProblems($swappedCapabilities, [], dirname(__DIR__)) !== []
    && gateCapabilityContractProblems($changedCapabilityStatus, [], dirname(__DIR__)) !== []
    && gateCapabilityContractProblems($changedCapabilityEntry, [], dirname(__DIR__)) !== []
    && gateCapabilityContractProblems($changedCapabilityProof, [], dirname(__DIR__)) !== []);
check('protected capability policy fields имеют независимый exact contract',
    count(array_filter($changedCapabilityPolicies, static function (array $registry): bool {
        return gateCapabilityContractProblems($registry, [], dirname(__DIR__)) !== [];
    })) === count($changedCapabilityPolicies)
    && gateCapabilityContractProblems($changedExperimentalPolicy, [], dirname(__DIR__)) !== []);
$capabilityById = static function (array &$registry, string $id): ?int {
    foreach ((array)($registry['capabilities'] ?? []) as $index => $capability) {
        if (($capability['id'] ?? '') === $id) return $index;
    }
    return null;
};
$changedProjectContracts = [];
foreach ([
    ['portal-events', 'project_entry', null],
    ['portal-events', 'project_entry', ['otherHandler (www/bin/process.php)']],
    ['portal-events', 'project_note', null],
    ['portal-events', 'project_note', 'Обработчик можно написать где угодно.'],
    ['long-task', 'project_entry', null],
    ['long-task', 'project_entry', ['taskStep (www/api/task.php)']],
    ['long-task', 'project_note', null],
    ['long-task', 'project_note', 'Unknown можно считать успешным без read-back.'],
] as [$id, $field, $value]) {
    $changed = $capRegistry;
    $index = $capabilityById($changed, $id);
    if ($index !== null && $value === null) unset($changed['capabilities'][$index][$field]);
    elseif ($index !== null) $changed['capabilities'][$index][$field] = $value;
    $changedProjectContracts[] = $changed;
}
check('project hooks и пояснения входят в independent exact capability contract',
    count(array_filter($changedProjectContracts, static function (array $registry): bool {
        return gateCapabilityContractProblems($registry, [], dirname(__DIR__)) !== [];
    })) === count($changedProjectContracts));
$changedCompanionOwners = [];
foreach ((array)$capRegistry['companion_files'] as $path => $currentOwner) {
    $changed = $capRegistry;
    $changed['companion_files'][$path] = $currentOwner === 'portal-transport'
        ? 'api-entry' : 'portal-transport';
    $changedCompanionOwners[] = $changed;
}
check('каждый template companion привязан к exact capability owner',
    count(array_filter($changedCompanionOwners, static function (array $registry): bool {
        return gateCapabilityContractProblems($registry, [], dirname(__DIR__)) !== [];
    })) === count($changedCompanionOwners));
$changedReadyUiContracts = [];
foreach ((array)$capRegistry['ready_ui'] as $symbol => $ownerFile) {
    $changed = $capRegistry;
    $changed['ready_ui'][$symbol] = $ownerFile === 'www/js/ui.js'
        ? 'www/js/controls.js' : 'www/js/ui.js';
    $changedReadyUiContracts[] = $changed;
}
$changedReadyUi = $capRegistry;
unset($changedReadyUi['ready_ui']['ui.select']);
$changedReadyUi['ready_ui']['ui.fieldA11y'] = 'www/js/controls.js';
check('ready UI symbol и owner защищены independent exact contract',
    gateCapabilityContractProblems($changedReadyUi, [], dirname(__DIR__)) !== []
    && count(array_filter($changedReadyUiContracts, static function (array $registry): bool {
        return gateCapabilityContractProblems($registry, [], dirname(__DIR__)) !== [];
    })) === count($changedReadyUiContracts));

$aclFixtureRoot = $dir . '/mandatory-acl';
$aclFixtureFiles = [
    'www/api/access.php', 'www/api/acl.php', 'www/api/session.php',
    'www/api/users.php', 'www/api/http.php', 'www/api/lib.php',
    'www/template.html', 'www/js/app.js', 'docs/capabilities.json',
];
foreach ($aclFixtureFiles as $relative) {
    $target = $aclFixtureRoot . '/' . $relative;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
    copy(dirname(__DIR__) . '/' . $relative, $target);
}
$aclFixtureGood = gateMandatoryAclProblems($aclFixtureRoot);
$aclTemplate = (string)file_get_contents($aclFixtureRoot . '/www/template.html');
file_put_contents($aclFixtureRoot . '/www/template.html',
    str_replace('data-pane="access"', 'data-pane="removed-access"', $aclTemplate));
$aclFixtureBad = gateMandatoryAclProblems($aclFixtureRoot);
check('обязательный ACL-контракт проходит целиком и краснеет без управляемого экрана',
    $aclFixtureGood === []
    && in_array('нет ACL UI marker data-pane="access"', $aclFixtureBad, true),
    implode(' · ', array_merge($aclFixtureGood, $aclFixtureBad)));
foreach (array_reverse($aclFixtureFiles) as $relative) @unlink($aclFixtureRoot . '/' . $relative);
foreach (['www/api', 'www/js', 'www', 'docs'] as $relative) @rmdir($aclFixtureRoot . '/' . $relative);
@rmdir($aclFixtureRoot);
$borrowedTemplateProof = $capRegistry;
$borrowedTemplateProof['project_extensions'] = [[
    'id' => 'borrowed-report',
    'file' => 'www/api/borrowed-report.php',
    'tests' => $capRegistry['capabilities'][0]['tests'],
    'checks' => [$capRegistry['capabilities'][0]['checks'][0]],
]];
check('project extension не может присвоить template suite/check',
    gateCapabilityContractProblems($borrowedTemplateProof,
        ['www/api/borrowed-report.php'], dirname(__DIR__)) !== []);
$extensionRoot = DATA_ROOT . '/project-extension-contract';
@mkdir($extensionRoot . '/tests', 0700, true);
@mkdir($extensionRoot . '/www/api', 0700, true);
file_put_contents($extensionRoot . '/tests/monthly_report_test.php',
    "<?php\ncheck('месячный отчёт отдаёт все строки', true);\n");
file_put_contents($extensionRoot . '/www/api/monthly-report.php', "<?php\n");
file_put_contents($extensionRoot . '/www/api/monthly-report-lib.php', "<?php\n");
$withProjectExtension = $capRegistry;
$withProjectExtension['project_extensions'] = [[
    'id' => 'monthly-report',
    'file' => 'www/api/monthly-report.php',
    'companions' => ['www/api/monthly-report-lib.php'],
    'tests' => ['tests/monthly_report_test.php'],
    'checks' => ['месячный отчёт отдаёт все строки'],
]];
check('корректный project endpoint живёт отдельно от 20 template capabilities',
    gateCapabilityContractProblems($withProjectExtension,
        ['www/api/monthly-report.php'], $extensionRoot) === []);
$missingProjectCompanion = $withProjectExtension;
$missingProjectCompanion['project_extensions'][0]['companions'] = ['www/api/missing-report-lib.php'];
$templateProjectCompanion = $withProjectExtension;
$templateProjectCompanion['project_extensions'][0]['companions'] = ['www/api/b24.php'];
$duplicateProjectCompanion = $withProjectExtension;
$duplicateProjectCompanion['project_extensions'][0]['companions'] = [
    'www/api/monthly-report-lib.php', 'www/api/monthly-report-lib.php',
];
check('project companion path существует, уникален и не заимствует template API',
    gateCapabilityContractProblems($missingProjectCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($templateProjectCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($duplicateProjectCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []);
$symlinkPrimaryPath = $extensionRoot . '/www/api/borrowed-primary.php';
$symlinkCompanionPath = $extensionRoot . '/www/api/borrowed-lib.php';
$symlinkTestPath = $extensionRoot . '/tests/borrowed_report_test.php';
@symlink(dirname(__DIR__) . '/www/api/b24.php', $symlinkPrimaryPath);
@symlink(dirname(__DIR__) . '/www/api/b24.php', $symlinkCompanionPath);
@symlink($extensionRoot . '/tests/monthly_report_test.php', $symlinkTestPath);
$symlinkPrimary = $withProjectExtension;
$symlinkPrimary['project_extensions'][0]['file'] = 'www/api/borrowed-primary.php';
$symlinkCompanion = $withProjectExtension;
$symlinkCompanion['project_extensions'][0]['companions'] = ['www/api/borrowed-lib.php'];
$symlinkTest = $withProjectExtension;
$symlinkTest['project_extensions'][0]['tests'] = ['tests/borrowed_report_test.php'];
check('project extension ownership отвергает symlink primary/companion/test',
    gateCapabilityContractProblems($symlinkPrimary,
        ['www/api/borrowed-primary.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($symlinkCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($symlinkTest,
        ['www/api/monthly-report.php'], $extensionRoot) !== []);
file_put_contents($extensionRoot . '/www/api/b24.php', "<?php\n");
file_put_contents($extensionRoot . '/tests/b24_test.php',
    "<?php\ncheck('project hardlink test evidence', true);\n");
$hardlinkPrimaryPath = $extensionRoot . '/www/api/hardlink-report.php';
$hardlinkCompanionPath = $extensionRoot . '/www/api/hardlink-lib.php';
$hardlinkTestPath = $extensionRoot . '/tests/hardlink_report_test.php';
$hardlinkPeerPath = $extensionRoot . '/www/api/weekly-report.php';
$hardlinkPeerTestPath = $extensionRoot . '/tests/weekly_report_test.php';
@link($extensionRoot . '/www/api/b24.php', $hardlinkPrimaryPath);
@link($extensionRoot . '/www/api/b24.php', $hardlinkCompanionPath);
@link($extensionRoot . '/tests/b24_test.php', $hardlinkTestPath);
@link($extensionRoot . '/www/api/monthly-report.php', $hardlinkPeerPath);
file_put_contents($hardlinkPeerTestPath, "<?php\ncheck('weekly report evidence', true);\n");
$crossRoleApiPath = $extensionRoot . '/www/api/cross-role.php';
$crossRoleTestPath = $extensionRoot . '/tests/cross_role_test.php';
file_put_contents($crossRoleApiPath, "<?php\ncheck('cross role evidence', true);\n");
@link($crossRoleApiPath, $crossRoleTestPath);
$crossRoleCompanionPath = $extensionRoot . '/www/api/cross-role-lib.php';
$crossRoleCompanionTestPath = $extensionRoot . '/tests/cross_role_companion_test.php';
file_put_contents($crossRoleCompanionPath,
    "<?php\ncheck('cross role companion evidence', true);\n");
@link($crossRoleCompanionPath, $crossRoleCompanionTestPath);
$hardlinkPrimary = $withProjectExtension;
$hardlinkPrimary['project_extensions'][0]['file'] = 'www/api/hardlink-report.php';
$hardlinkCompanion = $withProjectExtension;
$hardlinkCompanion['project_extensions'][0]['companions'] = ['www/api/hardlink-lib.php'];
$hardlinkTest = $withProjectExtension;
$hardlinkTest['project_extensions'][0]['tests'] = ['tests/hardlink_report_test.php'];
$hardlinkTest['project_extensions'][0]['checks'] = ['project hardlink test evidence'];
$hardlinkPeers = $withProjectExtension;
$hardlinkPeers['project_extensions'][] = [
    'id' => 'weekly-report',
    'file' => 'www/api/weekly-report.php',
    'tests' => ['tests/weekly_report_test.php'],
    'checks' => ['weekly report evidence'],
];
check('project ownership отвергает hardlink template и sibling extension',
    gateCapabilityContractProblems($hardlinkPrimary,
        ['www/api/hardlink-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($hardlinkCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($hardlinkTest,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($hardlinkPeers,
        ['www/api/monthly-report.php', 'www/api/weekly-report.php'], $extensionRoot) !== []);
$unregisteredTemplateSource = $extensionRoot . '/www/index.php';
@mkdir(dirname($unregisteredTemplateSource), 0700, true);
file_put_contents($unregisteredTemplateSource,
    "<?php\ncheck('unregistered template proof', true);\n");
$unregisteredPrimaryPath = $extensionRoot . '/www/api/unregistered-primary.php';
$unregisteredCompanionPath = $extensionRoot . '/www/api/unregistered-lib.php';
$unregisteredTestPath = $extensionRoot . '/tests/unregistered_template_test.php';
@link($unregisteredTemplateSource, $unregisteredPrimaryPath);
@link($unregisteredTemplateSource, $unregisteredCompanionPath);
@link($unregisteredTemplateSource, $unregisteredTestPath);
$unregisteredPrimary = $withProjectExtension;
$unregisteredPrimary['project_extensions'][0]['file'] = 'www/api/unregistered-primary.php';
$unregisteredPrimary['project_extensions'][0]['companions'] = [];
$unregisteredCompanion = $withProjectExtension;
$unregisteredCompanion['project_extensions'][0]['companions'] = ['www/api/unregistered-lib.php'];
$unregisteredTest = $withProjectExtension;
$unregisteredTest['project_extensions'][0]['tests'] = ['tests/unregistered_template_test.php'];
$unregisteredTest['project_extensions'][0]['checks'] = ['unregistered template proof'];
check('project ownership отвергает hardlink unregistered template во всех ролях',
    gateCapabilityContractProblems($unregisteredPrimary,
        ['www/api/unregistered-primary.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($unregisteredCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($unregisteredTest,
        ['www/api/monthly-report.php'], $extensionRoot) !== []);
$externalIdentitySource = DATA_ROOT . '/external-project-owned.php';
file_put_contents($externalIdentitySource,
    "<?php\ncheck('external hardlink proof', true);\n");
$externalPrimaryPath = $extensionRoot . '/www/api/external-primary.php';
$externalCompanionPath = $extensionRoot . '/www/api/external-lib.php';
$externalTestPath = $extensionRoot . '/tests/external_identity_test.php';
@link($externalIdentitySource, $externalPrimaryPath);
@link($externalIdentitySource, $externalCompanionPath);
@link($externalIdentitySource, $externalTestPath);
$externalPrimary = $withProjectExtension;
$externalPrimary['project_extensions'][0]['file'] = 'www/api/external-primary.php';
$externalPrimary['project_extensions'][0]['companions'] = [];
$externalCompanion = $withProjectExtension;
$externalCompanion['project_extensions'][0]['companions'] = ['www/api/external-lib.php'];
$externalTest = $withProjectExtension;
$externalTest['project_extensions'][0]['tests'] = ['tests/external_identity_test.php'];
$externalTest['project_extensions'][0]['checks'] = ['external hardlink proof'];
check('project ownership отвергает external hardlink во всех ролях',
    gateCapabilityContractProblems($externalPrimary,
        ['www/api/external-primary.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($externalCompanion,
        ['www/api/monthly-report.php'], $extensionRoot) !== []
    && gateCapabilityContractProblems($externalTest,
        ['www/api/monthly-report.php'], $extensionRoot) !== []);
$crossRoleOwnership = $capRegistry;
$crossRoleOwnership['project_extensions'] = [[
    'id' => 'cross-role',
    'file' => 'www/api/cross-role.php',
    'tests' => ['tests/cross_role_test.php'],
    'checks' => ['cross role evidence'],
]];
check('project production file и test proof не могут иметь один inode',
    gateCapabilityContractProblems($crossRoleOwnership,
        ['www/api/cross-role.php'], $extensionRoot) !== []);
$crossRoleCompanionOwnership = $capRegistry;
$crossRoleCompanionOwnership['project_extensions'] = [[
    'id' => 'cross-role-companion',
    'file' => 'www/api/monthly-report.php',
    'companions' => ['www/api/cross-role-lib.php'],
    'tests' => ['tests/cross_role_companion_test.php'],
    'checks' => ['cross role companion evidence'],
]];
check('project companion и test proof не могут иметь один inode',
    gateCapabilityContractProblems($crossRoleCompanionOwnership,
        ['www/api/monthly-report.php'], $extensionRoot) !== []);
$sharedProjectProof = $withProjectExtension;
$sharedProjectProof['project_extensions'][] = [
    'id' => 'weekly-report',
    'file' => 'www/api/weekly-report.php',
    'tests' => ['tests/monthly_report_test.php'],
    'checks' => ['месячный отчёт отдаёт все строки'],
];
check('два project extensions не могут присвоить один suite/check',
    gateCapabilityContractProblems($sharedProjectProof,
        ['www/api/monthly-report.php', 'www/api/weekly-report.php'], $extensionRoot) !== []);
@unlink($symlinkPrimaryPath); @unlink($symlinkCompanionPath); @unlink($symlinkTestPath);
@unlink($hardlinkPrimaryPath); @unlink($hardlinkCompanionPath); @unlink($hardlinkTestPath);
@unlink($hardlinkPeerPath); @unlink($hardlinkPeerTestPath);
@unlink($unregisteredPrimaryPath); @unlink($unregisteredCompanionPath); @unlink($unregisteredTestPath);
@unlink($unregisteredTemplateSource);
@unlink($externalPrimaryPath); @unlink($externalCompanionPath); @unlink($externalTestPath);
@unlink($externalIdentitySource);
@unlink($crossRoleTestPath); @unlink($crossRoleApiPath);
@unlink($crossRoleCompanionTestPath); @unlink($crossRoleCompanionPath);
@unlink($extensionRoot . '/www/api/b24.php'); @unlink($extensionRoot . '/tests/b24_test.php');
@unlink($extensionRoot . '/tests/monthly_report_test.php');
@unlink($extensionRoot . '/www/api/monthly-report.php');
@unlink($extensionRoot . '/www/api/monthly-report-lib.php');
@rmdir($extensionRoot . '/www/api'); @rmdir($extensionRoot . '/www');
@rmdir($extensionRoot . '/tests'); @rmdir($extensionRoot);
$frontFmtText = $sample('front_fmt_text.js', "// new Date(x); parseFloat(x)\nconst help = 'toFixed(2), 1.25';\nrender(serverPreparedValue);\n");
check('текст и комментарий про форматирование не считаются выполнением',
    gateFrontRawDomainPrimitiveLines($frontFmtText) === []);

// 1.7. Credential в исходнике запрещён независимо от того, попал ли он в лог.
$phpSecretDirect = $sample('php_secret_direct.php', "<?php\n\$clientSecret = 'hardcoded-value';\ndefine('API_KEY', 'another-value');\n\$cfg = ['refresh_token' => 'refresh-value'];\n");
check('PHP variable/define/array с literal-secret ловятся',
    count(gateHardcodedCredentialLines($phpSecretDirect)) >= 3);
$phpSecretSuffix = $sample('php_secret_suffix.php', "<?php\n\$oauthToken = 'hardcoded-oauth';\n\$B24_SECRET = 'hardcoded-secret';\n\$CLIENT_SECRET_PROD = 'hardcoded-prod';\n\$secretKey = 'hardcoded-key';\n\$credentials = 'hardcoded-creds';\n\$token_ttl = 60;\n\$token_count = 2;\n");
check('camel/snake/env credential-имена ловятся без false positive на token_ttl/count',
    count(gateHardcodedCredentialLines($phpSecretSuffix)) === 5);
$phpSecretAlias = $sample('php_secret_alias.php', "<?php\n\$part = 'hidden-value';\n\$clientSecret = \$part;\n\$cfg->applicationToken = base64_decode('c2VjcmV0LXZhbHVl');\n");
check('alias и кодированный literal не прячут PHP-secret',
    count(gateHardcodedCredentialLines($phpSecretAlias)) >= 2);
$phpWebhook = $sample('php_webhook.php', "<?php\n\$url = 'https://example.bitrix24.ru/rest/1/abcdef123456/crm.item.list';\n");
check('готовый webhook URL ловится даже у нейтрального имени переменной',
    gateHardcodedCredentialLines($phpWebhook) !== []);
$phpSecretSafe = $sample('php_secret_safe.php', "<?php\n\$clientSecret = getenv('B24_CLIENT_SECRET') ?: '';\n\$token = (string)(\$auth['access_token'] ?? '');\n\$cfg = ['client_secret' => \$clientSecret, 'token_ttl' => 60];\n\$authorization = 'Bearer ' . \$token;\n");
check('env/state/runtime credential и пустой fallback разрешены',
    gateHardcodedCredentialLines($phpSecretSafe) === []);
$jsSecret = $sample('js_secret.js', "const hidden = 'literal-one';\nconst clientSecret = hidden;\nconst cfg = {\n  refresh_token: 'literal-two',\n  'api_key': 'literal-three'\n};\nobj['application_token'] = 'literal-four';\n");
check('JS variable/object/bracket/alias с secret ловятся',
    count(gateFrontHardcodedCredentialLines($jsSecret)) >= 4);
$jsSecretSafe = $sample('js_secret_safe.js', "const clientSecret = window.RUNTIME_SECRET;\nconst cfg = { refresh_token: session.refreshToken, token_ttl: 60 };\nconst authorization = 'Bearer ' + session.token;\n");
check('динамический JS credential не выдаётся за hardcode',
    gateFrontHardcodedCredentialLines($jsSecretSafe) === []);
$shellSecret = $sample('shell_secret.sh', "#!/bin/sh\nCLIENT_SECRET='hardcoded-value'\n");
$shellSecretSafe = $sample('shell_secret_safe.sh', "#!/bin/sh\nCLIENT_SECRET=\"\${B24_CLIENT_SECRET:-}\"\nTOKEN=\"\$(openssl rand -hex 16)\"\n");
check('shell literal-secret ловится, env/runtime остаются допустимыми',
    gateShellHardcodedCredentialLines($shellSecret) !== []
    && gateShellHardcodedCredentialLines($shellSecretSafe) === []);

// ── 2. Запись состояния ──────────────────────────────────────────────────────

$lockWrite = $sample('lock_write.php', "<?php\nfile_put_contents(DATA_ROOT . '/settings.php', \$data, LOCK_EX);\n");
check('запись состояния с LOCK_EX всё равно ловится', gateDirectStateWrites($lockWrite) !== []);

// Значение уехало в переменную — прежняя версия теряла след.
$varWrite = $sample('var_write.php', "<?php\n\$target = DATA_ROOT . '/report-cache.php';\n\$fh = fopen(\$target, 'c+');\nfwrite(\$fh, \$payload);\n");
check('своя запись состояния через переменную ловится', gateDirectStateWrites($varWrite) !== []);

$logWrite = $sample('log_write.php', "<?php\n\$logFile = DATA_ROOT . '/logs/today.log.php';\n\$fh = fopen(\$logFile, 'a');\nfwrite(\$fh, \$line);\n");
check('дозапись журнала нарушением не считается', gateDirectStateWrites($logWrite) === []);
check('самодельный raw-журнал вместо appLog ловится отдельно', gateRawLogWriteLines($logWrite) !== []);
$catalogWrite = $sample('catalog_write.php', "<?php\nfile_put_contents(DATA_ROOT . '/catalog.php', \$payload);\n");
check('слово catalog не выдаётся за log и прямая запись ловится', gateDirectStateWrites($catalogWrite) !== []);
$foreachLogs = $sample('foreach_logs.php', "<?php\n\$logDir = DATA_ROOT . '/cron-logs';\nforeach (glob(\$logDir . '/*.log') ?: [] as \$logFile) { unlink(\$logFile); }\n");
check('уборка файлов журнала через foreach не выдаётся за запись state', gateDirectStateWrites($foreachLogs) === []);
$foreachState = $sample('foreach_state.php', "<?php\n\$stateDir = DATA_ROOT . '/items';\nforeach (glob(\$stateDir . '/*.php') ?: [] as \$stateFile) { unlink(\$stateFile); }\n");
check('удаление обычного state через foreach по-прежнему ловится', gateDirectStateWrites($foreachState) !== []);
$atomicState = $sample('atomic_state.php', "<?php\n\$tmp = DATA_ROOT . '/state.tmp';\nfile_put_contents(\$tmp, 'x');\nrename(\$tmp, STATE_FILE);\n");
check('атомарная запись tmp→rename в state не обходит обязательный store',
    gateDirectStateWrites($atomicState) !== []);
$stateAliasDecoy = $sample('state_alias_decoy.php', "<?php\n\$target = DATA_ROOT . '/cron.lock.php';\n\$target = DATA_ROOT . '/business-state.php';\n\$fh = fopen(\$target, 'c+');\n");
check('безобидное присваивание lock-пути не прикрывает state через тот же alias',
    gateDirectStateWrites($stateAliasDecoy) !== []);
$splState = $sample('spl_state.php', "<?php\n\$state = new SplFileObject(DATA_ROOT . '/settings.php', 'w');\n\$state->fwrite('x');\n");
check('SplFileObject в write-режиме не обходит готовый store', gateDirectStateWrites($splState) !== []);
$uploadState = $sample('upload_state.php', "<?php\nmove_uploaded_file(\$tmp, DATA_ROOT . '/settings.php');\n");
check('move_uploaded_file в state не обходит готовый store', gateDirectStateWrites($uploadState) !== []);
$rawErrorLog = $sample('raw_error_log.php', "<?php\nerror_log(\$e->getMessage(), 3, DATA_ROOT . '/logs/app.php');\n");
check('error_log не обходит готовый journal и maskSecrets', gateRawLogWriteLines($rawErrorLog) !== []);

// ── 3. Свой выход в сеть ─────────────────────────────────────────────────────

$varUrl = $sample('var_url.php', "<?php\n\$url = 'https://example.com/api';\n\$r = file_get_contents(\$url);\n");
check('свой запрос по адресу из переменной ловится', gateOwnHttpLines($varUrl) !== []);

// Имя переменной ни при чём: прежняя версия смотрела на слова url/host/api в имени.
$sneakyUrl = $sample('sneaky_url.php', "<?php\n\$remote = 'https://example.com/data.json';\n\$body = file_get_contents(\$remote);\n");
check('свой запрос ловится независимо от имени переменной', gateOwnHttpLines($sneakyUrl) !== []);
$fileUrl = $sample('file_url.php', "<?php\n\$rows = file('https://example.com/private');\n");
check('сетевой wrapper file() ловится', gateOwnHttpLines($fileUrl) !== []);

$noHttp = $sample('no_http.php', "<?php\n\$b24 = b24();\n\$r = \$b24->call('crm.item.get', ['id' => 1]);\n");
check('вызов через готовый клиент нарушением не считается', gateOwnHttpLines($noHttp) === []);
$localRead = $sample('local_read.php', "<?php\n\$tpl = file_get_contents(__DIR__ . '/template.html');\n");
check('чтение своего файла по пути проекта проходит', gateOwnHttpLines($localRead) === []);
check('локальное чтение библиотекой не выдаётся за свой HTTP-ответ', gateOwnAnswerLines($localRead) === []);
$shellHttp = $sample('shell_http.php', "<?php\n\$body = shell_exec('curl https://example.com/private');\n");
check('свой HTTP через shell_exec/curl ловится', gateOwnHttpLines($shellHttp) !== []);
$remoteCopy = $sample('remote_copy.php', "<?php\ncopy('https://example.com/data.json', DATA_ROOT . '/data.tmp');\n");
check('remote copy не обходит запрет своего HTTP', gateOwnHttpLines($remoteCopy) !== []);
$soapHttp = $sample('soap_http.php', "<?php\n\$soap = new SoapClient('https://example.com/service.wsdl');\n");
check('SoapClient считается собственным сетевым клиентом', gateOwnHttpLines($soapHttp) !== []);
$rawSocket = $sample('raw_socket.php', "<?php\n\$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);\n");
check('сырой socket_create считается собственным сетевым клиентом', gateOwnHttpLines($rawSocket) !== []);
$excusedProcess = $sample('excused_process.php', "<?php\n// bx-app-ignore: локальная утилита подписывает файл без сети и проверена отдельно\n\$out = shell_exec('/usr/bin/local-signer');\n");
check('комментарий не саморазрешает process primitive без подтверждённой правки гейта',
    gateOwnHttpLines($excusedProcess) !== []);
$backtickProcess = $sample('backtick_process.php', "<?php\n\$out = `local-signer --unsafe`;\n");
check('PHP backticks считаются запрещённым process primitive',
    gateOwnHttpLines($backtickProcess) !== []);

$dynamicObjectCall = $sample('dynamic_object_call.php', "<?php\n\$helper = 'call';\n\$b24->\$helper('crm.item.delete', []);\n");
check('динамическое имя object-метода не скрывает B24 mutation',
    gateIndirectDispatchLines($dynamicObjectCall) !== []);
$staticHelperList = $sample('static_helper_list.php', "<?php\n\$helper = 'call' . 'Checked';\n\$method = 'crm.item.list';\n\$rows = b24()->{\$helper}(\$method, ['start' => 0]);\n");
$staticHelperMutation = $sample('static_helper_mutation.php', "<?php\n\$helper = 'call' . 'Checked';\n\$method = 'crm.item.update';\nb24()->{\$helper}(\$method, ['id' => 1, 'fields' => []]);\n");
$unknownPortalHelper = $sample('unknown_portal_helper.php', "<?php\n\$helper = chooseHelper();\n\$method = chooseMethod();\nb24()->{\$helper}(\$method, []);\n");
$unrelatedDynamicHelper = $sample('unrelated_dynamic_helper.php', "<?php\n\$helper = chooseRenderer();\n\$widget->{\$helper}(\$value);\n");
$aliasedPortalList = $sample('aliased_portal_list.php', "<?php\n\$transport = b24();\n\$reader = \$transport;\n\$helper = chooseHelper();\n\$reader->{\$helper}('crm.item.list', []);\n");
$aliasedPortalMutation = $sample('aliased_portal_mutation.php', "<?php\n\$transport = b24();\n\$writer = \$transport;\n\$helper = chooseHelper();\n\$writer->{\$helper}('crm.item.update', ['id' => 1, 'fields' => []]);\n");
$constructedPortalList = $sample('constructed_portal_list.php', "<?php\n\$client = new B24('id', 'secret');\n\$client->call('crm.item.list', ['start' => 0]);\n");
$constructedPortalMutation = $sample('constructed_portal_mutation.php', "<?php\n\$client = new B24('id', 'secret');\n\$client->call('crm.item.add', ['fields' => []]);\n");
$constructedPortalRetry = $sample('constructed_portal_retry.php', "<?php\n\$client = new B24('id', 'secret');\n\$client->call('crm.item.add', ['fields' => []], true);\n");
$sameBranchPortalAlias = $sample('same_branch_portal_alias.php', "<?php\nif (enabled()) {\n  \$transport = b24();\n  \$alias = \$transport;\n  \$helper = chooseHelper();\n  \$alias->{\$helper}('crm.item.list', []);\n}\n");
$unrelatedNamedReceivers = $sample('unrelated_named_receivers.php', "<?php\n\$helper = chooseRenderer();\n\$client = widget();\n\$portal = widget();\n\$b24 = widget();\n\$client->{\$helper}(\$value);\n\$portal->{\$helper}(\$value);\n\$b24->{\$helper}(\$value);\n");
$crossFunctionReceiverDecoy = $sample('cross_function_receiver_decoy.php', "<?php\nfunction makeTransport() { \$client = b24(); return \$client; }\nfunction renderWidget() { \$client = widget(); \$helper = chooseRenderer(); \$client->{\$helper}(\$value); }\n");
$ambiguousReceiver = $sample('ambiguous_receiver.php', "<?php\n\$client = widget();\nif (enabled()) { \$client = b24(); }\n\$helper = chooseRenderer();\n\$client->{\$helper}(\$value);\n");
$overwrittenReceiver = $sample('overwritten_receiver.php', "<?php\n\$client = b24();\n\$client = widget();\n\$helper = chooseRenderer();\n\$client->{\$helper}(\$value);\n");
$unrelatedLiteralPortalHelper = $sample('unrelated_literal_portal_helper.php', "<?php\n\$client = widget();\n\$client->callChecked('crm.item.list', []);\n");
$unrelatedStaticPortalHelper = $sample('unrelated_static_portal_helper.php', "<?php\n\$client = widget();\n\$helper = 'call' . 'Checked';\n\$client->{\$helper}('crm.item.list', []);\n");
$unrelatedBatchPortalHelper = $sample('unrelated_batch_portal_helper.php', "<?php\n\$client = widget();\n\$client->batch(['rows' => ['method' => 'crm.item.list', 'params' => []]]);\n");
$typedPortalReceiver = $sample('typed_portal_receiver.php', "<?php\nfunction loadRows(B24 \$client): array {\n  return \$client->callChecked('crm.item.list', []);\n}\n");
$qualifiedTypedPortalReceiver = $sample('qualified_typed_portal_receiver.php', "<?php\nfunction updateRow(\\B24 \$client): array {\n  \$alias = \$client;\n  return \$alias->callChecked('crm.item.update', ['id' => 1, 'fields' => []]);\n}\n");
$importedTypedPortalReceiver = $sample('imported_typed_portal_receiver.php', "<?php\nuse Vendor\\B24 as PortalClient;\nfunction importedRows(PortalClient \$client): array {\n  return \$client->callChecked('crm.item.list', []);\n}\nfunction importedUpdate(PortalClient \$client): array {\n  return \$client->callChecked('crm.item.update', ['id' => 1, 'fields' => []]);\n}\n");
$arrowTypedPortalReceiver = $sample('arrow_typed_portal_receiver.php', "<?php\n\$load = static fn(B24 \$client): array => \$client->callChecked('crm.item.list', []);\n");
$propertyTypedPortalReceiver = $sample('property_typed_portal_receiver.php', "<?php\nclass PortalReport {\n  private B24 \$client;\n  public function rows(): array { return \$this->client->callChecked('crm.item.list', []); }\n}\n");
$constructorPortalReceiver = $sample('constructor_portal_receiver.php', "<?php\nclass PortalReport {\n  private \$client;\n  public function __construct(B24 \$client) { \$this->client = \$client; }\n  public function rows(): array { return \$this->client->callChecked('crm.item.list', []); }\n}\n");
$constructorFactoryPortalReceiver = $sample('constructor_factory_portal_receiver.php', "<?php\nclass PortalReport {\n  private \$client;\n  public function __construct() { \$this->client = b24(); }\n  public function rows(): array { return \$this->client->callChecked('crm.item.list', []); }\n}\n");
$arrowShadowedPortalReceiver = $sample('arrow_shadowed_portal_receiver.php', "<?php\n\$client = b24();\n\$render = static fn(Widget \$client): array => \$client->callChecked('crm.item.list', []);\n");
$groupedPortalReceiver = $sample('grouped_portal_receiver.php', "<?php\nnamespace App;\nuse Vendor\\{B24 as PortalClient, Widget};\nfunction groupedRows(PortalClient \$client): array { return \$client->call('crm.item.list', []); }\nfunction groupedUpdate(PortalClient \$client): array { return \$client->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => []]); }\n");
$groupedUnrelatedReceiver = $sample('grouped_unrelated_receiver.php', "<?php\nnamespace App;\nuse Vendor\\{Widget as PortalClient, B24 as OtherClient};\nfunction renderRows(PortalClient \$client): array { return \$client->call('crm.item.list', []); }\n");
$groupedFunctionConstReceiver = $sample('grouped_function_const_receiver.php', "<?php\nnamespace App;\nuse Vendor\\{function B24 as helper, const B24 as FLAG, Widget as PortalClient};\nfunction renderRows(PortalClient \$client): array { return \$client->call('crm.item.list', []); }\n");
$sameMethodPropertyReceiver = $sample('same_method_property_receiver.php', "<?php\nfinal class Loader {\n  private \$client;\n  public function run(): array {\n    \$this->client = b24();\n    return \$this->client->call('crm.item.list', []);\n  }\n}\n");
$sameMethodPropertyAlias = $sample('same_method_property_alias.php', "<?php\nfinal class Loader {\n  private \$client;\n  public function run(): array {\n    \$portal = b24();\n    \$this->client = \$portal;\n    return \$this->client->call('crm.item.list', []);\n  }\n}\n");
$overwrittenPropertyReceiver = $sample('overwritten_property_receiver.php', "<?php\nfinal class Loader {\n  private \$client;\n  public function __construct(B24 \$client) { \$this->client = \$client; }\n  public function run(): array {\n    \$this->client = widgetClient();\n    return \$this->client->call('crm.item.list', []);\n  }\n}\n");
check('static dynamic B24 helper поступает в pagination и mutation gates',
    gateUnpagedLists($staticHelperList) !== []
    && gateUnprotectedMutations($staticHelperMutation) !== []);
// Файл состояния ПРОЕКТА закрывается штатным списком APP_PURGE_EXTRA: он уходит
// и в уборку после удаления, и в план переноса на другой портал. Пока проверка
// искала имя константы только в ядре, пройти её можно было единственным
// способом — правкой ядра, то есть отказом от механизма, заведённого ровно для
// этого случая. Контрпример обязателен: послабление не должно пропускать файл,
// который в списке НЕ назван.
$envSOborkoy = "<?php\n// пример: define('APP_PURGE_EXTRA', [DATA_ROOT . '/primer.php']);\n"
    . "define('MOY_FILE', DATA_ROOT . '/moy.php');\n"
    . "if (!defined('APP_PURGE_EXTRA')) { define('APP_PURGE_EXTRA', [MOY_FILE]); }\n";
$envBezUborki = "<?php\ndefine('MOY_FILE', DATA_ROOT . '/moy.php');\n"
    . "define('APP_PURGE_EXTRA', []);\n";
$yadroBezUpominaniya = '<?php $files = [SESSIONS_FILE]; $targets = [SESSIONS_FILE => []];';
check('файл состояния проекта закрывается штатным списком уборки, а забытый — краснеет',
    gateStateFilesWithoutCleanup($envSOborkoy, $yadroBezUpominaniya, []) === []
    && gateStateFilesWithoutCleanup($envBezUborki, $yadroBezUpominaniya, []) === ['MOY_FILE']);

// Список без страниц: требовать fetchAll() у ответа без курсора значит толкать
// проект на обход гейта. Реестр поимённый, поэтому соседний метод той же семьи
// обязан остаться красным.
$noPagesList = $sample('no_pages_list.php', "<?php\n\$codes = \$b24->callChecked('bizproc.activity.list');\n");
check('список действий БП не требует пагинации — страниц у него нет',
    gateUnpagedLists($noPagesList) === []);
$pagedSibling = $sample('paged_sibling_list.php', "<?php\n\$rows = \$b24->callChecked('bizproc.workflow.template.list');\n");
check('соседний список той же семьи по-прежнему требует пагинации',
    gateUnpagedLists($pagedSibling) !== []);
check('unresolved portal helper fail-closed, unrelated object остаётся вне portal gate',
    gatePortalCallDetails($unknownPortalHelper) !== []
    && gatePortalCallDetails($unrelatedDynamicHelper) === []);
check('portal receiver provenance следует alias chain без name-based false positive',
    gateUnpagedLists($aliasedPortalList) !== []
    && gateUnprotectedMutations($aliasedPortalMutation) !== []
    && gateUnpagedLists($sameBranchPortalAlias) !== []
    && gatePortalCallDetails($unrelatedNamedReceivers) === []
    && gatePortalCallDetails($crossFunctionReceiverDecoy) === []
    && gatePortalCallDetails($ambiguousReceiver) === []
    && gatePortalCallDetails($overwrittenReceiver) === []);
check('new B24 receiver видим pagination, mutation и retry gates',
    gateUnpagedLists($constructedPortalList) !== []
    && gateUnprotectedMutations($constructedPortalMutation) !== []
    && gateUnsafeExplicitMutationRetry($constructedPortalRetry) !== []);
check('known portal-helper на unrelated receiver не даёт semantic false positive',
    gatePortalCallDetails($unrelatedLiteralPortalHelper) === []
    && gatePortalCallDetails($unrelatedStaticPortalHelper) === []
    && gatePortalCallDetails($unrelatedBatchPortalHelper) === []);
check('B24-typed receiver и его direct alias остаются видимы semantic gates',
    gateUnpagedLists($typedPortalReceiver) !== []
    && gateUnprotectedMutations($qualifiedTypedPortalReceiver) !== []);
check('imported alias, arrow и property B24 receivers видимы semantic gates',
    gateUnpagedLists($importedTypedPortalReceiver) !== []
    && gateUnprotectedMutations($importedTypedPortalReceiver) !== []
    && gateUnpagedLists($arrowTypedPortalReceiver) !== []
    && gateUnpagedLists($propertyTypedPortalReceiver) !== []
    && gateUnpagedLists($constructorPortalReceiver) !== []
    && gateUnpagedLists($constructorFactoryPortalReceiver) !== []);
check('typed arrow shadow не наследует provenance одноимённого outer receiver',
    gatePortalCallDetails($arrowShadowedPortalReceiver) === []);
check('grouped B24 import видим list/mutation gates без unrelated false positive',
    gateUnpagedLists($groupedPortalReceiver) !== []
    && gateUnprotectedMutations($groupedPortalReceiver) !== []
    && gatePortalCallDetails($groupedUnrelatedReceiver) === []
    && gatePortalCallDetails($groupedFunctionConstReceiver) === []);
check('property receiver provenance следует same-method assignment и overwrite',
    gateUnpagedLists($sameMethodPropertyReceiver) !== []
    && gateUnpagedLists($sameMethodPropertyAlias) !== []
    && gatePortalCallDetails($overwrittenPropertyReceiver) === []
    && gateUnpagedLists($constructorPortalReceiver) !== []);
$bracedObjectCall = $sample('braced_object_call.php', "<?php\n\$b24->{'call'}('crm.item.delete', []);\n");
check('braced dynamic dispatch ловится', gateIndirectDispatchLines($bracedObjectCall) !== []);
$callUserFunc = $sample('call_user_func.php', "<?php\ncall_user_func([\$b24, 'call'], 'crm.item.delete', []);\n");
check('call_user_func с B24 helper ловится', gateIndirectDispatchLines($callUserFunc) !== []);
$variableFunction = $sample('variable_function.php', "<?php\n\$reader = 'file_get_contents';\n\$reader('https://example.com/private');\n");
check('variable-function не скрывает сетевой или файловый sink',
    gateIndirectDispatchLines($variableFunction) !== []);
$arrayFunction = $sample('array_function.php', "<?php\n\$handlers = ['write' => 'file_put_contents'];\n\$handlers['write'](DATA_ROOT . '/state.php', 'x');\n");
check('callable из элемента массива не скрывает sink или готовый helper',
    gateIndirectDispatchLines($arrayFunction) !== []);
$evalDispatch = $sample('eval_dispatch.php', "<?php\n\$payload = base64_decode('ZWNobyAxOw==');\neval(\$payload);\n");
check('eval не скрывает динамически собранный sink или готовый helper',
    gateIndirectDispatchLines($evalDispatch) !== []);
$inlineClosureCall = $sample('inline_closure_call.php', "<?php\n\$format = static fn(\$x) => (string)\$x;\n\$format(1);\n");
check('видимая inline-closure не считается скрытым dispatch',
    gateIndirectDispatchLines($inlineClosureCall) === []);
$portalViaHttpJson = $sample('portal_httpjson.php', "<?php\nhttpJson('https://example.bitrix24.ru/rest/crm.item.delete.json', ['id' => 1]);\n");
check('generic httpJson не обходит готовый B24-клиент',
    gateGenericPortalHttpLines($portalViaHttpJson) !== []);
$externalHttpJson = $sample('external_httpjson.php', "<?php\nhttpJson('https://api.example.com/v1/items', []);\n");
check('literal стороннего API через httpJson остаётся разрешён',
    gateGenericPortalHttpLines($externalHttpJson) === []);

// ── 4. Свой формат ответа ────────────────────────────────────────────────────

$phpOutput = $sample('php_output.php', "<?php\nfile_put_contents('php://output', '{}');\n");
check('запись в php://output не обходит единый формат ответа',
    gateOwnAnswerLines($phpOutput) !== []);
$phpOutputHandle = $sample('php_output_handle.php', "<?php\n\$out = fopen('php://output', 'w');\nfwrite(\$out, '{}');\n");
check('php://output через файловый handle тоже ловится',
    gateOwnAnswerLines($phpOutputHandle) !== []);

$printAnswer = $sample('print_answer.php', "<?php\nprint json_encode(['ok' => true]);\n");
check('свой ответ через print ловится', gateOwnAnswerLines($printAnswer) !== []);
$dieAnswer = $sample('die_answer.php', "<?php\ndie(json_encode(['ok' => false]));\n");
check('свой ответ через die ловится', gateOwnAnswerLines($dieAnswer) !== []);
$exitAnswer = $sample('exit_answer.php', "<?php\n\$json = json_encode(['ok' => false]);\nexit \$json;\n");
check('свой ответ через exit без скобок ловится', gateOwnAnswerLines($exitAnswer) !== []);
$readfileAnswer = $sample('readfile_answer.php', "<?php\nreadfile(__DIR__ . '/payload.json');\n");
check('локальный readfile как ответ эндпоинта ловится', gateOwnAnswerLines($readfileAnswer) !== []);
$varAnswer = $sample('var_answer.php', "<?php\n\$answer = json_encode(['ok' => true]);\necho \$answer;\n");
check('свой ответ через промежуточную переменную ловится', gateOwnAnswerLines($varAnswer) !== []);
$okAnswer = $sample('ok_answer.php', "<?php\napiOk(['rows' => \$rows]);\n");
check('ответ через apiOk нарушением не считается', gateOwnAnswerLines($okAnswer) === []);
$rawInput = $sample('raw_input.php', "<?php\n\$raw = file_get_contents('php://input');\n\$body = json_decode(\$raw, true);\n");
check('ручной разбор php://input не обходит apiBody', gateRawInputLines($rawInput) !== []);
$postInput = $sample('post_input.php', "<?php\n\$value = \$_POST['value'] ?? null;\n");
check('прямой POST не обходит apiBody/валидаторы', gateRawInputLines($postInput) !== []);

// ── 5. Браузерные диалоги ────────────────────────────────────────────────────

$winDialog = $sample('win_dialog.js', "if (window.confirm('точно?')) { doIt(); }\n");
check('window.confirm ловится', gateBrowserDialog($winDialog));
$globalDialog = $sample('global_dialog.js', "globalThis.alert('готово');\n");
check('globalThis.alert ловится', gateBrowserDialog($globalDialog));
$optionalDialog = $sample('optional_dialog.js', "window?.confirm('точно?');\n");
check('optional chaining не скрывает window.confirm', gateBrowserDialog($optionalDialog));
$computedDialog = $sample('computed_dialog.js', "window['con' + 'firm']('точно?');\n");
check('склеенное имя не скрывает window.confirm', gateBrowserDialog($computedDialog));
$okDialog = $sample('ok_dialog.js', "ui.confirmBox({ title: 'точно?' }).then(function (yes) {});\n");
check('готовый confirmBox нарушением не считается', !gateBrowserDialog($okDialog));
$rawFetch = $sample('raw_fetch.js', "fetch('api/items.php').then(function (r) { return r.json(); });\n");
check('сырой fetch вне ui.js не обходит ui.api', gateFrontTransportLines($rawFetch) !== []);
$callFetch = $sample('call_fetch.js', "fetch.call(globalThis, '/api/items.php');\n");
check('fetch.call не обходит ui.api', gateFrontTransportLines($callFetch) !== []);
$optionalBareFetch = $sample('optional_bare_fetch.js', "fetch?.('/api/items.php');\n");
check('optional-вызов голого fetch не обходит ui.api', gateFrontTransportLines($optionalBareFetch) !== []);
$parenthesizedFetch = $sample('parenthesized_fetch.js', "(fetch)('/api/items.php');\n");
check('fetch в скобках не обходит ui.api', gateFrontTransportLines($parenthesizedFetch) !== []);
$defaultViewFetch = $sample('default_view_fetch.js', "document.defaultView.fetch('/api/items.php');\n");
check('document.defaultView.fetch не обходит ui.api', gateFrontTransportLines($defaultViewFetch) !== []);
$reflectFetch = $sample('reflect_fetch.js', "Reflect.apply(fetch, globalThis, ['/api/items.php']);\n");
check('Reflect.apply(fetch) не обходит ui.api', gateFrontTransportLines($reflectFetch) !== []);
$boundFetch = $sample('bound_fetch.js', "const r = globalThis.fetch.bind(globalThis);\nr('/api/items.php');\n");
check('fetch через bind/alias не обходит ui.api', gateFrontTransportLines($boundFetch) !== []);
$assignedFetch = $sample('assigned_fetch.js', "let request;\nrequest = fetch;\nrequest('/api/items.php');\n");
check('fetch через отдельное присваивание alias не обходит ui.api',
    gateFrontTransportLines($assignedFetch) !== []);
$multilineFetchAlias = $sample('multiline_fetch_alias.js', "const request = (\n    fetch\n);\nrequest('/api/items.php');\n");
check('многострочный parenthesized alias fetch не обходит ui.api',
    gateFrontTransportLines($multilineFetchAlias) !== []);
$objectFetchAlias = $sample('object_fetch_alias.js', "const request = client.fetch;\nrequest('/api/items.php');\n");
check('одноимённый метод обычного объекта не считается browser fetch',
    gateFrontTransportLines($objectFetchAlias) === []);
$templateFetch = $sample('template_fetch.js', "const text = `result: \${fetch('/api/items.php')}`;\n");
check('исполняемая интерполяция template literal не скрывает fetch',
    gateFrontTransportLines($templateFetch) !== []);
$indexedFetch = $sample('indexed_fetch.js', "globalThis['fetch']('/api/items.php');\n");
check('bracket-доступ к globalThis.fetch не обходит ui.api', gateFrontTransportLines($indexedFetch) !== []);
$aliasedConfirm = $sample('aliased_confirm.js', "const ask = window.confirm;\nask('точно?');\n");
check('window.confirm через alias всё равно ловится', gateBrowserDialog($aliasedConfirm));
$callConfirm = $sample('call_confirm.js', "confirm.call(globalThis, 'точно?');\n");
$optionalBareConfirm = $sample('optional_bare_confirm.js', "confirm?.('точно?');\n");
$parenthesizedConfirm = $sample('parenthesized_confirm.js', "(confirm)('точно?');\n");
check('call/optional/parenthesized формы confirm всё равно ловятся',
    gateBrowserDialog($callConfirm) && gateBrowserDialog($optionalBareConfirm)
    && gateBrowserDialog($parenthesizedConfirm));
$objectConfirmAlias = $sample('object_confirm_alias.js', "const ask = form.confirm;\nask('точно?');\n");
check('одноимённый метод обычного объекта не считается browser confirm',
    !gateBrowserDialog($objectConfirmAlias));
$commentOnlyFront = $sample('comment_only_front.js', "// never call fetch('/api/x') or confirm('x')\nconst label = 'fetch() confirm()';\n");
check('комментарий и строка с именами browser API не дают ложного запрета',
    gateFrontTransportLines($commentOnlyFront) === [] && !gateBrowserDialog($commentOnlyFront));
$arrayLabels = $sample('array_labels.js', "const labels = ['fetch()', 'confirm()'];\n");
check('строки browser API внутри массива тоже не дают ложного запрета',
    gateFrontTransportLines($arrayLabels) === [] && !gateBrowserDialog($arrayLabels));
$safeBracketCall = $sample('safe_bracket_call.js', "window['scrollTo'](0, 0);\n");
check('известное безопасное bracket-свойство browser-global не запрещается',
    gateFrontTransportLines($safeBracketCall) === [] && !gateBrowserDialog($safeBracketCall));
$inlineFetch = $sample('inline_fetch.html', "<button onclick=\"globalThis['fetch']('/api/x')\">x</button>\n");
check('inline-handler в HTML не становится слепой зоной transport-гейта',
    gateFrontTransportLines($inlineFetch) !== []);
$directBx = $sample('direct_bx.js', "BX24.callMethod('crm.item.delete', { id: 1 }, function () {});\n");
check('прямой BX24.callMethod не обходит серверный b24()', gateFrontTransportLines($directBx) !== []);

// ── 6. Сырой текст ошибки в журнале ──────────────────────────────────────────

$multiline = $sample('multiline_log.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    logAppError('сбой: '\n        . \$e->getMessage());\n}\n");
check('сырая ошибка в журнале ловится и в многострочном вызове', gateRawExceptionLines($multiline) !== []);
$viaVar = $sample('var_log.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    \$raw = \$e->getMessage();\n    appLog('error', 'сбой: ' . \$raw);\n}\n");
check('сырая ошибка через переменную ловится', gateRawExceptionLines($viaVar) !== []);
$viaAlias = $sample('alias_log.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    \$raw = \$e->getMessage();\n    \$alias = \$raw;\n    appLog('error', 'сбой', ['text' => \$alias]);\n}\n");
check('сырая ошибка через цепочку переменных ловится', gateRawExceptionLines($viaAlias) !== []);
$masked = $sample('masked_log.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    logAppError('сбой: ' . maskSecrets(\$e->getMessage()));\n}\n");
check('замаскированная ошибка проходит', gateRawExceptionLines($masked) === []);
$maskDecoy = $sample('mask_decoy.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    appLog('error', \$e->getMessage(), ['decoy' => maskSecrets('safe')]);\n}\n");
check('посторонний maskSecrets-приманка не прикрывает сырые данные исключения',
    gateRawExceptionLines($maskDecoy) !== []);
$maskTextDecoy = $sample('mask_text_decoy.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    appLog('буквы maskSecrets(safe)', \$e->getMessage());\n}\n");
check('текстовое упоминание maskSecrets не считается вызовом-обёрткой',
    gateRawExceptionLines($maskTextDecoy) !== []);
$maskDecoyVar = $sample('mask_decoy_var.php', "<?php\ntry { x(); } catch (Throwable \$e) {\n    \$raw = \$e->getMessage() . maskSecrets('safe');\n    appLog('error', \$raw);\n}\n");
check('maskSecrets-приманка не очищает tainted-переменную', gateRawExceptionLines($maskDecoyVar) !== []);

// ── 7. Исключения ────────────────────────────────────────────────────────────

$fakeExcuse = $sample('fake_excuse.php', "<?php\n\$s = 'bx-app-ignore: тут длинная строка про причину';\n\$fh = fopen(DATA_ROOT . '/mine.php', 'w');\n");
check('исключение в строковом литерале не действует', gateDirectStateWrites($fakeExcuse) !== []);
$emptyExcuse = $sample('empty_excuse.php', "<?php\n// bx-app-ignore: ок\n\$fh = fopen(DATA_ROOT . '/mine.php', 'w');\n");
check('исключение без внятной причины не действует', gateDirectStateWrites($emptyExcuse) !== []);
$realExcuse = $sample('real_excuse.php', "<?php\n// bx-app-ignore: стороннее SDK пишет свой кэш, обёртка сломала бы подпись\n\$fh = fopen(DATA_ROOT . '/sdk-cache.php', 'w');\n");
check('даже внятная причина в business-коде не отключает готовый store',
    gateDirectStateWrites($realExcuse) !== []);
$farExcuse = $sample('far_excuse.php', "<?php\n// bx-app-ignore: стороннее SDK пишет свой кэш, обёртка сломала бы подпись\n\$harmless = 1;\n\$fh = fopen(DATA_ROOT . '/sdk-cache.php', 'w');\n");
check('исключение через одну промежуточную строку уже не действует',
    gateDirectStateWrites($farExcuse) !== []);

// Одно исключение в шапке файла больше не снимает проверки со всего файла:
// именно так повторное ревью провело составной эндпоинт с шестью нарушениями.
$fileWide = $sample('file_wide_excuse.php', "<?php\n// bx-app-ignore: тут особый случай, объясняю подробно и убедительно\n\$x = 1;\n\$y = 2;\n\$z = 3;\n\$fh = fopen(DATA_ROOT . '/mine.php', 'w');\n");
check('исключение в шапке не снимает проверки со всего файла', gateDirectStateWrites($fileWide) !== []);

// ── 8. Положительные требования: готовое обязано использоваться ──────────────

$rawMutation = $sample('raw_mutation.php', "<?php\n\$b24 = b24();\n\$b24->call('crm.item.update', ['entityTypeId' => 2, 'id' => \$id, 'fields' => \$f]);\n");
check('мутация портала без runOnce/auditLog ловится', gateUnprotectedMutations($rawMutation) !== []);
$verbMutations = [
    'bizproc.workflow.start', 'tasks.task.complete',
    'disk.folder.uploadfile', 'tasks.task.delegate',
];
foreach ($verbMutations as $at => $method) {
    $verbMutation = $sample('verb_mutation_' . $at . '.php',
        "<?php\n\$b24->callChecked('" . $method . "', []);\n");
    check("неизвестный mutation-глагол $method трактуется fail-closed",
        gateUnprotectedMutations($verbMutation) !== []);
}
$readOnlyGet = $sample('read_only_get.php', "<?php\n\$item = \$b24->callChecked('crm.item.get', ['id' => 1]);\n");
check('доказуемый read-only crm.item.get не требует runOnce/auditLog',
    gateUnprotectedMutations($readOnlyGet) === []);
$readOnlyScope = $sample('read_only_scope.php', "<?php\n\$scope = \$b24->callChecked('scope', []);\n");
check('точный системный scope не требует mutation-цепочки',
    gateUnprotectedMutations($readOnlyScope) === []);
$scopeLookalike = $sample('scope_lookalike.php', "<?php\n\$scope = \$b24->callChecked('custom.scope', []);\n");
check('похожий на scope неизвестный метод остаётся fail-closed',
    gateUnprotectedMutations($scopeLookalike) !== []);
check('назначение core-файла отличает exact метод от семейства',
    gatePortalMethodAllowedByScope('scope', ['scope'])
    && !gatePortalMethodAllowedByScope('scope.update', ['scope'])
    && gatePortalMethodAllowedByScope('crm.item.get', ['crm.'])
    && !gatePortalMethodAllowedByScope('crmitem.get', ['crm.']));
$auditOnlyMutation = $sample('audit_only_mutation.php', "<?php\nrunOnce('deal-' . \$id, function () use (\$b24, \$id, \$f) {\n    \$r = \$b24->callChecked('crm.item.update', ['entityTypeId' => 2, 'id' => \$id, 'fields' => \$f]);\n    auditLog('deal.update', ['id' => \$id], \$uid);\n    return \$r;\n});\n");
check('runOnce и auditLog без read-back не выдают мутацию за проверенную',
    gateUnprotectedMutations($auditOnlyMutation) !== []);
$goodMutation = $sample('good_mutation.php', "<?php\nrunOnce('deal-' . \$id, function () use (\$b24, \$id, \$f) {\n    \$r = \$b24->callChecked('crm.item.update', ['entityTypeId' => 2, 'id' => \$id, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$f);\n    auditLog('deal.update', ['id' => \$id, 'actual' => \$actual], \$uid);\n    return \$r;\n});\n");
check('мутация через runOnce с read-back и auditLog проходит', gateUnprotectedMutations($goodMutation) === []);
$calledSnapshots = $sample('called_snapshots.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$b24->callChecked('crm.item.update', ['id' => nextId(), 'fields' => makeFields()]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => nextId()], makeFields());\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('два одинаково написанных вызова nextId/makeFields не считаются одним snapshot',
    gateUnprotectedMutations($calledSnapshots) !== []);
$savedSnapshots = $sample('saved_snapshots.php', "<?php\n\$id = nextId(); \$fields = makeFields();\nrunOnce('x-' . \$id, function () use (\$b24, \$id, \$fields) {\n    \$b24->callChecked('crm.item.update', ['id' => \$id, 'fields' => \$fields]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => \$id], \$fields);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('однократно вычисленные ID/fields проходят как стабильный snapshot',
    gateUnprotectedMutations($savedSnapshots) === []);
$rawCheckedDecoy = $sample('raw_checked_decoy.php', "<?php\nrunOnce('deal', function () use (\$b24, \$f) {\n    \$r = \$b24->call('crm.item.update', ['id' => 1, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => 1], \$f);\n    auditLog('deal.update', ['actual' => \$actual]);\n    return \$r;\n});\n");
check('сырой call не выдаёт error-ответ портала за защищённую мутацию',
    gateUnprotectedMutations($rawCheckedDecoy) !== []);
$wrongEntityId = $sample('wrong_entity_id.php', "<?php\nrunOnce('x', function () use (\$b24, \$f) {\n    \$b24->callChecked('crm.item.update', ['entityTypeId' => 2, 'id' => 1, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => 999], \$f);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('read-back другой записи того же семейства не прикрывает мутацию',
    gateUnprotectedMutations($wrongEntityId) !== []);
$wrongEntityType = $sample('wrong_entity_type.php', "<?php\nrunOnce('x', function () use (\$b24, \$f) {\n    \$b24->callChecked('crm.item.update', ['entityTypeId' => 2, 'id' => 1, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 7, 'id' => 1], \$f);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('read-back другого entityTypeId не прикрывает мутацию',
    gateUnprotectedMutations($wrongEntityType) !== []);
$uncheckedFields = $sample('unchecked_fields.php', "<?php\nrunOnce('x', function () use (\$b24, \$f) {\n    \$b24->callChecked('crm.item.update', ['id' => 1, 'fields' => \$f]);\n    \$actual = \$b24->callChecked('crm.item.get', ['id' => 1]);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('обычный get не заменяет готовую сверку сохранённых fields',
    gateUnprotectedMutations($uncheckedFields) !== []);
$wrongExpectedFields = $sample('wrong_expected_fields.php', "<?php\nrunOnce('x', function () use (\$b24, \$f, \$other) {\n    \$b24->callChecked('crm.item.update', ['id' => 1, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => 1], \$other);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('verifier обязан получить тот же snapshot fields, что и мутация',
    gateUnprotectedMutations($wrongExpectedFields) !== []);
$changedFields = $sample('changed_fields.php', "<?php\nrunOnce('x', function () use (\$b24, \$f) {\n    \$b24->callChecked('crm.item.update', ['id' => 1, 'fields' => \$f]);\n    \$f = ['TITLE' => 'уже другое'];\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => 1], \$f);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('переопределение fields между write и verifier не проходит по совпавшему имени',
    gateUnprotectedMutations($changedFields) !== []);
$changedReceiver = $sample('changed_receiver.php', "<?php\nrunOnce('x', function () use (\$b24, \$fake, \$f) {\n    \$b24->callChecked('crm.item.update', ['id' => 1, 'fields' => \$f]);\n    \$b24 = \$fake;\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => 1], \$f);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('подмена B24 receiver между write и verifier не создаёт ложный read-back',
    gateUnprotectedMutations($changedReceiver) !== []);
$emptyExpectedFields = $sample('empty_expected_fields.php', "<?php\nrunOnce('x', function () use (\$b24, \$f) {\n    \$b24->callChecked('crm.item.update', ['id' => 1, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => 1], []);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('пустой expectedFields не отключает сверку полей',
    gateUnprotectedMutations($emptyExpectedFields) !== []);
$lateParamsDecoy = $sample('late_params_decoy.php', "<?php\nrunOnce('x', function () use (\$b24, \$params, \$f) {\n    \$b24->callChecked('crm.item.update', \$params);\n    \$params = ['id' => 1, 'fields' => \$f];\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => 1], \$f);\n    auditLog('deal.update', ['actual' => \$actual]);\n});\n");
check('присваивание params после write не служит статическим доказательством selector/fields',
    gateUnprotectedMutations($lateParamsDecoy) !== []);
$wrongReadBack = $sample('wrong_readback.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$b24->callChecked('crm.item.update', ['id' => 1]);\n    \$b24->callChecked('user.get', ['ID' => 1]);\n    auditLog('deal.update', ['id' => 1]);\n});\n");
check('чтение чужого семейства не считается проверкой результата мутации',
    gateUnprotectedMutations($wrongReadBack) !== []);
$earlyReadBack = $sample('early_readback.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$b24->callChecked('crm.item.get', ['id' => 1]);\n    \$b24->callChecked('crm.item.update', ['id' => 1]);\n    auditLog('deal.update', ['id' => 1]);\n});\n");
check('чтение до мутации не считается read-back', gateUnprotectedMutations($earlyReadBack) !== []);
$lateReadBack = $sample('late_readback.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$b24->callChecked('crm.item.update', ['id' => 1]);\n    auditLog('deal.update', ['id' => 1]);\n    \$b24->callChecked('crm.item.get', ['id' => 1]);\n});\n");
check('чтение после аудита не подтверждает уже записанный след', gateUnprotectedMutations($lateReadBack) !== []);
$conditionalReadBack = $sample('conditional_readback.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$b24->callChecked('crm.item.update', ['id' => 1]);\n    if (\$flag) { \$b24->callChecked('crm.item.get', ['id' => 1]); }\n    auditLog('deal.update', ['id' => 1]);\n});\n");
check('условный read-back не прикрывает безусловную мутацию',
    gateUnprotectedMutations($conditionalReadBack) !== []);
$auditBefore = $sample('audit_before_mutation.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    auditLog('deal.update', ['id' => 1]);\n    return \$b24->callChecked('crm.item.update', ['id' => 1]);\n});\n");
check('auditLog до мутации не считается следом её результата',
    gateUnprotectedMutations($auditBefore) !== []);
$auditAfterReturn = $sample('audit_after_return.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    return \$r;\n    auditLog('deal.update', ['id' => 1]);\n});\n");
check('недостижимый auditLog после return не прикрывает мутацию',
    gateUnprotectedMutations($auditAfterReturn) !== []);
$conditionalAudit = $sample('conditional_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    if (\$flag) { auditLog('deal.update', ['id' => 1]); }\n    return \$r;\n});\n");
check('условный auditLog не прикрывает безусловную мутацию',
    gateUnprotectedMutations($conditionalAudit) !== []);
$unbracedConditionalAudit = $sample('unbraced_conditional_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    if (\$flag) auditLog('deal.update', ['id' => 1]);\n    return \$r;\n});\n");
check('условный auditLog без фигурных скобок тоже не прикрывает мутацию',
    gateUnprotectedMutations($unbracedConditionalAudit) !== []);
$alternativeConditionalAudit = $sample('alternative_conditional_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    if (\$flag):\n        \$harmless = true;\n        auditLog('deal.update', ['id' => 1]);\n    endif;\n    return \$r;\n});\n");
check('auditLog в альтернативном if после другой инструкции остаётся условным',
    gateUnprotectedMutations($alternativeConditionalAudit) !== []);
$shortCircuitAudit = $sample('short_circuit_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    \$flag && auditLog('deal.update', ['id' => 1]);\n    return \$r;\n});\n");
check('auditLog справа от short-circuit оператора остаётся условным',
    gateUnprotectedMutations($shortCircuitAudit) !== []);
$ternaryAudit = $sample('ternary_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    \$flag ? auditLog('deal.update', ['id' => 1]) : null;\n    return \$r;\n});\n");
check('auditLog внутри ternary не считается обязательным следом',
    gateUnprotectedMutations($ternaryAudit) !== []);
$coalesceAudit = $sample('coalesce_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$maybe) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    \$maybe ?? auditLog('deal.update', ['id' => 1]);\n    return \$r;\n});\n");
check('auditLog справа от coalesce не считается обязательным следом',
    gateUnprotectedMutations($coalesceAudit) !== []);
$argumentAudit = $sample('argument_audit.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    consume(auditLog('deal.update', ['id' => 1]));\n    return \$r;\n});\n");
check('auditLog как аргумент другого вызова не считается отдельной инструкцией',
    gateUnprotectedMutations($argumentAudit) !== []);
$assignedAudit = $sample('assigned_audit.php', "<?php\nrunOnce('x', function () use (\$b24) {\n    \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n    \$logged = auditLog('deal.update', ['id' => 1]);\n    return \$r;\n});\n");
check('присвоенный auditLog не считается отдельной инструкцией',
    gateUnprotectedMutations($assignedAudit) !== []);
$independentAuditBranches = $sample('independent_audit_branches.php', "<?php\nrunOnce('x', function () use (\$b24, \$mutate, \$writeAudit) {\n    \$r = null;\n    if (\$mutate) { \$r = \$b24->callChecked('crm.item.update', ['id' => 1]); }\n    if (\$writeAudit) { auditLog('deal.update', ['id' => 1]); }\n    return \$r;\n});\n");
check('независимые if мутации и аудита не доказывают общий путь выполнения',
    gateUnprotectedMutations($independentAuditBranches) !== []);
$sameBranchAudit = $sample('same_branch_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    if (\$flag) {\n        \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n        \$actual = \$b24->callChecked('crm.item.get', ['id' => 1]);\n        auditLog('deal.update', ['id' => 1, 'actual' => \$actual]);\n        return \$r;\n    }\n    return null;\n});\n");
check('мутация и следующий auditLog в одной ветке проходят',
    gateUnprotectedMutations($sameBranchAudit) === []);
$sameAlternativeBranchAudit = $sample('same_alternative_branch_audit.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    if (\$flag):\n        \$r = \$b24->callChecked('crm.item.update', ['id' => 1]);\n        \$actual = \$b24->callChecked('crm.item.get', ['id' => 1]);\n        auditLog('deal.update', ['id' => 1, 'actual' => \$actual]);\n        return \$r;\n    endif;\n    return null;\n});\n");
check('мутация и auditLog в одной альтернативной ветке проходят',
    gateUnprotectedMutations($sameAlternativeBranchAudit) === []);
$auditAfterConditionalMutation = $sample('audit_after_conditional_mutation.php', "<?php\nrunOnce('x', function () use (\$b24, \$flag) {\n    \$r = null;\n    if (\$flag) { \$r = \$b24->callChecked('crm.item.update', ['id' => 1]); }\n    \$actual = \$b24->callChecked('crm.item.get', ['id' => 1]);\n    auditLog('deal.update', ['id' => 1, 'actual' => \$actual]);\n    return \$r;\n});\n");
check('безусловный auditLog после условной мутации проходит',
    gateUnprotectedMutations($auditAfterConditionalMutation) === []);
$decoyMutation = $sample('decoy_mutation.php', "<?php\nfunction decoy() { runOnce('x', function () {}); auditLog('x', []); }\n\$b24->callChecked('crm.item.update', ['id' => 1]);\n");
check('неродственные runOnce/auditLog не прикрывают мутацию снаружи', gateUnprotectedMutations($decoyMutation) !== []);
$dynamicMutation = $sample('dynamic_mutation.php', "<?php\n\$method = 'crm.item.delete';\n\$b24->callChecked(\$method, ['id' => 1]);\n");
check('динамическое имя REST-метода трактуется fail-closed', gateUnprotectedMutations($dynamicMutation) !== []);
$keyClosureMutation = $sample('key_closure_mutation.php', "<?php\nrunOnce((function () use (\$b24) {\n    \$r = \$b24->callChecked('crm.item.delete', ['id' => 1]);\n    auditLog('deal.delete', ['id' => 1]);\n    return 'key';\n})(), function () { return null; });\n");
check('мутация в closure вычисления ключа runOnce остаётся вне защиты',
    gateUnprotectedMutations($keyClosureMutation) !== []);
$fakeDeleteReadBack = $sample('fake_delete_readback.php', "<?php\nrunOnce('delete-1', function () use (\$b24) {\n    \$b24->callChecked('crm.item.delete', ['entityTypeId' => 2, 'id' => 1]);\n    \$b24->callChecked('crm.item.get', ['entityTypeId' => 2, 'id' => 1]);\n    auditLog('deal.delete', ['id' => 1]);\n});\n");
check('успешный get после delete не выдаётся за доказательство отсутствия',
    gateUnprotectedMutations($fakeDeleteReadBack) !== []);
$goodDelete = $sample('good_delete.php', "<?php\nrunOnce('delete-1', function () use (\$b24) {\n    \$r = \$b24->deleteVerified('crm.item.delete', ['entityTypeId' => 2, 'id' => 1]);\n    auditLog('deal.delete', ['id' => 1]);\n    return \$r;\n});\n");
check('delete проходит только через встроенный policy-helper и аудит',
    gateUnprotectedMutations($goodDelete) === []);
$goodAction = $sample('good_action.php', "<?php\nrunOnce('workflow-1', function () use (\$b24, \$doc) {\n    \$r = \$b24->actionVerified('bizproc.workflow.start', ['TEMPLATE_ID' => 1, 'DOCUMENT_ID' => \$doc]);\n    auditLog('workflow.start', ['workflow' => \$r['result']]);\n    return \$r;\n});\n");
check('action без универсального get проходит через встроенный response-контракт',
    gateUnprotectedMutations($goodAction) === []);
// Реестр гейта и реестр клиента обязаны совпадать поимённо. Разойдутся молча —
// гейт зазеленеет на вызове, который B24 отвергнет исключением «нет встроенного
// verified-контракта», и узнает об этом уже боевой бизнес-процесс.
$b24Source = (string)@file_get_contents(dirname(__DIR__) . '/www/api/b24.php');
$policyBlock = '';
if (preg_match('~private static function verifiedMutationPolicy.*?\n    \}~s', $b24Source, $mPolicy)) {
    $policyBlock = $mPolicy[0];
}
$registryMismatch = [];
foreach (gateVerifiedMutationRegistry() as $contract => $methods) {
    foreach ($methods as $method) {
        if (strpos($policyBlock, "'" . $method . "' => [") === false) {
            $registryMismatch[] = $contract . ':' . $method;
        }
    }
}
check('реестр verified-методов гейта совпадает с политиками клиента B24',
    $policyBlock !== '' && $registryMismatch === [],
    $policyBlock === '' ? 'не найден блок verifiedMutationPolicy в www/api/b24.php'
        : ('нет политики у методов: ' . implode(', ', $registryMismatch)));

// Действия бизнес-процессов. У всей семьи нет ни одного *.get, поэтому контракт
// им даёт реестр, а не глагол: пока 'add' означал «нужен read-back», регистрация
// действия БП требовала невозможного и толкала проект на обход гейта.
$goodActivityAdd = $sample('good_activity_add.php', "<?php\nrunOnce('bp-activity-add', function () use (\$b24, \$fields) {\n    \$r = \$b24->actionVerified('bizproc.activity.add', ['CODE' => 'find', 'HANDLER' => \$url, 'NAME' => 'Поиск'] + \$fields);\n    auditLog('bp.activity.add', ['code' => 'find']);\n    return \$r;\n});\n");
check('регистрация действия БП проходит через встроенный response-контракт',
    gateUnprotectedMutations($goodActivityAdd) === []);
$goodActivityUpdate = $sample('good_activity_update.php', "<?php\nrunOnce('bp-activity-update', function () use (\$b24, \$fields) {\n    \$r = \$b24->actionVerified('bizproc.activity.update', ['CODE' => 'find', 'FIELDS' => \$fields]);\n    auditLog('bp.activity.update', ['code' => 'find']);\n    return \$r;\n});\n");
check('обновление действия БП проходит через встроенный response-контракт',
    gateUnprotectedMutations($goodActivityUpdate) === []);
$goodEventSend = $sample('good_event_send.php', "<?php\nrunOnce('bp-reply-' . \$token, function () use (\$b24, \$token, \$values) {\n    \$r = \$b24->actionVerified('bizproc.event.send', ['event_token' => \$token, 'return_values' => \$values]);\n    auditLog('bp.event.send', ['found' => 1]);\n    return \$r;\n});\n");
check('ответ бизнес-процессу проходит через встроенный response-контракт',
    gateUnprotectedMutations($goodEventSend) === []);
$rawEventSend = $sample('raw_event_send.php', "<?php\n\$b24->callChecked('bizproc.event.send', ['event_token' => \$token]);\n");
check('ответ бизнес-процессу мимо policy-helper остаётся нарушением',
    gateUnprotectedMutations($rawEventSend) !== []);
$rawActivityAdd = $sample('raw_activity_add.php', "<?php\n\$b24->callChecked('bizproc.activity.add', ['CODE' => 'find'] + \$fields);\n");
check('регистрация действия БП без runOnce/аудита остаётся нарушением',
    gateUnprotectedMutations($rawActivityAdd) !== []);
$findByComm = $sample('find_by_comm.php', "<?php\n\$found = \$b24->callChecked('crm.duplicate.findbycomm', ['type' => 'PHONE', 'values' => \$values, 'entity_type' => 'CONTACT']);\n");
check('поиск по телефону читает портал и не требует mutation-цепочки',
    gateUnprotectedMutations($findByComm) === []);
$findByCommLookalike = $sample('find_by_comm_lookalike.php', "<?php\n\$found = \$b24->callChecked('crm.duplicate.findbyphone', ['values' => \$values]);\n");
check('похожий на поиск дублей неизвестный метод остаётся fail-closed',
    gateUnprotectedMutations($findByCommLookalike) !== []);
$unknownActionPolicy = $sample('unknown_action_policy.php', "<?php\nrunOnce('complete-1', function () use (\$b24) {\n    \$r = \$b24->actionVerified('tasks.task.complete', ['taskId' => 1]);\n    auditLog('task.complete', ['id' => 1]);\n    return \$r;\n});\n");
check('неизвестный action нельзя протащить одним названием verified-helper',
    gateUnprotectedMutations($unknownActionPolicy) !== []);
$dynamicDeletePolicy = $sample('dynamic_delete_policy.php', "<?php\n\$method = 'crm.item.delete';\nrunOnce('delete-1', function () use (\$b24, \$method) {\n    \$r = \$b24->deleteVerified(\$method, ['entityTypeId' => 2, 'id' => 1]);\n    auditLog('deal.delete', ['id' => 1]);\n    return \$r;\n});\n");
check('policy-key verified-helper обязан быть литералом',
    gateUnprotectedMutations($dynamicDeletePolicy) !== []);
$batchMutation = $sample('batch_mutation.php', "<?php\n\$b24->batchAll(['x' => ['crm.item.add', ['fields' => \$f]]]);\n");
check('мутация внутри batchAll тоже требует runOnce/auditLog', gateUnprotectedMutations($batchMutation) !== []);
$goodBatchMutation = $sample('good_batch_mutation.php', "<?php\nrunOnce('batch-' . \$id, function () use (\$b24, \$f, \$uid) {\n    \$r = \$b24->batchAllChecked(['x' => ['crm.item.add', ['fields' => \$f]]]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => \$r['result']['x']['item']['id']], \$f);\n    auditLog('deal.batch', ['count' => 1, 'actual' => \$actual], \$uid);\n    return \$r;\n});\n");
check('мутация batchAllChecked внутри runOnce проходит только с read-back и аудитом',
    gateUnprotectedMutations($goodBatchMutation) === []);
$wrongBatchKey = $sample('wrong_batch_key.php', "<?php\nrunOnce('batch', function () use (\$b24, \$f) {\n    \$r = \$b24->batchAllChecked(['x' => ['crm.item.add', ['fields' => \$f]]]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => \$r['result']['y']['item']['id']], \$f);\n    auditLog('deal.batch', ['actual' => \$actual]);\n});\n");
check('read-back batch обязан брать ID результата той же команды',
    gateUnprotectedMutations($wrongBatchKey) !== []);
$goodDirectAdd = $sample('good_direct_add.php', "<?php\nrunOnce('add', function () use (\$b24, \$f) {\n    \$r = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$f]);\n    \$actual = \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$r['item']['id']], \$f);\n    auditLog('deal.add', ['actual' => \$actual]);\n    return \$r;\n});\n");
check('direct add проходит с ID из своего результата и verifier полей',
    gateUnprotectedMutations($goodDirectAdd) === []);
$changedAddResult = $sample('changed_add_result.php', "<?php\nrunOnce('add', function () use (\$b24, \$f) {\n    \$r = \$b24->callChecked('crm.item.add', ['fields' => \$f]);\n    \$r = ['item' => ['id' => 999]];\n    \$actual = \$b24->readBackChecked('crm.item.get', ['id' => \$r['item']['id']], \$f);\n    auditLog('deal.add', ['actual' => \$actual]);\n});\n");
check('подмена результата add перед извлечением ID не проходит provenance-гейт',
    gateUnprotectedMutations($changedAddResult) !== []);
$dynamicBatchDecoy = $sample('dynamic_batch_decoy.php', "<?php\n\$method = \$kind . '.delete';\n\$b24->batchAll([\n    'real' => [\$method, ['id' => 1]],\n    'decoy' => ['crm.item.get', ['id' => 1]],\n]);\n");
check('read-only команда-приманка не прикрывает динамический метод batch',
    gateUnprotectedMutations($dynamicBatchDecoy) !== []);

// ── Идемпотентный пакет: обещание словами больше не пропуск ──────────────────
// Кодекс 08.08.2026 провёл `crm.deal.add` через batchAllIdempotent: достаточно
// было написать объяснение длиннее двенадцати знаков. Теперь идемпотентность
// определяется методом и его контрактом в документации портала, а не текстом.
$idempotentDealAdd = $sample('idempotent_deal_add.php', "<?php\nfunction sendAll(\$b24, \$f) {\n    \$r = \$b24->batchAllIdempotent(['x' => ['crm.deal.add', ['fields' => \$f]]],\n        'повтор безопасен, честное слово, я всё продумал и проверил');\n    auditLog('deal.add', ['count' => 1]);\n    return \$r;\n}\n");
check('СОЗДАНИЕ СДЕЛКИ НЕ ПРОХОДИТ КАК «ИДЕМПОТЕНТНЫЙ ПАКЕТ»',
    gateUnprotectedMutations($idempotentDealAdd) !== []);
$idempotentDynamic = $sample('idempotent_dynamic.php', "<?php\nfunction sendAll(\$b24, \$method) {\n    \$r = \$b24->batchAllIdempotent(['x' => [\$method, ['TAG' => 'x']]],\n        'повтор безопасен по контракту портала, тег уникален в рамках приложения');\n    auditLog('notify.send', ['count' => 1]);\n    return \$r;\n}\n");
check('динамическое имя метода в идемпотентном пакете тоже краснеет',
    gateUnprotectedMutations($idempotentDynamic) !== []);
$idempotentNoTag = $sample('idempotent_no_tag.php', "<?php\nfunction sendAll(\$b24, \$uid, \$text) {\n    \$r = \$b24->batchAllIdempotent(['x' => ['im.notify.system.add', ['USER_ID' => \$uid, 'MESSAGE' => \$text]]],\n        'повтор безопасен по контракту портала, уведомления не дублируются');\n    auditLog('notify.send', ['count' => 1]);\n    return \$r;\n}\n");
check('УВЕДОМЛЕНИЕ БЕЗ МЕТКИ НЕ СЧИТАЕТСЯ ИДЕМПОТЕНТНЫМ',
    gateUnprotectedMutations($idempotentNoTag) !== []);
$idempotentWithTag = $sample('idempotent_with_tag.php', "<?php\nfunction sendAll(\$b24, \$uid, \$text, \$tag) {\n    \$r = \$b24->batchAllIdempotent(['x' => ['im.notify.system.add', ['USER_ID' => \$uid, 'MESSAGE' => \$text, 'TAG' => \$tag]]],\n        'у каждого уведомления своя метка: портал заменяет прежнее сообщение');\n    auditLog('notify.send', ['count' => 1]);\n    return \$r;\n}\n");
check('уведомление с меткой проходит идемпотентным пакетом',
    gateUnprotectedMutations($idempotentWithTag) === []);
$idempotentEmptyTag = $sample('idempotent_empty_tag.php', "<?php\nfunction sendAll(\$b24, \$uid, \$text) {\n    \$r = \$b24->batchAllIdempotent(['x' => ['im.notify.system.add', ['USER_ID' => \$uid, 'MESSAGE' => \$text, 'TAG' => '']]],\n        'у каждого уведомления своя метка: портал заменяет прежнее сообщение');\n    auditLog('notify.send', ['count' => 1]);\n    return \$r;\n}\n");
check('пустая метка не считается меткой', gateUnprotectedMutations($idempotentEmptyTag) !== []);

// ── Сборка пакета — только в своей функции ───────────────────────────────────
// Разбор искал `$cmd[...] = [...]` по всему файлу, поэтому безопасная сборка в
// СОСЕДНЕЙ функции снимала «безымянность» с динамического мутатора рядом.
$crossFunctionDecoy = $sample('cross_function_decoy.php', "<?php\nfunction bezopasno(\$b24) {\n    \$cmd = [];\n    \$cmd['a'] = ['user.get', []];\n    return \$b24->batchAll(\$cmd);\n}\nfunction opasno(\$b24, \$method, \$p) {\n    \$cmd = [];\n    \$cmd['b'] = [\$method, \$p];\n    return \$b24->batchAll(\$cmd);\n}\n");
check('СБОРКА ИЗ СОСЕДНЕЙ ФУНКЦИИ НЕ ПРИКРЫВАЕТ ДИНАМИЧЕСКИЙ МУТАТОР',
    in_array('dynamic-method', array_map(static function (string $hit): string {
        return explode(':', $hit)[0];
    }, gateUnprotectedMutations($crossFunctionDecoy)), true),
    implode(', ', gateUnprotectedMutations($crossFunctionDecoy)));
$assemblyLiteralOk = $sample('assembly_literal_ok.php', "<?php\nfunction chitaem(\$b24, \$ids) {\n    \$cmd = [];\n    foreach (\$ids as \$id) { \$cmd[\$id] = ['crm.item.get', ['id' => \$id]]; }\n    return \$b24->batchAll(\$cmd);\n}\n");
check('сборка чтений в своей функции остаётся зелёной',
    gateUnprotectedMutations($assemblyLiteralOk) === [],
    implode(', ', gateUnprotectedMutations($assemblyLiteralOk)));
$assemblyMutationCaught = $sample('assembly_mutation_caught.php', "<?php\nfunction pishem(\$b24, \$rows) {\n    \$cmd = [];\n    foreach (\$rows as \$row) { \$cmd[\$row['id']] = ['crm.item.add', ['fields' => \$row]]; }\n    return \$b24->batchAll(\$cmd);\n}\n");
check('мутация в собранном цикле пакете по-прежнему ловится',
    gateUnprotectedMutations($assemblyMutationCaught) !== []);
// Обратный край той же границы: чужая мутация не должна ПРИПИСЫВАТЬСЯ читающей
// функции. Без привязки к области видимости здесь загорается ложный красный, а
// ложный красный на честном коде опаснее пропуска: его учатся обходить.
$assemblyForeignMutation = $sample('assembly_foreign_mutation.php', "<?php\nfunction chitaem(\$b24, \$ids) {\n    \$cmd = [];\n    foreach (\$ids as \$id) { \$cmd[\$id] = ['crm.item.get', ['id' => \$id]]; }\n    return \$b24->batchAll(\$cmd);\n}\nfunction gotovim(\$rows) {\n    \$cmd = [];\n    foreach (\$rows as \$row) { \$cmd[\$row['id']] = ['crm.item.add', ['fields' => \$row]]; }\n    return \$cmd;\n}\n");
check('МУТАЦИЯ ИЗ СОСЕДНЕЙ ФУНКЦИИ НЕ ПРИПИСЫВАЕТСЯ ЧИТАЮЩЕЙ',
    gateUnprotectedMutations($assemblyForeignMutation) === [],
    implode(', ', gateUnprotectedMutations($assemblyForeignMutation)));
// Смесь в ОДНОЙ функции: два честных чтения и одна команда с вычисляемым именем.
// Пока нераспознанное имя просто выпадало из разбора, соседние литералы делали
// вызов «известным», и вычисляемый метод уезжал без всякой проверки.
// ── Роли и рецепты: разбор кода вместо поиска строк ─────────────────────────
// Проверки жили на одной регулярке с одинарными кавычками. Кодекс показал:
// `apiBoot(["role" => "user"])` в доке проходил зелёным — а такой пример
// копируют дословно, и эндпоинт закрывается ролью, которой нет.
$roleDouble = $sample('role_double.php', "<?php\napiBoot([\"role\" => \"user\"]);\n");
check('РОЛЬ В ДВОЙНЫХ КАВЫЧКАХ ТОЖЕ ВИДНА ЗАБОРУ',
    gateRequiredRoleNames($roleDouble) === ['user'], implode(', ', gateRequiredRoleNames($roleDouble)));
$roleWrapped = $sample('role_wrapped.php', "<?php\napiBoot([\n    'role'\n        =>\n        'manager',\n]);\n");
check('перенос строки между ключом и ролью не прячет её',
    gateRequiredRoleNames($roleWrapped) === ['manager'], implode(', ', gateRequiredRoleNames($roleWrapped)));
$roleOk = $sample('role_ok.php', "<?php\napiBoot(['role' => 'admin']);\n");
check('обычная запись роли читается как раньше',
    gateRequiredRoleNames($roleOk) === ['admin'], implode(', ', gateRequiredRoleNames($roleOk)));
$roleDoc = $sample('role_doc.md', "Пример: `apiBoot([\"role\" => \"user\"])` — так делать нельзя.\n");
check('роль в документации видна независимо от кавычек',
    gateRequiredRoleNames($roleDoc) === ['user'], implode(', ', gateRequiredRoleNames($roleDoc)));

$chunks = gateRecipeChunks("| тема | донор |\n| очередь | chuzhoy/www/api/ochered.php |\n\nАбзац про события,\nдонор: chuzhoy/www/api/sobytiya.php\n");
$temyProba = [['слова' => ['подписк'], 'блок' => 'events-bind.php']];
$receptPloho = "- **Подписки на события портала** — донор `chuzhoy/www/api/podpiski.php:10-90`,\n  берём оттуда сверку привязок.\n";
check('РЕЦЕПТ, ШЛЮЩИЙ К ЧУЖОМУ КОДУ ЧЕРЕЗ ПЕРЕНОС СТРОКИ, ЛОВИТСЯ',
    gateRecipeConflicts($receptPloho, $temyProba) !== [],
    implode(' · ', gateRecipeConflicts($receptPloho, $temyProba)));
$receptHorosho = "- **Подписки на события портала** — берём готовое `api/events-bind.php`; донор\n  `chuzhoy/www/api/podpiski.php:10-90` только за тем, чего в нём нет.\n";
check('рецепт, называющий готовый блок, остаётся зелёным',
    gateRecipeConflicts($receptHorosho, $temyProba) === [],
    implode(' · ', gateRecipeConflicts($receptHorosho, $temyProba)));
$receptOcheredi = "- **Очередь под нагрузкой** — сама очередь событий есть, `api/queue.php`; донор\n  `chuzhoy/www/api/queue.php:261-1010` за карантином битых записей.\n";
check('рецепт про очередь событий не путается с подписками',
    gateRecipeConflicts($receptOcheredi, $temyProba) === [],
    implode(' · ', gateRecipeConflicts($receptOcheredi, $temyProba)));

check('СТРОКА ТАБЛИЦЫ И АБЗАЦ — РАЗНЫЕ ЗАПИСИ КАТАЛОГА РЕЦЕПТОВ',
    count($chunks) === 3 && strpos($chunks[2], 'события') !== false
    && strpos($chunks[2], 'sobytiya.php') !== false,
    json_encode($chunks, JSON_UNESCAPED_UNICODE));

$assemblyMixedNames = $sample('assembly_mixed_names.php', "<?php\nfunction smesh(\$b24, \$method, \$ids) {\n    \$cmd = [];\n    foreach (\$ids as \$id) { \$cmd['r' . \$id] = ['crm.item.get', ['id' => \$id]]; }\n    \$cmd['x'] = [\$method, ['id' => 1]];\n    return \$b24->batchAll(\$cmd);\n}\n");
check('ВЫЧИСЛЯЕМОЕ ИМЯ РЯДОМ С ЧЕСТНЫМИ ЧТЕНИЯМИ НЕ ТЕРЯЕТСЯ',
    in_array('dynamic-method', array_map(static function (string $hit): string {
        return explode(':', $hit)[0];
    }, gateUnprotectedMutations($assemblyMixedNames)), true),
    implode(', ', gateUnprotectedMutations($assemblyMixedNames)));
$rawList = $sample('raw_list.php', "<?php\n\$rows = \$b24->call('crm.item.list', ['entityTypeId' => 2]);\n");
check('список портала без пагинации ловится', gateUnpagedLists($rawList) !== []);
$onePage = $sample('one_page.php', "<?php\n\$rows = \$b24->callChecked('crm.item.list', ['start' => 0]);\n");
check('start=0 у callChecked остаётся одной страницей и ловится', gateUnpagedLists($onePage) !== []);
$userGet = $sample('user_get.php', "<?php\n\$rows = \$b24->call('user.get', []);\n");
check('user.get распознаётся как список, несмотря на суффикс get', gateUnpagedLists($userGet) !== []);
$batchList = $sample('batch_list.php', "<?php\n\$rows = \$b24->batch(['one' => ['crm.item.list', ['start' => 0]]]);\n");
check('сырой list первой страницей внутри batch ловится', gateUnpagedLists($batchList) !== []);
$batchAllList = $sample('batch_all_list.php', "<?php\n\$rows = \$b24->batchAll(['one' => ['crm.item.list', ['start' => 0]]]);\n");
check('batchAll режет команды, но не страницы list, и тоже ловится',
    gateUnpagedLists($batchAllList) !== []);
$dynamicRawList = $sample('dynamic_raw_list.php', "<?php\n\$method = \$kind . '.list';\n\$rows = \$b24->call(\$method, ['start' => 0]);\n");
check('динамический метод у raw call трактуется fail-closed в paging-гейте',
    gateUnpagedLists($dynamicRawList) !== []);
$argumentRawList = $sample('argument_raw_list.php', "<?php\nfunction loadRows(\$b24, string \$method): array {\n    return \$b24->callChecked(\$method, ['start' => 0]);\n}\n");
check('REST-метод из аргумента функции не обходит paging-гейт raw helper',
    gateUnpagedLists($argumentRawList) !== []);
$decoyDynamicList = $sample('decoy_dynamic_list.php', "<?php\n\$method = \$known ? 'crm.item.get' : \$kind . '.list';\n\$rows = \$b24->call(\$method, ['start' => 0]);\n");
check('read-only литерал-приманка не прикрывает динамическую ветку raw call',
    gateUnpagedLists($decoyDynamicList) !== []);
// Страничные методы, не оканчивающиеся на .list/.search. Забор знал только
// суффикс и пять имён, поэтому сырой call к sonet_group.get, disk.*.getchildren,
// task.*.getlist и im.recent.get проходил молча: приложение отдавало первые
// 50 записей и выдавало их за все (K4-27, ревью 09.08.2026). Документация:
// api-reference/sonet-group/sonet-group-get.md — «Размер страницы результатов —
// 50 записей», start=(N-1)*50; api-reference/disk/folder/disk-folder-get-children.md
// — «Размер страницы результатов всегда статичный — 50 записей».
foreach ([
    'sonet_group.get'            => 'рабочие группы',
    'sonet_group.user.get'       => 'участники рабочей группы',
    'disk.folder.getchildren'    => 'содержимое папки диска',
    'disk.storage.getchildren'   => 'содержимое хранилища диска',
    'task.commentitem.getlist'   => 'комментарии задачи',
    'task.checklistitem.getlist' => 'чеклист задачи',
    'task.elapseditem.getlist'   => 'затраченное время',
    'im.recent.get'              => 'последние чаты',
] as $metod => $chto) {
    $obrazec = $sample('unpaged_' . str_replace('.', '_', $metod) . '.php',
        "<?php\n\$rows = \$b24->call('" . $metod . "', []);\n");
    check('страничный ' . $metod . ' (' . $chto . ') без пагинации ловится',
        gateUnpagedLists($obrazec) !== []);
}
// Точечные чтения по-прежнему разрешены: иначе забор станет шумом и его начнут
// обходить целиком.
foreach (['app.info', 'user.current', 'profile', 'scope', 'crm.item.get', 'task.item.userfield.get'] as $metod) {
    $tochka = $sample('point_' . str_replace('.', '_', $metod) . '.php',
        "<?php\n\$row = \$b24->call('" . $metod . "', ['id' => 1]);\n");
    check('точечный ' . $metod . ' пагинации не требует', gateUnpagedLists($tochka) === []);
}
$goodList = $sample('good_list.php', "<?php\n\$rows = \$b24->fetchAll('crm.item.list', ['entityTypeId' => 2]);\n");
check('список через fetchAll проходит', gateUnpagedLists($goodList) === []);
$goodDynamicList = $sample('good_dynamic_list.php', "<?php\n\$method = \$kind . '.list';\n\$rows = \$b24->fetchAll(\$method, ['entityTypeId' => 2]);\n");
check('динамический метод через fetchAll остаётся разрешённым paging-гейтом',
    gateUnpagedLists($goodDynamicList) === []);
$goodBatchedList = $sample('good_batched_list.php', "<?php\n\$rows = \$b24->fetchAllBatched('crm.item.list', ['entityTypeId' => 2]);\n");
check('список через fetchAllBatched проходит', gateUnpagedLists($goodBatchedList) === []);
$goodIds = $sample('good_ids.php', "<?php\n\$rows = \$b24->loadByIds('user.get', [1, 2], [], 'ID');\n");
check('точечное чтение через loadByIds не требует пагинации списка', gateUnpagedLists($goodIds) === []);

// ── 9. Инвентаризация видит весь код ─────────────────────────────────────────

$sample('sub/deep_endpoint.php', "<?php\n\$x = 1;\n");
$found = gateFilesUnder($dir, 'php');
check('инвентаризация находит файлы во вложенных папках',
    in_array($dir . '/sub/deep_endpoint.php', $found, true));
$sample('vendor/SomeLib.php', "<?php\ncurl_init();\n");
check('папка vendor внутри проверяемого webroot не становится слепой зоной',
    in_array($dir . '/vendor/SomeLib.php', gateFilesUnder($dir, 'php'), true));

// Совпадение basename не делает вложенный файл ядром. Иначе достаточно назвать
// свой endpoint `http.php`, чтобы снять с него apiBoot, стор и готовый клиент.
$expectedCore = ['www/api/http.php', 'www/api/store.php', 'www/api/lib.php',
                 'www/api/b24.php', 'www/api/session.php', 'www/api/access.php',
                 'www/api/acl.php', 'www/api/event.php', 'www/api/placements.php',
                 'www/api/journal.php', 'www/api/fmt.php', 'www/api/export-csv.php',
                 'www/api/queue.php', 'www/api/events-bind.php', 'www/api/crm-dict.php', 'www/api/fetch-all-compat.php',
                 'www/api/cache.php', 'www/api/uf.php', 'www/api/notify.php', 'www/api/plan.php',
                 'www/api/capability.php'];
$fakeProjectRoot = $dir . '/project';
$exactCore = $sample('project/www/api/http.php', "<?php\nfunction apiBoot(): void {}\n");
$nestedCoreName = $sample('project/www/api/report/http.php', "<?php\ncurl_init();\n");
check('штатный файл ядра узнаётся по точному относительному пути',
    gateIsExactPath($fakeProjectRoot, $exactCore, $expectedCore));
check('вложенный файл с basename ядра не получает исключения ядра',
    !gateIsExactPath($fakeProjectRoot, $nestedCoreName, $expectedCore));

// Корневой PHP раньше попадал только в часть запретов, но не считался endpoint:
// так `www/report.php` обходил обязательный apiBoot и единый ответ.
$expectedRootPhp = ['www/index.php', 'www/env.php', 'www/release.php'];
$expectedBinPhp = ['www/bin/deploy-private-store.php', 'www/bin/logs.php',
                   'www/bin/process.php'];
$sample('project/www/index.php', "<?php\n");
$sample('project/www/env.php', "<?php\n");
$rootEndpoint = $sample('project/www/report.php', "<?php\necho json_encode([]);\n");
$badRoot = gateUnexpectedRootPhp($fakeProjectRoot, $expectedRootPhp);
check('неожиданный endpoint прямо в www/ ловится', $badRoot === [$rootEndpoint],
    'найдено: ' . implode(', ', array_map('basename', $badRoot)));
$unexpectedLib = $sample('project/www/lib/escape.php', "<?php\nfunction hiddenEndpoint(): void {}\n");
$vendorPhp = $sample('project/www/vendor/ThirdParty.php', "<?php\nfunction vendorCode(): void {}\n");
$goodCli = $sample('project/www/bin/process.php', "<?php\ndeclare(strict_types=1);\nif (PHP_SAPI !== 'cli') {\n    http_response_code(404);\n    exit;\n}\necho 'cli';\n");
$editedCli = $sample('project/www/bin/logs.php', "<?php\necho json_encode(['public' => true]);\n");
$lateCli = $sample('project/www/bin/deploy-private-store.php', "<?php\nerror_reporting(E_ALL);\nif (PHP_SAPI !== 'cli') { http_response_code(404); exit; }\n");
$binBypass = $sample('project/www/bin/public-bypass.php', "<?php\necho json_encode(['bypass' => true]);\n");
$rootPhtml = $sample('project/www/public-bypass.phtml', "<?php\necho json_encode(['bypass' => true]);\n");
$hiddenInc = $sample('project/www/api/hidden.inc', "<?php\ncurl_init('https://example.invalid');\n");
$hiddenPayload = $sample('project/www/assets/transport.dat', "<?php\ncurl_init('https://example.invalid');\n");
$hiddenLoader = $sample('project/www/api/load-hidden.php', "<?php\nrequire_once __DIR__ . '/../assets/transport.dat';\n");
$canonicalLoader = $sample('project/www/api/load-php.php', "<?php\nrequire_once dirname(__DIR__) . '/api/http.php';\n");
$badEnvCall = $sample('bad-env.php', "<?php\ncurl_init('https://example.invalid');\n");
$badEnvOutput = $sample('loud-env.php', "<?php\necho 'before auth';\n");
$badEnvBacktick = $sample('shell-env.php', "<?php\n\$out = `curl https://example.invalid`;\n");
$badEnvWrapper = $sample('wrapper-env.php', "<?php\n\$url = 'https://example.invalid';\n\$raw = file_get_contents(\$url);\n");
$goodEnvShape = $sample('good-env.php', "<?php\nif (is_file(__DIR__ . '/release.php')) require_once __DIR__ . '/release.php';\ndefine('APP_SCOPES', ['crm']);\n");
$canonicalEnvFile = $root . '/www/env.php';
$canonicalEnvSource = (string)file_get_contents($canonicalEnvFile);
$badPrivateTargetSource = str_replace(
    '$data = $readPrivateState(STATE_FILE, STATE_MAX_BYTES, \'state\');',
    '$data = $readPrivateState((string)($_GET[\'state\'] ?? STATE_FILE), STATE_MAX_BYTES, \'state\');',
    $canonicalEnvSource,
    $badPrivateTargetCount
);
$badPrivateTargetEnv = $sample('bad-private-target-env.php', $badPrivateTargetSource);
$badPrivateCeilingSource = str_replace(
    'file_get_contents($file, false, null, 0, $maxBytes + 1)',
    'file_get_contents($file)',
    $canonicalEnvSource,
    $badPrivateCeilingCount
);
$badPrivateCeilingEnv = $sample('bad-private-ceiling-env.php', $badPrivateCeilingSource);
$badPrivateReasonSource = str_replace(
    '$e->getCode()',
    '$e->getMessage()',
    $canonicalEnvSource,
    $badPrivateReasonCount
);
$badPrivateReasonEnv = $sample('bad-private-reason-env.php', $badPrivateReasonSource);
$webConfig = $sample('project/www/.htaccess', "AddType application/x-httpd-php .txt\n");
$phpBackup = $sample('project/www/env.php.bak', "<?php\ndefine('B24_CLIENT_SECRET', 'leak');\n");
$allWwwPhp = gatePhpSourceFilesUnder($fakeProjectRoot . '/www');
$badLocations = gateUnexpectedPhpLocations(
    $fakeProjectRoot,
    $allWwwPhp,
    array_merge($expectedRootPhp, $expectedBinPhp),
    ['www/api/']
);
check('PHP-библиотека вне разрешённой структуры www ловится fail-closed',
    in_array($unexpectedLib, $badLocations, true) && in_array($rootEndpoint, $badLocations, true)
    && in_array($vendorPhp, $badLocations, true) && in_array($binBypass, $badLocations, true)
    && in_array($rootPhtml, $badLocations, true) && count($badLocations) === 5,
    'найдено: ' . implode(', ', array_map(static fn($f) => gateRelativePath($fakeProjectRoot, $f), $badLocations)));
check('зависимости нельзя прятать в www/vendor в обход first-party гейта',
    in_array($vendorPhp, $allWwwPhp, true));
check('варианты .phtml/.inc входят в PHP-инвентаризацию, а не образуют слепую зону',
    in_array($rootPhtml, $allWwwPhp, true) && in_array($hiddenInc, $allWwwPhp, true)
    && gateOwnHttpLines($hiddenInc) !== []);
check('PHP в произвольном .dat не исполняется через невидимый require',
    !in_array($hiddenPayload, $allWwwPhp, true)
    && gateNonCanonicalRequireLines($hiddenLoader) !== []);
check('статический require канонического .php остаётся разрешён',
    gateNonCanonicalRequireLines($canonicalLoader) === []);
check('env.php не принимает произвольный HTTP-вызов или ранний вывод',
    gateEnvEffectLines($badEnvCall) !== [] && gateEnvEffectLines($badEnvOutput) !== []
    && gateEnvEffectLines($badEnvBacktick) !== [] && gateEnvEffectLines($badEnvWrapper) !== []);
check('штатные define и release.php остаются допустимым env-контрактом',
    gateEnvEffectLines($goodEnvShape) === []);
check('exact bounded private-state reader разрешён только с фиксированными target/cap/label',
    $badPrivateTargetCount === 1 && $badPrivateCeilingCount === 1 && $badPrivateReasonCount === 1
    && array_values(array_diff(gateEnvEffectLines($canonicalEnvFile),
        gateDirectAccessGuardLines($canonicalEnvFile))) === []
    && array_values(array_diff(gateEnvEffectLines($badPrivateTargetEnv),
        gateDirectAccessGuardLines($badPrivateTargetEnv))) !== []
    && array_values(array_diff(gateEnvEffectLines($badPrivateCeilingEnv),
        gateDirectAccessGuardLines($badPrivateCeilingEnv))) !== []
    && array_values(array_diff(gateEnvEffectLines($badPrivateReasonEnv),
        gateDirectAccessGuardLines($badPrivateReasonEnv))) !== []);
check('webroot-конфиг, переназначающий обработчик, запрещён',
    gateForbiddenWebConfigFiles($fakeProjectRoot . '/www') === [$webConfig]);
check('PHP-backup с двойным расширением не выпадает из webroot-инвентаризации',
    gateForbiddenPhpBackupFiles($fakeProjectRoot . '/www') === [$phpBackup]);
check('штатный bin с ранним пустым 404 считается CLI-only', gateHasEarlyCli404Guard($goodCli));
check('известный bin нельзя превратить в публичный endpoint', !gateHasEarlyCli404Guard($editedCli));
check('даже безвредное действие до CLI/404-пролога запрещено', !gateHasEarlyCli404Guard($lateCli));

// Front-код может лежать не только в исторической папке www/js. Браузер без
// труда загрузит `www/css/x.js` или ES-модуль `.mjs`; оба обязаны попасть и в
// запрет собственного transport, и в синтаксическую проверку.
$hiddenJs = $sample('project/www/css/raw-transport.js', "fetch('/api/hidden.php');\n");
$hiddenMjs = $sample('project/www/assets/raw-transport.mjs', "export const x = () => fetch('/api/x.php');\n");
$allPublicScripts = array_merge(
    gateFilesUnder($fakeProjectRoot . '/www', 'js'),
    gateFilesUnder($fakeProjectRoot . '/www', 'mjs')
);
check('JS вне www/js всё равно входит в рекурсивную инвентаризацию',
    in_array($hiddenJs, $allPublicScripts, true) && gateFrontTransportLines($hiddenJs) !== []);
check('ES-модуль .mjs входит в ту же инвентаризацию',
    in_array($hiddenMjs, $allPublicScripts, true) && gateFrontTransportLines($hiddenMjs) !== []);

// ── 10. Забор нельзя убрать «между делом» ───────────────────────────────────

// Список ядра — не свободный перечень: он продублирован здесь и обязан
// совпадать. Дописать себя в ядро (и тем снять все проверки) было самым
// коротким обходом из всех.
$usesSrc = (string)file_get_contents(__DIR__ . '/uses_template_test.php');
preg_match('~\$CORE_API\s*=\s*\[(.*?)\];~s', $usesSrc, $mCore);
preg_match_all("~'([\\w./-]+\\.php)'~", $mCore[1] ?? '', $mNames);
$declaredCore = $mNames[1] ?? [];
sort($declaredCore); $expectedSorted = $expectedCore; sort($expectedSorted);
check('список файлов ядра не дополнен и не урезан', $declaredCore === $expectedSorted,
    'в гейте: ' . implode(', ', array_diff($declaredCore, $expectedSorted))
    . ' | пропало: ' . implode(', ', array_diff($expectedSorted, $declaredCore)));

preg_match('~\$ROOT_PHP\s*=\s*\[(.*?)\];~s', $usesSrc, $mRoot);
preg_match_all("~'([\\w./-]+\\.php)'~", $mRoot[1] ?? '', $mRootNames);
$declaredRoot = $mRootNames[1] ?? [];
sort($declaredRoot); $expectedRootSorted = $expectedRootPhp; sort($expectedRootSorted);
check('список допустимых корневых PHP не расширен', $declaredRoot === $expectedRootSorted,
    'лишнее: ' . implode(', ', array_diff($declaredRoot, $expectedRootSorted))
    . ' | пропало: ' . implode(', ', array_diff($expectedRootSorted, $declaredRoot)));

// index.php — готовый security-controller, а не место для бизнес-логики. Он
// обрабатывает неаутентифицированные install/lifecycle-ветки до session gate,
// поэтому локальный анализ отдельной вставки не может доказать безопасность
// всего control-flow. Держим точный content invariant: штатная разработка идёт
// в template.html/app.js/api, а изменение controller требует явной правки
// защищённой регрессии и видимого подтверждения агента.
// Обновлено 05.08.2026 по находке ревью: на опознании потолок на адрес теперь
// проверяется РАНЬШЕ личного ведра — иначе посторонний поток с подставными
// токенами раздувал файл счётчиков (замер: 260 строк и 30 КБ против 120 и 14 КБ).
// Обновлено 06.08.2026: поля кнопки повтора установки берутся теми же
// помощниками, что и транспортный забор, — на коробочном портале в POST нет ни
// APP_SID, ни DOMAIN, и кнопка не рисовалась вовсе.
// Обновлено 06.08.2026 (круг 2): install-ветка ловит незавершённую уборку прошлой
// установки и показывает готовый экран «Приложение убирает за собой» — раньше это
// исключение уходило в общий обработчик, и администратор в момент переустановки
// видел «Внутреннюю ошибку».
// Обновлено 06.08.2026 (круг 2, низкие): владелец фиксируется не только в момент
// установки — если сбой портала оставил приложение без owner, следующее открытие
// администратором записывает его, как и обещает текст на экране.
// Обновлено 08.08.2026: index.php стал сохранять ТАРИФ портала из уже
// полученного ответа app.info (см. api/plan.php). Отпечаток пересчитан
// осознанно — правка разобрана и названа, а не «подогнана под зелёное».
// Обновлено 09.08.2026 (ревью Кодекса, BXREV-011): обход первой установки
// перестал быть выключателем `ALLOW_UNVERIFIED_FIRST_INSTALL = true` — теперь
// это разовый билет на точный домен, с секретом от 32 знаков, сроком годности
// и сгоранием после применения (installUnverifiedTicketCheck/Burn в lib.php).
// Прежний выключатель принимал ЛЮБУЮ первую установку с ЛЮБОГО адреса, и
// достаточно было один раз забыть вернуть его обратно.
// Обновлено 09.08.2026 (круг ревью веером): отказ сохранения токенов при
// установке больше не печатает голый текст ошибки на белом фоне. Обе ветки —
// обычная и по разовому билету — пишут причину в журнал и показывают ту же
// тёмную страницу приложения с понятным текстом и подсказкой «повторите
// установку». Администратор портала видел техническую фразу без объяснения,
// что делать. Отпечаток пересчитан осознанно: правка названа и разобрана.
// Обновлено 09.08.2026 (круг ревью, R3-001 и R3-004): политика рамки iframe
// переехала в одну функцию api/b24.php и ПЕРЕСОБИРАЕТСЯ этим же ответом, как
// только портал подтвердил установку. Прежде заголовок собирался до разбора
// install POST'а, и свежая КОРОБКА не находила своего имени среди облачных масок
// — то есть блокировала собственную установку, а администратор видел пустую
// рамку. Заодно «тот же портал» сверяется без учёта регистра: точное сравнение
// объявляло переездом открытие с тем же именем в другом написании. Отпечаток
// пересчитан осознанно: обе правки названы, разобраны и закрыты проверками в
// tests/install_portal_test.php. Обновлено 10.08.2026: сохранение тарифа из
// app.info теперь не позволяет позднему старому ответу затереть более свежий;
// поведение закрыто проверками plan_test.php. В тот же день owner-private ссылка
// в комментарии заменена на общий bx-app KB; исполняемая логика не менялась.
// Обновлено 10.08.2026 (BX14-06): перед address/token buckets добавлен один
// fixed global identify ceiling. Semantic diff с post-BX13 frozen — ровно этот
// порядок и поясняющий комментарий; http_test.php теперь отдельно требует,
// чтобы focused rate-state прогон синхронизировал этот canonical hash.
// Обновлено 17.08.2026 (третий раз, тем же заходом): домен для рамки iframe
// берётся общим резолвером, а не своим чтением токенов. При работе постоянным
// ключом токенов нет, домен оставался пустым, и портал на собственном имени не
// мог встроить приложение — браузер показывал «отказано в подключении» вместо
// любого нашего текста. Закрыто живой проверкой заголовка в install_portal_test.
// Обновлено 17.08.2026 (второй раз, тем же заходом): разовый билет установки
// теперь принимает и тело БЕЗ секрета — портал поля install_ticket не шлёт и
// прислать не может, а на коробке без своего сервера авторизации других путей
// установки не осталось вовсе. Пропуском это не стало: без секрета подлинность
// обязан подтвердить сам портал ответом на присланный токен, и за токеном
// обязан стоять администратор; адрес портала берётся из настройки оператора, а
// хост сервера авторизации записаться как портал больше не может. Закрыто
// проверками в security_test.php и живым сценарием install_portal_test.php.
// Обновлено 17.08.2026: отказ первой установки пишет в журнал СОСТАВ тела —
// имена полей и хосты из SERVER_ENDPOINT/CLIENT_ENDPOINT. Без этого отказ на
// коробке неразбираем: домен резолвится в хост SERVER_ENDPOINT, и по одному
// имени не отличить «портал со своим сервером авторизации» от «портал, который
// своего адреса не прислал вовсе» — живой случай коробки клиента, где установка
// упиралась в это молча. Значения полей (в них лежат токены) не пишутся, строка
// идёт через maskSecrets. Поведение закрыто проверкой в install_portal_test.php.
// Обновлено 04.09.2026: забор «страница открывается только внутри Битрикс24»
// получил единственное исключение — доверенный предпросмотр с дев-портала.
// Ослаблением это не является: исключение требует подписи на ключе публикации,
// который лежит файлом на сервере и в браузер не попадает, а на боевом хостинге
// файла нет вовсе, поэтому там исключения не существует. Заведено потому, что
// иначе своё приложение нельзя увидеть, не поставив его на портал. Поведение
// закрыто живыми проверками в security_test.php — с подписью, с чужой подписью,
// с чужим именем зрителя, с просроченной и будущей меткой и вовсе без ключа.
// Обновлено 23.09.2026 (ревью, zhivoy-1): разметка читается appTemplateHtml() —
// из template.php выпуска за гардом 404 либо из template.html исходников.
// Поведение сначала закреплено в security_test.php (красное до правки).
$expectedIndexSha256 = '7c5a1e94effe6414eae42033a01ec5bfa4b9117142d64e4fe185a0ecf01948a7';
$actualIndexSha256 = hash_file('sha256', dirname(__DIR__) . '/www/index.php');
check('корневой security-controller index.php не изменён обходной вставкой',
    is_string($actualIndexSha256) && hash_equals($expectedIndexSha256, $actualIndexSha256),
    'sha256: ' . (string)$actualIndexSha256);
$indexSource = (string)file_get_contents(dirname(__DIR__) . '/www/index.php');
$leakNeedle = "require_once __DIR__ . '/env.php';";
$leakBranch = $leakNeedle . "\nif (isset(\$_GET['raw_state'])) { echo json_encode(storeRead(B24_TOKENS_FILE)); exit; }";
$leakyIndexSource = str_replace($leakNeedle, $leakBranch, $indexSource, $leakReplacements);
check('точный repro выдачи token-state ломает content invariant index.php',
    $leakReplacements === 1 && !hash_equals($expectedIndexSha256, hash('sha256', $leakyIndexSource)));

// ui.js — единственное разрешённое место сырого browser transport: именно он
// реализует готовый ui.api(). Поэтому исключение из общего transport-гейта
// держится точным content invariant, а не безусловным доверием к пути.
// Обновлено 06.08.2026: отказ теперь несёт полезную нагрузку ответа (err.data).
// Без неё форма не могла подсветить конкретное поле, а «задача уже идёт» не
// могла показать саму задачу — данные для этих веток терялись в транспорте.
// Правка того же дня: предупреждение живёт на экране столько же, сколько ошибка
// (4 секунды не хватало, чтобы прочитать, почему «успех» ничего не значит).
// Обновлено 09.08.2026 (ревью Кодекса, BXREV-022): у тостов появились роль и
// живая область, у диалога — связь с заголовком и текстом, у состояния загрузки
// — aria-busy. Прежде экранный диктор молчал ровно там, где человек видит
// «Готово» или вопрос «точно?»: разметка была только для глаз.
// Обновлено 09.08.2026 (круг ревью, R3-022): окно подтверждения стало
// одиночным. Прежде два вызова подряд рисовали ДВА overlay с одинаковыми id —
// человек отвечал, окно закрывалось, и под ним обнаруживалось точно такое же,
// а первый вопрос оставался без ответа. Теперь показ идёт через очередь (на
// экране всегда одно окно), id уникальны, а запрет прокрутки фона держится
// счётчиком и снимается при любом исходе. Дребезг одной кнопки склеивается в
// один вопрос ТОЛЬКО по явному ключу {key}: склейка по тексту схлопнула бы
// снятие доступа у двух разных сотрудников — тексты в списке одинаковы.
// Закрыто проверками в tests/ui-stand.js, в том числе прогоном с подсаженным
// нарушением (замок не ставится) и обратной проверкой «разные вопросы не
// склеиваются».
// Обновлено 10.08.2026: empty/error получили live-region semantics, чтобы
// экранный диктор услышал завершение загрузки и отказ. Поведение закреплено
// живым Chromium-тестом, а не одной сменой отпечатка.
// Обновлено 28.08.2026: готовые modal/toast/state получили обязательные
// enter/exit-переходы 120–200 мс и reduced-motion; exit завершается до удаления
// DOM. Поведение сначала закреплено в ui-stand, затем обновлён exact fingerprint.
// Обновлено 23.09.2026 (ревью, ux_sboev-5/6/7): истёкший пропуск ведёт к «Открыть
// заново» через BX24.reloadWindow, тост встаёт в видимую часть окна без прокрутки
// портала, шапка получает индикатор связи и версию выпуска. Поведение сначала
// закреплено в tests/ux_test.php (красное до правки), затем обновлён отпечаток.
$expectedUiSha256 = 'ad9d179d7f9dd3ea38f83c939fa2f76d619817c69193e617da81e1ea031d094b';
$uiPath = dirname(__DIR__) . '/www/js/ui.js';
$actualUiSha256 = hash_file('sha256', $uiPath);
check('готовый transport-модуль ui.js не изменён обходной вставкой',
    is_string($actualUiSha256) && hash_equals($expectedUiSha256, $actualUiSha256),
    'sha256: ' . (string)$actualUiSha256);
$uiSource = (string)file_get_contents($uiPath);
$rawUiMutation = $uiSource . "\nwindow.ui.rawFetch = function () { return fetch('/api/raw.php'); };\n";
check('точный repro нового сырого transport в ui.js ломает content invariant',
    !hash_equals($expectedUiSha256, hash('sha256', $rawUiMutation)));

preg_match('~\$CORE_BIN\s*=\s*\[(.*?)\];~s', $usesSrc, $mBin);
preg_match_all("~'([\\w./-]+\\.php)'~", $mBin[1] ?? '', $mBinNames);
$declaredBin = $mBinNames[1] ?? [];
sort($declaredBin); $expectedBinSorted = $expectedBinPhp; sort($expectedBinSorted);
check('список разрешённых CLI-точек не дополнен и не урезан', $declaredBin === $expectedBinSorted,
    'лишнее: ' . implode(', ', array_diff($declaredBin, $expectedBinSorted))
    . ' | пропало: ' . implode(', ', array_diff($expectedBinSorted, $declaredBin)));
check('uses_template инвентаризирует JS/MJS/CJS во всём www, а не только www/js',
    substr_count($usesSrc, "gateFilesUnder(\$root . '/www', 'js')") >= 2
    && substr_count($usesSrc, "gateFilesUnder(\$root . '/www', 'mjs')") >= 2
    && substr_count($usesSrc, "gateFilesUnder(\$root . '/www', 'cjs')") >= 2
    && strpos($usesSrc, "gateFilesUnder(\$root . '/www/js', 'js')") === false
    && strpos($usesSrc, "[\$root . '/www/index.php']") !== false);
check('www/bin не разрешён гейтом как свободный префикс',
    strpos($usesSrc, "['www/api/', 'www/bin/']") === false
    && strpos($usesSrc, "['www/api/']") !== false);
check('uses_template собирает весь PHP-like source, а не только .php',
    strpos($usesSrc, 'gatePhpSourceFilesUnder($root') !== false
    && strpos($usesSrc, 'gateForbiddenWebConfigFiles($root') !== false
    && strpos($usesSrc, 'gateForbiddenPhpBackupFiles($root') !== false);

// Обязательные наборы названы в двух местах, и оба обязаны совпадать: иначе
// достаточно было удалить два файла — оставалось ровно пять, порог молчал.
$runSrc   = (string)file_get_contents(__DIR__ . '/run.php');
$checkSrc = (string)file_get_contents(dirname(__DIR__) . '/tools/self-check.sh');
// Контракт наборов разбираем функцией, а не по месту: тем же кодом ниже гоняются
// контрпримеры. Проверка, которую нельзя покрасить намеренно, не доказывает ничего.
$parseSuiteContract = static function (string $src): array {
    preg_match('~const\s+REQUIRED_SUITES\s*=\s*\[(.*?)\];~s', $src, $mReq);
    preg_match_all("~'([\\w.-]+_test\\.php)'~", $mReq[1] ?? '', $mReqNames);
    $names = $mReqNames[1] ?? [];
    sort($names);
    preg_match('~const\s+REQUIRED_SUITE_MIN_CHECKS\s*=\s*\[(.*?)\];~s', $src, $mMins);
    preg_match_all("~'([\\w.-]+_test\\.php)'\\s*=>\\s*([0-9]+)~", $mMins[1] ?? '', $mMinRows, PREG_SET_ORDER);
    $mins = [];
    foreach ($mMinRows as $row) $mins[$row[1]] = (int)$row[2];
    return ['required' => $names, 'mins' => $mins];
};
$parsedContract = $parseSuiteContract($runSrc);
$required = $parsedContract['required'];
$expectedSuites = ['b24_test.php', 'gate_test.php', 'http_test.php', 'install_portal_test.php',
                   'modules_test.php', 'resource_limits_test.php', 'review_fixes_test.php',
                   'notify_test.php', 'fatal_test.php', 'security_test.php', 'snap_test.php', 'store_test.php', 'task_test.php', 'uf_test.php',
                   'crm_dict_test.php', 'plan_test.php', 'capability_test.php', 'uses_template_test.php',
                   // Ревью 23.09.2026 (dubli-5): три набора шаблона лежали вне всех
                   // списков-заборов и выдавались за «наборы проекта» — удали, и
                   // самопроверка оставалась зелёной (в т.ч. 9 проверок границ авторизации).
                   'architecture_tools_test.php', 'authorization_boundary_test.php', 'effective_role_test.php',
                   'ux_test.php', 'perf_test.php'];
sort($expectedSuites);
// Нижняя граница порога КАЖДОГО набора шаблона. Это не копия контракта: поднять
// порог в run.php можно не трогая гейт, опустить ниже этих чисел — нельзя.
// Занижённый порог молча принимает набор-заглушку, и раннер остаётся зелёным —
// воспроизведено прогоном песочницы ниже.
$expectedSuiteFloors = [
    'b24_test.php' => 29, 'gate_test.php' => 112, 'http_test.php' => 15,
    'install_portal_test.php' => 30,
    'modules_test.php' => 20, 'resource_limits_test.php' => 8, 'review_fixes_test.php' => 100,
    'security_test.php' => 20, 'snap_test.php' => 24, 'notify_test.php' => 26,
    'fatal_test.php' => 15, 'store_test.php' => 26, 'uf_test.php' => 28,
    'task_test.php' => 16, 'crm_dict_test.php' => 12, 'plan_test.php' => 16,
    'capability_test.php' => 16, 'uses_template_test.php' => 93,
    'architecture_tools_test.php' => 6, 'authorization_boundary_test.php' => 8, 'effective_role_test.php' => 5,
    'ux_test.php' => 12, 'perf_test.php' => 8,
];
$actualSuites = array_map('basename', glob(__DIR__ . '/*_test.php') ?: []);
sort($actualSuites);
$inScript = [];
foreach ($required as $suite) {
    if (strpos($checkSrc, 'tests/' . $suite) !== false) $inScript[] = $suite;
}
check('в tests/run.php обязательны все наборы забора', $required === $expectedSuites,
    'обязательны: ' . implode(', ', $required));
// Наборы ШАБЛОНА обязаны быть на месте все до одного и с не занижёнными
// порогами; наборы ПРОЕКТА рядом с ними законны и контракт не ослабляют.
// Прежде сравнение шло на строгое равенство, и первый же свой `*_test.php`
// красил гейт — то есть проект мог либо писать свои проверки, либо иметь
// зелёный гейт, но не оба сразу. Ревью K2-12 (09.08.2026): вместе с
// послаблением от проверки осталась одна сторона — присутствие файлов; ни
// занижённый порог, ни подмена набора заглушкой не ловились, а название
// проверки при этом обещало обратное.
$suiteContractProblems = static function (string $src, array $suiteFiles)
    use ($parseSuiteContract, $expectedSuites, $expectedSuiteFloors): array {
    $parsed = $parseSuiteContract($src);
    $problems = [];
    $noFile = array_values(array_diff($expectedSuites, $suiteFiles));
    if ($noFile !== []) $problems[] = 'нет наборов шаблона: ' . implode(', ', $noFile);
    if ($parsed['required'] !== $expectedSuites) {
        $problems[] = 'REQUIRED_SUITES разошёлся: лишнее ['
            . implode(', ', array_diff($parsed['required'], $expectedSuites)) . '], пропало ['
            . implode(', ', array_diff($expectedSuites, $parsed['required'])) . ']';
    }
    foreach ($expectedSuiteFloors as $suite => $floor) {
        if (!isset($parsed['mins'][$suite])) { $problems[] = "снят порог набора $suite"; continue; }
        if ($parsed['mins'][$suite] < $floor) {
            $problems[] = "порог $suite занижен до {$parsed['mins'][$suite]}, граница $floor";
        }
    }
    return $problems;
};
$suiteProblems = $suiteContractProblems($runSrc, $actualSuites);
check('наборы шаблона на месте и их пороги не занижены', $suiteProblems === [],
    implode(' | ', $suiteProblems));
$floorNames = array_keys($expectedSuiteFloors); sort($floorNames);
check('у каждого набора шаблона в гейте есть нижняя граница порога',
    $floorNames === $expectedSuites,
    'без границы: ' . implode(', ', array_diff($expectedSuites, $floorNames)));
// Послабление 09.08.2026 обязано остаться: свой набор проекта подхватывается сам
// и в контракте не регистрируется. Сравниваем со списком проблем без него —
// иначе проверка краснела бы заодно с любым посторонним нарушением контракта.
check('свой набор проекта рядом с наборами шаблона контракт не ослабляет',
    $suiteContractProblems($runSrc, array_merge($actualSuites, ['project_report_test.php']))
    === $suiteProblems);
// Контрпримеры: каждая сторона обязана уметь покраснеть.
$droppedSuiteSrc = str_replace("'store_test.php',", '', $runSrc);
check('образец: набор шаблона, выпавший из REQUIRED_SUITES, красит проверку',
    $droppedSuiteSrc !== $runSrc && $suiteContractProblems($droppedSuiteSrc, $actualSuites) !== []);
check('образец: пропавший файл набора шаблона красит проверку',
    $suiteContractProblems($runSrc, array_values(array_diff($actualSuites, ['store_test.php']))) !== []);
// Порог образца опускаем до нуля, а не до единицы: единица могла совпасть с уже
// занижённым порогом, и тогда контрпример молча перестал бы быть контрпримером.
$loweredSuiteSrc = (string)preg_replace("~('store_test\\.php'\\s*=>\\s*)\\d+~", '${1}0', $runSrc, 1);
check('образец: занижённый порог набора шаблона красит проверку',
    $loweredSuiteSrc !== $runSrc && $suiteContractProblems($loweredSuiteSrc, $actualSuites) !== []);
check('тот же список продублирован в tools/self-check.sh', $inScript === $required,
    'нет в скрипте: ' . implode(', ', array_diff($required, $inScript)));

// ПОТОЛОК ПАМЯТИ ПРОГОНА. У CLI-PHP предела нет вовсе, и 04.09.2026 круг
// самопроверки (его запускает Stop-хук в конце КАЖДОГО хода агента, да ещё и
// долями) вырос до 15,7 ГБ и уронил хост вместе с чужими боевыми сервисами.
// Потолок обязан жить в самом скрипте, а не в памяти того, кто его запускает:
// «запускайте с лимитом» — это не забор, а пожелание.
$memoryGuardProblems = [];
if (strpos($checkSrc, 'BXAPP_TEST_MEMORY_LIMIT') === false) {
    $memoryGuardProblems[] = 'нет настройки потолка памяти';
}
if (strpos($checkSrc, 'PHP_INI_SCAN_DIR') === false || strpos($checkSrc, 'memory_limit=%s') === false) {
    // Именно наследуемый файл настроек: дочерних php в круге больше десятка,
    // и точечные `-d` следующий тест забудет молча.
    $memoryGuardProblems[] = 'потолок не наследуется дочерними php';
}
if (strpos($checkSrc, 'MemAvailable') === false) {
    $memoryGuardProblems[] = 'число долей не считается по свободной памяти';
}
$serverSrc = (string)@file_get_contents(dirname(__DIR__) . '/tests/lib.php');
if (strpos($serverSrc, "'memory_limit=' . \$pamyat") === false) {
    $memoryGuardProblems[] = 'тестовый веб-сервер поднимается без потолка памяти';
}
check('ПРОГОН НЕ МОЖЕТ СЪЕСТЬ ХОСТ: ПОТОЛОК ПАМЯТИ ЗАШИТ В САМ КРУГ',
    $memoryGuardProblems === [], implode(' · ', $memoryGuardProblems));
$missingPhpLintExt = [];
foreach (['php', 'pht', 'phtm', 'phtml', 'php3', 'php4', 'php5', 'php6',
          'php7', 'php8', 'phar', 'phps', 'inc'] as $ext) {
    if (strpos($checkSrc, "-iname '*.$ext'") === false) $missingPhpLintExt[] = $ext;
}
$missingJsLintExt = [];
foreach (['js', 'mjs', 'cjs'] as $ext) {
    if (strpos($checkSrc, "-iname '*.$ext'") === false) $missingJsLintExt[] = $ext;
}
check('self-check линтит все PHP-like расширения', $missingPhpLintExt === [],
    'не линтятся: ' . implode(', ', $missingPhpLintExt));
check('self-check линтит JS/MJS/CJS рекурсивно', $missingJsLintExt === [],
    'не линтятся: ' . implode(', ', $missingJsLintExt));
check('этот набор сам входит в обязательные', in_array('gate_test.php', $required, true));
check('гейт «пользуйся готовым» входит в обязательные', in_array('uses_template_test.php', $required, true));
check('регрессии глубокого ревью входят в обязательные', in_array('review_fixes_test.php', $required, true));
check('проверки клиента B24 входят в обязательные', in_array('b24_test.php', $required, true));
check('проверки готовых модулей входят в обязательные', in_array('modules_test.php', $required, true));
check('ресурсные пределы готовых модулей входят в обязательные',
    in_array('resource_limits_test.php', $required, true));
check('runner блокирует ранний exit из include-набора до завершения',
    strpos($runSrc, "['__tests_completed'] = false") !== false
    && strpos($runSrc, 'САМОПРОВЕРКА НЕ ЗАВЕРШЕНА') !== false);
check('runner не принимает обязательный набор без единой проверки',
    strpos($runSrc, '$beforeSuite') !== false
    && strpos($runSrc, 'не выполнил ни одной проверки') !== false);
$suiteMins = $parsedContract['mins'];
$suiteMinNames = array_keys($suiteMins); sort($suiteMinNames);
check('каждый обязательный suite имеет отдельный порог выше одной заглушки',
    $suiteMinNames === $required
    && array_values(array_filter($suiteMins, static fn(int $minimum): bool => $minimum < 2)) === []);

// Тот же контракт — работой, а не чтением исходника: отдельный прогон раннера в
// песочнице, где каждый набор заменён заглушкой ровно на свой порог, а один —
// на одну проверку. При штатном контракте прогон обязан упасть и назвать
// виновника; с занижённым порогом та же заглушка проходит молча —
// поймать её может только нижняя граница выше, поэтому она и заведена.
$suiteSandbox = $dir . '/suite-contract';
$sandboxTests = $suiteSandbox . '/tests';
@mkdir($sandboxTests, 0700, true);
$sandboxReady = is_dir($sandboxTests) && count($suiteMins) === count($expectedSuites)
    && (is_link($suiteSandbox . '/www') || @symlink(dirname(__DIR__) . '/www', $suiteSandbox . '/www'))
    && @copy(__DIR__ . '/lib.php', $sandboxTests . '/lib.php');
$sandboxRun = static function (string $runSource, string $stubbed, int $stubChecks)
    use ($suiteSandbox, $sandboxTests, $suiteMins): array {
    foreach ($suiteMins as $suite => $minimum) {
        $count = $suite === $stubbed ? $stubChecks : $minimum;
        file_put_contents($sandboxTests . '/' . $suite,
            "<?php\nfor (\$i = 0; \$i < $count; \$i++) check('заглушка', true);\n");
    }
    file_put_contents($sandboxTests . '/run.php', $runSource);
    $out = []; $rc = 0;
    @exec('cd ' . escapeshellarg($suiteSandbox) . ' && ' . escapeshellarg(PHP_BINARY)
        . ' tests/run.php 2>/dev/null', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
};
// Песочница не собралась — исход «неизвестно», а не «пройдено»: значения по
// умолчанию у обеих проверок заведомо провальные.
$stubRun = $sandboxReady ? $sandboxRun($runSrc, 'store_test.php', 1)
    : ['rc' => 0, 'out' => 'песочница не собралась'];
check('образец: набор шаблона, подменённый заглушкой, роняет прогон поимённо',
    $stubRun['rc'] !== 0 && strpos($stubRun['out'], 'store_test.php') !== false,
    'код ' . $stubRun['rc'] . ', вывод: ' . mb_substr(trim($stubRun['out']), 0, 140));
$loweredRun = $sandboxReady ? $sandboxRun($loweredSuiteSrc, 'store_test.php', 1)
    : ['rc' => 1, 'out' => 'песочница не собралась'];
check('занижённый порог раннер пропускает — заглушку ловит только гейт',
    $loweredRun['rc'] === 0 && $suiteContractProblems($loweredSuiteSrc, $actualSuites) !== [],
    'код ' . $loweredRun['rc'] . ', вывод: ' . mb_substr(trim($loweredRun['out']), 0, 140));

$shardProbeDir = $suiteSandbox . '/shards';
@mkdir($shardProbeDir, 0700, true);
$shardProbeOk = $sandboxReady && is_dir($shardProbeDir);
$shardProbeDetails = [];
for ($probeIndex = 0; $probeIndex < 2 && $shardProbeOk; $probeIndex++) {
    $probeOut = []; $probeRc = 0;
    @exec('cd ' . escapeshellarg($suiteSandbox)
        . ' && BXAPP_SHARD_TOTAL=2 BXAPP_SHARD_INDEX=' . $probeIndex
        . ' BXAPP_SHARD_REPORT=' . escapeshellarg($shardProbeDir . '/report-' . $probeIndex . '.json')
        . ' ' . escapeshellarg(PHP_BINARY) . ' tests/run.php 2>&1', $probeOut, $probeRc);
    $shardProbeDetails[] = 'доля ' . $probeIndex . ': ' . $probeRc;
    if ($probeRc !== 0) $shardProbeOk = false;
}
$aggregateOut = []; $aggregateRc = 1;
if ($shardProbeOk) {
    @exec('cd ' . escapeshellarg($suiteSandbox)
        . ' && BXAPP_SHARD_TOTAL=2 BXAPP_SHARD_AGGREGATE_DIR=' . escapeshellarg($shardProbeDir)
        . ' ' . escapeshellarg(PHP_BINARY) . ' tests/run.php 2>&1', $aggregateOut, $aggregateRc);
}
$aggregateText = implode("\n", $aggregateOut);
check('две доли объединяются в единственный полный completion-marker',
    $shardProbeOk && $aggregateRc === 0
    && preg_match_all('~^BXAPP_SELF_CHECK_COMPLETE:[0-9]+$~m', $aggregateText) === 1,
    implode(', ', $shardProbeDetails) . '; агрегатор: ' . $aggregateRc);

@rename($shardProbeDir . '/report-1.json', $shardProbeDir . '/report-1.missing');
$missingShardOut = []; $missingShardRc = 0;
@exec('cd ' . escapeshellarg($suiteSandbox)
    . ' && BXAPP_SHARD_TOTAL=2 BXAPP_SHARD_AGGREGATE_DIR=' . escapeshellarg($shardProbeDir)
    . ' ' . escapeshellarg(PHP_BINARY) . ' tests/run.php 2>&1', $missingShardOut, $missingShardRc);
check('потерянный отчёт доли делает агрегатор красным',
    $missingShardRc !== 0 && strpos(implode("\n", $missingShardOut), 'отчёт доли 2') !== false,
    'код ' . $missingShardRc);

foreach (glob($shardProbeDir . '/*') ?: [] as $probeFile) @unlink($probeFile);
@rmdir($shardProbeDir);
foreach (array_keys($suiteMins) as $suite) @unlink($sandboxTests . '/' . $suite);
@unlink($sandboxTests . '/run.php'); @unlink($sandboxTests . '/lib.php');
@unlink($suiteSandbox . '/www');
@rmdir($sandboxTests); @rmdir($suiteSandbox);

check('self-check требует итоговый completion-marker раннера',
    strpos($runSrc, 'BXAPP_SELF_CHECK_COMPLETE:') !== false
    && strpos($checkSrc, '^BXAPP_SELF_CHECK_COMPLETE:[0-9]+$') !== false);

$fingerprintScopeProblems = static function (string $src): array {
    $problems = [];
    if (strpos($src, 'FINGERPRINT_DIRS=".claude docs scripts tests tools www"') === false) {
        $problems[] = 'нет явного allowlist каталогов приложения';
    }
    if (strpos($src, 'FINGERPRINT_ROOT_FILES=".bxapp-core.json .portal-meta.json AGENTS.md CLAUDE.md PORTING.md README.md SECURITY.md deploy-prod.sh"') === false) {
        $problems[] = 'нет явного allowlist корневых контрактов';
    }
    if (strpos($src, 'find "$PROJECT_DIR" -type f') !== false) {
        $problems[] = 'хешируется весь корень вместе с архивами и соседними проектами';
    }
    if (strpos($src, 'mv -f -- "$fp_tmp" "$FP_FILE"') === false) {
        $problems[] = 'квитанция публикуется не атомарно';
    }
    return $problems;
};
$fingerprintProblems = $fingerprintScopeProblems($checkSrc);
check('быстрая квитанция Stop хеширует только приложение и публикуется атомарно',
    $fingerprintProblems === [], implode(' | ', $fingerprintProblems));
$broadFingerprint = str_replace(
    'FINGERPRINT_DIRS=".claude docs scripts tests tools www"',
    'FINGERPRINT_DIRS="."',
    $checkSrc
);
check('образец: возврат к хешированию всего корня красит договор квитанции',
    $broadFingerprint !== $checkSrc && $fingerprintScopeProblems($broadFingerprint) !== []);
check('параллельный раннер имеет отчёты долей и отдельный fail-closed агрегатор',
    strpos($runSrc, 'BXAPP_SHARD_REPORT') !== false
    && strpos($runSrc, 'BXAPP_SHARD_AGGREGATE_DIR') !== false
    && strpos($runSrc, 'BXAPP_SHARD_COMPLETE:') !== false
    && strpos($checkSrc, 'BXAPP_SELF_CHECK_JOBS') !== false
    && strpos($checkSrc, 'BXAPP_SHARD_AGGREGATE_DIR') !== false);

// Полный список файлов забора — тоже контракт. Одного перечня suites мало:
// deploy мог остаться на месте, но перестать запускать этот забор перед сетью.
$gateFilesParsed = preg_match('~^GATE_FILES="([^"\r\n]+)"$~m', $checkSrc, $mGateFiles) === 1;
$declaredGateFiles = $gateFilesParsed
    ? preg_split('~\s+~', trim((string)$mGateFiles[1]), -1, PREG_SPLIT_NO_EMPTY) : [];
$expectedGateFiles = [
    '.claude/settings.json', 'AGENTS.md', 'CLAUDE.md', 'deploy-prod.sh',
    'tools/self-check.sh', 'tools/env-define-names.php', 'tools/env-contract-bump.php',
    // Манифест ядра помимо www/api и www/bin (ревью 23.09.2026, dubli-4): пропал —
    // гейт и сверка ядра перестают видеть фронтовой движок как ядро.
    'tools/core-extra.list',
    // Эталон обязательных настроек и история его версий. Без них набор настроек
    // меняется молча, а выкладка встаёт уже на боевом сервере — с текстом, из
    // которого не следует, что именно устарело.
    'tests/env-contract.txt', 'tests/env-contract-history.txt',
    'tests/run.php', 'tests/gate-lib.php',
    // Живые прогоны: они не запускаются на каждом ходу, но их исчезновение
    // означает, что интерфейс больше никто не прокликивает, — а самопроверка
    // при этом оставалась зелёной (круг 2 ревью 06.08.2026).
    'tests/live-harness.js', 'tests/settings-live.js', 'tests/task-live.js', 'tests/install-live.js',
    'tests/ui-stand.js', 'tests/ui-stand.html', 'tests/logs-live.js', 'tests/access-live.js',
];
foreach ($expectedSuites as $suite) $expectedGateFiles[] = 'tests/' . $suite;
sort($declaredGateFiles); sort($expectedGateFiles);
check('self-check защищает точный полный набор файлов забора, включая deploy',
    $gateFilesParsed && $declaredGateFiles === $expectedGateFiles,
    'лишнее: ' . implode(', ', array_diff($declaredGateFiles, $expectedGateFiles))
    . ' | пропало: ' . implode(', ', array_diff($expectedGateFiles, $declaredGateFiles)));

// Деплой обязан начать с единственного ДОСТИЖИМОГО self-check fail-closed.
// Одной позиции перед SSH мало: вызов можно спрятать в невызванную функцию /
// `if false` либо подменить shell-функцией bash. Поэтому до блока разрешён ровно
// канонический верхнеуровневый пролог без прикладных и сетевых действий, а
// дочерний shell запускается через путь текущего Bash, не по затеняемому имени.
$deploySrc = (string)file_get_contents(dirname(__DIR__) . '/deploy-prod.sh');

// Между «старого live уже нет» и «новый live на месте» сайт отдаёт «страница не
// найдена» ВСЕМ: и людям в портале, и самому порталу с его событиями. Убить
// процесс в этот момент можно и без нашего согласия (перезагрузка, OOM, kill -9),
// а trap такое не ловит. Полностью атомарной замены каталога симлинком в POSIX
// нет, поэтому единственное, чем управляем, — ДЛИНА окна: между двумя
// переименованиями не должно быть НИ ОДНОЙ лишней команды.
$windowOps = null;
if (preg_match("~mv '\\\$\{REMOTE_WWW\}' '\\\$\{LEGACY_DIR\}'(.*?)mv -Tf '\\\$\{LIVE_TMP\}' '\\\$\{REMOTE_WWW\}'~s",
        $deploySrc, $wm)) {
    $windowOps = [];
    foreach (explode("\n", $wm[1]) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $windowOps[] = $line;
    }
}
check('окно «сайта нет» при переезде на release сведено к одному переименованию',
    $windowOps !== null && $windowOps === [],
    $windowOps === null ? 'не нашёл обе фазы переезда в deploy-prod.sh'
        : 'между фазами выполняется: ' . implode(' | ', $windowOps));
$deployCheckPattern = '~^if ! "\$\{BASH\}" "\$\{PROJECT_DIR\}/tools/self-check\.sh"; then\R'
    . '(?:[ \t]+.*\R)+^fi$~mu';
$deployPreamble = [
    'set -Eeuo pipefail',
    'PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"',
    'echo "=== 0. Самопроверка проекта ==="',
    'if [ ! -f "${PROJECT_DIR}/tools/self-check.sh" ]; then',
    'echo "Деплой остановлен: нет tools/self-check.sh — проверять нечем." >&2',
    'exit 1',
    'fi',
];
$deployFailClosedBlock = [
    'if ! "${BASH}" "${PROJECT_DIR}/tools/self-check.sh"; then',
    'echo "Деплой остановлен: самопроверка красная — выкладывать нельзя." >&2',
    'exit 1',
    'fi',
];
$deployCodeLines = static function (string $source): array {
    $result = [];
    foreach (preg_split('~\R~u', $source) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $result[] = $line;
    }
    return $result;
};
$deploySelfCheckBeforeRemote = static function (string $source) use (
    $deployCheckPattern, $deployPreamble, $deployFailClosedBlock, $deployCodeLines
): bool {
    $matches = [];
    if (preg_match_all($deployCheckPattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) return false;
    $block = (string)$matches[0][0][0];
    $offset = (int)$matches[0][0][1];
    // Граница «дальше начинается работа с сервером» — определение самой функции
    // обращения, а не конкретная строка с ssh. По строке забор ломался от
    // безобидного оформления: стоило завести ветку для подменяемого транспорта
    // (испытания выкладки), и `SSH=(ssh` уехал под отступ — проверка перестала
    // находить границу и покраснела на исправном коде.
    $remoteBoundary = strpos($source, 'remote() {');
    return $deployCodeLines(substr($source, 0, $offset)) === $deployPreamble
        && $deployCodeLines($block) === $deployFailClosedBlock
        && $remoteBoundary !== false && $offset + strlen($block) < $remoteBoundary;
};
check('deploy начинает с достижимого self-check fail-closed до любой публикации',
    $deploySelfCheckBeforeRemote($deploySrc));

$deployMutationNo = 0;
$deployMutationHasSyntax = static function (string $source) use ($sample, &$deployMutationNo): bool {
    $path = $sample('deploy-mutation-' . (++$deployMutationNo) . '.sh', $source);
    $out = [];
    $rc = 0;
    @exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$deployMatch = [];
$deployBlockFound = preg_match($deployCheckPattern, $deploySrc, $deployMatch, PREG_OFFSET_CAPTURE) === 1;
$deployWithoutCheck = $deployBlockFound
    ? substr_replace($deploySrc, '', (int)$deployMatch[0][1], strlen((string)$deployMatch[0][0]))
    : $deploySrc;
check('мутация с удалённым self-check блокируется гейтом',
    $deployBlockFound && !$deploySelfCheckBeforeRemote($deployWithoutCheck));
$deployWithLateCheck = $deployBlockFound
    ? $deployWithoutCheck . "\n" . (string)$deployMatch[0][0] . "\n" : $deploySrc;
check('мутация с self-check после удалённых действий блокируется гейтом',
    $deployBlockFound && !$deploySelfCheckBeforeRemote($deployWithLateCheck));
$nonBlockingCount = 0;
$deployNonBlockingBlock = $deployBlockFound
    ? str_replace("  exit 1\n", "  true\n", (string)$deployMatch[0][0], $nonBlockingCount) : '';
$deployWithoutFailClosed = $deployBlockFound
    ? substr_replace($deploySrc, $deployNonBlockingBlock,
        (int)$deployMatch[0][1], strlen((string)$deployMatch[0][0])) : $deploySrc;
check('мутация без fail-closed выхода блокируется гейтом',
    $nonBlockingCount === 1 && $deployMutationHasSyntax($deployWithoutFailClosed)
    && !$deploySelfCheckBeforeRemote($deployWithoutFailClosed));

$deployWithEarlyNetwork = $deployBlockFound
    ? substr_replace($deploySrc, "rsync -a ./stage/ user@example:/release/\n",
        (int)$deployMatch[0][1], 0) : $deploySrc;
check('мутация с удалённой операцией до self-check блокируется гейтом',
    $deployBlockFound && $deployMutationHasSyntax($deployWithEarlyNetwork)
    && !$deploySelfCheckBeforeRemote($deployWithEarlyNetwork));

$deployInDeadFunction = $deployBlockFound
    ? substr_replace($deploySrc, "unused_deploy_gate() {\n" . (string)$deployMatch[0][0] . "\n}\n",
        (int)$deployMatch[0][1], strlen((string)$deployMatch[0][0])) : $deploySrc;
check('мутация с self-check в невызванной функции блокируется гейтом',
    $deployBlockFound && $deployMutationHasSyntax($deployInDeadFunction)
    && !$deploySelfCheckBeforeRemote($deployInDeadFunction));

$deployInFalseBranch = $deployBlockFound
    ? substr_replace($deploySrc, "if false; then\n" . (string)$deployMatch[0][0] . "\nfi\n",
        (int)$deployMatch[0][1], strlen((string)$deployMatch[0][0])) : $deploySrc;
check('мутация с self-check в ложной ветке блокируется гейтом',
    $deployBlockFound && $deployMutationHasSyntax($deployInFalseBranch)
    && !$deploySelfCheckBeforeRemote($deployInFalseBranch));

$shadowedDeployBlock = $deployBlockFound
    ? str_replace('if ! "${BASH}"', 'if ! bash', (string)$deployMatch[0][0]) : '';
$deployWithShadowedBash = $deployBlockFound
    ? substr_replace($deploySrc, "bash() { return 0; }\n" . $shadowedDeployBlock,
        (int)$deployMatch[0][1], strlen((string)$deployMatch[0][0])) : $deploySrc;
check('мутация с подменённым bash блокируется гейтом',
    $deployBlockFound && $deployMutationHasSyntax($deployWithShadowedBash)
    && !$deploySelfCheckBeforeRemote($deployWithShadowedBash));

$nestedFalseExitBlock = $deployBlockFound
    ? str_replace("  exit 1\n", "  if false; then\n    exit 1\n  fi\n",
        (string)$deployMatch[0][0]) : '';
$deployWithDeadExit = $deployBlockFound
    ? substr_replace($deploySrc, $nestedFalseExitBlock,
        (int)$deployMatch[0][1], strlen((string)$deployMatch[0][0])) : $deploySrc;
check('мутация с exit в ложной вложенной ветке блокируется гейтом',
    $deployBlockFound && $deployMutationHasSyntax($deployWithDeadExit)
    && !$deploySelfCheckBeforeRemote($deployWithDeadExit));

// Куски скрипта, уезжающие на сервер, локальный `bash -n` НЕ разбирает: для него
// это обычная строка в кавычках или текст heredoc. 06.08.2026 из-за этого в бой
// уехала ветка перевода обычной папки на release, где перенос строки стоял ПЕРЕД
// `&&`. Для sh строка, начинающаяся с `&&`, — синтаксическая ошибка; серверный
// bash не разбирал кусок целиком, и перевод не запускался ни разу. Локально всё
// было зелёным. Поэтому разбираем каждый серверный кусок отдельно.
$deployRemoteChunks = static function (string $source): array {
    $chunks = [];
    $len = strlen($source);
    // 1. Аргумент функции remote "..." — ОДНО слово shell. Внутри него кавычки
    // открываются и закрываются сколько угодно раз: `... [ -n "\$x" ] ...` тоже
    // одно слово, потому что shell склеивает соседние куски. Читаем ровно по
    // этому правилу и снимаем экранирование — на сервер уезжает результат.
    $pos = 0;
    while (($at = strpos($source, 'remote "', $pos)) !== false) {
        $i = $at + strlen('remote "');
        $buf = '';
        $inDouble = true;
        while ($i < $len) {
            $ch = $source[$i];
            if ($inDouble) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $next = $source[$i + 1];
                    $buf .= strpos('"\\$`', $next) !== false ? $next : $ch . $next;
                    $i += 2;
                    continue;
                }
                if ($ch === '"') { $inDouble = false; $i++; continue; }
                $buf .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"') { $inDouble = true; $i++; continue; }
            if ($ch === "'") {
                $close = strpos($source, "'", $i + 1);
                if ($close === false) break;
                $buf .= substr($source, $i + 1, $close - $i - 1);
                $i = $close + 1;
                continue;
            }
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $source[$i + 1]; $i += 2; continue; }
            // Слово закончилось: незакавыченный пробел, перевод строки или
            // разделитель команд.
            if (strpos(" \t\n;|&)", $ch) !== false) break;
            $buf .= $ch;
            $i++;
        }
        $chunks[] = $buf;
        $pos = $i + 1;
    }
    // 2. heredoc, который скармливают удалённому `bash -s`. PHP-heredoc сюда не
    // попадает: отбираем по самой строке запуска, а не по имени метки.
    if (preg_match_all('~^(?<head>.*bash -s.*<<\'(?<mark>[A-Z_]+)\'.*)$\R(?<body>.*?)^\k<mark>$~mus',
            $source, $heredocs, PREG_SET_ORDER)) {
        foreach ($heredocs as $found) $chunks[] = (string)$found['body'];
    }
    return $chunks;
};
// Подстановки шелла до сервера не доезжают как есть: снимаем экранирование и
// заменяем ${ИМЯ} на литерал, иначе разбор спотыкается о пустое слово, а не о
// настоящую ошибку.
$deployChunkAsShell = static function (string $chunk): string {
    return (string)preg_replace('~\$\{[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])?\}~', 'x', $chunk);
};
$deployRemoteSyntaxErrors = static function (string $source) use (
    $deployRemoteChunks, $deployChunkAsShell, $sample
): array {
    $bad = [];
    foreach ($deployRemoteChunks($source) as $no => $chunk) {
        $path = $sample('deploy-remote-chunk-' . $no . '-' . substr(md5($chunk), 0, 8) . '.sh',
            $deployChunkAsShell($chunk));
        $out = [];
        $rc = 0;
        @exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        if ($rc !== 0) $bad[] = $no . ': ' . implode(' ', $out);
    }
    return $bad;
};
$deployRemoteChunkCount = count($deployRemoteChunks($deploySrc));
check('серверные куски deploy-скрипта вообще найдены', $deployRemoteChunkCount >= 10,
    'найдено ' . $deployRemoteChunkCount);
$deployRemoteBad = $deployRemoteSyntaxErrors($deploySrc);
check('каждый кусок, уезжающий на сервер, разбирается shell-ом',
    $deployRemoteBad === [], implode(' | ', $deployRemoteBad));

// Испытание в обратную сторону: возвращаем ровно ту ошибку, что была в бою.
$brokenRemote = str_replace(
    "!= '\${NEW_TARGET}' ] &&\n          [ -d",
    "!= '\${NEW_TARGET}' ]\n          && [ -d",
    $deploySrc, $brokenRemoteCount);
check('перенос строки перед && в серверном куске ловится',
    $brokenRemoteCount === 1 && $deployMutationHasSyntax($brokenRemote)
    && $deployRemoteSyntaxErrors($brokenRemote) !== []);

// Вторая мина того же рода: кавычки вокруг переменной съедает ЛОКАЛЬНЫЙ shell, и
// на сервер уезжает голая переменная. На пустом значении `[ -n \$x ]` становится
// `[ -n ]` — истиной, и проверка срабатывает наоборот. Синтаксис при этом чист,
// поэтому ловим отдельно: любая переменная в скобочной проверке обязана уехать
// на сервер в кавычках.
$deployBareRemoteVars = static function (string $source) use ($deployRemoteChunks): array {
    $bare = [];
    foreach ($deployRemoteChunks($source) as $no => $chunk) {
        foreach (preg_split('~\R~u', $chunk) ?: [] as $line) {
            // Объяснение в комментарии нарушением не считается: иначе правило
            // ловит собственный текст «здесь так писать нельзя».
            $line = (string)preg_replace('~(^|\s)#.*$~u', '', $line);
            if (preg_match('~\[\s+(-[a-zA-Z]\s+)?\$[A-Za-z_(]~', $line) === 1
                || preg_match('~[!=]=?\s+\$[A-Za-z_]~', $line) === 1) {
                $bare[] = $no . ': ' . trim($line);
            }
        }
    }
    return $bare;
};
$deployBare = $deployBareRemoteVars($deploySrc);
check('переменные в серверных проверках уезжают в кавычках',
    $deployBare === [], implode(' | ', $deployBare));

// Текст внутри remote "..." — не инертный: обратная кавычка запускает подстановку
// команды ЛОКАЛЬНО, даже если стоит в комментарии. Так один пояснительный
// комментарий уронил выкладку с «unbound variable».
$deployBackticks = [];
foreach ($deployRemoteChunks($deploySrc) as $no => $chunk) {
    if (strpos($chunk, '`') !== false) $deployBackticks[] = (string)$no;
}
check('в серверных кусках нет обратных кавычек', $deployBackticks === [],
    'куски: ' . implode(', ', $deployBackticks));

$strippedQuotes = str_replace("[ -n \\\"\\\$first_entry\\\" ]", '[ -n "$first_entry" ]',
    $deploySrc, $strippedQuotesCount);
check('съеденные локальным shell кавычки вокруг переменной ловятся',
    $strippedQuotesCount === 1 && $deployMutationHasSyntax($strippedQuotes)
    && $deployRemoteSyntaxErrors($strippedQuotes) === []
    && $deployBareRemoteVars($strippedQuotes) !== []);

// ── Несколько порталов одним кодом ────────────────────────────────────────
// Приложение локальное: каждая установка отдельная, со своим slug'ом, приватным
// store и кредами. Из slug'а строятся пути на сервере, поэтому негодный список
// обязан останавливать выкладку ДО любых действий на хосте.
$instancesLib = dirname(__DIR__) . '/tools/instances.sh';
$instancesParse = static function (string $conf) use ($sample, $instancesLib): array {
    $path = $sample('instances-' . substr(md5($conf), 0, 8) . '.conf', $conf);
    $cmd = 'bash -c ' . escapeshellarg(
        '. ' . escapeshellarg($instancesLib) . '; bxapp_instances_parse ' . escapeshellarg($path)) . ' 2>&1';
    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
};
$instancesOk = $instancesParse("# список\n\nbg-one | portal.one.ru | боевой\nbg-two  | portal.two.ru | второй\n");
check('список установок читается: по одной строке на установку',
    $instancesOk['rc'] === 0 && $instancesOk['out'] === "bg-one\nbg-two", $instancesOk['out']);
$instancesDup = $instancesParse("one | a\none | b\n");
check('повтор установки в списке останавливает выкладку',
    $instancesDup['rc'] !== 0 && strpos($instancesDup['out'], 'повторяется') !== false);
$instancesPath = $instancesParse("../../чужое | a\n");
check('slug с переходом по каталогам в списке отвергается',
    $instancesPath['rc'] !== 0 && strpos($instancesPath['out'], 'недопустимые символы') !== false);
$instancesEmpty = $instancesParse("# только комментарий\n\n");
check('пустой список установок не считается успехом', $instancesEmpty['rc'] !== 0);

// Обход списка обязан быть в самом скрипте выкладки, иначе он остаётся бумагой.
check('выкладка обходит все установки из списка и краснеет на любой упавшей',
    strpos($deploySrc, 'bxapp_instances_parse "$INSTANCES_CONF"') !== false
    && strpos($deploySrc, 'BXAPP_INSTANCE="$bxapp_one" "${BASH}" "$0" "$@"') !== false
    && strpos($deploySrc, 'SLUG="${BXAPP_INSTANCE:-$(basename "$PROJECT_DIR")}"') !== false
    && strpos($deploySrc, 'Деплой НЕ принят для установок:') !== false);

// «Отвечает» и «живо» — разные вещи. Read-back доказывает, что по адресу новый
// release; этого мало: страница может вернуть 200 с фатальной ошибкой в теле,
// эндпоинт уехать в 500, а фоновый рабочий не разбираться серверным PHP. Тогда
// деплой зелёный, а приложение у человека не работает.
$aliveChecks = [
    'тело страницы на следы ошибки PHP' => 'Fatal error|Parse error',
    'эндпоинт отвечает и закрыт'        => "api/health.php",
    'набор кодов отказа без пропуска'   => '401|403|409|503) ;;',
    'рабочий разбирается серверным PHP' => "php -l '\${RELEASE_DIR}/bin/process.php'",
    'красный итог не принимается'       => 'Деплой не принят: приложение отвечает, но не живо.',
];
$aliveMissing = [];
foreach ($aliveChecks as $what => $needle) {
    if (strpos($deploySrc, $needle) === false) $aliveMissing[] = $what;
}
check('выкладка проверяет, что приложение ЖИВО, а не просто отвечает',
    $aliveMissing === [], 'нет проверки: ' . implode('; ', $aliveMissing));
// Проверка живости обязана стоять ДО признания release принятым: после ACCEPTED=1
// откат уже не сработает, и красный итог остался бы предупреждением в логе.
$alivePos = strpos($deploySrc, 'приложение живо: страница без ошибок');
$acceptedPos = strpos($deploySrc, "\nACCEPTED=1");
check('живость проверяется ДО признания release принятым',
    $alivePos !== false && $acceptedPos !== false && $alivePos < $acceptedPos);

// Сам скрипт с правильным exit 2 бесполезен, если агент отключил Stop-hook или
// снял подтверждение с правки забора. Проверяем проектную конфигурацию как часть
// того же контракта; её отсутствие уже ловит GATE_FILES до запуска runner.
$settingsPath = dirname(__DIR__) . '/.claude/settings.json';
$settingsRaw = is_file($settingsPath) ? (string)file_get_contents($settingsPath) : '';
$settings = json_decode($settingsRaw, true);
check('.claude/settings.json является валидным JSON-объектом',
    is_array($settings) && json_last_error() === JSON_ERROR_NONE);
$stopCommands = []; $stopHooks = [];
foreach ((array)($settings['hooks']['Stop'] ?? []) as $group) {
    foreach ((array)($group['hooks'] ?? []) as $hook) {
        if (is_array($hook) && ($hook['type'] ?? '') === 'command') {
            $stopCommands[] = (string)($hook['command'] ?? '') . ' '
                . implode(' ', array_map('strval', (array)($hook['args'] ?? [])));
            $stopHooks[] = $hook;
        }
    }
}
$sharedProjectStop = '/data/standards/bx-app/hooks/project-self-check.sh';
$selfCheckHooks = array_values(array_filter($stopHooks, static fn(array $hook): bool =>
    ($hook['command'] ?? '') === '/data/standards/bx-app/hooks/project-self-check.sh'
    && (array)($hook['args'] ?? []) === []
));
$selfCheckHook = $selfCheckHooks[0] ?? [];
check('Claude Stop-hook идёт через тот же debt-aware project self-check, что и Codex',
    count($selfCheckHooks) === 1
    && is_file($sharedProjectStop)
    && is_executable($sharedProjectStop));
check('Claude Stop-hook не обходит общий контур прямым вызовом project self-check',
    !in_array('bash ${CLAUDE_PROJECT_DIR}/tools/self-check.sh --hook', $stopCommands, true));
check('обязательный Stop-hook нельзя отключить общей настройкой',
    ($settings['disableAllHooks'] ?? false) !== true);
check('Stop-hook синхронный, без условия, и получает общий запас 900 секунд',
    count($selfCheckHooks) === 1
    && ($selfCheckHook['async'] ?? false) !== true
    && ($selfCheckHook['asyncRewake'] ?? false) !== true
    && !array_key_exists('if', $selfCheckHook)
    && ($selfCheckHook['timeout'] ?? null) === 900);
check('Claude не может включить bypassPermissions/auto вместо ask-забора',
    ($settings['permissions']['defaultMode'] ?? '') === 'acceptEdits'
    && ($settings['permissions']['disableBypassPermissionsMode'] ?? '') === 'disable'
    && ($settings['permissions']['disableAutoMode'] ?? '') === 'disable');
$askRules = array_values(array_filter((array)($settings['permissions']['ask'] ?? []), 'is_string'));
$protectedAgentFiles = [
    'Edit(/tools/self-check.sh)',
    'Edit(/tools/env-define-names.php)',
    'Edit(/deploy-prod.sh)',
    // Подтверждение просим на ФАЙЛЫ ЗАБОРА, а не на весь tests/**. Разница
    // практическая: обычные наборы (modules/store/http/security/b24/...) агент
    // правит по ходу работы постоянно, и вопрос на каждую такую правку люди
    // быстро начинают прокликивать не глядя — тогда подтверждение перестаёт
    // защищать и то, ради чего оно ставилось. Ослабить проверку можно только
    // через эти пять файлов: раннер, его обвес и три файла самого гейта.
    'Edit(/tests/run.php)',
    'Edit(/tests/lib.php)',
    'Edit(/tests/gate-lib.php)',
    'Edit(/tests/gate_test.php)',
    'Edit(/tests/uses_template_test.php)',
    'Edit(/AGENTS.md)',
    'Edit(/CLAUDE.md)',
    'Edit(/.claude/settings.json)',
    'Edit(/www/index.php)',
    // www/env.php намеренно БЕЗ подтверждения (решение владельца 26.08.2026):
    // он правится в каждой второй задаче (скоупы, плейсменты, уровень журнала),
    // и вопрос на каждую такую правку прокликивали не глядя. Содержимое файла
    // по-прежнему держит structural-гейт: ни сети, ни записи, ни вывода, ни
    // dynamic dispatch, единственный require — release.php.
    'Edit(/www/js/ui.js)',
];
foreach ($expectedCore as $coreFile) {
    $protectedAgentFiles[] = 'Edit(/' . $coreFile . ')';
}
$missingAskRules = array_values(array_diff($protectedAgentFiles, $askRules));
check('Claude обязан запросить подтверждение на правку хука, правил, ядра и тестового забора',
    $missingAskRules === [], 'нет ask-правил: ' . implode(', ', $missingAskRules));
check('Claude Edit ask-пути якорены к корню, а не зависят от cwd',
    array_values(array_filter($askRules, static fn(string $rule): bool =>
        strncmp($rule, 'Edit(/', 6) !== 0
    )) === []);
$allowRules = array_values(array_filter((array)($settings['permissions']['allow'] ?? []), 'is_string'));
$broadBash = array_values(array_filter($allowRules, static function (string $rule): bool {
    return $rule === 'Bash(*)' || $rule === 'Bash' || preg_match('~^Bash\([^)]*\*[^)]*\)$~', $rule) === 1;
}));
check('Claude не получает широкое Bash-разрешение в обход ask-защиты',
    $broadBash === [], 'широкие правила: ' . implode(', ', $broadBash));

// Повреждённый payload без доказуемого cwd не вправе запирать чужой проект, но
// и выглядеть как состоявшаяся проверка не должен: диагностика идёт в stderr и
// локальный журнал. Проверяем именно configured command, а не его копию.
$malformedLog = $dir . '/claude-stop-malformed.log';
$malformedOut = $dir . '/claude-stop-malformed.out';
$malformedErr = $dir . '/claude-stop-malformed.err';
$malformedRc = 0;
$malformedCmd = 'printf %s ' . escapeshellarg('{broken-json')
    . ' | BXAPP_PROJECT_STOP_LOG=' . escapeshellarg($malformedLog)
    . ' ' . escapeshellarg($sharedProjectStop)
    . ' >' . escapeshellarg($malformedOut)
    . ' 2>' . escapeshellarg($malformedErr);
@exec($malformedCmd, $ignoredMalformedOutput, $malformedRc);
$malformedStderr = (string)@file_get_contents($malformedErr);
$malformedJournal = (string)@file_get_contents($malformedLog);
check('повреждённый payload Claude Stop не блокирует чужой проект без cwd', $malformedRc === 0,
    'код возврата ' . $malformedRc);
check('повреждённый payload Claude Stop диагностируется вслух и в журнал',
    trim($malformedStderr) !== ''
    && preg_match('~payload|JSON|metadata|метадан~iu', $malformedStderr) === 1
    && preg_match('~malformed|payload|json~i', $malformedJournal) === 1);
check('повреждённый payload Claude Stop не пишет ложный успешный stdout',
    (string)@file_get_contents($malformedOut) === '');

// Хук обязан БЛОКИРОВАТЬ ход, а не просто ругаться. Проверяем контрактом, а не
// чтением кода: код возврата 2 и текст в stderr — единственное, что Claude Code
// понимает как «нельзя заканчивать». Прежняя версия печатала JSON и выходила с
// кодом 1 — ход заканчивался при красных проверках.
$fakeProject = $dir . '/hookcheck';
@mkdir($fakeProject . '/tools', 0700, true);
@mkdir($fakeProject . '/www', 0700, true);
copy(dirname(__DIR__) . '/tools/self-check.sh', $fakeProject . '/tools/self-check.sh');
copy(dirname(__DIR__) . '/tools/env-define-names.php', $fakeProject . '/tools/env-define-names.php');
file_put_contents($fakeProject . '/www/broken.php', "<?php\nfunction (\n");
$hookOut = [];
$hookRc = 0;
@exec('cd ' . escapeshellarg($fakeProject) . ' && bash tools/self-check.sh --hook 2>' . escapeshellarg($fakeProject . '/err.txt'), $hookOut, $hookRc);
$hookErr = (string)@file_get_contents($fakeProject . '/err.txt');
check('красная самопроверка не даёт агенту закончить ход (код 2)', $hookRc === 2, "код возврата $hookRc");
check('причина отказа уходит агенту в stderr', trim($hookErr) !== '' && strpos($hookErr, 'PHP') !== false,
    'stderr: ' . mb_substr(trim($hookErr), 0, 120));
check('в stdout хука ничего лишнего нет', implode('', $hookOut) === '', implode('', $hookOut));

// Точный repro прошлого обхода: самопроверка обязана остановиться, если удалили
// только review_fixes_test.php, хотя остальных наборов всё ещё больше минимума.
@unlink($fakeProject . '/www/broken.php');
file_put_contents($fakeProject . '/www/ok.php', "<?php\ndeclare(strict_types=1);\n");
@mkdir($fakeProject . '/tests', 0700, true);
@mkdir($fakeProject . '/.claude', 0700, true);
copy(dirname(__DIR__) . '/.claude/settings.json', $fakeProject . '/.claude/settings.json');
copy(dirname(__DIR__) . '/AGENTS.md', $fakeProject . '/AGENTS.md');
copy(dirname(__DIR__) . '/CLAUDE.md', $fakeProject . '/CLAUDE.md');
$fenceFiles = ['run.php', 'gate-lib.php', 'b24_test.php', 'gate_test.php',
               'http_test.php', 'modules_test.php', 'resource_limits_test.php',
               'notify_test.php', 'security_test.php', 'snap_test.php', 'store_test.php', 'uf_test.php', 'uses_template_test.php'];
foreach ($fenceFiles as $f) file_put_contents($fakeProject . '/tests/' . $f, "<?php\n");
$missingOut = [];
$missingRc = 0;
@exec('cd ' . escapeshellarg($fakeProject) . ' && bash tools/self-check.sh --hook 2>'
    . escapeshellarg($fakeProject . '/missing-err.txt'), $missingOut, $missingRc);
$missingErr = (string)@file_get_contents($fakeProject . '/missing-err.txt');
check('удалённый review_fixes_test.php блокирует завершение',
    $missingRc === 2 && strpos($missingErr, 'review_fixes_test.php') !== false,
    'код ' . $missingRc . ', stderr: ' . mb_substr(trim($missingErr), 0, 120));

// Теперь кладём недостающий suite, но оставляем без deploy-prod.sh: финальный
// fail-closed барьер тоже является частью забора, а не необязательной обвязкой.
file_put_contents($fakeProject . '/tests/review_fixes_test.php', "<?php\n");
$missingDeployOut = [];
$missingDeployRc = 0;
@exec('cd ' . escapeshellarg($fakeProject) . ' && bash tools/self-check.sh --hook 2>'
    . escapeshellarg($fakeProject . '/missing-deploy-err.txt'), $missingDeployOut, $missingDeployRc);
$missingDeployErr = (string)@file_get_contents($fakeProject . '/missing-deploy-err.txt');
check('удалённый deploy-prod.sh блокирует завершение',
    $missingDeployRc === 2 && strpos($missingDeployErr, 'deploy-prod.sh') !== false,
    'код ' . $missingDeployRc . ', stderr: ' . mb_substr(trim($missingDeployErr), 0, 120));

// Файлы забора на месте — их отсутствие обязано ронять самопроверку.
foreach (['gate-lib.php', 'gate_test.php', 'uses_template_test.php', 'run.php'] as $f) {
    check("файл забора tests/$f на месте и не пуст", is_file(__DIR__ . '/' . $f) && filesize(__DIR__ . '/' . $f) > 0);
}

foreach ($made as $f) @unlink($f);
@unlink($fakeProject . '/tools/self-check.sh');
@unlink($fakeProject . '/tools/env-define-names.php');
@unlink($fakeProject . '/www/broken.php'); @unlink($fakeProject . '/www/ok.php');
@unlink($fakeProject . '/err.txt');
@unlink($fakeProject . '/missing-err.txt');
@unlink($fakeProject . '/missing-deploy-err.txt');
@unlink($malformedLog); @unlink($malformedOut); @unlink($malformedErr);
@unlink($fakeProject . '/tests/review_fixes_test.php');
foreach ($fenceFiles ?? [] as $f) @unlink($fakeProject . '/tests/' . $f);
@rmdir($fakeProject . '/tests'); @rmdir($fakeProject . '/tools'); @rmdir($fakeProject . '/www'); @rmdir($fakeProject);
@rmdir($dir . '/sub'); @rmdir($dir . '/vendor');
@rmdir($dir . '/project/www/api/report'); @rmdir($dir . '/project/www/api');
@rmdir($dir . '/project/www/lib'); @rmdir($dir . '/project/www/vendor');
@rmdir($dir . '/project/www/bin'); @rmdir($dir . '/project/www/css');
@rmdir($dir . '/project/www/assets');
@rmdir($dir . '/project/www'); @rmdir($dir . '/project');
@rmdir($dir);

// ── Имена кредов с суффиксом среды ────────────────────────────────────────
// Ревью 05.08.2026: цикл срезания 'prod'/'stage'/'local' был доведён до половины —
// последний семантический сегмент брался из НЕобрезанного имени, поэтому
// $B24_SECRET_PROD и $apiTokenProd проходили гейт целиком, хотя $B24_SECRET и
// $apiToken он ловил. Форма имени взята из самого шаблона: www/env.php объявляет
// слот $B24_CLIENT_SECRET_LOCAL.
foreach (['B24_SECRET_PROD', 'apiTokenProd', 'oauthTokenStage', 'API_TOKEN_PROD',
          'B24_CLIENT_SECRET_LOCAL', 'webhookUrlProd'] as $credentialName) {
    check('имя ' . $credentialName . ' считается credential', gateIsCredentialName($credentialName));
}
foreach (['token_ttl', 'tokenTtl', 'prodMode', 'localPath', 'stageIndex'] as $plainName) {
    check('имя ' . $plainName . ' не считается credential', !gateIsCredentialName($plainName));
}
// Заполненные слоты env.php — штатный путь тестового хостинга, а не находка гейта.
$filledEnvSample = $dir . '/env_slots_filled';
@mkdir($filledEnvSample . '/www', 0700, true);
$envSlotSource = (string)file_get_contents(dirname(__DIR__) . '/www/env.php');
$envSlotSource = (string)preg_replace('~(\$B24_CLIENT_ID_LOCAL\s*=\s*)\'\'~',
    "$1'local.abc123def456.7890ab'", $envSlotSource, 1);
$envSlotSource = (string)preg_replace('~(\$B24_CLIENT_SECRET_LOCAL\s*=\s*)\'\'~',
    "$1'S3cr3tV4lu3ForLocalDev0000'", $envSlotSource, 1);
file_put_contents($filledEnvSample . '/www/env.php', $envSlotSource);
check('заполненные слоты кредов в env.php не считаются захардкоженным секретом',
    gateHardcodedCredentialLines(dirname(__DIR__) . '/www/env.php') === []);
$foreignSecretSample = $sample('foreign_secret.php',
    "<?php\n\$SOME_API_TOKEN_PROD = 'tok_live_9f8e7d6c5b4a';\n");
check('чужой секрет с суффиксом среды по-прежнему ловится',
    gateHardcodedCredentialLines($foreignSecretSample) !== []);

// ── Цепочка мутации: идентификатор можно положить в переменную ────────────
// Ревью 05.08.2026: гейт принимал только подстановку `$added['item']['id']` прямо
// в селектор read-back. Канонический код с промежуточной переменной (так учит
// документация) краснел, а сообщение указывало на строку мутации и не называло
// настоящую причину — требования этого нигде не было.
$mutationChainOk = $sample('mutation_chain_ok.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n"
    . "\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n"
    . "});\napiOk(['result' => \$out['result']]);\n");
check('цепочка мутации принимает идентификатор через промежуточную переменную',
    gateUnprotectedMutations($mutationChainOk) === []);

$mutationChainSwapped = $sample('mutation_chain_swapped.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n"
    . "\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    \$fields['title'] = 'подменили после записи';\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n"
    . "});\napiOk(['result' => \$out['result']]);\n");
check('подмена полей между записью и проверкой по-прежнему ловится',
    gateUnprotectedMutations($mutationChainSwapped) !== []);

// Идентификатор от ПРЕДЫДУЩЕЙ мутации не доказывает результат следующей: код
// выглядит как законная цепочка, а сверяется чужая запись — вторую мутацию не
// проверяет никто. Разворот переменной работает только в окне между этой
// записью и этой сверкой.
$mutationChainStaleId = $sample('mutation_chain_stale_id.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n"
    . "\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$first = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$first['item']['id'];\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n"
    . "});\napiOk(['result' => \$out['result']]);\n");
check('сверка по идентификатору предыдущей мутации не считается проверкой следующей',
    gateUnprotectedMutations($mutationChainStaleId) !== []);

// Подменить проверяемое значение можно и без знака «=»: функцией по ссылке или
// вырезав элемент. Раньше искали только присваивание, и такая подмена проходила.
$mutationChainByRef = $sample('mutation_chain_by_ref.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n"
    . "\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    ksort(\$fields);\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n"
    . "});\napiOk(['result' => \$out['result']]);\n");
check('подмена проверяемых полей функцией по ссылке ловится', gateUnprotectedMutations($mutationChainByRef) !== []);

$mutationChainUnset = $sample('mutation_chain_unset.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n"
    . "\$fields = ['title' => 'X', 'opportunity' => '100.00'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    unset(\$fields['opportunity']);\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n"
    . "});\napiOk(['result' => \$out['result']]);\n");
check('вырезание проверяемого поля перед сверкой ловится', gateUnprotectedMutations($mutationChainUnset) !== []);

// ── Послабление для служебных примитивов — точечное, а не на всю строку ────
// Ревью 05.08.2026: прощалась ВСЯ строка, где встретился замок или microtime.
// Значит деньги проходили сырым float, стоило приписать их к строке с замком —
// ровно та дыра, ради закрытия которой правило и существует.
$servicePrimitiveOk = $sample('service_primitive_ok.php', "<?php\n"
    . "storeUpdate(STATE_FILE, static function (array \$s): array {\n"
    . "    \$s['at'] = date('c');\n"
    . "    return \$s;\n"
    . "}, 25.0);\n"
    . "\$took = round(microtime(true) - \$started, 3);\n");
check('служебные примитивы каркаса (таймаут замка, метка времени, замер) не считаются доменными',
    gateRawDomainPrimitiveLines($servicePrimitiveOk) === []);

$servicePrimitiveAbuse = $sample('service_primitive_abuse.php', "<?php\n"
    . "storeUpdate(STATE_FILE, static function (array \$s) use (\$raw): array {\n"
    . "    \$s['сумма'] = (float)\$raw;\n"
    . "    return \$s;\n"
    . "}, 25.0);\n");
check('деньги сырым float не прячутся в строке со служебным замком',
    gateRawDomainPrimitiveLines($servicePrimitiveAbuse) !== []);

$serviceDatePrimitiveAbuse = $sample('service_date_abuse.php', "<?php\n"
    . "\$row = ['метка' => date('c'), 'день' => date('Y-m-d', \$ts)];\n");
check('дата Битрикса рядом с технической меткой времени всё равно ловится',
    gateRawDomainPrimitiveLines($serviceDatePrimitiveAbuse) !== []);

// Послабление для служебных примитивов действует только на САМ служебный токен
// и только на верхнем уровне вызова замка. Целевая проверка 05.08.2026 показала:
// поиск охватывающего вызова шёл по круглым скобкам сквозь тело колбэка, поэтому
// `storeUpdate($f, function () { ... (float)$сумма ... }, 25.0)` прощал ЛЮБОЕ
// число во всём теле — то есть ровно ту прикладную логику, ради которой правило
// и написано.
$moneyInsideLockCallback = $sample('money_inside_lock_callback.php', "<?php\n"
    . "storeUpdate(STATE_FILE, static function (array \$s) use (\$raw): array {\n"
    . "    \$s['сумма'] = (float)\$raw;\n"
    . "    \$s['цена'] = 12.5;\n"
    . "    return \$s;\n"
    . "}, 25.0);\n");
check('сырые деньги внутри тела обработчика замка ловятся, а не прощаются вместе с таймаутом',
    count(gateRawDomainPrimitiveLines($moneyInsideLockCallback)) >= 2);

$serviceInsideLockCallback = $sample('service_inside_lock_callback.php', "<?php\n"
    . "storeUpdate(STATE_FILE, static function (array \$s): array {\n"
    . "    \$s['at'] = date(\"c\");\n"
    . "    return \$s;\n"
    . "}, 25.0);\n"
    . "\$took = round(microtime(true) - \$started, 3);\n");
check('служебная метка времени в двойных кавычках и замер длительности не краснеют',
    gateRawDomainPrimitiveLines($serviceInsideLockCallback) === []);

$roundBypass = $sample('round_bypass.php', "<?php\n"
    . "\$итог = round(\$сумма * 1.2, strlen((string)microtime(true)));\n");
check('приписанный вторым аргументом microtime не снимает проверку с денег',
    gateRawDomainPrimitiveLines($roundBypass) !== []);

// Позиция аргумента важнее имени функции: `preg_match($re, $строка, $m)` меняет
// ТРЕТИЙ аргумент, а второй честно читает. Прежняя проверка судила по имени и
// краснела на нормальном коде — а ложный красный учит обходить гейт, а не
// соблюдать правило.
$chainReadsField = $sample('chain_reads_field.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    preg_match('~\\\\d+~', (string)\$id, \$m);\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n});\napiOk(['result' => \$out['result']]);\n");
check('честное чтение проверяемого значения не считается его подменой',
    gateUnprotectedMutations($chainReadsField) === []);

// Зато собственный помощник проекта с параметром по ссылке меняет снимок ровно
// так же, как sort(), — и обязан ловиться, хотя его нет ни в одном списке.
$chainByRefHelper = $sample('chain_byref_helper.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "function нормализоватьПоля(array &\$f): void { \$f['title'] = 'уже другое'; }\n"
    . "\$b24 = b24();\n\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    нормализоватьПоля(\$fields);\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    return \$added;\n});\napiOk(['result' => \$out['result']]);\n");
check('подмена снимка собственным помощником с аргументом по ссылке ловится',
    gateUnprotectedMutations($chainByRefHelper) !== []);

// Два блока мутации в одном эндпоинте — обычное дело, и оба обязаны проходить.
$chainTwoBlocks = $sample('chain_two_blocks.php', "<?php\n"
    . "require_once __DIR__ . '/http.php';\n"
    . "\$ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);\n"
    . "\$b24 = b24();\n\$fields = ['title' => 'X'];\n"
    . "\$out = runOnce('deal-add', static function () use (\$b24, \$fields, \$ctx): array {\n"
    . "    \$added = \$b24->callChecked('crm.item.add', ['entityTypeId' => 2, 'fields' => \$fields]);\n"
    . "    \$id = \$added['item']['id'];\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 2, 'id' => \$id], \$fields);\n"
    . "    auditLog('deal.add', ['id' => \$id], (int)\$ctx['uid']);\n"
    . "    \$second = \$b24->callChecked('crm.item.add', ['entityTypeId' => 3, 'fields' => \$fields]);\n"
    . "    \$id2 = \$second['item']['id'];\n"
    . "    \$b24->readBackChecked('crm.item.get', ['entityTypeId' => 3, 'id' => \$id2], \$fields);\n"
    . "    auditLog('task.add', ['id' => \$id2], (int)\$ctx['uid']);\n"
    . "    return \$added;\n});\napiOk(['result' => \$out['result']]);\n");
check('две честные цепочки мутации в одном файле проходят обе',
    gateUnprotectedMutations($chainTwoBlocks) === []);

// ── Каталог готовых блоков обязан совпадать с кодом ──────────────────────────
// Правило «прежде чем писать своё — открой docs/BLOCKS.md» держится ровно до
// первого выдуманного имени: разработчик грепает его, не находит и пишет своё.
// Проверки ниже обязаны КРАСНЕТЬ на подложенном расхождении, иначе они пустые.
$catalogGoodSrc = $sample('catalog_good.php',
    "<?php\nfunction storeUpdate(\$file, \$fn) { return \$fn([]); }\n");
$catalogGoodJs = $sample('catalog_good.js', "ui.switchBox = function (t, o) { return o; };\n");
$catalogCss = $sample('catalog.css', ".field-slot { display: flex; }\n.btn.is-active { color: red; }\n");
$catalogSources = [$catalogGoodSrc, $catalogGoodJs];

$catalogHonest = "## Блоки\n| Изменить состояние | `storeUpdate(ФАЙЛ, function (\$д) { ... })` |\n"
    . "| Переключатель | `ui.switchBox(куда, {value})` |\n"
    . "## Интерфейс\n| Строка формы | `.field-slot` + `.btn.is-active` |\n";
check('честный каталог проверку проходит',
    gateCatalogMissingSymbols($catalogHonest, $catalogSources) === []
    && gateCatalogMissingClasses($catalogHonest, [$catalogCss]) === []);

// Ровно тот случай, что нашло ревью 06.08.2026: имя в каталоге есть, функции нет.
$catalogLies = $catalogHonest . "| Деньги человеку | `moneyForHuman(\$копейки)` |\n";
check('выдуманное имя функции в каталоге ловится',
    gateCatalogMissingSymbols($catalogLies, $catalogSources) === ['moneyForHuman']);

// Точка расширения (ревью 23.09.2026, modulnost-2): функцию объявляет проект в
// api/project-hooks.php, ядро зовёт её через function_exists('имя'). Такое имя
// в каталоге — контракт ядра; имя, которого ядро не зовёт, по-прежнему ловится.
$catalogHookSrc = $sample('catalog_hook.php',
    "<?php\nfunction settingsSchema(): array {\n    if (!function_exists('projectSettingsSchema')) return [];\n"
    . "    return projectSettingsSchema();\n}\n");
$catalogHook = "| Настройки проекта | `projectSettingsSchema(): array` |\n";
check('имя точки расширения, которое ядро зовёт через function_exists, каталог не врёт',
    gateCatalogMissingSymbols($catalogHook, [$catalogHookSrc]) === []);
check('имя «точки расширения», которое ядро не зовёт, ловится как выдуманное',
    gateCatalogMissingSymbols("| Хук | `projectInventedHook(): void` |\n", [$catalogHookSrc])
        === ['projectInventedHook']);

$catalogLiesCss = "## Интерфейс\n| Подвал | `.settings-foot` |\n";
check('класс оформления, которого нет в css, ловится',
    gateCatalogMissingClasses($catalogLiesCss, [$catalogCss]) === ['settings-foot']);

// ЛАЗЕЙКА, НАЙДЕННАЯ 09.08.2026. Блок кода в тройном ограждении оставляет две
// непарные обратные кавычки, и разбор «текст между кавычками» дальше читает
// файл СО СДВИГОМ: хвост каталога после первого же примера кода не проверялся
// вовсе — там можно было обещать что угодно. Улика: выдуманное имя, подсаженное
// в конец docs/BLOCKS.md, не попало ни в один из 269 разобранных фрагментов.
// Проверки ниже держат обе стороны: и имя, и класс, и до, и после ограждения.
$catalogFenced = "## Блоки\n| Изменить состояние | `storeUpdate(ФАЙЛ, function (\$д) { ... })` |\n"
    . "\n```bash\nphp tools/map-sync.php --check\n```\n\n"
    . "| Деньги человеку | `moneyForHuman(\$копейки)` |\n";
check('выдуманное имя ПОСЛЕ блока кода не прячется от проверки',
    gateCatalogMissingSymbols($catalogFenced, $catalogSources) === ['moneyForHuman']);

// Обратная сторона: имя, спрятанное ВНУТРИ примера кода, — тоже обещание
// каталога. Просто выбросить ограждения было бы новой лазейкой вместо старой.
$catalogInsideFence = "## Блоки\n\n```php\n\$x = moneyForHuman(\$копейки);\n```\n";
check('имя ВНУТРИ блока кода проверяется наравне с остальными',
    gateCatalogMissingSymbols($catalogInsideFence, $catalogSources) === ['moneyForHuman']);

$catalogFencedCss = "## Интерфейс\n| Строка формы | `.field-slot` |\n"
    . "\n```css\n.field-slot { display: flex; }\n```\n\n"
    . "| Подвал | `.settings-foot` |\n";
check('выдуманный класс ПОСЛЕ блока кода не прячется от проверки',
    gateCatalogMissingClasses($catalogFencedCss, [$catalogCss]) === ['settings-foot']);

// Третья форма той же болезни: двойные ограничители (``имя``) — законный
// markdown, и они точно так же оставляют непарную кавычку. Проверено
// 09.08.2026: имя, стоящее ПОСЛЕ такого фрагмента, проверка не видела вовсе.
$catalogDoubleTicks = "## Блоки\n| Деньги | ``moneyForHuman(\$копейки)`` |\n"
    . "| Ещё | `storeUpdate(ФАЙЛ, function (\$д) { ... })` |\n";
check('двойные обратные кавычки не прячут следующее обещание каталога',
    gateCatalogMissingSymbols($catalogDoubleTicks, $catalogSources) === ['moneyForHuman']);

// Четвёртая форма: ограждение ТИЛЬДАМИ (~~~) — такой же штатный markdown-забор,
// как три обратные кавычки. Разбор его не знал вовсе, поэтому обещание внутри
// тильда-блока не попадало ни в один кусок и не проверялось ничем: каталог мог
// обещать несуществующий блок (K4-39, ревью 09.08.2026).
$catalogTilde = "## Блоки\n\n~~~php\n\$x = moneyForHuman(\$копейки);\n~~~\n";
check('имя ВНУТРИ тильда-ограждения проверяется наравне с остальными',
    gateCatalogMissingSymbols($catalogTilde, $catalogSources) === ['moneyForHuman']);
$catalogAfterTilde = "## Блоки\n\n~~~bash\nphp tools/map-sync.php --check\n~~~\n\n"
    . "| Деньги человеку | `moneyForHuman(\$копейки)` |\n";
check('выдуманное имя ПОСЛЕ тильда-ограждения не прячется от проверки',
    gateCatalogMissingSymbols($catalogAfterTilde, $catalogSources) === ['moneyForHuman']);
$catalogTildeCss = "## Интерфейс\n\n~~~css\n.field-slot { display: flex; }\n~~~\n\n"
    . "| Подвал | `.settings-foot` |\n";
check('выдуманный класс ПОСЛЕ тильда-ограждения не прячется от проверки',
    gateCatalogMissingClasses($catalogTildeCss, [$catalogCss]) === ['settings-foot']);
// Ограждение закрывается ТЕМ ЖЕ символом: тильды не должны закрывать блок,
// открытый обратными кавычками, иначе разбор снова поедет со сдвигом.
$catalogMixedFence = "## Блоки\n\n```php\n~~~\n\$x = moneyForHuman(\$копейки);\n```\n";
check('тильда внутри блока с обратными кавычками его не закрывает',
    gateCatalogMissingSymbols($catalogMixedFence, $catalogSources) === ['moneyForHuman']);

// Незакрытое ограждение съедает текст до конца файла — проверка обязана
// разобрать съеденное, а не тихо согласиться, что обещаний больше нет.
$catalogUnclosed = "## Блоки\n\n```php\n\$x = moneyForHuman(\$копейки);\n";
check('незакрытый блок кода не глотает обещания каталога',
    gateCatalogMissingSymbols($catalogUnclosed, $catalogSources) === ['moneyForHuman']);

// Имя файла и метод портала — не классы: иначе проверка требовала бы от css
// «класс .php» и «класс .item», и её пришлось бы отключить целиком.
$catalogPaths = "## Интерфейс\n| Журнал | `api/journal.php`, `crm.item.list`, `отчёт.csv` |\n";
check('точка внутри имени файла или метода портала не считается классом',
    gateCatalogMissingClasses($catalogPaths, [$catalogCss]) === []);

// Обратная сторона: готовый модуль, не названный в каталоге, напишут заново.
$moduleNamed = $sample('catalog_module_named.php', "<?php\nfunction crmDict(\$b24) { return []; }\n");
$moduleHidden = $sample('catalog_module_hidden.php', "<?php\nfunction чтоТоСвоё(\$x) { return \$x; }\n");
$catalogWithModule = "| Справочник | `crmDict(\$b24)` |\n";
check('модуль, не названный в каталоге ни файлом, ни функцией, ловится',
    gateModulesMissingFromCatalog($catalogWithModule, [$moduleNamed, $moduleHidden])
        === ['catalog_module_hidden.php']);

// ── Обход установок не должен съедать свой же список ─────────────────────────
// Дочерний заход наследует stdin цикла, а ssh внутри него этот stdin вычитывает.
// Без заглушки выкладывалась ТОЛЬКО первая установка из instances.conf, а скрипт
// печатал «выложены все» и возвращал ноль — худший вид поломки: молчаливый.
$deploySource = (string)file_get_contents(dirname(__DIR__) . '/deploy-prod.sh');
check('дочерний заход по установкам защищён от съедания списка',
    preg_match('~BXAPP_INSTANCE="\$bxapp_one"[^\n]*</dev/null~', $deploySource) === 1,
    'нет </dev/null у рекурсивного вызова: выложится только первая установка');

// Тот же класс, обратная сторона: намеренная подача heredoc серверу обязана
// остаться — глобальный `ssh -n` починил бы список и сломал бы её.
check('намеренная подача данных серверу сохранена',
    strpos($deploySource, 'bash -s') !== false
    && preg_match('~SSH=\(\s*ssh\s+-n~', $deploySource) !== 1,
    'глобальный ssh -n ломает передачу скриптов на сервер');

// ── Служебные скрипты знают про несколько установок ──────────────────────────
// Креды и журнал лежат в каталоге КОНКРЕТНОЙ установки. Пока slug брался только
// из имени папки проекта, `set-creds.sh` молча писал ключи одной установке из
// списка, а `--show` это подтверждал — человек был уверен, что настроил другую.
foreach (['scripts/set-creds.sh', 'scripts/logs.sh'] as $script) {
    $src = (string)@file_get_contents(dirname(__DIR__) . '/' . $script);
    check($script . ' выбирает установку, а не имя папки',
        strpos($src, 'BXAPP_INSTANCE') !== false
        && strpos($src, '--instance') !== false
        && strpos($src, 'instances.conf') !== false,
        'скрипт работает только с одной установкой');
}

// ── Забор состояния нельзя снять именем файла ────────────────────────────────
// Служебными считаются КАТАЛОГ (`data/logs/…`) и РАСШИРЕНИЕ (`.lock.php`, `.tmp`).
// Пока разделителем считался и дефис, служебным становилось всё, что начинается
// на tmp-/log-/archive-: файл `tmp-orders.php` проходил забор, и состояние
// писалось мимо хранилища — без замка, то есть с потерей чужих правок.
check('имя файла не превращает состояние в служебный файл',
    !gateStateTargetIsExempt('DATA_ROOT . "/tmp-orders.php"')
    && !gateStateTargetIsExempt('DATA_ROOT . "/log-settings.php"')
    && !gateStateTargetIsExempt('DATA_ROOT . "/archive-deals.php"'));
check('служебный каталог и служебное расширение по-прежнему разрешены',
    gateStateTargetIsExempt('DATA_ROOT . "/logs/2026-08-06.log.php"')
    && gateStateTargetIsExempt('(DATA_ROOT . "/cron.lock.php")')
    && gateStateTargetIsExempt('(glob((DATA_ROOT . "/cron-logs") . "/*.log") ?: [])'));

// ── Файл совместимости не должен требовать нового движка ─────────────────────
// Проверка нужна ровно потому, что глазами это не видно: здесь PHP 8.x, и
// array_is_list() читается как обычная функция. У того, ради кого файл написан
// (PHP 7.4), она даёт фатал «undefined function» — падает всё приложение, а не
// выборка. Испытываем обе стороны: образец нарушения обязан быть пойман, чистый
// код 7.4 — пропущен.
check('новая функция в файле для старого движка поймана',
    count(gateNewerThanPhp74('<?php if (array_is_list($a)) { echo str_contains($s, "x"); }')) === 2,
    implode(' | ', gateNewerThanPhp74('<?php if (array_is_list($a)) {}')));
check('новый синтаксис пойман',
    count(gateNewerThanPhp74('<?php $v = $o?->name; $r = match($x) { 1 => "a", default => "b" };')) >= 2);
check('код, понятный PHP 7.4, нарушением не считается',
    gateNewerThanPhp74('<?php function f(array $a): bool { foreach ($a as $k => $_) {} return strpos($s, "x") !== false; }') === []);
// Упоминание в комментарии и одноимённый метод объекта — не вызов новой функции.
check('упоминание в комментарии и свой метод не считаются нарушением',
    gateNewerThanPhp74('<?php /* array_is_list() появилась в 8.1 */ $this->str_contains($a);') === []);

// ── Живые прогоны нельзя удалить незаметно ───────────────────────────────────
// Круг 2 ревью 06.08.2026: прокликивание интерфейса браузером не входило ни в
// один забор. Удали файл — «все проверки пройдены» осталось бы зелёным, и
// пропажу заметил бы только тот, кто через неделю решит запустить прогон руками.
$selfCheckSrc = (string)file_get_contents(dirname(__DIR__) . '/tools/self-check.sh');
foreach (['tests/live-harness.js', 'tests/settings-live.js', 'tests/task-live.js', 'tests/install-live.js',
          'tests/ui-stand.js', 'tests/ui-stand.html', 'tests/logs-live.js',
          'tests/access-live.js'] as $liveFile) {
    check('живой прогон под защитой забора: ' . $liveFile,
        strpos($selfCheckSrc, $liveFile) !== false
        && is_file(dirname(__DIR__) . '/' . $liveFile));
}
check('синтаксис живых прогонов проверяется самопроверкой',
    preg_match('~for f in "\$\{PROJECT_DIR\}"/tests/\*\.js~', $selfCheckSrc) === 1);

// ── Пропуск живых сценариев не выдаёт себя за «пройдено» (BXREV-013) ────────
// Находка ревью 08.08.2026: с BXAPP_SKIP_LIVE=1 самопроверка печатала
// «Самопроверка пройдена» и отдавала код 0. Предупреждение строчкой выше
// терялось — деплой, агент и человек читают КОД, а он говорил «зелено», и на
// бой уезжало то, что ни разу не открывали.
$selfCheckSrc = (string)file_get_contents(dirname(__DIR__) . '/tools/self-check.sh');
check('пропуск живых сценариев отмечается отдельным исходом, а не молча',
    preg_match('~SKIPPED_LIVE=1~', $selfCheckSrc) === 1
    && preg_match('~ИТОГ: НЕ ПРОВЕРЕНО~u', $selfCheckSrc) === 1);
// Порядок внутри ветки важнее самого текста: «exit 1» обязан стоять ПОСЛЕ
// аварийной ветки и до «Самопроверка пройдена» — иначе пропуск снова станет
// зелёным, а строки останутся на месте.
$poz = [
    'skip'    => strpos($selfCheckSrc, 'ИТОГ: НЕ ПРОВЕРЕНО'),
    'avaria'  => strpos($selfCheckSrc, 'BXAPP_DEPLOY_WITHOUT_LIVE'),
    'proydeno'=> strpos($selfCheckSrc, '✓ Самопроверка пройдена'),
];
check('аварийный пропуск требует причины и стоит ДО отказа, а отказ — до «пройдено»',
    $poz['avaria'] !== false && $poz['skip'] !== false && $poz['proydeno'] !== false
    && $poz['avaria'] < $poz['skip'] && $poz['skip'] < $poz['proydeno'],
    json_encode($poz));
check('аварийный пропуск оставляет квитанцию, а не просто пропускает',
    strpos($selfCheckSrc, 'АВАРИЙНЫЙ ПРОПУСК ЖИВЫХ СЦЕНАРИЕВ. Квитанция:') !== false
    && strpos($selfCheckSrc, 'причина: ${BXAPP_DEPLOY_WITHOUT_LIVE}') !== false);

// ── Совместимость с PHP 7.4 проверяется по СИНТАКСИСУ, а не по списку имён ──
// Находка ревью 08.08.2026 (BXREV-017): забор знал девять функций и пять
// токенов, поэтому union-тип, `mixed`, именованный аргумент и продвижение
// свойств в конструкторе проходили молча. Локально (8.x) такой файл работает,
// у клиента на 7.4 он падает фаталом при первом подключении — целиком.
$php74Obraztsy = [
    'union-тип'        => "<?php\nfunction f(int|string \$x): int|false { return 1; }\n",
    'mixed'            => "<?php\nfunction f(\$x): mixed { return \$x; }\n",
    'never'            => "<?php\nfunction f(): never { exit; }\n",
    'именованный аргумент' => "<?php\nsetcookie(name: \"a\", value: \"b\");\n",
    'продвижение свойств'  => "<?php\nclass A { public function __construct(private int \$x) {} }\n",
    'throw как выражение'  => "<?php\n\$a = \$b ?? throw new RuntimeException(\"x\");\n",
    'catch без переменной' => "<?php\ntry { f(); } catch (RuntimeException) { }\n",
    'nullsafe'         => "<?php\n\$a = \$b?->c();\n",
    'match'            => "<?php\n\$a = match(\$b) { 1 => 2, default => 3 };\n",
];
$php74Propuscheny = [];
foreach ($php74Obraztsy as $imya => $kod) {
    if (gateNewerThanPhp74($kod) === []) $php74Propuscheny[] = $imya;
}
check('ЗАБОР PHP 7.4 ЛОВИТ СИНТАКСИС, А НЕ ТОЛЬКО ИМЕНА ФУНКЦИЙ',
    $php74Propuscheny === [],
    $php74Propuscheny === [] ? '' : 'прошло молча: ' . implode(', ', $php74Propuscheny));
// Обратная сторона: забор, который краснеет на исправном коде, выключат первым же.
$php74Chistye = [
    'обычная функция' => "<?php\nfunction f(int \$x): int { return \$x + 1; }\n",
    'switch с default' => "<?php\nswitch (\$x) { default: throw new RuntimeException(\"x\"); }\n",
    'case с throw'     => "<?php\nswitch (\$x) { case 5: throw new RuntimeException(\"x\"); }\n",
    'catch с переменной' => "<?php\ntry { f(); } catch (RuntimeException \$e) { }\n",
    'массив и стрелка'  => "<?php\n\$f = static fn(int \$x): int => \$x * 2;\n",
];
$php74Lozhnye = [];
foreach ($php74Chistye as $imya => $kod) {
    $hits = gateNewerThanPhp74($kod);
    if ($hits !== []) $php74Lozhnye[] = $imya . ' → ' . implode(', ', $hits);
}
check('и не краснеет на коде, который на 7.4 законен',
    $php74Lozhnye === [], implode(' · ', $php74Lozhnye));
// Настоящее доказательство — разбор тем самым движком. Держим шаг в самопроверке
// и требуем, чтобы отсутствие docker называлось «не проверено», а не молчало.
$selfCheck74 = (string)file_get_contents(dirname(__DIR__) . '/tools/self-check.sh');
check('самопроверка разбирает боевой код на настоящем PHP 7.4, когда есть чем',
    strpos($selfCheck74, 'php:7.4-cli') !== false
    && strpos($selfCheck74, 'PHP 7.4 не проверен') !== false);

// ── У КАЖДОГО СПИСКА ИСКЛЮЧЕНИЙ ЕСТЬ ОТРИЦАТЕЛЬНЫЙ ОБРАЗЕЦ (П-4) ───────────
// Причина, вытащенная из ревью 08.08.2026: забор с исключениями выглядит
// работающим, пока никто не проверил, что он вообще краснеет. Так ядро и
// оказалось вне семантических проверок на полгода — список исключений был, а
// контрпримера рядом не было ни одного.
$reestryIsklyucheniy = [];
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $nabor) {
    $tela = (string)file_get_contents($nabor);
    // Реестр исключений узнаём по имени переменной: EXEMPT/OWNERS/ALLOW/EXCUSED.
    if (preg_match('~\$[A-Z_]*(EXEMPT|OWNERS|ALLOWED|EXCUSED|SKIP)[A-Z_]*\s*=\s*\[~', $tela) !== 1) continue;
    // Рядом обязан быть контрпример: проверка, которая доказывает, что забор
    // краснеет. Ищем по слову в названии проверки — оно и человеку объясняет,
    // зачем эта строка нужна.
    $estObrazets = preg_match('~check\(\'[^\']*(образец|контрпример|краснеет|ловится|блокируется)~u', $tela) === 1;
    if (!$estObrazets) $reestryIsklyucheniy[] = basename($nabor);
}
check('РЯДОМ С КАЖДЫМ СПИСКОМ ИСКЛЮЧЕНИЙ ЕСТЬ ДОКАЗАТЕЛЬСТВО, ЧТО ЗАБОР КРАСНЕЕТ',
    $reestryIsklyucheniy === [],
    $reestryIsklyucheniy === [] ? '' : 'исключения без контрпримера: ' . implode(', ', $reestryIsklyucheniy)
        . ' — добавьте проверку с образцом, который забор обязан поймать');

// Источник истины тот же, что у aggregate guard в tests/run.php: читаем его
// minimum и его lag-limit, а не заводим третий вручную поддерживаемый список.
preg_match('~const\s+SUITE_MIN_LAG_LIMIT\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*;~', $runSrc, $lagMatch);
$gateSuiteLagLimit = isset($lagMatch[1]) ? (float)$lagMatch[1] : 0.0;
$gateSuiteMinimum = (int)($suiteMins['gate_test.php'] ?? 0);
$gateSuiteFact = (int)($GLOBALS['__tests_total'] ?? 0) - $gateSuiteChecksAtStart + 1;
$gateSuiteExpectedMinimum = (int)floor($gateSuiteFact * 0.8);
check('FOCUSED GATE СИНХРОНИЗИРУЕТ ПОРОГ С ФАКТИЧЕСКИМ ЧИСЛОМ ПРОВЕРОК',
    $gateSuiteLagLimit > 1.0 && $gateSuiteMinimum > 0
    && $gateSuiteFact <= (int)ceil($gateSuiteMinimum * $gateSuiteLagLimit),
    "gate_test.php: порог $gateSuiteMinimum при $gateSuiteFact проверках, поднимите до $gateSuiteExpectedMinimum");
