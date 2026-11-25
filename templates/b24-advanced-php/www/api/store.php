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
 * Защищённый файловый store: .php-файлы с единым 404+exit-префиксом.
 * Прямой HTTP-запрос → PHP-интерпретатор ставит 404 и завершает выполнение
 * до данных (защита-в-глубину; .htaccess не требуется).
 *
 * Все state-файлы приложения (b24-tokens, settings, sessions, tokens,
 * b24-smart) хранятся через эти helpers.
 *
 * ── Как менять состояние ───────────────────────────────────────────────
 * ВСЕГДА через `storeUpdate($файл, function (array $было): array { ... })`.
 *
 * Почему не «прочитал → поправил → записал» вручную. Приложение живёт в
 * iframe: портал открывает несколько запросов разом (страница + фоновые
 * вызовы), и все они лезут в одни и те же файлы. Два запроса читают одно
 * состояние, каждый правит своё поле и по очереди пишет целиком — правка
 * первого исчезает бесследно. Живой пример: один запрос обновил протухший
 * access_token, второй в это же время отметил, что пользователь прошёл
 * установку. Кто записал вторым — тот и прав, второе изменение потеряно, а
 * выглядит это как «приложение случайно разлогинивается».
 *
 * `storeUpdate` держит файл под эксклюзивным замком от чтения до записи,
 * поэтому второй запрос дождётся и увидит уже обновлённое состояние.
 * Замок не взялся — функция бросает исключение и НИЧЕГО не пишет: лучше
 * честная ошибка, чем тихо затёртые токены.
 */

/** Состояние на диске повреждено — работать с ним нельзя. */
class StoreCorruptedException extends RuntimeException {
    public string $stateFile;   // какой файл оказался битым
    public string $backupFile;  // куда отложена копия для разбора
    public function __construct(string $stateFile, string $backupFile) {
        $this->stateFile  = $stateFile;
        $this->backupFile = $backupFile;
        $message = 'Файл состояния повреждён: ' . basename($stateFile) . '. ';
        $message .= $backupFile !== ''
            ? 'Копия отложена как ' . basename($backupFile) . '.'
            : 'Копию создать не удалось; оригинал оставлен без изменений.';
        parent::__construct($message);
    }
}

/**
 * Файл состояния есть, но прочитать его не удалось.
 *
 * ОТДЕЛЬНО ОТ ПОВРЕЖДЕНИЯ, И ЭТО НЕ ПРИДИРКА. Живой случай 05.09.2026: у файла
 * состояния сменился владелец, приложение перестало его читать — и человек
 * увидел «Данные приложения повреждены… покажите разработчику код обращения».
 * Данные были целы до последнего байта, а чинилось всё возвратом прав.
 *
 * Причины разные, и действия разные: повреждение разбирает разработчик по
 * отложенной копии, отказ в доступе чинит администратор одной командой. Одно
 * сообщение на оба случая уводит по ложному следу и превращает минуту работы в
 * разбирательство.
 *
 * КОПИЮ В КАРАНТИН ЗДЕСЬ НЕ КЛАДЁМ. Прочитать файл не вышло — значит и копировать
 * нечего: улики в пустом файле нет, а в каталоге состояния он выглядит как след
 * аварии, которой не было.
 */
class StoreUnreadableException extends RuntimeException {
    public string $stateFile;
    public function __construct(string $stateFile) {
        $this->stateFile = $stateFile;
        parent::__construct('Файл состояния не удалось прочитать: ' . basename($stateFile)
            . '. Данные целы — проверьте права доступа и владельца файла.');
    }
}

/** Файл корректного формата превысил caller-specific pre-decode ceiling. */
class StoreReadLimitException extends RuntimeException {
    public string $stateFile;
    public int $maxBytes;
    public function __construct(string $stateFile, int $maxBytes) {
        $this->stateFile = $stateFile;
        $this->maxBytes = $maxBytes;
        parent::__construct('store: ' . basename($stateFile) . ' больше допустимого предела '
            . $maxBytes . ' байт; чтение остановлено до JSON decode');
    }
}

