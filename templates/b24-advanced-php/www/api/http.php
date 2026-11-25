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
 * api/http.php — единый вход для ЛЮБОГО эндпоинта приложения.
 *
 * Зачем этот файл существует. Каждый новый эндпоинт раньше писался с нуля:
 * кто-то забывал no-store, кто-то принимал POST по cookie, кто-то отдавал
 * `$e->getMessage()` вместе с токеном внутри, кто-то не ограничивал размер тела.
 * Ревью находило это снова и снова — в разных проектах одно и то же. Поэтому
 * защита переехала из «надо не забыть» в один вызов:
 *
 *     require_once __DIR__ . '/http.php';
 *     $ctx = apiBoot(['methods' => ['POST'], 'role' => 'edit']);   // 403/405 сам
 *     $body = apiBody();                                            // JSON с лимитом
 *     $id   = apiInt($body, 'id', ['min' => 1]);                    // 400 при мусоре
 *     apiOk(['item' => ...]);                                       // {ok:true,...}
 *
 * Что apiBoot() делает сам, до единой строки прикладного кода:
 *   1. заголовки: no-store + запрет угадывания типа + скромный referrer;
 *   2. shared-fence активной установки до ЛЮБОЙ записи в state/журнал;
 *   3. метод из белого списка (иначе 405);
 *   4. сессия по заголовку X-App-Token (иначе 403) — cookie здесь не принимается
 *      никогда: она выдана с SameSite=None ради iframe, и чужой сайт приложил бы
 *      её к своему запросу;
 *   5. роль не ниже требуемой (иначе 403);
 *   6. ограничение частоты запросов на пользователя (иначе 429).
 *
 * Ошибки наружу — только человеческим текстом. Технические подробности уходят в
 * общий журнал приложения (data/logs/) через logAppError(), где их видит разработчик, а не гость.
 */

// Это НЕ дублирование index.php: каждый файл api/*.php — самостоятельная точка
// входа, и фронт дёргает именно их. На хостинге с display_errors=1 любой Notice
// или Deprecated печатается ПЕРЕД JSON: разбор ответа на клиенте падает, а в тело
// уносится путь на сервере. Ошибки по-прежнему собираем полностью — но в журнал,
// а не в ответ. (Блок был потерян при правке 05.08 и возвращён по находке ревью.)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/fmt.php';       // готовые даты портала и деньги доступны каждому endpoint
// Журнал — каждому эндпоинту, а не только ветке отказа. Раньше journal.php
// подключался лениво (ниже, при записи отказа гейта), и любой эндпоинт, который
// писал в журнал на УСПЕШНОМ пути, звал appLog() у неподключённого модуля:
// вместо задуманной деградации человек получал 500. Так падал поиск сотрудников
// при отказе department.get (находка ревью 09.08.2026) — и так же упал бы любой
// новый эндпоинт, написанный по образцу соседей. Цена — разбор 42 КБ на запрос,
// причём lib.php (152 КБ) здесь и так подключается строкой выше.
require_once __DIR__ . '/journal.php';

/** Сколько байт тела принимаем по умолчанию. Больше — почти всегда ошибка или атака. */
const API_MAX_BODY = 262144;          // 256 КБ

/** Ограничение частоты: сколько запросов на пользователя за окно. */
const API_RATE_LIMIT  = 120;
const API_RATE_WINDOW = 60;           // секунд

/**
 * Ответ в едином формате. Все эндпоинты отвечают одинаково, поэтому фронт
 * разбирает ответ одной функцией, а не «как повезло на этом эндпоинте».
 *
 * Успех:  {"ok":true,  ...данные}
 * Отказ:  {"ok":false, "error":"текст человеку", "code":"machine_code"}
 */
/**
 * JSON без тихого пустого тела. Битая UTF-8 из внешнего API заменяется U+FFFD:
 * это безопаснее, чем ответ 200 с пустой строкой, который фронт примет за обрыв.
 */
function apiJsonEncode(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        throw new UnexpectedValueException('Ответ не сериализуется в JSON: ' . json_last_error_msg());
    }
    return $json;
}

/**
 * Отдать JSON и ЗАВЕРШИТЬ запрос: в конце exit, возврата отсюда нет.
 * Пометка нужна статическому анализу — иначе он считает, что после отказа
 * выполнение продолжается, и сыплет ложными «переменная может быть не определена».
 *
 * @return never
 */
function apiJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        sendNoStoreHeaders();
        header('Content-Type: application/json; charset=utf-8');
    }
    try {
        echo apiJsonEncode($payload);
    } catch (Throwable $e) {
        // Здесь нельзя звать apiFail(): он снова придёт в apiJson() с тем же
        // потенциально несериализуемым payload. Отдаём константный валидный JSON.
        if (!headers_sent()) http_response_code(500);
        echo '{"ok":false,"error":"Не удалось сформировать ответ","code":"json_encode_failed"}';
    }
    exit;
}

