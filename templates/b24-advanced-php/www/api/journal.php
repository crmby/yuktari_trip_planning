<?php
declare(strict_types=1);

// ПРЯМОЙ HTTP К ЭТОМУ ФАЙЛУ — 404. Деплой публикует каталог целиком, поэтому под
// веб-корнем лежит и то, что вызывают только по require. Пустой ответ 200
// подтверждает существование файла и его путь (находка ревью 08.08.2026,
// BXREV-025). CLI и подключение из другого файла проверка не задевает: она
// смотрит, запрошен ли ИМЕННО этот файл.
if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
/**
 * ЖУРНАЛ ПРИЛОЖЕНИЯ — одна запись на всё: сбои, действия людей, фоновая работа.
 *
 * Зачем один. Раньше в приложении было три несвязанных журнала: сбои писались в
 * один файл, действия людей — во второй, работа крона — в третий, и все в разных
 * форматах. Ни один нельзя было прочитать, не заходя на сервер по SSH, а журнал
 * действий не читал вообще никто и никогда. Разбор «почему у клиента не
 * сработало» превращался в переписку.
 *
 * Теперь: одна функция записи и два окна в один и тот же журнал —
 *   • человеку   — экран в приложении (api/logs.php);
 *   • разработчику — команда с сервера (bin/logs.php, scripts/logs.sh).
 *
 * Формат — по одной json-строке на запись (JSONL): его одинаково удобно читать и
 * кодом с фильтрами, и глазами через grep. Файл дневной лежит в private DATA_ROOT
 * и дополнительно обязан быть `.php` с исполняемым exit-префиксом первой строкой
 * (штатно 404+exit; transitional legacy выбирает только deploy ради rollback).
 */

require_once __DIR__ . '/../env.php';
// ПОРЯДОК ЗДЕСЬ — НЕСУЩИЙ, а не косметика (находка ревью 09.08.2026).
//
// lib.php подключает journal.php обратно (logAppError), и это кольцо само по себе
// безвредно: функции верхнего уровня доступны с момента компиляции файла. Но
// define/const — операторы времени выполнения. Пока они стояли ПОСЛЕ require
// lib.php, вход, который начинается с journal.php (www/bin/logs.php,
// www/bin/deploy-private-store.php), проходил так: journal.php → lib.php →
// его загрузочный блок восстановления состояния упал → logAppError → appLog →
// `LOG_LEVELS[$level]` при ещё НЕ выполненном `const LOG_LEVELS` → в PHP 8 это
// Error, а не null. Итог: самая тяжёлая авария (наполовину применённая
// транзакция состояния) не оставляла в журнале НИ ОДНОЙ строки и не показывала
// человеку код обращения.
//
// Поэтому: store.php (он даёт BXAPP_PRIVATE_PHP_HEADER для JOURNAL_FILE_PREFIX и
// сам ничего не подключает — нового кольца не появляется), затем ВСЕ константы
// журнала, и только потом lib.php. Переносить обратно нельзя целиком: частичный
// перенос лечит appLog, но тот же Error ждёт в journalAppend на JOURNAL_FILE_PREFIX.
require_once __DIR__ . '/store.php';     // BXAPP_PRIVATE_PHP_HEADER

// Пороговый уровень: строки ниже порога не пишутся вовсе. Раздельной чистки по
// уровням намеренно нет — она потребовала бы переписывать файл целиком.
if (!defined('APP_LOG_LEVEL'))      define('APP_LOG_LEVEL', 'info');
if (!defined('LOG_RETENTION_DAYS')) define('LOG_RETENTION_DAYS', 14);
if (!defined('LOG_DAY_MAX_BYTES'))  define('LOG_DAY_MAX_BYTES', 8 * 1048576);
// Одна физическая JSONL-строка тоже ограничена. Иначе единственный неудачно
// переданный ctx пробивает дневной потолок и затем собирается читалкой в память
// целиком. Лимит чтения чуть шире ради старых файлов, записанных до этого правила.
if (!defined('LOG_RECORD_MAX_BYTES'))    define('LOG_RECORD_MAX_BYTES', 64 * 1024);
if (!defined('LOG_SCAN_LINE_MAX_BYTES')) define('LOG_SCAN_LINE_MAX_BYTES', 256 * 1024);
if (!defined('LOG_SCAN_MAX_RECORDS'))    define('LOG_SCAN_MAX_RECORDS', 200000);
// Сколько отложенных файлов допускаем за один день (потолок объёма: файлов × размер).
if (!defined('LOG_DAY_MAX_FILES'))  define('LOG_DAY_MAX_FILES', 10);

const LOG_LEVELS   = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
const LOG_DIR_NAME = 'logs';
/**
 * Поля, которые нельзя писать в журнал открытым текстом, даже если это не секрет:
 * телефон и почта клиента — персональные данные, а журнал живёт две недели и
 * читается несколькими людьми. Пишем короткий хеш: записи по одному человеку
 * по-прежнему связываются между собой, а самих данных в файле нет.
 */
