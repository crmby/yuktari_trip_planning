<?php
declare(strict_types=1);
/**
 * «Приложение пользуется готовым» — гейт против самодеятельности.
 *
 * Зачем. Шаблон даёт готовые блоки: единый вход эндпоинтов, стор под замком,
 * клиент портала, защиту от повтора, свои диалоги вместо браузерных. Но написать
 * своё всегда быстрее, чем прочитать чужое, — и в проектах раз за разом
 * появлялись самодельный гейт без проверки частоты, запись в состояние без
 * замка, свой curl без повторов и браузерный confirm, который в iframe блокирует
 * портал. Ловил это человек на ревью, то есть своим временем.
 *
 * Теперь ловит машина: набор смотрит код ПРОЕКТА и падает, если готовое обошли
 * или не взяли там, где оно обязательно.
 *
 * ⚠ Предикаты живут в tests/gate-lib.php — там же, где их проверяет
 * gate_test.php на образцах нарушений. Последовательные сторонние ревью
 * 03.08.2026 воспроизвели обходы прежних версий; каждый закрыт и подтверждён
 * отдельной проверкой.
 *
 * Business-код не может сам снять правило комментарием: иначе агент сам себе
 * разрешает не пользоваться готовым. Редкое обоснованное исключение требует
 * подтверждённой правки защищённого `tests/gate-lib.php` и новой регрессии в
 * `tests/gate_test.php`; без этого гейт остаётся fail-closed.
 */

require_once __DIR__ . '/gate-lib.php';

suite('Приложение пользуется готовым, а не пишет своё');

$root = dirname(__DIR__);

/**
 * ЯДРО шаблона: библиотеки, которые по устройству живут по своим правилам (сами
 * пишут стор, сами ходят в сеть, сами собирают ответ) и потому из части проверок
 * исключены.
 *
 * Свой файл сюда дописывать НЕЛЬЗЯ: тот же список продублирован в
 * tests/gate_test.php и сверяется с этим — дописанная строка роняет прогон.
 * Раньше список был обычным редактируемым перечнем, и «вписать себя в ядро»
 * было самым коротким способом обойти весь гейт.
 */
$CORE_API = ['www/api/http.php', 'www/api/store.php', 'www/api/lib.php',
             'www/api/b24.php', 'www/api/session.php', 'www/api/access.php',
             'www/api/acl.php', 'www/api/event.php', 'www/api/placements.php',
             'www/api/journal.php', 'www/api/fmt.php', 'www/api/export-csv.php',
             'www/api/queue.php', 'www/api/events-bind.php', 'www/api/crm-dict.php', 'www/api/plan.php', 'www/api/capability.php', 'www/api/fetch-all-compat.php',
             'www/api/cache.php', 'www/api/uf.php', 'www/api/notify.php'];

// В корне www есть только три служебные точки. Проектные API живут в www/api:
// иначе файл в корне не попадает в обязательный apiBoot() и проверку единого
// ответа. Список также зафиксирован в gate_test.php — «разрешить себе» новый
// корневой endpoint одной строкой не получится.
$ROOT_PHP = ['www/index.php', 'www/env.php', 'www/release.php'];

// `www/bin` тоже находится под DocumentRoot, поэтому это не свободная папка
// для «служебного» PHP. Разрешены только известные CLI-точки, и каждая обязана
// закрыть HTTP пустым 404 до require, вывода и любого другого действия. Список,
// как и CORE_API/ROOT_PHP, зафиксирован независимой регрессией ниже.
$CORE_BIN = ['www/bin/deploy-private-store.php', 'www/bin/logs.php',
             'www/bin/process.php'];

// Инвентаризация начинается со ВСЕГО www/, а уже потом классифицирует файлы.
// Иначе `www/lib/x.php` не относится ни к api, ни к bin, ни к корню — и прежний
// гейт не видел его вообще. Сторонний vendor рекурсор исключает сам.
$wwwPhp = gatePhpSourceFilesUnder($root . '/www');
$nonCanonicalPhp = array_values(array_filter(
    $wwwPhp,
    static fn($f) => strtolower((string)pathinfo($f, PATHINFO_EXTENSION)) !== 'php'
));
$apiFiles = array_values(array_filter(
    $wwwPhp,
    static fn($f) => strncmp(gateRelativePath($root, $f), 'www/api/', 8) === 0
));
$ourApi   = array_values(array_filter(
    $apiFiles,
    static fn($f) => !gateIsExactPath($root, $f, $CORE_API)
));
$binFiles = array_values(array_filter(
    $wwwPhp,
    static fn($f) => strncmp(gateRelativePath($root, $f), 'www/bin/', 8) === 0
));

$unexpectedPhp = gateUnexpectedPhpLocations(
    $root,
    $wwwPhp,
    array_merge($ROOT_PHP, $CORE_BIN),
    ['www/api/']
);
$forbiddenWebConfig = gateForbiddenWebConfigFiles($root . '/www');
$forbiddenPhpBackups = gateForbiddenPhpBackupFiles($root . '/www');
$badRequireTargets = [];
foreach ($wwwPhp as $f) {
    // api/lib.php один раз подключает сгенерированный release по уже проверенному
    // абсолютному пути. Исключение точное: произвольный dynamic require не пройдёт.
    $allowedDynamic = gateIsExactPath($root, $f, ['www/api/lib.php'])
        ? ['require_once $releaseFile'] : [];
    $ls = gateNonCanonicalRequireLines($f, $allowedDynamic);
    if ($ls !== []) $badRequireTargets[] = gateRelativePath($root, $f) . ':' . implode(',', $ls);
}
$envFile = $root . '/www/env.php';
// Забор прямого HTTP в env.php — не «ранний исполняемый код»: он ничего не
// делает при подключении и существует ровно для того, чтобы прямой запрос
// получил 404 вместо пустого 200 (BXREV-025).
$badEnvEffects = is_file($envFile)
    ? array_values(array_diff(gateEnvEffectLines($envFile), gateDirectAccessGuardLines($envFile)))
    : [0];

// Эндпоинт — файл, который САМ что-то делает при обращении к нему. Признак
// ПОЛОЖИТЕЛЬНЫЙ и считается по исполнению: gateTopLevelCalls() отвечает, какие
// функции файл фактически зовёт при загрузке (в том числе из замыкания,
// вызванного на месте, — так написана ранняя защита шаблона).
//
// РАНЬШЕ ЗДЕСЬ СТОЯЛ ВЫВЕРНУТЫЙ ПРИЗНАК «нет забора прямого доступа — значит
// эндпоинт». Забор он узнавал ровно один, дословно, поэтому библиотека с
// рабочим, но иначе написанным забором (404 через header(); гард PHP_SAPI, как
// в www/bin/process.php) объявлялась эндпоинтом и валила прогон словами «без
// единого входа», хотя защита у неё работает. Обратная сторона того же признака
// — тихая дыра: канонический забор, приписанный к работающему эндпоинту, уводил
// его из-под apiBoot() целиком. Тот же вывернутый признак уже снят в
// security_test.php (находки K2-01 и K2-04 ревью 09.08.2026) — здесь остался.
//
// ТРАКТОВКА ОДНА С security_test.php, разница ровно на один шаг. Там точка входа
// — файл, который при загрузке САМ аутентифицирует запрос (apiBoot/hash_equals);
// всё остальное обязано отвечать 404, и это проверяется живым сервером. Здесь
// признак шире: эндпоинт — файл, который при загрузке делает хоть что-то СВЕРХ
// отказа в прямом доступе. Иначе файл, который работает при загрузке, но
// apiBoot() не зовёт вовсе, объявил бы себя библиотекой и ушёл бы из-под
// проверки — то есть проверка ослабла бы ровно там, ради чего написана.
//
// Отказ в прямом доступе — это 404 и выход. Судим его ПО ФОРМЕ, а не по тому,
// какой функцией сравнили путь: сам блок вырезается из исходника, и вердикт
// выносится по тому, что осталось.
//
// Раньше здесь стоял закрытый список из шести имён (realpath, php_sapi_name,
// http_response_code, header, header_remove, headers_sent). Забор, написанный
// через basename()/str_ends_with()/strpos() — обычный способ сравнить
// SCRIPT_FILENAME с __FILE__, — содержал вызов вне списка, и файл объявлялся
// эндпоинтом: прогон краснел двумя проверками сразу, хотя живой GET по такому
// файлу отдаёт 404 (находка K4-24 ревью 09.08.2026). Хуже был не сам красный, а
// подсказка: она предлагала добавить apiBoot() и «единый формат ответа», то есть
// СНЯТЬ забор с библиотеки.
//
// Список имён оставлен для остатка: файл без забора, который на верхнем уровне
// только отвечает 404. Остальное — файл без единого вызова, печатающий ответ
// сам, — ловят соседи: единый формат ответа здесь и живой 404-контур в
// security_test.php.
$OTKAZ_V_PRYAMOM_DOSTUPE = ['realpath', 'php_sapi_name', 'http_response_code',
                            'header', 'header_remove', 'headers_sent'];
/**
 * Копия файла без блока «отказ в прямом доступе» (номера строк сохранены).
 * Забор ищется по форме: условие поминает __FILE__/SCRIPT_FILENAME, тело отдаёт
 * 4xx и выходит. Забора нет — возвращается сам файл, лишних копий не плодим.
 */
$bezZabora = static function (string $f) use ($tmp): string {
    static $kesh = [];
    if (isset($kesh[$f])) return $kesh[$f];
    $src = (string)@file_get_contents($f);
    if ($src === '') return $kesh[$f] = $f;
    $bloki = [];
    if (preg_match_all('~\bif\s*\([^{}]*?(?:__FILE__|SCRIPT_FILENAME)[^{}]*?\)\s*\{[^{}]*?\}~s',
            $src, $sovpadeniya, PREG_OFFSET_CAPTURE)) {
        foreach ($sovpadeniya[0] as $hit) {
            [$blok, $ofs] = $hit;
            if (!preg_match('~\b(?:exit|die)\b~', $blok)) continue;
            if (!preg_match('~http_response_code\s*\(\s*4\d\d\s*\)~', $blok)) continue;
            $bloki[] = [$blok, (int)$ofs];
        }
    }
    if ($bloki === []) return $kesh[$f] = $f;
    $ochishchen = $src;
    // С конца: вырезание со сдвигом иначе испортит offset'ы следующих блоков.
    foreach (array_reverse($bloki) as [$blok, $ofs]) {
        $ochishchen = substr_replace($ochishchen,
            str_repeat("\n", substr_count($blok, "\n")), $ofs, strlen($blok));
    }
    $put = $tmp . '/bez-zabora-' . substr(md5($f), 0, 12) . '-' . basename($f);
    file_put_contents($put, $ochishchen);
    return $kesh[$f] = $put;
};
$rabotaetPriZagruzke = static function (string $f) use ($OTKAZ_V_PRYAMOM_DOSTUPE, $bezZabora): bool {
    foreach (gateTopLevelCalls($bezZabora($f)) as $imya) {
        if (!in_array(strtolower($imya), $OTKAZ_V_PRYAMOM_DOSTUPE, true)) return true;
    }
    return false;
};
$endpoints = array_values(array_filter($ourApi, $rabotaetPriZagruzke));
// Тот же project-код, но БЕЗ деления на эндпоинты и библиотеки: сюда попадают и
// файлы-помощники внутри api/, через которые обходили запреты.
$appApiPhp = array_values($ourApi);

$frontFiles = array_values(array_unique(array_merge(
    gateFilesUnder($root . '/www', 'js'),
    gateFilesUnder($root . '/www', 'mjs'),
    gateFilesUnder($root . '/www', 'cjs'),
    gateFilesUnder($root . '/www', 'html'),
    gateFilesUnder($root . '/www', 'htm'),
    gateFilesUnder($root . '/www', 'xhtml'),
    gateFilesUnder($root . '/www', 'svg'),
    [$root . '/www/index.php']
)));
$appPhp     = array_values(array_unique(array_filter(
    $wwwPhp,
    static fn($f) => !gateIsExactPath($root, $f, array_merge($CORE_API, ['www/env.php']))
)));
// ГОТОВЫЕ МОДУЛИ ЯДРА, а не прикладной код: транспорт (ui.js) и свои контролы
// (controls.js). Внутри календаря арифметика дат неизбежна — он для того и
// написан один раз, чтобы прикладной код не считал даты руками. Всё остальное
// во www/js судится как проектное.
$UI_CORE_JS = ['www/js/ui.js', 'www/js/controls.js', 'www/js/frame.js'];
$allScripts = array_values(array_unique(array_merge(
    gateFilesUnder($root . '/www', 'js'),
    gateFilesUnder($root . '/www', 'mjs'),
    gateFilesUnder($root . '/www', 'cjs')
)));
$projectScripts = array_values(array_filter($allScripts,
    static fn($f) => !gateIsExactPath($root, $f, $UI_CORE_JS)));
// Файлы ядра фронта судятся ТЕМИ ЖЕ мерками, но каждой строке с арифметикой
// нужен короткий ответ «почему это не предметные даты и деньги». Раньше они были
// исключены целиком — настоящая ошибка внутри них не нашлась бы никогда.
$uiCoreScripts = array_values(array_filter($allScripts,
    static fn($f) => gateIsExactPath($root, $f, $UI_CORE_JS)));

$rel = static fn(string $f): string => str_replace($root . '/', '', $f);
$lines = static fn(string $f, array $ls): string => implode(', ', array_map(static fn($l) => str_replace(dirname(__DIR__) . '/', '', $f) . ':' . $l, $ls));

check('весь first-party PHP лежит в www/api, фиксированном CLI-наборе или служебном корне', $unexpectedPhp === [],
    $unexpectedPhp === [] ? '' : 'неожиданное место; перенесите файл: ' . implode(', ', array_map($rel, $unexpectedPhp)));
check('PHP-исходники в webroot имеют только каноническое расширение .php',
    $nonCanonicalPhp === [],
    $nonCanonicalPhp === [] ? '' : 'скрытый/небезопасный PHP-source: ' . implode(', ', array_map($rel, $nonCanonicalPhp)));
check('webroot не переназначает обработчики через .htaccess/.user.ini/php.ini/web.config',
    $forbiddenWebConfig === [],
    $forbiddenWebConfig === [] ? '' : 'запрещённый web-config: ' . implode(', ', array_map($rel, $forbiddenWebConfig)));
check('в webroot нет PHP-backup/double-extension, отдающего исходник или обходящего handler',
    $forbiddenPhpBackups === [],
    $forbiddenPhpBackups === [] ? '' : 'опасная копия PHP: ' . implode(', ', array_map($rel, $forbiddenPhpBackups)));
check('project-код подключает только статически названные .php-файлы',
    $badRequireTargets === [],
    $badRequireTargets === [] ? '' : 'скрытый/dynamic require: ' . implode(', ', $badRequireTargets));
check('env.php остаётся конфигом без сети, записи, вывода и произвольных вызовов до auth',
    $badEnvEffects === [],
    $badEnvEffects === [] ? '' : 'ранний исполняемый код env.php, строки: ' . implode(', ', $badEnvEffects));

$badCliGuard = [];
foreach ($binFiles as $f) {
    if (!gateHasEarlyCli404Guard($f)) $badCliGuard[] = $rel($f);
}
check('каждая CLI-точка закрывает HTTP пустым 404 до первого действия', $badCliGuard === [],
    $badCliGuard === [] ? '' : 'нет раннего CLI/404-пролога: ' . implode(', ', $badCliGuard));

// Обязательный helper должен прийти из ядра, а не быть пустышкой с тем же
// именем в endpoint/worker. Exact core уже исключён из $appPhp выше.
$shadowedHelpers = [];
foreach ($appPhp as $f) {
    $ls = gateProtectedHelperShadows($f);
    if ($ls !== []) $shadowedHelpers[] = $lines($f, $ls);
}
check('project-код не подменяет и не импортирует-алиасит обязательные helper-имена',
    $shadowedHelpers === [],
    $shadowedHelpers === [] ? '' : 'подмена готового модуля: ' . implode(', ', $shadowedHelpers));

// Даты портала и деньги уже реализованы в fmt.php и автоматически подключены
// через http.php. Fail-closed правило не позволяет прикладному endpoint снова
// изобрести date/strtotime/DateTime/float/round. Во фронте доменное значение
// приходит уже подготовленным сервером, а не пересчитывается в TZ браузера.
$badDomainPrimitives = [];
foreach ($ourApi as $f) {
    $ls = gateRawDomainPrimitiveLines($f);
    if ($ls !== []) $badDomainPrimitives[] = $lines($f, $ls);
}
// Фоновая обработка — тоже прикладной код: шаблон сам велит писать её в projectJob()
// внутри www/bin/process.php. Раньше файл в проверку не попадал, и любой date()
// или (float) в дневном отчёте проходил молча — при том, что именно в отчётах
// деньги и даты и считают. Служебные строки каркаса (таймауты блокировок, замер
// длительности тика) распознаются по смыслу — см. gateServicePrimitiveTokens().
$backgroundJobFile = $root . '/www/bin/process.php';
if (is_file($backgroundJobFile)) {
    $ls = gateRawDomainPrimitiveLines($backgroundJobFile);
    if ($ls !== []) $badDomainPrimitives[] = $lines($backgroundJobFile, $ls);
}
foreach ($projectScripts as $f) {
    $ls = gateFrontRawDomainPrimitiveLines($f);
    if ($ls !== []) $badDomainPrimitives[] = $lines($f, $ls);
}
foreach ($uiCoreScripts as $f) {
    $ls = gateFrontRawDomainPrimitiveLines($f, true);
    if ($ls !== []) $badDomainPrimitives[] = $lines($f, $ls);
}
// Подавленная `@` операция записи, чей результат никто не проверил, — самый тихий
// способ потерять данные: диск кончился, права слетели, каталог исчез, а
// приложение продолжает работать и рапортует успех. Скан проектов, ни разу не
// проходивших ревью, нашёл это в восьми из четырнадцати — в том числе на записи
// OAuth-токенов, где цена молчания это потеря доступа к порталу.
$suppressedIo = [];
foreach (array_merge($wwwPhp, [$backgroundJobFile]) as $f) {
    if (!is_file($f)) continue;
    $ls = gateSuppressedIoLines($f);
    if ($ls !== []) $suppressedIo[] = $lines($f, $ls);
}
check('сбой записи не глушится оператором @ без проверки результата',
    $suppressedIo === [],
    $suppressedIo === [] ? '' : 'молчаливая запись: ' . implode(', ', $suppressedIo));

