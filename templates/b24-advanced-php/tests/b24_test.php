<?php
declare(strict_types=1);
/**
 * Клиент портала: пакеты, пагинация, повторы, проверка отказов.
 *
 * В сеть не ходим. Проверяем решающие функции клиента — те, где живёт правило
 * «повторять запрос или нет». Ошибка именно здесь тише всего: она не падает, а
 * молча создаёт вторую сделку в CRM или обрывает выборку на первой странице.
 */

suite('Клиент портала');

check('browser PROTOCOL=1 нормализуется в https, а не в схему 1://',
    installProtocolScheme('1') === 'https');
check('browser PROTOCOL=0 распознаётся как http-флаг',
    installProtocolScheme('0') === 'http');
$badProtocol = null;
try { installProtocolScheme('javascript'); } catch (Throwable $e) { $badProtocol = $e->getMessage(); }
check('неизвестный PROTOCOL отклоняется fail-closed', is_string($badProtocol));

$protocolClient = new B24('local.test.app', 'secret');
$protocolClient->saveTokensFromInstall([
    'AUTH_ID' => 'protocol-access', 'REFRESH_ID' => 'protocol-refresh',
    'DOMAIN' => 'portal-protocol.invalid', 'member_id' => 'MEMBER-PROTOCOL',
    'AUTH_EXPIRES' => 3600, 'PROTOCOL' => '1',
]);
$protocolState = storeRead(B24_TOKENS_FILE) ?? [];
check('первая legacy-установка строит только https REST endpoint',
    ($protocolState['protocol'] ?? '') === 'https'
    && ($protocolState['client_endpoint'] ?? '') === 'https://portal-protocol.invalid/rest/');

// ── Коробочный портал: DOMAIN не присылается вовсе ────────────────────────
// Коробка шлёт SERVER_ENDPOINT, а DOMAIN — нет. Пока установка искала домен
// только в DOMAIN и Referer, она (1) падала «domain не определить», когда
// Referrer-Policy обрезала заголовок, и (2) при нескольких именах коробки
// сохраняла внутреннее имя из Referer, тогда как забор сессии сверяет хост из
// SERVER_ENDPOINT — приложение после успешной установки навсегда отвечало
// «открывается только из портала».
testWipe(DATA_ROOT);
$boxClient = new B24('local.test.app', 'secret');
$boxInstallError = null;
try {
    $boxClient->saveTokensFromInstall([
        'AUTH_ID' => 'box-access', 'REFRESH_ID' => 'box-refresh', 'AUTH_EXPIRES' => 3600,
        'SERVER_ENDPOINT' => 'https://portal.box.invalid/rest/',
        'APPLICATION_TOKEN' => 'key-box', 'APPLICATION_SCOPE' => 'crm,user',
        'member_id' => 'MEMBER-BOX', 'status' => 'L', 'PLACEMENT' => 'DEFAULT',
    ], null);                       // Referer нет — так бывает при строгой политике
} catch (Throwable $e) { $boxInstallError = $e->getMessage(); }
$boxState = storeRead(B24_TOKENS_FILE) ?? [];
check('установка на коробке берёт домен из SERVER_ENDPOINT, а не из Referer',
    $boxInstallError === null && ($boxState['domain'] ?? '') === 'portal.box.invalid'
    && ($boxState['client_endpoint'] ?? '') === 'https://portal.box.invalid/rest/',
    (string)$boxInstallError);

// Та же коробка, следующий шаг: человек открывает приложение из левого меню.
// Страница портала при этом может быть открыта по ВНУТРЕННЕМУ имени, а REST-адрес
// — канонический. Referer тогда не совпадает с доменом установки, и забор резал
// законное открытие. Сверенный ключ приложения — доказательство сильнее Referer:
// его знают только портал и мы.
require_once dirname(__DIR__) . '/www/api/session.php';
$boxOpenPost = [
    'AUTH_ID' => 'user-token', 'AUTH_EXPIRES' => '3600', 'REFRESH_ID' => 'refresh',
    'SERVER_ENDPOINT' => 'https://portal.box.invalid/rest/', 'APPLICATION_TOKEN' => 'key-box',
    'member_id' => 'MEMBER-BOX', 'PLACEMENT' => 'DEFAULT',
];
$savedBoxReferer = $_SERVER['HTTP_REFERER'] ?? null;
$_SERVER['HTTP_REFERER'] = 'https://portal-internal.box.invalid/marketplace/app/1/';
$boxOtherNamePasses = portalPostPassesTransportFence($boxOpenPost, 'portal.box.invalid');
$boxWrongKey = portalPostPassesTransportFence(
    ['AUTH_ID' => 'user-token', 'SERVER_ENDPOINT' => 'https://portal.box.invalid/rest/',
     'APPLICATION_TOKEN' => 'подобранный-ключ'], 'portal.box.invalid');
unset($_SERVER['HTTP_REFERER']);
$boxNoRefererAtAll = portalPostPassesTransportFence($boxOpenPost, 'portal.box.invalid');
if ($savedBoxReferer === null) unset($_SERVER['HTTP_REFERER']);
else $_SERVER['HTTP_REFERER'] = $savedBoxReferer;
check('сверенный ключ приложения пускает коробку с любым именем в Referer, чужой ключ — нет',
    $boxOtherNamePasses === true && $boxNoRefererAtAll === true && $boxWrongKey === false);

// Присланный адрес используется только при совпадении хоста с доменом
// установки: иначе подставленный SERVER_ENDPOINT увёл бы токены на чужой сервер.
testWipe(DATA_ROOT);
$foreignEndpointClient = new B24('local.test.app', 'secret');
$foreignEndpointClient->saveTokensFromInstall([
    'AUTH_ID' => 'a', 'REFRESH_ID' => 'r', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal.invalid', 'member_id' => 'MEMBER-1', 'PROTOCOL' => '1',
    'SERVER_ENDPOINT' => 'https://attacker.invalid/rest/',
]);
$foreignEndpointState = storeRead(B24_TOKENS_FILE) ?? [];
check('чужой SERVER_ENDPOINT не подменяет адрес REST у своего портала',
    ($foreignEndpointState['domain'] ?? '') === 'portal.invalid'
    && ($foreignEndpointState['client_endpoint'] ?? '') === 'https://portal.invalid/rest/');
testWipe(DATA_ROOT);

storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'saved-access', 'refresh_token' => 'saved-refresh',
    'domain' => 'trusted-portal.invalid', 'member_id' => 'MEMBER-TRUSTED',
    'client_endpoint' => 'https://trusted-portal.invalid/rest/',
    'install_generation' => 'generation-trusted', 'expires_at' => time() + 3600,
]);
$existingClient = new B24('local.test.app', 'secret');
$existingClient->saveTokensFromInstall([
    'AUTH_ID' => 'new-access', 'REFRESH_ID' => 'new-refresh',
    'DOMAIN' => 'attacker.invalid', 'member_id' => 'MEMBER-ATTACKER',
    'PROTOCOL' => '0',
]);
$existingState = storeRead(B24_TOKENS_FILE) ?? [];
check('existing install игнорирует сырой DOMAIN/PROTOCOL и сохраняет trusted endpoint',
    ($existingState['domain'] ?? '') === 'trusted-portal.invalid'
    && ($existingState['member_id'] ?? '') === 'MEMBER-TRUSTED'
    && ($existingState['client_endpoint'] ?? '') === 'https://trusted-portal.invalid/rest/');

// Класс клиента намеренно final (его нельзя незаметно подменить в проекте),
// поэтому транспорт здесь не подменяем: проверяем чистые решающие функции —
// именно в них живёт логика «повторять или нет», и именно она ломается тише
// всего. Работа с сетью проверяется живым сценарием на боевом адресе.

// Повторяемость решается таблицей кодов — проверяем её напрямую.
check('исчерпанная корзина запросов повторяется', B24::isRetryable(503, 'QUERY_LIMIT_EXCEEDED', false));
// Блокировка по накопленному времени метода снимается корзинами по 10 минут
// (limits.md → operating_reset_at). Повтор через 1-2-4 секунды упирается в ту же
// блокировку: три сожжённые попытки и лишние 7 секунд ожидания человеку.
check('блокировка по времени метода НЕ повторяется вслепую',
    !B24::isRetryable(429, 'OPERATION_TIME_LIMIT', false)
    && !B24::isRetryable(429, 'OPERATION_TIME_LIMIT', true));
// Ручная блокировка REST у портала снимается только обращением в поддержку —
// повторять её значит держать воркер и грузить уже перегруженный портал.
check('ручная блокировка REST не повторяется даже у чтения',
    !B24::isRetryable(503, 'OVERLOAD_LIMIT', true));
// А обычная перегрузка (5xx без кода) у чтения по-прежнему повторяется.
check('обычная 5xx у чтения повторяется', B24::isRetryable(503, 'INTERNAL_SERVER_ERROR', true));
check('обычная ошибка данных не повторяется',     !B24::isRetryable(400, 'ERROR_MANDATORY_FIELD', true));
// ── Отказ сервера авторизации: разные причины — разные действия человека ──
// Пока текст был один на всех, экран «Состояние» одинаково говорил «не удалось
// обновить токен» и при протухшем ключе (лечится переустановкой за минуту), и при
// чужих кредах в конфиге (переустановка не поможет вовсе).
check('протухший ключ доступа зовёт переустановить приложение',
    strpos(oauthRefusalText('invalid_grant'), 'Переустановите') !== false);
check('нераспознанное приложение зовёт проверить установку и креды',
    strpos(oauthRefusalText('invalid_client'), 'код и ключ приложения') !== false
    && strpos(oauthRefusalText('invalid_client'), 'Переустановите') === false);
check('нехватка прав названа своими словами',
    strpos(oauthRefusalText('insufficient_scope'), 'права') !== false);
check('незнакомый код не выдаётся за понятый',
    strpos(oauthRefusalText('some_new_code', 'подробности'), 'some_new_code') !== false);
check('пустой код не превращается в пустое сообщение',
    oauthRefusalText('', '') !== '' && oauthRefusalText('', 'текст портала') === 'текст портала');

check('оборванная связь повторяется у чтения',    B24::isRetryable(0, 'http_error', true));
check('оборванная связь НЕ повторяется у записи', !B24::isRetryable(0, 'http_error', false),
    'иначе потерянный ответ на crm.item.add создаёт вторую запись');
check('пятисотые у записи не повторяются',        !B24::isRetryable(502, '', false));

check('список распознаётся как читающий метод', B24::isReadOnlyMethod('crm.item.list'));
check('добавление распознаётся как изменяющее', !B24::isReadOnlyMethod('crm.item.add'));
check('слово list внутри неизвестной мутации не разрешает сетевой повтор',
    !B24::isReadOnlyMethod('crm.item.list.update'));
check('слово get внутри неизвестной мутации не разрешает сетевой повтор',
    !B24::isReadOnlyMethod('lists.element.get.delete'));
check('неизвестные custom info/current/stat/check fail-closed',
    !B24::isReadOnlyMethod('custom.sideeffect.info')
    && !B24::isReadOnlyMethod('custom.sideeffect.current')
    && !B24::isReadOnlyMethod('custom.sideeffect.stat')
    && !B24::isReadOnlyMethod('custom.sideeffect.check'));
check('ровно штатные singleton read-методы остаются читающими',
    B24::isReadOnlyMethod('app.info')
    && B24::isReadOnlyMethod('user.current')
    && B24::isReadOnlyMethod('profile')
    && B24::isReadOnlyMethod('scope'));
check('похожие на scope неизвестные методы остаются fail-closed',
    !B24::isReadOnlyMethod('custom.scope')
    && !B24::isReadOnlyMethod('scope.update'));
check('документированные terminal read verbs остаются читающими',
    B24::isReadOnlyMethod('crm.item.get')
    && B24::isReadOnlyMethod('crm.item.list')
    && B24::isReadOnlyMethod('crm.item.fields')
    && B24::isReadOnlyMethod('crm.item.search'));

// BX13-09: cURL отдаёт тело кусками. Проверяем сам streaming receiver без сети:
// limit+1 chunk не добавляется даже временно к уже накопленной строке.
$responseBelow = new B24ResponseBuffer(10);
$belowWrites = [$responseBelow->write(null, '1234'), $responseBelow->write(null, '56789')];
check('B24 response cap: limit-1 принимается чанками',
    $belowWrites === [4, 5] && !$responseBelow->tooLarge()
        && $responseBelow->bytes() === 9 && $responseBelow->body() === '123456789',
    json_encode($belowWrites) . ', bytes=' . $responseBelow->bytes());
$responseAt = new B24ResponseBuffer(10);
$atWrites = [$responseAt->write(null, '1234'), $responseAt->write(null, '567890')];
check('B24 response cap: ровно limit принимается чанками',
    $atWrites === [4, 6] && !$responseAt->tooLarge()
        && $responseAt->bytes() === 10 && $responseAt->body() === '1234567890',
    json_encode($atWrites) . ', bytes=' . $responseAt->bytes());
$responseOver = new B24ResponseBuffer(10);
$overWrites = [
    $responseOver->write(null, '1234'),
    $responseOver->write(null, '567890'),
    $responseOver->write(null, 'X'),
];
check('B24 response cap: limit+1 обрывает download и не накапливает лишний chunk',
    $overWrites === [4, 6, 0] && $responseOver->tooLarge()
        && $responseOver->bytes() === 10 && $responseOver->body() === '1234567890',
    json_encode($overWrites) . ', bytes=' . $responseOver->bytes());
