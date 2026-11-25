<?php
declare(strict_types=1);
/**
 * Долгая задача: запуск, ход, отмена, продолжение после падения.
 *
 * Проверяем не «функция есть», а поведение, ради которого блок сделан: два
 * одновременных запуска не создают две задачи, отмена срабатывает МЕЖДУ
 * порциями, прогресс не едет назад, а мёртвый рабочий не блокирует кнопку
 * навсегда.
 */

require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/www/api/task-state.php';
require_once dirname(__DIR__) . '/www/api/b24.php';   // установка/удаление — для проверок жизненного цикла

suite('Долгая задача');

// ── Пустое состояние ──────────────────────────────────────────────────────
@unlink(TASK_FILE);
$t = taskForClient();
check('без запусков задача показана как «не запускалась»',
    $t['status'] === 'idle' && $t['percent'] === 0 && $t['id'] === '');

// ── Запуск ────────────────────────────────────────────────────────────────
$started = taskStart(10);
check('запуск создаёт задачу с меткой и признаком «выполняется»',
    $started['ok'] === true && ($started['task']['id'] ?? '') !== ''
    && taskForClient()['status'] === 'running');
$id = (string)$started['task']['id'];

// ── Повторный запуск не создаёт вторую задачу ─────────────────────────────
$again = taskStart(11);
check('повторный запуск отвергается, метка остаётся прежней',
    $again['ok'] === false && taskForClient()['id'] === $id);

// ── Прогресс ──────────────────────────────────────────────────────────────
check('порция отмечается и считается в процентах',
    taskProgress($id, 5, 20, 5) === true
    && taskForClient()['done'] === 5 && taskForClient()['percent'] === 25);

// Повтор порции после ретрая не должен откатывать полосу назад.
taskProgress($id, 3, 20, 3);
check('счётчик сделанного не едет назад', taskForClient()['done'] === 5);

// ── Чужая метка не пишет в текущую задачу ─────────────────────────────────
taskProgress('чужая-метка', 19, 20, 19);
check('порция чужой задачи не подменяет прогресс текущей', taskForClient()['done'] === 5);

// ── Отмена ────────────────────────────────────────────────────────────────
check('отмена помечает задачу, а не обрывает порцию',
    taskCancel(10) === true && taskForClient()['cancelling'] === true
    && taskForClient()['status'] === 'running');
check('следующая порция узнаёт об отмене и просит остановиться',
    taskProgress($id, 6, 20, 6) === false);
taskFinish($id, 'cancelled', 'Остановлено по просьбе пользователя');
check('после отмены задача завершена с человеческой причиной',
    taskForClient()['status'] === 'cancelled'
    && taskForClient()['result'] === 'Остановлено по просьбе пользователя');

// ── Успешное завершение подтягивает счётчик к итогу ───────────────────────
// Последняя порция сообщает «всё» признаком, а не цифрами, и без подтяжки
// готовая задача висела на экране с полосой в 83%.
taskForget();
$fin = taskStart(10);
$finId = (string)$fin['task']['id'];
taskProgress($finId, 25, 30, 25);
taskFinish($finId, 'done', 'Обработано записей: 30');
check('готовая задача показывает полные 100%, а не остаток последней порции',
    taskForClient()['percent'] === 100 && taskForClient()['done'] === 30);
// Отмена и отказ счётчик НЕ подтягивают: работа действительно не доделана.
taskForget();
$part = taskStart(10);
$partId = (string)$part['task']['id'];
taskProgress($partId, 12, 30, 12);
taskFinish($partId, 'cancelled', 'Остановлено по просьбе пользователя');
check('остановленная задача честно показывает, сколько успела',
    taskForClient()['done'] === 12 && taskForClient()['percent'] === 40);
taskForget();

// ── ТЕРПЕНИЕ К МОЛЧАНИЮ РАБОЧЕГО ──────────────────────────────────────────
// Сколько ждать, прежде чем считать фоновую обработку умершей, шаблон не знает:
// расписание живёт в панели хостинга. Он его ЗАМЕРЯЕТ. Опасность в том, что один
// простой (хостинг чинили, задание выключили на сутки) не должен навсегда стать
// «нормальным тактом»: иначе мёртвый рабочий будет определяться трое суток, а
// человек всё это время видит «выполняется» и не может запустить заново.
$rovno = [];
$ts = 1000000;
for ($i = 0; $i < 5; $i++) { $rovno = cronTickRemember($rovno, $ts); $ts += 900; }
check('такт замерен по наблюдениям, а не задан наугад',
    cronTickSeconds($rovno) === 900, (string)cronTickSeconds($rovno));
$normal = taskStaleSecondsFor($rovno);
check('при такте в 15 минут живая задача между тиками не считается мёртвой',
    $normal >= 900 && $normal <= 3600, (string)$normal);

$posleProstoya = cronTickRemember($rovno, $ts + 23 * 3600);   // сутки простоя
for ($i = 0; $i < 2; $i++) { $ts += 900; $posleProstoya = cronTickRemember($posleProstoya, $ts + 23 * 3600 + 900 * ($i + 1)); }
$after = taskStaleSecondsFor($posleProstoya);
check('ОДИН ПРОСТОЙ НЕ ДЕЛАЕТ ТЕРПЕНИЕ ВЕЧНЫМ',
    $after <= $normal * 2, 'было ' . $normal . ' сек, стало ' . $after . ' сек');
