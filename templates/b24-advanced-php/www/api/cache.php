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
 * api/cache.php — ПАМЯТЬ О ТЯЖЁЛОМ ОТВЕТЕ.
 *
 * ЗАЧЕМ. Часть данных портала дорогая и почти неизменная: справочник отделов,
 * список полей, настройки воронок. Приложение спрашивает их на каждое открытие
 * экрана и на каждый ввод буквы в поиске — человек ждёт, а корзина запросов
 * портала тратится на один и тот же ответ. Замер 06.08.2026: свой файловый кеш
 * с TTL написан в 17 проектах из 27, и почти в каждом — без защиты от того, что
 * два одновременных запроса полезут за одним и тем же ответом разом.
 *
 * ЧТО ДЕЛАЕТ. `cacheRemember($ключ, $секунды, $как_получить)` отдаёт запомненное
 * значение, а когда его нет или оно устарело — получает заново, кладёт в память
 * и отдаёт. Пока один запрос ходит за ответом, остальные ждут его, а не идут в
 * портал следом (короткий bounded claim по точному ключу).
 *
 * ЧТО ВОЗВРАЩАЕТ. Ровно то, что вернул загрузчик (любое значение, которое
 * ложится в JSON: массивы, строки, числа, null).
 * ПРИ ОТКАЗЕ ЗАГРУЗЧИКА. Исключение проходит наружу как есть, в память ничего
 * не пишется: неудачный ответ не должен застыть на час.
 *
 * ЧЕГО ЭТОТ БЛОК НЕ ДЕЛАЕТ. Он не помнит между порталами и не заменяет
 * состояние приложения: всё, что здесь лежит, в любой момент может исчезнуть
 * (уборка при удалении приложения стирает кеш целиком). Класть сюда то, что
 * нельзя получить заново, нельзя.
 */

require_once __DIR__ . '/store.php';

/** Дольше суток не помним: иначе смена настроек портала неделю не видна. */
const CACHE_MAX_TTL = 86400;

// Ключ участвует в имени business-lock, значение сериализуется отдельно, а
// CACHE_MAX_BYTES ограничивает уже весь private PHP file вместе с envelope.
// У cache один item на файл, но без этих трёх границ один неудачный loader мог
// принести многомегабайтный cursor/response и заставить каждый reader декодировать
// его целиком. Conditional define оставляет шов для малых boundary-fixtures.
if (!defined('CACHE_KEY_MAX_BYTES'))   define('CACHE_KEY_MAX_BYTES', 512);
if (!defined('CACHE_VALUE_MAX_BYTES')) define('CACHE_VALUE_MAX_BYTES', 8 * 1024 * 1024);
if (!defined('CACHE_MAX_BYTES'))       define('CACHE_MAX_BYTES', 8 * 1024 * 1024 + 65536);
// Per-file cap не ограничивает число разных bounded keys. Family имеет два
// независимых потолка, а key-lock — конечное число shard-файлов.
if (!defined('CACHE_FAMILY_MAX_FILES')) define('CACHE_FAMILY_MAX_FILES', 128);
if (!defined('CACHE_FAMILY_MAX_BYTES')) define('CACHE_FAMILY_MAX_BYTES', 64 * 1024 * 1024);
if (!defined('CACHE_LOCK_SHARDS'))      define('CACHE_LOCK_SHARDS', 32);
if (!defined('CACHE_CLAIMS_PER_SHARD')) define('CACHE_CLAIMS_PER_SHARD', 128);
if (!defined('CACHE_CLAIMS_MAX_BYTES')) define('CACHE_CLAIMS_MAX_BYTES', 64 * 1024);
if (!defined('CACHE_CLAIM_FALLBACK_SEC')) define('CACHE_CLAIM_FALLBACK_SEC', 30.0);
if (!defined('CACHE_CLAIM_HARD_SEC'))     define('CACHE_CLAIM_HARD_SEC', 600.0);

/**
 * Значение сейчас получает соседний запрос, и дождаться его не удалось.
 *
 * Отдельный тип, а не общий RuntimeException: вызывающий почти всегда хочет
 * различать «портал отказал» и «ответ вот-вот принесёт сосед» — второе лечится
 * повтором через секунду, а не показом ошибки навсегда.
 */
class CacheBusyException extends RuntimeException {}

/** Loader/legacy cache вышел за предметный resource contract. */
final class CacheStateLimitException extends RuntimeException {}