$tooLargeTyped = b24ResponseTooLargeError();
check('B24 response cap: typed error не содержит raw body',
    ($tooLargeTyped['error'] ?? '') === 'response_too_large'
        && !array_key_exists('raw', $tooLargeTyped)
        && !array_key_exists('body', $tooLargeTyped),
    json_encode($tooLargeTyped, JSON_UNESCAPED_UNICODE));
check('B24 response cap: повтор бессмысленен и запрещён для read и mutation',
    !B24::isRetryable(502, 'response_too_large', false)
        && !B24::isRetryable(502, 'response_too_large', true));

// BX14-01: одного bounded receiver в rawCall недостаточно. OAuth refresh и
// короткий portalGet создают свои cURL handle; инвентарный тест не даёт новому
// handle молча вернуться к CURLOPT_RETURNTRANSFER и неограниченной строке.
$b24CurlSource = (string)file_get_contents(dirname(__DIR__) . '/www/api/b24.php');
$curlHandleCount = preg_match_all('~\bcurl_init\s*\(~', $b24CurlSource, $curlHandleMatches);
$boundedOptionCount = preg_match_all('~\bb24BoundedCurlOptions\s*\(~', $b24CurlSource, $boundedMatches);
$curlCloseCount = preg_match_all('~\bcurl_close\s*\(~', $b24CurlSource, $curlCloseMatches);
check('КАЖДЫЙ cURL handle B24 использует общий bounded response helper и закрывается',
    $curlHandleCount === 3 && $boundedOptionCount === $curlHandleCount + 1
        && $curlCloseCount === $curlHandleCount
        && strpos($b24CurlSource, 'CURLOPT_RETURNTRANSFER => true') === false,
    "handles=$curlHandleCount helper=$boundedOptionCount close=$curlCloseCount");

// Разные семейства методов кладут список под своим ключом. Ошибка тут не падает,
// а показывает человеку одну запись вместо трёхсот — поэтому проверяем каждый вид.
check('список сделок разворачивается из items',
    B24::unwrapList(['items' => [['ID' => 1], ['ID' => 2]]]) === [['ID' => 1], ['ID' => 2]]);
check('список задач разворачивается из tasks',
    B24::unwrapList(['tasks' => [['id' => '8017']]]) === [['id' => '8017']],
    'tasks.task.list отдаёт result.tasks — проверено по документации портала');
check('готовый список берётся как есть', B24::unwrapList([['ID' => 1]]) === [['ID' => 1]]);
check('пустой ответ — это пустой список, а не ошибка', B24::unwrapList([]) === []);
check('незнакомый ключ со списком тоже разворачивается',
    B24::unwrapList(['elements' => [['ID' => 7]]]) === [['ID' => 7]]);
check('ответ без списка не выдаётся за пустой список', B24::unwrapList(['total' => 5, 'time' => []]) === null,
    'иначе выборка молча вернёт «ничего не нашли»');

$readBackEntity = new ReflectionMethod(B24::class, 'readBackEntity');
$readBackEntity->setAccessible(true);
$actualEntity = $readBackEntity->invoke(null, [
    'item' => ['ID' => '7', 'TITLE' => 'Готово', 'META' => ['ACTIVE' => true]],
], 'crm.item.get');
$assertReadBack = new ReflectionMethod(B24::class, 'assertReadBackSubset');
$assertReadBack->setAccessible(true);
$readBackOk = true;
try {
    $assertReadBack->invoke(null, $actualEntity,
        ['id' => 7, 'TITLE' => 'Готово', 'META' => ['ACTIVE' => true]], 'crm.item.get', 'fields');
} catch (Throwable $e) { $readBackOk = false; }
check('readBackChecked снимает item-wrapper и сверяет subset полей без потери типа bool', $readBackOk);

$readBackMismatch = '';
try {
    $assertReadBack->invoke(null, $actualEntity,
        ['TITLE' => 'Другое секретное значение'], 'crm.item.get', 'fields');
} catch (Throwable $e) { $readBackMismatch = $e->getMessage(); }
check('readBackChecked останавливает поток при несовпадении и не раскрывает значения в ошибке',
    strpos($readBackMismatch, 'fields.TITLE') !== false
    && strpos($readBackMismatch, 'Другое секретное значение') === false,
    $readBackMismatch);

// Delete/action без универсального get используют закрытый реестр официальных
// контрактов ответа. Business-код не может передать свой `fn()=>true`.
$verifiedPolicy = new ReflectionMethod(B24::class, 'verifiedMutationPolicy');
$verifiedPolicy->setAccessible(true);
$verifiedResult = new ReflectionMethod(B24::class, 'assertVerifiedMutationResult');
$verifiedResult->setAccessible(true);
$crmDeletePolicy = $verifiedPolicy->invoke(null, 'delete', 'crm.item.delete');
$taskDeletePolicy = $verifiedPolicy->invoke(null, 'delete', 'tasks.task.delete');
$workflowPolicy = $verifiedPolicy->invoke(null, 'action', 'bizproc.workflow.start');
$uploadPolicy = $verifiedPolicy->invoke(null, 'action', 'disk.folder.uploadfile');
$verifiedContractsOk = true;
try {
    $verifiedResult->invoke(null, 'crm.item.delete', ['entityTypeId' => 2, 'id' => 7], [], $crmDeletePolicy);
    $verifiedResult->invoke(null, 'tasks.task.delete', ['taskId' => 7], ['task' => true], $taskDeletePolicy);
    $verifiedResult->invoke(null, 'bizproc.workflow.start',
        ['TEMPLATE_ID' => 1, 'DOCUMENT_ID' => ['crm', 'CCrmDocumentDeal', 'DEAL_7']],
        ['result' => 'workflow-id'], $workflowPolicy);
    $verifiedResult->invoke(null, 'disk.folder.uploadfile',
        ['id' => 3, 'fileContent' => ['a.txt', 'YQ==']], ['ID' => '9'], $uploadPolicy);
} catch (Throwable $e) { $verifiedContractsOk = false; }
check('встроенные delete/action-контракты принимают только документированный успех', $verifiedContractsOk);

$falseDeleteAccepted = true;
try {
    $verifiedResult->invoke(null, 'tasks.task.delete', ['taskId' => 7], ['task' => false], $taskDeletePolicy);
} catch (Throwable $e) { $falseDeleteAccepted = false; }
check('tasks.task.delete с task=false не выдаётся за удаление', !$falseDeleteAccepted);

$unknownVerifiedAccepted = true;
try { $verifiedPolicy->invoke(null, 'action', 'tasks.task.complete'); }
catch (Throwable $e) { $unknownVerifiedAccepted = false; }
check('неизвестный action требует расширить защищённый реестр и тесты', !$unknownVerifiedAccepted);

// Пакет больше 50 команд портал не принимает. Тихо порезать здесь нельзя:
// вернулся бы ответ последнего куска как ответ на весь пакет.
storeWrite(B24_TOKENS_FILE, ['access_token' => 'a', 'refresh_token' => 'r',
    'domain' => 'x.bitrix24.ru', 'member_id' => 'M', 'expires_at' => time() + 3600]);
$tooBig = [];
for ($i = 0; $i < 51; $i++) $tooBig['c' . $i] = ['crm.item.get', ['id' => $i]];
$batchErr = null;
try { b24()->batch($tooBig); } catch (Throwable $e) { $batchErr = $e->getMessage(); }
check('пакет больше 50 команд отклоняется с подсказкой, а не режется молча',
    $batchErr !== null && strpos($batchErr, 'batchAll') !== false, (string)$batchErr);

// batch c halt=0 может вернуть только часть ключей. Пропавший ключ
// нельзя принять за пустой успех: выше по цепочке он становится partial data.
$decodeBatch = new ReflectionMethod(B24::class, 'decodeBatchChunk');
$chunk = [
    'a' => ['crm.item.get', ['id' => 1]],
    'b' => ['crm.item.get', ['id' => 2]],
];
[$decoded, $decodedErrors] = $decodeBatch->invoke(null, $chunk, [
    'result' => ['result' => ['a' => ['item' => ['id' => 1]]], 'result_error' => []],
]);
check('batchAll помечает каждый пропавший ключ как ошибку',
    isset($decoded['a']) && isset($decodedErrors['b']) && !isset($decoded['b']),
    json_encode([$decoded, $decodedErrors], JSON_UNESCAPED_UNICODE));

[$decodedBoth, $errorsBoth] = $decodeBatch->invoke(null, ['a' => $chunk['a']], [
    'result' => [
        'result' => ['a' => ['item' => ['id' => 1]]],
        'result_error' => ['a' => ['error' => 'DENIED', 'error_description' => 'нет прав']],
    ],
]);
check('противоречивый batch-ключ fail-closed считается ошибкой',
    $decodedBoth === [] && ($errorsBoth['a'] ?? '') === 'нет прав');

[$decodedTop, $errorsTop] = $decodeBatch->invoke(null, $chunk, [
    'error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'лимит',
]);
check('отказ всего batch помечает все запрошенные команды',
    $decodedTop === [] && count($errorsTop) === 2 && ($errorsTop['a'] ?? '') === 'лимит');

// Быстрая пагинация должна доказать, что каждая страница полная и ID не повторяются.
$validatePage = new ReflectionMethod(B24::class, 'validateBatchedPage');
[$validRows, $seenIds] = $validatePage->invoke(null,
    ['items' => [['ID' => '2'], ['ID' => 3]]], 2, 'crm.item.list', 'ID', [1 => true]);
check('fetchAllBatched принимает полную страницу с уникальными ID',
    count($validRows) === 2 && count($seenIds) === 3);

$shortPageError = null;
try {
    $validatePage->invoke(null, ['items' => [['ID' => 2]]], 2, 'crm.item.list', 'ID', [1 => true]);
} catch (Throwable $e) { $shortPageError = $e->getMessage(); }
check('fetchAllBatched отклоняет укороченную страницу',
    is_string($shortPageError) && strpos($shortPageError, 'неполна') !== false,
    (string)$shortPageError);

$duplicatePageError = null;
try {
    $validatePage->invoke(null, ['items' => [['ID' => 1]]], 1, 'crm.item.list', 'ID', [1 => true]);
} catch (Throwable $e) { $duplicatePageError = $e->getMessage(); }
check('fetchAllBatched отклоняет дубль ID между страницами',
    is_string($duplicatePageError) && strpos($duplicatePageError, 'повторно') !== false,
    (string)$duplicatePageError);
$zeroLimitError = null;
try { b24()->fetchAllBatched('crm.item.list', [], 0); }
catch (Throwable $e) { $zeroLimitError = $e->getMessage(); }
check('fetchAllBatched отклоняет нулевой hardLimit до сетевого вызова',
    is_string($zeroLimitError) && strpos($zeroLimitError, 'hardLimit') !== false,
    (string)$zeroLimitError);
$pageParams = new ReflectionMethod(B24::class, 'batchedPageParams');
$normalizedFirst = $pageParams->invoke(null, [
    'START' => 900, 'Start' => 700, 'start' => 500,
    'FILTER' => ['ACTIVE' => true], 'select' => ['ID'],
], 0);
$normalizedNext = $pageParams->invoke(null, [
    'START' => 900, 'filter' => ['ID' => 7],
], 50);
check('fetchAllBatched удаляет все START/start aliases и ставит один cursor',
    $normalizedFirst === [
        'FILTER' => ['ACTIVE' => true], 'select' => ['ID'], 'start' => 0,
    ] && $normalizedNext === ['filter' => ['ID' => 7], 'start' => 50],
    json_encode([$normalizedFirst, $normalizedNext], JSON_UNESCAPED_UNICODE));

// ── listStats(): объём работы для полосы прогресса ───────────────────────
// Метод отдаёт только числа, поэтому ошибиться в нём тише всего: заниженный
// крайний ID обрывает массовую сверку на середине, а вызывающий считает, что
// прошёл весь список.
$statsParams = new ReflectionMethod(B24::class, 'listStatsParams');
$statsNormalized = $statsParams->invoke(null, [
    'entityTypeId' => 4, 'START' => 900, 'start' => 500,
    'ORDER' => ['title' => 'ASC'], 'FILTER' => ['ACTIVE' => true], 'filter' => ['>id' => 7],
], 'crm.item.list', 'id');
check('listStats ставит свои start/order и сводит фильтр вызывающего в один ключ',
    $statsNormalized === [
        'entityTypeId' => 4,
        'filter' => ['ACTIVE' => true, '>id' => 7],
        'order'  => ['id' => 'DESC'],
        'select' => ['id'],
        'start'  => 0,
    ], json_encode($statsNormalized, JSON_UNESCAPED_UNICODE));

$statsKeepsSelect = $statsParams->invoke(null, ['select' => ['ID', 'TITLE']], 'crm.company.list', 'ID');
check('listStats не навязывает свой select, если вызывающий задал его сам',
    ($statsKeepsSelect['select'] ?? null) === ['ID', 'TITLE']);

$decodeStats = new ReflectionMethod(B24::class, 'decodeListStats');
check('listStats берёт total портала и крайний ID первой записи',
    $decodeStats->invoke(null, ['total' => '1204', 'result' => ['items' => [['id' => 980], ['id' => 12]]]],
        'crm.item.list', 'id') === ['total' => 1204, 'maxId' => 980]);
check('listStats на пустом списке отдаёт нули, а не отказ',
    $decodeStats->invoke(null, ['total' => 0, 'result' => ['items' => []]], 'crm.item.list', 'id')
        === ['total' => 0, 'maxId' => 0]);

$statsNoTotal = null;
try { $decodeStats->invoke(null, ['result' => ['items' => [['id' => 5]]]], 'crm.item.list', 'id'); }
catch (Throwable $e) { $statsNoTotal = $e->getMessage(); }
check('listStats отклоняет ответ без total — прогресс не на чем строить',
    is_string($statsNoTotal) && strpos($statsNoTotal, 'total') !== false, (string)$statsNoTotal);