/** Данные успешного ответа: служебный ok вызывающий переопределить не может. */
function apiSuccessPayload(array $data = []): array {
    $data['ok'] = true;
    return $data;
}

/**
 * Успешный ответ. Управление не возвращает — внутри apiJson() с exit.
 *
 * @return never
 */
function apiOk(array $data = []): void {
    apiJson(apiSuccessPayload($data));
}

/**
 * Отказ. $error — то, что прочитает человек; $code — короткий машинный признак
 * для фронта; $logDetail — техническая правда, она идёт ТОЛЬКО в журнал.
 *
 * Функция НЕ ВОЗВРАЩАЕТ управление: внутри exit. Без этой пометки статический
 * анализ считает, что после ветки с отказом выполнение продолжается, и сообщает
 * о «возможно неопределённых» переменных там, где их не может не быть — четыре
 * такие ложные находки прятали настоящие. В сигнатуре `never` не ставим: шаблон
 * заявляет совместимость с PHP 7.4, где такого типа ещё нет.
 *
 * @return never
 */
function apiFail(string $error, int $status = 400, string $code = 'bad_request', string $logDetail = '', array $extra = []): void {
    $ref = $logDetail !== '' ? logAppError($code . ': ' . $logDetail) : '';

    // Отказы гейта записываем ВСЕГДА, даже когда подробностей нет. Раньше они не
    // фиксировались нигде, и разбор «мне не даёт зайти» упирался в слово человека:
    // отказ по роли, отказ по источнику и исчерпанный лимит выглядели одинаково.
    // …но не бесконечно: адрес приложения публичен, и сканер портов или чужой
    // скрипт в цикле раздували бы журнал строкой на запрос — то есть отказ гейта
    // превращался в способ занять диск. Двадцати записей в минуту на причину
    // хватает, чтобы увидеть картину; остальное отсекаем тем же счётчиком частоты,
    // что и запросы. Сбой счётчика не должен глушить запись — он вернёт true.
    $logDenied = false;
    if ($code === 'rate_limited') {
        // Основной limiter уже решил, первая ли это запись отказа в окне.
        // Второй apiRateAllow здесь удваивал fsync/rename на каждом 429.
        $logDenied = !empty($GLOBALS['__api_rate_first_denial']);
    } elseif (in_array($code, ['no_session', 'forbidden', 'cross_site',
                              'method_not_allowed', 'store_corrupted'], true)) {
        $logDenied = apiRateAllow('deny-log:' . $code, 20, 60);
    }
    if ($logDenied) {
        require_once __DIR__ . '/journal.php';
        $denyRef = appLog($code === 'store_corrupted' ? 'error' : 'warn', 'access.deny', [
            'причина' => $code,
            'путь'    => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            'метод'   => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        ], ['msg' => $error]);
        if ($ref === '') $ref = $denyRef;
    }
    $payload = ['ok' => false, 'error' => $error, 'code' => $code];
    if ($ref !== '') $payload['ref'] = $ref;
    // $extra — разбор отказа ДЛЯ ФРОНТА: например, какие именно поля формы не
    // прошли проверку, чтобы подсветить их на месте, а не показывать одну общую
    // строку под кнопкой. Технические подробности сюда класть нельзя — это
    // ответ наружу, для них есть $logDetail. Служебные ключи ответа не
    // перезаписываются: иначе вызывающий смог бы подменить ok или code.
    foreach ($extra as $k => $v) {
        if (!in_array($k, ['ok', 'error', 'code', 'ref'], true)) $payload[$k] = $v;
    }
    apiJson($payload, $status);
}

/**
 * Единственная точка, где рождается ответ на неожиданное исключение.
 * Наружу — «что-то пошло не так», в журнал — замаскированные подробности.
 * Сырой текст исключения отдавать нельзя: в него попадают токены и пути.
 *
 * Управление не возвращает — внутри apiFail() с exit (см. пояснение там же).
 *
 * @return never
 */
function apiFailFromThrowable(Throwable $e, string $human = 'Не удалось выполнить операцию. Попробуйте ещё раз.'): void {
    apiFail($human, 500, 'internal', (string)maskSecrets($e->getMessage()));
}

/**
 * Запрос пришёл со страницы самого приложения, а не с чужого сайта?
 *
 * Проверка ОДНА на всё приложение и живёт в session.php: копия здесь неизбежно
 * разошлась бы с оригиналом — одну ужесточили бы, вторая продолжала пускать.
 * Для мутаций требуем строгого признака своего источника.
 */
function apiFromOwnSite(bool $strict = false): bool {
    return requestFromOwnSite($strict);
}

