<?php
declare(strict_types=1);

suite('Лимиты готовых модулей: журнал и CSV');

$resourceTemplateRoot = dirname(__DIR__);
require_once $resourceTemplateRoot . '/www/api/export-csv.php';
require_once $resourceTemplateRoot . '/tests/gate-lib.php';

/** Запустить изолированную low-memory проверку и вернуть её JSON-результат. */
$runResourceProbe = static function (string $code, string $memory = '16M') use ($resourceTemplateRoot): array {
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=' . $memory, '-r', $code],
        $desc,
        $pipes,
        $resourceTemplateRoot
    );
    if (!is_resource($proc)) return ['rc' => 255, 'json' => null, 'err' => 'proc_open failed'];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return ['rc' => $rc, 'json' => json_decode($out, true), 'err' => trim($err)];
};

$resourceChildPrelude = <<<'PHP'
$tmp = sys_get_temp_dir() . '/bxapp-resource-' . bin2hex(random_bytes(5));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) exit(90);
putenv('BXAPP_DATA_ROOT=' . $tmp);
$wipe = static function (string $path) use (&$wipe): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $wipe($path . '/' . $name);
    }
    @rmdir($path);
};
register_shutdown_function(static function () use (&$wipe, $tmp): void { $wipe($tmp); });
PHP;

// Одна огромная запись превращается в валидный marker, а несколько процессов
// одновременно не пробивают cap и не теряют записи при rename-ротации.
$journalWriteCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('LOG_DAY_MAX_BYTES', 2048);
define('LOG_RECORD_MAX_BYTES', 1024);
define('LOG_SCAN_LINE_MAX_BYTES', 4096);
define('LOG_DAY_MAX_FILES', 100);
require $root . '/www/api/journal.php';

appLog('info', 'oversized.event', ['blob' => str_repeat('Я', 50000)], ['msg' => 'huge']);
$first = journalQuery(['limit' => 10]);
$marker = $first['rows'][0] ?? [];

$workers = [];
for ($worker = 0; $worker < 6; $worker++) {
    $workerCode = '$root=' . var_export($root, true) . ';'
        . "define('LOG_DAY_MAX_BYTES',2048);define('LOG_RECORD_MAX_BYTES',1024);"
        . "define('LOG_SCAN_LINE_MAX_BYTES',4096);define('LOG_DAY_MAX_FILES',100);"
        . "require \$root.'/www/api/journal.php';"
        . '$worker=' . $worker . ';'
        . "for(\$i=0;\$i<40;\$i++) appLog('info','race.'.\$worker.'.'.\$i,[],['msg'=>str_repeat('x',120)]);";
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $workerCode], $desc, $pipes, $root);
    if (!is_resource($proc)) exit(91);
    fclose($pipes[0]);
    $workers[] = [$proc, $pipes];
}
$workersOk = true;
foreach ($workers as [$proc, $pipes]) {
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $out !== '' || $err !== '') $workersOk = false;
}

$files = glob(dirname(journalFile()) . '/*.log.php') ?: [];
$filesOk = $files !== [];
$records = 0;
foreach ($files as $file) {
    $text = (string)file_get_contents($file);
    if (strlen($text) > LOG_DAY_MAX_BYTES
        || substr_count($text, BXAPP_PRIVATE_PHP_404_PREFIX) !== 1) $filesOk = false;
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') continue;
        if (!is_array(json_decode($line, true))) $filesOk = false;
        $records++;
    }
}
echo json_encode([
    'marker' => !empty($marker['truncated'])
        && ($marker['event'] ?? '') === 'oversized.event'
        && (int)($marker['original_bytes'] ?? 0) > LOG_RECORD_MAX_BYTES,
    'workers' => $workersOk,
    'files' => $filesOk,
    'records' => $records,
]);
PHP;

$journalWriteProbe = $runResourceProbe($journalWriteCode);
$journalWriteJson = is_array($journalWriteProbe['json']) ? $journalWriteProbe['json'] : [];
check('oversized journal-запись заменяется явным bounded marker',
    $journalWriteProbe['rc'] === 0 && $journalWriteProbe['err'] === ''
    && !empty($journalWriteJson['marker']),
    $journalWriteProbe['err']);
check('конкурентная ротация журнала сохраняет cap, prefix и все записи',
    $journalWriteProbe['rc'] === 0 && $journalWriteProbe['err'] === ''
    && !empty($journalWriteJson['workers']) && !empty($journalWriteJson['files'])
    && (int)($journalWriteJson['records'] ?? 0) === 241,
    $journalWriteProbe['err'] !== '' ? $journalWriteProbe['err']
        : 'записей: ' . (int)($journalWriteJson['records'] ?? 0));

// Ref имеет смысл только после подтверждённой физической записи. Порог и
// жёсткий cap дают два детерминированных путя «записи нет».
$journalRefCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('LOG_DAY_MAX_BYTES', 1);
require $root . '/www/api/journal.php';
echo json_encode([
    'suppressed' => appLog('debug', 'ref.suppressed'),
    'failed' => appLog('info', 'ref.failed'),
    'error' => logAppError('ref failed through wrapper'),
]);
PHP;
$journalRefProbe = $runResourceProbe($journalRefCode);
$journalRefJson = is_array($journalRefProbe['json']) ? $journalRefProbe['json'] : [];
check('appLog и logAppError не выдают ref без физической записи',
    $journalRefProbe['rc'] === 0 && $journalRefProbe['err'] === ''
    && ($journalRefJson['suppressed'] ?? null) === ''
    && ($journalRefJson['failed'] ?? null) === ''
    && ($journalRefJson['error'] ?? null) === '',
    $journalRefProbe['err']);

// Legacy/повреждённая строка в 12 МБ читается при memory_limit=16M без её
// сборки в PHP-строку; пригодные записи с обеих сторон остаются доступными.
$journalReadCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('LOG_SCAN_LINE_MAX_BYTES', 65536);
require $root . '/www/api/journal.php';
$fh = fopen(journalFile(), 'wb');
fwrite($fh, JOURNAL_FILE_PREFIX);
fwrite($fh, json_encode(['ts'=>date('c'),'level'=>'info','event'=>'older','kind'=>'system']) . "\n");
fwrite($fh, '{"event":"giant","blob":"');
for ($i = 0; $i < 192; $i++) fwrite($fh, str_repeat('x', 65536));
fwrite($fh, '"}' . "\n");
fwrite($fh, json_encode(['ts'=>date('c'),'level'=>'info','event'=>'newer','kind'=>'system']) . "\n");
fclose($fh);
$result = journalQuery(['limit' => 10]);
echo json_encode([
    'events' => array_column($result['rows'], 'event'),
    'truncated' => $result['truncated'],
    'peak' => memory_get_peak_usage(true),
]);
PHP;

$journalReadProbe = $runResourceProbe($journalReadCode, '16M');
$journalReadJson = is_array($journalReadProbe['json']) ? $journalReadProbe['json'] : [];
check('reverse-reader bounded пропускает giant line и читает соседние записи при 16M',
    $journalReadProbe['rc'] === 0 && $journalReadProbe['err'] === ''
    && !empty($journalReadJson['truncated'])
    && ($journalReadJson['events'] ?? []) === ['newer', 'older'],
    $journalReadProbe['err']);

$brokenDay = '2001-02-03';
$brokenFile = journalFile($brokenDay);
file_put_contents($brokenFile, JOURNAL_FILE_PREFIX
    . json_encode(['ts' => date('c'), 'level' => 'info', 'event' => 'valid', 'kind' => 'system']) . "\n"
    . "{broken json\n");
$brokenRead = journalQuery(['day' => $brokenDay, 'limit' => 10]);
check('малая битая journal-строка не пропускается как полный результат',
    !empty($brokenRead['truncated']) && array_column($brokenRead['rows'], 'event') === ['valid']);

// Формула остаётся безопасной даже после whitespace, а настоящее отрицательное
// число не превращается в текст.
check('CSV formula injection после whitespace обезврежена',
    strpos(csvCell(" \t\n=HYPERLINK(\"x\")"), '"\'') === 0
    && strpos(csvCell("\r@cmd"), '"\'') === 0);
check('отрицательное числовое значение CSV остаётся числом',
    csvCell(-5) === '"-5"' && csvCell('-5.25') === '"-5.25"'
    && strpos(csvCell('-1+cmd'), '"\'') === 0);

$smallCsv = fopen('php://temp', 'w+b');
$smallMeta = csvWrite($smallCsv, ['id'], [[1], [2]]);
rewind($smallCsv);
$smallBody = (string)stream_get_contents($smallCsv);
fclose($smallCsv);
check('csvWrite возвращает точный размер для HTTP Content-Length',
    $smallMeta['bytes'] === strlen($smallBody) && $smallMeta['rows'] === 2
    && substr($smallBody, 0, 3) === "\xEF\xBB\xBF");

$buildBounded = false;
try {
    csvBuild([], [[str_repeat('x', 1000)]], ['max_bytes' => 128]);
} catch (RuntimeException $e) {
    $buildBounded = strpos($e->getMessage(), 'размер') !== false;
}
check('строковый csvBuild завершается контролируемо на своём малом cap', $buildBounded);

// Главная регрессия: 200k строк больше не склеиваются оператором .= в одну
// строку. Поток спуливается на диск и проходит при memory_limit=16M.
$csvStreamCode = '$root = ' . var_export($resourceTemplateRoot, true) . ";\n" . <<<'PHP'
require $root . '/www/api/export-csv.php';
$tmp = fopen('php://temp/maxmemory:262144', 'w+b');
$rows = (static function (): iterable {
    for ($i = 0; $i < 200000; $i++) yield [$i, str_repeat('x', 120), '  =1+1'];
})();
$meta = csvWrite($tmp, ['id', 'payload', 'formula'], $rows);
$size = ftell($tmp);
fclose($tmp);
echo json_encode(['meta' => $meta, 'size' => $size, 'peak' => memory_get_peak_usage(true)]);
PHP;

$csvStreamProbe = $runResourceProbe($csvStreamCode, '16M');
$csvStreamJson = is_array($csvStreamProbe['json']) ? $csvStreamProbe['json'] : [];
check('CSV 200k строк пишется потоково при memory_limit=16M',
    $csvStreamProbe['rc'] === 0 && $csvStreamProbe['err'] === ''
    && (int)($csvStreamJson['meta']['rows'] ?? 0) === 200000
    && (int)($csvStreamJson['meta']['bytes'] ?? -1) === (int)($csvStreamJson['size'] ?? -2)
    && (int)($csvStreamJson['size'] ?? 0) > 20 * 1048576,
    $csvStreamProbe['err']);

// ACL — растущий shared-state, поэтому его контракт обязан работать не только
// на обычном файле, но и ровно на обеих границах, на legacy oversize и под
// конкурентными добавлениями. Малые пределы задаём до require в отдельном PHP.
$aclLimitCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('ACL_MAX_ENTRIES', 4);
define('ACL_MAX_BYTES', 2048);
require $root . '/www/api/access.php';

$state = static function (array $users, array $depts = []): array {
    return ['owner_uid' => 1, 'users' => array_merge([
        ['uid' => 1, 'name' => 'owner', 'photo' => '', 'role' => 'admin'],
    ], $users), 'depts' => $depts];
};
$ok = [];

$minusOne = aclUpdate(static fn(array $old): array => $state(
    [['uid' => 2, 'name' => 'u2', 'photo' => '', 'role' => 'edit']],
    [['id' => 10, 'name' => 'd10', 'role' => 'view']]
));
$ok['count_minus_one'] = aclStateEntryCount($minusOne) === ACL_MAX_ENTRIES - 1;

$atLimit = aclUpdate(static fn(array $old): array => $state([
    ['uid' => 2, 'name' => 'u2', 'photo' => '', 'role' => 'edit'],
    ['uid' => 3, 'name' => 'u3', 'photo' => '', 'role' => 'view'],
], [['id' => 10, 'name' => 'd10', 'role' => 'view']]));
$ok['count_limit'] = aclStateEntryCount($atLimit) === ACL_MAX_ENTRIES;
$beforeCountOverflow = hash_file('sha256', ACL_FILE);
try {
    aclUpdate(static function (array $acl): array {
        $acl['depts'][] = ['id' => 11, 'name' => 'd11', 'role' => 'view'];
        return $acl;
    });
    $ok['count_plus_one'] = false;
} catch (AclStateLimitException $e) {
    $ok['count_plus_one'] = $e->reason === 'entries'
        && hash_file('sha256', ACL_FILE) === $beforeCountOverflow;
}

