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

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/access.php';

const SESSION_TTL    = 28800;
const SESSION_COOKIE = 'gsp_session';
// Portal POST приходит в index.php и затем используется session-гейтом. Читаем
// его один раз с общим потолком: две независимые raw-ветки расходились и обе
// позволяли большому/chunked запросу занять память до аутентификации.
const PORTAL_POST_MAX_BODY = 262144;
/** Потолки на размер файла сессий: один человек не должен раздувать стор. */
const SESSION_MAX_PER_USER = 16;
// Как часто обновляем отметку «пропуском пользовались» (секунды). Чаще — лишняя
// запись под замком на каждый запрос фронта, реже — вытеснение станет неточным.
const SESSION_TOUCH_EVERY  = 300;
const SESSION_MAX_TOTAL    = 512;
// Count строк не ограничивает JSON: snapshot отделов размножается по каждой
// вкладке. Эти три потолка — единый durable-state contract; transport cap ответа
// портала его не заменяет.
const SESSION_MAX_DEPARTMENTS = 256;
const SESSION_MAX_ROW_BYTES   = 32768;
const SESSION_MAX_BYTES       = 2097152;
/**
 * Сколько живёт «страховка администратора портала» внутри одного пропуска.
 *
 * Роль в списке доступа перечитывается на каждом запросе, а вот признак «это
 * администратор портала» проверяется живым токеном человека только при входе.
 * Пока признак лежал в пропуске все восемь часов, снятие прав администратора и
 * увольнение не действовали до конца дня. Теперь страховка протухает за полчаса:
 * дальше человек либо есть в списке доступа, либо переоткрывает приложение из
 * портала — и портал заново говорит, кто он и админ ли.
 */
const ADMIN_BYPASS_TTL = 1800;
// Передача владельца опаснее обычной admin-операции: право портала должно быть
// подтверждено недавним открытием iframe, а не получасовой страховкой.
const OWNER_TRANSFER_ADMIN_PROOF_TTL = 300;

final class PortalPostBodyTooLargeException extends RuntimeException {}
final class SessionStateLimitException extends RuntimeException {}

/**
 * Поля portal/install POST. Raw-body читается один раз и кэшируется на запрос.
 * Размер проверяется ДО JSON/form decode, включая chunked без Content-Length.
 */
function portalPostFields(): array {
    static $cached = null;
    if (is_array($cached)) return $cached;

    $fields = $_POST;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $cached = $fields;

    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > PORTAL_POST_MAX_BODY) throw new PortalPostBodyTooLargeException('Слишком большой запрос');

    $raw = file_get_contents('php://input', false, null, 0, PORTAL_POST_MAX_BODY + 1);
    if (!is_string($raw)) $raw = '';
    if (strlen($raw) > PORTAL_POST_MAX_BODY) {
        throw new PortalPostBodyTooLargeException('Слишком большой запрос');
    }

    if ($fields === [] && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) $fields = $json;
        else {
            parse_str($raw, $form);
            if (is_array($form) && $form !== []) $fields = $form;
        }
    }
    return $cached = $fields;
}

/** Недавнее доказательство: текущую сессию выдал портал именно своему admin. */
function sessionHasFreshPortalAdminProof(array $session): bool {
    if (empty($session['isAdmin'])) return false;
    $checkedAt = (int)($session['adminCheckedAt'] ?? 0);
    $now = time();
    return $checkedAt > 0 && $checkedAt <= $now
        && ($now - $checkedAt) <= OWNER_TRANSFER_ADMIN_PROOF_TTL;
}

/** Нормализовать authorization snapshot без тихого урезания прав. */
function sessionNormalizeDepartments(array $ids): array {
    $out = [];
    foreach ($ids as $value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) continue;
        $id = (int)$value;
        if ($id <= 0) continue;
        $out[$id] = $id;
        if (count($out) > SESSION_MAX_DEPARTMENTS) {
            throw new SessionStateLimitException('Слишком много подразделений для безопасной сессии');
        }
    }
    $out = array_values($out);
    sort($out, SORT_NUMERIC);
    return $out;
}

/** Размер одной строки в том же JSON-формате, что использует store. */
function sessionRowStoredBytes(array $row): int {
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new SessionStateLimitException('Строка сессии не сериализуется');
    }
    return strlen($json);
}

/** Нормализовать строку и громко отклонить authorization snapshot сверх cap. */
function sessionAssertRowWithinLimits(array $row): array {
    $row['departments'] = sessionNormalizeDepartments((array)($row['departments'] ?? []));
    if (sessionRowStoredBytes($row) > SESSION_MAX_ROW_BYTES) {
        throw new SessionStateLimitException('Строка сессии больше допустимого предела');
    }
    return $row;
}

/** Точный размер всего private PHP state-файла. */
function sessionStoredBytes(array $sessions): int {
    return strlen(storeEncode($sessions));
}

/**
 * Оставить самый длинный свежий prefix, который помещается в byte cap.
 * Размер prefix монотонен, поэтому binary search не превращает cleanup в O(n²).
 */
function sessionsBoundToBytes(array $sessions, int $maxBytes): array {
    if ($sessions === [] || $maxBytes < sessionStoredBytes([])) return [];
    $low = 0;
    $high = count($sessions);
    while ($low < $high) {
        $mid = intdiv($low + $high + 1, 2);
        if (sessionStoredBytes(array_slice($sessions, 0, $mid)) <= $maxBytes) $low = $mid;
        else $high = $mid - 1;
    }
    return array_slice($sessions, 0, $low);
}

/** Единственная bounded read-точка session state, включая legacy pre-decode. */
function sessionStoreRead(): ?array {
    return storeRead(SESSIONS_FILE, SESSION_MAX_BYTES);
}

/** Единственная bounded RMW-точка: normalize/count/bytes выполняются под EX lock. */
function sessionStoreUpdate(callable $mutator): array {
    return appWithAuthorizationLock(static function () use ($mutator): array {
        return storeUpdate(SESSIONS_FILE, static function (array $all) use ($mutator): ?array {
            $next = $mutator($all);
            return is_array($next) ? sessionsBound($next) : null;
        }, STORE_LOCK_TIMEOUT, SESSION_MAX_BYTES);
    });
}

