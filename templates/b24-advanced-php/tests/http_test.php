<?php
declare(strict_types=1);
/**
 * Единый вход эндпоинтов (api/http.php) — проверяется на живом эндпоинте
 * api/settings.php, который написан ровно так, как положено писать новые.
 */

suite('Единый вход эндпоинтов');

$root = dirname(__DIR__);
$data = DATA_ROOT;

// Регрессия R28-01. HTTP-фикстура вправе менять только собственную копию www:
// штатное завершение ещё может вернуть исходник на место, а SIGKILL и два
// параллельных прогона оставят подмену в шаблоне или перетрут друг друга.
$httpTestSource = (string)file_get_contents(__FILE__);
$shippingSchemaWrites = preg_match_all(
    '~file_put_contents\s*\(\s*\$(?:shemaPath|shippingSchemaPath)\b'
        . '|(?:rename|copy)\s*\([^;\n]*\$(?:shemaPath|shippingSchemaPath)\b~',
    $httpTestSource
);
check('HTTP-ФИКСТУРА НЕ ПИШЕТ В SHIPPING SETTINGS-SCHEMA', $shippingSchemaWrites === 0,
    'найдено записей в исходник: ' . (string)$shippingSchemaWrites);
$shippingSchemaPath = $root . '/www/api/settings-schema.php';
$shippingSchemaSource = (string)file_get_contents($shippingSchemaPath);
$shippingSchemaHash = hash('sha256', $shippingSchemaSource);

storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'example.bitrix24.ru', 'member_id' => 'MEMBER-1',
    'install_generation' => 'test-generation-http',
    'expires_at' => time() + 3600,
]);
aclSetOwner(10, 'Владелец');
aclUpdate(static function (array $acl): array {
    $acl['users'][] = ['uid' => 20, 'name' => 'Смотрящий', 'photo' => '', 'role' => 'view'];
    return $acl;
});

