<?php
declare(strict_types=1);
/**
 * Контракт безопасности — проверяется НАСТОЯЩИМИ HTTP-запросами к приложению,
 * а не вызовом функций напрямую. Разница принципиальная: почти все находки
 * ревью по этой теме выглядели как «функция-то правильная, а эндпоинт её не
 * зовёт» — такое ловится только запросом снаружи.
 *
 * Поднимаем встроенный сервер PHP на временном порту, стучимся curl'ом.
 */

suite('Контракт безопасности (живые HTTP-запросы)');

$root = dirname(__DIR__);
$data = DATA_ROOT;

// Приложение «установлено»: токены с известным порталом.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'example.bitrix24.ru', 'member_id' => 'MEMBER-1',
    'client_endpoint' => 'https://example.bitrix24.ru/rest/',
    'install_generation' => 'test-generation-security',
    'expires_at' => time() + 3600,
]);
// Владелец приложения — сотрудник 10, у него роль admin.
aclSetOwner(10, 'Тестовый Владелец');

$goodToken = str_repeat('a', 64);
$foreignToken = str_repeat('b', 64);
storeWrite(SESSIONS_FILE, [
    ['token' => $goodToken,    'uid' => 10, 'role' => 'admin', 'isAdmin' => false,
     'member_id' => 'MEMBER-1', 'install_generation' => 'test-generation-security',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
    ['token' => $foreignToken, 'uid' => 10, 'role' => 'admin', 'isAdmin' => false,
     'member_id' => 'OTHER-PORTAL', 'install_generation' => 'test-generation-security',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
]);

// installFinish — одна атомарная операция на поколение, не список сотрудников.
$installMetaBackup = storeRead(B24_TOKENS_FILE);
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'meta-access', 'refresh_token' => 'meta-refresh',
    'domain' => 'example.bitrix24.ru', 'member_id' => 'MEMBER-META',
    'client_endpoint' => 'https://example.bitrix24.ru/rest/',
    'install_generation' => 'generation-meta', 'expires_at' => time() + 3600,
]);
$metaB24 = new B24('test-id', 'test-secret');
check('installFinish атомарно захватывается один раз на поколение',
    $metaB24->claimInstallFinishForCurrentGeneration() === true
    && $metaB24->claimInstallFinishForCurrentGeneration() === false);
check('requested и confirmed различаются — неизвестный результат нельзя считать успехом',
    $metaB24->installFinishRequestedForCurrentGeneration()
    && !$metaB24->installFinishConfirmedForCurrentGeneration());
$metaState = storeRead(B24_TOKENS_FILE) ?? [];
check('installFinish хранит глобальный маркер поколения, не user-list',
    ($metaState['install_finish_requested_generation'] ?? '') === 'generation-meta'
    && !array_key_exists('installFinishedUsers', $metaState));
check('app.info-check защищён атомарным cooldown',
    $metaB24->claimAppInfoCheck(300) === true && $metaB24->claimAppInfoCheck(300) === false);
check('автоматического forced-повтора installFinish больше нет',
    !method_exists($metaB24, 'claimInstallFinishForced'));

storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'confirmed-access', 'refresh_token' => 'confirmed-refresh',
    'domain' => 'example.bitrix24.ru', 'member_id' => 'MEMBER-CONFIRMED',
    'client_endpoint' => 'https://example.bitrix24.ru/rest/',
    'install_generation' => 'generation-confirmed', 'expires_at' => time() + 3600,
]);
$confirmedMetaB24 = new B24('test-id', 'test-secret');
$confirmedMetaB24->confirmInstallFinishedForCurrentGeneration();
check('app.info=true закрывает поколение без вызова installFinish',
    $confirmedMetaB24->installFinishHandledForCurrentGeneration()
    && $confirmedMetaB24->installFinishConfirmedForCurrentGeneration()
    && $confirmedMetaB24->claimInstallFinishForCurrentGeneration() === false);

// Legacy per-user marker означает, что installFinish уже хотя бы раз запрашивали:
// мигрируем его в глобальный маркер без повторного сплэша.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'legacy-access', 'refresh_token' => 'legacy-refresh',
    'domain' => 'example.bitrix24.ru', 'member_id' => 'MEMBER-LEGACY',
    'client_endpoint' => 'https://example.bitrix24.ru/rest/',
    'install_generation' => 'generation-legacy', 'expires_at' => time() + 3600,
    'installFinishedUsers' => ['17'], 'installFinishedAt' => date('c', time() - 60),
]);
$legacyMetaB24 = new B24('test-id', 'test-secret');
check('legacy user-list мигрирует без повторного installFinish',
    $legacyMetaB24->claimInstallFinishForCurrentGeneration() === false
    && (storeRead(B24_TOKENS_FILE)['install_finish_requested_generation'] ?? '') === 'generation-legacy');
if (is_array($installMetaBackup)) storeWrite(B24_TOKENS_FILE, $installMetaBackup);

// ── сервер ────────────────────────────────────────────────────────────────
// Обвес (свободный порт, запуск, ожидание готовности, уборка) — в tests/lib.php.
// Креды сервер получает фиктивные: без них приложение честно отвечает «ещё не
// настроено», и проверка поддельной установки проверяла бы не то.
$srv = testServer();
check('встроенный сервер отвечает', $srv['ok']);
if (!$srv['ok']) return;
$port = $srv['port'];

/** Запрос к приложению. Возвращает [код, заголовки(строкой), тело]. */
$req = static function (string $method, string $path, array $opt = []) use ($srv): array {
    if (!empty($opt['cookie'])) $opt['cookie'] = 'gsp_session=' . $opt['cookie'];
    [$code, , $body, $head] = ($srv['call'])($method, $path, $opt);
    return [$code, $head, $body];
};

// ── 1. Состояние приложения не отдаётся по прямой ссылке ──────────────────
// Стор тестов лежит во временной папке, поэтому «настоящий» файл состояния
// внутри веб-корня кладём сюда сами: проверяем именно то, что увидит человек,
// открывший адрес data/... в браузере.
$webData = $root . '/www/data';
$madeDir = !is_dir($webData);
if ($madeDir) @mkdir($webData, 0700, true);
$probe = $webData . '/t-exposure.php';
storeWrite($probe, ['access_token' => 'СЕКРЕТ-НЕ-ДОЛЖЕН-УТЕЧЬ']);
[$code, , $body] = $req('GET', '/data/t-exposure.php');
check('ошибочно опубликованный state сам отвечает 404 без содержимого',
    $code === 404 && trim($body) === ''
    && strpos($body, 'СЕКРЕТ-НЕ-ДОЛЖЕН-УТЕЧЬ') === false,
    "код $code, тело: " . substr(trim($body), 0, 60));
@unlink($probe);
@unlink($probe . '.lock.php');
if ($madeDir) @rmdir($webData);

[$code, , $body] = $req('GET', '/env.php');
check('конфиг не отдаёт содержимое по прямой ссылке', trim($body) === '', "код $code");
[$code, , $body] = $req('GET', '/bin/deploy-private-store.php');
check('deploy-preflight нельзя вызвать по HTTP', $code === 404 && trim($body) === '', "код $code");

// ── 2. Гейт эндпоинта ────────────────────────────────────────────────────
[$code, $head, ] = $req('GET', '/api/acl.php');
check('без пропуска эндпоинт отвечает отказом', $code === 403, "код $code");
check('отказ не кэшируется браузером', stripos($head, 'no-store') !== false);

