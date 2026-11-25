<?php
declare(strict_types=1);

suite('Атомарная авторизация изменения доступа');

// Только изолированный DATA_ROOT раннера; HTTP исполняет shipping endpoint.
$authBoundarySrv = testServer();
if (!$authBoundarySrv['ok']) { check('AUTHZ: локальный HTTP стенд доступен', false); return; }

$authBoundaryRun = static function (array $body, callable $change, string $grant = 'user', bool $portalAdmin = false) use ($authBoundarySrv): array {
    storeWrite(B24_TOKENS_FILE, [
        'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh',
        'domain' => 'example.bitrix24.ru', 'member_id' => 'AUTHZ-FIXTURE',
        'install_generation' => 'authz-generation', 'expires_at' => time() + 3600,
    ]);
    storeWrite(ACL_FILE, ['owner_uid' => 10, 'users' => [
        ['uid' => 10, 'role' => 'admin'], ['uid' => 30, 'role' => 'view'],
    ], 'depts' => []]);
    aclUpdate(static function (array $acl) use ($grant): array {
        if ($grant === 'dept') $acl['depts'][] = ['id' => 7, 'role' => 'admin'];
        else $acl['users'][] = ['uid' => 20, 'role' => 'admin'];
        return $acl;
    });
    storeWrite(SESSIONS_FILE, []);
    $session = createSession(20, 'admin', $portalAdmin, $grant === 'dept' ? [7] : []);
    sessionStoreUpdate(static function (array $rows): array {
        foreach ($rows as &$row) $row['lastSeen'] = time() - 3600;
        return $rows;
    });
    storeWrite(RATELIMIT_FILE, []);
    $lock = fopen(RATELIMIT_FILE . '.lock.php', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('не взять test rate lock');
    $proc = null; $pipes = [];
    try {
        $url = 'http://127.0.0.1:' . $authBoundarySrv['port'] . '/api/acl.php';
        $proc = proc_open(['curl', '--silent', '--max-time', '10',
            '-H', 'Content-Type: application/json', '-H', 'Sec-Fetch-Site: same-origin',
            '-H', 'X-App-Token: ' . $session['token'], '--data', json_encode($body),
            '-w', "\n%{http_code}", $url],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('не запустить test HTTP request');
        fclose($pipes[0]); unset($pipes[0]);
        $pastGate = false;
        for ($i = 0; $i < 150; $i++) {
            foreach (loadSessions() as $row) {
                if (($row['token'] ?? '') === $session['token'] && ($row['lastSeen'] ?? 0) > time() - 10) $pastGate = true;
            }
            if ($pastGate) break;
            usleep(10000);
        }
        if (!$pastGate) throw new RuntimeException('test request не дошёл до role-before-rate boundary');
        $change($session);
        flock($lock, LOCK_UN); fclose($lock); $lock = null;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
        $rc = proc_close($proc); $proc = null;
        $split = strrpos($out, "\n");
        return ['rc' => $rc, 'status' => (int)substr($out, $split + 1),
            'body' => json_decode(substr($out, 0, $split), true), 'acl' => aclLoad(),
            'stderr_empty' => $err === ''];
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    }
};

$authBoundaryRemoveUser = static function (array $session): void {
    aclUpdate(static function (array $acl): array {
        $acl['users'] = array_values(array_filter($acl['users'], static fn($u) => (int)$u['uid'] !== 20));
        return $acl;
    });
    revokeSessionsForUser(20);
};
$authBoundaryAdd = ['action' => 'add', 'kind' => 'user', 'uid' => 20, 'role' => 'admin'];
$r = $authBoundaryRun($authBoundaryAdd, $authBoundaryRemoveUser);
check('AUTHZ RED: отозванный автор не возвращает себе admin', $r['status'] === 403 && aclUserRole(20) === null,
    'HTTP ' . $r['status']);

foreach ([
    ['action' => 'add', 'kind' => 'dept', 'dept' => 88, 'role' => 'admin'],
    ['action' => 'update', 'kind' => 'user', 'uid' => 30, 'role' => 'admin'],
    ['action' => 'delete', 'kind' => 'user', 'uid' => 30],
    ['action' => 'delete', 'kind' => 'dept', 'dept' => 7],
] as $index => $action) {
    $r = $authBoundaryRun($action, $authBoundaryRemoveUser);
    check('AUTHZ: каждая ACL mutation отвергает отозванного автора ' . $index,
        $r['status'] === 403 && aclUserRole(20) === null && aclUserRole(30) === 'view', 'HTTP ' . $r['status']);
}
$r = $authBoundaryRun($authBoundaryAdd, static function (): void {
    aclUpdate(static function (array $acl): array {
        foreach ($acl['users'] as &$u) if ((int)$u['uid'] === 20) $u['role'] = 'view';
        return $acl;
    });
});
check('AUTHZ: понижение автора без удаления сессии действует на запись', $r['status'] === 403 && aclUserRole(20) === 'view');
$r = $authBoundaryRun($authBoundaryAdd, static function (): void {
    aclUpdate(static function (array $acl): array { $acl['depts'] = []; return $acl; });
}, 'dept');
check('AUTHZ: снятие доступа отдела действует на запись', $r['status'] === 403 && aclUserRole(20, [7]) === null);
$r = $authBoundaryRun($authBoundaryAdd, static function (): void { revokeSessionsForUser(20); });
check('AUTHZ: отозванный пропуск не меняет ACL при сохранённой роли', $r['status'] === 403);
$r = $authBoundaryRun(['action' => 'claim_owner'], static function (): void { revokeSessionsForUser(20); }, 'user', true);
check('AUTHZ: передача owner заново проверяет пропуск под замком', $r['status'] === 403 && aclOwnerUid() === 10);
$r = $authBoundaryRun($authBoundaryAdd, static function (): void {});
check('AUTHZ: действующий admin сохраняет штатное изменение доступа', $r['status'] === 200 && aclUserRole(20) === 'admin');
$r = $authBoundaryRun(['action' => 'claim_owner'], static function (): void {}, 'user', true);
check('AUTHZ: свежий portal-admin сохраняет передачу owner', $r['status'] === 200 && aclOwnerUid() === 20);

($authBoundarySrv['close'])();
