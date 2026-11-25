<?php
declare(strict_types=1);
/**
 * СКОРОСТЬ ГОРЯЧИХ ПУТЕЙ (ревью 23.09.2026, skorost-*).
 *
 * Каждая находка проверяется парой: поведение не изменилось (те же пропуска
 * гаснут, те же роли остаются) и дорогая форма не вернулась (один проход
 * вместо N перезаписей, один разбор списка доступа вместо разбора на строку).
 */
suite('Скорость горячих путей: одна запись вместо N, один разбор вместо N');

/** Тело функции из исходника: от её объявления до парной закрывающей скобки. */
$perfBody = static function (string $src, string $fn): string {
    if (!preg_match('~function\s+' . preg_quote($fn, '~') . '\s*\(~', $src, $m, PREG_OFFSET_CAPTURE)) return '';
    $open = strpos($src, '{', $m[0][1]);
    if ($open === false) return '';
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}' && --$depth === 0) return substr($src, $open, $i - $open + 1);
    }
    return '';
};
$sessionSrc = (string)file_get_contents(dirname(__DIR__) . '/www/api/session.php');

// ── skorost-6: заблокированные сотрудники гасятся одной записью ──────────────
storeWrite(B24_TOKENS_FILE, ['access_token' => 'perf-a', 'refresh_token' => 'perf-r',
    'domain' => 'perf.bitrix24.ru', 'member_id' => 'PERF', 'install_generation' => 'perf-g',
    'expires_at' => time() + 3600]);
storeWrite(ACL_FILE, ['owner_uid' => 1, 'users' => [
    ['uid' => 1, 'role' => 'admin'], ['uid' => 2, 'role' => 'view'], ['uid' => 3, 'role' => 'view'],
    ['uid' => 4, 'role' => 'edit'], ['uid' => 5, 'role' => 'view'], ['uid' => 51, 'role' => 'view'],
], 'depts' => [['id' => 100, 'role' => 'edit']]]);
storeWrite(SESSIONS_FILE, []);
foreach ([1, 2, 3, 4, 5] as $uid) createSession($uid, 'view', false);
$blockedRevoked = revokeSessionsOfBlockedUsers(static function (array $uids): array {
    // 2 и 4 — заблокированы, 3 — портал промолчал (не гасим), остальные активны.
    return [1 => true, 2 => false, 4 => false, 5 => true];
});
$leftUids = array_values(array_unique(array_map(static fn($r) => (int)($r['uid'] ?? 0), loadSessions())));
sort($leftUids);
check('perf: заблокированные гаснут, молчание портала пропуск не трогает',
    $blockedRevoked === 2 && $leftUids === [1, 3, 5], json_encode(['n' => $blockedRevoked, 'left' => $leftUids]));
$blockedBody = $perfBody($sessionSrc, 'revokeSessionsOfBlockedUsers');
check('perf: пропуска заблокированных снимаются ОДНОЙ записью файла сессий',
    $blockedBody !== '' && strpos($blockedBody, 'revokeSessionsForUser(') === false
    && substr_count($blockedBody, 'sessionStoreUpdate(') === 1,
    'в цикле по сотрудникам осталась перезапись файла на каждого');

// ── skorost-7: список доступа разбирается один раз на операцию ───────────────
storeWrite(SESSIONS_FILE, []);
$s50 = createSession(50, 'edit', false, [100]);
$s51 = createSession(51, 'edit', false, [100]);
$s52 = createSession(52, 'edit', false, [100]);
$deptGone = sessionApplyPortalDepartments([50 => [200], 51 => [200], 52 => [100, 300]]);
$byUid = [];
foreach (loadSessions() as $r) $byUid[(int)($r['uid'] ?? 0)] = $r;
check('perf: перевод из отдела гасит пропуск без другого основания и пересчитывает роль',
    $deptGone === 1 && !isset($byUid[50]) && ($byUid[51]['role'] ?? '') === 'view'
    && ($byUid[52]['role'] ?? '') === 'edit',
    json_encode(['gone' => $deptGone, 'left' => array_map(static fn($r) => $r['role'] ?? null, $byUid)]));
