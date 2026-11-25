<?php
// CRON-воркер. Одна установка = один портал = одна задача по расписанию.
// Установок у проекта может быть несколько (instances.conf), но они полностью
// независимы: свой каталог, свой приватный store, свой планировщик.
//
// Пример cron-строки (раз в 15 минут):
//   */15 * * * * /usr/bin/php /<path>/www/bin/process.php >/dev/null 2>&1
//
// Защита: flock на DATA_ROOT/cron.lock.php — параллельные запуски не накладываются.
// Журнал: общий для всего приложения — data/logs/<дата>.log.php (api/journal.php).
// Смотреть: php www/bin/logs.php tail  либо  bash scripts/logs.sh tail
//
// Почему у лога и замка расширение .php. На сервере DATA_ROOT физически вне
// DocumentRoot и не опубликован через release/data. Но перенос/ошибка раскладки
// не должны превратить журнал в открытый текст: ЛЮБОЙ создаваемый здесь файл —
// только `.php` с исполняемым exit-префиксом первой строкой. Штатно это
// 404+exit; первый release после старого reader временно пишет legacy ради rollback.
//
// Куда дописывать бизнес-логику: блок «runJob()» ниже. Внутри использовать
// $b24 = b24() для REST-вызовов и helpers из api/lib.php для прочего.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../api/b24.php';
require_once __DIR__ . '/../api/lib.php';
require_once __DIR__ . '/../api/journal.php';
require_once __DIR__ . '/../api/access.php';   // runOnce для событий
require_once __DIR__ . '/../api/queue.php';    // очередь событий портала
require_once __DIR__ . '/../api/task-state.php'; // ход долгой задачи
require_once __DIR__ . '/../api/session.php';    // пропуска: гасим заблокированных в портале
require_once __DIR__ . '/../api/plan.php';       // тариф портала: от него зависит темп запросов
require_once __DIR__ . '/../api/capability.php'; // доступны ли приложению методы портала
// Работа проекта — в его собственном файле (находка ревью 23.09.2026, modulnost-2):
// правка тела здесь навсегда отрезала этот файл от починок шаблона. Имена в файле
// проекта свои (projectCronJob, projectTaskStep, projectReconcileUnknownStep,
// projectHandleEvent), поэтому двойного объявления нет; нет файла — заглушки ниже.
if (is_file(__DIR__ . '/../api/project-hooks.php')) require_once __DIR__ . '/../api/project-hooks.php';

/**
 * Как часто спрашиваем портал про тех, у кого есть живой пропуск.
 *
 * Объявлено ЗДЕСЬ, а не рядом со своей функцией внизу файла: `const` на верхнем
 * уровне выполняется по ходу чтения файла, а тик крона запускается раньше — и
 * константа, объявленная ниже, на втором же запуске давала «Undefined constant»
 * и роняла весь тик.
 */
const SESSION_RECHECK_SECONDS = 600;

const CRON_FILE_PREFIX = BXAPP_PRIVATE_PHP_HEADER;

$LOCK_FILE = DATA_ROOT . '/cron.lock.php';

// Маскировка и запись — в общем журнале приложения (api/journal.php): один
// журнал на всё, два окна в него — экран для человека и команда для агента.
function logline(string $s): void {
    // Обёртка над общим журналом приложения: сигнатура прежняя, поэтому тело
    // runJob() каждого проекта продолжает звать logline() как раньше. Раньше это
    // был третий по счёту формат журнала в приложении, и читался он только по SSH.
    appLog('info', 'cron.line', [], ['msg' => $s, 'src' => 'cron']);
}

// Разовая уборка за прежними версиями приложения. Журналы раньше писались
// отдельно от общего (`data/cron-logs/`), а до того — вовсе без php-заглушки,
// то есть качались прямой ссылкой вместе со всем, что писала бизнес-логика.
// Деплой такие файлы не трогает (data/ исключён из синхронизации), поэтому
// закрываем и убираем их сами при первом же запуске.
$legacyLogDir = DATA_ROOT . '/cron-logs';
$legacyGone = 0;
foreach (glob($legacyLogDir . '/*.log') ?: [] as $legacyLog) {
    // Именно удаляем, а не переносим: это журналы прежнего формата, лежащие в
    // веб-корне БЕЗ php-заглушки, то есть скачиваемые прямой ссылкой вместе со
    // всем, что писала бизнес-логика. Их содержимое старше перехода на общий
    // журнал и ценности не имеет, а дыра — вполне реальная.
    if (@unlink($legacyLog)) $legacyGone++;
}
if (is_file(DATA_ROOT . '/cron.lock')) @unlink(DATA_ROOT . '/cron.lock');
if ($legacyGone > 0) {
    appLog('info', 'log.legacy_cleanup', ['удалено_прежних_журналов' => $legacyGone], ['src' => 'cron']);
}

// Уборка журнала по возрасту. Зовём отсюда, потому что крон — единственное
// место, которое гарантированно выполняется регулярно; на хостинге место
// кончается тихо, и первым от этого страдает не журнал, а запись состояния.
$prunedOld = journalPrune();
foreach (glob($legacyLogDir . '/*.log.php') ?: [] as $oldLog) {
    if (@filemtime($oldLog) < time() - LOG_RETENTION_DAYS * 86400) { @unlink($oldLog); $prunedOld++; }
}
if ($prunedOld > 0) appLog('info', 'log.prune', ['удалено_файлов' => $prunedOld], ['src' => 'cron']);

// Уборка журнала операций runOnce — здесь по той же причине, что и журнал.
// Раньше единственная чистка шла внутри мутатора на КАЖДОЙ боевой операции:
// файл читался целиком, фильтровался и переписывался, поэтому цену роста платил
// пользователь в момент записи в CRM, а не фоновая задача ночью.
try {
    $prunedOps = runOncePrune();
    if ($prunedOps['было'] !== $prunedOps['стало']) {
        appLog('info', 'ops.prune', [
            'было' => $prunedOps['было'], 'стало' => $prunedOps['стало'],
        ], ['src' => 'cron']);
    }
} catch (Throwable $e) {
    // Разросшийся или повреждённый журнал операций не должен ронять весь тик:
    // очередь событий важнее уборки. Причина уйдёт в журнал приложения.
    appLog('warn', 'ops.prune_failed', ['причина' => maskSecrets($e->getMessage())], ['src' => 'cron']);
}

// Ручные подкоманды администратора. Они не делают работу тика и не соревнуются
// с ним: каждая правит свой файл состояния под собственным замком. Раньше они
// стояли за общим анти-нахлёстом — и пока крутился обычный тик, команда молча
// заканчивалась с кодом 0, будто всё сделано. Человек в этот момент разбирает
// аварию, и «молча ничего» — худший из возможных ответов.
$requestedCommand = (string)($argv[1] ?? '');
$manualCommand = in_array($requestedCommand, ['replay', 'forget-once'], true);
// Опечатка в команде не должна молча запускать полный боевой тик: человек набрал
// `replay-dead` вместо `replay` и получил бы обработку очереди с записями в
// портал вместо отказа. Пустой аргумент — это и есть обычный запуск по расписанию.
if ($requestedCommand !== '' && !$manualCommand) {
    fwrite(STDERR, "Неизвестная команда: " . $requestedCommand . "\n"
        . "Доступны: replay (вернуть события из ящика неразобранных), "
        . "forget-once <ключ> (снять залипший ключ операции).\n"
        . "Без аргументов — обычный запуск фоновой обработки.\n");
    exit(2);
}

// Анти-нахлёст: если предыдущий запуск ещё крутится — тихо выходим.
$lock = @fopen($LOCK_FILE, 'c');
if (!$lock) {
    fwrite(STDERR, "Не удалось открыть файл блокировки cron: " . basename($LOCK_FILE) . "\n");
    exit(1);
}
$lockHeld = (bool)flock($lock, LOCK_EX | LOCK_NB);
if (!$lockHeld && !$manualCommand) { fclose($lock); exit(0); }
// Писать в файл блокировки имеет право только тот, кто её держит.
if ($lockHeld) {
    if (filesize($LOCK_FILE) === 0 && !storeWriteAll($lock, CRON_FILE_PREFIX)) {
        fwrite(STDERR, "Не записать защитный префикс в файл блокировки cron\n");
        exit(1);
    }
    if (!storeApplyPrivatePermissions($LOCK_FILE)) {
        fwrite(STDERR, "Не выставить приватные права на файл блокировки cron\n");
        exit(1);
    }
}

