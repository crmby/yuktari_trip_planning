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
 * ОЧЕРЕДЬ СОБЫТИЙ ПОРТАЛА — снять порцию, подтвердить, вернуть на повтор.
 *
 * Зачем отдельным модулем. Раньше разбор жил внутри cron-скрипта и снимал порцию
 * так: вырезал события из очереди и работал с ними в памяти. Убитый процесс
 * (kill, таймаут хостинга, фатальная ошибка) уносил всю порцию: в очереди их уже
 * нет, обработаны не были, а портал подтверждённое не повторяет. Проверить это
 * тоже было нечем — код внутри исполняемого скрипта не подключить из проверок.
 *
 * Здесь порция не вырезается, а БЕРЁТСЯ В АРЕНДУ: событию ставится метка времени,
 * и оно исчезает из очереди только после подтверждения. Аренда протухает сама —
 * упавший разбор возвращает события в работу без ручного вмешательства.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';

/** Сколько ждём результата по взятому событию, прежде чем отдать его снова. */
if (!defined('QUEUE_LEASE_SEC')) define('QUEUE_LEASE_SEC', 600);
/** После скольких неудач событие уходит в ящик неразобранных, а не в мусор. */
if (!defined('QUEUE_MAX_TRIES')) define('QUEUE_MAX_TRIES', 3);
/** Пауза перед следующей попыткой: 60, 120, 240 секунд… */
if (!defined('QUEUE_BACKOFF_SEC')) define('QUEUE_BACKOFF_SEC', 60);
/** Сколько событий держим в ящике неразобранных. При заполнении ничего не теряем. */
if (!defined('DEADLETTER_MAX')) define('DEADLETTER_MAX', 500);
// Байтовый потолок ящика неразобранных. У inbox он есть давно и ровно по той же
// причине: 500 событий с крупной полезной нагрузкой (данные CRM приходят прямо в
// событии) — это десятки мегабайт, а файл читается целиком. Считать защитой один
// лишь счётчик записей нельзя: OOM даёт объём, а не количество.
if (!defined('DEADLETTER_MAX_BYTES')) define('DEADLETTER_MAX_BYTES', 4 * 1048576);
// Разбору нужен ДРУГОЙ, больший порог — иначе тупик: ящик перерос предел (на
// последнюю запись он всегда перерастает: проверка стоит ДО добавления) → приём
// новых отказывает → и единственный инструмент, которым ящик разгребают, тоже
// отказывает, потому что смотрит на тот же предел. Тогда события не достать
// вообще ничем, кроме правки файла на сервере. Разбор идёт из CLI, где памяти
// больше; выше и этого потолка — уже разбор руками.
if (!defined('DEADLETTER_RECOVERY_MAX_BYTES')) define('DEADLETTER_RECOVERY_MAX_BYTES', DEADLETTER_MAX_BYTES * 8);
// Runtime-пределы модуля, не настройки env-контракта: event.php подключает этот
// же файл, поэтому receiver/worker/replay физически не могут разойтись. Count
// недостаточно — 5000 крупных событий превращали inbox в гигабайт. Резерв нужен
// под eid/taken/tries/next_at, которые worker добавляет уже после приёма.
if (!defined('INBOX_MAX_BYTES')) define('INBOX_MAX_BYTES', 4 * 1048576);
if (!defined('INBOX_EVENT_MAX_BYTES')) define('INBOX_EVENT_MAX_BYTES', 64 * 1024);
if (!defined('INBOX_EVENT_META_RESERVE_BYTES')) define('INBOX_EVENT_META_RESERVE_BYTES', 256);

/**
 * Сколько помним о закрытых событиях. Неделя — с запасом: ящик неразобранных
 * разгребают руками через день-два, а не через месяц.
 */
if (!defined('QUEUE_DONE_TTL')) define('QUEUE_DONE_TTL', 7 * 86400);
/** Потолок завершённых записей; незакрытая crash-метка живёт до уборки residue. */
if (!defined('QUEUE_DONE_MAX')) define('QUEUE_DONE_MAX', 5000);

/**
 * РЕЕСТР ОКОНЧАТЕЛЬНО ЗАКРЫТЫХ СОБЫТИЙ.
 *
 * ЗАЧЕМ. «Событие исчезло из очереди» и «событие не обрабатывалось» — разные
 * вещи, а разбор ящика неразобранных судил только по текущей очереди. Гонка
 * из ревью 08.08.2026: копия события лежит и в ящике, и в очереди; первый разбор
 * видит живую аренду и оставляет запись в ящике, воркер доделывает событие и
 * убирает его из очереди, второй разбор уже не видит ничего — и кладёт то же
 * событие обратно. Бизнес-действие выполняется дважды, а портал повторов не
 * присылал.
 *
 * ГРАНИЦА. Реестр помнит `eid`, а `eid` выдаётся случайным при первом взятии в
 * работу (`queueTake`) — он не производная от содержимого. Поэтому два разных
 * события с одинаковыми данными сюда не сливаются: отсекается ровно повтор
 * одного и того же события, а не похожее новое.
 */