function loadSessions(): array {
    $d = sessionStoreRead();
    return is_array($d) ? $d : [];
}

/** Legacy-generation выдаём до SH: повышать shared lifecycle-lock до EX нельзя. */
function sessionPrepareLifecycleRead(): void {
    $tokens = storeRead(B24_TOKENS_FILE);
    if (is_array($tokens) && !empty($tokens['access_token'])
        && empty($tokens['uninstalled_at']) && empty($tokens['cleanup_pending'])
        && appInstallGenerationFrom($tokens) === '') {
        appEnsureInstallGeneration();
    }
}

/**
 * К какому порталу привязана текущая установка.
 *
 * Нужно, чтобы пропуска не пережили переезд приложения на другой портал: после
 * переустановки member_id меняется, и старая сессия обязана перестать работать —
 * иначе сотрудник прежнего портала продолжает видеть данные нового владельца.
 */
/** @return array{member_id:string,install_generation:string} */
function sessionInstallationIdentity(): array {
    try {
        $tokens = storeRead(B24_TOKENS_FILE);
        $tokensArr = is_array($tokens) ? $tokens : [];
        // b24Portal() — единый channel/tenant resolver. При mixed-channel
        // mismatch он бросает исключение, а этот гейт возвращает пустую
        // identity и гасит все старые сессии до любого доступа к данным.
        $canonicalPortal = b24Portal();
        // Работа постоянным ключом: установки под приложением нет, значит нет и
        // токенов — а «свой портал» при этом известен точно, он записан в самом
        // ключе. Без этой ветки каждый выданный пропуск гасился как «пропуск с
        // другого портала»: страница открывалась, но любой её экран получал
        // «доступ только из Bitrix24» (живой случай, 18.08.2026). Удаление и
        // уборку ключ не перебивает — они проверены ниже и по-прежнему главнее.
        if (empty($tokensArr['access_token'])
            && empty($tokensArr['uninstalled_at']) && empty($tokensArr['cleanup_pending'])) {
            $klyuch = b24WebhookBase();
            if ($klyuch !== '') {
                return [
                    // «Портал» здесь — его имя из ключа: оно и отличает одну
                    // установку от другой, когда member_id взять неоткуда.
                    'member_id' => is_string($canonicalPortal) && $canonicalPortal !== ''
                        ? 'wh:' . $canonicalPortal : '',
                    'install_generation' => appKeylessInstallGeneration(),
                ];
            }
            // Предпросмотр с дев-портала — тот же случай «работаем без установки»,
            // и та же грабля: без своей identity выданный пропуск гасился бы как
            // чужой, страница открывалась, а каждый её экран получал «доступ
            // только из Bitrix24». Портала за таким зрителем нет вовсе, поэтому
            // установку различаем именем публикации.
            if (previewActive()) {
                return [
                    'member_id' => 'pv:' . basename(dirname(__DIR__, 2)),
                    'install_generation' => appKeylessInstallGeneration(),
                ];
            }
        }
        if (!is_array($tokens) || empty($tokens['access_token'])
            || !empty($tokens['uninstalled_at']) || !empty($tokens['cleanup_pending'])) {
            return ['member_id' => '', 'install_generation' => ''];
        }
        $generation = appInstallGenerationFrom($tokens);
        if ($generation === '') {
            appEnsureInstallGeneration();
            $tokens = storeRead(B24_TOKENS_FILE);
            $generation = is_array($tokens) ? appInstallGenerationFrom($tokens) : '';
        }
        return [
            'member_id' => is_array($tokens) ? (string)($tokens['member_id'] ?? '') : '',
            'install_generation' => $generation,
        ];
    } catch (Throwable $e) {
        return ['member_id' => '', 'install_generation' => ''];
    }
}

/**
 * Оставить только свежие записи и не дать одному пользователю занять весь файл.
 *
 * Лишним считается пропуск, которым ДОЛЬШЕ ВСЕГО не пользовались, а не самый
 * старый по времени выдачи. Разница важная: приложение выдаёт пропуск на каждое
 * открытие из меню портала, и по возрасту первой вылетала вкладка, которую
 * человек открыл утром и держит весь день, — он получал «Доступ запрещён»
 * посреди работы, ничего для этого не сделав.
 */
function sessionsBound(array $sessions, string $preferredToken = ''): array {
    $now = time();
    // Expired rows never compete with live sessions for count/bytes. Missing
    // expiry is invalid legacy state and is revoked fail-closed as well.
    $rows = array_values(array_filter($sessions, static fn($row): bool
        => is_array($row) && (int)($row['expiresAt'] ?? 0) > $now));
    // Свежие по последнему использованию — первыми; давние вытесняются.
    usort($rows, static function (array $a, array $b) use ($preferredToken): int {
        if ($preferredToken !== '') {
            $ap = hash_equals((string)($a['token'] ?? ''), $preferredToken);
            $bp = hash_equals((string)($b['token'] ?? ''), $preferredToken);
            if ($ap !== $bp) return $ap ? -1 : 1;
        }
        $la = (int)($a['lastSeen'] ?? $a['createdAt'] ?? 0);
        $lb = (int)($b['lastSeen'] ?? $b['createdAt'] ?? 0);
        if ($la !== $lb) return $lb <=> $la;
        $ca = (int)($a['createdAt'] ?? 0);
        $cb = (int)($b['createdAt'] ?? 0);
        if ($ca !== $cb) return $cb <=> $ca;
        return strcmp((string)($b['token'] ?? ''), (string)($a['token'] ?? ''));
    });

    $kept = [];
    $perUser = [];
    foreach ($rows as $row) {
        try { $row = sessionAssertRowWithinLimits($row); }
        catch (SessionStateLimitException $e) { continue; } // invalid legacy row => fail-closed logout
        $uid = (int)($row['uid'] ?? 0);
        // Пропуск предпросмотра — единственный законный случай без портального
        // номера сотрудника: портала за таким зрителем нет вовсе. Считаем его
        // отдельной группой, чтобы он не занимал долю живого человека и чтобы
        // прежнее правило «нет номера — не пропуск» осталось в силе для всех
        // остальных строк. Без этой ветки пропуск молча исчезал сразу после
        // выдачи: страница открывалась, а каждый её экран получал «доступ
        // только из Bitrix24».
        $gruppa = !empty($row['preview']) ? 'preview' : $uid;
        if (($gruppa !== 'preview' && $uid <= 0)
            || (int)($perUser[$gruppa] ?? 0) >= SESSION_MAX_PER_USER) continue;
        $perUser[$gruppa] = (int)($perUser[$gruppa] ?? 0) + 1;
        $kept[] = $row;
        if (count($kept) >= SESSION_MAX_TOTAL) break;
    }
    return sessionsBoundToBytes($kept, SESSION_MAX_BYTES);
}