/**
 * Замок по бизнес-ключу занят другим процессом — работа НЕ выполнялась.
 *
 * Отдельный тип, а не общий RuntimeException: вызывающий почти всегда хочет
 * различать «работа упала» и «работу уже делает сосед». Разбор по тексту
 * сообщения ломается от первой же правки формулировки.
 */
class KeyLockBusyException extends RuntimeException {}

/** Сколько ждать чужой записи, прежде чем признать поражение (секунды). */
const STORE_LOCK_TIMEOUT = 5.0;

// Эти aggregate ceiling нужны не только владельцам task/crm state, но и
// общему lifecycle cleanup в lib.php. Store загружается раньше обоих модулей,
// поэтому здесь остаётся единственный источник значений для cross-file read.
if (!defined('TASK_MAX_BYTES'))     define('TASK_MAX_BYTES', 256 * 1024);
if (!defined('CRM_DICT_MAX_BYTES')) define('CRM_DICT_MAX_BYTES', 8 * 1024 * 1024);

/**
 * Единый исполняемый префикс ВСЕХ файлов в data/.
 *
 * Важно не разносить его копии по модулям: обычный `exit` скрывает тело, но
 * оставляет HTTP 200, из-за чего deploy не может доказать контракт приватного
 * пути. Старый префикс обычно принимаем только на чтение. Единственное
 * исключение — первый release после старого reader: deploy помечает его как
 * transitional writer, чтобы автоматический rollback продолжал читать state.
 * Следующая выкладка уже видит dual-reader и автоматически пишет 404.
 */
const BXAPP_PRIVATE_PHP_404_PREFIX = '<?php http_response_code(404); exit;';
const BXAPP_PRIVATE_PHP_LEGACY_PREFIX = '<?php exit;';
const BXAPP_PRIVATE_PHP_404_HEADER = "<?php http_response_code(404); exit; /* приватный файл bx-app */ ?>\n";
const BXAPP_PRIVATE_PHP_LEGACY_HEADER = "<?php exit; /* private bx-app rollback bridge */ ?>\n";
if (defined('BXAPP_PRIVATE_PHP_HEADER')) {
    throw new LogicException('BXAPP_PRIVATE_PHP_HEADER нельзя переопределять из project/env-кода.');
}
define('BXAPP_PRIVATE_PHP_HEADER',
    defined('BXAPP_RELEASE_WRITE_LEGACY') && BXAPP_RELEASE_WRITE_LEGACY === true
        ? BXAPP_PRIVATE_PHP_LEGACY_HEADER
        : BXAPP_PRIVATE_PHP_404_HEADER);

function privatePhpHas404Prefix(string $content): bool {
    return strncmp($content, BXAPP_PRIVATE_PHP_404_PREFIX, strlen(BXAPP_PRIVATE_PHP_404_PREFIX)) === 0;
}

/** Старый формат безопасен от чтения тела, но даёт HTTP 200; только для миграции. */
function privatePhpHasLegacyPrefix(string $content): bool {
    return strncmp($content, BXAPP_PRIVATE_PHP_LEGACY_PREFIX, strlen(BXAPP_PRIVATE_PHP_LEGACY_PREFIX)) === 0;
}

function privatePhpHasExecutablePrefix(string $content): bool {
    return privatePhpHas404Prefix($content) || privatePhpHasLegacyPrefix($content);
}

function storeEncode(array $data): string {
    // Сериализация может провалиться (битый UTF-8 в любом значении, слишком
    // глубокая вложенность) и вернуть false. Без этой проверки на диск легла бы
    // одна php-заглушка без данных, а следующее чтение сочло бы её «состояния
    // нет» — то есть приложение молча забыло бы, что оно установлено.
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('store: состояние не сериализуется (' . json_last_error_msg()
            . '). Записи НЕ было — проверь, что кладёшь в стор.');
    }
    return BXAPP_PRIVATE_PHP_HEADER . $json;
}