// Файл rate-state переписывается целиком на каждый mutating/public request.
// Ceiling учитывает и cardinality, и фактический private-PHP serialization.
if (!defined('API_RATE_STATE_MAX_ITEMS')) define('API_RATE_STATE_MAX_ITEMS', 2048);
if (!defined('API_RATE_STATE_MAX_BYTES')) define('API_RATE_STATE_MAX_BYTES', 512 * 1024);

/** Bounded rate-state не смог безопасно вместить даже fixed/global buckets. */
final class ApiRateStateLimitException extends RuntimeException {}

/** Fixed/global bucket не зависит от внешнего адреса/token fingerprint. */
function apiRateBucketIsFixed(string $bucket): bool {
    return strpos($bucket, ':') === false;
}

function apiRateStateFits(array $state, int $maxItems, int $maxBytes): bool {
    return count($state) <= max(1, $maxItems)
        && strlen(storeEncode($state)) <= max(1, $maxBytes);
}

/**
 * Детерминированная bounded cleanup: expired, затем oldest dynamic. Текущий
 * bucket и fixed/global сохраняются; если это невозможно, current отклоняется.
 */
function apiRateBoundState(
    array $state,
    string $currentBucket,
    int $now,
    int $maxItems,
    int $maxBytes,
    bool &$currentKept,
    array $protectedBuckets = []
): array {
    $live = [];
    foreach ($state as $key => $row) {
        if (!is_array($row)) continue;
        $rowWindow = (int)($row['window'] ?? 0);
        if ($rowWindow > 0 && (int)($row['start'] ?? 0) + $rowWindow <= $now) continue;
        $live[(string)$key] = $row;
    }
    $currentKept = isset($live[$currentBucket]);
    if (apiRateStateFits($live, $maxItems, $maxBytes)) return $live;

    $oldest = [];
    $protected = array_fill_keys(array_map('strval', $protectedBuckets), true);
    foreach ($live as $key => $row) {
        if ($key === $currentBucket || isset($protected[$key]) || apiRateBucketIsFixed($key)) continue;
        $oldest[] = ['key' => $key, 'start' => (int)($row['start'] ?? 0)];
    }
    usort($oldest, static function (array $a, array $b): int {
        $byTime = $a['start'] <=> $b['start'];
        return $byTime !== 0 ? $byTime : strcmp((string)$a['key'], (string)$b['key']);
    });
    foreach ($oldest as $candidate) {
        unset($live[(string)$candidate['key']]);
        if (apiRateStateFits($live, $maxItems, $maxBytes)) return $live;
    }

    // Dynamic current не записываем, если один он не помещается рядом с
    // fixed/global contract. Request получает false; oversized state не пишется.
    if (isset($live[$currentBucket]) && !apiRateBucketIsFixed($currentBucket)) {
        unset($live[$currentBucket]);
        $currentKept = false;
        if (apiRateStateFits($live, $maxItems, $maxBytes)) return $live;
    }
    throw new ApiRateStateLimitException('rate-state: fixed/global buckets exceed bounded capacity');
}

/**
 * Атомарная иерархия лимитов: верхние buckets проверяются первыми, но получают
 * hit только когда запрос прошёл ВСЕ уровни. Поэтому исчерпанный global ceiling
 * не плодит dynamic keys, а один исчерпанный IP не выжигает резерв остальных.
 *
 * @param array<int,array{0:string,1:int}> $limits пары [bucket, limit]
 */
