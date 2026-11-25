<?php
declare(strict_types=1);
/**
 * Проверки на находки стороннего ревью 03.08.2026.
 *
 * Каждая проверка здесь — это конкретная поломка, которую ревью воспроизвело на
 * стенде: подставленный токен приложения, кража пропуска соседним приложением,
 * двойное выполнение операции после падения, тихая потеря события, потерянная
 * порция очереди, заглушка журнала на каждой строке. Набор нужен, чтобы они не
 * вернулись: правку легко откатить случайно, а увидеть последствия — нет.
 */

require_once dirname(__DIR__) . '/www/api/queue.php';

suite('Находки ревью 03.08.2026 закрыты');

// ── Установка: подлинность события ONAPPINSTALL ──────────────────────────
// Домен нарочно несуществующий (.invalid): проверка подлинности ходит в портал,
// и проверки не должны зависеть от интернета.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1',
    'client_id' => 'local.test.app', 'expires_at' => time() + 3600,
]);

$srv = testServer();
if (!$srv['ok']) { check('встроенный сервер запущен', false); return; }
$call = $srv['call'];
$runWorker = static function (): array {
    $root = dirname(__DIR__);
    $env = [
        'BXAPP_DATA_ROOT'     => DATA_ROOT,
        'BXAPP_OAUTH_URL'     => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID'     => 'local.test.app',
        'BXAPP_CLIENT_SECRET' => 'test-secret',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', $root . '/www/bin/process.php'], $desc, $pipes, $root, $env);
    if (!is_resource($proc)) return [255, '', 'process.php не запущен'];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};

$evBody = http_build_query([
    'event' => 'ONAPPINSTALL',
    'auth'  => ['member_id' => 'MEMBER-1', 'application_token' => 'подставленный', 'access_token' => 'фальшивый'],
]);
[$code, $json, ] = $call('POST', '/index.php', ['body' => $evBody, 'type' => 'application/x-www-form-urlencoded']);
$tokens = storeRead(B24_TOKENS_FILE) ?? [];
check('событие установки без подтверждения портала отклоняется', $code === 403, "код $code");
check('подставленный токен приложения не сохранён',
    (string)($tokens['application_token'] ?? '') === '',
    'в состоянии: ' . (string)($tokens['application_token'] ?? '—'));

// Browser install POST официально может не содержать APPLICATION_TOKEN.
// ONAPPINSTALL вправе заполнить только пустой ключ и только при точном
// совпадении уже сохранённой OAuth-пары; остальные lifecycle-поля не меняет.
$beforeInstallBind = storeRead(B24_TOKENS_FILE) ?? [];
$boundInstall = lifecycleTokenEvent([
    'event' => 'ONAPPINSTALL',
    'auth' => [
        'member_id' => 'MEMBER-1', 'application_token' => 'ключ-из-install-event',
        'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    ],
]);
$afterInstallBind = storeRead(B24_TOKENS_FILE) ?? [];
check('ONAPPINSTALL однократно дописывает пустой ключ по точной browser OAuth-паре',
    is_array($boundInstall) && $boundInstall['ok'] === true && $boundInstall['adopted'] === true
    && ($afterInstallBind['application_token'] ?? '') === 'ключ-из-install-event');
check('one-time bind не меняет OAuth, generation и installFinish',
    ($afterInstallBind['access_token'] ?? '') === ($beforeInstallBind['access_token'] ?? '')
    && ($afterInstallBind['refresh_token'] ?? '') === ($beforeInstallBind['refresh_token'] ?? '')
    && appInstallGenerationFrom($afterInstallBind) === appInstallGenerationFrom($beforeInstallBind)
    && ($afterInstallBind['install_finish_confirmed_generation'] ?? null)
        === ($beforeInstallBind['install_finish_confirmed_generation'] ?? null));

$beforeWrongPair = storeRead(B24_TOKENS_FILE) ?? [];
$wrongPairInstall = lifecycleTokenEvent([
    'event' => 'ONAPPINSTALL',
    'auth' => [
        'member_id' => 'MEMBER-1', 'application_token' => 'другой-ключ',
        'access_token' => 'test-access', 'refresh_token' => 'чужой-refresh',
    ],
]);
check('ONAPPINSTALL не ротирует непустой ключ даже при похожей OAuth-паре',
    is_array($wrongPairInstall) && $wrongPairInstall['code'] === 403
    && (storeRead(B24_TOKENS_FILE) ?? []) === $beforeWrongPair);

// Уже сохранённый токен событие не переписывает (ротацию усваивает install-POST).
storeUpdate(B24_TOKENS_FILE, static function (array $t): array { $t['application_token'] = 'настоящий'; return $t; });
[$code, , ] = $call('POST', '/index.php', ['body' => $evBody, 'type' => 'application/x-www-form-urlencoded']);
$tokens = storeRead(B24_TOKENS_FILE) ?? [];
check('чужое событие не подменяет сохранённый токен приложения',
    $code === 403 && (string)($tokens['application_token'] ?? '') === 'настоящий', "код $code");

// ── Портал сменил ключ приложения (ONAPPUPDATE) ──────────────────────────
// Обновление приложения на портале выдаёт НОВЫЙ application_token. Раньше это
// не усваивалось ничем: событие с новым ключом сверялось со старым и получало
// 403 — приложение работало, а события портала переставали доходить совсем.
// Обращение к порталу подменяем: самопроверка в сеть не ходит (подмена работает
// только при подменённом сторе, см. b24PortalGet).
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1',
    'application_token' => 'ключ-старый', 'expires_at' => time() + 3600,
]);
storeWrite(ACL_FILE, ['owner_uid' => 0, 'users' => []]);
$lifecyclePortalCalls = 0;
$GLOBALS['__b24_portal_get'] = static function (string $domain, string $method, string $auth) use (&$lifecyclePortalCalls): ?array {
    $lifecyclePortalCalls++;
    if ($auth !== 'живой-токен-портала') return null;               // чужой access портал не подтвердит
    if ($method === 'user.current') return ['result' => ['ID' => '5', 'UF_DEPARTMENT' => [1]]];
    if ($method === 'user.admin')   return ['result' => true];
    return null;
};
$updateEvent = static fn(string $token, string $access): array => [
    'event' => 'ONAPPUPDATE',
    'auth'  => ['member_id' => 'MEMBER-1', 'application_token' => $token, 'access_token' => $access],
];
$res = lifecycleTokenEvent($updateEvent('ключ-новый', 'живой-токен-портала'));
$tokens = storeRead(B24_TOKENS_FILE) ?? [];
check('обновление приложения принимает новый ключ, а не отвечает отказом',
    is_array($res) && $res['ok'] === true && $res['adopted'] === true, json_encode($res, JSON_UNESCAPED_UNICODE));
check('новый ключ приложения сохранён', (string)($tokens['application_token'] ?? '') === 'ключ-новый',
    'в состоянии: ' . (string)($tokens['application_token'] ?? '—'));

// Событие с новым ключом теперь проходит обычную сверку приёмника.
storeUpdate(B24_TOKENS_FILE, static function (array $t): array { unset($t['eventVerifiedAt']); return $t; });
$again = lifecycleTokenEvent($updateEvent('ключ-новый', 'живой-токен-портала'));
check('повторное событие с тем же ключом ничего не меняет',
    is_array($again) && $again['ok'] === true && $again['adopted'] === false);

// Для UI-приложения browser flow уже создал поколение до installFinish.
// Запоздалый ONAPPINSTALL не имеет права создать второе и вызвать finish снова;
// async-событие не имеет generation, поэтому состояние по нему не меняется.
$beforeReinstallGeneration = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
storeUpdate(B24_TOKENS_FILE, static function (array $t): array { unset($t['eventVerifiedAt']); return $t; });
$browserInstallAuth = [
    'access_token' => 'живой-токен-портала',
    'refresh_token' => 'refresh-переустановки',
    'expires_in' => 1800,
];
storeUpdate(B24_TOKENS_FILE, static function (array $t) use ($browserInstallAuth): array {
    $t['access_token'] = $browserInstallAuth['access_token'];
    $t['refresh_token'] = $browserInstallAuth['refresh_token'];
    $t['expires_in'] = $browserInstallAuth['expires_in'];
    $t['expires_at'] = time() + $browserInstallAuth['expires_in'] - 60;
    return $t;
});
$beforeReinstallB24 = new B24('local.test.app', 'secret');
$beforeReinstallB24->claimInstallFinishForCurrentGeneration();
$beforeReinstallTokens = storeRead(B24_TOKENS_FILE) ?? [];
$reinstall = lifecycleTokenEvent([
    'event' => 'ONAPPINSTALL',
    'auth'  => ['member_id' => 'MEMBER-1', 'application_token' => 'ключ-новый',
        'access_token' => 'живой-токен-портала', 'refresh_token' => 'refresh-переустановки',
        'expires_in' => 1800, 'domain' => 'portal.invalid',
        'client_endpoint' => 'https://portal.invalid/rest/', 'scope' => 'crm,placement'],
]);
$afterReinstallTokens = storeRead(B24_TOKENS_FILE) ?? [];
$afterReinstallGeneration = appInstallGenerationFrom($afterReinstallTokens);
check('ONAPPINSTALL после browser install не создаёт второе поколение',
    is_array($reinstall) && $reinstall['ok'] === true && $reinstall['adopted'] === false
    && $beforeReinstallGeneration !== '' && $afterReinstallGeneration !== ''
    && hash_equals($beforeReinstallGeneration, $afterReinstallGeneration));
check('ONAPPINSTALL не заменяет проверенную browser OAuth-пару или application_token',
    ($afterReinstallTokens['access_token'] ?? '') === ($beforeReinstallTokens['access_token'] ?? '')
    && ($afterReinstallTokens['refresh_token'] ?? '') === ($beforeReinstallTokens['refresh_token'] ?? '')
    && ($afterReinstallTokens['expires_in'] ?? null) === ($beforeReinstallTokens['expires_in'] ?? null)
    && ($afterReinstallTokens['application_token'] ?? '') === ($beforeReinstallTokens['application_token'] ?? ''));
check('ONAPPINSTALL не подтверждает поколение вместо authoritative app.info',
    empty($afterReinstallTokens['install_finish_confirmed_generation']));

// Запоздалое событие прежней установки не вправе подтвердить только что
// созданное поколение, которое ещё даже не запросило installFinish.
storeUpdate(B24_TOKENS_FILE, static function (array $t): array {
    unset($t['install_finish_requested_generation'], $t['install_finish_requested_at'],
        $t['install_finish_confirmed_generation']);
    return $t;
});
$beforeStaleInstall = storeRead(B24_TOKENS_FILE) ?? [];
$staleInstallAuth = [
    'member_id' => 'MEMBER-1', 'application_token' => 'ключ-новый',
    'access_token' => 'живой-токен-портала',
    'refresh_token' => 'refresh-запоздалого-события',
    'expires_in' => 777, 'domain' => 'portal.invalid',
    'client_endpoint' => 'https://portal.invalid/rest/', 'scope' => 'crm,placement',
];
$staleInstallEvent = lifecycleTokenEvent([
    'event' => 'ONAPPINSTALL',
    'auth' => $staleInstallAuth,
]);
$afterStaleInstall = storeRead(B24_TOKENS_FILE) ?? [];
check('stale ONAPPINSTALL не подтверждает поколение до первого installFinish',
    is_array($staleInstallEvent) && $staleInstallEvent['ok'] === true
    && empty($afterStaleInstall['install_finish_confirmed_generation']));
check('stale ONAPPINSTALL до requested не перезаписывает OAuth-пару',
    ($afterStaleInstall['access_token'] ?? '') === ($beforeStaleInstall['access_token'] ?? '')
    && ($afterStaleInstall['refresh_token'] ?? '') === ($beforeStaleInstall['refresh_token'] ?? '')
    && ($afterStaleInstall['expires_in'] ?? null) === ($beforeStaleInstall['expires_in'] ?? null)
    && ($afterStaleInstall['application_token'] ?? '') === ($beforeStaleInstall['application_token'] ?? ''));

// Даже после claim async-событие без generation остаётся no-op: authoritative
// подтверждение текущего поколения приходит только через app.info=true.
$claimedAfterStale = (new B24('local.test.app', 'secret'))->claimInstallFinishForCurrentGeneration();
$beforeStaleAfterClaim = storeRead(B24_TOKENS_FILE) ?? [];
$staleAfterClaim = lifecycleTokenEvent(['event' => 'ONAPPINSTALL', 'auth' => $staleInstallAuth]);
$afterStaleAfterClaim = storeRead(B24_TOKENS_FILE) ?? [];
check('stale ONAPPINSTALL после requested не подтверждает чужое поколение',
    $claimedAfterStale && is_array($staleAfterClaim) && $staleAfterClaim['ok'] === true
    && $staleAfterClaim['adopted'] === false
    && empty($afterStaleAfterClaim['install_finish_confirmed_generation']));
check('stale ONAPPINSTALL после requested не перезаписывает OAuth-пару',
    ($afterStaleAfterClaim['access_token'] ?? '') === ($beforeStaleAfterClaim['access_token'] ?? '')
    && ($afterStaleAfterClaim['refresh_token'] ?? '') === ($beforeStaleAfterClaim['refresh_token'] ?? '')
    && ($afterStaleAfterClaim['expires_in'] ?? null) === ($beforeStaleAfterClaim['expires_in'] ?? null));

$journalDigest = static function (): string {
    $parts = [];
    $files = glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) $parts[] = basename($file) . ':' . (hash_file('sha256', $file) ?: '');
    return hash('sha256', implode("\n", $parts));
};
$beforeMismatchedInstall = storeRead(B24_TOKENS_FILE) ?? [];
$beforeMismatchedJournal = $journalDigest();
$mismatchedInstall = lifecycleTokenEvent([
    'event' => 'ONAPPINSTALL',
    'auth' => array_merge($staleInstallAuth, ['application_token' => 'чужой-ключ']),
]);
$afterMismatchedInstall = storeRead(B24_TOKENS_FILE) ?? [];
check('ONAPPINSTALL с чужим ключом не проходит даже как no-op',
    is_array($mismatchedInstall) && $mismatchedInstall['ok'] === false
    && $mismatchedInstall['code'] === 403 && $afterMismatchedInstall === $beforeMismatchedInstall
    && hash_equals($beforeMismatchedJournal, $journalDigest()));

// Чужой POST с выдуманным ключом портал не подтверждает — ключ остаётся прежним.
storeUpdate(B24_TOKENS_FILE, static function (array $t): array { unset($t['eventVerifiedAt']); return $t; });
$beforeBadApplicationToken = (string)((storeRead(B24_TOKENS_FILE) ?? [])['application_token'] ?? '');
$bad = lifecycleTokenEvent($updateEvent('ключ-подставленный', 'фальшивый'));
$tokens = storeRead(B24_TOKENS_FILE) ?? [];
check('неподтверждённое событие ключ не меняет',
    is_array($bad) && $bad['ok'] === false && $bad['code'] === 403
    && (string)($tokens['application_token'] ?? '') === $beforeBadApplicationToken);
$callsAfterBad = $lifecyclePortalCalls;
$rejectionProbe = new B24('local.test.app', 'secret');
$badFingerprint = hash('sha256', 'ключ-подставленный' . "\0" . 'фальшивый');
$legitFingerprint = hash('sha256', 'ключ-настоящий-после-подделки' . "\0" . 'живой-токен-портала');
check('маркер отказа относится к точной паре lifecycle-ключей, а не ко всем событиям',
    $rejectionProbe->eventVerificationRejectedRecently($badFingerprint, 10)
    && !$rejectionProbe->eventVerificationRejectedRecently($legitFingerprint, 10));
$sameBad = lifecycleTokenEvent($updateEvent('ключ-подставленный', 'фальшивый'));
check('троттл кэширует только точный повтор уже отклонённой пары ключей',
    is_array($sameBad) && $sameBad['ok'] === false && $sameBad['code'] === 403
    && $lifecyclePortalCalls === $callsAfterBad);
$legitAfterBad = lifecycleTokenEvent($updateEvent('ключ-настоящий-после-подделки', 'живой-токен-портала'));
$tokens = storeRead(B24_TOKENS_FILE) ?? [];
check('чужая попытка не закрывает окно следующему настоящему lifecycle-событию',
    is_array($legitAfterBad) && $legitAfterBad['ok'] === true && $legitAfterBad['adopted'] === true
    && (string)($tokens['application_token'] ?? '') === 'ключ-настоящий-после-подделки',
    json_encode(['result' => $legitAfterBad, 'token' => $tokens['application_token'] ?? '',
        'portal_calls' => $lifecyclePortalCalls], JSON_UNESCAPED_UNICODE));
unset($GLOBALS['__b24_portal_get']);

// ── Страница приложения не отдаётся фоновому запросу ─────────────────────
// Так соседнее приложение того же домена забирало пропуск: fetch к нашей
// странице проходил с нашей же cookie, а в ответе лежал токен.
[$code, , $body, $head] = $call('GET', '/index.php', ['headers' => ['Sec-Fetch-Mode: cors', 'Sec-Fetch-Dest: empty']]);
check('фоновый запрос к странице приложения отклоняется', $code === 403, "код $code");
check('в ответе на фоновый запрос нет пропуска', strpos($body, 'APP_SESSION') === false);
[$code, , , $head] = $call('GET', '/index.php', [
    'site' => 'cross-site',
    'headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document'],
]);
check('политика рамки не разрешает встраивание своим же доменом',
    stripos($head, 'frame-ancestors') !== false && strpos($head, "'self'") === false,
    'в заголовках: ' . trim((string)strstr($head, 'Content-Security-Policy')));

// ── Одна операция — один раз, даже после падения процесса ────────────────
$runs = 0;
$work = static function () use (&$runs): array { $runs++; return ['ok' => true]; };
// Ключ, помеченный «выполняется», но заброшенный: так выглядит состояние после
// убитого процесса. Деньги в портал могли уже уйти — повторять вслепую нельзя.
storeWrite(OPS_FILE, ['перевод-денег' => ['at' => time() - 400, 'status' => 'running']]);
$res = runOnce('перевод-денег', $work);
$ops = storeRead(OPS_FILE) ?? [];
check('оборванная операция вслепую не повторяется', $runs === 0);
check('её исход помечен как неизвестный', !empty($res['result']['unknown']) && !empty($res['repeated']));
check('в состоянии остался след неизвестного исхода',
    (string)($ops['перевод-денег']['status'] ?? '') === 'unknown',
    'статус: ' . (string)($ops['перевод-денег']['status'] ?? '—'));
// Человек проверил по данным портала, что работа НЕ прошла, и снял метку.
runOnceForget('перевод-денег');
$res2 = runOnce('перевод-денег', $work);
check('после проверки человеком операция выполняется', $runs === 1 && empty($res2['repeated']));
$res3 = runOnce('перевод-денег', $work);
check('следующий вызов той же операции работу не повторяет', $runs === 1 && !empty($res3['repeated']));

// Текст исключения не является доказательством, что работа не началась:
// внешнее действие могло пройти, а локальная ошибка возникнуть уже после него.
$externalEffects = 0;
try {
    runOnce('внешний-эффект-затем-ошибка', static function () use (&$externalEffects): array {
        $externalEffects++;
        throw new RuntimeException('обычная локальная ошибка после записи');
    });
} catch (RuntimeException $e) {
    // Ожидаемый исход первой попытки.
}
$blockedEffect = runOnce('внешний-эффект-затем-ошибка', static function () use (&$externalEffects): array {
    $externalEffects++;
    return ['ok' => true];
});
check('не-сетевая ошибка после внешнего эффекта не допускает дубль',
    $externalEffects === 1 && !empty($blockedEffect['result']['unknown']) && !empty($blockedEffect['repeated']),
    'внешних эффектов: ' . $externalEffects);

// Экран состояния обязан показать такие операции: иначе задача просто зависает,
// и об этом никто не узнаёт.
storeWrite(OPS_FILE, ['выставить-счёт' => ['at' => time() - 400, 'status' => 'unknown', 'error' => 'оборвано']]);
$healthTok = str_repeat('h', 64);
aclSetOwner(10, 'Владелец');
storeWrite(SESSIONS_FILE, [['token' => $healthTok, 'uid' => 10, 'role' => 'admin',
    'member_id' => 'MEMBER-1', 'install_generation' => (string)(appCurrentInstallGeneration() ?? ''),
    'createdAt' => time(), 'expiresAt' => time() + 3600]]);
[$hCode, $hJson, ] = $call('GET', '/api/health.php', ['token' => $healthTok]);
$healthText = (string)json_encode($hJson, JSON_UNESCAPED_UNICODE);
check('операции с неизвестным исходом видны на экране состояния',
    $hCode === 200 && strpos($healthText, 'неизвестным исходом') !== false,
    "код $hCode");

// ── События портала: ничего не теряем молча ──────────────────────────────
$acceptedGeneration = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1',
    'application_token' => 'app-token-1', 'expires_at' => time() + 3600,
    'install_generation' => $acceptedGeneration,
]);
$eventBody = http_build_query([
    'event' => 'ONCRMDEALADD',
    'auth'  => ['application_token' => 'app-token-1', 'member_id' => 'MEMBER-1'],
    'data'  => ['FIELDS' => ['ID' => 999999]],
]);
storeWrite(INBOX_FILE, []);
[$acceptedCode, , ] = $call('POST', '/api/event.php', ['body' => $eventBody,
    'type' => 'application/x-www-form-urlencoded']);
$acceptedQueue = storeRead(INBOX_FILE) ?? [];
check('принятое событие помечается поколением той же установки',
    $acceptedCode === 200 && count($acceptedQueue) === 1
    && (string)($acceptedQueue[0]['install_generation'] ?? '') === $acceptedGeneration,
    'код ' . $acceptedCode . ', поколение: ' . (string)($acceptedQueue[0]['install_generation'] ?? '—'));

// Count не ограничивает вес: один event и весь inbox имеют независимые byte caps.
$tooHeavyReason = null;
$tooHeavyQueue = queueAppendLimited([], [
    'at' => date('c'), 'event' => 'TOO_HEAVY',
    'data' => ['blob' => str_repeat('x', INBOX_EVENT_MAX_BYTES)],
    'install_generation' => $acceptedGeneration,
], $tooHeavyReason);
check('одно тяжёлое событие отклоняется до добавления в inbox',
    $tooHeavyReason === 'event_bytes' && $tooHeavyQueue === []);

$boundedQueue = [];
$boundedReason = null;
$mediumEvent = [
    'at' => date('c'), 'event' => 'MEDIUM',
    'data' => ['blob' => str_repeat('m', 48 * 1024)],
    'install_generation' => $acceptedGeneration,
];
for ($i = 0; $i < 1000; $i++) {
    $nextQueue = queueAppendLimited($boundedQueue, $mediumEvent, $boundedReason);
    if ($boundedReason !== null) break;
    $boundedQueue = $nextQueue;
}
check('общий byte cap останавливает inbox раньше OOM и не публикует лишнее событие',
    $boundedReason === 'total_bytes'
    && queueStoredBytes($boundedQueue) <= INBOX_MAX_BYTES
    && count($boundedQueue) < INBOX_MAX);
check('имя недоверенного event для журнала однострочное и ограничено',
    queueEventLogLabel("BAD\n" . str_repeat('Z', 200)) === 'BAD_' . str_repeat('Z', 60));

$full = [];
for ($i = 1; $i <= INBOX_MAX; $i++) $full[] = ['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => $i]];
storeWrite(INBOX_FILE, $full);
[$code, $json, ] = $call('POST', '/api/event.php', ['body' => $eventBody, 'type' => 'application/x-www-form-urlencoded']);
$queue = storeRead(INBOX_FILE) ?? [];
check('переполненная очередь отвечает отказом, а не «ок»', $code === 503, "код $code");
check('переполнение не выбрасывает уже принятое событие',
    count($queue) === INBOX_MAX && (int)($queue[0]['data']['ID'] ?? 0) === 1,
    'в очереди: ' . count($queue) . ', первое: ' . (int)($queue[0]['data']['ID'] ?? 0));

// ── Очередь: порция не пропадает вместе с процессом ──────────────────────
storeWrite(INBOX_FILE, [
    ['at' => date('c'), 'event' => 'A', 'data' => []],
    ['at' => date('c'), 'event' => 'B', 'data' => []],
]);
$batch = queueTake(10);
check('порция берётся в работу', count($batch) === 2);
check('взятые события остаются в очереди до подтверждения', queueSize() === 2);
check('соседний разбор ту же порцию не берёт', queueTake(10) === []);
queueAck([(string)$batch[0]['eid']], [$batch[1]]);
$left = storeRead(INBOX_FILE) ?? [];
check('подтверждённое событие уходит из очереди, возвращённое остаётся',
    count($left) === 1 && (string)($left[0]['event'] ?? '') === 'B');
check('возвращённое событие снова доступно для разбора', count(queueTake(10)) === 1);

// Повреждённая запись не является поводом «почистить» очередь молча. Прежний
// continue исключал scalar, после чего storeUpdate записывал inbox уже без него.
$malformedQueue = [
    ['at' => date('c'), 'event' => 'VALID', 'data' => [], 'eid' => 'valid-before-malformed'],
    'это не массив события',
];
storeWrite(INBOX_FILE, $malformedQueue);
$dlqBeforeMalformed = storeRead(DEADLETTER_FILE) ?? [];
$malformedRejected = false;
try { queueTake(10); }
catch (RuntimeException $e) { $malformedRejected = strpos($e->getMessage(), 'НЕ изменён') !== false; }
check('queueTake fail-closed останавливается на не-массиве', $malformedRejected);
check('повреждённый inbox не переписывается и не теряет ни одной записи',
    (storeRead(INBOX_FILE) ?? []) === $malformedQueue
    && (storeRead(DEADLETTER_FILE) ?? []) === $dlqBeforeMalformed);

// Неудачная попытка откладывается, а не повторяется в том же тике.
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'D', 'data' => ['ID' => 7], 'eid' => 'dd11']]);
$took = queueTake(10);
queueAck([], [queueDefer($took[0], 1)]);
check('после неудачи событие ждёт паузы, а не берётся снова сразу', queueTake(10) === []);
$deferred = storeRead(INBOX_FILE) ?? [];
check('пауза перед следующей попыткой проставлена',
    (int)($deferred[0]['next_at'] ?? 0) > time(), 'next_at: ' . (int)($deferred[0]['next_at'] ?? 0));

// Событие, не поддавшееся за все попытки, ложится в ящик, а не пропадает.
storeWrite(DEADLETTER_FILE, []);
queueDeadLetter(['event' => 'ONCRMDEALADD', 'data' => ['ID' => 42], 'tries' => 3, 'eid' => 'zz01'], 'обработчик упал');
check('неразобранное событие попадает в ящик, а не в мусор', queueDeadCount() === 1);
$box = storeRead(DEADLETTER_FILE) ?? [];
check('в ящике лежит исходное содержимое события',
    (int)($box[0]['data']['ID'] ?? 0) === 42 && (string)($box[0]['error'] ?? '') !== '');
storeWrite(INBOX_FILE, []);
$back = queueReplayDead();
$inbox = storeRead(INBOX_FILE) ?? [];
check('починили обработчик — события возвращаются в работу',
    $back === 1 && count($inbox) === 1 && (int)($inbox[0]['data']['ID'] ?? 0) === 42);
check('после возврата ящик пуст', queueDeadCount() === 0);

// Заполненный DLQ не имеет права вытеснять старейшее принятое событие.
$capDlq = DATA_ROOT . '/test-dead-cap.php';
$capBox = [];
for ($i = 0; $i < DEADLETTER_MAX; $i++) {
    $capBox[] = ['at' => date('c'), 'event' => 'OLD', 'data' => ['ID' => $i],
        'tries' => 3, 'error' => 'старое', 'eid' => 'cap-' . $i];
}
storeWrite($capDlq, $capBox);
$duplicateError = null;
try {
    queueDeadLetter(['event' => 'OLD', 'data' => ['ID' => 0], 'tries' => 3, 'eid' => 'cap-0'],
        'повтор', $capDlq);
} catch (Throwable $e) { $duplicateError = $e->getMessage(); }
check('повтор того же eid не раздувает заполненный DLQ',
    $duplicateError === null && count(storeRead($capDlq) ?? []) === DEADLETTER_MAX);
$overflowError = null;
try {
    queueDeadLetter(['event' => 'NEW', 'data' => ['ID' => 999], 'tries' => 3, 'eid' => 'cap-new'],
        'новое', $capDlq);
} catch (RuntimeException $e) { $overflowError = $e->getMessage(); }
$afterCap = storeRead($capDlq) ?? [];
check('заполненный DLQ отказывает без вытеснения старых событий',
    $overflowError !== null && count($afterCap) === DEADLETTER_MAX
    && (string)($afterCap[0]['eid'] ?? '') === 'cap-0');

// Replay переносит только свободную часть, сохраняет eid/generation и не
// очищает целиком DLQ, где могли появиться соседние записи.
$capInbox = DATA_ROOT . '/test-inbox-cap.php';
$replayDlq = DATA_ROOT . '/test-dead-replay.php';
$almostFull = [];
for ($i = 0; $i < INBOX_MAX - 1; $i++) {
    $almostFull[] = ['at' => date('c'), 'event' => 'Q', 'data' => [], 'eid' => 'q-' . $i];
}
storeWrite($capInbox, $almostFull);
storeWrite($replayDlq, [
    ['at' => date('c'), 'event' => 'R1', 'data' => ['ID' => 1], 'tries' => 3,
        'error' => 'x', 'eid' => 'replay-1', 'install_generation' => 'gen-A'],
    ['at' => date('c'), 'event' => 'R2', 'data' => ['ID' => 2], 'tries' => 3,
        'error' => 'y', 'eid' => 'replay-2', 'install_generation' => 'gen-A'],
]);
$moved = queueReplayDead($replayDlq, $capInbox);
$capQueue = storeRead($capInbox) ?? [];
$leftDlq = storeRead($replayDlq) ?? [];
check('replay соблюдает предел inbox и оставляет непоместившееся в DLQ',
    $moved === 1 && count($capQueue) === INBOX_MAX && count($leftDlq) === 1
    && (string)($leftDlq[0]['eid'] ?? '') === 'replay-2');
$lastReplay = $capQueue[count($capQueue) - 1] ?? [];
check('replay сохраняет eid и поколение установки',
    (string)($lastReplay['eid'] ?? '') === 'replay-1'
    && (string)($lastReplay['install_generation'] ?? '') === 'gen-A');
// Имитируем обрыв после записи в inbox, но до удаления из DLQ.
storeUpdate($replayDlq, static function (array $box): array {
    array_unshift($box, ['at' => date('c'), 'event' => 'R1', 'data' => ['ID' => 1],
        'tries' => 3, 'error' => 'x', 'eid' => 'replay-1', 'install_generation' => 'gen-A']);
    return $box;
});
$propuschenoReplay = 0;
$movedAgain = queueReplayDead($replayDlq, $capInbox, $propuschenoReplay);
check('повтор replay по тому же eid идемпотентен и не удаляет соседнее событие',
    count(storeRead($capInbox) ?? []) === INBOX_MAX
    && count(storeRead($replayDlq) ?? []) === 1
    && (string)((storeRead($replayDlq) ?? [])[0]['eid'] ?? '') === 'replay-2');
// ЧИСЛО В ОТЧЁТЕ ОПЕРАТОРУ — про работу, которая случится, а не про строки, которые
// исчезли из ящика. Событие replay-1 уже лежит во входящей очереди: из ящика его
// уберут, но разбираться заново оно не будет. Пока оба случая шли одним числом,
// «Возвращено в очередь: 1» обещало разбор, которого не произойдёт (находка ревью
// 09.08.2026). Теперь возвращённые и убранные без возврата считаются раздельно.
check('повторный replay честно говорит: возвращать было нечего',
    $movedAgain === 0 && $propuschenoReplay === 1,
    'возвращено ' . $movedAgain . ', убрано без возврата ' . $propuschenoReplay);

// Аренда протухла (процесс убили) — событие возвращается в работу само.
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'C', 'data' => [], 'eid' => 'ee11', 'taken' => time() - QUEUE_LEASE_SEC - 5]]);
$again = queueTake(10);
check('после обрыва событие снова берётся в работу, а не теряется',
    count($again) === 1 && (int)($again[0]['tries'] ?? 0) === 1);

// ── Удаление приложения из портала: стираем всё и не даём воскреснуть ────
$generationA = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'жив', 'refresh_token' => 'жив-2', 'domain' => 'portal.invalid',
    'member_id' => 'MEMBER-1', 'application_token' => 'ключ', 'expires_at' => time() + 3600,
    'install_generation' => $generationA,
]);
storeWrite(SETTINGS_FILE, ['greeting' => 'привет']);
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'X', 'data' => [],
    'install_generation' => $generationA]]);
storeWrite(OPS_FILE, ['операция' => ['at' => time(), 'status' => 'done']]);
// Запрос, начатый ДО удаления: объект уже держит рабочие токены в памяти.
$stale = new B24('local.test.app', 'secret');
require_once dirname(__DIR__) . '/www/api/journal.php';
appLog('info', 'portal.a.private', ['маркер' => 'ТОЛЬКО-ПОРТАЛ-A']);
check('до удаления приложение считается установленным', appInstalled());

$fenceReady = DATA_ROOT . '/test-lifecycle-purge-ready.php';
$purgeProc = null; $purgePipes = []; $purgeBlocked = false;
$purgeCode = 'require_once ' . var_export(dirname(__DIR__) . '/www/api/lib.php', true) . '; '
    . 'storeWrite(' . var_export($fenceReady, true) . ', ["ready" => true]); '
    . 'try { echo json_encode(appPurgeInstallState(' . var_export($generationA, true) . ')); } '
    . 'catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(2); }';
$childEnv = [
    'BXAPP_DATA_ROOT' => DATA_ROOT,
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app',
    'BXAPP_CLIENT_SECRET' => 'test-secret',
    'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
];
appWithLifecycleReadLock(static function () use (
    &$purgeProc, &$purgePipes, &$purgeBlocked, $purgeCode, $childEnv, $fenceReady
): void {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $purgeProc = proc_open(['php', '-r', $purgeCode], $desc, $purgePipes, dirname(__DIR__), $childEnv);
    if (!is_resource($purgeProc)) return;
    fclose($purgePipes[0]);
    for ($i = 0; $i < 100 && !is_file($fenceReady); $i++) usleep(20000);
    $status = proc_get_status($purgeProc);
    $purgeBlocked = is_file($fenceReady) && !empty($status['running']);
    // Это state-записи уже прошедшего apiBoot/event A. EX-уборка обязана
    // дождаться их и очистить после release, а не позволить им ожить поверх B.
    updateSettings(static fn(array $s): array => array_merge($s, ['late_A' => true]));
    aclUpdate(static function (array $acl): array {
        $acl['users'][] = ['uid' => 909, 'name' => 'late A', 'photo' => '', 'role' => 'view'];
        return $acl;
    });
});
$purgeOut = is_resource($purgeProc) ? (string)stream_get_contents($purgePipes[1]) : '';
$purgeErr = is_resource($purgeProc) ? (string)stream_get_contents($purgePipes[2]) : 'process не запущен';
if (is_resource($purgeProc)) {
    fclose($purgePipes[1]); fclose($purgePipes[2]); proc_close($purgeProc);
}
$cleared = json_decode($purgeOut, true);
if (!is_array($cleared)) $cleared = [];
check('сквозной lifecycle fence не пускает purge между apiBoot и state-записью A',
    $purgeBlocked && $purgeErr === ''
    && (storeRead(SETTINGS_FILE) ?? []) === []
    && (storeRead(ACL_FILE) ?? []) === ['owner_uid' => 0, 'users' => []], trim($purgeErr));
check('уборка задевает не три файла, а всё состояние установки', count($cleared) >= 9,
    'очищено: ' . implode(', ', $cleared));
check('после удаления приложение установленным не считается', !appInstalled());
check('настройки и очередь событий стёрты',
    (storeRead(SETTINGS_FILE) ?: []) === [] && (storeRead(INBOX_FILE) ?: []) === []);
check('журнал прежнего портала физически очищен',
    strpos((string)@file_get_contents(journalFile()), 'ТОЛЬКО-ПОРТАЛ-A') === false);

// Тот самый запрос доходит до записи — и не должен вернуть токены на место.
$staleRejected = false;
try { $stale->markEventVerified(); }               // любая запись через persist()
catch (InstallGenerationMismatchException $e) { $staleRejected = true; }
$after = storeRead(B24_TOKENS_FILE) ?? [];
check('запрос, начатый до удаления, не воскрешает токены',
    $staleRejected && empty($after['access_token']) && !empty($after['uninstalled_at']),
    'в состоянии: ' . implode(', ', array_keys($after)));

// Новая установка B не принимает ни запись старого request-объекта, ни
// запоздалый ONAPPUNINSTALL поколения A.
$installBError = null;
$installerB = new B24('local.test.app', 'secret');
try {
    $installerB->saveTokensFromInstall([
        'AUTH_ID' => 'test-access', 'REFRESH_ID' => 'test-refresh', 'AUTH_EXPIRES' => 3600,
        'DOMAIN' => 'portal.invalid', 'member_id' => 'MEMBER-1', 'PROTOCOL' => 'https',
        'APPLICATION_TOKEN' => 'key-B',
    ]);
} catch (Throwable $e) { $installBError = $e->getMessage(); }
$generationB = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
$currentB = new B24('local.test.app', 'secret');
check('новая установка поверх метки удаления проходит одним CAS',
    $installBError === null && appInstalled() && $generationB !== $generationA
    && $currentB->installGeneration() === $generationB, (string)$installBError);