$byteState = $state([]);
$pad = ACL_MAX_BYTES - aclStoredBytes($byteState);
if ($pad > 0) $byteState['users'][0]['name'] .= str_repeat('x', $pad);
$ok['bytes_limit'] = aclStoredBytes($byteState) === ACL_MAX_BYTES;
aclAssertStateWithinLimits($byteState);
$byteState['users'][0]['name'] .= 'x';
try {
    aclAssertStateWithinLimits($byteState);
    $ok['bytes_plus_one'] = false;
} catch (AclStateLimitException $e) {
    $ok['bytes_plus_one'] = $e->reason === 'bytes';
}

storeWrite(ACL_FILE, $state([
    ['uid' => 2, 'name' => str_repeat('z', ACL_MAX_BYTES + 512), 'photo' => '', 'role' => 'view'],
]));
$legacyHash = hash_file('sha256', ACL_FILE);
$loadClosed = $updateClosed = false;
try { aclLoad(); } catch (StoreReadLimitException $e) { $loadClosed = true; }
try { aclUpdate(static fn(array $acl): array => $acl); }
catch (StoreReadLimitException $e) { $updateClosed = true; }
$ok['legacy'] = $loadClosed && $updateClosed
    && hash_file('sha256', ACL_FILE) === $legacyHash;

storeWrite(ACL_FILE, $state([]));
$workers = [];
for ($worker = 0; $worker < 8; $worker++) {
    $code = '$root=' . var_export($root, true) . ';'
        . "define('ACL_MAX_ENTRIES',4);define('ACL_MAX_BYTES',2048);"
        . "require \$root.'/www/api/access.php';"
        . '$uid=' . (100 + $worker) . ';'
        . "try{aclUpdate(static function(array \$acl)use(\$uid):array{\$acl['users'][]=['uid'=>\$uid,'name'=>'u'.\$uid,'photo'=>'','role'=>'view'];return \$acl;});echo 'S';}"
        . "catch(AclStateLimitException \$e){echo 'B';}";
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $code], $desc, $pipes, $root);
    if (!is_resource($proc)) exit(92);
    fclose($pipes[0]);
    $workers[] = [$proc, $pipes];
}
$workerResults = '';
$workersOk = true;
foreach ($workers as [$proc, $pipes]) {
    $workerResults .= (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $err !== '') $workersOk = false;
}
$afterRace = aclLoad();
$ownerRows = array_values(array_filter($afterRace['users'],
    static fn(array $user): bool => (int)($user['uid'] ?? 0) === 1));
$ok['concurrent'] = $workersOk && substr_count($workerResults, 'S') === 3
    && substr_count($workerResults, 'B') === 5
    && aclStateEntryCount($afterRace) === ACL_MAX_ENTRIES
    && aclStoredBytes($afterRace) <= ACL_MAX_BYTES;
$ok['owner'] = count($ownerRows) === 1 && ($ownerRows[0]['role'] ?? '') === 'admin'
    && aclUserRole(1) === 'admin';

echo json_encode($ok);
PHP;

$aclLimitProbe = $runResourceProbe($aclLimitCode);
$aclLimitJson = is_array($aclLimitProbe['json']) ? $aclLimitProbe['json'] : [];
$aclLimitDetail = $aclLimitProbe['err'] !== '' ? $aclLimitProbe['err']
    : 'rc=' . $aclLimitProbe['rc'];
$aclExpected = [
    'count_minus_one', 'count_limit', 'count_plus_one', 'bytes_limit',
    'bytes_plus_one', 'legacy', 'concurrent', 'owner',
];
check('ACL bounded: границы count/bytes, legacy no-mutation, race и owner',
    $aclLimitProbe['rc'] === 0 && $aclLimitProbe['err'] === ''
    && array_keys($aclLimitJson) === $aclExpected
    && !in_array(false, array_map('boolval', $aclLimitJson), true),
    $aclLimitDetail);

// Session state хранит snapshot отделов в каждой вкладке. Count-limit строк не
// ограничивает размер файла: один большой portal response размножается сотни
// раз. Проверяем строку, aggregate, pre-decode legacy и гонку одним isolated
// fixture; production helpers обязательны, поэтому до их появления тест RED.
$sessionLimitCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
require $root . '/www/api/session.php';

$expected = [
    'contract', 'departments_minus_one', 'departments_limit', 'departments_plus_one',
    'row_minus_one', 'row_limit', 'row_plus_one', 'aggregate_boundaries',
    'aggregate_multiplication', 'legacy_limit', 'legacy_plus_one',
    'create_rejects', 'apply_revokes', 'concurrent',
];
$ok = array_fill_keys($expected, false);
$ok['contract'] = defined('SESSION_MAX_DEPARTMENTS')
    && defined('SESSION_MAX_ROW_BYTES') && defined('SESSION_MAX_BYTES')
    && function_exists('sessionNormalizeDepartments')
    && function_exists('sessionRowStoredBytes')
    && function_exists('sessionAssertRowWithinLimits')
    && function_exists('sessionStoredBytes')
    && function_exists('sessionsBoundToBytes')
    && function_exists('sessionStoreUpdate');
if (!$ok['contract']) { echo json_encode($ok); exit; }

$minus = sessionNormalizeDepartments(range(1, SESSION_MAX_DEPARTMENTS - 1));
$limit = sessionNormalizeDepartments(range(1, SESSION_MAX_DEPARTMENTS));
$deptRejected = false;
try { sessionNormalizeDepartments(range(1, SESSION_MAX_DEPARTMENTS + 1)); }
catch (SessionStateLimitException $e) { $deptRejected = true; }
$ok['departments_minus_one'] = count($minus) === SESSION_MAX_DEPARTMENTS - 1;
$ok['departments_limit'] = count($limit) === SESSION_MAX_DEPARTMENTS;
$ok['departments_plus_one'] = $deptRejected;

$rowAt = static function (int $target): array {
    $row = [
        'token' => str_repeat('a', 64), 'uid' => 1, 'role' => 'view',
        'isAdmin' => false, 'departments' => [1], 'member_id' => 'M',
        'install_generation' => 'G', 'createdAt' => 1, 'lastSeen' => 1,
        'expiresAt' => time() + 3600, 'padding' => '',
    ];
    $base = sessionRowStoredBytes($row);
    if ($base > $target) exit(92);
    $row['padding'] = str_repeat('x', $target - $base);
    while (sessionRowStoredBytes($row) < $target) $row['padding'] .= 'x';
    while (sessionRowStoredBytes($row) > $target) {
        $row['padding'] = substr($row['padding'], 0, -1);
    }
    return $row;
};
$rowMinus = $rowAt(SESSION_MAX_ROW_BYTES - 1);
$rowLimit = $rowAt(SESSION_MAX_ROW_BYTES);
$rowPlus = $rowAt(SESSION_MAX_ROW_BYTES + 1);
$rowPlusRejected = false;
try { sessionAssertRowWithinLimits($rowPlus); }
catch (SessionStateLimitException $e) { $rowPlusRejected = true; }
$ok['row_minus_one'] = sessionRowStoredBytes(sessionAssertRowWithinLimits($rowMinus))
    === SESSION_MAX_ROW_BYTES - 1;
$ok['row_limit'] = sessionRowStoredBytes(sessionAssertRowWithinLimits($rowLimit))
    === SESSION_MAX_ROW_BYTES;
$ok['row_plus_one'] = $rowPlusRejected;

$smallA = ['uid' => 11, 'departments' => [1], 'lastSeen' => 20, 'expiresAt' => time() + 3600];
$smallB = ['uid' => 12, 'departments' => [2], 'lastSeen' => 10, 'expiresAt' => time() + 3600];
$measured = sessionStoredBytes([$smallA, $smallB]);
$ok['aggregate_boundaries'] = count(sessionsBoundToBytes([$smallA, $smallB], $measured - 1)) === 1
    && count(sessionsBoundToBytes([$smallA, $smallB], $measured)) === 2
    && count(sessionsBoundToBytes([$smallA, $smallB], $measured + 1)) === 2;

$many = [];
for ($i = 0; $i < SESSION_MAX_TOTAL; $i++) {
    $many[] = [
        'token' => hash('sha256', 'many-' . $i), 'uid' => $i + 1, 'role' => 'view',
        'departments' => range(1, SESSION_MAX_DEPARTMENTS),
        'lastSeen' => SESSION_MAX_TOTAL - $i, 'expiresAt' => time() + 3600,
    ];
}
$boundedMany = sessionsBound($many);
$ok['aggregate_multiplication'] = count($boundedMany) <= SESSION_MAX_TOTAL
    && sessionStoredBytes($boundedMany) <= SESSION_MAX_BYTES
    && count($boundedMany) < count($many);

$rawAt = static function (int $target): string {
    $row = [['uid' => 1, 'padding' => '']];
    $raw = BXAPP_PRIVATE_PHP_HEADER . json_encode($row);
    if (strlen($raw) > $target) exit(93);
    $row[0]['padding'] = str_repeat('x', $target - strlen($raw));
    $raw = BXAPP_PRIVATE_PHP_HEADER . json_encode($row);
    while (strlen($raw) < $target) { $row[0]['padding'] .= 'x'; $raw = BXAPP_PRIVATE_PHP_HEADER . json_encode($row); }
    while (strlen($raw) > $target) { $row[0]['padding'] = substr($row[0]['padding'], 0, -1); $raw = BXAPP_PRIVATE_PHP_HEADER . json_encode($row); }
    return $raw;
};
$atLimit = $rawAt(SESSION_MAX_BYTES);
file_put_contents(SESSIONS_FILE, $atLimit);
$ok['legacy_limit'] = is_array(loadSessions());
$overLimit = $rawAt(SESSION_MAX_BYTES + 1);
file_put_contents(SESSIONS_FILE, $overLimit);
$beforeHash = hash_file('sha256', SESSIONS_FILE);
$readRejected = $updateRejected = false;
try { loadSessions(); } catch (StoreReadLimitException $e) { $readRejected = true; }
try { sessionStoreUpdate(static fn(array $all): array => []); }
catch (StoreReadLimitException $e) { $updateRejected = true; }
$ok['legacy_plus_one'] = $readRejected && $updateRejected
    && hash_equals((string)$beforeHash, (string)hash_file('sha256', SESSIONS_FILE));
@unlink(SESSIONS_FILE);

storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'a', 'refresh_token' => 'r', 'domain' => 'x.bitrix24.ru',
    'member_id' => 'MEMBER-A', 'expires_at' => time() + 3600,
]);
aclSetOwner(7, 'Владелец');
storeWrite(SESSIONS_FILE, []);
$beforeCreate = hash_file('sha256', SESSIONS_FILE);
$createRejected = false;
try { createSession(7, 'admin', false, range(1, SESSION_MAX_DEPARTMENTS + 1)); }
catch (SessionStateLimitException $e) { $createRejected = true; }
$ok['create_rejects'] = $createRejected
    && hash_equals((string)$beforeCreate, (string)hash_file('sha256', SESSIONS_FILE));

$now = time();
storeWrite(SESSIONS_FILE, [[
    'token' => str_repeat('b', 64), 'uid' => 42, 'role' => 'view',
    'departments' => [1], 'lastSeen' => $now, 'expiresAt' => $now + 3600,
]]);
$revoked = sessionApplyPortalDepartments([42 => range(1, SESSION_MAX_DEPARTMENTS + 1)]);
$ok['apply_revokes'] = $revoked === 1 && loadSessions() === [];

storeWrite(SESSIONS_FILE, []);
$workers = [];
for ($worker = 0; $worker < 6; $worker++) {
    $workerCode = '$root=' . var_export($root, true) . ';$worker=' . $worker . ';'
        . "require \$root.'/www/api/session.php';"
        . '$now=time();sessionStoreUpdate(static function(array $all) use($worker,$now):array {'
        . 'for($i=0;$i<100;$i++){$n=$worker*1000+$i+1;$all[]=['
        . "'token'=>hash('sha256','race-'.\$n),'uid'=>\$n,'role'=>'view',"
        . "'departments'=>range(1,50),'lastSeen'=>\$now+\$worker,'expiresAt'=>\$now+3600];}return \$all;});";
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $workerCode], $desc, $pipes, $root);
    if (!is_resource($proc)) exit(94);
    fclose($pipes[0]);
    $workers[] = [$proc, $pipes];
}
$workersOk = true;
foreach ($workers as [$proc, $pipes]) {
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $out !== '' || $err !== '') $workersOk = false;
}
$afterRace = loadSessions();
$ok['concurrent'] = $workersOk && count($afterRace) === SESSION_MAX_TOTAL
    && sessionStoredBytes($afterRace) <= SESSION_MAX_BYTES;