function storeDecode(string $content): ?array {
    // Одного закрывающего PHP-тега недостаточно. Иначе файл с обычным текстом
    // перед тегом и JSON после него считался валидным state, хотя при
    // прямом HTTP PHP отдаст его целиком как текст. Принимаем только наш точный
    // исполняемый префикс с первого байта; BOM/пробелы перед ним тоже
    // небезопасны, потому что уже попадут в ответ до exit.
    if (!privatePhpHasExecutablePrefix($content)) return null;
    $closeTag = '?' . '>';
    $pos = strpos($content, $closeTag);
    if ($pos === false) {
        // Файл без исполняемой 404/legacy-заглушки — не наш формат. Считать его состоянием нельзя:
        // так в стор попадал бы посторонний файл, случайно оказавшийся рядом.
        return null;
    }
    $j = json_decode(ltrim(substr($content, $pos + 2)), true);
    return is_array($j) ? $j : null;
}

/** Записать строку полностью: fwrite вправе вернуть короткую запись без false. */
function storeWriteAll($fh, string $bytes): bool {
    $length = strlen($bytes);
    $offset = 0;
    while ($offset < $length) {
        $written = @fwrite($fh, substr($bytes, $offset));
        if (!is_int($written) || $written <= 0) return false;
        $offset += $written;
    }
    return true;
}

/**
 * Каталог состояния существует и принадлежит нам — иначе громкая ошибка.
 *
 * `@mkdir()` без проверки — самый тихий способ потерять данные: каталога нет,
 * следующая запись падает уже в другом месте и с другим текстом, а причина
 * («не создался каталог, диск полон / нет прав») теряется. Гонку учитываем:
 * параллельный процесс мог создать каталог между проверкой и вызовом — это
 * успех, а не ошибка.
 */
function storeEnsureDir(string $dir, int $mode = 0700): void {
    if (is_dir($dir)) return;
    if (@mkdir($dir, $mode, true)) return;
    clearstatcache(true, $dir);
    if (is_dir($dir)) return;   // успел сосед — это норма
    throw new RuntimeException('store: не создать каталог состояния ' . basename($dir)
        . ' — проверьте права и свободное место');
}

/**
 * Записать exit-префикс в только что созданный файл состояния.
 *
 * Провал этой записи — не мелочь: без префикса файл становится обычным .php,
 * и веб-сервер отдаст его содержимое по прямой ссылке. Молчать тут нельзя.
 */
function storeWritePrivateHeader($fh, string $path): void {
    if (!storeWriteAll($fh, BXAPP_PRIVATE_PHP_HEADER) || !@fflush($fh)) {
        throw new RuntimeException('store: не записать защитный префикс в ' . basename($path)
            . ' — файл остался бы доступен по прямой ссылке');
    }
    if (!storeApplyPrivatePermissions($path)) {
        throw new RuntimeException('store: не выставить приватные права на ' . basename($path));
    }
}

/** Права private-state — часть успеха записи, а не best-effort украшение. */
function storeApplyPrivatePermissions(string $path): bool {
    if (!@chmod($path, 0600)) return false;
    clearstatcache(true, $path);
    $perms = @fileperms($path);
    return is_int($perms) && (($perms & 0777) === 0600);
}

/** Данные считаются принятыми файловой системой только после flush+fsync. */
function storeFlushAndSync($fh): bool {
    if (!@fflush($fh)) return false;
    return !function_exists('fsync') || @fsync($fh);
}

/**
 * Физическая запись: временный файл рядом → переименование поверх.
 * Переименование в пределах одной файловой системы атомарно, поэтому читатель
 * никогда не видит половину файла.
 *
 * Имя временного файла — уникальное на процесс И оканчивается на `.php`.
 * Оба требования обязательны: общий `x.php.tmp` два процесса писали бы друг
 * поверх друга, а имя без `.php` веб-сервер отдал бы как обычный текст —
 * то есть на долю секунды токены становились бы доступны по прямой ссылке.
 *
 * Вызывать напрямую нельзя — только из storeUpdate (иначе запись без замка).
 */