function queueDoneFile(): string {
    return defined('QUEUE_DONE_FILE') ? QUEUE_DONE_FILE : DATA_ROOT . '/queue-done.php';
}

/** Время валидной terminal-записи; closing — crash-safe, int — уборочная запись. */
function queueDoneRecordAt($record): ?int {
    if (is_int($record)) return $record;
    if (!is_array($record) || ($record['state'] ?? '') !== 'closing') return null;
    return is_int($record['at'] ?? null) ? $record['at'] : null;
}

function queueDoneRecordClosing($record): bool {
    return is_array($record) && ($record['state'] ?? '') === 'closing'
        && is_int($record['at'] ?? null);
}

/**
 * TTL/MAX применяются только к полностью убранным событиям. Closing удалять
 * нельзя: это единственная durable-улика между эффектом и очисткой inbox/DLQ.
 */
function queuePruneDoneRegistry(array $reestr, int $now): array {
    $settled = [];
    foreach ($reestr as $eid => $record) {
        if (queueDoneRecordClosing($record)) continue;
        if (!is_int($record) || ($now - $record) > QUEUE_DONE_TTL) {
            unset($reestr[$eid]);
            continue;
        }
        $settled[(string)$eid] = $record;
    }
    if (count($settled) > QUEUE_DONE_MAX) {
        asort($settled);
        $remove = count($settled) - QUEUE_DONE_MAX;
        foreach (array_keys($settled) as $eid) {
            if ($remove-- <= 0) break;
            unset($reestr[$eid]);
        }
    }
    return $reestr;
}

/** Отметить эффект terminal до очистки его остатков из inbox/DLQ. */
function queueMarkDone(array $eids): void {
    $eids = array_values(array_filter(array_map('strval', $eids), static fn(string $e): bool => $e !== ''));
    if ($eids === []) return;
    $now = time();
    storeUpdate(queueDoneFile(), static function (array $reestr) use ($eids, $now): array {
        foreach ($eids as $eid) $reestr[$eid] = ['at' => $now, 'state' => 'closing'];
        return queuePruneDoneRegistry($reestr, $now);
    });
}

/** После успешной очистки residue переводит crash-метку в TTL/MAX-реестр. */
function queueSettleDone(array $eids): void {
    $eids = array_values(array_filter(array_map('strval', $eids), static fn(string $e): bool => $e !== ''));
    if ($eids === []) return;
    $now = time();
    storeUpdate(queueDoneFile(), static function (array $reestr) use ($eids, $now): array {
        foreach ($eids as $eid) $reestr[$eid] = $now;
        return queuePruneDoneRegistry($reestr, $now);
    });
}

/** Закрыто ли событие окончательно; TTL — ленивая уборка, не окно дубля. */
function queueIsDone(string $eid): bool {
    if ($eid === '') return false;
    $reestr = storeRead(queueDoneFile());
    if (!is_array($reestr)) return false;
    return queueDoneRecordAt($reestr[$eid] ?? null) !== null;
}

/** Байт в точной store-сериализации очереди, включая private PHP header. */
function queueStoredBytes(array $queue): int {
    return strlen(storeEncode($queue));
}

/** Байт одной записи в том же формате, в каком она окажется внутри store. */
function queueEventStoredBytes(array $event): int {
    return strlen(storeEncode([$event]));
}

/**
 * Добавить событие, только если после записи останется место и для worker-меток.
 *
 * @param string|null $reason count|event_bytes|total_bytes при отказе.
 */
/**
 * @param bool $stateAlreadyChecked Состояние очереди уже проверено вызывающим —
 *        не переверять его на каждое добавление. Ставить true ТОЛЬКО в цикле,
 *        где очередь пришла из одного и того же замка и между итерациями меняется
 *        лишь дозаписью: проверка входного состояния там даёт один и тот же ответ,
 *        а стоит полного обхода. Разбор ящика неразобранных из-за этого был
 *        квадратичным и занимал 33 секунды из 48 всего проверочного контура.
 */