check('project-код использует готовый fmt.php, а не сырые даты/float/round',
    $badDomainPrimitives === [],
    $badDomainPrimitives === [] ? '' : 'обход fmt.php: ' . implode(', ', $badDomainPrimitives));

// 0.9. САМ ПРИЗНАК «ЭНДПОИНТ ИЛИ БИБЛИОТЕКА» — НА ОБРАЗЦАХ.
//      Признак судит чужой код, значит его самого надо проверять работой:
//      рабочий забор прямого доступа обязан считаться библиотекой в ЛЮБОЙ форме
//      записи, а файл, который при загрузке работает, — эндпоинтом. До правки
//      09.08.2026 (K4-24) форма с basename() валила прогон двумя проверками,
//      хотя живой GET по такому файлу отдавал 404.
$FORMY_ZABORA = [
    'realpath'      => "if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''))) {\n    http_response_code(404);\n    header('Cache-Control: no-store');\n    exit;\n}\n",
    'basename'      => "if (PHP_SAPI !== 'cli' && basename((string)(\$_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {\n    http_response_code(404);\n    header('Cache-Control: no-store');\n    exit;\n}\n",
    'str_ends_with' => "if (PHP_SAPI !== 'cli' && str_ends_with((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''), basename(__FILE__))) {\n    http_response_code(404);\n    exit;\n}\n",
    'strpos'        => "if (PHP_SAPI !== 'cli' && strpos((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''), basename(__FILE__)) !== false) {\n    http_response_code(404);\n    die;\n}\n",
];
$zaborKakEndpoint = [];
$zaborKakOtvet = [];
foreach ($FORMY_ZABORA as $forma => $kod) {
    $proba = $tmp . '/proba-zabor-' . $forma . '.php';
    file_put_contents($proba, "<?php\n" . $kod . "\nfunction probaZaborZnachenie(int \$x): int { return \$x * 2; }\n");
    if ($rabotaetPriZagruzke($proba)) $zaborKakEndpoint[] = $forma;
    if (gateOwnAnswerLines($bezZabora($proba)) !== []) $zaborKakOtvet[] = $forma;
}
check('РАБОЧИЙ ЗАБОР ПРЯМОГО ДОСТУПА ОСТАЁТСЯ БИБЛИОТЕКОЙ В ЛЮБОЙ ФОРМЕ ЗАПИСИ',
    $zaborKakEndpoint === [],
    $zaborKakEndpoint === [] ? '' : 'объявлены эндпоинтами: ' . implode(', ', $zaborKakEndpoint));
check('забор прямого доступа не считается своим форматом ответа',
    $zaborKakOtvet === [],
    $zaborKakOtvet === [] ? '' : 'посчитаны своим ответом: ' . implode(', ', $zaborKakOtvet));
// Обратная сторона того же признака: послабление не должно уводить из-под
// проверки файл, который при загрузке РАБОТАЕТ.
$probaEndpoint = $tmp . '/proba-endpoint.php';
file_put_contents($probaEndpoint,
    "<?php\nif (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''))) {\n    http_response_code(404);\n    exit;\n}\napiBoot();\napiOk([]);\n");
check('образец: файл, работающий при загрузке, остаётся эндпоинтом даже с забором',
    $rabotaetPriZagruzke($probaEndpoint));

// 1. Эндпоинт входит через apiBoot() ДО первого действия. Прежняя проверка
//    требовала «вызов где-то в основном теле», и её проходил файл, который сперва
//    писал состояние и отдавал ответ, а вход звал последним.
$badGate = [];
foreach ($endpoints as $f) {
    if (!gateBootBeforeEffects($f, 'apiBoot')) $badGate[] = $rel($f);
}
check('каждый эндпоинт входит через apiBoot() до первого действия', $badGate === [],
    $badGate === [] ? '' : 'без единого входа: ' . implode(', ', $badGate)
        . ' — эндпоинту первой строкой нужен apiBoot() (образец: api/settings.php);'
        . ' если это библиотека, забор прямого доступа не снимайте, а проверьте, что она ничего не делает при подключении');

// 1.1. Заведомо мёртвая ветка — способ «выполнить требование», ничего не выполняя.
$deadBranch = [];
foreach ($appPhp as $f) {
    if (gateHasDeadBranch($f)) $deadBranch[] = $rel($f);
}
check('в коде нет заведомо ложных ветвлений', $deadBranch === [],
    $deadBranch === [] ? '' : 'мёртвая ветка: ' . implode(', ', $deadBranch));

// 2. Состояние меняется только через стор: замок, временный файл, права 0600.
//    Прямая запись теряет правку соседнего запроса — портал открывает их пачкой.
$badWrite = [];
foreach ($appPhp as $f) {
    $ls = gateDirectStateWrites($f);
    if ($ls !== []) $badWrite[] = $lines($f, $ls);
}
check('состояние пишется через storeUpdate/storeWrite, а не напрямую', $badWrite === [],
    $badWrite === [] ? '' : 'прямая запись файлов: ' . implode(', ', $badWrite));

// Путь logs/ сам по себе не делает собственный файловый лог штатным: ротация,
// маскировка, generation и проверяемый ref живут только в journal/appLog.
$badRawLog = [];
foreach ($appPhp as $f) {
    $ls = gateRawLogWriteLines($f);
    if ($ls !== []) $badRawLog[] = $lines($f, $ls);
}
check('project-код пишет журнал только через appLog/journal, а не прямо в logs/',
    $badRawLog === [],
    $badRawLog === [] ? '' : 'самодельный файловый журнал: ' . implode(', ', $badRawLog));

// 3. В портал ходим клиентом: он умеет повторы на лимитах, обновление токена
//    под замком и режет пакеты по 50. Свой curl не умеет ничего из этого.
$badCurl = [];
foreach ($appPhp as $f) {
    $ls = gateOwnHttpLines($f);
    if ($ls !== []) $badCurl[] = $lines($f, $ls);
}
check('запросы к порталу идут через клиент b24(), а не своим curl', $badCurl === [],
    $badCurl === [] ? '' : 'свой HTTP-клиент: ' . implode(', ', $badCurl));

$badGenericPortal = [];
foreach ($appPhp as $f) {
    $ls = gateGenericPortalHttpLines($f);
    if ($ls !== []) $badGenericPortal[] = $lines($f, $ls);
}
check('generic httpJson не используется как обход клиента b24()', $badGenericPortal === [],
    $badGenericPortal === [] ? '' : 'REST Б24 через generic HTTP: ' . implode(', ', $badGenericPortal));

// Динамический dispatch скрывает от положительного гейта, какой готовый helper
// реально вызван. В project-коде он не нужен: вызываем методы явно.
$badDispatch = [];
foreach ($appPhp as $f) {
    $ls = gateIndirectDispatchLines($f);
    if ($ls !== []) $badDispatch[] = $lines($f, $ls);
}
check('project-код не скрывает вызовы модулей через dynamic dispatch/reflection',
    $badDispatch === [],
    $badDispatch === [] ? '' : 'непрямой вызов: ' . implode(', ', $badDispatch));

// 4. Ответы эндпоинтов — одного вида. Свой json_encode рано или поздно отдаёт
//    другую форму, и фронт показывает «ошибка 500» вместо нашего текста.
// Проверяем ВЕСЬ project-код в api/, а не только файлы-эндпоинты: запрещённые
// примитивы легко вынести в соседний файл-«помощник» (он выглядит библиотекой,
// потому что содержит только функции) и звать их оттуда. Ревью 05.08.2026
// воспроизвело ровно это: чтение php://input уехало в helper и прошло гейт.
$badAnswer = [];
foreach ($appApiPhp as $f) {
    // Забор прямого доступа — не «свой формат ответа»: 404 и выход есть в каждой
    // библиотеке шаблона. Вырезаем его той же формой, что и выше, иначе забор,
    // написанный не каноническим realpath'ом, краснел бы здесь (K4-24).
    $ls = gateOwnAnswerLines($bezZabora($f));
    if ($ls !== []) $badAnswer[] = $lines($f, $ls);
}
check('ответы отдаются через apiOk/apiFail', $badAnswer === [],
    $badAnswer === [] ? '' : 'свой формат ответа: ' . implode(', ', $badAnswer));

$badInput = [];
foreach ($appApiPhp as $f) {
    $ls = gateRawInputLines($f);
    if ($ls !== []) $badInput[] = $lines($f, $ls);
}
check('тело endpoint разбирается через apiBody и валидаторы', $badInput === [],
    $badInput === [] ? '' : 'сырой вход: ' . implode(', ', $badInput));

// 5. Браузерные диалоги запрещены: в iframe портала confirm() подвешивает не
//    только приложение, а весь портал целиком.
$badDialog = [];
foreach ($frontFiles as $f) {
    if (gateBrowserDialog($f)) $badDialog[] = $rel($f);
}
check('во фронте нет браузерных alert/confirm/prompt', $badDialog === [],
    $badDialog === [] ? '' : 'браузерные диалоги: ' . implode(', ', $badDialog));

$badFrontTransport = [];
foreach ($frontFiles as $f) {
    // Сам transport реализован ровно в готовом модуле; project-код только зовёт ui.api().
    if (gateIsExactPath($root, $f, ['www/js/ui.js'])) continue;
    $ls = gateFrontTransportLines($f);
    if ($ls !== []) $badFrontTransport[] = $lines($f, $ls);
}
check('frontend ходит в сеть через ui.api, а не fetch/XHR/BX24.callMethod',
    $badFrontTransport === [],
    $badFrontTransport === [] ? '' : 'обход ui.api: ' . implode(', ', $badFrontTransport));

$duplicateUi = [];
foreach ($frontFiles as $f) {
    if (gateIsExactPath($root, $f, ['www/js/controls.js'])) continue;
    $ls = gateDuplicateReadyUiLines($f);
    if ($ls !== []) $duplicateUi[] = $lines($f, $ls);
}
check('select/date/number/people UI подключается готовыми ui.* без своего дубля',
    $duplicateUi === [], $duplicateUi === [] ? '' : 'самодельный ready UI: ' . implode(', ', $duplicateUi));

$duplicateEndpoints = [];
foreach ($ourApi as $f) {
    $relative = $rel($f);
    // Канонические template endpoints сами реализуют роль. Предикат применяется
    // только к проектным файлам и смотрит тело, поэтому произвольное имя не спасает.
    if (in_array($relative, gateTemplateCapabilityApiFiles(), true)) continue;
    if (gateDuplicateReadyEndpoint($f)) $duplicateEndpoints[] = $relative;
}
check('готовые settings/task/users/health/ops/CSV endpoint не дублируются под другим именем',
    $duplicateEndpoints === [], $duplicateEndpoints === [] ? '' : 'дубли ready endpoint: ' . implode(', ', $duplicateEndpoints));

$directEmployeeSearch = [];
foreach ($ourApi as $f) {
    $relative = $rel($f);
    // Готовый users.php сам реализует этот контракт; project endpoint обязан
    // звать его через ui.pickPeople, а не повторять REST-поиск.
    if (in_array($relative, gateTemplateCapabilityApiFiles(), true)) continue;
    $hits = gateDirectEmployeeNameSearchLines($f);
    if ($hits !== []) $directEmployeeSearch[] = $lines($f, $hits);
}
check('project-owned поиск сотрудника переиспользует users.php/ui.pickPeople с фото',
    $directEmployeeSearch === [],
    $directEmployeeSearch === [] ? ''
        : 'прямой user.get NAME_SEARCH: ' . implode(', ', $directEmployeeSearch));

// 6. Секрет не должен попасть в журнал: пишем только через maskSecrets. Проверка
//    идёт по ВСЕМ файлам, включая ядро: утечка токена не становится безопаснее
//    оттого, что её допустили в библиотеке.
$allPhp = $wwwPhp;
$badLog = [];
foreach ($allPhp as $f) {
    $ls = gateRawExceptionLines($f);
    if ($ls !== []) $badLog[] = $lines($f, $ls);
}
check('текст исключения уходит в журнал только замаскированным', $badLog === [],
    $badLog === [] ? '' : 'сырой текст ошибки: ' . implode(', ', $badLog) . ' — оберните в maskSecrets()');

// Секрет не должен существовать в исходнике вообще. Это отдельный запрет от
// маскировки журнала: maskSecrets не спасает client_secret/webhook, уже
// закоммиченный в PHP, JS или deploy-скрипт. Пустые значения, env/stdin и
// runtime-generated token остаются допустимыми.
$badCredentials = [];
foreach ($wwwPhp as $f) {
    $ls = gateHardcodedCredentialLines($f);
    if ($ls !== []) $badCredentials[] = $lines($f, $ls);
}
foreach (array_values(array_unique(array_merge(
    gateFilesUnder($root . '/www', 'js'),
    gateFilesUnder($root . '/www', 'mjs'),
    gateFilesUnder($root . '/www', 'cjs')
))) as $f) {
    $ls = gateFrontHardcodedCredentialLines($f);
    if ($ls !== []) $badCredentials[] = $lines($f, $ls);
}
$shellFiles = array_values(array_unique(array_merge(
    gateFilesUnder($root . '/scripts', 'sh'),
    gateFilesUnder($root . '/tools', 'sh'),
    [$root . '/deploy-prod.sh']
)));
foreach ($shellFiles as $f) {
    if (!is_file($f)) continue;
    $ls = gateShellHardcodedCredentialLines($f);
    if ($ls !== []) $badCredentials[] = $lines($f, $ls);
}
check('в first-party PHP/JS/shell нет захардкоженных секретов и webhook',
    $badCredentials === [],
    $badCredentials === [] ? '' : 'credential literal: ' . implode(', ', $badCredentials));

// 7. ПОЛОЖИТЕЛЬНАЯ проверка: CRUD-мутация защищена от повтора, точным
//    readBackChecked() того же ID/fields; delete/action — policy-helper'ом; всё оставляет след.
//    только запрещать «своё» и никак не требовал взять готовое.
$badMutation = [];
foreach ($appPhp as $f) {
    foreach (gateUnprotectedMutations($f) as $hit) $badMutation[] = $rel($f) . ' → ' . $hit;
}
check('мутации портала идут через runOnce(), verified-helper/readBackChecked() и auditLog()', $badMutation === [],
    $badMutation === [] ? '' : 'мутация без защиты от повтора, read-back или следа: ' . implode(', ', $badMutation));

$unsafeRetry = [];
foreach ($appPhp as $f) {
    foreach (gateUnsafeExplicitMutationRetry($f) as $hit) $unsafeRetry[] = $rel($f) . ' → ' . $hit;
}
check('мутация не включает raw safeToRepeat=true под видом защиты runOnce',
    $unsafeRetry === [], $unsafeRetry === [] ? '' : 'опасный transport retry: ' . implode(', ', $unsafeRetry));

// 7.1. Список портала без пагинации молча отдаёт первые 50 записей — и отчёт
//      «сходится» на неполных данных.
$badPaging = [];
foreach ($appPhp as $f) {
    foreach (gateUnpagedLists($f) as $hit) $badPaging[] = $rel($f) . ' → ' . $hit;
}
check('списки портала читаются с пагинацией (fetchAll/fetchAllBatched)', $badPaging === [],
    $badPaging === [] ? '' : 'список без пагинации: ' . implode(', ', $badPaging));

$journalScreenProblems = gateJournalScreenProblems($root);
check('экран журнала ограничен 25 строками и имеет opaque cursor + Back/Next',
    $journalScreenProblems === [], implode(' · ', $journalScreenProblems));

// 8. Карта проекта не отстаёт от кода: новый файл api/ обязан быть в ней назван —
//    и НАЗВАН ПО ДЕЛУ. Упоминание в духе «такого файла нет» не считается.
$map = is_file($root . '/docs/MAP.md') ? (string)file_get_contents($root . '/docs/MAP.md') : '';
$notInMap = [];
foreach ($apiFiles as $f) {
    if ($map === '') break;
    $relative = $rel($f);
    if (!gateMapDescribes($map, $relative)) $notInMap[] = $relative;
}
$notInMap = array_values(array_unique($notInMap));
check('каждый файл api/ описан в карте проекта docs/MAP.md', $map !== '' && $notInMap === [],
    $map === '' ? 'нет docs/MAP.md' : ($notInMap === [] ? '' : 'нет в карте: ' . implode(', ', $notInMap)));

