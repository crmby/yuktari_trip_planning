<?php
declare(strict_types=1);

/**
 * Печатает имена констант из настоящих вызовов define('NAME', ...).
 *
 * Lexical-режим не исполняет env.php. Runtime-режим запускает его копию только
 * в отдельном ограниченном CLI-процессе с пустым state: live-файлы и переменные
 * хоста ему не видны, stdout/stderr поглощаются, значения наружу не выходят.
 * Скрипт одинаково работает с аргументом пути и через stdin + переменную среды.
 */

$runtimeMode = (isset($argv[1]) && $argv[1] === '--runtime')
    || getenv('BXAPP_ENV_CONTRACT_MODE') === 'runtime';
$pathArgument = $runtimeMode ? 2 : 1;
$path = isset($argv[$pathArgument]) && is_string($argv[$pathArgument]) && $argv[$pathArgument] !== ''
    ? $argv[$pathArgument]
    : getenv('BXAPP_ENV_CONTRACT_FILE');

$fail = static function (string $message, int $code): void {
    // STDOUT/STDERR не определены в некоторых CLI-сборках, когда сам helper
    // приходит через stdin. php://* одинаково работает в обоих режимах.
    file_put_contents('php://stderr', $message . "\n");
    exit($code);
};

if (!is_string($path) || $path === '' || !is_file($path) || is_link($path)) {
    $fail('env define-check: нужен обычный читаемый PHP-файл.', 2);
}

$source = @file_get_contents($path);
if (!is_string($source)) {
    $fail('env define-check: файл не прочитан.', 3);
}

if ($runtimeMode) {
    // Lexical define может находиться в if(false) или невызванной closure.
    // Нельзя require-ить недоверенный remote env в этом процессе: он способен
    // закрыть output-buffer, подделать stdout или затронуть live state. Поэтому
    // дочерний PHP получает КОПИЮ source в одноразовой open_basedir-песочнице,
    // пустой DATA_ROOT, минимальный env и отключённые сеть/process primitives.
    if (!function_exists('proc_open')) {
        $fail('env define-check: proc_open недоступен для изолированного runtime.', 8);
    }

    $sandbox = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'bxapp-env-contract-' . bin2hex(random_bytes(12));
    $cleanupSandbox = static function (string $root): void {
        if (!is_dir($root) || is_link($root)) return;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $target = $item->getPathname();
                if ($item->isLink() || $item->isFile()) {
                    @unlink($target);
                } else {
                    @chmod($target, 0700);
                    @rmdir($target);
                }
            }
        } catch (Throwable $e) {
            // Основной результат всё равно будет fail-closed ниже; содержимое
            // песочницы никогда не переносится в проект или live state.
        }
        @chmod($root, 0700);
        @rmdir($root);
    };

    if (!@mkdir($sandbox, 0700) || !@mkdir($sandbox . '/data', 0700)
        || @file_put_contents($sandbox . '/env.php', $source, LOCK_EX) === false
        || !@chmod($sandbox . '/env.php', 0600)) {
        $cleanupSandbox($sandbox);
        $fail('env define-check: runtime-песочница не создана.', 9);
    }

    // Ключ приходит дочернему wrapper через stdin, считывается ДО require и
    // остаётся локальной переменной внешней closure. В scope env.php ключа нет;
    // произвольный текст stdout не может выдать себя за подписанный результат.
    $probeCode = <<<'PHP'