/** Нормализовать ключ одинаково для data-path и lock shard. */
function cacheNormalizeKey(string $key): string {
    $key = trim($key);
    if ($key === '') {
        throw new InvalidArgumentException('cacheRemember: пустой ключ — непонятно, что запоминать.');
    }
    if (strlen($key) > CACHE_KEY_MAX_BYTES) {
        throw new CacheStateLimitException('cacheRemember: ключ больше допустимого предела');
    }
    return $key;
}

/** Номер одного из конечного числа coordination shards. */
function cacheEntryLockSlot(string $key): int {
    $key = cacheNormalizeKey($key);
    $shards = max(1, (int)CACHE_LOCK_SHARDS);
    return hexdec(substr(sha1($key), 0, 7)) % $shards;
}

/** Совместимое человекочитаемое имя shard для диагностики и контрактов. */
function cacheEntryLockKey(string $key): string {
    return 'cache-entry-shard-' . cacheEntryLockSlot($key);
}

/** Fixed state одного shard: число файлов не зависит от числа cache keys. */
function cacheClaimFileFor(string $key): string {
    return DATA_ROOT . '/cache-entry-claims-' . cacheEntryLockSlot($key) . '.php';
}

/** Process marker различает живого владельца и PID, повторно выданный ОС. */
function cacheClaimProcessMarker(?int $pid = null): string {
    $pid = $pid ?? getmypid();
    if ($pid <= 0) return '';
    $path = '/proc/' . $pid . '/stat';
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return is_dir('/proc') ? '' : (string)$pid;
    $close = strrpos($raw, ')');
    if ($close === false) return (string)$pid;
    $fields = preg_split('/\s+/', trim(substr($raw, $close + 1))) ?: [];
    // После comm идут field 3..; process starttime — field 22, индекс 19.
    return (string)$pid . ':' . (string)($fields[19] ?? '');
}

function cacheClaimOwnerAlive(array $claim, float $now): bool {
    $claimed = (float)($claim['claimed_at'] ?? 0.0);
    if ($claimed <= 0.0 || $now - $claimed >= (float)CACHE_CLAIM_HARD_SEC) return false;
    $marker = (string)($claim['process'] ?? '');
    if ($marker === '') return (float)($claim['expires_at'] ?? 0.0) > $now;
    $pid = (int)explode(':', $marker, 2)[0];
    $current = cacheClaimProcessMarker($pid);
    if (strpos($marker, ':') !== false && strpos($current, ':') !== false) {
        return hash_equals($marker, $current);
    }
    if (is_dir('/proc') && $current === '') return false;
    return (float)($claim['expires_at'] ?? 0.0) > $now;
}

/** Raw RMW claim-state; caller serializes lifecycle through family lock. */
function cacheClaimStateUpdate(string $key, callable $mutator): array {
    $claimStateFile = cacheClaimFileFor($key);
    return storeUpdate($claimStateFile, static function (array $state) use ($mutator): ?array {
        $claims = is_array($state['claims'] ?? null) ? $state['claims'] : [];
        $next = $mutator($claims);
        if ($next === null) return null;
        if (!is_array($next)) throw new LogicException('cache claim mutator обязан вернуть массив или null');
        if (count($next) > CACHE_CLAIMS_PER_SHARD) {
            throw new CacheStateLimitException('cache: слишком много одновременных claims в shard');
        }
        $candidate = ['claims' => $next];
        if (strlen(storeEncode($candidate)) > CACHE_CLAIMS_MAX_BYTES) {
            throw new CacheStateLimitException('cache: claim-state больше допустимого предела');
        }
        return $candidate;
    }, STORE_LOCK_TIMEOUT, CACHE_CLAIMS_MAX_BYTES);
}

