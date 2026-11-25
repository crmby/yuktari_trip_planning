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
 * УВЕДОМЛЕНИЯ ЛЮДЯМ В ПОРТАЛЕ — через очередь, а не прямо из запроса.
 *
 * ЗАЧЕМ. Приложению регулярно нужно сказать человеку: «обмен упал», «задача
 * готова», «проверьте документ». Написанное в лоб — `im.notify.system.add`
 * прямо в обработчике — ломается тремя способами, и все три видны не сразу:
 *
 *   1. Портал молчит полминуты — вместе с ним висит запрос человека, который
 *      вообще не про уведомление. Экран «думает», человек жмёт ещё раз.
 *   2. Портал не ответил — уведомление потеряно совсем. Никто не знает, что
 *      сообщение не дошло: ни отправитель, ни получатель.
 *   3. Обработка сотни сделок шлёт сотню уведомлений подряд, упирается в лимит
 *      портала, и половина не доходит — а приложение считает, что доставило.
 *
 * ЧТО ДЕЛАЕТ. `notifyQueue()` кладёт уведомление в очередь и сразу возвращает
 * управление — запрос человека не ждёт портал. `notifyDrain()` (крон) отправляет
 * накопленное пачками, повторяет временные отказы с нарастающей паузой, а
 * безнадёжные — отмечает и убирает, чтобы очередь не крутилась вечно.
 *
 * ПОЧЕМУ ДУБЛЕЙ НЕ БУДЕТ. У каждого уведомления есть метка (`tag`). Портал
 * считает метку уникальной в рамках приложения: повторная отправка с той же
 * меткой ЗАМЕНЯЕТ прежнее уведомление, а не добавляет второе. Поэтому повтор
 * после неизвестного исхода безопасен — человек не получит одно и то же дважды.
 *
 * НУЖЕН SCOPE `im`. Без него портал ответит отказом на каждое уведомление —
 * очередь это увидит и напишет в журнал, но сообщения не дойдут.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/access.php';

// Сколько уведомлений отправляем за один проход крона. Портал принимает пакет
// из 50 команд; больше за раз не отправляем сознательно — иначе один всплеск
// (обработали тысячу сделок) выжигает лимит портала на всё остальное.
if (!defined('NOTIFY_BATCH')) define('NOTIFY_BATCH', 50);
// Сколько раз пробуем, прежде чем признать доставку несостоявшейся.
if (!defined('NOTIFY_MAX_TRIES')) define('NOTIFY_MAX_TRIES', 5);
// Сколько дней храним несостоявшиеся — чтобы человек мог понять, что случилось.
if (!defined('NOTIFY_KEEP_FAILED_DAYS')) define('NOTIFY_KEEP_FAILED_DAYS', 7);
// Очередь читается и сериализуется целиком, поэтому одного TTL недостаточно:
// одно сообщение из внешнего поля CRM раньше могло раздуть state до memory_limit.
// Пределы считаются в ТОЧНОЙ store-сериализации, вместе с private PHP header.
if (!defined('NOTIFY_MAX_ITEMS')) define('NOTIFY_MAX_ITEMS', 1000);
if (!defined('NOTIFY_ITEM_MAX_BYTES')) define('NOTIFY_ITEM_MAX_BYTES', 64 * 1024);
if (!defined('NOTIFY_MAX_BYTES')) define('NOTIFY_MAX_BYTES', 4 * 1048576);
// Terminal history нужна для разбора, но не имеет права бесконечно вытеснять
// живую очередь. Старейшие записи после TTL/MAX/bytes удаляются первыми.
if (!defined('NOTIFY_FAILED_MAX_ITEMS')) define('NOTIFY_FAILED_MAX_ITEMS', 200);
if (!defined('NOTIFY_FAILED_ITEM_MAX_BYTES')) define('NOTIFY_FAILED_ITEM_MAX_BYTES', 16 * 1024);
if (!defined('NOTIFY_FAILED_REASON_MAX_BYTES')) define('NOTIFY_FAILED_REASON_MAX_BYTES', 8 * 1024);
if (!defined('NOTIFY_FAILED_MAX_BYTES')) define('NOTIFY_FAILED_MAX_BYTES', 1048576);