[$code, , $body] = $req('GET', '/api/acl.php', ['token' => $goodToken]);
check('с пропуском администратора список доступа отдаётся', $code === 200 && strpos($body, 'owner_uid') !== false, "код $code");

// Главная проверка: cookie, которую браузер обязан слать в iframe, НЕ пускает
// в эндпоинт. Иначе чужой сайт выполнил бы действие от имени сотрудника.
[$code, , ] = $req('GET', '/api/acl.php', ['cookie' => $goodToken]);
check('одной cookie для доступа к эндпоинту недостаточно', $code === 403, "код $code");

// Соседнее приложение живёт на том же origin. Fetch и iframe уже закрыты, но
// top-level popup сохраняет opener и позволял прочитать window.APP_SESSION.
[$code, , $body] = $req('GET', '/index.php', [
    'cookie' => $goodToken,
    'headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document'],
]);
check('same-origin popup не получает token-bearing страницу',
    $code === 403 && strpos($body, 'APP_SESSION') === false, "код $code");
[$code, , $body] = $req('GET', '/index.php', [
    'cookie' => $goodToken, 'site' => 'cross-site',
    'headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: iframe'],
]);
check('штатный iframe портала после popup-защиты продолжает работать',
    $code === 200 && strpos($body, 'APP_SESSION') !== false, "код $code");

// Install/session делят один bounded parser: index обязан вернуть 413 ещё до
// JSON/form decode, в том числе без Content-Length при chunked.
$oversizedIndex = json_encode(['payload' => str_repeat('x', PORTAL_POST_MAX_BODY + 1024)]);
[$code, , ] = $req('POST', '/index.php', ['body' => $oversizedIndex]);
check('index отклоняет большое JSON-тело до разбора', $code === 413, "код $code");
$oversizedIndexForm = http_build_query(['payload' => str_repeat('x', PORTAL_POST_MAX_BODY + 1024)]);
[$code, , ] = $req('POST', '/index.php', [
    'type' => 'application/x-www-form-urlencoded',
    'headers' => ['Transfer-Encoding: chunked'],
    'body' => $oversizedIndexForm,
]);
check('chunked form-body не обходит общий потолок index/session', $code === 413, "код $code");

// Один AUTH_ID без browser-fence не должен запускать install/self-heal ветку.
$beforeAnonymousAuth = storeRead(B24_TOKENS_FILE) ?? [];
[$code, , ] = $req('POST', '/index.php', [
    'body' => http_build_query(['AUTH_ID' => 'anonymous-probe']),
]);
$afterAnonymousAuth = storeRead(B24_TOKENS_FILE) ?? [];
check('анонимный AUTH_ID без APP_SID/DOMAIN/Referer отклоняется', $code === 403, "код $code");
check('анонимный AUTH_ID не запускает installFinish/app.info state-machine',
    ($afterAnonymousAuth['app_info_checked_at'] ?? null) === ($beforeAnonymousAuth['app_info_checked_at'] ?? null)
    && ($afterAnonymousAuth['install_finish_requested_generation'] ?? null)
        === ($beforeAnonymousAuth['install_finish_requested_generation'] ?? null));

$indexInstallSource = (string)file_get_contents($root . '/www/index.php');
check('app.info стоит после свежего portal-admin proof и cooldown claim',
    ($adminProofPos = strpos($indexInstallSource, "!empty(\$currentUserContext['isAdmin'])")) !== false
    && ($cooldownPos = strpos($indexInstallSource, 'claimAppInfoCheck($appInfoCooldown)')) !== false
    && ($appInfoPos = strpos($indexInstallSource, "'app.info', \$authId")) !== false
    && $adminProofPos < $appInfoPos && $cooldownPos < $appInfoPos);
check('installFinish разрешается только после успешного placement read-back и app.info=false',
    ($bindPos = strpos($indexInstallSource, 'bindPlacements($b24)')) !== false
    && ($appInfoPos = strpos($indexInstallSource, "'app.info', \$authId")) !== false
    && ($finishClaimPos = strpos($indexInstallSource, 'claimInstallFinishForCurrentGeneration()', $appInfoPos)) !== false
    && $appInfoPos < $bindPos && $bindPos < $finishClaimPos
    && strpos($indexInstallSource, 'claimInstallFinishForced') === false);
check('переустановка определяется app.info=false, а не одним INSTALL=Y',
    ($statusFalsePos = strpos($indexInstallSource, '$portalInstalledStatus === false')) !== false
    && ($unknownPos = strpos($indexInstallSource, '$finishRequested && !$finishConfirmed', $statusFalsePos)) !== false
    && ($confirmedPos = strpos($indexInstallSource, '$finishConfirmed && $b24->verifyAndAdopt($post)', $unknownPos)) !== false
    && $statusFalsePos < $unknownPos && $unknownPos < $confirmedPos);
// Автоматического повтора нет — но и тупика быть не должно. Сброс маркера
// разрешён только по ЯВНОМУ действию администратора портала (кнопка на экране
// 409 шлёт bxapp_retry_install_finish=1), поэтому проверяем не текст сообщения,
// а само условие: reset стоит под флагом из запроса и под уже доказанным
// admin-контекстом, а не вызывается сам по себе.
$retryFlagPos  = strpos($indexInstallSource, "\$post['bxapp_retry_install_finish'] ?? ''");
$resetCallPos  = strpos($indexInstallSource, '$b24->resetInstallFinish();');
check('неизвестный результат installFinish не запускает автоматический retry',
    strpos($indexInstallSource, '$installFinishOutcomeUnknown = true;', $statusFalsePos) !== false
    && strpos($indexInstallSource, '$appInfoCooldown = 5;') !== false
    && $retryFlagPos !== false && $resetCallPos !== false
    && $retryFlagPos < $resetCallPos
    && $adminProofPos < $retryFlagPos
    && substr_count($indexInstallSource, '$b24->resetInstallFinish();') === 1);
check('ошибка placements блокирует install-flow и без недокументированного INSTALL',
    strpos($indexInstallSource,
        'if (!$placementsReady && ($portalInstalledStatus === false || $installationAdoptedThisRequest))') !== false);

[$code, , ] = $req('POST', '/api/acl.php', ['cookie' => $goodToken, 'body' => '{"action":"delete","uid":10}']);
check('изменение по одной cookie не проходит', $code === 403, "код $code");

// ── 3. Пропуск, выданный на другом портале ───────────────────────────────
[$code, , ] = $req('GET', '/api/acl.php', ['token' => $foreignToken]);
check('пропуск с другого портала не действует', $code === 403, "код $code");

// ── 4. Мгновенный отзыв доступа ──────────────────────────────────────────
$aclBackup = storeRead(ACL_FILE);
storeWrite(ACL_FILE, ['owner_uid' => 999, 'users' => []]);      // сотрудника убрали из списка
[$code, , ] = $req('GET', '/api/acl.php', ['token' => $goodToken]);
check('доступ пропадает сразу после снятия прав, не дожидаясь конца дня', $code === 403, "код $code");
storeWrite(ACL_FILE, $aclBackup ?? []);
// Пропуск был погашен вместе с отзывом — это тоже часть контракта.
$alive = array_filter(storeRead(SESSIONS_FILE) ?? [], static fn($s) => ($s['token'] ?? '') === $goodToken);
check('пропуск снятого сотрудника погашен, а не оставлен «до истечения»', $alive === []);

