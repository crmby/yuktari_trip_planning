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
 * Состояние долгой задачи. Один файл, одна активная задача.
 *
 * Почему одна. Очередь параллельных задач звучит гибче, но у приложения один
 * фоновый рабочий и один портал: две задачи разом просто делят одну и ту же
 * квоту запросов и мешают друг другу. Когда понадобится больше — это отдельный
 * блок с очередью, а не тихое усложнение этого.
 *
 * Поля состояния:
 *   id        — метка запуска: по ней видно, что экран смотрит на ТУ ЖЕ задачу
 *   status    — running | unknown | done | failed | cancelled | idle
 *   total     — сколько всего работы (0 = ещё не посчитано)
 *   done      — сколько сделано; растёт только вперёд
 *   cursor    — на чём остановились: с него продолжает следующая порция
 *   step      — КВИТАНЦИЯ начатой порции: пишется ДО работы, снимается отметкой
 *               прогресса. Осталась — значит порция начиналась, а чем кончилась,
 *               не знает никто (процесс убит на середине)
 *   cancel    — просьба остановиться; рабочий видит её МЕЖДУ порциями
 *   startedAt / finishedAt / heartbeat — время; heartbeat обновляется каждой порцией
 *   error     — человеческая причина отказа (без внутренностей и секретов)
 *   result    — короткая сводка для экрана
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';

/**
 * Нижняя граница молчания, после которой рабочего считаем мёртвым.
 *
 * Это именно НИЖНЯЯ граница, а не ответ: настоящий срок зависит от того, как
 * часто планировщик вообще запускает фоновую обработку. Шаблон сам показывает в
 * примерах и «раз в минуту», и «раз в 15 минут», а деплой печатает строку крона,
 * которую человек правит под себя. При такте в 15 минут жёсткие пять минут
 * означали, что МЕЖДУ тиками живая задача выглядела мёртвой: экран писал «нет
 * признаков жизни» и предлагал запустить заново — то есть пройти по порталу
 * второй раз ту же работу, которую фон в это время спокойно доделывал.
 */
const TASK_STALE_SECONDS = 300;

/** Во сколько тактов планировщика укладывается терпение до вердикта «умер». */
const TASK_STALE_TICKS = 3;

/**
 * Сколько секунд за один тик крона задача считает.
 *
 * Держится НЕ из соображений «побольше успеть»: весь бюджет проходит внутри
 * общего замка установки, а установка/переустановка/удаление ждут своей очереди
 * 25 секунд и потом отвечают человеку «Приложение обновляется». Поэтому бюджет
 * заведомо меньше этого ожидания — иначе идущая задача раз в минуту делала бы
 * переустановку невозможной. Недосчитанное честно продолжит следующий тик.
 */
const TASK_TICK_BUDGET_SECONDS = 20;

// Cursor приходит из project hook, result/error — из project worker. Один active
// task не означает bounded state: каждое из этих полей раньше могло вырасти без
// потолка. Aggregate cap включает private-header и действует до JSON decode.
const TASK_CURSOR_MAX_BYTES = 64 * 1024;
const TASK_TEXT_MAX_BYTES = 16 * 1024;
// TASK_MAX_BYTES объявлен общим store.php: тот же ceiling нужен lifecycle
// replacement ещё до загрузки этого owner-модуля.

/** Cursor/message/candidate long-task вышел за предметный предел. */
final class TaskStateLimitException extends RuntimeException {}

/** Размер cursor в JSON-форме, реально попадающей в snapshot. */
function taskCursorStoredBytes($cursor): int {
    $json = json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new TaskStateLimitException('task: cursor не сериализуется');
    }
    return strlen($json);
}

function taskAssertCursorWithinLimit($cursor): void {
    if (taskCursorStoredBytes($cursor) > TASK_CURSOR_MAX_BYTES) {
        throw new TaskStateLimitException('task: cursor больше допустимого предела');
    }
}

function taskAssertTextWithinLimit(string $value, string $field): void {
    if (strlen($value) > TASK_TEXT_MAX_BYTES) {
        throw new TaskStateLimitException('task: поле ' . $field . ' больше допустимого предела');
    }
}