const LOG_PII_KEYS = ['phone', 'email', 'personal_mobile', 'user_code', 'inn', 'passport'];

const JOURNAL_FILE_PREFIX = BXAPP_PRIVATE_PHP_HEADER;

// lib.php — ПОСЛЕ констант (см. объяснение порядка выше): его загрузочный блок
// восстановления состояния умеет позвать logAppError → appLog ещё до того, как
// управление вернётся сюда, и к этому моменту константы обязаны существовать.
require_once __DIR__ . '/lib.php';       // maskSecrets

/**
 * Код обращения — один на весь запрос.
 *
 * Он же попадает в каждую строку журнала, он же показывается человеку на экране
 * ошибки, он же уходит в заголовок ответа. Поэтому «покажите код обращения»
 * достаточно, чтобы поднять всю историю запроса одной командой: `logs ref <код>`.
 */
function logRef(): string {
    if (!isset($GLOBALS['__log_ref'])) {
        // Формат прежний: этот код человек диктует голосом и вписывает в чат.
        $GLOBALS['__log_ref'] = date('md-His') . '-' . bin2hex(random_bytes(2));
    }
    return (string)$GLOBALS['__log_ref'];
}

/**
 * Объявить, с чем работает этот запрос: поля попадут в КАЖДУЮ следующую строку.
 *
 * Без этого каждое место записи само решает, приложить ли идентификатор сделки, —
 * и при разборе половину строк не с чем связать.
 */
function logContextSet(array $fields): void {
    $ctx = $GLOBALS['__log_ctx'] ?? [];
    foreach ($fields as $k => $v) {
        if ($v === null || $v === '' || $v === 0) continue;
        $ctx[(string)$k] = $v;
    }
    $GLOBALS['__log_ctx'] = $ctx;
}

/** Забыть объявленный контекст. Обязательно между задачами в одном тике крона. */
function logContextClear(): void { $GLOBALS['__log_ctx'] = []; }

/** Откуда пришла запись: web-страница, эндпоинт, крон, событие портала. */
function logSource(): string {
    if (PHP_SAPI === 'cli') return 'cron';
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'index.php') return 'web';
    if ($script === 'event.php') return 'event';
    return 'api';
}

/** Текущий серверный день журнала; это не календарный день портала. */
function journalToday(): string { return date('Y-m-d'); }

/** Файл текущего дня. Каталог и заглушка создаются здесь же. */
function journalFile(?string $day = null): string {
    $dir = DATA_ROOT . '/' . LOG_DIR_NAME;
    storeEnsureDir($dir);
    return $dir . '/' . ($day ?? journalToday()) . '.log.php';
}

/**
 * ЗАПИСЬ В ЖУРНАЛ — единственная на всё приложение.
 *
 * @param string $level debug|info|warn|error
 * @param string $event Машинный код через точку: install.ok, access.deny, b24.call
 * @param array  $ctx   Подробности. Проходят маскировку целиком.
 * @param array  $opts  ['kind'=>'action'|'system', 'uid'=>int, 'msg'=>string, 'ms'=>int, 'src'=>string]
 * @return string Код обращения только после подтверждённой записи; пустая
 *                строка означает, что журнал запись не принял.
 */
function appLog(string $level, string $event, array $ctx = [], array $opts = []): string {
    $ref = logRef();
    $rank = LOG_LEVELS[$level] ?? 1;
    if ($rank < (LOG_LEVELS[APP_LOG_LEVEL] ?? 1)) return '';   // ниже порога — ref не существует

    // Маскировка стоит ЗДЕСЬ, а не на месте вызова: пишет и прикладной код, где
    // легко не подумать, что в тексте ошибки лежит URL с токеном. Одна точка —
    // значит защищено и то, что допишут потом.
    $ctx = journalMaskPii(maskSecrets(array_merge($GLOBALS['__log_ctx'] ?? [], $ctx)));
    $row = [
        'ts'    => date('c'),
        // `ref` связывает ВСЕ строки одного запроса и потому намеренно
        // повторяется. Для cursor-пагинации каждой физической записи нужен
        // отдельный устойчивый идентификатор: иначе первая же пачка логов из
        // одного worker/request склеивает несколько страниц в одну позицию.
        'rid'   => bin2hex(random_bytes(12)),
        'level' => $level,
        'src'   => (string)($opts['src'] ?? logSource()),
        'event' => $event,
        'install_generation' => (string)($GLOBALS['__app_log_install_generation'] ?? ''),
        'ref'   => $ref,
        'kind'  => ($opts['kind'] ?? 'system') === 'action' ? 'action' : 'system',
        'uid'   => (int)($opts['uid'] ?? ($ctx['uid'] ?? 0)),
        // Адрес — тот же, по которому считаются лимиты: за прокси REMOTE_ADDR
        // показывает балансировщик, и тогда у ВСЕХ записей аудита один и тот же
        // адрес. Разбирать по нему «кто выдал себе права» невозможно, а выглядит
        // журнал заполненным и достоверным. apiClientIp() доверяет заголовку
        // только из рук прокси, перечисленного в APP_TRUSTED_PROXIES.
        'ip'    => PHP_SAPI === 'cli' ? '' : (function (): string {
            require_once __DIR__ . '/http.php';
            return apiClientIp();
        })(),
        'msg'   => (string)maskSecrets((string)($opts['msg'] ?? '')),
        'ctx'   => is_array($ctx) ? $ctx : ['value' => $ctx],
    ];
    if (isset($opts['ms'])) $row['ms'] = (int)$opts['ms'];

    $encoded = journalEncodeRecord($row);
    if ($encoded['line'] === '') return '';
    $append = journalAppend($encoded['line'], (string)$row['install_generation']);
    if (!empty($append['written'])) return $ref;

    // Когда tokens/generation не читаются, писать обычный journal без fence
    // нельзя: старый запрос смог бы воскресить лог уже после cleanup нового
    // поколения. Но и терять единственную диагностику молча нельзя — отправляем
    // короткую уже замаскированную запись в системный error_log. Код обращения
    // при этом НЕ возвращаем: по нему в journal физически нечего искать.
    $reason = (string)($append['reason'] ?? 'append_failed');
    // Generation mismatch — штатный исход уже начавшегося запроса после
    // uninstall/reinstall. Fence обязан отклонить его, но писать такой отказ в
    // stderr каждого CLI/worker-процесса нельзя: это не повреждение state и не
    // потерянная ошибка. Все остальные отказы (включая missing/unreadable
    // generation и физический сбой записи) получают аварийную диагностику.
    if ($reason !== 'install_generation_mismatch') {
        journalEmergencyDiagnostic($row, $reason);
    }
    return '';
}