$adminToken = str_repeat('c', 64);
$viewToken  = str_repeat('d', 64);
storeWrite(SESSIONS_FILE, [
    ['token' => $adminToken, 'uid' => 10, 'role' => 'admin', 'member_id' => 'MEMBER-1',
     'install_generation' => 'test-generation-http',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
    ['token' => $viewToken,  'uid' => 20, 'role' => 'view',  'member_id' => 'MEMBER-1',
     'install_generation' => 'test-generation-http',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
]);

require_once dirname(__DIR__) . '/www/api/settings-schema.php';
$nastroykaKlyuch = '';
$secretniyKlyuch = false;
foreach (['text', 'multiline', 'secret'] as $tipNastroyki) {
    foreach (settingsSchema() as $poleNastroyki) {
        if ((string)($poleNastroyki['type'] ?? '') === $tipNastroyki) {
            $nastroykaKlyuch = (string)$poleNastroyki['key'];
            $secretniyKlyuch = $tipNastroyki === 'secret';
            break 2;
        }
    }
}
// СХЕМА ШАБЛОНА ПУСТА НАМЕРЕННО (26.08.2026): образец «Приветствие на главной»
// сохранялся, но его никто не читал, а настройка без последствий хуже её
// отсутствия. Проверять при этом надо САМ МЕХАНИЗМ записи, а не наличие
// образца, — поэтому поле объявляем ФАЙЛОМ ПРОЕКТА (api/project-hooks.php) в
// ИЗОЛИРОВАННОЙ КОПИИ www тестового сервера — ровно так, как это делает проект
// (ревью 23.09.2026, modulnost-2). Ядро settings-schema.php не подменяется вовсе,
// а shipping-исходник не меняется ни на миг: SIGKILL и параллельный прогон не
// могут оставить или перетереть служебное поле.
$serverOptions = [];
$isolatedSchemaSource = '';
if ($nastroykaKlyuch === '') {
    $isolatedSchemaSource = "<?php\ndeclare(strict_types=1);\n"
        . "if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''))) {\n"
        . "    http_response_code(404);\n    exit;\n}\n"
        . "function projectSettingsSchema(): array {\n"
        . "    return [['key' => 'probe_text', 'title' => 'Проба записи', 'type' => 'text',"
        . " 'default' => '', 'hint' => 'Служебное поле проверки — в шаблоне его нет.']];\n}\n";
    $serverOptions['wwwFiles'] = ['api/project-hooks.php' => $isolatedSchemaSource];
    $nastroykaKlyuch = 'probe_text';
}

$srv = testServer([], $serverOptions);
if (!$srv['ok']) { check('встроенный сервер запущен', false); return; }
$call = $srv['call'];
$schemaCopyPath = (string)($srv['root'] ?? '') . '/www/api/project-hooks.php';
check('ПОДМЕНА СХЕМЫ ЖИВЁТ ТОЛЬКО В КОПИИ WWW ТЕСТОВОГО СЕРВЕРА',
    $isolatedSchemaSource === '' || (
        (string)($srv['root'] ?? '') !== $root
        && (string)@file_get_contents($schemaCopyPath) === $isolatedSchemaSource
        && !is_file($root . '/www/api/project-hooks.php')
        && hash('sha256', (string)@file_get_contents($shippingSchemaPath)) === $shippingSchemaHash
    ),
    'root=' . (string)($srv['root'] ?? 'нет')
        . '; shipping=' . hash('sha256', (string)@file_get_contents($shippingSchemaPath)));

// Аварийная и параллельная регрессия R28-01. Два независимых процесса
// одновременно готовят разные варианты одного файла, после чего родитель
// снимает оба SIGKILL — shutdown-функции гарантированно не выполняются. Даже
// в этом сценарии shipping-файл должен остаться байт-в-байт прежним, а каждая
// копия обязана содержать только собственный вариант.
$isolationNonce = bin2hex(random_bytes(4));
$isolationPayloads = [
    'a' => "<?php\n// r28-isolated-a-$isolationNonce\n",
    'b' => "<?php\n// r28-isolated-b-$isolationNonce\n",
];
$isolationWorkers = [];
foreach ($isolationPayloads as $workerId => $payload) {
    $receipt = sys_get_temp_dir() . '/bxapp-r28-isolation-' . $isolationNonce . '-' . $workerId . '.json';
    $workerCode = 'declare(strict_types=1);'
        . 'require ' . var_export($root . '/tests/lib.php', true) . ';'
        . '$copy=testServerWwwCopy(' . var_export($root, true)
        . ',["api/settings-schema.php"=>' . var_export($payload, true) . '],'
        . var_export('r28-' . $workerId . '-' . $isolationNonce, true) . ');'
        . '$result=["root"=>$copy,"hash"=>hash_file("sha256",'
        . '$copy."/www/api/settings-schema.php")];'
        . 'file_put_contents(' . var_export($receipt, true)
        . ',json_encode($result,JSON_UNESCAPED_SLASHES),LOCK_EX);'
        . 'sleep(30);';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $workerCode], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    if (isset($pipes[1]) && is_resource($pipes[1])) stream_set_blocking($pipes[1], false);
    if (isset($pipes[2]) && is_resource($pipes[2])) stream_set_blocking($pipes[2], false);
    $isolationWorkers[$workerId] = [
        'process' => $process, 'pipes' => $pipes, 'receipt' => $receipt, 'payload' => $payload,
    ];
}

$isolationDeadline = microtime(true) + 5.0;
do {
    $allIsolationReady = true;
    foreach ($isolationWorkers as $worker) {
        if (!is_file((string)$worker['receipt'])) { $allIsolationReady = false; break; }
    }
    if ($allIsolationReady || microtime(true) >= $isolationDeadline) break;
    usleep(20000);
} while (true);

$isolationReceipts = [];
$isolationDiagnostics = [];
foreach ($isolationWorkers as $workerId => $worker) {
    $rawReceipt = (string)@file_get_contents((string)$worker['receipt']);
    $decodedReceipt = json_decode($rawReceipt, true);
    if (is_array($decodedReceipt)) $isolationReceipts[$workerId] = $decodedReceipt;
    $status = is_resource($worker['process']) ? proc_get_status($worker['process']) : null;
    $isolationDiagnostics[] = $workerId . ':ready=' . (is_array($decodedReceipt) ? '1' : '0')
        . ',running=' . (is_array($status) && !empty($status['running']) ? '1' : '0');
}
check('ДВЕ ПАРАЛЛЕЛЬНЫЕ КОПИИ WWW УСПЕЛИ ПОДГОТОВИТЬСЯ',
    count($isolationReceipts) === count($isolationPayloads), implode(';', $isolationDiagnostics));

$isolationRoots = [];
$parallelIsolationOk = count($isolationReceipts) === count($isolationPayloads);
foreach ($isolationPayloads as $workerId => $payload) {
    $copyRoot = (string)($isolationReceipts[$workerId]['root'] ?? '');
    $isolationRoots[] = $copyRoot;
    $copyFile = $copyRoot . '/www/api/settings-schema.php';
    $parallelIsolationOk = $parallelIsolationOk
        && $copyRoot !== '' && $copyRoot !== $root
        && (string)@file_get_contents($copyFile) === $payload
        && (string)($isolationReceipts[$workerId]['hash'] ?? '') === hash('sha256', $payload);
}
$parallelIsolationOk = $parallelIsolationOk
    && count(array_unique($isolationRoots)) === count($isolationPayloads)
    && hash('sha256', (string)@file_get_contents($shippingSchemaPath)) === $shippingSchemaHash;
check('ПАРАЛЛЕЛЬНЫЕ HTTP-ФИКСТУРЫ НЕ ДЕЛЯТ КОПИЮ И НЕ МЕНЯЮТ SHIPPING-СХЕМУ',
    $parallelIsolationOk, implode(';', $isolationDiagnostics));

foreach ($isolationWorkers as $worker) {
    if (is_resource($worker['process'])) @proc_terminate($worker['process'], 9);
}
$killDeadline = microtime(true) + 3.0;
do {
    $allIsolationStopped = true;
    foreach ($isolationWorkers as $worker) {
        if (!is_resource($worker['process'])) continue;
        $status = proc_get_status($worker['process']);
        if (is_array($status) && !empty($status['running'])) { $allIsolationStopped = false; break; }
    }
    if ($allIsolationStopped || microtime(true) >= $killDeadline) break;
    usleep(20000);
} while (true);

$killDiagnostics = [];
foreach ($isolationWorkers as $workerId => $worker) {
    $out = isset($worker['pipes'][1]) && is_resource($worker['pipes'][1])
        ? (string)stream_get_contents($worker['pipes'][1]) : '';
    $err = isset($worker['pipes'][2]) && is_resource($worker['pipes'][2])
        ? (string)stream_get_contents($worker['pipes'][2]) : '';
    foreach ((array)$worker['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($worker['process'])) @proc_close($worker['process']);
    $killDiagnostics[] = $workerId . ':out=' . hash('sha256', $out)
        . ',err=' . hash('sha256', $err);
}
check('ПОСЛЕ SIGKILL SHIPPING SETTINGS-SCHEMA ОСТАЁТСЯ БАЙТ-В-БАЙТ ПРЕЖНЕЙ',
    hash('sha256', (string)@file_get_contents($shippingSchemaPath)) === $shippingSchemaHash,
    implode(';', $killDiagnostics));

$safeIsolationPrefix = rtrim(sys_get_temp_dir(), '/') . '/bxapp-www-r28-';
foreach ($isolationRoots as $copyRoot) {
    if (is_string($copyRoot) && strncmp($copyRoot, $safeIsolationPrefix, strlen($safeIsolationPrefix)) === 0
        && is_dir($copyRoot)) {
        testWipe($copyRoot, true);
    }
}
foreach ($isolationWorkers as $worker) @unlink((string)$worker['receipt']);

// 1. Ответ единого вида: у успеха всегда ok:true.
$forcedSuccess = apiSuccessPayload(['ok' => false, 'value' => 7]);
check('данные вызывающего не могут подменить обязательный ok:true',
    ($forcedSuccess['ok'] ?? null) === true && ($forcedSuccess['value'] ?? 0) === 7);
[$code, $json, ] = $call('GET', '/api/settings.php', ['token' => $viewToken]);
check('успешный ответ приходит в едином формате', $code === 200 && ($json['ok'] ?? null) === true, "код $code");
check('эндпоинт сообщает роль спрашивающего', ($json['me']['role'] ?? '') === 'view');

// 2. Метод не из списка — отказ до всякой логики.
[$code, $json, ] = $call('DELETE', '/api/settings.php', ['token' => $adminToken]);
check('неразрешённый метод отклоняется', $code === 405 && ($json['code'] ?? '') === 'method_not_allowed', "код $code");

// 3. Роль ниже требуемой — отказ, даже если фронт прислал запрос.
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $viewToken, 'body' => '{"settings":{"x":1}}']);
check('право просмотра не позволяет менять настройки', $code === 403 && ($json['ok'] ?? null) === false, "код $code");

// 3.1. Роль проверяет САМ ЕДИНЫЙ ВХОД, а не эндпоинт. Проверка нужна отдельная:
// у settings.php есть ещё и своя проверка внутри, поэтому убери роль из apiBoot —
// и предыдущий тест всё равно останется зелёным (проверено: так и было).
// Здесь берём эндпоинт, который на apiBoot и полагается: acl.php с role=>admin.
[$code, $json, ] = $call('GET', '/api/acl.php', ['token' => $viewToken]);
check('единый вход сам отсекает недостаточную роль',
    $code === 403 && ($json['code'] ?? '') === 'forbidden', "код $code, ответ: " . json_encode($json, JSON_UNESCAPED_UNICODE));

// 4. Администратор меняет — и это попадает в журнал действий.
// Настройку берём ИЗ СХЕМЫ ПРОЕКТА, а не по имени из шаблона: проект вправе
// убрать пример «greeting», и красным из-за этого он быть не должен.
check('есть поле настроек, на котором проверяются запись и журнал',
    $nastroykaKlyuch !== '');
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $adminToken,
    'body' => json_encode(['settings' => [$nastroykaKlyuch => 'привет']], JSON_UNESCAPED_UNICODE)]);
// Секрет наружу не возвращается по контракту схемы — тогда достаточно кода 200.
check('администратор сохраняет настройку', $code === 200
    && ($secretniyKlyuch || ($json['settings'][$nastroykaKlyuch] ?? '') === 'привет'), "код $code");
$last = journalQuery(['kind' => 'action', 'limit' => 20])['rows'][0] ?? [];
check('изменение настроек записано в журнал с автором',
    ($last['event'] ?? '') === 'settings.update' && (int)($last['uid'] ?? 0) === 10);

// Если каталог журнала сломан, вкладка обязана назвать причину и действие, а не
// провалиться в общий «Внутренняя ошибка приложения».
$logsDir = DATA_ROOT . '/' . LOG_DIR_NAME;
$logsBackup = DATA_ROOT . '/logs-http-test-backup';
@rename($logsDir, $logsBackup);
file_put_contents($logsDir, 'не каталог');
[$code, $json, ] = $call('GET', '/api/logs.php', ['token' => $adminToken]);
@unlink($logsDir);
@rename($logsBackup, $logsDir);
check('НЕДОСТУПНЫЙ КАТАЛОГ ЖУРНАЛА ДАЁТ НАЗВАННЫЙ ОТКАЗ С ДЕЙСТВИЕМ',
    $code === 503 && ($json['code'] ?? '') === 'journal_unavailable'
    && mb_strpos((string)($json['error'] ?? ''), 'data/logs') !== false,
    'код ' . $code . '; ' . json_encode($json, JSON_UNESCAPED_UNICODE));

// 5. Мусор вместо JSON — понятный отказ, а не 500.
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $adminToken, 'body' => '{не json']);
check('нечитаемое тело даёт понятный отказ', $code === 400 && ($json['code'] ?? '') === 'bad_json', "код $code");