$statsEmptyWithTotal = null;
try { $decodeStats->invoke(null, ['total' => 37, 'result' => ['items' => []]], 'crm.item.list', 'id'); }
catch (Throwable $e) { $statsEmptyWithTotal = $e->getMessage(); }
check('listStats не верит «записей 37, страница пуста»',
    is_string($statsEmptyWithTotal) && strpos($statsEmptyWithTotal, 'ни одной') !== false,
    (string)$statsEmptyWithTotal);

$statsWrongOrder = null;
try {
    $decodeStats->invoke(null, ['total' => 9, 'result' => ['items' => [['id' => 3], ['id' => 77]]]],
        'crm.item.list', 'id');
} catch (Throwable $e) { $statsWrongOrder = $e->getMessage(); }
check('listStats ловит проигнорированную порталом сортировку по убыванию',
    is_string($statsWrongOrder) && strpos($statsWrongOrder, 'не по убыванию') !== false,
    (string)$statsWrongOrder);

// Курсорный fetchAll тоже не имеет права копить одну страницу до maxPages.
$cursorPage = new ReflectionMethod(B24::class, 'cursorPageLastId');
check('курсор fetchAll продвигается строго возрастающими ID',
    $cursorPage->invoke(null, [['ID' => 11], ['ID' => 12]], 'crm.item.list', 'ID', 10) === 12);
$stuckCursorError = null;
try {
    $cursorPage->invoke(null, [['ID' => 10]], 'crm.item.list', 'ID', 10);
} catch (Throwable $e) { $stuckCursorError = $e->getMessage(); }
check('fetchAll сразу отклоняет недвижный курсор',
    is_string($stuckCursorError) && strpos($stuckCursorError, 'не двигается') !== false,
    (string)$stuckCursorError);
$zeroPagesError = null;
try { b24()->fetchAll('crm.item.list', [], 'ID', 0); }
catch (Throwable $e) { $zeroPagesError = $e->getMessage(); }
check('fetchAll не выдаёт пустую partial-выборку при maxPages=0',
    is_string($zeroPagesError) && strpos($zeroPagesError, 'maxPages') !== false,
    (string)$zeroPagesError);
$cursorParams = new ReflectionMethod(B24::class, 'cursorPageParams');
$normalizedCursorParams = $cursorParams->invoke(null, [
    'limit' => 10, 'LIMIT' => 7, 'filter' => ['=ACTIVE' => 'Y'], 'select' => ['ID'],
], 'crm.deal.list', 'ID', 125);
check('fetchAll снимает пользовательский limit и не выдаёт первую короткую страницу за весь список',
    !array_key_exists('limit', $normalizedCursorParams)
    && !array_key_exists('LIMIT', $normalizedCursorParams)
    && ($normalizedCursorParams['start'] ?? null) === -1
    && ($normalizedCursorParams['filter']['>ID'] ?? null) === 125
    && ($normalizedCursorParams['filter']['=ACTIVE'] ?? null) === 'Y');

// Прописные копии тех же ключей. Так пишет и документация Б24, и старый код в
// проектах: FILTER/ORDER/START. Оставшись рядом с нашими строчными, они уходили
// в портал вторым фильтром и второй сортировкой — какая пара победит, зависит от
// метода, и выборка молча оказывалась не той, что просили. Условие проверки: наш
// курсор на месте, чужой сортировки и чужого старта нет, а фильтр ПОЛЬЗОВАТЕЛЯ
// из прописного написания не потерян — иначе «починка» тихо расширила бы выборку.
$upperCursorParams = $cursorParams->invoke(null, [
    'FILTER' => ['=STAGE_ID' => 'NEW'], 'ORDER' => ['DATE_CREATE' => 'DESC'],
    'START' => 250, 'select' => ['ID'],
], 'crm.deal.list', 'ID', 125);
check('fetchAll не отправляет два фильтра и две сортировки сразу',
    !array_key_exists('FILTER', $upperCursorParams)
    && !array_key_exists('ORDER', $upperCursorParams)
    && !array_key_exists('START', $upperCursorParams)
    && ($upperCursorParams['order'] ?? null) === ['ID' => 'ASC']
    && ($upperCursorParams['start'] ?? null) === -1
    && ($upperCursorParams['filter']['>ID'] ?? null) === 125
    && ($upperCursorParams['filter']['=STAGE_ID'] ?? null) === 'NEW',
    json_encode($upperCursorParams, JSON_UNESCAPED_UNICODE));

// Оба написания фильтра разом: строчное побеждает при совпадении ключа, но ни
// одно условие не должно пропасть — иначе выборка вернёт лишние записи.
$bothCursorParams = $cursorParams->invoke(null, [
    'FILTER' => ['=STAGE_ID' => 'NEW', '=TYPE' => 'A'], 'filter' => ['=STAGE_ID' => 'WON'],
], 'crm.deal.list', 'ID', 0);
check('фильтры обоих написаний сливаются, ни одно условие не теряется',
    ($bothCursorParams['filter']['=TYPE'] ?? null) === 'A'
    && ($bothCursorParams['filter']['=STAGE_ID'] ?? null) === 'WON',
    json_encode($bothCursorParams, JSON_UNESCAPED_UNICODE));

// ── НИЖНЯЯ ГРАНИЦА ВЫЗЫВАЮЩЕГО ('>ID' => N) ────────────────────────────────
// Дельта-выборка «дай сделки новее N» приходит тем же ключом, что и наш курсор.
// Пока курсор просто дописывался справа, он на ПЕРВОЙ странице (lastId=0)
// затирал границу нулём — и вызывающий получал ВЕСЬ список, приняв его за
// дельту (находка K4-10, круг 4). Исключения нет, в журнале ничего.
foreach (['filter', 'FILTER'] as $kakNapisano) {
    $granica = $cursorParams->invoke(null, [$kakNapisano => ['>ID' => 500, '=ACTIVE' => 'Y']], 'crm.deal.list', 'ID', 0);
    check('fetchAll не теряет нижнюю границу вызывающего в «' . $kakNapisano . '»',
        ($granica['filter']['>ID'] ?? null) === 500
        && ($granica['filter']['=ACTIVE'] ?? null) === 'Y',
        json_encode($granica, JSON_UNESCAPED_UNICODE));
}
// Курсор ушёл дальше границы — побеждает курсор, иначе обход зациклится.
$granicaPozadi = $cursorParams->invoke(null, ['filter' => ['>ID' => 500]], 'crm.deal.list', 'ID', 900);
check('курсор, ушедший дальше границы вызывающего, не откатывается назад',
    ($granicaPozadi['filter']['>ID'] ?? null) === 900,
    json_encode($granicaPozadi, JSON_UNESCAPED_UNICODE));
// camelCase-семейство: у crm.item.* поле называется 'id', а вызывающий мог
// написать границу любым регистром — терять её нельзя ни в одном написании.
$granicaCamel = $cursorParams->invoke(null, ['filter' => ['>ID' => 500]], 'crm.item.list', 'id', 0);
check('нижняя граница «>ID» подхватывается и когда поле курсора называется «id»',
    ($granicaCamel['filter']['>id'] ?? null) === 500
    && !array_key_exists('>ID', $granicaCamel['filter']),
    json_encode($granicaCamel, JSON_UNESCAPED_UNICODE));

// Сквозная проверка на поддельном портале: 600 записей, просим хвост от 500.
$hvost = static function (array $params): array {
    $pervyjFiltr = null;
    $GLOBALS['__b24_call'] = static function (string $m, array $p) use (&$pervyjFiltr): array {
        if ($pervyjFiltr === null) $pervyjFiltr = $p['filter'] ?? [];
        $posle = (int)($p['filter']['>ID'] ?? 0);
        $stranica = [];
        for ($id = $posle + 1; $id <= 600 && count($stranica) < 50; $id++) $stranica[] = ['ID' => (string)$id];
        return ['result' => $stranica];
    };
    $rows = b24()->fetchAll('crm.deal.list', $params, 'ID');
    unset($GLOBALS['__b24_call']);
    return [$rows, $pervyjFiltr];
};
[$hvostRows, $hvostFiltr] = $hvost(['filter' => ['>ID' => 500]]);
$hvostIds = array_map(static fn(array $r): int => (int)$r['ID'], $hvostRows);
check('fetchAll с filter[«>ID»]=500 возвращает ТОЛЬКО хвост, а не весь список',
    count($hvostRows) === 100 && ($hvostIds ? min($hvostIds) : 0) === 501,
    'получено ' . count($hvostRows) . ', min=' . ($hvostIds ? min($hvostIds) : '—')
    . ', фильтр 1-й страницы: ' . json_encode($hvostFiltr, JSON_UNESCAPED_UNICODE));

// ── ТОТ ЖЕ КОНТРАКТ У КОПИИ ДЛЯ ЧУЖИХ ПРОЕКТОВ ─────────────────────────────
// fetch-all-compat.php кладут ПОВЕРХ проектов со старым движком именно затем,
// чтобы убрать брак «взяли первые 50 и выдали за все». Значит и обещание у него
// то же самое: неполной выборки не бывает — исключение, а не обрезанный список.
// Курсор двигали по МАКСИМУМУ страницы, и два ответа портала проходили молча
// (K4-11, круг 4): страница не по возрастанию ID — часть записей терялась
// навсегда; включающая граница ('>N' понято как '>=N') — записи дублировались.
require_once dirname(__DIR__) . '/www/api/fetch-all-compat.php';

// 1. Портал НЕ понял нашу сортировку (у lists.element.get она зовётся
//    ELEMENT_ORDER, у user.get — парой sort+order) и отдал набор по УБЫВАНИЮ ID.
//    Тогда максимум страницы сразу равен максимуму всего набора: следующий
//    запрос уходит с '>600', приходит пусто — и 550 записей теряются молча.
$portalNaoborot = static function (string $m, array $p): array {
    $posle = (int)($p['filter']['>ID'] ?? 0);
    $rows = [];
    for ($id = 600; $id > $posle && count($rows) < 50; $id--) $rows[] = ['ID' => $id];
    return ['result' => $rows];
};
$oshibkaNaoborot = null; $vydachaNaoborot = null;
try { $vydachaNaoborot = fetchAllCompat($portalNaoborot, 'crm.deal.list'); }
catch (Throwable $e) { $oshibkaNaoborot = $e->getMessage(); }
check('fetchAllCompat падает, а не молча обрезает выборку на несортированной странице',
    is_string($oshibkaNaoborot),
    'вернул ' . (is_array($vydachaNaoborot) ? count($vydachaNaoborot) . ' записей из 600' : 'не массив'));

// 2. Портал понял границу как включающую: каждая страница повторяет последнюю
//    запись предыдущей — максимум растёт, и дубли уезжали вызывающему.
$portalVkluchaya = static function (string $m, array $p): array {
    $posle = (int)($p['filter']['>ID'] ?? 0);
    $rows = [];
    for ($id = max(1, $posle); $id <= 600 && count($rows) < 50; $id++) $rows[] = ['ID' => $id];
    return ['result' => $rows];
};
$oshibkaVkluchaya = null; $vydachaVkluchaya = null;
try { $vydachaVkluchaya = fetchAllCompat($portalVkluchaya, 'crm.deal.list'); }
catch (Throwable $e) { $oshibkaVkluchaya = $e->getMessage(); }
check('fetchAllCompat падает, а не молча дублирует записи на включающей границе',
    is_string($oshibkaVkluchaya),
    is_array($vydachaVkluchaya)
        ? 'строк ' . count($vydachaVkluchaya) . ', уникальных '
          . count(array_unique(array_column($vydachaVkluchaya, 'ID')))
        : 'не массив');

// 3. Исправная выдача по-прежнему проходит целиком — забор не должен ловить своих.
$portalIspravnyj = static function (string $m, array $p): array {
    $posle = (int)($p['filter']['>ID'] ?? 0);
    $rows = [];
    for ($id = $posle + 1; $id <= 120 && count($rows) < 50; $id++) $rows[] = ['ID' => $id];
    return ['result' => $rows];
};
check('исправная выдача портала проходит построчную проверку целиком',
    count(fetchAllCompat($portalIspravnyj, 'crm.deal.list')) === 120);

// ── ИМЕНА ПАРАМЕТРОВ СОРТИРОВКИ И ФИЛЬТРА — ПО ДОКУМЕНТАЦИИ МЕТОДА ──────────
// Курсор безусловно ставил строчные order/filter, а часть списочных методов
// такие имена не знает вовсе (K4-41, круг 4): у lists.element.get сортировка
// зовётся ELEMENT_ORDER, у sonet_group.get — ORDER, фильтр у обоих — FILTER.
// Если портал регистр не нормализует, фильтр вызывающего просто отбрасывается,
// и «элементы с именем %Тест%» превращаются в список целиком — тихо.
$paramsList = $cursorParams->invoke(null, [
    'IBLOCK_TYPE_ID' => 'lists', 'IBLOCK_ID' => 47,
    'FILTER' => ['NAME' => '%Тест%'], 'ELEMENT_ORDER' => ['ID' => 'asc'],
], 'lists.element.get', 'ID', 0);
check('курсор lists.element.get идёт документированными ELEMENT_ORDER и FILTER',
    ($paramsList['ELEMENT_ORDER'] ?? null) === ['ID' => 'ASC']
    && ($paramsList['FILTER']['>ID'] ?? null) === 0
    && ($paramsList['FILTER']['NAME'] ?? null) === '%Тест%'
    && !array_key_exists('order', $paramsList)
    && !array_key_exists('filter', $paramsList),
    json_encode($paramsList, JSON_UNESCAPED_UNICODE));