function apiRateAllowChain(array $limits, int $window = API_RATE_WINDOW): bool {
    $GLOBALS['__api_rate_failure'] = '';
    if ($window <= 0) return true;
    $specs = [];
    foreach ($limits as $spec) {
        if (!is_array($spec) || count($spec) < 2) {
            throw new InvalidArgumentException('rate chain: expected [bucket, limit]');
        }
        $bucket = (string)$spec[0];
        $limit = (int)$spec[1];
        if ($bucket === '' || $limit <= 0 || isset($specs[$bucket])) {
            throw new InvalidArgumentException('rate chain: invalid or duplicate bucket');
        }
        $specs[$bucket] = $limit;
    }
    if ($specs === []) return true;

    $now = time();
    $allowed = true;
    $firstDenied = false;
    $GLOBALS['__api_rate_first_denial'] = false;
    try {
        storeUpdate(RATELIMIT_FILE, static function (array $st) use (
            $specs, $window, $now, &$allowed, &$firstDenied
        ) {
            $out = [];
            foreach ($st as $key => $row) {
                if (!is_array($row)) continue;
                $rowWindow = (int)($row['window'] ?? 0);
                if ($rowWindow <= 0 || (int)($row['start'] ?? 0) + $rowWindow > $now) {
                    $out[(string)$key] = $row;
                }
            }

            $rows = [];
            foreach ($specs as $bucket => $limit) {
                $row = $out[$bucket] ?? ['start' => $now, 'hits' => 0];
                $effectiveWindow = max($window, (int)($row['window'] ?? 0));
                if ((int)$row['start'] + $effectiveWindow <= $now) {
                    $row = ['start' => $now, 'hits' => 0];
                    $effectiveWindow = $window;
                }
                $row['window'] = $effectiveWindow;
                if ((int)$row['hits'] >= $limit) {
                    $allowed = false;
                    $alreadyLogged = !empty($row['denial_logged']);
                    $row['denial_logged'] = true;
                    $out[$bucket] = $row;
                    $kept = true;
                    $bounded = apiRateBoundState(
                        $out, $bucket, $now, API_RATE_STATE_MAX_ITEMS,
                        API_RATE_STATE_MAX_BYTES, $kept, [$bucket]
                    );
                    if (!$kept) throw new ApiRateStateLimitException('rate chain: denied bucket evicted');
                    if ($alreadyLogged && $bounded === $st) return null;
                    $firstDenied = !$alreadyLogged;
                    return $bounded;
                }
                $rows[$bucket] = $row;
            }

            foreach ($rows as $bucket => $row) {
                unset($row['denial_logged']);
                $row['hits'] = (int)$row['hits'] + 1;
                $out[$bucket] = $row;
            }
            $protected = array_keys($rows);
            $current = (string)end($protected);
            $kept = true;
            $bounded = apiRateBoundState(
                $out, $current, $now, API_RATE_STATE_MAX_ITEMS,
                API_RATE_STATE_MAX_BYTES, $kept, $protected
            );
            if (!$kept) throw new ApiRateStateLimitException('rate chain: current bucket evicted');
            foreach ($protected as $bucket) {
                if (!isset($bounded[$bucket])) {
                    throw new ApiRateStateLimitException('rate chain: protected bucket evicted');
                }
            }
            return $bounded;
        }, STORE_LOCK_TIMEOUT, API_RATE_STATE_MAX_BYTES);
    } catch (StoreReadLimitException | ApiRateStateLimitException $e) {
        $GLOBALS['__api_rate_failure'] = 'capacity';
        return false;
    } catch (Throwable $e) {
        $GLOBALS['__api_rate_failure'] = 'storage';
        return false;
    }
    $GLOBALS['__api_rate_first_denial'] = $firstDenied;
    return $allowed;
}

/**
 * Ограничение частоты: скользящее окно по пользователю (или по IP, если сессии
 * ещё нет). Нужно не «от хакеров», а от собственного фронта в цикле и от
 * случайного скрипта, который за минуту выгребет весь портал через нас.
 *
 * Счётчик живёт в общем сторе под тем же замком, что и остальное состояние.
 * Файл маленький: ключи чистятся, как только окно прошло.
 *
 * @return bool false — лимит исчерпан (вызывающий отвечает 429).
 */
function apiRateAllow(string $bucket, int $limit = API_RATE_LIMIT, int $window = API_RATE_WINDOW): bool {
    $GLOBALS['__api_rate_failure'] = '';
    if ($limit <= 0 || $window <= 0) return true;
    $now = time();
    $allowed = true;
    $firstDenied = false;
    $GLOBALS['__api_rate_first_denial'] = false;
    try {
    storeUpdate(RATELIMIT_FILE, static function (array $st) use (
        $bucket, $limit, $window, $now, &$allowed, &$firstDenied
    ) {
        $out = [];
        foreach ($st as $key => $row) {
            // Чужие окна, которые уже истекли, не тащим дальше — иначе файл растёт вечно.
            if (!is_array($row)) continue;
            $rowWindow = (int)($row['window'] ?? 0);
            // Legacy-строка не помнит своего окна. Чужим текущим окном удалять её
            // нельзя; при следующем обращении к её bucket окно будет дописано.
            if ($rowWindow <= 0 || (int)($row['start'] ?? 0) + $rowWindow > $now) $out[$key] = $row;
        }
        $row = $out[$bucket] ?? ['start' => $now, 'hits' => 0];
        // Один и тот же bucket нельзя «переоткрыть» более коротким окном: иначе
        // вызов long→short досрочно сбрасывает уже накопленный длинный лимит.
        $effectiveWindow = max($window, (int)($row['window'] ?? 0));
        if ((int)$row['start'] + $effectiveWindow <= $now) {
            $row = ['start' => $now, 'hits' => 0];
            $effectiveWindow = $window;
        }
        $row['window'] = $effectiveWindow;
        if ((int)$row['hits'] >= $limit) {
            $allowed = false;
            $alreadyLogged = !empty($row['denial_logged']);
            $row['denial_logged'] = true;
            $out[$bucket] = $row;
            $currentKept = true;
            $bounded = apiRateBoundState(
                $out, $bucket, $now, API_RATE_STATE_MAX_ITEMS, API_RATE_STATE_MAX_BYTES, $currentKept
            );
            if (!$currentKept) throw new ApiRateStateLimitException('rate-state: current bucket evicted');
            if ($alreadyLogged && $bounded === $st) return null; // состояние не менялось — не переписываем файл
            $firstDenied = !$alreadyLogged;
            return $bounded;
        }
        unset($row['denial_logged']);
        $row['hits'] = (int)$row['hits'] + 1;
        $out[$bucket] = $row;
        $currentKept = true;
        $bounded = apiRateBoundState(
            $out, $bucket, $now, API_RATE_STATE_MAX_ITEMS, API_RATE_STATE_MAX_BYTES, $currentKept
        );
        if (!$currentKept) $allowed = false;
        return $bounded;
    }, STORE_LOCK_TIMEOUT, API_RATE_STATE_MAX_BYTES);
    } catch (StoreReadLimitException | ApiRateStateLimitException $e) {
        // Capacity exhaustion на публичном expensive path fail-closed: иначе
        // огромный legacy-файл отключал бы именно тот ceiling, который обязан
        // остановить дальнейший churn. Оригинал остаётся неизменным. Не пишем
        // сюда же incident: broken limiter не может надёжно дедуплицировать его
        // между PHP-процессами, а строка на запрос сама стала бы disk-amplifier.
        $GLOBALS['__api_rate_failure'] = 'capacity';
        return false;
    } catch (Throwable $e) {
        // Corrupt/lock/I/O failure тоже сохраняет защитный ceiling. In-process
        // timestamp не является межпроцессным пределом, поэтому здесь выбран
        // детерминированный bounded policy: deny и ноль дополнительных записей.
        // Состояние private store отдельно диагностируют health/preflight.
        $GLOBALS['__api_rate_failure'] = 'storage';
        return false;
    }
    $GLOBALS['__api_rate_first_denial'] = $firstDenied;
    return $allowed;
}