/** Точный размер task snapshot вместе с private-header. */
function taskStoredBytes(array $state): int {
    try {
        return strlen(storeEncode($state));
    } catch (Throwable $e) {
        throw new TaskStateLimitException('task: состояние не сериализуется', 0, $e);
    }
}

/** Проверить field и aggregate ceilings до публикации. */
function taskAssertStateWithinLimits(array $state): array {
    if (array_key_exists('cursor', $state)) taskAssertCursorWithinLimit($state['cursor']);
    $step = $state['step'] ?? null;
    if (is_array($step) && array_key_exists('cursor', $step)) {
        taskAssertCursorWithinLimit($step['cursor']);
    }
    foreach (['result', 'error'] as $field) {
        $value = $state[$field] ?? '';
        if (!is_string($value)) {
            throw new TaskStateLimitException('task: поле ' . $field . ' должно быть строкой');
        }
        taskAssertTextWithinLimit($value, $field);
    }
    if (taskStoredBytes($state) > TASK_MAX_BYTES) {
        throw new TaskStateLimitException('task: файл больше допустимого предела');
    }
    return $state;
}

/** Единственная bounded read-точка task state, включая legacy pre-decode. */
function taskStoreRead(): ?array {
    $state = storeRead(TASK_FILE, TASK_MAX_BYTES);
    return is_array($state) ? taskAssertStateWithinLimits($state) : null;
}

/** Единственная bounded RMW-точка: candidate проверяется под тем же EX-lock. */
function taskStoreUpdate(callable $mutator): array {
    return storeUpdate(TASK_FILE, $mutator, STORE_LOCK_TIMEOUT, TASK_MAX_BYTES,
        static fn(array $next): array => taskAssertStateWithinLimits($next));
}

function taskEmpty(): array {
    return [
        'id' => '', 'status' => 'idle', 'total' => 0, 'done' => 0, 'cursor' => null,
        'cancel' => false, 'startedAt' => 0, 'finishedAt' => 0, 'heartbeat' => 0,
        'error' => '', 'result' => '', 'startedBy' => 0,
        // Квитанция начатой порции; null = ни одна порция сейчас не начата.
        'step' => null,
        // Последнее доказанное решение по unknown-step. Сам read-back
        // делает код проекта; движок хранит его краткую audit-квитанцию.
        'reconciliation' => null,
        // На каком выпуске приложения работа НАЧАЛАСЬ. Долгая задача переживает
        // выкладку: она идёт часами, а код за это время меняется. Продолжить её
        // «как ни в чём не бывало» — значит доделать вторую половину по другим
        // правилам: другой формат курсора, другой набор полей, другая логика
        // порции. Внешне это выглядит как «отработало», а расходится потом.
        'release' => '',
    ];
}

/** Отпечаток выпуска, на котором сейчас исполняется код. */
function taskCurrentRelease(): string {
    return defined('APP_RELEASE') ? (string)APP_RELEASE : 'dev';
}

/**
 * Сменился ли выпуск с момента запуска задачи.
 *
 * Пустая метка у старой задачи (заведена до этой проверки) — НЕ повод кричать:
 * сравнивать не с чем, и предупреждение без основания хуже молчания.
 */
function taskReleaseChanged(array $t): bool {
    $was = (string)($t['release'] ?? '');
    return $was !== '' && $was !== taskCurrentRelease();
}

function taskRead(): array {
    $raw = taskStoreRead();
    $state = is_array($raw) ? array_merge(taskEmpty(), $raw) : taskEmpty();
    return taskAssertStateWithinLimits($state);
}

/** Сколько последних промежутков между запусками помним. */
const CRON_TICK_SAMPLES = 5;

/** Потолок терпения: дольше этого «жив ли рабочий» не ждём НИКОГДА. */
const TASK_STALE_MAX_SECONDS = 6 * 3600;