$paramsSonet = $cursorParams->invoke(null, [
    'ORDER' => ['NAME' => 'ASC'], 'FILTER' => ['%NAME' => 'Про'],
], 'sonet_group.get', 'ID', 120);
check('курсор sonet_group.get идёт документированными ORDER и FILTER',
    ($paramsSonet['ORDER'] ?? null) === ['ID' => 'ASC']
    && ($paramsSonet['FILTER']['>ID'] ?? null) === 120
    && ($paramsSonet['FILTER']['%NAME'] ?? null) === 'Про'
    && !array_key_exists('order', $paramsSonet)
    && !array_key_exists('filter', $paramsSonet),
    json_encode($paramsSonet, JSON_UNESCAPED_UNICODE));

// user.get сортировку картой выразить НЕЛЬЗЯ (документированы две строки:
// sort — поле, order — направление), значит курсорный обход тут недоказуем.
// Молча отправлять непонятную методу сортировку хуже отказа: отказ называет
// готовую замену, а молчание отдаёт неполный список за полный.
$userGetError = null;
try { b24()->fetchAll('user.get', ['FILTER' => ['ACTIVE' => 'Y']]); }
catch (Throwable $e) { $userGetError = $e->getMessage(); }
check('fetchAll отказывает на user.get и называет замену fetchAllBatched()',
    is_string($userGetError) && strpos($userGetError, 'fetchAllBatched') !== false,
    (string)$userGetError);

// СПРАВОЧНИКИ И ПОЛЯ КУРСОРОМ НЕ ОБХОДЯТСЯ. Их фильтр документирован только по
// равенству: условие «>ID» портал выбрасывает молча, а порядок у списка полей
// идёт по SORT. Пока отказа не было, обход «работал» на стенде с десятком
// записей и падал на живом портале с шестьюдесятью — «курсор ID не двигается
// (220 после 2728)», и приложение не могло начать работу (0921-101006-3f84).
foreach (['crm.status.list', 'crm.company.userfield.list', 'crm.deal.userfield.list'] as $metodBezKursora) {
    $otkaz = null;
    try { b24()->fetchAll($metodBezKursora, ['filter' => ['ENTITY_ID' => 'DEAL_STAGE_2']]); }
    catch (Throwable $e) { $otkaz = $e->getMessage(); }
    check('fetchAll отказывает на ' . $metodBezKursora . ' и называет замену fetchAllBatched()',
        is_string($otkaz) && strpos($otkaz, 'fetchAllBatched') !== false,
        (string)$otkaz);
}

// ОБРАТНЫЙ КРАЙ. Отказ обязан касаться только курсорного обхода: счётчику
// listStats() курсор не нужен — ему хватает одной страницы с total, и запрет
// «сколько всего записей в справочнике» был бы запретом на ровном месте.
$statsSpravochnika = null;
$statsOshibka = null;
$GLOBALS['__b24_call'] = static function (string $m, array $p): array {
    return ['result' => [['ID' => 7]], 'total' => 42];
};
try {
    $statsSpravochnika = b24()->listStats('crm.status.list',
        ['filter' => ['ENTITY_ID' => 'DEAL_STAGE_2']], 'ID');
} catch (Throwable $e) { $statsOshibka = $e->getMessage(); }
unset($GLOBALS['__b24_call']);
check('listStats на crm.status.list по-прежнему считает записи',
    is_array($statsSpravochnika) && (int)($statsSpravochnika['total'] ?? 0) === 42,
    (string)$statsOshibka . json_encode($statsSpravochnika, JSON_UNESCAPED_UNICODE));

// Тот же приём был и в копии для чужих проектов, а она кладётся ровно в те
// проекты, где lists.element.get и встречается чаще всего.
$otpravlenoCompat = null;
fetchAllCompat(static function (string $m, array $p) use (&$otpravlenoCompat): array {
    if ($otpravlenoCompat === null) $otpravlenoCompat = $p;
    return ['result' => []];
}, 'lists.element.get', ['IBLOCK_ID' => 47, 'FILTER' => ['NAME' => '%Тест%']]);
check('fetchAllCompat тоже шлёт lists.element.get документированные ELEMENT_ORDER и FILTER',
    is_array($otpravlenoCompat)
    && ($otpravlenoCompat['ELEMENT_ORDER'] ?? null) === ['ID' => 'ASC']
    && ($otpravlenoCompat['FILTER']['>ID'] ?? null) === 0
    && ($otpravlenoCompat['FILTER']['NAME'] ?? null) === '%Тест%'
    && !array_key_exists('order', $otpravlenoCompat)
    && !array_key_exists('filter', $otpravlenoCompat),
    json_encode($otpravlenoCompat, JSON_UNESCAPED_UNICODE));

// Ревью 23.09.2026 (dubli-3): копия обхода для старых проектов не знала методов,
// которые курсором не обходятся (фильтр только по равенству), и падала «курсор
// не двигается» вместо отказа с заменой; курсор клала первым ключом, а ядро и
// заглушки портала — последним.
$compatCalls = 0;
$compatRefusals = [];
foreach (['crm.status.list', 'crm.deal.userfield.list'] as $compatMethod) {
    try {
        fetchAllCompat(static function () use (&$compatCalls): array { $compatCalls++; return ['result' => []]; },
            $compatMethod, []);
        $compatRefusals[$compatMethod] = 'не отказал';
    } catch (InvalidArgumentException $e) {
        $compatRefusals[$compatMethod] = strpos($e->getMessage(), 'fetchAllBatched') !== false ? 'ok' : $e->getMessage();
    }
}
check('fetchAllCompat отказывает до сети там же, где ядро, и называет замену',
    $compatCalls === 0 && $compatRefusals === ['crm.status.list' => 'ok', 'crm.deal.userfield.list' => 'ok'],
    json_encode([$compatCalls, $compatRefusals], JSON_UNESCAPED_UNICODE));
$compatSent = null;
fetchAllCompat(static function (string $m, array $p) use (&$compatSent): array {
    if ($compatSent === null) $compatSent = $p;
    return ['result' => []];
}, 'crm.deal.list', ['filter' => ['=STAGE_ID' => 'NEW']]);
$compatFilterKeys = array_keys((array)($compatSent['filter'] ?? []));
check('fetchAllCompat кладёт курсор последним ключом фильтра, как ядро',
    $compatFilterKeys === ['=STAGE_ID', '>ID'], json_encode($compatFilterKeys));

// Ревью 23.09.2026 (dubli-1): быстрая выгрузка передавала строчные filter/order
// как есть, и у lists.element.get портал условие отбрасывал — на экран уезжал
// весь список под видом отобранного, с «доказанной» полнотой.
$batchedSent = null;
$GLOBALS['__b24_call'] = static function (string $m, array $p) use (&$batchedSent): array {
    if ($batchedSent === null) $batchedSent = $p;
    return ['result' => [], 'total' => 0];
};
(new B24('local.test.app', 'secret'))->fetchAllBatched('lists.element.get', [
    'IBLOCK_TYPE_ID' => 'lists', 'IBLOCK_ID' => 47,
    'filter' => ['%NAME' => 'Тест'], 'order' => ['NAME' => 'ASC'],
]);
unset($GLOBALS['__b24_call']);
check('fetchAllBatched шлёт lists.element.get документированные FILTER и ELEMENT_ORDER',
    is_array($batchedSent)
    && ($batchedSent['FILTER'] ?? null) === ['%NAME' => 'Тест']
    && ($batchedSent['ELEMENT_ORDER'] ?? null) === ['NAME' => 'ASC']
    && !array_key_exists('filter', $batchedSent) && !array_key_exists('order', $batchedSent),
    json_encode($batchedSent, JSON_UNESCAPED_UNICODE));

// Регрессия: обычные списки CRM/задач как ходили строчными order/filter, так и ходят.
$paramsCrm = $cursorParams->invoke(null, ['filter' => ['=STAGE_ID' => 'NEW']], 'crm.deal.list', 'ID', 0);
check('обычные списки CRM по-прежнему ходят строчными order/filter',
    ($paramsCrm['order'] ?? null) === ['ID' => 'ASC']
    && ($paramsCrm['filter']['=STAGE_ID'] ?? null) === 'NEW'
    && !array_key_exists('ORDER', $paramsCrm) && !array_key_exists('FILTER', $paramsCrm),
    json_encode($paramsCrm, JSON_UNESCAPED_UNICODE));

// loadByIds возвращает partial map только по явному opt-in через пятый аргумент.
$partialContract = new ReflectionMethod(B24::class, 'enforcePartialLoadContract');
$strictLoadError = null;
try {
    $partialContract->invoke(null, 'crm.item.get', [2 => 'нет прав'], false);
} catch (Throwable $e) { $strictLoadError = $e->getMessage(); }
check('loadByIds без $errors не возвращает partial map',
    is_string($strictLoadError) && strpos($strictLoadError, 'частичный ответ запрещён') !== false,
    (string)$strictLoadError);
$optInLoadError = null;
try {
    $partialContract->invoke(null, 'crm.item.get', [2 => 'нет прав'], true);
} catch (Throwable $e) { $optInLoadError = $e->getMessage(); }
check('loadByIds с переданным $errors разрешает обрабатываемый partial map',
    $optInLoadError === null, (string)$optInLoadError);

// Ревью 23.09.2026 (otkazy-3): имя, которое портал не отдал из-за СБОЯ пакета,
// показывалось как «сотрудник №17» — точно так же, как удалённый сотрудник, и
// отчёт уезжал заказчику правдоподобно неверным. Сбой обязан быть отличим.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'a', 'refresh_token' => 'r', 'domain' => 'portal.invalid',
    'member_id' => 'M', 'client_endpoint' => 'https://portal.invalid/rest/',
    'install_generation' => 'g', 'expires_at' => time() + 3600,
]);
$namesClient = new B24('local.test.app', 'secret');
$GLOBALS['__b24_call'] = static function (string $method, array $params): array {
    if ($method !== 'batch') return ['result' => []];
    $result = []; $errors = [];
    foreach ((array)($params['cmd'] ?? []) as $key => $query) {
        if (strpos((string)$query, '917001') !== false) {
            $result[$key] = [['ID' => '917001', 'LAST_NAME' => 'Иванова', 'NAME' => 'Анна']];
        } elseif (strpos((string)$query, '917017') !== false) {
            $errors[$key] = ['error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'Too many requests'];
        } else {
            $result[$key] = [];
        }
    }
    return ['result' => ['result' => $result, 'result_error' => $errors]];
};
$namesUnread = null;
$namesOut = $namesClient->userNames([917001, 917017, 917099], $namesUnread);
unset($GLOBALS['__b24_call']);
check('сбой чтения имени отличим от удалённого сотрудника и назван вызывающему',
    ($namesOut[917001] ?? '') === 'Иванова Анна'
    && ($namesOut[917099] ?? '') === 'сотрудник №917099'
    && ($namesOut[917017] ?? '') !== 'сотрудник №917017'
    && strpos((string)($namesOut[917017] ?? ''), 'не прочитано') !== false
    && $namesUnread === [917017],
    json_encode([$namesOut, $namesUnread], JSON_UNESCAPED_UNICODE));

// ── Read-back денег и дат ─────────────────────────────────────────────────
// Ревью 05.08.2026: шаблон обязывает слать деньги и даты через fmt.php (строки
// '999.90' / '2026-08-05'), а портал в ответе возвращает число 999.9 и ISO-дату.
// Из-за этого штатный путь runOnce → callChecked → readBackChecked падал на любой
// сделке с суммой: запись в портале сделана, аудита нет, ключ операции навсегда
// «исход неизвестен». Сверка стала доменной, но чужое значение обязано падать.
$readBackAssert = new ReflectionMethod('B24', 'assertReadBackSubset');
$readBackAssert->setAccessible(true);
$readBackPasses = static function (array $actual, array $expected) use ($readBackAssert): bool {
    try { $readBackAssert->invoke(null, $actual, $expected, 'crm.item.get', 'fields'); return true; }
    catch (Throwable $e) { return false; }
};
check('read-back признаёт сумму из fmt.php и число портала одной суммой',
    $readBackPasses(['opportunity' => 999.9], ['opportunity' => moneyForB24(money('999,90'))]));
check('read-back признаёт день из fmt.php и ISO-дату портала одним днём',
    $readBackPasses(['begindate' => '2026-08-05T02:00:00+02:00'], ['begindate' => b24Day('2026-08-05T10:00:00+03:00')]));
check('read-back по-прежнему ловит ДРУГУЮ сумму',
    !$readBackPasses(['opportunity' => 999.9], ['opportunity' => '1000.00']));
check('read-back по-прежнему ловит ДРУГОЙ день',
    !$readBackPasses(['begindate' => '2026-08-06T02:00:00+02:00'], ['begindate' => '2026-08-05']));
check('read-back не начал считать равными произвольные строки',
    !$readBackPasses(['title' => 'Сделка Б'], ['title' => 'Сделка А']));
check('read-back не ломает сверку целых идентификаторов',
    $readBackPasses(['id' => '77'], ['id' => 77]) && !$readBackPasses(['id' => '78'], ['id' => 77]));

// ── Пакет любого размера действительно режется и собирается ────────────────
// Раньше batchAll() не вызывался ни одной проверкой: ломались резка на куски по
// 50, склейка ответов и сохранность ключей вызывающего — а прогон оставался
// зелёным. Проверяем НАСКВОЗЬ, подменив только обращение к порталу.
testWipe(DATA_ROOT);
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'a', 'refresh_token' => 'r', 'domain' => 'portal.invalid',
    'member_id' => 'M', 'client_endpoint' => 'https://portal.invalid/rest/',
    'install_generation' => 'g', 'expires_at' => time() + 3600,
]);
$batchClient = new B24('local.test.app', 'secret');
$seenChunks = [];
$GLOBALS['__b24_call'] = static function (string $method, array $params) use (&$seenChunks): array {
    if ($method !== 'batch') return ['result' => []];
    $cmd = (array)($params['cmd'] ?? []);
    $seenChunks[] = count($cmd);
    $answer = [];
    foreach ($cmd as $key => $query) $answer[$key] = ['echo' => $key];
    return ['result' => ['result' => $answer, 'result_error' => []]];
};
$commands = [];
for ($i = 1; $i <= 60; $i++) $commands['ключ' . $i] = ['crm.item.get', ['id' => $i]];
$batched = $batchClient->batchAll($commands);
unset($GLOBALS['__b24_call']);
check('пакет из 60 команд уходит двумя обращениями, не больше 50 в каждом',
    count($seenChunks) === 2 && max($seenChunks) <= 50 && array_sum($seenChunks) === 60,
    implode('+', $seenChunks));
