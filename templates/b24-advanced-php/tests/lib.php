<?php
declare(strict_types=1);

// Испытаниям незачем отбывать настоящие паузы повторов (1+2+4 с): предмет
// проверок — решение о повторе и его итог, а не длительность сна. Множитель
// уменьшает ТОЛЬКО сон в b24.php и наследуется дочерними процессами (воркер,
// стенд, помощники). Замер 26.08.2026: круг самопроверки 177 → 89 секунд.
// Проверке, которой нужна настоящая пауза, достаточно поставить у себя
// putenv('BXAPP_RETRY_PAUSE_SCALE=1') — множитель не меняет ни числа попыток,
// ни условия повтора, ни бюджета вызова.
if (getenv('BXAPP_RETRY_PAUSE_SCALE') === false) putenv('BXAPP_RETRY_PAUSE_SCALE=0.02');
/**
 * Обвес для проверок, которые ходят по живому HTTP.
 *
 * Зачем отдельным файлом. Требование шаблона «пиши свои проверки на новое
 * поведение» упиралось в сорок строк инфраструктуры, которые надо было
 * скопировать до первой полезной строки: выбрать свободный порт, поднять
 * встроенный сервер, дождаться готовности, не забыть убить его при аварии,
 * написать свой curl. Копия расходилась с оригиналом, а сервер иногда
 * оставался жить и занимал порт до перезагрузки.
 *
 * Теперь проверка нового эндпоинта пишется так:
 *
 *     $srv = testServer();                       // поднялся, сам умрёт
 *     [$code, $json] = $srv['call']('GET', '/api/мой.php', ['token' => $t]);
 */

/**
 * Убрать временную папку целиком, вместе с подкаталогами.
 *
 * Плоский glob перестал справляться, как только журнал переехал в logs/:
 * подкаталоги оставались, /tmp рос от прогона к прогону, а следующий набор
 * получал в наследство чужой журнал и мерил не то.
 */
/** Рекурсивная копия каталога — нужна проверкам, которым требуется своя копия www. */
function testCopyTree(string $from, string $to): void {
    if (!is_dir($from)) return;
    if (!is_dir($to)) @mkdir($to, 0700, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $src = $from . '/' . $entry;
        $dst = $to . '/' . $entry;
        if (is_dir($src)) { testCopyTree($src, $dst); continue; }
        if (@copy($src, $dst)) {
            // index.php строит cache-buster из mtime каждого ассета. Копия с
            // «сегодняшним» временем меняла наблюдаемую страницу, хотя байты
            // фронта те же, — тестовый сервер должен быть верной копией www.
            $mtime = @filemtime($src);
            if (is_int($mtime)) @touch($dst, $mtime);
        }
    }
}

/**
 * Сделать отдельную копию www и заменить в ней файлы фикстуры.
 *
 * Пути считаются от www/. В исходное дерево функция не пишет: это важно для
 * проверок, которые аварийно снимают через SIGKILL или запускают параллельно.
 *
 * @param array<string, string> $files относительный путь => содержимое
 */
function testServerWwwCopy(string $sourceRoot, array $files = [], string $label = 'files'): string {
    $safeLabel = (string)preg_replace('~[^a-z0-9-]+~i', '-', $label);
    $safeLabel = trim($safeLabel, '-');
    if ($safeLabel === '') $safeLabel = 'files';
    $copy = sys_get_temp_dir() . '/bxapp-www-' . $safeLabel . '-' . bin2hex(random_bytes(4));

    try {
        testCopyTree($sourceRoot . '/www', $copy . '/www');
        if (!is_dir($copy . '/www')) {
            throw new RuntimeException('не скопировать www для тестового сервера');
        }
        foreach ($files as $name => $contents) {
            $relative = (string)$name;
            if ($relative === '' || $relative[0] === '/' || strpos($relative, "\0") !== false
                || strpos($relative, '\\') !== false || strpos($relative, '//') !== false
                || preg_match('~(^|/)\.\.?(/|$)~', $relative)) {
                throw new InvalidArgumentException('небезопасный путь файла тестового www');
            }
            $target = $copy . '/www/' . $relative;
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                throw new RuntimeException('не создать каталог файла тестового www');
            }
            $tmp = $target . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, (string)$contents, LOCK_EX) === false
                || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('не записать файл тестового www');
            }
        }
        return $copy;
    } catch (Throwable $e) {
        if (is_dir($copy)) testWipe($copy, true);
        throw $e;
    }
}