$staleAfterReinstall = false;
try { $stale->markEventVerified(); }
catch (InstallGenerationMismatchException $e) { $staleAfterReinstall = true; }
$afterStaleRequest = storeRead(B24_TOKENS_FILE) ?? [];
check('старый request A не перезаписывает токены уже установленной B',
    $staleAfterReinstall && (string)($afterStaleRequest['access_token'] ?? '') === 'test-access'
    && appInstallGenerationFrom($afterStaleRequest) === $generationB);

$latePurgeRejected = false;
try { appPurgeInstallState($generationA); }
catch (InstallGenerationMismatchException $e) { $latePurgeRejected = true; }
check('запоздалое удаление A не стирает установку B', $latePurgeRejected && appInstalled());

// Та же граница обязательна у worker event: его business/state writes должны
// закончиться в A до того, как ONAPPINSTALL опубликует поколение B2.
$eventFenceReady = DATA_ROOT . '/test-event-fence-ready.php';
$eventFenceBefore = $generationB;
$eventFenceProc = null; $eventFencePipes = []; $eventFenceBlocked = false;
$reinstallCode = 'require_once ' . var_export(dirname(__DIR__) . '/www/api/b24.php', true) . '; '
    . '$b = new B24("local.test.app", "test-secret"); '
    . 'storeWrite(' . var_export($eventFenceReady, true) . ', ["ready" => true]); '
    . '$b->adoptApplicationToken("event-fence-B2", true, true);';
appWithLifecycleReadLock(static function () use (
    &$eventFenceProc, &$eventFencePipes, &$eventFenceBlocked,
    $reinstallCode, $childEnv, $eventFenceReady, $eventFenceBefore
): void {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $eventFenceProc = proc_open(['php', '-r', $reinstallCode], $desc, $eventFencePipes,
        dirname(__DIR__), $childEnv);
    if (!is_resource($eventFenceProc)) return;
    fclose($eventFencePipes[0]);
    for ($i = 0; $i < 100 && !is_file($eventFenceReady); $i++) usleep(20000);
    $status = proc_get_status($eventFenceProc);
    $eventFenceBlocked = is_file($eventFenceReady) && !empty($status['running'])
        && appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []) === $eventFenceBefore;
    storeUpdate(OPS_FILE, static function (array $ops): array {
        $ops['event-A-before-B2'] = ['status' => 'done', 'at' => time(), 'result' => ['ok' => true]];
        return $ops;
    });
});
$eventFenceErr = is_resource($eventFenceProc)
    ? (string)stream_get_contents($eventFencePipes[2]) : 'process не запущен';
if (is_resource($eventFenceProc)) {
    stream_get_contents($eventFencePipes[1]);
    fclose($eventFencePipes[1]); fclose($eventFencePipes[2]); proc_close($eventFenceProc);
}
$generationB = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
check('worker event A держит lifecycle SH до business/state writes перед reinstall B',
    $eventFenceBlocked && $eventFenceErr === '' && $generationB !== ''
    && !hash_equals($eventFenceBefore, $generationB)
    && isset((storeRead(OPS_FILE) ?? [])['event-A-before-B2']), trim($eventFenceErr));

// Portal-auth должен держать тот же SH от user.current/ACL до физической
// записи session. Раньше migration вклинивался между ними: A-user становился
// uid/isAdmin уже в сессии B и получал admin-bypass на полчаса.
$sessionRaceA = storeRead(B24_TOKENS_FILE) ?? [];
$sessionRaceA['domain'] = 'portal-session-a.invalid';
$sessionRaceA['member_id'] = 'MEMBER-SESSION-A';
$sessionRaceA['client_id'] = 'local.test.app';
storeWrite(B24_TOKENS_FILE, $sessionRaceA);
storeWrite(SESSIONS_FILE, []);
storeWrite(ACL_FILE, ['owner_uid' => 0, 'users' => []]);
$sessionRaceGenerationA = appInstallGenerationFrom($sessionRaceA);
$sessionRaceGenerationB = appNewInstallGeneration();
$sessionRaceB = $sessionRaceA;
$sessionRaceB['access_token'] = 'session-race-b-access';
$sessionRaceB['refresh_token'] = 'session-race-b-refresh';
$sessionRaceB['domain'] = 'portal-session-b.invalid';
$sessionRaceB['member_id'] = 'MEMBER-SESSION-B';
$sessionRaceB['install_generation'] = $sessionRaceGenerationB;
$sessionRaceReady = DATA_ROOT . '/test-session-race-ready.php';
$sessionRaceCode = 'require_once ' . var_export(dirname(__DIR__) . '/www/api/b24.php', true) . '; '
    . '$b = new B24("local.test.app", "test-secret"); '
    . 'storeWrite(' . var_export($sessionRaceReady, true) . ', ["ready" => true]); '
    . '$m = new ReflectionMethod(B24::class, "replaceInstallationTokens"); $m->setAccessible(true); '
    . '$m->invoke($b, ' . var_export($sessionRaceB, true) . ', true);';
$sessionRaceProc = null; $sessionRacePipes = []; $sessionMigrationBlocked = false;
$oldServer = $_SERVER; $oldPost = $_POST; $oldGet = $_GET;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_REFERER'] = 'https://portal-session-a.invalid/app/';
$_POST = [
    'DOMAIN' => 'portal-session-a.invalid', 'APP_SID' => 'session-race-app-sid',
    'AUTH_ID' => 'session-race-user-token',
];
$_GET = [];
$GLOBALS['__b24_portal_get'] = static function (string $domain, string $method, string $auth) use (
    &$sessionRaceProc, &$sessionRacePipes, &$sessionMigrationBlocked,
    $sessionRaceCode, $childEnv, $sessionRaceReady, $sessionRaceGenerationA
): ?array {
    if ($domain !== 'portal-session-a.invalid' || $auth !== 'session-race-user-token') return null;
    if ($method === 'user.current') {
        if (!is_resource($sessionRaceProc)) {
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $sessionRaceProc = proc_open(['php', '-r', $sessionRaceCode], $desc, $sessionRacePipes,
                dirname(__DIR__), $childEnv);
            if (is_resource($sessionRaceProc)) {
                fclose($sessionRacePipes[0]);
                for ($i = 0; $i < 100 && !is_file($sessionRaceReady); $i++) usleep(20000);
                $status = proc_get_status($sessionRaceProc);
                $sessionMigrationBlocked = is_file($sessionRaceReady) && !empty($status['running'])
                    && appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []) === $sessionRaceGenerationA;
            }
        }
        return ['result' => ['ID' => '812', 'UF_DEPARTMENT' => [1]]];
    }
    if ($method === 'user.admin') return ['result' => true];
    return null;
};
$raceSession = null; $raceSessionError = null;
try { $raceSession = tryCreateSessionFromB24Post(); }
catch (Throwable $e) { $raceSessionError = $e->getMessage(); }
unset($GLOBALS['__b24_portal_get']);
$_SERVER = $oldServer; $_POST = $oldPost; $_GET = $oldGet;
$sessionRaceErr = is_resource($sessionRaceProc)
    ? (string)stream_get_contents($sessionRacePipes[2]) : 'process не запущен';
if (is_resource($sessionRaceProc)) {
    stream_get_contents($sessionRacePipes[1]);
    fclose($sessionRacePipes[1]); fclose($sessionRacePipes[2]); proc_close($sessionRaceProc);
}
$generationB = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
check('portal-auth держит lifecycle SH от identity/ACL до записи session',
    $raceSessionError === null && is_array($raceSession) && (int)($raceSession['uid'] ?? 0) === 812
    && $sessionMigrationBlocked && $sessionRaceErr === ''
    && $generationB === $sessionRaceGenerationB
    && (storeRead(SESSIONS_FILE) ?? []) === [],
    trim((string)$raceSessionError . ' ' . $sessionRaceErr));

// Refresh, который взял готовый токен соседнего запроса, обязан обновить не
// только рабочие tokens, но и базовый loaded. Иначе следующий persist откатывал
// ещё более свежий токен соседа.
storeUpdate(B24_TOKENS_FILE, static function (array $t): array {
    $t['access_token'] = 'refresh-A'; $t['refresh_token'] = 'refresh-rA'; return $t;
});
$refreshing = new B24('local.test.app', 'secret');
storeUpdate(B24_TOKENS_FILE, static function (array $t): array {
    $t['access_token'] = 'refresh-B'; $t['refresh_token'] = 'refresh-rB'; return $t;
});
$refreshError = null;
try {
    $refreshMethod = new ReflectionMethod(B24::class, 'refreshAccessToken');
    $refreshMethod->setAccessible(true);
    $refreshMethod->invoke($refreshing); // берёт B с диска, в сеть не идёт
    storeUpdate(B24_TOKENS_FILE, static function (array $t): array {
        $t['access_token'] = 'refresh-C'; $t['refresh_token'] = 'refresh-rC'; return $t;
    });
    $refreshing->markEventVerified();
} catch (Throwable $e) { $refreshError = $e->getMessage(); }
$afterRefreshPersist = storeRead(B24_TOKENS_FILE) ?? [];
check('persist после соседнего refresh не откатывает ещё более свежий токен',
    $refreshError === null && (string)($afterRefreshPersist['access_token'] ?? '') === 'refresh-C'
    && !empty($afterRefreshPersist['eventVerifiedAt']), (string)$refreshError);

// Очередь могла существовать до появления generation-протокола. Такие записи
// нельзя безопасно выполнить, но ACK/drop превратил бы обновление шаблона в
// тихую потерю принятых событий — изолируем их целиком в DLQ.
storeWrite(DEADLETTER_FILE, []);
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'LEGACY_BEFORE_GENERATION', 'data' => ['ID' => 77],
    'eid' => 'legacy-before-generation',
]]);
[$legacyRc, , $legacyErr] = $runWorker();
$legacyDlq = storeRead(DEADLETTER_FILE) ?? [];
check('worker не теряет legacy-событие без поколения при обновлении шаблона',
    $legacyRc === 0 && (storeRead(INBOX_FILE) ?? []) === [] && count($legacyDlq) === 1
    && (string)($legacyDlq[0]['eid'] ?? '') === 'legacy-before-generation'
    && strpos((string)($legacyDlq[0]['error'] ?? ''), 'install_generation') !== false,
    'код ' . $legacyRc . ', stderr: ' . trim($legacyErr));

// Delayed uninstall из очереди также отсекается до обработчика и подтверждается
// как устаревший — B остаётся активной.
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'ONAPPUNINSTALL', 'data' => [], 'eid' => 'late-uninstall-A',
    'install_generation' => $generationA,
]]);
storeWrite(DEADLETTER_FILE, []);
[$lateRc, , $lateErr] = $runWorker();
$afterLateWorker = storeRead(B24_TOKENS_FILE) ?? [];
check('worker пропускает delayed ONAPPUNINSTALL поколения A после установки B',
    $lateRc === 0 && appInstallGenerationFrom($afterLateWorker) === $generationB
    && !empty($afterLateWorker['access_token']) && (storeRead(INBOX_FILE) ?? []) === [],
    'код ' . $lateRc . ', stderr: ' . trim($lateErr));

$workerSource = (string)@file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
check('worker привязывает аренду, business job и финальный cron state к одному поколению',
    strpos($workerSource, "\$GLOBALS['BXAPP_WORKER_INSTALLATION'] = \$workerInstallation") !== false
    && strpos($workerSource, 'Установка сменилась до аренды событий') !== false
    && strpos($workerSource, 'appWithMatchingInstallationReadLock($installation') !== false
    && substr_count($workerSource, 'cronRunForInstallation($workerInstallation') >= 3);

$workerFinishStart = strpos($workerSource, '$cronOk = true;');
$workerFinishEnd = strpos($workerSource, '/**', strpos($workerSource, 'exit($cronExit);'));
$workerFinish = ($workerFinishStart !== false && $workerFinishEnd !== false)
    ? substr($workerSource, $workerFinishStart, $workerFinishEnd - $workerFinishStart) : '';
$markerStart = strpos($workerSource, 'function cronMarkRun(');
$markerEnd = strpos($workerSource, 'function runJob(', $markerStart === false ? 0 : $markerStart);
$markerBody = ($markerStart !== false && $markerEnd !== false)
    ? substr($workerSource, $markerStart, $markerEnd - $markerStart) : '';
check('любая ошибка business job завершает cron ненулевым кодом',
    preg_match('~catch \(Throwable \$e\) \{\s*\$cronOk = false;\s*\$cronFailure = \$e;\s*\$cronExit = 1;~',
        $workerFinish) === 1);
check('ошибка записи cron status не проглатывается',
    $markerBody !== '' && strpos($markerBody, 'catch (Throwable') === false);
$markPos = strpos($workerFinish, 'cronMarkRun($cronOk, $ms);');
$endPos = strpos($workerFinish, "appLog('info', 'cron.end'");
check('cron.end пишется только после сохранения итогового статуса',
    $markPos !== false && $endPos !== false && $markPos < $endPos);

// Занятый flock — штатный анти-нахлёст с exit 0. Но невозможность даже открыть
// lock означает сбой хранилища, а не соседний запуск: scheduler обязан увидеть отказ.
$cronLockFile = DATA_ROOT . '/cron.lock.php';
@unlink($cronLockFile);
$cronLockBlocked = @mkdir($cronLockFile, 0700);
[$lockOpenRc, , $lockOpenErr] = $runWorker();
@rmdir($cronLockFile);
check('недоступный cron lock завершает worker ошибкой, а не ложным успехом',
    $cronLockBlocked && $lockOpenRc === 1
    && mb_stripos($lockOpenErr, 'не удалось открыть файл блокировки cron') !== false,
    'код ' . $lockOpenRc . ', stderr: ' . trim($lockOpenErr));
$cronLockHandle = @fopen($cronLockFile, 'c+b');
$cronLockHeld = is_resource($cronLockHandle) && @flock($cronLockHandle, LOCK_EX | LOCK_NB);
[$lockBusyRc, , $lockBusyErr] = $runWorker();
if (is_resource($cronLockHandle)) {
    @flock($cronLockHandle, LOCK_UN);
    fclose($cronLockHandle);
}
check('обычный cron overlap остаётся штатным тихим exit 0',
    $cronLockHeld && $lockBusyRc === 0 && trim($lockBusyErr) === '',
    'код ' . $lockBusyRc . ', stderr: ' . trim($lockBusyErr));

// Частичная уборка не подтверждает uninstall. Заполненный DLQ тоже не даёт
// удалить eid из inbox; следующий cron продолжает pending до appInstalled().
$generationPartial = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'partial-access', 'refresh_token' => 'partial-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-P',
    'application_token' => 'partial-key', 'expires_at' => time() + 3600,
    'install_generation' => $generationPartial,
]);
@unlink(SETTINGS_FILE);
@mkdir(SETTINGS_FILE, 0700); // запись settings гарантированно завершится ошибкой
storeWrite(DEADLETTER_FILE, $capBox);
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'ONAPPUNINSTALL', 'data' => [], 'eid' => 'partial-uninstall',
    'tries' => QUEUE_MAX_TRIES - 1, 'install_generation' => $generationPartial,
]]);
[$partialRc, , $partialErr] = $runWorker();
$pendingTokens = storeRead(B24_TOKENS_FILE) ?? [];
$pendingInbox = storeRead(INBOX_FILE) ?? [];
check('частичная уборка оставляет pending и не подтверждает uninstall при полном DLQ',
    $partialRc === 1 && !empty($pendingTokens['cleanup_pending'])
    && count($pendingInbox) === 1 && (string)($pendingInbox[0]['eid'] ?? '') === 'partial-uninstall',
    'код ' . $partialRc . ', stderr: ' . trim($partialErr));

@rmdir(SETTINGS_FILE);
[$resumeRc, , $resumeErr] = $runWorker();
$resumedTokens = storeRead(B24_TOKENS_FILE) ?? [];
check('следующий cron завершает pending-уборку до гейта appInstalled',
    $resumeRc === 0 && empty($resumedTokens['cleanup_pending'])
    && !empty($resumedTokens['cleanup_completed_at']) && (storeRead(INBOX_FILE) ?? []) === [],
    'код ' . $resumeRc . ', stderr: ' . trim($resumeErr));

// Успешный uninstall останавливает порцию и не создаёт state заново отметкой
// cron_last. Диагностическая строка удаления остаётся поколением C и не должна
// быть видна будущему администратору D.
$generationC = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'stop-access', 'refresh_token' => 'stop-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-C',
    'application_token' => 'stop-key', 'expires_at' => time() + 3600,
    'install_generation' => $generationC,
]);
storeWrite(STATE_FILE, ['before_uninstall' => true]);
storeWrite(SETTINGS_FILE, ['portal' => 'C']);
storeWrite(DEADLETTER_FILE, []);
storeWrite(INBOX_FILE, [
    ['at' => date('c'), 'event' => 'ONAPPUNINSTALL', 'data' => [], 'eid' => 'stop-uninstall',
        'install_generation' => $generationC],
    ['at' => date('c'), 'event' => 'СОБЫТИЕ_ПОСЛЕ_УДАЛЕНИЯ', 'data' => [], 'eid' => 'must-not-run',
        'install_generation' => $generationC],
]);
testWipe(DATA_ROOT . '/' . LOG_DIR_NAME);
[$stopRc, , $stopErr] = $runWorker();
$stopLog = (string)@file_get_contents(journalFile());
check('worker останавливается сразу после успешного uninstall',
    $stopRc === 0 && strpos($stopLog, 'СОБЫТИЕ_ПОСЛЕ_УДАЛЕНИЯ') === false
    && strpos($stopLog, 'NOOP') === false, 'код ' . $stopRc . ', stderr: ' . trim($stopErr));
check('после uninstall worker не создаёт state и очередь заново',
    (storeRead(STATE_FILE) ?? []) === [] && (storeRead(INBOX_FILE) ?? []) === [] && !appInstalled());
check('успешный worker uninstall не оставляет физический journal',
    (glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: []) === []);

// Следующая установка D: имитируем позднюю запись уже начавшегося запроса C.
// Физически она может появиться после purge, но generation-фильтр не отдаст её D.
$generationD = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1', 'expires_at' => time() + 3600,
    'install_generation' => $generationD,
]);
new B24('local.test.app', 'secret');
appBindLogGeneration($generationC);
appLog('info', 'portal.c.late', ['маркер' => 'ПОЗДНИЙ-ПОРТАЛ-C']);
appBindLogGeneration($generationD);
appLog('info', 'portal.d.current', ['маркер' => 'ТЕКУЩИЙ-ПОРТАЛ-D']);
$visibleForD = journalQuery(['day' => date('Y-m-d'), 'limit' => 100,
    'install_generation' => $generationD]);
$visibleText = (string)json_encode($visibleForD['rows'], JSON_UNESCAPED_UNICODE);
check('администратор новой установки не видит поздний журнал прошлого портала',
    strpos($visibleText, 'ТЕКУЩИЙ-ПОРТАЛ-D') !== false
    && strpos($visibleText, 'ПОЗДНИЙ-ПОРТАЛ-C') === false);

// Конструктор B24 не вправе перепривязать поколение уже начавшегося запроса.
// Иначе запрос C, зависший до установки D и создавший клиент только после неё,
// пометил бы свою позднюю строку поколением D — фильтр журнала уже не помог бы.
appBindLogGeneration($generationC);
$lateRequestClient = new B24('local.test.app', 'secret');
appLog('warn', 'portal.c.late_constructor', ['маркер' => 'ПОЗДНИЙ-B24-ПОРТАЛ-C']);
$lateCtorVisible = journalQuery(['day' => date('Y-m-d'), 'limit' => 100,
    'install_generation' => $generationD]);
$lateCtorText = (string)json_encode($lateCtorVisible['rows'], JSON_UNESCAPED_UNICODE);
check('B24 не маркирует поздний запрос старой установки новым поколением',
    strpos($lateCtorText, 'ПОЗДНИЙ-B24-ПОРТАЛ-C') === false);
unset($lateRequestClient);
appBindLogGeneration($generationD);

// Тот же member_id не означает ту же установку: после reinstall старый
// локальный пропуск C не должен открыть API поколения D.
$oldEpochSession = str_repeat('e', 64);
storeWrite(SESSIONS_FILE, [[
    'token' => $oldEpochSession, 'uid' => 901, 'role' => 'admin', 'isAdmin' => true,
    'member_id' => 'MEMBER-1', 'install_generation' => $generationC,
    'createdAt' => time(), 'adminCheckedAt' => time(), 'lastSeen' => time(),
    'expiresAt' => time() + 3600,
]]);
check('пропуск прежнего поколения не действует после переустановки на том же портале',
    findSessionByToken($oldEpochSession) === null);
$logAdminToken = str_repeat('d', 64);
storeWrite(ACL_FILE, ['owner_uid' => 901, 'users' => [
    ['uid' => 901, 'name' => 'Администратор D', 'photo' => '', 'role' => 'admin'],
]]);
storeWrite(SESSIONS_FILE, [[
    'token' => $logAdminToken, 'uid' => 901, 'role' => 'admin', 'isAdmin' => true,
    'member_id' => 'MEMBER-1', 'install_generation' => $generationD,
    'createdAt' => time(), 'adminCheckedAt' => time(),
    'lastSeen' => time(), 'expiresAt' => time() + 3600,
]]);
[$logsCode, $logsJson, ] = $call('GET', '/api/logs.php', ['token' => $logAdminToken]);
$adminVisibleText = (string)json_encode($logsJson['data']['rows'] ?? $logsJson['rows'] ?? [], JSON_UNESCAPED_UNICODE);
check('HTTP-журнал администратора D изолирован от поколения C',
    $logsCode === 200 && strpos($adminVisibleText, 'ТЕКУЩИЙ-ПОРТАЛ-D') !== false
    && strpos($adminVisibleText, 'ПОЗДНИЙ-ПОРТАЛ-C') === false, 'код ' . $logsCode);
check('новая установка после полной уборки снова активна', appInstalled());

// ── Журнал: заглушка одна, архивы не затирают друг друга ─────────────────
testWipe(DATA_ROOT . '/' . LOG_DIR_NAME);
appLog('info', 'проверка.один', ['x' => 1]);
appLog('info', 'проверка.два', ['x' => 2]);
$logText = (string)@file_get_contents(journalFile());
check('заглушка пишется один раз на файл, а не на каждую строку',
    substr_count($logText, BXAPP_PRIVATE_PHP_404_PREFIX) === 1,
    'заглушек: ' . substr_count($logText, BXAPP_PRIVATE_PHP_404_PREFIX));
check('обе записи на месте', substr_count($logText, '"event"') === 2);

// Отложенных файлов за день — не больше потолка (иначе публичный адрес
// превращается в способ занять диск).
for ($i = 0; $i < LOG_DAY_MAX_FILES + 4; $i++) {
    file_put_contents(DATA_ROOT . '/' . LOG_DIR_NAME . '/' . date('Y-m-d') . '-0000' . sprintf('%02d', $i) . '-aa.log.php', JOURNAL_FILE_PREFIX);
}
journalTrimArchives();
$archives = glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/' . date('Y-m-d') . '-*.log.php') ?: [];
check('число отложенных файлов журнала ограничено', count($archives) === LOG_DAY_MAX_FILES,
    'файлов: ' . count($archives));

// ── Права администратора портала не действуют вечно ──────────────────────
$staleAdmin = str_repeat('a', 64);
storeWrite(SESSIONS_FILE, [[
    'token' => $staleAdmin, 'uid' => 777, 'role' => 'admin', 'isAdmin' => true,
    'member_id' => 'MEMBER-1', 'install_generation' => $generationD,
    'createdAt' => time() - 7200,
    'adminCheckedAt' => time() - ADMIN_BYPASS_TTL - 60,
    'lastSeen' => time(), 'expiresAt' => time() + 3600,
]]);
storeWrite(ACL_FILE, ['owner_uid' => 0, 'users' => []]);       // в списке доступа его нет
check('устаревшая страховка администратора не пускает', findSessionByToken($staleAdmin) === null);

// Тот же случай, но человек ОСТАЛСЯ в списке доступа с обычной ролью: снятые
// права администратора не должны действовать и здесь.
$demotedAdmin = str_repeat('c', 64);
storeWrite(SESSIONS_FILE, [[
    'token' => $demotedAdmin, 'uid' => 779, 'role' => 'admin', 'isAdmin' => true,
    'member_id' => 'MEMBER-1', 'install_generation' => $generationD,
    'createdAt' => time() - 7200,
    'adminCheckedAt' => time() - ADMIN_BYPASS_TTL - 60,
    'lastSeen' => time(), 'expiresAt' => time() + 3600,
]]);
storeWrite(ACL_FILE, ['owner_uid' => 0, 'users' => [
    ['uid' => 779, 'name' => 'Бывший админ', 'photo' => '', 'role' => 'view'],
]]);
$demoted = findSessionByToken($demotedAdmin);
check('снятые права администратора не действуют, даже если человек в списке доступа',
    is_array($demoted) && empty($demoted['isAdmin']) && (string)($demoted['role'] ?? '') === 'view',
    'роль: ' . (string)($demoted['role'] ?? '—') . ', признак админа: ' . var_export($demoted['isAdmin'] ?? null, true));

$freshAdmin = str_repeat('b', 64);
storeWrite(SESSIONS_FILE, [[
    'token' => $freshAdmin, 'uid' => 778, 'role' => 'admin', 'isAdmin' => true,
    'member_id' => 'MEMBER-1', 'install_generation' => $generationD,
    'createdAt' => time(), 'adminCheckedAt' => time(),
    'lastSeen' => time(), 'expiresAt' => time() + 3600,
]]);
check('свежая страховка администратора работает', is_array(findSessionByToken($freshAdmin)));

// Сетевой migration-verify отпускает lifecycle-lock на время OAuth. Его поздний
// fail/success от D не вправе менять throttle уже опубликованной установки E.
$staleVerifyIdentity = storeRead(B24_TOKENS_FILE) ?? [];
$staleVerifier = new B24('local.test.app', 'secret');
$generationE = appNewInstallGeneration();
$tokensE = $staleVerifyIdentity;
$tokensE['access_token'] = 'portal-e-access';
$tokensE['refresh_token'] = 'portal-e-refresh';
$tokensE['client_id'] = 'local.old.app';
$tokensE['install_generation'] = $generationE;
storeWrite(B24_TOKENS_FILE, $tokensE);
$stateE = [
    'app_url' => 'https://app.example.test',
    'migrationVerifyAt' => 123456,
    'migrationVerifyFails' => 2,
];
storeWrite(STATE_FILE, $stateE);
$staleVerifyResult = $staleVerifier->verifyAndAdopt(['REFRESH_ID' => 'уже-не-наш-refresh']);
check('поздний migration fail прежнего поколения не троттлит новую установку',
    $staleVerifyResult === false && (storeRead(STATE_FILE) ?? []) === $stateE);

$throttleMethod = new ReflectionMethod(B24::class, 'updateMigrationVerifyThrottle');
$throttleMethod->setAccessible(true);
$lateSuccessAccepted = $throttleMethod->invoke(null, $staleVerifyIdentity, false);
check('поздний migration success прежнего поколения не снимает throttle новой установки',
    $lateSuccessAccepted === false && (storeRead(STATE_FILE) ?? []) === $stateE);

// Новый client_id на том же member_id — уже другая регистрация local-app.
// Fingerprint и очереди от неё недействительны, но settings/ACL/kill-switch и
// runOnce сохраняем: портал тот же, а потеря OPS разрешила бы повтор платежа.
$replaceMethod = new ReflectionMethod(B24::class, 'replaceInstallationTokens');
$replaceMethod->setAccessible(true);
$registrationClient = new B24('local.new.app', 'secret');
storeWrite(SETTINGS_FILE, ['portal' => 'E', 'mode' => 'боевой']);
storeWrite(ACL_FILE, ['owner_uid' => 778, 'users' => [[
    'uid' => 778, 'name' => 'Владелец E', 'photo' => '', 'role' => 'admin',
]]]);
storeWrite(OPS_FILE, ['same-key' => [
    'at' => time(), 'status' => 'done', 'result' => ['portal' => 'E'],
]]);
storeWrite(SWITCHES_FILE, ['live' => false, 'emergency_stop' => true]);
storeWrite(STATE_FILE, [
    'app_url' => 'https://app.example.test',
    'placements_fingerprint' => 'old-registration-fingerprint',
    'portal_cache' => ['deal' => 42],
]);
storeWrite(SESSIONS_FILE, [['token' => str_repeat('7', 64), 'uid' => 778,
    'member_id' => 'MEMBER-1', 'install_generation' => $generationE]]);
storeWrite(RATELIMIT_FILE, ['old-registration' => ['hits' => 99]]);
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'OLD_REGISTRATION', 'data' => [],
    'eid' => 'old-registration-inbox', 'install_generation' => $generationE]]);
storeWrite(DEADLETTER_FILE, [['at' => date('c'), 'event' => 'OLD_REGISTRATION', 'data' => [],
    'eid' => 'old-registration-dead', 'install_generation' => $generationE]]);
$generationRegistration = appNewInstallGeneration();
$tokensRegistration = $tokensE;
$tokensRegistration['access_token'] = 'new-registration-access';
$tokensRegistration['refresh_token'] = 'new-registration-refresh';
$tokensRegistration['client_id'] = 'local.new.app';
$tokensRegistration['install_generation'] = $generationRegistration;
$registrationError = null;
try { $replaceMethod->invoke($registrationClient, $tokensRegistration, false); }
catch (Throwable $e) { $registrationError = $e->getMessage(); }
check('новый client_id сбрасывает fingerprint/sessions/rate/queues до публикации',
    $registrationError === null
    && appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []) === $generationRegistration
    && (storeRead(STATE_FILE) ?? []) === ['app_url' => 'https://app.example.test']
    && (storeRead(SESSIONS_FILE) ?? []) === []
    && (storeRead(RATELIMIT_FILE) ?? []) === []
    && (storeRead(INBOX_FILE) ?? []) === []
    && (storeRead(DEADLETTER_FILE) ?? []) === [],
    (string)$registrationError);
check('перерегистрация того же портала сохраняет настройки/ACL/stop/runOnce',
    (storeRead(SETTINGS_FILE) ?? []) === ['portal' => 'E', 'mode' => 'боевой']
    && (int)((storeRead(ACL_FILE) ?? [])['owner_uid'] ?? 0) === 778
    && isset((storeRead(OPS_FILE) ?? [])['same-key'])
    && !empty((storeRead(SWITCHES_FILE) ?? [])['emergency_stop']));

// Прямой подтверждённый переезд D/E → Z обязан очистить ВСЁ portal-bound
// состояние ДО публикации токенов Z. Отдельно фиксируем placement fingerprint
// и runOnce: оба иначе незаметно тащили решения прежнего портала в новый.
$migrationClient = new B24('local.new.app', 'secret');
storeWrite(SETTINGS_FILE, ['portal' => 'E', 'mode' => 'боевой']);
storeWrite(SESSIONS_FILE, [[
    'token' => str_repeat('f', 64), 'uid' => 778, 'role' => 'admin',
    'member_id' => 'MEMBER-1', 'install_generation' => $generationRegistration,
]]);
storeWrite(ACL_FILE, ['owner_uid' => 778, 'users' => [[
    'uid' => 778, 'name' => 'Владелец E', 'photo' => '', 'role' => 'admin',
]]]);
storeWrite(STATE_FILE, [
    'app_url' => 'https://app.example.test',
    'placements_fingerprint' => 'portal-E-fingerprint',
    'portal_cache' => ['deal' => 42],
    'migrationVerifyAt' => time(),
    'migrationVerifyFails' => 3,
]);
storeWrite(RATELIMIT_FILE, ['portal-E' => ['count' => 99]]);
storeWrite(OPS_FILE, ['same-key' => [
    'at' => time(), 'status' => 'done', 'result' => ['portal' => 'E'],
]]);
storeWrite(SWITCHES_FILE, ['live' => true, 'owner' => 'E']);
storeWrite(DEADLETTER_FILE, [[
    'at' => date('c'), 'event' => 'OLD_E', 'data' => [], 'tries' => 3,
    'error' => 'старое', 'eid' => 'portal-e-dead', 'install_generation' => $generationRegistration,
]]);
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'OLD_E', 'data' => [], 'eid' => 'portal-e-inbox',
    'install_generation' => $generationRegistration,
]]);
appBindLogGeneration($generationRegistration);
appLog('info', 'portal.e.before_migration', ['marker' => 'PORTAL-E-PRIVATE']);

$generationZ = appNewInstallGeneration();
$tokensZ = $tokensRegistration;
$tokensZ['access_token'] = 'portal-z-access';
$tokensZ['refresh_token'] = 'portal-z-refresh';
$tokensZ['member_id'] = 'MEMBER-Z';
$tokensZ['domain'] = 'portal-z.invalid';
$tokensZ['client_endpoint'] = 'https://portal-z.invalid/rest/';
$tokensZ['application_token'] = 'portal-z-app-token';
$tokensZ['install_generation'] = $generationZ;
$migrationError = null;
try { $replaceMethod->invoke($migrationClient, $tokensZ, true); }
catch (Throwable $e) { $migrationError = $e->getMessage(); }

$afterMigrationTokens = storeRead(B24_TOKENS_FILE) ?? [];
$afterMigrationState = storeRead(STATE_FILE) ?? [];
check('прямой переезд публикует новые токены и generation только после очистки',
    $migrationError === null
    && (string)($afterMigrationTokens['access_token'] ?? '') === 'portal-z-access'
    && (string)($afterMigrationTokens['member_id'] ?? '') === 'MEMBER-Z'
    && appInstallGenerationFrom($afterMigrationTokens) === $generationZ,
    (string)$migrationError);
check('прямой переезд очищает settings/sessions/ACL/state/rate/switches/queues',
    (storeRead(SETTINGS_FILE) ?? []) === []
    && (storeRead(SESSIONS_FILE) ?? []) === []
    && (storeRead(ACL_FILE) ?? []) === ['owner_uid' => 0, 'users' => []]
    && $afterMigrationState === ['app_url' => 'https://app.example.test']
    && (storeRead(RATELIMIT_FILE) ?? []) === []
    && (storeRead(SWITCHES_FILE) ?? []) === []
    && (storeRead(INBOX_FILE) ?? []) === []
    && (storeRead(DEADLETTER_FILE) ?? []) === []);
check('прямой переезд физически удаляет journal прежнего портала',
    (glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: []) === []);

// Ошибка одного destination не вправе частично стереть прежний портал. Раньше
// reset шёл по файлам внутри mutator b24-tokens: switches.php-каталог ронял
// запись уже ПОСЛЕ очистки settings/ACL/OPS, а старые токены оставались активны.
storeWrite(SETTINGS_FILE, ['portal' => 'Z', 'keep' => true]);
storeWrite(ACL_FILE, ['owner_uid' => 991, 'users' => [[
    'uid' => 991, 'name' => 'Владелец Z', 'photo' => '', 'role' => 'admin',
]]]);
storeWrite(OPS_FILE, ['z-protected-effect' => [
    'at' => time(), 'status' => 'done', 'result' => ['portal' => 'Z'],
]]);
storeWrite(STATE_FILE, ['app_url' => 'https://app.example.test', 'portal_cache' => ['z' => 1]]);
$beforeFailedMigration = [
    'tokens' => storeRead(B24_TOKENS_FILE),
    'settings' => storeRead(SETTINGS_FILE),
    'acl' => storeRead(ACL_FILE),
    'ops' => storeRead(OPS_FILE),
    'state' => storeRead(STATE_FILE),
];
@unlink(SWITCHES_FILE);
@mkdir(SWITCHES_FILE, 0700);
$tokensFailedMigration = $tokensZ;
$tokensFailedMigration['domain'] = 'portal-failed.invalid';
$tokensFailedMigration['member_id'] = 'MEMBER-FAILED';
$tokensFailedMigration['install_generation'] = appNewInstallGeneration();
$failedMigrationError = null;
try { $replaceMethod->invoke($migrationClient, $tokensFailedMigration, true); }
catch (Throwable $e) { $failedMigrationError = $e->getMessage(); }
$afterFailedMigration = [
    'tokens' => storeRead(B24_TOKENS_FILE),
    'settings' => storeRead(SETTINGS_FILE),
    'acl' => storeRead(ACL_FILE),
    'ops' => storeRead(OPS_FILE),
    'state' => storeRead(STATE_FILE),
];
check('сбой destination откатывает межпортальный reset до публикации токенов',
    $failedMigrationError !== null && $afterFailedMigration === $beforeFailedMigration
    && !is_file(appStateTransitionManifestFile())
    && (glob(DATA_ROOT . '/*.transition-*.php') ?: []) === [],
    (string)$failedMigrationError);
@rmdir(SWITCHES_FILE);
storeWrite(SWITCHES_FILE, []);