class NotifyQueueLimitException extends RuntimeException {
    /** @var string item_bytes|count|total_bytes|failed_count|failed_bytes|legacy_bytes */
    public string $reason;

    public function __construct(string $reason, string $message) {
        $this->reason = $reason;
        parent::__construct($message);
    }
}

/** Уже разросшийся legacy-файл нельзя даже декодировать: именно decode может дать OOM. */
final class NotifyStateTooLargeException extends NotifyQueueLimitException {}

/**
 * Отказы, при которых повторять бессмысленно: получателя нет, прав нет, метод
 * недоступен. Их повтор — это вечная очередь и мусор в журнале вместо ответа.
 */
const NOTIFY_HOPELESS = [
    'ERROR_USER_NOT_FOUND', 'USER_NOT_FOUND', 'INVALID_ARG_VALUE',
    'ACCESS_DENIED', 'INSUFFICIENT_SCOPE', 'METHOD_NOT_FOUND', 'ERROR_METHOD_NOT_FOUND',
];

/** Байт в точной сериализации store, включая исполняемый private header. */
function notifyStoredBytes(array $state): int {
    try {
        return strlen(storeEncode($state));
    } catch (Throwable $e) {
        return PHP_INT_MAX;
    }
}

/** Байт одной pending-записи в том же формате, в каком её пишет store. */
function notifyItemStoredBytes(array $item): int {
    return notifyStoredBytes([$item]);
}

/** Не читать целиком legacy outbox, который уже больше штатного потолка. */
function notifyAssertFileWithinByteLimit(): void {
    if (!is_file(NOTIFY_OUTBOX_FILE)) return;
    clearstatcache(true, NOTIFY_OUTBOX_FILE);
    $bytes = @filesize(NOTIFY_OUTBOX_FILE);
    if (!is_int($bytes) || $bytes > NOTIFY_MAX_BYTES) {
        throw new NotifyStateTooLargeException('legacy_bytes',
            'notify: файл очереди больше предела ' . NOTIFY_MAX_BYTES
            . ' байт; автоматическая загрузка остановлена, нужен ручной разбор state');
    }
}

/**
 * Проверка candidate вызывается внутри storeUpdate: reject не меняет ни байта.
 * Drain может только уменьшать legacy count-overflow; item/bytes он не обходит.
 */
function notifyAssertStateWithinLimits(array $state, bool $allowLegacyCountOverflow = false): void {
    $items = $state['items'] ?? [];
    if (!is_array($items)) {
        throw new NotifyQueueLimitException('count', 'notify: items повреждён и не является массивом');
    }
    if (!$allowLegacyCountOverflow && count($items) > NOTIFY_MAX_ITEMS) {
        throw new NotifyQueueLimitException('count',
            'notify: сообщений больше предела ' . NOTIFY_MAX_ITEMS);
    }
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new NotifyQueueLimitException('item_bytes',
                'notify: повреждённая запись #' . (string)$index . ' не является массивом');
        }
        if (notifyItemStoredBytes($item) > NOTIFY_ITEM_MAX_BYTES) {
            throw new NotifyQueueLimitException('item_bytes',
                'notify: сообщение #' . (string)$index . ' больше предела '
                . NOTIFY_ITEM_MAX_BYTES . ' байт');
        }
    }

    $failed = $state['failed'] ?? [];
    if (!is_array($failed)) {
        throw new NotifyQueueLimitException('failed_count',
            'notify: failed повреждён и не является массивом');
    }
    if (count($failed) > NOTIFY_FAILED_MAX_ITEMS) {
        throw new NotifyQueueLimitException('failed_count',
            'notify: недоставленных больше предела ' . NOTIFY_FAILED_MAX_ITEMS);
    }
    if (notifyStoredBytes(['failed' => $failed]) > NOTIFY_FAILED_MAX_BYTES) {
        throw new NotifyQueueLimitException('failed_bytes',
            'notify: история недоставленных больше предела ' . NOTIFY_FAILED_MAX_BYTES . ' байт');
    }
    if (notifyStoredBytes($state) > NOTIFY_MAX_BYTES) {
        throw new NotifyQueueLimitException('total_bytes',
            'notify: очередь больше предела ' . NOTIFY_MAX_BYTES . ' байт');
    }
}