// Если процесс оборвался посреди двухфазного ONAPPUNINSTALL, рабочие токены уже
// отрезаны и appInstalled() вернёт false. Поэтому pending-уборку продолжаем ДО
// обычного гейта установки; иначе она навсегда останется незавершённой.
//
// Ручная команда сюда не заходит: она пришла снять ключ или вернуть события, а
// не доделывать удаление приложения. Иначе, пройдя мимо анти-нахлёста, она
// запускала бы вторую уборку состояния параллельно работающему тику.
try {
    $pendingGeneration = $manualCommand ? null : appPendingCleanupGeneration();
    if ($pendingGeneration !== null) {
        appPurgeInstallState($pendingGeneration);
        // Успех физически очистил журнал. Любая строка после него тут же создаст
        // новый log-файл уже удалённой установки, поэтому завершаем процесс.
        exit(0);
    }
} catch (Throwable $e) {
    appLog('error', 'app.uninstall.resume_fail', ['текст' => maskSecrets($e->getMessage())],
        ['src' => 'cron', 'msg' => 'уборка после удаления всё ещё не завершена']);
    exit(1);
}

// Фиксируем не просто факт установки, а точное поколение, к которому относится
// ЭТОТ процесс. Между двумя строками cron мог пройти reinstall A→B; без снимка
// старый worker продолжал уже в B и записывал туда cron_last_run от A.
try {
    $workerInstallation = cronInstallationSnapshot();
} catch (Throwable $e) {
    fwrite(STDERR, "Не удалось прочитать состояние установки: " . maskSecrets($e->getMessage()) . "\n");
    exit(1);
}
$workerGeneration = appInstallGenerationFrom($workerInstallation);
if (appInstallationPhaseFrom($workerInstallation) !== 'installed' || $workerGeneration === '') {
    try {
        cronRunForInstallation($workerInstallation, static function (): void {
            appLog('warn', 'cron.skip',
                ['причина' => 'приложение не установлено или удалено с портала'], ['src' => 'cron']);
        });
    } catch (Throwable $e) {
        // Диагностика не должна оживлять state и не важнее корректной остановки.
    }
    // Обычный тик здесь молчит намеренно (его запускает планировщик каждую
    // минуту), а вот человеку, который набрал команду руками, нужен ответ.
    if ($manualCommand) {
        fwrite(STDERR, "Команда не выполнена: приложение не установлено или удалено с портала\n");
        exit(1);
    }
    exit(0);
}
appBindLogGeneration($workerGeneration);
$GLOBALS['BXAPP_WORKER_INSTALLATION'] = $workerInstallation;