/** Получить точный key-claim, не удерживая shard во время loader. */
function cacheEntryClaimAcquire(string $key, float $timeout = STORE_LOCK_TIMEOUT): string {
    $key = cacheNormalizeKey($key);
    $hash = hash('sha256', $key);
    $owner = bin2hex(random_bytes(16));
    $deadline = microtime(true) + max(0.0, $timeout);
    do {
        $acquired = false;
        cacheWithFamilyLock(static function () use ($key, $hash, $owner, &$acquired): void {
            cacheClaimStateUpdate($key, static function (array $claims) use ($hash, $owner, &$acquired): ?array {
                $now = microtime(true);
                $changed = false;
                foreach ($claims as $claimHash => $claim) {
                    if (!is_string($claimHash) || !is_array($claim)
                        || !cacheClaimOwnerAlive($claim, $now)) {
                        unset($claims[$claimHash]); $changed = true;
                    }
                }
                if (isset($claims[$hash])) return $changed ? $claims : null;
                if (count($claims) >= CACHE_CLAIMS_PER_SHARD) {
                    throw new CacheStateLimitException('cache: claim shard заполнен');
                }
                $claims[$hash] = [
                    'owner' => $owner,
                    'process' => cacheClaimProcessMarker(),
                    'claimed_at' => $now,
                    'expires_at' => $now + (float)CACHE_CLAIM_FALLBACK_SEC,
                ];
                $acquired = true;
                return $claims;
            });
        });
        if ($acquired) return $owner;
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new KeyLockBusyException('cache claim: «' . $key . '» занят другим процессом дольше '
        . $timeout . ' с — работа НЕ выполнялась.');
}

function cacheEntryClaimRelease(string $key, string $owner): void {
    if ($owner === '') return;
    $hash = hash('sha256', cacheNormalizeKey($key));
    cacheWithFamilyLock(static function () use ($key, $hash, $owner): void {
        cacheClaimStateUpdate($key, static function (array $claims) use ($hash, $owner): ?array {
            if (isset($claims[$hash]) && is_array($claims[$hash])
                && hash_equals($owner, (string)($claims[$hash]['owner'] ?? ''))) {
                unset($claims[$hash]); return $claims;
            }
            return null;
        });
    });
}

/** @return mixed */
function cacheWithEntryClaim(string $key, callable $work, float $timeout = STORE_LOCK_TIMEOUT) {
    $owner = cacheEntryClaimAcquire($key, $timeout);
    try {
        return $work();
    } finally {
        cacheEntryClaimRelease($key, $owner);
    }
}

/** @return mixed */
function cacheWithFamilyLock(callable $work) {
    return withKeyLock('cache-family-quota', static function () use ($work) {
        // После process-crash могут остаться tmp и store-lock без data-файла.
        // Под family-lock активного cache-writer уже нет, поэтому такие точные
        // cache-owned имена можно безопасно убрать до и после штатной работы.
        cacheCleanupOwnedOrphans();
        try {
            return $work();
        } finally {
            cacheCleanupOwnedOrphans();
        }
    });
}

/** Точное имя нового cache data; полный SHA-256 не смешивает разные keys. */
function cacheIsDataFilename(string $name): bool {
    return preg_match('/^cache-(?:[A-Za-z0-9_-]{1,40}|key)-[a-f0-9]{64}\.php$/D', $name) === 1;
}

/** Старые 32-bit cache-имена небезопасны как identity и только удаляются. */
function cacheIsLegacyOwnedFilename(string $name): bool {
    return preg_match(
        '/^cache-(?:[A-Za-z0-9_-]{1,40}|key)-[a-f0-9]{8}\.php(?:\.lock\.php|\.tmp-[0-9]+-[a-f0-9]{8}\.php)?$/D',
        $name
    ) === 1;
}

/** Убрать только точные cache tmp и store-lock, у которых нет data-owner. */
function cacheCleanupOwnedOrphans(): int {
    storeEnsureDir(DATA_ROOT);
    $removed = 0;
    $it = new FilesystemIterator(DATA_ROOT, FilesystemIterator::SKIP_DOTS);
    foreach ($it as $entry) {
        $name = $entry->getFilename();
        $isLegacy = cacheIsLegacyOwnedFilename($name);
        $isTmp = preg_match(
            '/^cache-(?:[A-Za-z0-9_-]{1,40}|key)-[a-f0-9]{64}\.php\.tmp-[0-9]+-[a-f0-9]{8}\.php$/D',
            $name
        ) === 1;
        $isStoreLock = preg_match(
            '/^cache-(?:[A-Za-z0-9_-]{1,40}|key)-[a-f0-9]{64}\.php\.lock\.php$/D',
            $name
        ) === 1;
        $isClaimTmp = preg_match(
            '/^cache-entry-claims-[0-9]+\.php\.tmp-[0-9]+-[a-f0-9]{8}\.php$/D',
            $name
        ) === 1;
        $isClaimStoreLock = preg_match(
            '/^cache-entry-claims-[0-9]+\.php\.lock\.php$/D', $name
        ) === 1;
        if (!$isLegacy && !$isTmp && !$isStoreLock && !$isClaimTmp && !$isClaimStoreLock) continue;

        $path = $entry->getPathname();
        if (($isStoreLock && !$isLegacy) || $isClaimStoreLock) {
            $data = substr($path, 0, -strlen('.lock.php'));
            clearstatcache(true, $data);
            if (is_link($data)) {
                throw new RuntimeException('cache: data-owner orphan lock не является обычным файлом');
            }
            $dataStat = @lstat($data);
            if (is_array($dataStat)) {
                if ((((int)($dataStat['mode'] ?? 0)) & 0170000) !== 0100000) {
                    throw new RuntimeException('cache: data-owner orphan lock не является обычным файлом');
                }
                continue;
            }
            if (file_exists($data)) {
                throw new RuntimeException('cache: data-owner orphan lock нельзя проверить');
            }
        }
        if (is_link($path) || !is_file($path) || !@unlink($path)) {
            throw new RuntimeException('cache: не удалить orphan family-файл ' . $name);
        }
        $removed++;
    }
    return $removed;
}

function cacheAssertManagedFile(string $file): void {
    if (dirname($file) !== rtrim(DATA_ROOT, '/') || !cacheIsDataFilename(basename($file))) {
        throw new InvalidArgumentException('cache: путь не принадлежит cache family');
    }
}

/**
 * Bounded-memory snapshot family usage. При первом превышении можно остановиться:
 * legacy over-quota всё равно fail-closed, а cleanup использует streaming delete.
 *
 * @return array{count:int,bytes:int,target_exists:bool,target_bytes:int,paths:string[],overflow:bool}
 */
function cacheFamilyScan(?string $target = null, bool $collectPaths = false): array {
    storeEnsureDir(DATA_ROOT);
    $count = 0;
    $bytes = 0;
    $targetExists = false;
    $targetBytes = 0;
    $paths = [];
    $overflow = false;

    $it = new FilesystemIterator(DATA_ROOT, FilesystemIterator::SKIP_DOTS);
    foreach ($it as $entry) {
        $name = $entry->getFilename();
        if (!cacheIsDataFilename($name)) continue;
        $path = $entry->getPathname();
        $stat = @lstat($path);
        if (!is_array($stat) || (((int)($stat['mode'] ?? 0) & 0170000) !== 0100000)) {
            throw new CacheStateLimitException('cache: family содержит не обычный data-файл');
        }
        $size = (int)($stat['size'] ?? -1);
        if ($size < 0 || $size > PHP_INT_MAX - $bytes) {
            throw new CacheStateLimitException('cache: размер family не представим');
        }
        $count++;
        $bytes += $size;
        if ($target !== null && $path === $target) {
            $targetExists = true;
            $targetBytes = $size;
        }
        if ($collectPaths) $paths[] = $path;
        if ($count > CACHE_FAMILY_MAX_FILES || $bytes > CACHE_FAMILY_MAX_BYTES) {
            $overflow = true;
            break;
        }
    }

    return ['count' => $count, 'bytes' => $bytes,
        'target_exists' => $targetExists, 'target_bytes' => $targetBytes,
        'paths' => $paths, 'overflow' => $overflow];
}

function cacheAssertFamilyUsage(array $usage): void {
    if (!empty($usage['overflow']) || (int)$usage['count'] > CACHE_FAMILY_MAX_FILES
        || (int)$usage['bytes'] > CACHE_FAMILY_MAX_BYTES) {
        throw new CacheStateLimitException('cache: family больше допустимого предела');
    }
}

/** Проверить будущие count/bytes с вычитанием прежнего размера target. */
function cacheAssertFamilyCandidate(array $usage, int $candidateBytes, bool $existing): void {
    cacheAssertFamilyUsage($usage);
    $oldBytes = $existing ? (int)$usage['target_bytes'] : 0;
    $nextCount = (int)$usage['count'] + ($existing ? 0 : 1);
    $nextBytes = (int)$usage['bytes'] - $oldBytes + $candidateBytes;
    if ($nextCount > CACHE_FAMILY_MAX_FILES || $nextBytes > CACHE_FAMILY_MAX_BYTES) {
        throw new CacheStateLimitException('cache: запись превышает общий предел family');
    }
}

/** Размер value в том же JSON-режиме, в котором его положит store. */
function cacheValueStoredBytes($value): int {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new CacheStateLimitException('cache: значение не сериализуется');
    }
    return strlen($json);
}