check('терпение к молчанию в любом случае ограничено сверху',
    taskStaleSecondsFor(['cron_tick_seconds' => 86400]) <= 6 * 3600,
    (string)taskStaleSecondsFor(['cron_tick_seconds' => 86400]));
check('и не опускается ниже разумного минимума',
    taskStaleSecondsFor([]) >= TASK_STALE_SECONDS, (string)taskStaleSecondsFor([]));

// ── ОТМЕНА ДО ПЕРВОЙ ПОРЦИИ ───────────────────────────────────────────────
// Человек нажал «Отменить» сразу после запуска — до того, как крон вообще взялся
// за работу. Порция при этом НЕ должна выполниться: она меняет данные в портале,
// и «отменил, а оно всё равно сделало» — это чужие сделки, тронутые вопреки
// прямому отказу. Рабочий обязан спросить разрешение ДО порции, а не после.
taskForget();
$claim = taskStart(10);
$claimId = (string)$claim['task']['id'];
check('до отмены рабочему разрешено делать порцию', taskClaimStep($claimId) === true);
taskCancel(10);
check('ОТМЕНА ДО ПЕРВОЙ ПОРЦИИ ЗАПРЕЩАЕТ ЕЁ ВЫПОЛНЯТЬ', taskClaimStep($claimId) === false);
check('чужая задача разрешения тоже не получает', taskClaimStep('чужая-метка') === false);
taskFinish($claimId, 'cancelled', 'Остановлено по просьбе пользователя');

// ── ОДНОШАГОВАЯ ЗАДАЧА ────────────────────────────────────────────────────
// Работа уместилась в один шаг: порций не было, а значит и цифр никто не писал.
// Человек видел «готово · 0 из 0» — то есть отчёт, из которого не понять, сделано
// ли хоть что-нибудь.
taskForget();
$one = taskStart(10);
$oneId = (string)$one['task']['id'];
taskFinish($oneId, 'done', 'Обработано записей: 7', '', ['done' => 7, 'total' => 7]);
$oneView = taskForClient();
check('ОДНОШАГОВАЯ ЗАДАЧА ПОКАЗЫВАЕТ ЧЕСТНЫЕ ЦИФРЫ, А НЕ 0 ИЗ 0',
    $oneView['done'] === 7 && $oneView['total'] === 7 && $oneView['percent'] === 100,
    json_encode($oneView, JSON_UNESCAPED_UNICODE));
taskForget();

// ── Завершённую можно убрать, идущую — нет ────────────────────────────────
check('завершённую задачу можно убрать с экрана',
    taskForget() === true && taskForClient()['status'] === 'idle');
$run = taskStart(10);
check('идущую задачу убрать нельзя', taskForget() === false);
$runId = (string)$run['task']['id'];

// ── Мёртвый рабочий не блокирует кнопку навсегда ──────────────────────────
// Предохранитель обязан иметь выход: без этого падение процесса означало бы
// «уже выполняется» до конца времён, и починить это мог бы только разработчик
// руками на сервере.
storeUpdate(TASK_FILE, static function (array $prev): array {
    $prev['heartbeat'] = time() - TASK_STALE_SECONDS - 5;
    return $prev;
});
check('задача без признаков жизни показана отдельным состоянием, а не «выполняется»',
    taskForClient()['status'] === 'stalled');
$afterStale = taskStart(10);
check('после смерти рабочего задачу можно запустить заново',
    $afterStale['ok'] === true && $afterStale['task']['id'] !== $runId);
$freshId = (string)$afterStale['task']['id'];

// ── Отказ шага завершает задачу, а не оставляет её вечной ─────────────────
taskFinish($freshId, 'failed', '', 'Задача остановлена из-за ошибки. Подробности — в журнале приложения.');
$failed = taskForClient();
check('отказ завершает задачу с причиной, понятной человеку',
    $failed['status'] === 'failed' && strpos($failed['error'], 'журнале') !== false);
check('внутренности исключения наружу не уходят',
    strpos($failed['error'], 'Exception') === false && strpos($failed['error'], '.php') === false);

// ── Гонка: два процесса жмут «Запустить» одновременно ─────────────────────
// Рассуждением такое не проверяется — запускаем ВТОРОЙ процесс.
taskForget();
$helper = sys_get_temp_dir() . '/bxapp-task-race-' . getmypid() . '.php';
file_put_contents($helper, "<?php\nrequire " . var_export(dirname(__DIR__) . '/www/api/task-state.php', true)
    . ";\n\$r = taskStart(99);\necho \$r['ok'] ? 'STARTED:' . \$r['task']['id'] : 'REFUSED';\n");
$descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procs = [];
for ($i = 0; $i < 2; $i++) {
    $procs[$i] = proc_open(['php', $helper], $descr, $pipes[$i], null,
        ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')]);
}
$out = [];
foreach ($procs as $i => $p) {
    if (!is_resource($p)) continue;
    $out[] = trim((string)stream_get_contents($pipes[$i][1]));
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    proc_close($p);
}
@unlink($helper);
$startedCount = count(array_filter($out, static fn($o): bool => strpos($o, 'STARTED:') === 0));
check('два одновременных запуска создают ровно одну задачу',
    count($out) === 2 && $startedCount === 1, implode(' | ', $out));

// ── Задача принадлежит УСТАНОВКЕ, а не серверу ────────────────────────────
// Незавершённая задача несёт курсор: «дошли до записи №12 этого портала».
// Пережив удаление приложения, она заставляла фонового рабочего СЛЕДУЮЩЕЙ
// установки продолжить чужую работу с середины — на данных другого портала, — а
// экран показывал «Выполняется» у задачи, которую здесь никто не запускал.
taskForget();
@unlink(TASK_FILE);
$lifecycle = new B24('local.test.app', 'secret');
$lifecycle->saveTokensFromInstall([
    'AUTH_ID' => 'acc', 'REFRESH_ID' => 'ref', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal.invalid', 'member_id' => 'M-TASK', 'PROTOCOL' => 'https',
    'APPLICATION_TOKEN' => 'key-task',
]);
$generation = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
$live = taskStart(7);
$liveId = (string)$live['task']['id'];
taskProgress($liveId, 12, 30, 12);
$beforePurge = taskForClient();
appPurgeInstallState($generation);
$afterPurge = taskForClient();
check('удаление приложения с портала уносит и незавершённую задачу',
    $beforePurge['status'] === 'running' && $beforePurge['done'] === 12
    && $afterPurge['status'] === 'idle' && $afterPurge['id'] === ''
    && (storeRead(TASK_FILE)['cursor'] ?? null) === null,
    json_encode($afterPurge, JSON_UNESCAPED_UNICODE));

// Смена портала — та же беда: курсор указывает на записи ПРЕЖНЕГО портала.
check('перенос приложения на другой портал очищает задачу вместе с состоянием',
    array_key_exists(TASK_FILE, appPortalBoundResetTargets()));

// А перевыпуск регистрации на ТОМ ЖЕ портале задачу сохраняет намеренно: данные
// те же, курсор верен, и работа честно продолжается с той же позиции.
check('перевыпуск регистрации на том же портале задачу не теряет',
    !array_key_exists(TASK_FILE, appRegistrationBoundResetTargets()));

// Файл задачи — служебный: объявить его в APP_PURGE_EXTRA проект не может, иначе
// уборка задела бы его дважды и мимо своего протокола.
$extraRejected = false;
try {
    appNormalizePurgeExtraFiles([TASK_FILE]);
} catch (Throwable $e) { $extraRejected = true; }
check('файл задачи защищён от повторной очистки через APP_PURGE_EXTRA', $extraRejected);

// ── Бюджет порций не блокирует установку ──────────────────────────────────
// Весь бюджет задачи проходит внутри общего замка установки, а тот, кто ставит
// или удаляет приложение, ждёт эксклюзивный замок 25 секунд и дальше показывает
// человеку «Приложение обновляется». Больший бюджет означал бы, что идущая
// задача раз в минуту делает переустановку невозможной.
$processSource = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
$libSource = (string)file_get_contents(dirname(__DIR__) . '/www/api/lib.php');
preg_match('/function appWithLifecycleWriteLock\(callable \$work, float \$timeout = ([0-9.]+)\)/',
    $libSource, $writerTimeout);
$writerWait = isset($writerTimeout[1]) ? (float)$writerTimeout[1] : 0.0;
check('бюджет порций задачи заведомо меньше ожидания установки',
    $writerWait > 0 && TASK_TICK_BUDGET_SECONDS < $writerWait
    && strpos($processSource, 'TASK_TICK_BUDGET_SECONDS') !== false,
    'бюджет ' . TASK_TICK_BUDGET_SECONDS . ' с при ожидании установки ' . $writerWait . ' с');

// ── Живая задача не должна выглядеть мёртвой между тиками планировщика ──────
// Шаблон сам допускает крон раз в 15 минут (и печатает такую строку при
// выкладке). При жёстких пяти минутах живая задача МЕЖДУ тиками показывалась
// как «нет признаков жизни», а экран предлагал запустить её заново — то есть
// пройти по порталу второй раз ту же работу, которую фон в это время доделывал.
taskForget();
@unlink(TASK_FILE);
storeWrite(STATE_FILE, ['cron_tick_seconds' => 900]);   // планировщик раз в 15 минут
$slow = taskStart(5);
$slowId = (string)$slow['task']['id'];
storeUpdate(TASK_FILE, static function (array $prev): array {
    $prev['heartbeat'] = time() - 600;                  // 10 минут молчания
    return $prev;
});
check('при кроне раз в 15 минут пауза в 10 минут не считается смертью',
    taskForClient()['status'] === 'running' && taskStaleSeconds() === 2700);

// А вот молчание дольше нескольких тактов — уже настоящая смерть рабочего.
storeUpdate(TASK_FILE, static function (array $prev): array {
    $prev['heartbeat'] = time() - 3000;
    return $prev;
});
check('молчание дольше нескольких тактов по-прежнему считается смертью',
    taskForClient()['status'] === 'stalled');

// Такт неизвестен (планировщик ещё ни разу не отмечался) — работает прежний
// нижний порог, иначе первая же задача висела бы «выполняется» бесконечно.
storeWrite(STATE_FILE, []);
check('без известного такта действует прежний нижний порог',
    taskStaleSeconds() === TASK_STALE_SECONDS);
taskForget();
@unlink(TASK_FILE);

// ── ВЫПУСК СМЕНИЛСЯ ПОСРЕДИ ЗАДАЧИ ──────────────────────────────────────────
// Долгая задача переживает выкладку: она идёт часами, код за это время меняется.
// Доделать вторую половину на новом коде — значит собрать результат по двум
// разным правилам, и снаружи это выглядит как «отработало».
storeWrite(TASK_FILE, []);
$zapusk = taskStart(7);
check('у запущенной задачи записан выпуск, на котором она начата',
    (string)($zapusk['task']['release'] ?? '') !== '',
    json_encode($zapusk['task'] ?? [], JSON_UNESCAPED_UNICODE));
$idZadachi = (string)($zapusk['task']['id'] ?? '');
$releaseAttempt = '';
check('порция разрешена, пока выпуск тот же',
    taskClaimStep($idZadachi, null, $releaseAttempt) === true);
// Эта порция завершена: проверка ниже изолированно стережёт смену
// release. Unknown-receipt имеет отдельные тесты и всегда важнее release.
taskProgress($idZadachi, 0, 0, null, $releaseAttempt);

// Подменяем записанный выпуск — ровно это и происходит при выкладке.
storeUpdate(TASK_FILE, static function (array $t): array {
    $t['release'] = 'выпуск-которого-больше-нет';
    return $t;
});
check('ПОСЛЕ СМЕНЫ ВЫПУСКА ПОРЦИЯ НЕ РАЗРЕШАЕТСЯ', taskClaimStep($idZadachi) === false);
$posle = taskRead();
check('задача остановлена, а не висит «выполняется»',
    ($posle['status'] ?? '') === 'failed', (string)($posle['status'] ?? ''));
check('человеку сказано, что именно случилось и что делать',
    mb_strpos((string)($posle['error'] ?? ''), 'обновилось') !== false
    && mb_strpos((string)($posle['error'] ?? ''), 'заново') !== false,
    (string)($posle['error'] ?? ''));

// Старая задача без метки выпуска не должна падать: сравнивать не с чем, и
// предупреждение без основания хуже молчания.
storeWrite(TASK_FILE, []);
$staraya = taskStart(7);
storeUpdate(TASK_FILE, static function (array $t): array { $t['release'] = ''; return $t; });
check('задача без записанного выпуска работает как прежде',
    taskClaimStep((string)($staraya['task']['id'] ?? '')) === true);

// ── Отметка «планировщик жив» появляется В НАЧАЛЕ тика ──────────────────────
//
// Экран задачи предупреждает «Фоновая обработка ни разу не запускалась», пока в
// состоянии нет времени запуска. Пока отметка ставилась в КОНЦЕ тика, задача
// успевала опубликовать свой исход раньше — и человек видел «Остановлено» вместе
// с предупреждением, что обработка никогда не запускалась. Опрос экрана к этому
// моменту уже прекращён (задача в терминальном состоянии), поэтому ложь висела до
// перезагрузки страницы. Проверяем по исходнику воркера: порядок здесь и есть
// починка, а живой сценарий её подтверждает отдельно (tests/task-live.js).
$workerSrc = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
$posStart  = strpos($workerSrc, 'cronMarkStarted()');
$posJob    = strpos($workerSrc, 'runJob();');
$posRun    = strpos($workerSrc, 'cronMarkRun($cronOk');
check('ОТМЕТКА О ЗАПУСКЕ ПЛАНИРОВЩИКА СТАВИТСЯ ДО РАБОТЫ, А НЕ ПОСЛЕ',
    $posStart !== false && $posJob !== false && $posStart < $posJob,
    'отметка на ' . var_export($posStart, true) . ', работа на ' . var_export($posJob, true));
check('исход тика по-прежнему пишется в конце', $posRun !== false && $posRun > $posJob);
check('ИСХОД НЕ ВЫДАЁТСЯ ЗА ФАКТ ЗАРАНЕЕ: в начале тика успех не проставляется',
    strpos($workerSrc, 'function cronMarkStarted') !== false
    && strpos(substr($workerSrc, strpos($workerSrc, 'function cronMarkStarted'), 600), "cron_last_ok") === false);
// Такт замеряется РОВНО В ОДНОМ месте: два замера за тик превратили бы
// «раз в 15 минут» в «раз в секунду», и молчащий планировщик перестал бы
// отличаться от живого.
check('такт планировщика замеряется один раз за тик',
    substr_count($workerSrc, 'cronTickRemember($s, $now)') === 1,
    'вхождений: ' . substr_count($workerSrc, 'cronTickRemember($s, $now)'));

// ── ЖЁСТКОЕ ПАДЕНИЕ ПОСРЕДИ ПОРЦИИ (находка ревью R3-003, 09.08.2026) ────────
//
// Порция уже изменила портал, а отметить прогресс процесс не успел: OOM, kill
// -9, упавший хостинг. Обычный Throwable ловится и задача честно завершается —
// жёсткая смерть не ловится ничем. Прежде состояние после неё было НЕОТЛИЧИМО
// от «порция не начиналась»: следующий тик брал тот же курсор и делал ту же
// работу в портале второй раз. Убиваем процесс по-настоящему (SIGKILL) — на
// рассуждениях такое не проверяется.
$killerSrc = "<?php\ndeclare(strict_types=1);\n"
    . "putenv('BXAPP_DATA_ROOT=' . \$argv[1]);\n"
    . "require '" . dirname(__DIR__) . "/www/env.php';\n"
    . "require '" . dirname(__DIR__) . "/www/api/store.php';\n"
    . "require '" . dirname(__DIR__) . "/www/api/task-state.php';\n"
    . "\$attempt = '';\n"
    . "if (!taskClaimStep((string)\$argv[2], 'позиция-7', \$attempt)) exit(3);\n"
    . "file_put_contents(\$argv[3], 'эффект', FILE_APPEND);\n"   // порция тронула портал
    . "posix_kill(getmypid(), SIGKILL);\n";                       // и умерла до отметки прогресса
$killerFile  = sys_get_temp_dir() . '/bxapp-task-kill-' . bin2hex(random_bytes(4)) . '.php';
$effectFile  = sys_get_temp_dir() . '/bxapp-task-effect-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($killerFile, $killerSrc);
storeWrite(TASK_FILE, []);
$hard   = taskStart(3);
$hardId = (string)($hard['task']['id'] ?? '');
$desc   = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$killed = proc_open(['php', $killerFile, DATA_ROOT, $hardId, $effectFile], $desc, $killPipes,
    dirname(__DIR__), ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')]);
if (is_resource($killed)) {
    fclose($killPipes[0]);
    stream_get_contents($killPipes[1]);
    $killErr = (string)stream_get_contents($killPipes[2]);
    fclose($killPipes[1]); fclose($killPipes[2]);
    proc_close($killed);
} else {
    $killErr = 'процесс не запущен';
}
$hardState = taskRead();
check('порция успела тронуть портал до смерти процесса',
    is_file($effectFile) && (string)file_get_contents($effectFile) === 'эффект', $killErr);
check('ЖЁСТКАЯ СМЕРТЬ ОСТАВЛЯЕТ КВИТАНЦИЮ НАЧАТОЙ ПОРЦИИ',
    is_array($hardState['step'] ?? null) && ($hardState['step']['attempt'] ?? '') !== '',
    'в состоянии: ' . json_encode($hardState['step'] ?? null, JSON_UNESCAPED_UNICODE));
check('НЕЗАКРЫТАЯ КВИТАНЦИЯ НЕ ДАЁТ СДЕЛАТЬ ТУ ЖЕ ПОРЦИЮ ВТОРОЙ РАЗ',
    taskClaimStep($hardId, 'позиция-7') === false);

// Heartbeat и receipt — разные сигналы. Даже если worker уже мёртв,
// pending receipt доказывает, что внешний эффект мог успеть. Обычные
// start/forget не имеют права стирать это доказательство.
storeUpdate(TASK_FILE, static function (array $t): array {
    $t['heartbeat'] = time() - taskStaleSeconds() - 5;
    return $t;
});
$stalePendingBefore = taskRead();
$stalePendingAttempt = (string)($stalePendingBefore['step']['attempt'] ?? '');
$stalePendingStart = taskStart(77);
check('STALE С PENDING STEP НЕ РАЗРЕШАЕТ ОБЫЧНЫЙ START',
    ($stalePendingStart['ok'] ?? true) === false
    && (string)(taskRead()['id'] ?? '') === $hardId
    && (string)(taskRead()['step']['attempt'] ?? '') === $stalePendingAttempt);
check('STALE С PENDING STEP НЕ РАЗРЕШАЕТ ОБЫЧНЫЙ FORGET',
    taskForget() === false
    && (string)(taskRead()['id'] ?? '') === $hardId
    && (string)(taskRead()['step']['attempt'] ?? '') === $stalePendingAttempt);
check('STALE С PENDING STEP ВИДЕН КЛИЕНТУ КАК MACHINE-READABLE UNKNOWN',
    taskForClient()['status'] === 'unknown');
// Изолируем следующую прежнюю регрессию от результата этих трёх проверок:
// в RED-версии start/forget уже успели заменить состояние.
storeWrite(TASK_FILE, $stalePendingBefore);
// Поздняя отметка чужой попытки не имеет права закрыть чужую квитанцию: иначе
// достаточно одного запоздавшего ответа, чтобы неизвестный исход стал «сделано».
taskProgress($hardId, 9, 30, 'позиция-9', 'чужая-попытка');
check('поздняя отметка чужой попытки не снимает квитанцию',
    is_array(taskRead()['step'] ?? null));
// Предохранитель обязан выпускать: запуск заново начинает с чистого состояния.
storeWrite(TASK_FILE, []);
$again2 = taskStart(3);
check('запуск заново начинается без чужой квитанции',
    ($again2['ok'] ?? false) === true && (taskRead()['step'] ?? null) === null);
@unlink($killerFile);
@unlink($effectFile);

// Курсор проекта — произвольные данные, и он бывает нечитаемым для JSON (битая
// строка из портала). Ключ шага обязан получаться всё равно: без него порция
// осталась бы без имени, а с ним — без защиты от повтора.
$brokenKey = taskStepKey('задача-1', "\xB1\x31");
check('ключ шага получается даже на нечитаемом курсоре',
    strpos($brokenKey, 'задача-1:') === 0 && strlen($brokenKey) > strlen('задача-1:'),
    $brokenKey);

// ── Тот же обрыв глазами фоновой обработки: полный тик воркера ───────────────
// Проверяем не механику, а то, что видит человек: задача не делает работу
// второй раз, останавливается и говорит, почему.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1',
    'client_id' => 'local.test.app', 'expires_at' => time() + 3600,
]);
$runWorker = static function (): int {
    $env = [
        'BXAPP_DATA_ROOT'     => DATA_ROOT,
        'BXAPP_OAUTH_URL'     => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID'     => 'local.test.app',
        'BXAPP_CLIENT_SECRET' => 'test-secret',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', dirname(__DIR__) . '/www/bin/process.php'], $desc, $pipes,
        dirname(__DIR__), $env);
    if (!is_resource($proc)) return 255;
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($proc);
};

