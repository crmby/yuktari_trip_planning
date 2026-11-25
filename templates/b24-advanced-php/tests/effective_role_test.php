<?php
declare(strict_types=1);
suite('Единая роль сессии для UI и endpoints');
storeWrite(B24_TOKENS_FILE, ['access_token'=>'fixture-access','refresh_token'=>'fixture-refresh',
    'domain'=>'example.bitrix24.ru','member_id'=>'ROLE-FIXTURE','install_generation'=>'role-generation','expires_at'=>time()+3600]);
storeWrite(ACL_FILE, ['owner_uid'=>10,'users'=>[['uid'=>10,'role'=>'admin'],['uid'=>20,'role'=>'view'],['uid'=>21,'role'=>'edit']], 'depts'=>[]]);
storeWrite(SESSIONS_FILE, []);
foreach ([
    [20,true,false,'admin'], [21,true,false,'admin'], [22,true,false,'admin'],
    [20,false,false,'view'], [21,false,false,'edit'], [20,true,true,'view'], [22,true,true,null],
] as $i => [$uid,$admin,$expired,$expected]) {
    $row=createSession($uid,'view',$admin);
    if ($expired) sessionStoreUpdate(static function(array $rows) use($row): array {
        foreach($rows as &$saved) if($saved['token']===$row['token']) $saved['adminCheckedAt']=time()-ADMIN_BYPASS_TTL-10;
        return $rows;
    });
    $actual=findSessionByToken($row['token']);
    check('effective role: обычный вариант '.$i, ($actual['role']??null)===$expected,
        'role='.(string)($actual['role']??'none'));
}