/** Точный размер cache snapshot вместе с исполняемым private-header. */
function cacheStoredBytes(array $row): int {
    try {
        return strlen(storeEncode($row));
    } catch (Throwable $e) {
        throw new CacheStateLimitException('cache: состояние не сериализуется', 0, $e);
    }
}

function cacheAssertValueWithinLimit($value): void {
    if (cacheValueStoredBytes($value) > CACHE_VALUE_MAX_BYTES) {
        throw new CacheStateLimitException('cache: значение больше допустимого предела');
    }
}

/** Проверить candidate до atomic publish; лишние legacy-поля тоже входят в cap. */
function cacheAssertRowWithinLimits(array $row): array {
    if (array_key_exists('value', $row)) cacheAssertValueWithinLimit($row['value']);
    if (cacheStoredBytes($row) > CACHE_MAX_BYTES) {
        throw new CacheStateLimitException('cache: файл больше допустимого предела');
    }
    return $row;
}

/** Единственная bounded read-точка, включая проверку legacy до JSON decode. */
function cacheStoreRead(string $file): ?array {
    cacheAssertManagedFile($file);
    return cacheWithFamilyLock(static function () use ($file): ?array {
        $row = storeRead($file, CACHE_MAX_BYTES);
        return is_array($row) ? cacheAssertRowWithinLimits($row) : null;
    });
}

