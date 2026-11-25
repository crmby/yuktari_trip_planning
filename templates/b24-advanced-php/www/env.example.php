<?php
declare(strict_types=1);

// No output, provisioning, network, migrations or auth decisions on bootstrap.
if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

class StateFileLimitException extends RuntimeException {}
const STATE_MAX_BYTES = 2 * 1024 * 1024;
const B24_CREDS_MAX_BYTES = 64 * 1024;
define('BXAPP_ENV_SCHEMA', 13);
define('APP_PATH', __DIR__);
// Optional operator-owned immutable release metadata; never generated or executed by packaging.
if (is_file(__DIR__ . '/release.php')) {
    if (is_link(__DIR__ . '/release.php')) throw new StateFileLimitException('config_invalid:release', 1703);
    require __DIR__ . '/release.php';
}
$root = defined('BXAPP_RELEASE_DATA_ROOT') ? (string)BXAPP_RELEASE_DATA_ROOT
    : (string)(getenv('BXAPP_DATA_ROOT') ?: getenv('APP_DATA_ROOT') ?: dirname(__DIR__) . '/data');
$root = rtrim($root, '/');
$realRoot = realpath($root);
$public = realpath(__DIR__);
$documentRoot = realpath((string)(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: __DIR__));
if ($root === '' || $root[0] !== '/' || $realRoot === false || $realRoot !== $root
    || $root === '/' || $root === $public || strpos($root, $public . '/') === 0
    || ($documentRoot !== false && ($root === $documentRoot || strpos($root, $documentRoot . '/') === 0))
    || (defined('APP_RELEASE') && strpos($root . '/', dirname(__DIR__) . '/') === 0)) {
    throw new StateFileLimitException('config_invalid:private_root', 1703);
}
// Reject symlink ancestors, not just a symlink at the leaf. Provision as 0700 before use.
$part = $root;
while ($part !== '/' && $part !== '.') {
    if (is_link($part)) throw new StateFileLimitException('config_invalid:private_symlink', 1703);
    $part = dirname($part);
}
$rootStat = @lstat($root);
if (!is_array($rootStat) || (($rootStat['mode'] & 0170000) !== 0040000)
    || (($rootStat['mode'] & 0077) !== 0) || (($rootStat['mode'] & 0700) !== 0700)) {
    throw new StateFileLimitException('config_invalid:private_permissions', 1703);
}
define('DATA_ROOT', $root);
define('B24_CREDS_FILE', (string)(getenv('BXAPP_CREDS_FILE') ?: DATA_ROOT . '/creds.php'));
if (dirname(B24_CREDS_FILE) !== DATA_ROOT || substr(B24_CREDS_FILE, -4) !== '.php') {
    throw new StateFileLimitException('config_invalid:creds_path', 1703);
}
define('B24_TOKENS_FILE', DATA_ROOT . '/b24-tokens.php');
define('SETTINGS_FILE', DATA_ROOT . '/settings.php');
define('SESSIONS_FILE', DATA_ROOT . '/sessions.php');
define('ACL_FILE', DATA_ROOT . '/acl.php');
define('STATE_FILE', DATA_ROOT . '/state.php');
define('SWITCHES_FILE', DATA_ROOT . '/switches.php');
define('CAP_FILE', DATA_ROOT . '/cap.php');
define('CRM_DICT_FILE', DATA_ROOT . '/crm-dict.php');
define('DEADLETTER_FILE', DATA_ROOT . '/deadletter.php');
define('INBOX_FILE', DATA_ROOT . '/inbox.php');
define('NOTIFY_OUTBOX_FILE', DATA_ROOT . '/notify-outbox.php');
define('OPS_FILE', DATA_ROOT . '/ops.php');
define('PLAN_FILE', DATA_ROOT . '/plan.php');
define('QUEUE_DONE_FILE', DATA_ROOT . '/queue-done.php');
define('RATELIMIT_FILE', DATA_ROOT . '/ratelimit.php');
define('RATE_FILE', DATA_ROOT . '/rate.php');
define('TASK_FILE', DATA_ROOT . '/task.php');