/**
 * ТОЧКА ОТКАЗА ДЛЯ ИСПЫТАНИЙ — «а что, если процесс умрёт ровно здесь».
 *
 * Зачем. Самые дорогие ошибки живут не в коде, а в местах ОБРЫВА: временный файл
 * записан, но не переименован; портал ответил, но ответ не дошёл; состояние
 * записано, а квитанция — нет. Проверить это можно только одним способом —
 * оборвать выполнение в нужной точке. Раньше такие сценарии проверялись
 * рассуждением, то есть не проверялись.
 *
 * Работает ТОЛЬКО при подменённом сторе (задан BXAPP_DATA_ROOT), как и шов
 * подмены портала. На боевом сервере такой переменной нет, и точка отказа не
 * существует — включить её снаружи нечем.
 *
 * @param string $name Имя точки: 'store.after_tmp_write', 'store.before_rename', …
 */
function failpoint(string $name): void {
    if (empty($GLOBALS['__failpoints'][$name])) return;
    $data = getenv('BXAPP_DATA_ROOT');
    if ($data === false || $data === '') return;
    $what = $GLOBALS['__failpoints'][$name];
    unset($GLOBALS['__failpoints'][$name]);   // срабатывает один раз, иначе не выйти
    if (is_callable($what)) { $what($name); return; }
    throw new RuntimeException('точка отказа: ' . $name);
}

function storeWriteRaw(string $file, array $data): void {
    $dir = dirname($file);
    storeEnsureDir($dir);
    // Сериализуем ДО создания temp: битая UTF-8/глубина не оставляет пустой
    // private-файл и открытый дескриптор до конца запроса.
    $payload = storeEncode($data);
    if (defined('STATE_FILE') && defined('STATE_MAX_BYTES')
        && $file === STATE_FILE && strlen($payload) > STATE_MAX_BYTES) {
        throw new StateFileLimitException('state_file_too_large:write', 1702);
    }
    $tmp = $file . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    // Права ставим ДО записи: между созданием файла и chmod он секунду лежит с
    // правами по умолчанию — а внутри токены портала.
    $fh = @fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException('store: не записать ' . basename($file) . ' (нет прав на data/?)');
    }
    if (!storeApplyPrivatePermissions($tmp)) {
        fclose($fh); @unlink($tmp);
        throw new RuntimeException('store: не установить private-права 0600 для ' . basename($file));
    }
    if (!storeWriteAll($fh, $payload)) {
        fclose($fh); @unlink($tmp);
        throw new RuntimeException('store: запись ' . basename($file) . ' оборвалась (кончилось место?)');
    }
    // Сбрасываем данные самого файла до rename: это закрывает process-crash с
    // пустым временным файлом. fsync каталога здесь нет, поэтому порядок имён
    // после потери питания не обещаем (может сохраниться прежняя версия).
    if (!storeFlushAndSync($fh)) {
        fclose($fh); @unlink($tmp);
        throw new RuntimeException('store: flush/fsync ' . basename($file) . ' не удался; запись НЕ опубликована');
    }
    fclose($fh);
    // Обрыв ПОСЛЕ записи временного файла, но ДО публикации: на диске остаётся
    // старая версия целиком и мусорный tmp рядом. Это правильный исход, и он
    // обязан проверяться, а не предполагаться.
    failpoint('store.before_rename');
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('store: не переименовать временный файл в ' . basename($file));
    }
    // Обрыв СРАЗУ ПОСЛЕ публикации: новое состояние уже на диске, а вызывающий
    // об этом не узнает. Отсюда берутся «повторил — получил второе».
    failpoint('store.after_rename');
}

/**
 * Прочитать состояние, дать его изменить и записать — всё под одним замком.
 * Единственный правильный способ менять файлы в data/.
 *
 * @param string   $file    Файл состояния.
 * @param callable $mutator function(array $было): ?array — вернуть новое
 *                          состояние целиком, либо null, чтобы не писать.
 * @param float    $timeout Сколько ждать чужую запись. Поднимать только там, где
 *                          под замком идёт сетевой вызов (обновление токенов):
 *                          обычному запросу столько ждать нельзя.
 * @param int      $maxReadBytes Caller-specific потолок ДО JSON decode. Ноль —
 *                          обычный store без дополнительного ограничения.
 * @param callable|null $validator function(array $кандидат): array — предметная
 *                          проверка candidate под тем же EX-lock, до публикации.
 * @return array Состояние, которое лежит на диске после вызова.
 * @throws RuntimeException Замок не взялся за отведённое время — записи НЕ было.
 * @throws StoreCorruptedException Файл на диске битый — записи НЕ было.
 */
