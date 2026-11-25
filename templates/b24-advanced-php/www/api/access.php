<?php
declare(strict_types=1);

// ПРЯМОЙ HTTP К ЭТОМУ ФАЙЛУ — 404. Деплой публикует каталог целиком, поэтому под
// веб-корнем лежит и то, что вызывают только по require. Пустой ответ 200
// подтверждает существование файла и его путь (находка ревью 08.08.2026,
// BXREV-025). CLI и подключение из другого файла проверка не задевает: она
// смотрит, запрошен ли ИМЕННО этот файл.
if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
/**
 * api/access.php — права доступа приложения (файловый ACL).
 *
 * МОДЕЛЬ ПО УМОЛЧАНИЮ (как в miraphone-raspredelenie, но на файловом сторе):
 * приложение видно ТОЛЬКО тем, кто в списке доступа, и тому, кто его установил
 * (owner). Остальным — заглушка «Нет доступа». Администраторы портала имеют
 * доступ всегда (страховка от самоблокировки + управление списком).
 *
 * Хранилище: ACL_FILE — private shared .php-стор вне webroot, дополнительно
 * закрытый 404+exit-префиксом (не .json).
 *   { "owner_uid": int, "users": [ {uid, name, photo, role}, ... ] }
 * Роли: 'admin' (управляет доступом) | 'edit' | 'view'.
 * Владельца нельзя понизить/удалить — защита от самоблокировки.
 *
 * Файл без побочных эффектов: только функции. Гейтом рулит session.php.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';   // maskSecrets/logAppError — нужны журналу действий

const ACL_ROLES = ['admin', 'edit', 'view'];
if (!defined('ACL_MAX_ENTRIES')) define('ACL_MAX_ENTRIES', 2048);
if (!defined('ACL_MAX_BYTES')) define('ACL_MAX_BYTES', 512 * 1024);

/** Нормализованный ACL не помещается в предметный count/byte contract. */
class AclStateLimitException extends RuntimeException {
    public string $reason;
    public int $actual;
    public int $limit;
    public function __construct(string $reason, int $actual, int $limit) {
        $this->reason = $reason;
        $this->actual = $actual;
        $this->limit = $limit;
        parent::__construct('ACL: превышен предел ' . $reason . ' (' . $actual . ' > ' . $limit . ')');
    }
}

/** Привести сырое содержимое файла к ожидаемой форме списка доступа. */
function aclNormalize(?array $d): array {
    if (!is_array($d)) $d = [];
    $ownerUid = max(0, (int)($d['owner_uid'] ?? 0));
    $users = [];
    $seenUsers = [];
    foreach (is_array($d['users'] ?? null) ? $d['users'] : [] as $raw) {
        if (!is_array($raw)) continue;
        $uid = (int)($raw['uid'] ?? 0);
        if ($uid <= 0 || isset($seenUsers[$uid])) continue;
        $seenUsers[$uid] = true;
        $raw['uid'] = $uid;
        $raw['name'] = is_scalar($raw['name'] ?? null) ? (string)$raw['name'] : ('B24#' . $uid);
        $raw['photo'] = is_scalar($raw['photo'] ?? null) ? (string)$raw['photo'] : '';
        $role = is_scalar($raw['role'] ?? null) ? (string)$raw['role'] : 'view';
        $raw['role'] = $uid === $ownerUid ? 'admin'
            : (in_array($role, ACL_ROLES, true) ? $role : 'view');
        $users[] = $raw;
    }
    // Owner — инвариант, а не обычная ACL-строка: malformed/duplicate cleanup
    // не вправе удалить его или оставить с пониженной ролью.
    if ($ownerUid > 0 && !isset($seenUsers[$ownerUid])) {
        $users[] = ['uid' => $ownerUid, 'name' => 'B24#' . $ownerUid, 'photo' => '', 'role' => 'admin'];
    }

    $depts = [];
    $seenDepts = [];
    foreach (is_array($d['depts'] ?? null) ? $d['depts'] : [] as $raw) {
        if (!is_array($raw)) continue;
        $id = (int)($raw['id'] ?? 0);
        if ($id <= 0 || isset($seenDepts[$id])) continue;
        $seenDepts[$id] = true;
        $raw['id'] = $id;
        $raw['name'] = is_scalar($raw['name'] ?? null) ? (string)$raw['name'] : ('Отдел #' . $id);
        $role = is_scalar($raw['role'] ?? null) ? (string)$raw['role'] : 'view';
        $raw['role'] = in_array($role, ACL_ROLES, true) ? $role : 'view';
        $depts[] = $raw;
    }

    return [
        'owner_uid' => $ownerUid,
        'users'     => $users,
        // Доступ, выданный ОТДЕЛУ. Ключ появился позже пользователей: у старых
        // файлов его нет, и отсутствие обязано читаться как «отделов нет», а не
        // ронять разбор — иначе обновление ядра отбирает доступ у всех сразу.
        'depts'     => $depts,
    ];
}

/** Число независимо растущих ACL-строк после нормализации. */
function aclStateEntryCount(array $acl): int {
    return count($acl['users'] ?? []) + count($acl['depts'] ?? []);
}