storeWrite(TASK_FILE, []);
$e2eOk = taskStart(10);
$runWorker();
$doneState = taskRead();
check('фоновая обработка доводит обычную задачу до конца',
    ($doneState['status'] ?? '') === 'done' && (int)($doneState['done'] ?? 0) === 30,
    ($doneState['status'] ?? '') . ' · ' . (int)($doneState['done'] ?? 0));

storeWrite(TASK_FILE, []);
$e2eCrash = taskStart(10);
$crashId = (string)($e2eCrash['task']['id'] ?? '');
storeUpdate(TASK_FILE, static function (array $t) use ($crashId): array {
    $t['done'] = 5;
    $t['total'] = 30;
    $t['cursor'] = 5;
    // Ровно то, что оставляет убитый посреди работы процесс: порция начата,
    // отметки о её завершении нет.
    $t['step'] = ['attempt' => 'умерший-процесс', 'key' => $crashId . ':5',
                  'cursor' => 5, 'at' => time() - 30];
    return $t;
});
$runWorker();
$crashState = taskRead();
check('ПОСЛЕ ОБРЫВА ПОРЦИИ ФОН НЕ ДЕЛАЕТ ЕЁ ВТОРОЙ РАЗ',
    ($crashState['status'] ?? '') === 'unknown'
    && is_array($crashState['step'] ?? null)
    && (int)($crashState['done'] ?? 0) === 5,
    ($crashState['status'] ?? '') . ' · сделано ' . (int)($crashState['done'] ?? 0));