// 8.0. И карта не просто УПОМИНАЕТ файлы, а совпадает с кодом по составу.
//
// Упоминания мало: файл может быть назван, а список состояния, точки входа и
// набор функций — отстать на десять правок. Так и вышло в шаблоне: карта
// перечисляла 10 файлов состояния из 17 и неверно считала файлы под забором
// прямого HTTP (находка ревью 09.08.2026). Устаревшая карта опаснее отсутствующей:
// выглядит она ровно так же достоверно, как свежая.
//
// Поэтому инвентарь карты собирает машина (`php tools/map-sync.php`), а здесь мы
// требуем, чтобы собранное совпало с тем, что лежит в файле. Забыл пересобрать —
// прогон красный, а прогон это гейт деплоя: устаревшую карту не выкатить.
$mapSync = $root . '/tools/map-sync.php';
if (is_file($mapSync)) {
    $vyhlop = [];
    $kod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mapSync) . ' --check 2>&1', $vyhlop, $kod);
    // ГОТОВУЮ КОМАНДУ «пересобрать» из текста провала УБИРАЕМ (K4-13).
    // Отпечаток карты — единственная проверка, которая краснеет на всякой правке
    // кода, и пока в тексте провала стояла строка `php tools/map-sync.php`, забор
    // учил гасить себя одной командой: карта пересобиралась, прогон зеленел, а
    // ПОВЕДЕНИЕ правки при этом не проверял никто. Ревью 09.08.2026 подсадило
    // пять образцов нарушения, возвращавших поведение до правок круга 2, — по
    // существу не покраснел ни один, красным был только этот отпечаток.
    $prichina = trim((string)preg_replace('~Пересобрать:.*$~us', '',
        trim(implode(' ', array_slice($vyhlop, 0, 2)))));
    check('инвентарь карты собран из кода и совпадает с ним', $kod === 0,
        $kod === 0 ? $prichina : $prichina
            . ' — карта отстала от кода. Пересборка инвентаря гасит этот красный, но НИЧЕГО не доказывает:'
            . ' сперва закройте саму правку проверкой её поведения, и только потом пересобирайте карту');
} else {
    // Скрипта нет — проект собран из старой копии ядра. Не провал: карта всё ещё
    // проверяется по упоминаниям выше, но сказать об этом надо вслух.
    check('инвентарь карты собран из кода и совпадает с ним', true,
        'tools/map-sync.php нет — инвентарь карты не сверяется, подтяните ядро из шаблона');
}

// Рекурсивность проверяем не словами в map-sync.php, а настоящей мини-копией
// проекта: два вложенных файла с одинаковым basename обязаны попасть в карту
// каждый под своим полным путём. Так прежний shallow glob/basename не вернётся
// зелёным после рефакторинга скрипта.
$mapProbe = sys_get_temp_dir() . '/bxapp-map-recursive-' . bin2hex(random_bytes(4));
@mkdir($mapProbe . '/tools', 0700, true);
@mkdir($mapProbe . '/tests', 0700, true);
@mkdir($mapProbe . '/docs', 0700, true);
@mkdir($mapProbe . '/www/api/a', 0700, true);
@mkdir($mapProbe . '/www/api/b', 0700, true);
copy($root . '/tools/map-sync.php', $mapProbe . '/tools/map-sync.php');
copy($root . '/tests/gate-lib.php', $mapProbe . '/tests/gate-lib.php');
file_put_contents($mapProbe . '/docs/MAP.md', "# Probe\n\n<!-- MAP-INVENTORY:START -->\nold\n<!-- MAP-INVENTORY:END -->\n");
file_put_contents($mapProbe . '/www/env.php', "<?php\n/** Конфигурация probe. */\n");
$nestedPhp = "<?php\ndeclare(strict_types=1);\n/** Вложенная библиотека probe. */\nfunction %s(): void {}\n";
file_put_contents($mapProbe . '/www/api/a/report.php', sprintf($nestedPhp, 'mapProbeA'));
file_put_contents($mapProbe . '/www/api/b/report.php', sprintf($nestedPhp, 'mapProbeB'));
$mapProbeOut = [];
$mapProbeRc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mapProbe . '/tools/map-sync.php') . ' 2>&1',
    $mapProbeOut, $mapProbeRc);
$mapProbeText = (string)@file_get_contents($mapProbe . '/docs/MAP.md');
$mapProbeCheckOut = [];
$mapProbeCheckRc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mapProbe . '/tools/map-sync.php')
    . ' --check 2>&1', $mapProbeCheckOut, $mapProbeCheckRc);
check('map-sync рекурсивно различает два одинаковых basename по полному пути',
    $mapProbeRc === 0 && $mapProbeCheckRc === 0
    && strpos($mapProbeText, '`www/api/a/report.php`') !== false
    && strpos($mapProbeText, '`www/api/b/report.php`') !== false,
    'build=' . $mapProbeRc . ', check=' . $mapProbeCheckRc . ', ' . implode(' ', $mapProbeOut));
testWipe($mapProbe, true);

// Inline-подсказки — тоже часть контракта агента: неверный файл в комментарии
// отправляет следующую правку мимо готового блока, хотя каталог остаётся зелёным.
$templateSource = (string)@file_get_contents($root . '/www/template.html');
$componentsSource = (string)@file_get_contents($root . '/www/css/components.css');
check('inline-подсказки называют frame.js и controls.js, а не прежнюю архитектуру',
    strpos($templateSource, 'Прокручивается .content') === false
    && strpos($templateSource, 'frame.js') !== false
    && strpos($componentsSource, 'controls.js') !== false
    && strpos($componentsSource, 'ui.pickPeople собирает www/js/ui.js') === false);

// Motion — функциональная обратная связь, а не необязательная «красивость».
// RED 28.08.2026: прежний стенд смотрел шесть core-селекторов и не замечал
// project style, 300 мс, мгновенное закрытие overlay и псевдоэлементы при reduce.
$tokensSource = (string)@file_get_contents($root . '/www/css/tokens.css');
$uiSource = (string)@file_get_contents($root . '/www/js/ui.js');
$motionTokensOk = preg_match('~--motion-fast\s*:\s*120ms\s*;~', $tokensSource) === 1
    && preg_match('~--motion-base\s*:\s*150ms\s*;~', $tokensSource) === 1
    && preg_match('~--motion-slow\s*:\s*200ms\s*;~', $tokensSource) === 1;
check('motion-токены шаблона exact 120/150/200 мс', $motionTokensOk);

preg_match_all('~\btransition(?:-duration)?\s*:\s*([^;}]+)~i', $componentsSource,
    $motionTransitionDecls);
$motionRawTransitions = array_values(array_filter($motionTransitionDecls[1] ?? [],
    static fn(string $decl): bool => stripos($decl, 'none') === false
        && strpos($decl, 'var(--motion-') === false));
check('все core transitions берут длительность из motion-токенов', $motionRawTransitions === [],
    implode(' · ', array_slice($motionRawTransitions, 0, 5)));

$motionCalcArithmetic = [];
foreach (gateFilesUnder($root . '/www', 'css') as $motionCssFile) {
    $motionCalcLines = gateMotionCssArithmeticLines($motionCssFile);
    if ($motionCalcLines !== []) $motionCalcArithmetic[] = $lines($motionCssFile, $motionCalcLines);
}
check('во всех project CSS motion-токены используются без calc-арифметики',
    $motionCalcArithmetic === [],
    $motionCalcArithmetic === [] ? ''
        : 'motion token изменён через calc: ' . implode(', ', $motionCalcArithmetic));

$fullMotionReduce = preg_match(
    '~@media\s*\(prefers-reduced-motion\s*:\s*reduce\)[\s\S]*?\*\s*,\s*\*::before\s*,\s*\*::after\s*\{[^}]*animation\s*:\s*none[^}]*transition\s*:\s*none~i',
    $componentsSource) === 1;
check('prefers-reduced-motion выключает motion элементов и обоих pseudo', $fullMotionReduce);

check('toast и modal имеют достижимые enter+exit до удаления DOM',
    strpos($componentsSource, '@keyframes toast-out') !== false
    && strpos($componentsSource, '@keyframes modal-out') !== false
    && strpos($componentsSource, '.is-leaving') !== false
    && strpos($uiSource, 'motionRemove') !== false
    && preg_match('~motionRemove\s*\([^;]+is-leaving~s', $uiSource) === 1);

foreach (['CLAUDE.md', 'AGENTS.md'] as $motionDoc) {
    $motionText = (string)@file_get_contents($root . '/' . $motionDoc);
    check("$motionDoc закрепляет обязательный purposeful motion и reduced-motion",
        preg_match('~анимац[а-я]*[\s\S]{0,160}обязател|обязател[\s\S]{0,160}анимац~iu', $motionText) === 1
        && preg_match('~120\s*[–—-]\s*200\s*мс~u', $motionText) === 1
        && strpos($motionText, 'prefers-reduced-motion') !== false
        && preg_match('~декоратив[\s\S]{0,100}бесконеч~iu', $motionText) === 1);
}

// 8.1. Каталог готовых блоков не врёт. Правило «прежде чем писать своё — открой
//      docs/BLOCKS.md» держится на одном условии: имя из каталога обязано
//      находиться в коде. Стоит одному имени оказаться выдуманным — и каталог
//      начинает вредить: разработчик грепает его, не находит и пишет своё.
//      Ревью 06.08.2026 нашло два таких имени (`moneyForHuman`, `exportCsv`)
//      при 807 зелёных проверках — то есть ловилось это только чтением глазами.
$blocks = is_file($root . '/docs/BLOCKS.md')
    ? (string)file_get_contents($root . '/docs/BLOCKS.md') : '';
// `scripts/` входит сюда наравне с `www/`: медиа-воркер живёт вне веб-корня
// намеренно (там запрещены внешние процессы и свой HTTP), но его помощники —
// такое же готовое, которое следующий агент напишет заново, не найдя грепом.
// Пока каталог сверялся только с `www/`, у него было два одинаково плохих
// исхода: назвать функции воркера и покраснеть или промолчать о них и оставить
// каталог неполным.
$catalogSources = array_values(array_merge(
    gateFilesUnder($root . '/www', 'php'),
    gateFilesUnder($root . '/www', 'js'),
    is_dir($root . '/scripts') ? gateFilesUnder($root . '/scripts', 'php') : []
));
$missingSymbols = $blocks === '' ? [] : gateCatalogMissingSymbols($blocks, $catalogSources);
check('каждое имя из каталога блоков docs/BLOCKS.md есть в коде', $blocks !== '' && $missingSymbols === [],
    $blocks === '' ? 'нет docs/BLOCKS.md' : ($missingSymbols === [] ? ''
        : 'названо в каталоге, но не объявлено: ' . implode(', ', $missingSymbols)));

// `tools/extras` входит сюда по той же причине, что `scripts/` выше: там лежат
// наборы классов, которые НЕ подключены во фронте шаблона и приезжают в проект
// только по команде (`bxapp-extra.sh`). В `www/css` им нельзя — забор кэш-бастера
// требует, чтобы каждый файл оттуда был подключён на странице, и правильно
// требует. Пока каталог сверялся только с `www/css`, выбор был из двух плохих:
// назвать классы набора и покраснеть или промолчать — и тогда следующий агент
// напишет свой аккордеон, не найдя готовый грепом.
$missingClasses = $blocks === '' ? [] : gateCatalogMissingClasses($blocks, array_merge(
    gateFilesUnder($root . '/www/css', 'css'),
    is_dir($root . '/tools/extras') ? gateFilesUnder($root . '/tools/extras', 'css') : []
));
check('каждый класс оформления из каталога блоков есть в css', $missingClasses === [],
    $missingClasses === [] ? '' : 'нет в css: ' . implode(', ', $missingClasses));

// 8.2. «Готового не хватило — свой файл проекта, а не правка ядра». Живой прогон
//      09.08.2026: агент делал фичу в свежем проекте, помощника не нашёл и дважды
//      пытался дописать свою функцию в ЯДРО (`www/api/fmt.php`). Остановило его
//      только подтверждение из `.claude/settings.json` — то есть в интерактиве
//      правка бы прошла, файл навсегда стал бы «правили под себя», и починки
//      шаблона в проект больше не доехали бы. Молча. Написанного правила об этом
//      не было ни в одном документе проекта — агент вёл себя разумно.
foreach (['CLAUDE.md', 'AGENTS.md'] as $dokument) {
    $tekst = is_file($root . '/' . $dokument) ? (string)@file_get_contents($root . '/' . $dokument) : '';
    $skazano = $tekst !== ''
        && mb_strpos($tekst, 'ядро') !== false
        && (mb_strpos($tekst, 'свой файл проекта') !== false || mb_strpos($tekst, 'свой файл') !== false)
        && mb_strpos($tekst, 'правили под себя') !== false;
    check("$dokument говорит, что своё пишется отдельным файлом, а ядро не правится", $skazano,
        $tekst === '' ? "нет $dokument" : 'нет правила про ядро и свой файл: агент допишет своё в ядро и разойдётся с шаблоном');
}

foreach (['CLAUDE.md', 'AGENTS.md'] as $dokument) {
    $tekst = (string)@file_get_contents($root . '/' . $dokument);
    check("$dokument различает красный прогон и незавершённый runner",
        preg_match('~Провалено N\s*(?:из M|\n\s*из M).*?код 1~su', $tekst) === 1
        && preg_match('~код 86.*?(?:оборвался|обрыв).*?verdict|(?:оборвался|обрыв).*?verdict.*?код 86~su', $tekst) === 1
        && strpos($tekst, '86, а не 1') === false);
}

// Обратная сторона правила: готовый модуль, не названный в каталоге, всё равно
// что не существует — его напишут заново. Именно так экран настроек и долгая
// задача чуть не остались «внутренней кухней», о которой знает только автор.
$modulesMissing = $blocks === '' ? [] : gateModulesMissingFromCatalog($blocks, $apiFiles);
check('каждый модуль api/ представлен в каталоге блоков', $modulesMissing === [],
    $modulesMissing === [] ? '' : 'нет в каталоге: ' . implode(', ', $modulesMissing));

// 8.2. У каждого файла состояния есть ответ на вопрос «а что при удалении
//      приложения и при переносе на другой портал». Ревью 06.08.2026 показало
//      цену пропуска: долгая задача пережила удаление, и её курсор достался
//      следующей установке — фоновый рабочий продолжил чужую работу на данных
//      другого портала. Объявить файл в env.php и написать чтение-запись
//      недостаточно: третий шаг забывается молча, потому что списки уборки
//      живут в другом файле и ничем не связаны с объявлением.
//
//      Свой протокол — это не поблажка, а другая процедура, и каждая названа
//      причиной. Дописать сюда константу «чтобы прошло» = соврать вслух.
$stateOwnProtocol = [
    // Токены отрезаются двухфазно, с CAS по поколению: очистить их «вместе со
    // всеми» значит потерять признак незавершённой уборки.
    'B24_TOKENS_FILE',
    // Очереди чистятся последними и отдельно: пока обычное состояние не
    // очищено, событие удаления не подтверждается и повторится.
    'INBOX_FILE', 'DEADLETTER_FILE',
    // Секреты не принадлежат установке: они задаются один раз при заведении
    // приложения и переживают переустановку намеренно.
    'B24_CREDS_FILE',
];
// Собираем объявления из ВСЕГО приложения: файл состояния может объявить себя
// внутри своего модуля, и именно так справочник CRM обошёл первую версию этой
// проверки. Теперь такой файл обязан либо попасть в уборку, либо быть назван
// своим протоколом — где бы он ни объявлялся.
$declarationSources = '';
foreach (array_merge($appPhp, [$root . '/www/env.php']) as $f) {
    $declarationSources .= (string)@file_get_contents($f) . "\n";
}
$envSource = (string)@file_get_contents($root . '/www/env.php');
$libSource = (string)@file_get_contents($root . '/www/api/lib.php');
$stateOrphans = gateStateFilesWithoutCleanup($declarationSources, $libSource, $stateOwnProtocol);
check('у каждого файла состояния есть план на удаление приложения и перенос портала',
    $envSource !== '' && $libSource !== '' && $stateOrphans === [],
    $stateOrphans === [] ? '' : 'нет в уборке и не назван своим протоколом: '
        . implode(', ', $stateOrphans));

// 8.3. Формат портального POST разбирает ОДИН файл. Облако и коробка присылают
//      разные наборы полей, и пока про коробочный SERVER_ENDPOINT знал забор
//      сессии, а установка искала домен в DOMAIN и Referer, приложение
//      сохраняло одно имя портала, а сверяло по другому — и после исправной
//      установки навсегда отвечало «открывается только из портала». Второе
//      место чтения этих полей — это будущее расхождение, а не удобство.
$postFieldReaders = gatePortalPostFieldReaders(
    array_merge($appPhp, [$root . '/www/index.php']), 'api/b24.php');
check('поля портального POST разбираются только в api/b24.php', $postFieldReaders === [],
    $postFieldReaders === [] ? '' : 'читает сам, мимо общих помощников: '
        . implode(', ', $postFieldReaders));