/** Последняя линия диагностики, не зависящая от повреждённого app-state. */
function journalEmergencyDiagnostic(array $row, string $reason): void {
    $event = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', (string)($row['event'] ?? 'unknown'));
    $event = is_string($event) && $event !== '' ? substr($event, 0, 96) : 'unknown';
    $fallback = journalJson([
        'ts' => (string)($row['ts'] ?? date('c')),
        'level' => (string)($row['level'] ?? 'error'),
        'event' => $event,
        'reason' => substr($reason, 0, 80),
        'msg' => journalCut((string)($row['msg'] ?? ''), 2048),
    ]);
    @error_log('[bx-app journal fallback] ' . ($fallback ?? '{"event":"journal_encode_failed"}'));
}

/**
 * Персональные данные — коротким хешем: связать записи можно, прочитать нельзя.
 *
 * Признак «это ПДн» НАСЛЕДУЕТСЯ вглубь. Иначе защита не срабатывает ровно там,
 * где она нужна: портал отдаёт телефон и почту не строкой, а мультиполем —
 * PHONE => [ ['VALUE' => '+7…'] ]. Ключ PHONE подходит под список, но значение у
 * него массив, и без наследования проверка внутрь не доходила: карточка
 * контакта, записанная в журнал целиком, ложилась открытым текстом.
 */
function journalMaskPii($value, bool $inPii = false) {
    if (!is_array($value)) {
        return ($inPii && is_scalar($value) && (string)$value !== '')
            ? 'pii:' . substr(hash('sha256', (string)$value), 0, 8)
            : $value;
    }
    // Служебные ключи мультиполя хешировать незачем — по ним запись и разбирают.
    static $keep = ['id' => 1, 'type_id' => 1, 'value_type' => 1];
    $out = [];
    foreach ($value as $k => $v) {
        $key = strtolower((string)$k);
        $isPii = $inPii && !isset($keep[$key]);
        if (!$isPii && !isset($keep[$key])) {
            foreach (LOG_PII_KEYS as $needle) { if (strpos($key, $needle) !== false) { $isPii = true; break; } }
        }
        $out[$k] = journalMaskPii($v, $isPii);
    }
    return $out;
}

/** Сколько байт может занимать одна строка вместе с завершающим LF. */
function journalRecordByteLimit(): int {
    return max(0, min(
        (int)LOG_RECORD_MAX_BYTES,
        (int)LOG_SCAN_LINE_MAX_BYTES,
        (int)LOG_DAY_MAX_BYTES - strlen(JOURNAL_FILE_PREFIX)
    ));
}

/** Обрезать строку по байтам, не оставляя оборванный UTF-8, если доступен mbstring. */
function journalCut(string $value, int $bytes): string {
    if ($bytes <= 0) return '';
    if (strlen($value) <= $bytes) return $value;
    if (function_exists('mb_strcut')) {
        $cut = @mb_strcut($value, 0, $bytes, 'UTF-8');
        if (is_string($cut)) return $cut;
    }
    return substr($value, 0, $bytes);
}

/** JSON без падения на единичном битом UTF-8 в тексте внешней ошибки. */
function journalJson(array $row): ?string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($row, $flags);
    return is_string($json) ? $json : null;
}

/**
 * Собрать явную запись-маркер вместо слишком большой/некодируемой записи.
 * Возвращает пустую строку только при физически невозможной конфигурации, когда
 * дневной потолок меньше даже prefix + {"truncated":true} + LF.
 */