// 6. Отсутствующее обязательное поле — тоже 400, а не тихий пропуск.
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $adminToken, 'body' => '{"nothing":1}']);
check('запрос без нужного поля отклоняется', $code === 400, "код $code");

// 6.1. Число длиннее, чем помещается в целое, — отказ, а НЕ молчаливая подмена
//      предельным значением. Раньше «уберите доступ у сотрудника 999…9» (24 знака)
//      превращалось в работу с сотрудником с номером PHP_INT_MAX: запрос отвечал
//      200, в списке доступа появлялась запись с номером, которого на портале нет,
//      а человек был уверен, что выдал доступ тому, кого выбрал (находка ревью
//      09.08.2026, R3-026). То же самое насыщение до предела в обратную сторону
//      давало отрицательное число.
$aclDoBolshogo = json_encode(aclLoad(), JSON_UNESCAPED_UNICODE);
[$code, $json, ] = $call('POST', '/api/acl.php', ['token' => $adminToken,
    'body' => '{"action":"add","kind":"user","uid":"999999999999999999999999","name":"Пере","role":"view"}']);
check('ЧИСЛО СВЕРХ ПРЕДЕЛА ЦЕЛОГО ОТКЛОНЯЕТСЯ, А НЕ ОБРЕЗАЕТСЯ ДО МАКСИМУМА',
    $code === 400 && ($json['code'] ?? '') === 'bad_field', "код $code, ответ: " . json_encode($json, JSON_UNESCAPED_UNICODE));
check('такой запрос не оставил следа в списке доступа',
    json_encode(aclLoad(), JSON_UNESCAPED_UNICODE) === $aclDoBolshogo,
    json_encode(aclLoad(), JSON_UNESCAPED_UNICODE));
[$code, $json, ] = $call('POST', '/api/acl.php', ['token' => $adminToken,
    'body' => '{"action":"delete","kind":"user","uid":"-999999999999999999999999"}']);
check('отрицательное число сверх предела тоже отклоняется',
    $code === 400 && ($json['code'] ?? '') === 'bad_field', "код $code");
// Ровно на границе — по-прежнему допустимо: предохранитель не должен отсекать
// законные значения. PHP_INT_MAX как номер сотрудника нереален, но проверяем
// именно границу — на ней и ломаются такие проверки.
$granica = (string)PHP_INT_MAX;
[$code, $json, ] = $call('POST', '/api/acl.php', ['token' => $adminToken,
    'body' => '{"action":"delete","kind":"user","uid":"' . $granica . '"}']);
check('ровно PHP_INT_MAX через границу проходит (не найден — но разобран)',
    $code !== 400, "код $code, ответ: " . json_encode($json, JSON_UNESCAPED_UNICODE));
[$code, $json, ] = $call('POST', '/api/acl.php', ['token' => $adminToken,
    'body' => '{"action":"delete","kind":"user","uid":"' . $granica . '0"}']);
check('на один разряд больше границы — уже отказ',
    $code === 400 && ($json['code'] ?? '') === 'bad_field', "код $code");

// 6.2. Тот же разбор в СПИСКЕ значений: элементы проверяются поштучно, и
//      огромное число в списке точно так же не должно становиться предельным.
//      Отказ разбора завершает процесс (apiFail → exit), поэтому спрашиваем в
//      отдельном процессе: иначе проверка утащила бы за собой весь прогон.
$razbor = static function (string $vyrazhenie) use ($root): string {
    $probe = sys_get_temp_dir() . '/bxapp-apilist-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, "<?php\nrequire " . var_export($root . '/www/api/http.php', true)
        . ";\necho json_encode($vyrazhenie, JSON_UNESCAPED_UNICODE);\n");
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    @unlink($probe);
    return $out;
};
$spisokOgromnyh = $razbor("apiList(['ids' => ['999999999999999999999999']], 'ids', ['items' => 'int'])");
check('ОГРОМНОЕ ЧИСЛО В СПИСКЕ ОТКЛОНЯЕТСЯ, А НЕ СТАНОВИТСЯ ПРЕДЕЛЬНЫМ',
    strpos($spisokOgromnyh, 'bad_field') !== false && strpos($spisokOgromnyh, (string)PHP_INT_MAX) === false,
    trim($spisokOgromnyh));
$spisokGranica = $razbor("apiList(['ids' => ['" . PHP_INT_MAX . "', '-5']], 'ids', ['items' => 'int'])");
check('граничные значения в списке по-прежнему проходят',
    $spisokGranica === '[' . PHP_INT_MAX . ',-5]', trim($spisokGranica));

// 7. Тело сверх лимита не читается целиком в память.
$huge = '{"settings":{"x":"' . str_repeat('y', 300000) . '"}}';
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $adminToken, 'body' => $huge]);
check('слишком большое тело отклоняется', $code === 413, "код $code");

// 8. Запрос с чужого сайта отклоняется, даже если пропуск верный. Браузер в таком
//    запросе сам проставляет и Sec-Fetch-Site: cross-site, и чужой Origin —
//    подделать их со страницы нельзя, поэтому шлём ровно эту пару.
[$code, $json, ] = $call('GET', '/api/settings.php',
    ['token' => $adminToken, 'site' => 'cross-site', 'origin' => 'https://evil.example']);
check('запрос с чужого сайта отклоняется', $code === 403 && ($json['code'] ?? '') === 'cross_site', "код $code");

// 8.1. Изменение без единого признака источника (ни Sec-Fetch-Site, ни Origin) —
// отказ: так ходит не браузер, а сторонний скрипт с украденным пропуском.
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $adminToken, 'site' => '', 'body' => '{"settings":{"x":1}}']);
check('изменение без признаков источника отклоняется', $code === 403 && ($json['code'] ?? '') === 'cross_site', "код $code");

// 8.2. Запрос с соседнего сайта того же домена (общий хостинг) — тоже чужой.
[$code, $json, ] = $call('POST', '/api/settings.php', ['token' => $adminToken, 'site' => 'same-site', 'body' => '{"settings":{"x":1}}']);
check('изменение с соседнего сайта хостинга отклоняется', $code === 403 && ($json['code'] ?? '') === 'cross_site', "код $code");

// 9. Ограничение частоты: поток запросов упирается в потолок и получает 429.
$longWindowStart = time() - 120;
storeWrite(RATELIMIT_FILE, [
    'same-window' => ['start' => $longWindowStart, 'hits' => 2, 'window' => 3600],
    'other-long'  => ['start' => $longWindowStart, 'hits' => 2, 'window' => 3600],
]);
$sameBucketStillDenied = !apiRateAllow('same-window', 2, 60);
$rateAfterFirstDenial = (string)file_get_contents(RATELIMIT_FILE);
$sameBucketStillDeniedAgain = !apiRateAllow('same-window', 2, 60);
$rateAfterRepeatedDenial = (string)file_get_contents(RATELIMIT_FILE);
check('повторный 429 в том же окне не переписывает счётчик на диске',
    $sameBucketStillDeniedAgain && $rateAfterRepeatedDenial === $rateAfterFirstDenial
    && empty($GLOBALS['__api_rate_first_denial']));
apiRateAllow('short-window', 10, 60);
$afterShortWindow = storeRead(RATELIMIT_FILE) ?? [];
check('короткое окно не сбрасывает длинное окно того же или соседнего bucket',
    $sameBucketStillDenied
    && (int)($afterShortWindow['same-window']['start'] ?? 0) === $longWindowStart
    && (int)($afterShortWindow['same-window']['window'] ?? 0) === 3600
    && isset($afterShortWindow['other-long']));

// BX14-06: число разных bucket задаётся входящими адресами/токенами, поэтому
// очистки только по TTL недостаточно. Проверяем контракт отдельно от больших
// файлов: expired удаляются первыми, затем oldest dynamic; fixed/global bucket
// и текущий запрос не приносятся в жертву churn.
$rateBoundContract = function_exists('apiRateBoundState')
    && function_exists('apiRateStateFits')
    && defined('API_RATE_STATE_MAX_ITEMS')
    && defined('API_RATE_STATE_MAX_BYTES');