function testWipe(string $dir, bool $removeSelf = false): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? testWipe($path, true) : @unlink($path);
    }
    if ($removeSelf) @rmdir($dir);
}

/**
 * Временный каталог прогона, который убирается и при обрыве сигналом.
 *
 * Одного register_shutdown_function мало: PHP выполняет shutdown-функции при
 * штатном конце и при fatal, но НЕ при SIGTERM (проверено пробником 05.09.2026).
 * А самопроверка идёт около пяти минут и регулярно убивается по лимиту вызова —
 * тот самый «прогон убит сигналом на середине» из CLAUDE.md шаблона. Итог замера:
 * в /tmp лежало 403 каталога bxapp-www-fx-* при уже написанной уборке.
 *
 * Поэтому уборка висит на обоих концах: shutdown + обработчик сигналов. После
 * уборки процесс завершается кодом 128+сигнал, а не притворяется успешным, иначе
 * раннер посчитал бы прерванный прогон пройденным.
 */
function testRegisterTemp(string $dir): string {
    static $dirs = [];
    static $hooked = false;

    $dirs[] = $dir;

    if (!$hooked) {
        $hooked = true;
        // Регистрируют и файлы: у store-проверок в /tmp оставался не каталог, а
        // сам файл состояния вместе со своим `.lock.php` рядом.
        $uborka = static function () use (&$dirs): void {
            foreach ($dirs as $d) {
                if (is_dir($d)) { testWipe($d, true); continue; }
                @unlink($d);
                @unlink($d . '.lock.php');
            }
            $dirs = [];
        };
        register_shutdown_function($uborka);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
                pcntl_signal($sig, static function (int $signo) use ($uborka): void {
                    $uborka();
                    exit(128 + $signo);
                });
            }
        }
    }

    return $dir;
}

/**
 * Свободный порт из диапазона, который никто в системе не слушает.
 *
 * Проверяем ДО запуска сервера: наугад взятый порт может слушать чужой процесс,
 * и тогда проверки молча уходят не туда — а набор при этом зелёный.
 */
function testFreePort(int $from = 8100, int $to = 9600): int {
    for ($port = random_int($from, $to - 200); $port < $to; $port++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (!$probe) return $port;
        fclose($probe);
    }
    throw new RuntimeException('нет свободного порта для встроенного сервера');
}

/** Зарегистрировать child-close либо детерминированно закрыть все server текущего suite. */
function testServersCleanup(?callable $register = null): void {
    static $closers = [];
    if ($register !== null) { $closers[] = $register; return; }
    $current = $closers;
    $closers = [];
    foreach ($current as $close) $close();
}

/** Короткая secret-safe причина транспортного отказа для assertion receipt. */
function testDiagnosticText(string $text, int $maxBytes = 240): string {
    if (function_exists('maskSecrets')) $text = (string)maskSecrets($text);
    $text = (string)preg_replace('~https?://[^\s]+~i', '<url>', $text);
    return substr($text, 0, max(0, $maxBytes));
}

/** @return array{log_bytes:int,log_tail_hash:string,log_tail:string} */
function testServerLogReceipt(string $file): array {
    clearstatcache(true, $file);
    $stat = @lstat($file);
    if (!is_array($stat) || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0100000) {
        return ['log_bytes' => 0, 'log_tail_hash' => '', 'log_tail' => ''];
    }
    $bytes = max(0, (int)($stat['size'] ?? 0));
    $fh = @fopen($file, 'rb');
    if ($fh === false) return ['log_bytes' => $bytes, 'log_tail_hash' => '', 'log_tail' => ''];
    if ($bytes > 4096) @fseek($fh, $bytes - 4096);
    $tail = (string)@fread($fh, 4096);
    fclose($fh);
    return [
        'log_bytes' => $bytes,
        'log_tail_hash' => $tail === '' ? '' : hash('sha256', $tail),
        'log_tail' => testDiagnosticText($tail, 400),
    ];
}