function journalTruncatedLine(array $row, int $originalBytes, string $reason): string {
    $limit = journalRecordByteLimit();
    if ($limit <= 0) return '';

    $base = [
        'ts'    => journalCut((string)($row['ts'] ?? date('c')), 40),
        'rid'   => journalCut((string)($row['rid'] ?? bin2hex(random_bytes(12))), 32),
        'level' => journalCut((string)($row['level'] ?? 'warn'), 16),
        'src'   => journalCut((string)($row['src'] ?? 'api'), 32),
        'event' => journalCut((string)($row['event'] ?? 'log.record_truncated'), 192),
        'install_generation' => journalCut((string)($row['install_generation'] ?? ''), 128),
        'ref'   => journalCut((string)($row['ref'] ?? ''), 64),
        'kind'  => journalCut((string)($row['kind'] ?? 'system'), 16),
        'uid'   => (int)($row['uid'] ?? 0),
        'ip'    => journalCut((string)($row['ip'] ?? ''), 64),
        'msg'   => journalCut((string)($row['msg'] ?? ''), 512),
        'ctx'   => ['_journal' => 'record_truncated'],
        'truncated' => true,
        'truncated_reason' => $reason,
        'original_bytes' => max(0, $originalBytes),
    ];
    if (isset($row['ms'])) $base['ms'] = (int)$row['ms'];

    // Несколько всё более коротких вариантов нужны не для обычного лимита
    // (64 КБ), а чтобы и тестовая/ошибочная малая настройка оставалась fail-closed.
    $variants = [
        $base,
        [
            'ts' => $base['ts'], 'level' => $base['level'],
            'rid' => $base['rid'],
            'event' => 'log.record_truncated',
            'install_generation' => $base['install_generation'], 'ref' => $base['ref'],
            'truncated' => true, 'truncated_reason' => $reason,
            'original_bytes' => max(0, $originalBytes),
        ],
        ['rid' => $base['rid'], 'event' => 'log.record_truncated', 'truncated' => true,
            'original_bytes' => max(0, $originalBytes)],
        ['rid' => $base['rid'], 'truncated' => true],
    ];
    foreach ($variants as $variant) {
        $json = journalJson($variant);
        if ($json !== null && strlen($json) + 1 <= $limit) return $json . "\n";
    }
    return '';
}

/** @return array{line:string,truncated:bool,original_bytes:int} */
function journalEncodeRecord(array $row): array {
    $json = journalJson($row);
    $originalBytes = $json === null ? 0 : strlen($json) + 1;
    $limit = journalRecordByteLimit();
    if ($json !== null && $limit > 0 && $originalBytes <= $limit) {
        return ['line' => $json . "\n", 'truncated' => false, 'original_bytes' => $originalBytes];
    }
    $reason = $json === null ? 'json_encode_failed' : 'record_too_large';
    return [
        'line' => journalTruncatedLine($row, $originalBytes, $reason),
        'truncated' => true,
        'original_bytes' => $originalBytes,
    ];
}

/**
 * Защитный контракт для прямого вызова journalAppend(): ровно одна строка и
 * ровно один общий предел. appLog уже приходит сюда в таком виде, но этот слой
 * не позволяет будущему вызывающему снова пробить потолок сырым текстом.
 *
 * @return array{line:string,truncated:bool,original_bytes:int}
 */
function journalPrepareLine(string $line): array {
    $originalBytes = strlen($line);
    $contentBytes = $originalBytes;
    while ($contentBytes > 0 && ($line[$contentBytes - 1] === "\n" || $line[$contentBytes - 1] === "\r")) {
        $contentBytes--;
    }
    $firstBreak = strcspn($line, "\r\n");
    $hasInnerBreak = $firstBreak < $contentBytes;
    $normalBytes = $contentBytes + 1;
    if (!$hasInnerBreak && $normalBytes <= journalRecordByteLimit()) {
        return [
            'line' => substr($line, 0, $contentBytes) . "\n",
            'truncated' => false,
            'original_bytes' => $originalBytes,
        ];
    }

    $marker = [
        'ts' => date('c'), 'rid' => bin2hex(random_bytes(12)),
        'level' => 'warn', 'src' => logSource(),
        'event' => 'log.raw_record_truncated',
        'install_generation' => (string)($GLOBALS['__app_log_install_generation'] ?? ''),
        'ref' => logRef(), 'kind' => 'system', 'uid' => 0, 'ip' => '', 'msg' => '',
    ];
    return [
        'line' => journalTruncatedLine($marker, $originalBytes,
            $hasInnerBreak ? 'raw_multiline' : 'record_too_large'),
        'truncated' => true,
        'original_bytes' => $originalBytes,
    ];
}

/** Записать все байты или явно сообщить об ошибке частичной записи. */
// Байт-в-байт та же запись, что и в store.php, поэтому держим ОДНУ реализацию:
// две копии расходятся при первой же правке, и журнал начинает вести себя иначе,
// чем состояние, — молча. Модуль store.php здесь уже подключён.
function journalWriteAll($fh, string $bytes): bool {
    return storeWriteAll($fh, $bytes);
}