check('rate-state объявляет count/serialized-byte/pre-decode bounds', $rateBoundContract);
if ($rateBoundContract) {
    $rateNow = time();
    $rateKept = false;
    $rateBounded = apiRateBoundState([
        'identify-all' => ['start' => $rateNow - 10, 'hits' => 1, 'window' => 60],
        'identify-ip:expired' => ['start' => $rateNow - 120, 'hits' => 1, 'window' => 60],
        'identify-ip:oldest' => ['start' => $rateNow - 30, 'hits' => 1, 'window' => 60],
        'identify-ip:new' => ['start' => $rateNow - 1, 'hits' => 1, 'window' => 60],
    ], 'identify-ip:new', $rateNow, 2, 100000, $rateKept);
    check('rate-state чистит expired, затем oldest, сохраняя fixed/global и current',
        $rateKept && array_keys($rateBounded) === ['identify-all', 'identify-ip:new'],
        json_encode(array_keys($rateBounded), JSON_UNESCAPED_UNICODE));

    $rateOne = ['fixed-global' => ['start' => $rateNow, 'hits' => 1, 'window' => 60]];
    $rateOneBytes = strlen(storeEncode($rateOne));
    check('serialized-byte bound принимает ровно limit и отвергает limit+1',
        apiRateStateFits($rateOne, 1, $rateOneBytes)
        && !apiRateStateFits($rateOne, 1, $rateOneBytes - 1));
    $rateTwo = $rateOne + [
        'identify-ip:count-boundary' => ['start' => $rateNow, 'hits' => 1, 'window' => 60],
    ];
    check('item-count bound принимает ровно limit и очищает limit+1',
        apiRateStateFits($rateTwo, 2, 100000)
        && !apiRateStateFits($rateTwo, 1, 100000));

    // Legacy-файл режется ДО json_decode и остаётся нетронутым. Такой отказ для
    // публичного expensive path fail-closed, а не старый generic fail-open.
    $legacyOversized = BXAPP_PRIVATE_PHP_HEADER
        . str_repeat('X', API_RATE_STATE_MAX_BYTES + 1);
    file_put_contents(RATELIMIT_FILE, $legacyOversized);
    $legacyAllowed = apiRateAllow('identify-ip:legacy-overflow', 120, 60);
    check('oversized legacy rate-state отклоняется до decode без перезаписи',
        !$legacyAllowed && (string)file_get_contents(RATELIMIT_FILE) === $legacyOversized,
        'allowed=' . ($legacyAllowed ? 'yes' : 'no'));
    @unlink(RATELIMIT_FILE); // synthetic legacy fixture больше не участвует в следующих CAS-проверках

    // CAS/concurrency у самой границы: параллельные новые bucket не теряются,
    // файл остаётся в обоих bounds, а fixed/global bucket не вытесняется.
    $nearCap = ['identify-all' => ['start' => $rateNow, 'hits' => 1, 'window' => 3600]];
    for ($i = 0; $i < API_RATE_STATE_MAX_ITEMS - 16; $i++) {
        $nearCap['identify-ip:old-' . $i] = ['start' => $rateNow - 30, 'hits' => 1, 'window' => 3600];
    }
    storeWrite(RATELIMIT_FILE, $nearCap);
    $rateWorker = sys_get_temp_dir() . '/bxapp-rate-worker-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($rateWorker, '<?php' . "\n"
        . 'putenv(\'BXAPP_DATA_ROOT=\' . $argv[1]);' . "\n"
        . 'require ' . var_export($root . '/www/env.php', true) . ';' . "\n"
        . 'require ' . var_export($root . '/www/api/store.php', true) . ';' . "\n"
        . 'require ' . var_export($root . '/www/api/lib.php', true) . ';' . "\n"
        . 'require ' . var_export($root . '/www/api/journal.php', true) . ';' . "\n"
        . 'require ' . var_export($root . '/www/api/http.php', true) . ';' . "\n"
        . '$worker=(int)$argv[2]; for($i=0;$i<8;$i++) {' . "\n"
        . ' if(!apiRateAllow(\'identify-ip:race-\'.$worker.\'-\'.$i, 120, 3600)) exit(3); }' . "\n");
    $rateProcs = []; $ratePipes = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $rateProcs[$worker] = proc_open(
            [PHP_BINARY, $rateWorker, DATA_ROOT, (string)$worker], $spec, $ratePipes[$worker], $root
        );
    }
    $rateWorkersOk = true;
    foreach ($rateProcs as $worker => $proc) {
        if (!is_resource($proc)) { $rateWorkersOk = false; continue; }
        stream_get_contents($ratePipes[$worker][1]);
        stream_get_contents($ratePipes[$worker][2]);
        fclose($ratePipes[$worker][1]); fclose($ratePipes[$worker][2]);
        if (proc_close($proc) !== 0) $rateWorkersOk = false;
    }
    @unlink($rateWorker);
    $afterRateRace = storeRead(RATELIMIT_FILE) ?? [];
    $allRaceBuckets = true;
    for ($worker = 0; $worker < 4; $worker++) for ($i = 0; $i < 8; $i++) {
        if (!isset($afterRateRace['identify-ip:race-' . $worker . '-' . $i])) $allRaceBuckets = false;
    }
    check('rate-state CAS под concurrent churn не теряет новые bucket и держит bounds',
        $rateWorkersOk && $allRaceBuckets && isset($afterRateRace['identify-all'])
        && apiRateStateFits($afterRateRace, API_RATE_STATE_MAX_ITEMS, API_RATE_STATE_MAX_BYTES),
        'workers=' . ($rateWorkersOk ? 'ok' : 'failed') . ', entries=' . count($afterRateRace)
            . ', bytes=' . strlen(storeEncode($afterRateRace)));
}

// SEC-CAN-02: отказ самого limiter не вправе одновременно отключать ceiling и
// создавать по incident-строке в каждом новом PHP request. Проверяем реальные
// независимые процессы; policy детерминирован: deny и ноль новых log records.
$rateFailureCode = 'putenv("BXAPP_DATA_ROOT=".$argv[1]);require '
    . var_export($root . '/www/api/http.php', true)
    . ';echo apiRateAllow("security-failure",120,60)?"1":"0";';
$runRateFailure = static function () use ($rateFailureCode, $root): array {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $rateFailureCode, DATA_ROOT], $desc, $pipes, $root);
    if (!is_resource($proc)) return [255, '', 'process'];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};
$rateLogSnapshot = static function (): array {
    $snapshot = [];
    foreach (glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: [] as $file) {
        if (is_file($file)) $snapshot[basename($file)] = hash_file('sha256', $file);
    }
    ksort($snapshot);
    return $snapshot;
};
file_put_contents(RATELIMIT_FILE, 'synthetic-corrupt-rate-state');
$corruptHash = hash_file('sha256', RATELIMIT_FILE);
$logsBeforeCorrupt = $rateLogSnapshot();
$corruptOne = $runRateFailure();
$corruptTwo = $runRateFailure();
$corruptBackups = glob(preg_replace('/\.php$/', '', RATELIMIT_FILE) . '.bad-*.php') ?: [];
check('SEC-CAN-02 corrupt rate-state в разных процессах fail-closed с bounded logging',
    $corruptOne === [0, '0', ''] && $corruptTwo === [0, '0', '']
    && hash_file('sha256', RATELIMIT_FILE) === $corruptHash
    && count($corruptBackups) === 1 && $rateLogSnapshot() === $logsBeforeCorrupt,
    json_encode(['one' => $corruptOne[0], 'two' => $corruptTwo[0],
        'backups' => count($corruptBackups), 'logs_changed' => $rateLogSnapshot() !== $logsBeforeCorrupt]));