// SIGKILL не выполняет catch/finally. Имитируем обрыв после публикации первого
// файла, но до последнего commit marker: следующий вход обязан вернуть оба old.
$transitionA = DATA_ROOT . '/transition-probe-a.php';
$transitionB = DATA_ROOT . '/transition-probe-b.php';
storeWrite($transitionA, ['value' => 'old-a']);
storeWrite($transitionB, ['value' => 'old-b']);
$transitionId = 'test-crash';
$transitionAStage = $transitionA . '.transition-' . $transitionId . '.php';
$transitionABackup = $transitionA . '.rollback-' . $transitionId . '.php';
$transitionBStage = $transitionB . '.transition-' . $transitionId . '.php';
$transitionBBackup = $transitionB . '.rollback-' . $transitionId . '.php';
appStageStatePayload($transitionAStage, storeEncode(['value' => 'new-a']));
appStageStatePayload($transitionBStage, storeEncode(['value' => 'new-b']));
storeWrite(appStateTransitionManifestFile(), [
    'tx' => $transitionId, 'phase' => 'applying', 'operations' => [
    ['path' => $transitionA, 'stage' => $transitionAStage,
        'backup' => $transitionABackup, 'had' => true],
    ['path' => $transitionB, 'stage' => $transitionBStage,
        'backup' => $transitionBBackup, 'had' => true],
]]);
@rename($transitionA, $transitionABackup);
@rename($transitionAStage, $transitionA);
$transitionRecoveryError = null;
$transitionReaderSnapshot = null;
try {
    // Процесс уже include-нул lib ДО появления manifest. Получение SH обязано
    // само перейти через EX-recovery и не впустить callback в partial state.
    $transitionReaderSnapshot = appWithLifecycleReadLock(static function () use (
        $transitionA, $transitionB
    ): array {
        return [storeRead($transitionA), storeRead($transitionB)];
    });
} catch (Throwable $e) { $transitionRecoveryError = $e->getMessage(); }
check('preloaded SH-reader восстанавливает process-crash до чтения state',
    $transitionRecoveryError === null
    && $transitionReaderSnapshot === [['value' => 'old-a'], ['value' => 'old-b']]
    && (storeRead($transitionA) ?? []) === ['value' => 'old-a']
    && (storeRead($transitionB) ?? []) === ['value' => 'old-b']
    && !is_file(appStateTransitionManifestFile()), (string)$transitionRecoveryError);

// Preloaded B24 уже прочитал поколение Z. Соседний writer затем погиб после
// rename старого journal в rollback, но до token marker. Раньше B строил deletes
// ДО recovery, видел только rollback-имя и после восстановления оставлял
// исходный журнал прежнего портала рядом с уже опубликованным новым поколением.
$preloadedTransitionClient = new B24('local.new.app', 'secret');
$preloadedBaseTokens = storeRead(B24_TOKENS_FILE) ?? [];
$preloadedLogDir = DATA_ROOT . '/' . LOG_DIR_NAME;
if (!is_dir($preloadedLogDir)) @mkdir($preloadedLogDir, 0700, true);
$preloadedOldLog = $preloadedLogDir . '/preloaded-old-portal.log.php';
storeWrite($preloadedOldLog, ['private' => 'OLD-PRELOADED-PORTAL']);
$preloadedNextTokens = $preloadedBaseTokens;
$preloadedNextTokens['access_token'] = 'preloaded-new-access';
$preloadedNextTokens['refresh_token'] = 'preloaded-new-refresh';
$preloadedNextTokens['member_id'] = 'MEMBER-PRELOADED-NEW';
$preloadedNextTokens['domain'] = 'preloaded-new.invalid';
$preloadedNextTokens['client_endpoint'] = 'https://preloaded-new.invalid/rest/';
$preloadedNextTokens['application_token'] = 'preloaded-new-app-token';
$preloadedNextTokens['install_generation'] = appNewInstallGeneration();
$preloadedTx = 'preloaded-crash';
$preloadedLogBackup = $preloadedOldLog . '.rollback-' . $preloadedTx . '.php';
$preloadedTokenStage = B24_TOKENS_FILE . '.transition-' . $preloadedTx . '.php';
$preloadedTokenBackup = B24_TOKENS_FILE . '.rollback-' . $preloadedTx . '.php';
appWithJournalMutationLock(static function () use (
    $preloadedNextTokens, $preloadedTx, $preloadedOldLog, $preloadedLogBackup,
    $preloadedTokenStage, $preloadedTokenBackup
): void {
    appStageStatePayload($preloadedTokenStage, storeEncode($preloadedNextTokens));
    storeWrite(appStateTransitionManifestFile(), [
        'tx' => $preloadedTx, 'phase' => 'applying', 'operations' => [
            ['path' => $preloadedOldLog, 'stage' => null,
                'backup' => $preloadedLogBackup, 'had' => true],
            ['path' => B24_TOKENS_FILE, 'stage' => $preloadedTokenStage,
                'backup' => $preloadedTokenBackup, 'had' => true],
        ],
    ]);
    if (!@rename($preloadedOldLog, $preloadedLogBackup)) {
        throw new RuntimeException('test: не имитировать crash journal rename');
    }
});
$crashWindowAppend = journalAppend("{\"event\":\"crash.window.old.writer\"}\n",
    appInstallGenerationFrom($preloadedBaseTokens));
$preloadedReplaceError = null;
try { $replaceMethod->invoke($preloadedTransitionClient, $preloadedNextTokens, true); }
catch (Throwable $e) { $preloadedReplaceError = $e->getMessage(); }
check('preloaded writer строит journal cleanup только после recovery под EX',
    $preloadedReplaceError === null
    && appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? [])
        === $preloadedNextTokens['install_generation']
    && empty($crashWindowAppend['written'])
    && !is_file($preloadedOldLog) && !is_file($preloadedLogBackup)
    && !is_file($preloadedTokenStage) && !is_file(appStateTransitionManifestFile()),
    (string)$preloadedReplaceError);

// Crash во время подготовки: manifest уже существует, поэтому recovery знает
// точное имя собственного stage. Похожий неизвестный файл glob-ом не удаляем.
$preparingTarget = DATA_ROOT . '/transition-preparing-target.php';
$preparingTx = 'preparing-crash';
$preparingStage = $preparingTarget . '.transition-' . $preparingTx . '.php';
$preparingBackup = $preparingTarget . '.rollback-' . $preparingTx . '.php';
$unknownTransition = DATA_ROOT . '/transition-unknown.transition-foreign.php';
storeWrite($preparingTarget, ['value' => 'old']);
appStageStatePayload($preparingStage, storeEncode(['value' => 'new']));
storeWrite($unknownTransition, ['must' => 'survive']);
storeWrite(appStateTransitionManifestFile(), [
    'tx' => $preparingTx, 'phase' => 'preparing', 'operations' => [[
        'path' => $preparingTarget, 'stage' => $preparingStage,
        'backup' => $preparingBackup, 'had' => true,
    ]],
]);
$preparingRecoveryError = null;
try { appWithLifecycleWriteLock(static function (): void { appRecoverStateTransitionLocked(); }); }
catch (Throwable $e) { $preparingRecoveryError = $e->getMessage(); }
check('preparing-recovery удаляет только перечисленный orphan stage без glob',
    $preparingRecoveryError === null && !is_file($preparingStage)
    && !is_file(appStateTransitionManifestFile())
    && (storeRead($preparingTarget) ?? []) === ['value' => 'old']
    && (storeRead($unknownTransition) ?? []) === ['must' => 'survive'],
    (string)$preparingRecoveryError);
@unlink($preparingTarget); @unlink($unknownTransition);

// Валидный store-формат не делает manifest доверенным: внешний путь должен
// попасть в quarantine ДО любого rename/unlink.
$outsideTransition = dirname(DATA_ROOT) . '/bxapp-transition-outside-'
    . getmypid() . '-' . bin2hex(random_bytes(3)) . '.php';
testRegisterTemp($outsideTransition); // файл и его .lock.php оставались в /tmp после прогона
storeWrite($outsideTransition, ['value' => 'outside-old']);
$outsideTx = 'outside-manifest';
$quarantineBefore = glob(DATA_ROOT . '/state-transition.bad-*.php') ?: [];
storeWrite(appStateTransitionManifestFile(), [
    'tx' => $outsideTx, 'phase' => 'applying', 'operations' => [[
        'path' => $outsideTransition,
        'stage' => $outsideTransition . '.transition-' . $outsideTx . '.php',
        'backup' => $outsideTransition . '.rollback-' . $outsideTx . '.php',
        'had' => true,
    ]],
]);
$outsideManifestError = null;
try { appWithLifecycleWriteLock(static function (): void { appRecoverStateTransitionLocked(); }); }
catch (Throwable $e) { $outsideManifestError = get_class($e); }
$quarantineAfter = glob(DATA_ROOT . '/state-transition.bad-*.php') ?: [];
check('manifest с путём вне DATA_ROOT карантинится до первой мутации',
    $outsideManifestError === StoreCorruptedException::class
    && (storeRead($outsideTransition) ?? []) === ['value' => 'outside-old']
    && is_file(appStateTransitionManifestFile())
    && count(array_diff($quarantineAfter, $quarantineBefore)) === 1,
    (string)$outsideManifestError);
@unlink(appStateTransitionManifestFile()); @unlink($outsideTransition);

// Вложенный symlink не должен превращать лексически внутреннюю цель в запись
// наружу даже при обычном (не recovery) appCommitStateTransition.
$symlinkOutsideDir = dirname(DATA_ROOT) . '/bxapp-transition-link-'
    . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($symlinkOutsideDir, 0700);
$symlinkInside = DATA_ROOT . '/transition-link';
$symlinkCreated = @symlink($symlinkOutsideDir, $symlinkInside);
$symlinkEscapedTarget = $symlinkOutsideDir . '/escaped.php';
$symlinkTransitionError = null;
if ($symlinkCreated) {
    try {
        appWithLifecycleWriteLock(static function () use ($symlinkInside): void {
            appCommitStateTransition([$symlinkInside . '/escaped.php' => ['value' => 'escaped']]);
        });
    } catch (Throwable $e) { $symlinkTransitionError = $e->getMessage(); }
}
check('transition отклоняет вложенный symlink и не пишет наружу',
    $symlinkCreated && $symlinkTransitionError !== null && !file_exists($symlinkEscapedTarget)
    && !is_file(appStateTransitionManifestFile()), (string)$symlinkTransitionError);
if ($symlinkCreated) @unlink($symlinkInside);
@rmdir($symlinkOutsideDir);

// Если backup уже пропал, а ранняя цель выглядит опубликованной при ещё старом
// marker, recovery не вправе молча принять смесь old/new и удалить manifest.
$partialA = DATA_ROOT . '/transition-partial-a.php';
$partialB = DATA_ROOT . '/transition-partial-b.php';
$partialTx = 'partial-inconsistent';
$partialAStage = $partialA . '.transition-' . $partialTx . '.php';
$partialABackup = $partialA . '.rollback-' . $partialTx . '.php';
$partialBStage = $partialB . '.transition-' . $partialTx . '.php';
$partialBBackup = $partialB . '.rollback-' . $partialTx . '.php';
storeWrite($partialA, ['value' => 'new-without-backup']);
storeWrite($partialB, ['value' => 'old-marker']);
appStageStatePayload($partialBStage, storeEncode(['value' => 'new-marker']));
storeWrite(appStateTransitionManifestFile(), [
    'tx' => $partialTx, 'phase' => 'applying', 'operations' => [
        ['path' => $partialA, 'stage' => $partialAStage,
            'backup' => $partialABackup, 'had' => true],
        ['path' => $partialB, 'stage' => $partialBStage,
            'backup' => $partialBBackup, 'had' => true],
    ],
]);
$partialRecoveryError = null;
try { appWithLifecycleWriteLock(static function (): void { appRecoverStateTransitionLocked(); }); }
catch (Throwable $e) { $partialRecoveryError = get_class($e); }
check('невозможное partial-состояние recovery оставляет fail-closed',
    $partialRecoveryError === InstallCleanupIncompleteException::class
    && (storeRead($partialA) ?? []) === ['value' => 'new-without-backup']
    && (storeRead($partialB) ?? []) === ['value' => 'old-marker']
    && is_file($partialBStage) && is_file(appStateTransitionManifestFile()),
    (string)$partialRecoveryError);
@unlink(appStateTransitionManifestFile()); @unlink($partialA); @unlink($partialB);
@unlink($partialAStage); @unlink($partialABackup); @unlink($partialBStage); @unlink($partialBBackup);

// Writer успел include-нуть lib и привязаться к old generation, но проиграл
// stable journal-lock cleanup-процессу. После публикации new generation он не
// вправе создать новый физический old-журнал уже ПОСЛЕ purge.
$journalOldTokens = storeRead(B24_TOKENS_FILE) ?? [];
$journalOldGeneration = appInstallGenerationFrom($journalOldTokens);
$journalRaceReady = DATA_ROOT . '/journal-cleanup-race-ready.php';
$journalLib = var_export(dirname(__DIR__) . '/www/api/journal.php', true);
$journalRaceCode = 'require_once ' . $journalLib . '; '
    . 'storeWrite(' . var_export($journalRaceReady, true) . ', ["ready" => true]); '
    . 'appLog("info", "old.portal.after.cleanup", ["private" => "OLD-RACE"]); '
    . 'echo "writer-finished";';
$journalRaceProc = null; $journalRacePipes = [];
$journalRaceBlocked = false; $journalRaceReadySeen = false;
$journalNewTokens = $journalOldTokens;
$journalNewTokens['access_token'] = 'journal-new-access';
$journalNewTokens['refresh_token'] = 'journal-new-refresh';
$journalNewTokens['member_id'] = 'MEMBER-JOURNAL-NEW';
$journalNewTokens['domain'] = 'journal-new.invalid';
$journalNewTokens['install_generation'] = appNewInstallGeneration();
appLifecycleAcquire(LOCK_EX, 25.0);
try {
    appJournalMutationLockAcquire(25.0);
    try {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $journalRaceProc = proc_open(['php', '-r', $journalRaceCode], $desc,
            $journalRacePipes, dirname(__DIR__), $childEnv);
        if (is_resource($journalRaceProc)) {
            fclose($journalRacePipes[0]);
            for ($i = 0; $i < 150 && !is_file($journalRaceReady); $i++) usleep(20000);
            $journalRaceReadySeen = is_file($journalRaceReady);
            $journalRaceStatus = proc_get_status($journalRaceProc);
            $journalRaceBlocked = $journalRaceReadySeen && !empty($journalRaceStatus['running']);
        }
        storeWrite(B24_TOKENS_FILE, $journalNewTokens);
        appPurgeJournalFiles(); // reentrant: тот же stable lock покрывает purge
    } finally {
        appJournalMutationLockRelease();
    }
} finally {
    appLifecycleRelease();
}
$journalRaceOut = is_resource($journalRaceProc)
    ? (string)stream_get_contents($journalRacePipes[1]) : '';
$journalRaceErr = is_resource($journalRaceProc)
    ? (string)stream_get_contents($journalRacePipes[2]) : 'writer process не запущен';
if (is_resource($journalRaceProc)) {
    fclose($journalRacePipes[1]); fclose($journalRacePipes[2]);
    proc_close($journalRaceProc);
}
@unlink($journalRaceReady);
appBindLogGeneration(appInstallGenerationFrom($journalNewTokens));
check('journal writer и lifecycle cleanup сериализованы без old-файла после purge',
    $journalOldGeneration !== '' && $journalRaceReadySeen && $journalRaceBlocked
    && $journalRaceErr === '' && $journalRaceOut === 'writer-finished'
    && (glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: []) === [],
    trim($journalRaceErr . ' ' . $journalRaceOut));

$portalZRuns = 0;
$portalZOnce = runOnce('same-key', static function () use (&$portalZRuns): array {
    $portalZRuns++;
    return ['portal' => 'Z'];
});
check('runOnce после переезда не переиспользует результат прежнего портала',
    $portalZRuns === 1 && empty($portalZOnce['repeated'])
    && (string)($portalZOnce['result']['portal'] ?? '') === 'Z');

// APP_PURGE_EXTRA — расширение state, а не способ обойти lifecycle-протокол.
// Проверяем в отдельных PHP-процессах: константу нельзя переопределить между
// сценариями, а каждый процесс должен увидеть её уже при загрузке env/lib.
$runPurgeExtraProbe = static function (string $scenario) use ($childEnv): array {
    $probeRoot = DATA_ROOT . '/purge-extra-' . $scenario;
    if (!is_dir($probeRoot)) mkdir($probeRoot, 0700, true);
    $env = $childEnv;
    $env['BXAPP_DATA_ROOT'] = $probeRoot;
    $lib = var_export(dirname(__DIR__) . '/www/api/lib.php', true);
    $common = '$root=(string)getenv("BXAPP_DATA_ROOT"); '
        . '$generation="probe-generation"; '
        . 'storeWrite(B24_TOKENS_FILE,["access_token"=>"token-must-live",'
        . '"refresh_token"=>"refresh-must-live","domain"=>"probe.invalid",'
        . '"member_id"=>"PROBE","install_generation"=>$generation]); ';

    if ($scenario === 'uninstall-core') {
        $prefix = '$root=(string)getenv("BXAPP_DATA_ROOT"); '
            . 'define("APP_PURGE_EXTRA",[$root."/b24-tokens.php"]); '
            . 'require_once ' . $lib . '; ';
        $action = 'try { appPurgeInstallState($generation); $error=""; } '
            . 'catch (Throwable $e) { $error=get_class($e); } '
            . '$tokens=storeRead(B24_TOKENS_FILE)??[]; '
            . 'echo json_encode(["error"=>$error,"tokens"=>$tokens]);';
    } elseif ($scenario === 'reset-core') {
        $prefix = '$root=(string)getenv("BXAPP_DATA_ROOT"); '
            . 'define("APP_PURGE_EXTRA",[$root."/inbox.php"]); '
            . 'require_once ' . $lib . '; ';
        $common .= 'storeWrite(INBOX_FILE,[["eid"=>"must-live"]]); '
            . 'storeWrite(SETTINGS_FILE,["must_live"=>true]); ';
        $action = 'try { appResetPortalBoundState(); $error=""; } '
            . 'catch (Throwable $e) { $error=get_class($e); } '
            . 'echo json_encode(["error"=>$error,"inbox"=>storeRead(INBOX_FILE),'
            . '"settings"=>storeRead(SETTINGS_FILE)]);';
    } else {
        $prefix = '$root=(string)getenv("BXAPP_DATA_ROOT"); '
            . 'define("APP_PURGE_EXTRA",[dirname($root)."/outside.php"]); '
            . 'require_once ' . $lib . '; ';
        $common .= 'storeWrite(SETTINGS_FILE,["must_live"=>true]); ';
        $action = 'try { appResetPortalBoundState(); $error=""; } '
            . 'catch (Throwable $e) { $error=get_class($e); } '
            . 'echo json_encode(["error"=>$error,"settings"=>storeRead(SETTINGS_FILE)]);';
    }

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', '-r', $prefix . $common . $action], $desc, $pipes,
        dirname(__DIR__), $env);
    if (!is_resource($proc)) return ['rc' => 255, 'json' => null, 'err' => 'process не запущен'];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($proc);
    return ['rc' => $rc, 'json' => json_decode($out, true), 'err' => trim($err)];
};

$purgeCoreProbe = $runPurgeExtraProbe('uninstall-core');
$purgeCoreJson = is_array($purgeCoreProbe['json']) ? $purgeCoreProbe['json'] : [];
check('APP_PURGE_EXTRA не может очистить tokens до финального CAS uninstall',
    $purgeCoreProbe['rc'] === 0 && $purgeCoreProbe['err'] === ''
    && ($purgeCoreJson['error'] ?? '') === InstallPurgeConfigurationException::class
    && (string)($purgeCoreJson['tokens']['access_token'] ?? '') === 'token-must-live'
    && empty($purgeCoreJson['tokens']['cleanup_pending']),
    $purgeCoreProbe['err']);

$resetCoreProbe = $runPurgeExtraProbe('reset-core');
$resetCoreJson = is_array($resetCoreProbe['json']) ? $resetCoreProbe['json'] : [];
check('межпортальный reset не принимает inbox/DLQ/core через APP_PURGE_EXTRA',
    $resetCoreProbe['rc'] === 0 && $resetCoreProbe['err'] === ''
    && ($resetCoreJson['error'] ?? '') === InstallPurgeConfigurationException::class
    && (string)($resetCoreJson['inbox'][0]['eid'] ?? '') === 'must-live'
    && !empty($resetCoreJson['settings']['must_live']),
    $resetCoreProbe['err']);

$outsideProbe = $runPurgeExtraProbe('outside');
$outsideJson = is_array($outsideProbe['json']) ? $outsideProbe['json'] : [];
check('APP_PURGE_EXTRA с путём вне DATA_ROOT отклоняется до reset-записей',
    $outsideProbe['rc'] === 0 && $outsideProbe['err'] === ''
    && ($outsideJson['error'] ?? '') === InstallPurgeConfigurationException::class
    && !empty($outsideJson['settings']['must_live']),
    $outsideProbe['err']);

// ── Готовые модули не врут об успехе ─────────────────────────────────────
try {
    httpJson('GET', 'http://127.0.0.1:' . $srv['port'] . '/index.php',
        ['allowInsecure' => true, 'allowPrivate' => true, 'allowPorts' => [$srv['port']]]);
    check('не-JSON в ответе интеграции не выдаётся за пустой результат', false, 'исключения не было');
} catch (RuntimeException $e) {
    check('не-JSON в ответе интеграции не выдаётся за пустой результат',
        strpos($e->getMessage(), 'не JSON') !== false || strpos($e->getMessage(), 'HTTP 4') !== false,
        $e->getMessage());
}
try {
    httpJson('GET', 'file:///etc/passwd');
    check('адрес не по http(s) отклоняется', false, 'исключения не было');
} catch (RuntimeException $e) {
    check('адрес не по http(s) отклоняется', true);
}

// Адрес REST приводится к безопасному виду: со своим портом, логином и «хвостом»
// он раньше сохранялся как есть — а по нему уходит токен.
check('адрес портала с чужим портом не принимается', normalizeRestEndpoint('https://portal.ru:8443/rest/') === '');
check('адрес портала с логином не принимается', normalizeRestEndpoint('https://a:b@portal.ru/rest/') === '');
check('адрес портала не по https не принимается', normalizeRestEndpoint('http://portal.ru/rest/') === '');
check('обычный адрес портала принимается', normalizeRestEndpoint('https://portal.ru/rest/') === 'https://portal.ru/rest/');

// ── Деплой: immutable release + атомарная смена live-симлинка ───────────
$deploy = (string)@file_get_contents(dirname(__DIR__) . '/deploy-prod.sh');
$envSource = (string)@file_get_contents(dirname(__DIR__) . '/www/env.php');
$schemaMatches = [];
$schemaCount = preg_match_all(
    "/^[\\t ]*define\\(\\s*'BXAPP_ENV_SCHEMA'\\s*,\\s*([1-9][0-9]*)\\s*\\);\\s*$/m",
    $envSource,
    $schemaMatches
);
// Число не зашиваем: версия контракта обязана расти при добавлении обязательной
// настройки, а проверка «ровно 3» запрещала ровно то, ради чего эта версия
// заведена — и первый же честный бамп красил гейт. Сверяем с записанным
// контрактом tests/env-contract.txt: схема не может ОТКАТИТЬСЯ назад.
$contractSchema = 0;
$contractPath = dirname(__DIR__) . '/tests/env-contract.txt';
if (is_file($contractPath)) {
    foreach (explode("\n", (string)file_get_contents($contractPath)) as $line) {
        if (strncmp(trim($line), '#', 1) === 0 && preg_match('/схему?\s*(\d+)/u', $line, $m)) {
            $contractSchema = (int)$m[1];
            break;
        }
    }
}
check('env.php объявляет одну положительную целочисленную BXAPP_ENV_SCHEMA',
    $schemaCount === 1 && (int)$schemaMatches[1][0] >= max(1, $contractSchema),
    $schemaCount === 1 ? 'схема ' . $schemaMatches[1][0] . ', в контракте ' . $contractSchema : 'вхождений: ' . $schemaCount);
$localEnvCode = 'putenv("BXAPP_DATA_ROOT"); putenv("BXAPP_CREDS_FILE"); require '
    . var_export(dirname(__DIR__) . '/www/env.php', true)
    . '; echo DATA_ROOT, "|", B24_CREDS_FILE;';
$localEnvSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$localEnvProc = proc_open(['php', '-r', $localEnvCode], $localEnvSpec, $localEnvPipes,
    dirname(__DIR__), ['PATH' => (string)getenv('PATH')]);
$localEnvOut = ''; $localEnvErr = 'php не запущен'; $localEnvRc = 255;
if (is_resource($localEnvProc)) {
    fclose($localEnvPipes[0]);
    $localEnvOut = (string)stream_get_contents($localEnvPipes[1]);
    $localEnvErr = (string)stream_get_contents($localEnvPipes[2]);
    fclose($localEnvPipes[1]); fclose($localEnvPipes[2]);
    $localEnvRc = proc_close($localEnvProc);
}
$expectedLocalData = dirname(__DIR__) . '/data';
check('локальный DATA_ROOT по умолчанию находится вне публичного www',
    $localEnvRc === 0 && $localEnvErr === ''
    && $localEnvOut === $expectedLocalData . '|' . $expectedLocalData . '/creds.php'
    && strpos($localEnvOut, dirname(__DIR__) . '/www/') !== 0,
    trim($localEnvErr . ' ' . $localEnvOut));
check('literal define в env.php использует машинно проверяемый единый стиль',
    preg_match('/define\(\s*"[A-Z0-9_]+"/', $envSource) !== 1);
$posEnvSchema = strpos($deploy, 'BXAPP_ENV_SCHEMA');
$posRemoteEnvSchema = strpos($deploy, 'REMOTE_ENV_SCHEMA');
$posCheck = strpos($deploy, 'Совместимость конфига проверяем ДО заливки');
$posUpload = strpos($deploy, 'rsync -az --delete -e "$RSYNC_SSH"');
check('несовместимый конфиг останавливает деплой до заливки',
    $posCheck !== false && $posUpload !== false && $posCheck < $posUpload);
check('deploy fail-closed сверяет версию исполняемого env до заливки',
    $posEnvSchema !== false && $posRemoteEnvSchema !== false && $posUpload !== false
    && $posEnvSchema < $posUpload && $posRemoteEnvSchema < $posUpload
    && strpos($deploy, 'LOCAL_ENV_SCHEMA') !== false
    && strpos($deploy, 'REMOTE_ENV_SCHEMA') !== false
    && substr_count($deploy, '^[1-9][0-9]*$') >= 2
    && preg_match('/REMOTE_ENV_SCHEMA[^\\n]*[!=]=?[^\\n]*LOCAL_ENV_SCHEMA|LOCAL_ENV_SCHEMA[^\\n]*[!=]=?[^\\n]*REMOTE_ENV_SCHEMA/', $deploy) === 1);
check('schema дополняет защиту от потери обязательных define, а не заменяет её',
    strpos($deploy, 'ENV_NAMES_HELPER=') !== false
    && strpos($deploy, 'remote_names=') !== false
    && strpos($deploy, 'missing_source=') !== false
    && strpos($deploy, 'missing_runtime=') !== false);
$envNamesHelper = dirname(__DIR__) . '/tools/env-define-names.php';
$envNamesHelperSource = (string)@file_get_contents($envNamesHelper);
check('deploy передаёт серверу тот же tokenizer через stdin и больше не использует php -w/grep',
    strpos($deploy, 'php "$ENV_NAMES_HELPER" "${SRC_WWW}/env.php"') !== false
    && strpos($deploy, '< "$ENV_NAMES_HELPER"') !== false
    && strpos($deploy, 'BXAPP_ENV_CONTRACT_FILE=\'${REMOTE_WWW}/env.php\' /usr/bin/php') !== false
    && strpos($deploy, 'BXAPP_ENV_CONTRACT_MODE=runtime') !== false
    && strpos($deploy, 'local_runtime_names=') !== false
    && strpos($deploy, 'remote_runtime_names=') !== false
    && strpos($deploy, 'missing_runtime=') !== false
    && strpos($deploy, 'php -w') === false
    && strpos($envNamesHelperSource, 'token_get_all') !== false);

$runEnvNamesHelper = static function (array $command, string $stdin = '', ?array $env = null): array {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($command, $desc, $pipes, dirname(__DIR__), $env);
    if (!is_resource($proc)) return [254, '', 'PHP helper не запущен'];
    if ($stdin !== '') @fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};

$validEnvFixture = DATA_ROOT . '/env-contract-valid.php';
$decoyEnvFixture = DATA_ROOT . '/env-contract-decoys.php';
$namespaceEnvFixture = DATA_ROOT . '/env-contract-namespace.php';
$aliasEnvFixture = DATA_ROOT . '/env-contract-alias.php';
$nullsafeEnvFixture = DATA_ROOT . '/env-contract-nullsafe.php';
$duplicateEnvFixture = DATA_ROOT . '/env-contract-duplicate.php';
$brokenEnvFixture = DATA_ROOT . '/env-contract-broken.php';
$runtimeEnvFixture = DATA_ROOT . '/env-contract-runtime.php';
$outputEnvFixture = DATA_ROOT . '/env-contract-output.php';
$stdoutBypassEnvFixture = DATA_ROOT . '/env-contract-stdout-bypass.php';
$stderrBypassEnvFixture = DATA_ROOT . '/env-contract-stderr-bypass.php';
$exitForgeEnvFixture = DATA_ROOT . '/env-contract-exit-forge.php';
$silentExitEnvFixture = DATA_ROOT . '/env-contract-silent-exit.php';
$sandboxEscapeEnvFixture = DATA_ROOT . '/env-contract-sandbox-escape.php';
$sandboxOutsideSentinel = DATA_ROOT . '/env-contract-outside-sentinel.php';
file_put_contents($validEnvFixture, <<<'PHP'
<?php
define('REAL_TOP', 'secret-value-must-not-leak');
(static function (): void { define('REAL_CLOSURE', 'another-secret'); })();
$text = "define('DECOY_STRING', 'string-secret')";
$heredoc = <<<'DECOY'
define('DECOY_HEREDOC', 'heredoc-secret');
DECOY;
// define('DECOY_LINE_COMMENT', 1);
/* define('DECOY_BLOCK_COMMENT', 1); */
class EnvContractDecoy { public static function define($name, $value): void {} }
$object->define('DECOY_METHOD', 1);
EnvContractDecoy::define('DECOY_STATIC', 1);
Vendor\define('DECOY_QUALIFIED', 1);
\define('DECOY_FULLY_QUALIFIED', 1);
$dynamicName = 'DECOY_DYNAMIC';
define($dynamicName, 1);
PHP
);
file_put_contents($decoyEnvFixture, <<<'PHP'
<?php
$text = "define('DECOY_STRING', 1)";
$heredoc = <<<'DECOY'
define('DECOY_HEREDOC', 1);
DECOY;
// define('DECOY_COMMENT', 1);
$object->define('DECOY_METHOD', 1);
EnvContractDecoy::define('DECOY_STATIC', 1);
Vendor\define('DECOY_QUALIFIED', 1);
$name = 'DECOY_DYNAMIC'; define($name, 1);
PHP
);
file_put_contents($namespaceEnvFixture, "<?php\nnamespace Decoy;\ndefine('DECOY_NAMESPACE', 1);\n");
file_put_contents($aliasEnvFixture, "<?php\nuse function Vendor\\fake as define;\ndefine('DECOY_ALIAS', 1);\n");
file_put_contents($nullsafeEnvFixture, "<?php\n\$object?->define('DECOY_NULLSAFE', 1);\n");
file_put_contents($duplicateEnvFixture, "<?php\ndefine('DUPLICATE', 1);\ndefine('DUPLICATE', 2);\n");
file_put_contents($brokenEnvFixture, "<?php\ndefine('BROKEN', ;\n");
file_put_contents($runtimeEnvFixture, <<<'PHP'
<?php
define('RUNTIME_TOP', 'runtime-secret');
(static function (): void { define('RUNTIME_CLOSURE', 'closure-secret'); })();
if (false) define('DEAD_BRANCH', 'dead-secret');
$neverCalled = static function (): void { define('NEVER_CALLED', 'never-secret'); };
PHP
);
file_put_contents($outputEnvFixture, "<?php\ndefine('OUTPUT_REAL', 'output-secret');\necho 'must-not-leak';\n");
file_put_contents($stdoutBypassEnvFixture,
    "<?php\ndefine('STDOUT_REAL', 1);\nfile_put_contents('php://stdout', 'direct-stdout-leak');\n");
file_put_contents($stderrBypassEnvFixture,
    "<?php\ndefine('STDERR_REAL', 1);\nfile_put_contents('php://stderr', 'direct-stderr-leak');\n");
file_put_contents($exitForgeEnvFixture, <<<'PHP'
<?php
if (false) define('FORGED_REQUIRED', 1);
define('FORGED_DUMMY', 1);
@ob_end_clean();
echo "FORGED_DUMMY\nFORGED_REQUIRED\n";
exit(0);
PHP
);
file_put_contents($silentExitEnvFixture,
    "<?php\ndefine('SILENT_EXIT_REAL', 1);\nexit(0);\n");
file_put_contents($sandboxOutsideSentinel, 'safe');
file_put_contents($sandboxEscapeEnvFixture,
    "<?php\nfile_put_contents(" . var_export($sandboxOutsideSentinel, true)
    . ", 'changed');\ndefine('ESCAPE_REAL', 1);\n");

[$envNamesRc, $envNamesOut, $envNamesErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, $validEnvFixture]
);
$expectedEnvNames = "REAL_CLOSURE\nREAL_TOP\n";
check('tokenizer принимает настоящие define, включая IIFE, и игнорирует все лексические приманки',
    $envNamesRc === 0 && $envNamesOut === $expectedEnvNames
    && strpos($envNamesOut . $envNamesErr, 'secret') === false,
    trim($envNamesErr . ' ' . $envNamesOut));

[$remoteEnvNamesRc, $remoteEnvNamesOut, $remoteEnvNamesErr] = $runEnvNamesHelper(
    [PHP_BINARY],
    $envNamesHelperSource,
    ['BXAPP_ENV_CONTRACT_FILE' => $validEnvFixture]
);
check('stdin-режим серверного tokenizer даёт тот же точный контракт',
    $remoteEnvNamesRc === 0 && $remoteEnvNamesOut === $expectedEnvNames,
    trim($remoteEnvNamesErr . ' ' . $remoteEnvNamesOut));

[$decoyEnvRc, $decoyEnvOut] = $runEnvNamesHelper([PHP_BINARY, $envNamesHelper, $decoyEnvFixture]);
[$namespaceEnvRc, $namespaceEnvOut] = $runEnvNamesHelper([PHP_BINARY, $envNamesHelper, $namespaceEnvFixture]);
[$aliasEnvRc, $aliasEnvOut] = $runEnvNamesHelper([PHP_BINARY, $envNamesHelper, $aliasEnvFixture]);
[$nullsafeEnvRc, $nullsafeEnvOut] = $runEnvNamesHelper([PHP_BINARY, $envNamesHelper, $nullsafeEnvFixture]);
check('строки, комментарии, методы, qualified-вызовы, namespace и function-alias fail-closed',
    $decoyEnvRc !== 0 && $decoyEnvOut === ''
    && $namespaceEnvRc !== 0 && $namespaceEnvOut === ''
    && $aliasEnvRc !== 0 && $aliasEnvOut === ''
    && $nullsafeEnvRc !== 0 && $nullsafeEnvOut === ''
    && strpos($envNamesHelperSource, 'T_NULLSAFE_OBJECT_OPERATOR') !== false);

[$duplicateEnvRc, $duplicateEnvOut] = $runEnvNamesHelper([PHP_BINARY, $envNamesHelper, $duplicateEnvFixture]);
[$brokenEnvRc, $brokenEnvOut] = $runEnvNamesHelper([PHP_BINARY, $envNamesHelper, $brokenEnvFixture]);
check('повторный define и повреждённый PHP не превращаются в зелёный контракт',
    $duplicateEnvRc !== 0 && $duplicateEnvOut === ''
    && $brokenEnvRc !== 0 && $brokenEnvOut === '');

[$runtimeLexicalRc, $runtimeLexicalOut] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, $runtimeEnvFixture]
);
[$runtimeActualRc, $runtimeActualOut, $runtimeActualErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $runtimeEnvFixture]
);
check('runtime-контур отличает выполненные define от мёртвой ветки и невызванной closure',
    $runtimeLexicalRc === 0
    && $runtimeLexicalOut === "DEAD_BRANCH\nNEVER_CALLED\nRUNTIME_CLOSURE\nRUNTIME_TOP\n"
    && $runtimeActualRc === 0
    && $runtimeActualOut === "RUNTIME_CLOSURE\nRUNTIME_TOP\n"
    && strpos($runtimeActualOut . $runtimeActualErr, 'secret') === false,
    trim($runtimeActualErr . ' ' . $runtimeActualOut));

[$runtimeStdinRc, $runtimeStdinOut, $runtimeStdinErr] = $runEnvNamesHelper(
    [PHP_BINARY],
    $envNamesHelperSource,
    ['BXAPP_ENV_CONTRACT_MODE' => 'runtime', 'BXAPP_ENV_CONTRACT_FILE' => $runtimeEnvFixture]
);
check('runtime-контур работает тем же stdin-транспортом на сервере',
    $runtimeStdinRc === 0 && $runtimeStdinOut === "RUNTIME_CLOSURE\nRUNTIME_TOP\n",
    trim($runtimeStdinErr . ' ' . $runtimeStdinOut));