/**
 * Дописать строку в дневной файл.
 *
 * Именно дописать, а не «прочитать-изменить-записать»: журнал пишут все запросы
 * разом, и переписывание файла целиком означало бы очередь на замке и потерю
 * записей соседа. Переполненный день откладывается отдельным файлом — история не
 * пропадает, но и один день не съедает диск.
 *
 * @return array{written:bool,truncated:bool,bytes:int,original_bytes:int,rotated:bool,reason:string}
 */
function journalAppend(string $line, ?string $expectedGeneration = null): array {
    $prepared = journalPrepareLine($line);
    $result = [
        'written' => false,
        'truncated' => $prepared['truncated'],
        'bytes' => 0,
        'original_bytes' => $prepared['original_bytes'],
        'rotated' => false,
        'reason' => 'not_written',
    ];
    if ($prepared['line'] === '') return $result;
    $line = $prepared['line'];
    // Недоступный каталог журнала — это НЕУСПЕХ ЗАПИСИ, а не исключение. Создание
    // каталога умеет бросать (и правильно делает), но здесь бросок означал бы
    // катастрофу: вызов стоит до try, поэтому исключение улетало в обработчик
    // необработанных ошибок, а тот первым делом снова зовёт запись в журнал —
    // второе исключение внутри обработчика, и человек получает пустой 500 без
    // единой строки диагностики. Вместо этого возвращаем обычный отказ: ниже по
    // коду он уже умеет уходить в системный error_log аварийной диагностикой.
    try {
        $logFile = journalFile();
    } catch (Throwable $e) {
        $result['reason'] = 'log_dir_unavailable';
        return $result;
    }
    $day = substr(basename($logFile), 0, 10);
    $maxBytes = (int)LOG_DAY_MAX_BYTES;
    if ($maxBytes <= 0 || strlen(JOURNAL_FILE_PREFIX) + strlen($line) > $maxBytes) return $result;

    // Замок на отдельном стабильном inode сериализует и rename, и открытие
    // нового дневного файла. Замок самого .log.php этого не умеет: после rename
    // старый и новый запросы запирают уже разные inode и пишут одновременно.
    // Замок живёт ВНЕ logs/: lifecycle-уборка удаляет содержимое logs целиком.
    // Если unlink-нуть запертый inode, следующий писатель создаст новый и оба
    // окажутся «под замком» одновременно — ровно тот race, который здесь закрыт.
    try { appJournalMutationLockAcquire(); }
    catch (Throwable $e) { return $result; }
    try {
        // После process-crash lifecycle-lock уже отпущен, а manifest ещё есть.
        // Writer без lifecycle-fence не должен успеть создать/дописать journal
        // между crash и recovery и тем самым сделать delete-state неоднозначным.
        if (is_file(appStateTransitionManifestFile())) {
            $result['reason'] = 'lifecycle_transition';
            return $result;
        }

        // Writer мог сформировать строку до lifecycle-fence, проиграть cleanup
        // за stable lock и проснуться уже после публикации другого поколения.
        // Если writer был первым — cleanup удалит его строку; если cleanup был
        // первым — stale writer здесь ничего физически не создаёт.
        if ($expectedGeneration !== null) {
            try { $currentTokens = storeRead(B24_TOKENS_FILE) ?? []; }
            catch (Throwable $e) {
                $result['reason'] = 'install_generation_unreadable';
                return $result;
            }
            $currentGeneration = appInstallGenerationFrom($currentTokens);
            if ($currentGeneration === ''
                && appInstallationPhaseFrom($currentTokens) === 'installed'
                && empty($currentTokens['access_token'])) {
                $currentGeneration = appWebhookInstallGeneration();
            }
            $heldLifecycle = $GLOBALS['__app_lifecycle_lock'] ?? null;
            $cleanupOwner = is_array($heldLifecycle)
                && (int)($heldLifecycle['mode'] ?? 0) === LOCK_EX;
            // Пустой generation допустим только у действительно пустого state.
            // Активная legacy-установка без generation должна сначала пройти
            // appEnsureInstallGeneration(), а не писать неограждённый журнал.
            $unboundActiveInstall = $expectedGeneration === '' && $currentGeneration === ''
                && !empty($currentTokens['access_token'])
                && empty($currentTokens['uninstalled_at']) && empty($currentTokens['cleanup_pending']);
            if ($unboundActiveInstall
                || !hash_equals($expectedGeneration, $currentGeneration)
                || ((!empty($currentTokens['cleanup_pending']) || !empty($currentTokens['uninstalled_at']))
                    && !$cleanupOwner)) {
                $result['reason'] = $unboundActiveInstall
                    ? 'install_generation_missing'
                    : 'install_generation_mismatch';
                return $result;
            }
        }

        // Заглушку и строку пишем под ОДНИМ замком. Раздельно нельзя: два запроса,
        // попав в промежуток, оставляли файл без 404+exit-заглушки первой строкой — то
        // есть журнал скачивался бы по прямой ссылке.
        $fh = @fopen($logFile, 'ab');
        if (!$fh) return $result;
        if (!storeApplyPrivatePermissions($logFile)) {
            fclose($fh);
            $result['reason'] = 'chmod_failed';
            return $result;
        }
        if (!@flock($fh, LOCK_EX)) {
            fclose($fh); return $result;
        }

    // Размер спрашиваем У ФАЙЛА. В режиме дозаписи ftell() возвращает ноль при
    // каждом открытии — по нему заглушка приписывалась к КАЖДОЙ строке и раздувала
    // журнал вчетверо.
        $stat = @fstat($fh);
        $size = (int)($stat['size'] ?? 0);

    // Переполненный день откладываем отдельным файлом: история не пропадает, но
    // и один день не съедает диск. Читалки видят оба (см. journalQuery).
    // Откладываем ПОД ЗАМКОМ и с неповторяющимся именем: раньше два переполнения
    // в одну секунду переименовывали файл в один и тот же путь, и первый архив
    // молча исчезал вместе со всем, что в нём было.
        if ($size > 0 && ($size > $maxBytes || $size + strlen($line) > $maxBytes)) {
            $now = microtime(true);
            $second = (int)$now;
            $micros = (int)(($now - $second) * 1000000);
            $archive = DATA_ROOT . '/' . LOG_DIR_NAME . '/' . $day . '-' . date('His', $second)
                     . '-' . sprintf('%06d', $micros)
                     . '-' . bin2hex(random_bytes(6)) . '.log.php';
            if (@rename($logFile, $archive)) {
                $result['rotated'] = true;
                journalTrimArchives($day);
                // Дескриптор после переименования указывает на архив — открываем новый файл.
                @flock($fh, LOCK_UN);
                fclose($fh);
                $fh = @fopen($logFile, 'ab');
                if (!$fh) return $result;
                if (!storeApplyPrivatePermissions($logFile)) {
                    fclose($fh);
                    $result['reason'] = 'chmod_failed';
                    return $result;
                }
                if (!@flock($fh, LOCK_EX)) {
                    fclose($fh); return $result;
                }
                $size = 0;
            } else {
                @flock($fh, LOCK_UN);
                fclose($fh);
                return $result; // fail-closed: не пробиваем cap, если rotate не удался
            }
        }

        $payload = ($size === 0 ? JOURNAL_FILE_PREFIX : '') . $line;
        if ($size + strlen($payload) <= $maxBytes
            && journalWriteAll($fh, $payload)
            && storeFlushAndSync($fh)) {
            $result['written'] = true;
            $result['bytes'] = strlen($line);
            $result['reason'] = '';
        }
        @flock($fh, LOCK_UN);
        fclose($fh);
        return $result;
    } finally {
        appJournalMutationLockRelease();
    }
}