/** Единственная bounded RMW-точка cache: candidate проверяется под EX-lock. */
function cacheStoreUpdate(string $file, callable $mutator): array {
    cacheAssertManagedFile($file);
    return cacheWithFamilyLock(static function () use ($file, $mutator): array {
        $usage = cacheFamilyScan($file);
        cacheAssertFamilyUsage($usage);
        $existing = !empty($usage['target_exists']);

        // Missing target проверяем до storeUpdate: rejected unique key не должен
        // оставить новый per-file lock и обойти file-count ceiling.
        if (!$existing) {
            $next = $mutator([]);
            if (!is_array($next)) return [];
            $next = cacheAssertRowWithinLimits($next);
            cacheAssertFamilyCandidate($usage, cacheStoredBytes($next), false);
            return storeUpdate($file, static function (array $prev) use ($file, $next): array {
                clearstatcache(true, $file);
                if (is_file($file) || is_link($file)) {
                    throw new CacheStateLimitException('cache: target изменился вне family lock');
                }
                return $next;
            }, STORE_LOCK_TIMEOUT, CACHE_MAX_BYTES);
        }

        return storeUpdate($file, static function (array $prev) use ($mutator, $usage): ?array {
            if (cacheStoredBytes($prev) !== (int)$usage['target_bytes']) {
                throw new CacheStateLimitException('cache: target изменился вне family lock');
            }
            $next = $mutator($prev);
            if (!is_array($next)) return null;
            $next = cacheAssertRowWithinLimits($next);
            cacheAssertFamilyCandidate($usage, cacheStoredBytes($next), true);
            return $next;
        }, STORE_LOCK_TIMEOUT, CACHE_MAX_BYTES);
    });
}

/** Файл памяти для ключа. Имя читаемое, однозначность даёт хвост-отпечаток. */
function cacheFileFor(string $key): string {
    $key = cacheNormalizeKey($key);
    $slug = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $key), '-');
    return DATA_ROOT . '/cache-' . ($slug === '' ? 'key' : substr($slug, 0, 40))
        . '-' . hash('sha256', $key) . '.php';
}

/**
 * Запомненное значение, если оно ещё годится.
 * @return array{hit: bool, value: mixed, stale: bool}
 */
function cachePeek(string $key): array {
    $file = cacheFileFor($key);
    try {
        $row = cacheStoreRead($file);
    } catch (StoreCorruptedException $e) {
        // Порченый кеш — не повод останавливать приложение: значение всегда
        // можно получить заново. Копия уже отложена самим store для разбора.
        return ['hit' => false, 'value' => null, 'stale' => false];
    }
    if (!is_array($row) || !array_key_exists('value', $row)
        || !hash_equals($key, (string)($row['cache_key'] ?? ''))) {
        return ['hit' => false, 'value' => null, 'stale' => false];
    }
    $fresh = (int)($row['expires'] ?? 0) > time();
    return ['hit' => $fresh, 'value' => $row['value'], 'stale' => !$fresh];
}