/**
 * Отметить, что пропуском только что пользовались.
 *
 * Пишем не чаще раза в SESSION_TOUCH_EVERY секунд: иначе каждый запрос фронта
 * дёргал бы файл пропусков под замком и выстраивал бы приложение в очередь на
 * ровном месте. Точность здесь и не нужна — отметка решает только, какой пропуск
 * вытеснить при переполнении.
 */
function touchSession(string $token, int $lastSeen = 0): void {
    if ($token === '') return;
    // Решаем ДО захвата замка. Иначе троттл не работает вовсе: storeUpdate берёт
    // эксклюзивный замок и переписывает файл пропусков на КАЖДОМ запросе, даже
    // когда обновлять нечего, — и все запросы приложения встают в очередь.
    if (time() - $lastSeen < SESSION_TOUCH_EVERY) return;
    try {
        sessionStoreUpdate(static function (array $all) use ($token): array {
            $now = time();
            foreach ($all as $i => $row) {
                if (!is_array($row) || !hash_equals((string)($row['token'] ?? ''), $token)) continue;
                if ($now - (int)($row['lastSeen'] ?? 0) < SESSION_TOUCH_EVERY) return $all;
                $all[$i]['lastSeen'] = $now;
                return $all;
            }
            return $all;
        });
    } catch (Throwable $e) {
        // Отметка — вспомогательная: не пускать человека из-за занятого замка нельзя.
    }
}

/** Убрать один пропуск (например, у человека забрали доступ). */
function revokeSessionToken(string $token): void {
    if ($token === '') return;
    sessionStoreUpdate(static fn(array $all): array => array_values(array_filter(
        $all,
        static fn($r) => is_array($r) && !hash_equals((string)($r['token'] ?? ''), $token)
    )));
}

/** Убрать все пропуска сотрудника — зовётся, когда его удаляют из списка доступа. */
function revokeSessionsForUser(int $uid): void {
    if ($uid <= 0) return;
    sessionStoreUpdate(static fn(array $all): array => array_values(array_filter(
        $all,
        static fn($r) => is_array($r) && (int)($r['uid'] ?? 0) !== $uid
    )));
}

/**
 * ЗАБЛОКИРОВАННЫЙ В ПОРТАЛЕ СОТРУДНИК ТЕРЯЕТ ПРОПУСК, НЕ ДОЖИДАЯСЬ СРОКА.
 *
 * Пропуск живёт восемь часов и до сих пор ни разу не перепроверялся у портала:
 * человека увольняют, администратор блокирует его в Битриксе — а открытая вкладка
 * приложения продолжает работать почти рабочий день. Со стороны портала всё
 * закрыто, со стороны приложения — нет, и заметить это некому.
 *
 * Спрашиваем ПОРТАЛ про тех, у кого сейчас есть живой пропуск, и гасим пропуска
 * заблокированных. Делает это фоновая обработка: уволенный сам в приложение
 * заходить не станет, поэтому проверка «при следующем открытии» не сработала бы
 * именно в том случае, ради которого заводится.
 *
 * @param callable $ask function(array $uids): array — как спросить портал (uid → активен ли)
 * @return int сколько пропусков погашено
 */
function revokeSessionsOfBlockedUsers(callable $ask): int {
    $now = time();
    $sessions = loadSessions();
    if (!is_array($sessions)) return 0;
    $uids = [];
    foreach ($sessions as $row) {
        if (!is_array($row)) continue;
        if ((int)($row['expiresAt'] ?? 0) <= $now) continue;   // и так протух
        $uid = (int)($row['uid'] ?? 0);
        if ($uid > 0) $uids[$uid] = true;
    }
    if ($uids === []) return 0;

    $active = $ask(array_keys($uids));
    if (!is_array($active) || $active === []) return 0;        // портал не ответил — не гасим никого

    $blocked = [];
    foreach ($uids as $uid => $_) {
        // Гасим ТОЛЬКО тех, про кого портал прямо сказал «неактивен». Молчание про
        // сотрудника — это не «уволен»: так один сбой выборки разлогинил бы всех,
        // включая владельца, и починить это стало бы некому.
        if (!array_key_exists($uid, $active)) continue;
        if ($active[$uid] === true) continue;
        $blocked[(int)$uid] = true;
    }
    if ($blocked === []) return 0;
    // Все разом ОДНОЙ записью (ревью 23.09.2026, skorost-6): прежде на каждого
    // уволенного файл сессий переписывался целиком под двумя замками — отдел из
    // ста человек давал сто перезаписей ради одной логической правки.
    sessionStoreUpdate(static fn(array $all): array => array_values(array_filter(
        $all,
        static fn($r) => is_array($r) && !isset($blocked[(int)($r['uid'] ?? 0)])
    )));
    return count($blocked);
}