/**
 * Запомнить, когда рабочий приходил. Возвращает обновлённое состояние.
 *
 * ПОЧЕМУ НЕ «САМЫЙ ДЛИННЫЙ ПРОМЕЖУТОК». Раньше такт считался как максимум из
 * виденных, и он не умел уменьшаться. Один простой — хостинг чинили, задание в
 * панели выключили на сутки — навсегда становился «нормальным расписанием»: после
 * него мёртвый рабочий определялся только через трое суток. Всё это время человек
 * видит «выполняется», кнопка запуска заблокирована, и починить нечем.
 *
 * Теперь помним несколько последних промежутков и берём СЕРЕДИННЫЙ. Разовый
 * выброс в такой выборке ничего не решает, а настоящая смена расписания
 * (например, с 15 минут на час) через несколько запусков становится большинством
 * и принимается.
 */
function cronTickRemember(array $state, int $now): array {
    $prev = (int)($state['cron_last_run_ts'] ?? 0);
    if ($prev > 0 && $now > $prev) {
        $gap = $now - $prev;
        // Сутки — предел разумного: больший разрыв означает, что планировщик
        // стоял, и принимать его за расписание нельзя даже как наблюдение.
        if ($gap <= 86400) {
            $samples = array_values(array_filter(
                array_map('intval', (array)($state['cron_tick_samples'] ?? [])),
                static fn(int $v): bool => $v > 0
            ));
            $samples[] = $gap;
            if (count($samples) > CRON_TICK_SAMPLES) {
                $samples = array_slice($samples, -CRON_TICK_SAMPLES);
            }
            $state['cron_tick_samples'] = $samples;
            $state['cron_tick_seconds'] = cronTickSeconds($state);
        }
    }
    $state['cron_last_run_ts'] = $now;
    return $state;
}

/** Серединный промежуток между запусками — то, что считаем тактом. */
function cronTickSeconds(array $state): int {
    $samples = array_values(array_filter(
        array_map('intval', (array)($state['cron_tick_samples'] ?? [])),
        static fn(int $v): bool => $v > 0
    ));
    if ($samples === []) return max(0, (int)($state['cron_tick_seconds'] ?? 0));
    sort($samples);
    return (int)$samples[intdiv(count($samples), 2)];
}

/**
 * Сколько молчания терпим, прежде чем считать рабочего мёртвым.
 *
 * Отвечает НЕ константа, а наблюдение: сколько времени реально проходит между
 * запусками фоновой обработки на этом сервере. Отметку пишет сам рабочий, так что
 * расписание не надо ни спрашивать, ни угадывать. Сверху терпение ограничено:
 * предохранитель, из которого нельзя выйти в разумный срок, — это не защита.
 */
function taskStaleSecondsFor(array $state): int {
    $tick = cronTickSeconds($state);
    if ($tick <= 0) return TASK_STALE_SECONDS;
    return min(TASK_STALE_MAX_SECONDS, max(TASK_STALE_SECONDS, $tick * TASK_STALE_TICKS));
}

function taskStaleSeconds(): int {
    $state = storeRead(STATE_FILE);
    return taskStaleSecondsFor(is_array($state) ? $state : []);
}

/**
 * Задача считается ЖИВОЙ, только если рабочий недавно отмечался. Иначе после
 * падения процесса кнопка «Запустить» оставалась бы навсегда заблокированной, а
 * человеку сказали бы «уже выполняется» про то, что не выполняется никем.
 *
 * «Недавно» меряем в тактах планировщика, а не в секундах: при кроне раз в 15
 * минут пауза между тиками — нормальная работа, а не смерть процесса.
 */
function taskIsAlive(array $task): bool {
    if (($task['status'] ?? '') !== 'running') return false;
    $beat = (int)($task['heartbeat'] ?: $task['startedAt']);
    return $beat > 0 && (time() - $beat) < taskStaleSeconds();
}