echo json_encode($ok);
PHP;

$sessionLimitProbe = $runResourceProbe($sessionLimitCode, '32M');
$sessionLimitJson = is_array($sessionLimitProbe['json']) ? $sessionLimitProbe['json'] : [];
$sessionExpected = [
    'contract', 'departments_minus_one', 'departments_limit', 'departments_plus_one',
    'row_minus_one', 'row_limit', 'row_plus_one', 'aggregate_boundaries',
    'aggregate_multiplication', 'legacy_limit', 'legacy_plus_one',
    'create_rejects', 'apply_revokes', 'concurrent',
];
check('session state bounded: department/row/aggregate/legacy/race boundaries',
    $sessionLimitProbe['rc'] === 0 && $sessionLimitProbe['err'] === ''
    && array_keys($sessionLimitJson) === $sessionExpected
    && !in_array(false, array_map('boolval', $sessionLimitJson), true),
    $sessionLimitProbe['err'] !== '' ? $sessionLimitProbe['err']
        : json_encode($sessionLimitJson, JSON_UNESCAPED_UNICODE));

// Rebuildable cache, task progress и CRM dictionary раньше полагались только
// на общий store и размер REST-ответа. Это не ограничивало cursor/result,
// накопленный dictionary и уже лежащий на диске legacy-файл. Один isolated
// probe проверяет именно исполняемые контракты трёх владельцев состояния.
$boundedStateCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('CACHE_KEY_MAX_BYTES', 8);
define('CACHE_VALUE_MAX_BYTES', 32);
define('CACHE_MAX_BYTES', 512);
define('CACHE_FAMILY_MAX_FILES', 4);
define('CACHE_FAMILY_MAX_BYTES', 1400);
define('CACHE_LOCK_SHARDS', 4);
define('TASK_MAX_BYTES', 2048);
define('CRM_DICT_LABEL_MAX_BYTES', 8);
define('CRM_DICT_CODE_MAX_BYTES', 8);
define('CRM_DICT_MAX_FIELDS_PER_ENTITY', 2);
define('CRM_DICT_MAX_ITEMS', 4);
define('CRM_DICT_MAX_BYTES', 1024);

require $root . '/www/api/cache.php';
require $root . '/www/api/task-state.php';
require $root . '/www/api/crm-dict.php';

$expected = [
    'cache_contract', 'cache_key_boundaries', 'cache_value_boundaries',
    'cache_aggregate_boundaries', 'cache_field_no_mutation',
    'cache_candidate_no_mutation', 'cache_legacy_no_mutation',
    'cache_family_count_boundaries', 'cache_family_byte_boundaries',
    'cache_family_replacement_subtracts', 'cache_family_no_mutation',
    'task_contract', 'task_cursor_boundaries', 'task_text_boundaries',
    'task_aggregate_boundaries', 'task_field_no_mutation', 'task_candidate_no_mutation',
    'task_legacy_no_mutation', 'crm_contract', 'crm_label_boundaries',
    'crm_field_count_boundaries', 'crm_item_boundaries', 'crm_aggregate_boundaries',
    'crm_field_no_mutation', 'crm_candidate_no_mutation', 'crm_legacy_no_mutation',
];
$ok = array_fill_keys($expected, false);
$ok['cache_contract'] = defined('CACHE_KEY_MAX_BYTES') && defined('CACHE_VALUE_MAX_BYTES')
    && defined('CACHE_MAX_BYTES') && defined('CACHE_FAMILY_MAX_FILES')
    && defined('CACHE_FAMILY_MAX_BYTES') && defined('CACHE_LOCK_SHARDS')
    && class_exists('CacheStateLimitException')
    && function_exists('cacheValueStoredBytes') && function_exists('cacheStoredBytes')
    && function_exists('cacheAssertValueWithinLimit') && function_exists('cacheAssertRowWithinLimits')
    && function_exists('cacheStoreRead') && function_exists('cacheStoreUpdate')
    && function_exists('cacheFamilyScan') && function_exists('cacheAssertFamilyCandidate')
    && function_exists('cacheForgetAll');
$ok['task_contract'] = defined('TASK_CURSOR_MAX_BYTES') && defined('TASK_TEXT_MAX_BYTES')
    && defined('TASK_MAX_BYTES') && class_exists('TaskStateLimitException')
    && function_exists('taskCursorStoredBytes') && function_exists('taskStoredBytes')
    && function_exists('taskAssertCursorWithinLimit') && function_exists('taskAssertStateWithinLimits')
    && function_exists('taskStoreRead') && function_exists('taskStoreUpdate');
$ok['crm_contract'] = defined('CRM_DICT_LABEL_MAX_BYTES') && defined('CRM_DICT_CODE_MAX_BYTES')
    && defined('CRM_DICT_MAX_FIELDS_PER_ENTITY') && defined('CRM_DICT_MAX_ITEMS')
    && defined('CRM_DICT_MAX_BYTES') && class_exists('CrmDictStateLimitException')
    && function_exists('crmDictAssertLabelWithinLimit') && function_exists('crmDictItemCount')
    && function_exists('crmDictAssertStateWithinLimits')
    && function_exists('crmDictStoreRead') && function_exists('crmDictStoreUpdate');
if (!$ok['cache_contract'] || !$ok['task_contract'] || !$ok['crm_contract']) {
    echo json_encode($ok);
    exit;
}

$stringAt = static function (int $target, callable $measure): string {
    $value = '';
    $delta = $target - $measure($value);
    if ($delta < 0) exit(95);
    $value = str_repeat('x', $delta);
    while ($measure($value) < $target) $value .= 'x';
    while ($measure($value) > $target) $value = substr($value, 0, -1);
    if ($measure($value) !== $target) exit(96);
    return $value;
};
$rowAt = static function (array $row, int $target, callable $measure): array {
    $row['padding'] = '';
    $delta = $target - $measure($row);
    if ($delta < 0) exit(97);
    $row['padding'] = str_repeat('x', $delta);
    while ($measure($row) < $target) $row['padding'] .= 'x';
    while ($measure($row) > $target) $row['padding'] = substr($row['padding'], 0, -1);
    if ($measure($row) !== $target) exit(98);
    return $row;
};

// CACHE: raw key, JSON value и полный private PHP file — разные границы.
$keyMinus = $keyLimit = true;
try { cacheFileFor(str_repeat('k', CACHE_KEY_MAX_BYTES - 1)); }
catch (Throwable $e) { $keyMinus = false; }
try { cacheFileFor(str_repeat('k', CACHE_KEY_MAX_BYTES)); }
catch (Throwable $e) { $keyLimit = false; }
$keyPlus = false;
try { cacheFileFor(str_repeat('k', CACHE_KEY_MAX_BYTES + 1)); }
catch (CacheStateLimitException $e) { $keyPlus = true; }
$ok['cache_key_boundaries'] = $keyMinus && $keyLimit && $keyPlus;

$cacheValueMinus = $stringAt(CACHE_VALUE_MAX_BYTES - 1, 'cacheValueStoredBytes');
$cacheValueLimit = $stringAt(CACHE_VALUE_MAX_BYTES, 'cacheValueStoredBytes');
$cacheValuePlus = $stringAt(CACHE_VALUE_MAX_BYTES + 1, 'cacheValueStoredBytes');
$valueMinus = $valueLimit = true;
try { cacheAssertValueWithinLimit($cacheValueMinus); } catch (Throwable $e) { $valueMinus = false; }
try { cacheAssertValueWithinLimit($cacheValueLimit); } catch (Throwable $e) { $valueLimit = false; }
$valuePlus = false;
try { cacheAssertValueWithinLimit($cacheValuePlus); }
catch (CacheStateLimitException $e) { $valuePlus = true; }
$ok['cache_value_boundaries'] = $valueMinus && $valueLimit && $valuePlus;

$cacheMeasure = static fn(array $row): int => cacheStoredBytes($row);
$cacheBase = ['expires' => 1, 'value' => 'ok'];
$cacheMinus = $rowAt($cacheBase, CACHE_MAX_BYTES - 1, $cacheMeasure);
$cacheLimit = $rowAt($cacheBase, CACHE_MAX_BYTES, $cacheMeasure);
$cachePlus = $rowAt($cacheBase, CACHE_MAX_BYTES + 1, $cacheMeasure);
$cacheMinusOk = $cacheLimitOk = true;
try { cacheAssertRowWithinLimits($cacheMinus); } catch (Throwable $e) { $cacheMinusOk = false; }
try { cacheAssertRowWithinLimits($cacheLimit); } catch (Throwable $e) { $cacheLimitOk = false; }
$cachePlusRejected = false;
try { cacheAssertRowWithinLimits($cachePlus); }
catch (CacheStateLimitException $e) { $cachePlusRejected = true; }
$ok['cache_aggregate_boundaries'] = $cacheMinusOk && $cacheLimitOk && $cachePlusRejected;

$cacheFile = cacheFileFor('bounded');
cacheStoreUpdate($cacheFile, static fn(array $prev): array => $cacheLimit);
$cacheFieldBefore = hash_file('sha256', $cacheFile);
$cacheFieldRejected = false;
try {
    cacheStoreUpdate($cacheFile, static fn(array $prev): array => [
        'expires' => 1, 'value' => $cacheValuePlus,
    ]);
} catch (CacheStateLimitException $e) { $cacheFieldRejected = true; }
$ok['cache_field_no_mutation'] = $cacheFieldRejected
    && hash_equals((string)$cacheFieldBefore, (string)hash_file('sha256', $cacheFile));
$cacheBefore = hash_file('sha256', $cacheFile);
$cacheCandidateRejected = false;
try { cacheStoreUpdate($cacheFile, static fn(array $prev): array => $cachePlus); }
catch (CacheStateLimitException $e) { $cacheCandidateRejected = true; }
$ok['cache_candidate_no_mutation'] = $cacheCandidateRejected
    && hash_equals((string)$cacheBefore, (string)hash_file('sha256', $cacheFile));
file_put_contents($cacheFile, storeEncode($cachePlus));
$cacheLegacyBefore = hash_file('sha256', $cacheFile);
$cacheReadRejected = $cacheUpdateRejected = false;
try { cacheStoreRead($cacheFile); } catch (StoreReadLimitException $e) { $cacheReadRejected = true; }
try { cacheStoreUpdate($cacheFile, static fn(array $prev): array => $cacheBase); }
catch (StoreReadLimitException $e) { $cacheUpdateRejected = true; }
$ok['cache_legacy_no_mutation'] = $cacheReadRejected && $cacheUpdateRejected
    && hash_equals((string)$cacheLegacyBefore, (string)hash_file('sha256', $cacheFile));

// Family count ceiling: limit-1 и exact проходят, новый limit+1 не оставляет
// ни data, ни per-file store lock. Все операции идут через один family lock.
cacheForgetAll();
$cacheSmall = ['expires' => 1, 'value' => 'x'];
for ($i = 0; $i < CACHE_FAMILY_MAX_FILES - 1; $i++) {
    cacheStoreUpdate(cacheFileFor('c' . $i), static fn(array $prev): array => $cacheSmall);
}
$countMinus = count(cacheFiles()) === CACHE_FAMILY_MAX_FILES - 1;
cacheStoreUpdate(cacheFileFor('c-last'), static fn(array $prev): array => $cacheSmall);
$countExact = count(cacheFiles()) === CACHE_FAMILY_MAX_FILES;
$countBefore = array_map('hash_file', array_fill(0, count(cacheFiles()), 'sha256'), cacheFiles());
$countPlusFile = cacheFileFor('c-plus');
$countPlusRejected = false;
try { cacheStoreUpdate($countPlusFile, static fn(array $prev): array => $cacheSmall); }
catch (CacheStateLimitException $e) { $countPlusRejected = true; }
$countAfterFiles = cacheFiles();
$countAfter = array_map('hash_file', array_fill(0, count($countAfterFiles), 'sha256'), $countAfterFiles);
$ok['cache_family_count_boundaries'] = $countMinus && $countExact && $countPlusRejected
    && $countBefore === $countAfter && !is_file($countPlusFile)
    && !is_file($countPlusFile . '.lock.php');