/**
 * ПЕРЕВОД СОТРУДНИКА В ДРУГОЙ ОТДЕЛ ОТЗЫВАЕТ ПРАВА, НЕ ДОЖИДАЯСЬ СРОКА.
 *
 * Права выдают отделами, а состав отдела — это состояние ПОРТАЛА, которое
 * приложение видит один раз: в момент выдачи пропуска. Дальше пропуск живёт
 * восемь часов со снимком отделов внутри. Снятие самой привязки отдела в списке
 * доступа учитывалось сразу (`revokeSessionsForDepartment`), а вот перевод
 * человека ИЗ разрешённого отдела — нет: доступ у него оставался до конца
 * рабочего дня (находка ревью 08.08.2026).
 *
 * Зовётся из фоновой обработки тем же ответом портала, которым проверяется
 * блокировка: список отделов приходит в `UF_DEPARTMENT` того же `user.get`,
 * поэтому лишних запросов к порталу это не добавляет ни одного.
 *
 * ГРАНИЦА. Судим только по тем, про кого портал ПРЯМО сказал. Молчание про
 * сотрудника — не «его выгнали из всех отделов»: иначе один сбой выборки
 * отобрал бы доступ у всех разом, включая владельца.
 *
 * @param array<int,int[]> $map uid → отделы, как их сейчас видит портал
 * @return int сколько пропусков погашено
 */
function sessionApplyPortalDepartments(array $map): int {
    if ($map === []) return 0;
    $normalized = [];
    $oversized = [];
    foreach ($map as $uid => $departments) {
        $uid = (int)$uid;
        if ($uid <= 0) continue;
        try { $normalized[$uid] = sessionNormalizeDepartments((array)$departments); }
        catch (SessionStateLimitException $e) { $oversized[$uid] = true; }
    }
    if ($normalized === [] && $oversized === []) return 0;
    $pogasheno = 0;
    sessionStoreUpdate(static function (array $all) use ($normalized, $oversized, &$pogasheno): array {
        // Список доступа — один раз на операцию и под тем же замком (skorost-7).
        $acl = aclLoad();
        $out = [];
        foreach ($all as $row) {
            if (!is_array($row)) continue;
            $uid = (int)($row['uid'] ?? 0);
            if ($uid <= 0 || (!array_key_exists($uid, $normalized) && empty($oversized[$uid]))) {
                $out[] = $row;
                continue;
            }
            // Нельзя урезать authorization snapshot: при слишком большом ответе
            // безопасный исход — погасить пропуск и потребовать новый вход.
            if (!empty($oversized[$uid])) { $pogasheno++; continue; }
            $svezhie = $normalized[$uid];
            try { $bylo = sessionNormalizeDepartments((array)($row['departments'] ?? [])); }
            catch (SessionStateLimitException $e) { $pogasheno++; continue; }
            if ($svezhie === $bylo) { $out[] = $row; continue; }
            // Состав отделов изменился. Пропуск оставляем только тому, у кого
            // основание доступа СОХРАНИЛОСЬ по новым отделам (или персонально).
            $rol = aclRoleIn($acl, $uid, $svezhie);
            if ($rol === null && empty($row['isAdmin'])) { $pogasheno++; continue; }
            $row['departments'] = $svezhie;
            // Роль пересчитываем тут же: иначе в пропуске осталась бы прежняя,
            // более сильная — например «правка» у того, кто теперь только
            // «просмотр» по новому отделу.
            if ($rol !== null) $row['role'] = $rol;
            $out[] = $row;
        }
        return $out;
    });
    return $pogasheno;
}

/**
 * Погасить пропуска всех, кто входил по этому отделу.
 *
 * Доступ отделу снимают одним движением — и он обязан пропасть в ту же
 * секунду, как и персональный. Иначе сотрудники снятого отдела дорабатывают
 * день с правами, которых у них уже нет: роль лежит в пропуске, а пропуск
 * живёт восемь часов.
 *
 * Гасим только тех, у кого нет ДРУГОГО основания: у кого есть персональная
 * запись или другой разрешённый отдел, пропуск не трогаем — переоткрытие
 * приложения им ничего не даст, кроме лишнего запроса к порталу.
 */
function revokeSessionsForDepartment(int $deptId): void {
    if ($deptId <= 0) return;
    sessionStoreUpdate(static function (array $all) use ($deptId): array {
        // Список доступа — один раз на операцию и под тем же замком (skorost-7).
        $acl = aclLoad();
        return array_values(array_filter($all, static function ($r) use ($deptId, $acl) {
            if (!is_array($r)) return false;
            $depts = array_map('intval', (array)($r['departments'] ?? []));
            if (!in_array($deptId, $depts, true)) return true;   // не из этого отдела
            // Пропуск этого отдела — оставляем только если человек проходит по
            // другому основанию (персонально, владелец, админ портала).
            return aclRoleIn($acl, (int)($r['uid'] ?? 0), array_values(array_diff($depts, [$deptId]))) !== null;
        }));
    });
}

/**
 * Выбросить протухшие сессии, вернуть живые.
 *
 * Зовётся на КАЖДЫЙ запрос к API, поэтому замок берём только когда есть что
 * удалять: иначе все запросы приложения выстроились бы в очередь друг за
 * другом на ровном месте. Чтение без замка безопасно — файл всегда меняется
 * целиком, половины состояния читатель не увидит.
 */
function cleanupSessions(): array {
    $sessions = loadSessions();
    $now = time();
    // Дешёвый проход без сортировки и перекодирования (ревью 23.09.2026,
    // skorost-5): прежде КАЖДЫЙ запрос к api/* гнал полную проверку границ —
    // usort всех строк, json_encode каждой и двоичный поиск по байтам, около 9 мс
    // под lifecycle-замком. Файл и так пишется только через общий writer сессий,
    // который сам всё это делает; здесь достаточно заметить то, что устаревает
    // без записи: просроченные строки и перебор числа пропусков.
    $alive = [];
    $perUser = [];
    $overCap = false;
    foreach ($sessions as $x) {
        if (!is_array($x) || (int)($x['expiresAt'] ?? 0) <= $now) continue;
        $alive[] = $x;
        $gruppa = !empty($x['preview']) ? 'preview' : (int)($x['uid'] ?? 0);
        $perUser[$gruppa] = (int)($perUser[$gruppa] ?? 0) + 1;
        if ($perUser[$gruppa] > SESSION_MAX_PER_USER) $overCap = true;
    }
    if (count($alive) === count($sessions) && !$overCap && count($alive) <= SESSION_MAX_TOTAL) return $alive;

    // Чистим от СВЕЖЕГО состояния под замком: пока мы считали протухшие,
    // соседний запрос мог выдать новую сессию — она не должна пропасть.
    return array_values(sessionStoreUpdate(static fn(array $onDisk): array
        => array_values(array_filter($onDisk,
            fn($x) => is_array($x) && ($x['expiresAt'] ?? 0) > $now))));
}

