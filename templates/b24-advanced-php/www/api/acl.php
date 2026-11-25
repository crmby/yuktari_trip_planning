<?php
declare(strict_types=1);
/**
 * api/acl.php — управление списком доступа приложения (только роль admin).
 *
 * GET  → {ok:true, acl:{owner_uid, users:[{uid,name,photo,role}], depts:[{id,name,role}]}}
 * POST {action:'add',    kind:'user'|'dept', uid|dept, name?, photo?, role} → добавить/обновить
 *      {action:'update', kind, uid|dept, role}      → сменить роль
 *      {action:'delete', kind, uid|dept}            → убрать (владельца нельзя)
 *      {action:'claim_owner'}                        → portal-admin становится владельцем,
 *                                                     прежний owner теряет доступ
 *
 * uid и имя для add фронт получает из admin-only `api/users.php`:
 * тот ищет активных сотрудников готовым B24-клиентом с полной пагинацией.
 * Этот endpoint хранит выбранную запись. Владельца нельзя понизить/удалить
 * (защита от самоблокировки).
 *
 * Снятие доступа действует НЕМЕДЛЕННО: пропуска этого сотрудника гасятся тут же,
 * а не «доработают до конца дня». Роль на каждом запросе всё равно перечитывается
 * из этого списка (см. session.php), но пропуск лучше убрать сразу — тогда и
 * открытая у него вкладка перестанет работать в ту же секунду.
 */

require_once __DIR__ . '/http.php';

installFailureHandler(true);   // сбой отдаём JSON'ом, фронт покажет причину

$ctx = apiBoot(['methods' => ['GET', 'POST'], 'role' => 'admin']);

if ($ctx['method'] === 'GET') {
    $acl = aclLoad();
    $currentUid = (int)$ctx['uid'];
    apiOk([
        'acl' => $acl,
        'owner_transfer' => [
            'can_claim' => $currentUid > 0
                && $currentUid !== (int)$acl['owner_uid']
                && sessionHasFreshPortalAdminProof((array)$ctx['session']),
        ],
    ]);
}