// Family bytes: limit-1/limit/limit+1. Последний candidate сам меньше per-file cap,
// поэтому отказ доказывает именно общий byte ceiling, а не CACHE_MAX_BYTES.
cacheForgetAll();
$family450 = $rowAt($cacheBase, 450, $cacheMeasure);
$family499 = $rowAt($cacheBase, 499, $cacheMeasure);
$family500 = $rowAt($cacheBase, 500, $cacheMeasure);
$family501 = $rowAt($cacheBase, 501, $cacheMeasure);
$byteA = cacheFileFor('b-a');
$byteB = cacheFileFor('b-b');
$byteC = cacheFileFor('b-c');
cacheStoreUpdate($byteA, static fn(array $prev): array => $family450);
cacheStoreUpdate($byteB, static fn(array $prev): array => $family450);
cacheStoreUpdate($byteC, static fn(array $prev): array => $family499);
$bytesMinus = cacheFamilyScan()['bytes'] === CACHE_FAMILY_MAX_BYTES - 1;
cacheStoreUpdate($byteC, static fn(array $prev): array => $family500);
$bytesExact = cacheFamilyScan()['bytes'] === CACHE_FAMILY_MAX_BYTES;
$byteBeforeHash = hash_file('sha256', $byteC);
$bytePlusRejected = false;
try { cacheStoreUpdate($byteC, static fn(array $prev): array => $family501); }
catch (CacheStateLimitException $e) { $bytePlusRejected = true; }
$ok['cache_family_byte_boundaries'] = $bytesMinus && $bytesExact && $bytePlusRejected;
$ok['cache_family_no_mutation'] = $bytePlusRejected
    && hash_equals((string)$byteBeforeHash, (string)hash_file('sha256', $byteC))
    && cacheFamilyScan()['bytes'] === CACHE_FAMILY_MAX_BYTES;

// Existing update обязан вычесть старые 500 байт, а не считать candidate как
// пятый/добавочный payload. После shrink четвёртая запись доводит bytes до exact.
$family250 = $rowAt($cacheBase, 250, $cacheMeasure);
$family200 = $rowAt($cacheBase, 200, $cacheMeasure);
cacheStoreUpdate($byteA, static fn(array $prev): array => $family250);
$byteD = cacheFileFor('b-d');
cacheStoreUpdate($byteD, static fn(array $prev): array => $family200);
$ok['cache_family_replacement_subtracts'] = count(cacheFiles()) === CACHE_FAMILY_MAX_FILES
    && cacheFamilyScan()['bytes'] === CACHE_FAMILY_MAX_BYTES;
cacheForgetAll();

// TASK: cursor и result/error ограничены отдельно от всего snapshot.
$taskCursorMinus = $stringAt(TASK_CURSOR_MAX_BYTES - 1, 'taskCursorStoredBytes');
$taskCursorLimit = $stringAt(TASK_CURSOR_MAX_BYTES, 'taskCursorStoredBytes');
$taskCursorPlus = $stringAt(TASK_CURSOR_MAX_BYTES + 1, 'taskCursorStoredBytes');
$cursorMinus = $cursorLimit = true;
try { taskAssertCursorWithinLimit($taskCursorMinus); } catch (Throwable $e) { $cursorMinus = false; }
try { taskAssertCursorWithinLimit($taskCursorLimit); } catch (Throwable $e) { $cursorLimit = false; }
$cursorPlus = false;
try { taskAssertCursorWithinLimit($taskCursorPlus); }
catch (TaskStateLimitException $e) { $cursorPlus = true; }
$ok['task_cursor_boundaries'] = $cursorMinus && $cursorLimit && $cursorPlus;

$textMinus = $textLimit = true;
try { taskAssertTextWithinLimit(str_repeat('x', TASK_TEXT_MAX_BYTES - 1), 'result'); }
catch (Throwable $e) { $textMinus = false; }
try { taskAssertTextWithinLimit(str_repeat('x', TASK_TEXT_MAX_BYTES), 'result'); }
catch (Throwable $e) { $textLimit = false; }
$textPlus = false;
try { taskAssertTextWithinLimit(str_repeat('x', TASK_TEXT_MAX_BYTES + 1), 'result'); }
catch (TaskStateLimitException $e) { $textPlus = true; }
$ok['task_text_boundaries'] = $textMinus && $textLimit && $textPlus;

$taskMeasure = static fn(array $row): int => taskStoredBytes($row);
$taskBase = taskEmpty();
$taskMinus = $rowAt($taskBase, TASK_MAX_BYTES - 1, $taskMeasure);
$taskLimit = $rowAt($taskBase, TASK_MAX_BYTES, $taskMeasure);
$taskPlus = $rowAt($taskBase, TASK_MAX_BYTES + 1, $taskMeasure);
$taskMinusOk = $taskLimitOk = true;
try { taskAssertStateWithinLimits($taskMinus); } catch (Throwable $e) { $taskMinusOk = false; }
try { taskAssertStateWithinLimits($taskLimit); } catch (Throwable $e) { $taskLimitOk = false; }
$taskPlusRejected = false;
try { taskAssertStateWithinLimits($taskPlus); }
catch (TaskStateLimitException $e) { $taskPlusRejected = true; }
$ok['task_aggregate_boundaries'] = $taskMinusOk && $taskLimitOk && $taskPlusRejected;

$taskRunning = taskEmpty();
$taskRunning['id'] = 'job';
$taskRunning['status'] = 'running';
$taskRunning['heartbeat'] = time();
taskStoreUpdate(static fn(array $prev): array => $taskRunning);
$taskFieldBefore = hash_file('sha256', TASK_FILE);
$taskFieldRejected = false;
try { taskProgress('job', 1, null, $taskCursorPlus); }
catch (TaskStateLimitException $e) { $taskFieldRejected = true; }
$ok['task_field_no_mutation'] = $taskFieldRejected
    && hash_equals((string)$taskFieldBefore, (string)hash_file('sha256', TASK_FILE));
taskStoreUpdate(static fn(array $prev): array => $taskLimit);
$taskBefore = hash_file('sha256', TASK_FILE);
$taskCandidateRejected = false;
try { taskStoreUpdate(static fn(array $prev): array => $taskPlus); }
catch (TaskStateLimitException $e) { $taskCandidateRejected = true; }
$ok['task_candidate_no_mutation'] = $taskCandidateRejected
    && hash_equals((string)$taskBefore, (string)hash_file('sha256', TASK_FILE));
file_put_contents(TASK_FILE, storeEncode($taskPlus));
$taskLegacyBefore = hash_file('sha256', TASK_FILE);
$taskReadRejected = $taskUpdateRejected = false;
try { taskRead(); } catch (StoreReadLimitException $e) { $taskReadRejected = true; }
try { taskStoreUpdate(static fn(array $prev): array => taskEmpty()); }
catch (StoreReadLimitException $e) { $taskUpdateRejected = true; }
$ok['task_legacy_no_mutation'] = $taskReadRejected && $taskUpdateRejected
    && hash_equals((string)$taskLegacyBefore, (string)hash_file('sha256', TASK_FILE));

// CRM dictionary: длина code/label, fields per entity, total items и весь файл.
$labelMinus = $labelLimit = true;
try { crmDictAssertLabelWithinLimit(str_repeat('x', CRM_DICT_LABEL_MAX_BYTES - 1)); }
catch (Throwable $e) { $labelMinus = false; }
try { crmDictAssertLabelWithinLimit(str_repeat('x', CRM_DICT_LABEL_MAX_BYTES)); }
catch (Throwable $e) { $labelLimit = false; }
$labelPlus = false;
try { crmDictAssertLabelWithinLimit(str_repeat('x', CRM_DICT_LABEL_MAX_BYTES + 1)); }
catch (CrmDictStateLimitException $e) { $labelPlus = true; }
$ok['crm_label_boundaries'] = $labelMinus && $labelLimit && $labelPlus;

$crmBase = ['categories' => [], 'stages' => [], 'fields' => [], 'at' => 1,
    'complete' => true, 'failed' => [], 'restored' => []];
$crmFieldsMinus = $crmBase;
$crmFieldsMinus['fields'][2] = ['A' => 'a'];
$crmFieldsLimit = $crmBase;
$crmFieldsLimit['fields'][2] = ['A' => 'a', 'B' => 'b'];
$crmFieldsPlus = $crmBase;
$crmFieldsPlus['fields'][2] = ['A' => 'a', 'B' => 'b', 'C' => 'c'];
$fieldsMinus = $fieldsLimit = true;
try { crmDictAssertStateWithinLimits($crmFieldsMinus); } catch (Throwable $e) { $fieldsMinus = false; }
try { crmDictAssertStateWithinLimits($crmFieldsLimit); } catch (Throwable $e) { $fieldsLimit = false; }
$fieldsPlus = false;
try { crmDictAssertStateWithinLimits($crmFieldsPlus); }
catch (CrmDictStateLimitException $e) { $fieldsPlus = true; }
$ok['crm_field_count_boundaries'] = $fieldsMinus && $fieldsLimit && $fieldsPlus;

$crmItems = static function (int $count) use ($crmBase): array {
    $row = $crmBase;
    for ($i = 0; $i < $count; $i++) {
        $section = $i < 2 ? 'fields' : 'categories';
        $row[$section][2][$section === 'fields' ? chr(65 + $i) : $i] = 'v';
    }
    return $row;
};
$itemsMinus = $itemsLimit = true;
try { crmDictAssertStateWithinLimits($crmItems(CRM_DICT_MAX_ITEMS - 1)); }
catch (Throwable $e) { $itemsMinus = false; }
try { crmDictAssertStateWithinLimits($crmItems(CRM_DICT_MAX_ITEMS)); }
catch (Throwable $e) { $itemsLimit = false; }
$itemsPlus = false;
try { crmDictAssertStateWithinLimits($crmItems(CRM_DICT_MAX_ITEMS + 1)); }
catch (CrmDictStateLimitException $e) { $itemsPlus = true; }
$ok['crm_item_boundaries'] = $itemsMinus && $itemsLimit && $itemsPlus;

$crmMeasure = static fn(array $row): int => crmDictStoredBytes($row);
$crmMinus = $rowAt($crmBase, CRM_DICT_MAX_BYTES - 1, $crmMeasure);
$crmLimit = $rowAt($crmBase, CRM_DICT_MAX_BYTES, $crmMeasure);
$crmPlus = $rowAt($crmBase, CRM_DICT_MAX_BYTES + 1, $crmMeasure);
$crmMinusOk = $crmLimitOk = true;
try { crmDictAssertStateWithinLimits($crmMinus); } catch (Throwable $e) { $crmMinusOk = false; }
try { crmDictAssertStateWithinLimits($crmLimit); } catch (Throwable $e) { $crmLimitOk = false; }
$crmPlusRejected = false;
try { crmDictAssertStateWithinLimits($crmPlus); }
catch (CrmDictStateLimitException $e) { $crmPlusRejected = true; }
$ok['crm_aggregate_boundaries'] = $crmMinusOk && $crmLimitOk && $crmPlusRejected;

$crmValid = $crmBase;
$crmValid['fields'][2] = ['A' => 'ok'];
crmDictStoreUpdate(static fn(array $prev): array => $crmValid);
$crmFieldBefore = hash_file('sha256', CRM_DICT_FILE);
$crmBadField = $crmValid;
$crmBadField['fields'][2]['A'] = str_repeat('x', CRM_DICT_LABEL_MAX_BYTES + 1);
$crmFieldRejected = false;
try { crmDictStoreUpdate(static fn(array $prev): array => $crmBadField); }
catch (CrmDictStateLimitException $e) { $crmFieldRejected = true; }
$ok['crm_field_no_mutation'] = $crmFieldRejected
    && hash_equals((string)$crmFieldBefore, (string)hash_file('sha256', CRM_DICT_FILE));
crmDictStoreUpdate(static fn(array $prev): array => $crmLimit);
$crmBefore = hash_file('sha256', CRM_DICT_FILE);
$crmCandidateRejected = false;
try { crmDictStoreUpdate(static fn(array $prev): array => $crmPlus); }
catch (CrmDictStateLimitException $e) { $crmCandidateRejected = true; }
$ok['crm_candidate_no_mutation'] = $crmCandidateRejected
    && hash_equals((string)$crmBefore, (string)hash_file('sha256', CRM_DICT_FILE));