// ── 5. Установка: подделать её нельзя ────────────────────────────────────
// Адрес обработчика публичен. POST с выдуманными токенами не должен ни
// записать состояние, ни выдать сессию. Проверяем на ЧИСТОМ приложении:
// временно уводим стор в сторону, чтобы «не установлено» было правдой.
$tokensBackup = storeRead(B24_TOKENS_FILE);
@unlink(B24_TOKENS_FILE);
[$code, , $body] = $req('POST', '/index.php', [
    'body' => http_build_query([
        'AUTH_ID' => 'ПОДДЕЛКА', 'REFRESH_ID' => 'ПОДДЕЛКА',
        'DOMAIN' => 'example.bitrix24.ru', 'APP_SID' => 'x', 'INSTALL' => 'Y',
    ]),
]);
check('поддельная установка отклоняется', $code === 403, "код $code");
check('поддельная установка не создаёт состояние', storeRead(B24_TOKENS_FILE) === null);
if (is_array($tokensBackup)) storeWrite(B24_TOKENS_FILE, $tokensBackup);

// ── 6. События портала ───────────────────────────────────────────────────
[$code, , ] = $req('POST', '/api/event.php', [
    'body' => http_build_query(['event' => 'ONCRMDEALUPDATE', 'auth' => ['application_token' => 'ЧУЖОЙ']]),
]);
check('событие отклоняется, пока токен приложения не сохранён', $code === 403, "код $code");
check('отклонённое событие не попадает в очередь', storeRead(DATA_ROOT . '/inbox.php') === null);

// Дальше — с СОХРАНЁННЫМ токеном. Предыдущая проверка сама по себе ничего не
// стоит: она проходит и по ветке «сверять не с чем», то есть не проверяет
// сверку вовсе.
storeUpdate(B24_TOKENS_FILE, static function (array $t): array {
    $t['application_token'] = 'НАСТОЯЩИЙ-ТОКЕН-ПРИЛОЖЕНИЯ';
    return $t;
});
[$code, , ] = $req('POST', '/api/event.php', [
    'body' => http_build_query([
        'event' => 'ONCRMDEALUPDATE',
        'auth'  => ['application_token' => 'ЧУЖОЙ', 'member_id' => 'MEMBER-1'],
    ]),
]);
check('событие с чужим токеном при сохранённом своём отклоняется', $code === 403, "код $code");
[$code, , ] = $req('POST', '/api/event.php', [
    'body' => http_build_query([
        'event' => 'ONCRMDEALUPDATE',
        'auth'  => ['application_token' => 'НАСТОЯЩИЙ-ТОКЕН-ПРИЛОЖЕНИЯ', 'member_id' => 'ЧУЖОЙ-ПОРТАЛ'],
    ]),
]);
check('событие с чужого портала отклоняется даже с верным токеном', $code === 403, "код $code");
check('ни одно отклонённое событие не попало в очередь', storeRead(DATA_ROOT . '/inbox.php') === null);

// Публичный 403 остаётся fail-closed, но не превращается в безлимитный writer
// журнала. Bucket один на handler: недоверенное имя не создаёт новый счётчик.
storeWrite(RATELIMIT_FILE, []);
$deniedBefore = array_values(array_filter(
    journalQuery(['limit' => 200])['rows'],
    static fn(array $row): bool => strpos((string)($row['msg'] ?? ''), 'event: отклонено') !== false
));
$allDenied = true;
for ($i = 0; $i < 9; $i++) {
    [$deniedCode, , ] = $req('POST', '/api/event.php', [
        'body' => http_build_query([
            'event' => "FORGED\n" . str_repeat('Z', 180) . $i,
            'auth' => ['application_token' => 'ЧУЖОЙ-' . $i, 'member_id' => 'MEMBER-1'],
        ]),
    ]);
    if ($deniedCode !== 403) $allDenied = false;
}
$deniedAfterRows = journalQuery(['limit' => 200])['rows'];
$deniedAfter = array_values(array_filter(
    $deniedAfterRows,
    static fn(array $row): bool => strpos((string)($row['msg'] ?? ''), 'event: отклонено') !== false
));
$deniedDelta = count($deniedAfter) - count($deniedBefore);
$deniedRaw = is_file(journalFile()) ? (string)file_get_contents(journalFile()) : '';
check('анонимные события по-прежнему получают 403, но журналируются не чаще готового лимитера',
    $allDenied && $deniedDelta > 0 && $deniedDelta <= 5,
    'новых строк: ' . $deniedDelta);
check('недоверенное имя события не раздувает и не разбивает строку журнала',
    strpos($deniedRaw, str_repeat('Z', 80)) === false);

$oversizedEvent = http_build_query([
    'event' => 'ONCRMDEALUPDATE',
    'data'  => ['FIELDS' => ['ID' => 76, 'COMMENT' => str_repeat('x', 270000)]],
    'auth'  => ['application_token' => 'НАСТОЯЩИЙ-ТОКЕН-ПРИЛОЖЕНИЯ', 'member_id' => 'MEMBER-1'],
]);
[$code, , ] = $req('POST', '/api/event.php', [
    'type' => 'application/x-www-form-urlencoded', 'body' => $oversizedEvent,
]);
check('слишком большое событие отклоняется до записи в очередь',
    $code === 413 && storeRead(DATA_ROOT . '/inbox.php') === null, "код $code");

// При Transfer-Encoding: chunked заголовка Content-Length нет, а PHP всё равно
// может заполнить $_POST. Лимит обязан измерять фактически прочитанное тело.
[$code, , ] = $req('POST', '/api/event.php', [
    'type' => 'application/x-www-form-urlencoded',
    'headers' => ['Transfer-Encoding: chunked'],
    'body' => $oversizedEvent,
]);
check('chunked form-событие не обходит потолок тела через заполненный $_POST',
    $code === 413 && storeRead(DATA_ROOT . '/inbox.php') === null, "код $code");

// Ревью 23.09.2026 (otkazy-1): пока установка держит замок записи, событие
// получает 503 — а портал повторно его не пришлёт (events/index.md: «Повторных
// отправок нет»). Прежде об этой потере в журнале не оставалось ни строки.
storeWrite(RATELIMIT_FILE, []);
$busyLock = @fopen(DATA_ROOT . '/lifecycle.lock.php', 'c');
$busyHeld = is_resource($busyLock) && flock($busyLock, LOCK_EX | LOCK_NB);
[$busyCode, , ] = $req('POST', '/api/event.php', [
    'body' => http_build_query([
        'event' => 'ONCRMDEALUPDATE',
        'data'  => ['FIELDS' => ['ID' => 78]],
        'auth'  => ['application_token' => 'НАСТОЯЩИЙ-ТОКЕН-ПРИЛОЖЕНИЯ', 'member_id' => 'MEMBER-1'],
    ]),
]);
if (is_resource($busyLock)) { flock($busyLock, LOCK_UN); fclose($busyLock); }
$busyRows = array_values(array_filter(
    journalQuery(['limit' => 200])['rows'],
    static fn(array $row): bool => strpos((string)($row['msg'] ?? ''), 'event: не принято') !== false
));
check('событие, не принятое из-за занятой установки, оставляет строку в журнале',
    $busyHeld && $busyCode === 503 && $busyRows !== []
    && strpos((string)($busyRows[0]['msg'] ?? ''), 'ONCRMDEALUPDATE') !== false,
    'замок: ' . var_export($busyHeld, true) . ', код ' . $busyCode . ', строк: ' . count($busyRows));