(static function (): void {
    $key = trim((string)file_get_contents('php://stdin'));
    $envFile = (string)getenv('BXAPP_RUNTIME_ENV_FILE');
    if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $envFile === '') exit(90);

    $completed = false;
    register_shutdown_function(static function () use ($key, &$completed): void {
        // exit()/die() внутри env не являются успешным require: приложение
        // после такого конфига вообще не запустится. Без completion-флага
        // shutdown всё равно подписывал бы уже созданные define и давал зелёный.
        if (!$completed) return;
        $groups = get_defined_constants(true);
        $names = isset($groups['user']) && is_array($groups['user'])
            ? array_keys($groups['user']) : [];
        $names = array_values(array_filter($names, static function ($name): bool {
            return is_string($name) && preg_match('/^[A-Z0-9_]+$/D', $name) === 1;
        }));
        sort($names, SORT_STRING);
        $payload = $names === [] ? '' : implode("\n", $names) . "\n";
        $encoded = base64_encode($payload);
        $mac = hash_hmac('sha256', $encoded, $key);
        file_put_contents('php://stdout', 'BXAPP_RUNTIME_V1:' . $mac . ':' . $encoded . "\n");
    });

    set_error_handler(static function (int $severity): bool {
        throw new ErrorException('env runtime warning', 0, $severity);
    });
    $run = static function (string $file): void { require $file; };
    $run($envFile);
    $completed = true;
})();
PHP;

    $disabled = implode(',', [
        'exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen', 'mail',
        'dl', 'ini_set', 'ini_restore', 'set_time_limit', 'error_log', 'openlog', 'syslog', 'closelog',
        'pcntl_alarm', 'pcntl_async_signals', 'pcntl_exec', 'pcntl_fork',
        'pcntl_getcpuaffinity', 'pcntl_getpriority', 'pcntl_rfork', 'pcntl_setcpuaffinity',
        'pcntl_setpriority', 'pcntl_signal', 'pcntl_signal_dispatch', 'pcntl_sigprocmask',
        'pcntl_sigtimedwait', 'pcntl_sigwaitinfo', 'pcntl_unshare', 'pcntl_wait', 'pcntl_waitid',
        'pcntl_waitpid', 'posix_initgroups', 'posix_kill', 'posix_mkfifo', 'posix_setegid',
        'posix_seteuid', 'posix_setgid', 'posix_setpgid', 'posix_setsid', 'posix_setuid',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
        'socket_create', 'socket_create_listen', 'socket_accept', 'socket_bind', 'socket_connect',
        'socket_send', 'socket_sendmsg', 'socket_sendto', 'socket_write',
        'curl_exec', 'curl_multi_exec', 'curl_multi_select', 'ftp_connect', 'ftp_ssl_connect',
        'gethostbyaddr', 'gethostbyname', 'gethostbynamel', 'dns_get_record', 'checkdnsrr',
        'dns_check_record', 'dns_get_mx', 'getmxrr',
        'putenv', 'apache_setenv',
    ]);
    $command = [
        PHP_BINARY, '-n',
        '-d', 'display_errors=1', '-d', 'log_errors=0', '-d', 'html_errors=0',
        '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
        '-d', 'memory_limit=64M', '-d', 'max_execution_time=10',
        '-d', 'open_basedir=' . $sandbox, '-d', 'sys_temp_dir=' . $sandbox,
        '-d', 'upload_tmp_dir=' . $sandbox, '-d', 'disable_functions=' . $disabled,
        '-r', $probeCode,
    ];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $childEnv = [
        'BXAPP_RUNTIME_ENV_FILE' => $sandbox . '/env.php',
        'BXAPP_DATA_ROOT' => $sandbox . '/data',
        'BXAPP_CREDS_FILE' => $sandbox . '/data/creds.php',
        'BXAPP_CLIENT_ID' => '',
        'BXAPP_CLIENT_SECRET' => '',
        'BXAPP_OAUTH_URL' => 'https://oauth.bitrix24.tech/oauth/token/',
        'TMPDIR' => $sandbox,
    ];
    $process = @proc_open($command, $descriptorSpec, $pipes, $sandbox, $childEnv);
    if (!is_resource($process)) {
        $cleanupSandbox($sandbox);
        $fail('env define-check: изолированный runtime не запущен.', 10);
    }

    $key = bin2hex(random_bytes(32));
    $keyWritten = @fwrite($pipes[0], $key . "\n");
    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stdoutOverflow = false;
    $stderrSeen = false;
    $timedOut = false;
    $lastExitCode = -1;
    $deadline = microtime(true) + 15.0;
    while (true) {
        $read = [];
        if (is_resource($pipes[1]) && !feof($pipes[1])) $read[] = $pipes[1];
        if (is_resource($pipes[2]) && !feof($pipes[2])) $read[] = $pipes[2];

        if ($read !== []) {
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, 200000);
            if ($selected === false) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
            foreach ($read as $stream) {
                $chunk = (string)@fread($stream, 8192);
                if ($chunk === '') continue;
                if ($stream === $pipes[1]) {
                    if (strlen($stdout) + strlen($chunk) <= 131072) {
                        $stdout .= $chunk;
                    } else {
                        $stdoutOverflow = true;
                    }
                } else {
                    $stderrSeen = true; // содержимое намеренно никогда не сохраняем/не выводим
                }
            }
        } else {
            // Недоверенный env может закрыть оба потока и продолжить работу.
            // proc_close() тогда зависнет мимо deadline, поэтому ждём процесс,
            // а не наличие pipe, сохраняя контроль таймаута в родителе.
            usleep(20000);
        }
        $status = @proc_get_status($process);
        if (is_array($status) && !$status['running'] && (int)$status['exitcode'] >= 0) {
            $lastExitCode = (int)$status['exitcode'];
        }
        $pipesDrained = (!is_resource($pipes[1]) || feof($pipes[1]))
            && (!is_resource($pipes[2]) || feof($pipes[2]));
        if (is_array($status) && !$status['running'] && $pipesDrained) break;
        if (microtime(true) > $deadline) {
            $timedOut = true;
            @proc_terminate($process, 9);
            break;
        }
    }
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $status = @proc_get_status($process);
    if (is_array($status) && !$status['running'] && (int)$status['exitcode'] >= 0) {
        $lastExitCode = (int)$status['exitcode'];
    }
    $closeCode = @proc_close($process);
    $exitCode = is_int($closeCode) && $closeCode >= 0 ? $closeCode : $lastExitCode;
    $cleanupSandbox($sandbox);

    $frame = [];
    $frameOk = !$stdoutOverflow
        && preg_match('/^BXAPP_RUNTIME_V1:([a-f0-9]{64}):([A-Za-z0-9+\/=]*)\n$/D', $stdout, $frame) === 1
        && hash_equals(hash_hmac('sha256', (string)$frame[2], $key), (string)$frame[1]);
    $payload = $frameOk ? base64_decode((string)$frame[2], true) : false;
    $runtimeConstants = is_string($payload)
        ? preg_split('/\n/', rtrim($payload, "\n"), -1, PREG_SPLIT_NO_EMPTY) : false;
    $normalized = is_array($runtimeConstants) ? $runtimeConstants : [];
    $sorted = $normalized;
    sort($sorted, SORT_STRING);
    if ($keyWritten !== 65 || $timedOut || $stderrSeen || $exitCode !== 0 || !$frameOk
        || $normalized === [] || $normalized !== $sorted
        || count($normalized) !== count(array_unique($normalized))
        || array_filter($normalized, static function ($name): bool {
            return !is_string($name) || preg_match('/^[A-Z0-9_]+$/D', $name) !== 1;
        }) !== []) {
        $fail('env define-check: изолированный runtime не подтвердил контракт.', 11);
    }

    file_put_contents('php://stdout', implode("\n", $normalized) . "\n");
    exit(0);
}