@unlink(RATELIMIT_FILE);
foreach ($corruptBackups as $corruptBackup) @unlink($corruptBackup);

storeWrite(RATELIMIT_FILE, ['fixed' => ['start' => time(), 'hits' => 1, 'window' => 60]]);
$lockStateHash = hash_file('sha256', RATELIMIT_FILE);
$logsBeforeLock = $rateLogSnapshot();
$rateLock = fopen(RATELIMIT_FILE . '.lock.php', 'c+b');
$rateLockHeld = is_resource($rateLock) && flock($rateLock, LOCK_EX);
$lockFailure = $rateLockHeld ? $runRateFailure() : [255, '', 'lock'];
if ($rateLockHeld) flock($rateLock, LOCK_UN);
if (is_resource($rateLock)) fclose($rateLock);
$lockPreserved = hash_file('sha256', RATELIMIT_FILE) === $lockStateHash;
@unlink(RATELIMIT_FILE);
mkdir(RATELIMIT_FILE, 0700);
$ioStatBefore = lstat(RATELIMIT_FILE);
$ioOne = $runRateFailure();
$ioTwo = $runRateFailure();
$ioReasonAllowed = apiRateAllow('security-failure-reason', 120, 60);
$ioReason = (string)($GLOBALS['__api_rate_failure'] ?? '');
$ioStatAfter = lstat(RATELIMIT_FILE);
$ioPreserved = is_array($ioStatBefore) && is_array($ioStatAfter)
    && ($ioStatBefore['dev'] ?? null) === ($ioStatAfter['dev'] ?? null)
    && ($ioStatBefore['ino'] ?? null) === ($ioStatAfter['ino'] ?? null);
@rmdir(RATELIMIT_FILE);
check('SEC-CAN-02 lock/I-O failures сохраняют protective ceiling и не размножают logs',
    $rateLockHeld && $lockFailure === [0, '0', ''] && $lockPreserved
    && $ioOne === [0, '0', ''] && $ioTwo === [0, '0', ''] && $ioPreserved
    && $ioReasonAllowed === false && $ioReason === 'storage'
    && $rateLogSnapshot() === $logsBeforeLock,
    json_encode(['lock' => $lockFailure[0], 'io' => [$ioOne[0], $ioTwo[0]],
        'logs_changed' => $rateLogSnapshot() !== $logsBeforeLock]));

$identifySource = (string)file_get_contents($root . '/www/index.php');
check('public identify использует атомарную цепочку global, IP и token buckets',
    (bool)preg_match(
        '~\$identifyOk\s*=\s*apiRateAllowChain\(\[\s*\[\'identify-all\'.*?\[\'identify-ip:.*?\[\'identify-who:~s',
        $identifySource
    ));
check('public identify отличает поломку rate-state от честного минутного 429',
    strpos($identifySource, "\$identifyRateFailure = (string)(\$GLOBALS['__api_rate_failure'] ?? '')") !== false
    && preg_match('~if \(\$identifyRateFailure !== \'\'\).*?Защитное хранилище недоступно.*?\[\'code\' => 503~s',
        $identifySource) === 1
    && strpos($identifySource, "if (apiRateAllow('identify-deny-log', 10, 60))") !== false);

$httpSource = (string)file_get_contents($root . '/www/api/http.php');
$healthSource = (string)file_get_contents($root . '/www/api/health.php');
$logsSource = (string)file_get_contents($root . '/www/api/logs.php');
check('сбой rate-state отличается от 429 и доступен только admin-диагностике',
    strpos($httpSource, "'rate_state_unavailable'") !== false
    && substr_count($httpSource, "'rate_failure' => 'diagnostic'") === 0
    && strpos($healthSource, "'rate_failure' => 'diagnostic'") !== false
    && strpos($logsSource, "'rate_failure' => 'diagnostic'") !== false
    && strpos($healthSource, "RATELIMIT_FILE, 'счётчик частоты'") !== false
    && strpos($httpSource, "\$needRole !== 'admin'") !== false
    && strpos($httpSource, "!in_array(\$method, ['GET', 'HEAD'], true)") !== false
    && strpos($httpSource, "!\$needSession") !== false);

// Root-controller защищён canonical content hash в gate_test. BX14-06 меняет
// именно index.php, поэтому его focused-набор обязан сам доказать, что hash был
// осознанно пересчитан, а не оставить сюрприз только combined-прогону.
$gateSource = (string)file_get_contents($root . '/tests/gate_test.php');
$protectedIndexHash = [];
preg_match("~\\\$expectedIndexSha256\\s*=\\s*'([a-f0-9]{64})';~", $gateSource, $protectedIndexHash);
$actualProtectedIndexHash = hash_file('sha256', $root . '/www/index.php');
check('BX14-06 focused синхронизирует canonical protected hash index.php',
    isset($protectedIndexHash[1]) && is_string($actualProtectedIndexHash)
        && hash_equals($protectedIndexHash[1], $actualProtectedIndexHash),
    'trusted=' . (string)($protectedIndexHash[1] ?? 'missing')
        . ', actual=' . (string)$actualProtectedIndexHash);

storeWrite(RATELIMIT_FILE, []);
$limited = false;
for ($i = 0; $i < API_RATE_LIMIT + 5; $i++) {
    [$code, , ] = $call('POST', '/api/settings.php', ['token' => $adminToken, 'body' => '{"settings":{"n":' . $i . '}}']);
    if ($code === 429) { $limited = true; break; }
}
check('поток изменяющих запросов упирается в ограничение частоты', $limited);

// 9.1. Чтения лимитом не режутся: их шлёт каждая открытая вкладка пачками, и
// счётчик на них выстраивал бы приложение в очередь на замке файла.
[$code, , ] = $call('GET', '/api/settings.php', ['token' => $viewToken]);
check('чтение проходит даже после исчерпания лимита на изменения', $code === 200, "код $code");

// ── Страница приложения собирается целиком ───────────────────────────────
// Счётчик частоты обнуляем: предыдущий блок намеренно упёрся в лимит, и без
// сброса эти проверки мерили бы его, а не страницу.
storeWrite(RATELIMIT_FILE, []);
// Проверяем то, что реально увидит человек: подключён ли готовый слой фронта,
// проставлен ли кэш-бастер (без него правку видно только после жёсткой
// перезагрузки, которой внутри портала у человека нет) и знает ли фронт роль.
[$code, , $page, $head] = $call('GET', '/index.php', ['cookie' => SESSION_COOKIE . '=' . $adminToken]);
check('страница приложения отдаётся', $code === 200, "код $code");
check('страница не кэшируется', stripos($head, 'no-store') !== false);
check('подключён общий слой оформления и компонентов',
    strpos($page, 'css/tokens.css?v=') !== false && strpos($page, 'css/components.css?v=') !== false);
check('подключены готовые диалоги и запросы фронта', strpos($page, 'js/ui.js?v=') !== false);
// Кэш-бастер обязан стоять у КАЖДОГО файла фронта и совпадать со временем правки:
// иначе человек после правки видит вчерашнюю версию, а внутри портала жёсткой
// перезагрузки у него нет.
// Список файлов фронта берём с диска, а не вписываем сюда руками: вписанный
// список устаревает молча (добавили файл — он остался непроверенным), да ещё и
// выглядит для внешних проверок как разметка со ссылками на ассеты.
$freshBust = true;
$assets = array_merge(glob($root . '/www/js/*.js') ?: [], glob($root . '/www/css/*.css') ?: []);
foreach ($assets as $assetPath) {
    $rel   = ltrim(str_replace($root . '/www', '', $assetPath), '/');
    $mtime = (string)@filemtime($assetPath);
    if (strpos($page, $rel . '?v=' . $mtime) === false) { $freshBust = false; break; }
}
check('у каждого файла фронта метка версии по времени его правки', $freshBust,
    'иначе правка не доедет до человека — он увидит кэш');