// После ожиданий apiBoot авторизация и запись выполняются одной операцией.
appWithAuthorizationLock(static function () use ($ctx): void {
    $fresh = findSessionByToken((string)($ctx['session']['token'] ?? ''));
    if ($fresh === null || !roleAtLeast((string)($fresh['role'] ?? 'view'), 'admin')) {
        apiFail('Доступ изменился. Переоткройте приложение', 403, 'forbidden');
    }
    $ctx['session'] = $fresh;
    $ctx['role'] = (string)$fresh['role'];
    $body   = apiBody();
    $action = apiEnum($body, 'action', ['add', 'update', 'delete', 'claim_owner']);

    if ($action === 'claim_owner') {
        // Нельзя назначить произвольный uid по телу запроса. Владельцем становится
        // только сам человек, чьи user.current + user.admin недавно подтвердил портал.
        if (!sessionHasFreshPortalAdminProof((array)$ctx['session'])) {
            apiFail('Переоткройте приложение под администратором портала', 403, 'portal_admin_required');
        }
        $newOwnerUid = (int)$ctx['uid'];
        if ($newOwnerUid <= 0) apiFail('Портал не подтвердил пользователя', 403, 'portal_admin_required');

        $transfer = aclTransferOwner($newOwnerUid);
        $oldOwnerUid = (int)$transfer['old_owner_uid'];
        if (!empty($transfer['changed']) && $oldOwnerUid > 0) revokeSessionsForUser($oldOwnerUid);
        auditLog('acl.transfer_owner', [
            'old_uid' => $oldOwnerUid,
            'new_uid' => $newOwnerUid,
            'changed' => !empty($transfer['changed']),
        ], $newOwnerUid);
        apiOk(['acl' => aclLoad(), 'owner_transfer' => $transfer]);
    }

    // ЧЕЛОВЕК ИЛИ ОТДЕЛ. Доступ выдают и тем, и другим: «весь отдел продаж плюс
    // двое из бухгалтерии». Отдельное поле kind, а не «угадаем по наличию uid» —
    // иначе опечатка в запросе тихо превращает отдел в сотрудника с тем же номером.
    $kind = apiEnum($body, 'kind', ['user', 'dept'], ['required' => false, 'default' => 'user']);
    $uid  = $kind === 'user' ? apiInt($body, 'uid', ['min' => 1]) : 0;
    $dept = $kind === 'dept' ? apiInt($body, 'dept', ['min' => 1]) : 0;

    // Решения принимаем ВНУТРИ замка, по свежему списку: кто владелец и есть ли
    // уже такой сотрудник, могло измениться, пока мы читали запрос.
    $fail = null;   // ['code' => int, 'msg' => string] — отказ, решённый по свежим данным

    if ($action === 'add' || $action === 'update') {
        // add без роли имеет безопасный default=view. update обязан назвать новую
        // роль явно: пропущенное поле раньше молча понижало сотрудника до view.
        $role  = apiEnum($body, 'role', ACL_ROLES,
            ['required' => $action === 'update', 'default' => 'view']);
        $name  = apiStr($body,  'name',  ['required' => false, 'max' => 200]);
        $photo = apiStr($body,  'photo', ['required' => false, 'max' => 500]);
        $appliedRole = null;

        if ($kind === 'dept') {
            aclUpdate(function (array $acl) use ($dept, $role, $name, $action, &$fail, &$appliedRole): array {
                $found = false;
                foreach ($acl['depts'] as &$d) {
                    if ((int)$d['id'] === $dept) {
                        $d['role'] = $role;
                        if ($name !== '') $d['name'] = $name;
                        $appliedRole = $role;
                        $found = true;
                    }
                }
                unset($d);
                if (!$found) {
                    if ($action === 'update') { $fail = ['code' => 404, 'msg' => 'Отдел не найден в списке доступа']; return $acl; }
                    $acl['depts'][] = [
                        'id'   => $dept,
                        'name' => $name !== '' ? $name : ('отдел №' . $dept),
                        'role' => $role,
                    ];
                    $appliedRole = $role;
                }
                return $acl;
            });
            if ($fail) apiFail($fail['msg'], $fail['code'], 'acl_error');
            auditLog('acl.' . $action, ['dept' => $dept, 'role' => (string)$appliedRole], $ctx['uid']);
            apiOk(['acl' => aclLoad()]);
        }

        aclUpdate(function (array $acl) use ($uid, $role, $name, $photo, $action, &$fail, &$appliedRole): array {
            if ($uid === $acl['owner_uid']) $role = 'admin';   // владельца не понизить

            $found = false;
            foreach ($acl['users'] as &$u) {
                if ((int)$u['uid'] === $uid) {
                    $u['role'] = $role;
                    if ($name  !== '') $u['name']  = $name;
                    if ($photo !== '') $u['photo'] = $photo;
                    $appliedRole = $role;
                    $found = true;
                }
            }
            unset($u);
            if (!$found) {
                if ($action === 'update') { $fail = ['code' => 404, 'msg' => 'Сотрудник не найден']; return $acl; }
                $acl['users'][] = [
                    'uid'   => $uid,
                    'name'  => $name !== '' ? $name : ('B24#' . $uid),
                    'photo' => $photo,
                    'role'  => $role,
                ];
                $appliedRole = $role;
            }
            return $acl;
        });

        if ($fail) apiFail($fail['msg'], $fail['code'], 'acl_error');
        auditLog('acl.' . $action, ['uid' => $uid, 'role' => (string)$appliedRole], $ctx['uid']);
        apiOk(['acl' => aclLoad()]);
    }

    // action === 'delete'
    if ($kind === 'dept') {
        aclUpdate(function (array $acl) use ($dept): array {
            $acl['depts'] = array_values(array_filter($acl['depts'], fn($d) => (int)$d['id'] !== $dept));
            return $acl;
        });
        // Пропуска сотрудников этого отдела гасим все разом: роль в них уже выдана,
        // и без сброса человек доработал бы день с доступом, который сняли.
        revokeSessionsForDepartment($dept);
        auditLog('acl.delete', ['dept' => $dept], $ctx['uid']);
        apiOk(['acl' => aclLoad()]);
    }

    aclUpdate(function (array $acl) use ($uid, &$fail): array {
        if ($uid === $acl['owner_uid']) {
            $fail = ['code' => 400, 'msg' => 'Нельзя удалить владельца приложения'];
            return $acl;
        }
        $acl['users'] = array_values(array_filter($acl['users'], fn($u) => (int)$u['uid'] !== $uid));
        return $acl;
    });

    if ($fail) apiFail($fail['msg'], $fail['code'], 'acl_error');
    revokeSessionsForUser($uid);      // открытая вкладка сотрудника перестаёт работать сразу
    auditLog('acl.delete', ['uid' => $uid], $ctx['uid']);
    apiOk(['acl' => aclLoad()]);

});