/** Точный on-disk размер — тем же encoder, которым пишет store. */
function aclStoredBytes(array $acl): int {
    try {
        return strlen(storeEncode($acl));
    } catch (Throwable $e) {
        return PHP_INT_MAX;
    }
}

/** Проверить count/byte contract уже нормализованного состояния. */
function aclAssertStateWithinLimits(array $acl): void {
    $entries = aclStateEntryCount($acl);
    if ($entries > ACL_MAX_ENTRIES) {
        throw new AclStateLimitException('entries', $entries, ACL_MAX_ENTRIES);
    }
    $bytes = aclStoredBytes($acl);
    if ($bytes > ACL_MAX_BYTES) {
        throw new AclStateLimitException('bytes', $bytes, ACL_MAX_BYTES);
    }
}

function aclLoad(): array {
    // Caller-specific ceiling действует ДО JSON decode. Legacy oversize нельзя
    // ни декодировать, ни «лечить» записью пустого ACL поверх.
    $acl = aclNormalize(storeRead(ACL_FILE, ACL_MAX_BYTES));
    aclAssertStateWithinLimits($acl);
    return $acl;
}

/**
 * Общая граница коротких изменений ACL и сессий.
 * Порядок один: lifecycle → authorization → конкретный store.
 * Вложенный вызов продолжает ту же операцию, не берёт второй файловый замок.
 */
function appWithAuthorizationLock(callable $work) {
    static $inside = false;
    if ($inside) return $work();
    return appWithLifecycleReadLock(static function () use ($work, &$inside) {
        return withKeyLock('authorization-state', static function () use ($work, &$inside) {
            $inside = true;
            try { return $work(); }
            finally { $inside = false; }
        });
    });
}

/**
 * Изменить список доступа под замком.
 *
 * Только так: список правят из интерфейса, а параллельно его же трогает
 * установка (фиксация владельца). «Прочитал → поправил → записал» здесь
 * означало бы, что добавленный сотрудник исчезает, если в ту же секунду
 * приложение переустановили.
 *
 * @param callable $mutator function(array $acl): array — вернуть новый список.
 * @return array Список, который лежит на диске после вызова.
 */
function aclUpdate(callable $mutator): array {
    return appWithAuthorizationLock(static function () use ($mutator): array {
        $saved = aclNormalize(storeUpdate(ACL_FILE, static function (array $onDisk) use ($mutator): array {
            $acl = $mutator(aclNormalize($onDisk));
            $acl = aclNormalize(is_array($acl) ? $acl : []);
            aclAssertStateWithinLimits($acl); // до storeWriteRaw: на диске остаётся прежнее
            return $acl;
        }, STORE_LOCK_TIMEOUT, ACL_MAX_BYTES));
        aclAssertStateWithinLimits($saved);
        return $saved;
    });
}

function aclOwnerUid(): int { return aclLoad()['owner_uid']; }

/**
 * Зафиксировать владельца (установщика). Идемпотентно — зовётся при установке.
 * Владелец всегда попадает в список как admin.
 */
function aclSetOwner(int $uid, string $name = '', string $photo = ''): void {
    if ($uid <= 0) return;
    aclUpdate(static function (array $acl) use ($uid, $name, $photo): array {
        $acl['owner_uid'] = $uid;
        $found = false;
        foreach ($acl['users'] as &$u) {
            if ((int)$u['uid'] === $uid) {
                $u['role'] = 'admin';
                if ($name  !== '') $u['name']  = $name;
                if ($photo !== '') $u['photo'] = $photo;
                $found = true;
            }
        }
        unset($u);
        if (!$found) {
            $acl['users'][] = ['uid' => $uid, 'name' => $name !== '' ? $name : ('B24#' . $uid), 'photo' => $photo, 'role' => 'admin'];
        }
        return $acl;
    });
}

/**
 * Передать владение действующему portal-admin, который выполняет запрос.
 * Старый владелец удаляется из ACL в той же атомарной записи: даже если процесс
 * умрёт до физической уборки sessions.php, следующий запрос старого пропуска
 * уже fail-closed на перечитывании роли.
 *
 * @return array{changed:bool,old_owner_uid:int,new_owner_uid:int}
 */
function aclTransferOwner(int $newUid): array {
    if ($newUid <= 0) throw new InvalidArgumentException('Неверный новый владелец');
    $result = ['changed' => false, 'old_owner_uid' => 0, 'new_owner_uid' => $newUid];

    aclUpdate(static function (array $acl) use ($newUid, &$result): array {
        $oldUid = (int)$acl['owner_uid'];
        $result['old_owner_uid'] = $oldUid;
        if ($oldUid === $newUid) return $acl;

        $users = [];
        $newOwner = null;
        foreach ($acl['users'] as $user) {
            $uid = (int)($user['uid'] ?? 0);
            if ($uid === $oldUid) continue;       // прежнему owner доступ отзывается
            if ($uid === $newUid) {
                if ($newOwner === null) {
                    $user['uid'] = $newUid;
                    $user['role'] = 'admin';
                    $newOwner = $user;
                }
                continue;                         // заодно не оставляем дубликаты
            }
            $users[] = $user;
        }
        $users[] = $newOwner ?? [
            'uid' => $newUid, 'name' => 'B24#' . $newUid, 'photo' => '', 'role' => 'admin',
        ];
        $acl['owner_uid'] = $newUid;
        $acl['users'] = $users;
        $result['changed'] = true;
        return $acl;
    });

    return $result;
}