// 9. Фронт обязан быть исполнимым. Разметка со <script> ничего не доказывает:
//    сломанный синтаксис даёт пустой экран, а все прежние проверки — зелёные.
//    Нет node — это НЕ повод считать фронт проверенным (см. deploy-prod.sh:
//    там отсутствие обязательного инструмента останавливает выкладку).
$nodeBin = trim((string)@shell_exec('command -v node 2>/dev/null'));
$jsFiles = array_values(array_merge(
    gateFilesUnder($root . '/www', 'js'),
    gateFilesUnder($root . '/www', 'mjs'),
    gateFilesUnder($root . '/www', 'cjs')
));
if ($nodeBin !== '') {
    $badJs = [];
    foreach ($jsFiles as $f) {
        $out = null; $rc = 0;
        @exec(escapeshellarg($nodeBin) . ' --check ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        if ($rc !== 0) $badJs[] = $rel($f);
    }
    check('файлы фронта синтаксически исполнимы (node --check)', $badJs === [],
        $badJs === [] ? '' : 'сломанный JS: ' . implode(', ', $badJs));
} else {
    check('файлы фронта синтаксически исполнимы (node --check)', $jsFiles === [],
        'НЕТ node — проверить фронт нечем; поставьте node или запускайте самопроверку там, где он есть');
}

// 10. Модуль совместимости обязан работать на движке, ради которого написан.
//     Он кладётся в ЧУЖОЙ проект на PHP 7.4 поверх его старого клиента —
//     единственная функция из PHP 8.1 (array_is_list) роняла там всё приложение
//     фаталом на первой же странице выборки. Здесь движок 8.x, и глазами это не
//     видно: код выглядит и работает правильно. Файл назван прямо — правило
//     касается именно его, остальному шаблону 8.x разрешён.
$compat = $root . '/www/api/fetch-all-compat.php';
if (is_file($compat)) {
    $tooNew = gateNewerThanPhp74((string)file_get_contents($compat));
    check('модуль совместимости не требует движка новее PHP 7.4', $tooNew === [],
        $tooNew === [] ? '' : 'на 7.4 даст фатал: ' . implode(', ', $tooNew));
}

// ВЕСЬ боевой код, а не один модуль. Раньше проверялся только донорский файл —
// и первая же новая строка с функцией из PHP 8 проехала мимо (`str_contains` в
// очереди уведомлений). Заявленный минимум в README и CLAUDE.md — 7.4; пока он
// там написан, приложение обязано на нём запускаться, иначе на хостинге со
// старым движком оно падает фаталом ровно на ветке разбора ошибок — то есть
// тогда, когда и так что-то пошло не так.
$tooNewProd = [];
// Рекурсивно, как гейт размещения (ревью 23.09.2026, dubli-6): плоский glob не
// видел www/api/<подпапка>/*.php, и str_contains() там падал фаталом на PHP 7.4.
foreach (array_merge(gateFilesUnder($root . '/www/api', 'php'), gateFilesUnder($root . '/www/bin', 'php'),
         [$root . '/www/index.php', $root . '/www/env.php']) as $prodFile) {
    if (!is_file($prodFile)) continue;
    $hits = gateNewerThanPhp74((string)file_get_contents($prodFile));
    if ($hits) $tooNewProd[] = basename($prodFile) . ': ' . implode(', ', array_unique($hits));
}
// Добавил обязательную настройку — увеличь версию контракта конфига. Иначе
// выкладка на боевой сервер со старым env.php останавливается сообщением «в
// source remote env.php ТОЙ ЖЕ schema нет обязательных define», и человек ищет
// поломку конфига вместо того, чтобы просто обновить его осознанно.
$contractFile = $root . '/tests/env-contract.txt';
$historyFile  = $root . '/tests/env-contract-history.txt';
// Оба файла ОБЯЗАТЕЛЬНЫ. Пока проверка стояла под «если файл есть», её можно
// было выключить, удалив файл: набор настроек менялся молча, а выкладка на
// боевой сервер вставала уже там — с текстом про отсутствующие define, из
// которого не следует, что делать.
check('эталон обязательных настроек на месте', is_file($contractFile) && filesize($contractFile) > 0,
    $contractFile);
check('ИСТОРИЯ НАБОРА НАСТРОЕК НА МЕСТЕ', is_file($historyFile) && filesize($historyFile) > 0,
    $historyFile);
if (is_file($contractFile) && is_file($historyFile)) {
    $recorded = [];
    foreach (explode("\n", (string)file_get_contents($contractFile)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $recorded[] = $line;
    }
    $nowNames = [];
    exec('php ' . escapeshellarg($root . '/tools/env-define-names.php')
        . ' ' . escapeshellarg($root . '/www/env.php'), $nowNames);
    $nowNames = array_values(array_filter(array_map('trim', $nowNames)));
    sort($recorded);
    sort($nowNames);
    preg_match("/define\(\s*'BXAPP_ENV_SCHEMA'\s*,\s*(\d+)\s*\)/",
        (string)file_get_contents($root . '/www/env.php'), $sm);
    $nowSchema = (int)($sm[1] ?? 0);
    $nowSum = hash('sha256', implode("\n", $nowNames) . "\n");

    // История — цепочка: каждая запись помнит отпечаток предыдущей строки.
    // Поправить давнюю запись, не пересчитав все следующие, нельзя, а массовый
    // пересчёт цепочки — уже не «заодно с кодом», а отдельное осознанное дело.
    $records = [];
    $chainOk = true;
    $prevRaw = '';
    foreach (explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($historyFile))) as $line) {
        if (trim($line) === '' || $line[0] === '#') continue;
        $parts = explode("\t", $line);
        if (count($parts) !== 3) { $chainOk = false; break; }
        $wantPrev = $prevRaw === '' ? '-' : hash('sha256', $prevRaw);
        if ($parts[2] !== $wantPrev) { $chainOk = false; break; }
        $records[] = ['schema' => (int)$parts[0], 'sum' => $parts[1]];
        $prevRaw = $line;
    }
    check('ЦЕПОЧКА ИСТОРИИ НАСТРОЕК ЦЕЛА', $chainOk && $records !== [],
        'записей: ' . count($records));

    $schemasGrow = true;
    for ($i = 1; $i < count($records); $i++) {
        if ($records[$i]['schema'] <= $records[$i - 1]['schema']) $schemasGrow = false;
    }
    check('версии конфига в истории только растут', $schemasGrow,
        implode(' → ', array_column($records, 'schema')));

    $lastRecord = $records === [] ? null : $records[count($records) - 1];
    check('эталон настроек совпадает с тем, что реально в env.php', $recorded === $nowNames,
        'разошлись: ' . implode(', ', array_merge(array_diff($nowNames, $recorded),
            array_diff($recorded, $nowNames))));
    // Главное. Набор изменился — обязана вырасти версия конфига, и новая строка
    // истории обязана появиться. Согласованная правка кода и эталона больше не
    // проходит: сумма в истории считается от САМОГО env.php, а не от эталона.
    check('НАБОР НАСТРОЕК НЕ ИЗМЕНИЛСЯ БЕЗ РОСТА ВЕРСИИ КОНФИГА',
        $lastRecord !== null && $lastRecord['schema'] === $nowSchema && $lastRecord['sum'] === $nowSum,
        $lastRecord === null ? 'история пуста'
            : ('в истории версия ' . $lastRecord['schema'] . ', в env.php ' . $nowSchema
               . ($lastRecord['sum'] === $nowSum ? '' : '; набор настроек отличается от записанного')
               . ' — поднимите BXAPP_ENV_SCHEMA и выполните php tools/env-contract-bump.php'));
}

check('боевой код запускается на заявленном минимуме PHP 7.4', $tooNewProd === [],
    $tooNewProd === [] ? '' : 'на 7.4 даст фатал — ' . implode(' · ', $tooNewProd));

// Рабочий обязан спросить разрешение ДО порции. Проверка текстовая намеренно:
// поведение уже закрыто испытаниями состояния задачи, а здесь стережём саму
// связку — чтобы правка в кроне не вернула порядок «сделали, потом спросили».
$cronSrc = (string)file_get_contents($root . '/www/bin/process.php');
$runner = '';
if (preg_match('/function\s+runPendingTask\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n\}/s', $cronSrc, $rm)) {
    $runner = $rm[1];
}
$claimAt = strpos($runner, 'taskClaimStep(');
$stepAt  = strpos($runner, 'taskStep(');
check('порция долгой задачи выполняется только после разрешения',
    $runner !== '' && $claimAt !== false && $stepAt !== false && $claimAt < $stepAt,
    $runner === '' ? 'не нашёл тело runPendingTask()' :
        'разрешение на позиции ' . var_export($claimAt, true) . ', работа на ' . var_export($stepAt, true));
check('завершение задачи передаёт итоговые цифры, а не оставляет 0 из 0',
    strpos($runner, "taskFinish(\$id, 'done'") !== false && strpos($runner, "'total' =>") !== false,
    'в runPendingTask() нет счётчиков при завершении');

// Пример в доке, который закрывает эндпоинт, хуже отсутствия примера: его
// копируют дословно. Ролей ровно три — проверяем ВСЕ упоминания требуемой роли
// в документации и в боевом коде, а не одну известную строку.
$roleBad = [];
foreach (array_merge(glob($root . '/docs/*.md') ?: [], glob($root . '/*.md') ?: [],
                     glob($root . '/www/api/*.php') ?: []) as $doc) {
    $name = basename($doc);
    // Разборы ревью и этот чеклист сами цитируют неверный пример как находку.
    if (strpos($name, 'review-') === 0 || strpos($name, 'CHEKLIST') === 0) continue;
    foreach (gateRequiredRoleNames($doc) as $r) {
        if (!in_array($r, ['view', 'edit', 'admin'], true)) $roleBad[] = $name . ': ' . $r;
    }
}
check('нигде не предлагается роль, которой у приложения нет', $roleBad === [],
    implode(' · ', $roleBad));

// «Готово» и «иди пиши сам» не могут относиться к одной и той же задаче. Рецепты
// пишутся раньше блоков и устаревают молча: человек читает «донор такой-то»,
// уходит копировать чужой код и заводит второй транспорт рядом с готовым.
$recipes = $root . '/docs/RECIPES.md';
$recipeConflicts = [];
if (is_file($recipes)) {
    // Слова тем узкие намеренно. «Событие» живёт и в «очереди событий», и в
    // «приёме события»: по нему проверка ругалась бы на честный рецепт про
    // очередь. Подписки узнаются по «подписк» и «привязк».
    $temy = [
        ['слова' => ['подписк', 'привязк событ'], 'файл' => 'www/api/events-bind.php', 'блок' => 'events-bind.php'],
        ['слова' => ['очеред'],            'файл' => 'www/api/queue.php',       'блок' => 'queue.php'],
        ['слова' => ['воронк', 'стади', 'справочник'], 'файл' => 'www/api/crm-dict.php', 'блок' => 'crm-dict.php'],
        ['слова' => ['уведомлен', 'алерт'], 'файл' => 'www/api/notify.php',     'блок' => 'notify.php'],
    ];
    // По КУСКАМ, а не по строкам: перенос строки между темой и донором больше не
    // разводит их по разным записям и не прячет противоречие.
    $temy = array_values(array_filter($temy, static fn(array $t): bool => is_file($root . '/' . $t['файл'])));
    $recipeConflicts = gateRecipeConflicts((string)file_get_contents($recipes), $temy);
}
check('рецепты не шлют к чужому коду там, где в шаблоне уже есть готовое',
    $recipeConflicts === [], implode(' · ', $recipeConflicts));

// ── ЯДРО НЕ МЕСТО ДЛЯ ПРИКЛАДНОГО КОДА ───────────────────────────────────────
// Файлы ядра исключены из проектных проверок: они и есть та механика, которую
// проверки требуют использовать. Обратная сторона — «дописать бизнес-логику в
// файл ядра» становится самым коротким способом обойти гейт целиком, причём
// незаметно: файл выглядит служебным. Поэтому у каждого файла ядра есть предел
// того, с какими семействами методов портала ему положено работать, и запрет на
// предметные поля CRM. Смотрим РЕАЛЬНЫЕ вызовы портала, а не любые строки:
// имя события в журнале — не обращение к порталу.
$CORE_CALL_SCOPE = [
    'www/api/b24.php'              => ['*'],   // транспорт: метод выбирает вызывающий
    'www/api/fetch-all-compat.php' => ['*'],   // обход списков поверх транспорта
    'www/api/crm-dict.php'         => ['crm.'],
    'www/api/uf.php'               => ['crm.'],
    'www/api/notify.php'           => ['im.'],
    'www/api/event.php'            => ['event.'],
    'www/api/events-bind.php'      => ['event.'],
    'www/api/placements.php'       => ['placement.'],
    'www/api/session.php'          => ['user.', 'profile', 'app.'],
    'www/api/access.php'           => ['user.', 'profile', 'app.'],
    'www/api/acl.php'              => ['user.'],
    // Тариф портала. Один метод и только он: `app.info` отдаёт LICENSE, по
    // которому считается предел темпа. Ничего прикладного модулю не нужно.
    'www/api/plan.php'             => ['app.info'],
    // Доступность методов и выданных scopes. Оба точечных метода читающие:
    // `method.get` отвечает, есть ли метод и доступен ли он, `scope` возвращает
    // конечный список прав приложения без пагинации. Ничего прикладного модулю
    // не нужно, и зваться прикладные методы отсюда не должны — только проверяться.
    'www/api/capability.php'       => ['method.get', 'scope'],
    'www/api/lib.php'              => ['app.', 'event.', 'user.'],
    // Часовой пояс портала — законная нужда форматирования дат: без него
    // «сегодня» считается по времени сервера, а не по времени компании.
    'www/api/fmt.php'              => ['profile'],
];
$coreOutOfScope = [];
foreach ($CORE_API as $relCore) {
    $file = $root . '/' . $relCore;
    if (!is_file($file)) continue;
    $allowed = $CORE_CALL_SCOPE[$relCore] ?? [];
    if ($allowed === ['*']) continue;
    foreach (gatePortalCallDetails($file) as $callInfo) {
        $method = trim((string)($callInfo['method'] ?? ''), "'\"");
        if ($method === '' || !gateLooksLikePortalMethod($method)) continue;
        $ok = gatePortalMethodAllowedByScope($method, $allowed);
        if (!$ok) $coreOutOfScope[] = $relCore . ' → ' . $method;
    }
}
$coreOutOfScope = array_values(array_unique($coreOutOfScope));
check('файлы ядра не зовут портал за пределами своего назначения',
    $coreOutOfScope === [],
    $coreOutOfScope === [] ? '' : 'прикладной вызов в ядре: ' . implode(' · ', $coreOutOfScope)
        . ' — такому коду место в www/api проекта, где работают все проверки');