[$outputEnvRc, $outputEnvOut, $outputEnvErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $outputEnvFixture]
);
check('runtime-контур fail-closed гасит неожиданный вывод env без утечки его содержимого',
    $outputEnvRc !== 0 && $outputEnvOut === ''
    && strpos($outputEnvErr, 'must-not-leak') === false
    && strpos($outputEnvErr, 'output-secret') === false);

[$stdoutBypassRc, $stdoutBypassOut, $stdoutBypassErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $stdoutBypassEnvFixture]
);
[$stderrBypassRc, $stderrBypassOut, $stderrBypassErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $stderrBypassEnvFixture]
);
[$exitForgeRc, $exitForgeOut, $exitForgeErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $exitForgeEnvFixture]
);
[$silentExitRc, $silentExitOut, $silentExitErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $silentExitEnvFixture]
);
check('runtime-контур гасит прямые php:// потоки, закрытие buffer, exit и подделку списка',
    $stdoutBypassRc !== 0 && $stdoutBypassOut === ''
    && $stderrBypassRc !== 0 && $stderrBypassOut === ''
    && $exitForgeRc !== 0 && $exitForgeOut === ''
    && $silentExitRc !== 0 && $silentExitOut === ''
    && strpos($stdoutBypassErr . $stderrBypassErr, 'direct-') === false
    && strpos($exitForgeErr, 'FORGED_') === false
    && strpos($silentExitErr, 'SILENT_EXIT_REAL') === false);

[$sandboxEscapeRc, $sandboxEscapeOut, $sandboxEscapeErr] = $runEnvNamesHelper(
    [PHP_BINARY, $envNamesHelper, '--runtime', $sandboxEscapeEnvFixture]
);
check('runtime-контур не даёт env писать за пределы одноразовой песочницы',
    $sandboxEscapeRc !== 0 && $sandboxEscapeOut === ''
    && (string)@file_get_contents($sandboxOutsideSentinel) === 'safe'
    && strpos($sandboxEscapeErr, $sandboxOutsideSentinel) === false);
check('runtime-контур запускает child с open_basedir, минимальным env и подписанным ответом',
    strpos($envNamesHelperSource, "'open_basedir=' . \$sandbox") !== false
    && strpos($envNamesHelperSource, "'disable_functions=' . \$disabled") !== false
    && strpos($envNamesHelperSource, "'BXAPP_RUNTIME_ENV_FILE' => \$sandbox") !== false
    && strpos($envNamesHelperSource, "hash_hmac('sha256'") !== false
    && strpos($envNamesHelperSource, 'hash_equals(') !== false
    && strpos($envNamesHelperSource, 'if (!$completed) return;') !== false
    && strpos($envNamesHelperSource, 'if ($read === []) break;') === false
    && strpos($envNamesHelperSource, '@proc_terminate($process, 9)') !== false);

foreach ([$validEnvFixture, $decoyEnvFixture, $namespaceEnvFixture, $aliasEnvFixture,
    $nullsafeEnvFixture, $duplicateEnvFixture, $brokenEnvFixture, $runtimeEnvFixture,
    $outputEnvFixture, $stdoutBypassEnvFixture, $stderrBypassEnvFixture,
    $exitForgeEnvFixture, $silentExitEnvFixture, $sandboxEscapeEnvFixture,
    $sandboxOutsideSentinel] as $envFixture) {
    @unlink($envFixture);
}
$agents = (string)@file_get_contents(dirname(__DIR__) . '/AGENTS.md');
$map = (string)@file_get_contents(dirname(__DIR__) . '/docs/MAP.md');
check('правило schema закреплено для агента и в карте',
    strpos($agents, 'BXAPP_ENV_SCHEMA') !== false
    && strpos($agents, 'ровно на 1') !== false
    && strpos($map, 'BXAPP_ENV_SCHEMA') !== false);
check('деплой проверяет, что страница жива, а не только заголовки',
    strpos($deploy, "%{http_code}") !== false);
check('security read-back использует реальные canary и падает при недоступности',
    strpos($deploy, 'SECURITY_CANARY="BXAPP-READBACK-${RELEASE}"') !== false
    && strpos($deploy, 'deploy-canary-${RELEASE}.php') !== false
    && strpos($deploy, 'security_probe()') !== false
    && strpos($deploy, 'HTTP read-back не выполнен') !== false);
$probeFunction = '';
$probeExtracted = preg_match('/^security_probe\(\) \{\R.*?^\}\R/ms', $deploy, $probeMatch) === 1;
if ($probeExtracted) $probeFunction = (string)$probeMatch[0];
$runProbeContract = static function (
    string $body,
    string $code,
    int $curlRc,
    string $contract = 'not-found'
) use ($probeFunction): int {
    if ($probeFunction === '') return 255;
    $fakeCurl = <<<'BASH'
set -uo pipefail
curl() {
  out=''
  last=''
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -o) out="$2"; shift 2 ;;
      *) last="$1"; shift ;;
    esac
  done
  [ "$last" = 'https://probe.invalid/file.php' ] || return 90
  # proc_open может не передать env-переменную с пустым значением. Для
  # безопасного пустого body это именно пустая строка, а не сбой fake-curl.
  printf '%s' "${FAKE_BODY-}" > "$out"
  printf '%s' "$FAKE_CODE"
  return "$FAKE_RC"
}
BASH;
    $script = $fakeCurl . "\n" . $probeFunction
        . "\nsecurity_probe 'test' 'https://probe.invalid/file.php' 'CANARY-MARK' \"\$PROBE_OUT\" "
        . escapeshellarg($contract) . "\n";
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['bash', '-c', $script], $desc, $pipes, dirname(__DIR__), [
        'FAKE_BODY' => $body, 'FAKE_CODE' => $code, 'FAKE_RC' => (string)$curlRc,
        'PROBE_OUT' => DATA_ROOT . '/deploy-security-probe.body', 'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
    if (!is_resource($proc)) return 254;
    fclose($pipes[0]);
    $probeStdout = (string)stream_get_contents($pipes[1]);
    $probeStderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($proc);
    $GLOBALS['__probe_contract_debug'] = trim($probeStdout . ' ' . $probeStderr);
    return $rc;
};
$safeProbeRc = $probeExtracted ? $runProbeContract('', '404', 0) : 255;
check('shell-контракт security_probe требует HTTP 404 для приватного state',
    $safeProbeRc === 0, 'rc=' . $safeProbeRc . ' ' . (string)($GLOBALS['__probe_contract_debug'] ?? ''));
check('shell-контракт security_probe отвергает пустой 200, source leak, сеть, redirect и WAF',
    $probeExtracted
    && $runProbeContract('', '200', 0) !== 0
    && $runProbeContract('CANARY-MARK', '200', 0) !== 0
    && $runProbeContract('', '000', 7) !== 0
    && $runProbeContract('', '302', 0) !== 0
    && $runProbeContract('', '429', 0) !== 0);
check('env.php допускает только действительно пустой HTTP 200',
    $probeExtracted
    && $runProbeContract('', '200', 0, 'empty-or-denied') === 0
    && $runProbeContract('unexpected body', '200', 0, 'empty-or-denied') !== 0);
$selfCheck = (string)@file_get_contents(dirname(__DIR__) . '/tools/self-check.sh');
check('гейт деплоя проверяет и фронт',
    strpos($deploy, '/tools/self-check.sh') !== false
    && strpos($selfCheck, 'node --check') !== false);

// Сборка полностью заканчивается в новом release. Live меняет только rename
// симлинка: никакого пофайлового rsync поверх работающей версии.
$posRelease = strpos($deploy, 'mkdir \'${RELEASE_DIR}\'');
$posPublish = strpos($deploy, '=== 3. Публикация атомарной сменой live-симлинка');
$posComposer = strpos($deploy, 'composer install (в новом release, до публикации)');
check('release создаётся эксклюзивно и собирается до публикации',
    $posRelease !== false && $posPublish !== false && $posRelease < $posPublish);
check('composer отрабатывает ДО публикации',
    $posComposer !== false && $posComposer < $posPublish
    && strpos($deploy, '"${SRC_WWW}/composer.json"') !== false);
check('composer build воспроизводим и не запускает код проекта до switch',
    strpos($deploy, 'composer.lock') !== false
    && strpos($deploy, '--no-scripts') !== false
    && strpos($deploy, '--no-plugins') !== false
    && strpos($deploy, 'BXAPP_RELEASE_LINKS') !== false);
$releaseLinksBlock = '';
if (preg_match("/<<'BXAPP_RELEASE_LINKS'\R(.*?)^BXAPP_RELEASE_LINKS$/ms", $deploy, $releaseLinksMatch) === 1) {
    $releaseLinksBlock = (string)$releaseLinksMatch[1];
}
check('ошибка find при проверке release-симлинков не маскируется process substitution',
    $releaseLinksBlock !== ''
    && strpos($releaseLinksBlock, 'if ! find -P "$release" -type l -print0 > "$links_file"; then') !== false
    && strpos($releaseLinksBlock, '< <(') === false);
check('live публикуется атомарным rename симлинка, не rsync поверх каталога',
    strpos($deploy, 'mv -Tf \'${LIVE_TMP}\' \'${REMOTE_WWW}\'') !== false
    && strpos($deploy, 'rsync -a --delete --exclude \'data/\' --exclude \'env.php\' \'${RELEASE_DIR}/\' \'${REMOTE_WWW}/\'') === false);
check('одновременные deploy блокируются remote-lock на весь цикл',
    strpos($deploy, 'mkdir \'${LOCK}\'') !== false && strpos($deploy, 'LOCK_HELD=1') !== false
    && strpos($deploy, 'mkdir \'${LOCK}\'') < strpos($deploy, 'LIVE_KIND='));
check('любой сбой после switch попадает в trap rollback',
    strpos($deploy, 'if [ "$PUBLISHED" = "1" ] && [ "$ACCEPTED" != "1" ]; then rollback; fi') !== false);
check('rollback тоже меняет симлинк, а не переписывает live по файлам',
    strpos($deploy, 'rb_tmp=') !== false && strpos($deploy, 'mv -Tf \'${rb_tmp}\' \'${REMOTE_WWW}\'') !== false
    && strpos($deploy, 'после rollback не все PHP-FPM workers подтвердили сброс кеша') !== false
    && strpos($deploy, 'wait_for_release_window "${PREVIOUS_TARGET##*/}"') !== false
    && strpos($deploy, 'rollback_stable_seconds=$((FPM_REALPATH_TTL + 15))') !== false);
check('однажды опубликованный release не удаляется до истечения кешей workers',
    strpos($deploy, 'PUBLICATION_ATTEMPTED=1') !== false
    && strpos($deploy, '[ "$PUBLICATION_ATTEMPTED" != "1" ]') !== false);
check('деплой сверяет, что на бою именно эта выкладка',
    strpos($deploy, 'X-App-Release') !== false && strpos($deploy, 'READBACK_CONSECUTIVE') !== false
    && strpos($deploy, 'READBACK_REQUIRED_CONSECUTIVE=24') !== false
    && strpos($deploy, 'READBACK_STABLE_SECONDS=$((FPM_REALPATH_TTL + 15))') !== false
    && strpos($deploy, 'READBACK_MAX_SECONDS=$((READBACK_STABLE_SECONDS * 2 + 60))') !== false
    && strpos($deploy, "-H 'Connection: close'") !== false);
check('symlink-deploy сбрасывает OPcache/realpath через одноразовый token-probe',
    strpos($deploy, 'FPM_RESET_TOKEN=') !== false
    && strpos($deploy, 'FPM_REALPATH_TTL=') !== false
    && strpos($deploy, 'X-Bxapp-Deploy-Token: ${FPM_RESET_TOKEN}') !== false
    && strpos($deploy, 'hash_equals($expected, $provided)') !== false
    && strpos($deploy, 'probe_anon_code=') !== false
    && strpos($deploy, '[ "$probe_anon_code" != "404" ]') !== false
    && strpos($deploy, 'opcache_reset()') !== false
    && strpos($deploy, 'fpm_reset_wave 32 40') !== false
    && strrpos($deploy, 'PROBE_CREATED=0') > strpos($deploy, 'PHP runtime использует private shared'));
check('принятый release не откатывается из-за сбоя удаления временного probe',
    strpos($deploy, 'ACCEPTED=1') < strpos($deploy, 'rm -f \'${PROBE_LINK}\'')
    && strpos($deploy, 'release принят, но одноразовый FPM-probe не удалён') !== false);
$readbackStatusFunction = '';
$readbackStatusExtracted = preg_match(
    '/^readback_status_ok\(\) \{\R.*?^\}\R/ms',
    $deploy,
    $readbackStatusMatch
) === 1;
if ($readbackStatusExtracted) $readbackStatusFunction = (string)$readbackStatusMatch[0];
$runReadbackStatusContract = static function (string $code) use ($readbackStatusFunction): int {
    if ($readbackStatusFunction === '') return 255;
    $script = "set -uo pipefail\n" . $readbackStatusFunction
        . "\nreadback_status_ok \"\$READBACK_CODE\"\n";
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['bash', '-c', $script], $desc, $pipes, dirname(__DIR__), [
        'READBACK_CODE' => $code,
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
    if (!is_resource($proc)) return 254;
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($proc);
};
check('read-back принимает штатные статусы страницы во всех lifecycle-фазах',
    $readbackStatusExtracted
    && $runReadbackStatusContract('200') === 0
    && $runReadbackStatusContract('403') === 0
    && $runReadbackStatusContract('409') === 0
    && $runReadbackStatusContract('503') === 0);
check('read-back не принимает сетевой сбой, redirect и внутреннюю ошибку',
    $readbackStatusExtracted
    && $runReadbackStatusContract('000') !== 0
    && $runReadbackStatusContract('302') !== 0
    && $runReadbackStatusContract('500') !== 0);
$readbackHeaderFunction = '';
$readbackHeaderExtracted = preg_match(
    '/^readback_headers_match\(\) \{\R.*?^\}\R/ms',
    $deploy,
    $readbackHeaderMatch
) === 1;
if ($readbackHeaderExtracted) $readbackHeaderFunction = (string)$readbackHeaderMatch[0];
$runReadbackHeaderContract = static function (string $headers, string $release) use ($readbackHeaderFunction): int {
    if ($readbackHeaderFunction === '') return 255;
    $script = "set -uo pipefail\n" . $readbackHeaderFunction
        . "\nreadback_headers_match \"\$HEADERS\" \"\$EXPECTED_RELEASE\"\n";
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['bash', '-c', $script], $desc, $pipes, dirname(__DIR__), [
        'HEADERS' => $headers,
        'EXPECTED_RELEASE' => $release,
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
    if (!is_resource($proc)) return 254;
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($proc);
};
$expectedRelease = '20260803-aaaa';
check('read-back принимает только точную release-метку и директиву Cache-Control: no-store',
    $readbackHeaderExtracted
    && $runReadbackHeaderContract(
        "HTTP/2 200\r\nX-App-Release: {$expectedRelease}\r\nCache-Control: private, no-store, no-cache\r\n",
        $expectedRelease
    ) === 0);
check('read-back отклоняет prefix/дубли release и no-store в чужом заголовке или слове',
    $readbackHeaderExtracted
    && $runReadbackHeaderContract(
        "HTTP/1.1 200 OK\r\nX-App-Release: {$expectedRelease}-old\r\nCache-Control: no-store\r\n",
        $expectedRelease
    ) !== 0
    && $runReadbackHeaderContract(
        "HTTP/1.1 200 OK\r\nX-App-Release: {$expectedRelease}\r\nX-App-Release: {$expectedRelease}\r\n"
        . "Cache-Control: no-store\r\n",
        $expectedRelease
    ) !== 0
    && $runReadbackHeaderContract(
        "HTTP/1.1 200 OK\r\nX-App-Release: {$expectedRelease}\r\nX-Debug: no-store\r\n",
        $expectedRelease
    ) !== 0
    && $runReadbackHeaderContract(
        "HTTP/1.1 200 OK\r\nX-App-Release: {$expectedRelease}\r\nCache-Control: no-storeish\r\n",
        $expectedRelease
    ) !== 0);
check('read-back не склеивает обязательные заголовки interim и финального HTTP-ответов',
    $readbackHeaderExtracted
    && $runReadbackHeaderContract(
        "HTTP/1.1 103 Early Hints\r\nX-App-Release: {$expectedRelease}\r\n\r\n"
        . "HTTP/1.1 200 OK\r\nCache-Control: no-store\r\n",
        $expectedRelease
    ) !== 0
    && $runReadbackHeaderContract(
        "HTTP/1.1 103 Early Hints\r\nCache-Control: no-store\r\n\r\n"
        . "HTTP/1.1 200 OK\r\nX-App-Release: {$expectedRelease}\r\n",
        $expectedRelease
    ) !== 0);
check('без обязательных инструментов деплой останавливается, а не пропускает проверки',
    strpos($deploy, 'нет обязательных инструментов') !== false
    && strpos($deploy, 'curl не найден — проверь вручную') === false);
check('метка release создаётся во временной сборке, не меняя исходный www',
    strpos($deploy, '"${LOCAL_STAGE}/release.php"') !== false
    && strpos($deploy, '> "${SRC_WWW}/release.php"') === false
    && strpos($deploy, 'clearstatcache(true, $cachePath)') !== false
    && strpos($deploy, '$_SERVER["DOCUMENT_ROOT"]') !== false);
check('rsync исключает только корневой runtime, не одноимённые модули глубже',
    strpos($deploy, "--exclude '/data/'") !== false
    && strpos($deploy, "--exclude '/env.php'") !== false
    && strpos($deploy, "--exclude '/release.php'") !== false);
check('VCS- и OS-мусор исключается на любой глубине webroot',
    strpos($deploy, "--exclude '.git'") !== false
    && strpos($deploy, "--exclude '.DS_Store'") !== false
    && strpos($deploy, "--exclude '.git/'") === false
    && strpos($deploy, "--exclude '/.git/'") === false);
check('локальная и legacy-проверки дерева падают при ошибке find',
    strpos($deploy, 'if ! local_link="$(find -P "$SRC_WWW" -type l -print -quit)"; then') !== false
    && strpos($deploy, 'first_entry=\\$(find') !== false
    && strpos($deploy, "find '\${SHARED}/data' -mindepth 1 -maxdepth 1 | grep -q") === false);
check('state один для всех releases и не опубликован через data-symlink',
    strpos($deploy, 'define("BXAPP_RELEASE_DATA_ROOT", dirname(__DIR__, 2) . "/shared/data")') !== false
    && strpos($deploy, 'ln -s \'../../shared/data\' \'${RELEASE_DIR}/data\'') === false);
$privateWriterSources = [
    'store' => (string)@file_get_contents(dirname(__DIR__) . '/www/api/store.php'),
    'journal' => (string)@file_get_contents(dirname(__DIR__) . '/www/api/journal.php'),
    'lib' => (string)@file_get_contents(dirname(__DIR__) . '/www/api/lib.php'),
    'worker' => (string)@file_get_contents(dirname(__DIR__) . '/www/bin/process.php'),
];
check('все штатные private-writers используют единый deploy-selected header с 404 по умолчанию',
    !in_array('', $privateWriterSources, true)
    && strpos($privateWriterSources['store'], 'BXAPP_PRIVATE_PHP_404_HEADER') !== false
    && strpos($privateWriterSources['store'], 'BXAPP_PRIVATE_PHP_LEGACY_HEADER') !== false
    && strpos($privateWriterSources['store'], "defined('BXAPP_RELEASE_WRITE_LEGACY')") !== false
    && strpos($privateWriterSources['store'], 'storeWriteAll($fh, BXAPP_PRIVATE_PHP_HEADER)') !== false
    && strpos($privateWriterSources['journal'], 'const JOURNAL_FILE_PREFIX = BXAPP_PRIVATE_PHP_HEADER;') !== false
    // lib.php пишет защитный префикс либо сам, либо общим помощником стора —
    // помощник вдобавок проверяет результат записи и выставленные права, поэтому
    // засчитывается наравне с прямой константой.
    && (substr_count($privateWriterSources['lib'], 'BXAPP_PRIVATE_PHP_HEADER')
        + substr_count($privateWriterSources['lib'], 'storeWritePrivateHeader(')) >= 2
    && strpos($privateWriterSources['store'], 'function storeWritePrivateHeader(') !== false
    && strpos($privateWriterSources['worker'], 'const CRON_FILE_PREFIX = BXAPP_PRIVATE_PHP_HEADER;') !== false);
$legacyWriterProbeCode = "define('BXAPP_RELEASE_WRITE_LEGACY', true); require "
    . var_export(dirname(__DIR__) . '/www/api/store.php', true)
    . "; echo privatePhpHasLegacyPrefix(storeEncode(['bridge' => true])) ? 'legacy' : 'wrong';";
[$legacyWriterProbeRc, $legacyWriterProbeOut, $legacyWriterProbeErr] = $runEnvNamesHelper(
    [PHP_BINARY, '-r', $legacyWriterProbeCode]
);
check('переходный release действительно пишет legacy для читаемого rollback',
    $legacyWriterProbeRc === 0 && $legacyWriterProbeOut === 'legacy',
    trim($legacyWriterProbeErr . ' ' . $legacyWriterProbeOut));
check('deploy включает переходный writer только пока previous не доказал dual-reader',
    strpos($deploy, 'BXAPP_PREVIOUS_STORE=') !== false
    && strpos($deploy, "function_exists('storeDecode')") !== false
    && strpos($deploy, 'storeDecode("<?php http_response_code(404); exit; ?>') !== false
    && strpos($deploy, 'RELEASE_WRITE_LEGACY=1') !== false
    && strpos($deploy, 'define("BXAPP_RELEASE_WRITE_LEGACY", %s);') !== false);
$releaseLayoutRoot = DATA_ROOT . '/release-layout-' . bin2hex(random_bytes(4));
$releaseId = '20260804-000000-abcd';
$releaseDir = $releaseLayoutRoot . '/.bxapp/probe/releases/' . $releaseId;
$sharedDataDir = $releaseLayoutRoot . '/.bxapp/probe/shared/data';
@mkdir($releaseDir . '/api', 0700, true);
@mkdir($releaseDir . '/bin', 0700, true);
@mkdir($sharedDataDir, 0700, true);
@copy(dirname(__DIR__) . '/www/env.php', $releaseDir . '/env.php');
@copy(dirname(__DIR__) . '/www/api/store.php', $releaseDir . '/api/store.php');
@copy(dirname(__DIR__) . '/www/api/lib.php', $releaseDir . '/api/lib.php');
@copy(dirname(__DIR__) . '/www/api/journal.php', $releaseDir . '/api/journal.php');
@copy(dirname(__DIR__) . '/www/bin/deploy-private-store.php', $releaseDir . '/bin/deploy-private-store.php');
@file_put_contents($releaseDir . '/release.php', "<?php\n"
    . "define('APP_RELEASE', " . var_export($releaseId, true) . ");\n"
    . "define('BXAPP_RELEASE_DATA_ROOT', dirname(__DIR__, 2) . '/shared/data');\n");
$releaseEnvProbe = 'require ' . var_export($releaseDir . '/env.php', true)
    . '; echo DATA_ROOT, "|", B24_CREDS_FILE;';
$releaseProbeDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$releaseProbeProc = proc_open([PHP_BINARY, '-r', $releaseEnvProbe], $releaseProbeDesc,
    $releaseProbePipes, dirname(__DIR__), [
        'BXAPP_DATA_ROOT' => $releaseDir . '/public-override',
        'BXAPP_CREDS_FILE' => $releaseDir . '/public-creds.php',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
$releaseProbeOut = '';
$releaseProbeErr = 'process не запущен';
$releaseProbeRc = 255;
if (is_resource($releaseProbeProc)) {
    fclose($releaseProbePipes[0]);
    $releaseProbeOut = (string)stream_get_contents($releaseProbePipes[1]);
    $releaseProbeErr = (string)stream_get_contents($releaseProbePipes[2]);
    fclose($releaseProbePipes[1]);
    fclose($releaseProbePipes[2]);
    $releaseProbeRc = proc_close($releaseProbeProc);
}
check('release.php направляет state и creds в private shared, а не в webroot',
    $releaseProbeRc === 0
    && $releaseProbeOut === $sharedDataDir . '|' . $sharedDataDir . '/creds.php',
    trim($releaseProbeOut . ' ' . $releaseProbeErr));
$storeProbeProc = proc_open([
    PHP_BINARY, $releaseDir . '/bin/deploy-private-store.php', $sharedDataDir, $releaseId,
], $releaseProbeDesc, $storeProbePipes, dirname(__DIR__), [
    'BXAPP_DATA_ROOT' => $releaseDir . '/public-override',
    'BXAPP_CREDS_FILE' => $releaseDir . '/public-creds.php',
    'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
]);
$storeProbeOut = '';
$storeProbeErr = 'process не запущен';
$storeProbeRc = 255;
if (is_resource($storeProbeProc)) {
    fclose($storeProbePipes[0]);
    $storeProbeOut = (string)stream_get_contents($storeProbePipes[1]);
    $storeProbeErr = (string)stream_get_contents($storeProbePipes[2]);
    fclose($storeProbePipes[1]);
    fclose($storeProbePipes[2]);
    $storeProbeRc = proc_close($storeProbeProc);
}
check('CLI preflight реально проверяет private store и штатный journal writer release',
    $storeProbeRc === 0 && trim($storeProbeOut) === 'BXAPP_PRIVATE_STORE_OK:' . $releaseId
    && strncmp((string)@file_get_contents($sharedDataDir . '/deploy-canary-' . $releaseId . '.php'),
        BXAPP_PRIVATE_PHP_404_HEADER, strlen(BXAPP_PRIVATE_PHP_404_HEADER)) === 0
    && is_file($sharedDataDir . '/logs/' . date('Y-m-d') . '.log.php')
    && strncmp((string)@file_get_contents($sharedDataDir . '/logs/' . date('Y-m-d') . '.log.php'),
        BXAPP_PRIVATE_PHP_404_HEADER, strlen(BXAPP_PRIVATE_PHP_404_HEADER)) === 0
    && strpos((string)@file_get_contents($sharedDataDir . '/logs/' . date('Y-m-d') . '.log.php'),
        'BXAPP-READBACK-' . $releaseId) !== false,
    trim($storeProbeOut . ' ' . $storeProbeErr));
testWipe($releaseLayoutRoot, true);
$privateStoreProbe = (string)@file_get_contents(dirname(__DIR__) . '/www/bin/deploy-private-store.php');
check('deploy исполняет env/store release и сверяет фактический private DATA_ROOT',
    $privateStoreProbe !== ''
    && strpos($privateStoreProbe, "realpath(\$actualRoot)") !== false
    && strpos($privateStoreProbe, "B24_CREDS_FILE !== \$actualRoot . '/creds.php'") !== false
    && strpos($privateStoreProbe, 'journalAppend($journalLine . "\\n")') !== false
    && strpos($privateStoreProbe, 'BXAPP_PRIVATE_PHP_LEGACY_HEADER') !== false
    && strpos($privateStoreProbe, 'BXAPP_PRIVATE_PHP_404_HEADER') !== false
    && strpos($privateStoreProbe, '$journalExisted') !== false
    && strpos($privateStoreProbe, 'strncmp($journalRaw, $expectedHeader') !== false
    && strpos($deploy, "BXAPP_DATA_ROOT='\${RELEASE_DIR}/forbidden-data'") !== false
    && strpos($deploy, "BXAPP_CREDS_FILE='\${RELEASE_DIR}/forbidden-creds.php'") !== false
    && strpos($deploy, '/bin/deploy-private-store.php') !== false
    && strpos($deploy, "test ! -e '\${RELEASE_DIR}/data'") !== false);
check('обычный deploy копирует активный remote env, а локальный требует явного флага',
    strpos($deploy, 'cp -p \'${REMOTE_WWW}/env.php\' \'${RELEASE_DIR}/env.php\'') !== false
    && strpos($deploy, '--push-env') !== false);
check('legacy migration нельзя начать без подтверждённой остановки writers',
    strpos($deploy, '--migrate-legacy --confirm-quiesced') !== false
    && strpos($deploy, 'файловый state нельзя переносить при живых writers') !== false);
check('legacy rollback выводит фазу из файловой системы, а не post-mv флагов',
    strpos($deploy, 'moved=') === false && strpos($deploy, 'migrated=') === false
    && strpos($deploy, 'trap restore_legacy EXIT') !== false
    && strpos($deploy, "trap 'exit 143' HUP TERM") !== false);
check('retention не разбирает имена через word-splitting ls',
    strpos($deploy, 'for name in \\$(ls') === false
    && strpos($deploy, 'BXAPP_RETENTION') !== false);
$retentionBlock = '';
if (preg_match("/<<'BXAPP_RETENTION'\R(.*?)^BXAPP_RETENTION$/ms", $deploy, $retentionMatch) === 1) {
    $retentionBlock = (string)$retentionMatch[1];
}
check('ошибка sort при retention не маскируется process substitution',
    $retentionBlock !== ''
    && strpos($retentionBlock, 'sort -r "$unsorted" > "$sorted"') !== false
    && strpos($retentionBlock, '< <(') === false
    && strpos($retentionBlock, 'retention_tmp=$(mktemp -d)') !== false
    && strpos($retentionBlock, 'trap \'rm -rf -- "$retention_tmp"\' EXIT') !== false);
check('общий self-check fail-closed перечисляет PHP/JS без process substitution',
    substr_count($selfCheck, 'if ! find "${PROJECT_DIR}/www" -type f') === 2
    && strpos($selfCheck, '< <(find') === false
    && strpos($selfCheck, '-print0 > "$CHECK_TMP/php.files"') !== false
    && strpos($selfCheck, '-print0 > "$CHECK_TMP/js.files"') !== false);
check('сигнал в hook-режиме идёт через блокирующий fail, а не неблокирующий exit',
    strpos($selfCheck, 'if [ "$HOOK" = "1" ]; then') !== false
    && strpos($selfCheck, 'fail "Самопроверка прервана сигналом ${signal}') !== false
    && strpos($selfCheck, "trap 'interrupted HUP 129' HUP") !== false
    && strpos($selfCheck, "trap 'interrupted INT 130' INT") !== false
    && strpos($selfCheck, "trap 'interrupted TERM 143' TERM") !== false
    && strpos($selfCheck, "trap 'exit 143' TERM") === false);
// Путь для планировщика обязан быть абсолютным: cron стартует не из домашнего
// каталога, и относительный путь у него молча не находится. Домашний каталог
// при этом бывает нестандартным (приложение подселяют на VPS рядом с чужим),
// поэтому он берётся переменной — но с абсолютным значением по умолчанию.
// Служебные команды обязаны обращаться к ТОМУ ЖЕ серверу, куда выкладывается
// проект. 21.09.2026 запись кред жила со своим жёстко зашитым путём к параметрам
// хостинга: проект на VPS клиента выложился куда надо, а секрет клиента молча
// уехал на дефолтный хост шаблона — и команда ответила «Готово».
$deployEnvLine = [];
preg_match('~^BXAPP_ENV_FILE=(\S+)~m', $deploy, $deployEnvLine);
$deployEnvFile = (string)($deployEnvLine[1] ?? '');
// Слоты раскладки спрашиваем по делу: журнал читает CLI внутри релиза (нужен
// только родитель релизов), запись кред лезет ещё и в приватный store.
$cliLayoutSlots = ['set-creds.sh' => ['parent', 'control'], 'logs.sh' => ['parent']];
foreach ($cliLayoutSlots as $cliName => $neededSlots) {
    $cliSource = (string)@file_get_contents(dirname(__DIR__) . '/scripts/' . $cliName);
    $hardcoded = preg_match('~(?<![\w/])source\s+/data/config/env/netangels\.env~', $cliSource) === 1;
    check('служебная команда берёт параметры сервера оттуда же, что и деплой: ' . $cliName,
        !$hardcoded && strpos($cliSource, 'bxappEnvFile') !== false
        && strpos($cliSource, 'bxapp-remote.sh') !== false,
        $hardcoded ? 'путь зашит в самом скрипте' : '');
    foreach ($neededSlots as $slot) {
        $helper = $slot === 'parent' ? 'bxappRemoteParent' : 'bxappRemoteControl';
        check('служебная команда берёт раскладку каталогов у общего источника (' . $slot . '): ' . $cliName,
            strpos($cliSource, $helper) !== false);
    }
}
check('cron печатается абсолютным путём',
    strpos($deploy, 'REMOTE_HOME="${BXAPP_REMOTE_HOME:-/home/${NETANGELS_USER}}"') !== false
    && strpos($deploy, '${REMOTE_HOME}/${REMOTE_WWW}/bin/process.php') !== false);
// Проверяем КОНТРАКТ подсказки, а не содержимое конкретного env.php: слоты должны
// объявляться ровно в том виде, который умеет распознать deploy. Живой файл сюда
// не годится — разработчик по инструкции шаблона вписывает в него свои тестовые
// креды, и проверка «слот пуст» краснела бы у него на каждой самопроверке.
$envSlotDeclarations = (string)preg_replace('~(\$B24_CLIENT_(?:ID|SECRET)_LOCAL\s*=\s*)\'[^\']*\'~',
    "$1''", $envSource);
check('финальная подсказка deploy распознаёт реальное выравнивание пустых local-кредов',
    strpos($deploy, '\\\$B24_CLIENT_ID_LOCAL[[:space:]]*=[[:space:]]*[\'][\']') !== false
    && strpos($deploy, '\\\$B24_CLIENT_SECRET_LOCAL[[:space:]]*=[[:space:]]*[\'][\']') !== false
    && preg_match("/^[\\t ]*\\\$B24_CLIENT_ID_LOCAL[\\t ]*=[\\t ]*''[\\t ]*;/m", $envSlotDeclarations) === 1
    && preg_match("/^[\\t ]*\\\$B24_CLIENT_SECRET_LOCAL[\\t ]*=[\\t ]*''[\\t ]*;/m", $envSlotDeclarations) === 1);
$setCreds = (string)@file_get_contents(dirname(__DIR__) . '/scripts/set-creds.sh');
$setCredsWriter = (string)@file_get_contents(dirname(__DIR__) . '/scripts/set-creds-write.php');
check('локальный модуль кредов пишет private state вне webroot',
    substr_count($setCreds, '${PROJECT_DIR}/data/creds.php') >= 3
    && strpos($setCreds, '${PROJECT_DIR}/www/data/creds.php') === false
    && strpos($setCreds, 'data/creds.php вне webroot') !== false);
check('готовый модуль кредов физически запрещает секрет в argv',
    strpos($setCreds, 'client_secret аргументом запрещён') !== false
    && strpos($setCreds, 'CLIENT_SECRET="${2:-') === false);
check('готовый модуль кредов выбирает live-layout и делегирует bounded atomic writer',
    strpos($setCreds, 'PRIVATE_DATA="${CONTROL}/shared/data"') !== false
    && strpos($setCreds, 'REMOTE_DATA="${REMOTE_WWW}/data"') !== false
    && strpos($setCreds, "printf 'legacy'") !== false
    && strpos($setCreds, 'scripts/set-creds-write.php') !== false
    && strpos($setCreds, 'php -- \"\$target\"') !== false
    && strpos($setCredsWriter, 'SET_CREDS_MAX_BYTES') !== false
    && strpos($setCredsWriter, "tempnam(\$directory, '.creds.')") !== false
    && strpos($setCredsWriter, '@rename($tmp, $target)') !== false);
check('credential writer проверяет flush/fsync lock-header до чтения target',
    substr_count($setCredsWriter, 'setCredsFlushAndSync(') === 3
    && strpos($setCredsWriter, '@fflush($lock)') === false
    && strpos($setCredsWriter, '@fsync($lock)') === false
    && strpos($setCredsWriter, "setCredsFlushAndSync(\$lock, 'private lock-header')")
        < strpos($setCredsWriter, '$creds = setCredsReadCurrent($target);'));
check('готовый модуль кредов fail-closed запрещает symlink и directory-target',
    strpos($setCreds, 'unsafe creds target') !== false
    && strpos($setCreds, 'if [ -L \"\$target\" ]') !== false
    && strpos($setCreds, '[ ! -f \"\$target\" ]') !== false
    && substr_count($setCredsWriter, '@lstat(') >= 4
    && strpos($setCredsWriter, '@flock($lock, LOCK_EX)') !== false
    && strpos($setCredsWriter, "['ino']") !== false
    // Каталог, в котором появится live-ссылка, обязан быть настоящим каталогом.
    // Имя ему даёт общая раскладка (bxappRemoteParent), а не домен из env: проект
    // может стоять в подкаталоге чужого сайта — как на VPS клиента.
    && strpos($setCreds, "check_existing_dir '\${REMOTE_PARENT}'") !== false
    && strpos($setCreds,
        "test -d '\${REMOTE_PARENT}' && test ! -L '\${REMOTE_PARENT}'") !== false);
// Метка выкладки должна доезжать до человека заголовком — иначе проверять нечего.
check('приложение отдаёт метку выкладки заголовком',
    ($libSource = (string)@file_get_contents(dirname(__DIR__) . '/www/api/lib.php')) !== ''
    && strpos($libSource, "__DIR__ . '/../release.php'") !== false
    && strpos($libSource, 'require_once $releaseFile') !== false
    && strpos($libSource, 'X-App-Release') !== false);

// ── ACL: поздний старый ответ не отменяет свежую выдачу/отзыв доступа ─────
// Два GET намеренно завершаются в обратном порядке. Одной проверки наличия
// переменной generation мало: здесь выполняется настоящий app.js с DOM/API-заглушками.
$aclRaceHarness = <<<'JS'
const fs = require('fs');
const vm = require('vm');

(async function () {
  const appPath = process.argv[2];
  const original = fs.readFileSync(appPath, 'utf8');
  const source = original.replace(/\}\)\(\);\s*$/, 'window.__testLoadAcl = loadAcl;\n})();');
  if (source === original) throw new Error('не удалось открыть loadAcl для теста');

  const tableBody = {
    innerHTML: '',
    querySelectorAll: function () { return []; }
  };
  const requests = [];
  const document = {
    getElementById: function () { return null; },
    querySelector: function (selector) {
      return selector === '#acl-table tbody' ? tableBody : null;
    },
    querySelectorAll: function () { return []; }
  };
  const ui = {
    api: function (method, path, body, options) {
      return new Promise(function (resolve, reject) {
        requests.push({ method, path, options: options || {}, resolve, reject });
      });
    },
    esc: function (value) { return String(value); },
    toast: function () {},
    confirmBox: function () { return Promise.resolve(false); },
    showState: function (target, state, options) {
      target.innerHTML = state + ':' + String((options && options.text) || '');
    }
  };
  const window = { APP_ROLE: 'admin', AbortController: AbortController };
  vm.runInNewContext(source, {
    window, document, ui, Promise, AbortController, console,
    setTimeout, clearTimeout, encodeURIComponent
  }, { filename: appPath });

  window.__testLoadAcl();
  window.__testLoadAcl();
  if (requests.length !== 2) throw new Error('ожидалось два ACL-запроса');
  if (!requests[0].options.signal || !requests[0].options.signal.aborted) {
    throw new Error('предыдущий ACL-запрос не отменён');
  }

  requests[1].resolve({ acl: {
    owner_uid: 1,
    users: [{ uid: 2, name: 'NEW', role: 'view' }]
  }});
  await new Promise(function (resolve) { setImmediate(resolve); });
  requests[0].resolve({ acl: {
    owner_uid: 1,
    users: [{ uid: 3, name: 'OLD', role: 'view' }]
  }});
  await new Promise(function (resolve) { setImmediate(resolve); });

  if (tableBody.innerHTML.indexOf('NEW') < 0 || tableBody.innerHTML.indexOf('OLD') >= 0) {
    throw new Error('поздний старый ACL перерисовал таблицу: ' + tableBody.innerHTML);
  }
  process.stdout.write('ACL_RACE_OK\n');
})().catch(function (error) {
  process.stderr.write(String(error && error.stack || error) + '\n');
  process.exitCode = 1;
});
JS;
$nodeBin = trim((string)@shell_exec('command -v node 2>/dev/null'));
$aclHarnessFile = DATA_ROOT . '/acl-race-test.js';
file_put_contents($aclHarnessFile, $aclRaceHarness);
$aclRaceOut = []; $aclRaceRc = 127;
if ($nodeBin !== '') {
    @exec(escapeshellarg($nodeBin) . ' ' . escapeshellarg($aclHarnessFile) . ' '
        . escapeshellarg(dirname(__DIR__) . '/www/js/app.js') . ' 2>&1', $aclRaceOut, $aclRaceRc);
}
@unlink($aclHarnessFile);
check('ACL применяет только последний ответ и отменяет предыдущий запрос',
    $aclRaceRc === 0 && in_array('ACL_RACE_OK', $aclRaceOut, true),
    $nodeBin === '' ? 'нет node' : implode(' | ', $aclRaceOut));

// ── Настройки: GET и POST владеют одной epoch ──────────────────────
// Матрица гоняет фактический app.js, а не копию функции: read↔mutation
// запускаются в обоих порядках, late success/error не меняют UI, а новый
// GET после принятого POST по-прежнему работает.
$settingsRaceHarness = <<<'JS'
const fs = require('fs');
const vm = require('vm');

function flush() { return new Promise(function (resolve) { setImmediate(resolve); }); }

function makeHarness(appPath) {
  const original = fs.readFileSync(appPath, 'utf8');
  const source = original.replace(/\}\)\(\);\s*$/, [
    'window.__testLoadSettings = loadSettings;',
    'window.__testReplaceRenderSettings = function (fn) { renderSettings = fn; };',
    '})();'
  ].join('\n'));
  if (source === original) throw new Error('settings export failed');
  const saveButton = {};
  const box = { classList: { add() {}, remove() {} }, innerHTML: '' };
  const requests = [], renders = [], toasts = [], states = [];
  const document = {
    getElementById: function (id) {
      if (id === 'settings-save') return saveButton;
      if (id === 'settings-form') return box;
      return null;
    },
    querySelector: function () { return null; },
    querySelectorAll: function () { return []; },
    createElement: function () { return {}; }
  };
  const ui = {
    api: function (method, path, body, options) {
      return new Promise(function (resolve, reject) {
        requests.push({ method, path, body, options: options || {}, resolve, reject });
      });
    },
    action: function (button, work) { return Promise.resolve().then(work); },
    showState: function (target, state) { states.push(state); },
    toast: function (message, kind) { toasts.push(kind + ':' + message); },
    esc: function (value) { return String(value); }
  };
  const window = { APP_ROLE: 'admin', AbortController: AbortController };
  vm.runInNewContext(source, {
    window, document, ui, Promise, AbortController, console,
    setTimeout, clearTimeout, encodeURIComponent, isFinite
  }, { filename: appPath });
  window.__testReplaceRenderSettings(function (fields) {
    renders.push(fields[0] && fields[0].key);
  });
  return { window, saveButton, requests, renders, toasts, states };
}