check('человеку сказано, что исход порции неизвестен и что делать',
    mb_strpos((string)($crashState['error'] ?? ''), 'неизвестно') !== false
    && mb_strpos((string)($crashState['error'] ?? ''), 'read-back') !== false,
    (string)($crashState['error'] ?? ''));

// Временно неубедительный read-back не должен превращать unknown в вечный deadlock.
// Первый tick выше уже вернул default null и оставил receipt. На следующем tick
// project hook получает свежее доказательство; движок должен снова вызвать hook
// для persisted unknown, CAS-ом снять ТОТ ЖЕ receipt и только потом продолжить.
$retryHookFile = sys_get_temp_dir() . '/bxapp-task-reconcile-hook-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($retryHookFile, "<?php\n\$GLOBALS['__task_reconcile_test_resolution'] = "
    . "['outcome'=>'not_applied','evidence'=>'synthetic fresh read-back: effect absent'];\n");
$retryEnv = [
    'BXAPP_DATA_ROOT' => DATA_ROOT,
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app',
    'BXAPP_CLIENT_SECRET' => 'test-secret',
    'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
];
$retryProc = proc_open(['php', '-d', 'auto_prepend_file=' . $retryHookFile,
    dirname(__DIR__) . '/www/bin/process.php'], $desc, $retryPipes, dirname(__DIR__), $retryEnv);
