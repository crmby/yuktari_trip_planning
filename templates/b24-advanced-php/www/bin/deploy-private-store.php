<?php
declare(strict_types=1);

/**
 * CLI-only preflight приватного store для deploy-prod.sh.
 *
 * Проверяет не декларацию shell-скрипта, а исполняемый env.php конкретного
 * release: DATA_ROOT обязан совпасть с private shared, creds — лежать там же,
 * запись и чтение через штатный store, а также реальный journal writer должны
 * работать. Созданные HTTP-canary затем запрашиваются снаружи и обязаны дать 404.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 3) {
    fwrite(STDERR, "Использование: php deploy-private-store.php <expected-data-root> <release>\n");
    exit(64);
}

$expectedRoot = rtrim((string)$argv[1], '/');
$expectedRelease = (string)$argv[2];
if ($expectedRoot === '' || preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$/D', $expectedRelease) !== 1) {
    fwrite(STDERR, "Private-store preflight: недопустимые аргументы.\n");
    exit(64);
}

require_once dirname(__DIR__) . '/env.php';
require_once dirname(__DIR__) . '/api/store.php';
require_once dirname(__DIR__) . '/api/journal.php';

$actualRoot = rtrim(DATA_ROOT, '/');
$actualReal = realpath($actualRoot);
$expectedReal = realpath($expectedRoot);
if ($actualReal === false || $expectedReal === false || $actualReal !== $expectedReal) {
    fwrite(STDERR, "Private-store preflight: runtime DATA_ROOT не совпал с private shared.\n");
    exit(65);
}
if (!defined('APP_RELEASE') || APP_RELEASE !== $expectedRelease) {
    fwrite(STDERR, "Private-store preflight: env загружен не из ожидаемого release.\n");
    exit(65);
}
if (B24_CREDS_FILE !== $actualRoot . '/creds.php') {
    fwrite(STDERR, "Private-store preflight: creds ушли из private DATA_ROOT.\n");
    exit(65);
}

// Контролируемые компоненты private layout не могут быть symlink. Иначе shell
// проверит один каталог, а PHP запишет canary/state в чужое дерево.
foreach ([$actualRoot, dirname($actualRoot), dirname($actualRoot, 2)] as $path) {
    if (is_link($path) || !is_dir($path)) {
        fwrite(STDERR, "Private-store preflight: небезопасный компонент private path.\n");
        exit(65);
    }
}

$logDir = $actualRoot . '/logs';
if (is_link($logDir) || (file_exists($logDir) && !is_dir($logDir))) {
    fwrite(STDERR, "Private-store preflight: logs — symlink или не каталог.\n");
    exit(65);
}
if (!is_dir($logDir) && !@mkdir($logDir, 0700, true)) {
    fwrite(STDERR, "Private-store preflight: не создать logs.\n");
    exit(65);
}
if (!@chmod($logDir, 0700)) {
    fwrite(STDERR, "Не выставить приватные права на каталог журнала\n");
    exit(1);
}

$marker = 'BXAPP-READBACK-' . $expectedRelease;
$stateFile = $actualRoot . '/deploy-canary-' . $expectedRelease . '.php';
$httpLogCanaryFile = $logDir . '/deploy-canary-' . $expectedRelease . '.log.php';
foreach ([$stateFile, $httpLogCanaryFile] as $file) {
    if (is_link($file) || file_exists($file)) {
        fwrite(STDERR, "Private-store preflight: canary path уже занят.\n");
        exit(65);
    }
}

storeWrite($stateFile, ['marker' => $marker, 'kind' => 'state']);
// Отдельный предсказуемый URL нужен внешнему HTTP-probe. Сам journal ниже
// проверяем его настоящим writer'ом, а не выдаём storeWrite за журнал.
storeWrite($httpLogCanaryFile, ['marker' => $marker, 'kind' => 'http-log-path']);
$state = storeRead($stateFile);
$httpLogCanary = storeRead($httpLogCanaryFile);
if (($state['marker'] ?? null) !== $marker || ($httpLogCanary['marker'] ?? null) !== $marker) {
    fwrite(STDERR, "Private-store preflight: штатный store не прочитал canary обратно.\n");
    exit(66);
}
$expectedHeader = defined('BXAPP_RELEASE_WRITE_LEGACY') && BXAPP_RELEASE_WRITE_LEGACY === true
    ? BXAPP_PRIVATE_PHP_LEGACY_HEADER
    : BXAPP_PRIVATE_PHP_404_HEADER;
foreach ([$stateFile, $httpLogCanaryFile] as $file) {
    $raw = (string)@file_get_contents($file);
    if (strncmp($raw, $expectedHeader, strlen($expectedHeader)) !== 0) {
        fwrite(STDERR, "Private-store preflight: writer не соблюдает выбранный release-префикс.\n");
        exit(66);
    }
}

$journalLine = json_encode([
    'ts' => date('c'), 'level' => 'info', 'src' => 'deploy',
    'event' => 'deploy.private_store_probe', 'marker' => $marker,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($journalLine)) {
    fwrite(STDERR, "Private-store preflight: не собрать journal-canary.\n");
    exit(66);
}
$journalFile = journalFile();
$journalExisted = is_file($journalFile);
$journalResult = journalAppend($journalLine . "\n");
$journalRaw = is_file($journalFile)
    ? (string)@file_get_contents(DATA_ROOT . '/' . LOG_DIR_NAME . '/' . date('Y-m-d') . '.log.php')
    : '';
$journalPrefixOk = $journalExisted
    ? privatePhpHasExecutablePrefix($journalRaw)
    : strncmp($journalRaw, $expectedHeader, strlen($expectedHeader)) === 0;
if (empty($journalResult['written'])
    || !$journalPrefixOk
    || strpos($journalRaw, $marker) === false) {
    fwrite(STDERR, "Private-store preflight: реальный journal writer/read-back не подтверждён.\n");
    exit(66);
}

fwrite(STDOUT, "BXAPP_PRIVATE_STORE_OK:{$expectedRelease}\n");