/**
 * Найти живой пропуск по токену — и заново проверить, что он всё ещё годен.
 *
 * Права ПЕРЕЧИТЫВАЮТСЯ ИЗ СПИСКА ДОСТУПА на каждом запросе, а не берутся из
 * самого пропуска. Разница видна ровно тогда, когда она важна: администратор
 * убрал сотрудника из списка — и следующий же запрос сотрудника получает отказ,
 * а не работает ещё восемь часов до истечения пропуска. Так же и с повышением
 * роли: новая роль действует сразу, переоткрывать приложение не нужно.
 *
 * Здесь же проверяется портал: пропуск, выданный до переустановки приложения на
 * другой портал, аннулируется.
 */
function findSessionByToken(string $token): ?array {
    if ($token === '') return null;
    sessionPrepareLifecycleRead();
    return appWithLifecycleReadLock(static function () use ($token): ?array {
        return findSessionByTokenLocked($token);
    }, 25.0);
}

/** Внутренняя часть поиска; вызывающий держит lifecycle SH. */
function findSessionByTokenLocked(string $token): ?array {
    $identity = sessionInstallationIdentity();
    $tenant = $identity['member_id'];
    $generation = $identity['install_generation'];
    foreach (cleanupSessions() as $s) {
        if (!hash_equals((string)($s['token'] ?? ''), $token)) continue;

        // Найденную строку проверяем тем же правилом, что и писатель: уборка на
        // чтении больше не прогоняет sessionsBound() по всему файлу (skorost-5),
        // а строка из старого формата не должна стать пропуском в обход него.
        try { $s = sessionAssertRowWithinLimits($s); }
        catch (SessionStateLimitException $e) { revokeSessionToken($token); return null; }
        if (empty($s['preview']) && (int)($s['uid'] ?? 0) <= 0) { revokeSessionToken($token); return null; }

        // Пропуск с другого портала (приложение переустановили) — недействителен.
        // Не смогли определить свой портал (токенов нет, файл повреждён) — тоже
        // отказ: раньше в этом случае проверка просто отключалась, то есть
        // переставала работать ровно там, где нужнее всего.
        $bound = (string)($s['member_id'] ?? '');
        if ($tenant === '' || ($bound !== '' && !hash_equals($bound, $tenant))) {
            revokeSessionToken($token);
            return null;
        }
        // member_id не меняется при переустановке на том же портале. Поэтому
        // пропуск обязан помнить ещё и конкретное поколение установки: иначе
        // локальная сессия A продолжает действовать в B до восьми часов. Старые
        // пропуска без поколения безопасно инвалидируем — пользователь просто
        // заново откроет приложение из портала.
        $boundGeneration = (string)($s['install_generation'] ?? '');
        if ($generation === '' || $boundGeneration === ''
            || !hash_equals($boundGeneration, $generation)) {
            revokeSessionToken($token);
            return null;
        }

        $uid  = (int)($s['uid'] ?? 0);

        // Срок страховки администратора портала проверяем ДО всего остального.
        // Раньше он проверялся только в ветке «человека нет в списке доступа», и
        // получалось так: у сотрудника сняли права администратора на портале, но
        // он остался в нашем списке с ролью «просмотр» — просроченный признак
        // admin оставался в пропуске, а единый вход пропускал его на действия
        // администратора приложения до конца восьмичасового пропуска. Повторное
        // ревью 03.08.2026 воспроизвело это на изолированном состоянии.
        $checkedAt = (int)($s['adminCheckedAt'] ?? $s['createdAt'] ?? 0);
        if (!empty($s['isAdmin']) && $checkedAt > 0 && time() - $checkedAt > ADMIN_BYPASS_TTL) {
            $s['isAdmin'] = false;           // страховка протухла — дальше только по списку доступа
        }

        // Одна effective role для UI и всех endpoint; TTL проверен выше.
        $role = !empty($s['isAdmin']) ? 'admin' : aclUserRole($uid, (array)($s['departments'] ?? []));
        if ($role === null) {
            // Доступ отозвали (или страховка протухла) — пропуск гасим сразу,
            // чтобы следующий запрос не тратил время на ту же проверку. Человек
            // переоткроет приложение из портала, и портал заново скажет, кто он.
            if (empty($s['isAdmin'])) { revokeSessionToken($token); return null; }
            $role = 'admin';                 // администратор портала — страховка от самоблокировки
        }
        $s['role'] = $role;
        // Отметку обновляем не чаще раза в SESSION_TOUCH_EVERY — значение уже
        // прочитано, поэтому обычный запрос замок не берёт вовсе.
        touchSession($token, (int)($s['lastSeen'] ?? 0));
        return $s;
    }
    return null;
}

function setSessionCookie(string $token, int $expires): void {
    $appUrl  = defined('APP_URL') ? APP_URL : '';
    $cookiePath = $appUrl !== '' ? (rtrim(parse_url($appUrl, PHP_URL_PATH) ?: '/', '/') . '/') : '/';
    // Partitioned (CHIPS) через setcookie() не выставить — пишем заголовок руками.
    // Без него браузеры по мере ужесточения политики режут cookie в чужом iframe
    // молча, и перезагрузка страницы внутри портала начинает отдавать «Доступ
    // запрещён» без всякой видимой причины.
    header('Set-Cookie: ' . SESSION_COOKIE . '=' . rawurlencode($token)
        . '; Path=' . $cookiePath
        . '; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT'
        . '; Secure; HttpOnly; SameSite=None; Partitioned', false);
}