function storeUpdate(
    string $file,
    callable $mutator,
    float $timeout = STORE_LOCK_TIMEOUT,
    int $maxReadBytes = 0,
    ?callable $validator = null
): array {
    // Какие файлы этот процесс уже держит. Повторный замок из того же процесса
    // на новом дескрипторе не «узнаёт своего» — он встанет намертво, поэтому
    // вложенный вызов работает внутри уже взятого замка.
    static $held = [];

    $dir = dirname($file);
    storeEnsureDir($dir);

    if (!empty($held[$file])) {
        // Вложенная правка ТОГО ЖЕ файла — всегда ошибка в коде: внешний вызов
        // держит своё представление состояния и, закончив, запишет его поверх —
        // вложенная правка исчезнет без следа. Правильно менять тот массив,
        // который мутатору и передали.
        throw new RuntimeException('store: ' . basename($file) . ' уже правится этим же запросом. '
            . 'Не вызывай storeUpdate того же файла внутри мутатора — меняй переданный массив.');
    }

    // Замок — отдельный файл рядом. Имя с `.php` и exit-префиксом внутри:
    // всё, что лежит в data/, должно быть закрыто от прямого запроса.
    $lockFile = $file . '.lock.php';
    $fh = @fopen($lockFile, 'c+b');
    if ($fh === false) {
        throw new RuntimeException('store: не открыть файл замка для ' . basename($file));
    }
    if (!storeApplyPrivatePermissions($lockFile)) {
        fclose($fh);
        throw new RuntimeException('store: не установить private-права 0600 для lock ' . basename($file));
    }
    $lockStat = @fstat($fh);
    if ((int)($lockStat['size'] ?? 0) === 0) {
        if (!storeWriteAll($fh, BXAPP_PRIVATE_PHP_HEADER) || !storeFlushAndSync($fh)) {
            fclose($fh);
            throw new RuntimeException('store: не записать безопасный header lock ' . basename($file));
        }
    }

    // Ждём ограниченное время: бесконечное ожидание в веб-запросе — это
    // зависший воркер, а их на хостинге считанные штуки.
    $deadline = microtime(true) + max(0.0, $timeout);
    $locked = false;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) { $locked = true; break; }
        usleep(20000);   // 20 мс
    } while (microtime(true) < $deadline);
    if (!$locked) {
        fclose($fh);
        throw new RuntimeException('store: ' . basename($file) . ' занят другим запросом дольше '
            . $timeout . ' с — состояние НЕ изменено');
    }

    $held[$file] = true;
    try {
        // Проверка идёт под тем же EX-lock, что CAS-запись. Отдельный stat до
        // storeUpdate оставлял бы TOCTOU: сосед мог опубликовать огромный файл
        // после проверки, но до file_get_contents/json_decode.
        $data = storeRead($file, $maxReadBytes) ?? [];
        $new  = $mutator($data);
        if (!is_array($new)) return $data;   // мутатор решил, что писать нечего
        if ($validator !== null) {
            $new = $validator($new);
            if (!is_array($new)) {
                throw new LogicException('store: validator обязан вернуть массив состояния');
            }
        }
        storeWriteRaw($file, $new);
        // Обрыв ПОСЛЕ публикации и перечитывания, но ДО возврата вызывающему:
        // на диске уже новое состояние, а наверх уходит ошибка. Ровно этот
        // случай обязан заканчиваться «неизвестно», а не повтором работы.
        failpoint('store.after_reread');
        return $new;
    } finally {
        unset($held[$file]);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Полностью заменить состояние (под замком).
 * Годится, только когда новое значение не зависит от старого. Меняешь часть —
 * бери storeUpdate, иначе затрёшь чужую параллельную правку.
 * Caller ceiling нужен cross-file владельцу: полная замена тоже сначала читает
 * прежнее состояние под lock и не вправе decode legacy без предметного cap.
 */
function storeWrite(string $file, array $data, int $maxReadBytes = 0): void {
    storeUpdate($file, static fn(array $prev): array => $data,
        STORE_LOCK_TIMEOUT, $maxReadBytes);
}

/**
 * Прочитать состояние. Только чтение — ничего не пишет и не удаляет.
 *
 * Файл есть, но не разбирается — это НЕ «пусто». Считать порчу пустотой
 * нельзя: приложение решит, что не установлено, и первым же запросом затрёт
 * остатки токенов. Поэтому копию откладываем и останавливаемся с ошибкой.
 */
function storeRead(string $file, int $maxBytes = 0): ?array {
    if (is_file($file)) {
        $sharedState = defined('STATE_FILE') && defined('STATE_MAX_BYTES') && $file === STATE_FILE;
        if ($sharedState && ($maxBytes <= 0 || $maxBytes > STATE_MAX_BYTES)) {
            $maxBytes = STATE_MAX_BYTES;
        }
        $stat = @lstat($file);
        if (!is_array($stat) || (((int)($stat['mode'] ?? 0) & 0170000) !== 0100000)) {
            throw new RuntimeException('store: ' . basename($file) . ' должен быть обычным файлом');
        }
        if ($maxBytes > 0 && (int)($stat['size'] ?? ($maxBytes + 1)) > $maxBytes) {
            if ($sharedState && $maxBytes === STATE_MAX_BYTES) {
                throw new StateFileLimitException('state_file_too_large:read', 1701);
            }
            throw new StoreReadLimitException($file, $maxBytes);
        }
        // РАЗЛИЧИЕ «ПУСТО» И «НЕ СМОГЛИ ПРОЧИТАТЬ» ТЕРЯЛОСЬ ПРИВЕДЕНИЕМ К СТРОКЕ.
        // При отказе в доступе file_get_contents() возвращает false, а `(string)`
        // превращал его в пустую строку — неотличимую от пустого файла. Дальше
        // разбор давал null, и целый файл объявлялся повреждённым (живой случай
        // 05.09.2026: сменился владелец файла, человек увидел «Данные приложения
        // повреждены» и пошёл искать порчу вместо прав).
        $syroe = $maxBytes > 0
            ? @file_get_contents($file, false, null, 0, $maxBytes + 1)
            : @file_get_contents($file);
        if ($syroe === false) throw new StoreUnreadableException($file);
        $raw = (string)$syroe;
        if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
            // Не quarantine: файл может быть полностью валиден для старой
            // версии, он лишь слишком велик для ограниченного consumer.
            if ($sharedState && $maxBytes === STATE_MAX_BYTES) {
                throw new StateFileLimitException('state_file_too_large:read', 1701);
            }
            throw new StoreReadLimitException($file, $maxBytes);
        }
        $data = storeDecode($raw);
        // Сам факт существования файла означает, что в нём обязано быть
        // полноценное состояние. Пустой файл и одна заглушка — не «состояния
        // ещё нет», а след оборванной/чужой записи; считать их [] означало бы
        // затереть единственную улику следующим storeUpdate().
        if ($data === null) {
            throw new StoreCorruptedException($file, storeQuarantine($file, $raw));
        }
        return $data;
    }
    return null;
}