/**
 * Роль пользователя: 'admin'|'edit'|'view' или null (нет доступа). Владелец → admin.
 *
 * @param int[] $userDepartments Отделы зрителя (UF_DEPARTMENT из ответа портала
 *        на токен САМОГО зрителя). Пустой список = проверяем только поимённо.
 *        Из тела запроса эти номера брать нельзя никогда: иначе доступ выдаёт
 *        себе любой, кто подставит чужой отдел.
 */
function aclUserRole(int $uid, array $userDepartments = []): ?string {
    if ($uid <= 0) return null;
    return aclRoleIn(aclLoad(), $uid, $userDepartments);
}

/**
 * То же, что aclUserRole(), но по УЖЕ прочитанному списку доступа.
 *
 * Для циклов по пропускам внутри одной операции (ревью 23.09.2026, skorost-7):
 * aclUserRole() на каждой строке перечитывал и разбирал весь список с диска —
 * до 512 разборов подряд под замком авторизации. Снимок берут один раз в той же
 * операции и под тем же замком; кешировать его между запросами нельзя — снятие
 * доступа обязано действовать сразу.
 *
 * @param array $acl Результат aclLoad() этой же операции.
 */
function aclRoleIn(array $acl, int $uid, array $userDepartments = []): ?string {
    if ($uid <= 0) return null;
    if ($acl['owner_uid'] === $uid) return 'admin';
    foreach ($acl['users'] as $u) {
        if ((int)$u['uid'] === $uid) {
            $r = (string)($u['role'] ?? 'view');
            return in_array($r, ACL_ROLES, true) ? $r : 'view';
        }
    }
    // Доступ по отделу — ниже поимённого: если человек указан отдельно, его
    // персональная роль главнее (её выдавали осознанно, обычно понижая).
    $best = null;
    foreach ($acl['depts'] as $d) {
        $deptId = (int)($d['id'] ?? 0);
        if ($deptId <= 0 || !in_array($deptId, array_map('intval', $userDepartments), true)) continue;
        $r = (string)($d['role'] ?? 'view');
        if (!in_array($r, ACL_ROLES, true)) $r = 'view';
        if ($best === null || roleAtLeast($r, $best)) $best = $r;
    }
    return $best;
}

function aclHasAccess(int $uid): bool { return aclUserRole($uid) !== null; }

/**
 * Роль не ниже требуемой? Порядок: view < edit < admin.
 *
 * Одна функция на всё приложение. Раньше каждый эндпоинт сравнивал роли сам —
 * и однажды сравнение вышло вида `$role === 'admin' || $role === 'edit'`, куда
 * при добавлении роли забыли дописать новую: человек с правом правки получал
 * отказ на своей же кнопке.
 */
function roleAtLeast(?string $role, string $needed): bool {
    $rank = ['view' => 1, 'edit' => 2, 'admin' => 3];
    $have = $rank[(string)$role] ?? 0;
    $want = $rank[$needed] ?? 99;
    return $have >= $want;
}

/**
 * Записать действие в журнал: кто, что, над чем, чем закончилось.
 *
 * Зачем это в шаблоне. В Битриксе любой наш вызов выглядит как действие
 * УСТАНОВЩИКА приложения — по логам портала нельзя понять, кто именно нажал
 * кнопку. Значит, единственное место, где остаётся настоящий автор, — наш
 * собственный журнал. Без него на вопрос «кто удалил эти сделки» ответа нет.
 *
 * Пишем в defensive-режиме: журнал не должен ронять операцию, ради которой его
 * ведут. Не записалось — операция всё равно идёт дальше.
 */
function auditLog(string $action, array $details = [], int $uid = 0): void {
    // Обёртка над общим журналом приложения: сигнатура прежняя, поэтому места
    // вызова не менялись. Раньше действия писались в отдельный файл, который не
    // читал никто и ничем — ни экрана, ни команды. Теперь это тот же журнал, где
    // лежат сбои и работа крона, только помеченный как действие человека.
    try {
        require_once __DIR__ . '/journal.php';
        appLog('info', $action, $details, ['kind' => 'action', 'uid' => $uid, 'msg' => $action]);
    } catch (Throwable $e) {
        // Журнал не должен ронять операцию, ради которой его ведут.
    }
}

/**
 * Не допустить слепого повторения операции с внешним эффектом.
 *
 * Зачем. Человек жмёт кнопку дважды, сеть роняет ответ и фронт повторяет
 * запрос, планировщик запускает обработку внахлёст — и в CRM появляется второй
 * такой же счёт. Ключ операции (его задаёт вызывающий: например
 * 'deal-create:' . $dealId) запоминается вместе с результатом; повтор возвращает
 * прежний результат, а не делает работу заново. Если callback завершился
 * исключением или процесс оборвался, исход считается `unknown`: автоматического
 * повтора нет до read-back и явного runOnceForget().
 *
 * @param callable $work function(): array — сама операция, её результат сохраняется.
 * @return array ['result' => array, 'repeated' => bool]
 */