/** Что видит экран. Внутренние поля наружу не отдаём. */
function taskForClient(): array {
    $t = taskRead();
    $pending = taskPendingStep($t);
    $stale = ($t['status'] ?? '') === 'running' && !taskIsAlive($t);
    // Протухший heartbeat обычно означает «можно начать заново». Но
    // receipt важнее liveness: порция могла успеть изменить портал.
    $status = $stale ? ($pending !== null ? 'unknown' : 'stalled') : (string)$t['status'];
    $needsReconciliation = $status === 'unknown' && $pending !== null;
    return [
        'id'         => (string)$t['id'],
        'status'     => $status,
        'total'      => (int)$t['total'],
        'done'       => (int)$t['done'],
        'percent'    => (int)$t['total'] > 0
            ? (int)floor(min(100, (int)$t['done'] * 100 / (int)$t['total'])) : 0,
        'cancelling' => !empty($t['cancel']) && ($t['status'] ?? '') === 'running',
        'startedAt'  => (int)$t['startedAt'],
        'finishedAt' => (int)$t['finishedAt'],
        'error'      => (string)$t['error'],
        'result'     => (string)$t['result'],
        'needsReconciliation' => $needsReconciliation,
        // Attempt наружу не отдаём: это CAS-токен для project hook, а не
        // кнопка браузера. Клиенту достаточно стабильного key и времени.
        'unknownStep' => $needsReconciliation ? [
            'key' => (string)($pending['key'] ?? ''),
            'at'  => (int)($pending['at'] ?? 0),
        ] : null,
    ];
}

/**
 * Поставить задачу. Возвращает ['ok'=>bool,'error'=>string,'task'=>array].
 *
 * Проверка «уже идёт» и запись — под ОДНИМ замком. Раздельно это классическая
 * гонка: два человека жмут «Запустить» одновременно, оба видят «свободно», и
 * задача стартует дважды — портал получает двойную работу.
 */
function taskStart(int $uid): array {
    $result = ['ok' => false, 'error' => '', 'task' => []];
    taskStoreUpdate(static function (array $prev) use ($uid, &$result): array {
        $t = array_merge(taskEmpty(), $prev);
        if (taskPendingStep($t) !== null) {
            $result = ['ok' => false,
                'error' => 'Исход прошлой порции неизвестен: сначала нужна сверка',
                'task' => $t];
            return $t;
        }
        if (taskIsAlive($t)) {
            $result = ['ok' => false, 'error' => 'Задача уже выполняется', 'task' => $t];
            return $t;
        }
        $fresh = taskEmpty();
        // Метка запуска нужна экрану: по ней он отличает «моя задача идёт» от
        // «пока я не смотрел, задача успела закончиться и началась другая».
        // Это ЯРЛЫК, а не дата: форматировать время здесь нечего, и сырой date()
        // тут был бы обходом общего правила «даты — только через fmt.php».
        $fresh['id'] = (string)time() . '-' . bin2hex(random_bytes(4));
        $fresh['status'] = 'running';
        $fresh['startedAt'] = time();
        $fresh['heartbeat'] = time();
        $fresh['startedBy'] = $uid;
        $fresh['release'] = taskCurrentRelease();
        $result = ['ok' => true, 'error' => '', 'task' => $fresh];
        return $fresh;
    });
    return $result;
}

/** Попросить остановиться. Флаг, а не убийство: порция дорабатывает до конца. */
function taskCancel(int $uid): bool {
    $ok = false;
    taskStoreUpdate(static function (array $prev) use (&$ok): array {
        $t = array_merge(taskEmpty(), $prev);
        if (($t['status'] ?? '') !== 'running') return $t;
        $t['cancel'] = true;
        $ok = true;
        return $t;
    });
    return $ok;
}

/** Убрать завершённую задачу. Идущую и unknown-receipt не трогаем. */
function taskForget(): bool {
    $ok = false;
    taskStoreUpdate(static function (array $prev) use (&$ok): array {
        $t = array_merge(taskEmpty(), $prev);
        if (taskPendingStep($t) !== null) return $t;
        if (taskIsAlive($t)) return $t;
        $ok = true;
        return taskEmpty();
    });
    return $ok;
}