if (is_resource($retryProc)) {
    fclose($retryPipes[0]);
    stream_get_contents($retryPipes[1]);
    $retryErr = (string)stream_get_contents($retryPipes[2]);
    fclose($retryPipes[1]); fclose($retryPipes[2]);
    $retryExit = proc_close($retryProc);
} else {
    $retryErr = 'процесс не запущен';
    $retryExit = 255;
}
$afterRetryHook = taskRead();
check('PERSISTED UNKNOWN ПОВТОРНО ВЫЗЫВАЕТ PROJECT READ-BACK НА СЛЕДУЮЩЕМ TICK',
    ($afterRetryHook['reconciliation']['evidence'] ?? '')
        === 'synthetic fresh read-back: effect absent',
    'exit ' . $retryExit . ' · ' . $retryErr);
check('NULL FIRST, RESOLVED NEXT TICK: CAS СНИМАЕТ RECEIPT И ТОЛЬКО ПОТОМ ПРОДОЛЖАЕТ',
    ($afterRetryHook['status'] ?? '') === 'done'
    && ($afterRetryHook['step'] ?? null) === null
    && ($afterRetryHook['reconciliation']['outcome'] ?? '') === 'not_applied',
    json_encode($afterRetryHook, JSON_UNESCAPED_UNICODE));