function createSession(int $uid = 0, string $role = 'view', bool $isAdmin = false, array $departmentIds = []): array {
    $departmentIds = sessionNormalizeDepartments($departmentIds);
    sessionPrepareLifecycleRead();
    return appWithLifecycleReadLock(static function () use ($uid, $role, $isAdmin, $departmentIds): array {
        return createSessionLocked($uid, $role, $isAdmin, $departmentIds);
    }, 25.0);
}

/** Внутренняя часть выдачи; identity и запись сессии атомарны относительно migration. */
function createSessionLocked(int $uid = 0, string $role = 'view', bool $isAdmin = false, array $departmentIds = []): array {
    $departmentIds = sessionNormalizeDepartments($departmentIds);
    $token = bin2hex(random_bytes(32));
    $identity = sessionInstallationIdentity();
    $session = [
        'token'     => $token,
        'uid'       => $uid,
        'role'      => $role,
        'isAdmin'   => $isAdmin,
        // Отделы человека — из ответа портала на токен САМОГО зрителя. Нужны,
        // чтобы доступ, выданный отделу, проверялся на каждом запросе, а не
        // только в момент входа. Из тела запроса их брать нельзя: подставив
        // чужой отдел, доступ выдавал бы себе кто угодно.
        'departments' => $departmentIds,
        // Когда портал в последний раз подтвердил, что это администратор
        // (см. ADMIN_BYPASS_TTL): страховка администратора действует не дольше.
        'adminCheckedAt' => time(),
        // К какому порталу привязан пропуск. Переустановили на другой портал —
        // старые пропуска перестают действовать (проверка в findSessionByToken).
        'member_id' => $identity['member_id'],
        'install_generation' => $identity['install_generation'],
        // Пропуск выдан предпросмотром дев-портала, а не порталом. Пометка нужна
        // и уборке пропусков (у такого зрителя нет номера сотрудника), и чтению
        // журнала: действия из предпросмотра видно, что сделаны не человеком портала.
        'preview' => previewActive(),
        'createdAt' => time(),
        'lastSeen'  => time(),
        'expiresAt' => time() + SESSION_TTL,
    ];
    $session = sessionAssertRowWithinLimits($session);
    // Добавляем к тому, что на диске прямо сейчас, а не к своей копии списка:
    // иначе сессия соседа, выданная секунду назад, исчезнет — человек увидит
    // «доступ только из Bitrix24» посреди работы.
    $now = time();
    sessionStoreUpdate(static function (array $onDisk) use ($session, $now): array {
        $alive = array_values(array_filter($onDisk, fn($x) => ($x['expiresAt'] ?? 0) > $now));
        $alive[] = $session;
        return sessionsBound($alive, $session['token']);
    });
    setSessionCookie($token, $session['expiresAt']);
    return $session;
}

/** Причина отказа выдачи сессии — для index.php-страницы. */
function sessionDenialReason(): ?string {
    return $GLOBALS['__session_denial_reason'] ?? null;
}

// Личность и права смотрящего берём ОДНОЙ функцией из api/b24.php —
// resolveUserContextFromAuth(): она возвращает id, признак админа и отделы за один
// поход в портал и кэширует ответ на время запроса. Прежние b24CurrentUserId() и
// b24IsPortalAdmin() делали ровно то же самое двумя лишними запросами и удалены.

function tryCreateSessionFromB24Post(): ?array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;

    sessionPrepareLifecycleRead();
    return appWithLifecycleReadLock(static function (): ?array {
        return tryCreateSessionFromB24PostLocked();
    }, 25.0);
}

// portalPostDomain() и portalPostRestEndpoint() живут в api/b24.php — рядом с
// разбором адреса REST и установкой. Формат портального POST обязан быть описан
// в ОДНОМ месте: пока забор знал про коробочный SERVER_ENDPOINT, а установка
// нет, приложение на коробке ставилось с одним именем портала, а проверялось по
// другому — и навсегда отвечало «открывается только из портала».

/**
 * Идентификатор сеанса портала из POST'а обработчика.
 *
 * Коробка не присылает APP_SID, но присылает APPLICATION_TOKEN — тот же ключ
 * приложения, который УЖЕ так трактует установка (index.php сверяет им же).
 * Здесь делаем симметрично.
 *
 * Забор от этого не слабеет, а становится строже: APP_SID проверяется только на
 * непустоту, а APPLICATION_TOKEN сверяется с сохранённым ключом приложения. До
 * первой подтверждённой установки сверять не с чем — тогда работает как APP_SID.
 */
function portalPostAppSid(array $input): string {
    $appSid = (string)($input['APP_SID'] ?? '');
    if ($appSid !== '') return $appSid;

    $appToken = (string)($input['APPLICATION_TOKEN'] ?? $input['application_token'] ?? '');
    if ($appToken === '') return '';
    $saved = b24ApplicationTokenStored();
    if ($saved === '') return $appToken;
    return hash_equals($saved, $appToken) ? $appToken : '';
}

/**
 * Дешёвый браузерный fence ДО REST-проверки AUTH_ID.
 *
 * Это не аутентификация: DOMAIN/APP_SID/Referer сами по себе ничего не доказывают.
 * Но без них публичный index превращался в анонимный прокси к user.current/user.admin:
 * любой POST с одним AUTH_ID занимал PHP-worker двумя внешними запросами. Настоящая
 * личность всё равно подтверждается resolveUserContextFromAuth() ниже.
 */