/**
 * Не больше LOG_DAY_MAX_FILES отложенных файлов на день.
 *
 * Без потолка публичный адрес приложения превращался в способ занять диск:
 * отказы гейта пишутся всем подряд, день переполняется снова и снова, а срок
 * хранения (14 дней) удаляет файлы слишком поздно. Лишние — самые старые.
 */
function journalTrimArchives(?string $day = null): int {
    $day = $day ?? date('Y-m-d');
    $files = glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/' . $day . '-*.log.php') ?: [];
    if (count($files) <= LOG_DAY_MAX_FILES) return 0;
    sort($files);                                   // имя начинается с времени — старые первыми
    $extra = array_slice($files, 0, count($files) - LOG_DAY_MAX_FILES);
    $n = 0;
    foreach ($extra as $f) if (@unlink($f)) $n++;
    return $n;
}

/**
 * Прочитать журнал С КОНЦА (свежее — первым), с фильтрами.
 *
 * Читаем блоками от конца файла, а не целиком: дневной журнал бывает в мегабайты,
 * а человеку нужны последние строки. Лимит проверяется до разбора json — иначе
 * тяжёлый день упирался бы в память ровно тогда, когда журнал нужнее всего.
 *
 * @param array $f ['day'=>Y-m-d, 'level'=>..., 'kind'=>'action', 'ref'=>..., 'q'=>подстрока,
 *                  'limit'=>int, 'cursor'=>rid последней строки предыдущей страницы]
 * `truncated=true` означает, что результат неполон: достигнут потолок сканирования
 * либо пропущена старая/повреждённая физическая строка больше безопасного лимита.
 *
 * @return array{rows:array,scanned:int,truncated:bool,cursor_found:bool}
 */