/**
 * Отдать запомненное или получить и запомнить.
 *
 * @param string   $key    Что запоминаем: «departments», «fields-deal».
 * @param int      $ttl    Сколько секунд значение считается годным (1..CACHE_MAX_TTL).
 * @param callable $loader function(): mixed — как получить значение заново.
 * @param bool     $force  Человек нажал «Обновить» — взять заново, минуя память.
 * @return mixed Значение загрузчика.
 */
function cacheRemember(string $key, int $ttl, callable $loader, bool $force = false) {
    if ($ttl < 1 || $ttl > CACHE_MAX_TTL) {
        throw new InvalidArgumentException('cacheRemember: срок памяти должен быть от 1 с до суток, получено ' . $ttl . '.');
    }
    if (!$force) {
        $peek = cachePeek($key);
        if ($peek['hit']) return $peek['value'];
    }

    $file = cacheFileFor($key);
    $store = static function ($value) use ($file, $key, $ttl) {
        cacheStoreUpdate($file, static fn(array $prev): array => [
            'cache_key' => $key,
            'expires' => time() + $ttl,
            'value' => $value,
        ]);
        return $value;
    };

    $podZamkom = static function () use ($key, $force, $loader, $store) {
        return cacheWithEntryClaim($key, static function () use ($key, $force, $loader, $store) {
            // Пока ждали замок, сосед мог уже всё принести. Тогда второй поход в
            // портал — чистая трата корзины запросов.
            if (!$force) {
                $again = cachePeek($key);
                if ($again['hit']) return $again['value'];
            }
            return $store($loader());
        });
    };

    try {
        return $podZamkom();
    } catch (KeyLockBusyException $e) {
        // Замок держат дольше отведённого. Прошлое значение отдать можно и нужно:
        // отказать человеку из-за того, что сосед обновляет справочник, нельзя.
        $stale = cachePeek($key);
        if ($stale['stale']) return $stale['value'];

        // ХОЛОДНАЯ ПАМЯТЬ. Прежде здесь запускался загрузчик ВНЕ замка — ровно
        // то, ради чего замок и заведён: два процесса шли в портал за одним и
        // тем же ответом, каждый запоминал своё, и клиенты получали РАЗНЫЕ
        // значения (находка ревью R3-009, 09.08.2026, подтверждена двумя
        // процессами). Правильный ход один — дождаться соседа и прочитать то,
        // что он опубликовал: он уже в портале, наш второй запрос ничего не
        // ускорит. Ждём ещё один такой же срок: сосед обычно публикует в первые
        // же миллисекунды после нашего отказа.
        try {
            return $podZamkom();
        } catch (KeyLockBusyException $vtoroy) {
            $pozdno = cachePeek($key);
            if ($pozdno['hit'] || $pozdno['stale']) return $pozdno['value'];
            // Загрузчик соседа идёт дольше двух сроков ожидания. Своего второго
            // похода в портал не делаем: он не спасёт этот запрос (ответ будет
            // так же долгим), но удвоит нагрузку ровно там, где портал и так не
            // успевает. Отдаём типизированный отказ — его видно и в журнале, и
            // в обработчике вызывающего.
            throw new CacheBusyException('cacheRemember: «' . $key . '» прямо сейчас получает соседний запрос '
                . '(дольше ' . (STORE_LOCK_TIMEOUT * 2) . ' с) — значение НЕ получено. Повторите запрос.',
                0, $vtoroy);
        }
    }
}

/**
 * Забыть значение (настройки изменились — прежний ответ уже неверен).
 *
 * ПОД ТЕМ ЖЕ ЗАМКОМ, что и загрузка. Иначе «забудь» успевал между началом
 * чужого загрузчика и его публикацией: файла в этот момент нет, снимать нечего,
 * вызов возвращался успехом — а через секунду сосед публиковал СТАРОЕ значение,
 * и забытое воскресало на весь срок памяти (находка ревью R3-009).
 */