function portalPostPassesTransportFence(array $input, ?string $storedPortal = null): bool {
    $stored = $storedPortal ?? b24Portal();
    if (!is_string($stored) || $stored === '') return false;
    if ((string)($input['AUTH_ID'] ?? '') === '') return false;
    if (!hash_equals($stored, portalPostDomain($input))) return false;
    if (portalPostAppSid($input) === '') return false;

    // Сверенный ключ приложения — доказательство СИЛЬНЕЕ заголовка Referer:
    // его знают только портал и мы, тогда как Referer вообще ничего не
    // подтверждает. Поэтому там, где ключ совпал с сохранённым, имя в заголовке
    // уже не требуем. Без этого коробка с двумя именами (страницу открыли по
    // внутреннему, а REST-адрес канонический) не проходила забор ПОСЛЕ успешной
    // установки, и приложение навсегда отвечало «открывается только из портала».
    $appToken = (string)($input['APPLICATION_TOKEN'] ?? $input['application_token'] ?? '');
    $savedAppToken = $appToken !== '' ? b24ApplicationTokenStored() : '';
    if ($savedAppToken !== '' && hash_equals($savedAppToken, $appToken)) return true;

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $isB24Ref = (bool)preg_match('#^https://' . preg_quote($stored, '#') . '/#', $referer);
    $isSelfRef = defined('APP_URL') && APP_URL !== ''
        && strpos($referer, rtrim(APP_URL, '/') . '/') === 0;
    return $isB24Ref || $isSelfRef;
}

/** Внутренняя часть portal-auth; SH живёт через REST/ACL и createSession(). */
function tryCreateSessionFromB24PostLocked(): ?array {
    // index.php уже вызвал этот parser до install/lifecycle decode; повторный
    // вызов возвращает тот же bounded-снимок и не читает php://input второй раз.
    $input = array_merge($_GET, portalPostFields());

    $authId = (string)($input['AUTH_ID'] ?? '');
    $stored = b24Portal();
    if (!portalPostPassesTransportFence($input, $stored)) return null;

    // Доступ по умолчанию (модель ACL): приложение видно ТОЛЬКО тем, кто в списке
    // доступа, и тому, кто установил приложение (owner). Остальным — заглушка.
    // Администратор портала имеет доступ всегда (страховка + управление списком).
    // Кто открыл и админ ли он — ОДНИМ обращением к порталу (внутри оно ещё и
    // кэшируется на запрос). Раньше здесь шли два отдельных вызова, и вместе с
    // проверкой подлинности установки портал успевали спросить трижды за одно
    // открытие экрана — лишняя секунда ожидания на ровном месте.
    $ctx = resolveUserContextFromAuth($authId, $stored);
    if (!is_array($ctx)) {
        // Портал не ответил (сеть, таймаут, протухший AUTH_ID). Это НЕ «тебе сюда
        // нельзя»: советовать человеку просить доступ бессмысленно, надо просто
        // повторить. Разделяем причины, чтобы текст на экране был честным.
        $GLOBALS['__session_denial_reason'] = 'portal_unreachable';
        return null;
    }
    $uid     = (int)$ctx['id'];
    $isAdmin = (bool)$ctx['isAdmin'];
    $departmentIds = (array)($ctx['departmentIds'] ?? []);
    $role    = $isAdmin ? 'admin' : aclUserRole($uid, $departmentIds);  // свежий ответ портала
    if ($role === null && !$isAdmin) {
        $GLOBALS['__session_denial_reason'] = 'no_access';
        return null;
    }
    if ($role === null) $role = 'admin';          // админ портала без явной записи в ACL

    return createSession($uid, $role, $isAdmin, $departmentIds);
}

// ── Доверенный предпросмотр из дев-портала ───────────────────────────────
//
// ЗАЧЕМ. Приложение живёт в рамке Битрикс24: личность подтверждает портал, а по
// прямой ссылке контроллер намеренно отвечает «страница доступна только внутри
// Битрикс24». Разработчику при этом надо видеть свою работу на дев-портале, не
// ставя приложение на портал. Предпросмотр — второй вход, но не дыра в первом:
// за зрителя ручается панель дев-портала, которая уже спросила логин и сверила
// владельца проекта, и ручается ПОДПИСЬЮ на ключе, которого в браузере нет.
//
// ГДЕ КЛЮЧ. В приватном сторе приложения, файлом `preview-key.php` того же
// формата, что и всё состояние: исполняемый префикс `404 + exit` и JSON следом.
// Кладёт его панель дев-портала при подъёме, права 0600. Стор физически вне
// webroot и не переносится деплоем, поэтому на боевом хостинге предпросмотра
// НЕ СУЩЕСТВУЕТ: нет ключа — нет входа, fail-closed.
if (!defined('PREVIEW_TTL_SEC')) define('PREVIEW_TTL_SEC', 300);
if (!defined('PREVIEW_KEY_FILE')) {
    // Путь можно задать окружением процесса — этим пользуются испытания, чтобы
    // не заводить ключ в дереве шаблона. Из браузера окружение не подделать:
    // его ставит тот, кто запускает PHP, а на бою файла нет ни по одному пути.
    $bxappPreviewKeyEnv = (string)(getenv('BXAPP_PREVIEW_KEY_FILE') ?: '');
    define('PREVIEW_KEY_FILE', $bxappPreviewKeyEnv !== ''
        ? $bxappPreviewKeyEnv
        : DATA_ROOT . '/preview-key.php');
    unset($bxappPreviewKeyEnv);
}

/**
 * Ключ предпросмотра или '' — если предпросмотр в этой установке не заведён.
 * Отсутствие файла НЕ ошибка: это норма для боевого хостинга.
 */
function previewKeyValue(): string {
    $file = PREVIEW_KEY_FILE;
    if (!is_string($file) || $file === '' || !is_file($file)) return '';
    // Читаем штатным чтением состояния: ключ лежит в том же приватном формате
    // (`404 + exit` и JSON), что и остальной стор, поэтому своего разбора файла
    // здесь не нужно, а предел размера сторонний файл на этом месте не даст
    // раздуть в память запроса.
    // Файл есть, а прочитать не вышло (испорчен, права, не тот формат) — это не
    // «предпросмотра нет», а поломка, и молчать о ней нельзя: человек увидел бы
    // заглушку про рамку портала и не понял, почему своё приложение не
    // открывается. Пишем причину в журнал (ключ туда не попадает — в тексте
    // только путь и сообщение, и оно проходит маскировку) и отвечаем «нет ключа»:
    // пускать по нечитаемому ключу нельзя.
    try { $data = storeRead($file, 4096); }
    catch (Throwable $e) {
        logAppError(maskSecrets('Предпросмотр: ключ ' . $file . ' не прочитан — ' . $e->getMessage()));
        return '';
    }
    $key = is_array($data) ? trim((string)($data['key'] ?? '')) : '';
    if ($key === '' || preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
        if ($data !== null) {
            logAppError('Предпросмотр: в ключе ' . $file . ' нет пригодного значения — перезаведите публикацию');
        }
        return '';
    }
    return $key;
}