/**
 * Снять unknown-step ПОСЛЕ РУЧНОЙ проверки человеком (ревью 23.09.2026, ux_sboev-1).
 *
 * Прежде выход из unknown был только у кода проекта (`taskReconcileUnknownStep()`),
 * а в шаблоне и в большинстве проектов он возвращает null — задача вставала
 * навсегда, а на экране не было ни кнопки, ни объяснения. Теперь администратор,
 * посмотрев в портале, что успело примениться, снимает блокировку сам — ровно
 * как «Снять» у зависшей операции runOnce. Прогресс не продвигается: задача
 * становится остановленной, и следующий запуск начинается заново.
 *
 * Живую порцию снять нельзя: пока рабочий отмечается, он может её закончить.
 * CAS по id не даёт снять блокировку у задачи, запущенной после открытия экрана.
 */
function taskResolveManually(string $id, int $uid, string $note): bool {
    $note = trim($note);
    if ($id === '' || $uid <= 0) return false;
    if (mb_strlen($note) > 200) $note = mb_substr($note, 0, 200);
    $resolved = false;
    taskStoreUpdate(static function (array $prev) use ($id, $uid, $note, &$resolved): array {
        $t = array_merge(taskEmpty(), $prev);
        $pending = taskPendingStep($t);
        if ($pending === null || (string)$t['id'] !== $id) return $t;
        $status = (string)($t['status'] ?? '');
        if ($status !== 'unknown' && !($status === 'running' && !taskIsAlive($t))) return $t;
        $t['step'] = null;
        $t['reconciliation'] = [
            'key' => (string)($pending['key'] ?? ''),
            'outcome' => 'manual',
            'evidence' => $note !== '' ? $note : 'проверено вручную',
            'uid' => $uid,
            'at' => time(),
        ];
        $t['status'] = 'failed';
        $t['cancel'] = false;
        $t['finishedAt'] = time();
        $t['heartbeat'] = time();
        $t['error'] = 'Остановлено вручную: исход последней порции проверил сотрудник №' . $uid
            . '. Следующий запуск начнёт задачу заново.';
        $t['result'] = '';
        $resolved = true;
        return $t;
    });
    return $resolved;
}

/**
 * Стабильное имя шага: одна и та же позиция одной и той же задачи — один ключ.
 *
 * Отдаётся порции вторым аргументом, чтобы проект мог сделать свой внешний
 * эффект защищённым от повтора: `runOnce($step['key'], fn() => …)`. Курсор
 * бывает массивом и бывает длинным, поэтому в ключ идёт его отпечаток, а не он сам.
 */
function taskStepKey(string $id, $cursor): string {
    $json = json_encode($cursor, JSON_UNESCAPED_UNICODE);
    if (is_string($json) && strlen($json) > TASK_CURSOR_MAX_BYTES) {
        throw new TaskStateLimitException('task: cursor больше допустимого предела');
    }
    return $id . ':' . substr(hash('sha256', is_string($json) ? $json : 'нечитаемый-курсор'), 0, 16);
}

/**
 * Квитанция начатой, но НЕ завершённой порции — или null.
 *
 * Единственный признак, по которому после жёсткой смерти процесса (OOM, kill -9,
 * упавший хостинг) видно, что порция уже трогала портал. Обычный сбой ловится
 * перехватом, жёсткая смерть — ничем: без квитанции состояние после неё
 * неотличимо от «порция не начиналась», и следующий тик делает ту же работу
 * второй раз (находка ревью R3-003 от 09.08.2026).
 */
function taskPendingStep(array $task): ?array {
    $step = $task['step'] ?? null;
    if (!is_array($step) || (string)($step['attempt'] ?? '') === '') return null;
    return $step;
}

/**
 * Зафиксировать unknown-outcome, не стирая единственную квитанцию шага.
 *
 * Это не failed: портал мог принять мутацию, а мог не принять. Пока код
 * проекта не сделает свежий read-back, ни повтор, ни forget недопустимы.
 */
function taskMarkUnknown(string $id, string $error): bool {
    $marked = false;
    taskStoreUpdate(static function (array $prev) use ($id, $error, &$marked): array {
        $t = array_merge(taskEmpty(), $prev);
        if ((string)$t['id'] !== $id) return $t;
        if (!in_array((string)($t['status'] ?? ''), ['running', 'unknown'], true)) return $t;
        if (taskPendingStep($t) === null) return $t;
        $t['status'] = 'unknown';
        $t['finishedAt'] = 0;
        $t['heartbeat'] = time();
        $t['error'] = $error;
        $t['result'] = '';
        $marked = true;
        return $t;
    });
    return $marked;
}