function cacheForget(string $key): void {
    $file = cacheFileFor($key);
    $snyat = static function () use ($file, $key): void {
        cacheWithFamilyLock(static function () use ($file, $key): void {
            clearstatcache(true, $file);
            if (is_link($file) || (file_exists($file) && !is_file($file))) {
                throw new RuntimeException('cacheForget: data-path не является обычным файлом.');
            }
            if (is_file($file) && !@unlink($file)) {
                // Молча оставлять устаревшее значение нельзя: человек нажал «Обновить»
                // и ждёт свежих данных, а получит прежние.
                throw new RuntimeException('cacheForget: не удалось забыть «' . $key . '» — файл памяти не удаляется.');
            }
            // storeUpdate держит отдельный lock рядом. Удаляем его под теми же
            // shard+family locks, иначе churn разных keys снова рос бы без cap.
            $storeLock = $file . '.lock.php';
            if (is_link($storeLock) || (file_exists($storeLock) && !is_file($storeLock))) {
                throw new RuntimeException('cacheForget: lock-path не является обычным файлом.');
            }
            if (is_file($storeLock) && !@unlink($storeLock)) {
                throw new RuntimeException('cacheForget: не удалить lock забытой памяти.');
            }
        });
    };
    try {
        cacheWithEntryClaim($key, $snyat);
    } catch (KeyLockBusyException $e) {
        // Сосед ещё в портале. Ждём второй срок: забыть надо ПОСЛЕ его
        // публикации, иначе снимем пустоту и оставим старое значение жить.
        try {
            cacheWithEntryClaim($key, $snyat);
        } catch (KeyLockBusyException $vtoroy) {
            throw new CacheBusyException('cacheForget: «' . $key . '» прямо сейчас получает соседний запрос — '
                . 'значение НЕ забыто. Снять его сейчас нельзя: сосед опубликовал бы прежнее значение уже '
                . 'после «забудь». Повторите.', 0, $vtoroy);
        }
    }
}

/** Все файлы памяти — для уборки при удалении приложения и переносе портала. */
function cacheFiles(): array {
    return cacheWithFamilyLock(static function (): array {
        $usage = cacheFamilyScan(null, true);
        cacheAssertFamilyUsage($usage);
        $paths = $usage['paths'];
        sort($paths);
        return $paths;
    });
}

/** Streaming cleanup всей family, включая orphan tmp/lock, под owner-lock. */
function cacheForgetAll(): int {
    return cacheWithFamilyLock(static function (): int {
        storeEnsureDir(DATA_ROOT);
        $removed = 0;
        // Новые claims не войдут, пока удерживается family lock. Активный
        // loader/forget нельзя обгонять lifecycle-очисткой.
        for ($slot = 0, $shards = max(1, (int)CACHE_LOCK_SHARDS); $slot < $shards; $slot++) {
            $claimFile = DATA_ROOT . '/cache-entry-claims-' . $slot . '.php';
            if (!is_file($claimFile)) continue;
            $state = storeUpdate($claimFile, static function (array $state): array {
                $claims = is_array($state['claims'] ?? null) ? $state['claims'] : [];
                $now = microtime(true);
                foreach ($claims as $hash => $claim) {
                    if (!is_string($hash) || !is_array($claim)
                        || !cacheClaimOwnerAlive($claim, $now)) unset($claims[$hash]);
                }
                return ['claims' => $claims];
            }, STORE_LOCK_TIMEOUT, CACHE_CLAIMS_MAX_BYTES);
            if (!empty($state['claims'])) {
                throw new CacheBusyException('cache: очистка family невозможна — есть активный loader');
            }
        }
        $it = new FilesystemIterator(DATA_ROOT, FilesystemIterator::SKIP_DOTS);
        foreach ($it as $entry) {
            $name = $entry->getFilename();
            $isData = cacheIsDataFilename($name);
            $isStoreLock = preg_match(
                '/^cache-(?:[A-Za-z0-9_-]{1,40}|key)-[a-f0-9]{64}\.php\.lock\.php$/D',
                $name
            ) === 1;
            $isTmp = preg_match(
                '/^cache-(?:[A-Za-z0-9_-]{1,40}|key)-[a-f0-9]{64}\.php\.tmp-[0-9]+-[a-f0-9]{8}\.php$/D',
                $name
            ) === 1;
            $isLegacy = cacheIsLegacyOwnedFilename($name);
            $isClaim = preg_match('/^cache-entry-claims-[0-9]+\.php(?:\.lock\.php)?$/D', $name) === 1;
            if (!$isData && !$isStoreLock && !$isTmp && !$isLegacy && !$isClaim) continue;
            $path = $entry->getPathname();
            if (is_link($path) || !is_file($path) || !@unlink($path)) {
                throw new RuntimeException('cache: не удалить family-файл ' . $name);
            }
            $removed++;
        }
        return $removed;
    });
}