revokeSessionsForDepartment(100);
$afterDept = array_map(static fn($r) => (int)($r['uid'] ?? 0), loadSessions());
sort($afterDept);
check('perf: снятие отдела оставляет тех, у кого есть другое основание',
    $afterDept === [51], json_encode($afterDept));
$loopBodies = $perfBody($sessionSrc, 'sessionApplyPortalDepartments') . $perfBody($sessionSrc, 'revokeSessionsForDepartment');
check('perf: в циклах по пропускам список доступа не перечитывается на каждую строку',
    $loopBodies !== '' && strpos($loopBodies, 'aclUserRole(') === false
    && strpos($loopBodies, 'aclRoleIn(') !== false && function_exists('aclRoleIn'),
    'aclUserRole() в цикле: storeRead + json_decode + нормализация ACL на каждую строку');

// ── skorost-5: чтение пропуска не пересортировывает и не перекодирует весь файл ─
storeWrite(SESSIONS_FILE, []);
$live = createSession(1, 'admin', false);
$other = createSession(2, 'view', false);
// Строка из старого формата: без номера сотрудника и не предпросмотр. Писатель
// её бы не пропустил, но файл мог остаться от прежней версии — чтение обязано
// отклонить её так же, как отклоняла полная уборка.
$legacyToken = bin2hex(random_bytes(32));
$raw = loadSessions();
$raw[] = array_merge($raw[0], ['token' => $legacyToken, 'uid' => 0, 'preview' => false]);
storeWrite(SESSIONS_FILE, $raw);
$foundLive = findSessionByToken($live['token']);
$foundLegacy = findSessionByToken($legacyToken);
check('perf: живой пропуск находится, строка без сотрудника отклоняется как раньше',
    is_array($foundLive) && (int)($foundLive['uid'] ?? 0) === 1 && $foundLegacy === null,
    json_encode(['live' => $foundLive['uid'] ?? null, 'legacy' => $foundLegacy === null ? 'нет' : 'нашёлся']));
// Просроченный пропуск уходит с диска при первом же чтении.
$raw = loadSessions();
$raw[] = array_merge($raw[0], ['token' => bin2hex(random_bytes(32)), 'uid' => 3, 'expiresAt' => time() - 10]);
storeWrite(SESSIONS_FILE, $raw);
findSessionByToken($other['token']);
$expiredLeft = array_filter(loadSessions(), static fn($r) => (int)($r['expiresAt'] ?? 0) <= time());
check('perf: просроченный пропуск уходит с диска при чтении', $expiredLeft === [],
    'осталось просроченных: ' . count($expiredLeft));
check('perf: путь чтения пропуска не зовёт полную пересортировку и побайтовую обрезку',
    ($cleanupBody = $perfBody((string)file_get_contents(dirname(__DIR__) . '/www/api/session.php'), 'cleanupSessions')) !== ''
    && strpos($cleanupBody, 'sessionsBound(') === false,
    'cleanupSessions() на каждом запросе зовёт sessionsBound(): usort + json_encode каждой строки + двоичный поиск');