/** Обрезать UTF-8 по байтам, не оставив битую последовательность для json_encode. */
function notifyUtf8Prefix(string $value, int $maxBytes): string {
    if (strlen($value) <= $maxBytes) return $value;
    $suffix = '…';
    $room = max(0, $maxBytes - strlen($suffix));
    if (function_exists('mb_strcut')) {
        $cut = mb_strcut($value, 0, $room, 'UTF-8');
        return (is_string($cut) ? $cut : '') . $suffix;
    }
    $cut = substr($value, 0, $room);
    while ($cut !== '' && @preg_match('//u', $cut) !== 1) $cut = substr($cut, 0, -1);
    return $cut . $suffix;
}

/**
 * TTL/count/bytes для terminal failed. Потеря старейшей terminal-диагностики
 * допустима и явна; pending здесь не удаляется никогда.
 */
function notifyBoundFailed(array $failed, int $keepAfter): array {
    $valid = [];
    foreach ($failed as $row) {
        if (!is_array($row) || (int)($row['at'] ?? 0) < $keepAfter) continue;
        $row['reason'] = notifyUtf8Prefix((string)($row['reason'] ?? ''), NOTIFY_FAILED_REASON_MAX_BYTES);
        if (notifyStoredBytes([$row]) > NOTIFY_FAILED_ITEM_MAX_BYTES) {
            // Остальные поля — короткие метаданные нашего кода. Если legacy
            // всё же принёс крупное поле, сохраняем доказуемый минимум.
            $row = [
                'tag' => notifyUtf8Prefix((string)($row['tag'] ?? ''), 256),
                'user_id' => (int)($row['user_id'] ?? 0),
                'reason' => $row['reason'],
                'at' => (int)($row['at'] ?? 0),
            ];
        }
        $valid[] = $row;
    }

    // Берём новые с конца и добавляем, пока одновременно соблюдены count и bytes.
    $bounded = [];
    for ($i = count($valid) - 1; $i >= 0; $i--) {
        if (count($bounded) >= NOTIFY_FAILED_MAX_ITEMS) break;
        $candidate = $bounded;
        array_unshift($candidate, $valid[$i]);
        if (notifyStoredBytes(['failed' => $candidate]) > NOTIFY_FAILED_MAX_BYTES) continue;
        $bounded = $candidate;
    }
    return $bounded;
}

/**
 * Метка уведомления. Одинаковая метка = замена прежнего, а не второе сообщение.
 *
 * Портал принимает в метке только латиницу и цифры, поэтому остальное вырезаем.
 * Но вырезать МОЛЧА нельзя: ключи «сделка-42» и «задача-42» после чистки давали
 * один и тот же «app-7--42», и второе уведомление ЗАМЕНЯЛО первое — человек
 * узнавал об одном событии из двух, без ошибки и без следа в журнале (находка
 * ревью 09.08.2026). Поэтому если чистка что-то изменила, дописываем к метке
 * отпечаток исходного ключа: разные ключи остаются разными, а одинаковые
 * по-прежнему совпадают.
 */
function notifyIdentitySource(string $text, string $key = ''): string {
    return $key !== '' ? "key\0" . $key : "text\0" . $text;
}

/** Полная logical identity хранится в row и страхует transport-tag. */
function notifyIdentity(string $text, string $key = ''): string {
    return hash('sha256', notifyIdentitySource($text, $key));
}

function notifyTag(int $userId, string $text, string $key = ''): string {
    $prefixSource = $key !== '' ? $key : 'text';
    $prefix = trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '', $prefixSource), '-_');
    if ($prefix === '') $prefix = $key !== '' ? 'key' : 'text';
    return 'app-' . $userId . '-' . substr($prefix, 0, 40)
        . '-' . notifyIdentity($text, $key);
}

/** Точный transport-tag версии до full-identity — только для safe migration. */
function notifyLegacyTag(int $userId, string $text, string $key = ''): string {
    $base = $key !== '' ? $key : substr(sha1($text), 0, 12);
    $clean = (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $base);
    if ($clean !== $base) $clean .= '-' . substr(sha1($base), 0, 8);
    return 'app-' . $userId . '-' . $clean;
}