// Ручной возврат неразобранных событий: `php www/bin/process.php replay`.
// Обработчик починили — события из ящика возвращаются в очередь и разбираются
// обычным ходом. Без этой команды ящик был бы просто складом, из которого
// ничего не достать.
if (($argv[1] ?? '') === 'replay') {
    $back = 0;
    $propuscheno = 0;
    try {
        $matched = cronRunForInstallation($workerInstallation, static function () use (&$back, &$propuscheno): void {
            $back = queueReplayDead(null, null, $propuscheno);
            appLog('warn', 'event.replay', ['возвращено' => $back, 'убрано без возврата' => $propuscheno],
                ['src' => 'cron', 'msg' => 'события из ящика неразобранных возвращены в очередь']);
        });
        if (!$matched) {
            fwrite(STDERR, "События не возвращены: установка сменилась или приложение уже удалено с портала\n");
            exit(1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Не удалось вернуть события: " . maskSecrets($e->getMessage()) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "Возвращено в очередь: $back\n");
    // Второе число обязательно: оператор разгребает ящик, чтобы события РАЗОБРАЛИСЬ.
    // Убранные без возврата (уже в очереди или уже сделаны) разбираться не будут,
    // и молчать об этом — значит обещать работу, которой не случится.
    if ($propuscheno > 0) {
        fwrite(STDOUT, "Убрано из ящика без возврата (уже в очереди или уже обработаны): $propuscheno\n");
    }
    // Разбор мог остановиться на середине: входящая очередь имеет предел, и при
    // большом ящике часть событий в неё не влезла. Молчать об этом нельзя —
    // оператор решит, что ящик разобран, и больше не вернётся.
    $ostalos = (int)($GLOBALS['BXAPP_REPLAY_INBOX_FULL'] ?? 0);
    if ($ostalos > 0) {
        fwrite(STDOUT, "ОСТАНОВЛЕНО: входящая очередь заполнена, в ящике осталось $ostalos. "
            . "Дождитесь, пока планировщик разберёт очередь, и повторите команду.\n");
    }
    exit(0);
}

// Снять залипший ключ операции: `php www/bin/process.php forget-once <ключ>`.
// После обрыва посреди мутации ключ остаётся в состоянии «исход неизвестен» и не
// уходит по времени — это правильно, потому что время не доказывает, что работа
// не сделана. Но снять метку было нечем: runOnceForget() не вызывался ниоткуда,
// и приложение упиралось в «неизвестно» до ручной правки файла состояния.
// Проверять исход обязан человек: сначала посмотреть в портале, потом снимать.
if (($argv[1] ?? '') === 'forget-once') {
    $forgetKey = (string)($argv[2] ?? '');
    if ($forgetKey === '') {
        fwrite(STDERR, "Укажите ключ операции: php www/bin/process.php forget-once <ключ>\n");
        exit(1);
    }
    try {
        $forgotten = false;
        $matched = cronRunForInstallation($workerInstallation, static function () use ($forgetKey, &$forgotten): void {
            $before = storeRead(OPS_FILE) ?? [];
            $forgotten = isset($before[$forgetKey]);
            // Снятие ЗДЕСЬ намеренно без сверки состояния (экран, в отличие от
            // командной строки, передаёт увиденную запись — api/ops.php). Это
            // последний рубеж человека: им разжимают журнал операций, упёршийся
            // в потолок, когда веб-путь уже отказывает. Предохранитель, из
            // которого нельзя выйти, — не защита, а тупик.
            runOnceForget($forgetKey);
            if ($forgotten) {
                appLog('warn', 'ops.forget', ['ключ' => $forgetKey], ['src' => 'cron',
                    'msg' => 'ключ операции снят вручную после проверки исхода в портале']);
            }
        });
        if (!$matched) {
            fwrite(STDERR, "Ключ не снят: установка сменилась или приложение уже удалено с портала\n");
            exit(1);
        }
        fwrite(STDOUT, $forgotten ? "Ключ снят: $forgetKey\n" : "Такого ключа нет: $forgetKey\n");
        exit($forgotten ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, "Не удалось снять ключ: " . maskSecrets($e->getMessage()) . "\n");
        exit(1);
    }
}

$started = microtime(true);
try {
    if (!cronRunForInstallation($workerInstallation, static function (): void {
        appLog('info', 'cron.start', [], ['src' => 'cron']);
        // ОТМЕТКУ «ПЛАНИРОВЩИК ЖИВ» СТАВИМ СРАЗУ, А НЕ В КОНЦЕ ТИКА.
        //
        // Экран задачи предупреждает «Фоновая обработка ни разу не запускалась»,
        // пока этой отметки нет. Раньше она появлялась после ВСЕЙ работы тика, а
        // задача публикует свой исход раньше — и получалось окно, в котором
        // человек видел «Остановлено» вместе с предупреждением, что обработка
        // никогда не запускалась. Опрос экрана к этому моменту уже прекращён
        // (задача в терминальном состоянии), поэтому предупреждение висело до
        // перезагрузки страницы — то есть врало о том, что рабочий не работает,
        // ровно в тот момент, когда он отработал.
        //
        // Факт запуска известен ЗДЕСЬ, а исход и длительность допишет cronMarkRun
        // в конце: это разные сведения, и держать их вместе было незачем.
        cronMarkStarted();
    })) exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Не удалось начать cron: " . maskSecrets($e->getMessage()) . "\n");
    exit(1);
}
$GLOBALS['BXAPP_WORKER_UNINSTALLED'] = false;
$GLOBALS['BXAPP_WORKER_UNINSTALL_PENDING'] = false;

$cronOk = true;
$cronFailure = null;
$cronExit = 0;
$generationChanged = false;
try {
    runJob();
} catch (InstallGenerationMismatchException $e) {
    // Нормальная гонка с reinstall/uninstall: ни лог, ни state прежнего worker
    // уже нельзя дописывать. Новый процесс обслужит новое поколение.
    $generationChanged = true;
} catch (StoreCorruptedException $e) {
    $cronOk = false;
    $cronFailure = $e;
    $cronExit = 1;
} catch (Throwable $e) {
    $cronOk = false;
    $cronFailure = $e;
    $cronExit = 1;
}

// Успешный ONAPPUNINSTALL уже очистил state и inbox. Ни NOOP, ни cron.end, ни
// cronMarkRun после него писать нельзя: это заново создавало state удалённой
// установки и позволяло воркеру продолжить бизнес-обработку той же порции.
if (!empty($GLOBALS['BXAPP_WORKER_UNINSTALLED'])) exit(0);
if (!empty($GLOBALS['BXAPP_WORKER_UNINSTALL_PENDING'])) exit(1);
if ($generationChanged) exit(0);

$ms = (int)round((microtime(true) - $started) * 1000);
try {
    $matched = cronRunForInstallation($workerInstallation,
        static function () use ($cronOk, $cronFailure, $ms): void {
            if ($cronFailure instanceof StoreCorruptedException) {
                // Данные испорчены — молча продолжать нельзя: следующий запуск
                // упадёт так же. Пишем только пока это всё ещё та же установка.
                appLog('error', 'cron.stop', ['причина' => maskSecrets($cronFailure->getMessage())],
                    ['src' => 'cron', 'msg' => 'состояние повреждено, разбирать вручную']);
                cronMarkRun(false, $ms);
                return;
            }
            if ($cronFailure instanceof Throwable) {
                appLog('error', 'cron.fail',
                    ['где' => basename($cronFailure->getFile()) . ':' . $cronFailure->getLine(),
                        'текст' => maskSecrets($cronFailure->getMessage())], ['src' => 'cron']);
            }
            cronMarkRun($cronOk, $ms);
            appLog('info', 'cron.end', ['успешно' => $cronOk], ['src' => 'cron', 'ms' => $ms]);
        });
    if (!$matched) exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Не удалось завершить cron: " . maskSecrets($e->getMessage()) . "\n");
    exit(1);
}
exit($cronExit);

/**
 * Снимок установки для всего запуска worker. Legacy-установке сначала выдаём
 * generation под EX, затем читаем итог под SH одним непротиворечивым снимком.
 *
 * @return array<string,mixed>
 */
function cronInstallationSnapshot(): array {
    $before = storeRead(B24_TOKENS_FILE) ?? [];
    // Заодно валидируем channel/tenant matrix. Worker не должен успеть
    // обработать очередь в identity OAuth-портала A, если transport настроен
    // webhook'ом портала B.
    b24Portal();
    if (appInstallationPhaseFrom($before) === 'installed'
        && appInstallGenerationFrom($before) === '') {
        appEnsureInstallGeneration();
    }
    return appWithLifecycleReadLock(static function (): array {
        $snapshot = storeRead(B24_TOKENS_FILE) ?? [];
        if (appInstallationPhaseFrom($snapshot) === 'installed'
            && appInstallGenerationFrom($snapshot) === '' && empty($snapshot['access_token'])) {
            $webhookGeneration = appWebhookInstallGeneration();
            if ($webhookGeneration !== '') $snapshot['install_generation'] = $webhookGeneration;
        }
        return $snapshot;
    }, 25.0);
}

/**
 * Выполнить служебную запись worker только для его исходной установки.
 * false означает штатную смену поколения: писать в новую установку запрещено.
 */
function cronRunForInstallation(array $installation, callable $work): bool {
    try {
        appWithMatchingInstallationReadLock($installation, $work, 25.0);
        return true;
    } catch (InstallGenerationMismatchException $e) {
        return false;
    }
}

/**
 * Отметка «фон отработал»: время, длительность, исход.
 *
 * Нужна для экрана состояния. Без неё молчащий крон и работающий выглядят
 * одинаково, и о том, что задание в панели хостинга отключилось, узнают через
 * несколько дней по отсутствию результата.
 */
/**
 * Отметить, что планировщик ЗАПУСТИЛСЯ. Пишется в начале тика.
 *
 * Отдельно от cronMarkRun намеренно: «рабочий запускается» и «рабочий отработал
 * успешно за N мс» — разные сведения, и первое нужно экрану немедленно. Исход
 * (`cron_last_ok`, `cron_last_ms`) здесь НЕ трогаем: до конца работы он ещё
 * неизвестен, а выдать предположение за факт — худшее, что тут можно сделать.
 */
function cronMarkStarted(): void {
    storeUpdate(STATE_FILE, static function (array $s): array {
        $now = time();
        $s = cronTickRemember($s, $now);
        $s['cron_last_run_at'] = date('c');
        return $s;
    });
}

function cronMarkRun(bool $ok, int $ms): void {
    // Экран health считает эту отметку источником истины о работе cron.
    // Поэтому ошибка записи не может быть «вспомогательной»: иначе hosting
    // увидит exit 0 и cron.end, а человек — старый статус без объяснения.
    storeUpdate(STATE_FILE, static function (array $s) use ($ok, $ms): array {
        // Такт замеряет cronMarkStarted() в начале тика — там же, где ставится
        // время запуска. Считать его ещё и здесь нельзя: два замера за один тик
        // с разницей в доли секунды превратили бы «раз в 15 минут» в «раз в
        // секунду», и молчащий планировщик перестал бы отличаться от живого.
        $s['cron_last_ok'] = $ok;
        $s['cron_last_ms'] = $ms;
        return $s;
    });
}

/**
 * Один тик крона: общая механика шаблона. МЕНЯТЬ ЗДЕСЬ НЕЧЕГО.
 *
 * Что делает по порядку: разбирает очередь событий портала, уступает дорогу
 * установке, считает очередную порцию долгой задачи и зовёт работу проекта —
 * projectJob(). Заменять эту функцию целиком нельзя: вместе с ней уедут разбор
 * событий и долгая задача, а поломка будет тихой — портал продолжит слать
 * события, приложение продолжит отвечать «принято» и не делать ничего.
 */
function runJob(): void {
    // ТАРИФ ПОРТАЛА — до всякой работы. От него зависит, с какой частотой
    // приложение вправе обращаться к порталу: Энтерпрайз позволяет 5 запросов в
    // секунду, прочие тарифы — 2 (limits.md). Пока тариф неизвестен, клиент
    // держит самый строгий предел, то есть на Энтерпрайзе выгрузка идёт вдвое
    // медленнее, чем позволено. Спрашиваем раз в сутки и НЕ падаем, если портал
    // не ответил: тариф — это ускорение, а не условие работы.
    try {
        planRefresh(b24());
    } catch (Throwable $e) {
        appLog('warn', 'plan.refresh.failed', ['ошибка' => maskSecrets($e->getMessage())],
            ['src' => 'cron', 'msg' => 'тариф портала не обновлён — работаем по прежнему пределу']);
    }

    // ДОСТУПНОСТЬ МЕТОДОВ ПОРТАЛА — тоже до работы. Выданное право и работающий
    // метод разные вещи: на коробке модуль бывает выключен, а версия старее
    // (замер на живой коробке: при 71 выданном праве `crm.item.list` вызвать
    // нельзя). Спрашиваем раз в сутки одним пакетом и НЕ падаем при отказе:
    // непроверенное считается разрешённым, иначе первая же неудача остановила бы
    // работающее приложение.
    try {
        $nuzhno = capNeeded();
        $spisok = array_merge($nuzhno['обязательные'], $nuzhno['необязательные']);
        // ПОКА ЧЕГО-ТО ОБЯЗАТЕЛЬНОГО НЕ ХВАТАЕТ — СПРАШИВАЕМ КАЖДЫЙ ТИК, не раз в
        // сутки. Иначе предохранитель не умеет сниматься: работа проекта ниже
        // останавливается по этой же карте, а карта считается свежей ещё сутки —
        // администратор выдаёт недостающее право в портале и не понимает, почему
        // приложение стоит до завтра (находка ревью 09.08.2026). Лишних запросов
        // это почти не даёт: один batch и только в аварийном состоянии, в норме
        // список недостающего пуст и работает обычный суточный срок.
        $srochno = capMissingRequired() !== [];
        if ($spisok !== []) capRefresh(b24(), $spisok, $srochno);
    } catch (Throwable $e) {
        appLog('warn', 'capability.check.failed', ['ошибка' => maskSecrets($e->getMessage())],
            ['src' => 'cron', 'msg' => 'доступность методов портала не проверена — работаем по прежнему знанию']);
    }

    // Разбор событий портала идёт всегда: приём (api/event.php) складывает их в
    // очередь, и без этого шага портал получал бы «принято», а дальше не
    // происходило ничего — очередь молча копилась и обрезалась по потолку.
    // Разбираем очередь, пока она не опустеет или не кончится бюджет времени:
    // одна порция за тик крона не успевала бы за потоком событий портала.
    $deadline = microtime(true) + 45;
    do {
        $n = drainInbox();
        if (!empty($GLOBALS['BXAPP_WORKER_UNINSTALLED'])
            || !empty($GLOBALS['BXAPP_WORKER_UNINSTALL_PENDING'])) return;
        // Кто-то ждёт эксклюзивный замок — значит прямо сейчас человек ставит,
        // переставляет или удаляет приложение. Уступаем: следующая порция ушла бы
        // за общим замком снова, а flock отдаёт его читателю охотнее, чем
        // ожидающему писателю, — и установка отвалилась бы по своим 25 секундам,
        // хотя мешала ей только наша очередь. Недоразобранное подождёт минуту.
        if (appLifecycleWriterWaiting()) {
            logline('разбор очереди уступает установке приложения — продолжим следующим тиком');
            return;
        }
    } while ($n > 0 && microtime(true) < $deadline);

    $installation = $GLOBALS['BXAPP_WORKER_INSTALLATION'] ?? null;
    if (!is_array($installation)) {
        throw new InstallGenerationMismatchException('Worker запущен без снимка установки');
    }
    appWithMatchingInstallationReadLock($installation, static function (): void {
        $tokens = storeRead(B24_TOKENS_FILE) ?? [];
        // Каноническая installation phase уже учитывает оба штатных transport:
        // OAuth и постоянный webhook. Повторная raw-проверка access_token здесь
        // отрезала webhook-only worker после успешного верхнего generation gate.
        if (appInstallationPhaseFrom($tokens) !== 'installed') {
            throw new InstallGenerationMismatchException('Установка сменилась до фоновой задачи');
        }
        // Долгая задача, поставленная с экрана, считается ЗДЕСЬ — порциями, с
        // отметкой прогресса после каждой. Поэтому закрытое окно ничего не
        // обрывает, а падение процесса не теряет сделанное.
        runPendingTask();

        // Накопленные уведомления людям (подробности — в самой функции).
        drainNotifications();

        // Заблокированные в портале не должны доходить рабочий день на старом
        // пропуске (подробности — в api/session.php).
        revokeBlockedUserSessions();

        // ОБЯЗАТЕЛЬНЫЙ МЕТОД, КОТОРОГО НЕТ, ОСТАНАВЛИВАЕТ РАБОТУ ПРОЕКТА.
        // Прежде это знание жило только на экране «Состояние», а фоновая работа
        // шла и падала на середине — половина сделана, половина нет (находка
        // ревью 08.08.2026). Здесь, а не в начале тика: остановка всего тика
        // забрала бы с собой разбор очереди событий (включая удаление
        // приложения), долгую задачу и уведомления — они от списка методов
        // ПРОЕКТА не зависят. Судим только по прямому «нет доступа»/«нет на
        // портале»: непроверенное работу не останавливает, иначе первая же
        // неудача связи выключила бы исправное приложение.
        $netObyazatelnyh = capMissingRequired();
        if ($netObyazatelnyh !== []) {
            appLog('error', 'capability.required.missing',
                ['методы' => array_slice($netObyazatelnyh, 0, 10), 'всего' => count($netObyazatelnyh)],
                ['src' => 'cron', 'msg' => 'работа проекта не начата: портал не даёт обязательные методы — '
                    . implode(', ', array_slice($netObyazatelnyh, 0, 5))]);
            logline('ОСТАНОВЛЕНО: нет обязательных методов портала ('
                . implode(', ', array_slice($netObyazatelnyh, 0, 5)) . ')');
            return;
        }

        // Работа ПРОЕКТА — отдельной функцией и только она. Раньше здесь стояла
        // строка-заглушка, а README предлагал «заменить runJob()» — и заменяли
        // целиком, вместе с разбором очереди событий и долгой задачей: события
        // портала переставали разбираться, а запущенная с экрана задача висела
        // «выполняется» вечно. Теперь заменять нечего: своё пишется в projectJob().
        projectJob();
    }, 25.0);
}

/**
 * Отправить накопленные уведомления людям.
 *
 * Живёт в кроне, а не в запросе: иначе человек, нажавший кнопку, ждёт портал
 * вместе с уведомлением, которое к его действию отношения не имеет. Отказ
 * отправки не рушит остальной такт — очередь сама повторит попытку позже, и
 * ради этого она и заведена.
 */
function drainNotifications(): void {
    try {
        require_once __DIR__ . '/../api/notify.php';
        $res = notifyDrain(b24());
        if ($res['sent'] || $res['failed']) {
            logline('уведомления: отправлено ' . $res['sent']
                . ', не доставлено ' . $res['failed'] . ', ждёт ' . $res['left']);
        }
    } catch (Throwable $e) {
        // Текст исключения может содержать адрес с токеном — в журнал только
        // через маскировку, как и весь остальной разбор ошибок.
        logAppError('очередь уведомлений не разобрана: ' . json_encode(maskSecrets([
            'ошибка' => $e->getMessage(),
        ]), JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Погасить пропуска сотрудников, заблокированных в портале.
 *
 * Проверять при следующем ОТКРЫТИИ приложения бесполезно: уволенный сам туда не
 * пойдёт, а его открытая вкладка продолжит работать до конца восьмичасового срока.
 * Поэтому спрашивает фон — раз в десять минут, и только про тех, у кого пропуск
 * реально есть: это один batch на всех, а не запрос на человека.
 */
function revokeBlockedUserSessions(): void {
    $state = storeRead(STATE_FILE) ?? [];
    $last = (int)($state['sessions_checked_at'] ?? 0);
    if ($last > 0 && (time() - $last) < SESSION_RECHECK_SECONDS) return;

    $otdely = [];   // uid → отделы, как их сейчас видит портал
    try {
        $revoked = revokeSessionsOfBlockedUsers(static function (array $uids) use (&$otdely): array {
            // Спрашиваем ГОТОВЫМ блоком: он сам бьёт список на пакеты по 50 и
            // отдаёт частичный ответ отдельно от результата.
            $errors = [];
            // Точечная загрузка по известным id: user.get — спрашиваем ровно тех,
            // у кого есть живой пропуск. Это не список: перелистывать нечего,
            // а пакеты по 50 делает сам loadByIds().
            $rows = b24()->loadByIds('user.get', $uids, [], 'ID', $errors);
            $out = [];
            foreach ($rows as $uid => $row) {
                if (!is_array($row)) continue;
                // user.get отдаёт запись массивом из одного элемента.
                $u = isset($row[0]) && is_array($row[0]) ? $row[0] : $row;
                // Отделы приходят ТЕМ ЖЕ ответом: перевод сотрудника из
                // разрешённого отдела обязан отзывать права, а не ждать конца
                // восьмичасового пропуска (находка ревью 08.08.2026). Лишних
                // запросов к порталу это не добавляет ни одного.
                if (array_key_exists('UF_DEPARTMENT', $u)) {
                    $otdely[(int)$uid] = array_values(array_filter(
                        array_map('intval', (array)$u['UF_DEPARTMENT']),
                        static fn(int $d): bool => $d > 0));
                }
                if (!array_key_exists('ACTIVE', $u)) continue;   // портал не сказал — не решаем за него
                $active = $u['ACTIVE'];
                $out[(int)$uid] = ($active === true || $active === 'Y' || $active === 1 || $active === '1');
            }
            return $out;
        });
        // Порядок важен: сначала гасим заблокированных, потом пересчитываем
        // отделы у ОСТАВШИХСЯ. Наоборот было бы лишней работой по пропускам,
        // которые через строчку всё равно исчезнут.
        $poOtdelam = sessionApplyPortalDepartments($otdely);
        if ($poOtdelam > 0) {
            logline('пропуска после перевода в другой отдел погашены: ' . $poOtdelam);
            appLog('warn', 'session.revoked_department_change', ['погашено' => $poOtdelam],
                ['src' => 'cron', 'msg' => 'сотрудников перевели из разрешённого отдела — пропуска сняты']);
        }
        if ($revoked > 0) {
            logline('пропуска заблокированных сотрудников погашены: ' . $revoked);
            appLog('warn', 'session.revoked_blocked', ['погашено' => $revoked],
                ['src' => 'cron', 'msg' => 'сотрудники заблокированы в портале — пропуска сняты']);
        }
    } catch (Throwable $e) {
        // Портал не ответил — это «не знаю», а не «все уволены». Просто попробуем
        // в следующий раз; отметку времени при отказе не двигаем.
        appLog('warn', 'session.recheck_failed', ['текст' => maskSecrets($e->getMessage())], ['src' => 'cron']);
        return;
    }

    storeUpdate(STATE_FILE, static function (array $s): array {
        $s['sessions_checked_at'] = time();
        return $s;
    });
}

/**
 * РАБОТА КОНКРЕТНОГО ПРОЕКТА НА КАЖДОМ ТИКЕ. Сюда НЕ пишут: тело проекта —
 * projectCronJob() в www/api/project-hooks.php, эта функция его зовёт. Всё
 * остальное в runJob() — общая механика шаблона.
 *
 * Вызывается ВНУТРИ общего замка установки, поэтому запись состояния и результаты
 * обращений к порталу там безопасны: переустановка не вклинится в середину.
 * Долгую выборку и всё, что делает много запросов, ставьте не туда, а в долгую
 * задачу (api/task.php + projectTaskStep) — она считается порциями и переживает падение.
 *
 * Пример тела в project-hooks.php:
 *   function projectCronJob(): void {
 *       $deals = b24()->fetchAll('crm.item.list', ['entityTypeId' => 2, 'filter' => [...]]);
 *       foreach ($deals as $deal) { ... }
 *       logline('обработано сделок: ' . count($deals));
 *   }
 */
function projectJob(): void {
    if (function_exists('projectCronJob')) { projectCronJob(); return; }
    logline('NOOP: projectJob() — заглушка, работа проекта пишется в projectCronJob() файла api/project-hooks.php');
}

/**
 * Отработать поставленную с экрана долгую задачу — порциями, пока есть время.
 *
 * Держится на трёх вещах, и каждая из них закрывает свою типовую поломку:
 *   1. ПОРЦИИ. После каждой пишется, сколько сделано и на чём остановились.
 *      Тик крона кончился или процесс убили — следующий продолжит с той же
 *      позиции, а не начнёт заново и не потеряет сделанное.
 *   2. ОТМЕНА МЕЖДУ ПОРЦИЯМИ. taskProgress() возвращает false, когда человек
 *      нажал «Отменить». Порция при этом дорабатывает до конца — незаконченная
 *      порция оставила бы портал в половинчатом состоянии.
 *   3. ЧЕСТНЫЙ ИСХОД. Отказ шага не оставляет задачу «вечно выполняющейся»:
 *      она завершается с человеческой причиной, и экран это показывает.
 *   4. ОБРЫВ ПОСРЕДИ ПОРЦИИ — НЕ ПОВТОР. Порция начинается с квитанции, которая
 *      снимается только отметкой прогресса. Осталась незакрытой — процесс умер
 *      жёстко (OOM, kill -9, упавший хостинг) где-то между работой в портале и
 *      этой отметкой. Что он успел сделать, не знает никто, поэтому задача
 *      останавливается и решение принимает человек: повторить ту же работу
 *      автоматически значило бы, возможно, сделать её второй раз.
 */
function runPendingTask(): void {
    $task = taskRead();
    // unknown тоже проверяем: в этом состоянии project hook может на
    // следующем тике сделать read-back. Обычную порцию без reconciliation
    // статус unknown всё равно не пустит: pending-step стоит первым в cycle.
    if (!in_array((string)($task['status'] ?? ''), ['running', 'unknown'], true)) return;
    $id = (string)$task['id'];

    // Бюджет тика. Он ограничен ДВАЖДЫ, и вторая причина неочевидна:
    //   1. задача не должна съедать весь запуск крона — очередь событий и
    //      остальная работа обязаны получить своё время;
    //   2. весь этот бюджет проходит ВНУТРИ общего замка установки (SH). Тот,
    //      кто ставит, переставляет или удаляет приложение, ждёт эксклюзивный
    //      замок 25 секунд и дальше отвечает человеку «Приложение обновляется».
    //      Бюджет ОБЯЗАН быть заведомо меньше этого ожидания, иначе долгая
    //      задача раз в минуту делает переустановку невозможной, а виноватым
    //      выглядит портал.
    $deadline = microtime(true) + TASK_TICK_BUDGET_SECONDS;
    try {
        while (microtime(true) < $deadline) {
            // ОБРЫВ ПРОШЛОЙ ПОРЦИИ — ДО всего остального. Незакрытая квитанция
            // означает: порция начиналась, и её исход неизвестен. Повторить —
            // значит, возможно, сделать ту же работу в портале второй раз;
            // пропустить и пойти дальше — значит потерять кусок. Оба варианта
            // молчаливые, поэтому решает человек, а мы останавливаемся и
            // называем позицию, на которой это случилось.
            $pending = taskPendingStep($task);
            if ($pending !== null) {
                $unknownError = 'Порция задачи прервалась на середине, и неизвестно, успела ли она '
                    . 'изменить портал. Автоматически повторять её нельзя. Нужен свежий project-specific '
                    . 'read-back; до сверки задача остаётся заблокированной.';
                appLog('error', 'task.step_unknown', maskSecrets([
                    'task' => $id, 'ключ_шага' => (string)($pending['key'] ?? ''),
                    'позиция' => mb_substr(json_encode($pending['cursor'] ?? null,
                        JSON_UNESCAPED_UNICODE) ?: '', 0, 200),
                    'начата' => (int)($pending['at'] ?? 0),
                ]), ['src' => 'cron', 'msg' => 'порция задачи прервана на середине — исход неизвестен']);
                taskMarkUnknown($id, $unknownError);

                // Generic-движок не знает, как выглядит эффект конкретного проекта.
                // Hook делает свежий read-back БЕЗ store-lock; затем taskReconcileStep()
                // применяет вывод CAS-ом. null = сверять нечем, остаёмся fail-closed.
                $resolution = taskReconcileUnknownStep(taskRead(), $pending);
                if ($resolution !== null) {
                    try {
                        $outcome = (string)($resolution['outcome'] ?? '');
                        $reconciled = taskReconcileStep($id, (string)($pending['attempt'] ?? ''),
                            $outcome, $resolution);
                        if ($reconciled) {
                            auditLog('task.step.reconcile', [
                                'задача' => $id,
                                'ключ_шага' => (string)($pending['key'] ?? ''),
                                'исход' => $outcome,
                                'доказательство' => (string)($resolution['evidence'] ?? ''),
                            ], (int)($task['startedBy'] ?? 0));
                            logline('задача ' . $id . ': unknown-step сверен (' . $outcome . ')');
                            $task = taskRead();
                            if (($task['status'] ?? '') === 'running') continue;
                            return;
                        }
                        appLog('warn', 'task.step_reconcile_conflict', maskSecrets([
                            'task' => $id, 'ключ_шага' => (string)($pending['key'] ?? ''),
                        ]), ['src' => 'cron', 'msg' => 'CAS сверки не применён: состояние уже изменилось']);
                    } catch (Throwable $reconcileError) {
                        appLog('error', 'task.step_reconcile_invalid', maskSecrets([
                            'task' => $id,
                            'text' => get_class($reconcileError) . ': ' . $reconcileError->getMessage(),
                        ]), ['src' => 'cron', 'msg' => 'project hook не дал безопасное решение']);
                    }
                }
                logline('задача ' . $id . ' остановлена: исход порции неизвестен ('
                    . (string)($pending['key'] ?? '') . ')');
                return;
            }
            // Разрешение спрашиваем ДО работы. Отмена, нажатая сразу после
            // запуска, обязана остановить нас РАНЬШЕ, чем мы тронем портал:
            // порция меняет чужие сделки, и «отменил, а оно всё равно сделало»
            // человеку не объяснить. Здесь же выдаётся квитанция порции.
            $attempt = '';
            if (!taskClaimStep($id, $task['cursor'] ?? null, $attempt)) {
                $now = taskRead();
                if ((string)$now['id'] === $id && ($now['status'] ?? '') === 'running') {
                    taskFinish($id, 'cancelled', 'Остановлено по просьбе пользователя');
                    logline('задача ' . $id . ' отменена до порции');
                }
                return;
            }
            // Детерминированная crash-точка для локальных испытаний: на бою она
            // не включается, потому что failpoint работает только с BXAPP_DATA_ROOT.
            failpoint('task.after_claim');
            // Второй аргумент — паспорт шага: ['key','attempt','cursor','at'].
            // Стабильный `key` даёт проекту сделать свой внешний эффект защищённым
            // от повтора: runOnce($step['key'], fn() => …). Проектная порция с
            // одним параметром продолжает работать — лишний аргумент PHP не мешает.
            $step = taskStep($task['cursor'] ?? null, [
                'key'     => taskStepKey($id, $task['cursor'] ?? null),
                'attempt' => $attempt,
                'cursor'  => $task['cursor'] ?? null,
            ]);
            $done = (int)($step['done'] ?? 0);
            $total = array_key_exists('total', $step) ? (int)$step['total'] : null;

            if (!empty($step['finished'])) {
                // Цифры отдаём явно: работа могла уместиться в один шаг, и тогда
                // порций не было вовсе — без них экран показывал «Готово · 0 из 0».
                taskFinish($id, 'done', (string)($step['result'] ?? ''), '',
                    ['done' => $done, 'total' => $total ?? $done]);
                logline('задача ' . $id . ' завершена: ' . (string)($step['result'] ?? ''));
                return;
            }
            // Метку попытки передаём обязательно: ею закрывается квитанция ЭТОЙ
            // порции. Без неё квитанция осталась бы висеть, и следующая порция
            // выглядела бы обрывом.
            if (!taskProgress($id, $done, $total, $step['cursor'] ?? null, $attempt)) {
                // false означает либо отмену, либо что задачу успели сменить.
                $now = taskRead();
                if ((string)$now['id'] === $id) {
                    taskFinish($id, 'cancelled', 'Остановлено по просьбе пользователя');
                    logline('задача ' . $id . ' отменена на ' . $done);
                }
                return;
            }
            $task = taskRead();
            if ((string)$task['id'] !== $id) return;   // задачу сменили — эта больше не наша
            // Между порциями смотрим, не ждёт ли нас установка. Бюджет тика и так
            // меньше её ожидания, но порции идут одна за другой, и суммарно мы
            // держали бы общий замок дольше, чем она согласна ждать.
            if (appLifecycleWriterWaiting()) {
                logline('задача ' . $id . ' уступает установке приложения — продолжит следующим тиком');
                return;
            }
        }
        logline('задача ' . $id . ' продолжится следующим тиком');
    } catch (Throwable $e) {
        // Наружу — человеческая причина. Подробности идут в журнал через
        // maskSecrets(), сырой текст исключения показывать нельзя.
        appLog('error', 'task.failed', maskSecrets([
            'task' => $id, 'text' => get_class($e) . ': ' . $e->getMessage(),
        ]));
        // Если claim уже выдал receipt, Throwable не доказывает rollback
        // внешнего эффекта. Оставляем machine-readable unknown до read-back.
        if (!taskMarkUnknown($id,
            'Порция остановилась с ошибкой после начала. Исход внешнего эффекта неизвестен; '
            . 'до project-specific read-back повтор заблокирован.')) {
            taskFinish($id, 'failed', '',
                'Задача остановлена из-за ошибки. Подробности — в журнале приложения.');
        }
    }
}

/**
 * ОДНА ПОРЦИЯ РАБОТЫ ПРОЕКТА. Сюда НЕ пишут: тело задачи — projectTaskStep($cursor, $step)
 * в www/api/project-hooks.php с тем же контрактом; без неё работает заглушка ниже.
 *
 * Контракт возврата:
 *   ['done' => сколько сделано ВСЕГО, 'total' => сколько всего, 'cursor' => на чём
 *    остановились, 'finished' => true когда всё, 'result' => сводка для экрана]
 *
 * Второй аргумент — ПАСПОРТ ШАГА от движка: ['key','attempt','cursor'].
 *   `key` стабилен: одна и та же позиция одной и той же задачи — один и тот же
 *   ключ и на повторном запуске. Если порция меняет портал (создаёт сделки,
 *   шлёт письма, списывает), оберните этот эффект готовым блоком:
 *       runOnce($step['key'], fn(): array => $этоТронетПортал);
 *   тогда даже повторный вызов не сделает работу второй раз. Порция, объявленная
 *   с одним параметром, продолжает работать — лишний аргумент PHP не мешает.
 *
 * Порция обязана быть КОРОТКОЙ (секунды) и завершённой: после неё состояние
 * портала должно быть целым, потому что следующий шаг может не случиться.
 * Процесс убит посреди порции — задача НЕ продолжится сама: движок увидит
 * незакрытую квитанцию, остановится и позовёт человека (см. runPendingTask).
 */
function taskStep($cursor, array $step = []): array {
    if (function_exists('projectTaskStep')) return projectTaskStep($cursor, $step);
    $position = (int)$cursor;
    $total = 30;                       // заглушка: столько «единиц работы»
    $position += 5;                    // за порцию делаем пять
    if ($position >= $total) {
        return ['done' => $total, 'total' => $total, 'finished' => true,
                'result' => 'Обработано записей: ' . $total];
    }
    return ['done' => $position, 'total' => $total, 'cursor' => $position];
}

/**
 * PROJECT HOOK: сверить unknown-step с порталом или другим источником истины.
 *
 * Generic read-back невозможен: taskStep() может создавать сделку, шлать письмо
 * или менять список. Поэтому default — null и задача остаётся fail-closed
 * `unknown`: автоматического обхода нет, а вручную её снимает только администратор
 * после проверки в портале (экран «Работа», taskResolveManually()). Проект пишет
 * projectReconcileUnknownStep($task, $step)
 * в www/api/project-hooks.php (тело здесь не правят) и в ней:
 *   1. по `step.key/cursor` делает СВЕЖИЙ read-only запрос;
 *   2. возвращает null, если ответ не доказывает исход;
 *   3. или ['outcome'=>'not_applied','evidence'=>'...'] при доказанном отсутствии;
 *   4. или ['outcome'=>'applied','done'=>N,'total'=>M,'cursor'=>...,'evidence'=>'...']
 *      при доказанном эффекте. Evidence — краткая ссылка/идентификатор, не секрет.
 *
 * Движок применит ответ CAS-ом по task id + attempt и запишет audit. Самому
 * hook писать в task state или вызывать taskReconcileStep() нельзя.
 */
function taskReconcileUnknownStep(array $task, array $step): ?array {
    // Шов только для synthetic-испытания на подменённом store — тот же
    // предохранитель, что у failpoint(). В проекте нужно заменить тело hook,
    // а не включать этот канал.
    $testRoot = getenv('BXAPP_DATA_ROOT');
    $testResolution = $GLOBALS['__task_reconcile_test_resolution'] ?? null;
    if ($testRoot !== false && $testRoot !== '' && is_array($testResolution)) {
        return $testResolution;
    }
    if (function_exists('projectReconcileUnknownStep')) return projectReconcileUnknownStep($task, $step);
    return null;
}

/**
 * Разобрать очередь событий портала.
 *
 * Порция снимается из очереди ПОД ЗАМКОМ и сразу: иначе следующий тик крона
 * возьмёт те же события второй раз. Событие, на котором обработчик упал,
 * возвращается в конец очереди со счётчиком попыток; после третьей неудачи оно
 * переносится в DLQ. Если DLQ заполнен или не записался, событие НЕ
 * подтверждается и остаётся в inbox: уже принятое событие нельзя потерять.
 *
 * @return int Сколько событий разобрано за вызов.
 */
function drainInbox(int $portion = 50): int {
    // Legacy-generation выдаётся только под EX, поэтому делаем это до SH всего
    // batch и не пытаемся повышать lock изнутри. Саму аренду берём уже под SH:
    // migration не должна очистить A и выдать этому worker события B между
    // queueTake() и проверкой поколения.
    $beforeFence = storeRead(B24_TOKENS_FILE);
    if (is_array($beforeFence) && !empty($beforeFence['access_token'])
        && empty($beforeFence['uninstalled_at']) && empty($beforeFence['cleanup_pending'])
        && appInstallGenerationFrom($beforeFence) === '') {
        appEnsureInstallGeneration();
    }
    $lifecycleHeld = false;
    appLifecycleAcquire(LOCK_SH, 25.0);
    $lifecycleHeld = true;
    try {
    $workerInstallation = $GLOBALS['BXAPP_WORKER_INSTALLATION'] ?? null;
    $tokensAtTake = storeRead(B24_TOKENS_FILE) ?? [];
    $workerTakeGeneration = is_array($workerInstallation)
        ? appInstallGenerationFrom($workerInstallation) : '';
    $currentTakeGeneration = appInstallGenerationFrom($tokensAtTake);
    if ($currentTakeGeneration === ''
        && appInstallationPhaseFrom($tokensAtTake) === 'installed'
        && empty($tokensAtTake['access_token'])) {
        $currentTakeGeneration = appWebhookInstallGeneration();
    }
    if (is_array($workerInstallation)
        && (!hash_equals($workerTakeGeneration, $currentTakeGeneration)
            || appInstallationPhaseFrom($workerInstallation) !== appInstallationPhaseFrom($tokensAtTake))) {
        throw new InstallGenerationMismatchException('Установка сменилась до аренды событий');
    }

    // Порция берётся В АРЕНДУ (api/queue.php): событие исчезает из очереди только
    // после подтверждения. Раньше порцию вырезали сразу, и убитый посреди работы
    // процесс уносил её целиком — события уже не в очереди, но и не обработаны, а
    // портал подтверждённое не повторяет.
    $batch = queueTake($portion);
    if ($batch === []) return 0;

    $done = 0; $retry = []; $dropped = 0; $retained = 0; $finished = [];
    $terminalFinished = []; $povtorov = 0;
    foreach ($batch as $event) {
        $code = (string)($event['event'] ?? 'неизвестно');
        $eid = (string)($event['eid'] ?? '');

        // СОБЫТИЕ УЖЕ ЗАКРЫТО ОКОНЧАТЕЛЬНО — исполнять его второй раз нельзя.
        // Такое приходит двумя путями, и оба реальны: процесс умер между
        // обработкой и подтверждением (аренда истекла, событие вернулось в
        // работу) либо разбор ящика неразобранных вернул копию. Раньше защиты
        // не было вовсе, и повтор означал повторное бизнес-действие в CRM
        // клиента (находка ревью 08.08.2026).
        if ($eid !== '' && queueIsDone($eid)) {
            // Это может быть closing после падения между effect и cleanup.
            // Обычный ack снял бы inbox, но оставил бы DLQ и вечную closing-
            // запись. Полная финализация дочищает оба остатка без нового эффекта.
            $terminalFinished[] = $eid;
            $povtorov++;
            appLog('warn', 'event.already_done', ['событие' => $code],
                ['src' => 'cron', 'msg' => 'событие уже было обработано — подтверждаем без повторного исполнения']);
            continue;
        }
        $eventGeneration = is_string($event['install_generation'] ?? null)
            ? trim((string)$event['install_generation']) : '';

        // Проверяем поколение и активность по ОДНОМУ снимку токенов. Событие от
        // установки A не должно выполнить бизнес-логику уже после установки B.
        $tokens = storeRead(B24_TOKENS_FILE);
        $tokensArr = is_array($tokens) ? $tokens : [];
        $currentGeneration = appInstallGenerationFrom($tokensArr);
        if ($currentGeneration === ''
            && appInstallationPhaseFrom($tokensArr) === 'installed'
            && empty($tokensArr['access_token'])) {
            $currentGeneration = appWebhookInstallGeneration();
        }
        $active = appInstallationPhaseFrom($tokensArr) === 'installed';

        if (!$active || $currentGeneration === '') {
            // Состояние жизненного цикла меняется прямо сейчас. Ничего не
            // исполняем и не подтверждаем: следующий cron увидит окончательный
            // generation либо завершит pending-уборку.
            $retained++;
            appLog('warn', 'event.generation_wait', ['событие' => $code], ['src' => 'cron',
                'msg' => 'событие оставлено в inbox до стабилизации установки']);
            if (is_array($tokens) && !empty($tokens['cleanup_pending'])) {
                $GLOBALS['BXAPP_WORKER_UNINSTALL_PENDING'] = true;
                return $done;
            }
            if (is_array($tokens) && !empty($tokens['uninstalled_at'])) {
                $GLOBALS['BXAPP_WORKER_UNINSTALLED'] = true;
                return $done;
            }
            continue;
        }

        if ($eventGeneration === '') {
            // Legacy-событие не исполняем вслепую, но и не ACK/drop: переносим
            // целиком в DLQ для явного решения. Если DLQ не принял запись, eid
            // остаётся leased в inbox и вернётся после истечения аренды.
            try {
                queueDeadLetter($event, 'нет install_generation: событие изолировано после обновления шаблона');
                $finished[] = $eid;
                $dropped++;
                $done++;
                appLog('warn', 'event.generation_missing', ['событие' => $code], ['src' => 'cron',
                    'msg' => 'legacy-событие без поколения перенесено в DLQ, а не потеряно']);
            } catch (Throwable $dlqError) {
                $retained++;
                appLog('error', 'event.deadletter_fail', ['событие' => $code,
                    'текст' => maskSecrets($dlqError->getMessage())], ['src' => 'cron',
                    'msg' => 'legacy-событие без поколения оставлено в inbox']);
            }
            continue;
        }

        if (!hash_equals($currentGeneration, $eventGeneration)) {
            $finished[] = $eid;
            $done++;
            appLog('warn', 'event.generation_skip', ['событие' => $code,
                'поколение_события' => $eventGeneration,
                'текущее_поколение' => $currentGeneration],
                ['src' => 'cron', 'msg' => 'событие прежней установки пропущено']);
            continue;
        }
        try {
            if ($code === 'ONAPPUNINSTALL') {
                // SH→EX повышать нельзя. До этой строки поколение события ещё
                // раз сверено под SH; отпускаем его непосредственно перед CAS
                // uninstall. Обычные events держат SH до общего queueAck ниже.
                appLifecycleRelease();
                $lifecycleHeld = false;
            }
            $uninstalled = handleEvent($code,
                is_array($event['data'] ?? null) ? $event['data'] : [], $eventGeneration);
            $done++;
            // Отмечаем закрытым СРАЗУ, а не в общем ack в конце порции: между
            // обработкой и ack проходит до сорока пяти секунд, и падение в это
            // окно возвращало событие в работу как неразобранное.
            //
            // Своя обработка отказа, а не общий catch ниже: дело СДЕЛАНО, и
            // единственное, что не удалось, — записать об этом в реестр. Общий
            // catch посчитал бы событие неудавшимся и вернул в очередь, то есть
            // выполнил бизнес-действие второй раз — ровно то, от чего реестр и
            // заведён (находка ревью 09.08.2026). Событие считаем закрытым:
            // худшее последствие незаписанного реестра — один лишний разбор
            // после ручного replay, и он дешевле дубля в CRM клиента.
            try {
                if ($eid !== '' && !$uninstalled) queueFinalizeDone([$eid]);
            } catch (Throwable $reestr) {
                appLog('error', 'event.done_registry_fail',
                    ['событие' => $eid, 'ошибка' => maskSecrets($reestr->getMessage())],
                    ['src' => 'cron', 'msg' => 'событие обработано, но отметка о закрытии не записана — '
                        . 'повтор возможен только через ручной replay']);
                // Сохраняем прежнюю деградацию: если done-реестр недоступен,
                // хотя бы снимаем уже обработанное событие с inbox. Повтор тогда
                // возможен только через осознанный ручной replay.
                if ($eid !== '' && !$uninstalled) queueAck([$eid]);
            }
            if ($uninstalled) {
                // appPurgeInstallState() уже очистил inbox целиком. queueAck()
                // здесь не нужен и опасен: накопленные retry могли бы вернуть в
                // пустую очередь события только что удалённой установки.
                $GLOBALS['BXAPP_WORKER_UNINSTALLED'] = true;
                return $done;
            }
        } catch (InstallGenerationMismatchException $e) {
            if (!$lifecycleHeld) {
                appLifecycleAcquire(LOCK_SH, 25.0);
                $lifecycleHeld = true;
            }
            $afterMismatch = storeRead(B24_TOKENS_FILE) ?? [];
            $afterMismatchGeneration = appInstallGenerationFrom($afterMismatch);
            if ($afterMismatchGeneration === ''
                && appInstallationPhaseFrom($afterMismatch) === 'installed'
                && empty($afterMismatch['access_token'])) {
                $afterMismatchGeneration = appWebhookInstallGeneration();
            }
            if (is_array($workerInstallation)
                && !hash_equals(appInstallGenerationFrom($workerInstallation),
                    $afterMismatchGeneration)) {
                // ONAPPUNINSTALL успел столкнуться с новым поколением. Не пишем
                // старую диагностику и не подтверждаем eid уже в очереди B.
                throw $e;
            }
            // Установка сменилась между проверкой выше и CAS внутри обработчика.
            // Это не временный сбой и не повод повторять событие поколения A.
            $finished[] = $eid;
            $done++;
            appLog('warn', 'event.generation_skip', ['событие' => $code,
                'поколение_события' => $eventGeneration,
                'текст' => maskSecrets($e->getMessage())], ['src' => 'cron']);
        } catch (Throwable $e) {
            if (!$lifecycleHeld) {
                appLifecycleAcquire(LOCK_SH, 25.0);
                $lifecycleHeld = true;
            }
            $cleanupIncomplete = $e instanceof InstallCleanupIncompleteException;
            $tries = (int)($event['tries'] ?? 0) + 1;
            appLog('error', 'event.fail', ['событие' => $code, 'попытка' => $tries,
                'текст' => maskSecrets($e->getMessage())], ['src' => 'cron']);
            if ($tries < QUEUE_MAX_TRIES) {
                // Откладываем с нарастающей паузой. Без неё все три попытки
                // сгорали за один тик крона: разбор крутится в цикле, пока
                // очередь не опустеет, и временный сбой портала выглядел как
                // окончательный.
                $finished[] = $eid;
                $retry[] = queueDefer($event, $tries);
            } else {
                // Событие не удалось разобрать совсем — кладём В ЯЩИК, а не в
                // мусор. Портал события не переприсылает: удаление означало бы,
                // что принятая сделка или оплата потеряна навсегда, а в журнале
                // осталась строка, из которой ничего не повторить.
                try {
                    queueDeadLetter($event, maskSecrets($e->getMessage()));
                    // Подтверждаем СРАЗУ, не дожидаясь конца порции. Иначе
                    // событие до 45 секунд лежит в двух местах сразу — в ящике и
                    // в очереди, — и ручной `replay`, запущенный человеком в это
                    // окно, видит свой же eid в очереди, считает его «уже
                    // возвращённым прошлым разом», удаляет из ящика и не кладёт
                    // в очередь. Дальше общий ack убирает его из очереди —
                    // событие исчезает отовсюду, а портал повторов не шлёт.
                    queueAck([$eid]);
                    $dropped++;
                    appLog('error', 'event.deadletter', ['событие' => $code, 'попыток' => $tries],
                        ['src' => 'cron', 'msg' => 'событие отложено в ящик неразобранных — починить обработчик и вернуть: php www/bin/process.php replay']);
                } catch (Throwable $dlqError) {
                    // Главное правило ack: eid добавляется в finished только
                    // ПОСЛЕ успешной записи в DLQ. При заполненном/битом ящике
                    // leased-запись остаётся в inbox до следующего запуска.
                    $retained++;
                    appLog('error', 'event.deadletter_fail', ['событие' => $code,
                        'текст' => maskSecrets($dlqError->getMessage())], ['src' => 'cron',
                        'msg' => 'DLQ не принял событие — оно оставлено в inbox без подтверждения']);
                }
            }
            if ($cleanupIncomplete) {
                // Токены уже превращены в pending-tombstone. Даже если событие
                // отложено/положено в DLQ, выполнять после него бизнес-логику и
                // заново создавать state нельзя. Следующий cron сначала
                // продолжит уборку в гейте над appInstalled().
                $GLOBALS['BXAPP_WORKER_UNINSTALL_PENDING'] = true;
                break;
            }
        }
    }
    // Разобранное убираем из очереди, неудавшееся кладём обратно — одной правкой
    // под замком, чтобы между двумя операциями не мог вклиниться соседний запуск.
    if ($terminalFinished !== []) queueFinalizeDone($terminalFinished);
    queueAck($finished, $retry);
    appLog(($retry !== [] || $dropped > 0 || $retained > 0 || $povtorov > 0) ? 'warn' : 'info', 'event.drain',
        ['обработано' => $done, 'отложено' => count($retry), 'в_ящик' => $dropped,
            'оставлено_в_inbox' => $retained, 'повторов_отсечено' => $povtorov], ['src' => 'cron']);
    return $done;
    } finally {
        if ($lifecycleHeld) appLifecycleRelease();
    }
}

/**
 * Что делать с событием портала. Тело проекта здесь НЕ пишут: оно —
 * projectHandleEvent($code, $data, $eventGeneration) в www/api/project-hooks.php.
 * Удаление приложения обрабатывает само ядро, до кода проекта.
 *
 * @param string $code Код события, например ONCRMDEALUPDATE
 * @param array  $data Полезная нагрузка от портала (FIELDS и прочее)
 * @param string $eventGeneration Поколение установки из принятого события
 * @return bool true, если приложение удалено и воркер обязан остановиться
 */
function handleEvent(string $code, array $data, string $eventGeneration): bool {
    // Удаление приложения с портала. Обрабатываем всегда, даже когда подписки на
    // событие нет: как только её включат, приложение обязано убрать за собой —
    // иначе на сервере остаются рабочие токены портала, список доступа и пропуска
    // уже удалённого приложения. Раньше здесь было «обработчик не задан», то есть
    // событие подтверждалось и не делало ничего.
    if ($code === 'ONAPPUNINSTALL') {
        // Уборка одна на всё приложение (api/lib.php): раньше здесь чистились
        // три файла из девяти, а настройки, счётчики, очередь и режимы оставались
        // от удалённой установки — следующая установка видела чужое состояние
        // как своё. Токены получают метку удаления, чтобы запрос, начатый до
        // события, не вернул их обратно своим мержем.
        appPurgeInstallState($eventGeneration);
        return true;
    }

    // Своя обработка — projectHandleEvent() в api/project-hooks.php. Если она меняет
    // что-то в портале — оборачивайте в runOnce() с ключом от идентификатора
    // сущности: повторный разбор той же записи очереди без ключа обработает
    // сделку дважды.
    if (function_exists('projectHandleEvent')) {
        projectHandleEvent($code, $data, $eventGeneration);
        return false;
    }
    logline('событие ' . $code . ' получено, обработчик не задан');
    return false;
}