file_put_contents(CRM_DICT_FILE, storeEncode($crmPlus));
$crmLegacyBefore = hash_file('sha256', CRM_DICT_FILE);
$crmReadRejected = $crmUpdateRejected = false;
try { crmDictStoreRead(); } catch (StoreReadLimitException $e) { $crmReadRejected = true; }
try { crmDictStoreUpdate(static fn(array $prev): array => $crmBase); }
catch (StoreReadLimitException $e) { $crmUpdateRejected = true; }
$ok['crm_legacy_no_mutation'] = $crmReadRejected && $crmUpdateRejected
    && hash_equals((string)$crmLegacyBefore, (string)hash_file('sha256', CRM_DICT_FILE));

echo json_encode($ok);
PHP;

$boundedStateProbe = $runResourceProbe($boundedStateCode, '32M');
$boundedStateJson = is_array($boundedStateProbe['json']) ? $boundedStateProbe['json'] : [];
$boundedStateExpected = [
    'cache_contract', 'cache_key_boundaries', 'cache_value_boundaries',
    'cache_aggregate_boundaries', 'cache_field_no_mutation',
    'cache_candidate_no_mutation', 'cache_legacy_no_mutation',
    'cache_family_count_boundaries', 'cache_family_byte_boundaries',
    'cache_family_replacement_subtracts', 'cache_family_no_mutation',
    'task_contract', 'task_cursor_boundaries', 'task_text_boundaries',
    'task_aggregate_boundaries', 'task_field_no_mutation', 'task_candidate_no_mutation',
    'task_legacy_no_mutation', 'crm_contract', 'crm_label_boundaries',
    'crm_field_count_boundaries', 'crm_item_boundaries', 'crm_aggregate_boundaries',
    'crm_field_no_mutation', 'crm_candidate_no_mutation', 'crm_legacy_no_mutation',
];
check('cache/task/CRM state bounded: field/item/aggregate границы и no-mutation',
    $boundedStateProbe['rc'] === 0 && $boundedStateProbe['err'] === ''
    && array_keys($boundedStateJson) === $boundedStateExpected
    && !in_array(false, array_map('boolval', $boundedStateJson), true),
    $boundedStateProbe['err'] !== '' ? $boundedStateProbe['err']
        : json_encode($boundedStateJson, JSON_UNESCAPED_UNICODE));

// Разные keys имеют разные entry-shards, поэтому только общий family lock может
// не дать им одновременно пройти count check. Восемь реальных процессов
// борются за четыре места: ровно четыре публикации, ни одного лишнего store-lock.
$cacheFamilyRaceCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('DATA_ROOT', $tmp);
define('CACHE_KEY_MAX_BYTES', 32);
define('CACHE_VALUE_MAX_BYTES', 64);
define('CACHE_MAX_BYTES', 512);
define('CACHE_FAMILY_MAX_FILES', 4);
define('CACHE_FAMILY_MAX_BYTES', 4096);
define('CACHE_LOCK_SHARDS', 4);
require $root . '/www/api/cache.php';

$workers = [];
for ($i = 0; $i < 8; $i++) {
    $code = 'define("DATA_ROOT",' . var_export($tmp, true) . ');'
        . 'define("CACHE_KEY_MAX_BYTES",32);define("CACHE_VALUE_MAX_BYTES",64);'
        . 'define("CACHE_MAX_BYTES",512);define("CACHE_FAMILY_MAX_FILES",4);'
        . 'define("CACHE_FAMILY_MAX_BYTES",4096);define("CACHE_LOCK_SHARDS",4);'
        . 'require ' . var_export($root . '/www/api/cache.php', true) . ';'
        . '$i=' . $i . ';try{cacheRemember("race-".$i,60,static fn():array=>["i"=>$i]);echo "ok";}'
        . 'catch(CacheStateLimitException $e){echo "limit";}catch(Throwable $e){echo "other:".get_class($e);}';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $code], $desc, $pipes, $root);
    if (!is_resource($proc)) exit(99);
    fclose($pipes[0]);
    $workers[] = [$proc, $pipes];
}
$outcomes = [];
$workersOk = true;
foreach ($workers as [$proc, $pipes]) {
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $err !== '') $workersOk = false;
    $outcomes[] = $out;
}
$storeLocks = 0;
$claimLocks = 0;
$shardLocks = 0;
foreach (scandir(DATA_ROOT) ?: [] as $name) {
    if (preg_match('/^cache-entry-claims-[0-9]+\.php\.lock\.php$/D', $name)) $claimLocks++;
    elseif (preg_match('/^cache-.*\.php\.lock\.php$/D', $name)) $storeLocks++;
    if (preg_match('/^lock-cache-entry-shard-/', $name)) $shardLocks++;
}
$usage = cacheFamilyScan();
echo json_encode([
    'workers' => $workersOk,
    'ok' => count(array_filter($outcomes, static fn(string $v): bool => $v === 'ok')),
    'limit' => count(array_filter($outcomes, static fn(string $v): bool => $v === 'limit')),
    'other' => count(array_filter($outcomes, static fn(string $v): bool => strpos($v, 'other:') === 0)),
    'count' => $usage['count'], 'bytes' => $usage['bytes'],
    'store_locks' => $storeLocks, 'claim_locks' => $claimLocks, 'shard_locks' => $shardLocks,
]);
PHP;
$cacheFamilyRace = $runResourceProbe($cacheFamilyRaceCode, '32M');
$cacheFamilyRaceJson = is_array($cacheFamilyRace['json']) ? $cacheFamilyRace['json'] : [];
check('cache family quota под общим lock не пробивается разными keys и не оставляет rejected locks',
    $cacheFamilyRace['rc'] === 0 && $cacheFamilyRace['err'] === ''
    && !empty($cacheFamilyRaceJson['workers'])
    && (int)($cacheFamilyRaceJson['ok'] ?? -1) === 4
    && (int)($cacheFamilyRaceJson['limit'] ?? -1) === 4
    && (int)($cacheFamilyRaceJson['other'] ?? -1) === 0
    && (int)($cacheFamilyRaceJson['count'] ?? -1) === 4
    && (int)($cacheFamilyRaceJson['bytes'] ?? 0) <= 4096
    && (int)($cacheFamilyRaceJson['store_locks'] ?? -1) === 4
    && (int)($cacheFamilyRaceJson['claim_locks'] ?? -1) <= 4
    && (int)($cacheFamilyRaceJson['shard_locks'] ?? 0) <= 4,
    $cacheFamilyRace['err'] !== '' ? $cacheFamilyRace['err']
        : json_encode($cacheFamilyRaceJson, JSON_UNESCAPED_UNICODE));

// Детерминированная пара прежнего 32-bit suffix обязана получить разные пути,
// разные rows и вызвать оба loader. Старые имена безопасно инвалидируются:
// cache rebuildable, а определить исходный key по legacy-файлу невозможно.
$cacheKeyIdentityCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('DATA_ROOT', $tmp);
require $root . '/www/api/cache.php';
$firstKey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-87327';
$secondKey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-110431';
$calls = 0;
$first = cacheRemember($firstKey, 60, static function () use (&$calls): string { $calls++; return 'first'; });
$second = cacheRemember($secondKey, 60, static function () use (&$calls): string { $calls++; return 'second'; });
$firstFile = cacheFileFor($firstKey);
$secondFile = cacheFileFor($secondKey);
$firstRow = storeRead($firstFile);
$secondRow = storeRead($secondFile);

$legacyBase = 'cache-legacy-' . substr(sha1('legacy'), 0, 8) . '.php';
$legacyData = DATA_ROOT . '/' . $legacyBase;
$legacyLock = $legacyData . '.lock.php';
$legacyTmp = $legacyData . '.tmp-123-' . str_repeat('a', 8) . '.php';
file_put_contents($legacyData, storeEncode(['expires' => time() + 60, 'value' => 'legacy']));
file_put_contents($legacyLock, BXAPP_PRIVATE_PHP_HEADER);
file_put_contents($legacyTmp, BXAPP_PRIVATE_PHP_HEADER);
$legacyPresent = is_file($legacyData) && is_file($legacyLock) && is_file($legacyTmp);
$migrationPeek = cachePeek('migration-trigger');

echo json_encode([
    'paths' => $firstFile !== $secondFile,
    'values' => $first === 'first' && $second === 'second' && $calls === 2,
    'identity' => is_array($firstRow) && is_array($secondRow)
        && ($firstRow['cache_key'] ?? '') === $firstKey
        && ($secondRow['cache_key'] ?? '') === $secondKey,
    'legacy' => $legacyPresent
        && $migrationPeek === ['hit' => false, 'value' => null, 'stale' => false]
        && !file_exists($legacyData) && !file_exists($legacyLock) && !file_exists($legacyTmp),
]);
PHP;
$cacheKeyIdentityProbe = $runResourceProbe($cacheKeyIdentityCode, '32M');
$cacheKeyIdentityJson = is_array($cacheKeyIdentityProbe['json']) ? $cacheKeyIdentityProbe['json'] : [];
check('разные cache keys не смешиваются при прежней 32-bit коллизии, legacy удалён',
    $cacheKeyIdentityProbe['rc'] === 0 && $cacheKeyIdentityProbe['err'] === ''
    && ($cacheKeyIdentityJson === ['paths' => true, 'values' => true, 'identity' => true, 'legacy' => true]),
    $cacheKeyIdentityProbe['err'] !== '' ? $cacheKeyIdentityProbe['err']
        : json_encode($cacheKeyIdentityJson, JSON_UNESCAPED_UNICODE));

// Обрыв между tmp-write и rename раньше оставлял уникальные tmp/store-lock вне
// family quota. Recoverable failure чистится сразу, process-exit — следующим
// штатным cache-вызовом под тем же owner-lock.
$cacheOrphanCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('DATA_ROOT', $tmp);
define('CACHE_KEY_MAX_BYTES', 32);
define('CACHE_VALUE_MAX_BYTES', 64);
define('CACHE_MAX_BYTES', 512);
define('CACHE_FAMILY_MAX_FILES', 4);
define('CACHE_FAMILY_MAX_BYTES', 4096);
define('CACHE_LOCK_SHARDS', 4);
require $root . '/www/api/cache.php';

$GLOBALS['__failpoints']['store.before_rename'] = true;
$recoverable = false;
try { cacheRemember('recoverable', 60, static fn(): string => 'value'); }
catch (RuntimeException $e) { $recoverable = true; }
$afterRecoverable = glob(DATA_ROOT . '/cache-*.php.tmp-*.php') ?: [];
$recoverableLocks = glob(DATA_ROOT . '/cache-*.php.lock.php') ?: [];

$worker = 'define("DATA_ROOT",' . var_export($tmp, true) . ');'
    . 'define("CACHE_KEY_MAX_BYTES",32);define("CACHE_VALUE_MAX_BYTES",64);'
    . 'define("CACHE_MAX_BYTES",512);define("CACHE_FAMILY_MAX_FILES",4);'
    . 'define("CACHE_FAMILY_MAX_BYTES",4096);define("CACHE_LOCK_SHARDS",4);'
    . 'require ' . var_export($root . '/www/api/cache.php', true) . ';'
    . '$GLOBALS["__failpoints"]["store.before_rename"]=static function(){exit(77);};'
    . 'cacheRemember("crash",60,static fn():string=>"value");';
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open([PHP_BINARY, '-r', $worker], $desc, $pipes, $root);
if (!is_resource($proc)) exit(99);
fclose($pipes[0]);
$out = (string)stream_get_contents($pipes[1]);
$err = (string)stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$workerRc = proc_close($proc);
$beforeNext = [
    'tmp' => count(glob(DATA_ROOT . '/cache-*.php.tmp-*.php') ?: []),
    'locks' => count(glob(DATA_ROOT . '/cache-*.php.lock.php') ?: []),
];
$next = cachePeek('next-normal-call');
$afterNext = [
    'tmp' => count(glob(DATA_ROOT . '/cache-*.php.tmp-*.php') ?: []),
    'locks' => count(glob(DATA_ROOT . '/cache-*.php.lock.php') ?: []),
];
echo json_encode([
    'recoverable' => $recoverable && $afterRecoverable === [] && $recoverableLocks === [],
    'crash' => $workerRc === 77 && $out === '' && $err === ''
        && $beforeNext['tmp'] === 1 && $beforeNext['locks'] === 1,
    'next' => $next === ['hit' => false, 'value' => null, 'stale' => false]
        && $afterNext === ['tmp' => 0, 'locks' => 0],
]);
PHP;
$cacheOrphanProbe = $runResourceProbe($cacheOrphanCode, '32M');
$cacheOrphanJson = is_array($cacheOrphanProbe['json']) ? $cacheOrphanProbe['json'] : [];
check('cache failure/crash не оставляет неограниченные tmp и orphan store-lock',
    $cacheOrphanProbe['rc'] === 0 && $cacheOrphanProbe['err'] === ''
    && ($cacheOrphanJson === ['recoverable' => true, 'crash' => true, 'next' => true]),
    $cacheOrphanProbe['err'] !== '' ? $cacheOrphanProbe['err']
        : json_encode($cacheOrphanJson, JSON_UNESCAPED_UNICODE));