function journalQuery(array $f = []): array {
    $day   = (string)($f['day'] ?? date('Y-m-d'));
    $limit = max(1, min(500, (int)($f['limit'] ?? 100)));
    $minRank = LOG_LEVELS[(string)($f['level'] ?? 'debug')] ?? 0;
    $cursor = trim((string)($f['cursor'] ?? ''));
    $cursorFound = $cursor === '';

    // Файлы одного дня: текущий и отложенные при переполнении, свежие первыми.
    // Без этого после откладывания журнал показывал только хвост — а человек
    // как раз в этот момент и идёт смотреть, что произошло.
    $archives = glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/' . $day . '-*.log.php') ?: [];
    rsort($archives);
    $files = array_merge([journalFile($day)], $archives);

    $rows = [];
    $scanned = 0;
    $truncated = false;
    $stopped = false;
    foreach ($files as $file) {
        if (count($rows) >= $limit || $stopped) break;
        if (!is_file($file)) continue;
        $f['_cursor_found'] = $cursorFound;
        $part = journalScanFile($file, $limit - count($rows), $minRank, $f, $scanned);
        foreach ($part['rows'] as $r) $rows[] = $r;
        $scanned   = $part['scanned'];
        $truncated = $truncated || $part['truncated'];
        $stopped   = $part['stopped'];
        $cursorFound = $part['cursor_found'];
    }
    return ['rows' => $rows, 'scanned' => $scanned, 'truncated' => $truncated,
        'cursor_found' => $cursorFound];
}

/** Unicode-lowercase для русского текста, когда расширения mbstring нет. */
function journalFallbackLower(string $value): string {
    static $upperToLower = [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и',
        'Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т',
        'У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь',
        'Э'=>'э','Ю'=>'ю','Я'=>'я','І'=>'і','Ї'=>'ї','Є'=>'є','Ґ'=>'ґ','Ў'=>'ў',
    ];
    return strtolower(strtr($value, $upperToLower));
}

/** Case-insensitive substring без обязательной зависимости от mbstring. */
/**
 * Может ли строка журнала пройти отбор по уровню — БЕЗ разбора JSON.
 *
 * Дешёвая проверка перед дорогой: в строке ищется `"level":"<уровень>"` для тех
 * уровней, которые проходят порог. Ошибиться в сторону «пропустить лишнее»
 * безопасно — точный отбор идёт ниже по разобранной записи; ошибиться в сторону
 * «отбросить нужное» нельзя, поэтому строку без опознанного уровня пропускаем.
 */
function journalLineMayPassLevel(string $stroka, int $minRank): bool {
    foreach (LOG_LEVELS as $imya => $rang) {
        if ($rang < $minRank) continue;
        if (strpos($stroka, '"level":"' . $imya . '"') !== false) return true;
    }
    // Уровня в строке не нашли (другой формат, старая запись) — не отбрасываем.
    return strpos($stroka, '"level":"') === false;
}

function journalTextContains(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return strpos(journalFallbackLower($haystack), journalFallbackLower($needle)) !== false;
}