/**
 * Применить результат PROJECT-SPECIFIC read-back к unknown-step.
 *
 * Сама функция в портал не ходит. Вызывать её можно только после свежей
 * сверки в `taskReconcileUnknownStep()` и с краткой evidence-ссылкой. CAS по id +
 * attempt не даст запоздавшему ответу снять receipt более новой задачи.
 *
 * @param string $outcome `applied` = эффект доказан и progress продвигается;
 *                        `not_applied` = доказано отсутствие эффекта, шаг можно повторить.
 * @param array  $resolution Обязательно `evidence`; для applied также `done` и `cursor`,
 *                           опционально `total`.
 */
function taskReconcileStep(string $id, string $attempt, string $outcome, array $resolution): bool {
    if (!in_array($outcome, ['applied', 'not_applied'], true)) {
        throw new InvalidArgumentException('taskReconcileStep: неизвестный исход');
    }
    if (!isset($resolution['evidence']) || !is_string($resolution['evidence'])) {
        throw new InvalidArgumentException('taskReconcileStep: evidence должна быть строкой');
    }
    $evidence = trim($resolution['evidence']);
    if ($evidence === '' || mb_strlen($evidence) > 200) {
        throw new InvalidArgumentException('taskReconcileStep: нужна evidence-ссылка до 200 символов');
    }
    if ($outcome === 'applied') {
        if (!array_key_exists('done', $resolution) || !array_key_exists('cursor', $resolution)) {
            throw new InvalidArgumentException('taskReconcileStep: applied требует done и cursor');
        }
        if (!is_int($resolution['done']) || $resolution['done'] < 0) {
            throw new InvalidArgumentException('taskReconcileStep: done должен быть целым неотрицательным числом');
        }
        if (array_key_exists('total', $resolution)
            && (!is_int($resolution['total']) || $resolution['total'] < $resolution['done'])) {
            throw new InvalidArgumentException('taskReconcileStep: total должен быть целым и не меньше done');
        }
        if (!is_string(json_encode($resolution['cursor'], JSON_UNESCAPED_UNICODE))) {
            throw new InvalidArgumentException('taskReconcileStep: cursor не сериализуется');
        }
        taskAssertCursorWithinLimit($resolution['cursor']);
    }

    $reconciled = false;
    taskStoreUpdate(static function (array $prev) use (
        $id, $attempt, $outcome, $resolution, $evidence, &$reconciled
    ): array {
        $t = array_merge(taskEmpty(), $prev);
        $pending = taskPendingStep($t);
        if ((string)$t['id'] !== $id || ($t['status'] ?? '') !== 'unknown' || $pending === null) return $t;
        if ($attempt === '' || !hash_equals((string)$pending['attempt'], $attempt)) return $t;

        if ($outcome === 'applied') {
            $done = $resolution['done'];
            $currentDone = max(0, (int)$t['done']);
            $currentTotal = max(0, (int)$t['total']);
            $total = array_key_exists('total', $resolution) ? $resolution['total'] : $currentTotal;
            $oldCursor = json_encode($pending['cursor'] ?? null, JSON_UNESCAPED_UNICODE);
            $newCursor = json_encode($resolution['cursor'], JSON_UNESCAPED_UNICODE);
            if ($done <= $currentDone) {
                throw new InvalidArgumentException('taskReconcileStep: applied обязан продвинуть done');
            }
            if ($total > 0 && $total < $done) {
                throw new InvalidArgumentException('taskReconcileStep: итоговый total меньше done');
            }
            if (!is_string($oldCursor) || !is_string($newCursor) || hash_equals($oldCursor, $newCursor)) {
                throw new InvalidArgumentException('taskReconcileStep: applied обязан продвинуть cursor');
            }
            $t['done'] = $done;
            if (array_key_exists('total', $resolution)) $t['total'] = $total;
            $t['cursor'] = $resolution['cursor'];
        }
        $t['step'] = null;
        $t['reconciliation'] = [
            'key' => (string)($pending['key'] ?? ''),
            'outcome' => $outcome,
            'evidence' => $evidence,
            'at' => time(),
        ];
        $t['heartbeat'] = time();
        $t['error'] = '';
        if (!empty($t['cancel'])) {
            $t['status'] = 'cancelled';
            $t['finishedAt'] = time();
            $t['result'] = 'Остановлено после сверки unknown-step';
            $t['cancel'] = false;
        } else {
            $t['status'] = 'running';
            $t['finishedAt'] = 0;
        }
        $reconciled = true;
        return $t;
    });
    return $reconciled;
}

