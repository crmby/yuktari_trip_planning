<?php
declare(strict_types=1);
/**
 * api/event.php — приём событий портала (ONCRMDEALUPDATE, ONAPPUNINSTALL и т.п.).
 *
 * Отдельная точка входа, а не ветка в index.php: там стоят гейт установки и гейт
 * доступа, рассчитанные на человека в браузере, и событие об них спотыкается —
 * портал считает подписку сломанной.
 *
 * Что здесь важно и чего нельзя менять:
 *
 * 1. Подлинность — по `application_token` из тела события, сверенному с
 *    сохранённым при установке. Адрес обработчика публичен, `access_token` в
 *    событии может вообще не прийти, а домен и member_id подделываются. Токен
 *    приложения — единственное, что доказывает отправителя.
 * 2. Ответ 200 отдаём ТОЛЬКО после того, как событие сохранено. Повторов у
 *    портала нет: ответил 200 и упал — событие потеряно навсегда.
 * 3. Сама обработка — не здесь. Здесь очередь, разбирает её cron (bin/process.php).
 *    Портал ждёт быстрый ответ, а бизнес-логика бывает долгой.
 *
 * Подписка на события ставится методом `event.bind` с этим URL в handler.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/access.php';   // владелец приложения: он же вправе переустановить
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/http.php';     // готовый persistent rate limiter для публичных отказов
require_once __DIR__ . '/queue.php';    // единые byte caps inbox

installFailureHandler(true);
sendNoStoreHeaders();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Публичный handler не должен разбирать неограниченное тело. Проверяем длину
// ДО работы с уже заполненным $_POST и затем фактически прочитанные байты: при
// chunked-запросе CONTENT_LENGTH может отсутствовать. Без этого корректный
// префикс большого form-body принимался частично, а портал получал ложный 200.
$eventMaxBody = INBOX_EVENT_MAX_BYTES;
$eventContentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($eventContentLength > $eventMaxBody) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Слишком большой запрос'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Читаем ограниченный raw-body ВСЕГДА, даже когда PHP уже заполнил $_POST.
// При chunked form-urlencoded CONTENT_LENGTH отсутствует, но $_POST непустой;
// прежняя ветка тогда вообще не измеряла тело и обходила cap.
$eventRaw = file_get_contents('php://input', false, null, 0, $eventMaxBody + 1);
if (!is_string($eventRaw)) $eventRaw = '';
if (strlen($eventRaw) > $eventMaxBody) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Слишком большой запрос'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Портал шлёт form-urlencoded; JSON поддерживаем на случай ручной отправки.
$in = $_POST;
if (!$in) {
    $j = json_decode($eventRaw, true);
    if (is_array($j)) $in = $j;
    else { parse_str($eventRaw, $p); if (is_array($p)) $in = $p; }
}

// События жизненного цикла (ONAPPINSTALL/ONAPPUPDATE) несут НОВЫЙ токен
// приложения — сверять его с сохранённым бессмысленно, он для того и прислан,
// чтобы заменить старый. Разбирает их общая с index.php функция: она проверяет
// подлинность живым access_token нашего портала и принимает ротацию. Без этой
// ветки обновление приложения на портале навсегда обрывало приём событий.
$life = lifecycleTokenEvent($in);
if ($life !== null) {
    if (!$life['ok']) {
        http_response_code($life['code']);
        if ($life['code'] === 503) header('Retry-After: 60');
        echo json_encode(['ok' => false, 'error' => $life['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Обычное событие держит ту же shared lifecycle-границу, что API-запрос:
// проверка application_token/generation и запись в inbox относятся к одному
// поколению. Переустановка/удаление ждут завершения этой записи.
// Отказ здесь — ПОТЕРЯ события: портал повторно его не пришлёт
// (/data/config/b24-rest-docs/api-reference/events/index.md: «Повторных отправок
// нет»). Прежде ни одна из двух веток не оставляла следа, и на вопрос «почему
// сделка не обработалась» журнал молчал (ревью 23.09.2026, otkazy-1). Имя события
// недоверенное — только короткая метка; лимитер тот же, что у ветки 403.
$eventLostLog = static function (string $why) use ($in): void {
    if (apiRateAllow('event-lifecycle-busy-log', 20, 60)) {
        logAppError('event: не принято — ' . $why . ' (событие: ' . queueEventLogLabel($in['event'] ?? null)
            . '); портал повторно его не пришлёт — изменение нужно сверить с порталом');
    }
};
try {
    $lockedGeneration = appLifecycleHoldRequest();
} catch (InstallGenerationMismatchException $e) {
    $eventLostLog('состояние установки обновляется');
    http_response_code(503);
    header('Retry-After: 60');
    echo json_encode(['ok' => false, 'error' => 'Состояние установки обновляется'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (InstallLifecycleBusyException $e) {
    $eventLostLog('установка занята обновлением');
    http_response_code(503);
    header('Retry-After: 60');
    echo json_encode(['ok' => false, 'error' => 'Установка занята обновлением'], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth  = (array)($in['auth'] ?? []);
$b24   = new B24(defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '', defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '');
$saved = (string)($b24->applicationToken() ?? '');
$got   = (string)($auth['application_token'] ?? $in['application_token'] ?? '');
$member = (string)($auth['member_id'] ?? $in['member_id'] ?? '');
$generation = (string)($b24->installGeneration() ?? '');

// Fail-closed по всем осям сразу: не установлено / нет сохранённого токена /
// токен не совпал / чужой портал. Ни одна из них не должна пропускать дальше.
if ($saved === '' || $got === '' || !hash_equals($saved, $got)
    || ($member !== '' && !hash_equals((string)($b24->memberId() ?? ''), $member))) {
    // Адрес публичен: без лимита аноним мог превратить каждый 403 в новую строку
    // журнала. Имя события тоже недоверенное — только короткая ASCII-метка без LF.
    if (apiRateAllow('event-auth-denied-log', 5, 60)) {
        logAppError('event: отклонено — подлинность не подтверждена (событие: '
            . queueEventLogLabel($in['event'] ?? null) . ')');
    }
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Событие не подтверждено'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($generation === '' || !hash_equals($lockedGeneration, $generation)) {
    // Без поколения событие нельзя безопасно связать с установкой: delayed
    // uninstall иначе способен стереть уже следующую быструю переустановку.
    http_response_code(503);
    header('Retry-After: 60');
    echo json_encode(['ok' => false, 'error' => 'Состояние установки обновляется'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $overflow = null;
    // Этот гейт идёт до storeRead внутри storeUpdate: legacy-файл, который уже
    // вырос за cap, нельзя сначала целиком декодировать — именно это и даёт OOM.
    queueAssertFileWithinByteLimit(INBOX_FILE);
    storeUpdate(INBOX_FILE, static function (array $queue) use ($in, $generation, &$overflow): array {
        // Очередь ограничена: если разбор встал, она не должна съесть диск. Но
        // выбрасывать УЖЕ ПРИНЯТОЕ событие нельзя — мы за него ответили 200, а
        // портал подтверждённое не повторяет. Раньше здесь стоял array_slice, и
        // переполнение молча стирало самое старое событие, отвечая «ок» на новое:
        // терялись оба конца — и старое, и доверие к ответу. Теперь отказываемся
        // принимать новое, пока очередь не разберут: 503 портал покажет в журнале
        // доставки, и потеря станет видимой.
        $record = [
            'at'    => date('c'),
            'event' => queueEventLogLabel($in['event'] ?? null),
            'data'  => $in['data'] ?? [],
            'install_generation' => $generation,
        ];
        return queueAppendLimited($queue, $record, $overflow);
    });
    if ($overflow !== null) {
        if (apiRateAllow('event-inbox-overflow-log', 5, 300)) {
            logAppError('event: очередь не приняла событие (' . $overflow . ') — разбор встал или превышен byte cap');
        }
        $tooLarge = $overflow === 'event_bytes';
        http_response_code($tooLarge ? 413 : 503);
        if (!$tooLarge) header('Retry-After: 300');
        echo json_encode(['ok' => false, 'error' => $tooLarge
            ? 'Событие слишком большое'
            : 'Очередь переполнена, событие не принято'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    // Не сохранили — не подтверждаем. Портал хотя бы зафиксирует ошибку доставки.
    // Через maskSecrets: в тексте исключения от портала регулярно оказывается
    // URL целиком, а в нём — токен доступа.
    logAppError('event: не удалось сохранить событие: ' . maskSecrets($e->getMessage()));
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Временно не можем принять событие'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