(async function () {
  const appPath = process.argv[2];

  const a = makeHarness(appPath);
  a.saveButton.onclick(); await flush();
  a.window.__testLoadSettings();
  if (a.requests.length !== 2 || a.requests[0].method !== 'POST' || a.requests[1].method !== 'GET') {
    throw new Error('mutation/read request pair mismatch');
  }
  a.requests[0].resolve({ saved: ['new'], fields: [{ key: 'new' }] }); await flush();
  if (!a.requests[1].options.signal || !a.requests[1].options.signal.aborted) {
    throw new Error('accepted POST did not abort overlapping GET');
  }
  a.requests[1].resolve({ fields: [{ key: 'old' }] }); await flush();
  if (a.renders.join(',') !== 'new') throw new Error('late GET replaced POST: ' + a.renders);
  a.window.__testLoadSettings();
  a.requests[2].resolve({ fields: [{ key: 'fresh' }] }); await flush();
  if (a.renders.join(',') !== 'new,fresh') throw new Error('fresh GET after POST failed: ' + a.renders);

  const b = makeHarness(appPath);
  b.window.__testLoadSettings();
  b.saveButton.onclick(); await flush();
  if (!b.requests[0].options.signal || !b.requests[0].options.signal.aborted) {
    throw new Error('POST did not abort earlier GET');
  }
  b.requests[0].resolve({ fields: [{ key: 'old' }] }); await flush();
  b.requests[1].resolve({ saved: ['new'], fields: [{ key: 'new' }] }); await flush();
  if (b.renders.join(',') !== 'new') throw new Error('read/mutation final UI is stale: ' + b.renders);

  const c = makeHarness(appPath);
  c.saveButton.onclick(); await flush();
  c.window.__testLoadSettings();
  c.requests[1].resolve({ fields: [{ key: 'fresh' }] }); await flush();
  c.requests[0].reject({ message: 'late failure', data: { fields: { any: 'old error' } } }); await flush();
  if (c.renders.join(',') !== 'fresh' || c.toasts.length !== 0 || c.states.indexOf('error') >= 0) {
    throw new Error('late POST error changed fresh form');
  }
  process.stdout.write('SETTINGS_EPOCH_OK\n');
})().catch(function (error) {
  process.stderr.write(String(error && error.stack || error) + '\n');
  process.exitCode = 1;
});
JS;
$settingsHarnessFile = DATA_ROOT . '/settings-epoch-test.js';
file_put_contents($settingsHarnessFile, $settingsRaceHarness);
$settingsRaceOut = []; $settingsRaceRc = 127;
if ($nodeBin !== '') {
    @exec(escapeshellarg($nodeBin) . ' ' . escapeshellarg($settingsHarnessFile) . ' '
        . escapeshellarg(dirname(__DIR__) . '/www/js/app.js') . ' 2>&1', $settingsRaceOut, $settingsRaceRc);
}
@unlink($settingsHarnessFile);
check('настройки делят единую read/mutation epoch и не применяют устаревшие success/error',
    $settingsRaceRc === 0 && in_array('SETTINGS_EPOCH_OK', $settingsRaceOut, true),
    $nodeBin === '' ? 'нет node' : implode(' | ', $settingsRaceOut));

// ── Неустановленное приложение объясняет ИМЕННО ЭТО ─────────────────────
// Самый частый первый сценарий: приложение выложили и открыли ссылку до
// установки в портале. Барьер поколения отказывал здесь текстом «приложение
// переустановили во время открытия» — человек шёл искать поломку, которой нет.
storeWrite(B24_TOKENS_FILE, []);
[$niCode, , $niPage, ] = $call('GET', '/index.php',
    ['site' => 'cross-site', 'headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document']]);
check('страница неустановленного приложения говорит, что установки не было',
    $niCode === 409 && strpos($niPage, 'ещё не установлено') !== false,
    "код $niCode");
check('и подсказывает установить из карточки, а не «открыть заново»',
    strpos($niPage, 'карточку local-app') !== false && strpos($niPage, 'переустановлено') === false);
[$niApi, $niJson, ] = $call('GET', '/api/settings.php', ['token' => str_repeat('n', 64)]);
check('API отвечает «не установлено», а не «установка изменилась»',
    $niApi === 409 && ($niJson['code'] ?? '') === 'not_installed',
    "код $niApi, ответ: " . (string)($niJson['code'] ?? '—'));

// Удалённое приложение — отдельная причина и отдельный текст.
storeWrite(B24_TOKENS_FILE, ['uninstalled_at' => time()]);
[$unCode, , $unPage, ] = $call('GET', '/index.php',
    ['site' => 'cross-site', 'headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document']]);
check('удалённое приложение так и говорит', $unCode === 409 && strpos($unPage, 'удалено с портала') !== false,
    "код $unCode");
[$unApi, $unJson, ] = $call('GET', '/api/settings.php', ['token' => str_repeat('u', 64)]);
check('API удалённого приложения возвращает отдельный машинный код',
    $unApi === 409 && ($unJson['code'] ?? '') === 'uninstalled',
    "код $unApi, ответ: " . (string)($unJson['code'] ?? '—'));

// Незавершённая очистка — временное состояние: клиент должен повторить запрос,
// а не считать, что навсегда попал в чужое поколение установки.
storeWrite(B24_TOKENS_FILE, [
    'install_generation' => appNewInstallGeneration(),
    'uninstalled_at' => time(),
    'cleanup_pending' => true,
]);
[$pendingPageCode, , $pendingPage, $pendingPageHeaders] = $call('GET', '/index.php',
    ['site' => 'cross-site', 'headers' => ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document']]);
check('страница незавершённой очистки отвечает временным отказом с повтором',
    $pendingPageCode === 503 && strpos($pendingPage, 'убирает за собой') !== false
    && preg_match('~^Retry-After:\s*60\s*$~mi', $pendingPageHeaders) === 1,
    "код $pendingPageCode");
[$pendingApiCode, $pendingApiJson, , $pendingApiHeaders] = $call(
    'GET', '/api/settings.php', ['token' => str_repeat('p', 64)]
);
check('API незавершённой очистки даёт retryable 503, а не installation_changed',
    $pendingApiCode === 503
    && ($pendingApiJson['code'] ?? '') === 'installation_cleanup_pending'
    && preg_match('~^Retry-After:\s*60\s*$~mi', $pendingApiHeaders) === 1,
    "код $pendingApiCode, ответ: " . (string)($pendingApiJson['code'] ?? '—'));

// Оба HTTP-входа обязаны читать одну матрицу — иначе следующая новая фаза снова
// будет исправлена только на странице либо только в API.
$expectedInstallationFailures = [
    'empty' => [409, 'not_installed', 0],
    'uninstalled' => [409, 'uninstalled', 0],
    'pending' => [503, 'installation_cleanup_pending', 60],
    'changed' => [409, 'installation_changed', 0],
];
foreach ($expectedInstallationFailures as $phase => [$status, $machineCode, $retryAfter]) {
    $failure = appInstallationFailureSpec($phase);
    check("контракт lifecycle-фазы $phase един для страницы и API",
        $failure['status'] === $status && $failure['code'] === $machineCode
        && $failure['retry_after'] === $retryAfter
        && $failure['title'] !== '' && $failure['reason'] !== '' && $failure['action'] !== ''
        && $failure['api_error'] !== '');
}
$unknownFailure = appInstallationFailureSpec('неизвестная-фаза');
check('неизвестная lifecycle-фаза безопасно сводится к installation_changed',
    $unknownFailure['status'] === 409 && $unknownFailure['code'] === 'installation_changed');
$indexLifecycleSource = (string)file_get_contents(dirname(__DIR__) . '/www/index.php');
$httpLifecycleSource = (string)file_get_contents(dirname(__DIR__) . '/www/api/http.php');
// Смысл проверки — «нет СВОИХ веток по фазам», а не «обработчик зовут ровно
// дважды». Точное число было снимком текущего кода: 05.08 перехват отказа
// добавили ещё в двух местах (блок app.info и отметка installFinish), где раньше
// исключение уходило в общий 500, — и проверка покраснела на правильной правке.
// Ограничение осталось там, где оно и нужно: матрица фаз читается в одном месте,
// разбора `$e->phase === ...` не появляется нигде.
check('страница и API не дублируют ветки фаз поверх общей матрицы',
    substr_count($indexLifecycleSource, 'appInstallationFailureSpec($phase)') === 1
    && substr_count($indexLifecycleSource, '$renderIndexInstallationFailure(') >= 2
    && substr_count($httpLifecycleSource, 'appInstallationFailureSpec($e->phase)') === 1
    && strpos($indexLifecycleSource, '$e->phase ===') === false
    && strpos($httpLifecycleSource, '$e->phase ===') === false);

// Добавление phase не должно ломать унаследованный контракт RuntimeException:
// прикладные расширения шаблона могли передавать code и previous.
$previousInstallError = new RuntimeException('предыдущая причина');
$compatibleInstallError = new InstallGenerationMismatchException(
    'несовпадение', 409, $previousInstallError
);
check('lifecycle-исключение сохраняет стандартные message/code/previous',
    $compatibleInstallError->getMessage() === 'несовпадение'
    && $compatibleInstallError->getCode() === 409
    && $compatibleInstallError->getPrevious() === $previousInstallError
    && $compatibleInstallError->phase === 'changed');
$pendingInstallError = new InstallGenerationMismatchException(
    'очистка', 73, $previousInstallError, 'pending'
);
check('четвёртый аргумент фазы не подменяет стандартные code/previous',
    $pendingInstallError->phase === 'pending' && $pendingInstallError->getCode() === 73
    && $pendingInstallError->getPrevious() === $previousInstallError);

// ── Журнал операций runOnce: потолок, уборка и урезание результата ────────
// Ревью 05.08.2026: файл рос линейно по числу мутаций за неделю, а читается он
// целиком и дважды на операцию. Замер тогда дал 356 мс и 64 МиБ на одну мутацию
// при 20 000 записей и фатал по памяти около 40 000 — причём фатал случался
// ПОСЛЕ работы в портале, но ДО фиксации результата.
$opsNow = time();
$opsSample = [];
for ($i = 0; $i < 400; $i++) {
    $opsSample['истёкшая:' . $i] = ['at' => $opsNow - 800000, 'expires_at' => $opsNow - 100,
        'ttl' => 604800, 'status' => 'done', 'result' => ['id' => $i], 'attempt' => 'a' . $i];
}
for ($i = 0; $i < 100; $i++) {
    $opsSample['живая:' . $i] = ['at' => $opsNow, 'expires_at' => $opsNow + 604800,
        'ttl' => 604800, 'status' => 'done', 'result' => ['id' => $i], 'attempt' => 'b' . $i];
}
$opsSample['оборвалась'] = ['at' => $opsNow - 900000, 'status' => 'unknown', 'error' => 'обрыв', 'attempt' => 'c'];
storeWrite(OPS_FILE, $opsSample);
$pruned = runOncePrune();
$opsAfter = storeRead(OPS_FILE) ?? [];
check('уборка снимает только истёкшие завершённые операции',
    $pruned['было'] === 501 && $pruned['стало'] === 101
    && count($opsAfter) === 101 && isset($opsAfter['оборвалась'])
    && !isset($opsAfter['истёкшая:0']),
    'было ' . $pruned['было'] . ' стало ' . $pruned['стало']);

storeWrite(OPS_FILE, [
    'оборванный-running' => ['at' => $opsNow - 900, 'status' => 'running', 'attempt' => 'dead'],
    'живой-running' => ['at' => $opsNow - 900, 'status' => 'running', 'attempt' => 'alive'],
]);
[$activeLease, $activeLeasePath] = runOnceLeaseAcquire('живой-running');
$runningPruned = runOncePrune();
$runningAfter = storeRead(OPS_FILE) ?? [];
runOnceLeaseRelease($activeLease, $activeLeasePath);
check('prune переводит только abandoned running со свободной арендой в unknown',
    $runningPruned['было'] === 2 && $runningPruned['стало'] === 2
    && ($runningAfter['оборванный-running']['status'] ?? '') === 'unknown'
    && ($runningAfter['живой-running']['status'] ?? '') === 'running');

$opsEndpointSource = (string)file_get_contents(APP_PATH . '/api/ops.php');
$accessSource = (string)file_get_contents(APP_PATH . '/api/access.php');
check('admin recovery читает увеличенный журнал и не обещает мнимую автоуборку',
    strpos($opsEndpointSource, 'runOnceAssertFileWithinByteLimit(RUN_ONCE_MAX_BYTES * 8)') !== false
    && strpos($accessSource, 'Фоновая обработка разбирает его сама') === false);

storeWrite(OPS_FILE, []);
$bigOnce = runOnce('крупный-результат', static fn(): array => ['id' => 77, 'payload' => str_repeat('д', 20000)]);
$bigStored = (storeRead(OPS_FILE) ?? [])['крупный-результат']['result'] ?? [];
check('крупный результат не раздувает журнал операций, но оставляет идентификатор',
    ($bigStored['id'] ?? null) === 77 && !empty($bigStored['truncated'])
    && !isset($bigStored['payload']) && ($bigOnce['result']['payload'] ?? '') !== '',
    json_encode($bigStored, JSON_UNESCAPED_UNICODE));

// Разросшийся файл: боевая мутация обязана честно отказать (иначе OOM посреди
// работы в портале), а фоновая уборка — наоборот, обязана его разобрать.
$opsOverflow = [];
for ($i = 0; $i < 9000; $i++) {
    $opsOverflow['ключ:' . $i] = ['at' => $opsNow - 900000, 'expires_at' => $opsNow - 100,
        'ttl' => 604800, 'status' => 'done', 'result' => ['id' => $i],
        'attempt' => str_repeat('x', 250)];
}
storeWrite(OPS_FILE, $opsOverflow);
$overflowRefused = false;
try { runOnce('после-переполнения', static fn(): array => ['ok' => true]); }
catch (Throwable $e) { $overflowRefused = strpos($e->getMessage(), 'журнал операций больше предела') !== false; }
check('разросшийся журнал операций останавливает мутацию понятной ошибкой, а не фаталом',
    $overflowRefused && filesize(OPS_FILE) > RUN_ONCE_MAX_BYTES);
$prunedOverflow = ['было' => -1, 'стало' => -1];
try { $prunedOverflow = runOncePrune(); } catch (Throwable $e) { /* ниже проверим */ }
check('фоновая уборка разбирает именно тот файл, который заблокировал мутацию',
    $prunedOverflow['было'] === 9000 && $prunedOverflow['стало'] === 0
    && filesize(OPS_FILE) < RUN_ONCE_MAX_BYTES,
    'было ' . $prunedOverflow['было'] . ' стало ' . $prunedOverflow['стало']);
storeWrite(OPS_FILE, []);

// ── Повторное ревью 05.08.2026: регрессии на собственные правки ───────────
// Прогон по уже исправленному шаблону нашёл, что часть фиксов лечила симптом и
// заводила новый тупик. Эти проверки держат именно то поведение, которого не
// хватало: выход из тупика должен РАБОТАТЬ, а не присутствовать в коде.

// Кнопка «Повторить завершение установки» на экране 409 шлёт обычный POST на
// себя же — и обязана пройти тот же транспортный забор, что и открытие из
// портала. Без APP_SID и точного DOMAIN админ получал «Приложение открывается
// только из портала», а установка оставалась незавершённой навсегда.
$retryOldReferer = $_SERVER['HTTP_REFERER'] ?? null;
// В браузере такой POST приходит с self-REFERER (страница приложения в iframe),
// он забором тоже принимается; здесь APP_URL пуст — в CLI её неоткуда взять, —
// поэтому проверяем ровно то, из-за чего кнопка была мёртвой: поля APP_SID и DOMAIN.
$_SERVER['HTTP_REFERER'] = 'https://portal.invalid/marketplace/app/1/';
$retryFormFields = ['AUTH_ID' => 'admin-token', 'APP_SID' => 'sid-1',
    'DOMAIN' => 'portal.invalid', 'bxapp_retry_install_finish' => '1'];
$retryPasses = portalPostPassesTransportFence($retryFormFields, 'portal.invalid');
$retryWithoutSid = portalPostPassesTransportFence(
    ['AUTH_ID' => 'admin-token', 'bxapp_retry_install_finish' => '1'], 'portal.invalid');
if ($retryOldReferer === null) unset($_SERVER['HTTP_REFERER']); else $_SERVER['HTTP_REFERER'] = $retryOldReferer;
check('нажатие кнопки повтора установки доходит до обработчика, а не до отказа забора',
    $retryPasses === true && $retryWithoutSid === false);
// ── Коробочный портал: другой набор полей в POST обработчика ──────────────
// Коробка не шлёт ни DOMAIN, ни APP_SID. Она шлёт SERVER_ENDPOINT и
// APPLICATION_TOKEN (+ member_id, PLACEMENT). Забор искал именно DOMAIN/APP_SID,
// поэтому КАЖДОЕ открытие на коробке отбивалось при исправной установке.
$boxOldReferer = $_SERVER['HTTP_REFERER'] ?? null;
$_SERVER['HTTP_REFERER'] = 'https://box.invalid/marketplace/app/1/';
$boxPost = [
    'AUTH_ID' => 'user-token', 'AUTH_EXPIRES' => '3600', 'REFRESH_ID' => 'refresh',
    'SERVER_ENDPOINT' => 'https://box.invalid/rest/', 'APPLICATION_TOKEN' => 'app-token-box',
    'APPLICATION_SCOPE' => 'crm,user', 'member_id' => 'mid', 'status' => 'L',
    'PLACEMENT' => 'DEFAULT',
];
$boxPasses = portalPostPassesTransportFence($boxPost, 'box.invalid');
// Чужой портал в SERVER_ENDPOINT забором не принимается.
$boxForeign = portalPostPassesTransportFence(
    ['AUTH_ID' => 'user-token', 'SERVER_ENDPOINT' => 'https://other.invalid/rest/',
     'APPLICATION_TOKEN' => 'app-token-box'], 'box.invalid');
// Без ключа приложения коробочный POST не проходит: одного AUTH_ID мало.
$boxNoToken = portalPostPassesTransportFence(
    ['AUTH_ID' => 'user-token', 'SERVER_ENDPOINT' => 'https://box.invalid/rest/'], 'box.invalid');
check('коробочный POST с SERVER_ENDPOINT и APPLICATION_TOKEN проходит забор',
    $boxPasses === true && $boxForeign === false && $boxNoToken === false);

// Облачный POST не должен измениться: SERVER_ENDPOINT там указывает на общий
// сервер авторизации, и подставлять его вместо домена портала нельзя.
$cloudWithEndpoint = portalPostPassesTransportFence(
    ['AUTH_ID' => 'user-token', 'APP_SID' => 'sid-1', 'DOMAIN' => 'portal.invalid',
     'SERVER_ENDPOINT' => 'https://oauth.bitrix24.tech/rest/'], 'portal.invalid');
$_SERVER['HTTP_REFERER'] = 'https://portal.invalid/marketplace/app/1/';
$cloudWrongDomain = portalPostPassesTransportFence(
    ['AUTH_ID' => 'user-token', 'APP_SID' => 'sid-1', 'DOMAIN' => 'other.invalid',
     'SERVER_ENDPOINT' => 'https://portal.invalid/rest/'], 'portal.invalid');
if ($boxOldReferer === null) unset($_SERVER['HTTP_REFERER']); else $_SERVER['HTTP_REFERER'] = $boxOldReferer;
check('облачный POST забором не ослаблен: домен по-прежнему сверяется по DOMAIN',
    $cloudWithEndpoint === false && $cloudWrongDomain === false);

// Обновление токенов идёт на сервер авторизации, а не на портал: портал не
// хранит client_secret приложения и отвергает refresh.
$envSource = (string)file_get_contents(APP_PATH . '/env.php');
$b24Source = (string)file_get_contents(APP_PATH . '/api/b24.php');
check('обновление токенов адресовано серверу авторизации Битрикса',
    strpos($envSource, "'https://oauth.bitrix24.tech/oauth/token/'") !== false
    && strpos($b24Source, "'https://oauth.bitrix24.tech/oauth/token/'") !== false
    && strpos($envSource, 'oauth.bitrix.info') === false
    && strpos($b24Source, 'oauth.bitrix.info') === false);

$indexSource = (string)file_get_contents(APP_PATH . '/index.php');
check('форма повтора установки возвращает поля забора из текущего POST портала',
    strpos($indexSource, "'APP_SID'    => portalPostAppSid(\$post)") !== false
    && strpos($indexSource, "'DOMAIN'     => portalPostDomain(\$post)") !== false);

// Опознание считается на пару «адрес + токен открывающего»: у офиса за общим
// внешним адресом один сотрудник не должен закрывать приложение остальным.
check('лимит опознания личный, а общий потолок на адрес — отдельный и выше',
    strpos($indexSource, 'apiRateAllowChain([') !== false
    && strpos($indexSource, "['identify-ip:' . \$identifyIp, 120]") !== false
    && strpos($indexSource, "['identify-who:' . \$identifyWho, 30]") !== false);

// X-Forwarded-For пишет сам клиент. Верить ему без доверенного прокси нельзя:
// одним заголовком обходится любой лимит на адрес, а подставив чужой адрес,
// можно закрыть вход именно ему.
$ipOldServer = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
$ipWithoutProxy = apiClientIp();
$_SERVER = $ipOldServer;
check('подставленный X-Forwarded-For не подменяет адрес клиента',
    $ipWithoutProxy === '203.0.113.10', $ipWithoutProxy);

// Ящик неразобранных: приём отказывает при переполнении (иначе фатал по памяти
// в момент, когда событие ещё не подтверждено), но разбор обязан работать —
// иначе события не достать вообще ничем, кроме правки файла на сервере.
$deadOverflow = [];
for ($i = 0; $i < 900; $i++) {
    $deadOverflow[] = ['at' => date('c'), 'event' => 'ONCRMDEALUPDATE',
        'data' => ['FIELDS' => ['ID' => $i, 'PAYLOAD' => str_repeat('д', 2400)]],
        'tries' => 3, 'error' => 'обработчик падал', 'eid' => 'dead-' . $i,
        'install_generation' => ''];
}
storeWrite(DEADLETTER_FILE, $deadOverflow);
storeWrite(INBOX_FILE, []);
$deadRefused = false;
try { queueDeadLetter(['event' => 'ONCRMDEALADD', 'data' => [], 'eid' => 'ещё-одно'], 'ошибка'); }
catch (Throwable $e) { $deadRefused = strpos($e->getMessage(), 'больше предела') !== false; }
clearstatcache(true, DEADLETTER_FILE);
$deadBytesBefore = (int)filesize(DEADLETTER_FILE);   // размер до разбора: разбор его и уменьшает
$deadCounted = -1; $deadReplayed = -1; $deadError = '';
try {
    $deadCounted = queueDeadCount();
    $deadReplayed = queueReplayDead();
} catch (Throwable $e) { $deadError = $e->getMessage(); }
check('переполненный ящик неразобранных отказывает в приёме, но разбирается и считается',
    $deadRefused && $deadBytesBefore > DEADLETTER_MAX_BYTES
    && $deadCounted === 900 && $deadReplayed > 0 && $deadError === '',
    'счёт ' . $deadCounted . ', возвращено ' . $deadReplayed . ' ' . $deadError);
storeWrite(DEADLETTER_FILE, []);
storeWrite(INBOX_FILE, []);

// Журнал операций мог переполниться СВЕЖИМИ записями: уборка по сроку тогда
// бессильна (удалять ещё нечего), и каждая мутация в портале отказывала бы до
// конца недели. Дожимаем по возрасту — завершённые снимаются досрочно.
$freshNow = time();
$freshOps = [];
for ($i = 0; $i < 9000; $i++) {
    $freshOps['свежий:' . $i] = ['at' => $freshNow - $i, 'expires_at' => $freshNow + 604800,
        'ttl' => 604800, 'status' => 'done', 'result' => ['id' => $i],
        'attempt' => str_repeat('y', 250)];
}
$freshOps['неизвестный-исход'] = ['at' => $freshNow, 'status' => 'unknown', 'attempt' => 'z'];
storeWrite(OPS_FILE, $freshOps);
clearstatcache(true, OPS_FILE);
$freshBytesBefore = (int)filesize(OPS_FILE);
$freshPruned = runOncePrune();
$freshAfter = storeRead(OPS_FILE) ?? [];
clearstatcache(true, OPS_FILE);
$freshBytesAfter = (int)filesize(OPS_FILE);
// Проверяем не «размер уменьшился», а сценарий пользователя: после уборки
// мутация в портале снова проходит. Прежняя проверка сравнивала размер впритык
// и осталась зелёной, когда дожим мерил компактный JSON вместо файла на диске
// (стор пишет с отступами — почти вдвое больше) и тупик на самом деле не размыкал.
$freshMutationError = '';
try { runOnce('после-дожима-' . $freshNow, static fn(): array => ['ok' => true]); }
catch (Throwable $e) { $freshMutationError = $e->getMessage(); }
check('журнал операций, переполненный свежими записями, разбирается по возрасту',
    $freshBytesBefore > RUN_ONCE_MAX_BYTES
    && $freshBytesAfter <= intdiv(RUN_ONCE_MAX_BYTES * 3, 4)
    && $freshMutationError === ''
    && $freshPruned['стало'] < $freshPruned['было']
    && isset($freshAfter['неизвестный-исход']) && !isset($freshAfter['свежий:8999']),
    'было ' . $freshPruned['было'] . ' стало ' . $freshPruned['стало']
    . ', файл ' . $freshBytesBefore . ' → ' . $freshBytesAfter
    . ($freshMutationError !== '' ? ', мутация: ' . $freshMutationError : ''));
storeWrite(OPS_FILE, []);

// Проверка события платит походом в портал, поэтому её частоту ограничиваем. Но
// общего счётчика тут быть не может: Битрикс события не переприсылает, и поток
// мусора закрыл бы окно единственному настоящему ONAPPUPDATE — приложение молча
// осталось бы со старым ключом. Считаем на адрес отправителя.
storeWrite(RATELIMIT_FILE, []);
$identifyChainAvailable = function_exists('apiRateAllowChain');
$identifyAttackerAllowed = 0;
if ($identifyChainAvailable) {
    for ($i = 0; $i < 700; $i++) {
        if (apiRateAllowChain([
            ['identify-all', 600],
            ['identify-ip:203.0.113.8', 120],
            ['identify-who:attacker-' . $i, 30],
        ], 60)) $identifyAttackerAllowed++;
    }
}
$identifyOtherAllowed = $identifyChainAvailable && apiRateAllowChain([
    ['identify-all', 600],
    ['identify-ip:198.51.100.8', 120],
    ['identify-who:employee', 30],
], 60);
check('один адрес не выжигает общий резерв опознания для другого сотрудника',
    $identifyChainAvailable && $identifyAttackerAllowed === 120 && $identifyOtherAllowed,
    'attacker=' . $identifyAttackerAllowed . ', other=' . ($identifyOtherAllowed ? 'yes' : 'no'));

storeWrite(RATELIMIT_FILE, []);
$verifyOldServer = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
$verifyBurst = 0;
for ($i = 0; $i < 300; $i++) if (lifecycleVerifyAllowed()) $verifyBurst++;
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$verifyOtherAllowed = lifecycleVerifyAllowed();
$_SERVER = $verifyOldServer;
check('поток проверок с одного адреса не закрывает окно законному событию с другого',
    $verifyBurst === 20 && $verifyOtherAllowed === true, 'пропущено ' . $verifyBurst);

// Ручные команды администратора (`replay`, `forget-once`) раньше стояли за общим
// анти-нахлёстом: пока крутился обычный тик, команда молча заканчивалась с кодом
// 0, будто всё сделано. Человек в этот момент разбирает аварию — «молча ничего»
// худший из возможных ответов.
// Приложение должно числиться установленным, иначе фоновый скрипт выйдет раньше
// по другой причине и проверка будет мерить не то.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1',
    'client_id' => 'local.test.app', 'expires_at' => time() + 3600,
    'install_generation' => 'gen-forget',
]);
$cronLockFile = DATA_ROOT . '/cron.lock.php';
// Порядок здесь — не мелочь. Пока держатель сначала объявлял готовность и лишь
// потом брал замок, родитель успевал проверить замок в промежутке и видел его
// свободным: проверка падала «иногда», без всякой связи с проверяемым кодом.
// Плавающий красный хуже пропуска — на него перестают смотреть. Теперь сначала
// захват, потом готовность, и объявляется она переименованием: файл появляется
// целиком или не появляется вовсе.
$holderCode = '$f = fopen(getenv("LOCK"), "c"); flock($f, LOCK_EX);'
    . 'file_put_contents(getenv("READY") . ".tmp", "1");'
    . 'rename(getenv("READY") . ".tmp", getenv("READY"));'
    . 'sleep(20);';
$holderReady = DATA_ROOT . '/lock-holder-ready.tmp';
$holderEnv = ['PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'), 'LOCK' => $cronLockFile, 'READY' => $holderReady];
$holderDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$holder = proc_open(['php', '-r', $holderCode], $holderDesc, $holderPipes, dirname(__DIR__), $holderEnv);
$forgetOut = ''; $forgetCode = -1; $lockHeld = false;
if (is_resource($holder)) {
    fclose($holderPipes[0]);
    for ($i = 0; $i < 100 && !is_file($holderReady); $i++) usleep(20000);
    // Убеждаемся, что замок действительно занят: иначе проверка ничего не значит.
    // Подтверждаем сами, а не верим маркеру на слово, и даём короткий запас на
    // случай, если операционная система ещё не отдала блокировку соседу.
    for ($i = 0; $i < 50 && !$lockHeld; $i++) {
        $probe = @fopen($cronLockFile, 'c');
        if ($probe !== false) {
            $lockHeld = !@flock($probe, LOCK_EX | LOCK_NB);
            if (!$lockHeld) @flock($probe, LOCK_UN);
            fclose($probe);
        }
        if (!$lockHeld) usleep(20000);
    }
    $forgetDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $forget = proc_open(['php', dirname(__DIR__) . '/www/bin/process.php', 'forget-once', 'ключ-которого-нет'],
        $forgetDesc, $forgetPipes, dirname(__DIR__), [
            'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'), 'BXAPP_DATA_ROOT' => DATA_ROOT,
            'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
        ]);
    if (is_resource($forget)) {
        fclose($forgetPipes[0]);
        $forgetOut = (string)stream_get_contents($forgetPipes[1]) . (string)stream_get_contents($forgetPipes[2]);
        fclose($forgetPipes[1]); fclose($forgetPipes[2]);
        $forgetCode = proc_close($forget);
    }
    @proc_terminate($holder);
    stream_get_contents($holderPipes[1]); fclose($holderPipes[1]); fclose($holderPipes[2]);
    proc_close($holder);
}
@unlink($holderReady);
check('снятие залипшего ключа отвечает человеку и при работающей фоновой обработке',
    $lockHeld && $forgetCode !== 0 && trim($forgetOut) !== '',
    'замок занят: ' . var_export($lockHeld, true) . ', код ' . $forgetCode . ', вывод «' . trim($forgetOut) . '»');