// Reader стартует, когда deleter уже держит family-lock. Он обязан дождаться
// удаления и увидеть miss; прежний lock-free cacheStoreRead мгновенно видел old.
$cacheReadDeleteCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('DATA_ROOT', $tmp);
require $root . '/www/api/cache.php';
cacheRemember('serial', 60, static fn(): string => 'old');
$file = cacheFileFor('serial');
$ready = DATA_ROOT . '/reader-delete-ready';
$worker = 'define("DATA_ROOT",' . var_export($tmp, true) . ');'
    . 'require ' . var_export($root . '/www/api/cache.php', true) . ';'
    . '$file=' . var_export($file, true) . ';$ready=' . var_export($ready, true) . ';'
    . 'cacheWithFamilyLock(static function()use($file,$ready):void{file_put_contents($ready,"1");'
    . 'usleep(300000);if(is_file($file))unlink($file);});';
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open([PHP_BINARY, '-r', $worker], $desc, $pipes, $root);
if (!is_resource($proc)) exit(99);
fclose($pipes[0]);
$deadline = microtime(true) + 2.0;
while (!is_file($ready) && microtime(true) < $deadline) usleep(10000);
$started = microtime(true);
$peek = cachePeek('serial');
$elapsed = microtime(true) - $started;
$out = (string)stream_get_contents($pipes[1]);
$err = (string)stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$workerRc = proc_close($proc);
echo json_encode([
    'worker' => $workerRc === 0 && $out === '' && $err === '',
    'waited' => $elapsed >= 0.20 && $elapsed < 2.0,
    'miss' => $peek === ['hit' => false, 'value' => null, 'stale' => false],
]);
PHP;
$cacheReadDeleteProbe = $runResourceProbe($cacheReadDeleteCode, '32M');
$cacheReadDeleteJson = is_array($cacheReadDeleteProbe['json']) ? $cacheReadDeleteProbe['json'] : [];
check('cache read/delete сериализованы коротким family owner-lock',
    $cacheReadDeleteProbe['rc'] === 0 && $cacheReadDeleteProbe['err'] === ''
    && ($cacheReadDeleteJson === ['worker' => true, 'waited' => true, 'miss' => true]),
    $cacheReadDeleteProbe['err'] !== '' ? $cacheReadDeleteProbe['err']
        : json_encode($cacheReadDeleteJson, JSON_UNESCAPED_UNICODE));

// Реальный cross-file helper из lib должен применять ровно owner ceilings
// ещё до загрузки task/crm modules. Проверяем −1/exact/+1 и SHA no-mutation.
$lifecycleBoundedCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
define('TASK_MAX_BYTES', 2048);
define('CRM_DICT_MAX_BYTES', 1024);
require $root . '/www/api/lib.php';
$rowAt = static function (int $target): array {
    $row = ['padding' => ''];
    while (strlen(storeEncode($row)) < $target) $row['padding'] .= 'x';
    while (strlen(storeEncode($row)) > $target) $row['padding'] = substr($row['padding'], 0, -1);
    if (strlen(storeEncode($row)) !== $target) exit(98);
    return $row;
};
$checkPath = static function (string $file, int $limit) use ($rowAt): array {
    file_put_contents($file, storeEncode($rowAt($limit - 1)));
    appLifecycleStoreWrite($file, []);
    $minus = storeRead($file, $limit) === [];
    file_put_contents($file, storeEncode($rowAt($limit)));
    appLifecycleStoreWrite($file, []);
    $exact = storeRead($file, $limit) === [];
    file_put_contents($file, storeEncode($rowAt($limit + 1)));
    $before = hash_file('sha256', $file);
    $typed = false;
    try { appLifecycleStoreWrite($file, []); }
    catch (StoreReadLimitException $e) { $typed = $e->maxBytes === $limit; }
    return [$minus, $exact, $typed,
        hash_equals((string)$before, (string)hash_file('sha256', $file))];
};
$task = $checkPath(TASK_FILE, TASK_MAX_BYTES);
$crm = $checkPath(CRM_DICT_FILE, CRM_DICT_MAX_BYTES);
$lib = (string)file_get_contents($root . '/www/api/lib.php');
echo json_encode(['task' => $task, 'crm' => $crm,
    'linked' => strpos($lib, 'appLifecycleStoreWrite($file, [])') !== false]);
PHP;
$lifecycleBounded = $runResourceProbe($lifecycleBoundedCode, '32M');
$lifecycleBoundedJson = is_array($lifecycleBounded['json']) ? $lifecycleBounded['json'] : [];
check('lifecycle task/CRM replacement использует owner pre-decode ceilings и no-mutation',
    $lifecycleBounded['rc'] === 0 && $lifecycleBounded['err'] === ''
    && ($lifecycleBoundedJson['task'] ?? []) === [true, true, true, true]
    && ($lifecycleBoundedJson['crm'] ?? []) === [true, true, true, true]
    && !empty($lifecycleBoundedJson['linked']),
    $lifecycleBounded['err'] !== '' ? $lifecycleBounded['err']
        : json_encode($lifecycleBoundedJson, JSON_UNESCAPED_UNICODE));

// Машинный resource-inventory привязан к точкам записи, а не к одному только
// storeUpdate: атомарность store не доказывает предметный предел роста. Число
// вызовов делает добавление writer в уже известный файл тоже заметным.
$stateWriterRows = [
    'www/api/access.php' => [8, 'access/operations', 'ACL и operation keys', 'ACL_MAX_ENTRIES/RUN_ONCE TTL', 'ACL_MAX_BYTES/RUN_ONCE_MAX_BYTES', 'ACL_MAX_BYTES/RUN_ONCE recovery ceiling', 'fail-closed oversize; bounded CLI recovery', 'ACL race/runOnce suites'],
    'www/api/b24.php' => [5, 'oauth/state', 'token refresh и fixed control state', 'fixed schema', 'transport/input caps', 'B24_RESPONSE_MAX_BYTES + fixed schema', 'fixed-schema migration', 'b24/oauth suites'],
    'www/api/cache.php' => [4, 'cache', 'rebuildable values plus exact-key claims in fixed shards', 'CACHE_FAMILY_MAX_FILES/CACHE_LOCK_SHARDS/CACHE_CLAIMS_PER_SHARD', 'CACHE_VALUE_MAX_BYTES/CACHE_MAX_BYTES/CACHE_FAMILY_MAX_BYTES/CACHE_CLAIMS_MAX_BYTES', 'CACHE_MAX_BYTES/CACHE_CLAIMS_MAX_BYTES pre-decode', 'typed reject; legacy fail-closed; streaming cleanup including claim tmp/locks', 'cache resource/concurrency suite'],
    'www/api/capability.php' => [1, 'capability', 'bounded REST method registry', 'portal method inventory', 'bounded REST response', 'bounded REST response', 'replaceable snapshot', 'capability suites'],
    'www/api/crm-dict.php' => [1, 'crm-dict', 'portal labels/codes and merged dictionary', 'CRM_DICT_MAX_FIELDS_PER_ENTITY/CRM_DICT_MAX_ITEMS', 'CRM_DICT_LABEL_MAX_BYTES/CRM_DICT_CODE_MAX_BYTES/CRM_DICT_MAX_BYTES', 'CRM_DICT_MAX_BYTES pre-decode', 'typed candidate reject; legacy fail-closed', 'CRM dictionary resource suite'],
    'www/api/event.php' => [1, 'queue', 'inbox events', 'queue contract', 'INBOX_MAX_BYTES', 'INBOX_MAX_BYTES pre-decode', 'fail-closed oversized legacy', 'queue multiprocess suites'],
    'www/api/events-bind.php' => [2, 'install-state', 'fixed binding fingerprint', 'fixed schema', 'bounded REST response', 'fixed-schema state', 'replaceable fields', 'events-bind suites'],
    'www/api/http.php' => [2, 'rate-limit', 'IP/user buckets', 'API_RATE_STATE_MAX_ITEMS', 'API_RATE_STATE_MAX_BYTES', 'API_RATE_STATE_MAX_BYTES pre-decode', 'fail-closed oversized legacy', 'rate multiprocess suites'],
    'www/api/lib.php' => [13, 'lifecycle/settings', 'fixed state plus bounded settings', 'schema limits', 'settings/input limits', 'TASK_MAX_BYTES/CRM_DICT_MAX_BYTES on lifecycle replace', 'migration manifest + typed legacy fail-closed', 'store/settings/lifecycle suites'],
    'www/api/notify.php' => [3, 'notify', 'outbox and failed rows', 'NOTIFY_MAX_ITEMS', 'NOTIFY_MAX_BYTES', 'NOTIFY_MAX_BYTES pre-decode', 'fail-closed oversized legacy', 'notify multiprocess suites'],
    'www/api/placements.php' => [2, 'install-state', 'fixed placement fingerprint', 'fixed schema', 'bounded REST response', 'fixed-schema state', 'replaceable fields', 'placements suites'],
    'www/api/plan.php' => [2, 'plan/rate', 'fixed portal plan and last-call timestamp', 'fixed schema', 'bounded REST response', 'fixed-schema state', 'replaceable fields', 'plan suites'],
    'www/api/queue.php' => [9, 'queue', 'inbox/dead/done events', 'TTL/count cleanup', 'INBOX/DEADLETTER caps', 'INBOX/DEADLETTER recovery ceilings', 'bounded recovery or fail-closed', 'queue multiprocess suites'],
    'www/api/session.php' => [1, 'sessions', 'viewer sessions', 'SESSION_MAX_TOTAL/PER_USER/DEPARTMENTS', 'SESSION_MAX_ROW_BYTES/SESSION_MAX_BYTES', 'SESSION_MAX_BYTES via sessionStoreRead/sessionStoreUpdate', 'fail-closed oversized legacy + deterministic eviction', 'session resource multiprocess suite'],
    'www/api/task-state.php' => [1, 'task', 'single task cursor/result/error', 'one active task', 'TASK_CURSOR_MAX_BYTES/TASK_TEXT_MAX_BYTES/TASK_MAX_BYTES', 'TASK_MAX_BYTES pre-decode', 'typed candidate reject; legacy fail-closed', 'task resource/multiprocess suites'],
    'www/bin/deploy-private-store.php' => [2, 'deploy canary', 'two fixed canary rows', 'fixed schema', 'fixed marker', 'fixed marker', 'throwaway deploy fixture', 'deploy-private-store suite'],
    'www/bin/process.php' => [3, 'worker-state', 'fixed worker health fields', 'fixed schema', 'bounded scalar fields', 'fixed-schema state', 'replaceable fields', 'process suites'],
    'www/index.php' => [1, 'identify throttle', 'bounded global identify buckets', 'bounded bucket count', 'bounded state bytes', 'bounded state pre-decode', 'fail-closed oversized legacy', 'resource/security suites'],
];
$stateWriterColumns = [
    'writer_calls', 'owner', 'growth_source', 'item_bound', 'byte_bound',
    'read_bound', 'legacy_strategy', 'concurrency_test',
];
$stateWriterInventory = [];
foreach ($stateWriterRows as $path => $row) {
    $stateWriterInventory[$path] = count($row) === count($stateWriterColumns)
        ? array_combine($stateWriterColumns, $row)
        : [];
}
$stateWriterTargetInventory = [
    'www/api/access.php' => ['ACL_FILE' => 1, 'OPS_FILE' => 7],
    'www/api/b24.php' => ['B24_TOKENS_FILE' => 4, 'STATE_FILE' => 1],
    'www/api/cache.php' => ['$claimFile' => 1, '$claimStateFile' => 1, '$file' => 2],
    'www/api/capability.php' => ['CAP_FILE' => 1],
    'www/api/crm-dict.php' => ['CRM_DICT_FILE' => 1],
    'www/api/event.php' => ['INBOX_FILE' => 1],
    'www/api/events-bind.php' => ['STATE_FILE' => 2],
    'www/api/http.php' => ['RATELIMIT_FILE' => 2],
    'www/api/lib.php' => [
        '$file' => 2, '$manifestFile' => 1, 'B24_TOKENS_FILE' => 3,
        'SETTINGS_FILE' => 1, 'STATE_FILE' => 2, 'SWITCHES_FILE' => 2,
        'appStateTransitionManifestFile()' => 2,
    ],
    'www/api/notify.php' => ['NOTIFY_OUTBOX_FILE' => 3],
    'www/api/placements.php' => ['STATE_FILE' => 2],
    'www/api/plan.php' => ['PLAN_FILE' => 1, 'RATE_FILE' => 1],
    'www/api/queue.php' => ['$file' => 5, '$inbox' => 1, 'queueDoneFile()' => 3],
    'www/api/session.php' => ['SESSIONS_FILE' => 1],
    'www/api/task-state.php' => ['TASK_FILE' => 1],
    'www/bin/deploy-private-store.php' => ['$httpLogCanaryFile' => 1, '$stateFile' => 1],
    'www/bin/process.php' => ['STATE_FILE' => 3],
    'www/index.php' => ['$throttleFile' => 1],
];
// ФАЙЛЫ ПРОЕКТА в этот реестр не попадают и не должны: он описывает состояние
// ШАБЛОНА, а приложение конкретного заказчика заводит своё. Пока фильтра не
// было, первый же проектный модуль с собственным состоянием делал самопроверку
// красной, и единственным выходом была правка НАБОРА ШАБЛОНА — то есть файла,
// который обновляется из шаблона и после правки обновляться перестанет.
//
// Список проектных файлов берём не «по имени», а из реестра расширений
// docs/capabilities.json: чтобы файл туда попал, проект обязан назвать его
// тесты и выполненные проверки, и другой забор это отдельно стережёт.
$projectOwnedFiles = [];
$registryRaw = @file_get_contents($resourceTemplateRoot . '/docs/capabilities.json');
$registryData = is_string($registryRaw) ? json_decode($registryRaw, true) : null;
foreach ((array)($registryData['project_extensions'] ?? []) as $extension) {
    foreach (array_merge([(string)($extension['file'] ?? '')],
                         (array)($extension['companions'] ?? [])) as $ownFile) {
        $ownFile = trim((string)$ownFile);
        if ($ownFile !== '') $projectOwnedFiles[$ownFile] = true;
    }
}