[$code, , ] = $req('POST', '/api/event.php', [
    'body' => http_build_query([
        'event' => 'ONCRMDEALUPDATE',
        'data'  => ['FIELDS' => ['ID' => 77]],
        'auth'  => ['application_token' => 'НАСТОЯЩИЙ-ТОКЕН-ПРИЛОЖЕНИЯ', 'member_id' => 'MEMBER-1'],
    ]),
]);
$inbox = storeRead(DATA_ROOT . '/inbox.php') ?? [];
check('подлинное событие принимается', $code === 200, "код $code");
check('подлинное событие попадает в очередь целиком',
    count($inbox) === 1 && ($inbox[0]['event'] ?? '') === 'ONCRMDEALUPDATE'
    && (string)($inbox[0]['data']['FIELDS']['ID'] ?? '') === '77');

// ── 7. Список доступа: правка, защита владельца и гашение пропусков ──────
// Пропуск администратора выдаём заново: проверка отзыва прав выше его погасила
// (и это правильное поведение, а не помеха — просто дальше нужен живой пропуск).
$adminToken2 = str_repeat('f', 64);
storeUpdate(SESSIONS_FILE, static function (array $all) use ($adminToken2): array {
    $all[] = ['token' => $adminToken2, 'uid' => 10, 'role' => 'admin', 'member_id' => 'MEMBER-1',
              'install_generation' => 'test-generation-security',
              'createdAt' => time(), 'expiresAt' => time() + 3600];
    return $all;
});
$victim = 20;
$victimToken = str_repeat('e', 64);
storeUpdate(SESSIONS_FILE, static function (array $all) use ($victim, $victimToken): array {
    $all[] = ['token' => $victimToken, 'uid' => $victim, 'role' => 'view', 'member_id' => 'MEMBER-1',
              'install_generation' => 'test-generation-security',
              'createdAt' => time(), 'expiresAt' => time() + 3600];
    return $all;
});

[$code, , ] = $req('POST', '/api/acl.php', ['token' => $adminToken2,
    'body' => json_encode(['action' => 'update', 'uid' => 10, 'role' => 'view'])]);
$ownerAudit = null;
foreach (journalQuery(['kind' => 'action', 'limit' => 50])['rows'] as $auditRow) {
    if (($auditRow['event'] ?? '') === 'acl.update' && (int)($auditRow['ctx']['uid'] ?? 0) === 10) {
        $ownerAudit = $auditRow;
        break;
    }
}
check('принудительная роль владельца admin отражается и в ACL, и в аудите',
    $code === 200 && aclUserRole(10) === 'admin'
    && ($ownerAudit['ctx']['role'] ?? '') === 'admin', "код $code");

[$code, , ] = $req('POST', '/api/acl.php', ['token' => $adminToken2,
    'body' => json_encode(['action' => 'add', 'uid' => $victim, 'role' => 'edit'])]);
$roles = array_column(aclLoad()['users'], 'role', 'uid');
check('администратор добавляет сотрудника в список доступа',
    $code === 200 && ($roles[$victim] ?? '') === 'edit', "код $code");

[$code, , ] = $req('POST', '/api/acl.php', ['token' => $adminToken2,
    'body' => json_encode(['action' => 'delete', 'uid' => 10])]);
check('владельца приложения удалить нельзя', $code === 400, "код $code");
check('владелец остался в списке', aclOwnerUid() === 10);

[$code, , ] = $req('POST', '/api/acl.php', ['token' => $adminToken2,
    'body' => json_encode(['action' => 'delete', 'uid' => $victim])]);
$stillThere = array_filter(storeRead(SESSIONS_FILE) ?? [],
    static fn($s) => (int)($s['uid'] ?? 0) === $victim);
check('снятие доступа удаляет сотрудника из списка', $code === 200
    && !in_array($victim, array_column(aclLoad()['users'], 'uid'), true), "код $code");
check('вместе с доступом гаснут и его пропуска', $stillThere === []);

// Передача owner — не обычная ACL-операция. Локальная роль admin не доказывает,
// что человек всё ещё администратор портала; забрать владение может только тот,
// кого user.current + user.admin подтвердили при недавнем открытии iframe.
[$code, $json, ] = ($srv['call'])('POST', '/api/acl.php', [
    'token' => $adminToken2, 'body' => json_encode(['action' => 'claim_owner']),
]);
check('локальный admin без свежего доказательства портала не забирает владение',
    $code === 403 && ($json['code'] ?? '') === 'portal_admin_required' && aclOwnerUid() === 10,
    "код $code");

$portalAdminUid = 30;
$portalAdminToken = str_repeat('9', 64);
storeUpdate(SESSIONS_FILE, static function (array $all) use ($portalAdminUid, $portalAdminToken): array {
    $all[] = [
        'token' => $portalAdminToken, 'uid' => $portalAdminUid, 'role' => 'admin', 'isAdmin' => true,
        'adminCheckedAt' => time(), 'member_id' => 'MEMBER-1',
        'install_generation' => 'test-generation-security',
        'createdAt' => time(), 'lastSeen' => time(), 'expiresAt' => time() + 3600,
    ];
    return $all;
});
[$code, $json, ] = ($srv['call'])('GET', '/api/acl.php', ['token' => $portalAdminToken]);
check('недавно подтверждённый portal-admin видит безопасную передачу себе',
    $code === 200 && !empty($json['owner_transfer']['can_claim']), "код $code");
[$code, $json, ] = ($srv['call'])('POST', '/api/acl.php', [
    'token' => $portalAdminToken,
    // Произвольный uid намеренно передан: endpoint не должен назначить его.
    'body' => json_encode(['action' => 'claim_owner', 'uid' => 999]),
]);
$oldOwnerSessions = array_filter(storeRead(SESSIONS_FILE) ?? [],
    static fn($s) => (int)($s['uid'] ?? 0) === 10);
check('portal-admin передаёт owner только самому себе, не uid из тела',
    $code === 200 && aclOwnerUid() === $portalAdminUid
    && (int)($json['owner_transfer']['new_owner_uid'] ?? 0) === $portalAdminUid,
    "код $code");
check('передача атомарно убирает прежнего owner из ACL и отзывает его сессии',
    aclUserRole(10) === null && $oldOwnerSessions === [] && aclUserRole($portalAdminUid) === 'admin');

$appJs = (string)file_get_contents($root . '/www/js/app.js');
$templateHtml = (string)file_get_contents($root . '/www/template.html');
check('передача владельца доступна сотруднику из готового интерфейса, а не только через API',
    strpos($appJs, "action: 'claim_owner'") !== false
    && strpos($templateHtml, 'id="btn-claim-owner"') !== false);

// Сервер убирает за собой сам (см. testServer в tests/lib.php).