function queueAppendLimited(array $queue, array $event, ?string &$reason = null, bool $stateAlreadyChecked = false): array {
    $reason = null;
    // Не использовать приём нового события как способ «починить» повреждённый
    // legacy inbox: это перепишет уже принятое состояние и замаскирует причину.
    if (!$stateAlreadyChecked) queueAssertStateWithinByteLimits($queue);
    if (count($queue) >= INBOX_MAX) {
        $reason = 'count';
        return $queue;
    }
    if (queueEventStoredBytes($event) + INBOX_EVENT_META_RESERVE_BYTES > INBOX_EVENT_MAX_BYTES) {
        $reason = 'event_bytes';
        return $queue;
    }
    $candidate = $queue;
    $candidate[] = $event;
    $reserved = count($candidate) * INBOX_EVENT_META_RESERVE_BYTES;
    if (queueStoredBytes($candidate) + $reserved > INBOX_MAX_BYTES) {
        $reason = 'total_bytes';
        return $queue;
    }
    return $candidate;
}

/** Текущее состояние не должно стать больше объявленных byte caps. */
function queueAssertStateWithinByteLimits(array $queue): void {
    if (count($queue) > INBOX_MAX) {
        throw new RuntimeException('queue: записей больше предела ' . INBOX_MAX);
    }
    foreach ($queue as $index => $event) {
        if (!is_array($event)) {
            throw new RuntimeException('queue: повреждённая запись #' . (string)$index . ' не является массивом');
        }
        if (queueEventStoredBytes($event) > INBOX_EVENT_MAX_BYTES) {
            throw new RuntimeException('queue: событие #' . (string)$index . ' больше предела '
                . INBOX_EVENT_MAX_BYTES . ' байт');
        }
    }
    if (queueStoredBytes($queue) > INBOX_MAX_BYTES) {
        throw new RuntimeException('queue: inbox больше предела ' . INBOX_MAX_BYTES . ' байт');
    }
}

/** Не декодировать уже разросшийся legacy inbox: это само по себе может дать OOM. */
function queueAssertFileWithinByteLimit(string $file, ?int $limit = null): void {
    if (!is_file($file)) return;
    $limit = $limit ?? INBOX_MAX_BYTES;
    clearstatcache(true, $file);
    $bytes = @filesize($file);
    if (!is_int($bytes) || $bytes > $limit) {
        throw new RuntimeException('queue: файл очереди на диске больше предела ' . $limit
            . ' байт; разберите его вручную, автоматическая загрузка остановлена');
    }
}

/** Безопасная короткая метка недоверенного event только для текста журнала. */
function queueEventLogLabel($raw): string {
    if (!is_scalar($raw)) return '?';
    $label = substr((string)$raw, 0, 128);
    $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $label);
    $label = is_string($label) ? trim($label, '_') : '';
    return $label !== '' ? substr($label, 0, 64) : '?';
}

/**
 * Взять порцию событий в работу.
 *
 * @return array Список событий; у каждого есть 'eid' — им же и подтверждают.
 */