/**
 * Адреса фронт-прокси, чьему X-Forwarded-For можно верить.
 *
 * Пусто по умолчанию, и это осознанно: на обычном хостинге (Apache + PHP-FPM,
 * как у Netangels) прокси перед приложением нет, REMOTE_ADDR настоящий. Стоит
 * поверить заголовку без такой проверки — и он превращается в оружие: клиент
 * сам его пишет, поэтому одним заголовком обходится любой лимит на адрес, а
 * подставив чужой адрес, можно закрыть вход именно ему. Ставьте сюда адрес
 * балансировщика ТОЛЬКО если он реально стоит перед приложением.
 */
if (!defined('APP_TRUSTED_PROXIES')) define('APP_TRUSTED_PROXIES', []);

/**
 * Чистое правило разбора адреса — без суперглобалов, чтобы его можно было
 * проверить прямым вызовом на всех топологиях, а не только на своей.
 *
 * Идём по цепочке справа налево и отбрасываем СВОИ прокси: клиент — первый
 * адрес, который мы не признаём своим. Брать просто «крайний справа валидный»
 * нельзя: при двух хопах он окажется адресом собственного балансировщика (все
 * клиенты сольются в один, и общий потолок станет рубильником на всех), а при
 * нестандартном хвосте («unknown», ip:port, [IPv6]) разбор уедет левее — к
 * значению, которое написал сам клиент.
 */
function apiResolveClientIp(string $remoteAddr, string $forwardedFor, array $trusted): string {
    $remoteOk = filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : 'unknown';
    if ($trusted === [] || !in_array($remoteOk, $trusted, true)) return $remoteOk;
    if ($forwardedFor === '') return $remoteOk;

    foreach (array_reverse(array_map('trim', explode(',', $forwardedFor))) as $hop) {
        // Нормализуем то, что дописывают прокси: [2001:db8::1] и 1.2.3.4:5566.
        if (preg_match('~^\[(.+)\](?::\d+)?$~', $hop, $m)) $hop = $m[1];
        elseif (substr_count($hop, ':') === 1 && preg_match('~^([^:]+):\d+$~', $hop, $m)) $hop = $m[1];
        if (!filter_var($hop, FILTER_VALIDATE_IP)) return $remoteOk;   // мусор в цепочке — не гадаем
        if (in_array($hop, $trusted, true)) continue;                  // это наш прокси, идём левее
        return $hop;
    }
    return $remoteOk;
}

/** IP клиента: X-Forwarded-For — только из рук доверенного прокси, иначе REMOTE_ADDR. */
function apiClientIp(): string {
    return apiResolveClientIp(
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        defined('APP_TRUSTED_PROXIES') ? (array)APP_TRUSTED_PROXIES : []
    );
}