// ── Незаполненные креды объясняются человеческим языком ───────────────────
// Самая частая причина неудачной установки — не подделка, а два пустых поля
// конфига. Общий текст «установка не подтверждена» отправлял человека проверять
// права администратора, то есть искать не там.
// Стор — свой и пустой: с токенами от прошлых проверок установка перестанет
// быть первой, и мы проверили бы совсем другую ветку.
$blankData = DATA_ROOT . '/blank-' . bin2hex(random_bytes(3));
mkdir($blankData, 0700, true);
// Копия www с очищенными слотами: проверяем поведение приложения БЕЗ кредов, а не
// содержимое конкретного env.php. Раньше проверка читала боевой конфиг проекта и
// падала у всех, кто вписал туда свои тестовые креды по инструкции шаблона.
$blank = testServer(['BXAPP_CLIENT_ID' => null, 'BXAPP_CLIENT_SECRET' => null,
    'BXAPP_DATA_ROOT' => $blankData], ['blankLocalCreds' => true]);
if ($blank['ok']) {
    [$code, , $body] = ($blank['call'])('POST', '/index.php', [
        'type' => 'application/x-www-form-urlencoded',
        'body' => http_build_query(['AUTH_ID' => 'x', 'REFRESH_ID' => 'y',
            'DOMAIN' => 'example.bitrix24.ru', 'INSTALL' => 'Y']),
    ]);
    check('без кредов приложение говорит «ещё не настроено», а не «установка не подтверждена»',
        $code === 503 && mb_strpos($body, 'не настроено') !== false, "код $code");
    check('на экране написано, что именно сделать', mb_strpos($body, 'set-creds') !== false);
} else {
    check('второй сервер для проверки пустых кредов поднялся', false);
}
foreach (glob($blankData . '/*') ?: [] as $f) @unlink($f);
@rmdir($blankData);

// ── Креды живут отдельно от конфига ───────────────────────────────────────
// Обычный release наследует remote env, но явный --push-env заменяет его
// локальным и способен затереть вписанные туда креды пустыми. Проверяем в
// отдельном процессе: конфиг обязан взять их из shared-файла, даже когда
// собственные поля пусты.
$credsFile = $data . '/creds-probe.php';
file_put_contents($credsFile, BXAPP_PRIVATE_PHP_HEADER
    . json_encode(['client_id' => 'local.probe123', 'client_secret' => 'SECRET-PROBE']));
$probe = 'require ' . var_export($root . '/www/env.php', true) . '; echo B24_CLIENT_ID, "|", B24_CLIENT_SECRET;';
$out = (string)shell_exec('BXAPP_DATA_ROOT=' . escapeshellarg($data)
    . ' BXAPP_CREDS_FILE=' . escapeshellarg($credsFile)
    . ' php -r ' . escapeshellarg($probe) . ' 2>&1');
check('креды читаются из отдельного файла, который деплой не трогает',
    $out === 'local.probe123|SECRET-PROBE', $out);

// Private layout — основной барьер, исполняемый префикс остаётся защитой при
// переносе или ошибочной публикации.
check('файл кредов закрыт 404+exit-префиксом',
    privatePhpHas404Prefix((string)file_get_contents($credsFile)));
@unlink($credsFile);

