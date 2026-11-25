<?php
declare(strict_types=1);
/**
 * api/logs.php — журнал приложения для человека.
 *
 * GET → {ok:true, rows:[...], days:[...], next_cursor:string, truncated:bool}
 *
 * Первое из двух окон в общий журнал (второе — команда `bin/logs.php` для
 * разработчика). Раньше окна не было вовсе: чтобы ответить «почему у Ивана не
 * сработало», приходилось идти на сервер по SSH — то есть звать разработчика.
 *
 * Только для администратора приложения: в журнале видно, кто что делал, а это
 * не то, что показывают всем подряд.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/journal.php';

installFailureHandler(true);

// Частоту просим ЯВНО: по умолчанию чтения не считаются (их шлёт каждая вкладка
// пачками), но здесь каждое чтение разбирает файл в мегабайты.
$ctx = apiBoot(['methods' => ['GET'], 'role' => 'admin', 'rate' => [30, 60],
    'rate_failure' => 'diagnostic']);

$day   = apiStr($_GET, 'day',   ['required' => false, 'default' => journalToday(), 'max' => 10]);
$level = apiEnum($_GET, 'level', ['debug', 'info', 'warn', 'error'], ['required' => false, 'default' => 'debug']);
$kind  = apiEnum($_GET, 'kind',  ['all', 'action', 'system'], ['required' => false, 'default' => 'all']);
$ref   = apiStr($_GET, 'ref',   ['required' => false, 'default' => '', 'max' => 32]);
$q     = apiStr($_GET, 'q',     ['required' => false, 'default' => '', 'max' => 120]);
$cursor = apiStr($_GET, 'cursor', ['required' => false, 'default' => '', 'max' => 32]);
const LOGS_PAGE_SIZE = 25;
$installGeneration = appCurrentInstallGeneration();
if ($installGeneration === null) apiFail('Установка приложения не определена', 409, 'install_state');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    apiFail('Дата задаётся как ГГГГ-ММ-ДД', 400, 'bad_field');
}

$filter = [
    'day'   => $day,
    'level' => $level,
    'kind'  => $kind === 'all' ? '' : $kind,
    'ref'   => $ref,
    'q'     => $q,
    // Читаем одну лишнюю строку только как признак следующей страницы. В ответ
    // и DOM она не попадает: экран всегда ограничен 25 строками.
    'limit' => LOGS_PAGE_SIZE + 1,
    'cursor' => $cursor,
    // Администратор видит строки ЭТОГО портала: текущей регистрации и прежних
    // на той же площадке. Строки другого портала недоступны — при переносе
    // история поколений не наследуется, — и остаются недоступны даже при гонке с
    // физической очисткой файлов во время uninstall.
    'install_generation' => appInstallGenerationsOfThisPortal(storeRead(B24_TOKENS_FILE) ?? []),
];
try {
    $found = journalQuery($filter);
    $days = journalDays();
} catch (Throwable $e) {
    // Единый журнал сам умеет аварийный fallback в системный error_log, если
    // его каталог недоступен. Не заводим здесь второй путь записи.
    logAppError('journal read failed: ' . (string)maskSecrets($e->getMessage()));
    apiFail('Журнал сейчас недоступен. Проверьте каталог data/logs и права на него, '
        . 'затем обновите экран', 503, 'journal_unavailable');
}

if ($cursor !== '' && empty($found['cursor_found'])) {
    apiFail('Страница журнала устарела или уже удалена. Вернитесь к первой странице.',
        409, 'journal_cursor');
}
$hasMore = count($found['rows']) > LOGS_PAGE_SIZE;
$pageRows = array_slice($found['rows'], 0, LOGS_PAGE_SIZE);
$nextCursor = $hasMore && $pageRows !== []
    ? (string)($pageRows[count($pageRows) - 1]['rid']
        ?? ($pageRows[count($pageRows) - 1]['ref'] ?? '')) : '';

apiOk([
    'day'       => $day,                   // какой день показан на самом деле
    'rows'      => $pageRows,
    'days'      => $days,                  // за какие дни журнал вообще есть
    'scanned'   => $found['scanned'],
    'page_size' => LOGS_PAGE_SIZE,
    'next_cursor' => $nextCursor,
    // Упёрлись в предел разбора — честно говорим об этом, вместо того чтобы
    // выдать неполную выборку за весь журнал.
    'truncated' => $found['truncated'],
]);