@unlink($retryHookFile);

// Обычный Throwable ловится worker-ом, но он так же может возникнуть
// после внешнего эффекта. Точка отказа сначала пишет synthetic effect,
// затем бросает исключение строго после taskClaimStep().
$throwPrepend = sys_get_temp_dir() . '/bxapp-task-throw-' . bin2hex(random_bytes(4)) . '.php';
$throwEffect = sys_get_temp_dir() . '/bxapp-task-throw-effect-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($throwPrepend, "<?php\n\$GLOBALS['__failpoints']['task.after_claim'] = "
    . "static function (): void { file_put_contents((string)getenv('BXAPP_TASK_EFFECT_FILE'), 'effect'); "
    . "throw new RuntimeException('synthetic task throwable'); };\n");
storeWrite(TASK_FILE, []);
$throwTask = taskStart(10);
$throwId = (string)($throwTask['task']['id'] ?? '');
$throwEnv = [
    'BXAPP_DATA_ROOT' => DATA_ROOT,
    'BXAPP_TASK_EFFECT_FILE' => $throwEffect,
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app',
    'BXAPP_CLIENT_SECRET' => 'test-secret',
    'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
];
$throwProc = proc_open(['php', '-d', 'auto_prepend_file=' . $throwPrepend,
    dirname(__DIR__) . '/www/bin/process.php'], $desc, $throwPipes, dirname(__DIR__), $throwEnv);
if (is_resource($throwProc)) {
    fclose($throwPipes[0]);
    stream_get_contents($throwPipes[1]);
    $throwErr = (string)stream_get_contents($throwPipes[2]);
    fclose($throwPipes[1]); fclose($throwPipes[2]);
    $throwExit = proc_close($throwProc);
} else {
    $throwErr = 'процесс не запущен';
    $throwExit = 255;
}
$throwState = taskRead();
check('THROWABLE ПОСЛЕ CLAIM СОХРАНЯЕТ SYNTHETIC EFFECT',
    is_file($throwEffect) && (string)file_get_contents($throwEffect) === 'effect',
    'exit ' . $throwExit . ' · ' . $throwErr);
check('THROWABLE ПОСЛЕ CLAIM ОСТАВЛЯЕТ DURABLE UNKNOWN RECEIPT',
    ($throwState['status'] ?? '') === 'unknown'
    && (string)($throwState['id'] ?? '') === $throwId
    && is_array($throwState['step'] ?? null)
    && (string)($throwState['step']['attempt'] ?? '') !== '',
    json_encode($throwState, JSON_UNESCAPED_UNICODE));

// Project-hook — обычный код проекта, поэтому опечатка в его outcome не должна
// уничтожать единственную unknown-квитанцию. Схема проверяется до записи, а
// продвижение — повторно под CAS-lock относительно текущего state.
$malformedAttempt = (string)($throwState['step']['attempt'] ?? '');
$malformedBase = $throwState;
$malformedBase['done'] = 5;
$malformedBase['total'] = 30;
$malformedBase['cursor'] = 5;
$malformedBase['step']['cursor'] = 5;
$malformedCases = [
    'строковые done/total' => ['done' => '6', 'total' => '30', 'cursor' => 6],
    'отрицательный done' => ['done' => -1, 'total' => 30, 'cursor' => 6],
    'total меньше done' => ['done' => 6, 'total' => 5, 'cursor' => 6],
    'done не продвинулся' => ['done' => 5, 'total' => 30, 'cursor' => 6],
    'cursor не продвинулся' => ['done' => 6, 'total' => 30, 'cursor' => 5],
];
foreach ($malformedCases as $caseName => $badResolution) {
    storeWrite(TASK_FILE, $malformedBase);
    $badResolution['evidence'] = 'synthetic malformed read-back';
    $wasRejected = false;
    try {
        taskReconcileStep($throwId, $malformedAttempt, 'applied', $badResolution);
    } catch (InvalidArgumentException $e) {
        $wasRejected = true;
    }
    $afterMalformed = taskRead();
    check('MALFORMED APPLIED ОТКЛОНЁН И UNKNOWN RECEIPT СОХРАНЁН: ' . $caseName,
        $wasRejected && $afterMalformed === $malformedBase,
        json_encode($afterMalformed, JSON_UNESCAPED_UNICODE));
}