/**
 * Забыть операцию: следующий вызов runOnce с этим ключом выполнит работу заново.
 *
 * Нужно после статуса `unknown`: прошлая попытка оборвалась на неизвестном
 * месте, вызывающий проверил по своим данным, что работа НЕ сделана, — и снимает
 * метку. Без этой функции неизвестный исход остаётся заблокирован бессрочно:
 * время само по себе не доказывает, что внешний эффект не произошёл.
 */
if (!defined('RUN_ONCE_DEFAULT_TTL')) define('RUN_ONCE_DEFAULT_TTL', 604800);

/**
 * Потолки журнала операций — те же, что давно стоят у очереди событий.
 *
 * Без них файл рос линейно по числу мутаций за неделю, а читается он ЦЕЛИКОМ и
 * дважды на каждую операцию (захват ключа и фиксация результата). Замер на копии
 * шаблона: 5 000 записей — 109 мс и 22 МиБ на одну мутацию, 20 000 — 356 мс и
 * 64 МиБ, 50 000 — 970 мс и 152 МиБ, то есть выше типового memory_limit=128M.
 * Хуже всего момент фатала: работа в портале уже сделана, а результат записать
 * не успели — ключ остаётся `running`/`unknown` и снимается только руками.
 */
if (!defined('RUN_ONCE_MAX_BYTES')) define('RUN_ONCE_MAX_BYTES', 2 * 1048576);
/** Сколько байт результата храним. Больше — на повторе вернём пометку, а не данные. */
if (!defined('RUN_ONCE_RESULT_MAX_BYTES')) define('RUN_ONCE_RESULT_MAX_BYTES', 8192);

/**
 * Файл журнала операций уже разросся — не декодировать, а честно отказать.
 * Иначе первый же storeRead даёт OOM ровно там, где идёт боевая мутация.
 */
function runOnceAssertFileWithinByteLimit(?int $limit = null): void {
    if (!is_file(OPS_FILE)) return;
    $limit = $limit ?? RUN_ONCE_MAX_BYTES;
    clearstatcache(true, OPS_FILE);
    $bytes = @filesize(OPS_FILE);
    if (!is_int($bytes) || $bytes > $limit) {
        throw new RuntimeException('runOnce: журнал операций больше предела ' . $limit
            . ' байт. Откройте экран состояния и разберите операции с неизвестным исходом;'
            . ' если экран недоступен, запустите php www/bin/process.php вручную');
    }
}

/**
 * Результат в журнал кладём урезанным по размеру: он хранится ради повтора, а не
 * как копия ответа портала. Крупный ответ (список сущностей, выгрузка) раздувал
 * файл до предела за сотни операций.
 */
function runOnceStorableResult(array $result): array {
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    if (is_string($json) && strlen($json) <= RUN_ONCE_RESULT_MAX_BYTES) return $result;
    $kept = [];
    // Идентификаторы — единственное, что реально нужно повтору: по ним вызывающий
    // находит созданную сущность вместо того, чтобы создавать вторую.
    foreach (['id', 'ID', 'ok', 'result_id'] as $k) {
        if (array_key_exists($k, $result) && is_scalar($result[$k])) $kept[$k] = $result[$k];
    }
    $kept['truncated'] = true;
    return $kept;
}

/**
 * Сколько байт журнал операций займёт НА ДИСКЕ.
 *
 * Считать «примерно» нельзя: предохранитель смотрит на реальный `filesize`, а
 * стор пишет с отступами и exit-префиксом — компактный JSON почти вдвое меньше.
 * С прикидкой дожим считал, что уложился в предел, файл оставался больше него, и
 * тупик «каждая мутация в портале отказывает до конца недели» не размыкался,
 * хотя уборка честно рапортовала успех. Меряем той же функцией, что и пишет.
 */
function runOnceStoredBytes(array $ops): int {
    try {
        return strlen(storeEncode($ops));
    } catch (Throwable $e) {
        return PHP_INT_MAX;   // не сериализуется — считаем «больше любого предела»
    }
}

/**
 * Убрать из журнала операций всё, что уже нельзя повторить по TTL.
 *
 * Зовётся из фоновой задачи: раньше единственная уборка шла внутри мутатора на
 * каждой боевой операции, то есть цену чистки платил пользователь, а не cron.
 *
 * @return array{было:int,стало:int}
 */
/**
 * Оставить ли запись ключа в реестре — ОДНО правило на обе уборки.
 *
 * Раньше этот предикат был скопирован дословно в двух местах: в уборке по
 * времени и в захвате ключа. Копии живут ровно до первой правки одной из них, а
 * расходятся МОЛЧА: одна уборка начнёт выбрасывать то, что другая бережёт, и
 * повторный вызов выполнит внешний эффект второй раз (находка ревью 09.08.2026).
 *
 * Что здесь важно и почему так:
 *  - `running` и `unknown` не выбрасываются НИКОГДА по времени. Первое — идёт
 *    прямо сейчас, второе — исход неизвестен, и снять его может только человек
 *    после read-back (`runOnceForget`). Забыть их по TTL значит повторить
 *    списание, отправку или создание сущности.
 *  - У старых записей своего срока не было: для них берём безопасный default, а
 *    не TTL текущего вызова — иначе чужой длинный TTL воскресит просроченное.
 */