$writerActual = [];
$writerTargetsActual = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $resourceTemplateRoot . '/www', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $info) {
    if (!$info->isFile() || strtolower($info->getExtension()) !== 'php') continue;
    $relative = str_replace('\\', '/', substr($info->getPathname(), strlen($resourceTemplateRoot) + 1));
    if (isset($projectOwnedFiles[$relative])) continue;  // состояние проекта, не шаблона
    if ($relative === 'www/api/store.php') continue; // реализация primitive, не caller state
    $tokens = token_get_all((string)file_get_contents($info->getPathname()));
    $calls = 0;
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING
            || ($token[1] !== 'storeUpdate' && $token[1] !== 'storeWrite')) continue;
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j])
            && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
        if (($tokens[$j] ?? null) !== '(') continue;
        $calls++;
        $j++;
        $depth = 1;
        $target = '';
        for (; isset($tokens[$j]); $j++) {
            $part = $tokens[$j];
            $text = is_array($part) ? $part[1] : $part;
            if ($text === '(') $depth++;
            if ($text === ')') $depth--;
            if ($text === ',' && $depth === 1) break;
            if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $target .= $text;
        }
        $writerTargetsActual[$relative][$target] = (int)($writerTargetsActual[$relative][$target] ?? 0) + 1;
    }
    if ($calls > 0) $writerActual[$relative] = $calls;
}
ksort($writerActual);
ksort($writerTargetsActual);
foreach ($writerTargetsActual as &$targets) ksort($targets);
unset($targets);
ksort($stateWriterTargetInventory);
foreach ($stateWriterTargetInventory as &$targets) ksort($targets);
unset($targets);
$inventoryCalls = array_map(static fn(array $row): int => (int)($row['writer_calls'] ?? -1), $stateWriterInventory);
ksort($inventoryCalls);
$inventoryShape = true;
foreach ($stateWriterInventory as $row) {
    if (array_keys($row) !== $stateWriterColumns
        || array_filter(array_slice($row, 1), static fn($v): bool => !is_string($v) || $v === '') !== []) {
        $inventoryShape = false;
    }
}
// ОБРАЗЕЦ ПОСЛАБЛЕНИЯ: из инвентаря выпадает ТОЛЬКО то, что проект объявил
// своим расширением. Файл ядра таким способом спрятать нельзя — иначе
// послабление стало бы способом вывести state шаблона из-под надзора.
check('образец: послабление проектных файлов не прячет ни одного файла ядра',
    !isset($projectOwnedFiles['www/api/lib.php'])
    && !isset($projectOwnedFiles['www/api/session.php'])
    && !isset($projectOwnedFiles['www/bin/process.php']));

check('resource inventory фиксирует path/target и owner/growth/bounds/legacy/concurrency каждого writer',
    $writerActual === $inventoryCalls
    && $writerTargetsActual === $stateWriterTargetInventory
    && $inventoryShape,
    $writerActual === $inventoryCalls && $writerTargetsActual === $stateWriterTargetInventory
        ? '' : json_encode(['actual' => $writerTargetsActual, 'inventory' => $stateWriterTargetInventory]));

// Для sessions одной строки inventory недостаточно: машинно связываем её с
// конкретными helpers/constant и запрещаем второй прямой read/update в обход cap.
$sessionSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/session.php');
$sessionDirectReads = preg_match_all('~storeRead\(\s*SESSIONS_FILE~', $sessionSource);
$sessionDirectUpdates = preg_match_all('~storeUpdate\(\s*SESSIONS_FILE~', $sessionSource);
check('session resource inventory привязан к общему byte/pre-decode helper без bypass',
    strpos($sessionSource, 'const SESSION_MAX_DEPARTMENTS') !== false
    && strpos($sessionSource, 'const SESSION_MAX_ROW_BYTES') !== false
    && strpos($sessionSource, 'const SESSION_MAX_BYTES') !== false
    && preg_match('~storeRead\(\s*SESSIONS_FILE\s*,\s*SESSION_MAX_BYTES\s*\)~', $sessionSource) === 1
    && preg_match('~storeUpdate\(\s*SESSIONS_FILE\s*,[\s\S]*STORE_LOCK_TIMEOUT\s*,\s*SESSION_MAX_BYTES\s*\)~', $sessionSource) === 1
    && $sessionDirectReads === 1 && $sessionDirectUpdates === 1
    // 9-й — одна запись на всех заблокированных (ревью 23.09.2026, skorost-6).
    && substr_count($sessionSource, 'sessionStoreUpdate(') === 9,
    json_encode(['reads' => $sessionDirectReads, 'updates' => $sessionDirectUpdates,
        'wrapped' => substr_count($sessionSource, 'sessionStoreUpdate(')]));

// Те же гарантии для новых владельцев: ровно один bounded read/write primitive,
// а все публичные пути идут через предметный wrapper с candidate-validation.
$cacheSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/cache.php');
$taskSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/task-state.php');
$crmDictSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/crm-dict.php');
$storeSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/store.php');
$libSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/lib.php');
$cacheDirectReads = preg_match_all('~storeRead\(\s*\$file~', $cacheSource);
$cacheDirectUpdates = preg_match_all('~storeUpdate\(\s*\$file~', $cacheSource);
$taskDirectReads = preg_match_all('~storeRead\(\s*TASK_FILE~', $taskSource);
$taskDirectUpdates = preg_match_all('~storeUpdate\(\s*TASK_FILE~', $taskSource);
$crmDirectReads = preg_match_all('~storeRead\(\s*CRM_DICT_FILE~', $crmDictSource);
$crmDirectUpdates = preg_match_all('~storeUpdate\(\s*CRM_DICT_FILE~', $crmDictSource);
check('cache/task/CRM inventory машинно привязан к bounded helpers без bypass',
    preg_match('~storeRead\(\s*\$file\s*,\s*CACHE_MAX_BYTES\s*\)~', $cacheSource) === 1
    && preg_match_all('~STORE_LOCK_TIMEOUT\s*,\s*CACHE_MAX_BYTES\s*\)~', $cacheSource) === 2
    && $cacheDirectReads === 1 && $cacheDirectUpdates === 2
    && substr_count($cacheSource, 'cacheStoreRead(') === 2
    && substr_count($cacheSource, 'cacheStoreUpdate(') === 2
    && strpos($cacheSource, "define('CACHE_FAMILY_MAX_FILES'") !== false
    && strpos($cacheSource, "define('CACHE_FAMILY_MAX_BYTES'") !== false
    && strpos($cacheSource, "define('CACHE_LOCK_SHARDS'") !== false
    && strpos($cacheSource, "define('CACHE_CLAIMS_PER_SHARD'") !== false
    && strpos($cacheSource, "define('CACHE_CLAIMS_MAX_BYTES'") !== false
    && strpos($cacheSource, 'cacheWithFamilyLock(') !== false
    && strpos($cacheSource, 'cacheWithEntryClaim(') !== false
    && preg_match('~storeUpdate\(\s*\$claimStateFile\s*,[\s\S]*CACHE_CLAIMS_MAX_BYTES\s*\)~', $cacheSource) === 1
    && strpos($cacheSource, 'cache-entry-claims-') !== false
    && preg_match('~function\s+cacheStoreRead\([^}]+return\s+cacheWithFamilyLock~s', $cacheSource) === 1
    && substr_count($cacheSource, 'cacheCleanupOwnedOrphans();') === 2
    && strpos($cacheSource, 'cacheAssertFamilyCandidate(') !== false
    && strpos($cacheSource, 'cacheForgetAll(') !== false
    && preg_match('~storeRead\(\s*TASK_FILE\s*,\s*TASK_MAX_BYTES\s*\)~', $taskSource) === 1
    && preg_match('~storeUpdate\(\s*TASK_FILE\s*,\s*\$mutator\s*,\s*STORE_LOCK_TIMEOUT\s*,\s*TASK_MAX_BYTES\s*,[\s\S]*taskAssertStateWithinLimits\(\$next\)~', $taskSource) === 1
    && $taskDirectReads === 1 && $taskDirectUpdates === 1
    && substr_count($taskSource, 'taskStoreRead(') === 2
    // 10-й — taskResolveManually() (ревью 23.09.2026, ux_sboev-1): тот же bounded wrapper.
    && substr_count($taskSource, 'taskStoreUpdate(') === 10
    && preg_match('~storeRead\(\s*CRM_DICT_FILE\s*,\s*CRM_DICT_MAX_BYTES\s*\)~', $crmDictSource) === 1
    && preg_match('~storeUpdate\(\s*CRM_DICT_FILE\s*,\s*\$mutator\s*,\s*STORE_LOCK_TIMEOUT\s*,\s*CRM_DICT_MAX_BYTES\s*,[\s\S]*crmDictAssertStateWithinLimits\(\$next\)~', $crmDictSource) === 1
    && $crmDirectReads === 1 && $crmDirectUpdates === 1
    && substr_count($crmDictSource, 'crmDictStoreRead(') === 2
    && substr_count($crmDictSource, 'crmDictStoreUpdate(') === 2
    && strpos($storeSource, "define('TASK_MAX_BYTES'") !== false
    && strpos($storeSource, "define('CRM_DICT_MAX_BYTES'") !== false
    && preg_match('~function\s+storeWrite\([^)]*int\s+\$maxReadBytes\s*=\s*0~', $storeSource) === 1
    && strpos($libSource, 'if ($file === TASK_FILE) $maxReadBytes = TASK_MAX_BYTES;') !== false
    && strpos($libSource, 'elseif ($file === CRM_DICT_FILE) $maxReadBytes = CRM_DICT_MAX_BYTES;') !== false
    && strpos($libSource, 'appLifecycleStoreWrite($file, [])') !== false,
    json_encode([
        'cache' => [$cacheDirectReads, $cacheDirectUpdates, substr_count($cacheSource, 'cacheStoreUpdate(')],
        'task' => [$taskDirectReads, $taskDirectUpdates, substr_count($taskSource, 'taskStoreUpdate(')],
        'crm' => [$crmDirectReads, $crmDirectUpdates, substr_count($crmDictSource, 'crmDictStoreUpdate(')],
    ]));