check('фронт знает роль зрителя', strpos($page, 'window.APP_ROLE') !== false);
// Раздел доступа обязателен всегда: без него владелец не выдаст права никому.
// Служебные «Состояние» и «Журнал» приложение вправе не показывать (в шаблоне
// раздел состояния убран 26.08.2026, два его действия переехали в «Служебное»
// внутри настроек) — но убирать их надо ЦЕЛИКОМ, вместе с кодом: половина
// удалённого экрана ломается молча.
check('раздел доступа есть в разметке, а служебные экраны либо есть целиком, либо убраны целиком',
    strpos($page, 'data-pane="access"') !== false
    && (strpos($page, 'data-pane="health"') !== false) === (strpos($page, 'health-facts') !== false)
    && (strpos($page, 'data-pane="log"') !== false) === (strpos($page, 'log-table') !== false));

// Состояние приложения отвечает и называет проблемы человеческим языком.
[$code, $json, ] = $call('GET', '/api/health.php', ['token' => $adminToken]);
check('экран состояния отвечает администратору', $code === 200 && isset($json['problems']), "код $code");
check('состояние знает, установлено ли приложение', isset($json['portal']['установлено']));
[$code, , ] = $call('GET', '/api/health.php', ['token' => $viewToken]);
check('состояние закрыто от обычного сотрудника', $code === 403, "код $code");

// Сервер убирает за собой сам (см. testServer в tests/lib.php).

// ── Залипшую операцию человек снимает с экрана, а не из командной строки ─────
// Круг 2 ревью 06.08.2026. Операция с внешним эффектом (счёт, перевод) идёт через
// runOnce(); оборвался процесс между работой и записью «сделано» — ключ остаётся
// в статусе unknown. Автоматически повторять нельзя, снять умела только командная
// строка сервера, а экран советовал «повторите действие»: администратору портала
// совет был неисполним, операции копились, а с ними рос файл журнала операций —
// до предела, на котором отказывают уже ВСЕ мутации.
storeWrite(OPS_FILE, [
    'deal-42'  => ['status' => 'unknown', 'at' => time(), 'error' => 'связь оборвалась'],
    'deal-7'   => ['status' => 'done', 'at' => time(), 'result' => ['id' => 7], 'ttl' => 600],
    'deal-99'  => ['status' => 'running', 'at' => time(), 'attempt' => 'x'],
]);
[$code, $json, ] = $call('POST', '/api/ops.php', ['token' => $viewToken, 'body' => json_encode(['key' => 'deal-42'], JSON_UNESCAPED_UNICODE)]);
check('снять операцию может только администратор приложения', $code === 403, "код $code");

[$code, $json, ] = $call('POST', '/api/ops.php', ['token' => $adminToken, 'body' => json_encode(['key' => ''], JSON_UNESCAPED_UNICODE)]);
check('без ключа операции — понятный отказ',
    $code === 400 && ($json['code'] ?? '') === 'key_required', "код $code");

// Экран снятия unknown-ключа допускает увеличенный recovery-предел: иначе
// переполненный обычный лимитом журнал нельзя починить именно тогда, когда
// мутации уже остановлены. Абсолютный предел он всё равно проверяет до чтения.
$opsNormal = storeRead(OPS_FILE) ?? [];
storeWrite(OPS_FILE, ['oversized' => [
    'status' => 'unknown', 'at' => time(), 'padding' => str_repeat('x', RUN_ONCE_MAX_BYTES + 1024),
]]);
[$code, $json, ] = $call('POST', '/api/ops.php', [
    'token' => $adminToken, 'body' => json_encode(['key' => 'oversized'], JSON_UNESCAPED_UNICODE),
]);
check('ADMIN RECOVERY ОТКРЫВАЕТ ЖУРНАЛ ВЫШЕ ОБЫЧНОГО ЛИМИТА',
    $code === 200 && ($json['ok'] ?? false) === true,
    'код ' . $code . '; ' . json_encode($json, JSON_UNESCAPED_UNICODE));
storeWrite(OPS_FILE, ['oversized' => [
    'status' => 'unknown', 'at' => time(), 'padding' => str_repeat('x', RUN_ONCE_MAX_BYTES * 8 + 1024),
]]);
[$code, $json, ] = $call('POST', '/api/ops.php', [
    'token' => $adminToken, 'body' => json_encode(['key' => 'oversized'], JSON_UNESCAPED_UNICODE),
]);
check('ЖУРНАЛ ВЫШЕ RECOVERY-ПРЕДЕЛА ОТКЛОНЯЕТСЯ ДО ЧТЕНИЯ ЦЕЛИКОМ',
    $code === 503 && ($json['code'] ?? '') === 'ops_too_large',
    'код ' . $code . '; ' . json_encode($json, JSON_UNESCAPED_UNICODE));
storeWrite(OPS_FILE, $opsNormal);

[$code, $json, ] = $call('POST', '/api/ops.php', ['token' => $adminToken, 'body' => json_encode(['key' => 'deal-7'], JSON_UNESCAPED_UNICODE)]);
check('завершённую операцию снимать нечего — отказ с причиной',
    $code === 409 && ($json['code'] ?? '') === 'not_unknown', "код $code");

[$code, $json, ] = $call('POST', '/api/ops.php', ['token' => $adminToken, 'body' => json_encode(['key' => 'deal-99'], JSON_UNESCAPED_UNICODE)]);
check('идущую операцию снять нельзя — её callback работает с порталом',
    $code === 409, "код $code");

[$code, $json, ] = $call('POST', '/api/ops.php', ['token' => $adminToken, 'body' => json_encode(['key' => 'deal-42'], JSON_UNESCAPED_UNICODE)]);
$opsAfter = storeRead(OPS_FILE) ?? [];
check('операция с неизвестным исходом снимается по кнопке',
    $code === 200 && !array_key_exists('deal-42', $opsAfter)
    && array_key_exists('deal-7', $opsAfter) && array_key_exists('deal-99', $opsAfter),
    "код $code, осталось: " . implode(', ', array_keys($opsAfter)));

// Повтор по уже снятому ключу — не ошибка: экран мог отстать от соседней вкладки.
[$code, $json, ] = $call('POST', '/api/ops.php', ['token' => $adminToken, 'body' => json_encode(['key' => 'deal-42'], JSON_UNESCAPED_UNICODE)]);
check('повторное снятие того же ключа не выглядит поломкой', $code === 200, "код $code");

// А экран обязан отдавать ключи отдельным полем — по ним фронт и рисует кнопку.
[$code, $json, ] = $call('GET', '/api/health.php', ['token' => $adminToken]);
check('экран «Состояние» отдаёт ключи зависших операций для кнопки',
    $code === 200 && array_key_exists('stuck_ops', $json), "код $code");
storeWrite(OPS_FILE, []);

// ── Отказ восстановления виден человеку и журналу, а не «пустой 500» ─────────
// Круг 2 ревью 06.08.2026. Восстановление оборванной транзакции состояния идёт
// при ЗАГРУЗКЕ api/lib.php — раньше, чем точка входа успевает поставить свой
// обработчик ошибок. Пока здесь не было своего перехвата, отказ давал белый
// экран с 500 и НИ ОДНОЙ строки в журнале: самый тяжёлый случай был ещё и самым
// немым. Проверяем на живом сервере, а не рассуждением.
$manifestPath = DATA_ROOT . '/state-transition.php';
file_put_contents($manifestPath,
    "<?php http_response_code(404); exit; ?>\n{\"tx\":\"битый манифест\",\"operations\":\"не массив\"}");
[$code, , $body] = $call('GET', '/index.php', ['cookie' => SESSION_COOKIE . '=' . $adminToken]);
check('отказ восстановления показывает человеку понятный экран, а не пустой 500',
    $code === 503 && strpos((string)$body, 'восстанавливается после сбоя') !== false,
    'код ' . $code);
check('в тексте есть, что делать дальше',
    strpos((string)$body, 'откройте приложение заново') !== false);