/**
 * Поставить уведомление в очередь. Возвращает метку — по ней потом видно судьбу
 * сообщения в журнале.
 *
 * @param int    $userId получатель (идентификатор пользователя портала)
 * @param string $text   текст; поддерживаются BB-коды портала
 * @param array  $opts   ['key' => 'своя метка', 'out' => 'текст для почты']
 */
function notifyQueue(int $userId, string $text, array $opts = []): string {
    $text = trim($text);
    if ($userId <= 0) throw new InvalidArgumentException('уведомление без получателя');
    if ($text === '') throw new InvalidArgumentException('уведомление без текста');

    $key = (string)($opts['key'] ?? '');
    $identity = notifyIdentity($text, $key);
    $tag = notifyTag($userId, $text, $key);
    $legacyTag = notifyLegacyTag($userId, $text, $key);
    $legacyKeyExact = $key !== '' && preg_match('/^[a-zA-Z0-9_-]+$/D', $key) === 1;
    notifyAssertFileWithinByteLimit();
    storeUpdate(NOTIFY_OUTBOX_FILE, static function (array $q) use (
        $userId, $text, $key, $tag, $legacyTag, $identity, $legacyKeyExact, $opts
    ): array {
        $keepAfter = time() - NOTIFY_KEEP_FAILED_DAYS * 86400;
        $q['failed'] = notifyBoundFailed((array)($q['failed'] ?? []), $keepAfter);
        $items = $q['items'] ?? [];
        // Та же метка уже в очереди — обновляем текст, а не кладём второе
        // сообщение: человеку важно последнее состояние, а не его история.
        $out = (string)($opts['out'] ?? '');
        foreach ($items as $i => $row) {
            $rowIdentity = (string)($row['identity'] ?? '');
            $legacyDefaultExact = $key === ''
                && (int)($row['user_id'] ?? 0) === $userId
                && (string)($row['text'] ?? '') === $text;
            if ($rowIdentity === '' && (string)($row['tag'] ?? '') === $legacyTag
                && ($legacyKeyExact || $legacyDefaultExact)) {
                // Для default исходный text лежит в row и доказывает identity.
                // Для ASCII key сам legacy tag содержит ключ целиком. Очищенный
                // legacy key по короткому hash доказать нельзя — его не сливаем.
                $rowIdentity = $identity;
                $items[$i]['tag'] = $tag;
                $items[$i]['identity'] = $identity;
            }
            $sameIdentity = $rowIdentity !== '' && hash_equals($identity, $rowIdentity);
            if ((string)($items[$i]['tag'] ?? '') === $tag && $sameIdentity) {
                // Изменилось ли содержимое на самом деле. Повтор того же текста —
                // не уточнение: обнулять по нему паузу нельзя, иначе каждый такт
                // крона будет долбить портал одним и тем же сообщением, ради чего
                // пауза и заведена.
                $changed = (string)($row['text'] ?? '') !== $text
                    || (string)($row['out'] ?? '') !== $out;
                $items[$i]['text'] = $text;
                $items[$i]['identity'] = $identity;
                // Текст для внешних каналов — часть содержимого. Раньше он тут не
                // обновлялся вовсе: человек правил сообщение, а на почту уходила
                // прежняя редакция, и заметить это было нечем.
                $items[$i]['out'] = $out;
                $items[$i]['queued_at'] = time();
                if ($changed) {
                    // Версия содержимого. Растёт при каждом уточнении и нужна не для
                    // истории: пока идёт отправка, замок уже снят, и человек успевает
                    // уточнить текст. Без версии завершение СТАРОЙ отправки вычеркнет
                    // строку по метке — вместе с уточнением, которого портал не видел.
                    $items[$i]['rev'] = (int)($row['rev'] ?? 0) + 1;
                    // Новый текст — новая работа. Пауза и сожжённые попытки достались
                    // от ПРЕЖНЕГО содержимого: отказ относился к нему, а не к этому.
                    // С ними исправление ждало бы назначенный старому час вместо
                    // ближайшего такта, а до «безнадёжно» ему оставалась бы одна
                    // попытка из пяти — то есть текст, который человек только что
                    // починил, списывался бы быстрее нового.
                    $items[$i]['tries'] = 0;
                    $items[$i]['next_try_at'] = 0;
                }
                $q['items'] = $items;
                notifyAssertStateWithinLimits($q);
                return $q;
            }
        }
        $items[] = [
            'tag' => $tag,
            'user_id' => $userId,
            'identity' => $identity,
            'text' => $text,
            'out' => (string)($opts['out'] ?? ''),
            'queued_at' => time(),
            'rev' => 1,
            'tries' => 0,
            'next_try_at' => 0,
        ];
        $q['items'] = $items;
        notifyAssertStateWithinLimits($q);
        return $q;
    });
    return $tag;
}