function queueTake(int $portion = 50, ?string $file = null): array {
    $file = $file ?? INBOX_FILE;
    if (!is_file($file)) return [];
    queueAssertFileWithinByteLimit($file);
    $batch = [];
    $expired = [];          // исчерпавшие попытки — переносим в ящик ПОСЛЕ мутатора
    $now = time();
    storeUpdate($file, static function (array $queue) use (&$batch, &$expired, $portion, $now): array {
        $out = [];
        foreach ($queue as $index => $event) {
            if (!is_array($event)) {
                // Невалидную запись нельзя тихо отфильтровать: storeUpdate затем
                // записал бы очередь без неё, то есть уже принятое событие
                // исчезло бы без DLQ и сигнала. Исключение отменяет всю запись —
                // inbox остаётся байт-в-байт логически прежним для разбора.
                throw new RuntimeException('queue: повреждённая запись #' . (string)$index
                    . ' не является массивом; inbox НЕ изменён');
            }
            $lease = (int)($event['taken'] ?? 0);
            if ($lease > 0 && $now - $lease < QUEUE_LEASE_SEC) { $out[] = $event; continue; }  // в работе
            // Отложенное после неудачи ждёт своего часа. Без этого повтор
            // приходился на тот же тик крона (разбор крутится в цикле, пока
            // очередь не опустеет) — три «попытки» сгорали за одну секунду, и
            // временная ошибка портала выглядела как окончательная.
            $nextAt = (int)($event['next_at'] ?? 0);
            if ($nextAt > $now) { $out[] = $event; continue; }
            if (count($batch) >= $portion) { $out[] = $event; continue; }
            // Аренда истекла — значит прошлый заход по этому событию оборвался.
            if ($lease > 0) $event['tries'] = (int)($event['tries'] ?? 0) + 1;
            if ((string)($event['eid'] ?? '') === '') $event['eid'] = bin2hex(random_bytes(6));
            // ПОТОЛОК ПОПЫТОК ПРОВЕРЯЕТСЯ ЗДЕСЬ, а не только в обработчике ошибок.
            // Второй потолок в process.php стоит внутри catch — туда попадает лишь
            // перехваченное исключение. Если процесс УМИРАЕТ (нехватка памяти на
            // крупном событии, фатал, SIGKILL по таймауту планировщика хостинга),
            // до catch дело не доходит вовсе: счётчик рос, но его никто не смотрел,
            // и одно ядовитое событие забирало порцию за порцией бесконечно,
            // не попадая в ящик неразобранных и заслоняя собой живую очередь.
            if ((int)($event['tries'] ?? 0) >= QUEUE_MAX_TRIES) {
                // В ящик переносим ПОСЛЕ выхода отсюда, а не прямо здесь.
                // Перенос умеет отказывать (ящик может быть полон), и его отказ
                // изнутри этого мутатора выходил наружу необработанным: падал не
                // перенос одного протухшего события, а весь тик фоновой
                // обработки — вместе с разбором живой очереди и долгой задачей.
                // Одно ядовитое событие плюс заполненный ящик останавливали фон
                // целиком, и снаружи это выглядело как «крон перестал работать».
                $event['taken'] = $now;         // держим арендованным, в работу не отдаём
                $expired[] = $event;
                $out[] = $event;
                continue;
            }
            $event['taken'] = $now;
            $out[] = $event;
            $batch[] = $event;
        }
        queueAssertStateWithinByteLimits($out);
        return $out;
    });

    // Перенос в ящик неразобранных — ОТДЕЛЬНЫМ шагом, вне мутатора очереди.
    // Он умеет отказывать (ящик переполнен), и его отказ не должен ронять весь
    // тик: событие просто остаётся арендованным во входящей очереди и уедет в
    // ящик, когда там освободится место. Молчать при этом нельзя — иначе
    // переполненный ящик становится невидимой причиной остановки разбора.
    foreach ($expired as $event) {
        $eid = (string)($event['eid'] ?? '');
        try {
            queueDeadLetter($event, 'обработчик прерывался до подтверждения аренды');
            if ($eid !== '') queueAck([$eid], [], $file);
        } catch (Throwable $e) {
            logAppError('очередь: событие «' . (string)($event['event'] ?? 'без имени')
                . '» исчерпало попытки, но ящик неразобранных его не принял — '
                . maskSecrets($e->getMessage()));
        }
    }
    return $batch;
}

/**
 * Подтвердить разобранные события и вернуть в очередь то, что нужно повторить.
 *
 * Одной правкой под замком: между «убрать» и «вернуть» не должен вклиниться
 * соседний запуск и увидеть очередь в половинчатом виде.
 *
 * @param string[] $doneEids События, которые больше не нужны.
 * @param array[]  $retry    События, которые кладём обратно (метку аренды снимаем).
 */
function queueAck(array $doneEids, array $retry = [], ?string $file = null): void {
    $file = $file ?? INBOX_FILE;
    if ($doneEids === [] && $retry === []) return;
    queueAssertFileWithinByteLimit($file);
    storeUpdate($file, static function (array $queue) use ($doneEids, $retry): array {
        // Убираем и подтверждённые, и возвращаемые: возвращаемое кладётся заново
        // (без метки аренды), и без этого шага оно осталось бы в очереди дважды.
        $drop = array_flip(array_map('strval', $doneEids));
        foreach ($retry as $event) {
            if (is_array($event) && (string)($event['eid'] ?? '') !== '') $drop[(string)$event['eid']] = true;
        }
        $out = [];
        foreach ($queue as $event) {
            if (is_array($event) && isset($drop[(string)($event['eid'] ?? '')])) continue;
            $out[] = $event;
        }
        foreach ($retry as $event) {
            if (!is_array($event)) continue;
            unset($event['taken']);
            $out[] = $event;
        }
        queueAssertStateWithinByteLimits($out);
        return $out;
    });
}

/** Убрать только terminal-eid из DLQ; чужие и повреждённые записи сохранить. */
function queueForgetDead(array $eids, ?string $file = null): void {
    $eids = array_values(array_filter(array_map('strval', $eids), static fn(string $e): bool => $e !== ''));
    if ($eids === []) return;
    $file = $file ?? DEADLETTER_FILE;
    if (!is_file($file)) return;
    queueAssertFileWithinByteLimit($file, DEADLETTER_RECOVERY_MAX_BYTES);
    $done = array_fill_keys($eids, true);
    storeUpdate($file, static function (array $current) use ($done): array {
        $out = [];
        foreach ($current as $rec) {
            if (!is_array($rec) || !isset($done[(string)($rec['eid'] ?? '')])) $out[] = $rec;
        }
        return $out;
    });
}