/**
 * Единый вход: заголовки → fence установки → метод → сессия → роль → частота.
 *
 * @param array $opts
 *   'methods'   => ['GET']        какие методы разрешены (остальным 405)
 *   'role'      => 'view'|'edit'|'admin'   минимальная роль (по умолчанию 'view')
 *   'session'   => true|false     нужна ли сессия вообще (false — публичный
 *                                 эндпоинт: install/event-хендлер, он
 *                                 аутентифицируется своим способом)
 *   'rate'      => [limit, window] своё ограничение частоты
 *   'rate_failure' => 'deny'|'diagnostic' при поломке limiter: 503 либо
 *                                 пропустить только административную диагностику
 * @return array Контекст: ['session' => ?array, 'role' => string, 'uid' => int]
 */
function apiBoot(array $opts = []): array {
    $methods = $opts['methods'] ?? ['GET', 'POST'];
    $needSession = $opts['session'] ?? true;
    $needRole = (string)($opts['role'] ?? 'view');

    // Ролей ровно три. Опечатка или скопированный из старой доки пример ('user',
    // 'manager') раньше означал «нужен ранг 99» — то есть эндпоинт закрывался
    // МОЛЧА для всех, включая владельца. Разработчик видел 403 у себя и часами
    // искал ошибку в правах, хотя дело было в одной строке настройки. Такое надо
    // называть вслух: это не отказ в правах, а поломка самого эндпоинта.
    if (!in_array($needRole, ['view', 'edit', 'admin'], true)) {
        if (function_exists('appLog')) {
            appLog('error', 'api.bad_role', [
                'запрошенная роль' => $needRole,
                'допустимые' => 'view, edit, admin',
            ]);
        }
        apiFail('Эндпоинт настроен неверно: у приложения нет роли «' . htmlspecialchars($needRole, ENT_QUOTES, 'UTF-8')
            . '». Допустимые: view, edit, admin.', 500, 'endpoint_misconfigured');
    }

    if (!headers_sent()) {
        sendNoStoreHeaders();
        header('Content-Type: application/json; charset=utf-8');
        // Браузер не должен «угадывать» тип ответа и подставлять его как скрипт,
        // а адрес нашей страницы не должен утекать на чужие сайты.
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        // Эндпоинты вообще не предназначены для показа в рамке — ни в чьей.
        header("Content-Security-Policy: frame-ancestors 'none'");
    }

    // Fence берём ДО проверки метода. Даже отказ 405 пишет rate-limit и журнал;
    // без shared-lock такой отказ мог одновременно с переустановкой воскресить
    // ratelimit/log старого поколения поверх уже опубликованного нового state.
    try {
        $installGeneration = appLifecycleHoldRequest();
    } catch (InstallGenerationMismatchException $e) {
        $failure = appInstallationFailureSpec($e->phase);
        if ($failure['retry_after'] > 0 && !headers_sent()) {
            header('Retry-After: ' . $failure['retry_after']);
        }
        apiFail($failure['api_error'], $failure['status'], $failure['code']);
    } catch (InstallLifecycleBusyException $e) {
        apiFail('Установка приложения обновляется, повторите запрос', 503, 'installation_busy');
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        // Preflight не разрешаем: cross-site вызовов у приложения нет по замыслу.
        apiFail('Метод не поддерживается', 405, 'method_not_allowed');
    }
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        apiFail('Метод не поддерживается', 405, 'method_not_allowed');
    }

    $rateFailurePolicy = (string)($opts['rate_failure'] ?? 'deny');
    if (!in_array($rateFailurePolicy, ['deny', 'diagnostic'], true)) {
        apiFail('Эндпоинт настроен неверно: неизвестная политика сбоя защитного лимита.',
            500, 'endpoint_misconfigured');
    }
    if ($rateFailurePolicy === 'diagnostic'
        && (!$needSession || $needRole !== 'admin' || !in_array($method, ['GET', 'HEAD'], true))) {
        // Это защитный контракт, а не рекомендация для автора endpoint. Иначе
        // скопированная опция позволяла POST/view продолжить мутацию именно в
        // момент, когда rate-state сломан и limiter ничего не гарантирует.
        apiFail('Эндпоинт настроен неверно: диагностика лимита разрешена только административному чтению.',
            500, 'endpoint_misconfigured');
    }

    $session = null;
    $uid = 0;
    $role = 'anon';
    if ($needSession) {
        $session = requireSession();                 // 403 и выход, если токена нет
        $uid  = (int)($session['uid'] ?? 0);
        $role = (string)($session['role'] ?? 'view');
        // Мутация с чужого сайта недопустима совсем; для чтения послабление —
        // человек может открыть страницу приложения по прямой ссылке.
        if (!apiFromOwnSite(!in_array($method, ['GET', 'HEAD'], true))) {
            apiFail('Запрос пришёл с другого сайта', 403, 'cross_site');
        }
        // Администратор портала проходит всегда — это страховка от самоблокировки:
        // если список доступа испорчен или владелец уволился, кто-то должен иметь
        // возможность починить список.
        if (!roleAtLeast($role, $needRole) && empty($session['isAdmin'])) {
            apiFail('Недостаточно прав для этого действия', 403, 'forbidden');
        }
    }

    // Счёт частоты стоит записи в общий файл под замком, поэтому по умолчанию
    // считаем только изменяющие запросы: их единицы, а чтения идут пачками с
    // каждой открытой вкладки, и лимит на них выстраивал бы всё приложение в
    // очередь на замке. Тяжёлому GET-эндпоинту лимит можно попросить явно:
    // apiBoot(['rate' => [30, 60]]).
    $defaultRate = in_array($method, ['GET', 'HEAD'], true) ? [0, 0] : [API_RATE_LIMIT, API_RATE_WINDOW];
    [$limit, $window] = $opts['rate'] ?? $defaultRate;
    $bucket = $uid > 0 ? ('u' . $uid) : ('ip' . apiClientIp());
    if (!apiRateAllow($bucket, (int)$limit, (int)$window)) {
        $rateFailure = (string)($GLOBALS['__api_rate_failure'] ?? '');
        if ($rateFailure !== '' && $rateFailurePolicy === 'diagnostic') {
            // Только явно названные admin GET-экраны могут диагностировать limiter,
            // не выдавая сбой хранилища за минутный 429. Мутации остаются fail-closed.
        } elseif ($rateFailure !== '') {
            apiFail('Хранилище защитного лимита недоступно. Проверьте место на диске и права каталога data.',
                503, 'rate_state_unavailable');
        } else {
            header('Retry-After: ' . (int)$window);
            apiFail('Слишком много запросов подряд, подождите минуту', 429, 'rate_limited');
        }
    }

    return ['session' => $session, 'role' => $role, 'uid' => $uid, 'method' => $method,
        'install_generation' => $installGeneration,
        'rate_failure' => (string)($GLOBALS['__api_rate_failure'] ?? '')];
}