/**
 * Отправить накопленное. Зовётся из крона; из запроса человека звать не нужно —
 * ради этого очередь и заведена.
 *
 * @return array ['sent' => N, 'failed' => N, 'left' => N]
 */
function notifyDrain($b24, int $limit = NOTIFY_BATCH): array {
    $limit = max(1, min($limit, NOTIFY_BATCH));
    $now = time();
    notifyAssertFileWithinByteLimit();

    // Порцию забираем под замком: два крона (ручной запуск поверх обычного
    // такта) иначе возьмут одни и те же строки и отправят их дважды.
    $portion = withKeyLock('notify-drain', static function () use ($limit, $now): array {
        $take = [];
        storeUpdate(NOTIFY_OUTBOX_FILE, static function (array $q) use ($limit, $now, &$take): array {
            $keepAfter = $now - NOTIFY_KEEP_FAILED_DAYS * 86400;
            $q['failed'] = notifyBoundFailed((array)($q['failed'] ?? []), $keepAfter);
            $items = $q['items'] ?? [];
            foreach ($items as $i => $row) {
                if (count($take) >= $limit) break;
                if ((int)($row['next_try_at'] ?? 0) > $now) continue;   // ждёт своей паузы
                // Номер попытки считаем ОДИН раз и кладём в порцию тот же, что
                // записали в состояние. Прежде в порцию уезжало СТАРОЕ значение,
                // а состояние получало новое — и решение «попытки кончились»
                // принималось по числу на единицу меньше: при пределе в пять
                // портал звали шесть раз (находка ревью 08.08.2026, BXREV-024).
                $popytka = (int)($row['tries'] ?? 0) + 1;
                $vzyato = $row;
                $vzyato['tries'] = $popytka;
                $vzyato['_i'] = $i;
                $take[] = $vzyato;
                // Отметка попытки ставится СРАЗУ, до отправки: если процесс
                // умрёт на середине, счётчик уже увеличен и вечного цикла
                // «шлём — падаем — шлём заново» не будет.
                $items[$i]['tries'] = $popytka;
                $items[$i]['next_try_at'] = $now + notifyBackoff($popytka);
            }
            $q['items'] = $items;
            // До сетевого вызова доказываем, что отметки попыток не вытолкнули
            // state за bytes/item cap. Count-overflow допускается только как
            // legacy recovery: drain не добавляет ни одной pending-записи.
            notifyAssertStateWithinLimits($q, true);
            return $q;
        });
        return $take;
    });

    if (!$portion) {
        $left = count((storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? []));
        return ['sent' => 0, 'failed' => 0, 'left' => $left];
    }

    $cmd = [];
    foreach ($portion as $row) {
        $params = [
            'USER_ID' => (int)$row['user_id'],
            'MESSAGE' => (string)$row['text'],
            'TAG'     => (string)$row['tag'],
        ];
        if (($row['out'] ?? '') !== '') $params['MESSAGE_OUT'] = (string)$row['out'];
        $cmd[$row['tag']] = ['im.notify.system.add', $params];
    }
    // Повтор безопасен по контракту портала: у каждого уведомления есть метка,
    // и повторная отправка с той же меткой ЗАМЕНЯЕТ прежнее сообщение. Поэтому
    // «неизвестный исход» здесь не требует разбирательства человеком — в
    // отличие от счёта или созданной сделки.
    $res = $b24->batchAllIdempotent($cmd,
        'у каждого уведомления своя метка: портал заменяет прежнее сообщение, второго человек не получит');

    // Метка → ВЕРСИЯ текста, которую портал реально видел. Судьба строки решается
    // по паре «метка + версия»: за время отправки содержимое могло уехать вперёд.
    $sent = [];
    $hopeless = [];
    foreach ($portion as $row) {
        $tag = (string)$row['tag'];
        $rev = (int)($row['rev'] ?? 0);
        if (!isset($res['errors'][$tag])) { $sent[$tag] = $rev; continue; }
        $err = strtoupper((string)$res['errors'][$tag]);
        $isHopeless = false;
        // strpos, а не str_contains: заявленный минимум движка — PHP 7.4, где
        // str_contains ещё нет. Фатал случился бы ровно здесь — на разборе
        // ошибки, то есть когда и так что-то пошло не так.
        foreach (NOTIFY_HOPELESS as $code) if (strpos($err, $code) !== false) $isHopeless = true;
        // Попытки кончились — тоже безнадёжно: дальше это уже не «портал занят»,
        // а что-то, чего повтор не лечит.
        if ((int)$row['tries'] >= NOTIFY_MAX_TRIES) $isHopeless = true;
        if ($isHopeless) $hopeless[$tag] = ['reason' => (string)$res['errors'][$tag], 'rev' => $rev];
    }

    storeUpdate(NOTIFY_OUTBOX_FILE, static function (array $q) use ($sent, $hopeless, $now): array {
        $items = [];
        $failed = $q['failed'] ?? [];
        foreach (($q['items'] ?? []) as $row) {
            $tag = (string)($row['tag'] ?? '');
            $rev = (int)($row['rev'] ?? 0);
            // Уточнение, пришедшее ПОКА ШЛА отправка, старую судьбу не наследует:
            // портал видел прежний текст, значит строка остаётся в очереди, а
            // попытки считаем заново — отказ относился к старому содержимому.
            $stale = ($rev !== 0) && (
                (isset($sent[$tag]) && $rev !== $sent[$tag])
                || (isset($hopeless[$tag]) && $rev !== $hopeless[$tag]['rev'])
            );
            if ($stale) {
                $row['tries'] = 0;
                $row['next_try_at'] = 0;
                $items[] = $row;
                continue;
            }
            if (isset($sent[$tag])) continue;                       // доставлено — из очереди убираем
            if (isset($hopeless[$tag])) {
                // Несостоявшиеся не выбрасываем молча: человек должен иметь
                // возможность узнать, почему сообщение не дошло.
                $failed[] = ['tag' => $tag, 'user_id' => $row['user_id'] ?? 0,
                    'reason' => $hopeless[$tag]['reason'], 'at' => $now];
                continue;
            }
            $items[] = $row;
        }
        // Старые записи о неудачах убираем по сроку, иначе файл растёт вечно.
        $keepAfter = $now - NOTIFY_KEEP_FAILED_DAYS * 86400;
        $q['failed'] = notifyBoundFailed($failed, $keepAfter);
        $q['items'] = $items;
        notifyAssertStateWithinLimits($q, true);
        return $q;
    });

    {
        // След действия, а не просто строка в журнале: приложение отправило
        // людям сообщения от своего имени, и через месяц вопрос «кто это
        // прислал» должен иметь ответ. Пишем ПОСЛЕ отправки — то есть факт, а
        // не намерение.
        auditLog('notify.drain', [
            'доставлено' => count($sent),
            'не доставлено' => array_map(static fn(array $h): string => $h['reason'], $hopeless),
        ]);
    }
    $left = count((storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? []));
    return ['sent' => count($sent), 'failed' => count($hopeless), 'left' => $left];
}

/**
 * Пауза перед следующей попыткой: 1 минута, 5, 15, 30, час. Растущая — потому
 * что частая причина отказа (портал перегружен, лимит) сама проходит, а частый
 * повтор её только усугубляет.
 */
function notifyBackoff(int $tries): int {
    $ladder = [60, 300, 900, 1800, 3600];
    $i = max(0, min($tries - 1, count($ladder) - 1));
    return $ladder[$i];
}

/** Что не доставлено — для экрана «Состояние». */
function notifyFailed(): array {
    notifyAssertFileWithinByteLimit();
    return (array)(storeRead(NOTIFY_OUTBOX_FILE)['failed'] ?? []);
}

/** Сколько ждёт отправки — тоже для экрана «Состояние». */
function notifyPending(): int {
    notifyAssertFileWithinByteLimit();
    return count((array)(storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? []));
}