/**
 * ОДНА РАБОТА ПО ОДНОМУ КЛЮЧУ ЗА РАЗ.
 *
 * ЗАЧЕМ. `storeUpdate()` закрывает только запись файла: пока мутатор считает,
 * никто в файл не влезет. Но настоящая работа обычно длиннее файла — «выгрузить
 * сделки клиента 42 и записать итог», «разобрать очередь», «провести импорт».
 * Два одновременных запуска такой работы не спорят за файл, они делают её
 * дважды: две выгрузки, два письма, две записи в портал. От того, что каждая
 * запись прошла под замком, дублей не становится меньше.
 *
 * Замер 06.08.2026: свой файловый замок вокруг работы написан в 22 проектах из
 * 27 — под разными именами (`bp-lock.php`, `upload-lease.php`, `acl_store.php`),
 * с разной логикой ожидания и почти везде без снятия замка при исключении.
 *
 * ЧТО ДЕЛАЕТ. Пускает внутрь `$work` только один процесс на ключ. Остальные ждут
 * до `$timeout` и, не дождавшись, получают ошибку — вместо второго прохода.
 * Замок снимается в `finally`: исключение внутри работы не оставляет ключ
 * занятым до перезапуска.
 *
 * ЧТО ВОЗВРАЩАЕТ. То же, что вернула `$work`.
 * ПРИ ОТКАЗЕ. `RuntimeException` — работа НЕ выполнялась ни разу.
 *
 * ГДЕ ГРАНИЦА. Это замок в пределах одной машины (файловый `flock`). Приложение
 * живёт одной папкой на одном хостинге, поэтому этого достаточно; на двух
 * серверах разом он не работает — там нужен замок в общем хранилище.
 *
 * ЗАМОК — НЕ ЗАЩИТА ОТ ПОВТОРА ВО ВРЕМЕНИ. Он разводит одновременное. Если
 * повтор может прийти через час (портал прислал событие дважды), нужен журнал
 * ключей — `runOnce()` в `api/access.php`.
 *
 * @param string   $key     Бизнес-ключ: что именно нельзя делать дважды разом
 *                          («import», «sync-deal-42»). Не путь к файлу.
 * @param callable $work    function(): mixed — сама работа.
 * @param float    $timeout Сколько ждать чужую работу, секунды.
 */