// ── skorost-3: справочник CRM — пакетами, а не двумя десятками вызовов подряд ─
require_once dirname(__DIR__) . '/www/api/b24.php';
require_once dirname(__DIR__) . '/www/api/crm-dict.php';
$dictB24 = new B24('local.test.app', 'secret');
$dictB24->saveTokensFromInstall([
    'AUTH_ID' => 'perf-access', 'REFRESH_ID' => 'perf-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-perf.invalid', 'member_id' => 'MEMBER-PERF',
    'APPLICATION_TOKEN' => 'key-perf', 'APPLICATION_SCOPE' => 'crm,user',
]);
$perfPortal = static function (string $method, array $params) {
    if ($method === 'crm.category.list') {
        if ((int)($params['entityTypeId'] ?? 0) !== 2) return ['error' => 'ENTITY_TYPE_NOT_SUPPORTED'];
        $cats = [];
        for ($i = 0; $i < 10; $i++) $cats[] = ['id' => $i, 'name' => 'Воронка ' . $i];
        return ['result' => ['categories' => $cats]];
    }
    if ($method === 'crm.item.fields') return ['result' => ['fields' => ['TITLE' => ['title' => 'Название']]]];
    if ($method === 'crm.status.list') {
        $e = (string)($params['filter']['ENTITY_ID'] ?? '');
        $status = strpos($e, 'DEAL_STAGE_') === 0 ? 'C' . substr($e, 11) . ':NEW' : 'NEW';
        return ['result' => [['ID' => crc32($e), 'ENTITY_ID' => $e, 'STATUS_ID' => $status, 'NAME' => 'Новая ' . $e]], 'total' => 1];
    }
    return ['error' => 'ERROR_METHOD_NOT_FOUND'];
};
$perfCalls = [];
$GLOBALS['__b24_transport'] = static function (string $method, array $params) use ($perfPortal, &$perfCalls) {
    $perfCalls[] = $method;
    if ($method !== 'batch') return $perfPortal($method, $params);
    $ok = []; $bad = [];
    foreach ((array)($params['cmd'] ?? []) as $key => $command) {
        $parts = explode('?', (string)$command, 2);
        $sub = [];
        if (isset($parts[1])) parse_str($parts[1], $sub);
        $answer = $perfPortal($parts[0], $sub);
        if (isset($answer['error'])) { $bad[$key] = ['error' => $answer['error'], 'error_description' => '']; continue; }
        $ok[$key] = $answer['result'];
    }
    return ['result' => ['result' => $ok, 'result_error' => $bad, 'result_total' => [], 'result_next' => [], 'result_time' => []]];
};
storeWrite(CRM_DICT_FILE, []);
$perfDict = crmDict($dictB24, true);
unset($GLOBALS['__b24_transport']);
check('perf: справочник по 10 воронкам полный и с названиями стадий каждой воронки',
    !empty($perfDict['complete']) && count($perfDict['categories'][2] ?? []) === 10
    && ($perfDict['stages'][2]['C9:NEW'] ?? '') === 'Новая DEAL_STAGE_9'
    && ($perfDict['fields'][4]['TITLE'] ?? '') === 'Название',
    json_encode(['complete' => $perfDict['complete'] ?? null, 'failed' => $perfDict['failed'] ?? null,
        'stages' => count($perfDict['stages'][2] ?? [])]));
check('perf: сборка справочника укладывается в 3 обращения к порталу, а не в два десятка',
    count($perfCalls) <= 3, 'обращений: ' . count($perfCalls) . ' — ' . implode(',', array_slice($perfCalls, 0, 25)));

// ── skorost-2: каталог блоков не подаёт медленный обход как основной для экрана ─
$blocksMd = (string)file_get_contents(dirname(__DIR__) . '/docs/BLOCKS.md');
$rowBatched = ''; $rowCursor = '';
foreach (preg_split('~\R~u', $blocksMd) ?: [] as $line) {
    if (strpos($line, '| ') !== 0) continue;
    if (strpos($line, '$b24->fetchAllBatched(') !== false && $rowBatched === '') $rowBatched = $line;
    if (strpos($line, '$b24->fetchAll(') !== false && $rowCursor === '') $rowCursor = $line;
}
check('perf: для экрана и отчёта каталог ведёт на fetchAllBatched(), у fetchAll() названа цена',
    preg_match('~^\| \*\*[^|]*(экран|отч[её]т)~u', $rowBatched) === 1
    && preg_match('~обращени[йея][^|]*подряд|подряд[^|]*обращени~u', $rowCursor) === 1,
    json_encode(['batched' => mb_substr($rowBatched, 0, 90), 'cursor' => mb_substr($rowCursor, 0, 90)], JSON_UNESCAPED_UNICODE));