/**
 * Тело запроса как массив. Принимает JSON (основной путь) и form-urlencoded.
 * Больше $maxBytes не читаем: приложение без файлов столько не присылает, а
 * незнакомый гигабайт — это уже не запрос, а способ занять память хостинга.
 */
function apiBody(int $maxBytes = API_MAX_BODY): array {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $maxBytes) apiFail('Слишком большой запрос', 413, 'body_too_large');

    $raw = (string)file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (strlen($raw) > $maxBytes) apiFail('Слишком большой запрос', 413, 'body_too_large');
    if (trim($raw) === '') return $_POST ?: [];

    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== false || strncmp(ltrim($raw), '{', 1) === 0) {
        $data = json_decode($raw, true);
        if (!is_array($data)) apiFail('Тело запроса не разобрать как JSON', 400, 'bad_json');
        return $data;
    }
    parse_str($raw, $form);
    return is_array($form) ? $form : [];
}

/* ── Разбор входа ─────────────────────────────────────────────────────────
   Каждое поле достаём явно и с проверкой. «Взял из тела и передал в REST» —
   это способ получить в Битриксе то, чего никто не просил. */

function apiStr(array $src, string $key, array $opts = []): string {
    $required = $opts['required'] ?? true;
    $max = (int)($opts['max'] ?? 1000);
    $v = $src[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) apiFail('Не передано поле «' . $key . '»', 400, 'missing_field');
        return (string)($opts['default'] ?? '');
    }
    if (!is_string($v) && !is_numeric($v)) apiFail('Поле «' . $key . '» должно быть строкой', 400, 'bad_field');
    $v = trim((string)$v);
    if (strlen($v) > $max) apiFail('Поле «' . $key . '» длиннее допустимого', 400, 'bad_field');
    return $v;
}

/**
 * Целое из входа — с проверкой границ ДО приведения типа.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ ФУНКЦИЯ, А НЕ ПРОСТО `(int)`. PHP не сообщает о переполнении:
 * `(int)'999999999999999999999999'` молча отдаёт PHP_INT_MAX, а с минусом —
 * PHP_INT_MIN. Проверка «состоит из цифр» это пропускает, и дальше по коду идёт
 * СОВСЕМ другое число: «убрать доступ у сотрудника 999…9» превращалось в работу
 * с сотрудником 9223372036854775807, запрос отвечал 200, а в списке доступа
 * появлялась запись, которой человек не заводил (находка ревью 09.08.2026,
 * R3-026). Сравнение делаем строками: любое приведение к числу или к float
 * возвращает нас ровно к той потере, от которой уходим.
 *
 * @param mixed $v Значение из тела/строки запроса.
 * @return int|null null — не целое ИЛИ не помещается в int этой платформы.
 */