check('в ответе присутствуют ВСЕ ключи вызывающего и ни один не потерян',
    count($batched['result']) === 60
    && isset($batched['result']['ключ1'], $batched['result']['ключ60'])
    && $batched['errors'] === []);
// Прямой batch() больше 50 обязан отказывать: иначе половина команд «выполнится»
// только на бумаге — портал молча обрежет пакет.
$tooBig = null;
try { $batchClient->batch(array_slice($commands, 0, 51, true)); }
catch (Throwable $e) { $tooBig = $e->getMessage(); }
check('пакет больше предела портала отвергается громко',
    is_string($tooBig) && strpos($tooBig, 'batchAll') !== false);
testWipe(DATA_ROOT);

// ── МЕТКА ОБРАЩЕНИЯ ──────────────────────────────────────────────────────────
// Один логический вызов оставляет в журнале несколько записей: запрос, повтор
// после отказа, обновление токена посередине, разбор ответа пакета. Без общей
// метки это выглядит как разные события, и разбор «что было в 14:07» делается
// глазами по времени. Проверяем сам инвариант: метка живёт ровно на операцию,
// у соседних операций она разная, а вложенная не затирает внешнюю.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'a', 'refresh_token' => 'r',
    'domain' => 'portal.invalid', 'client_endpoint' => 'https://portal.invalid/rest/',
    'member_id' => 'M-REF', 'expires_at' => time() + 3600,
]);
$refSeen = [];
$refInner = null;
$b24ref = b24();
$GLOBALS['__b24_call'] = static function (string $m, array $p) use (&$refSeen, &$refInner, &$b24ref): array {
    $refSeen[] = (string)($GLOBALS['__log_ctx']['обращение'] ?? '');
    // Внутри операции приложение иногда обращается к порталу ещё раз — так
    // ведёт себя обновление токена. Внешняя метка обязана пережить вложенную.
    if ($m === 'crm.deal.list' && $refInner === null) {
        $refInner = 'ждём';
        $b24ref->call('user.current', []);
        $refInner = (string)($GLOBALS['__log_ctx']['обращение'] ?? '');
    }
    return ['result' => ['ok' => true]];
};
$b24ref->call('crm.deal.list', []);
$vtoraya = [];
$GLOBALS['__b24_call'] = static function (string $m, array $p) use (&$vtoraya): array {
    $vtoraya[] = (string)($GLOBALS['__log_ctx']['обращение'] ?? '');
    return ['result' => ['ok' => true]];
};
$b24ref->call('crm.deal.list', []);
unset($GLOBALS['__b24_call']);

check('У ОПЕРАЦИИ С ПОРТАЛОМ ЕСТЬ МЕТКА',
    (bool)preg_match('~^[0-9a-f]{8}$~', (string)($refSeen[0] ?? '')), json_encode($refSeen));
check('вложенное обращение получает свою метку, а не чужую',
    isset($refSeen[1]) && $refSeen[1] !== '' && $refSeen[1] !== $refSeen[0], json_encode($refSeen));
check('ПОСЛЕ ВЛОЖЕННОГО ОБРАЩЕНИЯ ВНЕШНЯЯ МЕТКА ВОССТАНОВЛЕНА',
    $refInner === (string)($refSeen[0] ?? 'нет'), 'внешняя: ' . json_encode($refSeen[0] ?? null) . ', после вложенной: ' . json_encode($refInner));
check('у следующей операции метка другая',
    ($vtoraya[0] ?? '') !== '' && ($vtoraya[0] ?? '') !== ($refSeen[0] ?? ''), json_encode($vtoraya));
check('в метке нет ни адреса портала, ни токена',
    (bool)preg_match('~^[0-9a-f]{8}$~', (string)($refSeen[0] ?? '')), (string)($refSeen[0] ?? ''));
check('после операции метка не остаётся висеть в журнале',
    !isset($GLOBALS['__log_ctx']['обращение']),
    json_encode($GLOBALS['__log_ctx'] ?? [], JSON_UNESCAPED_UNICODE));

// ── ОТМЕТКА «ИЗМЕНЕНИЯ НАЧАТЫ» ───────────────────────────────────────────────
// От неё зависит текст, который человек прочитает при аварийном завершении.
// Раньше страница обещала «данные не пострадали» даже после отправленной
// мутации; теперь обещание опирается на факт, и факт этот ставится ЗДЕСЬ.
// Ошибка в любую сторону дорогая: не поставили — соврали про сохранность;
// поставили на каждое чтение — предупреждение горит всегда и его перестают читать.
$otmetka = static function (callable $work): array {
    unset($GLOBALS['__changes_started']);
    $GLOBALS['__b24_call'] = static function (string $m, array $p): array {
        return ['result' => ['ok' => true]];
    };
    try { $work(); } catch (Throwable $e) { /* нас интересует только отметка */ }
    unset($GLOBALS['__b24_call']);
    $was = $GLOBALS['__changes_started'] ?? [];
    unset($GLOBALS['__changes_started']);
    return is_array($was) ? $was : [];
};
$b24otm = b24();
check('чтение портала не помечается изменением',
    $otmetka(static function () use ($b24otm) { $b24otm->call('crm.deal.list', []); }) === [],
    'после crm.deal.list');
check('ИЗМЕНЯЮЩИЙ ВЫЗОВ ПОМЕЧАЕТСЯ ДО ОТПРАВКИ',
    $otmetka(static function () use ($b24otm) { $b24otm->call('crm.deal.add', ['fields' => []]); })
        === ['crm.deal.add'],
    'после crm.deal.add');
check('пакет из одних чтений изменением не считается',
    $otmetka(static function () use ($b24otm) {
        $b24otm->batch(['a' => ['crm.deal.list', []], 'b' => ['user.get', []]]);
    }) === [], 'после пакета чтений');
check('ПАКЕТ С ОДНОЙ МУТАЦИЕЙ СЧИТАЕТСЯ ИЗМЕНЕНИЕМ',
    $otmetka(static function () use ($b24otm) {
        $b24otm->batch(['a' => ['crm.deal.list', []], 'b' => ['crm.deal.update', ['id' => 1]]]);
    }) === ['batch'], 'после пакета с crm.deal.update');
check('незнакомый метод считается изменением, а не чтением',
    $otmetka(static function () use ($b24otm) { $b24otm->call('nekiy.novyj.metod', []); })
        === ['nekiy.novyj.metod'], 'после незнакомого метода');
check('отметка не зависит от того, ответил ли портал',
    $otmetka(static function () use ($b24otm) {
        $GLOBALS['__b24_call'] = static function (string $m, array $p): array {
            throw new RuntimeException('связь оборвалась');
        };
        $b24otm->call('crm.deal.add', ['fields' => []]);
    }) === ['crm.deal.add'], 'мутация с оборванной связью');

// ── ГРАНИЦА СТРАНИЦЫ: 51 и 101 объект ───────────────────────────────────────
// Самый частый дефект парка: первая страница выдана за весь список. Ломается это
// ровно на границах — 50 записей влезают, 51-я начинает вторую страницу.
// Проверяем на настоящем обходе, а не на разборе одной страницы.
$stranicy = static function (int $vsego) use (&$b24otm): array {
    $otdano = [];
    $GLOBALS['__b24_call'] = static function (string $m, array $p) use ($vsego, &$otdano): array {
        // Заглушка ведёт себя как портал: отдаёт по 50 записей, курсор — по ID.
        $posle = 0;
        foreach ((array)($p['filter'] ?? []) as $k => $v) if (strpos((string)$k, 'ID') !== false) $posle = (int)$v;
        $stranica = [];
        for ($id = $posle + 1; $id <= $vsego && count($stranica) < 50; $id++) {
            $stranica[] = ['ID' => (string)$id];
        }
        $otdano[] = count($stranica);
        return ['result' => ['items' => $stranica]];
    };
    $rows = b24()->fetchAll('crm.item.list', ['entityTypeId' => 2], 'ID');
    unset($GLOBALS['__b24_call']);
    return [$rows, $otdano];
};

[$rows51, $pages51] = $stranicy(51);
check('ПЯТЬДЕСЯТ ОДНА ЗАПИСЬ ЧИТАЕТСЯ ЦЕЛИКОМ, А НЕ ПЕРВОЙ СТРАНИЦЕЙ',
    count($rows51) === 51, 'получено ' . count($rows51) . ', страницы: ' . implode('+', $pages51));
check('за 51 записью приложение сходило дважды, а не один раз',
    count($pages51) >= 2, 'страниц: ' . count($pages51));

[$rows101, $pages101] = $stranicy(101);
check('СТО ОДНА ЗАПИСЬ ЧИТАЕТСЯ ЦЕЛИКОМ',
    count($rows101) === 101, 'получено ' . count($rows101) . ', страницы: ' . implode('+', $pages101));
check('идентификаторы не повторяются между страницами',
    count(array_unique(array_map(static fn(array $r): string => (string)$r['ID'], $rows101))) === 101,
    'уникальных: ' . count(array_unique(array_map(static fn(array $r): string => (string)$r['ID'], $rows101))));

[$rows50, $pages50] = $stranicy(50);
check('ровно пятьдесят записей тоже читаются целиком',
    count($rows50) === 50, 'получено ' . count($rows50));

// ── ОТКАЗАВШИЕ КОМАНДЫ ПАКЕТА НЕ СТАНОВЯТСЯ НУЛЁМ ───────────────────────────
// Ещё один частый дефект парка: часть команд пакета упала, а код читает ответ
// так, будто пришли нули или пустые значения, — и показывает человеку отчёт,
// в котором «просто мало данных». Проверяем контракт: неудавшиеся ключи видны
// отдельно и не подменяются пустотой.
$GLOBALS['__b24_call'] = static function (string $m, array $p): array {
    return ['result' => [
        'result' => ['ok1' => ['item' => ['id' => 1]]],
        'result_error' => ['bad1' => ['error' => 'ACCESS_DENIED', 'error_description' => 'нет прав']],
    ]];
};
$chastichno = b24()->batchAll([
    'ok1'  => ['crm.item.get', ['id' => 1]],
    'bad1' => ['crm.item.get', ['id' => 2]],
]);
unset($GLOBALS['__b24_call']);
check('УДАВШИЕСЯ И ОТКАЗАВШИЕ КОМАНДЫ ПАКЕТА РАЗДЕЛЕНЫ',
    isset($chastichno['result']['ok1']) && isset($chastichno['errors']['bad1']),
    json_encode($chastichno, JSON_UNESCAPED_UNICODE));
check('ОТКАЗАВШАЯ КОМАНДА НЕ ПОПАДАЕТ В РЕЗУЛЬТАТ ПУСТЫМ ЗНАЧЕНИЕМ',
    !array_key_exists('bad1', $chastichno['result']),
    json_encode(array_keys($chastichno['result']), JSON_UNESCAPED_UNICODE));
check('в отказе назван его повод, а не просто «ошибка»',
    strpos(mb_strtolower((string)($chastichno['errors']['bad1'] ?? '')), 'прав') !== false
    || strpos((string)($chastichno['errors']['bad1'] ?? ''), 'ACCESS_DENIED') !== false,
    (string)($chastichno['errors']['bad1'] ?? ''));

// Строгий помощник в той же ситуации обязан не «вернуть, что получилось», а
// бросить исключение: молчаливый частичный успех — это и есть правдоподобный
// ноль, из-за которого отчёты расходятся с порталом.
$GLOBALS['__b24_call'] = static function (string $m, array $p): array {
    return ['result' => [
        'result' => ['ok1' => true],
        'result_error' => ['bad1' => ['error' => 'ACCESS_DENIED', 'error_description' => 'нет прав']],
    ]];
};
$strogoOshibka = null;
try {
    b24()->batchAllChecked(['ok1' => ['crm.item.add', []], 'bad1' => ['crm.item.add', []]]);
} catch (Throwable $e) { $strogoOshibka = $e->getMessage(); }
unset($GLOBALS['__b24_call']);
check('СТРОГИЙ ПАКЕТ НЕ ВЫДАЁТ ЧАСТИЧНЫЙ УСПЕХ ЗА УСПЕХ',
    $strogoOshibka !== null, 'исключения не было');

// ── ПОДМЕНЯЕМЫЙ ТРАНСПОРТ: ПОВЕДЕНИЕ ВОКРУГ СЕТИ БЕЗ СЕТИ ───────────────────
// Ветки «повторять или нет» до сих пор проверялись только по решающим функциям.
// Теперь через них проходит настоящий вызов: клиент видит ответ портала, какой
// мы захотим, и мы смотрим, что он сделал.
suite('Подменяемый транспорт портала');