/**
 * Завершить closing, оставшийся после crash в последнем окне cleanup→settle.
 * Вызывать только под queue-finalize-replay: отсутствие eid проверяется одним
 * согласованным снимком inbox+DLQ, после чего запись снова получает TTL/MAX.
 */
function queueRecoverClosingUnlocked(?string $inbox = null): void {
    $reestr = storeRead(queueDoneFile());
    if (!is_array($reestr)) return;
    $closing = [];
    foreach ($reestr as $eid => $record) {
        if (queueDoneRecordClosing($record)) $closing[(string)$eid] = true;
    }
    if ($closing === []) return;

    $active = [];
    $sources = [
        [$inbox ?? INBOX_FILE, INBOX_MAX_BYTES],
        [DEADLETTER_FILE, DEADLETTER_RECOVERY_MAX_BYTES],
    ];
    foreach ($sources as [$file, $limit]) {
        if (!is_file($file)) continue;
        queueAssertFileWithinByteLimit($file, $limit);
        $records = storeRead($file);
        if (!is_array($records)) continue;
        foreach ($records as $rec) {
            if (!is_array($rec)) continue;
            $eid = (string)($rec['eid'] ?? '');
            if ($eid !== '') $active[$eid] = true;
        }
    }
    $recover = array_diff_key($closing, $active);
    if ($recover === []) return;

    $now = time();
    storeUpdate(queueDoneFile(), static function (array $current) use ($recover, $now): array {
        foreach ($recover as $eid => $_) {
            if (queueDoneRecordClosing($current[$eid] ?? null)) $current[$eid] = $now;
        }
        return queuePruneDoneRegistry($current, $now);
    });
}

/**
 * Атомарная относительно ручного replay финализация успешных событий.
 *
 * Реестр done и inbox — два файла. Без общего замка replay успевал прочитать
 * старый done, дождаться inbox-lock уже после worker ack и вернуть закрытый eid
 * обратно. Порядок внутри один для всех: общий mutex → done → DLQ → inbox → done.
 * Closing пишется первой и снимается последней: падение на любом промежуточном
 * шаге оставляет запрет повторного бизнес-эффекта, а не опасное полусостояние.
 */
function queueFinalizeDone(array $eids, ?string $file = null): void {
    if ($eids === []) return;
    withKeyLock('queue-finalize-replay', static function () use ($eids, $file): void {
        queueRecoverClosingUnlocked($file);
        queueMarkDone($eids);
        queueForgetDead($eids);
        queueAck($eids, [], $file);
        queueSettleDone($eids);
    }, 25.0);
}

/** Сколько событий ждёт разбора (взятые в работу тоже считаются). */
function queueSize(?string $file = null): int {
    $file = $file ?? INBOX_FILE;
    if (!is_file($file)) return 0;
    queueAssertFileWithinByteLimit($file);
    $queue = storeRead($file);
    if (!is_array($queue)) return 0;
    queueAssertStateWithinByteLimits($queue);
    return count($queue);
}

/**
 * Отложить событие до следующей попытки: 60 секунд, потом 120, потом 240.
 *
 * @param array $event Событие, как его вернул queueTake().
 * @param int   $tries Какая по счёту попытка провалилась.
 */
function queueDefer(array $event, int $tries): array {
    $event['tries']   = $tries;
    $event['next_at'] = time() + QUEUE_BACKOFF_SEC * (2 ** max(0, $tries - 1));
    unset($event['taken']);
    return $event;
}

/**
 * ЯЩИК НЕРАЗОБРАННЫХ: событие, которое не поддалось за все попытки.
 *
 * Раньше такое событие просто удалялось из очереди — оставалась строка в
 * журнале, из которой нельзя ничего повторить. Портал события не переприсылает,
 * поэтому дефект обработчика превращал принятое бизнес-событие (сделка, оплата)
 * в окончательно потерянное. Теперь оно ложится сюда целиком, вместе с текстом
 * ошибки, и его можно вернуть в работу: `php www/bin/process.php replay`.
 */