// ── Что под веб-корнем отвечает по прямой ссылке (BXREV-025) ───────────────
// Находка ревью 08.08.2026: прямой запрос к `env.php` и десятку библиотек в
// `www/api/` возвращал HTTP 200 с пустым телом. Утечки данных это не давало, но
// подтверждало и путь, и существование файла — то есть карту приложения для
// того, кто его изучает. Деплой публикует каталог целиком, поэтому «этот файл
// вызывают только по require» ничего не значит для веба.
//
// Проверяем ЖИВЫМ сервером, а не чтением кода: код читали и раньше.
$invSrv = testServer();
if (!$invSrv['ok']) {
    check('встроенный сервер для инвентаризации запущен', false);
} else {
    $invCall = $invSrv['call'];
    // Точки входа — отвечают на прямой HTTP по замыслу. Признак точки входа —
    // не список имён, а КОД: файл сам разбирает запрос, то есть зовёт apiBoot()
    // (эндпоинты) либо сверяет токен приложения через hash_equals (index.php и
    // api/event.php, куда портал шлёт установку и события).
    //
    // ПОЧЕМУ НЕ ИМЕНАМИ. Список был зашит здесь, и первый же эндпоинт проекта
    // делал прогон красным: «api/report.php отвечает не 404». Чтобы это
    // починить, проект был обязан править ЭТОТ файл — то есть ядро, которое
    // обновляется из шаблона. Живой прогон агента 09.08.2026: он полез сюда
    // вписывать свою точку входа, потратив на разбор гейта больше, чем на саму
    // фичу. Сила проверки при этом не падает: библиотека без гарда по-прежнему
    // обязана отвечать 404, а «сам разбирает запрос» — то же условие, которым
    // список проверялся ниже.
    $tochkiShablona = ['index.php', 'api/health.php', 'api/settings.php', 'api/acl.php',
        'api/users.php', 'api/logs.php', 'api/ops.php', 'api/task.php', 'api/bind.php',
        'api/event.php'];
    $koren = dirname(__DIR__) . '/www';
    require_once __DIR__ . '/gate-lib.php';
    // Обход веб-корня РЕКУРСИВНЫЙ. Прежние два glob'а («» и «api/») смотрели
    // ровно один уровень, а гейт мест PHP разрешает весь префикс www/api/:
    // помощник в www/api/inc/ публиковался деплоем, отвечал 200 и не проверялся
    // ничем — ни здесь, ни картой (K4-07 и K4-09, ревью 09.08.2026). Название
    // проверки при этом обещало «ПОД ВЕБ-КОРНЕМ», а не «на первом уровне».
    $sobratPhp = static function () use ($koren): array {
        $out = [];
        foreach (gatePhpSourceFilesUnder($koren) as $f) {
            $out[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($f, strlen($koren) + 1));
        }
        sort($out);
        return $out;
    };

    // ОБРАЗЕЦ НАРУШЕНИЯ прямо в прогоне: библиотека уровнем глубже. Пока обход
    // был неглубоким, её не было ни в списке, ни в живом запросе — проверка
    // молчала. Файлы живут секунды и убираются в любом исходе, в том числе
    // при обрыве прогона.
    // Файлы-образцы подсаживаются НЕ в www/ проекта, а в его временную копию:
    // дерево исходников тесты не трогают. В изоляции платформы прогонов снимок
    // проекта только на чтение, и запись в www/api/ падала «No such file or
    // directory» — две проверки были красными у каждого проекта по этому шаблону
    // (19.09.2026). Копия www с пробами — тот же механизм, что у fixtures:
    // testServer(wwwFiles) копирует www во временный каталог, подкладывает файлы
    // (вложенный путь допустим) и поднимает сервер на копии; каталог убирается
    // общей уборкой временных деревьев в любом исходе прогона.
    $probaZakryt  = 'api/__proba-podkatalog/proba-zakryta.php';
    $probaOtkryt  = 'api/__proba-podkatalog/proba-otkryta.php';
    $probaSrv = testServer([], ['wwwFiles' => [
        // Закрытая: канонический забор прямого доступа, как у любой библиотеки.
        $probaZakryt => "<?php\nif (realpath(__FILE__) === realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '')) {\n"
            . "    http_response_code(404);\n    exit;\n}\nfunction probaPodkatalogaRows(): array { return []; }\n",
        // Открытая: ни забора, ни данных — ровно то, что обход обязан поймать.
        $probaOtkryt => "<?php\nreturn;\n",
    ]]);
    // Отдельной проверки «сервер поднят» нет намеренно: счёт набора остаётся 87,
    // и порог 58 в REQUIRED_SUITE_MIN_CHECKS не отстаёт от факта. Если сервер не
    // поднялся — это видно в диагностике проверок кода ответа ниже.
    $probaDiag = $probaSrv['ok'] ? '' : ' (сервер на копии www не поднялся: ' . json_encode($probaSrv['diagnostic'] ?? null, JSON_UNESCAPED_UNICODE) . ')';
    $probaKoren = (string)$probaSrv['root'] . '/www';
    $sProboj = [];
    foreach (gatePhpSourceFilesUnder($probaKoren) as $f) {
        $sProboj[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($f, strlen($probaKoren) + 1));
    }
    sort($sProboj);
    check('обход веб-корня видит PHP в подкаталоге api/',
        in_array($probaZakryt, $sProboj, true) && in_array($probaOtkryt, $sProboj, true),
        'в списке ' . count($sProboj) . ' файлов, подсаженных нет');
    [$kodZakryt] = ($probaSrv['call'])('GET', '/' . $probaZakryt);
    [$kodOtkryt] = ($probaSrv['call'])('GET', '/' . $probaOtkryt);
    check('библиотека в подкаталоге с забором отвечает 404', $kodZakryt === 404, 'код ' . $kodZakryt . $probaDiag);
    check('библиотека в подкаталоге БЕЗ забора отвечает не 404 — значит проверка ниже её поймает',
        $kodOtkryt !== 404, 'код ' . $kodOtkryt);
    ($probaSrv['close'])();

    $vsePhp = $sobratPhp();
    check('образец нарушения не попал в веб-корень проекта',
        !in_array($probaZakryt, $vsePhp, true) && !in_array($probaOtkryt, $vsePhp, true)
        && !is_dir($koren . '/api/__proba-podkatalog'));
    // Признак ПОЛОЖИТЕЛЬНЫЙ и считается по исполнению: аутентификация должна
    // случиться ПРЯМО ПРИ ЗАГРУЗКЕ файла, а не когда-нибудь потом.
    //
    // ТРИ НЕВЕРНЫХ ПРИЗНАКА, ВСЕ ПРОВЕРЕНЫ ПРОГОНОМ И ОТБРОШЕНЫ:
    //   • «файл упоминает apiBoot или hash_equals» — http.php ОБЪЯВЛЯЕТ apiBoot,
    //     b24.php и session.php зовут hash_equals внутри своих функций: пятеро
    //     библиотек стали бы «точками входа»;
    //   • «у файла нет забора прямого доступа» — признак вывернут наизнанку:
    //     новый ОТКРЫТЫЙ файл без забора и без всякой проверки прав объявлял бы
    //     себя точкой входа и проходил зелёным, то есть забор переставал ловить
    //     ровно то, ради чего заведён (находка ревью 09.08.2026, K2-01, P0).
    //     Сюда же попадала библиотека с рабочим, но иначе написанным забором —
    //     404 через header() или гард PHP_SAPI, как в www/bin/process.php: она
    //     объявлялась точкой входа и валила прогон (K2-04);
    //   • «вызов на самом верхнем уровне файла» (ручной счёт фигурных скобок) —
    //     ранняя защита index.php живёт в немедленно вызванных замыканиях, и
    //     index.php переставал быть точкой входа: прогон 09.08.2026 покраснел
    //     на двух проверках сразу.
    //
    // Поэтому «зовёт при загрузке» считает ОБЩИЙ предикат забора из
    // tests/gate-lib.php: он прослеживает исполнение (замыкание, вызванное на
    // месте, — часть загрузки) и молчит там, где не уверен. Молчание безопасно:
    // неопознанный файл считается библиотекой и обязан отвечать 404, то есть
    // промах предиката выходит красным прогоном, а не тихой дырой.
    require_once __DIR__ . '/gate-lib.php';
    $samRazbiraet = static function (string $otn) use ($koren): bool {
        foreach (gateTopLevelCalls($koren . '/' . $otn) as $imya) {
            // Имена функций в PHP регистронезависимы — сравниваем так же.
            if (strcasecmp($imya, 'apiBoot') === 0 || strcasecmp($imya, 'hash_equals') === 0) return true;
        }
        return false;
    };
    $tochkiVhoda = [];
    foreach ($vsePhp as $otn) {
        if ($otn === 'release.php') continue;
        // www/bin — CLI-точки. Они лежат под веб-корнем и потому обязаны отдавать
        // по HTTP пустой 404, что бы ни делали при запуске из планировщика:
        // process.php сверяет токен жизненного цикла через hash_equals, но
        // точкой входа ПО HTTP от этого не становится. Без этой оговорки
        // рекурсивный обход объявил его точкой входа и потребовал ответа.
        if (strncmp($otn, 'bin/', 4) === 0) continue;
        if ($samRazbiraet($otn)) $tochkiVhoda[] = $otn;
    }
    // Точки входа САМОГО ШАБЛОНА обязаны остаться на месте: если одна из них
    // перестала разбирать запрос, это не «проект так решил», а поломка ядра.
    $poteryannye = array_values(array_diff($tochkiShablona, $tochkiVhoda));
    check('точки входа шаблона на месте и сами разбирают запрос', $poteryannye === [],
        $poteryannye === [] ? '' : 'перестали разбирать запрос при загрузке: ' . implode(', ', $poteryannye)
            . ' — вернуть в загрузку файла вызов apiBoot(), а для index.php и api/event.php сверку'
            . ' application_token через hash_equals');
    $otkrytye = [];
    $zakrytyeVhody = [];
    $zakrytyeBiblioteki = [];
    foreach ($vsePhp as $otn) {
        if ($otn === 'release.php') continue;   // его создаёт деплой, в шаблоне нет
        [$kod] = $invCall('GET', '/' . $otn);
        $vhod = in_array($otn, $tochkiVhoda, true);
        if (!$vhod && $kod !== 404) $otkrytye[] = $otn . ' → ' . $kod;
        if (!$vhod && $kod === 404) $zakrytyeBiblioteki[] = $otn;
        if ($vhod && $kod === 404) $zakrytyeVhody[] = $otn;
    }
    // Забор бывает написан по-разному (exit-префикс, header() с 404, гард
    // PHP_SAPI, вызов общего хелпера) — здесь важен ОТВЕТ, а не то, каким
    // текстом он получен. Поэтому и подсказка называет цель, а не один способ.
    check('БИБЛИОТЕКИ ПОД ВЕБ-КОРНЕМ ОТВЕЧАЮТ 404 НА ПРЯМОЙ ЗАПРОС',
        $otkrytye === [],
        $otkrytye === [] ? '' : 'отвечает не 404: ' . implode(', ', $otkrytye)
            . ' — закрыть забором прямого доступа (404 и exit до любой работы); если это точка'
            . ' входа, она обязана сама разбирать запрос — звать apiBoot() при загрузке');
    check('точки входа при этом продолжают отвечать, а не закрылись заодно',
        $zakrytyeVhody === [],
        $zakrytyeVhody === [] ? '' : implode(', ', $zakrytyeVhody)
            . ': разбирает запрос при загрузке, но отвечает 404 — если это библиотека, забор'
            . ' ПРАВИЛЬНЫЙ, а вызов apiBoot()/hash_equals надо унести внутрь функции');
    // У ПРОВЕРКИ ОБЯЗАН БЫТЬ КОНТРПРИМЕР. Если бы точками входа оказались ВСЕ
    // файлы под веб-корнем, проверка «библиотеки отвечают 404» стала бы пустой:
    // проверять было бы нечего, а прогон остался бы зелёным. Так и жила
    // api/export-csv.php — вписанная в список исключений и открытая пустым 200
    // без аутентификации (находка ревью 09.08.2026).
    //
    // Считаем по ЖИВЫМ ответам, а не по тексту файлов: прежний счёт «всего минус
    // точки входа» брался из того же скана, который и назначает ожидание, и не
    // двигался, сколько открытых файлов ни подсади (K2-03). Теперь подсаженный
    // открытый файл увеличивает число библиотек, а его 200 виден отдельно —
    // страховка снова реагирует на то, от чего заведена.
    $bibliotek = count($zakrytyeBiblioteki) + count($otkrytye);
    check('под веб-корнем есть библиотеки, и хотя бы одна реально ответила 404',
        $bibliotek > 0 && $zakrytyeBiblioteki !== [],
        'библиотек: ' . $bibliotek . ', из них ответили 404: ' . count($zakrytyeBiblioteki));
    // Ответ 404 не должен кэшироваться: иначе браузер и промежуточные узлы
    // запомнят его для адреса, который завтра станет точкой входа.
    // call отдаёт [код, разобранный json, тело, заголовки] — заголовки четвёртые.
    [, , , $zagolovki] = $invCall('GET', '/api/store.php');
    check('закрытая библиотека отвечает без кэширования',
        stripos((string)$zagolovki, 'no-store') !== false, mb_substr((string)$zagolovki, 0, 200));
}