// ── СТРОГИЙ ГЕЙТ ЯДРА: доверие ФАЙЛУ, а не имени «ядро» ─────────────────────
//
// Находка ревью 08.08.2026 (BXREV-008). Ядро было исключено из семантических
// проверок целиком: контрпример с достижимой незащищённой мутацией внутри
// core-файла проходил гейт зелёным. «Текущий код такого не содержит» — не
// защита, а совпадение: следующая правка ядра пройдёт так же молча.
//
// Совсем без исключений нельзя: ядро САМО реализует те примитивы, которых
// требует от прикладного кода — `store.php` и есть запись состояния под замком,
// `b24.php` и есть клиент портала, `journal.php` и есть журнал. Замер 09.08.2026
// без исключений: 29 срабатываний в 13 файлах, почти все — реализация примитива.
//
// Поэтому доверие адресное, у каждой строки есть причина, а не «это ядро»:
//   1. Реализация примитива разрешена ПОИМЁННО (файл + вид проверки + причина).
//      Новый файл ядра или знакомый файл с новым нарушением — гейт краснеет.
//   2. Вызовы портала сверяются по МЕТКЕ (имя метода), а не по номеру строки:
//      правка ядра метки не меняет, а новый вызов `crm.item.add` без защиты —
//      меняет, и это видно.
//   3. Мёртвых ветвлений ядру не позволено вовсе — исключений нет.
$CORE_PRIMITIVE_OWNERS = [
    // вид проверки => файл => зачем ему это позволено
    'прямая запись состояния' => [
        'www/api/store.php'      => 'этот файл И ЕСТЬ стор: замок, временный файл, rename и права 0600 написаны здесь',
        'www/api/lib.php'        => 'уборка состояния при удалении приложения и карантин повреждённых файлов — операции над файлами по определению',
        'www/api/access.php'     => 'журнал операций runOnce со своей арендой ключа',
        'www/api/journal.php'    => 'этот файл И ЕСТЬ журнал: ротация и архивы пишутся здесь',
        'www/api/export-csv.php' => 'потоковая выгрузка во временный файл: держать её в памяти означало бы OOM на большой выборке',
        'www/api/cache.php'      => 'память с временем жизни, реализованная поверх стора',
    ],
    'самодельный журнал' => [
        'www/api/journal.php' => 'этот файл И ЕСТЬ журнал приложения',
        'www/api/lib.php'     => 'аварийный журнал ошибок на случай, когда сам journal.php недоступен',
    ],
    'свой HTTP-клиент' => [
        'www/api/b24.php'     => 'этот файл И ЕСТЬ клиент портала',
        'www/api/lib.php'     => 'проверка доступности своего же адреса и работа с файлами релиза',
        'www/api/store.php'   => 'файловые операции стора',
        'www/api/journal.php' => 'файловые операции журнала',
    ],
    'generic httpJson к порталу' => [
        'www/api/lib.php' => 'самопроверка приложения ходит по своему адресу, а не в портал',
    ],
    'непрямой вызов' => [
        'www/api/store.php'            => 'мутатор состояния приходит от вызывающего — это и есть контракт storeUpdate',
        'www/api/lib.php'              => 'обёртки принимают работу коллбэком (withKeyLock, appWith…Lock)',
        'www/api/b24.php'              => 'подменяемый транспорт и постраничный обход отдают порцию коллбэком',
        'www/api/session.php'          => 'проверка личности зовёт переданный опознаватель',
        'www/api/access.php'           => 'runOnce принимает работу коллбэком',
        'www/api/placements.php'       => 'привязка мест размещения принимает описание из проекта',
        'www/api/fmt.php'              => 'форматирование по переданному правилу',
        'www/api/fetch-all-compat.php' => 'мост к старому способу выборки принимает загрузчик',
        'www/api/cache.php'            => 'загрузчик значения приходит от вызывающего',
    ],
];
// Метки вызовов портала, которые ядру позволены. Ключ — метод (или dynamic-*),
// значение — причина. Номера строк намеренно отброшены: гейт обязан ловить НОВЫЙ
// вызов, а не переезд старого на десять строк ниже.
$CORE_PORTAL_MARKS = [
    'мутация без защиты' => [
        'www/api/b24.php'        => ['dynamic-method' => 'имя метода приходит от вызывающего — это сам клиент портала'],
        'www/api/placements.php' => ['dynamic-method' => 'код места размещения задаёт проект в env.php'],
        'www/api/events-bind.php'=> ['event.bind' => 'подписка на события: повтор безопасен, портал отвечает тем же результатом, а отпечаток набора хранится в состоянии'],
        'www/api/capability.php' => ['dynamic-method' => 'опрос доступности методов и прав портала: оба вызова читающие '
            . '(`method.get`/`scope`), состояние портала не меняют'],
        'www/api/crm-dict.php'   => ['dynamic-method' => 'пакет справочника (ревью 23.09.2026, skorost-3): в нём только '
            . 'читающие crm.category.list / crm.item.fields / crm.status.list, состояние портала не меняется'],
    ],
    'список без пагинации' => [
        'www/api/b24.php'        => [
            '<dynamic>' => 'постраничный обход реализован здесь же',
            'user.get'  => 'отделы ОДНОГО уже опознанного сотрудника по точному номеру: '
                . 'ответ — одна запись, страниц у него не бывает. Нужен там, где портал '
                . 'урезал права приложению и назвал человека без списка его отделов',
        ],
        'www/api/placements.php' => ['<dynamic>' => 'список мест размещения приложения: их единицы, и портал отдаёт их одним ответом'],
        'www/api/crm-dict.php'   => [
            'crm.category.list' => 'воронок в портале десятки, метод страниц не имеет',
            '<dynamic>' => 'пакет первых страниц справочника (skorost-3): полная страница или total больше '
                . 'пришедшего — хвост добирает crmDictStatusRows()/постраничный цикл воронок',
        ],
        'www/api/capability.php' => ['<dynamic>' => 'список выданных приложению прав: портал отдаёт его одним ответом, страниц у него нет'],
    ],
];
$corePrimitiveChecks = [
    'прямая запись состояния'   => static fn(string $f): array => gateDirectStateWrites($f),
    'самодельный журнал'        => static fn(string $f): array => gateRawLogWriteLines($f),
    'свой HTTP-клиент'          => static fn(string $f): array => gateOwnHttpLines($f),
    'generic httpJson к порталу'=> static fn(string $f): array => gateGenericPortalHttpLines($f),
    'непрямой вызов'            => static fn(string $f): array => gateIndirectDispatchLines($f),
];
$coreNarushenia = [];
$coreLishnie = [];      // разрешение выдано, а нарушения давно нет — мусор в реестре
foreach ($corePrimitiveChecks as $vid => $fn) {
    $vladeltsy = $CORE_PRIMITIVE_OWNERS[$vid] ?? [];
    foreach ($CORE_API as $relCore) {
        $file = $root . '/' . $relCore;
        if (!is_file($file)) continue;
        $hits = $fn($file);
        if ($hits !== [] && !isset($vladeltsy[$relCore])) {
            $coreNarushenia[] = $relCore . ' → ' . $vid . ' (строки: '
                . implode(',', array_slice(array_map('strval', $hits), 0, 5)) . ')';
        }
    }
    foreach (array_keys($vladeltsy) as $relOwner) {
        $file = $root . '/' . $relOwner;
        if (!is_file($file)) { $coreLishnie[] = $relOwner . ' → ' . $vid . ' (файла нет)'; continue; }
        if ($fn($file) === []) $coreLishnie[] = $relOwner . ' → ' . $vid;
    }
}
check('ЯДРО ПРОХОДИТ ТЕ ЖЕ ПРОВЕРКИ, ЧТО И ПРИКЛАДНОЙ КОД — КРОМЕ ИМЕНОВАННЫХ ИСКЛЮЧЕНИЙ',
    $coreNarushenia === [],
    $coreNarushenia === [] ? '' : 'в ядре нарушение без разрешения: ' . implode(' · ', $coreNarushenia)
        . ' — либо чинить, либо вписать файл в $CORE_PRIMITIVE_OWNERS с причиной');
check('в реестре разрешений ядра нет мусора: каждое кому-то ещё нужно',
    $coreLishnie === [],
    $coreLishnie === [] ? '' : 'разрешение выдано, а нарушения нет: ' . implode(' · ', $coreLishnie));

// Вызовы портала — по меткам. Правка ядра метки не меняет; новый вызов меняет.
$corePortalChecks = [
    'мутация без защиты' => static fn(string $f): array => gateUnprotectedMutations($f),
    'список без пагинации' => static fn(string $f): array => gateUnpagedLists($f),
];
$coreNovyeVyzovy = [];
foreach ($corePortalChecks as $vid => $fn) {
    foreach ($CORE_API as $relCore) {
        $file = $root . '/' . $relCore;
        if (!is_file($file)) continue;
        $razresheno = $CORE_PORTAL_MARKS[$vid][$relCore] ?? [];
        foreach ($fn($file) as $hit) {
            // Метка — всё до последнего двоеточия: «dynamic-method:726» → «dynamic-method».
            $metka = (string)$hit;
            $pos = strrpos($metka, ':');
            if ($pos !== false && ctype_digit(substr($metka, $pos + 1))) $metka = substr($metka, 0, $pos);
            if (!array_key_exists($metka, $razresheno)) {
                $coreNovyeVyzovy[] = $relCore . ' → ' . $vid . ': ' . $metka;
            }
        }
    }
}
$coreNovyeVyzovy = array_values(array_unique($coreNovyeVyzovy));
check('НОВЫЙ ВЫЗОВ ПОРТАЛА В ЯДРЕ НЕ ПРОХОДИТ МОЛЧА',
    $coreNovyeVyzovy === [],
    $coreNovyeVyzovy === [] ? '' : 'вызов без разрешения: ' . implode(' · ', $coreNovyeVyzovy)
        . ' — защитить (runOnce/read-back/пагинация) либо вписать метку с причиной в $CORE_PORTAL_MARKS');

// Мёртвая ветка в ядре — без исключений вовсе: «if (false)» это способ
// выполнить требование, не выполняя его, и в ядре он стоит дороже всего.
$coreDeadBranch = [];
foreach ($CORE_API as $relCore) {
    $file = $root . '/' . $relCore;
    if (is_file($file) && gateHasDeadBranch($file)) $coreDeadBranch[] = $relCore;
}
check('в ядре нет заведомо ложных ветвлений', $coreDeadBranch === [],
    $coreDeadBranch === [] ? '' : 'мёртвая ветка в ядре: ' . implode(', ', $coreDeadBranch));

// ОТРИЦАТЕЛЬНЫЕ ОБРАЗЦЫ. Проверка, которая не краснеет на контрпримере, — это
// не проверка: ровно так ядро и оказалось вне гейта на полгода.
// Каждому образцу — СВОЙ файл: разбор кода кэшируется по пути, и три перезаписи
// одного имени проверяли бы одно и то же первое содержимое (поймано прогоном:
// два образца из трёх «не ловились», хотя проверки исправны).
$probaMut = DATA_ROOT . '/core-proba-mutation.php';
file_put_contents($probaMut, "<?php\ndeclare(strict_types=1);\nfunction probaMut(): void {\n    b24()->call('crm.item.add', ['entityTypeId' => 2, 'fields' => ['TITLE' => 'x']]);\n}\n");
$probaHits = gateUnprotectedMutations($probaMut);
check('образец: незащищённая мутация в файле ядра ловится',
    $probaHits !== [], 'найдено: ' . implode(',', array_map('strval', $probaHits)));
$probaDead = DATA_ROOT . '/core-proba-dead.php';
file_put_contents($probaDead, "<?php\ndeclare(strict_types=1);\nfunction probaDead(): void {\n    if (false) { return; }\n}\n");
check('образец: мёртвая ветка в файле ядра ловится', gateHasDeadBranch($probaDead));
$probaWrite = DATA_ROOT . '/core-proba-write.php';
file_put_contents($probaWrite, "<?php\ndeclare(strict_types=1);\nfunction probaWrite(): void {\n    file_put_contents(DATA_ROOT . '/своё.php', 'x');\n}\n");
check('образец: прямая запись состояния в файле ядра ловится',
    gateDirectStateWrites($probaWrite) !== []);
@unlink($probaMut); @unlink($probaDead); @unlink($probaWrite);

// Второй предел: предметные поля CRM. Их появление в служебном файле означает,
// что туда переехала бизнес-логика — вместе с ней уезжают из-под проверок и
// мутации, и даты, и деньги.
$CRM_FIELD_MARKERS = ['UF_CRM_', 'OPPORTUNITY', 'STAGE_ID', 'CURRENCY_ID',
                      'ASSIGNED_BY_ID', 'CATEGORY_ID', 'CONTACT_ID', 'COMPANY_ID'];
$coreDomainFields = [];
foreach ($CORE_API as $relCore) {
    // Модулям полей и справочников эти имена нужны по прямому назначению.
    if (in_array($relCore, ['www/api/uf.php', 'www/api/crm-dict.php'], true)) continue;
    $file = $root . '/' . $relCore;
    if (!is_file($file)) continue;
    foreach (token_get_all((string)file_get_contents($file)) as $tok) {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $literal = trim($tok[1], "'\"");
        foreach ($CRM_FIELD_MARKERS as $marker) {
            if (strpos($literal, $marker) !== false) {
                $coreDomainFields[] = $relCore . ':' . $tok[2] . ' → ' . $literal;
            }
        }
    }
}
$coreDomainFields = array_values(array_unique($coreDomainFields));
check('в файлах ядра нет предметных полей CRM', $coreDomainFields === [],
    $coreDomainFields === [] ? '' : 'бизнес-поле в ядре: ' . implode(' · ', $coreDomainFields));

// ── РЕЕСТР ГОТОВЫХ БЛОКОВ ────────────────────────────────────────────────────
// Каталоги в Markdown расходятся с кодом молча: файл переименовали, функцию
// переписали, а дока продолжает звать по-старому — и агент, честно открывший
// BLOCKS.md, пишет своё. Реестр — машинный источник, и он обязан совпадать
// с реальностью: файл существует, названная точка входа существует, а блок
// упомянут в человеческом каталоге.
$registryFile = $root . '/docs/capabilities.json';
check('машинный реестр готовых блоков есть', is_file($registryFile), $registryFile);
if (is_file($registryFile)) {
    $registry = json_decode((string)file_get_contents($registryFile), true);
    check('реестр читается как JSON', is_array($registry) && !empty($registry['capabilities']));
    $regBad = [];
    $blocksSrc = (string)@file_get_contents($root . '/docs/BLOCKS.md');
    foreach ((array)($registry['capabilities'] ?? []) as $cap) {
        $id = (string)($cap['id'] ?? '?');
        $capFile = (string)($cap['file'] ?? '');
        if ($capFile === '' || !is_file($root . '/' . $capFile)) {
            $regBad[] = $id . ': нет файла ' . $capFile;
            continue;
        }
        if (!in_array((string)($cap['status'] ?? ''), ['ready', 'experimental', 'absent'], true)) {
            $regBad[] = $id . ': непонятное состояние';
        }
        // Точки входа проверяем только у PHP: у скриптов это команды, а не функции.
        if (substr($capFile, -4) === '.php') {
            $src = (string)file_get_contents($root . '/' . $capFile);
            foreach ((array)($cap['entry'] ?? []) as $fn) {
                if (!preg_match('~function\s+' . preg_quote((string)$fn, '~') . '\s*\(~', $src)) {
                    $regBad[] = $id . ': реестр обещает ' . $fn . '(), а в ' . $capFile . ' её нет';
                }
            }
        }
        // В каталоге пути пишут коротко («api/b24.php») — сравниваем так же.
        $shortPath = preg_replace('~^www/~', '', $capFile);
        if ($blocksSrc !== '' && strpos($blocksSrc, $shortPath) === false) {
            $regBad[] = $id . ': ' . $shortPath . ' не назван в BLOCKS.md';
        }
        // Опытный блок обязан объяснить, чего ему не хватает до «готово»: иначе
        // статус превращается в вечную отговорку.
        if (($cap['status'] ?? '') === 'experimental' && trim((string)($cap['why_experimental'] ?? '')) === '') {
            $regBad[] = $id . ': помечен опытным без объяснения';
        }
    }
    check('реестр не расходится с кодом и каталогом', $regBad === [], implode(' · ', $regBad));

    // Template registry и расширения проекта — разные контракты. Иначе новый
    // report.php ошибочно превращался в готовый блок всех будущих проектов, а
    // удаление не-API capability можно было спрятать переносом checks в соседа.
    $capIds = [];
    $registryApi = [];
    foreach ((array)($registry['capabilities'] ?? []) as $cap) {
        $id = (string)($cap['id'] ?? '');
        if ($id !== '') $capIds[$id] = true;
        $f = (string)($cap['file'] ?? '');
        if (strpos($f, 'www/api/') === 0 && substr($f, -4) === '.php') $registryApi[$f] = true;
    }
    $companionBad = [];
    foreach ((array)($registry['companion_files'] ?? []) as $f => $id) {
        if (!is_file($root . '/' . $f)) $companionBad[] = 'нет ' . $f;
        if (!isset($capIds[(string)$id])) $companionBad[] = $f . ': неизвестный capability ' . $id;
        $registryApi[(string)$f] = true;
    }
    $templateApi = gateTemplateCapabilityApiFiles();
    $projectEndpoints = [];
    foreach ($endpoints as $endpoint) {
        $relative = $rel($endpoint);
        if (!in_array($relative, $templateApi, true)) $projectEndpoints[] = $relative;
    }
    $registryContractBad = gateCapabilityContractProblems($registry, $projectEndpoints, $root);
    check('реестр хранит exact 20 template capabilities, а project endpoints — отдельными extensions',
        $companionBad === [] && $registryContractBad === [],
        implode(' · ', array_merge($companionBad, $registryContractBad)));

    $mandatoryAclProblems = gateMandatoryAclProblems($root);
    check('КАЖДОЕ ПРИЛОЖЕНИЕ СОХРАНЯЕТ ОБЯЗАТЕЛЬНЫЙ ACL: BACKEND, UI, LIFECYCLE И РОЛИ',
        $mandatoryAclProblems === [], implode(' · ', $mandatoryAclProblems));

    $uiBad = [];
    foreach ((array)($registry['ready_ui'] ?? []) as $symbol => $f) {
        $src = (string)@file_get_contents($root . '/' . $f);
        $short = preg_replace('~^ui\.~', '', (string)$symbol);
        $name = preg_quote((string)$short, '~');
        $direct = preg_match('~ui\.' . $name . '\s*=~', $src) === 1;
        $exportedFunction = preg_match('~function\s+' . $name . '\s*\(~', $src) === 1
            && preg_match('~global\.ui\s*=\s*\{[\s\S]*?\b' . $name . '\s*:\s*' . $name . '\b~', $src) === 1;
        if ($src === '' || (!$direct && !$exportedFunction)) {
            $uiBad[] = $symbol . ' не объявлен в ' . $f;
        }
        if (strpos($blocksSrc, (string)$symbol) === false) $uiBad[] = $symbol . ' не назван в BLOCKS.md';
    }
    check('все ready UI-контракты есть в машинном реестре, коде и BLOCKS.md',
        count((array)($registry['ready_ui'] ?? [])) === 11 && $uiBad === [], implode(' · ', $uiBad));

    // ── СТАТУС БЛОКА ВИДЕН ТАМ, ГДЕ ЕГО ЧИТАЮТ (BXREV-019) ─────────────────
    // Реестр честно делит блоки на готовые и опытные, но читают-то каталог: там
    // рядом с инструкцией «бери и подключай» статуса не было ни слова, и опытный
    // договор принимался за окончательный (находка ревью 08.08.2026).
    $bezMetki = [];
    foreach ((array)($registry['capabilities'] ?? []) as $cap) {
        if (($cap['status'] ?? '') !== 'experimental') continue;
        $shortPath = preg_replace('~^www/~', '', (string)($cap['file'] ?? ''));
        // Заголовок раздела каталога с этим файлом обязан нести метку.
        $naydeno = false;
        foreach (explode("\n", $blocksSrc) as $stroka) {
            if (strpos($stroka, '## ') !== 0 || strpos($stroka, $shortPath) === false) continue;
            $naydeno = true;
            if (mb_strpos($stroka, 'ОПЫТНЫЙ') === false) $bezMetki[] = (string)($cap['id'] ?? '?');
        }
        if (!$naydeno) $bezMetki[] = (string)($cap['id'] ?? '?') . ' (нет раздела в каталоге)';
    }
    check('ОПЫТНЫЙ БЛОК ПОМЕЧЕН В САМОМ КАТАЛОГЕ, А НЕ ТОЛЬКО В РЕЕСТРЕ',
        $bezMetki === [], implode(' · ', array_unique($bezMetki)));
    // И обратная сторона: метка не должна висеть на готовом блоке — иначе её
    // перестанут замечать вовсе.
    $lishnyaya = [];
    foreach ((array)($registry['capabilities'] ?? []) as $cap) {
        if (($cap['status'] ?? '') !== 'ready') continue;
        $shortPath = preg_replace('~^www/~', '', (string)($cap['file'] ?? ''));
        foreach (explode("\n", $blocksSrc) as $stroka) {
            if (strpos($stroka, '## ') !== 0 || strpos($stroka, $shortPath) === false) continue;
            if (mb_strpos($stroka, 'ОПЫТНЫЙ') !== false) $lishnyaya[] = (string)($cap['id'] ?? '?');
        }
    }
    check('готовый блок не помечен опытным', $lishnyaya === [], implode(' · ', array_unique($lishnyaya)));

    // ── РЕЕСТР ДОКАЗЫВАЕТ ПОВЕДЕНИЕ, А НЕ НАЛИЧИЕ ФАЙЛА (BXREV-018) ─────────
    // Прежде сверялось: файл есть, функция объявлена, тестовый файл существует.
    // Совпадение имени файла ничего не говорит о том, проверяет ли он ЗАЯВЛЕННОЕ
    // поведение: ручная сверка ревью подтвердила связь лишь для 16 блоков из 20.
    // Теперь блок обязан назвать КОНКРЕТНЫЕ проверки, и каждая обязана
    // существовать дословно — тогда удаление поведения красит именно этот блок.
    //
    // ЗДЕСЬ ТОЛЬКО РАННИЙ СИГНАЛ ОБ ОПЕЧАТКЕ. Совпадение имени в ИСХОДНИКЕ
    // доказательством не является: заглушка, положившая те же имена в
    // комментарий, проходила эту сверку и уносила с собой 205 проверок
    // (K4-01, 09.08.2026). Настоящее доказательство — исполнение: список
    // фактически выполненных проверок собирает tests/run.php и сверяет с
    // реестром сам («НАЗВАННАЯ РЕЕСТРОМ ПРОВЕРКА ПОВЕДЕНИЯ РЕАЛЬНО ВЫПОЛНИЛАСЬ»).
    $bezDokazatelstv = [];
    foreach ((array)($registry['capabilities'] ?? []) as $cap) {
        $id = (string)($cap['id'] ?? '?');
        $proverki = (array)($cap['checks'] ?? []);
        if ($proverki === []) { $bezDokazatelstv[] = $id . ': не названо ни одной проверки поведения'; continue; }
        $tela = '';
        foreach ((array)($cap['tests'] ?? []) as $t) {
            $tp = $root . '/' . (string)$t;
            if (is_file($tp)) $tela .= (string)file_get_contents($tp);
        }
        foreach ($proverki as $nazvanie) {
            $nazvanie = (string)$nazvanie;
            if (strpos($tela, "check('" . $nazvanie . "'") === false
                && strpos($tela, 'check("' . $nazvanie . '"') === false) {
                $bezDokazatelstv[] = $id . ': имени «' . $nazvanie . '» нет в исходниках названных наборов (опечатка?)';
            }
        }
    }
    check('КАЖДЫЙ БЛОК НАЗЫВАЕТ ПРОВЕРКИ СВОЕГО ПОВЕДЕНИЯ, И ИХ ИМЕНА ЕСТЬ В ИСХОДНИКЕ',
        $bezDokazatelstv === [], implode(' · ', $bezDokazatelstv));
}