// Read DATA, never require a state file. lstat and bounded read both precede JSON decode.
$bxappReadPrivateState = static function (string $file, int $maxBytes, string $label): ?array {
    clearstatcache(true, $file);
    $stat = @lstat($file);
    if ($stat === false) {
        if (file_exists($file) || is_link($file)) throw new StateFileLimitException('state_file_unreadable:' . $label, 1704);
        return null;
    }
    if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
        || (int)($stat['nlink'] ?? 0) !== 1 || is_link($file)) {
        throw new StateFileLimitException('state_file_invalid:' . $label, 1703);
    }
    if ((int)($stat['size'] ?? ($maxBytes + 1)) > $maxBytes) {
        throw new StateFileLimitException('state_file_too_large:read', 1701);
    }
    $raw = @file_get_contents($file, false, null, 0, $maxBytes + 1);
    if (!is_string($raw)) throw new StateFileLimitException('state_file_unreadable:' . $label, 1704);
    if (strlen($raw) > $maxBytes) throw new StateFileLimitException('state_file_too_large:read', 1701);
    $header = "<?php http_response_code(404); exit; /* приватный файл bx-app */ ?>\n";
    $legacy = "<?php exit; /* private bx-app rollback bridge */ ?>\n";
    if (strncmp($raw, $header, strlen($header)) === 0) $raw = substr($raw, strlen($header));
    elseif (strncmp($raw, $legacy, strlen($legacy)) === 0) $raw = substr($raw, strlen($legacy));
    else throw new StateFileLimitException('state_file_corrupt:' . $label, 1705);
    $decoded = json_decode($raw, true, 32);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new StateFileLimitException('state_file_corrupt:' . $label, 1705);
    }
    return $decoded;
};
$readPrivateState = $bxappReadPrivateState;
$creds = $readPrivateState(B24_CREDS_FILE, B24_CREDS_MAX_BYTES, 'creds') ?? [];
$state = [];
$stateFailure = false;
try {
    $data = $readPrivateState(STATE_FILE, STATE_MAX_BYTES, 'state');
    $state = $data ?? [];
} catch (StateFileLimitException $e) {
    // Diagnostic bootstrap may report an unreadable state, but still goes through apiBoot admin auth.
    $healthScript = realpath(__DIR__ . '/api/health.php');
    if ($healthScript === false || realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== $healthScript) throw $e;
    $categories = [1701 => 'too_large', 1703 => 'invalid', 1704 => 'unreadable', 1705 => 'corrupt'];
    $GLOBALS['__bxapp_state_bootstrap'] = [
        'error' => (string)($categories[$e->getCode()] ?? 'invalid'),
    ];
    $stateFailure = true;
}
// Credentials file wins, so replacing public config cannot erase private credentials.
$clientId = $creds['client_id'] ?? (getenv('BXAPP_CLIENT_ID') ?: getenv('B24_CLIENT_ID') ?: '');
$clientSecret = $creds['client_secret'] ?? (getenv('BXAPP_CLIENT_SECRET') ?: getenv('B24_CLIENT_SECRET') ?: '');
if (!is_string($clientId) || trim($clientId) === '' || !is_string($clientSecret) || trim($clientSecret) === '') {
    throw new StateFileLimitException('config_missing:client_credentials', 1703);
}
define('B24_CLIENT_ID', $clientId);
define('B24_CLIENT_SECRET', $clientSecret);
// Never derive trust from Host, forwarded headers, request DOMAIN, or Origin.
define('APP_URL_OVERRIDE', (string)(getenv('BXAPP_APP_URL') ?: getenv('APP_URL') ?: ''));
$url = $stateFailure ? '' : (APP_URL_OVERRIDE !== '' ? APP_URL_OVERRIDE : ($state['app_url'] ?? ''));
if (!$stateFailure && (!is_string($url) || $url === ''
    || preg_replace('~^https://[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?(?::443)?/(?:[a-zA-Z0-9_-]+/)*index\.php$~D', '', $url) !== '')) {
    throw new StateFileLimitException('config_missing_or_invalid:app_url', 1703);
}
define('APP_URL', $url);
define('B24_OAUTH_URL', 'https://oauth.bitrix24.tech/oauth/token/');
define('B24_WEBHOOK_URL', '');
// Safe base profile: no business-event subscriptions or placements until explicitly designed.
define('APP_SCOPES', ['crm', 'user', 'placement']);
define('APP_METHODS_REQUIRED', ['user.current', 'app.info']);
define('APP_METHODS_OPTIONAL', []);
define('APP_PLACEMENTS', []);
define('APP_EVENTS', []);
define('UF_PREFIX', 'APP');
define('APP_LOG_LEVEL', 'info');
define('LOG_RETENTION_DAYS', 14);
define('INBOX_MAX', 1000);
if (!defined('UNVERIFIED_FIRST_INSTALL')) define('UNVERIFIED_FIRST_INSTALL', []);