// ── Обход первой установки — по разовому билету (BXREV-011) ────────────────
// Находка ревью 08.08.2026: выключатель `true/false` принимал ЛЮБУЮ первую
// установку с ЛЮБОГО адреса. Забыть вернуть его обратно достаточно один раз.
// Билет ограничен со всех сторон, и каждое ограничение проверяется отдельно:
// проверка, отсекающая только один способ, — это не проверка.
$biletTest = static function (array $bilet, array $post): array {
    // Константу переопределить нельзя, поэтому проверяем ту же логику отдельным
    // процессом: он объявляет билет ДО подключения env.php.
    $koren = dirname(__DIR__);
    $code = 'define("UNVERIFIED_FIRST_INSTALL", ' . var_export($bilet, true) . ');'
        . ' require ' . var_export($koren . '/www/env.php', true) . ';'
        . ' require ' . var_export($koren . '/www/api/store.php', true) . ';'
        . ' require ' . var_export($koren . '/www/api/lib.php', true) . ';'
        . ' echo json_encode(installUnverifiedTicketCheck(' . var_export($post, true) . '), JSON_UNESCAPED_UNICODE);';
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', '-r', $code], $desc, $pipes, $koren, [
        'BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
        'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
    ]);
    if (!is_resource($proc)) return ['ok' => false, 'причина' => 'процесс не запущен'];
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $r = json_decode($out, true);
    return is_array($r) ? $r : ['ok' => false, 'причина' => 'нет ответа: ' . trim($out . ' ' . $err)];
};
$sekret = str_repeat('k7Qx', 9);            // 36 знаков
$godnyy = ['domain' => 'portal.example.ru', 'ticket' => $sekret, 'until' => date('c', time() + 3600)];
$prishlo = ['DOMAIN' => 'portal.example.ru', 'install_ticket' => $sekret];

check('верный билет принимается', ($biletTest($godnyy, $prishlo)['ok'] ?? false) === true,
    json_encode($biletTest($godnyy, $prishlo), JSON_UNESCAPED_UNICODE));
check('БЕЗ ОБЪЯВЛЕННОГО БИЛЕТА ОБХОДА НЕТ ВОВСЕ',
    ($biletTest([], $prishlo)['ok'] ?? true) === false);
check('ИСТЁКШИЙ БИЛЕТ НЕ ПРИНИМАЕТСЯ',
    ($biletTest(['domain' => 'portal.example.ru', 'ticket' => $sekret, 'until' => date('c', time() - 60)], $prishlo)['ok'] ?? true) === false);
check('билет с чужого портала не принимается',
    ($biletTest($godnyy, ['DOMAIN' => 'drugoy.example.ru', 'install_ticket' => $sekret])['ok'] ?? true) === false);
check('УСТАНОВКА С IP-АДРЕСА НЕ ПРИНИМАЕТСЯ',
    ($biletTest(['domain' => '127.0.0.1', 'ticket' => $sekret, 'until' => date('c', time() + 3600)],
        ['DOMAIN' => '127.0.0.1', 'install_ticket' => $sekret])['ok'] ?? true) === false);
check('установка с внутреннего имени не принимается',
    ($biletTest(['domain' => 'portal.local', 'ticket' => $sekret, 'until' => date('c', time() + 3600)],
        ['DOMAIN' => 'portal.local', 'install_ticket' => $sekret])['ok'] ?? true) === false);
check('короткий секрет не принимается — он подбирается перебором',
    ($biletTest(['domain' => 'portal.example.ru', 'ticket' => 'korotkiy', 'until' => date('c', time() + 3600)],
        ['DOMAIN' => 'portal.example.ru', 'install_ticket' => 'korotkiy'])['ok'] ?? true) === false);
// Секрета в теле нет — и это НЕ пропуск: сам по себе билет установку не
// принимает, он требует доказательства портала. Так пришлось сделать потому,
// что портал поля install_ticket не присылает и прислать не может: на коробке
// без своего сервера авторизации иначе не работал единственный оставшийся путь
// установки. Доказательство — рабочий токен портала за администратором; его
// спрашивает index.php, и без него установка не проходит (живой сценарий —
// tests/install_portal_test.php).
$bezSekreta = $biletTest($godnyy, ['DOMAIN' => 'portal.example.ru']);
check('БЕЗ СЕКРЕТА В ТЕЛЕ БИЛЕТ САМ ПО СЕБЕ УСТАНОВКУ НЕ ПРИНИМАЕТ',
    ($bezSekreta['доказать_токеном'] ?? false) === true,
    json_encode($bezSekreta, JSON_UNESCAPED_UNICODE));
check('с верным секретом доказательство токеном не требуется',
    ($biletTest($godnyy, $prishlo)['доказать_токеном'] ?? true) === false);
check('чужой секрет в теле установки не срабатывает',
    ($biletTest($godnyy, ['DOMAIN' => 'portal.example.ru', 'install_ticket' => str_repeat('z', 36)])['ok'] ?? true) === false);
// ОДНОРАЗОВОСТЬ: сожжённый билет не проходит даже в срок.
$otpechatok = hash('sha256', 'portal.example.ru|' . $sekret);
installUnverifiedTicketBurn($otpechatok);
check('ПРИМЕНЁННЫЙ БИЛЕТ ВТОРОЙ РАЗ НЕ ПРОХОДИТ',
    ($biletTest($godnyy, $prishlo)['ok'] ?? true) === false,
    json_encode($biletTest($godnyy, $prishlo), JSON_UNESCAPED_UNICODE));

// ── Доверенный предпросмотр из дев-портала ───────────────────────────────
// Приложение по прямой ссылке намеренно отвечает «только внутри Битрикс24».
// Единственное исключение — запрос, подписанный ключом публикации: его ставит
// панель дев-портала, за зрителя она уже поручилась. Проверяем ЖИВЫМ запросом
// обе стороны: без ключа и без подписи вход закрыт, с подписью — открыт.
$previewKey  = bin2hex(random_bytes(32));
$previewFile = sys_get_temp_dir() . '/bxapp-preview-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($previewFile, BXAPP_PRIVATE_PHP_HEADER . json_encode(['key' => $previewKey]));
@chmod($previewFile, 0600);
register_shutdown_function(static function () use ($previewFile): void { @unlink($previewFile); });