storeWrite(TASK_FILE, $malformedBase);
$validApplied = taskReconcileStep($throwId, $malformedAttempt, 'applied', [
    'done' => 6, 'total' => 30, 'cursor' => 6,
    'evidence' => 'synthetic fresh read-back: effect applied',
]);
$afterValidApplied = taskRead();
check('ВАЛИДНЫЙ APPLIED ПРОДВИГАЕТ PROGRESS И СНИМАЕТ RECEIPT',
    $validApplied
    && ($afterValidApplied['status'] ?? '') === 'running'
    && (int)($afterValidApplied['done'] ?? 0) === 6
    && (int)($afterValidApplied['total'] ?? 0) === 30
    && (int)($afterValidApplied['cursor'] ?? 0) === 6
    && ($afterValidApplied['step'] ?? null) === null);
storeWrite(TASK_FILE, $throwState);

// Reconciliation делает свежий project-specific read-back ВНЕ store-lock,
// а затем применяет его вывод CAS-ом по task id + attempt. Пока read-back идёт,
// обычный start не может вытеснить receipt; запоздавший CAS не трогает
// более новую задачу.
$hasReconcile = function_exists('taskReconcileStep');
check('ДЛЯ UNKNOWN STEP ЕСТЬ ЯВНЫЙ CAS RECONCILIATION CONTRACT', $hasReconcile);
if ($hasReconcile) {
    $unknownAttempt = (string)($throwState['step']['attempt'] ?? '');
    $reconcileHelper = sys_get_temp_dir() . '/bxapp-task-reconcile-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($reconcileHelper, "<?php\ndeclare(strict_types=1);\n"
        . "putenv('BXAPP_DATA_ROOT=' . \$argv[1]);\n"
        . "require " . var_export(dirname(__DIR__) . '/www/api/task-state.php', true) . ";\n"
        . "usleep(150000);\n"
        . "echo taskReconcileStep(\$argv[2], \$argv[3], 'not_applied', "
        . "['evidence' => 'synthetic fresh read-back: effect absent']) ? 'RECONCILED' : 'CONFLICT';\n");
    $reconcileProc = proc_open(['php', $reconcileHelper, DATA_ROOT, $throwId, $unknownAttempt],
        $descr, $reconcilePipes, dirname(__DIR__), ['BXAPP_DATA_ROOT' => DATA_ROOT,
            'PATH' => (string)getenv('PATH')]);
    $startDuringReadBack = taskStart(88);
    if (is_resource($reconcileProc)) {
        $reconcileOut = trim((string)stream_get_contents($reconcilePipes[1]));
        fclose($reconcilePipes[1]); fclose($reconcilePipes[2]);
        proc_close($reconcileProc);
    } else {
        $reconcileOut = 'NOT_STARTED';
    }
    check('CONCURRENT START НЕ СТИРАЕТ RECEIPT ВО ВРЕМЯ READ-BACK',
        ($startDuringReadBack['ok'] ?? true) === false && $reconcileOut === 'RECONCILED');

    taskFinish($throwId, 'cancelled', 'Сверено: эффекта не было');
    $newTask = taskStart(89);
    $newId = (string)($newTask['task']['id'] ?? '');
    $newAttempt = '';
    taskClaimStep($newId, 'новая-позиция', $newAttempt);
    $beforeLateCas = taskRead();
    $lateCas = taskReconcileStep($throwId, $unknownAttempt, 'not_applied',
        ['evidence' => 'stale synthetic read-back']);
    $afterLateCas = taskRead();
    check('ЗАПОЗДАВШИЙ RECONCILE НЕ СТИРАЕТ RECEIPT НОВОЙ ЗАДАЧИ',
        $lateCas === false
        && (string)($afterLateCas['id'] ?? '') === $newId
        && (string)($afterLateCas['step']['attempt'] ?? '') === $newAttempt
        && (string)($beforeLateCas['step']['attempt'] ?? '') === $newAttempt);
    taskFinish($newId, 'cancelled', 'Тест завершён');
    @unlink($reconcileHelper);
} else {
    check('CONCURRENT START/RECONCILE СОХРАНЯЕТ RECEIPT', false,
        'taskReconcileStep не реализован');
    check('ЗАПОЗДАВШИЙ RECONCILE НЕ СТИРАЕТ RECEIPT НОВОЙ ЗАДАЧИ', false,
        'taskReconcileStep не реализован');
}

$taskUiSource = (string)file_get_contents(dirname(__DIR__) . '/www/js/app.js');
check('UI UNKNOWN НЕ ПРЕДЛАГАЕТ ОБЫЧНЫЙ RESTART/FORGET',
    strpos($taskUiSource, "t.status === 'unknown'") !== false
    && strpos($taskUiSource, "['idle', 'done', 'failed', 'cancelled', 'stalled'].includes(t.status)") !== false);

$taskApiSource = (string)file_get_contents(dirname(__DIR__) . '/www/api/task.php');
check('CRON HINT В API ВЕДЁТ В ПАНЕЛЬ ХОСТИНГА',
    strpos($taskApiSource, 'панели хостинга') !== false
    && strpos($taskApiSource, 'экране «Состояние»') === false);
check('STALLED HINT В UI ВЕДЁТ В ПАНЕЛЬ ХОСТИНГА',
    strpos($taskUiSource, 'панели хостинга') !== false
    && strpos($taskUiSource, 'планировщик на экране «Состояние»') === false);
@unlink($throwPrepend);
@unlink($throwEffect);