function apiIntOrNull($v): ?int {
    if (is_int($v)) return $v;
    if (!is_string($v) || !preg_match('/^-?\d+$/', $v)) return null;
    $negative = $v[0] === '-';
    $digits = ltrim($negative ? substr($v, 1) : $v, '0');
    if ($digits === '') $digits = '0';
    // У отрицательного int модуль на единицу больше (PHP_INT_MIN), поэтому
    // предел берём свой для каждого знака — иначе ровно одно корректное
    // значение отвергалось бы как переполнение.
    $limit = $negative ? ltrim((string)PHP_INT_MIN, '-') : (string)PHP_INT_MAX;
    if (strlen($digits) > strlen($limit)
        || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        return null;
    }
    return (int)$v;
}

function apiInt(array $src, string $key, array $opts = []): int {
    $required = $opts['required'] ?? true;
    $v = $src[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) apiFail('Не передано поле «' . $key . '»', 400, 'missing_field');
        return (int)($opts['default'] ?? 0);
    }
    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) {
        apiFail('Поле «' . $key . '» должно быть числом', 400, 'bad_field');
    }
    $n = apiIntOrNull($v);
    if ($n === null) {
        // Отдельный текст, а не «должно быть числом»: цифры-то как раз на месте,
        // и человеку иначе непонятно, что именно не так с его номером.
        apiFail('Поле «' . $key . '» — число за пределами допустимого', 400, 'bad_field');
    }
    if (isset($opts['min']) && $n < (int)$opts['min']) apiFail('Поле «' . $key . '» меньше допустимого', 400, 'bad_field');
    if (isset($opts['max']) && $n > (int)$opts['max']) apiFail('Поле «' . $key . '» больше допустимого', 400, 'bad_field');
    return $n;
}

/** Значение строго из списка. Всё, что уходит в REST-вызов, должно проходить здесь. */
function apiEnum(array $src, string $key, array $allowed, array $opts = []): string {
    $v = apiStr($src, $key, ['required' => $opts['required'] ?? true, 'max' => 100, 'default' => $opts['default'] ?? '']);
    if ($v === '' && !($opts['required'] ?? true)) return (string)($opts['default'] ?? '');
    if (!in_array($v, $allowed, true)) apiFail('Недопустимое значение поля «' . $key . '»', 400, 'bad_field');
    return $v;
}

/**
 * Список значений с потолком длины и проверкой КАЖДОГО элемента.
 *
 * Проверять элементы обязательно: «список» из вложенных массивов, попав в
 * REST-вызов, превращается в набор параметров, которых никто не задавал.
 *
 * @param array $opts 'items' => 'int'|'string' (по умолчанию 'string'),
 *                    'allowed' => [...] допустимые значения, 'max' => потолок длины.
 */
function apiList(array $src, string $key, array $opts = []): array {
    $max  = (int)($opts['max'] ?? 500);
    $type = (string)($opts['items'] ?? 'string');
    $v = $src[$key] ?? null;
    if ($v === null) {
        if ($opts['required'] ?? true) apiFail('Не передано поле «' . $key . '»', 400, 'missing_field');
        return [];
    }
    if (!is_array($v)) apiFail('Поле «' . $key . '» должно быть списком', 400, 'bad_field');
    if (count($v) > $max) apiFail('В поле «' . $key . '» слишком много значений', 400, 'bad_field');

    $out = [];
    foreach (array_values($v) as $i => $item) {
        if (!is_scalar($item)) {
            apiFail('Элемент №' . ($i + 1) . ' поля «' . $key . '» должен быть простым значением', 400, 'bad_field');
        }
        if ($type === 'int') {
            if (!is_int($item) && !(is_string($item) && preg_match('/^-?\d+$/', $item))) {
                apiFail('Элемент №' . ($i + 1) . ' поля «' . $key . '» должен быть числом', 400, 'bad_field');
            }
            // Тот же разбор, что и у одиночного числа: `(int)` на длинной строке
            // молча отдаёт предел платформы, и в список уходит не то значение,
            // которое прислали.
            $parsed = apiIntOrNull($item);
            if ($parsed === null) {
                apiFail('Элемент №' . ($i + 1) . ' поля «' . $key . '» — число за пределами допустимого', 400, 'bad_field');
            }
            $item = $parsed;
        } else {
            $item = trim((string)$item);
            if (strlen($item) > (int)($opts['maxLen'] ?? 1000)) {
                apiFail('Элемент №' . ($i + 1) . ' поля «' . $key . '» длиннее допустимого', 400, 'bad_field');
            }
        }
        if (isset($opts['allowed']) && !in_array($item, $opts['allowed'], true)) {
            apiFail('Недопустимое значение в поле «' . $key . '»', 400, 'bad_field');
        }
        $out[] = $item;
    }
    return $out;
}