$previewSlug = basename(dirname(__DIR__));
$previewSign = static function (string $user, int $ts, string $key, string $slug): string {
    return hash_hmac('sha256', $slug . "\n" . $user . "\n" . $ts, (string)hex2bin($key));
};
$previewHeaders = static function (string $user, int $ts, string $sig): array {
    return ['headers' => [
        'X-Bxapp-Preview-User: ' . $user,
        'X-Bxapp-Preview-Ts: ' . $ts,
        'X-Bxapp-Preview-Sig: ' . $sig,
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Dest: document',
    ], 'site' => 'same-origin'];
};

// Сервер с ключом: так выглядит опубликованное приложение на дев-портале.
$srvPreview = testServer(['BXAPP_PREVIEW_KEY_FILE' => $previewFile]);
check('сервер предпросмотра поднялся', $srvPreview['ok']);
if ($srvPreview['ok']) {
    $callPreview = $srvPreview['call'];
    $now = time();

    [$code] = $callPreview('GET', '/index.php',
        $previewHeaders('dmitriy.f', $now, $previewSign('dmitriy.f', $now, $previewKey, $previewSlug)));
    check('ПОДПИСАННЫЙ ПРЕДПРОСМОТР ОТКРЫВАЕТ ПРИЛОЖЕНИЕ БЕЗ ПОРТАЛА',
        $code !== 403, "код $code");

    [$code] = $callPreview('GET', '/index.php',
        $previewHeaders('dmitriy.f', $now, str_repeat('a', 64)));
    check('чужая подпись предпросмотра не пускает', $code === 403, "код $code");

    // Подпись верная, но выписана на другого человека — заголовок перехвачен и
    // подставлен чужому имени.
    [$code] = $callPreview('GET', '/index.php',
        $previewHeaders('postoronniy', $now, $previewSign('dmitriy.f', $now, $previewKey, $previewSlug)));
    check('подпись, выписанная другому зрителю, не пускает', $code === 403, "код $code");

    $staryy = $now - 3600;
    [$code] = $callPreview('GET', '/index.php',
        $previewHeaders('dmitriy.f', $staryy, $previewSign('dmitriy.f', $staryy, $previewKey, $previewSlug)));
    check('просроченная подпись предпросмотра не пускает', $code === 403, "код $code");

    $budushchee = $now + 3600;
    [$code] = $callPreview('GET', '/index.php',
        $previewHeaders('dmitriy.f', $budushchee, $previewSign('dmitriy.f', $budushchee, $previewKey, $previewSlug)));
    check('подпись из будущего не пускает', $code === 403, "код $code");

    [$code] = $callPreview('GET', '/index.php',
        ['headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document'], 'site' => 'same-origin']);
    check('без заголовков предпросмотра прямая ссылка по-прежнему закрыта', $code === 403, "код $code");
}

// Боевая раскладка: ключа предпросмотра НЕТ — вход закрыт даже с формально
// правильными заголовками. Это главный предохранитель: механизм существует
// только там, где панель дев-портала положила ключ.
$srvNoKey = testServer(['BXAPP_PREVIEW_KEY_FILE' => $previewFile . '-otsutstvuet']);
check('сервер без ключа предпросмотра поднялся', $srvNoKey['ok']);
if ($srvNoKey['ok']) {
    $now = time();
    [$code] = ($srvNoKey['call'])('GET', '/index.php',
        $previewHeaders('dmitriy.f', $now, $previewSign('dmitriy.f', $now, $previewKey, $previewSlug)));
    check('БЕЗ КЛЮЧА НА ДИСКЕ ПРЕДПРОСМОТР НЕ РАБОТАЕТ ВОВСЕ', $code === 403, "код $code");
}

// ── zhivoy-1: каркас интерфейса не отдаётся по прямой ссылке (ревью 23.09.2026) ──
// template.html лежал в webroot: /index.php постороннему отвечал 403 «только из
// портала», а /template.html — 200 с разметкой, подписями и комментариями
// разработчика, без no-store и без frame-ancestors. .htaccess шаблон не использует
// намеренно, поэтому выпуск кладёт разметку в template.php с ранним 404 + exit, а
// index.php читает её оттуда (и из template.html — у проекта со старым деплоем).
$tplTool = $root . '/tools/release-template.php';
$tplStage = sys_get_temp_dir() . '/bxapp-tpl-' . bin2hex(random_bytes(4));
@mkdir($tplStage, 0700, true);
$tplOriginal = (string)file_get_contents($root . '/www/template.html');
file_put_contents($tplStage . '/template.html', $tplOriginal);
$tplOut = []; $tplRc = 1;
if (is_file($tplTool)) exec('php ' . escapeshellarg($tplTool) . ' ' . escapeshellarg($tplStage) . ' 2>&1', $tplOut, $tplRc);
$tplBuilt = (string)@file_get_contents($tplStage . '/template.php');
check('zhivoy-1: выпуск кладёт разметку в template.php с ранним 404 + exit и убирает template.html',
    $tplRc === 0 && !is_file($tplStage . '/template.html')
    && strpos($tplBuilt, "<?php http_response_code(404); exit; ?>\n") === 0,
    'rc=' . $tplRc . ' ' . implode(' ', $tplOut));
check('zhivoy-1: index.php получает из выпуска ту же разметку, что в исходнике, и из старого template.html тоже',
    function_exists('appTemplateHtml') && appTemplateHtml($tplStage) === $tplOriginal
    && appTemplateHtml($root . '/www') === $tplOriginal,
    function_exists('appTemplateHtml') ? 'разметка расходится с исходником' : 'нет appTemplateHtml()');
$tplDirect = null;
if (is_file($tplStage . '/template.php')) {
    $tplPhpOut = []; $tplPhpRc = 0;
    exec('php-cgi -q ' . escapeshellarg($tplStage . '/template.php') . ' 2>/dev/null', $tplPhpOut, $tplPhpRc);
    if ($tplPhpOut === [] && $tplPhpRc === 127) {
        exec('php ' . escapeshellarg($tplStage . '/template.php') . ' 2>&1', $tplPhpOut);
    }
    $tplDirect = implode("\n", $tplPhpOut);
}
check('zhivoy-1: прямое исполнение template.php не отдаёт ни строки разметки',
    $tplDirect !== null && strpos($tplDirect, 'id="app-name"') === false && strpos($tplDirect, '<title>') === false,
    mb_substr((string)$tplDirect, 0, 120));
$deploySrc = (string)file_get_contents($root . '/deploy-prod.sh');
check('zhivoy-1: деплой собирает template.php и проверяет, что каркас снаружи не виден',
    strpos($deploySrc, 'tools/release-template.php') !== false
    && preg_match("~security_probe '[^']*каркас[^']*' \"\\\$\\{BASE\\}/template\\.html\"~u", $deploySrc) === 1
    && preg_match("~security_probe '[^']*каркас[^']*' \"\\\$\\{BASE\\}/template\\.php\"~u", $deploySrc) === 1,
    'нет вызова tools/release-template.php или пробы /template.html и /template.php');
testWipe($tplStage, true);