// Разрешение на ручной повтор установки — ОДНОРАЗОВОЕ и живёт на сервере.
// Целевая проверка правок 05.08.2026 показала живым прогоном: флаг в теле формы
// переживает перезагрузку страницы завершения установки (она перезагружает себя
// сама, и браузер шлёт то же тело), поэтому один клик администратора давал
// ~30 автоматических повторов установки, под сотню запросов в портал и столько
// же ложных записей «администратор вручную разрешил повтор».
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'retry-access', 'refresh_token' => 'retry-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-1',
    'client_id' => 'local.test.app', 'expires_at' => time() + 3600,
    'install_generation' => 'gen-retry',
    'install_finish_requested_generation' => 'gen-retry',
    'install_finish_requested_at' => time() - 600,
]);
$retryB24 = new B24('local.test.app', 'test-secret');
$retryBefore = $retryB24->manualInstallRetryUsedForCurrentGeneration();
$retryB24->resetInstallFinish();
$retryB24->markManualInstallRetryUsed();
$retryAfter = $retryB24->manualInstallRetryUsedForCurrentGeneration();
// Свежий экземпляр читает состояние с диска: отметка обязана пережить процесс,
// иначе следующий запрос (та самая перезагрузка) снова получит право на повтор.
$retryFresh = (new B24('local.test.app', 'test-secret'))->manualInstallRetryUsedForCurrentGeneration();
check('ручной повтор установки разрешается ровно один раз на поколение',
    $retryBefore === false && $retryAfter === true && $retryFresh === true);

// Новая установка (другое поколение) обязана получить своё право на повтор,
// иначе после переустановки выход из тупика снова окажется закрыт.
$retryTokens = storeRead(B24_TOKENS_FILE) ?? [];
$retryTokens['install_generation'] = 'gen-retry-2';
storeWrite(B24_TOKENS_FILE, $retryTokens);
check('после переустановки право на ручной повтор появляется снова',
    (new B24('local.test.app', 'test-secret'))->manualInstallRetryUsedForCurrentGeneration() === false);

$indexRetrySource = (string)file_get_contents(APP_PATH . '/index.php');
check('контроллер не выполняет повтор, если он уже израсходован в этом поколении',
    strpos($indexRetrySource, '!$b24->manualInstallRetryUsedForCurrentGeneration()') !== false
    && strpos($indexRetrySource, '$b24->markManualInstallRetryUsed();') !== false);
check('израсходованный повтор убирает кнопку с экрана, а не оставляет её мёртвой',
    strpos($indexRetrySource, '$retryUsed = $b24->manualInstallRetryUsedForCurrentGeneration();') !== false
    && strpos($indexRetrySource, '$retryPossible = !$retryUsed') !== false);

// Разбор ящика во время работающей фоновой обработки не должен уничтожать
// событие. Целевая проверка 05.08.2026 воспроизвела потерю: тик кладёт событие
// в ящик, но подтверждает всю порцию скопом в конце, поэтому событие живёт
// одновременно в очереди и в ящике; ручной разбор в этом окне видел свой же eid
// в очереди, считал «уже возвращённым», вычёркивал из ящика — и общий ack потом
// убирал его из очереди. Событие исчезало отовсюду, а портал повторов не шлёт.
storeWrite(INBOX_FILE, [
    ['at' => date('c'), 'event' => 'ONCRMDEALADD', 'data' => ['ID' => 111], 'tries' => 2,
     'eid' => 'занято-тиком', 'taken' => time(), 'install_generation' => ''],
    ['at' => date('c'), 'event' => 'ONCRMDEALADD', 'data' => ['ID' => 222], 'tries' => 0,
     'eid' => 'след-оборванного-разбора', 'install_generation' => ''],
]);
storeWrite(DEADLETTER_FILE, [
    ['at' => date('c'), 'event' => 'ONCRMDEALADD', 'data' => ['ID' => 111], 'tries' => 3,
     'error' => 'обработчик падал', 'eid' => 'занято-тиком', 'install_generation' => ''],
    ['at' => date('c'), 'event' => 'ONCRMDEALADD', 'data' => ['ID' => 222], 'tries' => 3,
     'error' => 'обработчик падал', 'eid' => 'след-оборванного-разбора', 'install_generation' => ''],
    ['at' => date('c'), 'event' => 'ONCRMDEALADD', 'data' => ['ID' => 333], 'tries' => 3,
     'error' => 'обработчик падал', 'eid' => 'свободное', 'install_generation' => ''],
]);
queueReplayDead();
$raceBox   = array_column(array_filter(storeRead(DEADLETTER_FILE) ?? [], 'is_array'), 'eid');
$raceQueue = array_column(array_filter(storeRead(INBOX_FILE) ?? [], 'is_array'), 'eid');
check('разбор ящика не отбирает событие у работающей фоновой обработки',
    in_array('занято-тиком', $raceBox, true)          // осталось в ящике: тик его ещё держит
    && in_array('свободное', $raceQueue, true)        // свободное вернулось в очередь
    && !in_array('свободное', $raceBox, true)
    && !in_array('след-оборванного-разбора', $raceBox, true) // след прошлого разбора убран
    && count(array_keys($raceQueue, 'занято-тиком')) === 1,  // и не задвоилось в очереди
    'ящик: ' . implode(',', $raceBox) . ' | очередь: ' . implode(',', $raceQueue));

$workerSource = (string)file_get_contents(dirname(APP_PATH) . '/www/bin/process.php');
check('событие подтверждается сразу после укладки в ящик, а не в конце всей порции',
    (bool)preg_match('~queueDeadLetter\([^;]*\);\s*(//[^\n]*\n\s*)*queueAck\(\[\$eid\]\);~', $workerSource));
storeWrite(INBOX_FILE, []);
storeWrite(DEADLETTER_FILE, []);

// Адрес клиента за доверенным прокси: идём по цепочке справа налево и
// отбрасываем СВОИ хопы. Крайний справа валидный адрес брать нельзя — при двух
// хопах это адрес собственного балансировщика (все клиенты сливаются в один и
// общий потолок становится рубильником на всех), а при нестандартном хвосте
// разбор уезжает к значению, которое написал сам клиент.
$ipOld = $_SERVER;
$ipCases = [
    ['10.1.2.3',     '203.0.113.9, 10.1.2.3',  '203.0.113.9', 'два хопа: клиент и наш прокси'],
    ['10.1.2.3',     '1.2.3.4, [2001:db8::1]', '2001:db8::1', 'хвост в скобках IPv6'],
    ['10.1.2.3',     '203.0.113.9:5566',       '203.0.113.9', 'адрес с портом'],
    ['10.1.2.3',     '1.2.3.4, unknown',       '10.1.2.3',    'мусор в цепочке — не гадаем'],
    ['203.0.113.7',  '1.2.3.4',                '203.0.113.7', 'прокси не наш — заголовку не верим'],
];
$ipBad = [];
foreach ($ipCases as [$remote, $xff, $want, $why]) {
    // Правило разбора вынесено в чистую функцию именно ради этой проверки:
    // список доверенных прокси — константа окружения, и через суперглобалы
    // чужую топологию не воспроизвести.
    $got = apiResolveClientIp($remote, $xff, ['10.1.2.3']);
    if ($got !== $want) $ipBad[] = $why . ': ' . $got . ' вместо ' . $want;
}
$_SERVER = $ipOld;
check('адрес клиента за доверенным прокси определяется по цепочке, а не по краю',
    $ipBad === [], implode('; ', $ipBad));

// Опечатка в подкоманде не имеет права молча запустить полный боевой тик с
// записями в портал: человек набрал не то и обязан это увидеть.
$typoDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$typo = proc_open(['php', dirname(__DIR__) . '/www/bin/process.php', 'replay-dead'],
    $typoDesc, $typoPipes, dirname(__DIR__), [
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'), 'BXAPP_DATA_ROOT' => DATA_ROOT,
        'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
    ]);
$typoOut = ''; $typoCode = -1;
if (is_resource($typo)) {
    fclose($typoPipes[0]);
    $typoOut = (string)stream_get_contents($typoPipes[1]) . (string)stream_get_contents($typoPipes[2]);
    fclose($typoPipes[1]); fclose($typoPipes[2]);
    $typoCode = proc_close($typo);
}
check('неизвестная команда фоновой обработки отвечает отказом, а не запускает тик',
    $typoCode !== 0 && strpos($typoOut, 'Неизвестная команда') !== false,
    'код ' . $typoCode . ', вывод «' . trim($typoOut) . '»');

// ── Находки ревью 05.08.2026 ────────────────────────────────────────────────

// Событие, на котором процесс УМИРАЕТ (нехватка памяти, фатал, SIGKILL по
// таймауту хостинга), до catch в process.php не доходит — значит потолок попыток
// обязан стоять в самом queueTake. Иначе одно ядовитое событие забирает порцию
// за порцией бесконечно и заслоняет живую очередь, не попадая в ящик.
$poisonInbox = DATA_ROOT . '/inbox-poison.php';
$poisonDead  = DATA_ROOT . '/dead-poison.php';
storeWrite($poisonInbox, [[
    'eid' => 'poison', 'event' => 'ONCRMDEALUPDATE',
    'taken' => time() - QUEUE_LEASE_SEC - 10, 'tries' => QUEUE_MAX_TRIES,
]]);
$poisonBatch = queueTake(10, $poisonInbox);
$poisonLeft  = storeRead($poisonInbox) ?: [];
check('событие с исчерпанными попытками уходит в ящик, а не выдаётся снова',
    $poisonBatch === [] && $poisonLeft === [],
    'выдано ' . count($poisonBatch) . ', осталось в очереди ' . count($poisonLeft));

// Обратная сторона: событие, у которого попытки ещё есть, обязано работать.
storeWrite($poisonInbox, [
    ['eid' => 'fresh-one', 'event' => 'ONCRMDEALADD'],
    ['eid' => 'retry-one', 'event' => 'ONCRMDEALUPDATE',
     'taken' => time() - QUEUE_LEASE_SEC - 10, 'tries' => 1],
]);
$aliveBatch = queueTake(10, $poisonInbox);
check('события с непотраченными попытками выдаются в работу как прежде',
    count($aliveBatch) === 2, 'выдано ' . count($aliveBatch));
@unlink($poisonInbox); @unlink($poisonDead);

// Иерархия опознания атомарна: global проверяется до dynamic buckets, но hit
// получает лишь запрос, прошедший всю цепочку.
$orderSource = (string)file_get_contents(dirname(__DIR__) . '/www/index.php');
check('опознание использует атомарную цепочку global, address и token buckets',
    (bool)preg_match(
        '~\$identifyOk\s*=\s*apiRateAllowChain\(\[\s*\[\'identify-all\'.*?\[\'identify-ip:.*?\[\'identify-who:~s',
        $orderSource
    ),
    'атомарная иерархия лимитов в index.php пропала');

// Та же атомарная цепочка защищает редкое lifecycle-событие.
$lifecycleSource = (string)file_get_contents(dirname(__DIR__) . '/www/api/b24.php');
check('проверка событий жизненного цикла использует атомарную цепочку',
    (bool)preg_match('~return apiRateAllowChain\(\[\s*\[\'lifecycle-verify-all\'.*?\[\'lifecycle-verify-ip:~s',
        $lifecycleSource),
    'атомарная иерархия lifecycleVerifyAllowed() пропала');

// ── Подписка на события портала (блок от 05.08.2026) ────────────────────────

// Список объявляется в настройках и приводится к виду портала; пустой список
// означает ноль обращений к порталу — приложение событиями не пользуется.
require_once dirname(__DIR__) . '/www/api/events-bind.php';
check('пустой список событий не приводит к обращениям в портал',
    eventsSpec() === [] || defined('APP_EVENTS'),
    'eventsSpec() при пустом APP_EVENTS вернул ' . json_encode(eventsSpec()));

// Адрес приёмника подставляется сам: руками вписанный адрес — это тот самый
// случай, когда после переноса тест→бой события боевого портала улетали на стенд.
check('адрес приёмника событий берётся из адреса приложения',
    eventsHandlerUrl('https://example.test/app/') === 'https://example.test/app/api/event.php',
    eventsHandlerUrl('https://example.test/app/'));

// Отпечаток объявленного меняется при смене адреса приложения — иначе
// переустановка после переезда не переставила бы подписку.
$eventsPrintNow = eventsFingerprint();
check('отпечаток подписки существует и стабилен', $eventsPrintNow === eventsFingerprint(), $eventsPrintNow);

// Ответ портала «уже подписаны» — это успех, а не ошибка.
check('повторная подписка на то же событие считается успехом',
    eventsAlreadyBound(['error' => 'ERROR_EVENT_BINDING_EXISTS'])
    && !eventsAlreadyBound(['error' => 'ACCESS_DENIED']),
    'классификация ответа портала неверна');

// Модуль обязан быть описан в карте: иначе следующий разработчик его не найдёт
// и напишет подписку заново — ровно то, от чего блок и заводился.
$eventsMap = (string)@file_get_contents(dirname(__DIR__) . '/docs/MAP.md');
check('модуль подписки на события описан в карте проекта',
    strpos($eventsMap, 'events-bind.php') !== false, 'нет в docs/MAP.md');

// ── Справочники CRM человеческим языком (блок от 05.08.2026) ────────────────

require_once dirname(__DIR__) . '/www/api/crm-dict.php';

// Код стадии по правилу префикса воронки. У ПЕРВОЙ воронки префикса нет — именно
// на этом расходились 26 проектов, и в отчётах часть сделок не попадала ни в одну
// стадию. Проверяем все четыре случая, включая уже готовый код и пустой ввод.
$stageCases = [[0, 'NEW', 'NEW'], [5, 'NEW', 'C5:NEW'], [7, 'C7:WON', 'C7:WON'], [3, '   ', '']];
$stageBad = [];
foreach ($stageCases as [$cat, $in, $want]) {
    $got = crmDictStageCode($cat, $in);
    if ($got !== $want) $stageBad[] = "воронка $cat + «$in» → «$got», ждали «$want»";
}
check('код стадии собирается по правилу префикса воронки', $stageBad === [], implode('; ', $stageBad));

// Неизвестный код НЕ прячется: пустое место в отчёте хуже, чем непереведённый код —
// по коду хотя бы понятно, что искать.
$dictFixture = ['categories' => [2 => [5 => 'Розница']],
                'stages' => [2 => ['C5:NEW' => 'Договор подписан']],
                'fields' => [2 => ['UF_CRM_1' => 'Источник']], 'at' => time(), 'complete' => true];
check('неизвестный код стадии возвращается как есть, а не пустотой',
    crmDictStageName($dictFixture, 2, 'C9:LOSE') === 'C9:LOSE'
    && crmDictStageName($dictFixture, 2, 'C5:NEW') === 'Договор подписан',
    'перевод кодов работает неверно');

// Срок памяти зависит от полноты ответа: неполный живёт пять минут, иначе
// получасовой сбой портала застыл бы в приложении на сутки.
check('неполный справочник живёт минуты, полный — сутки',
    crmDictAgeLeft(['at' => time() - 3600, 'complete' => false]) === 0
    && crmDictAgeLeft(['at' => time() - 3600, 'complete' => true]) > 0,
    'сроки памяти справочника заданы неверно');

// Модуль обязан быть в карте и в каталоге готовых блоков: иначе его не найдут и
// напишут то же самое заново — ровно то, от чего блок и заводился.
$dictMap = (string)@file_get_contents(dirname(__DIR__) . '/docs/MAP.md');
$dictBlocks = (string)@file_get_contents(dirname(__DIR__) . '/docs/BLOCKS.md');
check('справочники CRM описаны в карте и в каталоге готовых блоков',
    strpos($dictMap, 'crm-dict.php') !== false && strpos($dictBlocks, 'crmDict') !== false,
    'нет в docs/MAP.md или docs/BLOCKS.md');

// ── Полная выборка для проектов со старым движком (блок от 05.08.2026) ──────

require_once dirname(__DIR__) . '/www/api/fetch-all-compat.php';

// Забираем ВСЕ страницы, а не первые 50 — ровно тот брак, что нашёлся в 44 проектах.
$fakePortal = static function (int $total, string $idField = 'ID', string $wrap = 'list') {
    return static function (string $m, array $p) use ($total, $idField, $wrap) {
        $after = (int)($p['filter']['>' . $idField] ?? 0);
        $rows = [];
        for ($id = $after + 1; $id <= $total && count($rows) < 50; $id++) $rows[] = [$idField => $id];
        return ['result' => $wrap === 'list' ? $rows : ['items' => $rows]];
    };
};
check('полная выборка забирает все страницы, а не первую',
    count(fetchAllCompat($fakePortal(120), 'crm.deal.list')) === 120,
    'забрали ' . count(fetchAllCompat($fakePortal(120), 'crm.deal.list')) . ' из 120');
check('понимает и список в result, и список в result.items',
    count(fetchAllCompat($fakePortal(120, 'id', 'items'), 'crm.item.list', [], 'id')) === 120, '');

// Неполная выборка НЕ отдаётся молча: тихо обрезанный список опаснее ошибки —
// его принимают за весь и снимают признак у «всех остальных» записей.
$stuck = static function (string $m, array $p) { return ['result' => array_fill(0, 50, ['ID' => 7])]; };
$stuckStopped = false;
try { fetchAllCompat($stuck, 'crm.deal.list'); } catch (RuntimeException $e) { $stuckStopped = true; }
$limitStopped = false;
try { fetchAllCompat($fakePortal(500), 'crm.deal.list', [], 'ID', 2); } catch (RuntimeException $e) { $limitStopped = true; }
$denyStopped = false;
try {
    fetchAllCompat(static fn(string $m, array $p) => ['error' => 'ACCESS_DENIED'], 'crm.deal.list');
} catch (RuntimeException $e) { $denyStopped = true; }
check('застрявший курсор, предел страниц и отказ портала не превращаются в обрезанный список',
    $stuckStopped && $limitStopped && $denyStopped,
    "курсор:$stuckStopped предел:$limitStopped отказ:$denyStopped");

// ── Предохранитель дорогого OAuth-запроса срабатывает ДО похода в сеть ────────
// Круг 2 ревью 06.08.2026. Счётчик попыток читался перед вызовом, а писался
// только после него — то есть через двадцать секунд обмена с oauth. При флуде на
// публичный handler-URL (ради которого предохранитель и заведён) все запросы
// приходят в одну секунду, ВСЕ видят «неудач: 0» и все уходят в дорогой
// исходящий запрос. Гонка не видна чтением: в один поток порядок выглядит верным.
//
// Проверяем именно ПОРЯДОК в исходнике, а не следствие: воспроизвести флуд к
// живому oauth в самопроверке нельзя, а порядок — ровно то, что сломается при
// следующей правке.
$b24Source = (string)file_get_contents(dirname(__DIR__) . '/www/api/b24.php');
$verifyStart = strpos($b24Source, 'public function verifyAndAdopt(');
$verifyBody = $verifyStart === false ? '' : substr($b24Source, $verifyStart, 6000);
$markPos    = strpos($verifyBody, 'updateMigrationVerifyThrottle($verifyIdentity, true, $window)');
$networkPos = strpos($verifyBody, '$this->doRefreshRequest(');
check('попытка verify отмечается до сетевого запроса к oauth',
    $markPos !== false && $networkPos !== false && $markPos < $networkPos,
    'отметка на ' . var_export($markPos, true) . ', сеть на ' . var_export($networkPos, true));

// Обратная сторона: отметка должна быть ОДНА на попытку. Прежний postfactum-счёт
// остался бы вторым инкрементом, и законный администратор упирался бы в запрет
// вдвое быстрее, чем написано в правиле «три неудачи подряд».
check('неудача не считается второй раз после сетевого вызова',
    substr_count($verifyBody, 'updateMigrationVerifyThrottle($verifyIdentity, true, $window)') === 1
    && strpos($verifyBody, '$markFail') === false,
    'инкрементов: ' . substr_count($verifyBody, 'updateMigrationVerifyThrottle($verifyIdentity, true, $window)'));

// И успешное усвоение обязано счётчик обнулять — иначе три законные установки
// подряд закрыли бы окно администратору, который всё делал правильно.
check('успешное усвоение обнуляет счётчик попыток',
    strpos($b24Source, 'updateMigrationVerifyThrottle($this->loaded, false)') !== false);

// ── Ручной разбор ящика не запирает приёмник событий ─────────────────────────
// Круг 2 ревью 06.08.2026. Ящик неразобранных копится месяцами, а разгребают его
// руками одной командой. Пока весь перенос шёл ОДНИМ storeUpdate, входящая
// очередь была заперта на всё время обхода; приёмник ждёт тот же замок пять
// секунд и, не дождавшись, отвечает порталу отказом — а портал повторов не шлёт.
// Живое событие терялось ровно в тот момент, когда чинили очередь.
//
// Рассуждением это не проверяется: запускаем ВТОРОЙ процесс и смотрим состояние
// со стороны — видно ли, что очередь пополняется порциями, и доживает ли до
// конца событие, вклиненное посреди переноса.
$raceDlq   = DATA_ROOT . '/test-replay-race-dead.php';
$raceInbox = DATA_ROOT . '/test-replay-race-inbox.php';
$raceTotal = 300;
$raceBox = [];
for ($i = 0; $i < $raceTotal; $i++) {
    $raceBox[] = ['at' => date('c'), 'event' => 'ONCRMDEALUPDATE',
        'data' => ['FIELDS' => ['ID' => $i, 'pad' => str_repeat('x', 200)]],
        'tries' => 3, 'error' => 'старое', 'eid' => 'race-' . $i, 'install_generation' => 'gen-R'];
}
storeWrite($raceDlq, $raceBox);
storeWrite($raceInbox, []);

$raceHelper = sys_get_temp_dir() . '/bxapp-replay-race-' . getmypid() . '.php';
file_put_contents($raceHelper, "<?php\nrequire " . var_export(dirname(__DIR__) . '/www/api/queue.php', true)
    . ";\necho queueReplayDead(" . var_export($raceDlq, true) . ", " . var_export($raceInbox, true) . ");\n");
$raceDescr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$racePipes = [];
$raceProc = proc_open(['php', $raceHelper], $raceDescr, $racePipes, null,
    ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')]);

$sawPartial = false;      // очередь пополнялась порциями, а не одним скачком в конце
$liveWritten = false;     // приёмник сумел вклиниться со своим событием
if (is_resource($raceProc)) {
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($raceProc);
        $now = count(storeRead($raceInbox) ?? []);
        if ($now > 0 && $now < $raceTotal) {
            $sawPartial = true;
            if (!$liveWritten) {
                // Ровно то, что делает приёмник события портала.
                storeUpdate($raceInbox, static function (array $queue): array {
                    $queue[] = ['at' => date('c'), 'event' => 'LIVE', 'data' => [],
                        'eid' => 'live-during-replay', 'install_generation' => 'gen-R'];
                    return $queue;
                });
                $liveWritten = true;
            }
        }
        if (!$status['running']) break;
        usleep(20000);
    }
    fclose($racePipes[1]);
    fclose($racePipes[2]);
    proc_close($raceProc);
}
@unlink($raceHelper);

$raceQueue = storeRead($raceInbox) ?? [];
$raceLeft  = storeRead($raceDlq) ?? [];
$raceEids  = [];
foreach ($raceQueue as $e) if (is_array($e)) $raceEids[(string)($e['eid'] ?? '')] = true;
check('ручной разбор ящика отпускает замок между порциями', $sawPartial && $liveWritten,
    'промежуточное состояние: ' . var_export($sawPartial, true) . ', приёмник вклинился: ' . var_export($liveWritten, true));
check('событие портала, пришедшее во время разбора, не потеряно',
    isset($raceEids['live-during-replay']),
    'в очереди ' . count($raceQueue) . ' событий');
check('порционный разбор переносит весь ящик и ничего в нём не оставляет',
    count($raceLeft) === 0 && count($raceQueue) === $raceTotal + ($liveWritten ? 1 : 0),
    'в ящике ' . count($raceLeft) . ', в очереди ' . count($raceQueue));

// Старые записи шаблона своей метки не имеют — она выводится из позиции в ящике.
// Порционный перенос эти позиции сдвигает, поэтому удаление идёт по отпечатку
// записи. Без этого второй порции «не находилось» бы, что удалять, и события
// возвращались бы в очередь ПОВТОРНО при каждом следующем разборе.
$legacyDlq   = DATA_ROOT . '/test-replay-legacy-dead.php';
$legacyInbox = DATA_ROOT . '/test-replay-legacy-inbox.php';
$legacyBox = [];
for ($i = 0; $i < QUEUE_REPLAY_PORTION * 2 + 3; $i++) {
    $legacyBox[] = ['at' => date('c'), 'event' => 'OLD', 'data' => ['ID' => $i], 'tries' => 3, 'error' => 'x'];
}
storeWrite($legacyDlq, $legacyBox);
storeWrite($legacyInbox, []);
$legacyMoved = queueReplayDead($legacyDlq, $legacyInbox);
check('записи без своей метки переносятся все и из ящика удаляются',
    $legacyMoved === count($legacyBox)
    && count(storeRead($legacyDlq) ?? []) === 0
    && count(storeRead($legacyInbox) ?? []) === count($legacyBox),
    'перенесено ' . $legacyMoved . ', в ящике ' . count(storeRead($legacyDlq) ?? []));
$legacyRepeat = queueReplayDead($legacyDlq, $legacyInbox);
check('повторный разбор пустого ящика ничего не дублирует',
    $legacyRepeat === 0 && count(storeRead($legacyInbox) ?? []) === count($legacyBox));

// ── Фоновый работник уступает установке приложения ───────────────────────────
// Круг 2 ревью 06.08.2026. Работник держит общий замок и берёт его снова и снова:
// очередь разбирается до 45 секунд, следом порция долгой задачи. Установка ждёт
// эксклюзивный замок 25 секунд, а flock отдаёт его читателю охотнее, чем
// ожидающему писателю — переустановка и удаление отваливались с «Приложение
// обновляется», хотя мешала им только фоновая работа.
//
// Чинится заявкой: тот, кто ждёт эксклюзив, оставляет свежую отметку, работник
// смотрит на неё между порциями. Проверяем ВТОРЫМ ПРОЦЕССОМ — рассуждением такое
// не проверяется.
appLifecycleForgetWriterWait();
check('в покое заявки на эксклюзивный замок нет', appLifecycleWriterWaiting() === false);

$waitHelper = sys_get_temp_dir() . '/bxapp-writer-wait-' . getmypid() . '.php';
file_put_contents($waitHelper, "<?php\nrequire " . var_export(dirname(__DIR__) . '/www/api/lib.php', true)
    . ";\ntry { appLifecycleAcquire(LOCK_EX, 8.0); echo 'GOT'; appLifecycleRelease(); }\n"
    . "catch (Throwable \$e) { echo 'FAIL:' . \$e->getMessage(); }\n");