/** Прочитать ОДИН файл журнала с конца. Вынесено, чтобы читать день целиком. */
function journalScanFile(string $file, int $limit, int $minRank, array $f, int $scanned): array {
    $fh = @fopen($file, 'rb');
    if (!$fh) return ['rows' => [], 'scanned' => $scanned, 'truncated' => false, 'stopped' => false];

    $stat = @fstat($fh);
    $size = (int)($stat['size'] ?? 0);
    $chunk = 65536;
    $lineLimit = max(1, (int)LOG_SCAN_LINE_MAX_BYTES);
    $scanLimit = max(1, (int)LOG_SCAN_MAX_RECORDS);
    $pos = $size;
    $tail = '';
    $tailOversized = false;
    $rows = [];
    $truncated = false;
    $stopped = false;
    $cursor = trim((string)($f['cursor'] ?? ''));
    $cursorFound = !empty($f['_cursor_found']) || $cursor === '';

    // Одна bounded-функция обслуживает и обычную строку, и явно пропущенную
    // гигантскую. Во втором случае JSON не разбираем, но честно выставляем
    // truncated и продолжаем к более старым пригодным строкам.
    $visit = static function (string $raw, bool $oversized) use (
        &$rows, &$scanned, &$truncated, &$stopped, &$cursorFound,
        $cursor, $limit, $scanLimit, $minRank, $f
    ): void {
        if ($stopped || count($rows) >= $limit) return;
        if ($oversized) {
            $truncated = true;
            $scanned++;
            if ($scanned > $scanLimit) $stopped = true;
            return;
        }

        $s = trim($raw);
        if ($s === '') return;
        // Единственная не-JSON строка штатного файла — защитный PHP-prefix.
        // Любой другой мусор означает потерянную запись: не показываем его как
        // данные, но честно отмечаем результат неполным и учитываем в scan cap.
        if ($s[0] !== '{') {
            if (privatePhpHasExecutablePrefix($s)) return;
            $truncated = true;
            $scanned++;
            if ($scanned > $scanLimit) $stopped = true;
            return;
        }
        $scanned++;
        if ($scanned > $scanLimit) {
            $truncated = true;
            $stopped = true;
            return;
        }
        // Дешёвый отсев ДО разбора json: на большом дне разбор каждой строки
        // ради двух подходящих — это и есть «экран думает».
        if (!empty($f['q']) && !journalTextContains($s, (string)$f['q'])) return;
        if (!empty($f['ref']) && strpos($s, '"' . $f['ref'] . '"') === false) return;
        // Уровень тоже отсеиваем ДО разбора. Экран журнала по умолчанию
        // открывают на «ошибки», а в дне лежат в основном info: без этого отсева
        // разбирался КАЖДЫЙ день целиком ради нескольких строк, и на большом
        // журнале экран заметно думал. Строка отбрасывается только если её
        // уровня в ней нет вовсе — точный отбор ниже всё равно по разобранному.
        if ($minRank >= 2 && !journalLineMayPassLevel($s, $minRank)) return;
        $row = json_decode($s, true);
        if (!is_array($row)) {
            $truncated = true;
            return;
        }
        if ((LOG_LEVELS[$row['level'] ?? 'info'] ?? 1) < $minRank) return;
        if (array_key_exists('install_generation', $f)) {
            // Фильтр принимает и одно поколение, и СПИСОК: после переустановки на
            // том же портале журнал прежней регистрации остаётся доступен, а
            // строки другого портала — нет (там список не наследуется вовсе).
            $rowGeneration = (string)($row['install_generation'] ?? '');
            $wanted = $f['install_generation'];
            if (is_array($wanted)) {
                if (!in_array($rowGeneration, array_map('strval', $wanted), true)) return;
            } elseif ($rowGeneration !== (string)$wanted) return;
        }
        if (!empty($f['kind']) && ($row['kind'] ?? '') !== $f['kind']) return;
        if (!empty($f['ref'])  && ($row['ref'] ?? '') !== $f['ref']) return;
        if (!empty($f['q'])    && !journalTextContains($s, (string)$f['q'])) return;
        if (!$cursorFound) {
            // Старые строки без rid остаются читаемыми через прежний ref. Все
            // новые записи получают уникальный rid и не конфликтуют даже
            // внутри одного request-wide ref.
            $rowCursor = (string)($row['rid'] ?? ($row['ref'] ?? ''));
            if ($rowCursor !== '' && hash_equals($cursor, $rowCursor)) $cursorFound = true;
            return;
        }
        $rows[] = $row;
    };

    while ($pos > 0 && count($rows) < $limit && !$stopped) {
        $read = (int)min($chunk, $pos);
        $pos -= $read;
        if (@fseek($fh, $pos) !== 0) { $truncated = true; $stopped = true; break; }
        $data = @fread($fh, $read);
        if (!is_string($data) || strlen($data) !== $read) {
            $truncated = true;
            $stopped = true;
            break;
        }
        $parts = explode("\n", $data);

        // В блоке нет границы строки: переносим в следующий круг только
        // ограниченный хвост. После превышения сами байты больше не копим.
        if (count($parts) === 1) {
            if ($tailOversized || strlen($data) + strlen($tail) > $lineLimit) {
                $tail = '';
                $tailOversized = true;
            } else {
                $tail = $data . $tail;
            }
            if ($pos === 0) {
                $visit($tail, $tailOversized);
                $tail = '';
                $tailOversized = false;
            }
            continue;
        }

        $left = (string)array_shift($parts);  // незаконченная строка слева
        $right = (string)array_pop($parts);   // продолжение прежнего tail справа

        if ($tailOversized || strlen($right) + strlen($tail) > $lineLimit) {
            $visit('', true);
        } else {
            $visit($right . $tail, false);
        }
        $tail = '';
        $tailOversized = false;

        // Между крайними кусками лежат целые строки; идём от новых к старым.
        for ($i = count($parts) - 1; $i >= 0 && count($rows) < $limit && !$stopped; $i--) {
            $part = (string)$parts[$i];
            $visit($part, strlen($part) > $lineLimit);
        }

        if ($pos === 0) {
            if (count($rows) < $limit && !$stopped) $visit($left, strlen($left) > $lineLimit);
        } elseif (strlen($left) > $lineLimit) {
            $tailOversized = true;
        } else {
            $tail = $left;
        }
    }
    fclose($fh);
    return [
        'rows' => $rows,
        'scanned' => $scanned,
        'truncated' => $truncated,
        'stopped' => $stopped,
        'cursor_found' => $cursorFound,
    ];
}

/** Какие дни есть в журнале (свежие первыми). */
function journalDays(): array {
    $dir = DATA_ROOT . '/' . LOG_DIR_NAME;
    $days = [];
    foreach (glob($dir . '/*.log.php') ?: [] as $f) {
        $day = substr(basename($f), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $days[$day] = true;
    }
    krsort($days);
    return array_keys($days);
}

/**
 * Убрать журналы старше срока. Возвращает, сколько файлов удалено, — чтобы
 * «уборка отработала» было видно, а не предполагалось.
 */
function journalPrune(?int $days = null): int {
    $days = $days ?? LOG_RETENTION_DAYS;
    $edge = time() - $days * 86400;
    $n = 0;
    foreach (glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: [] as $f) {
        if (@filemtime($f) < $edge && @unlink($f)) $n++;
    }
    return $n;
}