@unlink($manifestPath);
foreach (glob(DATA_ROOT . '/state-transition.bad-*.php') ?: [] as $badCopy) @unlink($badCopy);
// Стор снова здоров: следующая проверка обязана видеть обычную страницу.
[$code, , ] = $call('GET', '/index.php', ['cookie' => SESSION_COOKIE . '=' . $adminToken]);
check('после уборки битого манифеста приложение открывается как обычно', $code === 200, "код $code");

// ── Внешний вызов: повтор при отказе, который сервис просит повторить ─────
//
// Рассуждением такое не проверяется: «повторяем 429» и «повторяем ровно столько,
// сколько попросили» — разные вещи, и вторая ловится только настоящим сервисом,
// который сначала отказывает, а потом отвечает. Поэтому поднимаем свой.
suite('Внешний вызов с повтором');

$stubDir = DATA_ROOT . '/http-stub';
$GLOBALS['bxappStubDir'] = $stubDir;
@mkdir($stubDir, 0700, true);
file_put_contents($stubDir . '/index.php', <<<'STUB'
<?php
// Заглушка внешнего сервиса: считает обращения по каждому сценарию и ведёт себя
// так, как ведут себя настоящие API под нагрузкой.
$mode = (string)($_GET['mode'] ?? 'ok');
// Счётчик лежит РЯДОМ С ЗАГЛУШКОЙ, а не в общем /tmp: у каждого прогона свой
// каталог состояния, и два параллельных прогона больше не считают чужие
// обращения. Прежде общий файл давал то ложный красный, то ложный зелёный —
// причём воспроизводилось это только при одновременном запуске.
$counterFile = __DIR__ . '/count-' . preg_replace('/\W+/', '', $mode) . '.txt';
$n = (int)@file_get_contents($counterFile) + 1;
file_put_contents($counterFile, (string)$n);
header('Content-Type: application/json');
if ($mode === 'busy' && $n === 1) {
    // Просим подождать секунду — ровно как переполненный сервис.
    http_response_code(429);
    header('Retry-After: 1');
    echo json_encode(['error' => ['message' => 'слишком часто']]);
    exit;
}
if ($mode === 'broken' && $n <= 2) {
    http_response_code(503);
    echo json_encode(['error' => ['message' => 'сервис недоступен']]);
    exit;
}
if ($mode === 'always500') {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'внутренняя ошибка']]);
    exit;
}
if ($mode === 'huge') {
    // Тело заведомо больше потолка вызывающего (ревью 23.09.2026, otkazy-4).
    echo json_encode(['ok' => true, 'хвост' => str_repeat('x', 300000)]);
    exit;
}
if ($mode === 'hang') {
    // Соединение установлено, ответа нет. Самый неприятный вид отказа: сервис
    // не отказывает, а МОЛЧИТ, и вызывающий висит вместе с ним, занимая воркер.
    // Таймаут соединения тут не спасает — он уже пройден.
    sleep(30);
    echo json_encode(['ok' => true, 'поздно' => true]);
    exit;
}
echo json_encode(['ok' => true, 'обращений' => $n]);
STUB
);
$stubPort = testFreePort();
$stubDesc = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
// `timeout` — аварийный клапан: если набор снимут по SIGKILL, shutdown-функция
// ниже не выполнится и заглушка останется висеть сиротой (см. tests/lib.php).
$stub = proc_open(['timeout', '-k', '5', (string)((int)(getenv('BXAPP_TEST_SERVER_TTL') ?: 900)),
    'php', '-S', '127.0.0.1:' . $stubPort, '-t', $stubDir],
    $stubDesc, $stubPipes, $stubDir);
register_shutdown_function(static function () use (&$stub): void {
    if (is_resource($stub)) { @proc_terminate($stub); @proc_close($stub); $stub = null; }
});
$stubUp = false;
for ($i = 0; $i < 100 && is_resource($stub); $i++) {
    $c = @fsockopen('127.0.0.1', $stubPort, $e1, $e2, 0.2);
    if ($c) { fclose($c); $stubUp = true; break; }
    usleep(50000);
}
$stubUrl = static fn(string $mode): string => 'http://127.0.0.1:' . $stubPort . '/index.php?mode=' . $mode;
$counterOf = static fn(string $mode): string => $GLOBALS['bxappStubDir'] . '/count-' . $mode . '.txt';
// Заглушка живёт на 127.0.0.1 по открытому http и нестандартному порту — то
// есть ровно на том, что блок с 09.08.2026 запрещает по умолчанию (BXREV-012).
// Разрешаем осознанно и ЯВНО: опт-ин обязан быть виден в коде вызывающего, а не
// зашит в сам блок.
$mestnyy = ['allowInsecure' => true, 'allowPrivate' => true, 'allowPorts' => [$stubPort]];
foreach (['busy', 'broken', 'always500', 'huge'] as $mode) {
    @unlink($counterOf($mode));
}

check('заглушка внешнего сервиса поднялась', $stubUp);

// Отказ здесь — это провалённая проверка, а не конец набора: неповторённый 429
// прилетает исключением, и без ловли он оборвал бы все следующие проверки.
$startedAt = microtime(true);
$busyAnswer = [];
try { if ($stubUp) $busyAnswer = httpJson('GET', $stubUrl('busy'), $mestnyy); }
catch (RuntimeException $e) { $busyAnswer = ['сорвалось' => $e->getMessage()]; }
$busyWaited = microtime(true) - $startedAt;
check('отказ «слишком часто» повторяется сам и заканчивается ответом',
    ($busyAnswer['ok'] ?? false) === true && (int)($busyAnswer['обращений'] ?? 0) === 2,
    json_encode($busyAnswer, JSON_UNESCAPED_UNICODE));
check('пауза берётся из ответа сервиса, а не из своей лестницы',
    $busyWaited >= 1.0 && $busyWaited < 2.5, 'ждали ' . round($busyWaited, 2) . ' с');

$brokenAnswer = [];
try { if ($stubUp) $brokenAnswer = httpJson('GET', $stubUrl('broken'), $mestnyy); }
catch (RuntimeException $e) { $brokenAnswer = ['сорвалось' => $e->getMessage()]; }
check('временный сбой сервиса переживается повторами',
    (int)($brokenAnswer['обращений'] ?? 0) === 3, json_encode($brokenAnswer, JSON_UNESCAPED_UNICODE));

// Изменяющий запрос после 5xx НЕ повторяется: ответ мог потеряться уже после
// того, как сервис всё сделал, и повтор создаст вторую запись.
@unlink($counterOf('always500'));
$mutationFailed = null;
try { httpJson('POST', $stubUrl('always500'), ['body' => '{}'] + $mestnyy); }
catch (RuntimeException $e) { $mutationFailed = $e->getMessage(); }
$mutationTries = (int)@file_get_contents($counterOf('always500'));
check('изменяющий запрос после сбоя не повторяется вслепую',
    $mutationFailed !== null && $mutationTries === 1, 'обращений: ' . $mutationTries);

// А с явным разрешением вызывающего — повторяется: он знает, что операция
// идемпотентна (например, «поставить значение», а не «прибавить»).
@unlink($counterOf('always500'));
try { httpJson('POST', $stubUrl('always500'), ['body' => '{}', 'safeToRepeat' => true, 'budget' => 9] + $mestnyy); }
catch (RuntimeException $e) { /* ожидаемо: сервис отказывает всегда */ }
$repeatTries = (int)@file_get_contents($counterOf('always500'));
check('явно идемпотентный запрос повторяется ограниченное число раз',
    $repeatTries > 1 && $repeatTries <= 4, 'обращений: ' . $repeatTries);