function runOnceEntryAlive($zapis, int $now): bool {
    if (!is_array($zapis)) return false;
    $status = (string)($zapis['status'] ?? '');
    if ($status === 'running' || $status === 'unknown') return true;
    if ($status !== 'done') return false;
    $expiresAt = (int)($zapis['expires_at'] ?? 0);
    if ($expiresAt <= 0) {
        $storedTtl = max(1, (int)($zapis['ttl'] ?? RUN_ONCE_DEFAULT_TTL));
        $expiresAt = (int)($zapis['at'] ?? 0) + $storedTtl;
    }
    return $expiresAt > $now;
}

function runOncePrune(): array {
    // Уборке нужен ДРУГОЙ порог, иначе получается тупик: файл перерос предел →
    // веб-путь отказывается работать → и единственный инструмент, который мог бы
    // его починить, отказывается тоже, потому что проверяет тот же предел.
    // Cron идёт из CLI, где памяти обычно больше, поэтому здесь потолок выше;
    // выше него — уже разбор руками, автоматике там делать нечего.
    runOnceAssertFileWithinByteLimit(RUN_ONCE_MAX_BYTES * 8);
    runOnceMarkAbandonedRunningUnknown();
    $before = 0;
    $now = time();
    $after = storeUpdate(OPS_FILE, static function (array $ops) use ($now, &$before): array {
        $before = count($ops);
        // Правило «оставлять или нет» — одно на всё приложение: runOnceEntryAlive().
        return array_filter($ops, static fn($r): bool => runOnceEntryAlive($r, $now));
    });

    // Уборки по TTL мало. Записи живут неделю, а предел упирается в байты: пачка
    // свежих операций переполняет файл за часы — и тогда КАЖДАЯ мутация в портале
    // отказывает до конца недели, потому что удалять по сроку ещё нечего. Поэтому
    // переполненный журнал дожимаем по возрасту: самые старые завершённые уходят
    // досрочно. Плата известна и мала — по такой операции повтор перестанет
    // возвращать прежний результат раньше срока; альтернатива — стоящее
    // приложение. running и unknown не трогаем и здесь: их исход неизвестен,
    // снимать такие ключи имеет право только человек (forget-once).
    if (runOnceStoredBytes($after) > RUN_ONCE_MAX_BYTES) {
        $dropped = 0;
        $after = storeUpdate(OPS_FILE, static function (array $ops) use (&$dropped): array {
            $keep = [];   // running/unknown — неприкосновенны
            $aged = [];   // done — кандидаты на досрочное снятие, от старых к новым
            foreach ($ops as $k => $r) {
                if (!is_array($r)) continue;
                $status = (string)($r['status'] ?? '');
                if ($status === 'running' || $status === 'unknown') { $keep[$k] = $r; continue; }
                $aged[$k] = $r;
            }
            uasort($aged, static fn(array $a, array $b): int => ((int)($a['at'] ?? 0)) <=> ((int)($b['at'] ?? 0)));
            // Цель — половина предела, чтобы дожимать раз в сутки, а не каждый час.
            $target = intdiv(RUN_ONCE_MAX_BYTES, 2);
            // Размер считаем вычитанием, а не пересчётом всего массива на каждом
            // шаге: на журнале в десятки тысяч записей второй способ — это минуты
            // работы cron и та же нехватка памяти, от которой мы и защищаемся.
            $total = runOnceStoredBytes($keep + $aged);
            foreach (array_keys($aged) as $k) {
                if ($total <= $target) break;
                // Вклад записи считаем в тех же байтах, что и весь файл: с
                // отступами. Иначе вычитание идёт медленнее реального роста и
                // цикл останавливается раньше, чем файл уйдёт под предел.
                $chunk = json_encode([$k => $aged[$k]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $total -= is_string($chunk) ? max(1, strlen($chunk) - 2) : 1;
                // Не array_shift: ключи журнала строковые, а он переиндексирует
                // числовые — ключ операции поехал бы и повтор перестал бы её узнавать.
                unset($aged[$k]);
                $dropped++;
            }
            return $keep + $aged;
        });
        if ($dropped > 0 && function_exists('logAppError')) {
            logAppError('runOnce: журнал операций переполнен, досрочно снято завершённых ключей: ' . $dropped);
        }
    }

    return ['было' => $before, 'стало' => count($after)];
}

/**
 * Свободная key-lease доказывает, что callback прежнего running уже не жив.
 * Переводим такие записи в unknown, но не повторяем и не удаляем: человек
 * обязан сделать read-back и решить, был ли внешний эффект.
 */
function runOnceMarkAbandonedRunningUnknown(int $limit = 128): int {
    $now = time();
    $ops = storeRead(OPS_FILE) ?? [];
    $held = [];
    foreach ($ops as $key => $record) {
        if (count($held) >= max(1, $limit)) break;
        if (!is_array($record) || ($record['status'] ?? '') !== 'running'
            || (int)($record['at'] ?? 0) + 300 > $now) continue;
        try {
            [$lease, $path] = runOnceLeaseAcquire((string)$key);
        } catch (Throwable $e) {
            continue;
        }
        if (!is_resource($lease)) continue;
        $held[(string)$key] = [
            'lease' => $lease,
            'path' => $path,
            'fingerprint' => runOnceRecordFingerprint($record),
        ];
    }
    if ($held === []) return 0;

    $changed = 0;
    try {
        storeUpdate(OPS_FILE, static function (array $current) use ($held, $now, &$changed): array {
            foreach ($held as $key => $leaseInfo) {
                $record = $current[$key] ?? null;
                if (!is_array($record) || ($record['status'] ?? '') !== 'running'
                    || (int)($record['at'] ?? 0) + 300 > $now
                    || !hash_equals((string)$leaseInfo['fingerprint'], runOnceRecordFingerprint($record))) {
                    continue;
                }
                $current[$key] = [
                    'at' => $now,
                    'status' => 'unknown',
                    'error' => 'процесс оборвался во время работы — исход неизвестен',
                    'attempt' => (string)($record['attempt'] ?? ''),
                    'retaken' => (int)($record['retaken'] ?? 0) + 1,
                ];
                $changed++;
            }
            return $current;
        });
    } finally {
        foreach ($held as $leaseInfo) {
            runOnceLeaseRelease($leaseInfo['lease'], (string)$leaseInfo['path']);
        }
    }
    return $changed;
}

/** Взять короткий общий замок реестра файлов-аренд runOnce. */
function runOnceLeaseRegistryAcquire() {
    $dir = dirname(OPS_FILE);
    storeEnsureDir($dir);
    $path = $dir . '/operations-leases.lock.php';
    $fh = @fopen($path, 'c+b');
    if ($fh === false) throw new RuntimeException('runOnce: не открыть реестр аренд');
    if ((int)@filesize($path) === 0) storeWritePrivateHeader($fh, $path);
    elseif (!storeApplyPrivatePermissions($path)) {
        fclose($fh);
        throw new RuntimeException('runOnce: не выставить приватные права на реестр аренд');
    }
    $deadline = microtime(true) + STORE_LOCK_TIMEOUT;
    do {
        if (@flock($fh, LOCK_EX | LOCK_NB)) return $fh;
        usleep(20000);
    } while (microtime(true) < $deadline);
    fclose($fh);
    throw new RuntimeException('runOnce: реестр аренд занят дольше ' . STORE_LOCK_TIMEOUT . ' с');
}

/**
 * Попытаться взять аренду конкретного ключа.
 *
 * Файл создаётся/открывается только под registry-lock. Благодаря этому release
 * может безопасно unlink-нуть его, не создавая два разных inode с двумя
 * независимыми flock для одного ключа.
 *
 * @return array{0:mixed,1:string} [resource|null, path]
 */
function runOnceLeaseAcquire(string $key): array {
    $registry = runOnceLeaseRegistryAcquire();
    $path = dirname(OPS_FILE) . '/operation-lease-' . hash('sha256', $key) . '.lock.php';
    try {
        $lease = @fopen($path, 'c+b');
        if ($lease === false) throw new RuntimeException('runOnce: не открыть аренду операции');
        if ((int)@filesize($path) === 0) storeWritePrivateHeader($lease, $path);
        elseif (!storeApplyPrivatePermissions($path)) {
            fclose($lease);
            throw new RuntimeException('runOnce: не выставить приватные права на файл аренды');
        }
        if (!@flock($lease, LOCK_EX | LOCK_NB)) {
            fclose($lease);
            return [null, $path];
        }
        return [$lease, $path];
    } finally {
        flock($registry, LOCK_UN);
        fclose($registry);
    }
}

/** Освободить аренду и убрать её одноразовый файл без inode-race. */
function runOnceLeaseRelease($lease, string $path): void {
    if (!is_resource($lease)) return;
    try {
        $registry = runOnceLeaseRegistryAcquire();
    } catch (Throwable $e) {
        flock($lease, LOCK_UN);
        fclose($lease);
        return;
    }
    try {
        if (is_file($path) && !is_link($path)) @unlink($path);
        flock($lease, LOCK_UN);
        fclose($lease);
    } finally {
        flock($registry, LOCK_UN);
        fclose($registry);
    }
}

/** Ответ по уже известному состоянию операции. */
function runOnceKnownResult(?array $known): ?array {
    if ($known === null) return null;
    if (($known['status'] ?? '') === 'done') {
        return ['result' => (array)($known['result'] ?? []), 'repeated' => true];
    }
    if (($known['status'] ?? '') === 'unknown') {
        return ['result' => ['unknown' => true, 'error' => (string)($known['error'] ?? '')], 'repeated' => true];
    }
    return ['result' => ['pending' => true], 'repeated' => true];
}

/**
 * Снятие отменено: запись ключа уже не та, которую видел решающий человек.
 *
 * Отдельный класс, а не общий RuntimeException: вызывающий обязан отличать «ключ
 * занят живой операцией» от «состояние изменилось», иначе экран показывает одну
 * и ту же причину на два разных случая и советует не то.
 */
class RunOnceStaleForgetException extends RuntimeException {}

/**
 * Отпечаток записи журнала операций: «то же ли это состояние, которое видели».
 *
 * Считается по ВСЕЙ записи, а не по одному полю: у перезахваченной попытки
 * `attempt` вообще не сохраняется, и сверка по нему пропустила бы подмену. Ключи
 * сортируем рекурсивно — порядок полей в JSON не должен решать ничего.
 */
function runOnceRecordFingerprint($record): string {
    if (!is_array($record)) return '';
    $canon = static function ($value) use (&$canon) {
        if (!is_array($value)) return $value;
        ksort($value);
        foreach ($value as $k => $item) $value[$k] = $canon($item);
        return $value;
    };
    $json = json_encode($canon($record), JSON_UNESCAPED_UNICODE);
    return is_string($json) ? hash('sha256', $json) : '';
}

/**
 * Снять ключ операции.
 *
 * @param array|null $expected Запись, на которую человек СМОТРЕЛ, принимая решение.
 *        Передана — под арендой сверяем, что в журнале лежит ровно она, и только
 *        тогда удаляем. Не передана — снятие ПРИНУДИТЕЛЬНОЕ, без сверки.
 *
 * Зачем сверка (находка ревью R3-002 от 09.08.2026). Между чтением записи и
 * снятием ключа проходит время: за него ключ успевает уйти и вернуться — его
 * сняли из командной строки, операция прошла заново и записала «сделано».
 * Безусловное удаление стирало именно это «сделано», то есть единственное
 * доказательство, что внешний эффект уже произошёл, — и следующий вызов
 * runOnce() списывал деньги второй раз.
 *
 * Почему принудительный путь ОБЯЗАН остаться. Записи `unknown` не уходят по
 * времени и не выбрасываются уборкой: только человек вправе их снять. Если
 * единственный способ снятия начнёт требовать совпадения состояния, залипший
 * ключ становится неснимаемым, журнал операций дорастает до потолка — и тогда
 * отказывают уже ВСЕ мутации приложения. Поэтому командная строка сервера
 * (`php www/bin/process.php forget-once <ключ>`) сверки не делает: это последний
 * рубеж, и упираться он не должен ни во что.
 *
 * Кого сверка отсекает кроме гонки: администратора, чей запрос отстал на
 * миллисекунды. Ему возвращается отказ «состояние изменилось, обновите экран», а
 * повторный клик читает запись заново и снимает её штатно.
 */
function runOnceForget(string $key, ?array $expected = null): void {
    if ($key === '') return;
    [$lease, $leasePath] = runOnceLeaseAcquire($key);
    if (!is_resource($lease)) {
        throw new RuntimeException('runOnce: операция ещё выполняется — снимать её ключ нельзя');
    }
    try {
        $expectedPrint = $expected === null ? '' : runOnceRecordFingerprint($expected);
        $conflict = '';
        storeUpdate(OPS_FILE, static function (array $ops) use ($key, $expected, $expectedPrint, &$conflict): array {
            $current = is_array($ops[$key] ?? null) ? $ops[$key] : null;
            // Ключа уже нет — удалять нечего, значит и стереть чужое доказательство
            // нечем. Это не конфликт: нужное состояние уже достигнуто.
            if ($current === null) return $ops;
            if ($expected !== null && runOnceRecordFingerprint($current) !== $expectedPrint) {
                $conflict = (string)($current['status'] ?? '');
                return $ops;
            }
            unset($ops[$key]);
            return $ops;
        });
        if ($conflict !== '') {
            throw new RunOnceStaleForgetException(
                'runOnce: состояние операции изменилось (сейчас «' . $conflict . '») — снятие ключа отменено');
        }
    } finally {
        runOnceLeaseRelease($lease, $leasePath);
    }
}

function runOnce(string $key, callable $work, int $ttl = RUN_ONCE_DEFAULT_TTL): array {
    if ($key === '') throw new InvalidArgumentException('runOnce: пустой ключ операции');
    if ($ttl <= 0) throw new InvalidArgumentException('runOnce: срок хранения результата должен быть положительным');
    $now = time();
    $known = null;
    $attempt = bin2hex(random_bytes(16));

    // Живой callback держит отдельную аренду ключа. Возраст записи сам по себе
    // больше не считается доказательством смерти процесса: долгий callback может
    // законно работать дольше пяти минут.
    // Проверяем размер ДО первого чтения: разросшийся файл должен дать понятную
    // ошибку, а не фатал по памяти посреди мутации портала.
    runOnceAssertFileWithinByteLimit();
    [$lease, $leasePath] = runOnceLeaseAcquire($key);
    if (!is_resource($lease)) {
        $ops = storeRead(OPS_FILE) ?? [];
        $known = is_array($ops[$key] ?? null) ? $ops[$key] : null;
        return runOnceKnownResult($known)
            ?? ['result' => ['pending' => true], 'repeated' => true];
    }

    try {
    // Захват ключа и проверка «уже делали» — под одним замком: иначе два
    // одновременных запроса оба увидят «ещё не делали» и оба выполнят работу.
    storeUpdate(OPS_FILE, static function (array $ops) use ($key, $now, $ttl, $attempt, &$known): array {
        // TTL относится только к ДОКАЗАННО завершённому результату; `running` и
        // `unknown` по времени не выбрасываются вовсе. Правило одно на обе уборки
        // — runOnceEntryAlive(), см. его объяснение.
        $ops = array_filter($ops, static fn($r): bool => runOnceEntryAlive($r, $now));
        if (isset($ops[$key])) {
            $known = $ops[$key];
            // Зависшая попытка: процесс убит, хостинг оборвал по таймауту, упал
            // сервер. Работу здесь НЕ повторяем — исход неизвестен.
            //
            // Раньше ключ просто перезахватывался и работа шла заново. Это
            // закрывало гонку двух recovery-процессов, но не главное окно: между
            // тем, как $work() уже перевёл деньги, и записью «done» лежит промежуток,
            // в который процесс и умирает. Повторное ревью 03.08.2026 убило процесс
            // ровно там — внешний счётчик вырос дважды. Обещание «ровно один раз»
            // при этом оставалось в документации.
            //
            // Теперь такая попытка становится `unknown`: вызывающий обязан
            // посмотреть по своим данным, сделана ли работа, и только потом либо
            // снять метку (runOnceForget) и повторить, либо считать сделанной.
            // Эту ветку выполняет только владелец key-lease. Значит, прежний
            // callback уже точно не жив; один возраст записи больше не достаточен.
            if (($known['status'] ?? '') === 'running' && (int)($known['at'] ?? 0) + 300 <= $now) {
                $ops[$key] = [
                    'at'      => $now,
                    'status'  => 'unknown',
                    'error'   => 'процесс оборвался во время работы — исход неизвестен',
                    'retaken' => (int)($known['retaken'] ?? 0) + 1,
                ];
                $known = $ops[$key];
            }
            return $ops;
        }
        $ops[$key] = ['at' => $now, 'status' => 'running', 'attempt' => $attempt, 'ttl' => $ttl];
        return $ops;
    });

    if ($known !== null) {
        return runOnceKnownResult($known) ?? ['result' => ['pending' => true], 'repeated' => true];
    }

    try {
        $result = $work();
        if (!is_array($result)) $result = ['ok' => true];
    } catch (Throwable $e) {
        // После входа в callback нельзя доказать по классу или тексту ошибки,
        // что внешнее действие не произошло. Обработчик мог сначала создать
        // сущность в портале, а затем бросить самое обычное RuntimeException.
        // Поэтому ЛЮБОЙ сбой здесь — неизвестный исход. Повтор разрешает только
        // вызывающий после read-back, явно сняв ключ через runOnceForget().
        $msg = (string)maskSecrets($e->getMessage());
        $failedAt = time();
        storeUpdate(OPS_FILE, static function (array $ops) use ($key, $msg, $failedAt, $attempt): array {
            $current = $ops[$key] ?? null;
            if (is_array($current) && ($current['attempt'] ?? '') === $attempt) {
                $ops[$key] = ['at' => $failedAt, 'status' => 'unknown', 'error' => $msg, 'attempt' => $attempt];
            }
            return $ops;
        });
        throw $e;
    }

    $finishedAt = time();
    $committed = false;
    storeUpdate(OPS_FILE, static function (array $ops) use (
        $key, $finishedAt, $ttl, $result, $attempt, &$committed
    ): array {
        $current = $ops[$key] ?? null;
        if (!is_array($current) || ($current['attempt'] ?? '') !== $attempt) return $ops;
        $ops[$key] = [
            'at' => $finishedAt,
            'expires_at' => $finishedAt + $ttl,
            'ttl' => $ttl,
            'status' => 'done',
            'result' => runOnceStorableResult($result),
            'attempt' => $attempt,
        ];
        $committed = true;
        return $ops;
    });
    if (!$committed) {
        throw new RuntimeException('runOnce: состояние попытки изменилось до фиксации результата');
    }
    return ['result' => $result, 'repeated' => false];
    } finally {
        runOnceLeaseRelease($lease, $leasePath);
    }
}

/**
 * Зафиксировать владельца приложения = того, кто его установил.
 *
 * Зовётся из ОБОИХ обработчиков установки. Пока этого не произошло, в приложение
 * пускает только администраторов портала — то есть человек, который сам его и
 * ставил, упрётся в заглушку «нет доступа» и решит, что всё сломано. Раньше
 * фиксация стояла лишь в запасном обработчике (api/install.php), а штатный путь —
 * index.php — её не делал вовсе; найдено ревью 29.07.2026.
 *
 * Установку не валит: не вышло — вернём причину, вызывающий её покажет.
 *
 * @return string Пустая строка = владелец записан; иначе причина человеческим языком.
 */
function recordAppOwner(B24 $b24): string {
    try {
        $me = $b24->call('user.current');
        $u  = $me['result'] ?? [];
        if (empty($u['ID'])) return 'портал не вернул данные текущего пользователя';
        aclSetOwner(
            (int)$u['ID'],
            trim((string)($u['NAME'] ?? '') . ' ' . (string)($u['LAST_NAME'] ?? '')),
            (string)($u['PERSONAL_PHOTO'] ?? '')
        );
        return '';
    } catch (Throwable $e) {
        return function_exists('maskSecrets') ? (string)maskSecrets($e->getMessage()) : 'сбой обращения к порталу';
    }
}

/** Один текст на все места, где владельца записать не удалось. */
function ownerNotFixedNotice(string $reason): string {
    if ($reason === '') return '';
    return 'Владельца зафиксировать не удалось (' . $reason . '). Пока это не сделано, '
         . 'доступ в приложение есть только у администраторов портала — откройте его под '
         . 'администратором, владелец запишется автоматически.';
}