// Общий state.php читается ещё в env.php, до загрузки store.php. Его предел
// обязан действовать и на bootstrap, и на каждый последующий reader/writer.
$stateLimit = 2 * 1024 * 1024;
$stateBootstrapProbe = static function (int $bytes) use ($runResourceProbe, $resourceChildPrelude, $resourceTemplateRoot, $stateLimit): array {
    $code = $resourceChildPrelude . "\n"
        . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
        . '$target = ' . $bytes . ";\n"
        . '$limit = ' . $stateLimit . ";\n"
        . <<<'PHP'
$prefix = "<?php http_response_code(404); exit; /* приватный файл bx-app */ ?>\n";
$base = json_encode(['app_url' => 'https://state-limit.test', 'pad' => '']);
$pad = $target - strlen($prefix . $base);
if ($pad < 0) exit(92);
$payload = $prefix . json_encode(['app_url' => 'https://state-limit.test', 'pad' => str_repeat('x', $pad)]);
if (strlen($payload) !== $target || file_put_contents($tmp . '/state.php', $payload) !== $target) exit(93);
$loaded = false;
$rejected = false;
try {
    require $root . '/www/env.php';
    $loaded = defined('APP_URL') && APP_URL === 'https://state-limit.test';
} catch (Throwable $e) {
    $rejected = $e instanceof StateFileLimitException
        && $e->getCode() === 1701 && $e->getMessage() === 'state_file_too_large:read';
}
echo json_encode(['loaded' => $loaded, 'rejected' => $rejected, 'size' => filesize($tmp . '/state.php')]);
PHP;
    return $runResourceProbe($code, '32M');
};

$bootstrapMinus = $stateBootstrapProbe($stateLimit - 1);
$bootstrapExact = $stateBootstrapProbe($stateLimit);
$bootstrapPlus = $stateBootstrapProbe($stateLimit + 1);
$bootstrapMinusJson = is_array($bootstrapMinus['json']) ? $bootstrapMinus['json'] : [];
$bootstrapExactJson = is_array($bootstrapExact['json']) ? $bootstrapExact['json'] : [];
$bootstrapPlusJson = is_array($bootstrapPlus['json']) ? $bootstrapPlus['json'] : [];
check('STATE_FILE bootstrap bounded: limit-1/limit проходят, limit+1 typed fail-closed',
    $bootstrapMinus['rc'] === 0 && $bootstrapExact['rc'] === 0 && $bootstrapPlus['rc'] === 0
    && $bootstrapMinus['err'] === '' && $bootstrapExact['err'] === '' && $bootstrapPlus['err'] === ''
    && !empty($bootstrapMinusJson['loaded']) && empty($bootstrapMinusJson['rejected'])
    && !empty($bootstrapExactJson['loaded']) && empty($bootstrapExactJson['rejected'])
    && empty($bootstrapPlusJson['loaded']) && !empty($bootstrapPlusJson['rejected'])
    && (int)($bootstrapPlusJson['size'] ?? 0) === $stateLimit + 1,
    json_encode(['minus' => $bootstrapMinusJson, 'exact' => $bootstrapExactJson, 'plus' => $bootstrapPlusJson]));

$stateWriterCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . '$expectedLimit = ' . $stateLimit . ";\n"
    . <<<'PHP'
require $root . '/www/env.php';
require $root . '/www/api/store.php';
$make = static function (int $bytes): array {
    $base = storeEncode(['pad' => '']);
    $pad = $bytes - strlen($base);
    if ($pad < 0) throw new RuntimeException('fixture too small');
    $row = ['pad' => str_repeat('x', $pad)];
    if (strlen(storeEncode($row)) !== $bytes) throw new RuntimeException('fixture size mismatch');
    return $row;
};
$exact = $make($expectedLimit);
storeWrite(STATE_FILE, $exact);
$before = hash_file('sha256', STATE_FILE);
$readExact = storeRead(STATE_FILE);
$rejected = false;
try { storeWrite(STATE_FILE, $make($expectedLimit + 1)); }
catch (Throwable $e) {
    $rejected = $e instanceof StateFileLimitException
        && $e->getCode() === 1702 && $e->getMessage() === 'state_file_too_large:write';
}
echo json_encode([
    'contract' => defined('STATE_MAX_BYTES') && STATE_MAX_BYTES === $expectedLimit,
    'exact' => is_array($readExact) && strlen((string)($readExact['pad'] ?? '')) === strlen($exact['pad'])
        && filesize(STATE_FILE) === $expectedLimit,
    'plus' => $rejected,
    'preserved' => hash_file('sha256', STATE_FILE) === $before && filesize(STATE_FILE) === $expectedLimit,
]);
PHP;
$stateWriterProbe = $runResourceProbe($stateWriterCode, '32M');
$stateWriterJson = is_array($stateWriterProbe['json']) ? $stateWriterProbe['json'] : [];
check('STATE_FILE writer bounded: exact publish, limit+1 reject сохраняет прежние байты',
    $stateWriterProbe['rc'] === 0 && $stateWriterProbe['err'] === ''
    && array_keys($stateWriterJson) === ['contract', 'exact', 'plus', 'preserved']
    && !in_array(false, array_map('boolval', $stateWriterJson), true),
    $stateWriterProbe['err'] !== '' ? $stateWriterProbe['err'] : json_encode($stateWriterJson));

$stateRaceCode = $resourceChildPrelude . "\n"
    . '$root = ' . var_export($resourceTemplateRoot, true) . ";\n"
    . <<<'PHP'
require $root . '/www/env.php';
require $root . '/www/api/store.php';
storeWrite(STATE_FILE, ['counts' => []]);
$workers = [];
for ($worker = 0; $worker < 4; $worker++) {
    $workerCode = 'putenv(' . var_export('BXAPP_DATA_ROOT=' . $tmp, true) . ');'
        . '$root=' . var_export($root, true) . ';require $root."/www/env.php";require $root."/www/api/store.php";'
        . '$worker=' . $worker . ';for($i=0;$i<20;$i++)storeUpdate(STATE_FILE,static function(array $s)use($worker):array{'
        . '$s["counts"][$worker]=(int)($s["counts"][$worker]??0)+1;return $s;});';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $workerCode], $desc, $pipes, $root);
    if (!is_resource($proc)) exit(94);
    fclose($pipes[0]);
    $workers[] = [$proc, $pipes];
}
$workersOk = true;
foreach ($workers as [$proc, $pipes]) {
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $out !== '' || $err !== '') $workersOk = false;
}
$state = storeRead(STATE_FILE);
$countsOk = is_array($state['counts'] ?? null) && count($state['counts']) === 4;
foreach (range(0, 3) as $worker) $countsOk = $countsOk && (int)($state['counts'][$worker] ?? 0) === 20;
echo json_encode(['workers' => $workersOk, 'counts' => $countsOk, 'bounded' => filesize(STATE_FILE) <= STATE_MAX_BYTES]);
PHP;
$stateRaceProbe = $runResourceProbe($stateRaceCode, '32M');
$stateRaceJson = is_array($stateRaceProbe['json']) ? $stateRaceProbe['json'] : [];
check('STATE_FILE multiprocess RMW не теряет обновления и остаётся bounded',
    $stateRaceProbe['rc'] === 0 && $stateRaceProbe['err'] === ''
    && array_keys($stateRaceJson) === ['workers', 'counts', 'bounded']
    && !in_array(false, array_map('boolval', $stateRaceJson), true),
    $stateRaceProbe['err'] !== '' ? $stateRaceProbe['err'] : json_encode($stateRaceJson));

$envStateSource = (string)file_get_contents($resourceTemplateRoot . '/www/env.php');
$storeStateSource = (string)file_get_contents($resourceTemplateRoot . '/www/api/store.php');
$stateRawBypasses = [];
$statePathAliases = [];
$stateIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $resourceTemplateRoot . '/www', FilesystemIterator::SKIP_DOTS));
foreach ($stateIterator as $stateInfo) {
    if (!$stateInfo->isFile() || strtolower($stateInfo->getExtension()) !== 'php') continue;
    $stateRelative = str_replace('\\', '/', substr($stateInfo->getPathname(), strlen($resourceTemplateRoot) + 1));
    if ($stateRelative === 'www/env.php' || $stateRelative === 'www/api/store.php') continue;
    $stateSource = (string)file_get_contents($stateInfo->getPathname());
    preg_match_all('~\$(\w+)\s*=\s*(?:STATE_FILE|DATA_ROOT\s*\.\s*[\'\"]\/state\.php[\'\"])\s*;~',
        $stateSource, $aliasMatches);
    $aliases = array_values(array_unique((array)($aliasMatches[1] ?? [])));
    foreach ($aliases as $alias) $statePathAliases[] = $stateRelative . ':$' . $alias;
    preg_match_all('~\b(?:file_get_contents|file_put_contents|fopen|readfile|unlink|rename|copy)\s*\(([^;\n]*)~',
        $stateSource, $rawCalls, PREG_SET_ORDER);
    foreach ($rawCalls as $rawCall) {
        $args = (string)($rawCall[1] ?? '');
        $targetsState = strpos($args, 'STATE_FILE') !== false
            || preg_match('~DATA_ROOT\s*\.\s*[\'\"]\/state\.php[\'\"]~', $args) === 1;
        foreach ($aliases as $alias) {
            if (strpos($args, '$' . $alias) !== false) $targetsState = true;
        }
        if ($targetsState) $stateRawBypasses[] = $stateRelative . ':' . trim($args);
    }
}
sort($statePathAliases);
sort($stateRawBypasses);
check('STATE_FILE sibling inventory запрещает raw reader/writer и фиксирует единственный legacy alias',
    $stateRawBypasses === [] && $statePathAliases === ['www/index.php:$throttleFile'],
    json_encode(['raw' => $stateRawBypasses, 'aliases' => $statePathAliases], JSON_UNESCAPED_UNICODE));
$envGateGood = tempnam(sys_get_temp_dir(), 'bx17-env-good-');
$envGateBad = tempnam(sys_get_temp_dir(), 'bx17-env-bad-');
file_put_contents($envGateGood, "<?php\nclass StateFileLimitException extends RuntimeException {}\n"
    . "if (is_file(STATE_FILE)) { \$s=lstat(STATE_FILE); \$n=strlen((string)file_get_contents(STATE_FILE)); "
    . "if (\$n > STATE_MAX_BYTES) throw new StateFileLimitException('state_file_too_large:read',1701); }\n");
file_put_contents($envGateBad, "<?php\nthrow new RuntimeException('arbitrary early object');\n");
$envGateGoodHits = gateEnvEffectLines($envGateGood);
$envGateBadHits = gateEnvEffectLines($envGateBad);
@unlink($envGateGood); @unlink($envGateBad);
check('env gate разрешает только typed STATE_FILE bound и не открывает произвольный early new',
    $envGateGoodHits === [] && $envGateBadHits !== [],
    json_encode(['good' => $envGateGoodHits, 'bad' => $envGateBadHits]));
check('STATE_FILE cap имеет одного владельца и охватывает bootstrap/store read/write без bypass',
    preg_match('~const\s+STATE_MAX_BYTES\s*=\s*2\s*\*\s*1024\s*\*\s*1024\s*;~', $envStateSource) === 1
    && substr_count($envStateSource, 'class StateFileLimitException') === 1
    && strpos($envStateSource, '$readPrivateState(STATE_FILE, STATE_MAX_BYTES, \'state\')') !== false
    && strpos($envStateSource, 'file_get_contents($file, false, null, 0, $maxBytes + 1)') !== false
    && strpos($envStateSource, "(int)(\$stat['size'] ?? (\$maxBytes + 1)) > \$maxBytes") !== false
    && strpos($envStateSource, 'strlen($raw) > $maxBytes') !== false
    && strpos($storeStateSource, '$maxBytes = STATE_MAX_BYTES') !== false
    && strpos($storeStateSource, '$maxBytes + 1') !== false
    && strpos($storeStateSource, 'strlen($payload) > STATE_MAX_BYTES') !== false
    && substr_count($storeStateSource, 'class StateFileLimitException') === 0,
    json_encode(['env_owner' => substr_count($envStateSource, 'class StateFileLimitException'),
        'store_owner' => substr_count($storeStateSource, 'class StateFileLimitException')]));