/**
 * Отметить порцию: сколько всего, сколько сделано, где остановились.
 * Зовёт рабочий после КАЖДОЙ порции — это и есть «продолжение после падения».
 *
 * @param string $attempt Метка попытки, выданная taskClaimStep(). Совпала —
 *        квитанция снимается: порция дошла до конца и её исход известен. Пустая
 *        или чужая — прогресс пишется, но квитанция остаётся: запоздавший ответ
 *        не имеет права объявить неизвестный исход завершённым.
 * @return bool продолжать ли: false = попросили отмену либо задача уже не наша
 */
function taskProgress(string $id, int $done, ?int $total = null, $cursor = null,
                      string $attempt = ''): bool {
    $go = false;
    taskStoreUpdate(static function (array $prev) use ($id, $done, $total, $cursor, $attempt, &$go): array {
        $t = array_merge(taskEmpty(), $prev);
        // Чужая задача (успели отменить и запустить новую) — свои цифры в неё не
        // пишем: иначе прогресс новой задачи прыгает назад.
        if ((string)$t['id'] !== $id || ($t['status'] ?? '') !== 'running') return $t;
        $pending = taskPendingStep($t);
        if ($attempt !== '' && $pending !== null && (string)$pending['attempt'] === $attempt) {
            $t['step'] = null;   // порция закрыта: её исход известен
        }
        // Счётчик только вперёд: повторная порция после ретрая иначе откатывала
        // бы полосу назад, и человек видел бы, что работа идёт в обратную сторону.
        $t['done'] = max((int)$t['done'], $done);
        if ($total !== null) $t['total'] = max(0, $total);
        if ($cursor !== null) $t['cursor'] = $cursor;
        $t['heartbeat'] = time();
        $go = empty($t['cancel']);
        return $t;
    });
    return $go;
}

/**
 * Разрешение на СЛЕДУЮЩУЮ порцию. Спрашивается ДО работы, а не после.
 *
 * Зачем отдельно от taskProgress(). Прежде рабочий узнавал об отмене только по
 * итогу уже сделанной порции: человек нажимал «Отменить» сразу после запуска, а
 * приложение всё равно успевало тронуть чужие сделки в портале — «отменил, а оно
 * всё равно сделало». Разрешение выдаётся под тем же замком, что и отмена,
 * поэтому между проверкой и решением вклиниться нельзя.
 *
 * КВИТАНЦИЯ. Разрешение и запись «порция начата» — одна и та же запись состояния:
 * иначе между ними есть окно, в котором процесс умирает уже с разрешением, но
 * ещё без следа. Метка попытки возвращается через $attempt — с ней рабочий потом
 * закрывает ИМЕННО свою квитанцию (taskProgress), а чужая отметка её не закроет.
 *
 * @param mixed        $cursor  Позиция, с которой пойдёт порция: попадает в квитанцию.
 * @param string|null &$attempt Метка выданной попытки (пусто, если не разрешено).
 * @return bool можно ли делать порцию: false = отмена, чужая задача, не running
 *         ЛИБО осталась незакрытая квитанция прошлой порции (её исход неизвестен,
 *         и повторять работу нельзя — решение принимает человек).
 */