try {
    $tokens = token_get_all($source, TOKEN_PARSE);
} catch (Throwable $e) {
    $fail('env define-check: синтаксис PHP не разобран.', 4);
}

// env.php — контракт глобального пространства имён. Namespace/import может
// переопределить смысл неqualified define(), поэтому неоднозначный файл не
// пытаемся «угадать»: новая структура требует новой schema и правки гейта.
foreach ($tokens as $token) {
    if (is_array($token) && ($token[0] === T_NAMESPACE || $token[0] === T_USE)) {
        $fail('env define-check: namespace/import в env.php запрещён.', 5);
    }
}

$isTrivia = static function ($token): bool {
    return is_array($token)
        && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
};
$nextSignificant = static function (array $all, int $from) use ($isTrivia): ?int {
    $count = count($all);
    for ($i = $from; $i < $count; $i++) {
        if (!$isTrivia($all[$i])) return $i;
    }
    return null;
};
$previousSignificant = static function (array $all, int $from) use ($isTrivia): ?int {
    for ($i = $from; $i >= 0; $i--) {
        if (!$isTrivia($all[$i])) return $i;
    }
    return null;
};

$forbiddenPreviousIds = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
if (defined('T_NS_SEPARATOR')) $forbiddenPreviousIds[] = constant('T_NS_SEPARATOR');
if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
    $forbiddenPreviousIds[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
}

$names = [];
$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];
    if (!is_array($token) || $token[0] !== T_STRING || strcasecmp($token[1], 'define') !== 0) {
        continue;
    }

    // Не принимать $obj->define(), Foo::define(), new define() и объявления
    // функций. Foo\define()/\define() в PHP 7 содержат T_NS_SEPARATOR, а в PHP
    // 8 приходят единым T_NAME_* и до ветки T_STRING вообще не доходят.
    $previous = $previousSignificant($tokens, $i - 1);
    if ($previous !== null) {
        $previousToken = $tokens[$previous];
        if ((is_array($previousToken) && in_array($previousToken[0], $forbiddenPreviousIds, true))
            || $previousToken === '\\') {
            continue;
        }
    }

    $open = $nextSignificant($tokens, $i + 1);
    $nameAt = $open === null ? null : $nextSignificant($tokens, $open + 1);
    $comma = $nameAt === null ? null : $nextSignificant($tokens, $nameAt + 1);
    if ($open === null || $tokens[$open] !== '(' || $nameAt === null || $comma === null
        || $tokens[$comma] !== ',') {
        continue;
    }

    $nameToken = $tokens[$nameAt];
    if (!is_array($nameToken) || $nameToken[0] !== T_CONSTANT_ENCAPSED_STRING
        || preg_match("/^'([A-Z0-9_]+)'$/D", $nameToken[1], $match) !== 1) {
        continue;
    }
    if (isset($names[$match[1]])) {
        $fail('env define-check: повторный define неоднозначен.', 6);
    }
    $names[$match[1]] = true;
}

if ($names === []) {
    $fail("env define-check: настоящие define('NAME', ...) не найдены.", 7);
}

$result = array_keys($names);
sort($result, SORT_STRING);
file_put_contents('php://stdout', implode("\n", $result) . "\n");