/**
 * Поднять встроенный php-сервер на www/ и вернуть готовый вызыватель.
 *
 * @param array $env Переменные окружения сверх обязательных. По умолчанию сервер
 *                   изолирован: стор — тестовый, сервер авторизации уведён в
 *                   никуда (проверки не должны зависеть от интернета и не должны
 *                   стучаться к настоящему Битриксу), креды заданы фиктивные.
 * @return array{port:int, call:callable, ok:bool, root:string}
 */
/**
 * @param array $env  Переменные окружения; пустое значение = «переменной нет».
 * @param array $opts 'blankLocalCreds' => true — поднять сервер на КОПИИ www, где
 *                    слоты локальных кредов в env.php очищены. Нужно проверкам
 *                    поведения «приложение ещё не настроено»: раньше они читали
 *                    боевой env.php проекта, и у разработчика, который вписал туда
 *                    свои тестовые креды (что шаблон прямо разрешает), самопроверка
 *                    краснела на ровном месте — а вместе с ней вставали Stop-хук и деплой.
 *                    'wwwFiles' => ['api/file.php' => '<?php ...'] — заменить
 *                    файлы внутри отдельной копии www; пути считаются от www/.
 */
function testServer(array $env = [], array $opts = []): array {
    $root = dirname(__DIR__);
    if (!empty($opts['blankLocalCreds'])) {
        $copy = sys_get_temp_dir() . '/bxapp-www-blank-' . bin2hex(random_bytes(4));
        testCopyTree($root . '/www', $copy . '/www');
        $envCopy = $copy . '/www/env.php';
        $src = (string)@file_get_contents($envCopy);
        $src = (string)preg_replace('~(\$B24_CLIENT_ID_LOCAL\s*=\s*)\'[^\']*\'~', "$1''", $src, 1);
        $src = (string)preg_replace('~(\$B24_CLIENT_SECRET_LOCAL\s*=\s*)\'[^\']*\'~', "$1''", $src, 1);
        // Пишем как и весь шаблон: во временный файл рядом и атомарным
        // переименованием поверх. Здесь это копия конфига для проверки, но
        // правило одно на всё — половинчатый env.php сервер прочитать не должен.
        $envTmp = $envCopy . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
        $envHandle = @fopen($envTmp, 'wb');
        if ($envHandle !== false) {
            if (@flock($envHandle, LOCK_EX)) {
                @fwrite($envHandle, $src);
                @fflush($envHandle);
                @flock($envHandle, LOCK_UN);
            }
            fclose($envHandle);
            @chmod($envTmp, 0600);
            if (!@rename($envTmp, $envCopy)) @unlink($envTmp);
        }
        testRegisterTemp($copy);
        $root = $copy;
    }
    // Точки входа, которых в шаблоне быть не должно, но которые нужны, чтобы
    // проверить поведение при сбое (например, кончилась память). Кладём их в
    // КОПИЮ дерева: боевые файлы шаблона не трогаются и деплой их не подхватит.
    if (!empty($opts['fixtures'])) {
        $copy = sys_get_temp_dir() . '/bxapp-www-fx-' . bin2hex(random_bytes(4));
        testCopyTree($root . '/www', $copy . '/www');
        foreach ((array)$opts['fixtures'] as $name => $code) {
            file_put_contents($copy . '/www/' . basename((string)$name), (string)$code);
        }
        testRegisterTemp($copy);
        $root = $copy;
    }
    if (!empty($opts['wwwFiles'])) {
        $copy = testServerWwwCopy($root, (array)$opts['wwwFiles'], 'files');
        testRegisterTemp($copy);
        $root = $copy;
    }
    $port = testFreePort();
    // Readiness должна принадлежать ИМЕННО только что поднятому child. Простая
    // проверка открытого порта принимала чужой параллельный php -S, а после его
    // завершения все следующие curl возвращали неразличимый HTTP code=0.
    $serverNonce = bin2hex(random_bytes(16));
    $serverRouter = sys_get_temp_dir() . '/bxapp-test-router-' . $serverNonce . '.php';
    $routerSource = <<<'PHP'
<?php
$nonce = (string)getenv('BXAPP_TEST_SERVER_NONCE');
if ($nonce !== '' && (string)($_SERVER['REQUEST_URI'] ?? '') === '/__bxapp_test_ready') {
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo $nonce;
    return true;
}
return false;
PHP;
    if (@file_put_contents($serverRouter, $routerSource, LOCK_EX) === false
        || !@chmod($serverRouter, 0600)) {
        throw new RuntimeException('не создать private router для readiness-пробы');
    }

    $env = array_merge([
        'BXAPP_DATA_ROOT'     => DATA_ROOT,
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
        'BXAPP_OAUTH_URL'     => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID'     => 'local.test.app',
        'BXAPP_CLIENT_SECRET' => 'test-secret',
    ], $env);
    // Пустое значение = «переменной нет»: так проверяется поведение при
    // незаполненном конфиге.
    $env = array_filter($env, static fn($v): bool => $v !== null && $v !== '');
    $env['BXAPP_TEST_SERVER_NONCE'] = $serverNonce;

    // Ошибки сервера по умолчанию уходят в /dev/null — и «код 500» в отчёте
    // остаётся без причины: сообщение PHP, которое прямо называет файл и строку,
    // выбрасывается. Путь к журналу можно задать переменной окружения и увидеть
    // текст ошибки, не переписывая набор.
    $serverLog = (string)getenv('BXAPP_TEST_SERVER_LOG');
    $ownedServerLog = $serverLog === '';
    if ($ownedServerLog) {
        $serverLog = sys_get_temp_dir() . '/bxapp-test-server-' . $serverNonce . '.log';
        if (@file_put_contents($serverLog, '', LOCK_EX) === false || !@chmod($serverLog, 0600)) {
            @unlink($serverRouter);
            throw new RuntimeException('не создать private stderr receipt тестового сервера');
        }
    }
    $errSink = ['file', $serverLog, 'a'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => $errSink];
    // ЖЁСТКИЙ СРОК ЖИЗНИ СЕРВЕРА.
    //
    // Ниже стоит register_shutdown_function, и при штатном конце набора она
    // сервер гасит. Но набор не всегда кончается штатно: прогон прерывают,
    // процесс снимают по SIGKILL, машину перезагружают в середине. Тогда
    // shutdown-функция не выполняется вовсе, а `php -S` остаётся жить сиротой —
    // молча, без всякого признака в отчёте. Замер 08.08.2026: на машине висело
    // 718 таких серверов возрастом до 19 часов, самые старые ещё держали порты.
    //
    // `timeout` снимает сервер сам, даже если о нём забыли: он пересылает сигнал
    // дочернему php, поэтому proc_terminate ниже продолжает работать как прежде.
    // Срок берём с большим запасом к самому долгому набору — он аварийный клапан,
    // а не ограничение на длительность прогона.
    $ttl = (int)(getenv('BXAPP_TEST_SERVER_TTL') ?: 900);
    // Потолок памяти обязателен и здесь. У CLI-PHP предела нет вовсе, а тестовых
    // серверов в круге поднимается много и живут они долго: 04.09.2026 такие
    // роутеры выросли до 3,3 и 2,0 ГБ и вместе с долями прогона уронили хост.
    // Значение то же, что у самого круга (tools/self-check.sh), а без него —
    // тот же разумный запас: обычный тестовый сервер не переваливает и 200 МБ.
    $pamyat = (string)(getenv('BXAPP_TEST_MEMORY_LIMIT') ?: '512M');
    $server = proc_open(['timeout', '-k', '5', (string)$ttl, 'php',
        '-d', 'memory_limit=' . $pamyat, '-S', '127.0.0.1:' . $port,
        '-t', $root . '/www', $serverRouter],
        $descriptors, $pipes, $root, $env);

    // Даже если набор упадёт с исключением, сервер не должен остаться жить.
    $close = static function () use (&$server, $serverRouter, $serverLog, $ownedServerLog): void {
        if (is_resource($server)) { @proc_terminate($server); @proc_close($server); $server = null; }
        @unlink($serverRouter);
        if ($ownedServerLog) @unlink($serverLog);
    };
    register_shutdown_function($close);
    testServersCleanup($close);

    $up = false;
    if (is_resource($server)) {
        for ($i = 0; $i < 100; $i++) {
            $ready = curl_init('http://127.0.0.1:' . $port . '/__bxapp_test_ready');
            curl_setopt_array($ready, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 200,
                CURLOPT_TIMEOUT_MS => 200,
            ]);
            $readyBody = curl_exec($ready);
            $readyCode = (int)curl_getinfo($ready, CURLINFO_HTTP_CODE);
            curl_close($ready);
            $child = proc_get_status($server);
            if (is_string($readyBody) && hash_equals($serverNonce, $readyBody)
                && $readyCode === 200 && is_array($child) && !empty($child['running'])) {
                $up = true;
                break;
            }
            if (is_array($child) && empty($child['running'])) break;
            usleep(50000);
        }
    }

    $startupChild = is_resource($server) ? proc_get_status($server) : null;
    $startupDiagnostic = [
        'port' => $port,
        'server_running' => is_array($startupChild) && !empty($startupChild['running']),
        'server_exit_code' => is_array($startupChild) ? (int)($startupChild['exitcode'] ?? -1) : -1,
    ] + testServerLogReceipt($serverLog);
    if (!$up) $close();

    $call = static function (string $method, string $path, array $opt = []) use (
        $port, &$server, $serverLog, $startupDiagnostic
    ): array {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        $headers = ['Content-Type: ' . ($opt['type'] ?? 'application/json')];
        // По умолчанию ведём себя как браузер со страницы приложения; сценарий
        // «запрос без признаков источника» задаётся явно: ['site' => null].
        $site = array_key_exists('site', $opt) ? $opt['site'] : 'same-origin';
        if ($site !== null) $headers[] = 'Sec-Fetch-Site: ' . $site;
        if (!empty($opt['token']))  $headers[] = 'X-App-Token: ' . $opt['token'];
        if (!empty($opt['origin'])) $headers[] = 'Origin: ' . $opt['origin'];
        if (!empty($opt['cookie'])) $headers[] = 'Cookie: ' . $opt['cookie'];
        foreach ((array)($opt['headers'] ?? []) as $h) $headers[] = $h;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, (int)($opt['timeout'] ?? 15)),
            CURLOPT_TIMEOUT => (int)($opt['timeout'] ?? 15),
            CURLOPT_HEADER => true,
        ]);
        if (isset($opt['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']);

        $result = curl_exec($ch);
        $raw  = is_string($result) ? $result : '';
        $curlErrno = curl_errno($ch);
        $curlError = testDiagnosticText((string)curl_error($ch));
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $head = substr($raw, 0, $hlen);
        $body = substr($raw, $hlen);
        $child = is_resource($server) ? proc_get_status($server) : null;
        $diagnostic = [
            'port' => $port,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'server_running' => is_array($child) && !empty($child['running']),
            'server_exit_code' => is_array($child) ? (int)($child['exitcode'] ?? -1) : -1,
        ] + testServerLogReceipt($serverLog) + ['startup' => $startupDiagnostic];
        return [$code, json_decode($body, true) ?: [], $body, $head, $diagnostic];
    };

    return ['port' => $port, 'call' => $call, 'close' => $close, 'root' => $root,
        'diagnostic' => $startupDiagnostic, 'ok' => $up];
}