testWipe(DATA_ROOT);
$trK = new B24('local.test.app', 'secret');
$trK->saveTokensFromInstall([
    'AUTH_ID' => 'tr-access', 'REFRESH_ID' => 'tr-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-transport.invalid', 'member_id' => 'MEMBER-TR',
    'APPLICATION_TOKEN' => 'key-tr', 'APPLICATION_SCOPE' => 'crm,user',
]);

/** Сколько раз клиент реально сходил «в сеть» и с чем. */
$vyzovy = [];
$otvety = [];
$GLOBALS['__b24_transport'] = static function (string $what, array $params) use (&$vyzovy, &$otvety) {
    $vyzovy[] = $what;
    return array_shift($otvety) ?? ['result' => 'по умолчанию'];
};

// 1. Успешный ответ доходит наверх целиком.
$otvety = [['result' => ['ID' => 7]]];
$vyzovy = [];
$ok = $trK->call('crm.item.get', ['entityTypeId' => 2, 'id' => 7]);
check('подменённый ответ портала доходит до вызывающего',
    ($ok['result']['ID'] ?? null) === 7, json_encode($ok, JSON_UNESCAPED_UNICODE));
check('на один вызов уходит ровно один запрос', count($vyzovy) === 1, (string)count($vyzovy));

// 2. Учёт нагрузки портала работает и на подменённом ответе — иначе испытания
// проверяли бы не тот код, что идёт в бой.
$otvety = [['result' => 1, 'time' => ['operating' => 42.5, 'operating_reset_at' => 111]]];
$trK->call('crm.item.list', []);
$nagruzka = $trK->portalLoad();
check('нагрузка портала считается и на подменённом транспорте',
    (float)($nagruzka['operating'] ?? 0) === 42.5 && (int)($nagruzka['reset_at'] ?? 0) === 111,
    json_encode($nagruzka, JSON_UNESCAPED_UNICODE));

// 3. Повторяемый отказ портала действительно повторяется — а безопасное чтение
// повторять можно.
$otvety = [
    ['__code' => 503, 'error' => 'QUERY_LIMIT_EXCEEDED'],
    ['result' => 'со второго раза'],
];
$vyzovy = [];
$posleOtkaza = $trK->call('crm.item.list', []);
check('ПОВТОРЯЕМЫЙ ОТКАЗ ЧТЕНИЯ ПОВТОРЯЕТСЯ, А НЕ ВОЗВРАЩАЕТСЯ НАВЕРХ',
    ($posleOtkaza['result'] ?? '') === 'со второго раза' && count($vyzovy) === 2,
    'запросов: ' . count($vyzovy) . ' ' . json_encode($posleOtkaza, JSON_UNESCAPED_UNICODE));

// 4. Исчерпанная корзина запросов — отказ ДО выполнения, работа не начиналась.
// Такой отказ повторяется даже на необратимой записи, иначе половина обхода
// теряется из-за соседнего приложения на том же хостинге (limits.md).
$otvety = [
    ['__code' => 503, 'error' => 'QUERY_LIMIT_EXCEEDED'],
    ['result' => ['item' => ['id' => 999]]],
];
$vyzovy = [];
$korzina = $trK->call('crm.item.add', ['entityTypeId' => 2, 'fields' => ['TITLE' => 'т']]);
check('отказ до выполнения повторяется даже для записи',
    count($vyzovy) === 2 && (($korzina['result']['item']['id'] ?? 0) === 999),
    'запросов: ' . count($vyzovy) . ' ' . json_encode($korzina, JSON_UNESCAPED_UNICODE));

// 5. А вот 5xx без объяснения — случай принципиально другой: запрос МОГ дойти и
// выполниться, потерялся только ответ. Повтор здесь создал бы вторую сделку.
$otvety = [
    ['__code' => 502, 'error' => ''],
    ['result' => ['item' => ['id' => 1000]]],
];
$vyzovy = [];
$posleZapisi = $trK->call('crm.item.add', ['entityTypeId' => 2, 'fields' => ['TITLE' => 'т']]);
check('НЕОБРАТИМАЯ ЗАПИСЬ ПОСЛЕ ПОТЕРЯННОГО ОТВЕТА НЕ ПОВТОРЯЕТСЯ САМА',
    count($vyzovy) === 1 && (($posleZapisi['result']['item']['id'] ?? 0) !== 1000),
    'запросов: ' . count($vyzovy) . ' ' . json_encode($posleZapisi, JSON_UNESCAPED_UNICODE));

// Неизвестный коробочный/custom метод с «похожим на чтение» суффиксом — всё
// равно мутация, пока caller не дал отдельное idempotency-доказательство.
$otvety = [
    ['__code' => 502, 'error' => ''],
    ['result' => ['effect' => 'duplicated']],
];
$vyzovy = [];
$customLostAnswer = $trK->call('custom.sideeffect.check', ['id' => 42]);
check('unknown/custom suffix после потерянного ответа получает ровно одну попытку',
    count($vyzovy) === 1 && !isset($customLostAnswer['result']['effect']),
    'запросов: ' . count($vyzovy) . ' '
        . json_encode($customLostAnswer, JSON_UNESCAPED_UNICODE));

// Переполнение могло случиться уже после выполнения portal-метода. Оно никогда
// не становится пустым success и не запускает transport retry — даже для read,
// потому что тот же ответ снова упрётся в тот же hard cap.
$otvety = [
    ['__code' => 502, 'error' => 'response_too_large'],
    ['result' => ['item' => ['id' => 1002]]],
];
$vyzovy = [];
$largeMutation = $trK->call('crm.item.add', ['entityTypeId' => 2, 'fields' => ['TITLE' => 'large']]);
check('response_too_large у неоднозначной mutation не повторяется',
    count($vyzovy) === 1 && ($largeMutation['error'] ?? '') === 'response_too_large',
    'запросов: ' . count($vyzovy) . ' ' . json_encode($largeMutation, JSON_UNESCAPED_UNICODE));
$otvety = [
    ['__code' => 502, 'error' => 'response_too_large'],
    ['result' => 'не должен маскировать первый отказ'],
];
$vyzovy = [];
$largeRead = $trK->call('crm.item.list', []);
check('response_too_large у safe read не маскируется пустотой или повтором',
    count($vyzovy) === 1 && ($largeRead['error'] ?? '') === 'response_too_large'
        && !array_key_exists('result', $largeRead),
    'запросов: ' . count($vyzovy) . ' ' . json_encode($largeRead, JSON_UNESCAPED_UNICODE));

// runOnce защищает повтор CALLBACK между запросами, но не повтор transport
// ВНУТРИ одного callback. Это отрицательный контракт документации: использовать
// runOnce как основание для safeToRepeat=true нельзя.
$otvety = [
    ['__code' => 502, 'error' => ''],
    ['result' => ['item' => ['id' => 1001]]],
];
$vyzovy = [];
$runOnceRetry = runOnce('transport-retry-is-inside-callback', static function () use ($trK): array {
    return $trK->call('crm.item.add', ['entityTypeId' => 2, 'fields' => ['TITLE' => 'т']], true);
});
check('runOnce НЕ ОСТАНАВЛИВАЕТ transport retry внутри callback',
    count($vyzovy) === 2 && empty($runOnceRetry['repeated']),
    'запросов: ' . count($vyzovy) . ' ' . json_encode($runOnceRetry, JSON_UNESCAPED_UNICODE));

// А то же самое ЧТЕНИЕ после 5xx повторить можно и нужно — иначе выборка молча
// обрывается на середине.
$otvety = [
    ['__code' => 502, 'error' => ''],
    ['result' => 'чтение дожали'],
];
$vyzovy = [];
$chtenie = $trK->call('crm.item.list', []);
check('чтение после потерянного ответа повторяется',
    count($vyzovy) === 2 && ($chtenie['result'] ?? '') === 'чтение дожали',
    'запросов: ' . count($vyzovy) . ' ' . json_encode($chtenie, JSON_UNESCAPED_UNICODE));

// 5. Обрыв связи (транспорт вернул не массив) — это http_error, а не «пусто».
$otvety = [];
$GLOBALS['__b24_transport'] = static function (string $what, array $params) use (&$vyzovy) {
    $vyzovy[] = $what;
    return null;                                  // сеть молчит
};
$vyzovy = [];
$netSeti = $trK->call('crm.item.add', ['entityTypeId' => 2, 'fields' => ['TITLE' => 'т']]);
check('молчание сети на необратимой записи даёт http_error без повтора',
    ($netSeti['error'] ?? '') === 'http_error' && count($vyzovy) === 1,
    'запросов: ' . count($vyzovy) . ' ' . json_encode($netSeti, JSON_UNESCAPED_UNICODE));

// 6. Отказ обновления токена по причине из ответа сервера авторизации: человеку
// нужен РАЗНЫЙ текст, и без подмены эта ветка недостижима без сети.
$GLOBALS['__b24_transport'] = static function (string $what, array $params) {
    if ($what === '__oauth_refresh') return ['error' => 'invalid_grant'];
    return ['__code' => 401, 'error' => 'expired_token'];
};
$refreshBeda = '';
try { $trK->call('crm.item.list', []); }
catch (Throwable $e) { $refreshBeda = $e->getMessage(); }
check('отказ обновления токена объясняется причиной, а не одним текстом на всё',
    $refreshBeda !== '' && mb_strpos($refreshBeda, 'refresh') !== false,
    $refreshBeda);

// Переполнение OAuth-ответа — отдельный машинный тип. Оно происходит внутри
// storeUpdate токенов: исключение обязано оставить файл байт-в-байт прежним,
// иначе неудачная попытка refresh сама отрежет приложение от портала.
$overflowTokens = [
    'access_token' => 'overflow-old-access', 'refresh_token' => 'overflow-old-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'M-overflow',
    'client_endpoint' => 'https://portal.invalid/rest/',
    'install_generation' => 'generation-overflow', 'expires_at' => time() - 60,
];
storeWrite(B24_TOKENS_FILE, $overflowTokens);
$overflowBefore = (string)file_get_contents(B24_TOKENS_FILE);
$overflowClient = new B24('local.test.app', 'secret');
$GLOBALS['__b24_transport'] = static function (string $what, array $params): array {
    if ($what === '__oauth_refresh') return b24ResponseTooLargeError();
    return ['__code' => 401, 'error' => 'expired_token'];
};
$overflowClass = '';
try { $overflowClient->call('crm.item.list', []); }
catch (Throwable $e) { $overflowClass = get_class($e); }
$overflowAfter = (string)file_get_contents(B24_TOKENS_FILE);
check('OAuth response overflow имеет отдельный тип и не меняет token state',
    $overflowClass === 'B24ResponseTooLargeException' && $overflowAfter === $overflowBefore,
    'тип=' . $overflowClass . ', state_changed=' . ($overflowAfter !== $overflowBefore ? 'yes' : 'no'));

unset($GLOBALS['__b24_transport']);

// 7. И главное: на боевом подмены не существует. Функция есть в коде, но без
// подменённого стора она не включается ничем снаружи.
$boevoyData = getenv('BXAPP_DATA_ROOT');
$GLOBALS['__b24_transport'] = static fn(string $w, array $p): array => ['result' => 'подмена сработала'];
putenv('BXAPP_DATA_ROOT=');
$naBoyu = '';
try { $naBoyu = (string)($trK->call('crm.item.list', [])['result'] ?? ''); }
catch (Throwable $e) { $naBoyu = 'исключение: ' . $e->getMessage(); }
putenv('BXAPP_DATA_ROOT=' . (string)$boevoyData);
unset($GLOBALS['__b24_transport']);
check('ПОДМЕНА ТРАНСПОРТА ПОРТАЛА НЕ РАБОТАЕТ БЕЗ ПОДМЕНЁННОГО СТОРА',
    $naBoyu !== 'подмена сработала', $naBoyu);

// ── 8. Протухший токен ВНУТРИ пакета ────────────────────────────────────────
//
// Одиночный вызов на expired_token обновляет токен и повторяется — это было с
// самого начала. Но пакет отвечает 200: сам метод `batch` отработал, а протухший
// токен виден только внутри, в ошибках подкоманд. Прежде такой ответ уходил
// вызывающему как есть: пятьдесят «ошибок», ноль данных и ни одной попытки
// обновиться. Для человека это выглядит как «портал молчит», а на деле токен
// просто устарел на минуту.
//
// Повторяем ТОЛЬКО когда успехов ровно ноль и КАЖДАЯ подкоманда упала на токене.
// Смешанный ответ не повторяем никогда: половина команд уже выполнена, и второй
// заход создал бы дубли — сделки, задачи, письма.
$tokClient = new B24('local.test.app', 'secret');
$popytki = 0; $obnovleniy = 0;
$GLOBALS['__b24_transport'] = static function (string $what, array $params) use (&$obnovleniy): array {
    if ($what === '__oauth_refresh') {
        $obnovleniy++;
        return ['access_token' => 'novyy', 'refresh_token' => 'r2', 'expires_in' => 3600,
                'domain' => 'portal.invalid', 'member_id' => 'M',
                'client_endpoint' => 'https://portal.invalid/rest/'];
    }
    return ['result' => 'не ждали'];
};
$GLOBALS['__b24_call'] = static function (string $method, array $params) use (&$popytki): array {
    if ($method !== 'batch') return ['result' => []];
    $popytki++;
    $cmd = (array)($params['cmd'] ?? []);
    if ($popytki === 1) {
        $err = [];
        foreach ($cmd as $key => $q) $err[$key] = ['error' => 'expired_token', 'error_description' => 'The access token provided has expired'];
        return ['result' => ['result' => [], 'result_error' => $err]];
    }
    $ok = [];
    foreach ($cmd as $key => $q) $ok[$key] = ['echo' => $key];
    return ['result' => ['result' => $ok, 'result_error' => []]];
};
$otvetPosleObnovleniya = $tokClient->batch([
    'a' => ['crm.item.get', ['id' => 1]],
    'b' => ['crm.item.get', ['id' => 2]],
]);
unset($GLOBALS['__b24_call'], $GLOBALS['__b24_transport']);
check('ПАКЕТ, ЦЕЛИКОМ УПАВШИЙ НА ПРОТУХШЕМ ТОКЕНЕ, ОБНОВЛЯЕТ ТОКЕН И ПОВТОРЯЕТСЯ',
    $popytki === 2 && $obnovleniy === 1
    && count((array)($otvetPosleObnovleniya['result']['result'] ?? [])) === 2,
    'попыток ' . $popytki . ', обновлений ' . $obnovleniy);