function taskClaimStep(string $id, $cursor = null, ?string &$attempt = null): bool {
    $allow = false;
    $issued = '';
    taskStoreUpdate(static function (array $prev) use ($id, $cursor, &$allow, &$issued): array {
        $t = array_merge(taskEmpty(), $prev);
        if ((string)$t['id'] !== $id) return $t;              // задачу успели сменить
        if (($t['status'] ?? '') !== 'running') return $t;
        if (!empty($t['cancel'])) return $t;                  // попросили остановиться
        // ВЫПУСК СМЕНИЛСЯ ПОСРЕДИ РАБОТЫ. Дальше идти нельзя: вторая половина
        // работы пойдёт по другому коду, и это худший вид расхождения — снаружи
        // «отработало», а результат собран по двум разным правилам. Останавливаем
        // и говорим человеку прямо; продолжение — это новый запуск на новом коде.
        // Receipt важнее даже смены выпуска: новый код тоже не знает,
        // успел ли прежний внешний эффект. Сначала project read-back.
        if (taskPendingStep($t) !== null) return $t;
        if (taskReleaseChanged($t)) {
            $t['status'] = 'failed';
            $t['finishedAt'] = time();
            $t['error'] = 'Приложение обновилось, пока задача выполнялась. '
                . 'Работа остановлена на сделанном: продолжать на другом коде нельзя — '
                . 'вторая половина пошла бы по другим правилам. Запустите заново.';
            $t['step'] = null;   // задача терминальна — см. taskFinish()
            return $t;
        }
        $allow = true;
        // Квитанция начатой порции — В ТОЙ ЖЕ записи, что и разрешение.
        $issued = bin2hex(random_bytes(8));
        $t['step'] = [
            'attempt' => $issued,
            'key'     => taskStepKey($id, $cursor),
            'cursor'  => $cursor,
            'at'      => time(),
        ];
        // Признак жизни ставим здесь же: рабочий взялся за порцию, и до её конца
        // задачу нельзя считать брошенной.
        $t['heartbeat'] = time();
        return $t;
    });
    $attempt = $allow ? $issued : '';
    return $allow;
}

/**
 * Завершить: done | failed | cancelled. Причину отказа пишем человеческим языком.
 *
 * $counters — итоговые цифры для работы, которая уместилась в один шаг: порций
 * не было, писать цифры было некому, и человек видел «Готово · 0 из 0» — отчёт,
 * из которого не понять, сделано ли хоть что-то.
 */
function taskFinish(string $id, string $status, string $result = '', string $error = '',
                    array $counters = []): void {
    if (!in_array($status, ['done', 'failed', 'cancelled'], true)) {
        throw new InvalidArgumentException('taskFinish: неизвестный исход ' . $status);
    }
    taskStoreUpdate(static function (array $prev) use ($id, $status, $result, $error, $counters): array {
        $t = array_merge(taskEmpty(), $prev);
        if ((string)$t['id'] !== $id) return $t;   // задачу уже сменили — не трогаем
        // Цифры последнего шага. Как и в порции, счётчик только вперёд: повтор
        // завершения не должен уменьшать сделанное.
        if (array_key_exists('total', $counters)) $t['total'] = max(0, (int)$counters['total']);
        if (array_key_exists('done', $counters))  $t['done']  = max((int)$t['done'], (int)$counters['done']);
        // Успешное завершение подтягивает счётчик к итогу. Последняя порция
        // сообщает «всё» отдельным признаком и своих цифр уже не пишет — без
        // этого готовая задача оставалась на экране с полосой в 83%, и человек
        // видел «Готово» рядом с недоделанной работой.
        if ($status === 'done' && (int)$t['total'] > 0) $t['done'] = (int)$t['total'];
        $t['status'] = $status;
        $t['finishedAt'] = time();
        $t['heartbeat'] = time();
        $t['result'] = $result;
        $t['error'] = $error;
        $t['cancel'] = false;
        // Задача терминальна: держать квитанцию не за чем и опасно — она
        // пережила бы задачу и запретила первую порцию следующей. Повтор
        // прерванной работы после этого — осознанный запуск человеком заново.
        $t['step'] = null;
        return $t;
    });
}