$waitPipes = [];
appLifecycleAcquire(LOCK_SH, 5.0);          // работник занял общий замок
$waitProc = proc_open(['php', $waitHelper], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $waitPipes, null,
    ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')]);
$sawWaiting = false;
if (is_resource($waitProc)) {
    $until = microtime(true) + 5;
    while (microtime(true) < $until) {
        if (appLifecycleWriterWaiting()) { $sawWaiting = true; break; }
        usleep(30000);
    }
}
appLifecycleRelease();                       // уступаем — теперь установка пройдёт
$waitOut = '';
if (is_resource($waitProc)) {
    $waitOut = trim((string)stream_get_contents($waitPipes[1]));
    fclose($waitPipes[1]);
    fclose($waitPipes[2]);
    proc_close($waitProc);
}
@unlink($waitHelper);
check('работник видит, что установка ждёт эксклюзивный замок', $sawWaiting);
check('уступив общий замок, установка проходит', strpos($waitOut, 'GOT') === 0, $waitOut);
check('получив замок, установка снимает свою заявку', appLifecycleWriterWaiting() === false);

// ПРЕДОХРАНИТЕЛЬ ОБЯЗАН ОТПУСКАТЬ. Процесс, убитый в момент ожидания, оставит
// заявку в файле — без срока годности фоновая обработка встала бы навсегда, и
// починить это мог бы только человек с доступом к серверу.
appLifecycleAnnounceWriterWait();
check('свежая заявка видна', appLifecycleWriterWaiting() === true);
touch(appLifecycleWriterWaitFile(), time() - 3600);
clearstatcache(true, appLifecycleWriterWaitFile());
check('заявка убитого процесса протухает и работу не блокирует',
    appLifecycleWriterWaiting() === false);
appLifecycleForgetWriterWait();

// И обе долгие петли работника обязаны эту заявку спрашивать. Проверяем сам
// исходник: следующая правка цикла — ровно то место, где это теряется.
$processSourceYield = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
check('разбор очереди спрашивает заявку между порциями',
    preg_match('~drainInbox\(\).*?appLifecycleWriterWaiting\(\)~s', $processSourceYield) === 1);
check('долгая задача спрашивает заявку между порциями',
    preg_match('~taskStep\(.*?appLifecycleWriterWaiting\(\)~s', $processSourceYield) === 1);

// ── Переустановка поверх незавершённой уборки даёт экран, а не «Внутреннюю ошибку» ──
// Круг 2 ревью 06.08.2026. Прошлую установку удалили, а уборка не доделалась
// (файл не стёрся, диск был занят). Ставить приложение поверх нельзя — новая
// установка увидела бы остатки чужого портала как свои, — и на этот случай экран
// давно написан: «Приложение убирает за собой», 503 с Retry-After. Но install-ветка
// ловила только смену поколения и занятый барьер, а исключение уборки уходило в
// общий обработчик: администратор в момент переустановки видел «Внутреннюю
// ошибку» — повод звонить вместо «подождите минуту».
//
// Воспроизвести отказ уборки в самопроверке нельзя (тесты идут от root, а права
// на файл его не останавливают), поэтому проверяем ОБА перехвата по исходнику —
// именно они теряются при следующей правке ветки.
$indexSource = (string)file_get_contents(dirname(__DIR__) . '/www/index.php');
check('install-ветка ловит незавершённую уборку в обоих местах',
    substr_count($indexSource, 'catch (InstallCleanupIncompleteException $e)') === 2,
    'перехватов: ' . substr_count($indexSource, 'catch (InstallCleanupIncompleteException $e)'));
check('незавершённая уборка показывает свой экран, а не общий отказ',
    preg_match('~catch \(InstallCleanupIncompleteException \$e\).*?renderIndexInstallationFailure\(\'pending\'\)~s',
        $indexSource) === 1);

// Сам экран обязан существовать и просить подождать, а не «обратитесь в поддержку».
$pendingSpec = appInstallationFailureSpec('pending');
check('экран незавершённой уборки говорит человеку, что делать',
    $pendingSpec['status'] === 503 && $pendingSpec['retry_after'] > 0
    && strpos($pendingSpec['title'], 'убирает за собой') !== false,
    json_encode($pendingSpec, JSON_UNESCAPED_UNICODE));

// ── Подписка на события не переставляется на каждом открытии ─────────────────
// Круг 2 ревью 06.08.2026, две находки одного корня. Отпечаток подписки в шаблоне
// был посчитан, но нигде не использовался: каждое открытие приложения любым
// сотрудником слало порталу event.bind по всем событиям плюс общий event.get на
// перечитывание — и писало в аудит «подписались на события», хотя не менялось
// ничего. Настоящую перепривязку (сняли обработчик, сменился адрес) в этом
// потоке ложных записей было не разглядеть, а квота портала тратилась впустую.
require_once dirname(__DIR__) . '/www/api/events-bind.php';
storeWrite(STATE_FILE, []);
$eventCalls = [];
$GLOBALS['__b24_call'] = static function (string $method, array $params) use (&$eventCalls): array {
    $eventCalls[] = $method;
    if ($method === 'event.bind') return ['result' => true];
    if ($method === 'event.get') {
        $rows = [];
        foreach (eventsSpec() as $code) {
            $rows[] = ['event' => $code, 'handler' => eventsHandlerUrl(APP_URL)];
        }
        return ['result' => $rows];
    }
    return ['result' => []];
};
$firstBind = bindEvents(b24());
$callsFirst = count($eventCalls);
check('первая подписка действительно идёт в портал',
    $callsFirst > 0 && ($firstBind['bound'] ?? []) !== [], 'вызовов: ' . $callsFirst);

$eventCalls = [];
$secondBind = bindEvents(b24());
check('повторное открытие приложения в портал не ходит',
    $eventCalls === [] && !empty($secondBind['unchanged']),
    'вызовов: ' . count($eventCalls));
// Аудит идёт в общий журнал приложения, поэтому считаем не строки, а порядок в
// коде: выход по совпавшему отпечатку стоит ВЫШЕ записи аудита — значит повтор до
// неё не доходит. Ложные «подписались на события» на каждом открытии и были той
// самой находкой.
$eventsSource = (string)file_get_contents(dirname(__DIR__) . '/www/api/events-bind.php');
$unchangedPos = strpos($eventsSource, "'unchanged' => true");
$auditPos     = strpos($eventsSource, "auditLog('events.bind'");
check('повтор без изменений не доходит до записи в аудит',
    $unchangedPos !== false && $auditPos !== false && $unchangedPos < $auditPos,
    'выход на ' . var_export($unchangedPos, true) . ', аудит на ' . var_export($auditPos, true));

// Сменился адрес приложения — подписку надо ставить заново, отпечаток обязан это
// заметить сам. Иначе события уходили бы на прежний адрес, а приложение молчало.
$eventCalls = [];
storeUpdate(STATE_FILE, static function (array $s): array {
    $s['events_fingerprint'] = 'отпечаток-от-другого-адреса';
    return $s;
});
$afterMove = bindEvents(b24());
check('смена списка событий или адреса переставляет подписку',
    $eventCalls !== [] && ($afterMove['bound'] ?? []) !== [], 'вызовов: ' . count($eventCalls));

// Кнопка «Пересобрать» обязана работать и при совпавшем отпечатке: её жмут
// именно тогда, когда в портале что-то сняли руками.
$eventCalls = [];
$forced = bindEvents(b24(), true);
check('кнопка «Пересобрать» переставляет подписку даже без изменений',
    $eventCalls !== [] && ($forced['bound'] ?? []) !== [], 'вызовов: ' . count($eventCalls));

// Неудачная подписка НЕ фиксирует отпечаток — иначе следующей попытки не будет
// никогда и приложение молча останется без событий портала.
storeWrite(STATE_FILE, []);
$GLOBALS['__b24_call'] = static function (string $method, array $params): array {
    if ($method === 'event.bind') return ['error' => 'ACCESS_DENIED', 'error_description' => 'нет прав'];
    return ['result' => []];
};
bindEvents(b24());
$stateAfterFail = storeRead(STATE_FILE) ?? [];
check('неудачная подписка не фиксирует отпечаток и будет повторена',
    (string)($stateAfterFail['events_fingerprint'] ?? '') === '',
    json_encode($stateAfterFail, JSON_UNESCAPED_UNICODE));
unset($GLOBALS['__b24_call']);

// ── Документация не предлагает снести механику вместе с задачей ──────────────
// Круг 2 ревью 06.08.2026. README и карта называли местом для своей работы
// runJob() — а в ней, кроме заглушки, живут разбор очереди событий портала и
// порция долгой задачи. Заменив «тело cron-задачи» целиком (ровно так и написано
// было), проект молча оставался без разбора событий: портал шлёт, приложение
// отвечает «принято» и не делает ничего.
$workerSourceProject = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
// projectJob() зовётся ПОСЛЕ порции долгой задачи и внутри того же замка.
// Между ними может стоять другая механика тика (сверка пропусков, проверка
// обязательных методов портала) — важен ПОРЯДОК, а не расстояние в символах.
// Прежде здесь стояло окно `.{0,1200}`, и добавленная механика роняла проверку
// не потому, что порядок нарушен, а потому, что комментарий стал длиннее.
$posLock    = strpos($workerSourceProject, 'appWithMatchingInstallationReadLock(');
$posPending = strpos($workerSourceProject, "\n        runPendingTask();");
$posProject = strpos($workerSourceProject, "\n        projectJob();");
check('работа проекта живёт в отдельной функции, а не внутри механики тика',
    strpos($workerSourceProject, 'function projectJob(): void') !== false
    && $posLock !== false && $posPending !== false && $posProject !== false
    && $posLock < $posPending && $posPending < $posProject,
    'замок ' . var_export($posLock, true) . ', задача ' . var_export($posPending, true)
    . ', работа проекта ' . var_export($posProject, true));
$readmeText = (string)file_get_contents(dirname(__DIR__) . '/README.md');
$mapText    = (string)file_get_contents(dirname(__DIR__) . '/docs/MAP.md');
check('README и карта отправляют проект в projectJob(), а не в runJob()',
    strpos($readmeText, 'projectJob()') !== false && strpos($mapText, 'projectJob()') !== false
    && preg_match('~тело cron-задачи~i', $readmeText) !== 1,
    'README всё ещё предлагает заменить runJob целиком');

// ── После переустановки того же портала журнал остаётся на месте ─────────────
// Круг 2 ревью 06.08.2026. Журнал и аудит фильтруются по поколению установки —
// правильно: строки ЧУЖОГО портала администратору видеть нельзя. Но переустановка
// на том же портале тоже меняет поколение, и прежний журнал разом исчезал с
// экрана: расследовать «кому и когда выдали доступ» после переустановки было
// нечем, хотя портал тот же и данные те же.
// Поколение берём ТЕКУЩЕЕ с диска: журнал отвергает строки чужого поколения, и
// синтетическая метка просто не записалась бы — проверка была бы зелёной на нуле.
$sameA = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
appBindLogGeneration($sameA);
appLog('info', 'acl.add', ['кому' => 'Иванов'], ['kind' => 'action', 'uid' => 10]);
$sameB = appNewInstallGeneration();
$tokensSame = ['install_generation' => $sameB, 'prior_generations' => [$sameA]];
$visible = appInstallGenerationsOfThisPortal($tokensSame);
check('прежняя регистрация того же портала остаётся видимой',
    $visible === [$sameB, $sameA], implode(', ', $visible));
$rowsSame = journalQuery(['limit' => 50, 'install_generation' => $visible]);
$foundOld = false;
foreach ($rowsSame['rows'] as $r) {
    if ((string)($r['install_generation'] ?? '') === $sameA) $foundOld = true;
}
check('журнал прежней регистрации читается после переустановки', $foundOld,
    'строк: ' . count($rowsSame['rows']));

// А вот у ЧУЖОГО портала истории нет — и его строки остаются недоступны.
$otherPortal = appInstallGenerationsOfThisPortal(['install_generation' => appNewInstallGeneration()]);
$rowsOther = journalQuery(['limit' => 50, 'install_generation' => $otherPortal]);
$leaked = false;
foreach ($rowsOther['rows'] as $r) {
    if ((string)($r['install_generation'] ?? '') === $sameA) $leaked = true;
}
check('строки прежнего портала новой площадке не видны', !$leaked);

// История наследуется ТОЛЬКО при перерегистрации на той же площадке. Проверяем
// сам код публикации: при смене портала список поколений не должен переезжать.
$b24SourcePrior = (string)file_get_contents(dirname(__DIR__) . '/www/api/b24.php');
check('история поколений не наследуется при смене портала',
    preg_match('~if \(!\$resetPortalState && \$generationChanged && \$baseGeneration !== \'\'\)~', $b24SourcePrior) === 1);

// ── Таблица журнала: колонок в строке столько же, сколько заголовков ─────────
// Круг 2 ревью 06.08.2026. Над строками стояли три заголовка — «Когда · Что ·
// Подробности», — а вся запись жила в ОДНОЙ ячейке: браузер отдавал ей треть
// ширины, две колонки стояли пустыми, и таблица занимала половину экрана,
// обещая разбивку, которой не было.
$tplSource = (string)file_get_contents(dirname(__DIR__) . '/www/template.html');
$appSource = (string)file_get_contents(dirname(__DIR__) . '/www/js/app.js');
preg_match('~<table[^>]*id="log-table".*?<thead>(.*?)</thead>~s', $tplSource, $mHead);
$logHeaders = isset($mHead[1]) ? substr_count($mHead[1], '<th>') : 0;
// Строка журнала строится одним map() — берём его тело и считаем ячейки.
// Ищем саму строку журнала по её содержимому, а не по имени переменной:
// проект вправе показывать журнал страницами (тогда map идёт по срезу), и
// привязка к `d.rows` краснела на ровном месте.
preg_match('~level === .error.(.*?)\}\)\.join~s', $appSource, $mRow);
$logCells = isset($mRow[1]) ? preg_match_all('~<td[ >]~', $mRow[1]) : 0;
check('в строке журнала столько же ячеек, сколько заголовков в шапке',
    $logHeaders === 3 && $logCells === 3,
    'заголовков ' . $logHeaders . ', ячеек в строке ' . $logCells);
// Служебные строки («Записей нет», «журнал неполный») обязаны занимать всю
// ширину — иначе они прижимаются к первой колонке и выглядят обрезанными.
preg_match_all('~<tr><td(?: colspan="3")?><div class="state"~', $appSource, $mState);
preg_match_all('~<tr><td><div class="state"~', $appSource, $mStateNarrow);
check('служебные строки таблиц растянуты на все колонки',
    count($mStateNarrow[0]) === 0, 'узких служебных строк: ' . count($mStateNarrow[0]));

// ── Заблокированный в портале теряет пропуск, не дожидаясь восьми часов ──────
// Круг 2 ревью 06.08.2026. Пропуск живёт восемь часов и ни разу не сверялся с
// порталом: человека увольняют, администратор блокирует его в Битриксе — а
// открытая вкладка приложения работает почти весь оставшийся день.
storeWrite(SESSIONS_FILE, [
    ['token' => str_repeat('1', 64), 'uid' => 101, 'role' => 'admin', 'member_id' => 'MEMBER-1',
     'install_generation' => 'gen-block', 'createdAt' => time(), 'expiresAt' => time() + 3600],
    ['token' => str_repeat('2', 64), 'uid' => 102, 'role' => 'edit', 'member_id' => 'MEMBER-1',
     'install_generation' => 'gen-block', 'createdAt' => time(), 'expiresAt' => time() + 3600],
    ['token' => str_repeat('3', 64), 'uid' => 103, 'role' => 'view', 'member_id' => 'MEMBER-1',
     'install_generation' => 'gen-block', 'createdAt' => time() - 7200, 'expiresAt' => time() - 60],
]);
$askedUids = [];
$revokedCount = revokeSessionsOfBlockedUsers(static function (array $uids) use (&$askedUids): array {
    $askedUids = $uids;
    sort($askedUids);
    return [101 => true, 102 => false];      // 102 заблокирован в портале
});
$leftSessions = storeRead(SESSIONS_FILE) ?? [];
$leftUids = [];
foreach ($leftSessions as $r) if (is_array($r)) $leftUids[] = (int)($r['uid'] ?? 0);
sort($leftUids);
check('спрашиваем портал только про тех, у кого пропуск ещё живой',
    $askedUids === [101, 102], implode(', ', $askedUids));
check('пропуск заблокированного сотрудника снимается сразу',
    $revokedCount === 1 && !in_array(102, $leftUids, true) && in_array(101, $leftUids, true),
    'осталось: ' . implode(', ', $leftUids));

// ПРЕДОХРАНИТЕЛЬ. Молчание портала про сотрудника — это НЕ «уволен»: один сбой
// выборки иначе разлогинил бы всех разом, включая владельца, и починить это
// стало бы некому — в приложение никто не смог бы войти.
storeWrite(SESSIONS_FILE, [
    ['token' => str_repeat('4', 64), 'uid' => 201, 'role' => 'admin', 'member_id' => 'MEMBER-1',
     'install_generation' => 'gen-block', 'createdAt' => time(), 'expiresAt' => time() + 3600],
]);
check('портал не ответил — пропуска не трогаем',
    revokeSessionsOfBlockedUsers(static fn(array $uids): array => []) === 0
    && count(storeRead(SESSIONS_FILE) ?? []) === 1);
check('портал промолчал про конкретного сотрудника — его пропуск остаётся',
    revokeSessionsOfBlockedUsers(static fn(array $uids): array => [999 => false]) === 0
    && count(storeRead(SESSIONS_FILE) ?? []) === 1);

// И фон обязан это делать сам: уволенный в приложение не зайдёт, поэтому
// проверка «при следующем открытии» не сработала бы именно тогда, когда нужна.
$workerSessionsSrc = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
check('сверку пропусков делает фоновая обработка, а не открытие приложения',
    strpos($workerSessionsSrc, 'revokeBlockedUserSessions();') !== false
    && strpos($workerSessionsSrc, 'SESSION_RECHECK_SECONDS') !== false);
storeWrite(SESSIONS_FILE, []);

// ── Карантинные копии не переживают удаление и переезд ───────────────────────
// Круг 2 ревью 06.08.2026. Когда файл состояния не разбирается, storeQuarantine()
// откладывает его содержимое рядом — `<файл>.bad-<отпечаток>.php` — чтобы человек
// посмотрел, что там. Но это ТО ЖЕ состояние портала: настройки, список доступа,
// счётчики. Уборка их не трогала, и данные удалённого портала оставались на диске
// у следующей установки, а при переезде — у нового владельца.
storeWrite(SETTINGS_FILE, ['portal' => 'Q']);
$quarantined = storeQuarantine(SETTINGS_FILE, '{битый json прежнего портала}');
check('карантинная копия действительно создана и видна перечислением',
    is_file($quarantined) && in_array($quarantined, appQuarantineFiles(), true),
    $quarantined);

$qClient = new B24('local.test.app', 'secret');
$qClient->saveTokensFromInstall([
    'AUTH_ID' => 'q-acc', 'REFRESH_ID' => 'q-ref', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-q.invalid', 'member_id' => 'MEMBER-Q', 'PROTOCOL' => 'https',
    'APPLICATION_TOKEN' => 'q-token',
]);
$qGeneration = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
$qBad = storeQuarantine(SETTINGS_FILE, '{второй битый файл}');
appPurgeInstallState($qGeneration);
check('удаление приложения уносит карантинные копии с диска',
    !is_file($qBad) && appQuarantineFiles() === [],
    implode(', ', appQuarantineFiles()));

// Перенос на другой портал — та же беда: копия принадлежит ПРЕЖНЕЙ площадке.
$b24SourceQuarantine = (string)file_get_contents(dirname(__DIR__) . '/www/api/b24.php');
check('переезд на другой портал удаляет карантинные копии вместе с журналом',
    preg_match('~appJournalTransitionTargets\(\), appQuarantineFiles\(\)~', $b24SourceQuarantine) === 1);
$libSourceQuarantine = (string)file_get_contents(dirname(__DIR__) . '/www/api/lib.php');
check('смена портала из lib.php чистит карантин тем же способом',
    preg_match('~appJournalTransitionTargets\(\), appQuarantineFiles\(\)~', $libSourceQuarantine) === 1);

// ── Владелец фиксируется не одной попыткой ──────────────────────────────────
// Круг 2 ревью 06.08.2026. Владелец писался ровно один раз — в момент установки.
// Сбой портала в эту секунду (таймаут, 503) оставлял приложение вообще без
// владельца, а человек читал на экране «откройте под администратором, владелец
// запишется автоматически» — обещание, которого в коде не было: следующее
// открытие попытку не повторяло, и приложение навсегда оставалось доступным
// только администраторам портала.
$indexOwnerSrc = (string)file_get_contents(dirname(__DIR__) . '/www/index.php');
check('открытие приложения дописывает владельца, если его ещё нет',
    preg_match('~aclOwnerUid\(\) === 0\) \$recordOwnerAfterFence = true;~', $indexOwnerSrc) === 1);
check('повторная попытка идёт ПОСЛЕ забора установки, а не до него',
    strpos($indexOwnerSrc, 'holdIndexInstallation($b24)') !== false
    && strpos($indexOwnerSrc, 'holdIndexInstallation($b24)') < strpos($indexOwnerSrc, 'aclOwnerUid() === 0'));
// Обещание на экране и поведение должны совпадать — иначе текст снова начнёт врать.
check('текст на экране обещает ровно то, что делает код',
    strpos(ownerNotFixedNotice('портал не ответил'), 'откройте его под') !== false);

// ── Обязательный метод портала, которого нет: что именно останавливается ────
// Находка ревью 08.08.2026 (BXREV-005): недоступность обязательного метода жила
// только на экране «Состояние» — фоновая работа шла как ни в чём не бывало и
// падала на середине. Проверяем ЖИВЫМ запуском воркера, а не чтением кода: у
// предохранителя два вопроса, и второй важнее первого — кого он отсекает КРОМЕ
// нарушителя. Работа проекта не должна начаться; разбор очереди событий портала
// (в том числе удаление приложения) обязан идти дальше.
$generationCap = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'cap-access', 'refresh_token' => 'cap-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-CAP',
    'application_token' => 'cap-key', 'expires_at' => time() + 3600,
    'install_generation' => $generationCap,
]);
storeWrite(CAP_FILE, ['методы' => ['crm.item.list' => 'нет доступа'], 'at' => time()]);
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => [], 'eid' => 'cap-event',
    'install_generation' => $generationCap,
]]);
storeWrite(DEADLETTER_FILE, []);
testWipe(DATA_ROOT . '/' . LOG_DIR_NAME);
$runWorkerWithRequired = static function (array $required): array {
    $root = dirname(__DIR__);
    $code = 'define("APP_METHODS_REQUIRED", ' . var_export($required, true) . ');'
        . ' require ' . var_export($root . '/www/bin/process.php', true) . ';';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', '-r', $code], $desc, $pipes, $root, [
        'BXAPP_DATA_ROOT'     => DATA_ROOT,
        'BXAPP_OAUTH_URL'     => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID'     => 'local.test.app',
        'BXAPP_CLIENT_SECRET' => 'test-secret',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
    if (!is_resource($proc)) return [255, '', 'воркер не запущен'];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};
[$capRc, , $capErr] = $runWorkerWithRequired(['crm.item.list']);
$capLog = (string)@file_get_contents(journalFile());
check('НЕДОСТУПНЫЙ ОБЯЗАТЕЛЬНЫЙ МЕТОД ОСТАНАВЛИВАЕТ РАБОТУ ПРОЕКТА',
    strpos($capLog, 'ОСТАНОВЛЕНО: нет обязательных методов портала') !== false
    && strpos($capLog, 'NOOP: projectJob()') === false,
    'код ' . $capRc . ', stderr: ' . trim($capErr));
check('ПРЕДОХРАНИТЕЛЬ НЕ ОТСЕКАЕТ РАЗБОР СОБЫТИЙ ПОРТАЛА',
    (storeRead(INBOX_FILE) ?? []) === [],
    'в очереди осталось: ' . count(storeRead(INBOX_FILE) ?? []));

// Тот же воркер с доступным методом работает как обычно — иначе проверка выше
// доказывала бы лишь то, что воркер вообще ничего не делает.
storeWrite(CAP_FILE, ['методы' => ['crm.item.list' => 'ok'], 'at' => time()]);
testWipe(DATA_ROOT . '/' . LOG_DIR_NAME);
[$capOkRc, , $capOkErr] = $runWorkerWithRequired(['crm.item.list']);
$capOkLog = (string)@file_get_contents(journalFile());
check('доступный обязательный метод работу не останавливает',
    $capOkRc === 0 && strpos($capOkLog, 'NOOP: projectJob()') !== false
    && strpos($capOkLog, 'ОСТАНОВЛЕНО') === false,
    'код ' . $capOkRc . ', stderr: ' . trim($capOkErr));

// ── Код проекта — в своём файле, ядро остаётся обновляемым (ревью 23.09.2026, modulnost-2) ──
// Прежде настройки, работа тика, порция задачи и обработчик событий писались
// ПРЯМО в settings-schema.php и bin/process.php: первая же правка навсегда
// отрезала эти 1500 строк ядра от починок шаблона. Теперь ядро зовёт функции
// необязательного файла проекта www/api/project-hooks.php.
check('шаблон не везёт файл проекта project-hooks.php — иначе сверка ядра сочтёт его ядром',
    !is_file(dirname(__DIR__) . '/www/api/project-hooks.php'));
$hooksWorker = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
check('каждая точка расширения ядра зовёт функцию файла проекта',
    strpos($hooksWorker, "function_exists('projectCronJob')") !== false
    && strpos($hooksWorker, "function_exists('projectTaskStep')") !== false
    && strpos($hooksWorker, "function_exists('projectReconcileUnknownStep')") !== false
    && strpos($hooksWorker, "function_exists('projectHandleEvent')") !== false
    && strpos((string)file_get_contents(dirname(__DIR__) . '/www/api/settings-schema.php'),
        "function_exists('projectSettingsSchema')") !== false);
$hooksCopy = testRegisterTemp(testServerWwwCopy(dirname(__DIR__), [
    'api/project-hooks.php' => "<?php\ndeclare(strict_types=1);\n"
        . "if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? ''))) {\n"
        . "    http_response_code(404);\n    exit;\n}\n"
        . "function projectCronJob(): void { logline('HOOKS_MARK: работа проекта'); }\n"
        . "function projectHandleEvent(string \$code, array \$data, string \$generation): void {\n"
        . "    logline('HOOKS_MARK: событие ' . \$code);\n}\n",
], 'project-hooks'));
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => [], 'eid' => 'hooks-event',
    'install_generation' => $generationCap,
]]);
testWipe(DATA_ROOT . '/' . LOG_DIR_NAME);
$hooksCode = 'define("APP_METHODS_REQUIRED", ["crm.item.list"]);'
    . ' require ' . var_export($hooksCopy . '/www/bin/process.php', true) . ';';
$hooksProc = proc_open(['php', '-r', $hooksCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $hooksPipes, $hooksCopy, [
        'BXAPP_DATA_ROOT' => DATA_ROOT, 'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID' => 'local.test.app', 'BXAPP_CLIENT_SECRET' => 'test-secret',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
$hooksRc = 255; $hooksErr = 'воркер не запущен';
if (is_resource($hooksProc)) {
    fclose($hooksPipes[0]);
    stream_get_contents($hooksPipes[1]);
    $hooksErr = (string)stream_get_contents($hooksPipes[2]);
    fclose($hooksPipes[1]); fclose($hooksPipes[2]);
    $hooksRc = proc_close($hooksProc);
}
$hooksLog = (string)@file_get_contents(journalFile());
check('воркер зовёт работу проекта из project-hooks.php, а не заглушку',
    $hooksRc === 0 && strpos($hooksLog, 'HOOKS_MARK: работа проекта') !== false
    && strpos($hooksLog, 'NOOP: projectJob()') === false,
    'код ' . $hooksRc . ', stderr: ' . testDiagnosticText($hooksErr));
check('событие портала уходит в обработчик проекта из project-hooks.php',
    strpos($hooksLog, 'HOOKS_MARK: событие ONCRMDEALUPDATE') !== false
    && strpos($hooksLog, 'обработчик не задан') === false,
    'в журнале нет отметки обработчика проекта');

// ── Гонка разбора ящика и подтверждения события (BXREV-006) ─────────────────
// Находка ревью 08.08.2026. Последовательность из отчёта: копия события лежит
// и в ящике неразобранных, и во входящей очереди с живой арендой. Первый разбор
// ящика видит аренду и запись оставляет; воркер доделывает событие и убирает
// его из очереди; ВТОРОЙ разбор уже ничего не видит — и кладёт то же событие
// обратно. Бизнес-действие выполняется дважды, а портал повторов не присылал.
// Проверяем настоящими процессами: «php process.php replay» — это отдельный
// процесс, как у человека, который разгребает ящик руками.
$generationRace = appNewInstallGeneration();
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'race-access', 'refresh_token' => 'race-refresh',
    'domain' => 'portal.invalid', 'member_id' => 'MEMBER-RACE',
    'application_token' => 'race-key', 'expires_at' => time() + 3600,
    'install_generation' => $generationRace,
]);
@unlink(QUEUE_DONE_FILE);
$raceEid = 'race-eid-1';
$raceRecord = ['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 7],
    'tries' => QUEUE_MAX_TRIES, 'error' => 'обработчик падал', 'eid' => $raceEid,
    'install_generation' => $generationRace];
storeWrite(DEADLETTER_FILE, [$raceRecord]);
// Копия того же события во входящей очереди — В РАБОТЕ прямо сейчас.
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 7],
    'tries' => 0, 'eid' => $raceEid, 'taken' => time(),
    'install_generation' => $generationRace]]);
$runReplay = static function (): array {
    $root = dirname(__DIR__);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', $root . '/www/bin/process.php', 'replay'], $desc, $pipes, $root, [
        'BXAPP_DATA_ROOT'     => DATA_ROOT,
        'BXAPP_OAUTH_URL'     => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID'     => 'local.test.app',
        'BXAPP_CLIENT_SECRET' => 'test-secret',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
    if (!is_resource($proc)) return [255, '', 'replay не запущен'];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};
[$replay1Rc, , ] = $runReplay();
$boxAfter1 = storeRead(DEADLETTER_FILE) ?? [];
check('разбор ящика не трогает событие, которое сейчас в работе',
    count($boxAfter1) === 1, 'в ящике: ' . count($boxAfter1) . ', код ' . $replay1Rc);

// Воркер доделал событие и подтвердил его — ровно то, что и происходит между
// двумя разборами ящика.
queueMarkDone([$raceEid]);
queueAck([$raceEid]);
check('после подтверждения событие ушло из входящей очереди',
    (storeRead(INBOX_FILE) ?? []) === []);

[$replay2Rc, , ] = $runReplay();
$inboxAfter2 = storeRead(INBOX_FILE) ?? [];
$boxAfter2 = storeRead(DEADLETTER_FILE) ?? [];
check('УЖЕ ОБРАБОТАННОЕ СОБЫТИЕ НЕ ВОЗВРАЩАЕТСЯ В ОЧЕРЕДЬ ВТОРЫМ РАЗБОРОМ',
    $inboxAfter2 === [], 'в очереди оказалось: ' . count($inboxAfter2) . ', код ' . $replay2Rc);
check('и из ящика оно при этом убрано, а не осталось висеть',
    $boxAfter2 === [], 'в ящике осталось: ' . count($boxAfter2));

// Остаточная гонка 10.08.2026: done-реестр читался ДО inbox-lock. Поэтому replay
// успевал прочитать пустой реестр, ждал занятый inbox, а воркер тем временем
// записывал done и убирал eid. После ожидания replay работал по устаревшему
// снимку и снова ставил уже завершённое событие. Здесь родитель заранее держит
// общий finalize/replay mutex, запускает настоящий второй процесс replay, под
// тем же mutex завершает событие и только потом отпускает ребёнка. Старый replay,
// который mutex не брал, оставит запись в DLQ; новый увидит done и уберёт её без
// возврата во входящую очередь.
$mutexEid = 'race-finalize-replay';
storeWrite(DEADLETTER_FILE, [[
    'at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 71],
    'tries' => QUEUE_MAX_TRIES, 'error' => 'старый отказ', 'eid' => $mutexEid,
    'install_generation' => $generationRace,
]]);
storeWrite(INBOX_FILE, [[
    'at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 71],
    'tries' => 0, 'eid' => $mutexEid, 'taken' => time(),
    'install_generation' => $generationRace,
]]);
@unlink(QUEUE_DONE_FILE);
$mutexProc = null;
$mutexPipes = [];
withKeyLock('queue-finalize-replay', static function () use (&$mutexProc, &$mutexPipes, $mutexEid): void {
    $root = dirname(__DIR__);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $mutexProc = proc_open(['php', $root . '/www/bin/process.php', 'replay'], $desc, $mutexPipes, $root, [
        'BXAPP_DATA_ROOT'     => DATA_ROOT,
        'BXAPP_OAUTH_URL'     => 'http://127.0.0.1:9/oauth/token/',
        'BXAPP_CLIENT_ID'     => 'local.test.app',
        'BXAPP_CLIENT_SECRET' => 'test-secret',
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]);
    if (!is_resource($mutexProc)) return;
    fclose($mutexPipes[0]);
    // Даём дочернему процессу дойти до mutex; сам mutex остаётся у родителя.
    usleep(350000);
    queueMarkDone([$mutexEid]);
    queueAck([$mutexEid]);
}, 25.0);
$mutexRc = 255;
$mutexOut = '';
$mutexErr = 'процесс replay не запущен';
if (is_resource($mutexProc)) {
    $mutexOut = (string)stream_get_contents($mutexPipes[1]);
    $mutexErr = (string)stream_get_contents($mutexPipes[2]);
    fclose($mutexPipes[1]);
    fclose($mutexPipes[2]);
    $mutexRc = proc_close($mutexProc);
}
$mutexInbox = storeRead(INBOX_FILE) ?? [];
$mutexDlq = storeRead(DEADLETTER_FILE) ?? [];
$mutexDone = storeRead(QUEUE_DONE_FILE) ?? [];
check('FINALIZE И REPLAY ДЕЛЯТ ОДИН MUTEX И НЕ ВОЗВРАЩАЮТ УЖЕ ЗАКРЫТОЕ СОБЫТИЕ',
    $mutexRc === 0 && strpos($mutexOut, 'Возвращено в очередь: 0') !== false
    && $mutexInbox === [] && $mutexDlq === []
    && isset($mutexDone[$mutexEid]),
    'код ' . $mutexRc . ', moved=' . trim($mutexOut) . ', inbox=' . count($mutexInbox)
        . ', dlq=' . count($mutexDlq) . ', stderr=' . trim($mutexErr));
// Следующий сценарий намеренно переиспользует прежний eid: вернём его done-
// метку после изолированного mutex-fixture, чтобы проверять именно crash-window.
queueMarkDone([$raceEid]);

// ПРЕДОХРАНИТЕЛЬ НЕ ДОЛЖЕН ОТСЕКАТЬ ЛИШНЕГО: событие, которое честно не
// обрабатывалось, разбор ящика обязан вернуть как прежде.
$freshEid = 'race-eid-2';
storeWrite(DEADLETTER_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALADD', 'data' => ['ID' => 8],
    'tries' => QUEUE_MAX_TRIES, 'error' => 'портал не ответил', 'eid' => $freshEid,
    'install_generation' => $generationRace]]);
storeWrite(INBOX_FILE, []);
[$replay3Rc, , ] = $runReplay();
$inboxAfter3 = storeRead(INBOX_FILE) ?? [];
check('необработанное событие разбор ящика возвращает как и раньше',
    count($inboxAfter3) === 1 && (string)($inboxAfter3[0]['eid'] ?? '') === $freshEid,
    'в очереди: ' . count($inboxAfter3) . ', код ' . $replay3Rc);

// Второй путь того же дефекта: процесс умер между обработкой и подтверждением.
// Аренда истекает, событие возвращается в работу — и без реестра выполняется
// второй раз. Здесь оно обязано быть подтверждено БЕЗ повторного исполнения.
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 7],
    'tries' => 0, 'eid' => $raceEid, 'install_generation' => $generationRace]]);
storeWrite(DEADLETTER_FILE, []);
testWipe(DATA_ROOT . '/' . LOG_DIR_NAME);
[$deadRc, , $deadErr] = $runWorker();
$deadLog = (string)@file_get_contents(journalFile());
check('ВЕРНУВШЕЕСЯ ПОСЛЕ ПАДЕНИЯ СОБЫТИЕ НЕ ИСПОЛНЯЕТСЯ ПОВТОРНО',
    strpos($deadLog, 'event.already_done') !== false && (storeRead(INBOX_FILE) ?? []) === [],
    'код ' . $deadRc . ', в очереди: ' . count(storeRead(INBOX_FILE) ?? []) . ', stderr: ' . trim($deadErr));

// BX11-01: terminal-marker обязан переживать незавершённую финализацию. Иначе
// остаток той же записи в DLQ после TTL/вытеснения снова запускает бизнес-эффект.
$terminalRecord = static function (string $eid, int $id) use ($generationRace): array {
    return ['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => $id],
        'tries' => QUEUE_MAX_TRIES, 'error' => 'аварийный остаток', 'eid' => $eid,
        'install_generation' => $generationRace];
};

$finalizeResidueEid = 'terminal-residue-finalize';
storeWrite(DEADLETTER_FILE, [$terminalRecord($finalizeResidueEid, 71)]);
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 71],
    'tries' => 0, 'eid' => $finalizeResidueEid, 'install_generation' => $generationRace]]);
queueFinalizeDone([$finalizeResidueEid]);
check('ТЕРМИНАЛЬНАЯ ФИНАЛИЗАЦИЯ УБИРАЕТ ОСТАТОК ТОГО ЖЕ EID ИЗ DLQ',
    (storeRead(INBOX_FILE) ?? []) === [] && (storeRead(DEADLETTER_FILE) ?? []) === []
        && queueIsDone($finalizeResidueEid),
    'inbox=' . count(storeRead(INBOX_FILE) ?? []) . ', dlq=' . count(storeRead(DEADLETTER_FILE) ?? []));

$ttlResidueEid = 'terminal-residue-ttl';
storeWrite(DEADLETTER_FILE, [$terminalRecord($ttlResidueEid, 72)]);
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 72],
    'tries' => 0, 'eid' => $ttlResidueEid, 'install_generation' => $generationRace]]);
queueMarkDone([$ttlResidueEid]);
queueAck([$ttlResidueEid], []);
storeUpdate(queueDoneFile(), static function (array $done) use ($ttlResidueEid): array {
    $old = time() - QUEUE_DONE_TTL - 10;
    if (is_array($done[$ttlResidueEid] ?? null)) $done[$ttlResidueEid]['at'] = $old;
    else $done[$ttlResidueEid] = $old;
    return $done;
});
$ttlReplay = queueReplayDead();
check('CRASH-ОСТАТОК НЕ ВОЗВРАЩАЕТСЯ В РАБОТУ ПОСЛЕ TTL',
    $ttlReplay === 0 && (storeRead(INBOX_FILE) ?? []) === []
        && (storeRead(DEADLETTER_FILE) ?? []) === [],
    'moved=' . $ttlReplay . ', inbox=' . count(storeRead(INBOX_FILE) ?? [])
        . ', dlq=' . count(storeRead(DEADLETTER_FILE) ?? []));

$evictResidueEid = 'terminal-residue-eviction';
storeWrite(DEADLETTER_FILE, [$terminalRecord($evictResidueEid, 73)]);
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 73],
    'tries' => 0, 'eid' => $evictResidueEid, 'install_generation' => $generationRace]]);
queueMarkDone([$evictResidueEid]);
queueAck([$evictResidueEid], []);
$evictionDone = storeRead(queueDoneFile()) ?? [];
$evictionMarker = $evictionDone[$evictResidueEid] ?? null;
$oldestTs = time() - 10000;
if (is_array($evictionMarker)) $evictionMarker['at'] = $oldestTs;
else $evictionMarker = $oldestTs;
$baseTs = time() - QUEUE_DONE_MAX;
for ($i = 0; $i < QUEUE_DONE_MAX; $i++) $evictionDone['eviction-fill-' . $i] = $baseTs + $i;
$evictionDone[$evictResidueEid] = $evictionMarker;
storeWrite(queueDoneFile(), $evictionDone);
queueMarkDone(['eviction-prune-trigger']);
$afterEviction = storeRead(queueDoneFile()) ?? [];
$evictionReplay = queueReplayDead();
check('НЕЗАВЕРШЁННАЯ ТЕРМИНАЛЬНАЯ МЕТКА НЕ ВЫТЕСНЯЕТСЯ ИЗ DONE-РЕЕСТРА',
    array_key_exists($evictResidueEid, $afterEviction) && $evictionReplay === 0
        && (storeRead(INBOX_FILE) ?? []) === [] && (storeRead(DEADLETTER_FILE) ?? []) === [],
    'marker=' . (array_key_exists($evictResidueEid, $afterEviction) ? 'yes' : 'no')
        . ', moved=' . $evictionReplay . ', done=' . count($afterEviction));

// FAILPOINT: процесс погиб после удаления обоих replay-источников, но ровно до
// queueSettleDone(). Следующая штатная финализация обязана доказать отсутствие
// eid под тем же mutex, сжать closing и вернуть ему обычные TTL/MAX-границы.
$settleGapEid = 'terminal-settle-gap';
storeWrite(DEADLETTER_FILE, [$terminalRecord($settleGapEid, 74)]);
storeWrite(INBOX_FILE, [['at' => date('c'), 'event' => 'ONCRMDEALUPDATE', 'data' => ['ID' => 74],
    'tries' => 0, 'eid' => $settleGapEid, 'install_generation' => $generationRace]]);
withKeyLock('queue-finalize-replay', static function () use ($settleGapEid): void {
    queueMarkDone([$settleGapEid]);
    queueForgetDead([$settleGapEid]);
    queueAck([$settleGapEid], []);
    // failpoint: queueSettleDone([$settleGapEid]) намеренно не вызван.
}, 25.0);
$gapBeforeRecovery = storeRead(queueDoneFile()) ?? [];
queueFinalizeDone(['terminal-recovery-trigger']);
$gapAfterRecovery = storeRead(queueDoneFile()) ?? [];
check('CRASH ПОСЛЕ CLEANUP ДО SETTLE НЕ ОСТАВЛЯЕТ ВЕЧНУЮ CLOSING-МЕТКУ',
    is_array($gapBeforeRecovery[$settleGapEid] ?? null)
        && is_int($gapAfterRecovery[$settleGapEid] ?? null),
    'до=' . gettype($gapBeforeRecovery[$settleGapEid] ?? null)
        . ', после=' . gettype($gapAfterRecovery[$settleGapEid] ?? null));
storeUpdate(queueDoneFile(), static function (array $done) use ($settleGapEid): array {
    $done[$settleGapEid] = time() - QUEUE_DONE_TTL - 10;
    return $done;
});
queueFinalizeDone(['terminal-recovery-prune-trigger']);
$gapAfterPrune = storeRead(queueDoneFile()) ?? [];
check('ВОССТАНОВЛЕННАЯ CLOSING-МЕТКА СНОВА ОГРАНИЧЕНА TTL И MAX',
    !array_key_exists($settleGapEid, $gapAfterPrune)
        && count($gapAfterPrune) <= QUEUE_DONE_MAX,
    'marker=' . (array_key_exists($settleGapEid, $gapAfterPrune) ? 'yes' : 'no')
        . ', done=' . count($gapAfterPrune));

storeWrite(INBOX_FILE, []);
storeWrite(DEADLETTER_FILE, []);
@unlink(queueDoneFile());