// Обратная сторона, и она важнее: часть команд выполнилась. Повтор здесь создал
// бы вторую сделку, второе письмо, вторую задачу.
$smesh = new B24('local.test.app', 'secret');
$popytkiSmesh = 0;
$GLOBALS['__b24_call'] = static function (string $method, array $params) use (&$popytkiSmesh): array {
    if ($method !== 'batch') return ['result' => []];
    $popytkiSmesh++;
    return ['result' => ['result' => ['a' => ['id' => 10]],
                         'result_error' => ['b' => ['error' => 'expired_token']]]];
};
$smeshanniy = $smesh->batch(['a' => ['crm.item.add', []], 'b' => ['crm.item.add', []]]);
unset($GLOBALS['__b24_call']);
check('СМЕШАННЫЙ ОТВЕТ ПАКЕТА НЕ ПОВТОРЯЕТСЯ — иначе выполненное задвоится',
    $popytkiSmesh === 1, 'попыток ' . $popytkiSmesh);

// ── ЭКРАН «СОСТОЯНИЕ»: ОКНО В ПОРТАЛ, КОТОРОЕ САМО НЕ ИМЕЕТ ПРАВА ЛОМАТЬСЯ ──
// Живой стенд: человек приходит на этот экран ИМЕННО тогда, когда что-то
// сломалось, — значит, отказ любого хранилища обязан превращаться в строку
// problems, а не в пустой 500. Проверки живут здесь, потому что это тот же
// контур «данные портала»: журнал ошибок и поколения установки.
suite('Экран состояния: журнал и поколения установки');

testWipe(DATA_ROOT);
$pokolenieStaroe = 'GEN-STARAYA';
$pokolenieNovoe  = 'GEN-NOVAYA';
$tokenyPortala = static function (string $pokolenie, array $prezhnie = []): void {
    storeWrite(B24_TOKENS_FILE, [
        'access_token' => 'health-access', 'refresh_token' => 'health-refresh',
        'domain' => 'health.bitrix24.ru', 'member_id' => 'MEMBER-HEALTH',
        'scope' => 'crm,user,placement', 'application_token' => 'health-event-token',
        'install_generation' => $pokolenie, 'prior_generations' => $prezhnie,
        'expires_at' => time() + 3600,
    ]);
};
// Сначала ПЕРВАЯ установка: строка журнала принимается только под своим
// поколением — писатель fail-closed отвергает чужое.
$tokenyPortala($pokolenieStaroe);
aclSetOwner(901, 'Владелец состояния');
$healthToken = str_repeat('h', 64);
storeWrite(SESSIONS_FILE, [[
    'token' => $healthToken, 'uid' => 901, 'role' => 'admin', 'isAdmin' => false,
    'member_id' => 'MEMBER-HEALTH', 'install_generation' => $pokolenieNovoe,
    'createdAt' => time(), 'expiresAt' => time() + 3600,
]]);

// Ошибка записана ДО переустановки — ровно та, за которой человек и придёт.
appBindLogGeneration($pokolenieStaroe);
appLog('error', 'health.staraya.oshibka', [], ['msg' => 'MARKER-DO-PEREUSTANOVKI']);
// А теперь человек нажал «Переустановить» по совету этого же экрана: поколение
// сменилось, прежняя регистрация той же площадки ушла в историю.
$tokenyPortala($pokolenieNovoe, [$pokolenieStaroe]);
appBindLogGeneration($pokolenieNovoe);

$srvH = testServer();
check('стенд для экрана состояния поднялся', $srvH['ok']);
if ($srvH['ok']) {
    $callH = $srvH['call'];

    // K4-29. Вкладка «Журнал» эти строки показывает (она берёт СПИСОК поколений
    // портала), а экран состояния фильтровал одним текущим — и «Последние
    // ошибки» пустели ровно в момент, когда за ними пришли. Пустой список
    // читается как «ошибок нет».
    [$kodLogs, $jsonLogs, ] = $callH('GET', '/api/logs.php', ['token' => $healthToken]);
    $vidnoVZhurnale = strpos((string)json_encode($jsonLogs['rows'] ?? [], JSON_UNESCAPED_UNICODE),
        'MARKER-DO-PEREUSTANOVKI') !== false;
    [$kodH, $jsonH, , , $diagH] = $callH('GET', '/api/health.php', ['token' => $healthToken]);
    $vidnoVSostoyanii = strpos((string)json_encode($jsonH['last_errors'] ?? [], JSON_UNESCAPED_UNICODE),
        'MARKER-DO-PEREUSTANOVKI') !== false;
    check('после переустановки на том же портале экран состояния показывает те же ошибки, что и «Журнал»',
        $kodLogs === 200 && $kodH === 200 && $vidnoVZhurnale && $vidnoVSostoyanii,
        'журнал: ' . ($vidnoVZhurnale ? 'видно' : 'НЕТ') . ', состояние: '
        . ($vidnoVSostoyanii ? 'видно' : 'НЕТ') . ', коды ' . $kodLogs . '/' . $kodH
        . ($kodH === 0 ? ', transport: ' . json_encode($diagH) : ''));

    // K4-12. Каталог журнала подменён обычным файлом (снесла чужая уборка,
    // кончилось место, нет прав на создание). Соседние обращения к стору на этом
    // экране обёрнуты, а journalQuery стоял голым — и экран отдавал пустой 500
    // вместо девяти диагнозов. Обычный эндпоинт в тех же условиях отвечает 200.
    $katalogZhurnala = DATA_ROOT . '/' . LOG_DIR_NAME;
    testWipe($katalogZhurnala, true);
    file_put_contents($katalogZhurnala, 'не каталог, а файл');
    [$kodSettings, , ] = $callH('GET', '/api/settings.php', ['token' => $healthToken]);
    [$kodSlomano, $jsonSlomano, , , $diagSlomano] = $callH('GET', '/api/health.php', ['token' => $healthToken]);
    $tekstProblem = (string)json_encode($jsonSlomano['problems'] ?? [], JSON_UNESCAPED_UNICODE);
    check('при недоступном каталоге журнала экран состояния отвечает 200 и называет причину словами',
        $kodSlomano === 200 && journalTextContains($tekstProblem, 'журнал приложения сейчас недоступен'),
        'состояние: ' . $kodSlomano . ' (обычный эндпоинт: ' . $kodSettings . '), problems: '
        . mb_substr($tekstProblem, 0, 200)
        . ($kodSlomano === 0 ? ', transport: ' . json_encode($diagSlomano) : ''));
    check('недоступный журнал помечает выборку неполной, а пустой список ошибок не выдаётся за «ошибок нет»',
        !empty($jsonSlomano['journal']['truncated']) && ($jsonSlomano['last_errors'] ?? null) === [],
        json_encode($jsonSlomano['journal'] ?? null, JSON_UNESCAPED_UNICODE));
    @unlink($katalogZhurnala);

    // Ещё одно хранилище того же экрана: карта доступности методов портала.
    // Экран состояния — последнее место, которое имеет право падать, поэтому
    // порча ЛЮБОГО его файла обязана превращаться в строку problems, а не в 500.
    file_put_contents(CAP_FILE, 'повреждённая карта без защитного префикса');
    [$kodCap, $jsonCap, , , $diagCap] = $callH('GET', '/api/health.php', ['token' => $healthToken]);
    $tekstCap = (string)json_encode($jsonCap['problems'] ?? [], JSON_UNESCAPED_UNICODE);
    check('при повреждённой карте доступности методов экран состояния отвечает 200 и показывает остальные диагнозы',
        $kodCap === 200 && count((array)($jsonCap['problems'] ?? [])) > 0
        && array_key_exists('portal', $jsonCap) && array_key_exists('cron', $jsonCap),
        'код ' . $kodCap . ', problems: ' . mb_substr($tekstCap, 0, 200)
        . ($kodCap === 0 ? ', transport: ' . json_encode($diagCap) : ''));
    @unlink(CAP_FILE);
}
if (is_callable($srvH['close'] ?? null)) $srvH['close']();

// ── ОПОЗНАНИЕ ЧЕЛОВЕКА: ПУТЬ, ПО КОТОРОМУ ИДЁТ КАЖДОЕ ОТКРЫТИЕ ──────────────
// Через resolveUserContextFromAuth() проходят гейт сессии, открытие, установка и
// разбор событий портала, включая ONAPPUNINSTALL. Значит цена ошибки здесь —
// не «медленно», а «войти нельзя».
suite('Опознание человека: контракт и цена');

// K4-30. Отметка темпа притащила в b24PortalGet() чтение стора (plan.php), и
// функция, которая по документации возвращает ?array, стала БРОСАТЬ на
// повреждённом файле темпа. Бросок проходит насквозь через опознание, у
// которого перехвата нет: порча одного некритичного файла превращалась в отказ
// входа и в невозможность разобрать событие удаления.
// Проверяем ПОДПРОЦЕССОМ: planCurrentRate() держит темп в статик-кэше 5 секунд,
// и в прогретом процессе дефект не воспроизводится вовсе — первый зонд скептика
// именно так и дал ложно-зелёный результат.
$probaKornya = sys_get_temp_dir() . '/bxapp-plan-' . bin2hex(random_bytes(4));
mkdir($probaKornya, 0700, true);
$probaFayl = $probaKornya . '-probe.php';
file_put_contents($probaFayl, '<?php' . "\n"
    . 'require_once ' . var_export($root . '/www/env.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/store.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/lib.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/journal.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/plan.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/b24.php', true) . ';' . "\n"
    . '$GLOBALS[\'__b24_portal_get\'] = static fn(string $d, string $m, string $a): array'
    . ' => [\'result\' => $m === \'user.admin\' ? true : [\'ID\' => 7, \'UF_DEPARTMENT\' => [1]]];' . "\n"
    // Портим тариф ДО первого обращения — иначе сработает статик-кэш.
    . 'file_put_contents(PLAN_FILE, \'мусор без защитного префикса\');' . "\n"
    . '$out = [];' . "\n"
    . 'try { $r = b24PortalGet(\'portal.invalid\', \'user.current\', \'auth-probe\');'
    . ' $out[\'portalGet\'] = is_array($r) ? \'массив\' : var_export($r, true); }' . "\n"
    . 'catch (Throwable $e) { $out[\'portalGet\'] = \'БРОСИЛ \' . get_class($e); }' . "\n"
    . 'try { $c = resolveUserContextFromAuth(\'auth-probe\', \'portal.invalid\');'
    . ' $out[\'opoznanie\'] = is_array($c) ? \'контекст\' : var_export($c, true); }' . "\n"
    . 'catch (Throwable $e) { $out[\'opoznanie\'] = \'БРОСИЛ \' . get_class($e); }' . "\n"
    . 'echo json_encode($out, JSON_UNESCAPED_UNICODE);' . "\n");
$probaDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$probaProc = proc_open([PHP_BINARY, $probaFayl], $probaDesc, $probaPipes, $root, [
    'BXAPP_DATA_ROOT' => $probaKornya, 'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
]);
$probaOut = '';
if (is_resource($probaProc)) {
    fclose($probaPipes[0]);
    $probaOut = (string)stream_get_contents($probaPipes[1]);
    (string)stream_get_contents($probaPipes[2]);
    fclose($probaPipes[1]); fclose($probaPipes[2]);
    proc_close($probaProc);
}
$probaJson = json_decode($probaOut, true);
check('повреждённый файл темпа НЕ ломает опознание человека: b24PortalGet держит свой контракт',
    is_array($probaJson) && ($probaJson['portalGet'] ?? '') === 'массив'
    && ($probaJson['opoznanie'] ?? '') === 'контекст',
    'ответ зонда: ' . ($probaOut === '' ? 'пусто' : $probaOut));
@unlink($probaFayl);
testWipe($probaKornya, true);

// K4-31. Опознание делает два запроса к порталу. Пока слот в общей очереди брал
// КАЖДЫЙ, второй запрос вставал ровно на интервал темпа (на типовом тарифе
// 2 запр/с — полсекунды), и приложение спало полсекунды на КАЖДОМ открытии даже
// при полностью пустой очереди. В журнале следа нет: слот выдан, деградации нет.
// Замер — ПОДПРОЦЕССОМ: отметка темпа существует, только когда загружен plan.php
// (в бою его грузит www/index.php), а тянуть его в этот набор нельзя — тогда
// слоты начнут брать и остальные наборы прогона.
$tempoKoren = sys_get_temp_dir() . '/bxapp-tempo-' . bin2hex(random_bytes(4));
mkdir($tempoKoren, 0700, true);
$tempoFayl = $tempoKoren . '-probe.php';
file_put_contents($tempoFayl, '<?php' . "\n"
    . 'require_once ' . var_export($root . '/www/env.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/store.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/lib.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/journal.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/plan.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/b24.php', true) . ';' . "\n"
    . '$GLOBALS[\'__b24_portal_get\'] = static fn(string $d, string $m, string $a): array'
    . ' => [\'result\' => $m === \'user.admin\' ? true : [\'ID\' => 42, \'UF_DEPARTMENT\' => [3]]];' . "\n"
    . '$zamer = static function (string $auth): float {' . "\n"
    . '    $n = microtime(true);' . "\n"
    . '    resolveUserContextFromAuth($auth, \'portal-tempo.invalid\');' . "\n"
    . '    return microtime(true) - $n;' . "\n"
    . '};' . "\n"
    . '$interval = planCurrentRate() > 0 ? 1.0 / planCurrentRate() : 0.0;' . "\n"
    . '$pervoe = $zamer(\'auth-tempo-1\');' . "\n"
    . '$vtoroe = $zamer(\'auth-tempo-2\');' . "\n"
    . 'echo json_encode([\'interval\' => $interval, \'pervoe\' => $pervoe, \'vtoroe\' => $vtoroe]);' . "\n");
$tempoDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$tempoProc = proc_open([PHP_BINARY, $tempoFayl], $tempoDesc, $tempoPipes, $root, [
    'BXAPP_DATA_ROOT' => $tempoKoren, 'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
]);
$tempoOut = '';
if (is_resource($tempoProc)) {
    fclose($tempoPipes[0]);
    $tempoOut = (string)stream_get_contents($tempoPipes[1]);
    (string)stream_get_contents($tempoPipes[2]);
    fclose($tempoPipes[1]); fclose($tempoPipes[2]);
    proc_close($tempoProc);
}
$tempoJson = json_decode($tempoOut, true);
$tempoInterval = is_array($tempoJson) ? (float)($tempoJson['interval'] ?? 0) : 0.0;
check('отметка темпа на опознании вообще действует — иначе замер ниже ничего не проверяет',
    $tempoInterval > 0.0, 'интервал темпа: ' . $tempoOut);
check('опознание берёт ОДИН слот на пару запросов и не спит на пустой очереди',
    is_array($tempoJson) && $tempoInterval > 0.0
    && (float)$tempoJson['pervoe'] < $tempoInterval * 0.5
    && (float)$tempoJson['vtoroe'] < $tempoInterval * 1.5,
    'ответ зонда: ' . ($tempoOut === '' ? 'пусто' : $tempoOut));
@unlink($tempoFayl);
testWipe($tempoKoren, true);

// ── ЗАБОРЫ НА КОНТУР ВЫБОРКИ (K4-26, K4-38) ─────────────────────────────────
// Правки контура выборки, сделанные 09.08.2026, не были закрыты ни одной
// проверкой: подсадка исходных дефектов давала зелёный прогон, и единственным
// «СБОЕМ» был отпечаток кода в карте — он срабатывает на ЛЮБУЮ правку файла и
// забором поведения не является. Ниже — заборы по поведению: каждый проверен
// подсадкой исходного дефекта и увиден КРАСНЫМ до того, как попал сюда.
suite('Заборы контура выборки');

// 1. Поле курсора выбирается ПО ИМЕНИ МЕТОДА. Витринный однострочник
//    `$b24->fetchAll('crm.item.list', [...])` зовётся без третьего аргумента, и
//    глухой дефолт 'ID' заставлял листать по полю, которого у метода нет:
//    портал отвечает INVALID_ARG_VALUE, список не приходит вовсе.
check('поле курсора у crm.item.* — camelCase «id», у остальных списков — «ID»',
    B24::defaultIdField('crm.item.list') === 'id'
    && B24::defaultIdField('crm.item.get') === 'id'
    && B24::defaultIdField('CRM.ITEM.LIST') === 'id'
    && B24::defaultIdField('crm.deal.list') === 'ID'
    && B24::defaultIdField('user.get') === 'ID'
    && B24::defaultIdField('crm.deal.userfield.list') === 'ID',
    B24::defaultIdField('crm.item.list') . '/' . B24::defaultIdField('crm.deal.list'));

// Сквозной проход: тот самый вызов из docs/BLOCKS.md, без третьего аргумента.
$otpravlenoItem = null;
$GLOBALS['__b24_call'] = static function (string $m, array $p) use (&$otpravlenoItem): array {
    if ($otpravlenoItem === null) $otpravlenoItem = $p;
    $posle = (int)($p['filter']['>id'] ?? 0);
    $rows = [];
    for ($id = $posle + 1; $id <= 60 && count($rows) < 50; $id++) $rows[] = ['id' => $id];
    return ['result' => ['items' => $rows]];
};
// Ловим и исключение: при глухом дефолте курсор пойдёт по несуществующему полю
// и обход упадёт — это тоже провал забора, а не повод оборвать весь прогон.
$itemRows = [];
$itemOshibka = '';
try { $itemRows = b24()->fetchAll('crm.item.list', ['entityTypeId' => 2]); }
catch (Throwable $e) { $itemOshibka = $e->getMessage(); }
unset($GLOBALS['__b24_call']);
check('витринный fetchAll(«crm.item.list») без третьего аргумента листает по «id» и собирает весь список',
    $itemOshibka === '' && count($itemRows) === 60
    && ($otpravlenoItem['order'] ?? null) === ['id' => 'ASC']
    && array_key_exists('>id', (array)($otpravlenoItem['filter'] ?? [])),
    ($itemOshibka !== '' ? 'исключение: ' . $itemOshibka . '; ' : '')
    . 'записей ' . count($itemRows) . ', отправлено: '
    . json_encode($otpravlenoItem, JSON_UNESCAPED_UNICODE));

$compatItemSent = null;
$compatItemRows = fetchAllCompat(static function (string $m, array $p) use (&$compatItemSent): array {
    $compatItemSent = $p;
    return ['result' => ['items' => []]];
}, 'crm.item.list', ['entityTypeId' => 2]);
check('fetchAllCompat(«crm.item.list») без idField использует тот же camelCase-дефолт «id»',
    $compatItemRows === []
    && ($compatItemSent['order'] ?? null) === ['id' => 'ASC']
    && array_key_exists('>id', (array)($compatItemSent['filter'] ?? [])),
    json_encode($compatItemSent, JSON_UNESCAPED_UNICODE));

// 2. Копия для чужих проектов: нижняя граница вызывающего и start = -1.
$compatOtpravleno = null;
$compatRows = fetchAllCompat(static function (string $m, array $p) use (&$compatOtpravleno): array {
    if ($compatOtpravleno === null) $compatOtpravleno = $p;
    $posle = (int)($p['filter']['>ID'] ?? 0);
    $rows = [];
    for ($id = $posle + 1; $id <= 600 && count($rows) < 50; $id++) $rows[] = ['ID' => $id];
    return ['result' => $rows];
}, 'crm.deal.list', ['filter' => ['>ID' => 500]]);
$compatIds = array_map(static fn(array $r): int => (int)$r['ID'], $compatRows);
check('fetchAllCompat с нижней границей вызывающего возвращает только хвост и шлёт её на первой странице',
    count($compatRows) === 100 && ($compatIds ? min($compatIds) : 0) === 501
    && ($compatOtpravleno['filter']['>ID'] ?? null) === 500,
    'получено ' . count($compatRows) . ', отправлено: '
    . json_encode($compatOtpravleno, JSON_UNESCAPED_UNICODE));
check('fetchAllCompat просит портал не считать total (start = -1)',
    ($compatOtpravleno['start'] ?? null) === -1,
    json_encode($compatOtpravleno, JSON_UNESCAPED_UNICODE));

// 3. K4-28 (серверная половина). Отказ портала на department.get обязан прийти
//    в ответ ПРИЗНАКОМ, а не пустым списком отделов: пустой список читается на
//    экране как «отдела с таким названием нет», админ выдаёт доступ поимённо и
//    забывает новичков. Показ причины человеку живёт во фронте (www/js) — здесь
//    держим то, на чём он стоит: контракт ответа api/users.php.
$zaglushkaPortala = '<?php' . "\n"
    . '$GLOBALS[\'__b24_call\'] = static function (string $m, array $p): array {' . "\n"
    . '    if ($m === \'user.get\') return [\'result\' => [[\'ID\' => 77, \'NAME\' => \'Тест\','
    . ' \'LAST_NAME\' => \'Тестов\', \'ACTIVE\' => true, \'UF_DEPARTMENT\' => []]], \'total\' => 1];' . "\n"
    . '    if ($m === \'department.get\') return [\'error\' => \'ACCESS_DENIED\','
    . ' \'error_description\' => \'нет права department\'];' . "\n"
    . '    return [\'result\' => []];' . "\n"
    . '};' . "\n"
    . 'require __DIR__ . \'/api/users.php\';' . "\n";

testWipe(DATA_ROOT);
$pokolenieU = 'GEN-USERS';
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'users-access', 'refresh_token' => 'users-refresh',
    'domain' => 'users.bitrix24.ru', 'member_id' => 'MEMBER-USERS',
    'scope' => 'crm,user,placement', 'application_token' => 'users-event-token',
    'install_generation' => $pokolenieU, 'expires_at' => time() + 3600,
]);
aclSetOwner(701, 'Владелец поиска');
$usersToken = str_repeat('u', 64);
storeWrite(SESSIONS_FILE, [[
    'token' => $usersToken, 'uid' => 701, 'role' => 'admin', 'isAdmin' => false,
    'member_id' => 'MEMBER-USERS', 'install_generation' => $pokolenieU,
    'createdAt' => time(), 'expiresAt' => time() + 3600,
]]);
$srvU = testServer([], ['fixtures' => ['zz-users-otkaz.php' => $zaglushkaPortala]]);
check('стенд для поиска сотрудников поднялся', $srvU['ok']);
if ($srvU['ok']) {
    [$kodU, $jsonU, ] = $srvU['call']('GET', '/zz-users-otkaz.php?q=' . rawurlencode('Тест'),
        ['token' => $usersToken]);
    check('отказ портала на department.get приходит признаком depts_available=false, а не пустым списком',
        $kodU === 200 && ($jsonU['depts_available'] ?? null) === false
        && is_string($jsonU['depts_note'] ?? null) && trim((string)($jsonU['depts_note'] ?? '')) !== ''
        && ($jsonU['depts'] ?? null) === [],
        'код ' . $kodU . ', ответ: ' . mb_substr((string)json_encode($jsonU, JSON_UNESCAPED_UNICODE), 0, 300));
    check('люди при этом находятся: отделы — дополнение к поиску, а не его условие',
        $kodU === 200 && count((array)($jsonU['users'] ?? [])) === 1
        && array_key_exists('matched', $jsonU) && array_key_exists('limited', $jsonU),
        'найдено: ' . count((array)($jsonU['users'] ?? [])));
}
if (is_callable($srvU['close'] ?? null)) $srvU['close']();

// ── ОПОЗНАНИЕ НА ПОРТАЛЕ С УРЕЗАННЫМИ ПРАВАМИ ПРИЛОЖЕНИЯ ───────────────────
//
// Живой случай (коробка клиента, 18.08.2026): токен зрителя рабочий, но портал
// отвечает на user.current «insufficient_scope» — права приложению урезаны на
// уровне самого сервера. Приложение показывало «портал сейчас не отвечает» и не
// пускало внутрь НИКОГО, хотя портал отвечал исправно. Документированный метод
// profile работает без скоупов и называет того же человека, поэтому опознание
// обязано состояться. Личность при этом по-прежнему называет ПОРТАЛ в ответ на
// токен зрителя — подменять её телом запроса нельзя ни при каких правах.
$urezKoren = sys_get_temp_dir() . '/bxapp-urez-' . getmypid();
@mkdir($urezKoren, 0700, true);
testRegisterTemp($urezKoren); // уборки не было: каталог оставался после каждого прогона
$urezFayl = $urezKoren . '-probe.php';
file_put_contents($urezFayl, '<?php' . "\n"
    . 'require_once ' . var_export($root . '/www/env.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/store.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/lib.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/journal.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/plan.php', true) . ';' . "\n"
    . 'require_once ' . var_export($root . '/www/api/b24.php', true) . ';' . "\n"
    . '$GLOBALS[\'__b24_portal_get\'] = static function (string $d, string $m, string $a): array {' . "\n"
    . '    if ($m === \'profile\') return [\'result\' => [\'ID\' => \'42\', \'ADMIN\' => true]];' . "\n"
    . '    return [\'error\' => \'insufficient_scope\'];' . "\n"
    . '};' . "\n"
    . '$c = resolveUserContextFromAuth(\'auth-urez\', \'portal.invalid\');' . "\n"
    . 'echo json_encode([\'ctx\' => $c], JSON_UNESCAPED_UNICODE);' . "\n");
$urezDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$urezProc = proc_open([PHP_BINARY, $urezFayl], $urezDesc, $urezPipes, $root, [
    'BXAPP_DATA_ROOT' => $urezKoren, 'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
]);
$urezOut = '';
if (is_resource($urezProc)) {
    fclose($urezPipes[0]);
    $urezOut = (string)stream_get_contents($urezPipes[1]);
    (string)stream_get_contents($urezPipes[2]);
    fclose($urezPipes[1]); fclose($urezPipes[2]); proc_close($urezProc);
}
$urezJson = json_decode(trim($urezOut), true);
$urezCtx = is_array($urezJson) ? ($urezJson['ctx'] ?? null) : null;
check('ПОРТАЛ УРЕЗАЛ ПРАВА — ЧЕЛОВЕКА ВСЁ РАВНО ОПОЗНАЛИ',
    is_array($urezCtx) && (string)($urezCtx['id'] ?? '') === '42' && ($urezCtx['isAdmin'] ?? null) === true,
    'ответ пробы: ' . mb_substr($urezOut, 0, 200));
@unlink($urezFayl);