function queueDeadLetter(array $event, string $error, ?string $file = null): void {
    $file = $file ?? DEADLETTER_FILE;
    // Разросшийся ящик не декодируем: понятная ошибка лучше, чем фатал по памяти
    // в момент, когда событие ещё не подтверждено во входящей очереди.
    queueAssertFileWithinByteLimit($file, DEADLETTER_MAX_BYTES);
    storeUpdate($file, static function (array $box) use ($event, $error): array {
        $eid = (string)($event['eid'] ?? '');
        if ($eid === '') $eid = bin2hex(random_bytes(12));

        // Повтор после обрыва между записью в DLQ и ack входящей очереди не
        // должен создавать вторую копию того же события.
        foreach ($box as $known) {
            if (is_array($known) && (string)($known['eid'] ?? '') === $eid) return $box;
        }
        // Вытеснять старое событие новым нельзя: это тихая потеря уже принятого
        // бизнес-события. Исключение оставляет исходное событие в inbox — cron
        // не имеет права подтвердить его, пока DLQ не принял запись.
        if (count($box) >= DEADLETTER_MAX) {
            throw new RuntimeException('ящик неразобранных заполнен (' . DEADLETTER_MAX
                . '): событие осталось во входящей очереди');
        }
        if (queueStoredBytes($box) >= DEADLETTER_MAX_BYTES) {
            throw new RuntimeException('ящик неразобранных больше предела ' . DEADLETTER_MAX_BYTES
                . ' байт: событие осталось во входящей очереди');
        }
        $box[] = [
            'at'      => date('c'),
            'event'   => (string)($event['event'] ?? ''),
            'data'    => $event['data'] ?? [],
            'tries'   => (int)($event['tries'] ?? 0),
            'error'   => $error,
            'eid'     => $eid,
            'install_generation' => (string)($event['install_generation'] ?? ''),
        ];
        return $box;
    });
}

/** Сколько событий лежит в ящике неразобранных. */
function queueDeadCount(?string $file = null): int {
    $file = $file ?? DEADLETTER_FILE;
    if (!is_file($file)) return 0;
    // Веб-путь читает файл в память обычного PHP-воркера, поэтому потолок у него
    // прежний: распухший ящик даст понятную ошибку на экране «Состояние», а не
    // фатал по памяти посреди страницы. Больший потолок разбора — только у CLI,
    // где памяти больше и где ящик, собственно, и разгребают.
    queueAssertFileWithinByteLimit($file,
        PHP_SAPI === 'cli' ? DEADLETTER_RECOVERY_MAX_BYTES : DEADLETTER_MAX_BYTES);
    $box = storeRead($file);
    return is_array($box) ? count($box) : 0;
}

/**
 * Вернуть неразобранные события в очередь (руками, после починки обработчика).
 *
 * @return int Сколько вернули.
 */
function queueReplayDead(?string $file = null, ?string $inbox = null, ?int &$propuscheno = null): int {
    $propuscheno = 0;
    $file  = $file ?? DEADLETTER_FILE;
    $inbox = $inbox ?? INBOX_FILE;
    if (!is_file($file)) return 0;
    queueAssertFileWithinByteLimit($file, DEADLETTER_RECOVERY_MAX_BYTES);
    $box = storeRead($file);
    if (!is_array($box) || $box === []) return 0;
    queueAssertFileWithinByteLimit($inbox);

    // Метку каждой записи считаем ОДИН раз, до переноса. У старых записей шаблона
    // своего eid нет, и он выводится из позиции в ящике — а позиции сдвигаются,
    // как только первая порция оттуда удалена. Посчитав метку заранее и удаляя
    // потом по ней (а старые записи — по содержимому), мы перестаём зависеть от
    // порядка вовсе.
    $items = [];
    foreach ($box as $i => $rec) {
        if (!is_array($rec)) continue;
        $eid = (string)($rec['eid'] ?? '');
        $items[] = [
            'eid'    => $eid !== '' ? $eid : 'legacy-' . substr(hash('sha256', serialize([$i, $rec])), 0, 24),
            'own'    => $eid !== '',                                   // своя метка или выведенная нами
            'digest' => hash('sha256', serialize($rec)),
            'rec'    => $rec,
        ];
    }
    if ($items === []) return 0;

    // ПЕРЕНОСИМ ПОРЦИЯМИ, а не всем ящиком под одним замком.
    //
    // Ящик неразобранных копится месяцами и разгребается один раз — руками, после
    // починки обработчика. Пока весь перенос шёл одним storeUpdate, входящая
    // очередь была заперта на всё время обхода (а обход тяжёлый: каждая запись
    // пересчитывает объём очереди целиком). Приёмник событий ждёт этот же замок
    // ровно пять секунд и, не дождавшись, отвечает порталу отказом — а портал
    // повторов не шлёт. То есть ручная починка ящика РОНЯЛА живые события, и
    // происходило это тем вернее, чем больше накопилось разбирать.
    //
    // Между порциями замок отпускается, и приёмник вклинивается со своим
    // событием. Заодно обрыв на середине больше не теряет весь перенос: то, что
    // уже уехало во входящую очередь, из ящика удалено.
    $total = 0;
    $full = false;
    foreach (array_chunk($items, QUEUE_REPLAY_PORTION) as $chunk) {
        $total += queueReplayPortion($file, $inbox, $chunk, $full, $propuscheno);
        if ($full) break;   // входящая очередь переполнена — остальное ждёт следующего раза
    }
    // МОЛЧА ОСТАНОВИТЬСЯ НЕЛЬЗЯ. Оператор запускает разбор ящика руками, один раз,
    // после починки обработчика — и по числу «возвращено» решает, что дело
    // сделано. Если очередь переполнилась на середине, часть событий осталась в
    // ящике, а выглядело это как полный успех (находка ревью 09.08.2026).
    if ($full) {
        $ostalos = max(0, count($items) - $total - (int)$propuscheno);
        if (function_exists('appLog')) {
            appLog('warn', 'queue.replay.inbox_full',
                ['возвращено' => $total, 'осталось в ящике' => $ostalos],
                ['src' => 'cron', 'msg' => 'разбор ящика остановлен: входящая очередь заполнена. '
                    . 'Дождитесь, пока фоновая работа разберёт очередь, и повторите — '
                    . 'в ящике осталось ещё ' . $ostalos]);
        }
        $GLOBALS['BXAPP_REPLAY_INBOX_FULL'] = $ostalos;
    }
    return $total;
}