// ── Перевод сотрудника из разрешённого отдела отзывает права (BXREV-007) ────
// Находка ревью 08.08.2026. Права выдают отделами, а состав отдела — состояние
// портала, которое приложение видело один раз: в момент выдачи пропуска. Снятие
// самой привязки отдела учитывалось сразу, а вот перевод человека ИЗ отдела —
// нет: доступ оставался до конца восьмичасового пропуска.
aclUpdate(static function (array $acl): array {
    $acl['owner_uid'] = 10;
    $acl['users'] = [['uid' => 305, 'role' => 'view', 'name' => 'Персональный доступ']];
    $acl['depts'] = [['id' => 5, 'role' => 'edit', 'name' => 'Отдел продаж']];
    return $acl;
});
storeWrite(SESSIONS_FILE, [
    // Доступ только по отделу 5 — после перевода оснований не остаётся.
    ['token' => str_repeat('a', 64), 'uid' => 301, 'role' => 'edit', 'departments' => [5],
     'member_id' => 'MEMBER-1', 'install_generation' => 'gen-dep',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
    // Есть персональная запись — пропуск обязан выжить.
    ['token' => str_repeat('b', 64), 'uid' => 305, 'role' => 'edit', 'departments' => [5],
     'member_id' => 'MEMBER-1', 'install_generation' => 'gen-dep',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
    // Про этого сотрудника портал промолчит — трогать его нельзя.
    ['token' => str_repeat('c', 64), 'uid' => 309, 'role' => 'edit', 'departments' => [5],
     'member_id' => 'MEMBER-1', 'install_generation' => 'gen-dep',
     'createdAt' => time(), 'expiresAt' => time() + 3600],
]);
$snyato = sessionApplyPortalDepartments([301 => [9], 305 => [9]]);
$poslePerevoda = storeRead(SESSIONS_FILE) ?? [];
$uidyPosle = [];
foreach ($poslePerevoda as $r) if (is_array($r)) $uidyPosle[(int)($r['uid'] ?? 0)] = $r;
check('ПЕРЕВОД ИЗ РАЗРЕШЁННОГО ОТДЕЛА СНИМАЕТ ПРОПУСК СРАЗУ',
    $snyato === 1 && !isset($uidyPosle[301]),
    'снято ' . $snyato . ', остались: ' . implode(', ', array_keys($uidyPosle)));
check('у кого есть персональный доступ — пропуск остаётся, но роль пересчитана',
    isset($uidyPosle[305]) && (string)($uidyPosle[305]['role'] ?? '') === 'view'
    && ($uidyPosle[305]['departments'] ?? []) === [9],
    json_encode($uidyPosle[305] ?? [], JSON_UNESCAPED_UNICODE));
check('ПРО КОГО ПОРТАЛ ПРОМОЛЧАЛ — ТОГО НЕ ТРОГАЕМ',
    isset($uidyPosle[309]) && ($uidyPosle[309]['departments'] ?? []) === [5],
    json_encode($uidyPosle[309] ?? [], JSON_UNESCAPED_UNICODE));
check('пустой ответ портала не гасит вообще никого',
    sessionApplyPortalDepartments([]) === 0 && count(storeRead(SESSIONS_FILE) ?? []) === 2);
// Перевод В разрешённый отдел не должен ничего ломать: человек просто получает
// доступ по новому основанию.
$vhod = sessionApplyPortalDepartments([309 => [5, 7]]);
$posleDobavki = storeRead(SESSIONS_FILE) ?? [];
$dep309 = [];
foreach ($posleDobavki as $r) if (is_array($r) && (int)($r['uid'] ?? 0) === 309) $dep309 = $r['departments'] ?? [];
check('добавление отдела пропуск не гасит, а обновляет состав',
    $vhod === 0 && $dep309 === [5, 7], implode(', ', $dep309));
// Воркер обязан спрашивать отделы тем же ответом портала, что и активность —
// иначе проверка живёт только в тесте, а в бою её никто не зовёт.
$workerDeptSrc = (string)file_get_contents(dirname(__DIR__) . '/www/bin/process.php');
check('фоновая проверка берёт отделы из того же ответа user.get',
    strpos($workerDeptSrc, "array_key_exists('UF_DEPARTMENT', \$u)") !== false
    && strpos($workerDeptSrc, 'sessionApplyPortalDepartments($otdely)') !== false);

// ── Пустой список мест не отменяет перепривязку событий (BXREV-010) ────────
// Находка ревью 08.08.2026: при нуле мест в портале запрос обрывался ДО
// перепривязки событий, а кнопка в интерфейсе пряталась по тому же признаку.
// Приложение без пунктов меню, но с событиями, теряло единственный способ
// вернуть отвалившуюся подписку — включая служебную «приложение удалили».
$bindSrc = (string)file_get_contents(dirname(__DIR__) . '/www/api/bind.php');
check('перепривязка событий выполняется и при нуле мест в портале',
    preg_match('~\$res = \$spec === \[\]\s*\?\s*\[.*?\]\s*:\s*bindPlacements~s', $bindSrc) === 1
    && strpos($bindSrc, 'bindEvents($b24, true)') !== false
    && strpos($bindSrc, "apiOk([\n        'bound'  => [],") === false);
// Порядок важнее текста: bindEvents обязан стоять ДО единственного apiOk.
$posEvents = strpos($bindSrc, 'bindEvents($b24, true)');
$posOk = strpos($bindSrc, 'apiOk([');
check('ответ отдаётся ПОСЛЕ перепривязки событий, а не вместо неё',
    $posEvents !== false && $posOk !== false && $posEvents < $posOk,
    'события ' . var_export($posEvents, true) . ', ответ ' . var_export($posOk, true));
$appJsBind = (string)file_get_contents(dirname(__DIR__) . '/www/js/app.js');
// Кнопка живёт в карточке «Служебное» внутри настроек (раздел «Состояние» убран
// 26.08.2026). Требование прежнее: она обязана появляться и ради одних только
// событий, а не только ради мест в портале — иначе отвалившуюся подписку нечем
// вернуть, и приложение молча перестаёт получать события портала.
//
// Проверяем не имя промежуточной переменной, а сам гейт перед созданием кнопки:
// условие обязано учитывать места, события и состояние cleanup. Точный живой
// сценарий «нулевые placements + одно событие» исполняет settings-live.js.
$gateKnopkiPeresborki = preg_match(
    '~var\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*([^;]+);\s*'
    . 'if\s*\(\s*!\1\s*\)\s*\{.*?\breturn\s*;\s*\}\s*'
    . 'var\s+rb\s*=\s*document\.createElement\(\s*[\'\"]button[\'\"]\s*\)~s',
    $appJsBind,
    $gateKnopkiMatch
);
$uslovieKnopki = $gateKnopkiPeresborki === 1
    ? (string)preg_replace('~\s+~', '', (string)($gateKnopkiMatch[2] ?? ''))
    : '';
check('кнопка «Пересобрать» не прячется, когда есть что пересобирать в событиях',
    $gateKnopkiPeresborki === 1
    && $uslovieKnopki === 'mesta.length>0||obyavlenoSobytij>0||uborka!==true'
    && strpos($appJsBind, "ui.api('POST', 'api/bind.php')") !== false,
    $uslovieKnopki !== '' ? 'условие: ' . $uslovieKnopki : 'гейт кнопки не найден');
check('подсказка о пропавшей подписке зовёт нажать кнопку, а не переустанавливать приложение',
    strpos((string)file_get_contents(dirname(__DIR__) . '/www/api/health.php'),
        'Нажмите «Пересобрать привязки» на этом экране') !== false);

// ── Канонический security/correctness checkpoint 22.08.2026 ────────────────
// Все пробы изолированы в disposable DATA_ROOT, не ходят в сеть и используют
// только синтетические значения. Вывод дочерних процессов содержит лишь
// булевы результаты и коды typed-отказов, но не сами credentials.
$securityFixRoot = DATA_ROOT . '/security-fix-regression';
mkdir($securityFixRoot, 0700, true);
$securityFixRun = static function (array $command, array $extraEnv = [], string $stdin = '') use ($root): array {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = ['PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'), 'LANG' => 'C.UTF-8'] + $extraEnv;
    $proc = proc_open($command, $desc, $pipes, $root, $env);
    if (!is_resource($proc)) return ['rc' => 255, 'out' => '', 'err' => 'process'];
    if ($stdin !== '') fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['rc' => proc_close($proc), 'out' => $out, 'err' => $err];
};
$privateHeader = "<?php http_response_code(404); exit; /* приватный файл bx-app */ ?>\n";
$legacyPrivateHeader = "<?php exit; /* private bx-app rollback bridge */ ?>\n";

$bootstrapProbe = static function (
    string $name,
    ?string $creds,
    ?string $state,
    string $appUrlOverride = ''
) use (
    $root, $securityFixRoot, $securityFixRun
): array {
    $dir = $securityFixRoot . '/bootstrap-' . $name;
    if (is_dir($dir)) testWipe($dir, true);
    mkdir($dir, 0700, true);
    $credsFile = $dir . '/creds.php';
    $stateFile = $dir . '/state.php';
    if ($creds !== null) file_put_contents($credsFile, $creds);
    if ($state !== null) file_put_contents($stateFile, $state);
    $envFile = $root . '/www/env.php';
    if ($appUrlOverride !== '') {
        $envSource = (string)file_get_contents($envFile);
        $envSource = (string)preg_replace(
            "~define\\('APP_URL_OVERRIDE',\\s*''\\);~",
            "define('APP_URL_OVERRIDE', " . var_export($appUrlOverride, true) . ');',
            $envSource,
            1
        );
        $envFile = $dir . '/env.php';
        file_put_contents($envFile, $envSource);
    }
    $beforeCreds = is_file($credsFile) ? hash_file('sha256', $credsFile) : '';
    $beforeState = is_file($stateFile) ? hash_file('sha256', $stateFile) : '';
    $code = '$_SERVER=["HTTP_HOST"=>"fallback.example.test","HTTPS"=>"on",'
        . '"SCRIPT_NAME"=>"/app/index.php"];try{require '
        . var_export($envFile, true) . ';echo json_encode(['
        . '"ok"=>true,"id"=>B24_CLIENT_ID,"url"=>APP_URL]);}catch(Throwable $e){'
        . 'echo json_encode(["ok"=>false,"class"=>get_class($e),"code"=>$e->getCode()]);}';
    $run = $securityFixRun([PHP_BINARY, '-r', $code], [
        'BXAPP_DATA_ROOT' => $dir,
        'BXAPP_CREDS_FILE' => $credsFile,
    ]);
    $json = json_decode($run['out'], true);
    return $run + [
        'json' => is_array($json) ? $json : [],
        'creds_preserved' => $beforeCreds === '' || hash_file('sha256', $credsFile) === $beforeCreds,
        'state_preserved' => $beforeState === '' || hash_file('sha256', $stateFile) === $beforeState,
    ];
};

$validCreds = $privateHeader . json_encode([
    'client_id' => 'local.synthetic.valid', 'client_secret' => 'synthetic-secret',
], JSON_UNESCAPED_SLASHES);
$validState = $privateHeader . json_encode(['app_url' => 'https://stored.example.test/app']);
$missingBootstrap = $bootstrapProbe('missing', null, null);
$validCredsBootstrap = $bootstrapProbe('valid-creds', $validCreds, null);
$validLegacyCredsBootstrap = $bootstrapProbe('valid-legacy-creds',
    $legacyPrivateHeader . json_encode([
        'client_id' => 'local.synthetic.legacy', 'client_secret' => 'synthetic-secret',
    ], JSON_UNESCAPED_SLASHES), null);
$validStateBootstrap = $bootstrapProbe('valid-state', null, $validState);
$validLegacyStateBootstrap = $bootstrapProbe('valid-legacy-state', null,
    $legacyPrivateHeader . json_encode(['app_url' => 'https://legacy.example.test/app']));
$badCredsJson = $bootstrapProbe('bad-creds-json', $privateHeader . '{', null);
$badCredsHeader = $bootstrapProbe('bad-creds-header', "<?php exit; ?>\n{}", null);
$badCredsType = $bootstrapProbe('bad-creds-type', $privateHeader
    . json_encode(['client_id' => ['not-a-string']]), null);
$badStateJson = $bootstrapProbe('bad-state-json', null, $privateHeader . '{');
$badStateHeader = $bootstrapProbe('bad-state-header', null, "<?php exit; ?>\n{}");
$badStateHeaderWithOverride = $bootstrapProbe('bad-state-header-override', null,
    "<?php exit; ?>\n{}", 'https://trusted-override.example.test/app');
$badStateType = $bootstrapProbe('bad-state-type', null, $privateHeader
    . json_encode(['app_url' => ['not-a-string']]));
$oversizedState = $bootstrapProbe('oversized-state', null,
    $privateHeader . str_repeat('x', 2 * 1024 * 1024 + 1));
check('COR-04 missing/valid bootstrap работает, present-invalid state/creds fail-closed',
    $missingBootstrap['rc'] === 0 && !empty($missingBootstrap['json']['ok'])
    && ($missingBootstrap['json']['url'] ?? '') === 'https://fallback.example.test/app'
    && $validCredsBootstrap['rc'] === 0 && !empty($validCredsBootstrap['json']['ok'])
    && ($validCredsBootstrap['json']['id'] ?? '') === 'local.synthetic.valid'
    && $validLegacyCredsBootstrap['rc'] === 0 && !empty($validLegacyCredsBootstrap['json']['ok'])
    && ($validLegacyCredsBootstrap['json']['id'] ?? '') === 'local.synthetic.legacy'
    && $validStateBootstrap['rc'] === 0 && !empty($validStateBootstrap['json']['ok'])
    && ($validStateBootstrap['json']['url'] ?? '') === 'https://stored.example.test/app'
    && $validLegacyStateBootstrap['rc'] === 0 && !empty($validLegacyStateBootstrap['json']['ok'])
    && ($validLegacyStateBootstrap['json']['url'] ?? '') === 'https://legacy.example.test/app'
    && ($badCredsJson['json']['code'] ?? 0) === 1714
    && ($badCredsHeader['json']['code'] ?? 0) === 1712
    && ($badCredsType['json']['code'] ?? 0) === 1714
    && ($badStateJson['json']['code'] ?? 0) === 1705
    && ($badStateHeader['json']['code'] ?? 0) === 1703
    && ($badStateHeaderWithOverride['json']['code'] ?? 0) === 1703
    && ($badStateType['json']['code'] ?? 0) === 1705
    && ($oversizedState['json']['code'] ?? 0) === 1701
    && $badCredsJson['creds_preserved'] && $badCredsHeader['creds_preserved']
    && $badCredsType['creds_preserved'] && $badStateJson['state_preserved']
    && $badStateHeader['state_preserved'] && $badStateHeaderWithOverride['state_preserved']
    && $badStateType['state_preserved']
    && $oversizedState['state_preserved'],
    json_encode([
        'missing' => $missingBootstrap['json'], 'valid_creds' => $validCredsBootstrap['json'],
        'valid_legacy_creds' => $validLegacyCredsBootstrap['json'],
        'valid_state' => $validStateBootstrap['json'], 'bad_codes' => [
            $badCredsJson['json']['code'] ?? 0, $badCredsHeader['json']['code'] ?? 0,
            $badCredsType['json']['code'] ?? 0, $badStateJson['json']['code'] ?? 0,
            $badStateHeader['json']['code'] ?? 0, $badStateType['json']['code'] ?? 0,
            $oversizedState['json']['code'] ?? 0,
        ],
    ]));

$credsSized = static function (int $bytes) use ($privateHeader): string {
    $base = $privateHeader . json_encode([
        'client_id' => 'local.synthetic.boundary', 'client_secret' => '',
    ], JSON_UNESCAPED_SLASHES);
    $pad = $bytes - strlen($base);
    if ($pad < 0) throw new RuntimeException('creds boundary fixture too small');
    $payload = $privateHeader . json_encode([
        'client_id' => 'local.synthetic.boundary', 'client_secret' => str_repeat('x', $pad),
    ], JSON_UNESCAPED_SLASHES);
    if (strlen($payload) !== $bytes) throw new RuntimeException('creds boundary fixture mismatch');
    return $payload;
};
$credsMinus = $bootstrapProbe('creds-minus', $credsSized(65535), null);
$credsExact = $bootstrapProbe('creds-exact', $credsSized(65536), null);
$credsPlus = $bootstrapProbe('creds-plus', $credsSized(65537), null);
check('Q-PERF-01a creds bootstrap принимает limit-1/limit и limit+1 отвергает без mutation',
    $credsMinus['rc'] === 0 && !empty($credsMinus['json']['ok'])
    && $credsExact['rc'] === 0 && !empty($credsExact['json']['ok'])
    && $credsPlus['rc'] === 0 && empty($credsPlus['json']['ok'])
    && ($credsPlus['json']['code'] ?? 0) === 1711
    && $credsMinus['creds_preserved'] && $credsExact['creds_preserved']
    && $credsPlus['creds_preserved'],
    json_encode(['minus' => $credsMinus['json'], 'exact' => $credsExact['json'],
        'plus' => $credsPlus['json']]));

$webhookWorkerDir = $securityFixRoot . '/webhook-worker';
mkdir($webhookWorkerDir, 0700, true);
file_put_contents($webhookWorkerDir . '/creds.php', $privateHeader . json_encode([
    'client_id' => 'local.synthetic.worker', 'client_secret' => 'synthetic-secret',
    'webhook_url' => 'https://worker.example.test/rest/1/syntheticKey/',
], JSON_UNESCAPED_SLASHES));
file_put_contents($webhookWorkerDir . '/plan.php', $privateHeader
    . json_encode(['at' => time(), 'license' => 'synthetic', 'type' => 'synthetic',
        'family' => 'company', 'enterprise' => false, 'box' => false]));
file_put_contents($webhookWorkerDir . '/state.php', $privateHeader
    . json_encode(['sessions_checked_at' => time()]));
$webhookWorker = $securityFixRun([PHP_BINARY, $root . '/www/bin/process.php'], [
    'BXAPP_DATA_ROOT' => $webhookWorkerDir,
]);
$webhookReadCode = 'require_once ' . var_export($root . '/www/env.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/store.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/lib.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/b24.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/session.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/journal.php', true) . ';'
    . '$tokens=storeRead(B24_TOKENS_FILE)??[];$generations=appInstallGenerationsOfThisPortal($tokens);'
    . '$identity=sessionInstallationIdentity();$client=new B24(B24_CLIENT_ID,B24_CLIENT_SECRET);'
    . '$rows=journalQuery(["q"=>"NOOP: projectJob()","level"=>"debug","limit"=>20,'
    . '"install_generation"=>$generations]);'
    . 'echo json_encode(["installed"=>appInstalled(),"generation"=>$client->installGeneration(),'
    . '"generations"=>$generations,"identity"=>$identity,"visible"=>count($rows["rows"])]);';
$webhookRead = $securityFixRun([PHP_BINARY, '-r', $webhookReadCode], [
    'BXAPP_DATA_ROOT' => $webhookWorkerDir,
]);
$webhookReadJson = json_decode($webhookRead['out'], true);
if (!is_array($webhookReadJson)) $webhookReadJson = [];

$webhookOtherDir = $securityFixRoot . '/webhook-other';
mkdir($webhookOtherDir, 0700, true);
file_put_contents($webhookOtherDir . '/creds.php', $privateHeader . json_encode([
    'client_id' => 'local.synthetic.worker', 'client_secret' => 'synthetic-secret',
    'webhook_url' => 'https://worker.example.test/rest/1/otherSyntheticKey/',
], JSON_UNESCAPED_SLASHES));
$webhookOtherCode = 'require ' . var_export($root . '/www/env.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/store.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/lib.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/b24.php', true) . ';'
    . 'echo json_encode(["generation"=>appCurrentInstallGeneration()]);';
$webhookOther = $securityFixRun([PHP_BINARY, '-r', $webhookOtherCode], [
    'BXAPP_DATA_ROOT' => $webhookOtherDir,
]);
$webhookOtherJson = json_decode($webhookOther['out'], true);
if (!is_array($webhookOtherJson)) $webhookOtherJson = [];
check('COR-01 webhook-only worker, session и journal используют одно canonical generation',
    $webhookWorker['rc'] === 0 && $webhookWorker['err'] === ''
    && $webhookRead['rc'] === 0 && $webhookRead['err'] === ''
    && !empty($webhookReadJson['installed'])
    && is_string($webhookReadJson['generation'] ?? null)
    && ($webhookReadJson['generation'] ?? '') !== ''
    && ($webhookReadJson['generations'] ?? []) === [$webhookReadJson['generation']]
    && ($webhookReadJson['identity']['install_generation'] ?? '') === $webhookReadJson['generation']
    && (int)($webhookReadJson['visible'] ?? 0) >= 1
    && $webhookOther['rc'] === 0 && $webhookOther['err'] === ''
    && ($webhookOtherJson['generation'] ?? '') !== ($webhookReadJson['generation'] ?? ''),
    json_encode(['worker_rc' => $webhookWorker['rc'], 'reader' => $webhookReadJson,
        'other_changed' => ($webhookOtherJson['generation'] ?? '') !== ($webhookReadJson['generation'] ?? '')]));

$mixedDir = $securityFixRoot . '/mixed-channel';
mkdir($mixedDir, 0700, true);
file_put_contents($mixedDir . '/creds.php', $privateHeader . json_encode([
    'client_id' => 'local.synthetic.mixed', 'client_secret' => 'synthetic-secret',
    'webhook_url' => 'https://channel-b.example.test/rest/1/syntheticKey/',
], JSON_UNESCAPED_SLASHES));
file_put_contents($mixedDir . '/b24-tokens.php', $privateHeader . json_encode([
    'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
    'domain' => 'channel-a.example.test', 'member_id' => 'SYNTHETIC',
    'install_generation' => 'synthetic-generation', 'expires_at' => time() + 3600,
], JSON_UNESCAPED_SLASHES));
$mixedBefore = hash_file('sha256', $mixedDir . '/b24-tokens.php');
$mixedCode = 'require ' . var_export($root . '/www/env.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/store.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/lib.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/b24.php', true) . ';'
    . 'require ' . var_export($root . '/www/api/session.php', true) . ';'
    . '$ctor=false;$portal=false;try{new B24(B24_CLIENT_ID,B24_CLIENT_SECRET);}catch(Throwable $e){$ctor=true;}'
    . 'try{b24Portal();}catch(Throwable $e){$portal=true;}$identity=sessionInstallationIdentity();'
    . '$same=B24::portalForChannels(["access_token"=>"x","domain"=>"same.example.test"],'
    . '"https://same.example.test/rest/1/key/");'
    . 'echo json_encode(["ctor"=>$ctor,"portal"=>$portal,"identity_empty"=>'
    . '($identity["member_id"]===""&&$identity["install_generation"]===""),'
    . '"same"=>$same==="same.example.test"]);';
$mixedRun = $securityFixRun([PHP_BINARY, '-r', $mixedCode], [
    'BXAPP_DATA_ROOT' => $mixedDir,
]);
$mixedJson = json_decode($mixedRun['out'], true);
if (!is_array($mixedJson)) $mixedJson = [];
check('COR-02 mixed OAuth/webhook tenant fail-closed, same-portal control проходит',
    $mixedRun['rc'] === 0 && $mixedRun['err'] === ''
    && !in_array(false, array_map('boolval', $mixedJson), true)
    && hash_file('sha256', $mixedDir . '/b24-tokens.php') === $mixedBefore,
    json_encode($mixedJson));

$priorDir = $securityFixRoot . '/prior-generation';
mkdir($priorDir, 0700, true);
$priorCode = 'require_once ' . var_export($root . '/www/env.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/store.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/lib.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/journal.php', true) . ';'
    . 'require_once ' . var_export($root . '/www/api/b24.php', true) . ';'
    . '$base=["access_token"=>"a","refresh_token"=>"r",'
    . '"domain"=>"prior.example.test","member_id"=>"M","client_id"=>"local.synthetic.prior",'
    . '"client_endpoint"=>"https://prior.example.test/rest/","expires_at"=>time()+3600];'
    . 'storeWrite(B24_TOKENS_FILE,$base+["install_generation"=>"g1"]);'
    . 'appBindLogGeneration("g1");appLog("info","prior.synthetic",[],["msg"=>"prior-visible"]);'
    . 'storeWrite(B24_TOKENS_FILE,$base+["install_generation"=>"g2","prior_generations"=>["g1"]]);'
    . '$b=new B24("local.synthetic.prior","synthetic-secret");'
    . '$b->saveTokensFromInstall(["AUTH_ID"=>"a2","REFRESH_ID"=>"r2","AUTH_EXPIRES"=>3600,'
    . '"APPLICATION_SCOPE"=>"crm,user"]);$after=storeRead(B24_TOKENS_FILE)??[];'
    . '$gens=appInstallGenerationsOfThisPortal($after);$rows=journalQuery(["q"=>"prior-visible",'
    . '"level"=>"debug","limit"=>10,"install_generation"=>$gens]);'
    . 'echo json_encode(["prior"=>($after["prior_generations"]??[]),"generation"=>'
    . 'appInstallGenerationFrom($after),"visible"=>count($rows["rows"])]);';
$priorRun = $securityFixRun([PHP_BINARY, '-r', $priorCode], [
    'BXAPP_DATA_ROOT' => $priorDir,
]);
$priorJson = json_decode($priorRun['out'], true);
if (!is_array($priorJson)) $priorJson = [];
check('COR-03 same-generation token refresh сохраняет prior generations и видимость journal',
    $priorRun['rc'] === 0 && $priorRun['err'] === ''
    && ($priorJson['prior'] ?? []) === ['g1']
    && ($priorJson['generation'] ?? '') === 'g2'
    && (int)($priorJson['visible'] ?? 0) === 1,
    json_encode($priorJson));

// Текста мало: скрипт обязан ФАКТИЧЕСКИ выбрать тот env-файл, что назван в
// deploy-prod.sh рядом с ним. Даём заведомо отсутствующий путь и смотрим, о каком
// файле он доложит: молчаливый уход на дефолт шаблона выглядел бы как успех.
$remoteEnvProbeDir = $securityFixRoot . '/remote-env-probe';
mkdir($remoteEnvProbeDir . '/scripts', 0700, true);
$probeEnvPath = $remoteEnvProbeDir . '/only-this-server.env';
file_put_contents($remoteEnvProbeDir . '/deploy-prod.sh',
    "#!/usr/bin/env bash\nBXAPP_ENV_FILE=" . $probeEnvPath . "\n");
foreach (['set-creds.sh', 'logs.sh', 'bxapp-remote.sh'] as $copied) {
    copy(dirname(__DIR__) . '/scripts/' . $copied, $remoteEnvProbeDir . '/scripts/' . $copied);
    chmod($remoteEnvProbeDir . '/scripts/' . $copied, 0700);
}
foreach (['set-creds.sh --show', 'logs.sh tail'] as $probeCall) {
    [$probeScript, $probeArg] = explode(' ', $probeCall, 2);
    $probeRun = $securityFixRun(['bash', $remoteEnvProbeDir . '/scripts/' . $probeScript, $probeArg]);
    $said = trim($probeRun['err'] . $probeRun['out']);
    check('служебная команда целится в env-файл своего проекта, а не в дефолт шаблона: ' . $probeScript,
        $probeRun['rc'] !== 0 && strpos($said, $probeEnvPath) !== false
        && strpos($said, '/data/config/env/netangels.env') === false,
        $said);
}

// Запись кред не имеет права сужать проход к релизу. На сервере, где веб-сервер
// работает другим пользователем, каталоги служебного дерева стоят в режиме деплоя
// (BXAPP_REMOTE_CONTROL_MODE): выставив им 700, команда 21.09.2026 увела боевой
// адрес в 404 — код на месте, а зайти в каталог релиза веб-сервер уже не мог.
$setCredsModeSource = (string)@file_get_contents(dirname(__DIR__) . '/scripts/set-creds.sh');
check('запись кред ставит проходным каталогам тот же режим, что деплой',
    strpos($setCredsModeSource, 'CONTROL_DIR_MODE="${BXAPP_REMOTE_CONTROL_MODE:-700}"') !== false
    && strpos($setCredsModeSource, "ensure_dir '\${REMOTE_CONTROL_PARENT}' '\${CONTROL_DIR_MODE}'") !== false
    && strpos($setCredsModeSource, "ensure_dir '\${CONTROL}' '\${CONTROL_DIR_MODE}'") !== false);
check('каталог с самим состоянием остаётся закрытым наглухо',
    strpos($setCredsModeSource, "ensure_dir '\${CONTROL}/shared'\n") !== false
    && strpos($setCredsModeSource, "ensure_dir '\${REMOTE_DATA}'\n") !== false
    && strpos($setCredsModeSource, 'mode=\"\${2:-700}\"') !== false);

$cliRoot = $securityFixRoot . '/set-creds-cli';
mkdir($cliRoot . '/scripts', 0700, true);
$cliScriptSource = (string)file_get_contents($root . '/scripts/set-creds.sh');
// Disposable copy deliberately points to an absent remote config: local/help
// must remain independent from remote infrastructure.
$cliScriptSource = str_replace('/data/config/env/netangels.env',
    $cliRoot . '/missing-netangels.env', $cliScriptSource);
file_put_contents($cliRoot . '/scripts/set-creds.sh', $cliScriptSource);
file_put_contents($cliRoot . '/scripts/set-creds-write.php',
    (string)file_get_contents($root . '/scripts/set-creds-write.php'));
chmod($cliRoot . '/scripts/set-creds.sh', 0700);
$flushHelperSource = strstr($setCredsWriter, "\ntry {\n    \$target =", true);
$flushProbeFile = $cliRoot . '/scripts/set-creds-flush-probe.php';
file_put_contents($flushProbeFile, (string)$flushHelperSource . <<<'PHP'

$closed = fopen('php://temp', 'w+b');
fclose($closed);
try {
    setCredsFlushAndSync($closed, 'fixture');
} catch (SetCredsWriteException $e) {
    exit(0);
}
exit(1);
PHP);
$flushProbe = $securityFixRun([PHP_BINARY, $flushProbeFile]);
check('credential writer останавливается на flush/fsync failure до mutation',
    is_string($flushHelperSource)
    && $flushProbe['rc'] === 0 && $flushProbe['out'] === '' && $flushProbe['err'] === '',
    json_encode(['rc' => $flushProbe['rc'], 'stderr' => $flushProbe['err']]));
$cliScript = $cliRoot . '/scripts/set-creds.sh';
$cliRun = static function (array $args, array $env = []) use ($securityFixRun, $cliScript): array {
    return $securityFixRun(array_merge(['bash', $cliScript], $args), $env);
};
$cliNoArgs = $cliRun([]);
$cliMissingInstance = $cliRun(['--instance']);
$cliHelp = $cliRun(['--help']);
check('COR-06 CLI preflight даёт diagnostics без unbound и разбирается до remote config',
    $cliNoArgs['rc'] !== 0 && strpos($cliNoArgs['err'], 'нужен client_id') !== false
    && strpos($cliNoArgs['err'], 'unbound') === false
    && $cliMissingInstance['rc'] !== 0 && trim($cliMissingInstance['err']) !== ''
    && $cliHelp['rc'] === 0 && $cliHelp['err'] === '',
    json_encode(['noargs' => $cliNoArgs['rc'], 'instance' => $cliMissingInstance['rc'],
        'help' => $cliHelp['rc']]));

$cliCredsFile = $cliRoot . '/data/creds.php';
$decodeCliCreds = static function (string $file) use ($privateHeader): array {
    $raw = (string)@file_get_contents($file);
    if (strncmp($raw, $privateHeader, strlen($privateHeader)) !== 0) return [];
    $decoded = json_decode(substr($raw, strlen($privateHeader)), true);
    return is_array($decoded) ? $decoded : [];
};
$cliFirst = $cliRun(['--local', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-1',
    'B24_WEBHOOK_URL' => 'https://cli.example.test/rest/1/syntheticKey/',
]);
$cliAfterFirst = $decodeCliCreds($cliCredsFile);
$cliSecond = $cliRun(['--local', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-2',
]);
$cliAfterSecond = $decodeCliCreds($cliCredsFile);
$cliRemove = $cliRun(['--local', '--remove-webhook', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-3',
]);
$cliAfterRemove = $decodeCliCreds($cliCredsFile);
check('COR-05 ordinary credential update сохраняет webhook, explicit removal удаляет',
    $cliFirst['rc'] === 0 && $cliSecond['rc'] === 0 && $cliRemove['rc'] === 0
    && isset($cliAfterFirst['webhook_url'])
    && ($cliAfterSecond['webhook_url'] ?? '') === ($cliAfterFirst['webhook_url'] ?? '')
    && !array_key_exists('webhook_url', $cliAfterRemove)
    && ($cliAfterSecond['client_secret'] ?? '') !== ($cliAfterFirst['client_secret'] ?? '')
    && ($cliAfterRemove['client_secret'] ?? '') !== ($cliAfterSecond['client_secret'] ?? ''),
    json_encode(['first' => $cliFirst['rc'], 'second' => $cliSecond['rc'], 'remove' => $cliRemove['rc']]));

// Оба reader принимают ровно штатный current header и migration bridge;
// bounded writer читает bridge, но всегда публикует обратно current 404 header.
$legacyWriterWebhook = 'https://legacy-writer.example.test/rest/1/syntheticKey/';
file_put_contents($cliCredsFile, $legacyPrivateHeader . json_encode([
    'client_id' => 'local.synthetic.cli',
    'client_secret' => 'synthetic-secret-legacy',
    'webhook_url' => $legacyWriterWebhook,
], JSON_UNESCAPED_SLASHES));
$legacyWriterUpdate = $cliRun(['--local', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-after-legacy',
]);
$legacyWriterRaw = (string)@file_get_contents($cliCredsFile);
$legacyWriterData = $decodeCliCreds($cliCredsFile);
check('COR-05 legacy private header совместим с bounded writer без расширения формата',
    $legacyWriterUpdate['rc'] === 0 && $legacyWriterUpdate['err'] === ''
    && strncmp($legacyWriterRaw, $privateHeader, strlen($privateHeader)) === 0
    && strncmp($legacyWriterRaw, $legacyPrivateHeader, strlen($legacyPrivateHeader)) !== 0
    && ($legacyWriterData['webhook_url'] ?? '') === $legacyWriterWebhook
    && ($legacyWriterData['client_secret'] ?? '') === 'synthetic-secret-after-legacy',
    json_encode(['rc' => $legacyWriterUpdate['rc'], 'canonical' =>
        strncmp($legacyWriterRaw, $privateHeader, strlen($privateHeader)) === 0]));

$leakSafe = true;
$leakNoMutation = true;
$leakBaseline = hash_file('sha256', $cliCredsFile);
$invalidWebhookUrls = [
    'http://invalid.example.test/rest/1/SYNTHETIC_UNIQUE_MARKER/',
    'https://userSYNTHETIC_UNIQUE_MARKER@invalid.example.test/rest/1/key/',
    'https://invalid.example.test:8443/rest/1/SYNTHETIC_UNIQUE_MARKER/',
    'https://invalid.example.test/rest/1/SYNTHETIC_UNIQUE_MARKER/?q=1',
    'https://invalid.example.test/rest/1/SYNTHETIC_UNIQUE_MARKER/#fragment',
];
foreach ($invalidWebhookUrls as $invalidWebhookUrl) {
    $invalidRun = $cliRun(['--local', 'local.synthetic.cli'], [
        'B24_CLIENT_SECRET' => 'synthetic-secret-invalid',
        'B24_WEBHOOK_URL' => $invalidWebhookUrl,
    ]);
    $combined = $invalidRun['out'] . $invalidRun['err'];
    if ($invalidRun['rc'] === 0 || strpos($combined, 'SYNTHETIC_UNIQUE_MARKER') !== false) {
        $leakSafe = false;
    }
    if (hash_file('sha256', $cliCredsFile) !== $leakBaseline) $leakNoMutation = false;
}
check('SEC-CAN-01 invalid webhook diagnostics не раскрывает bearer marker и не меняет target',
    $leakSafe && $leakNoMutation,
    json_encode(['classes' => count($invalidWebhookUrls), 'safe' => $leakSafe,
        'preserved' => $leakNoMutation]));

file_put_contents($cliCredsFile, $privateHeader . '{');
$malformedBefore = hash_file('sha256', $cliCredsFile);
$malformedUpdate = $cliRun(['--local', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-malformed',
]);
$malformedPreserved = $malformedUpdate['rc'] !== 0
    && hash_file('sha256', $cliCredsFile) === $malformedBefore;
file_put_contents($cliCredsFile, $privateHeader . str_repeat('x', 65537));
$oversizedBefore = hash_file('sha256', $cliCredsFile);
$oversizedUpdate = $cliRun(['--local', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-oversized',
]);
$oversizedPreserved = $oversizedUpdate['rc'] !== 0
    && hash_file('sha256', $cliCredsFile) === $oversizedBefore;
@unlink($cliCredsFile);
$outsideCreds = $securityFixRoot . '/outside-creds.php';
file_put_contents($outsideCreds, $validCreds);
$outsideBefore = hash_file('sha256', $outsideCreds);
$symlinkMade = @symlink($outsideCreds, $cliCredsFile);
$symlinkUpdate = $cliRun(['--local', 'local.synthetic.cli'], [
    'B24_CLIENT_SECRET' => 'synthetic-secret-symlink',
]);
check('COR-05 malformed/oversized/symlink credential target fail-closed без mutation',
    $malformedPreserved && $oversizedPreserved && $symlinkMade
    && $symlinkUpdate['rc'] !== 0 && is_link($cliCredsFile)
    && hash_file('sha256', $outsideCreds) === $outsideBefore,
    json_encode(['malformed' => $malformedUpdate['rc'], 'oversized' => $oversizedUpdate['rc'],
        'symlink' => $symlinkUpdate['rc']]));
@unlink($cliCredsFile);

$writerTarget = $cliRoot . '/data/writer-boundary.php';
$writerPayloadFor = static function (int $bytes) use ($privateHeader): array {
    $id = 'local.synthetic.writer';
    $base = $privateHeader . json_encode(['client_id' => $id, 'client_secret' => ''],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $pad = $bytes - strlen($base);
    if ($pad < 0) throw new RuntimeException('writer boundary fixture too small');
    $secret = str_repeat('x', $pad);
    $candidate = $privateHeader . json_encode(['client_id' => $id, 'client_secret' => $secret],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (strlen($candidate) !== $bytes) throw new RuntimeException('writer boundary fixture mismatch');
    return ['request' => json_encode(['client_id' => $id, 'client_secret' => $secret,
        'webhook_action' => 'preserve'], JSON_UNESCAPED_SLASHES), 'bytes' => strlen($candidate)];
};
$writerMinusRequest = $writerPayloadFor(65535);
$writerMinus = $securityFixRun([PHP_BINARY, $cliRoot . '/scripts/set-creds-write.php', $writerTarget], [],
    (string)$writerMinusRequest['request']);
clearstatcache(true, $writerTarget);
$writerMinusSize = is_file($writerTarget) ? filesize($writerTarget) : 0;
$writerExactRequest = $writerPayloadFor(65536);
$writerExact = $securityFixRun([PHP_BINARY, $cliRoot . '/scripts/set-creds-write.php', $writerTarget], [],
    (string)$writerExactRequest['request']);
clearstatcache(true, $writerTarget);
$writerExactSize = is_file($writerTarget) ? filesize($writerTarget) : 0;
$writerExactHash = is_file($writerTarget) ? hash_file('sha256', $writerTarget) : '';
$writerPlusRequest = $writerPayloadFor(65537);
$writerPlus = $securityFixRun([PHP_BINARY, $cliRoot . '/scripts/set-creds-write.php', $writerTarget], [],
    (string)$writerPlusRequest['request']);
clearstatcache(true, $writerTarget);
check('Q-PERF-01a credential writer принимает limit-1/limit, limit+1 не публикует',
    $writerMinus['rc'] === 0 && $writerMinus['err'] === '' && $writerMinusSize === 65535
    && $writerExact['rc'] === 0 && $writerExact['err'] === '' && $writerExactSize === 65536
    && $writerPlus['rc'] !== 0 && filesize($writerTarget) === 65536
    && hash_file('sha256', $writerTarget) === $writerExactHash,
    json_encode(['minus' => [$writerMinus['rc'], $writerMinusSize],
        'exact' => [$writerExact['rc'], $writerExactSize], 'plus' => $writerPlus['rc']]));


// Ревью kg-perenosbp 04.10.2026: настоящий параллельный OAuth и event.get.
// Node запускает PHP в отдельной копии исходников с искусственным private state.
// Живые credentials/портал не читаются; никакого сетевого транспорта.
$coreReviewRegression = [
    ['core-s1-regression.cjs', 'CORE_S1_ALL_CHECKS_PASS', [
        'concurrent-six', 'sequential-three-then-denied',
        'expired-window-one-attempt-then-denied', 'verified-success-clears-counter',
        'changed-generation-no-network',
    ]],
    ['core-s2-regression.cjs', 'CORE_S2_ALL_CHECKS_PASS', [
        'event-list-rejected', 'event-list-throws', 'known-only-cleanup', 'known-empty-list',
    ]],
];
foreach ($coreReviewRegression as [$script, $marker, $expectedScenarios]) {
    $pipes = [];
    $proc = proc_open(['node', __DIR__ . '/' . $script, dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = ''; $err = ''; $rc = 255;
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($proc);
    }
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['scenario'])) $rows[$row['scenario']] = $row;
    }
    check('CORE-04.10 ' . $script . ' завершает настоящий сценарий',
        $rc === 0 && $err === '' && strpos($out, $marker) !== false,
        maskSecrets($out . $err));
    check('CORE-04.10 ' . $script . ' выполняет все заданные края',
        array_keys($rows) === $expectedScenarios);
    foreach ($expectedScenarios as $name) {
        check('CORE-04.10 ' . $name, ($rows[$name]['passed'] ?? false) === true,
            json_encode($rows[$name] ?? ['missing' => $name]));
    }
}