// ── ВЫКЛАДКА: ПОДМЕНЯЕМЫЙ ТРАНСПОРТ И ТОЧКИ ОТКАЗА ──────────────────────────
// Проверить обрыв выкладки нельзя, пока транспорт прибит гвоздями к боевому
// серверу. Здесь стережём три вещи: подмена возможна, она невозможна случайно
// (нужна явная переменная), и точки отказа без подмены не существуют.
$deploySrc = (string)@file_get_contents($root . '/deploy-prod.sh');
check('транспорт выкладки можно подменить для испытаний',
    strpos($deploySrc, 'BXAPP_DEPLOY_SSH') !== false,
    'в deploy-prod.sh нет подменяемого транспорта — обрыв выкладки проверить нечем');
check('ПОДМЕНА ТРАНСПОРТА НЕ СЛУЧАЕТСЯ САМА',
    preg_match('~if \[ -n "\$\{BXAPP_DEPLOY_SSH:-\}" \]~', $deploySrc) === 1
    && strpos($deploySrc, 'ssh -i "$NETANGELS_SSH_KEY"') !== false,
    'ветка боевого ssh должна оставаться значением по умолчанию');
check('у выкладки есть точки отказа на публикации и на приёмке',
    strpos($deploySrc, 'deploy_failpoint after_publish') !== false
    && strpos($deploySrc, 'deploy_failpoint before_accept') !== false,
    'нет точек отказа — обрыв между публикацией и приёмкой не проверяется');
check('ТОЧКА ОТКАЗА ВЫКЛАДКИ НЕ РАБОТАЕТ БЕЗ ПОДМЕНЁННОГО ТРАНСПОРТА',
    preg_match('~deploy_failpoint\(\)\s*\{\s*\n\s*\[ -n "\$\{BXAPP_DEPLOY_SSH:-\}" \] \|\| return 0~', $deploySrc) === 1,
    'на боевом запуске точка отказа обязана быть недостижимой');
// Откат обязан вызываться сам, когда выпуск опубликован, но не принят: именно
// это и есть «убили процесс посередине».
check('опубликованный, но не принятый выпуск откатывается сам',
    preg_match('~PUBLISHED. = .?1.? \] && \[ "\$ACCEPTED" != "1" \]; then rollback~', $deploySrc) === 1
    || strpos($deploySrc, 'if [ "$PUBLISHED" = "1" ] && [ "$ACCEPTED" != "1" ]; then rollback; fi') !== false,
    'в обработчике завершения нет отката для опубликованного и непринятого выпуска');

// ── ВЛАДЕЛЕЦ ПРОЕКТА ОБЪЯВЛЕН ЯВНО ──────────────────────────────────────────
// По владельцу включаются рабочие правила. Пустое поле не даёт ни ошибки, ни
// предупреждения — просто другая работа, и заметить это нечем. У шаблона
// владельца нет намеренно, но это обязано быть НАПИСАНО, а не подразумеваться.
$metaFile = $root . '/.portal-meta.json';
$meta = is_file($metaFile) ? json_decode((string)file_get_contents($metaFile), true) : null;
check('метаданные проекта на месте и читаются как JSON', is_array($meta), $metaFile);
if (is_array($meta)) {
    $ownerNamed = isset($meta['owner']) && is_string($meta['owner']) && trim($meta['owner']) !== '';
    $explained = array_key_exists('owner', $meta)
        && !empty($meta['owner_required_in_project'])
        && isset($meta['_']) && mb_strpos((string)$meta['_'], 'владел') !== false;
    // Копию от самого шаблона отличает подстановка имени: скрипт создания
    // проекта меняет {{PROJECT_NAME}} на слаг во всех .json. Без этого различия
    // проверка была зелёной ВЕЗДЕ — объяснение «у шаблона владельца нет»
    // копируется в проект вместе с файлом и закрывает её навсегда. Проверено на
    // живом создании проекта 09.08.2026: owner остался пустым, набор испытаний
    // прошёл, и никто бы не узнал.
    $imya = isset($meta['project']) && is_string($meta['project']) ? trim($meta['project']) : '';
    $ehtoKopiya = $imya !== '' && strpos($imya, '{{') === false;
    if ($ehtoKopiya) {
        check('ВЛАДЕЛЕЦ ПРОЕКТА НАЗВАН',
            $ownerNamed,
            "проект $imya создан из шаблона, но owner пуст: правила владельца молча не применяются");
    } else {
        check('ВЛАДЕЛЕЦ НАЗВАН ЛИБО ЕГО ОТСУТСТВИЕ ОБЪЯСНЕНО',
            $ownerNamed || $explained,
            'ни owner, ни объяснения: правила владельца не применятся, и никто об этом не узнает');
    }
}

// ── ПОРЯДОК И ЦЕЛОСТНОСТЬ СКРИПТОВ ФРОНТА ───────────────────────────────────
// Самый дешёвый прогон фронта: без браузера, но ловит то, что иначе всплывает
// у человека в окне — «ничего не нажимается». Скрипт подключён, но файла нет;
// прикладной код подключён РАНЬШЕ библиотек, на которых стоит; один и тот же
// файл подключён дважды и второй раз затирает состояние первого.
$razmetka = (string)@file_get_contents($root . '/www/template.html');
preg_match_all('~<script[^>]+src="([^"]+)"~i', $razmetka, $scriptMatches);
$svoiSkripty = [];
foreach ($scriptMatches[1] ?? [] as $src) {
    if (strpos($src, '//') === 0 || preg_match('~^https?://~i', $src)) continue;   // чужой хост судит другой забор
    $svoiSkripty[] = preg_replace('~\?.*$~', '', $src);
}
$netFayla = [];
foreach ($svoiSkripty as $src) {
    if (!is_file($root . '/www/' . $src)) $netFayla[] = $src;
}
check('КАЖДЫЙ ПОДКЛЮЧЁННЫЙ СКРИПТ СУЩЕСТВУЕТ', $netFayla === [],
    'подключены, но отсутствуют: ' . implode(', ', $netFayla));
$dubli = array_values(array_diff_assoc($svoiSkripty, array_unique($svoiSkripty)));
check('один и тот же скрипт не подключён дважды', $dubli === [],
    'дубли: ' . implode(', ', $dubli));
$poz = static function (string $imya) use ($svoiSkripty): int {
    foreach ($svoiSkripty as $i => $src) if (strpos($src, $imya) !== false) return $i;
    return -1;
};
$pozUi = $poz('ui.js');
$pozApp = $poz('app.js');
check('БИБЛИОТЕКИ ПОДКЛЮЧЕНЫ РАНЬШЕ ПРИКЛАДНОГО КОДА',
    $pozUi === -1 || $pozApp === -1 || $pozUi < $pozApp,
    'ui.js на позиции ' . $pozUi . ', app.js на ' . $pozApp);
// Метка версии обязательна у каждого своего скрипта: без неё человек после
// правки видит вчерашний файл, и «не работает» ищут в коде, которого уже нет.
$bezMetki = [];
foreach ($scriptMatches[1] ?? [] as $src) {
    if (strpos($src, '//') === 0 || preg_match('~^https?://~i', $src)) continue;
    if (strpos($src, '?v=') === false) $bezMetki[] = $src;
}
check('у каждого своего скрипта есть метка версии', $bezMetki === [],
    'без метки: ' . implode(', ', $bezMetki));

// ── КАТАЛОГ РЕЦЕПТОВ СВЕРЕН С ЖИВЫМИ ДОНОРАМИ ───────────────────────────────
// Каталог отправляет за кодом в ЧУЖИЕ проекты. Такая ссылка гниёт молча: файл
// перенесли, функцию убрали — а документ уверенно шлёт по адресу, которого нет.
// Человек идёт, не находит и пишет своё.
$receptyReestr = dirname(__DIR__) . '/docs/recipes.json';
check('машинный реестр доноров рецептов есть', is_file($receptyReestr), $receptyReestr);
$receptyJson = json_decode((string)@file_get_contents($receptyReestr), true);
check('реестр доноров читается и не пуст',
    is_array($receptyJson) && count($receptyJson['donors'] ?? []) >= 20,
    'доноров: ' . (is_array($receptyJson) ? count($receptyJson['donors'] ?? []) : -1));
check('у каждого донора есть путь, опорный символ и отпечаток',
    (static function (array $j): bool {
        foreach ($j['donors'] ?? [] as $z) {
            if (($z['donor'] ?? '') === '' || !preg_match('~^[a-z0-9-]+/~', (string)$z['donor'])) return false;
            if (!is_array($z['symbols'] ?? null) || !is_array($z['anchors'] ?? [])) return false;
            if (($z['symbols'] ?? []) === [] && ($z['anchors'] ?? []) === []) return false;
            foreach ($z['symbols'] ?? [] as $s) if (!is_string($s) || $s === '') return false;
            foreach ($z['anchors'] ?? [] as $a) if (!is_string($a) || $a === '') return false;
            if (!preg_match('~^[0-9a-f]{12}$~', (string)($z['sha256_12'] ?? ''))) return false;
        }
        return true;
    })(is_array($receptyJson) ? $receptyJson : []),
    'запись реестра неполна');

// Сверку зовём тем же скриптом, которым её зовёт человек: иначе испытание
// проверяло бы свою копию логики, а не забор.
$receptyOut = [];
$receptyCode = 0;
exec('php ' . escapeshellarg(dirname(__DIR__) . '/tools/recipes-check.php') . ' --json 2>&1',
    $receptyOut, $receptyCode);
$receptyR = json_decode(implode("\n", $receptyOut), true);
check('сверка каталога рецептов запускается и отвечает разбором',
    is_array($receptyR) && array_key_exists('ok', $receptyR), implode("\n", $receptyOut));
