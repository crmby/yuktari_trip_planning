<?php
declare(strict_types=1);
/**
 * ДОЛГАЯ ЗАДАЧА: запуск, ход, отмена, продолжение после закрытия окна.
 *
 * ЗАЧЕМ. Любая массовая работа с порталом (перебрать сделки, выгрузить, разнести
 * поля) длится минуты, а окно приложения человек закрывает через двадцать секунд.
 * Каждый проект решал это по-своему и одинаково плохо: работа шла В ЗАПРОСЕ, и
 * закрытая вкладка обрывала её на середине — половина сделок обновлена, половина
 * нет, и никто не знает, где именно остановились.
 *
 * КАК ЗДЕСЬ. Экран только СТАВИТ задачу и читает её состояние. Считает фоновый
 * рабочий (`bin/process.php`) порциями, отмечая, сколько сделано и на чём
 * остановился. Поэтому:
 *   — закрытое окно ничего не обрывает: работа идёт на сервере;
 *   — открытое заново окно показывает актуальный ход, а не «запустите снова»;
 *   — отмена — это ФЛАГ, который рабочий проверяет между порциями, а не убийство
 *     процесса на полуслове;
 *   — падение сервера в середине не теряет прогресс: он записан после каждой
 *     порции, и следующий тик продолжает с той же позиции.
 *
 * GET  → {ok:true, task:{...}}                     состояние (кто угодно с доступом)
 * POST → {ok:true, task:{...}}  тело {"do":"start"|"cancel"|"forget"}   роль edit
 *        тело {"do":"resolve","id":…} — снять unknown после ручной проверки, роль admin
 *
 * Тело задачи проекта — в `bin/process.php`, функция `taskStep()`. Здесь только
 * состояние: что за задача, сколько сделано, чем кончилась.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/task-state.php';

installFailureHandler(true);

$ctx = apiBoot(['methods' => ['GET', 'POST'], 'role' => 'view']);

/**
 * Молчит ли планировщик. Считает задачу не экран, а фоновый рабочий, — значит
 * при незаведённом cron «Запустить» выглядит как успех, а работа не начинается
 * вовсе. Правда всплывала только через пять минут, когда задача становилась
 * «без признаков жизни», и человек к этому времени уже закрывал окно.
 *
 * Порог с запасом: cron ставят и раз в 15 минут, и раз в час. Час молчания —
 * это уже поломка, а не расписание.
 */
const TASK_CRON_SILENCE_SECONDS = 3600;

function taskCronSilence(): array {
    $state = storeRead(STATE_FILE) ?? [];
    $at = (string)($state['cron_last_run_at'] ?? '');
    if ($at === '') {
        return ['silent' => true,
            'hint' => 'Фоновая обработка ни разу не запускалась. Пока не заведено задание планировщика '
                . '(команду печатает деплой), задача будет числиться запущенной, но считать её некому.'];
    }
    $stamp = appIsoTimestamp($at);
    if ($stamp === null || $stamp < time() - TASK_CRON_SILENCE_SECONDS) {
        return ['silent' => true,
            'hint' => 'Фоновая обработка давно не отмечалась. Попросите администратора хостинга '
                . 'проверить задание планировщика в панели хостинга (команду печатает деплой) — '
                . 'без него задача не сдвинется с места.'];
    }
    return ['silent' => false, 'hint' => ''];
}

if ($ctx['method'] === 'GET') {
    apiOk(['task' => taskForClient(), 'cron' => taskCronSilence()]);
}

// Запуск и отмена меняют состояние — значит роль не ниже edit. Проверка ролью, а
// не «кнопки не видно»: фронт это подсказка, а не защита.
if (!roleAtLeast($ctx['role'], 'edit')) {
    apiFail('Запускать и отменять задачу может сотрудник с правом на изменения', 403, 'forbidden');
}

$body = apiBody();
$do = apiEnum($body, 'do', ['start', 'cancel', 'forget', 'resolve']);

// Снять unknown-step после ручной проверки (ux_sboev-1). Только администратор:
// это решение «портал проверен», а не обычная команда задаче, — как «Снять» у
// зависшей операции в api/ops.php. Номер задачи обязателен: CAS не даст снять
// блокировку у задачи, которую запустили уже после открытия экрана.
if ($do === 'resolve') {
    if (!roleAtLeast($ctx['role'], 'admin')) {
        apiFail('Снять блокировку после проверки может только администратор приложения', 403, 'forbidden');
    }
    $taskId = apiStr($body, 'id', ['max' => 80]);
    if (!taskResolveManually($taskId, (int)$ctx['uid'], 'проверено вручную через экран «Работа»')) {
        apiFail('Снимать нечего: у задачи нет порции с неизвестным исходом, или она ещё выполняется',
            409, 'task_not_unknown', '', ['task' => taskForClient()]);
    }
    auditLog('task.resolve', ['task' => $taskId], $ctx['uid']);
    apiOk(['task' => taskForClient(), 'cron' => taskCronSilence()]);
}

if ($do === 'start') {
    $started = taskStart((int)$ctx['uid']);
    if (!$started['ok']) {
        $currentTask = taskForClient();
        // Отдельный код, а не общий отказ: фронт по нему показывает уже идущую
        // задачу вместо ошибки — человек нажал «Запустить» дважды, это не сбой.
        $unknown = ($currentTask['status'] ?? '') === 'unknown';
        apiFail($started['error'], 409, $unknown ? 'task_reconciliation_required' : 'task_running',
            $unknown ? 'unknown-step требует project-specific read-back'
                : 'повторный запуск при активной задаче',
            ['task' => $currentTask]);
    }
    auditLog('task.start', ['task' => $started['task']['id'] ?? ''], $ctx['uid']);
    apiOk(['task' => taskForClient(), 'cron' => taskCronSilence()]);
}

if ($do === 'cancel') {
    $cancelled = taskCancel((int)$ctx['uid']);
    if (!$cancelled) apiFail('Отменять нечего: задача не выполняется', 409, 'task_idle');
    auditLog('task.cancel', [], $ctx['uid']);
    apiOk(['task' => taskForClient(), 'cron' => taskCronSilence()]);
}

// «Убрать с экрана» — только для завершённой задачи. Пока задача идёт, скрыть её
// нельзя: иначе человек прячет то, что продолжает менять данные в портале.
if (!taskForget()) {
    $currentTask = taskForClient();
    $unknown = ($currentTask['status'] ?? '') === 'unknown';
    apiFail($unknown ? 'Убрать unknown-step можно только после project-specific read-back'
            : 'Убрать можно только завершённую задачу',
        409, $unknown ? 'task_reconciliation_required' : 'task_running');
}
apiOk(['task' => taskForClient(), 'cron' => taskCronSilence()]);