function withKeyLock(string $key, callable $work, float $timeout = STORE_LOCK_TIMEOUT) {
    // Какие ключи держит ЭТОТ процесс. Повторный flock на новом дескрипторе
    // своего же замка не узнаёт — вложенный вызов встал бы намертво до таймаута
    // и вернул «занято» там, где занято им самим.
    static $held = [];

    $key = trim($key);
    if ($key === '') {
        throw new InvalidArgumentException('withKeyLock: пустой ключ — замок не на чем ставить.');
    }
    if (!empty($held[$key])) {
        throw new RuntimeException('withKeyLock: ключ «' . $key . '» уже занят этим же запросом. '
            . 'Вложенный замок того же ключа — ошибка в коде: работа уже идёт под ним.');
    }

    storeEnsureDir(DATA_ROOT);
    // Имя человекочитаемое (чтобы в data/ было видно, что за замок), но
    // однозначность даёт хеш: два разных ключа не должны сойтись в один файл
    // после вычистки нелатинских символов.
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $key);
    $slug = trim((string)$slug, '-');
    $lockFile = DATA_ROOT . '/lock-' . ($slug === '' ? 'key' : substr($slug, 0, 40))
        . '-' . substr(sha1($key), 0, 8) . '.php';

    $fh = @fopen($lockFile, 'c+b');
    if ($fh === false) {
        throw new RuntimeException('withKeyLock: не открыть файл замка для ключа «' . $key . '».');
    }
    if (!storeApplyPrivatePermissions($lockFile)) {
        fclose($fh);
        throw new RuntimeException('withKeyLock: не установить private-права 0600 для замка «' . $key . '».');
    }
    $stat = @fstat($fh);
    if ((int)($stat['size'] ?? 0) === 0) {
        // Файл замка лежит в data/ и обязан быть закрыт от прямого запроса, как
        // любой другой файл там: пустой .php без префикса отдал бы 200.
        if (!storeWriteAll($fh, BXAPP_PRIVATE_PHP_HEADER) || !storeFlushAndSync($fh)) {
            fclose($fh);
            throw new RuntimeException('withKeyLock: не записать безопасный header замка «' . $key . '».');
        }
    }

    $deadline = microtime(true) + max(0.0, $timeout);
    $locked = false;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) { $locked = true; break; }
        usleep(20000);   // 20 мс
    } while (microtime(true) < $deadline);

    if (!$locked) {
        fclose($fh);
        throw new KeyLockBusyException('withKeyLock: «' . $key . '» занят другим процессом дольше '
            . $timeout . ' с — работа НЕ выполнялась.');
    }

    $held[$key] = true;
    try {
        return $work();
    } finally {
        unset($held[$key]);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Содержимое файла без служебного php-префикса — чтобы отличить «пусто» от «битое». */
function storePayload(string $raw): string {
    $closeTag = '?' . '>';
    $pos = strpos($raw, $closeTag);
    return trim($pos === false ? $raw : substr($raw, $pos + 2));
}

/**
 * Отложить копию повреждённого файла для разбора.
 *
 * Оригинал НЕ удаляем намеренно. Убрать его — значит показать приложению
 * «состояния нет», и первая же установка запишет поверх остатки токенов: порча
 * превратится в безвозвратную потерю. Пусть лучше приложение честно падает,
 * пока человек не посмотрит, что там.
 *
 * Имя копии — по отпечатку содержимого, чтобы каждый следующий запрос не
 * плодил новый файл: одно и то же повреждение = одна копия.
 * Копия лежит в data/ под именем с `.php` и exit-префиксом — внутри состояние
 * приложения, наружу его отдавать нельзя.
 *
 * @return string Путь к отложенной копии.
 */
function storeQuarantine(string $file, string $raw): string {
    $backup = preg_replace('/\.php$/', '', $file) . '.bad-' . substr(sha1($raw), 0, 8) . '.php';
    // Пробелы/BOM перед префиксом не считаем безопасными: новый исполняемый
    // header всегда становится первым байтом, а исходное содержимое остаётся
    // после него уликой для разбора.
    $closeTag = '?' . '>';
    $hasClosedPrefix = privatePhpHasExecutablePrefix($raw) && strpos($raw, $closeTag) !== false;
    $body = BXAPP_PRIVATE_PHP_HEADER . ($hasClosedPrefix ? storePayload($raw) : $raw);

    // Имя детерминировано, поэтому файл мог остаться от прежней оборванной
    // попытки или оказаться ссылкой. Подтверждаем копию только когда это именно
    // тот regular-файл и его содержимое совпадает с ожидаемым побайтно.
    if (storeQuarantineCopyMatches($backup, $body)) return $backup;

    $fh = @fopen($backup, 'x+b');
    if ($fh !== false) {
        $private = storeApplyPrivatePermissions($backup);
        $written = $private && storeWriteAll($fh, $body);
        $synced = $written && storeFlushAndSync($fh);
        if (!$synced) {
            fclose($fh);
            @unlink($backup);
        } else {
            fclose($fh);
            return storeQuarantineCopyMatches($backup, $body) ? $backup : '';
        }
    }
    return '';
}

/** Существующий quarantine-файл — не ссылка/обрывок и точно содержит ожидаемую копию. */
function storeQuarantineCopyMatches(string $backup, string $body): bool {
    if (is_link($backup)) return false;
    $before = @lstat($backup);
    if (!is_array($before) || (((int)($before['mode'] ?? 0) & 0170000) !== 0100000)) return false;

    $fh = @fopen($backup, 'rb');
    if ($fh === false) return false;
    $opened = fstat($fh);
    $actual = is_array($opened) && (int)($opened['size'] ?? -1) === strlen($body)
        ? stream_get_contents($fh)
        : false;
    fclose($fh);

    $after = @lstat($backup);
    if (!is_array($opened) || !is_array($after) || is_link($backup)) return false;
    foreach (['dev', 'ino'] as $identity) {
        if (($before[$identity] ?? null) !== ($opened[$identity] ?? null)
            || ($before[$identity] ?? null) !== ($after[$identity] ?? null)) return false;
    }
    return is_string($actual) && $actual === $body;
}

/**
 * Host из HTTP_REFERER принимать как domain Б24-портала?
 * «not-self»: любой host, отличный от APP_URL host'а. Покрывает cloud
 * (*.bitrix24.*) и on-prem-коробки, но отвергает self-REFERER при
 * installFinish-reload.
 */
function isExternalB24Host(string $host): bool {
    if ($host === '') return false;
    $appUrl = defined('APP_URL') ? APP_URL : '';
    if ($appUrl === '') return true;
    $ownHost = parse_url($appUrl, PHP_URL_HOST);
    if (!is_string($ownHost) || $ownHost === '') return true;
    return strcasecmp($host, $ownHost) !== 0;
}