// ПРОПУСК — НЕ «ВСЁ ХОРОШО»: на машине без чужих проектов сверять нечем, и это
// сказано отдельным полем, а не выдано за успех.
if (is_array($receptyR) && !empty($receptyR['skipped'])) {
    check('недоступность чужих проектов названа пропуском, а не успехом',
        $receptyR['checked'] === 0 && $receptyR['warnings'] !== [],
        json_encode($receptyR, JSON_UNESCAPED_UNICODE));
} else {
    // ДРЕЙФ ЧУЖИХ ПРОЕКТОВ — НЕ ПОЛОМКА ШАБЛОНА (находка ревью 08.08.2026,
    // BXREV-021). Донор живёт в ЧУЖОМ проекте: его переименовали, перенесли или
    // удалили — шаблон при этом не изменился ни на байт, а набор краснел. Здесь
    // проверяется только своё: реестр читается, каталог не ссылается на доноров
    // мимо реестра. Расхождение с живыми проектами показывается отдельной
    // строкой и разбирается отдельно.
    check('КАТАЛОГ РЕЦЕПТОВ ИСПРАВЕН САМ ПО СЕБЕ, НЕЗАВИСИМО ОТ ЧУЖИХ ПРОЕКТОВ',
        is_array($receptyR) && !empty($receptyR['ok']) && $receptyCode !== 1,
        json_encode($receptyR['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $dreyf = is_array($receptyR) ? (array)($receptyR['drift'] ?? []) : [];
    if ($dreyf !== []) {
        // Не молчим: «ушедший донор» надо когда-то поправить, просто не ценой
        // красного набора у того, кто чужие проекты в глаза не видел.
        echo '  ~ дрейф доноров: ' . count($dreyf) . ' — ' . mb_substr((string)$dreyf[0], 0, 90)
            . ' (подробности: php tools/recipes-check.php)' . PHP_EOL;
    }
    // Отсутствие чужого проекта НЕ должно давать код «шаблон сломан».
    $chuzhoyKoren = sys_get_temp_dir() . '/bxapp-net-donorov-' . bin2hex(random_bytes(3));
    $probaOut = []; $probaCode = 0;
    exec('BXAPP_DONOR_ROOT=' . escapeshellarg($chuzhoyKoren) . ' php '
        . escapeshellarg(dirname(__DIR__) . '/tools/recipes-check.php') . ' --json 2>&1',
        $probaOut, $probaCode);
    $probaR = json_decode(implode("\n", $probaOut), true);
    check('ПРОПАВШИЕ ЧУЖИЕ ПРОЕКТЫ НЕ ДЕЛАЮТ ШАБЛОН КРАСНЫМ',
        $probaCode !== 1 && is_array($probaR) && !empty($probaR['skipped']),
        'код ' . $probaCode . ': ' . implode(' ', array_slice($probaOut, 0, 3)));
}

// ── УЕХАВШАЯ ЦИТАТА НАЗЫВАЕТ НОВЫЕ СТРОКИ (ревью 23.09.2026, doki-1) ─────────
// Донор правят — номера строк в каталоге гниют, а сверка говорила лишь
// «проверить цитаты по строкам 1796-1864», и никто не знал, куда смотреть. Стенд:
// донор bg-calendar с опорными функциями НИЖЕ записанного диапазона.
$citataKoren = sys_get_temp_dir() . '/bxapp-citata-' . bin2hex(random_bytes(3));
$citataZapis = null;
foreach ((array)($receptyJson['donors'] ?? []) as $z) {
    if (($z['cited_ranges'] ?? []) !== [] && ($z['symbols'] ?? []) !== []) { $citataZapis = $z; break; }
}
$citataOk = false; $citataOut = [];
if (is_array($citataZapis)) {
    $citataFile = $citataKoren . '/' . (string)$citataZapis['donor'];
    @mkdir(dirname($citataFile), 0700, true);
    $citataTekst = "<?php\n" . str_repeat("// заполнитель\n", 2000);
    foreach ($citataZapis['symbols'] as $sym) $citataTekst .= 'function ' . $sym . "() {}\n";
    file_put_contents($citataFile, $citataTekst);
    exec('BXAPP_DONOR_ROOT=' . escapeshellarg($citataKoren) . ' php '
        . escapeshellarg(dirname(__DIR__) . '/tools/recipes-check.php') . ' --json 2>&1', $citataOut);
    $citataR = json_decode(implode("\n", $citataOut), true);
    foreach ((array)($citataR['drift'] ?? []) as $d) {
        if (strpos((string)$d, (string)$citataZapis['donor']) === 0 && preg_match('~теперь на строках 200[0-9]~u', (string)$d)) {
            $citataOk = true;
        }
    }
    @unlink($citataFile);
}
if (is_dir($citataKoren)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($citataKoren, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($citataKoren);
}
check('уехавшая цитата донора названа новыми строками опорных функций',
    $citataOk, mb_substr(implode(' ', $citataOut), 0, 300));

// ── K4-04. ИНСТРУМЕНТ ПОДЪЁМА КОНТРАКТА НАСТРОЕК ОБЯЗАН ИСПОЛНЯТЬСЯ ──────────
// Читать его исходник глазами мало: 09.08.2026 в комментарий попал закрывающий
// тег PHP, и весь код после него стал обычным текстом. Линт такое считает
// законным, поэтому файл молча печатал сам себя, выходил с кодом 0 и не писал
// ни эталон, ни историю — а красный прогон требовал выполнить ровно эту
// команду, то есть штатного лекарства не существовало вовсе. Проверка
// ЗАПУСКАЕТ инструмент на временной копии и смотрит результат его работы.
$bumpSandbox = sys_get_temp_dir() . '/bxapp-bump-' . bin2hex(random_bytes(4));
$bumpReady = @mkdir($bumpSandbox . '/www', 0700, true) && @mkdir($bumpSandbox . '/tests', 0700, true)
    && @mkdir($bumpSandbox . '/tools', 0700, true)
    && @copy($root . '/www/env.php', $bumpSandbox . '/www/env.php')
    && @copy($root . '/tests/env-contract.txt', $bumpSandbox . '/tests/env-contract.txt')
    && @copy($root . '/tests/env-contract-history.txt', $bumpSandbox . '/tests/env-contract-history.txt')
    && @copy($root . '/tools/env-contract-bump.php', $bumpSandbox . '/tools/env-contract-bump.php')
    && @copy($root . '/tools/env-define-names.php', $bumpSandbox . '/tools/env-define-names.php');
// Песочница не собралась — исход «не проверено», а не «пройдено»: значения по
// умолчанию заведомо провальные, чтобы отсутствие копии не выдавало зелёный.
$bumpRc = -1; $bumpOut = ['песочница не собралась']; $bumpSchema = 0;
$historyGrew = false; $contractGrew = false; $bumpPrintedSelf = true;
if ($bumpReady) {
    $envCopy = $bumpSandbox . '/www/env.php';
    $envSrc = (string)file_get_contents($envCopy);
    preg_match("/define\(\s*'BXAPP_ENV_SCHEMA'\s*,\s*(\d+)\s*\)/", $envSrc, $bm);
    $bumpSchema = (int)($bm[1] ?? 0) + 1;
    $envSrc = (string)preg_replace("/define\(\s*'BXAPP_ENV_SCHEMA'\s*,\s*\d+\s*\)/",
        "define('BXAPP_ENV_SCHEMA', " . $bumpSchema . ")", $envSrc, 1);
    file_put_contents($envCopy, $envSrc . "\ndefine('BXAPP_PROBE_BUMP_SETTING', 'x');\n");
    $historyBefore  = (string)file_get_contents($bumpSandbox . '/tests/env-contract-history.txt');
    $contractBefore = (string)file_get_contents($bumpSandbox . '/tests/env-contract.txt');
    $bumpOut = []; $bumpRc = 0;
    exec('cd ' . escapeshellarg($bumpSandbox) . ' && ' . escapeshellarg(PHP_BINARY)
        . ' tools/env-contract-bump.php 2>&1', $bumpOut, $bumpRc);
    $historyAfter  = (string)file_get_contents($bumpSandbox . '/tests/env-contract-history.txt');
    $contractAfter = (string)file_get_contents($bumpSandbox . '/tests/env-contract.txt');
    $historyGrew  = $historyAfter !== $historyBefore
        && strpos($historyAfter, "\n" . $bumpSchema . "\t") !== false;
    $contractGrew = $contractAfter !== $contractBefore
        && strpos($contractAfter, 'BXAPP_PROBE_BUMP_SETTING') !== false;
    // Собственный исходник в выводе — тот самый симптом: PHP-режим закрыт, и
    // остаток файла уехал в stdout как inline-HTML.
    $bumpPrintedSelf = preg_match('~^\s*//~m', implode("\n", $bumpOut)) === 1;
    foreach (['/www/env.php', '/tests/env-contract.txt', '/tests/env-contract-history.txt',
              '/tests/env-contract-history.txt.lock', '/tools/env-contract-bump.php',
              '/tools/env-define-names.php'] as $tmpFile) @unlink($bumpSandbox . $tmpFile);
    foreach (['/www', '/tests', '/tools'] as $tmpDir) @rmdir($bumpSandbox . $tmpDir);
    @rmdir($bumpSandbox);
}
check('ИНСТРУМЕНТ ПОДЪЁМА КОНТРАКТА НАСТРОЕК РЕАЛЬНО ПИШЕТ ЭТАЛОН И ИСТОРИЮ',
    $bumpRc === 0 && $historyGrew && $contractGrew && !$bumpPrintedSelf,
    'код ' . $bumpRc . '; история дописана: ' . var_export($historyGrew, true)
    . '; эталон обновлён: ' . var_export($contractGrew, true)
    . '; печатает свой исходник: ' . var_export($bumpPrintedSelf, true)
    . '; вывод: ' . mb_substr(trim(implode(' | ', array_slice($bumpOut, 0, 3))), 0, 160));

// Тот же корень, второй забор: закрывающий тег PHP не нужен НИГДЕ в шаблоне —
// ни в коде, ни внутри комментария. Внутри комментария он опаснее всего:
// PHP-режим закрывается, остаток файла становится текстом, а линт молчит.
$zakryvayushchiyTeg = static function (string $src): array {
    $naydeno = [];
    foreach (@token_get_all($src) as $t) {
        if (is_array($t) && $t[0] === T_CLOSE_TAG) $naydeno[] = (int)$t[2];
    }
    return $naydeno;
};
// ОБРАЗЕЦ НАРУШЕНИЯ. Забор без него зелёный всегда — в том числе когда он
// перестал ловить: проверяем на заведомо испорченном исходнике, что предикат
// краснеет, и только потом верим его молчанию на настоящих файлах.
$obrazetsTega = "<?php\n// комментарий с закрывающим тегом " . '?' . ">\n\$x = 1;\n";
check('образец: закрывающий тег в комментарии предикат видит',
    $zakryvayushchiyTeg($obrazetsTega) !== [] && $zakryvayushchiyTeg("<?php\n\$x = 1;\n") === []);
$closeTagHits = [];
$phpWalk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO));
foreach ($phpWalk as $phpFile) {
    if (!$phpFile->isFile() || strtolower($phpFile->getExtension()) !== 'php') continue;
    $rel = substr($phpFile->getPathname(), strlen($root) + 1);
    // data/ — стор приложения: там файлы НАЧИНАЮТСЯ с exit-префикса и по
    // устройству содержат закрывающий тег, это их защита, а не ошибка.
    if (strpos($rel, 'data/') === 0 || strpos($rel, '.git') === 0) continue;
    $lines = $zakryvayushchiyTeg((string)file_get_contents($phpFile->getPathname()));
    if ($lines !== []) $closeTagHits[] = $rel . ' стр. ' . implode(',', $lines);
}
check('ЗАКРЫВАЮЩЕГО ТЕГА PHP НЕТ НИ В ОДНОМ ФАЙЛЕ ШАБЛОНА ВНЕ data/', $closeTagHits === [],
    'после него остаток файла — не код, а текст, и линт этого не видит: '
    . implode(' · ', $closeTagHits));

// ── K4-35. ЧИСЛА СОСТАВА ЯДРА В ДОКУМЕНТАХ СЧИТАЕТ МАШИНА ──────────────────
// Оба документа объясняют «правка прошла без вопроса ≠ файл не ядро» и называют
// состав словами. 09.08.2026 в ядро добавили фронтовой движок — и оба текста
// разошлись с действительностью молча: говорили про двенадцать незащищённых
// файлов, а их шестнадцать. Агент, сверяясь с документом, считал правку
// tokens.css безопасной для ядра. Числа обязаны сверяться с settings.json и
// диском, а не переписываться руками.
$coreFixtureOk = false; $coreFixtureDetail = 'нет независимого core contract helper';
if (function_exists('gateTemplateCoreFiles') && function_exists('gateTemplateCoreInventoryProblems')) {
    $coreFixture = sys_get_temp_dir() . '/bxapp-core-contract-' . bin2hex(random_bytes(5));
    $fixtureRegistry = ['project_extensions' => [[
        'file' => 'www/api/project-report.php',
        'companions' => ['www/api/project-report-lib.php'],
    ]]];
    foreach (gateTemplateCoreFiles() as $relative) {
        @mkdir(dirname($coreFixture . '/' . $relative), 0700, true);
        file_put_contents($coreFixture . '/' . $relative, "<?php\n");
    }
    @mkdir($coreFixture . '/www/api', 0700, true);
    file_put_contents($coreFixture . '/www/api/project-report.php', "<?php\n");
    file_put_contents($coreFixture . '/www/api/project-report-lib.php', "<?php\n");
    $projectProblems = gateTemplateCoreInventoryProblems($coreFixture, $fixtureRegistry);
    file_put_contents($coreFixture . '/www/api/undeclared-extra.php', "<?php\n");
    $undeclaredProblems = gateTemplateCoreInventoryProblems($coreFixture, $fixtureRegistry);
    @unlink($coreFixture . '/www/api/undeclared-extra.php');
    $removed = gateTemplateCoreFiles()[0];
    @unlink($coreFixture . '/' . $removed);
    $removedProblems = gateTemplateCoreInventoryProblems($coreFixture, $fixtureRegistry);
    $coreFixtureOk = count(gateTemplateCoreFiles()) === 39 && $projectProblems === []
        && $undeclaredProblems !== [] && $removedProblems !== [];
    $coreFixtureDetail = 'project=' . implode(' · ', $projectProblems)
        . '; undeclared=' . implode(' · ', $undeclaredProblems)
        . '; removed=' . implode(' · ', $removedProblems);
    $fixtureWalk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($coreFixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($fixtureWalk as $fixturePath) {
        $fixturePath->isDir() ? @rmdir($fixturePath->getPathname()) : @unlink($fixturePath->getPathname());
    }
    @rmdir($coreFixture);
}
check('PROJECT ENDPOINT С VALID EXTENSION НЕ МЕНЯЕТ CANONICAL 39/23/16, CORE ADD/REMOVE КРАСНЫЕ',
    $coreFixtureOk, $coreFixtureDetail);

// Ревью 23.09.2026 (dubli-4): фронтовое ядро было записано трижды — дважды в
// gate-lib.php и в раздающем core-sync.sh. Новый файл движка, вписанный только в
// гейт, давал зелёный прогон и молча не доезжал до проектов. Теперь список один:
// tools/core-extra.list, его читают и гейт, и сверка ядра.
$coreExtraList = [];
foreach (preg_split('~\R~u', (string)@file_get_contents($root . '/tools/core-extra.list')) ?: [] as $coreLine) {
    $coreLine = trim($coreLine);
    if ($coreLine !== '' && $coreLine[0] !== '#') $coreExtraList[] = $coreLine;
}
$gateLibText = (string)@file_get_contents($root . '/tests/gate-lib.php');
check('фронтовое ядро гейта берётся из одного манифеста tools/core-extra.list',
    $coreExtraList !== [] && function_exists('gateTemplateExtraCoreFiles')
    && gateTemplateExtraCoreFiles() === $coreExtraList
    && array_diff($coreExtraList, gateTemplateCoreFiles()) === []
    && substr_count($gateLibText, "'www/css/components.css'") === 0,
    'манифест: ' . implode(',', $coreExtraList));

// Ревью 23.09.2026 (dubli-6): инвентарь ядра и проверка PHP 7.4 шли плоским glob,
// а гейт размещения разрешает подкаталоги www/api/ — файл во вложенной папке
// обходил и объявление в project_extensions, и проверку минимальной версии PHP.
$nestedFixture = sys_get_temp_dir() . '/bxapp-core-nested-' . bin2hex(random_bytes(5));
foreach (gateTemplateCoreFiles() as $relative) {
    @mkdir(dirname($nestedFixture . '/' . $relative), 0700, true);
    file_put_contents($nestedFixture . '/' . $relative, "<?php\n");
}
@mkdir($nestedFixture . '/www/api/report', 0700, true);
file_put_contents($nestedFixture . '/www/api/report/util.php', "<?php\n");
$nestedProblems = gateTemplateCoreInventoryProblems($nestedFixture, ['project_extensions' => []]);
testWipe($nestedFixture, true);
check('файл во вложенной папке www/api не обходит инвентарь ядра',
    (bool)array_filter($nestedProblems, static fn(string $p): bool => strpos($p, 'report/util.php') !== false),
    implode(' · ', $nestedProblems));

$projectExtensionExample = '{"id":"monthly-report","file":"www/api/monthly-report.php",'
    . '"companions":["www/api/monthly-report-lib.php"],'
    . '"tests":["tests/monthly_report_test.php"],'
    . '"checks":["месячный отчёт отдаёт все строки"]}';
$projectExtensionDocsBad = [];
foreach (['AGENTS.md', 'CLAUDE.md', 'docs/BLOCKS.md'] as $instructionFile) {
    $instruction = (string)@file_get_contents($root . '/' . $instructionFile);
    if (strpos($instruction, $projectExtensionExample) === false
        || strpos($instruction, 'top-level `companion_files`') === false
        || strpos($instruction, 'per-extension `companions`') === false) {
        $projectExtensionDocsBad[] = $instructionFile;
    }
}
check('ИНСТРУКЦИИ АГЕНТА ПОКАЗЫВАЮТ PROJECT EXTENSION COMPANIONS И ОТЛИЧАЮТ ИХ ОТ TEMPLATE',
    $projectExtensionDocsBad === [],
    $projectExtensionDocsBad === [] ? '' : 'нет exact-примера/различия: ' . implode(',', $projectExtensionDocsBad));

$coreFiles = gateTemplateCoreFiles();
$coreInventoryBad = gateTemplateCoreInventoryProblems($root, $registry);
$askList = [];
$settingsJson = json_decode((string)@file_get_contents($root . '/.claude/settings.json'), true);
foreach ((array)($settingsJson['permissions']['ask'] ?? []) as $pravilo) {
    if (preg_match('~^Edit\(/(.+)\)$~', (string)$pravilo, $am)) $askList[] = $am[1];
}
$coreFree = array_values(array_diff($coreFiles, $askList));
$vsegoYadra = count($coreFiles);
$bezSprosa = count($coreFree);
$zakrytoAsk = $vsegoYadra - $bezSprosa;
// Нечитаемый settings.json — исход «не проверено»: без него сверять не с чем, и
// молчание здесь означало бы, что документы верны, а это неизвестно.
check('состав ядра читается: файлы на диске и ask-правила на месте',
    is_array($settingsJson) && $coreInventoryBad === []
        && $vsegoYadra > 0 && $zakrytoAsk > 0 && $bezSprosa > 0,
    $vsegoYadra . ' файлов ядра, ' . $zakrytoAsk . ' закрыто ask, ' . $bezSprosa . ' без ask'
        . ($coreInventoryBad === [] ? '' : '; ' . implode(' · ', $coreInventoryBad)));
foreach (['CLAUDE.md', 'AGENTS.md'] as $doc) {
    $docSrc = (string)file_get_contents($root . '/' . $doc);
    preg_match('~(?:в шаблоне это|итого) (\d+) файл~u', $docSrc, $m1);
    preg_match('~закрывает (\d+) из этих (\d+)~u', $docSrc, $m2);
    preg_match('~ез подтверждения остаются (\d+) файл~u', $docSrc, $m3);
    // Словесное «и ещё восемь файлов» запрещено отдельно: именно оно разошлось
    // с диском, и разойдётся снова — числительное словом не пересчитывают.
    $slovami = preg_match('~и ещё (?:два|три|четыре|пять|шесть|семь|восемь|девять|десять|'
        . 'одиннадцать|двенадцать|тринадцать|четырнадцать|пятнадцать|шестнадцать) файл~u', $docSrc) === 1;
    check('ЧИСЛА СОСТАВА ЯДРА В ' . $doc . ' СХОДЯТСЯ С settings.json И ДИСКОМ',
        (int)($m1[1] ?? 0) === $vsegoYadra && (int)($m2[1] ?? 0) === $zakrytoAsk
        && (int)($m2[2] ?? 0) === $vsegoYadra && (int)($m3[1] ?? 0) === $bezSprosa && !$slovami,
        'в документе всего ' . ($m1[1] ?? '—') . ', закрыто ' . ($m2[1] ?? '—')
        . ', без подтверждения ' . ($m3[1] ?? '—')
        . ($slovami ? ' (число написано словом — так его и не пересчитали)' : '')
        . '; на деле ' . $vsegoYadra . ' / ' . $zakrytoAsk . ' / ' . $bezSprosa
        . '; без подтверждения: ' . implode(', ', $coreFree));
}

// ── K4-36. НАЗНАЧЕНИЕ В ИНВЕНТАРЕ БЕРЁТСЯ ИЗ ШАПКИ ФАЙЛА ───────────────────
// Сборщик карты брал ПЕРВЫЙ /** … */ в файле. У cron-воркера шапка написана
// строчными комментариями, поэтому первым docblock оказывался комментарий к
// внутренней константе — и карта объявляла назначением фонового рабочего фразу
// «Как часто спрашиваем портал про тех, у кого есть живой пропуск», а главный
// вход шёл как «(без описания в docblock)». Агент читает карту, чтобы понять,
// куда класть работу, и получает бессмыслицу; сверка при этом зелёная.
$mapMd = (string)file_get_contents($root . '/docs/MAP.md');
$razdely = ['### Куда приходит запрос снаружи', '### Фоновая работа'];
$bezNaznacheniya = [];
$neIzShapki = [];
foreach ($razdely as $zagolovok) {
    $ot = strpos($mapMd, $zagolovok);
    if ($ot === false) { $bezNaznacheniya[] = 'в карте нет раздела ' . $zagolovok; continue; }
    $do = strpos($mapMd, "\n### ", $ot + strlen($zagolovok));
    $kusok = substr($mapMd, $ot, $do === false ? null : $do - $ot);
    preg_match_all('~^- \*\*`([^`]+)`\*\* — (.*)$~mu', $kusok, $stroki, PREG_SET_ORDER);
    foreach ($stroki as $stroka) {
        $fajl = $stroka[1];
        $naznachenie = trim($stroka[2]);
        if ($naznachenie === '' || mb_strpos($naznachenie, 'без описания') !== false) {
            $bezNaznacheniya[] = $fajl . ': ' . ($naznachenie === '' ? 'пусто' : $naznachenie);
            continue;
        }
        // Назначение обязано лежать в ШАПКЕ файла. Комментарий к константе из
        // середины — это назначение чего угодно, только не файла.
        $put = $root . '/' . $fajl;
        if (!is_file($put)) { $bezNaznacheniya[] = $fajl . ': файла нет'; continue; }
        $shapka = implode("\n", array_slice(preg_split('~\R~u',
            (string)file_get_contents($put)), 0, 25));
        $nachalo = mb_substr($naznachenie, 0, 24);
        if (mb_strpos($shapka, $nachalo) === false) {
            $neIzShapki[] = $fajl . ': «' . $nachalo . '…» в первых 25 строках файла нет';
        }
    }
}
check('У КАЖДОЙ ТОЧКИ ВХОДА И ФОНОВОГО ФАЙЛА В КАРТЕ ЕСТЬ НАЗНАЧЕНИЕ',
    $bezNaznacheniya === [], implode(' · ', $bezNaznacheniya));
check('НАЗНАЧЕНИЕ В КАРТЕ ВЗЯТО ИЗ ШАПКИ ФАЙЛА, А НЕ ИЗ ЧУЖОГО КОММЕНТАРИЯ',
    $neIzShapki === [], implode(' · ', $neIzShapki));

// ── R28-10. ТЕКУЩИЙ REVIEW НЕ СМЕШИВАЕТСЯ С ИСТОРИЧЕСКИМ LEDGER ───────────
// Старый quality-чеклист содержит честную историю, но его открытые формулировки
// уже заставляли Stop и агента поднимать закрытый долг как новую задачу.
$currentReviewName = 'review-2026-08-28.md';
$qualityArchive = (string)file_get_contents($root . '/docs/CHEKLIST-KACHESTVA.md');
$qualityArchiveHead = mb_substr($qualityArchive, 0, 1800);
$boxResearch = (string)file_get_contents($root . '/docs/b24-on-premise-research-2026-08-08.md');
$boxResearchHead = mb_substr($boxResearch, 0, 2200);
check('MAP ВЕДЁТ В ТЕКУЩИЙ REVIEW, А CHEKLIST НАЗЫВАЕТ ИСТОРИЧЕСКИМ АРХИВОМ',
    strpos($mapMd, $currentReviewName) !== false
    && preg_match('~CHEKLIST-KACHESTVA[^\n]{0,180}(?:архив|истори)~iu', $mapMd) === 1
    && strpos($mapMd, 'ЕДИНЫЙ список всех работ') === false);
check('ИСТОРИЧЕСКИЙ CHEKLIST СРАЗУ МАРКИРОВАН АРХИВОМ И ВЕДЁТ В ТЕКУЩИЙ REVIEW',
    strpos($qualityArchiveHead, $currentReviewName) !== false
    && preg_match('~архив~iu', $qualityArchiveHead) === 1
    && strpos($qualityArchiveHead, 'ЕДИНЫЙ список всех работ') === false);
check('BOX-ИССЛЕДОВАНИЕ НЕ ОБЪЯВЛЯЕТ СТАРЫЙ CHEKLIST CURRENT LEDGER',
    strpos($boxResearchHead, $currentReviewName) !== false
    && preg_match('~CHEKLIST-KACHESTVA[^\n]{0,180}(?:архив|истори)~iu', $boxResearchHead) === 1
    && strpos($boxResearchHead, 'Канонический ledger') === false);

// ── K4-37 / K4-44. В КОРНЕ ШАБЛОНА НЕТ ЧУЖОГО ─────────────────────────────
// Новый проект раскладывается командой `cp -r "$TEMPLATE_DIR/." "$PROJECT_DIR"`,
// то есть корень шаблона уезжает ЦЕЛИКОМ. Забытый после отладки a.out приезжал
// в каждый новый проект, и разработчик тратил время на выяснение, что это и
// можно ли удалять. Прежний забор смотрел только .php — файл без расширения
// проходил мимо него молча.
$korenBelyy = ['AGENTS.md', 'CLAUDE.md', 'PORTING.md', 'README.md', 'SECURITY.md',
    'deploy-prod.sh', '.gitignore', '.portal-meta.json'];
$korenPapki = ['.claude', 'data', 'docs', 'scripts', 'tests', 'tools', 'www'];
// Каталоги ОКРУЖЕНИЯ, а не шаблона: их заводит не разработчик, а инструменты
// вокруг проекта — фабрика при заведении (`.ai-factory`) и редактор при первом
// открытии (`.vscode`). В шаблоне их нет, уехать через cp -r им неоткуда, зато
// в каждом заведённом проекте они появляются сами и делали деплой красным на
// ровном месте: 17.08.2026 первый же `deploy-prod.sh` в новом проекте встал на
// «уедет в каждый новый проект: .ai-factory, .vscode», хотя убирать нечего.
// `.publish` — из той же семьи: его заводит панель dev-портала, когда владелец
// открывает предпросмотр приложения (поднимает `php -S 127.0.0.1:<порт> -t www`),
// и держит там pid и лог этого сервера. 04.09.2026 владелец просто открыл
// предпросмотр — самопроверка стала красной, хотя ни строки кода никто не менял.
// Забор обязан ловить забытый разработчиком мусор, а не штатное действие панели.
// `.bxapp-core.json` — из той же семьи: карту отпечатков ядра заводит общий
// инструмент сверки (`/data/standards/tools/core-sync.sh --adopt`), без неё он
// не отличает «файл отстал от шаблона» от «файл правили под себя». В самом
// шаблоне её нет и уехать через cp -r ей неоткуда, а в проекте она появляется
// после первой же сверки — и делала деплой красным сразу после неё.
$korenSredy = ['.ai-factory', '.vscode', '.publish', '.bxapp-core.json'];
$lishneeVKorne = static function (array $imena) use ($korenBelyy, $korenPapki, $korenSredy): array {
    $lishnee = [];
    foreach ($imena as $imya) {
        if ($imya === '.' || $imya === '..') continue;
        if (in_array($imya, $korenBelyy, true) || in_array($imya, $korenPapki, true)) continue;
        if (in_array($imya, $korenSredy, true)) continue;
        $lishnee[] = $imya;
    }
    sort($lishnee);
    return $lishnee;
};
// ОБРАЗЕЦ НАРУШЕНИЯ: без него забор зелёный и тогда, когда перестал ловить.
// Второй образец — про послабление: каталоги окружения проходят, но соседний
// незнакомый скрытый каталог по-прежнему ловится, а не «все точечные — свои».
check('образец: посторонний файл в корне предикат видит',
    $lishneeVKorne(['CLAUDE.md', 'a.out', 'www']) === ['a.out']
    && $lishneeVKorne(array_merge($korenBelyy, $korenPapki)) === []
    && $lishneeVKorne($korenSredy) === []
    && $lishneeVKorne(['.vscode', '.publish', '.bxapp-core.json', '.svn', 'a.out']) === ['.svn', 'a.out']);
$vKorne = $lishneeVKorne(scandir($root) ?: []);
check('В КОРНЕ ШАБЛОНА НЕТ ПОСТОРОННИХ ФАЙЛОВ', $vKorne === [],
    'уедет в каждый новый проект через cp -r: ' . implode(', ', $vKorne));
// Стор шаблона обязан быть пуст: состояние заводит установка, а не шаблон.
// Журнал, токены или сессия от прогона — чужой мусор в новом проекте.
//
// Исключения два, и оба — файлы, которые заводит не разработчик и не прогон.
//
// Первое — замки `*.lock.php`. Их создаёт САМО ядро: `appLifecycleLock()`
// (www/api/lib.php) кладёт `data/lifecycle.lock.php` на ПЕРВОМ ЖЕ запросе к
// приложению, и внутри лежит только штатная заглушка `404 + exit` — ни байта
// состояния. Значит стоило владельцу открыть предпросмотр в панели, как забор
// объявлял мусором файл, который приложение обязано было создать.
//
// Второе — `preview-key.php`, ключ доверенного предпросмотра: его кладёт панель
// дев-портала при подъёме публикации, ровно с тем же эффектом — открыл своё
// приложение и получил красный забор. В новый проект такой ключ уехать не может:
// панель заводит его в сторе конкретной публикации, а стор не копируется и не
// деплоится; попади он туда всё равно — он не открывает НИЧЕГО, пока панель не
// подпишет им запрос, а подписывает она только владельцу своего проекта.
//
// Уехавшие токены или сессия — совсем другое дело, поэтому всё остальное
// по-прежнему красное.
$lishneeVStore = static function (array $imena): array {
    $lishnee = [];
    foreach ($imena as $imya) {
        if (in_array($imya, ['.', '..', '.gitkeep', 'preview-key.php'], true)) continue;
        if (substr($imya, -9) === '.lock.php') continue;
        $lishnee[] = $imya;
    }
    sort($lishnee);
    return $lishnee;
};
// ОБРАЗЕЦ НАРУШЕНИЯ: послабление касается ТОЛЬКО замков. Настоящий след
// состояния — токены, сессии, журнал — обязан краснить и рядом с замком.
check('образец: след состояния в сторе предикат видит и рядом с замком',
    $lishneeVStore(['.', '..', 'lifecycle.lock.php']) === []
    && $lishneeVStore(['lifecycle.lock.php', 'b24-tokens.php', 'app.log'])
        === ['app.log', 'b24-tokens.php']
    && $lishneeVStore(['lock.php']) === ['lock.php']
    // Ключ предпросмотра послаблением прикрыт, а сессии и токены рядом с ним — нет.
    && $lishneeVStore(['preview-key.php']) === []
    && $lishneeVStore(['preview-key.php', 'sessions.php', 'b24-tokens.php'])
        === ['b24-tokens.php', 'sessions.php']);
// ГДЕ ЭТА ПРОВЕРКА УМЕСТНА, А ГДЕ ВРЕДИТ. Смысл её один: корень шаблона уезжает
// в новый проект целиком, и стор обязан приехать пустым. В РАБОЧЕМ проекте стор
// живой по определению — там сессии, журнал и замки, которые кладёт само
// приложение при первом же запросе. Требовать от него пустоты значит требовать,
// чтобы приложение никогда не запускали: 04.09.2026 владелец открыл предпросмотр,
// и самопроверка покраснела на `sessions.php`, `journal.lock.php`,
// `preview-key.php` — на файлах, которые приложение обязано было создать.
// Отличаем по владельцу: у шаблона в `.portal-meta.json` `owner` пуст НАМЕРЕННО
// (шаблон не принадлежит человеку), у проекта он заполнен.
$metaSyroy = @file_get_contents($root . '/.portal-meta.json');
$meta = is_string($metaSyroy) ? json_decode($metaSyroy, true) : null;
$etoShablon = !is_array($meta) || trim((string)($meta['owner'] ?? '')) === '';
$vData = $etoShablon ? $lishneeVStore(scandir($root . '/data') ?: []) : [];
check('СТОР ШАБЛОНА ПУСТ — СЛЕДЫ ПРОГОНОВ НЕ УЕЗЖАЮТ В НОВЫЙ ПРОЕКТ', $vData === [],
    'в data/ осталось: ' . implode(', ', $vData));
// ОБРАЗЕЦ: послабление не должно превратиться в «проверка выключена всегда».
check('образец: шаблон узнаётся по пустому владельцу, проект — по заполненному',
    (static function (): bool {
        $shablon = ['owner' => null, 'template' => 'bx-app'];
        $proekt  = ['owner' => 'dmitriy.f', 'template' => 'bx-app'];
        $pust = static fn(array $m): bool => trim((string)($m['owner'] ?? '')) === '';
        return $pust($shablon) === true && $pust($proekt) === false
            && $pust(['owner' => '   ']) === true;
    })());

// ── K4-42. УКАЗАТЕЛЬ ИЗ README ОБЯЗАН КУДА-ТО ВЕСТИ ───────────────────────
// README перечисляет инструменты и отправляет за назначением каждого в карту:
// «остальные (…) — назначение каждого в docs/MAP.md». Для recipes-check.php в
// карте не было ни одного упоминания — агент шёл по указателю и не находил
// ничего, а карта при этом считалась полной.
$readmeSrc = (string)file_get_contents($root . '/README.md');
$mapSrcDlyaTools = (string)file_get_contents($root . '/docs/MAP.md');
$poteryannye = [];
foreach (glob($root . '/tools/*') ?: [] as $instrument) {
    $imya = basename($instrument);
    if (strpos($readmeSrc, $imya) === false) continue;   // README о нём молчит — и ладно
    if (strpos($mapSrcDlyaTools, $imya) === false) $poteryannye[] = $imya;
}
check('КАЖДЫЙ ИНСТРУМЕНТ, НАЗВАННЫЙ В README, ОПИСАН В КАРТЕ', $poteryannye === [],
    'README шлёт за назначением в docs/MAP.md, а там их нет: ' . implode(', ', $poteryannye));

// ── K4-43. СТРОКА С ПАЛКАМИ ВНЕ ТАБЛИЦЫ — ЭТО НЕ СТРОКА ТАБЛИЦЫ ────────────
// Каталог готовых блоков — первое, что открывают вместо написания своего.
// Строка «Полная выборка в проекте со старым движком | fetchAllCompat(...)»
// была отделена от таблицы пустой строкой и имела две колонки вместо трёх: в
// отрендеренном markdown это не строка таблицы, а текст с палками. Блок
// визуально выпадал из каталога, и его писали заново — ровно тот случай, ради
// которого каталог и заведён. Имя в тексте при этом есть, поэтому проверки
// каталога по именам символов оставались зелёными.
$tablicyBedy = static function (array $lines): array {
    $bedy = [];
    $vTablice = false;
    $kolonok = 0;
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] !== '|') { $vTablice = false; $kolonok = 0; continue; }
        // Экранированная палка внутри ячейки (`is-ok\|is-warn`) — это текст, а
        // не граница колонки: без этого каждая такая строка выглядела рваной.
        $bezEkrana = str_replace('\|', '\x01', $t);
        $stolbcy = count(explode('|', trim($bezEkrana, '|')));
        $razdelitel = preg_match('~^\|[\s:|-]+\|$~u', $bezEkrana) === 1;
        if (!$vTablice) {
            $sled = trim((string)($lines[$i + 1] ?? ''));
            if ($sled === '' || preg_match('~^\|[\s:|-]+\|$~u', $sled) !== 1) {
                $bedy[] = 'строка ' . ($i + 1) . ': «' . mb_substr($t, 0, 52)
                    . '…» начинается с палки, но таблицы над ней нет';
                continue;
            }
            $vTablice = true; $kolonok = $stolbcy;
            continue;
        }
        if ($razdelitel) continue;
        if ($stolbcy !== $kolonok) {
            $bedy[] = 'строка ' . ($i + 1) . ': колонок ' . $stolbcy . ' вместо ' . $kolonok;
        }
    }
    return $bedy;
};
// ОБРАЗЕЦ НАРУШЕНИЯ: оторванная строка и строка с недостающей колонкой.
$obrazetsTablicy = ['| Что | Чем | Почему |', '|---|---|---|', '| а | б | в |', '',
    '| Полная выборка | `fetchAllCompat(...)` |'];
$obrazetsRvanoy = ['| Что | Чем | Почему |', '|---|---|---|', '| а | б |'];
check('образец: оторванная строка и нехватка колонки предикатом видны',
    count($tablicyBedy($obrazetsTablicy)) === 1 && count($tablicyBedy($obrazetsRvanoy)) === 1
    && $tablicyBedy(['| Что | Чем |', '|---|---|', '| а | б |']) === []);
$blocksBedy = $tablicyBedy(preg_split('~\R~u',
    (string)file_get_contents($root . '/docs/BLOCKS.md')));
check('В КАТАЛОГЕ ГОТОВЫХ БЛОКОВ КАЖДАЯ СТРОКА ТАБЛИЦЫ — ДЕЙСТВИТЕЛЬНО В ТАБЛИЦЕ',
    $blocksBedy === [], implode(' · ', $blocksBedy));