// Ответ больше потолка — причина детерминированная: второй такой же запрос не
// даст тела меньше. Прежде обрыв по своему потолку был неотличим от сетевого
// сбоя, GET повторялся ещё трижды (до 20 МБ и 7 с сна в веб-запросе), а текст
// был «Callback aborted» (ревью 23.09.2026, otkazy-4).
$hugeError = null;
$hugeStarted = microtime(true);
try { if ($stubUp) httpJson('GET', $stubUrl('huge'), ['maxBytes' => 100000] + $mestnyy); }
catch (RuntimeException $e) { $hugeError = $e->getMessage(); }
$hugeTries = (int)@file_get_contents($counterOf('huge'));
check('ответ больше потолка не повторяется и назван понятно',
    !$stubUp || ($hugeTries === 1 && $hugeError !== null && mb_strpos($hugeError, 'больше предела') !== false),
    'обращений: ' . $hugeTries . ', ждали ' . round(microtime(true) - $hugeStarted, 1) . ' с, текст: ' . (string)$hugeError);

// Срок из заголовка понимается и датой, и числом — иначе просьба «подождать до
// 12:03:15» превращается в повтор через секунду.
// ЗАВИСШЕЕ СОЕДИНЕНИЕ. Один из восьми дефектов, встреченных по всему парку:
// таймаут соединения стоит, а срока на ОТВЕТ нет — и вызов висит, пока не
// кончится жизнь воркера. Проверяем не наличие настройки, а поведение: сколько
// на самом деле ждали и чем закончилось.
$hangStarted = microtime(true);
$hangError = null;
$hangAnswer = null;
try {
    if ($stubUp) $hangAnswer = httpJson('GET', $stubUrl('hang'), ['timeout' => 2, 'budget' => 6] + $mestnyy);
} catch (RuntimeException $e) { $hangError = $e->getMessage(); }
$hangWaited = microtime(true) - $hangStarted;
check('МОЛЧАЩИЙ СЕРВИС НЕ ДЕРЖИТ ВЫЗОВ БЕСКОНЕЧНО',
    $stubUp ? ($hangError !== null && $hangAnswer === null) : true,
    'ждали ' . round($hangWaited, 1) . ' с, ответ: ' . json_encode($hangAnswer, JSON_UNESCAPED_UNICODE));
check('ожидание укладывается в общий срок, а не в сумму попыток',
    !$stubUp || $hangWaited < 12, 'ждали ' . round($hangWaited, 1) . ' с');
check('человеку сказано, что случилось, а не «пустой ответ»',
    !$stubUp || ($hangError !== null && trim($hangError) !== ''), (string)$hangError);

check('срок ожидания читается числом секунд', httpRetryAfterSeconds("Retry-After: 7\r\n") === 7);
$asDate = httpRetryAfterSeconds('Retry-After: ' . gmdate('D, d M Y H:i:s', time() + 5) . " GMT\r\n");
check('срок ожидания читается датой', $asDate !== null && $asDate >= 3 && $asDate <= 6, (string)$asDate);
check('без заголовка срок не выдумывается', httpRetryAfterSeconds("Content-Type: application/json\r\n") === null);

// ── ОПЕЧАТКА В ТРЕБУЕМОЙ РОЛИ ────────────────────────────────────────────────
// Роли в приложении три: view, edit, admin. Опечатка или скопированный из доки
// пример вроде 'user' раньше закрывал эндпоинт МОЛЧА: сравнение ролей честно
// отвечало «не хватает прав» всем подряд, включая владельца. Разработчик видел
// 403 у себя и искал ошибку в правах, а дело было в одной строке настройки.
$brokenEndpoint = <<<'PHP_FIXTURE'
<?php
require_once __DIR__ . '/api/http.php';
$ctx = apiBoot(['methods' => ['GET'], 'role' => 'user']);
apiOk(['dошли' => true]);
PHP_FIXTURE;
$goodEndpoint = <<<'PHP_FIXTURE'
<?php
require_once __DIR__ . '/api/http.php';
$ctx = apiBoot(['methods' => ['GET'], 'role' => 'view']);
apiOk(['дошли' => true]);
PHP_FIXTURE;

$srv2 = testServer([], ['fixtures' => [
    'zz-rol-opechatka.php' => $brokenEndpoint,
    'zz-rol-vernaya.php'   => $goodEndpoint,
]]);
if ($srv2['ok']) {
    $call2 = $srv2['call'];
    [$c1, $j1, $b1] = $call2('GET', '/zz-rol-opechatka.php', ['token' => $adminToken]);
    check('ОПЕЧАТКА В РОЛИ НЕ ВЫГЛЯДИТ КАК ОТКАЗ В ПРАВАХ',
        $c1 !== 403, "код $c1 · " . mb_substr($b1, 0, 120));
    check('про ошибку настройки сказано отдельным видом отказа',
        ($j1['code'] ?? '') !== 'forbidden' && ($j1['ok'] ?? null) === false,
        mb_substr($b1, 0, 160));
    [$c2, $j2, $b2] = $call2('GET', '/zz-rol-vernaya.php', ['token' => $viewToken]);
    check('правильная роль по-прежнему пропускает', $c2 === 200 && ($j2['ok'] ?? null) === true,
        "код $c2 · " . mb_substr($b2, 0, 120));
}

// ── Готовый HTTP-блок безопасен ПО УМОЛЧАНИЮ (BXREV-012) ───────────────────
// Находка ревью 08.08.2026: блок проверял, по сути, схему и наличие хоста.
// Адрес из настройки или из ответа чужого сервиса уводил запрос внутрь машины
// (127.0.0.1, 169.254.169.254, локальная сеть), тащил пароль в user:pass@,
// уходил на нестандартный порт — а токен интеграции уезжал туда же.
$otkaz = static function (callable $chto): string {
    try { $chto(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
check('ЗАПРОС ВНУТРЬ МАШИНЫ ОТКЛОНЯЕТСЯ БЕЗ ЯВНОГО РАЗРЕШЕНИЯ',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'https://127.0.0.1/api')), 'внутренний адрес') !== false,
    $otkaz(static fn() => httpJson('GET', 'https://127.0.0.1/api')));
check('адрес метаданных облака тоже внутренний и тоже отклоняется',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'https://169.254.169.254/latest/meta-data/')), 'внутренний адрес') !== false);
check('открытый http без явного разрешения отклоняется',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'http://example.com/api')), 'открытый http запрещён') !== false);
check('ЛОГИН И ПАРОЛЬ В АДРЕСЕ ОТКЛОНЯЮТСЯ — ОНИ УТЕКАЮТ В ЖУРНАЛЫ',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'https://user:secret@example.com/api')), 'логин и пароль') !== false);
check('нестандартный порт без явного разрешения отклоняется',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'https://example.com:8443/api')), 'нестандартный порт') !== false);
check('несуществующее имя отклоняется с внятной причиной, а не «ошибкой сети»',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'https://ne-suschestvuet-' . bin2hex(random_bytes(6)) . '.invalid/api')),
        'не разрешается в адрес') !== false);
// Разрешения — точечные: одно не открывает остальные.
check('разрешение на внутренний адрес НЕ разрешает заодно открытый http',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'http://127.0.0.1/api', ['allowPrivate' => true])),
        'открытый http запрещён') !== false);
check('разрешение на http НЕ разрешает заодно внутренний адрес',
    mb_strpos($otkaz(static fn() => httpJson('GET', 'http://127.0.0.1/api', ['allowInsecure' => true])),
        'внутренний адрес') !== false);
// Перенаправления не ходим: 302 на внутренний адрес обнулил бы всю проверку.
$libSrcHttp = (string)file_get_contents(dirname(__DIR__) . '/www/api/lib.php');
check('блок не ходит по перенаправлениям',
    strpos($libSrcHttp, 'CURLOPT_FOLLOWLOCATION => false') !== false
    && strpos($libSrcHttp, 'CURLOPT_MAXREDIRS      => 0') !== false);
check('запрос идёт на ПРОВЕРЕННЫЙ адрес, а не на повторно разрешённое имя',
    strpos($libSrcHttp, 'CURLOPT_RESOLVE') !== false);
check('у ответа есть потолок размера, и он обрывает чтение, а не считает после',
    strpos($libSrcHttp, 'CURLOPT_PROGRESSFUNCTION') !== false && strpos($libSrcHttp, '$maxBytes') !== false);