/** За сколько записей ящика берёмся под одним замком входящей очереди. */
if (!defined('QUEUE_REPLAY_PORTION')) define('QUEUE_REPLAY_PORTION', 50);

/**
 * Одна порция переноса: под замком входящей очереди — только она.
 *
 * @param array $items записи ящика с посчитанными метками (см. queueReplayDead)
 * @param bool  $full  наружу: входящая очередь переполнена, дальше переносить некуда
 * @param int   $propuscheno наружу: убрано из ящика БЕЗ возврата (уже в очереди / уже закрыто)
 * @return int сколько записей действительно поставлено в очередь
 */
function queueReplayPortion(string $file, string $inbox, array $items, bool &$full,
                            ?int &$propuscheno = null): int {
    return withKeyLock('queue-finalize-replay', static function () use (
        $file, $inbox, $items, &$full, &$propuscheno
    ): int {
        queueRecoverClosingUnlocked($inbox);
        return queueReplayPortionUnlocked($file, $inbox, $items, $full, $propuscheno);
    }, 25.0);
}

/** Внутренняя порция: вызывается только под queue-finalize-replay mutex. */
function queueReplayPortionUnlocked(string $file, string $inbox, array $items, bool &$full,
                                    ?int &$propuscheno = null): int {
    $moved = [];        // метка → убрано из ящика (перенесено ИЛИ признано лишним)
    $postavleno = [];   // метка → реально поставлено во входящую очередь
    $movedRaw = [];     // отпечаток записи → перенесено (для старых записей без своей метки)
    $uzheSdelano = []; // метка → событие уже закрыто окончательно, возвращать нельзя
    // Реестр закрытых читаем ОДИН раз на порцию: он маленький, а обращение к
    // состоянию на каждую запись превратило бы разбор в сотни чтений подряд.
    $reestr = storeRead(queueDoneFile());
    if (!is_array($reestr)) $reestr = [];
    storeUpdate($inbox, static function (array $queue) use ($items, $reestr, &$moved, &$postavleno, &$movedRaw, &$uzheSdelano, &$full): array {
        // Состояние входящей очереди проверяем ОДИН раз на вход в мутатор. Дальше
        // она меняется только нашей же дозаписью, поэтому переверять её на каждое
        // добавленное событие — значит обходить всю очередь N раз подряд.
        queueAssertStateWithinByteLimits($queue);
        $known = [];
        $leased = [];
        $now = time();
        foreach ($queue as $event) {
            if (!is_array($event) || (string)($event['eid'] ?? '') === '') continue;
            $eid = (string)$event['eid'];
            $known[$eid] = true;
            // Событие с ЖИВОЙ арендой прямо сейчас держит фоновая обработка. Это
            // не след прошлого разбора, а копия, которую тик вот-вот подтвердит и
            // уберёт: считать её «уже возвращённой» и вычёркивать из ящика —
            // значит потерять событие целиком, потому что портал повторов не шлёт.
            $taken = (int)($event['taken'] ?? 0);
            if ($taken > 0 && ($now - $taken) < QUEUE_LEASE_SEC) $leased[$eid] = true;
        }
        foreach ($items as $item) {
            $eid = (string)$item['eid'];
            $rec = $item['rec'];
            if (isset($leased[$eid])) {
                // Занято работающим тиком — оставляем в ящике нетронутым.
                // Следующий разбор заберёт его, когда тик закончит.
                continue;
            }
            if (isset($known[$eid])) {
                // Прошлый replay оборвался после записи в inbox: событие уже там,
                // из ящика его надо просто убрать.
                $moved[$eid] = true;
                if (!$item['own']) $movedRaw[$item['digest']] = true;
                continue;
            }
            // СОБЫТИЕ УЖЕ ЗАКРЫТО ОКОНЧАТЕЛЬНО. В очереди его нет не потому, что
            // оно потерялось, а потому, что воркер его доделал и подтвердил
            // между двумя разборами ящика. Вернуть такое — значит выполнить
            // бизнес-действие второй раз (находка ревью 08.08.2026). Из ящика
            // запись убираем: держать её там больше не за чем.
            $zakryto = $reestr[$eid] ?? null;
            if (queueDoneRecordAt($zakryto) !== null) {
                $uzheSdelano[$eid] = true;
                $moved[$eid] = true;
                if (!$item['own']) $movedRaw[$item['digest']] = true;
                continue;
            }
            $candidate = [
                'at'    => date('c'),
                'event' => (string)($rec['event'] ?? ''),
                'data'  => $rec['data'] ?? [],
                // Счётчик обнуляем: обработчик починили, попытки считаем заново.
                'tries' => 0,
                'eid'   => $eid,
                'install_generation' => (string)($rec['install_generation'] ?? ''),
            ];
            $reason = null;
            // Состояние очереди проверено один раз перед циклом — повторять на
            // каждой записи незачем: очередь меняется только нашей же дозаписью,
            // а каждая проверка обходит её целиком.
            $next = queueAppendLimited($queue, $candidate, $reason, true);
            if ($reason !== null) { $full = true; break; }
            $queue = $next;
            $known[$eid] = true;
            $moved[$eid] = true;
            $postavleno[$eid] = true;
            if (!$item['own']) $movedRaw[$item['digest']] = true;
        }
        return $queue;
    });
    // Убранное из ящика и ПОСТАВЛЕННОЕ в очередь — разные числа. Событие, уже
    // лежащее в очереди, и событие, закрытое окончательно, из ящика исчезают, но
    // разбираться заново не будут. Пока наружу уходило одно число, оператор,
    // разгребавший ящик после починки обработчика, читал «Возвращено в очередь: 40»
    // и ждал сорок разборов — а их могло не быть вовсе (находка ревью 09.08.2026).
    $propuscheno = (int)$propuscheno + (count($moved) - count($postavleno));
    if ($uzheSdelano !== [] && function_exists('appLog')) {
        // Молчать нельзя: снаружи это выглядит как «ящик разобрался сам».
        appLog('warn', 'queue.replay.already_done',
            ['событий' => count($uzheSdelano), 'метки' => array_slice(array_keys($uzheSdelano), 0, 10)],
            ['src' => 'cron', 'msg' => 'из ящика убраны события, которые уже были обработаны: '
                . count($uzheSdelano) . ' — повторно они не ставятся']);
    }
    if ($moved === []) return 0;
    $skolkoPostavleno = count($postavleno);

    // Не очищаем файл целиком: между чтением выше и этим замком другой worker мог
    // добавить новую запись. Удаляем только действительно перенесённое — по своей
    // метке, а записи без метки по отпечатку содержимого. По позиции сравнивать
    // нельзя: соседняя порция уже сдвинула их.
    storeUpdate($file, static function (array $current) use ($moved, $movedRaw): array {
        $out = [];
        foreach ($current as $rec) {
            if (!is_array($rec)) { $out[] = $rec; continue; }
            $eid = (string)($rec['eid'] ?? '');
            if ($eid !== '') {
                if (!isset($moved[$eid])) $out[] = $rec;
                continue;
            }
            if (!isset($movedRaw[hash('sha256', serialize($rec))])) $out[] = $rec;
        }
        return $out;
    });
    // DLQ уже очищен успешно: теперь closing можно сжать в обычную TTL-запись.
    // Если процесс умер строкой выше, closing останется и всё равно не даст
    // следующему replay повторить бизнес-эффект.
    if ($uzheSdelano !== []) queueSettleDone(array_keys($uzheSdelano));
    return $skolkoPostavleno;
}