/**
 * Зритель доверенного предпросмотра или null. null означает «обычный запрос» —
 * вызывающий обязан продолжать со своей штатной защитой, а не считать это
 * разрешением.
 *
 * @return array{user:string}|null
 */
function previewViewer(): ?array {
    $user = (string)($_SERVER['HTTP_X_BXAPP_PREVIEW_USER'] ?? '');
    $ts   = (string)($_SERVER['HTTP_X_BXAPP_PREVIEW_TS'] ?? '');
    $sig  = (string)($_SERVER['HTTP_X_BXAPP_PREVIEW_SIG'] ?? '');
    if ($user === '' || $ts === '' || $sig === '') return null;
    if (strlen($user) > 64 || preg_match('/^[0-9]{1,12}$/', $ts) !== 1) return null;
    if (preg_match('/^[0-9a-f]{64}$/', $sig) !== 1) return null;

    $key = previewKeyValue();
    if ($key === '') return null;

    // Окно проверяем В ОБЕ стороны: метка из будущего означает либо разъехавшиеся
    // часы, либо заготовленную заранее подпись — принимать её нельзя.
    $age = time() - (int)$ts;
    if ($age > PREVIEW_TTL_SEC || $age < -PREVIEW_TTL_SEC) return null;

    $slug = basename(dirname(__DIR__, 2));
    $expected = hash_hmac('sha256', $slug . "\n" . $user . "\n" . $ts, (string)hex2bin($key));
    if (!hash_equals($expected, $sig)) return null;

    return ['user' => $user];
}

/** Идёт ли текущий запрос через доверенный предпросмотр. */
function previewActive(): bool {
    static $cached = null;
    if ($cached === null) $cached = previewViewer() !== null;
    return $cached;
}

/**
 * Сессия предпросмотра или null.
 *
 * Роль — админ приложения: разработчик смотрит собственную работу целиком,
 * включая экран доступа. Портального идентификатора у такого зрителя нет, и
 * выдумывать чужой uid нельзя — записи и журнал пометились бы живым сотрудником
 * портала. Поэтому uid остаётся нулевым: это «никто из портала», и ACL-проверки
 * по людям и отделам на нём не срабатывают.
 */
function previewSession(): ?array {
    if (!previewActive()) return null;
    return createSession(0, 'admin', true, []);
}

function findSessionFromCookie(): ?array {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    return $token ? findSessionByToken((string)$token) : null;
}

function findSessionFromHeader(): ?array {
    $token = $_SERVER['HTTP_X_APP_TOKEN'] ?? '';
    return $token ? findSessionByToken((string)$token) : null;
}

/**
 * Запрос пришёл со страницы самого приложения, а не с чужого сайта?
 *
 * Вторая линия обороны для страниц, которые человек открывает руками (там
 * заголовка нет и работает cookie). Смотрим то, что браузер проставляет сам и
 * подделать со страницы нельзя.
 */
function requestFromOwnSite(bool $strict = false): bool {
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '') {
        // `same-site` — это НЕ мы: на общем хостинге соседний сайт того же домена
        // второго уровня принадлежит другому клиенту. Для мутаций принимаем
        // только `same-origin`; для обычного перехода по ссылке (`none`)
        // послабление оставляем — иначе страницу нельзя открыть из адресной
        // строки, а она для того и сделана.
        $ok = $strict ? ['same-origin'] : ['same-origin', 'none'];
        return in_array($site, $ok, true);
    }

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return !$strict;   // мутация без единого признака источника — отказ
    $own = defined('APP_URL') ? parse_url(APP_URL, PHP_URL_HOST) : null;
    $from = parse_url($origin, PHP_URL_HOST);
    return is_string($own) && is_string($from) && $own !== '' && strcasecmp($from, $own) === 0;
}

/**
 * Для API-эндпоинтов: возвращает сессию или отдаёт 403 JSON.
 *
 * Принимаем ТОЛЬКО заголовок `X-App-Token`, cookie здесь не годится.
 * Почему так строго: cookie сессии выдана с SameSite=None (иначе приложение не
 * работает в iframe портала), а значит браузер приложит её и к запросу, который
 * инициировала чужая страница. Раньше здесь был запасной путь через cookie — то
 * есть посторонний сайт мог, пока сотрудник сидит в портале, тихо дёрнуть наш
 * эндпоинт и, например, дописать себе админа в список доступа. Кастомный
 * заголовок так не подставить: браузер сначала спросит разрешение (preflight),
 * а мы его не выдаём. Найдено ревью 29.07.2026.
 */
function requireSession(): array {
    $session = findSessionFromHeader();
    if (!$session) {
        // Отказ собирает apiFail(): он же кладёт запись в журнал приложения.
        // Своя копия ответа здесь означала бы, что именно этот отказ — самый
        // частый вопрос «мне не даёт зайти» — нигде не остаётся следом.
        if (function_exists('apiFail')) {
            apiFail('Доступ только из Bitrix24. Откройте приложение в левом меню портала.', 403, 'no_session');
        }
        // Запасной путь: гейт позвали в обход единого входа (api/http.php не
        // подключён). Формат тот же, чтобы фронт разбирал ответы одной функцией.
        http_response_code(403);
        sendNoStoreHeaders();   // иначе браузер закэширует отказ и покажет его после входа
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'Доступ только из Bitrix24. Откройте приложение в левом меню портала.',
            'code'  => 'no_session',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $session;
}
