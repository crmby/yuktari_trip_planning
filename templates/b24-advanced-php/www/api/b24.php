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

require_once __DIR__ . '/store.php';
// maskSecrets (текст ошибки Б24 несёт URL с токеном) и sendNoStoreHeaders
// (страницы, которые отдаёт этот файл). lib.php сам тянет только env + store —
// кольца зависимостей не получается.
require_once __DIR__ . '/lib.php';
// appLog зовётся и на верхнем уровне этого файла (страховка сплэша установки), и
// из lifecycle-функций. Свой журнал модуль обязан принести сам: подключение «по
// счастливой цепочке вызывающего» ломается ровно в тот момент, когда файл берут
// в новый контекст. journal.php тянет env + lib — кольца тоже не выходит.
require_once __DIR__ . '/journal.php';

// REST-ответ Б24 читается кусками: timeout ограничивает время, но не память.
// 5 MiB совпадает с потолком generic httpJson(); штатная пагинация должна
// дробить более крупные выборки, а не копить один гигантский ответ в worker.
if (!defined('B24_RESPONSE_MAX_BYTES')) define('B24_RESPONSE_MAX_BYTES', 5 * 1048576);

/**
 * Приёмник CURLOPT_WRITEFUNCTION. Никогда не держит больше limit байт:
 * переполняющий chunk не добавляется, callback возвращает 0 и cURL обрывает
 * download с CURLE_WRITE_ERROR.
 */
final class B24ResponseBuffer {
    private int $limit;
    private string $body = '';
    private bool $tooLarge = false;

    public function __construct(int $limit) {
        $this->limit = max(1, $limit);
    }

    /** @param mixed $handle cURL resource на PHP 7.4 либо CurlHandle на PHP 8. */
    public function write($handle, string $chunk): int {
        $chunkBytes = strlen($chunk);
        if ($this->tooLarge || $chunkBytes > $this->limit - strlen($this->body)) {
            $this->tooLarge = true;
            return 0;
        }
        $this->body .= $chunk;
        return $chunkBytes;
    }

    public function tooLarge(): bool { return $this->tooLarge; }
    public function bytes(): int { return strlen($this->body); }
    public function body(): string { return $this->body; }
}

/** Отдельный тип: caller может fail-closed без разбора текста исключения. */
final class B24ResponseTooLargeException extends RuntimeException {}

/**
 * Общий streaming contract для каждого cURL handle этого модуля.
 * @return array<int,mixed>
 */
function b24BoundedCurlOptions(B24ResponseBuffer $buffer): array {
    return [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BUFFERSIZE => 16384,
        CURLOPT_WRITEFUNCTION => [$buffer, 'write'],
    ];
}

/** Machine-readable отказ без сырого многомегабайтного ответа. */
function b24ResponseTooLargeError(): array {
    return [
        'error' => 'response_too_large',
        'error_description' => 'Ответ Битрикс24 больше допустимого предела '
            . B24_RESPONSE_MAX_BYTES . ' байт',
    ];
}

/**
 * Класс B24: OAuth-обёртка REST API Битрикс24. Одна установка обслуживает
 * ОДИН портал: токены, поколение и домен принадлежат ей одной.
 * Хранит токены в B24_TOKENS_FILE, сам refresh'ит при expired_token,
 * сам сохраняет изменения (persist) — слиянием под замком, чтобы параллельный
 * запрос не затёр обновлённый токен.
 *
 * KB: b24-marketplace-install-post-no-domain (фильтр REFERER + резолв domain).
 */
final class B24 {
    private string $clientId;
    private string $clientSecret;
    private array  $tokens = [];
    /** Снимок состояния на момент чтения — база для слияния при записи (см. persist). */
    private array  $loaded = [];

    /**
     * База постоянного ключа-вебхука: `https://<портал>/rest/<id>/<ключ>/`.
     *
     * Второй канал доступа к порталу. Нужен там, где вход под приложением
     * недоступен: коробка может вовсе не иметь своего сервера авторизации и при
     * этом не быть известной облачному — тогда OAuth-обмен не проходит ни по
     * одному адресу, и приложение без этого канала не работает совсем
     * (живой случай: портал прислал в установке только чужой SERVER_ENDPOINT).
     * Канал выбирает СЕРВЕР по настройке, а вызывающий код различия не видит:
     * call/batch/fetchAll и разбор отказов у обоих каналов общие.
     *
     * @var string '' — канала нет, работаем токенами установки.
     */
    private $webhookBase = '';

    public function __construct(string $clientId, string $clientSecret) {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->webhookBase  = b24WebhookBase();
        $j = storeRead(B24_TOKENS_FILE);
        // Старые установки поколения не имели. Миграция атомарна: два первых
        // запроса получают один generation, а пустое состояние не создаётся.
        if (is_array($j) && !empty($j['access_token'])
            && appInstallGenerationFrom($j) === '' && empty($j['uninstalled_at'])) {
            appEnsureInstallGeneration();
            $j = storeRead(B24_TOKENS_FILE);
        }
        if (is_array($j)) {
            $this->tokens = $j;
            $this->loaded = $j;
            // lib.php фиксирует поколение в самом начале запроса. Не заменяем
            // этот снимок более свежим состоянием с диска: запрос A мог начать
            // работу до быстрой переустановки B, а создать B24 уже после неё.
            // Если пометить его поздние логи поколением B, новый администратор
            // увидит строки прежней установки. Пустой снимок допустимо заполнить
            // только после атомарной миграции legacy-установки выше.
        }
        // Проверяем channel/tenant до первого REST-вызова и до выдачи identity.
        // Два канала одного портала допустимы; смешивать identity одного
        // портала с transport другого нельзя даже при operator misconfiguration.
        self::portalForChannels($this->tokens, $this->webhookBase);
        if ((string)($GLOBALS['__app_log_install_generation'] ?? '') === '') {
            appBindLogGeneration($this->installGeneration());
        }
    }

    /** @var array<string,mixed> последняя телеметрия портала из объекта `time` */
    private $portalLoad = [];

    public function memberId(): ?string { return $this->tokens['member_id'] ?? null; }
    /**
     * Домен портала. При работе постоянным ключом он известен из самого ключа:
     * ключ выписан порталом и содержит его имя, поэтому подделать домен через
     * тело запроса нельзя — в отличие от DOMAIN/Referer, которым верить нельзя.
     */
    public function domain(): ?string {
        return self::portalForChannels($this->tokens, $this->webhookBase);
    }
    /** client_id (регистрация local-app), под которым выпущен сохранённый токен. '' до 1-й установки. */
    public function clientId(): ?string  { return $this->tokens['client_id'] ?? null; }
    /**
     * Есть ли чем обращаться к порталу. Постоянный ключ — такое же «есть», как
     * и токен установки: разделять их здесь значило бы, что каждый вызывающий
     * помнит про два канала, а он про них знать не обязан.
     */
    public function hasTokens(): bool   {
        return !empty($this->tokens['access_token']) || $this->webhookBase !== '';
    }

    /** Работаем постоянным ключом, а не токенами установки. */
    public function usesWebhook(): bool { return $this->webhookBase !== ''; }
    public function tokens(): array     { return $this->tokens; }
    public function installGeneration(): ?string {
        $generation = appInstallGenerationFrom($this->tokens);
        if ($generation === '' && empty($this->tokens['access_token'])
            && empty($this->tokens['uninstalled_at']) && empty($this->tokens['cleanup_pending'])) {
            $generation = appKeylessInstallGeneration();
        }
        return $generation !== '' ? $generation : null;
    }

    /**
     * Канонический portal identity для OAuth/webhook channel matrix.
     *
     * @throws RuntimeException если активный OAuth и webhook принадлежат
     *   разным площадкам либо OAuth-снимок не содержит проверяемого domain.
     */
    public static function portalForChannels(array $tokens, string $webhookBase): ?string {
        $saved = strtolower(trim((string)($tokens['domain'] ?? '')));
        $webhookHost = '';
        if ($webhookBase !== '') {
            $parsed = parse_url($webhookBase, PHP_URL_HOST);
            if (is_string($parsed)) $webhookHost = strtolower(trim($parsed));
        }
        if (!empty($tokens['access_token']) && $webhookBase !== '') {
            if ($saved === '' || $webhookHost === '' || !hash_equals($saved, $webhookHost)) {
                throw new RuntimeException('B24: OAuth и webhook относятся к разным порталам');
            }
        }
        if ($saved !== '') return $saved;
        return $webhookHost !== '' ? $webhookHost : null;
    }

    /** application_token, сохранённый при установке. null если ещё не сохранён. */
    public function applicationToken(): ?string {
        $t = (string)($this->tokens['application_token'] ?? '');
        return $t !== '' ? $t : null;
    }
    /** Сохранённый scope (APPLICATION_SCOPE на момент последнего сейва). '' если не знаем. */
    public function savedScope(): string {
        return (string)($this->tokens['scope'] ?? '');
    }

    public function isAccessExpired(): bool {
        $exp = (int)($this->tokens['expires_at'] ?? 0);
        return $exp > 0 && time() >= $exp;
    }

    /**
     * Сохранить токены из уже подтверждённого install POST Б24.
     *
     * У существующей установки tenant/endpoint берём только из защищённого
     * состояния: DOMAIN/PROTOCOL в браузерном POST не являются источником
     * доверия. Сырой POST нужен лишь для явно включённого legacy-fallback первой
     * установки; PROTOCOL там нормализуется по официальному контракту 0/1.
     */
    /**
     * @param string $domainHint Адрес портала, названный ОПЕРАТОРОМ в настройке
     *   (разовый билет установки). Нужен там, где портал своего адреса не
     *   присылает вовсе: у коробки без собственного сервера авторизации в теле
     *   установки лежит только SERVER_ENDPOINT облачного сервера, и записать его
     *   как портал нельзя — REST ушёл бы на сервер авторизации. Из тела запроса
     *   этот адрес не берётся никогда: он приходит из конфига на диске.
     */
    public function saveTokensFromInstall(array $post, ?string $referer = null,
        string $domainHint = ''): void {
        $get = fn($k) => $post[$k] ?? $post[strtolower($k)] ?? null;

        $access  = (string)($get('AUTH_ID')    ?? '');
        $refresh = (string)($get('REFRESH_ID') ?? '');
        if ($access === '' || $refresh === '') {
            throw new RuntimeException('B24 POST без AUTH_ID/REFRESH_ID');
        }

        $existingDomain = (string)($this->tokens['domain'] ?? '');
        $domain = $existingDomain;
        if ($domain === '') $domain = (string)($get('DOMAIN') ?? '');
        // Коробочный портал DOMAIN не присылает вовсе — он присылает
        // SERVER_ENDPOINT (официальный состав POST: AUTH_ID, AUTH_EXPIRES,
        // REFRESH_ID, SERVER_ENDPOINT, APPLICATION_TOKEN, APPLICATION_SCOPE,
        // member_id, status, PLACEMENT). Берём домен оттуда ДО Referer, и вот
        // почему это не мелочь: (1) при обрезанном Referrer-Policy установка
        // падала «domain не определить», хотя адрес портала лежал в том же
        // POST; (2) у коробки часто несколько имён, и сохранённое из Referer
        // внутреннее имя не совпадало с хостом из SERVER_ENDPOINT, который
        // сверяет забор сессии, — приложение после успешной установки навсегда
        // отвечало «открывается только из портала». Тот же источник, что и в
        // заборе (api/session.php → portalPostDomain), иначе они снова разъедутся.
        if ($domain === '') {
            $fromPost = portalPostDomain($post);
            // Хост сервера авторизации порталом не является: приняв его, мы бы
            // отправляли туда все REST-вызовы и получали «метод не найден» на
            // ровном месте. Такой POST считаем «адреса нет» и идём к подсказке
            // оператора ниже.
            if ($fromPost !== '' && b24IsAuthServerHost($fromPost)) $fromPost = '';
            if ($fromPost !== '' && isExternalB24Host($fromPost)) $domain = $fromPost;
        }
        if ($domain === '' && $domainHint !== '' && isExternalB24Host($domainHint)) {
            $domain = strtolower($domainHint);
        }
        if ($domain === '' && is_string($referer) && $referer !== '') {
            $h = parse_url($referer, PHP_URL_HOST);
            if (is_string($h) && isExternalB24Host($h)) $domain = $h;
        }
        if ($domain === '') {
            throw new RuntimeException('B24 POST: domain не определить (нет в POST/tokens/referer)');
        }

        $memberId = (string)($this->tokens['member_id'] ?? '');
        if ($memberId === '') $memberId = (string)($get('member_id') ?? '');
        $expires  = (int)($get('AUTH_EXPIRES') ?? 3600);
        $savedEndpoint = normalizeRestEndpoint((string)($this->tokens['client_endpoint'] ?? ''));
        if ($savedEndpoint !== '') {
            $protocol = 'https';
            $clientEndpoint = $savedEndpoint;
        } else {
            $protocol = installProtocolScheme($get('PROTOCOL'));
            if ($protocol !== 'https') {
                throw new RuntimeException('B24 POST: небезопасный HTTP-протокол портала не поддерживается');
            }
            $clientEndpoint = 'https://' . $domain . '/rest/';
            // У коробки REST может жить не в корне (`/rest/` — лишь обычай), и
            // собранный вручную адрес тогда молча ведёт в 404 на КАЖДОМ вызове.
            $postEndpoint = portalPostRestEndpoint($post, $domain);
            if ($postEndpoint !== '') $clientEndpoint = $postEndpoint;
        }

        // application_token — постоянный секрет, выданный порталом приложению при установке.
        // Сохраняем при первой установке и сверяем на последующих install/session-POST (защита
        // от подделки). Дока: events/safe-event-handlers. KB: rule-b24-verify-application-token.
        $appToken = (string)($get('APPLICATION_TOKEN') ?? '');
        if ($appToken === '') $appToken = (string)($this->tokens['application_token'] ?? '');
        // Текущий scope (Б24 шлёт как APPLICATION_SCOPE в install/placement-POST). Храним, чтобы
        // ловить СМЕНУ scope на placement-POST и подхватывать свежий токен без reinstall. #reinstall-scope
        $scope = (string)($get('APPLICATION_SCOPE') ?? ($this->tokens['scope'] ?? ''));

        $replacesInstallation = empty($this->tokens['access_token'])
            || !empty($this->tokens['uninstalled_at']) || !empty($this->tokens['cleanup_pending']);
        $generation = $replacesInstallation
            ? appNewInstallGeneration()
            : appInstallGenerationFrom($this->tokens);
        if ($generation === '') $generation = appNewInstallGeneration();

        $next = [
            'access_token'         => $access,
            'refresh_token'        => $refresh,
            'expires_at'           => time() + $expires - 60,
            'expires_in'           => $expires,
            'member_id'            => $memberId,
            'domain'               => $domain,
            'protocol'             => $protocol,
            'client_endpoint'      => $clientEndpoint,
            'client_id'            => $this->clientId,
            'application_token'    => $appToken,
            'scope'                => $scope,
            'install_generation'   => $generation,
        ];
        // Маркеры относятся к поколению установки. При обычном обновлении
        // installer-токена/scope сохраняем их, при новой установке — нет.
        if (!$replacesInstallation) {
            foreach (self::installGenerationKeysCarried() as $field) {
                if (array_key_exists($field, $this->tokens)) $next[$field] = $this->tokens[$field];
            }
        }
        $this->tokens = $next;
        if ($replacesInstallation) $this->replaceInstallationTokens($next);
        else $this->persist();
    }

    /**
     * Служебные отметки ХОДА установки — ОДИН список на все три места, где он нужен.
     *
     * Раньше он был выписан трижды подряд (перенос при обновлении токенов, сброс
     * внутри поколения и гигиена при смене поколения) и уже разошёлся на один
     * ключ. Сегодняшнего дефекта из этого не следовало, а завтрашний следует
     * прямо: новый служебный ключ, добавленный в один список из трёх, переживает
     * новую установку и достаётся ей в наследство — то есть рабочий продолжает
     * чужую работу на данных другого портала (находка ревью 09.08.2026).
     * Заводите новый маркер поколения — дописывайте ЗДЕСЬ.
     */
    private const INSTALL_GENERATION_KEYS = [
        'install_finish_requested_generation',
        'install_finish_requested_at',
        'app_info_checked_at',
        'install_finish_confirmed_generation',
        // Legacy-поля нужны только до ленивой миграции claimInstallFinish…().
        'installFinishedAt',
        'installFinishedUsers',
        'installedForcedAt',
    ];

    /**
     * Те же ключи ПЛЮС израсходованный ручной повтор — набор для ПЕРЕНОСА.
     *
     * Разница намеренная и в одну сторону: при обновлении installer-токена того
     * же поколения ручной повтор обязан остаться израсходованным (иначе
     * перезагрузка страницы снова получит право на автоматический повтор
     * установки), а сбрасывать его внутри поколения не нужно — вызывающий тут же
     * выставляет его заново.
     */
    private static function installGenerationKeysCarried(): array {
        return array_merge(self::INSTALL_GENERATION_KEYS, [
            'install_finish_manual_retry_generation',
            // История регистраций принадлежит порталу, а не одному токену.
            // Обычный scope/token refresh того же поколения не вправе её стереть.
            'prior_generations',
        ]);
    }

    /** Забыть отметки хода установки, НЕ записывая состояние (запись — на вызывающем). */
    private function forgetInstallFinishMarkers(): void {
        foreach (self::INSTALL_GENERATION_KEYS as $field) unset($this->tokens[$field]);
    }

    /** Сброс служебных отметок installFinish/app.info для новой установки. */
    public function resetInstallFinish(): void {
        $this->forgetInstallFinishMarkers();
        $this->persist();
    }

    /**
     * Ручной повтор установки уже разрешался в этом поколении.
     *
     * Разрешение НЕЛЬЗЯ держать только в теле запроса: страница завершения
     * установки перезагружает себя, а перезагрузка переотправляет тот же POST
     * вместе с флагом. Один клик администратора превращался в три десятка
     * автоматических повторов: экран мигал сплэшем, в портал уходило под сотню
     * запросов, а журнал наполнялся ложными записями «администратор вручную
     * разрешил повтор». Ровно тот автоповтор установочного эффекта, который
     * запрещён. Поэтому разрешение одноразовое и живёт на сервере, при своём
     * поколении: следующая попытка — снова осознанный клик человека.
     */
    public function manualInstallRetryUsedForCurrentGeneration(): bool {
        $generation = appInstallGenerationFrom($this->tokens);
        if ($generation === '') return false;
        $used = (string)($this->tokens['install_finish_manual_retry_generation'] ?? '');
        return $used !== '' && hash_equals($generation, $used);
    }

    /** Пометить, что ручной повтор для текущего поколения израсходован. */
    public function markManualInstallRetryUsed(): void {
        $generation = appInstallGenerationFrom($this->tokens);
        if ($generation === '') return;
        $this->tokens['install_finish_manual_retry_generation'] = $generation;
        $this->persist();
    }
    /** Разово дописать application_token, если его ещё нет — миграция установок, сделанных до
     *  внедрения проверки подлинности. Включает строгую сверку без ручной переустановки. */
    /**
     * @param bool $confirmedByAdmin Подтверждено ли, что POST пришёл от админа
     *        нашего же портала (результат `installPostAuthenticity()['tofu']`).
     *        Без подтверждения дозапись ЗАПРЕЩЕНА: иначе посторонний присылает
     *        свой токен, тот сохраняется — и следующий его же запрос проходит
     *        строгую сверку как «свой». Дальше он переписывает серверные токены и
     *        уводит адрес портала на свой хост, а каждый открывающий сотрудник
     *        отправляет туда свой рабочий токен.
     */
    public function backfillApplicationToken(string $token, bool $confirmedByAdmin): void {
        if (!$confirmedByAdmin) {
            throw new LogicException('backfillApplicationToken без подтверждения подлинности — так подсаживают чужой токен');
        }
        if ($token === '' || !$this->hasTokens() || !empty($this->tokens['application_token'])) return;
        $this->tokens['application_token'] = $token;
        $this->persist();
    }

    /**
     * Одноразово связать application_token из ONAPPINSTALL с уже сохранённой
     * browser OAuth-парой. Событие не имеет generation/sequence, поэтому оно
     * вправе заполнить только пустой ключ; менять непустой ключ, OAuth,
     * installFinish или поколение здесь запрещено.
     */
    public function bindInstallApplicationToken(array $auth): bool {
        $eventToken = (string)($auth['application_token'] ?? '');
        $eventAccess = (string)($auth['access_token'] ?? '');
        $eventRefresh = (string)($auth['refresh_token'] ?? '');
        $eventMember = (string)($auth['member_id'] ?? '');
        if ($eventToken === '' || $eventAccess === '' || $eventRefresh === '' || $eventMember === '') return false;

        $accepted = false;
        $this->updateInstallMeta(static function (array $state) use (
            $eventToken, $eventAccess, $eventRefresh, $eventMember, &$accepted
        ): array {
            $member = (string)($state['member_id'] ?? '');
            $access = (string)($state['access_token'] ?? '');
            $refresh = (string)($state['refresh_token'] ?? '');
            if ($member === '' || $access === '' || $refresh === ''
                || !hash_equals($member, $eventMember)
                || !hash_equals($access, $eventAccess)
                || !hash_equals($refresh, $eventRefresh)) return $state;

            $saved = (string)($state['application_token'] ?? '');
            if ($saved !== '') {
                $accepted = hash_equals($saved, $eventToken); // дубль — no-op; ротация запрещена
                return $state;
            }
            $state['application_token'] = $eventToken;
            $state['appTokenBoundAt'] = time();
            $accepted = true;
            return $state;
        });
        return $accepted;
    }

    /**
     * Усвоить auth[] подтверждённого lifecycle-события.
     *
     * Событие установки официально несёт не только application_token, но и новую
     * пару access/refresh. Сохранять один ключ приложения недостаточно: после
     * переустановки фоновые REST-вызовы продолжали жить на прежней OAuth-паре.
     * В UI-шаблоне через этот путь усваивается только ONAPPUPDATE после
     * portal-proof; ONAPPINSTALL использует отдельный one-time bind пустого
     * application_token и не меняет OAuth/поколение/confirmed.
     */
    public function adoptLifecycleAuthorization(
        array $auth,
        bool $confirmedByPortal,
        bool $installationCompleted = false,
        bool $newInstall = false
    ): void {
        if (!$confirmedByPortal) {
            throw new LogicException('adoptLifecycleAuthorization без подтверждения порталом');
        }
        $token = (string)($auth['application_token'] ?? '');
        if ($token === '' || !$this->hasTokens()) return;

        $currentMember = (string)($this->tokens['member_id'] ?? '');
        $eventMember = (string)($auth['member_id'] ?? '');
        if ($eventMember !== '' && ($currentMember === '' || !hash_equals($currentMember, $eventMember))) {
            throw new RuntimeException('lifecycle auth относится к другому порталу');
        }

        $currentDomain = strtolower((string)($this->tokens['domain'] ?? ''));
        $eventDomain = strtolower((string)($auth['domain'] ?? ''));
        if ($eventDomain !== '' && ($currentDomain === '' || !hash_equals($currentDomain, $eventDomain))) {
            throw new RuntimeException('lifecycle auth содержит другой домен');
        }
        $eventEndpointRaw = (string)($auth['client_endpoint'] ?? '');
        $eventEndpoint = $eventEndpointRaw !== '' ? normalizeRestEndpoint($eventEndpointRaw) : '';
        if ($eventEndpointRaw !== '' && $eventEndpoint === '') {
            throw new RuntimeException('lifecycle auth содержит небезопасный REST endpoint');
        }
        if ($eventEndpoint !== '') {
            $eventHost = strtolower((string)parse_url($eventEndpoint, PHP_URL_HOST));
            if ($currentDomain === '' || !hash_equals($currentDomain, $eventHost)) {
                throw new RuntimeException('lifecycle REST endpoint относится к другому порталу');
            }
        }

        $savedToken = (string)($this->tokens['application_token'] ?? '');
        $tokenChanged = $savedToken === '' || !hash_equals($savedToken, $token);
        $this->tokens['application_token'] = $token;
        if ($tokenChanged) $this->tokens['appTokenRotatedAt'] = time();

        // Не разрываем OAuth-пару: часть событий не содержит refresh_token.
        // В таком случае access из события служит только proof и не заменяет
        // installer-авторизацию, которая продолжает штатно refresh'иться сама.
        $access = (string)($auth['access_token'] ?? '');
        $refresh = (string)($auth['refresh_token'] ?? '');
        if ($access !== '' && $refresh !== '') {
            $expires = max(60, (int)($auth['expires_in'] ?? 3600));
            $this->tokens['access_token'] = $access;
            $this->tokens['refresh_token'] = $refresh;
            $this->tokens['expires_in'] = $expires;
            $this->tokens['expires_at'] = time() + $expires - 60;
            if ($eventEndpoint !== '') $this->tokens['client_endpoint'] = $eventEndpoint;
            if ($eventDomain !== '') $this->tokens['domain'] = $eventDomain;
            $scope = (string)($auth['scope'] ?? '');
            if ($scope !== '') $this->tokens['scope'] = $scope;
        }

        // ONAPPINSTALL приходит только после успешного BX24.installFinish().
        // Фиксируем этот факт в той же CAS-записи, что и свежую OAuth-пару:
        // отдельная запись оставляла окно, в котором app.info=false мог принять
        // уже выполненную установку за новую и повторно показать finish-страницу.
        if ($installationCompleted && $this->installFinishRequestedForCurrentGeneration()) {
            $generation = appInstallGenerationFrom($this->tokens);
            if ($generation === '') {
                throw new InstallGenerationMismatchException('ONAPPINSTALL без поколения установки');
            }
            $this->tokens['install_finish_confirmed_generation'] = $generation;
        }

        // Дубликат ONAPPINSTALL с тем же application_token не создаёт ещё одно
        // поколение. Новый ключ означает реальную новую установку.
        if ($newInstall && $savedToken !== '' && $tokenChanged) {
            // ONAPPINSTALL с новым ключом — новая установка даже на том же
            // портале. У неё своё поколение; старый uninstall больше её не сотрёт.
            $this->tokens['install_generation'] = appNewInstallGeneration();
            $this->forgetInstallFinishMarkers();
            $this->replaceInstallationTokens($this->tokens);
        } else {
            $this->persist();   // ONAPPUPDATE остаётся в том же поколении
        }
    }

    /** Совместимая узкая обёртка для модулей, которым нужна только ротация ключа. */
    public function adoptApplicationToken(string $token, bool $confirmedByPortal, bool $newInstall = false): void {
        $this->adoptLifecycleAuthorization([
            'application_token' => $token,
            'member_id' => (string)($this->tokens['member_id'] ?? ''),
        ], $confirmedByPortal, false, $newInstall);
    }

    /**
     * Недавний отказ ТОЙ ЖЕ паре ключей lifecycle-события.
     *
     * Глобальный timestamp здесь недопустим: Битрикс не повторяет события, и
     * один чужой POST закрывал окно настоящему ONAPPINSTALL/ONAPPUPDATE. Хеш
     * не раскрывает ключи, но отсекает повтор ровно уже проверенной подделки.
     */
    public function eventVerificationRejectedRecently(string $fingerprint, int $sec): bool {
        $saved = (string)($this->tokens['eventRejectedFingerprint'] ?? '');
        $ts = (int)($this->tokens['eventRejectedAt'] ?? 0);
        return $fingerprint !== '' && $saved !== '' && hash_equals($saved, $fingerprint)
            && $ts > 0 && (time() - $ts) < $sec;
    }
    public function markEventVerificationRejected(string $fingerprint): void {
        $this->tokens['eventRejectedFingerprint'] = $fingerprint;
        $this->tokens['eventRejectedAt'] = time();
        unset($this->tokens['eventVerifiedAt']); // миграция старого глобального троттла
        $this->persist();
    }
    public function clearEventVerificationRejection(): void {
        unset($this->tokens['eventRejectedFingerprint'], $this->tokens['eventRejectedAt'],
            $this->tokens['eventVerifiedAt']);
    }

    /** @deprecated Оставлено для совместимости модулей; lifecycle-гейт его больше не использует. */
    public function eventVerifiedRecently(int $sec): bool {
        $ts = (int)($this->tokens['eventVerifiedAt'] ?? 0);
        return $ts > 0 && (time() - $ts) < $sec;
    }
    /** @deprecated Глобальная метка не участвует в приёме lifecycle-событий. */
    public function markEventVerified(): void {
        $this->tokens['eventVerifiedAt'] = time();
        $this->persist();
    }

    /** Недавно ли мы уже ходили в портал ради разовой проверки «доверия в первый раз». */
    public function tofuCheckedRecently(int $sec): bool {
        $ts = (int)($this->tokens['tofuCheckedAt'] ?? 0);
        return $ts > 0 && (time() - $ts) < $sec;
    }
    public function markTofuChecked(): void {
        $this->tokens['tofuCheckedAt'] = time();
        $this->persist();
    }

    /** Троттл межпортального verify: не чаще раза в столько секунд (DoS-гард ниже). */
    const MIGRATION_VERIFY_THROTTLE = 30;

    /**
     * Переезд на ДРУГОЙ портал / пересоздание local-app (#app-migration). При смене привязки строгая
     * сверка application_token невозможна (он выдан прежней привязкой) → сейв блокировался, токен
     * «закисал», member_id не менялся, автоочистка портал-специфичного data/ не запускалась. Здесь
     * подтверждаем владение КРИПТОСТОЙКО — пробным refresh присланного REFRESH_ID нашим client_secret
     * (проходит ТОЛЬКО для токенов НАШЕГО приложения; чужой портал/приложение — секрет не тот).
     * domain/member_id/client_endpoint берём СТРОГО из ответа oauth (не из подделываемого POST DOMAIN).
     * Усваиваем только от АДМИНА (иначе installer-токен деградировал бы до прав не-админа — как
     * #reinstall-scope). Возвращает true при усвоении. KB: b24-local-app-portal-migration-self-heal.
     */
    public function verifyAndAdopt(array $post, bool $firstInstall = false): bool {
        $get = fn($k) => $post[$k] ?? $post[strtolower($k)] ?? null;
        $refresh = (string)($get('REFRESH_ID') ?? '');
        if ($refresh === '') return false;

        // Сетевой verify выполняется без lifecycle-lock: держать EX/SH двадцать
        // секунд нельзя. Все короткие чтения и поздние записи throttle ниже
        // сверяются с этим снимком, чтобы запрос A не менял state уже установки B.
        $verifyIdentity = $this->loaded;

        // DoS-гард: условие входа (identityChanged) держится на подделываемом POST DOMAIN, поэтому
        // неаутентифицированный флуд мог бы гонять дорогой исходящий refresh на каждый запрос →
        // истощение воркеров и риск бана нашего IP у oauth. Пускаем не чаще раза в окно (по стору).
        // На ПЕРВОЙ установке окно короче: человек, у которого установка не
        // прошла с первого раза, жмёт «Установить» снова через несколько секунд,
        // и упереться в тридцатисекундный запрет он не должен.
        $window = $firstInstall ? 5 : self::MIGRATION_VERIFY_THROTTLE;
        // Метку последней попытки держим в общем состоянии, а НЕ в файле токенов:
        // иначе первый же анонимный POST создаёт «состояние приложения» из ничего,
        // и потом не отличить неустановленное приложение от установленного.
        // Решение о допуске и резервирование попытки — под одним EX-lock.
        // Иначе параллельные запросы читают прежний счётчик и все уходят в OAuth.
        // Успех снимает счётчик ниже; после паузы новая попытка снова разрешена.
        if (!self::updateMigrationVerifyThrottle($verifyIdentity, true, $window)) return false;
        try {
            $data = $this->doRefreshRequest($refresh);
        } catch (\Throwable $e) {
            return false; // не наше приложение / истёкший refresh / сетевой сбой → не усваиваем
        }
        if (empty($data['access_token']) || empty($data['refresh_token'])) return false;

        // Домен/member_id — ТОЛЬКО из авторитетного ответа oauth. POST DOMAIN не используем: он
        // подделываем, а увод client_endpoint на чужой хост = утечка installer-токена. Нет
        // client_endpoint/member_id в ответе → не усваиваем (defense-in-depth).
        $endpoint = normalizeRestEndpoint((string)($data['client_endpoint'] ?? ''));
        $memberId = (string)($data['member_id'] ?? '');
        $host = $endpoint !== '' ? parse_url($endpoint, PHP_URL_HOST) : null;
        if (!is_string($host) || $host === '' || $memberId === '') return false;

        // Admin-гейт: installer-токен несёт REST-права ОТКРЫВШЕГО. Токен не-админа → сервер не видел
        // бы данные портала. Проверяем свежим access_token на авторитетном хосте; не админ → не усваиваем.
        $ctx = resolveUserContextFromAuth((string)$data['access_token'], $host);
        if (!is_array($ctx) || empty($ctx['isAdmin'])) return false;

        $expires = (int)($data['expires_in'] ?? 3600);
        $previousMemberId = (string)($this->loaded['member_id'] ?? '');
        $previousClientId = (string)($this->loaded['client_id'] ?? '');
        $sameBinding = $previousMemberId !== '' && hash_equals($previousMemberId, $memberId)
            && ($previousClientId === '' || hash_equals($previousClientId, $this->clientId));
        $incomingAppToken = (string)($get('APPLICATION_TOKEN') ?? '');
        // Официальный browser install POST APPLICATION_TOKEN не обещает. На
        // переустановке той же привязки сохраняем уже подтверждённый ключ;
        // при смене tenant/client_id чужой старый ключ не переносим.
        $applicationToken = $incomingAppToken !== ''
            ? $incomingAppToken
            : ($sameBinding ? (string)($this->loaded['application_token'] ?? '') : '');
        $this->tokens = [
            'access_token'         => (string)$data['access_token'],
            'refresh_token'        => (string)$data['refresh_token'],
            'expires_at'           => time() + $expires - 60,
            'expires_in'           => $expires,
            'member_id'            => $memberId,
            'domain'               => $host,
            'protocol'             => 'https',
            'client_endpoint'      => $endpoint,
            'client_id'            => $this->clientId,
            // Browser POST официально может не содержать application_token.
            // Async ONAPPINSTALL не имеет generation и не используется для
            // backfill: до verified browser POST события остаются fail-closed.
            'application_token'    => $applicationToken,
            'scope'                => (string)($data['scope'] ?? $get('APPLICATION_SCOPE') ?? ''),
            'install_generation'   => appNewInstallGeneration(),
        ];
        // Это подтверждённая новая привязка/переустановка, а не обычный merge.
        // Отдельный CAS не даст запросу, начатому до uninstall, воскресить её.
        $portalChanged = $previousMemberId !== '' && !hash_equals($previousMemberId, $memberId);
        $this->replaceInstallationTokens($this->tokens, $portalChanged);
        // Успех снимает счётчик неудач: следующая законная переустановка не
        // должна упираться в запрет, оставшийся от чужих попыток. Сверка нового
        // снимка не даст позднему успеху B стереть throttle уже установки C.
        self::updateMigrationVerifyThrottle($this->loaded, false);
        return true;
    }

    /** Атомарно изменить служебные поля только текущего поколения установки. */
    private function updateInstallMeta(callable $mutator): void {
        $expected = (string)($this->installGeneration() ?? '');
        if ($expected === '') throw new InstallGenerationMismatchException('install-meta без поколения');
        $updated = appWithLifecycleReadLock(static function () use ($expected, $mutator): array {
            return storeUpdate(B24_TOKENS_FILE, static function (array $onDisk) use ($expected, $mutator): array {
                if (!hash_equals(appInstallGenerationFrom($onDisk), $expected)
                    || !empty($onDisk['cleanup_pending']) || !empty($onDisk['uninstalled_at'])) {
                    throw new InstallGenerationMismatchException('install-meta относится к прежней установке');
                }
                return $mutator($onDisk);
            }, 25.0);
        }, 25.0);
        $this->tokens = $updated;
        $this->loaded = $updated;
    }

    /**
     * Один installFinish на всё поколение, а не на каждого сотрудника.
     * Маркер ставится под lock ДО рендера: параллельные открытия не получают два
     * сплэша. Legacy-массив пользователей мигрирует в глобальный маркер без сплэша.
     */
    public function claimInstallFinishForCurrentGeneration(): bool {
        $claimed = false;
        $this->updateInstallMeta(static function (array $state) use (&$claimed): array {
            $generation = appInstallGenerationFrom($state);
            $marked = (string)($state['install_finish_requested_generation'] ?? '');
            if ($marked !== '' && hash_equals($generation, $marked)) return $state;
            $confirmed = (string)($state['install_finish_confirmed_generation'] ?? '');
            if ($confirmed !== '' && hash_equals($generation, $confirmed)) return $state;

            $legacyFinished = !empty((array)($state['installFinishedUsers'] ?? []));
            $state['install_finish_requested_generation'] = $generation;
            $state['install_finish_requested_at'] = $legacyFinished
                ? (int)(strtotime((string)($state['installFinishedAt'] ?? '')) ?: time())
                : time();
            unset($state['installFinishedAt'], $state['installFinishedUsers']);
            $claimed = !$legacyFinished;
            return $state;
        });
        return $claimed;
    }

    /** installFinish уже был запрошен для текущего поколения (результат мог потеряться). */
    public function installFinishRequestedForCurrentGeneration(): bool {
        $generation = appInstallGenerationFrom($this->tokens);
        if ($generation === '') return false;
        $requested = (string)($this->tokens['install_finish_requested_generation'] ?? '');
        return ($requested !== '' && hash_equals($generation, $requested))
            || !empty((array)($this->tokens['installFinishedUsers'] ?? []));
    }

    /** Портал уже подтвердил успешное завершение текущего поколения. */
    public function installFinishConfirmedForCurrentGeneration(): bool {
        $generation = appInstallGenerationFrom($this->tokens);
        if ($generation === '') return false;
        $confirmed = (string)($this->tokens['install_finish_confirmed_generation'] ?? '');
        return $confirmed !== '' && hash_equals($generation, $confirmed);
    }

    /** Уже запросили installFinish либо портал подтвердил установку этого поколения. */
    public function installFinishHandledForCurrentGeneration(): bool {
        return $this->installFinishRequestedForCurrentGeneration()
            || $this->installFinishConfirmedForCurrentGeneration();
    }

    /** Один app.info-check на cooldown даже при параллельных открытиях. */
    public function claimAppInfoCheck(int $cooldownSec): bool {
        $claimed = false;
        $this->updateInstallMeta(static function (array $state) use ($cooldownSec, &$claimed): array {
            $last = (int)($state['app_info_checked_at'] ?? 0);
            if ($last > 0 && (time() - $last) < max(1, $cooldownSec)) return $state;
            $state['app_info_checked_at'] = time();
            $claimed = true;
            return $state;
        });
        return $claimed;
    }

    /** app.info уже видит INSTALLED=true: это поколение не должно звать installFinish. */
    public function confirmInstallFinishedForCurrentGeneration(): void {
        $this->updateInstallMeta(static function (array $state): array {
            $state['install_finish_confirmed_generation'] = appInstallGenerationFrom($state);
            unset($state['installedForcedAt']);
            return $state;
        });
    }

    /**
     * @param ?bool $safeToRepeat Можно ли повторить вызов, если ответ потерялся.
     *        null — решаем по методу (читающие повторяем, изменяющие нет).
     *        true — вызывающий подтверждает, что повтор не создаст дубль
     *        на стороне портала (например, запись по заранее известному ID).
     *        runOnce() не является таким подтверждением: transport retry идёт
     *        внутри callback до того, как runOnce увидит результат.
     */
    public function call(string $method, array $params = [], ?bool $safeToRepeat = null): array {
        if (!$this->hasTokens()) throw new RuntimeException('B24: токенов нет — приложение не установлено');
        // Метка ОДНОЙ операции с порталом. Ставится здесь, а не у сетевой
        // попытки: повтор после «портал занят» и обновление токена посередине —
        // части того же обращения, и в журнале они обязаны читаться вместе.
        $callRef = self::newCallRef();
        // Отметку «изменения начались» ставим ДО отправки, а не после ответа:
        // ответ может не прийти вовсе (оборвалась связь, кончилась память), а
        // портал команду уже выполнит. Ответ при сбое опирается на эту отметку,
        // и опоздание превратило бы его снова в обещание «данные целы».
        if (self::callChangesPortal($method, $params) && function_exists('appChangesStarted')) {
            appChangesStarted($method);
        }
        $prevRef = $GLOBALS['__log_ctx']['обращение'] ?? null;
        if (function_exists('logContextSet')) logContextSet(['обращение' => $callRef]);
        try {
            return $this->callWithRef($method, $params, $safeToRepeat);
        } finally {
            // Вложенный вызов не должен стирать метку той операции, из которой
            // он начат: возвращаем прежнюю, а не чистим вслепую.
            if ($prevRef === null) unset($GLOBALS['__log_ctx']['обращение']);
            else $GLOBALS['__log_ctx']['обращение'] = $prevRef;
        }
    }

    private function callWithRef(string $method, array $params, ?bool $safeToRepeat): array {
        // Шов для самопроверки — по образцу b24PortalGet(): подмена включается
        // ТОЛЬКО когда стор подменён временной папкой (BXAPP_DATA_ROOT задан).
        // На бою такой переменной нет, подменить обращение к порталу нечем.
        // Нужен он затем, что «пакет любого размера» иначе не проверяется вовсе:
        // резка на куски по 50, сборка ответов и сохранность ключей вызывающего
        // ломаются тихо — часть команд просто не выполнится, а код останется зелёным.
        $stub = $GLOBALS['__b24_call'] ?? null;
        if ($stub !== null && is_callable($stub)
            && getenv('BXAPP_DATA_ROOT') !== false && getenv('BXAPP_DATA_ROOT') !== '') {
            $stubbed = $stub($method, $params);
            return is_array($stubbed) ? $stubbed : ['error' => 'stub_bad_answer'];
        }
        $res = $this->doCall($method, $params, $safeToRepeat);
        if (!empty($res['error']) && in_array($res['error'], ['expired_token', 'invalid_token'], true)
            && !empty($this->tokens['refresh_token'])) {
            $this->refreshAccessToken();
            $res = $this->doCall($method, $params, $safeToRepeat);
        }
        return $res;
    }

    /**
     * Один пакет — не больше 50 команд (жёсткий предел портала).
     *
     * Больше 50 сюда слать нельзя, и молча резать здесь тоже нельзя: метод
     * возвращает сырой ответ портала, то есть от одного пакета. Порезав, мы
     * вернули бы ответ ПОСЛЕДНЕГО куска, а вызывающий принял бы его за ответ на
     * весь пакет — половина команд «выполнилась» бы только на бумаге. Поэтому
     * отказ громкий и с указанием, чем звать: batchAll() режет и собирает сам.
     */
    public function batch(array $commands): array {
        // Признак «повтор безопасен» наружу не выставлен намеренно: объявлять его
        // вправе только тот, кто объяснил ПОЧЕМУ, — то есть batchAllIdempotent().
        // Публичный флаг здесь превратился бы в «поставь true, если торопишься».
        return $this->batchWithRepeatPolicy($commands, null);
    }

    private function batchWithRepeatPolicy(array $commands, ?bool $safeToRepeat): array {
        if (count($commands) > 50) {
            throw new RuntimeException('B24 batch: команд ' . count($commands)
                . ', предел портала — 50. Для пакета любого размера используйте batchAll().');
        }
        $cmd = [];
        foreach ($commands as $k => [$m, $p]) {
            $cmd[$k] = $m . '?' . http_build_query($p);
        }
        $res = $this->call('batch', ['halt' => 0, 'cmd' => $cmd], $safeToRepeat);

        // ПРОТУХШИЙ ТОКЕН ВНУТРИ ПАКЕТА. Одиночный вызов такое чинит сам:
        // портал отвечает ошибкой, клиент обновляет токен и повторяет. Пакет —
        // другое дело: сам метод `batch` отработал успешно, а протухший токен
        // виден только внутри, в ошибках подкоманд. Вызывающему уходили
        // пятьдесят «ошибок» и ноль данных; для человека это «портал молчит»,
        // хотя токен просто устарел на минуту.
        //
        // Повторяем ТОЛЬКО когда успехов ровно ноль и КАЖДАЯ подкоманда упала
        // именно на токене. Смешанный ответ не повторяем никогда: часть команд
        // уже выполнена, и второй заход создал бы дубли — вторую сделку, второе
        // письмо, вторую задачу. Повтор один: если и он упал на токене, дело не
        // в свежести токена, и крутить бессмысленно.
        if (!empty($this->tokens['refresh_token']) && self::batchAllExpired($res)) {
            $this->refreshAccessToken();
            $res = $this->call('batch', ['halt' => 0, 'cmd' => $cmd], $safeToRepeat);
        }
        return $res;
    }

    /** Весь пакет лёг на протухшем токене: успехов ноль, все ошибки — про токен. */
    private static function batchAllExpired(array $res): bool {
        $body = $res['result'] ?? null;
        if (!is_array($body)) return false;
        $ok  = (array)($body['result'] ?? []);
        $err = (array)($body['result_error'] ?? []);
        if ($ok !== [] || $err === []) return false;
        foreach ($err as $e) {
            $code = is_array($e) ? (string)($e['error'] ?? '') : (string)$e;
            if (!in_array($code, ['expired_token', 'invalid_token'], true)) return false;
        }
        return true;
    }

    /**
     * Достать список из ответа портала: разные семейства методов кладут его под
     * разными ключами (`crm.item.list` → items, `tasks.task.list` → tasks), а
     * старые методы отдают массив сразу.
     *
     * Отдельной функцией — потому что ошибка здесь беззвучная: неразвёрнутый
     * ответ выглядит как «одна запись» и обход списка обрывается на первой
     * странице, показав человеку 1 задачу вместо 300.
     *
     * @return array|null null — в ответе списка нет вовсе.
     */
    public static function unwrapList($result): ?array {
        if (!is_array($result)) return null;
        if ($result === []) return [];
        // Список пришёл как есть (ключи 0,1,2…).
        if (array_key_first($result) === 0) return $result;
        foreach (['items', 'tasks'] as $known) {
            if (isset($result[$known]) && is_array($result[$known])) return $result[$known];
        }
        // Незнакомый метод: список под единственным ключом — берём его. Так
        // новые семейства методов работают без правки этого файла.
        if (count($result) === 1) {
            $only = reset($result);
            if (is_array($only) && ($only === [] || array_key_first($only) === 0)) return $only;
        }
        return null;
    }

    /**
     * Семейства методов, у которых поля в REST пишутся camelCase.
     *
     * Список намеренно КОРОТКИЙ и по документации, а не «на глаз»: угаданный
     * префикс — та же ошибка, только молчаливая. Метод не отсюда — берём
     * legacy-имя 'ID'; нужен другой регистр — передайте $idField явно.
     *
     * Источник: /data/config/b24-rest-docs/api-reference/crm/universal/index.md
     * («в БД UPPER_CASE, в REST camelCase») и crm-item-list.md — там id, stageId,
     * assignedById, а прописное 'ID' в order/filter портал отвергает.
     */
    private const CAMEL_ID_PREFIXES = ['crm.item.'];

    /**
     * Имя поля идентификатора по умолчанию — по имени метода.
     *
     * Зачем вообще: витринный блок `$b24->fetchAll('crm.item.list', [...])`
     * зовётся БЕЗ третьего аргумента, и прежний глухой дефолт 'ID' отправлял
     * `order={'ID':'ASC'}` — портал отвечал INVALID_ARG_VALUE, список не
     * приходил вовсе. Менять дефолт на 'id' для всех было нельзя: у
     * `*.userfield.list`, `crm.status.list`, `user.get` верно как раз 'ID'.
     */
    public static function defaultIdField(string $method): string {
        $m = strtolower(trim($method));
        foreach (self::CAMEL_ID_PREFIXES as $prefix) {
            if (strpos($m, $prefix) === 0) return 'id';
        }
        return 'ID';
    }

    /** Явно переданное имя поля важнее дефолта; пустая строка — это не имя. */
    private static function resolveIdField(string $method, ?string $idField): string {
        $named = $idField === null ? '' : trim($idField);
        return $named !== '' ? $named : self::defaultIdField($method);
    }

    /**
     * Имена параметров сортировки и фильтра — ПО ДОКУМЕНТАЦИИ, по семействам.
     *
     * Курсорный обход ставит свою сортировку и своё условие '>ID', но у части
     * списочных методов эти параметры называются иначе. Пока имена были глухо
     * зашиты как строчные order/filter, запрос уходил с сортировкой, которой у
     * метода нет, а фильтр вызывающего переезжал под неизвестное методу имя:
     * портал его отбрасывал, и «элементы с именем %Тест%» превращались в список
     * целиком — молча, без исключения (K4-41, круг 4).
     *
     * Список намеренно КОРОТКИЙ и по документации, а не «на глаз»:
     *   • lists.element.get — ELEMENT_ORDER и FILTER
     *     (api-reference/lists/elements/lists-element-get.md:124 и :81;
     *      строчного `filter` в документации метода нет вовсе);
     *   • sonet_group.get — ORDER и FILTER
     *     (api-reference/sonet-group/sonet-group-get.md:25).
     * Метода нет в таблице — берём строчные order/filter: так работают crm.*,
     * tasks.* и прочие современные списки, на которых курсор и проверен.
     */
    private const CURSOR_PARAM_NAMES = [
        'lists.element.' => ['order' => 'ELEMENT_ORDER', 'filter' => 'FILTER'],
        'sonet_group.'   => ['order' => 'ORDER',         'filter' => 'FILTER'],
    ];

    /**
     * Методы, курсорный обход которых выразить НЕЧЕМ, — отказываем до сети.
     *
     * У `user.get` сортировка документирована двумя строками: `sort` — имя поля,
     * `order` — направление (api-reference/user/user-get.md:33-37). Картой
     * `{ID: ASC}` это не выражается, а отправить её «на всякий случай» значит
     * снова тихо отдать неполный список за полный. Отказ называет готовую замену
     * — `fetchAllBatched()`, который листает смещением и сверяет `total`; именно
     * им пользователей и читает сам шаблон (www/api/users.php).
     */
    private const CURSOR_UNSUPPORTED = [
        'user.get' => 'сортировка у user.get задаётся парой строк sort+order, а не картой',
    ];

    /**
     * Методы, чей ФИЛЬТР документирован только по равенству: оператор «>ID»
     * портал молча выбрасывает, и курсор стоит на месте.
     *
     * Это не догадка: у `crm.status.list` фильтруемые поля задаются строками
     * (api-reference/crm/status/crm-status-list.md), у `*.userfield.list` они
     * перечислены прямым списком — ID, FIELD_NAME, USER_TYPE_ID, XML_ID, SORT,
     * MULTIPLE… (api-reference/crm/companies/userfields/
     * crm-company-userfield-list.md), причём порядок по умолчанию идёт по SORT,
     * а не по ID. Пока этой проверки не было, приложение на портале с 60 полями
     * компании падало «курсор ID не двигается (220 после 2728)» и не могло
     * начать работу вовсе (обращение 0921-101006-3f84). Оба метода возвращают
     * `total` и листаются `start`, поэтому замена честная и полная.
     *
     * Ключ — либо полное имя метода, либо окончание имени для целого семейства.
     */
    private const CURSOR_UNSUPPORTED_FILTER = [
        'crm.status.list' => 'фильтр crm.status.list документирован только по равенству',
        '.userfield.list' => 'фильтр *.userfield.list документирован только по равенству, а порядок по умолчанию идёт по SORT',
    ];

    /**
     * Можно ли обойти метод курсором по идентификатору.
     *
     * Отдельно от cursorParamNames() намеренно: имена параметров нужны и
     * счётчику listStats(), которому курсор ни к чему — ему хватает одной
     * страницы с `total`. Запрет обязан касаться только курсорного ОБХОДА,
     * иначе «сколько всего записей в справочнике» стало бы недоступно на
     * ровном месте.
     */
    private static function assertCursorSupported(string $method): void {
        $m = strtolower(trim($method));
        foreach (self::CURSOR_UNSUPPORTED_FILTER as $obrazec => $prichina) {
            $sovpalo = $obrazec[0] === '.'
                ? substr($m, -strlen($obrazec)) === $obrazec
                : $m === $obrazec;
            if (!$sovpalo) continue;
            throw new InvalidArgumentException('B24 fetchAll: метод ' . $method
                . ' курсором по идентификатору не обходится — ' . $prichina
                . ', условие «>ID» портал молча выбрасывает. Возьмите fetchAllBatched('
                . var_export($method, true) . ', ...): он листает смещением и сверяет total.');
        }
    }

    /** @return array{order: string, filter: string} */
    private static function cursorParamNames(string $method): array {
        $m = strtolower(trim($method));
        if (isset(self::CURSOR_UNSUPPORTED[$m])) {
            throw new InvalidArgumentException('B24 fetchAll: метод ' . $method
                . ' курсором по идентификатору не обходится — ' . self::CURSOR_UNSUPPORTED[$m]
                . '. Возьмите fetchAllBatched(' . var_export($method, true)
                . ', ...): он листает смещением и сверяет total.');
        }
        foreach (self::CURSOR_PARAM_NAMES as $prefix => $names) {
            if (strpos($m, $prefix) === 0) return $names;
        }
        return ['order' => 'order', 'filter' => 'filter'];
    }

    /**
     * Вызов, который НЕ даёт перепутать отказ портала с успехом.
     *
     * `call()` возвращает ответ как есть, и типовая ошибка выглядит так:
     * `$r = $b24->call('crm.item.add', ...); $id = $r['result']['item']['id'];`
     * — портал ответил `{"error":"..."}`, `$id` стал пустым, а код поехал дальше
     * и записал пустоту в наш стор. Здесь отказ становится исключением сразу.
     */
    public function callChecked(string $method, array $params = []): array {
        $r = $this->call($method, $params);
        if (isset($r['error'])) {
            // Код отказа портала обязан дойти до вызывающего. Раньше в сообщение
            // попадало только описание, а оно бывает и пустым, и переведённым:
            // «портал отказал» одинаково выглядит у истёкшего токена события
            // (ACCESS_DENIED — повторять бессмысленно) и у превышенного лимита
            // (повторить нужно). Отличить их было нечем, кроме угадывания.
            $code = (string)$r['error'];
            $text = (string)($r['error_description'] ?? '');
            throw new RuntimeException('B24 ' . $method . ': '
                . ($text !== '' ? $text : $code)
                . ($code !== '' ? ' [' . $code . ']' : ''));
        }
        return is_array($r['result'] ?? null) ? $r['result'] : ['result' => $r['result'] ?? null];
    }

    /**
     * Точечный read-back после мутации с проверкой реально сохранённых полей.
     *
     * Обычный callChecked('...get') доказывает только успешный HTTP-ответ. Он
     * не замечает ни чтение другой записи, ни тихо проигнорированное порталом
     * поле. Этот helper принимает тот же snapshot `$fields`, с которым была
     * выполнена мутация, и бросает исключение ДО auditLog(), если портал вернул
     * иное значение. В сообщение намеренно не попадают selector/значения: там
     * могут быть персональные данные и секреты.
     *
     * @return array Фактическая сущность без обёртки item/task.
     */
    public function readBackChecked(string $method, array $selector, array $expectedFields): array {
        if (!preg_match('~\.get$~i', $method)) {
            throw new InvalidArgumentException('B24 read-back: разрешён только точечный *.get');
        }
        if ($expectedFields === []) {
            throw new InvalidArgumentException('B24 read-back: expectedFields не может быть пустым');
        }
        $result = $this->callChecked($method, $selector);
        $entity = self::readBackEntity($result, $method);
        self::assertReadBackSubset($entity, $expectedFields, $method, 'fields');
        return $entity;
    }

    /**
     * Удаление по встроенному контракту конкретного REST-метода. Business-код
     * не передаёт callback проверки: иначе `fn()=>true` превращает verifier в
     * декорацию. Новая семья добавляется только сюда вместе с официальным
     * контрактом ответа/read-back и регрессией.
     */
    public function deleteVerified(string $method, array $params): array {
        $policy = self::verifiedMutationPolicy('delete', $method);
        $result = $this->callChecked($method, $params);
        self::assertVerifiedMutationResult($method, $params, $result, $policy);

        if (isset($policy['probe'])) {
            $probe = $policy['probe'];
            $selector = [];
            foreach ($probe['selector'] as $from => $to) $selector[$to] = $params[$from];
            $read = $this->call((string)$probe['method'], $selector, true);
            $error = (string)($read['error'] ?? '');
            if ($error === '' || !in_array($error, $probe['absent_errors'], true)) {
                throw new RuntimeException('B24 ' . $method . ': удаление не подтверждено read-back');
            }
        }
        return $result;
    }

    /**
     * Action-метод без универсального *.get: успех определяется защищённым
     * реестром официальных контрактов ответа. Неизвестный action fail-closed —
     * сначала расширьте реестр и тесты шаблона.
     */
    public function actionVerified(string $method, array $params): array {
        $policy = self::verifiedMutationPolicy('action', $method);
        $result = $this->callChecked($method, $params);
        self::assertVerifiedMutationResult($method, $params, $result, $policy);
        return $result;
    }

    /** @return array<string,mixed> */
    private static function verifiedMutationPolicy(string $kind, string $method): array {
        $policies = [
            'delete' => [
                // crm.item.delete возвращает []; отсутствие доказывает crm.item.get → NOT_FOUND.
                'crm.item.delete' => [
                    'required' => ['entityTypeId', 'id'], 'result' => 'empty-array',
                    'probe' => ['method' => 'crm.item.get',
                        'selector' => ['entityTypeId' => 'entityTypeId', 'id' => 'id'],
                        'absent_errors' => ['NOT_FOUND']],
                ],
                // tasks.task.get смешивает «нет» и «нет доступа»; официальный delete-result.task=true
                // является более сильным и однозначным подтверждением этой операции.
                'tasks.task.delete' => [
                    'required' => ['taskId'], 'result' => 'true-path', 'path' => ['task'],
                ],
            ],
            'action' => [
                // Официальный результат — непустой ID запущенного workflow.
                'bizproc.workflow.start' => [
                    'required' => ['TEMPLATE_ID', 'DOCUMENT_ID'],
                    'result' => 'non-empty-string-path', 'path' => ['result'],
                ],
                // Завершённая загрузка возвращает объект файла с положительным ID.
                'disk.folder.uploadfile' => [
                    'required' => ['id', 'fileContent'],
                    'result' => 'positive-id-path', 'path' => ['ID'],
                ],
                // Регистрация действия БП. Официальный ответ — result: true
                // (bizproc/bizproc-activity/bizproc-activity-add.md). Перечитать
                // нечего: bizproc.activity.get в REST не существует, list отдаёт
                // только коды. Повтор безопасен потому, что вызывающий выбирает
                // add или update по этому же списку, а не пробует вслепую:
                // второй add тем же CODE падает ERROR_ACTIVITY_ALREADY_INSTALLED.
                'bizproc.activity.add' => [
                    'required' => ['CODE', 'HANDLER', 'NAME'],
                    'result' => 'true-path', 'path' => ['result'],
                ],
                'bizproc.activity.update' => [
                    'required' => ['CODE', 'FIELDS'],
                    'result' => 'true-path', 'path' => ['result'],
                ],
                // Ответ бизнес-процессу по его event_token. Значения дошли до
                // процесса ТОЛЬКО при result === true: HTTP 200 с result=false
                // означает «не доставлено», и засчитывать его успехом нельзя —
                // процесс останется висеть (проверено на bp-reply epk).
                // Имена параметров портал принимает в нижнем регистре, как в
                // официальных примерах метода.
                'bizproc.event.send' => [
                    'required' => ['event_token'],
                    'result' => 'true-path', 'path' => ['result'],
                ],
            ],
        ];
        $method = strtolower($method);
        if (!isset($policies[$kind][$method])) {
            throw new InvalidArgumentException('B24 ' . $method . ': нет встроенного verified-контракта');
        }
        return $policies[$kind][$method];
    }

    /** @param array<string,mixed> $policy */
    private static function assertVerifiedMutationResult(
        string $method, array $params, array $result, array $policy
    ): void {
        foreach ($policy['required'] as $key) {
            if (!array_key_exists($key, $params)) {
                throw new InvalidArgumentException('B24 ' . $method . ': verified-контракт не получил ' . $key);
            }
        }
        $mode = (string)$policy['result'];
        if ($mode === 'empty-array') {
            if ($result === []) return;
        } else {
            $value = $result;
            foreach ($policy['path'] as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    throw new RuntimeException('B24 ' . $method . ': ответ не прошёл verified-контракт');
                }
                $value = $value[$key];
            }
            if ($mode === 'true-path' && $value === true) return;
            if ($mode === 'non-empty-string-path' && is_string($value) && $value !== '') return;
            if ($mode === 'positive-id-path'
                && ((is_int($value) && $value > 0)
                    || (is_string($value) && ctype_digit($value) && (int)$value > 0))) return;
        }
        throw new RuntimeException('B24 ' . $method . ': ответ не прошёл verified-контракт');
    }

    /** @return array<string|int,mixed> */
    private static function readBackEntity(array $result, string $method): array {
        foreach (['item', 'task'] as $wrapper) {
            if (array_key_exists($wrapper, $result)) {
                if (!is_array($result[$wrapper]) || $result[$wrapper] === []) {
                    throw new RuntimeException('B24 ' . $method . ': read-back не вернул сущность');
                }
                return $result[$wrapper];
            }
        }
        // Старые методы (например crm.deal.get) возвращают поля прямо в
        // result. Числовой список и scalar-wrapper сущностью не считаем.
        if ($result === [] || array_key_first($result) === 0 || array_key_exists('result', $result)) {
            throw new RuntimeException('B24 ' . $method . ': неоднозначный ответ read-back');
        }
        return $result;
    }

    private static function assertReadBackSubset($actual, $expected, string $method, string $path): void {
        if (is_array($expected)) {
            if (!is_array($actual)) self::readBackMismatch($method, $path);
            $expectedList = ($expected === [] || array_key_first($expected) === 0);
            $actualList = ($actual === [] || array_key_first($actual) === 0);
            if ($expectedList) {
                if (!$actualList || count($actual) !== count($expected)) self::readBackMismatch($method, $path);
                foreach ($expected as $at => $value) {
                    self::assertReadBackSubset($actual[$at] ?? null, $value, $method, $path . '[' . $at . ']');
                }
                return;
            }
            if ($actualList) self::readBackMismatch($method, $path);
            foreach ($expected as $key => $value) {
                $actualKey = self::readBackActualKey($actual, (string)$key);
                if ($actualKey === null) self::readBackMismatch($method, $path . '.' . (string)$key);
                self::assertReadBackSubset($actual[$actualKey], $value, $method, $path . '.' . (string)$key);
            }
            return;
        }

        if (gettype($actual) === gettype($expected) && $actual === $expected) return;
        // REST часто сериализует целые ID как строки. Разрешаем только
        // каноническую integer-string, без общей опасной нормализации типов.
        if (is_int($expected) && is_string($actual)
            && preg_match('~^(?:0|-?[1-9]\d*)$~D', $actual) && (string)$expected === $actual) return;
        if (is_string($expected) && is_int($actual)
            && preg_match('~^(?:0|-?[1-9]\d*)$~D', $expected) && (string)$actual === $expected) return;
        // Деньги и даты приложение обязано отправлять через готовый fmt.php, а он
        // по контракту Битрикса отдаёт СТРОКИ ('999.90', '2026-08-05'). Портал же
        // в ответе возвращает число (999.9) и ISO-дату с временем и зоной. Без этой
        // ветки штатный путь шаблона (runOnce → callChecked → readBackChecked) падал
        // на любой сделке с суммой: запись в портале уже сделана, аудит не записан,
        // а ключ runOnce навсегда оставался в состоянии «исход неизвестен».
        if (self::readBackSameMoney($actual, $expected)) return;
        if (self::readBackSameDay($actual, $expected)) return;
        self::readBackMismatch($method, $path);
    }

    /**
     * Одна и та же сумма, записанная по-разному? Сравниваем в копейках, а не
     * строками: '999.90' от moneyForB24() и 999.9 от портала — это одни деньги.
     * Плавающую точку не сравниваем напрямую (0.1+0.2 != 0.3): обе стороны
     * приводим к целым копейкам через ту же функцию, что и остальной шаблон.
     */
    private static function readBackSameMoney($actual, $expected): bool {
        $toCents = static function ($v): ?int {
            if (is_int($v)) return $v * 100;
            if (is_float($v)) return (int)round($v * 100);
            if (is_string($v)) {
                $t = trim($v);
                // Ведущий ноль — не запись денег, а другое значение: '0100' против
                // 100 сходились в одних копейках, и подменённое порталом поле
                // проходило сверку как совпадение. Целую часть с ведущим нулём
                // принимаем только в виде '0.xx'.
                if (preg_match('~^-?0\d~D', $t)) return null;
                if (preg_match('~^-?\d+(?:[.,]\d{1,2})?$~D', $t)) {
                    return (int)round((float)str_replace(',', '.', $t) * 100);
                }
            }
            return null;
        };
        // Обе стороны должны быть похожи на деньги, и хотя бы одна — с дробной
        // частью или строкой: иначе это обычные целые, у них своя ветка выше.
        if ((is_string($expected) && strpbrk($expected, '.,') === false)
            && (is_string($actual) && strpbrk($actual, '.,') === false)) return false;
        if (is_bool($actual) || is_bool($expected) || $actual === null || $expected === null) return false;
        $a = $toCents($actual);
        $b = $toCents($expected);
        return $a !== null && $b !== null && $a === $b;
    }

    /**
     * Один и тот же день? b24Day() даёт '2026-08-05', портал возвращает
     * '2026-08-05T02:00:00+02:00'. Сравниваем срез ISO 0–10, как принято в шаблоне
     * для дат Битрикса, — без strtotime и без переводов часового пояса.
     */
    private static function readBackSameDay($actual, $expected): bool {
        $day = static function ($v): ?string {
            if (!is_string($v)) return null;
            $v = trim($v);
            if (preg_match('~^\d{4}-\d{2}-\d{2}$~D', $v)) return $v;
            if (preg_match('~^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}~D', $v, $m)) return $m[1];
            return null;
        };
        $a = $day($actual);
        $b = $day($expected);
        return $a !== null && $b !== null && $a === $b;
    }

    /** @return string|int|null */
    private static function readBackActualKey(array $actual, string $expectedKey) {
        if (array_key_exists($expectedKey, $actual)) return $expectedKey;
        $found = null;
        foreach ($actual as $key => $_) {
            if (!is_string($key) || strcasecmp($key, $expectedKey) !== 0) continue;
            if ($found !== null) return null; // неоднозначные FOO/foo — fail-closed
            $found = $key;
        }
        return $found;
    }

    private static function readBackMismatch(string $method, string $path): void {
        throw new RuntimeException('B24 ' . $method . ': read-back не совпал, поле ' . $path);
    }

    /**
     * Пакет команд ЛЮБОГО размера: сам режет по 50 (лимит портала) и собирает
     * ответы в один массив по тем же ключам.
     *
     * halt=0 намеренно: одна плохая команда не должна отменять весь пакет. Но
     * тогда обязательно вернуть и ошибки — «частично применилось» это не сбой
     * вызова, а факт, который прикладной код должен увидеть и обработать.
     *
     * @return array ['result' => [ключ => ответ], 'errors' => [ключ => текст]]
     */
    public function batchAll(array $commands): array {
        return $this->batchAllWithRepeatPolicy($commands, null);
    }

    /** @param ?bool $safeToRepeat null — решаем по составу пакета (мутация не повторяется). */
    private function batchAllWithRepeatPolicy(array $commands, ?bool $safeToRepeat): array {
        $result = [];
        $errors = [];
        foreach (array_chunk($commands, 50, true) as $chunk) {
            $r = $this->batchWithRepeatPolicy($chunk, $safeToRepeat);
            [$chunkResult, $chunkErrors] = self::decodeBatchChunk($chunk, $r);
            foreach ($chunkResult as $k => $v) $result[$k] = $v;
            foreach ($chunkErrors as $k => $v) $errors[$k] = $v;
        }
        return ['result' => $result, 'errors' => $errors];
    }

    /** Пакет мутаций: ни одна partial-ошибка не может выглядеть успехом. */
    public function batchAllChecked(array $commands): array {
        $result = $this->batchAll($commands);
        if ($result['errors'] !== []) {
            throw new RuntimeException('B24 batch: ошибок команд ' . count($result['errors']));
        }
        return $result;
    }

    /**
     * Пакет мутаций, где ЧАСТИЧНЫЙ отказ — нормальный исход, а не авария.
     *
     * Зачем отдельно от batchAllChecked. Есть операции, где падение одной
     * команды не должно отменять остальные: завести десять полей в карточке,
     * проставить признак пятистам сделкам. batchAllChecked там не годится — он
     * бросает исключение, и вызывающий не узнаёт, что девять полей уже созданы;
     * на следующем заходе он создаст их заново. А голый batchAll — обратная
     * крайность: «ошибок нет» там означает лишь «портал принял команды», и
     * между этим и «изменение реально произошло» помещается вся разница между
     * работающим приложением и тихо сломанным.
     *
     * Поэтому здесь проверка ОБЯЗАТЕЛЬНА как аргумент: $readBack получает ключи
     * команд и возвращает те из них, которые он ПОДТВЕРДИЛ перечитыванием
     * портала. Всё, что не подтверждено, уходит в errors — даже если портал
     * ответил успехом.
     *
     * @param array    $commands ключ => [метод, параметры]
     * @param callable $readBack fn(array $keys): array — подтверждённые ключи
     * @return array ['result' => [...], 'errors' => [ключ => причина]]
     */
    public function batchAllPartialChecked(array $commands, callable $readBack): array {
        $res = $this->batchAll($commands);
        $sent = array_keys($res['result']);
        $confirmed = (array)$readBack($sent);
        foreach ($sent as $key) {
            if (in_array($key, $confirmed, true)) continue;
            // Портал сказал «сделано», а перечитывание этого не нашло. Это не
            // придирка: так выглядят нехватка прав, лимит и подмена имени —
            // случаи, где приложение считает работу выполненной, а её нет.
            $res['errors'][$key] = 'портал ответил успехом, но перечитывание не подтвердило изменение';
            unset($res['result'][$key]);
        }
        return $res;
    }

    /**
     * Пакет команд, повтор которых БЕЗОПАСЕН по контракту самого портала.
     *
     * Есть операции, где перечитать результат нечем, а повтор при этом ничего
     * не ломает. Пример — уведомление с меткой: портал считает метку уникальной
     * в рамках приложения и при повторной отправке ЗАМЕНЯЕТ прежнее сообщение,
     * а не добавляет второе. Требовать здесь read-back бессмысленно (проверять
     * нечего), а отправлять голым batchAll — значит потерять всякий след того,
     * почему повтор сочли безопасным.
     *
     * Поэтому идемпотентность заявляется ЯВНО и с объяснением: строка $why
     * уходит в журнал вместе с фактом отправки. Это тот же приём, что
     * `safeToRepeat` у внешних вызовов: молчаливое «наверное, можно» — как раз
     * то, из-за чего клиенту выставляют второй счёт.
     *
     * @param array  $commands ключ => [метод, параметры]
     * @param string $why      почему повтор безопасен — человеческим языком
     */
    public function batchAllIdempotent(array $commands, string $why): array {
        $why = trim($why);
        if (mb_strlen($why) < 12) {
            throw new InvalidArgumentException(
                'B24 batchAllIdempotent: скажите, ПОЧЕМУ повтор этих команд безопасен. '
                . 'Без объяснения это просто пакет мутаций без защиты.');
        }
        // ВОТ РАДИ ЧЕГО ЗАТЕВАЛСЯ ЭТОТ ПОМОЩНИК. Обычный пакет мутаций после
        // потерянного ответа (502, оборванная связь) не повторяется — и правильно:
        // портал мог команду выполнить. Здесь вызывающий объяснил, почему повтор
        // безопасен, и признак идёт до самого сетевого слоя. Раньше он никуда не
        // шёл: helper требовал объяснение, писал его в журнал и отправлял пакет
        // ровно тем же fail-closed путём — обещание безопасного повтора не
        // выполнялось ни разу (находка ревью 09.08.2026, R3-011).
        $res = $this->batchAllWithRepeatPolicy($commands, true);
        appLog('info', 'b24.idempotent_batch', [
            'команд' => count($commands),
            'ошибок' => count($res['errors']),
            'почему повтор безопасен' => $why,
        ]);
        return $res;
    }

    /**
     * Разобрать batch-ответ строго по ключам запрошенных команд.
     * Ключа нет ни в result, ни в result_error — это отказ, а не пустой успех.
     * Посторонние ключи ответа не проносим выше.
     *
     * @return array{0: array, 1: array}
     */
    private static function decodeBatchChunk(array $chunk, array $response): array {
        $result = [];
        $errors = [];

        if (array_key_exists('error', $response)) {
            $text = (string)($response['error_description'] ?? $response['error'] ?? 'отказ batch');
            if ($text === '') $text = 'отказ batch';
            foreach ($chunk as $key => $_) $errors[$key] = $text;
            return [$result, $errors];
        }

        $body = is_array($response['result'] ?? null) ? $response['result'] : [];
        $ok = is_array($body['result'] ?? null) ? $body['result'] : [];
        $bad = is_array($body['result_error'] ?? null) ? $body['result_error'] : [];
        foreach ($chunk as $key => $_) {
            // Противоречивый ключ в обоих разделах тоже fail-closed: ошибка побеждает.
            if (array_key_exists($key, $bad)) {
                $v = $bad[$key];
                $errors[$key] = is_array($v)
                    ? (string)($v['error_description'] ?? $v['error'] ?? 'ошибка')
                    : (string)$v;
                if ($errors[$key] === '') $errors[$key] = 'ошибка';
            } elseif (array_key_exists($key, $ok)) {
                $result[$key] = $ok[$key];
            } else {
                $errors[$key] = 'портал не вернул ответ команды';
            }
        }
        return [$result, $errors];
    }

    /**
     * Забрать весь список БЫСТРО: первая страница даёт total, дальше страницы
     * тянутся пакетами по 50 команд.
     *
     * Отличие от fetchAll: тот идёт курсором строго последовательно и на 5000
     * записях делает сто запросов подряд — человек ждёт минуту, глядя на
     * крутилку. Здесь тех же 5000 записей приезжают за 3 обращения к порталу.
     *
     * Цена: список берётся постранично по `start`, а он на ЖИВОМ, меняющемся
     * списке способен пропустить и продублировать записи (люди работают, сделки
     * добавляются). Поэтому:
     *   • отчёты и экраны, где важна скорость, — fetchAllBatched();
     *   • выгрузки и массовые правки, где важна полнота, — fetchAll() курсором.
     *
     * @param int $hardLimit Предохранитель ПО ЧИСЛУ записей (не по памяти: вес
     *        записи бывает в разы больше). 10000 типовых записей CRM — это уже
     *        десятки мегабайт, поэтому предел скромный и поднимается осознанно.
     * @param string|null $idField Поле уникального ID; нужно для доказательства, что страницы
     *        не дублируются. null — определить по имени метода (см. defaultIdField()).
     */
    public function fetchAllBatched(
        string $method,
        array $params = [],
        int $hardLimit = 10000,
        ?string $idField = null
    ): array {
        $idField = self::resolveIdField($method, $idField);
        if ($hardLimit < 1) {
            throw new InvalidArgumentException('B24 fetchAllBatched: hardLimit должен быть больше нуля');
        }
        $first = $this->call($method, self::batchedPageParams($params, 0, $method));
        if (isset($first['error'])) {
            throw new RuntimeException('B24 ' . $method . ': ' . (string)($first['error_description'] ?? $first['error']));
        }
        $items = self::unwrapList($first['result'] ?? []);
        if ($items === null) {
            throw new RuntimeException('B24 ' . $method . ': в ответе нет списка — разберите ответ через call().');
        }
        $rawTotal = $first['total'] ?? null;
        if (!(is_int($rawTotal) || (is_string($rawTotal) && ctype_digit($rawTotal)))) {
            throw new RuntimeException('B24 ' . $method . ': портал не вернул total — '
                . 'полноту выборки нельзя доказать, используйте fetchAll().');
        }
        $total = (int)$rawTotal;
        $page  = count($items);
        if ($total > $hardLimit) {
            throw new RuntimeException('B24 ' . $method . ': в портале ' . $total . ' записей, это больше предела '
                . $hardLimit . '. Сузьте фильтр или поднимите предел осознанно.');
        }
        if ($page > $total || ($page === 0 && $total > 0)) {
            throw new RuntimeException('B24 ' . $method . ': первая страница не совпадает с total — '
                . 'выборка неполна.');
        }
        $seenIds = self::rememberUniqueRows($items, $method, $idField);
        if ($total === $page) return $items;

        // Смещения следующих страниц знаем заранее — значит, их можно просить пачкой.
        $commands = [];
        for ($start = $page; $start < $total; $start += $page) {
            $commands['p' . $start] = [$method, self::batchedPageParams($params, $start, $method)];
        }
        $res = $this->batchAll($commands);
        if ($res['errors'] !== []) {
            throw new RuntimeException('B24 ' . $method . ': часть страниц не пришла ('
                . implode('; ', array_slice($res['errors'], 0, 3)) . ') — выборка неполная.');
        }
        foreach ($commands as $key => $_command) {
            $start = (int)substr((string)$key, 1);
            $expected = min($page, $total - $start);
            $chunk = $res['result'][$key]; // batchAll: тут либо result[$key], либо errors[$key]
            [$rows, $seenIds] = self::validateBatchedPage(
                is_array($chunk) ? $chunk : [],
                $expected,
                $method,
                $idField,
                $seenIds
            );
            foreach ($rows as $row) $items[] = $row;
        }
        if (count($items) !== $total || count($seenIds) !== $total) {
            throw new RuntimeException('B24 ' . $method . ': портал вернул ' . count($items)
                . ' уникальных записей из ' . $total . ' — выборка неполна.');
        }
        return $items;
    }

    /**
     * СКОЛЬКО ВСЕГО ЗАПИСЕЙ И КАКОВ КРАЙНИЙ ID — без выгрузки самого списка.
     *
     * ЗАЧЕМ. Массовая сверка («пройти все компании и проставить признак») обязана
     * показывать полосу прогресса и оценку оставшегося времени, а им нужен
     * знаменатель — общий объём работы. Без него проект делает одно из двух, и
     * оба плохи: тянет весь список ради count (десятки мегабайт и минуты на
     * хостинге, где запрос живёт 30 секунд) или угадывает объём — и полоса
     * скачет, а «осталось 2 минуты» превращается в полчаса.
     *
     * КАК. Спрашивается ОДНА страница, и нужны в ней не записи, а два числа:
     * служебное поле ответа `total` (api-reference/crm/universal/crm-item-list.md:
     * «Общее количество найденных записей») и крайний идентификатор при
     * сортировке по убыванию. Поэтому `start` здесь именно 0: на `start = -1`
     * портал общее количество НЕ считает (так ускоряется курсорный fetchAll).
     *
     * ЭТО НЕ ЗАМЕНА fetchAll(). Метод не возвращает записей вовсе и потому не
     * может выдать первую страницу за весь список: наружу идут только числа.
     * Сами данные по-прежнему берутся fetchAll() / fetchAllBatched(), и только
     * они доказывают полноту выборки.
     *
     * ПОЧЕМУ СОРТИРОВКА ПРОВЕРЯЕТСЯ. Порядок, который метод не поддерживает,
     * портал может проигнорировать молча. Тогда первая запись страницы — не
     * крайняя, а самая ранняя, и вызывающий, который делит работу окнами ID,
     * закончит обход на середине, считая список пройденным. Поэтому убывание
     * доказывается по самой странице, а не предполагается.
     *
     * @param string|null $idField Имя поля идентификатора в order (см. defaultIdField()).
     * @return array{total:int,maxId:int} maxId = 0, когда записей нет вовсе.
     */
    public function listStats(string $method, array $params = [], ?string $idField = null): array {
        $idField = self::resolveIdField($method, $idField);
        $r = $this->call($method, self::listStatsParams($params, $method, $idField));
        if (isset($r['error'])) {
            throw new RuntimeException('B24 ' . $method . ': ' . (string)($r['error_description'] ?? $r['error']));
        }
        return self::decodeListStats($r, $method, $idField);
    }

    /** Параметры счётной страницы: свой order, свой start, фильтр вызывающего. */
    private static function listStatsParams(array $params, string $method, string $idField): array {
        $imena = self::cursorParamNames($method);
        // Фильтр вызывающего забираем в обоих написаниях и отдаём порталу ОДНИМ
        // ключом — по той же причине, что и в cursorPageParams(): два фильтра
        // рядом дают выборку, которую никто не заказывал.
        $userFilter = array_merge(
            is_array($params['FILTER'] ?? null) ? $params['FILTER'] : [],
            is_array($params['filter'] ?? null) ? $params['filter'] : []
        );
        $p = $params;
        unset(
            $p['limit'], $p['LIMIT'], $p['ORDER'], $p['FILTER'], $p['START'],
            $p['order'], $p['filter'], $p['start'], $p[$imena['order']], $p[$imena['filter']]
        );
        if ($userFilter !== []) $p[$imena['filter']] = $userFilter;
        $p[$imena['order']] = [$idField => 'DESC'];
        // Записи не нужны — нужен только их счёт и крайний ID. Свой select
        // ставим лишь когда вызывающий не задал его сам: у части методов этот
        // параметр называется иначе, и навязанный ключ был бы вредом.
        if (!isset($p['select']) && !isset($p['SELECT'])) $p['select'] = [$idField];
        $p['start'] = 0;                   // только при нём портал считает total
        return $p;
    }

    /** Разбор счётной страницы: числа принимаются лишь вместе с доказательствами. */
    private static function decodeListStats(array $response, string $method, string $idField): array {
        $rawTotal = $response['total'] ?? null;
        if (!(is_int($rawTotal) || (is_string($rawTotal) && ctype_digit($rawTotal)))) {
            throw new RuntimeException('B24 ' . $method . ': портал не вернул total — '
                . 'объём списка неизвестен, считать по нему прогресс нельзя.');
        }
        $total = (int)$rawTotal;
        $rows = self::unwrapList($response['result'] ?? []);
        if ($rows === null) {
            throw new RuntimeException('B24 ' . $method . ': в ответе нет списка — разберите ответ через call().');
        }
        if ($rows === []) {
            if ($total > 0) {
                throw new RuntimeException('B24 ' . $method . ': портал сообщил ' . $total
                    . ' записей и не дал ни одной — такому ответу верить нельзя.');
            }
            return ['total' => 0, 'maxId' => 0];
        }
        $rows = array_values($rows);
        $first = self::positiveRowId($rows[0], $method, $idField, 0);
        $last  = self::positiveRowId($rows[count($rows) - 1], $method, $idField, count($rows) - 1);
        if ($last > $first) {
            throw new RuntimeException('B24 ' . $method . ': страница пришла не по убыванию ' . $idField
                . ' — сортировка не применилась, крайний ID недоказуем.');
        }
        return ['total' => $total, 'maxId' => $first];
    }

    /**
     * Один недвусмысленный cursor независимо от регистра caller-параметра.
     *
     * Имена фильтра и сортировки — из ТОЙ ЖЕ таблицы CURSOR_PARAM_NAMES, что у
     * fetchAll()/listStats() (ревью 23.09.2026, dubli-1): у lists.element.get они
     * FILTER/ELEMENT_ORDER, и строчный `filter` портал молча отбрасывал — быстрая
     * выгрузка отдавала весь список под видом отобранного. Для остальных методов
     * параметры идут как есть.
     */
    private static function batchedPageParams(array $params, int $start, string $method = ''): array {
        foreach (array_keys($params) as $key) {
            if (is_string($key) && strcasecmp($key, 'start') === 0) unset($params[$key]);
        }
        $m = strtolower(trim($method));
        foreach (self::CURSOR_PARAM_NAMES as $prefix => $imena) {
            if (strpos($m, $prefix) !== 0) continue;
            foreach (['filter', 'order'] as $vid) {
                $slito = [];
                foreach (array_keys($params) as $key) {
                    if (!is_string($key) || strcasecmp($key, $vid) !== 0 || $key === $imena[$vid]) continue;
                    if (is_array($params[$key])) $slito = array_merge($slito, $params[$key]);
                    unset($params[$key]);
                }
                if ($slito !== []) {
                    $params[$imena[$vid]] = array_merge(
                        is_array($params[$imena[$vid]] ?? null) ? $params[$imena[$vid]] : [], $slito);
                }
            }
            break;
        }
        $params['start'] = $start;
        return $params;
    }

    /** @return array<int|string, true> */
    private static function rememberUniqueRows(
        array $rows,
        string $method,
        string $idField,
        array $seen = []
    ): array {
        foreach ($rows as $pos => $row) {
            $id = self::positiveRowId($row, $method, $idField, $pos);
            if (isset($seen[$id])) {
                throw new RuntimeException('B24 ' . $method . ': ID ' . $id
                    . ' пришёл повторно — пагинация пропустила или продублировала записи.');
            }
            $seen[$id] = true;
        }
        return $seen;
    }

    /** @return array{0: array, 1: array<int|string, true>} */
    private static function validateBatchedPage(
        array $raw,
        int $expected,
        string $method,
        string $idField,
        array $seen
    ): array {
        $rows = self::unwrapList($raw);
        if ($rows === null || count($rows) !== $expected) {
            $actual = $rows === null ? 'нет списка' : (string)count($rows);
            throw new RuntimeException('B24 ' . $method . ': страница вернула ' . $actual
                . ' вместо ' . $expected . ' записей — выборка неполна.');
        }
        return [$rows, self::rememberUniqueRows($rows, $method, $idField, $seen)];
    }

    /**
     * Достать записи по списку id одним пакетом.
     *
     * Типовая ошибка — цикл `foreach ($ids as $id) $b24->call('crm.item.get')`:
     * на трёхстах сделках это триста запросов и упор в лимит портала.
     *
     * Пятый аргумент — явное согласие принять частичный ответ: ошибки
     * вернутся в переданную переменную. Если аргумент опущен, любая ошибка
     * бросает исключение: partial map не выдаётся за полную.
     *
     * @return array id => запись
     */
    public function loadByIds(string $method, array $ids, array $params = [], string $idField = 'id', ?array &$errors = null): array {
        $partialAccepted = func_num_args() >= 5;
        $out = [];
        $errors = [];
        $commands = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id <= 0) continue;
            $commands['i' . $id] = [$method, array_merge($params, [$idField => $id])];
        }
        if ($commands === []) return $out;

        $res = $this->batchAll($commands);
        // Частичный ответ — это факт, а не мелочь: раньше ошибки пакета здесь
        // молча выбрасывались, и вызывающий получал неполный список как полный.
        // Теперь они и возвращаются (пятым параметром), и попадают в журнал —
        // иначе «пропала часть сделок» невозможно объяснить постфактум.
        foreach ($res['errors'] as $key => $text) {
            $errors[(int)substr((string)$key, 1)] = $text;
        }
        if ($errors !== []) {
            logAppError('b24.loadByIds: ' . $method . ' — портал не отдал ' . count($errors)
                . ' из ' . count($commands) . ' записей: ' . maskSecrets(implode('; ', array_slice($errors, 0, 3))));
        }
        self::enforcePartialLoadContract($method, $errors, $partialAccepted);
        foreach ($res['result'] as $key => $value) {
            $id = (int)substr((string)$key, 1);
            // Разные семейства кладут запись по-разному: item / task / просто объект.
            $row = is_array($value) ? ($value['item'] ?? $value['task'] ?? $value) : $value;
            if ($id > 0) $out[$id] = $row;
        }
        return $out;
    }

    private static function enforcePartialLoadContract(string $method, array $errors, bool $partialAccepted): void {
        if ($errors === [] || $partialAccepted) return;
        throw new RuntimeException('B24 ' . $method . ': портал не отдал ' . count($errors)
            . ' записей (' . maskSecrets(implode('; ', array_slice($errors, 0, 3)))
            . ') — частичный ответ запрещён. Передайте $errors пятым аргументом, '
            . 'только если вызывающий код умеет обработать partial map.');
    }

    /**
     * Имена сотрудников по их номерам — с кешем на время запроса.
     *
     * Нужно почти в каждом экране: портал отдаёт ответственного числом, а
     * человеку надо показать фамилию. Без общего места это превращается в
     * запрос user.get на каждую строку таблицы.
     *
     * @param array|null $unread сюда кладутся uid, чьё имя НЕ прочитано из-за сбоя
     *        портала (не путать с удалённым сотрудником — у того «сотрудник №N»).
     *        Выгрузка, которой нужна полнота, проверяет его и отказывается.
     * @return array uid => «Фамилия Имя» | «сотрудник №N» | «имя не прочитано (№N)»
     */
    public function userNames(array $uids, ?array &$unread = null): array {
        static $cache = [];
        $unread = [];
        $failed = [];
        $need = [];
        foreach (array_unique(array_map('intval', $uids)) as $uid) {
            if ($uid > 0 && !isset($cache[$uid])) $need[] = $uid;
        }
        if ($need !== []) {
            $errors = [];
            $rows = $this->loadByIds('user.get', $need, [], 'ID', $errors);
            foreach ($need as $uid) {
                $u = $rows[$uid] ?? null;
                // user.get отдаёт список, даже когда спрашивают одного.
                if (is_array($u) && isset($u[0])) $u = $u[0];
                $name = is_array($u)
                    ? trim((string)($u['LAST_NAME'] ?? '') . ' ' . (string)($u['NAME'] ?? ''))
                    : '';
                if ($name !== '') { $cache[$uid] = $name; continue; }
                // Имя не пришло. Если по этому сотруднику была ОШИБКА пакета —
                // заглушку не запоминаем: иначе сетевой сбой на полминуты
                // превращался в «сотрудник №17» до конца запроса и был неотличим
                // от удалённого пользователя.
                if (!isset($errors[$uid])) $cache[$uid] = 'сотрудник №' . $uid;
                else $failed[$uid] = true;
            }
        }
        // Сбой пакета НЕ выдаём за удалённого сотрудника (ревью 23.09.2026,
        // otkazy-3): прежде заглушка не запоминалась, но в ответ уходила та же
        // «сотрудник №17», и отчёт с выгрузкой выглядели правдоподобно верными.
        // Подпись отличима на экране, а список таких uid — во втором аргументе:
        // выгрузка по нему может отказаться формироваться.
        $out = [];
        foreach (array_unique(array_map('intval', $uids)) as $uid) {
            if ($uid <= 0) continue;
            if (isset($cache[$uid])) { $out[$uid] = $cache[$uid]; continue; }
            $out[$uid] = 'имя не прочитано (№' . $uid . ')';
            $unread[] = $uid;
        }
        return $out;
    }

    /**
     * Забрать ВЕСЬ список, а не первую страницу.
     *
     * Курсором по ID, а не через `start`: на списках, которые меняются во время
     * обхода (а они меняются всегда — люди работают), постраничный `start`
     * пропускает и дублирует записи. Фильтр `>ID` от такого не страдает.
     *
     * Метод должен поддерживать `order` и `filter` — это все `crm.item.list`,
     * `crm.*.list`, `tasks.task.list` (там свои имена полей — см. $idField).
     *
     * @param string|null $idField Имя поля идентификатора В ЗАПРОСЕ (order/filter).
     *        null — определить по имени метода (см. defaultIdField()). Регистр здесь
     *        не косметика: у `crm.item.*` поля camelCase, и портал отвечает на
     *        прописное `ID` отказом INVALID_ARG_VALUE «field 'ID' is not allowed
     *        in order» — выборка не приходит вовсе.
     * @param int $maxPages Предохранитель от бесконечного цикла на чужой ошибке.
     */
    public function fetchAll(string $method, array $params = [], ?string $idField = null, int $maxPages = 500): array {
        self::assertCursorSupported($method);
        $idField = self::resolveIdField($method, $idField);
        if ($maxPages < 1) {
            throw new InvalidArgumentException('B24 fetchAll: maxPages должен быть больше нуля');
        }
        $items = [];
        $lastId = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $p = self::cursorPageParams($params, $method, $idField, $lastId);
            $r = $this->call($method, $p);
            if (isset($r['error'])) {
                throw new RuntimeException('B24 ' . $method . ': ' . (string)($r['error_description'] ?? $r['error']));
            }
            $batch = self::unwrapList($r['result'] ?? []);
            if ($batch === null) {
                // Ответ есть, а списка в нём нет: метод отдаёт данные в форме,
                // которой мы не знаем. Молчать нельзя — вызывающий примет пустоту
                // за «записей нет» и, например, снимет признак у всех остальных.
                throw new RuntimeException('B24 ' . $method . ': в ответе нет списка — '
                    . 'этот метод отдаёт данные иначе. Разберите ответ сами через call().');
            }
            if ($batch === []) break;
            // Портал мог проигнорировать filter/order или отдать дубль. Раньше
            // курсор не двигался, а одна страница дописывалась до maxPages. Сначала
            // валидируем её целиком и только потом добавляем в итог.
            $lastId = self::cursorPageLastId($batch, $method, $idField, $lastId);
            foreach ($batch as $row) $items[] = $row;
            if (count($batch) < 50) break;      // страница неполная — это конец списка
            if ($page === $maxPages - 1) {
                // Дошли до предохранителя. Молча вернуть, что успели, нельзя:
                // вызывающий примет часть списка за весь и, например, снимет
                // признак у «всех остальных» записей.
                throw new RuntimeException('B24 ' . $method . ': выборка НЕ полная — уперлись в предел '
                    . $maxPages . ' страниц. Сузьте фильтр или поднимите предел осознанно.');
            }
        }
        return $items;
    }

    /**
     * Параметры одной курсорной страницы.
     *
     * `fetchAll()` обещает ВЕСЬ список, поэтому пользовательский `limit` здесь
     * не является допустимым ограничителем результата. Раньше `limit=10`
     * доходил до портала, а условие конца всё равно сравнивало страницу с 50:
     * первые десять строк молча выдавались за полную выборку.
     */
    private static function cursorPageParams(array $params, string $method, string $idField, int $lastId): array {
        $p = $params;
        // Имена параметров берём у метода, а не «как принято»: у lists.element.get
        // сортировка называется ELEMENT_ORDER, у sonet_group.get — ORDER, фильтр у
        // обоих — FILTER. Метод, курсор которому выразить нечем (user.get), здесь
        // же отказывает — до похода в сеть (K4-41, круг 4).
        $imena = self::cursorParamNames($method);
        // Разные методы/обёртки встречаются и с lower-, и с upper-case ключом.
        // Не передаём ни один: штатный размер страницы Б24 — 50, именно его
        // использует доказательство конца списка ниже.
        //
        // То же и с ПРОПИСНЫМИ копиями наших ключей. Примеры Б24 и старый код
        // сплошь пишут FILTER/ORDER/START, портал понимает оба написания — и,
        // оставив их рядом со своими строчными, мы отправляли бы запрос с ДВУМЯ
        // фильтрами сразу. Какой из них победит, зависит от метода: выборка молча
        // оказывается не той, что просили, а исключения нет — ошибка проявится
        // где-то дальше, на «пропавших» записях. Содержимое пользовательского
        // фильтра при этом не теряем: забираем оба написания себе.
        $userFilter = array_merge(
            is_array($params['FILTER'] ?? null) ? $params['FILTER'] : [],
            is_array($params['filter'] ?? null) ? $params['filter'] : []
        );
        // НИЖНЮЮ ГРАНИЦУ ВЫЗЫВАЮЩЕГО ЗАБИРАЕМ СЕБЕ, А НЕ ЗАТИРАЕМ КУРСОРОМ.
        //
        // «Дай сделки новее N» — обычная дельта-выборка, и приходит она тем же
        // ключом, что и наш курсор ('>ID'). Рядом с курсором такой ключ жить не
        // может, а прежнее слияние справа затирало границу нулём на ПЕРВОЙ
        // странице: вызывающий получал ВЕСЬ список и принимал его за дельту —
        // молча, без исключения и без строки в журнале (K4-10, круг 4). Поэтому
        // ключ снимаем по всем написаниям, а его значение принимаем за старт
        // курсора: смысл условия сохранён, движение курсора не сломано. То же
        // решение уже принято в www/api/fetch-all-compat.php — держим их в паре.
        foreach ([$idField, strtoupper($idField), strtolower($idField)] as $pole) {
            $klyuchKursora = '>' . $pole;
            if (!array_key_exists($klyuchKursora, $userFilter)) continue;
            $lastId = max($lastId, (int)$userFilter[$klyuchKursora]);
            unset($userFilter[$klyuchKursora]);
        }
        // Порядок пользователя отбрасываем осознанно (и раньше отбрасывали, просто
        // молча пропуская прописную копию): курсор идёт строго по возрастанию
        // идентификатора, любая другая сортировка делает выборку неполной.
        //
        // Снимаем ОБА написания и своё имя параметра: у lists.element.get это
        // ELEMENT_ORDER, и оставленная рядом чужая сортировка ушла бы в портал
        // вторым, конфликтующим набором — ровно тем, от чего этот unset и заведён.
        unset(
            $p['limit'], $p['LIMIT'], $p['ORDER'], $p['FILTER'], $p['START'],
            $p['order'], $p['filter'], $p[$imena['order']], $p[$imena['filter']]
        );
        $p[$imena['order']] = [$idField => 'ASC'];
        // Условие курсора — ПОСЛЕДНИМ ключом фильтра, как и раньше. Коллизии по
        // этому ключу здесь уже нет (границу вызывающего сняли выше и вобрали в
        // $lastId), поэтому на корректность порядок не влияет вовсе — и менять
        // его нельзя: заглушки портала в проектах и в наборах шаблона ищут курсор
        // как ПОСЛЕДНИЙ ключ, содержащий «ID», и при обратном порядке принимают
        // за курсор чужое условие вроде 'ENTITY_ID'. Возврат прежнего дефекта
        // ловит не порядок ключей, а забор в tests/b24_test.php: без снятия
        // границы выборка краснеет при ЛЮБОМ порядке.
        $p[$imena['filter']] = array_merge($userFilter, ['>' . $idField => $lastId]);
        $p['start']  = -1;                 // портал не считает общее количество — так быстрее
        return $p;
    }

    private static function cursorPageLastId(array $rows, string $method, string $idField, int $lastId): int {
        $next = $lastId;
        foreach ($rows as $pos => $row) {
            $id = self::positiveRowId($row, $method, $idField, $pos);
            if ($id <= $next) {
                throw new RuntimeException('B24 ' . $method . ': курсор ' . $idField
                    . ' не двигается (' . $id . ' после ' . $next . ') — выборка неполна.');
            }
            $next = $id;
        }
        return $next;
    }

    private static function positiveRowId($row, string $method, string $idField, $position): int {
        $lower = strtolower($idField);
        $upper = strtoupper($idField);
        $raw = is_array($row)
            ? ($row[$idField] ?? $row[$lower] ?? $row[$upper] ?? null)
            : null;
        $valid = is_int($raw) ? $raw > 0 : (is_string($raw) && ctype_digit($raw) && (int)$raw > 0);
        if (!$valid) {
            throw new RuntimeException('B24 ' . $method . ': у записи ' . (string)$position
                . ' нет положительного ' . $idField . ' — полноту выборки нельзя проверить.');
        }
        return (int)$raw;
    }

    /**
     * Повторять ли вызов при таком ответе портала?
     *
     * Отдельной функцией — чтобы поведение можно было проверить таблицей кодов,
     * не выходя в сеть. Повторяем ровно то, что портал сам просит повторить:
     * исчерпанную корзину запросов, превышение времени операции и любую 5xx.
     * На хостинге адрес общий для всех наших приложений, поэтому корзину нам
     * может выбрать соседний проект — без повтора это выглядит как «половина
     * данных пропала».
     */
    public static function isRetryable(int $httpCode, string $error, bool $safeToRepeat = true): bool {
        $error = strtoupper($error);
        // Повтор того же запроса не уменьшит тело. Для мутации он вдобавок
        // опасен: портал мог выполнить эффект до того, как мы оборвали ответ.
        if ($error === 'RESPONSE_TOO_LARGE') return false;
        // Ручная блокировка REST у портала (503 OVERLOAD_LIMIT). Снимается ТОЛЬКО
        // обращением в поддержку Битрикса — повторять бессмысленно: три попытки
        // с паузами лишь дольше держат воркер и добавляют нагрузки уже
        // перегруженному порталу. Документация: _includes/system-errors.md.
        if ($error === 'OVERLOAD_LIMIT') return false;
        // Накопленное время метода превысило лимит (429 OPERATION_TIME_LIMIT).
        // Повторять МОЖНО, но не через секунду: корзина считается минутами и
        // сбрасывается по `operating_reset_at` (limits.md: корзина живёт 10 минут).
        // Наши паузы 1+2+4 с гарантированно упираются в ту же блокировку — три
        // сожжённые попытки, +7 секунд ожидания человеку и лишний расход
        // интенсивности. Честный отказ здесь полезнее: вызывающий покажет
        // «метод временно заблокирован лимитом времени» и вернётся позже.
        if ($error === 'OPERATION_TIME_LIMIT') return false;
        // Портал отказал ДО выполнения — повторять безопасно всегда: работа не
        // начиналась, корзина запросов просто исчерпана.
        if ($error === 'QUERY_LIMIT_EXCEEDED') return true;
        if ($httpCode === 429) return true;
        // А вот оборванная связь и 5xx — случай другой: запрос мог дойти и
        // выполниться, потерялся только ответ. Слепой повтор здесь создаёт
        // вторую сделку и второй счёт. Повторяем только то, что заведомо ничего
        // не меняет (чтение) либо помечено вызывающим как безопасное к повтору.
        if (!$safeToRepeat) return false;
        return $error === 'HTTP_ERROR' || $httpCode >= 500;
    }

    /**
     * Меняет ли метод данные в портале? Читающие можно повторять свободно,
     * изменяющие — нет (см. isRetryable).
     */
    public static function isReadOnlyMethod(string $method): bool {
        $m = strtolower(trim($method));
        // Только КОНЕЧНЫЙ глагол. Проверка по подстроке (`.list`/`.get`
        // где угодно) считала бы вымышленный/новый `crm.item.list.update`
        // чтением и разрешила повтор после потерянного ответа — для неизвестного
        // метода безопасный дефолт обязан быть «мутация, не повторять».
        // info/current/profile/scope — НЕ глаголы: они безопасны только у
        // точных штатных singleton-методов. Иначе коробочный custom
        // `acme.invoice.check` после потерянного 502 выполнялся второй раз.
        if (in_array($m, ['app.info', 'user.current', 'profile', 'scope'], true)) return true;
        return (bool)preg_match('~(\.|^)(list|get|fields|search)$~', $m);
    }

    /**
     * Может ли этот вызов изменить данные портала?
     *
     * Пакет разбираем по составу: `batch` из одних чтений ничего не меняет, и
     * помечать его изменением — значит после каждого сбоя гнать человека
     * проверять портал там, где проверять нечего. Предупреждение, которое горит
     * всегда, перестают читать. Всё непонятное (пакет без разбираемых команд,
     * незнакомый метод) считаем изменением: ошибиться можно только в эту сторону.
     *
     * @param mixed[] $params
     */
    private static function callChangesPortal(string $method, array $params): bool {
        if (strtolower(trim($method)) !== 'batch') return !self::isReadOnlyMethod($method);
        $cmd = $params['cmd'] ?? null;
        if (!is_array($cmd) || $cmd === []) return true;
        foreach ($cmd as $one) {
            if (!is_string($one)) return true;
            $name = trim(explode('?', $one, 2)[0]);
            if ($name === '' || !self::isReadOnlyMethod($name)) return true;
        }
        return false;
    }

    /**
     * Сколько всего секунд один call() имеет право занимать воркер.
     *
     * Раньше бюджет был только у попытки (30 с), а попыток четыре плюс паузы
     * 1+2+4 — то есть один вызов мог держать PHP-воркер до 127 секунд, вчетверо
     * дольше, чем ждёт фронт, и впятеро дольше окна lifecycle-замка. В вебе это
     * выглядит как «приложение зависло», а на хостинге с единицами воркеров
     * несколько таких вызовов кладут приложение целиком. В cron спешить некуда,
     * поэтому там бюджет больше.
     */
    /**
     * Множитель СНА между повторами. В бою всегда 1: переменной окружения на
     * хостинге нет, а неверное значение игнорируется.
     *
     * Зачем. Испытания гоняют воркер и клиент против заведомо недоступного
     * портала, и каждый такой прогон честно отбывал 1+2+4 секунды — замер
     * 26.08.2026: 145 секунд из 177 секунд всего круга самопроверки уходило на
     * этот сон, девять проверок по ~14,7 с каждая. Предмет тех проверок —
     * очередь, DLQ и решение о повторе, а не длительность паузы.
     *
     * Множитель уменьшает ТОЛЬКО сон: число попыток, условие повтора и бюджет
     * вызова он не трогает, поэтому проверка, которой нужна настоящая пауза,
     * ставит у себя BXAPP_RETRY_PAUSE_SCALE=1 и получает прежнее поведение.
     */
    private static function retryPauseScale(): float {
        $raw = (string)getenv('BXAPP_RETRY_PAUSE_SCALE');
        if ($raw === '' || !is_numeric($raw)) return 1.0;
        $v = (float)$raw;
        return ($v > 0 && $v <= 1) ? $v : 1.0;
    }

    private static function callBudgetSeconds(): int {
        return PHP_SAPI === 'cli' ? 90 : 25;
    }

    /**
     * Метка ОДНОЙ операции с порталом.
     *
     * Зачем. В журнале один логический вызов оставляет несколько записей: сам
     * запрос, повтор после 503, обновление токена посередине, разбор ответа
     * пакета. Без общей метки они выглядят как отдельные события, и вопрос
     * «что происходило в 14:07» разбирается вручную по времени. Метка короткая и
     * случайная: в ней нет ни адреса портала, ни токена, ни данных запроса.
     */
    private static function newCallRef(): string {
        try {
            return substr(bin2hex(random_bytes(4)), 0, 8);
        } catch (Throwable $e) {
            // Источник случайности недоступен — метка всё равно нужна, но она не
            // должна выглядеть уникальной, когда таковой не является.
            return 'noent' . substr((string)microtime(true), -3);
        }
    }

    private function doCall(string $method, array $params, ?bool $safeToRepeat = null): array {
        // По умолчанию безопасными к повтору считаем только читающие вызовы.
        // Изменяющий повторяется лишь по явному разрешению вызывающего (он знает,
        // идемпотентна ли операция) — см. call($method, $params, true).
        //
        // Судим по СОСТАВУ, а не по имени: у пакета имя метода всегда «batch», и
        // проверка по имени объявляла мутацией даже пакет из одних чтений. Разовый
        // 502 такую выборку обрывал молча — для человека это «половина данных
        // пропала», хотя повторить её было безопасно (R3-011, тот же корень).
        // callChangesPortal() разбирает cmd и всё непонятное считает мутацией.
        $safe = $safeToRepeat ?? !self::callChangesPortal($method, $params);
        $deadline = microtime(true) + self::callBudgetSeconds();
        for ($try = 0; ; $try++) {
            $left = (int)ceil($deadline - microtime(true));
            [$code, $j] = $this->rawCall($method, $params, max(1, $left));
            // Портал команду ВЫПОЛНИЛ, а ответ до нас не дошёл — самый дорогой
            // из отказов: слепой повтор создаёт вторую сделку и второй счёт.
            // Испытания включают эту точку и смотрят, что делает код дальше.
            if (function_exists('failpoint')) failpoint('b24.after_call_before_answer');
            if (!self::isRetryable($code, (string)($j['error'] ?? ''), $safe) || $try >= 3) return $j;
            // 1 → 2 → 4 секунды. Дольше ждать в веб-запросе нельзя: на хостинге
            // воркеров считанные штуки, и очередь ожидающих сама станет отказом.
            $pause = pow(2, $try) * self::retryPauseScale();
            // Повтор начинаем, только если он успеет уложиться в общий бюджет:
            // иначе воркер простаивает в паузе, чтобы затем всё равно упереться
            // в таймаут. Отдаём последний ответ портала как есть.
            if (microtime(true) + $pause >= $deadline) return $j;
            usleep((int)($pause * 1000000));
        }
    }

    /**
     * ПОДМЕНЯЕМЫЙ ТРАНСПОРТ ПОРТАЛА — только для испытаний, без сети.
     *
     * ЗАЧЕМ. Поведение вокруг сетевого вызова (повторы, «ответ не дошёл»,
     * разбор ошибок портала, учёт нагрузки) — самая дорогая часть клиента, и
     * проверить её живым порталом нельзя: бой клиента не полигон, а тестового
     * портала под рукой может не быть. Без подмены эти ветки проверяются
     * грепом кода, то есть не проверяются.
     *
     * ПОЧЕМУ ЭТО НЕ ДЫРА. Работает при двух условиях сразу: задан
     * `BXAPP_DATA_ROOT` (то есть стор подменён на испытательный) И в
     * `$GLOBALS['__b24_transport']` лежит функция. На боевом сервере первого
     * условия нет, включить снаружи нечем — ровно как у точек отказа.
     *
     * @return array|null [код, ответ] — либо null, и тогда идёт обычный curl.
     */
    private function testTransport(string $what, array $params): ?array {
        $fn = $GLOBALS['__b24_transport'] ?? null;
        if (!is_callable($fn)) return null;
        $data = getenv('BXAPP_DATA_ROOT');
        if ($data === false || $data === '') return null;
        $answer = $fn($what, $params);
        if (!is_array($answer)) return [0, ['error' => 'http_error']];
        $code = isset($answer['__code']) ? (int)$answer['__code'] : 200;
        unset($answer['__code']);
        return [$code, $answer];
    }

    /**
     * ТЕМП ЗАПРОСОВ ПО ТАРИФУ ПОРТАЛА.
     *
     * Портал ограничивает частоту обращений, и пороги зависят от тарифа:
     * Энтерпрайз — 5 запросов в секунду, прочие — 2 (limits.md). Приложение,
     * которое об этом не знает, ведёт себя одинаково на обоих: на обычном
     * тарифе упирается в `QUERY_LIMIT_EXCEEDED` и теряет половину обхода, на
     * Энтерпрайзе работает вдвое медленнее, чем позволено.
     *
     * Держим равномерный темп: если с прошлого запроса прошло меньше, чем
     * положено при этом тарифе, ждём остаток. Ждём КОРОТКО и только внутри
     * процесса — это не замена пакетам, а страховка для того, что пакетами не
     * закрывается (одиночные мутации, повторы, чтение по одному).
     *
     * Тариф неизвестен — берём самый строгий предел: ошибиться в эту сторону
     * значит работать медленнее, в другую — получить блокировку у клиента.
     */
    private function throttleByPlan(): void {
        if (!function_exists('planCurrentRate')) return;      // модуль не подключён
        $rate = planCurrentRate();
        if ($rate <= 0) return;
        $interval = 1.0 / $rate;
        // СНАЧАЛА ОБЩАЯ ОЧЕРЕДЬ. Предел портала считает запросы приложения, а не
        // процесса: веб-запрос, фоновый обработчик и воркер по расписанию идут
        // разом, и со счётчиком «у каждого свой» приложение превышает предел
        // кратно числу процессов. Общая отметка живёт в файле под flock.
        // Не получилось (файла нет, очередь длиннее предохранителя) — падать
        // нельзя: держим темп хотя бы внутри своего процесса, как раньше.
        if (function_exists('planWaitTurn') && planWaitTurn($interval)) {
            self::$lastRequestAt = microtime(true);
            return;
        }
        $now = microtime(true);
        $proshlo = $now - self::$lastRequestAt;
        if (self::$lastRequestAt > 0 && $proshlo < $interval) {
            // Потолок ожидания зависит от того, кто ждёт: в вебе за экраном
            // человек (полсекунды), в кроне — никто. Прежде здесь стояло жёсткое
            // 0.5 для обоих, и фоновая работа, оставшись без общей очереди,
            // держала темп вдвое-втрое выше положенного (находка ревью 08.08.2026).
            $potolok = function_exists('planWaitCap') ? planWaitCap() : 0.5;
            $zhdat = min($interval - $proshlo, $potolok);
            usleep((int)($zhdat * 1000000));
            $now = microtime(true);
        }
        self::$lastRequestAt = $now;
    }

    /** Когда этот процесс в последний раз обращался к порталу. */
    private static $lastRequestAt = 0.0;

    /** Один сетевой вызов. Возвращает [HTTP-код, разобранный ответ]. */
    private function rawCall(string $method, array $params, ?int $timeout = null): array {
        if ($this->webhookBase !== '') {
            // Постоянный ключ уже содержит и адрес портала, и саму авторизацию.
            // Поле `auth` при этом НЕ добавляем: портал берёт права из ключа, а
            // лишний параметр с чужим токеном отправлял бы наружу то, что к
            // этому каналу отношения не имеет.
            $endpoint = $this->webhookBase;
        } else {
            // Нормализуем и на чтении: в состоянии, записанном прежней версией, мог
            // остаться адрес с портом или «хвостом» — по нему уходит токен.
            $endpoint = normalizeRestEndpoint((string)($this->tokens['client_endpoint'] ?? ''));
            if ($endpoint === '') $endpoint = 'https://' . (string)($this->tokens['domain'] ?? '') . '/rest/';
        }
        $url = rtrim($endpoint, '/') . '/' . $method . '.json';
        if ($this->webhookBase === '') $params['auth'] = $this->tokens['access_token'] ?? '';
        // Темп держим ДО отправки и одинаково для боевого пути и для испытаний:
        // иначе испытания проверяли бы не тот код, что идёт в бой.
        $this->throttleByPlan();
        $podmena = $this->testTransport($method, $params);
        if ($podmena !== null) {
            // Учёт нагрузки портала обязан работать и на подменённом ответе:
            // иначе испытания проверяли бы не тот код, что идёт в бой.
            if (is_array($podmena[1])) $this->rememberPortalLoad($method, $podmena[1]);
            return $podmena;
        }
        $ch = curl_init($url);
        $buffer = new B24ResponseBuffer(B24_RESPONSE_MAX_BYTES);
        curl_setopt_array($ch, array_replace([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            // Таймаут попытки не больше того, что осталось от общего бюджета
            // вызова, и отдельный предел на установление соединения: без него
            // недоступный хост съедал весь таймаут молча.
            CURLOPT_TIMEOUT        => max(1, min(30, $timeout ?? 30)),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ], b24BoundedCurlOptions($buffer)));
        $ok   = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($buffer->tooLarge()) return [$code, b24ResponseTooLargeError()];
        if ($ok === false) return [0, ['error' => 'http_error']];
        $raw = $buffer->body();
        $j = json_decode($raw, true);
        if (is_array($j)) $this->rememberPortalLoad($method, $j);
        return [$code, is_array($j) ? $j : ['error' => 'bad_json', 'raw' => $raw]];
    }

    /**
     * Запомнить, сколько ресурсов портала мы уже потратили.
     *
     * Портал кладёт это в объект `time` КАЖДОГО ответа (data-types.md): `operating` —
     * накопленное время выполнения ЭТОГО метода, `operating_reset_at` — когда счётчик
     * уменьшится. Переполнение даёт блокировку метода на 10 минут (limits.md), и узнать
     * о ней постфактум — значит узнать поздно: половина обхода уже не сделана.
     * Поэтому цифры мы храним и показываем на экране «Состояние», а не выбрасываем.
     */
    private function rememberPortalLoad(string $method, array $response): void {
        $time = $response['time'] ?? null;
        if (!is_array($time) || !isset($time['operating'])) return;
        $this->portalLoad = [
            'method'     => $method,
            'operating'  => (float)$time['operating'],
            'reset_at'   => (int)($time['operating_reset_at'] ?? 0),
            'at'         => time(),
        ];
    }

    /** Последняя известная нагрузка на портал. Пусто — портал ещё ничего не сообщал. */
    public function portalLoad(): array {
        return $this->portalLoad;
    }

    /**
     * Обновить протухший access_token.
     *
     * Обновление идёт ПОД ЗАМКОМ файла токенов, и первым делом состояние
     * перечитывается с диска. Почему так: портал открывает несколько запросов
     * разом, и раньше оба видели «токен протух», оба меняли один и тот же
     * одноразовый refresh_token, а второй ответ затирал первый — приложение
     * теряло доступ и требовало переустановки. Теперь второй запрос, дождавшись
     * замка, видит уже обновлённые токены соседа и в сеть не идёт вовсе.
     */
    private function refreshAccessToken(): void {
        $before = (string)($this->tokens['access_token'] ?? '');
        $expectedGeneration = appInstallGenerationFrom($this->loaded);
        if ($expectedGeneration === '') {
            throw new InstallGenerationMismatchException('OAuth refresh без поколения установки');
        }
        // 25 секунд: под этим замком идёт сетевой обмен с oauth (до 20 с), и
        // ждущий сосед не должен упасть по таймауту замка раньше, чем обмен
        // закончится — иначе вместо «дождался и взял готовое» он получит ошибку.
        $updated = appWithLifecycleReadLock(function () use ($before, $expectedGeneration): array {
            return storeUpdate(B24_TOKENS_FILE, function (array $onDisk) use ($before, $expectedGeneration): array {
                if (!hash_equals(appInstallGenerationFrom($onDisk), $expectedGeneration)
                    || !empty($onDisk['cleanup_pending']) || !empty($onDisk['uninstalled_at'])) {
                    throw new InstallGenerationMismatchException('OAuth refresh относится к прежней установке');
                }
                // Сосед успел обновить, пока мы ждали замок — просто берём его результат.
                $diskToken = (string)($onDisk['access_token'] ?? '');
                if ($diskToken !== '' && $diskToken !== $before) return $onDisk;

                $refresh = (string)($onDisk['refresh_token'] ?? $this->tokens['refresh_token'] ?? '');
                $data = $this->doRefreshRequest($refresh);
                $expires = (int)($data['expires_in'] ?? 3600);
                $onDisk['access_token']  = $data['access_token'];
                $onDisk['refresh_token'] = $data['refresh_token'] ?? $refresh;
                $onDisk['expires_at']    = time() + $expires - 60;
                $onDisk['expires_in']    = $expires;
                return $onDisk;
            }, 25.0);
        }, 25.0);
        $this->tokens = $updated;
        // Refresh уже записал эти поля. Если оставить loaded прежним, следующий
        // persist сочтёт их своей новой правкой и откатит более свежий refresh
        // соседнего запроса обратно к этому значению.
        $this->loaded = $updated;
    }

    /** Сетевой запрос refresh к oauth нашим client_id/secret. Бросает при curl/невалидном ответе.
     *  Общий для авто-refresh и межпортального verifyAndAdopt (#app-migration). */
    private function doRefreshRequest(string $refreshToken): array {
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        // Запасной адрес — тот же сервер авторизации, что и в env.php. Портал сюда
        // подставлять НЕЛЬЗЯ ни при каких условиях: client_secret приложения он не
        // хранит и обновление отвергает.
        $podmena = $this->testTransport('__oauth_refresh', ['refresh_token' => $refreshToken]);
        if ($podmena !== null) {
            $data = is_array($podmena[1]) ? $podmena[1] : [];
            if ((string)($data['error'] ?? '') === 'response_too_large') {
                throw new B24ResponseTooLargeException('B24 refresh: response_too_large');
            }
            if (empty($data['access_token'])) {
                throw new RuntimeException('B24 refresh: '
                    . oauthRefusalText((string)($data['error'] ?? ''),
                        (string)($data['error_description'] ?? '')));
            }
            return $data;
        }
        $ch = curl_init(defined('B24_OAUTH_URL') ? B24_OAUTH_URL : 'https://oauth.bitrix24.tech/oauth/token/');
        $buffer = new B24ResponseBuffer(B24_RESPONSE_MAX_BYTES);
        curl_setopt_array($ch, array_replace([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            // Редирект НЕ идём. В этом запросе уходят client_secret и refresh_token,
            // а перенаправление 307/308 сохраняет тело POST — то есть один ответ
            // сервера авторизации (ошибочный или подменённый) отправил бы наши
            // секреты на чужой хост. Законный ответ oauth редиректов не содержит.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
            // Отдельный потолок на УСТАНОВКУ соединения. Общий таймаут его не
            // заменяет: пока сервер авторизации не отвечает на само подключение,
            // воркер занят все двадцать секунд, и обновление токена — самая
            // частая операция приложения — держит их на каждом запросе.
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ], b24BoundedCurlOptions($buffer)));
        $ok = curl_exec($ch);
        $tooLarge = $buffer->tooLarge();
        $raw = $buffer->body();
        curl_close($ch);
        if ($tooLarge) throw new B24ResponseTooLargeException('B24 refresh: response_too_large');
        if ($ok === false) throw new RuntimeException('B24 refresh: curl error');
        $data = json_decode($raw, true) ?: [];
        if (empty($data['access_token'])) {
            // Причины отказа принципиально разные, и человек по ним делает РАЗНОЕ.
            // Пока текст был один на всех, экран «Состояние» одинаково говорил
            // «не удалось обновить токен» и при протухшем refresh (лечится
            // переустановкой за минуту), и при неверных кредах в конфиге
            // (переустановка не поможет вовсе).
            throw new RuntimeException('B24 refresh: '
                . oauthRefusalText((string)($data['error'] ?? ''),
                    (string)($data['error_description'] ?? '')));
        }
        return $data;
    }

    /**
     * Сохранить токены, не затирая параллельную правку.
     *
     * Пишем не «всё, что у меня в памяти», а только то, что этот запрос
     * реально изменил с момента чтения, — поверх свежего состояния с диска.
     * Иначе типичная пара «один запрос обновил протухший access_token, другой
     * в это же время отметил пройденную установку» заканчивалась потерей одного
     * из двух: кто записал вторым, тот и затёр. Слияние под замком, чтения и
     * записи между собой не переплетаются.
     */
    /**
     * Как класть изменённое поле поверх того, что уже на диске.
     *
     * Обычное значение — просто наше. А вот список, в который только ДОБАВЛЯЛИ
     * (типичный пример — отметки «этот сотрудник прошёл установку»), сливаем:
     * иначе два человека, открывшие приложение одновременно, затрут отметку друг
     * друга и один из них увидит экран установки повторно. Если из списка что-то
     * удаляли — это осознанный сброс, тогда наш вариант побеждает целиком.
     */
    private static function mergeField($base, $curr, $onDisk) {
        $isList = static function ($a): bool {
            if (!is_array($a)) return false;
            if (!$a) return true;   // пустой массив — тоже список; range(0,-1) вернул бы [0,-1]
            return array_keys($a) === range(0, count($a) - 1);
        };
        if (!$isList($base) || !$isList($curr) || !$isList($onDisk)) return $curr;
        if (array_diff($base, $curr)) return $curr;             // что-то удалили — не сливаем
        $res = $onDisk;
        foreach (array_diff($curr, $base) as $added) {
            if (!in_array($added, $res, true)) $res[] = $added;
        }
        return array_values($res);
    }

    /** installed / pending / uninstalled / empty — CAS сравнивает не только id поколения. */
    private static function installationState(array $tokens): string {
        return appInstallationPhaseFrom($tokens);
    }

    /**
     * Изменить migration-throttle, только если сетевой verify всё ещё относится
     * к текущей установке. Поздний fail/success прежнего портала ничего не пишет.
     */
    private static function updateMigrationVerifyThrottle(array $identity, bool $failed, int $window = 0): bool {
        $allowed = false;
        try {
            appWithMatchingInstallationReadLock($identity, static function () use ($failed, $window, &$allowed): void {
                storeUpdate(STATE_FILE, static function (array $state) use ($failed, $window, &$allowed): ?array {
                    if ($failed) {
                        $now = time();
                        $last = (int)($state['migrationVerifyAt'] ?? 0);
                        $fails = (int)($state['migrationVerifyFails'] ?? 0);
                        if ($fails >= 3 && $last > 0 && ($now - $last) < $window) return null;
                        $state['migrationVerifyAt'] = $now;
                        $state['migrationVerifyFails'] = $fails + 1;
                    } else {
                        unset($state['migrationVerifyFails'], $state['migrationVerifyAt']);
                    }
                    $allowed = true;
                    return $state;
                });
            });
            return $allowed;
        } catch (\Throwable $e) {
            // Не смогли зарезервировать попытку — внешнего вызова не будет.
            // Неизвестный или сменившийся state не оправдывает обход лимита.
            lifecycleDenyLog('migration: троттл проверки не обновлён — '
                . maskSecrets($e->getMessage()));
            return false;
        }
    }

    /**
     * Единственный путь намеренно заменить поколение установки.
     *
     * Обычный persist этого делать не умеет. Сравниваем ещё и фазу жизненного
     * цикла: запрос, загрузивший active A, не вправе заменить уже tombstone A.
     */
    private function replaceInstallationTokens(array $next, bool $resetPortalState = false): void {
        $base = $this->loaded;
        $baseGeneration = appInstallGenerationFrom($base);
        $baseState = self::installationState($base);
        $nextGeneration = appInstallGenerationFrom($next);
        if ($nextGeneration === '') {
            throw new LogicException('Новая установка без install_generation');
        }

        $generationChanged = !hash_equals($baseGeneration, $nextGeneration);

        // ПАМЯТЬ О ПРЕЖНИХ РЕГИСТРАЦИЯХ ТОГО ЖЕ ПОРТАЛА.
        //
        // Журнал и аудит фильтруются по поколению установки — правильно: строки
        // ЧУЖОГО портала администратору видеть нельзя. Но переустановка на том же
        // портале тоже меняет поколение, и весь прежний журнал разом исчезал с
        // экрана: расследовать «кому и когда выдали доступ» после переустановки
        // становилось нечем, хотя данные те же и портал тот же.
        //
        // Поэтому прежнее поколение запоминаем — но ТОЛЬКО когда портал не менялся
        // ($resetPortalState означает смену площадки, и там история не наследуется
        // вовсе). Держим последние десять: это глубина расследования, а не архив.
        if (!$resetPortalState && $generationChanged && $baseGeneration !== '') {
            $prior = $base['prior_generations'] ?? [];
            if (!is_array($prior)) $prior = [];
            $prior = array_values(array_filter(array_map('strval', $prior), static fn(string $g): bool => $g !== ''));
            array_unshift($prior, $baseGeneration);
            $next['prior_generations'] = array_slice(array_values(array_unique($prior)), 0, 10);
        }

        $written = appWithLifecycleWriteLock(function () use (
            $baseGeneration, $baseState, $next, $resetPortalState, $generationChanged
        ): array {
            // Этот объект мог загрузиться ДО того, как соседний процесс оставил
            // незавершённый transition, а затем ждать его lifecycle EX-lock.
            // Восстанавливаем диск сразу после получения EX и только потом
            // читаем токены либо строим план очистки: иначе план увидит
            // rollback-имя, recovery вернёт исходный journal, а stale-план его
            // уже не удалит при публикации нового поколения.
            appRecoverStateTransitionLocked();

            $verify = static function (array $onDisk) use ($baseGeneration, $baseState): void {
                $diskGeneration = appInstallGenerationFrom($onDisk);
                if (!hash_equals($diskGeneration, $baseGeneration)
                    || self::installationState($onDisk) !== $baseState
                    || self::installationState($onDisk) === 'pending') {
                    throw new InstallGenerationMismatchException('Установка изменилась до сохранения новых токенов');
                }
            };
            $next['saved_at'] = date('c');

            // Reset нескольких файлов и публикация новых токенов — одна process-
            // транзакция. Раньше reset шёл внутри mutator токенов: ошибка на
            // switches.php оставляла токены A активными, но settings/ACL/OPS A
            // уже были стёрты. Теперь все payload сначала готовы, а токены —
            // последний commit marker; SIGKILL восстанавливается по манифесту.
            if ($resetPortalState || ($generationChanged && $baseState === 'installed')) {
                $onDisk = storeRead(B24_TOKENS_FILE) ?? [];
                $verify($onDisk);
                if ($resetPortalState) {
                    // lifecycle EX уже взят; stable journal-lock держим от
                    // scandir до последнего rename, чтобы параллельный appLog
                    // не записал старое поколение в unlinked inode и не создал
                    // новый старый journal сразу после построения deletes.
                    return appWithJournalMutationLock(static function () use ($next): array {
                        $targets = appPortalBoundResetTargets();
                        // Карантинные копии повреждённого состояния — это данные
                        // ПРЕЖНЕГО портала, отложенные на разбор. При переезде они
                        // обязаны уехать вместе с журналом: иначе новый владелец
                        // получает на диске чужие настройки и список доступа без
                        // единого следа о том, откуда они взялись.
                        $deletes = array_merge(appJournalTransitionTargets(), appQuarantineFiles());
                        $targets[B24_TOKENS_FILE] = $next;
                        appCommitStateTransition($targets, $deletes);
                        return $next;
                    });
                }

                // Новая регистрация той же площадки не наследует sessions,
                // fingerprint и события, но сохраняет settings/ACL/OPS.
                $targets = appRegistrationBoundResetTargets();
                $targets[B24_TOKENS_FILE] = $next;
                appCommitStateTransition($targets, []);
                return $next;
            }

            // Обычная первая публикация меняет один файл и сохраняет штатный
            // per-file lock/CAS storeUpdate.
            return storeUpdate(B24_TOKENS_FILE,
                static function (array $onDisk) use ($verify, $next): array {
                    $verify($onDisk);
                    return $next;
                }, 25.0);
        }, 25.0);
        $this->tokens = $written;
        $this->loaded = $written;
        appBindLogGeneration(appInstallGenerationFrom($written));
    }

    public function persist(): void {
        $base = $this->loaded;
        $curr = $this->tokens;
        $expectedGeneration = appInstallGenerationFrom($base);
        if (appInstallGenerationFrom($curr) !== $expectedGeneration) {
            throw new LogicException('Обычный persist не может менять поколение установки');
        }
        // 25 секунд — столько же, сколько держит замок обновление токена (под ним
        // идёт сетевой обмен). С прежними пятью штатное открытие приложения
        // падало с ошибкой ровно в тот момент, когда сосед обновлял токен.
        $merged = appWithLifecycleReadLock(static function () use ($base, $curr, $expectedGeneration): array {
            return storeUpdate(B24_TOKENS_FILE,
                static function (array $onDisk) use ($base, $curr, $expectedGeneration): array {
                    if ($expectedGeneration === ''
                        || !hash_equals(appInstallGenerationFrom($onDisk), $expectedGeneration)
                        || !empty($onDisk['cleanup_pending']) || !empty($onDisk['uninstalled_at'])) {
                        throw new InstallGenerationMismatchException('Запрос относится к прежней установке');
                    }
                    foreach ($curr as $k => $v) {              // что этот запрос изменил или добавил
                        if (array_key_exists($k, $base) && $base[$k] === $v) continue;
                        $onDisk[$k] = self::mergeField($base[$k] ?? null, $v, $onDisk[$k] ?? null);
                    }
                    foreach ($base as $k => $v) {              // и что он осознанно удалил
                        if (!array_key_exists($k, $curr)) unset($onDisk[$k]);
                    }
                    $onDisk['saved_at'] = date('c');
                    return $onDisk;
                }, 25.0);
        }, 25.0);
        // Дальше в этом же запросе работаем от того, что реально на диске:
        // там могли появиться поля, записанные соседним запросом.
        $this->tokens = $merged;
        $this->loaded = $merged;
    }
}

// ── helpers ──────────────────────────────────────────────────────────────

/** Фабрика — глобальный экземпляр для install и API endpoint'ов. */
/**
 * Это имя — сервер авторизации Битрикса, а не портал.
 *
 * Различать их приходится потому, что коробка без своего сервера авторизации
 * присылает в установке только `SERVER_ENDPOINT` облачного сервера — своего
 * адреса там нет вовсе. Принять такой хост за портал нельзя: все REST-вызовы
 * ушли бы на сервер авторизации и вернули «метод не найден», а приложение
 * считало бы, что портал просто пуст.
 */
function b24IsAuthServerHost(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === '') return false;
    // Список намеренно короткий: действующий адрес сервера авторизации берётся
    // из настройки (там он и задаётся для коробки со своим OAuth), а перечислять
    // здесь исторические адреса нельзя — забор шаблона следит, чтобы устаревшие
    // имена не появлялись в клиенте даже упоминанием.
    $svoy = defined('B24_OAUTH_URL') ? parse_url((string)B24_OAUTH_URL, PHP_URL_HOST) : null;
    return is_string($svoy) && $svoy !== '' && $host === strtolower($svoy);
}

/**
 * Постоянный ключ-вебхук в проверенном виде — или '' , если канала нет.
 *
 * Форма проверяется строго, потому что этот адрес уходит в сеть ВМЕСТЕ с
 * секретом: чужой хост в настройке означал бы, что ключ портала клиента мы сами
 * отправляем постороннему. Поэтому только https, только имя хоста без логина,
 * пароля и нестандартного порта, и обязательный путь `/rest/<id>/<ключ>/` —
 * ровно тот, что выдаёт портал. Что угодно другое — канала нет: «непонятное»
 * здесь обязано означать «не ходить», а не «попробовать».
 *
 * Сам ключ в настройках приложения НЕ лежит: он приходит из приватного
 * хранилища рядом с кредами (env.php читает его оттуда), потому что env.php
 * переносится деплоем целиком и секретам там не место.
 */
function b24WebhookBase(): string {
    $raw = defined('B24_WEBHOOK_URL') ? trim((string)B24_WEBHOOK_URL) : '';
    if ($raw === '') return '';
    $p = parse_url($raw);
    if (!is_array($p)) return '';
    if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return '';
    if (isset($p['user']) || isset($p['pass'])) return '';
    if (isset($p['port']) && (int)$p['port'] !== 443) return '';
    $host = (string)($p['host'] ?? '');
    if ($host === '' || preg_match('~^[a-z0-9][a-z0-9.-]*$~iD', $host) !== 1) return '';
    $path = (string)($p['path'] ?? '');
    if (preg_match('~^/rest/([0-9]+)/([A-Za-z0-9]+)/?$~D', $path, $m) !== 1) return '';
    return 'https://' . strtolower($host) . '/rest/' . $m[1] . '/' . $m[2];
}

function b24(): B24 {
    $cid = defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '';
    $sec = defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '';
    $b24 = new B24($cid, $sec);
    if (!$b24->hasTokens()) {
        throw new RuntimeException('Нет B24-токенов. Открой приложение из портала Bitrix24.');
    }
    return $b24;
}

/**
 * Штатные события жизненного цикла приложения: ONAPPINSTALL и ONAPPUPDATE.
 *
 * ONAPPINSTALL не несёт generation/порядкового номера, поэтому для UI-шаблона
 * может лишь одноразово заполнить пустой application_token при точном совпадении
 * уже сохранённой browser OAuth-пары; завершение подтверждает app.info=true.
 * ONAPPUPDATE, напротив, усваивает подтверждённую порталом ротацию auth/key;
 * раньше приёмник сравнивал новый application_token со старым и отвечал 403.
 *
 * Подлинность доказывается тем же способом, что и везде в шаблоне: присланный
 * access_token спрашивает НАШ портал (домен из сохранённых токенов, не из тела
 * события — оно подделываемо). Ответит только действующий токен этого портала.
 * Дополнительно требуем, чтобы за токеном стоял администратор портала или
 * владелец приложения: установщиком бывает и не-админ, и его законную
 * переустановку отбивать нельзя.
 *
 * @return array|null null — событие не про жизненный цикл, разбирайте дальше.
 *                    Иначе ['ok'=>bool,'code'=>int,'error'=>string,'adopted'=>bool].
 */
/**
 * Запись об отказе lifecycle-события — не чаще пяти раз в минуту.
 *
 * Адрес обработчика публичен, а строка в журнал шла на КАЖДЫЙ неподтверждённый
 * POST: поток мусора вымывал из дневного журнала настоящую диагностику и аудит.
 * Тот же приём уже стоит у отказов гейта (api/http.php) и у обычных событий.
 */
function lifecycleDenyLog(string $text): void {
    require_once __DIR__ . '/http.php';
    if (apiRateAllow('lifecycle-denied-log', 5, 60)) logAppError($text);
}

/**
 * Можно ли сейчас платить за проверку события походом в портал.
 *
 * Общего счётчика здесь быть не должно: Битрикс события НЕ переприсылает
 * (api-reference/events/index.md — «Повторных отправок нет»), поэтому поток
 * мусора закрыл бы окно единственному настоящему ONAPPUPDATE — приложение молча
 * осталось бы со старым ключом и потеряло доступ к порталу до переустановки.
 * Считаем на адрес отправителя: чужой поток выжигает свой лимит, адрес портала
 * при этом свободен. Аварийный общий потолок оставлен НАМНОГО выше личного —
 * он ловит только распределённый поток, а не соседа.
 */
function lifecycleVerifyAllowed(): bool {
    require_once __DIR__ . '/http.php';
    // Атомарная цепочка сохраняет global-first preflight без прежнего побочного
    // эффекта: общий bucket получает hit только у запроса, прошедшего IP ceiling.
    return apiRateAllowChain([
        ['lifecycle-verify-all', 240],
        ['lifecycle-verify-ip:' . apiClientIp(), 20],
    ], 60);
}

/**
 * Принятое событие установки/обновления означает: обработчики портала сброшены.
 *
 * Документация event.bind прямо говорит, что при обновлении и переустановке
 * приложения его действия удаляются, а ставить их заново обязан установщик каждой
 * версии. Наше состояние при этом не меняется — значит, узнать об этом можно
 * только здесь, и здесь же надо оставить метку. Метку ставим ТОЛЬКО после того,
 * как событие подтверждено порталом: неподтверждённый POST не имеет права
 * заставлять приложение слать порталу подписки заново.
 */
function lifecycleMarkBindingsDirty(string $event): void {
    if (!function_exists('eventsMarkRebindRequired')) {
        require_once __DIR__ . '/events-bind.php';
    }
    if (!function_exists('placementsMarkRebindRequired')) {
        require_once __DIR__ . '/placements.php';
    }
    try {
        eventsMarkRebindRequired();
    } catch (Throwable $e) {
        // Не смогли записать метку — событие всё равно принято (Битрикс события
        // не переприсылает), но след обязателен: иначе «приложение не получает
        // событий» разбирать будет не по чему.
        logAppError('lifecycle: ' . $event . ' — метку пересборки подписки записать не удалось: '
            . (string)maskSecrets($e->getMessage()));
    }
    try {
        placementsMarkRebindRequired();
    } catch (Throwable $e) {
        logAppError('lifecycle: ' . $event . ' — метку пересборки плейсментов записать не удалось: '
            . (string)maskSecrets($e->getMessage()));
    }
}

function lifecycleTokenEvent(array $post): ?array {
    $event = strtoupper((string)($post['event'] ?? ''));
    if ($event !== 'ONAPPINSTALL' && $event !== 'ONAPPUPDATE') return null;

    $auth = is_array($post['auth'] ?? null) ? $post['auth'] : [];
    $b24  = new B24(defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '', defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '');
    $ourMember = (string)($b24->memberId() ?? '');
    $ourDomain = (string)($b24->domain() ?? '');
    $evToken   = (string)($auth['application_token'] ?? $post['application_token'] ?? '');
    $evAccess  = (string)($auth['access_token'] ?? '');
    $evMember  = (string)($auth['member_id'] ?? $post['member_id'] ?? '');

    if ($ourMember === '' || $evToken === '' || !hash_equals($ourMember, $evMember)) {
        // ONAPPINSTALL публичен и не меняет состояние: не даём произвольным
        // запросам превращать отказ в неограниченную запись журнала.
        if ($event !== 'ONAPPINSTALL') {
            lifecycleDenyLog('lifecycle: ' . $event . ' отклонён — портал события не совпал с нашим');
        }
        return ['ok' => false, 'code' => 403, 'error' => 'Событие не от нашего портала', 'adopted' => false];
    }

    $saved = (string)($b24->applicationToken() ?? '');
    $sameToken = $saved !== '' && hash_equals($saved, $evToken);

    // Browser POST официально может не прислать application_token. Единственная
    // безопасная корреляция async ONAPPINSTALL — точное совпадение уже сохранённой
    // OAuth-пары под CAS. Тогда разрешён только one-time backfill пустого ключа;
    // непустой ключ событие не ротирует и installFinish/generation не подтверждает.
    if ($event === 'ONAPPINSTALL') {
        if ($sameToken) {
            lifecycleMarkBindingsDirty($event);
            return ['ok' => true, 'code' => 200, 'error' => '', 'adopted' => false];
        }
        try {
            $bound = ($saved === '') && $b24->bindInstallApplicationToken($auth);
        } catch (Throwable $e) {
            $bound = false;
        }
        if (!$bound) {
            return ['ok' => false, 'code' => 403,
                'error' => 'Событие не подтверждено ключом приложения', 'adopted' => false];
        }
        lifecycleMarkBindingsDirty($event);
        return ['ok' => true, 'code' => 200, 'error' => '', 'adopted' => true];
    }

    if ($sameToken) {
        // application_token уже доказывает подлинность события. OAuth-пару
        // обновляем только если присланный access дополнительно подтвердил
        // администратора/owner; иначе принимаем событие как безопасный no-op.
        $sameCtx = ($evAccess !== '' && $ourDomain !== '')
            ? resolveUserContextFromAuth($evAccess, $ourDomain) : null;
        $sameOwnerUid = function_exists('aclOwnerUid') ? aclOwnerUid() : 0;
        $sameTrusted = is_array($sameCtx)
            && (!empty($sameCtx['isAdmin']) || ($sameOwnerUid > 0 && (int)$sameCtx['id'] === $sameOwnerUid));
        if ($sameTrusted) {
            try {
                $b24->adoptLifecycleAuthorization($auth, true, false);
            } catch (Throwable $e) {
                lifecycleDenyLog('lifecycle: ' . $event . ' auth-пара не усвоена — поля не совпали с порталом');
            }
        }
        // Ключ приложения совпал — событие наше, даже если OAuth-пару мы не
        // трогали. Обработчики портал снял всё равно, значит подписку надо
        // ставить заново.
        lifecycleMarkBindingsDirty($event);
        return ['ok' => true, 'code' => 200, 'error' => '', 'adopted' => false];
    }

    // Битрикс не повторяет события после ошибочного HTTP-ответа. Поэтому
    // запрещён глобальный троттл: чужая попытка не должна закрывать окно
    // законному новому ключу. Ограничиваем только точный повтор уже отвергнутой
    // пары application/access token; угадать отпечаток настоящего события без
    // самих секретов нельзя.
    $fingerprint = hash('sha256', $evToken . "\0" . $evAccess);
    if ($b24->eventVerificationRejectedRecently($fingerprint, 10)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Событие не подтверждено порталом', 'adopted' => false];
    }

    // Опознание стоит похода в портал, а адрес обработчика публичен: без лимита
    // поток мусорных событий гнал наружу по два REST-вызова на каждый POST.
    // Отказ лимитера отдаём как 503 — это «повторите позже», а не «навсегда нет».
    if (!lifecycleVerifyAllowed()) {
        return ['ok' => false, 'code' => 503, 'error' => 'Слишком много проверок подряд, повторите позже',
            'adopted' => false];
    }
    $ctx = ($evAccess !== '' && $ourDomain !== '') ? resolveUserContextFromAuth($evAccess, $ourDomain) : null;
    $ownerUid = function_exists('aclOwnerUid') ? aclOwnerUid() : 0;
    $trusted  = is_array($ctx) && (!empty($ctx['isAdmin']) || ($ownerUid > 0 && (int)$ctx['id'] === $ownerUid));
    if (!$trusted) {
        $b24->markEventVerificationRejected($fingerprint);
        lifecycleDenyLog('lifecycle: ' . $event . ' отклонён — присланный access_token не подтверждён порталом');
        return ['ok' => false, 'code' => 403, 'error' => 'Событие не подтверждено порталом', 'adopted' => false];
    }

    try {
        // ONAPPINSTALL уже завершился no-op выше; здесь остаётся только
        // подтверждённая ротация ONAPPUPDATE в текущем поколении.
        $b24->clearEventVerificationRejection();
        $b24->adoptLifecycleAuthorization($auth, true, false);
    } catch (Throwable $e) {
        $b24->markEventVerificationRejected($fingerprint);
        lifecycleDenyLog('lifecycle: ' . $event . ' отклонён — auth-поля не совпали с подтверждённым порталом');
        return ['ok' => false, 'code' => 403, 'error' => 'Событие содержит неверные auth-поля', 'adopted' => false];
    }
    logAppError($saved === ''
        ? 'lifecycle: auth и application_token получены событием ' . $event
        : 'lifecycle: auth и application_token обновлены событием ' . $event . ' (ротация ключа приложения)');
    lifecycleMarkBindingsDirty($event);
    return ['ok' => true, 'code' => 200, 'error' => '', 'adopted' => true];
}

/**
 * Domain Б24-портала. null если портал ещё не известен.
 *
 * Обычно имя приходит из сохранённых токенов установки. При работе постоянным
 * ключом установки может не быть вовсе, а портал известен точно — он записан в
 * самом ключе, который выписал этот портал. Без такого возврата забор сессии
 * сравнивал бы открытие «ни с чем» и не пускал в приложение никого.
 */
function b24Portal(): ?string {
    $j = storeRead(B24_TOKENS_FILE);
    return B24::portalForChannels(is_array($j) ? $j : [], b24WebhookBase());
}

/** Ключ приложения из сохранённых токенов. Читается ТАК ЖЕ дёшево, как домен:
 *  транспортному забору нужен именно снимок с диска, а b24() до установки бросает
 *  исключение и для проверки «пускать ли дальше» не годится. */
function b24ApplicationTokenStored(): string {
    $j = storeRead(B24_TOKENS_FILE);
    $t = is_array($j) ? ($j['application_token'] ?? null) : null;
    return is_string($t) ? $t : '';
}

// resolveUserIdFromAuth() удалена 05.08.2026 по находке ревью: её не вызывал
// никто, а сама возможность взять «только id» тянет к обходу полного контекста —
// вместе с id теряются признак администратора и отделы, на которых стоит доступ.
// Нужен id — бери его из resolveUserContextFromAuth() вместе с ролью.

/** Полный контекст iframe-юзера по AUTH_ID: id, admin ли, отделы. Токеном самого юзера (не
 *  installer'ом) — иначе все выглядели бы как установщик. null — AUTH_ID невалиден / сеть упала.
 *  KB: rule-b24-which-token-for-what. Используется для scopeRefreshOk (сейв свежего scope только
 *  от админа) и как основа admin-gate. */
function resolveUserContextFromAuth(string $authId, string $domain): ?array {
    if ($authId === '' || $domain === '') return null;

    // Ответ кэшируется на время запроса. За одно открытие приложения контекст
    // спрашивают несколько мест (гейт доступа, проверка подлинности install-POST,
    // фиксация владельца) — без кэша это три-четыре одинаковых обращения к порталу
    // подряд, то есть лишняя секунда ожидания у человека и лишний расход лимита REST.
    static $cache = [];
    $key = $domain . "\0" . $authId;
    if (array_key_exists($key, $cache)) return $cache[$key];

    // ОДИН слот на всё опознание, а не по слоту на запрос. Пара user.current +
    // user.admin неразделима, и второй слот превращался в 0,5 с сна на КАЖДОМ
    // открытии приложения при пустой очереди (K4-31, круг 4).
    b24PortalRateSlot();
    $get = function (string $method) use ($authId, $domain) {
        return b24PortalGet($domain, $method, $authId, false);
    };
    $cur = $get('user.current');
    $u = is_array($cur) ? ($cur['result'] ?? null) : null;
    // ЗАПАСНОЙ СПОСОБ СПРОСИТЬ «КТО ТЫ» — когда прав на user.current нет.
    //
    // Бывают порталы (коробка со своим сервером), где приложению урезают права на
    // уровне самого портала: токен зрителя рабочий, но user.current отвечает
    // «insufficient_scope». Опознать человека при этом всё равно можно —
    // документированный метод `profile` работает БЕЗ скоупов и возвращает того же
    // текущего пользователя: его идентификатор и признак администратора
    // (api-reference/common/users/profile.md). Личность по-прежнему называет
    // ПОРТАЛ в ответ на токен САМОГО зрителя, а не тело запроса.
    //
    // Чего profile не даёт — отделов. Их доберём ниже отдельно, если есть чем.
    $poProfile = false;
    if (!is_array($u) || empty($u['ID'])) {
        $otkaz = is_array($cur) ? (string)($cur['error'] ?? '') : '';
        if ($otkaz !== '') {
            $prof = $get('profile');
            $p = is_array($prof) ? ($prof['result'] ?? null) : null;
            if (is_array($p) && !empty($p['ID'])) {
                $u = ['ID' => $p['ID'], 'UF_DEPARTMENT' => []];
                $poProfile = true;
                $profileAdmin = ($p['ADMIN'] ?? null);
            }
        }
    }
    // Почему не опознали — В ЖУРНАЛ. Снаружи и «портал молчит», и «портал
    // отверг токен» выглядят одинаково: экран пишет «портал сейчас не
    // отвечает», человек ждёт и жмёт обновить, а причина может быть совсем
    // другой — например, портал вообще не признаёт токены этого приложения.
    // Пишем ТОЛЬКО код отказа портала, без самого токена и без тела ответа.
    if (!is_array($u) || empty($u['ID'])) {
        if (function_exists('appLog')) {
            appLog('warn', 'auth.identify_failed', [
                'портал'       => $domain,
                'ответ_пришёл' => is_array($cur),
                'отказ'        => is_array($cur) ? (string)maskSecrets((string)($cur['error'] ?? '')) : '',
            ], ['msg' => 'портал не назвал, кто открыл приложение']);
        }
    }
    // Неудачу кэшируем ТОЛЬКО в пределах текущего запроса — как и успех: кэш живёт
    // в static и умирает вместе с процессом. Причина: один и тот же AUTH_ID
    // опознают два места (install-ветка index.php и session-гейт), и без этого
    // анонимный POST с мусорным токеном платил сетевой таймаут ДВАЖДЫ — 6 секунд
    // превращались в 12, а публичный адрес обработчика становился способом занять
    // воркеры хостинга. Между запросами кэша нет, поэтому восстановление связи
    // подхватывается на следующем же открытии.
    if (!is_array($u) || empty($u['ID'])) { $cache[$key] = null; return null; }
    // Права спрашиваем отдельным вызовом. Если СВЯЗЬ оборвалась (ответа нет
    // вообще), считать «не админ» нельзя: администратор на весь запрос
    // превратился бы в обычного сотрудника, а результат ещё и осел бы в кэше.
    // Возвращаем «не знаю» — выше это показывается как «портал не отвечает».
    // Портал ответил, пусть даже отказом, — верим ответу.
    if ($poProfile) {
        // profile уже сказал и «кто», и «админ ли» — второй вызов не нужен, а на
        // урезанном портале user.admin всё равно ответит отказом по правам.
        $a = $profileAdmin;
    } else {
        $adm = $get('user.admin');
        if ($adm === null) { $cache[$key] = null; return null; }
        $a = is_array($adm) ? ($adm['result'] ?? false) : false;
    }
    $deps = [];
    foreach ((array)($u['UF_DEPARTMENT'] ?? []) as $d) if ((int)$d > 0) $deps[] = (int)$d;
    // Отделы человека при опознании через profile портал не сообщает, а права в
    // приложении выдают в том числе отделами. Доспрашиваем их постоянным ключом —
    // у него нужное право есть. Это НЕ подмена личности: кто именно открыл,
    // сказал портал по токену зрителя, а ключом мы лишь читаем справочник по уже
    // known идентификатору. Нет ключа или portal отказал — остаёмся без отделов:
    // поимённый доступ и доступ администратора продолжают работать.
    if ($poProfile && $deps === [] && b24WebhookBase() !== '') {
        try {
            $svoi = b24();
            $rows = $svoi->call('user.get', ['ID' => (int)$u['ID']]);
            $row = is_array($rows) ? (($rows['result'][0] ?? null)) : null;
            foreach ((array)($row['UF_DEPARTMENT'] ?? []) as $d) if ((int)$d > 0) $deps[] = (int)$d;
        } catch (Throwable $e) {
            if (function_exists('appLog')) {
                appLog('warn', 'auth.departments_unknown', ['пользователь' => (string)$u['ID']],
                    ['msg' => 'отделы сотрудника не прочитаны — доступ по отделам в этом запросе не сработает']);
            }
        }
    }
    $ctx = [
        'id'            => (string)$u['ID'],
        'isAdmin'       => ($a === true || $a === 'Y' || $a === 1 || $a === '1'),
        'departmentIds' => $deps,
    ];
    $cache[$key] = $ctx;
    return $ctx;
}

/**
 * Один короткий запрос к порталу токеном САМОГО пользователя.
 *
 * Отдельной функцией — чтобы самопроверка могла проверить разбор событий
 * жизненного цикла, не выходя в сеть: подмена включается ТОЛЬКО когда стор
 * подменён временной папкой (BXAPP_DATA_ROOT задан). На бою такой переменной
 * нет, значит и подменить обращение к порталу нечем.
 */
/**
 * Занять слот в общей очереди к порталу — НИКОГДА не бросая.
 *
 * Отдельной функцией по двум причинам. Первая: занимать слот нужно и на ГРУППУ
 * запросов сразу — опознание человека делает два (user.current + user.admin), и
 * пока слот брал каждый, второй вставал ровно на интервал темпа. На типовом
 * тарифе это 0,5 с чистого сна на КАЖДОМ открытии приложения при полностью
 * пустой очереди, без единой строки в журнале: слот-то выдан (K4-31, круг 4).
 * Пара опознания неразделима и сидит на пути человека, поэтому она намеренно
 * учитывается как один слот; при массовом открытии очередь по-прежнему держит
 * процессы порознь, а «дешевле, чем есть» здесь ровно на один запрос.
 *
 * Вторая: отметка темпа читает стор, а тот бывает повреждён. Ни один вызывающий
 * не обязан из-за этого менять свой исход — контракт «не бросает» живёт здесь, в
 * одном месте, а не переписывается в каждой точке входа (K4-30, круг 4).
 */
function b24PortalRateSlot(): void {
    try {
        if (!function_exists('planCurrentRate') || !function_exists('planWaitTurn')) return;
        $rate = planCurrentRate();
        // Ожидание ограничено самим planWaitTurn (planWaitCap, в вебе — полсекунды):
        // очередь занимаем, но открытие приложения этим не задерживаем надолго.
        if ($rate > 0) planWaitTurn(1.0 / $rate);
    } catch (Throwable $e) {
        if (function_exists('planNoteDegraded')) {
            planNoteDegraded('отметка темпа недоступна: ' . (function_exists('maskSecrets')
                ? (string)maskSecrets($e->getMessage()) : 'ошибка состояния'));
        }
    }
}

/**
 * @param bool $zanyatSlot false — слот в общей очереди уже занят вызывающим на
 *        всю группу запросов (см. resolveUserContextFromAuth). Второй раз его
 *        занимать нельзя: это и есть лишний сон на пути человека.
 */
function b24PortalGet(string $domain, string $method, string $authId, bool $zanyatSlot = true): ?array {
    // СЛОТ В ОБЩЕЙ ОЧЕРЕДИ — ДО отправки и одинаково для боевого пути и для
    // испытаний (тот же порядок, что в B24::rawCall). Мимо общей отметки эти
    // запросы в бюджет портала не попадали вовсе: при массовом открытии вместе с
    // фоновым обработчиком приложение честно держало свой предел и всё равно
    // упиралось в лимит портала (находка ревью 09.08.2026). Берём его ровно один
    // раз на запрос — либо здесь, либо выше по стеку сразу на всю группу
    // (см. b24PortalRateSlot и resolveUserContextFromAuth). Повтора здесь
    // по-прежнему НЕТ намеренно: за этим путём ждёт человек, а таймаут короткий.
    if ($zanyatSlot) b24PortalRateSlot();
    $stub = $GLOBALS['__b24_portal_get'] ?? null;
    if ($stub !== null && is_callable($stub) && getenv('BXAPP_DATA_ROOT') !== false && getenv('BXAPP_DATA_ROOT') !== '') {
        $r = $stub($domain, $method, $authId);
        return is_array($r) ? $r : null;
    }
    // ТОКЕН — В ТЕЛЕ ЗАПРОСА, А НЕ В АДРЕСЕ. Документация портала прямо говорит:
    // «для приложения передавайте токен авторизации в поле auth в теле запроса»
    // (rest-v3.md). Раньше здесь стоял GET с `?auth=…`, и рабочий токен уезжал в
    // журналы веб-сервера, в историю обращений и в заголовок Referer — то есть
    // жил дольше самого запроса и в местах, откуда его никто не удаляет.
    $url = 'https://' . $domain . '/rest/' . $method . '.json';
    $ch = curl_init($url);
    $buffer = new B24ResponseBuffer(B24_RESPONSE_MAX_BYTES);
    // Таймаут короткий намеренно: опознание стоит на пути КАЖДОГО открытия
    // приложения, и если портал молчит, человек должен получить внятное
    // «портал не отвечает» за секунды, а не смотреть в пустой iframe полминуты.
    // Заодно это ограничивает, насколько долго чужой запрос держит наш воркер.
    curl_setopt_array($ch, array_replace([
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['auth' => $authId]),
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ], b24BoundedCurlOptions($buffer)));
    $ok = curl_exec($ch);
    $tooLarge = $buffer->tooLarge();
    $resp = $buffer->body();
    curl_close($ch);
    if ($tooLarge || $ok === false) return null;
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

/** Строго разобрать app.info.INSTALLED; неизвестная форма не равна false. */
function b24InstalledStatus(?array $response): ?bool {
    if (!is_array($response)) return null;
    $value = $response['result']['INSTALLED'] ?? null;
    if ($value === true || $value === 'Y' || $value === 'true' || $value === 1 || $value === '1') return true;
    if ($value === false || $value === 'N' || $value === 'false' || $value === 0 || $value === '0') return false;
    return null;
}

/**
 * Подлинный ли install-POST — общая политика для ВСЕХ публичных обработчиков.
 *
 * Handler-URL висит в интернете открытым, поэтому POST с чужой стороны мог бы
 * перезаписать installer-токены (приложение «отвалится») или увести адрес
 * портала на чужой хост (наши серверные вызовы пошли бы туда). Правила:
 *  • первая установка помечается кандидатом, но принимается только после
 *    verifyAndAdopt() через REFRESH_ID + client_secret;
 *  • дальше — строгая сверка application_token, когда канал его действительно
 *    прислал; официальный browser POST этот ключ не обещает;
 *  • старая установка без сохранённого токена — доверяем ОДИН раз и только на
 *    тот же портал с рабочим AUTH_ID; сразу после этого токен дописывается.
 *
 * Живёт здесь, а не в index.php, потому что обработчиков установки два, и
 * когда политика была только в одном, второй (api/install.php) принимал токены
 * от кого угодно — найдено ревью 29.07.2026.
 *
 * @return array{authentic: bool, first: bool, strict: bool}
 */
/**
 * PROTOCOL в browser POST — не URL-схема, а официальный флаг 0/1.
 * Текстовые значения оставлены только для совместимости со старыми обёртками.
 */
/**
 * Отказ сервера авторизации — человеческим языком, с указанием, что делать.
 *
 * Коды документированы в settings/oauth/error-codes.md. Различать их обязательно:
 * `invalid_grant` лечится переустановкой приложения на портале, а `invalid_client`
 * означает, что приложение туда даже не установлено или креды в конфиге чужие —
 * и переустановка ничего не изменит.
 */
function oauthRefusalText(string $error, string $description = ''): string {
    switch (strtolower(trim($error))) {
        case 'invalid_grant':
            return 'сохранённый ключ доступа больше не действует. Переустановите приложение '
                . 'на портале: Приложения → Разработчикам → ваше приложение → Переустановить';
        case 'invalid_client':
            return 'портал не признаёт это приложение. Проверьте, что приложение установлено '
                . 'на портале и что код и ключ приложения в настройках сервера совпадают '
                . 'с карточкой local-app';
        case 'insufficient_scope':
            return 'приложению не выдали нужные права. Добавьте их в карточку приложения '
                . 'на портале и переустановите его';
        case '':
            return $description !== '' ? $description : 'сервер авторизации ответил без объяснения';
        default:
            // Незнакомый код показываем как есть: угадывать смысл опаснее, чем
            // признаться, что мы его не знаем.
            return 'сервер авторизации отказал (' . $error . ')'
                . ($description !== '' ? ': ' . $description : '');
    }
}

function installProtocolScheme($raw): string {
    if ($raw === null || $raw === '') return 'https';
    $value = strtolower(trim((string)$raw));
    if ($value === '1' || $value === 'https' || $value === 'https:') return 'https';
    if ($value === '0' || $value === 'http' || $value === 'http:') return 'http';
    throw new RuntimeException('B24 POST: неизвестное значение PROTOCOL');
}

/**
 * Привести адрес REST к безопасному виду или вернуть пустую строку.
 *
 * Портал присылает `client_endpoint` в ответе авторизации, и раньше мы проверяли
 * у него только имя хоста, а хранили и звали строку ЦЕЛИКОМ — со схемой, портом,
 * логином-паролем и «хвостом» запроса, какими бы они ни пришли. Каждый серверный
 * вызов уходит по этому адресу вместе с токеном, поэтому оставляем только то, что
 * обязано там быть: https, имя хоста, путь.
 */
/**
 * ═══ ФОРМАТ ПОРТАЛЬНОГО POST — ОПИСАН ЗДЕСЬ И ТОЛЬКО ЗДЕСЬ ═══
 *
 * Портал шлёт на handler разный набор полей, и разница не косметическая:
 *   облако  — DOMAIN, APP_SID, PROTOCOL, member_id, AUTH_ID/REFRESH_ID;
 *   коробка — SERVER_ENDPOINT, APPLICATION_TOKEN, APPLICATION_SCOPE, member_id,
 *             status, PLACEMENT (ни DOMAIN, ни APP_SID, ни PROTOCOL).
 *
 * Почему разбор в одном месте. Пока про SERVER_ENDPOINT знал только забор
 * сессии, а установка искала домен в DOMAIN и Referer, приложение на коробке
 * (1) не ставилось вовсе, если Referrer-Policy обрезала заголовок, и
 * (2) сохраняло одно имя портала, а проверялось по другому — и после успешной
 * установки навсегда отвечало «открывается только из портала». Обе функции ниже
 * зовут и установка, и забор: разъехаться им больше негде.
 */

/**
 * Домен портала из POST'а обработчика.
 *
 * Хост из SERVER_ENDPOINT берём ТОЛЬКО когда DOMAIN не пришёл: в облаке
 * SERVER_ENDPOINT указывает на общий сервер авторизации, а не на портал, и
 * подставлять его вместо домена нельзя.
 */
function portalPostDomain(array $input): string {
    $domain = (string)($input['DOMAIN'] ?? $input['domain'] ?? '');
    if ($domain !== '') return $domain;

    $endpoint = (string)($input['SERVER_ENDPOINT'] ?? $input['server_endpoint'] ?? '');
    if ($endpoint === '') return '';
    $host = parse_url($endpoint, PHP_URL_HOST);
    return is_string($host) ? $host : '';
}

/**
 * Адрес REST из POST'а обработчика — только для СВОЕГО домена.
 *
 * У коробки REST может жить не в корне, и собранный вручную `https://<домен>/rest/`
 * тогда молча ведёт в 404 на каждом вызове. Но принимать чужой адрес нельзя ни
 * при каких условиях: подставленный SERVER_ENDPOINT увёл бы токены портала на
 * чужой сервер. Поэтому — только при точном совпадении хоста с доменом установки.
 */
function portalPostRestEndpoint(array $input, string $domain): string {
    if ($domain === '') return '';
    $endpoint = normalizeRestEndpoint(
        (string)($input['SERVER_ENDPOINT'] ?? $input['server_endpoint'] ?? ''));
    if ($endpoint === '') return '';
    $host = parse_url($endpoint, PHP_URL_HOST);
    return is_string($host) && strcasecmp($host, $domain) === 0 ? $endpoint : '';
}

function normalizeRestEndpoint(string $endpoint): string {
    if ($endpoint === '') return '';
    $p = parse_url($endpoint);
    if (!is_array($p)) return '';
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host   = (string)($p['host'] ?? '');
    if ($scheme !== 'https' || $host === '') return '';
    if (isset($p['user']) || isset($p['pass'])) return '';
    if (isset($p['port']) && (int)$p['port'] !== 443) return '';
    if (!preg_match('~^[a-z0-9.-]+$~i', $host)) return '';
    $path = (string)($p['path'] ?? '/rest/');
    if ($path === '' || strpos($path, '..') !== false) $path = '/rest/';
    return 'https://' . $host . rtrim($path, '/') . '/';
}

/**
 * Кто вправе встраивать нас в iframe — ОДНО правило на все ответы приложения.
 *
 * Пока портал не подтверждён, в рамку пускаются только маски облачных порталов
 * Битрикса. Это и есть весь список, который можно назвать заранее: коробочный
 * портал живёт под собственным именем, и угадать его нельзя.
 *
 * Домен принимаем только в том виде, в каком портал его подтвердил, и с проверкой
 * состава: значение уходит в заголовок ответа, а туда нельзя пускать ни пробел,
 * ни перевод строки, ни вторую директиву. Не похоже на имя хоста — возвращаемся к
 * маскам, потому что «непонятное» здесь обязано означать «строже», а не «шире».
 */
function appFrameAncestorsValue(string $domain): string {
    $domain = strtolower(trim($domain));
    if ($domain !== '' && preg_match('~^[a-z0-9][a-z0-9.-]*$~D', $domain) === 1) {
        return 'https://' . $domain;
    }
    return 'https://*.bitrix24.ru https://*.bitrix24.com https://*.bitrix24.kz '
        . 'https://*.bitrix24.by https://*.bitrix24.ua https://*.bitrix24.de '
        . 'https://*.bitrix24.eu https://*.bitrix24.com.br';
}

/**
 * Поставить (или ЗАМЕНИТЬ) политику рамки текущего ответа.
 *
 * Заменять приходится вот почему. Заголовок собирается в самом начале запроса, до
 * разбора установки, — иначе его негде поставить раньше первого вывода. Но именно
 * первый аутентифицированный POST установки и приносит имя портала: до него в
 * сторе пусто, и свежая КОРОБКА получала список из одних облачных масок, то есть
 * блокировала сама себя. Браузер отказывался рисовать нашу страницу в рамке
 * портала раньше, чем установка успевала завершиться, и администратор видел пустой
 * прямоугольник без единого слова (находка ревью 09.08.2026, R3-001).
 *
 * Звать МОЖНО только с уже подтверждённым доменом: неподтверждённое имя из тела
 * запроса в рамку не попадает никогда — иначе любой посторонний объявлял бы себя
 * вправе нас встраивать.
 */
function appSendFrameAncestors(string $domain): void {
    if (headers_sent()) return;
    // X-Frame-Options здесь снимается намеренно: он умеет только «никому» или
    // «своему домену» и сломал бы встраивание в портал.
    header_remove('X-Frame-Options');
    // 'self' в списке стоять НЕ должно: приложения одного клиента живут путями
    // одного домена, и сосед по origin читал бы наш DOM вместе с пропуском.
    header('Content-Security-Policy: frame-ancestors ' . appFrameAncestorsValue($domain));
}

function installPostAuthenticity(B24 $b24, array $post, bool $allowUserLookup = false): array {
    $isFirst    = !$b24->hasTokens();
    $saved      = $b24->applicationToken();
    $postToken  = (string)($post['APPLICATION_TOKEN'] ?? $post['application_token'] ?? '');
    $oldDomain  = (string)($b24->domain() ?? '');
    // Домен POST'а — ТЕМ ЖЕ резолвером, что и весь остальной путь установки.
    // Своё чтение `$post['DOMAIN']` отсекало здесь два законных случая разом:
    // КОРОБКА поля DOMAIN не присылает вовсе (у неё SERVER_ENDPOINT), а облако
    // вправе прислать имя портала в другом регистре. В обоих случаях старая
    // установка без сохранённого application_token не могла его дозаписать
    // никогда — то есть навсегда оставалась без строгой сверки подлинности
    // (находка ревью 09.08.2026, R3-004).
    $postDomain = portalPostDomain($post);
    $authId     = (string)($post['AUTH_ID'] ?? '');

    $strict = ($saved !== null && $saved !== '' && $postToken !== '' && hash_equals($saved, $postToken));

    // Доверие «в первый раз» (старая установка без сохранённого токена) — ТОЛЬКО
    // администратору того же портала. Проверять «AUTH_ID вообще резолвится» мало:
    // тело placement-POST со своим AUTH_ID видит в браузере любой сотрудник, даже
    // тот, кому в приложение доступ закрыт. Повторив его, он подменил бы
    // серверный токен своим — и приложение перестало бы видеть данные портала,
    // зато он стал бы его владельцем.
    // Имя портала регистронезависимо (RFC 1035), поэтому «тот же портал» сверяем
    // приведёнными строками и постоянным по времени сравнением: это условие
    // допуска, а не косметика.
    $sameDomain = $oldDomain !== '' && $postDomain !== ''
        && hash_equals(strtolower($oldDomain), strtolower($postDomain));

    $tofu = false;
    if ($allowUserLookup && $saved === null && $sameDomain && $authId !== '') {
        // Троттл: это единственная ветка, где неподтверждённый POST заставляет нас
        // ходить в портал. Без ограничения любой желающий гонял бы наши исходящие
        // запросы пачками — воркеры заняты, а нас у oauth могут и придержать.
        // Раз в 10 секунд достаточно: ветка нужна ровно один раз, дальше работает
        // строгая сверка.
        if (!$b24->tofuCheckedRecently(10)) {
            $b24->markTofuChecked();
            $ctx  = resolveUserContextFromAuth($authId, $oldDomain);
            $tofu = is_array($ctx) && !empty($ctx['isAdmin']);
        }
    }

    return [
        'authentic' => ($isFirst || $strict || $tofu),
        'first'     => $isFirst,
        'strict'    => $strict,
        'tofu'      => $tofu,
    ];
}

/**
 * Сплэш «устанавливается…» с авто-перезагрузкой.
 *
 * @param string $note Предупреждение, которое человек должен увидеть (например,
 *               что владельца зафиксировать не удалось). Показываем ДО reload'а
 *               и задерживаем перезагрузку, иначе текст мелькнёт и исчезнет.
 */
function renderInstallFinishPage(string $note = ''): void {
    sendNoStoreHeaders();
    header('Content-Type: text/html; charset=utf-8');
    $reloadAfter = $note !== '' ? 6000 : 700;
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка</title>'
       . '<script src="//api.bitrix24.com/api/v1/"></script>'
       . '<style>:root{color-scheme:dark}body{margin:0;min-height:100vh;display:flex;align-items:center;'
       . 'justify-content:center;padding:24px;background:#14161a;color:#e6e8eb;'
       . 'font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}'
       . '.card{max-width:460px;width:100%;background:#1b1e24;border:1px solid #2a2f37;border-radius:14px;padding:26px 28px;text-align:center}'
       . 'h1{margin:0 0 10px;font-size:18px;font-weight:600;color:#4caf7d}p{margin:0;color:#b9bfc7}'
       . '.warn{margin-top:16px;padding:12px 14px;background:#20242b;border-left:3px solid #f0b429;border-radius:0 8px 8px 0;text-align:left;color:#e6e8eb}'
       . '</style></head><body><div class="card">'
       . '<h1>Приложение устанавливается…</h1>'
       . '<p id="splash-note">Через секунду откроется интерфейс. Не закрывайте вкладку.</p>'
       . ($note !== '' ? '<p class="warn">' . htmlspecialchars($note) . '</p>' : '')
       . '</div>'
       . '<script>document.addEventListener("DOMContentLoaded",function(){'
       // Страховка от вечного сплэша. Раньше она смотрела только на наличие
       // window.BX24: SDK загрузился, а BX24.init не вызвал наш колбэк (портал не
       // ответил, ошибка внутри SDK) — и человек молча смотрел на «устанавливается…»
       // сколько угодно долго (находка ревью 09.08.2026). Теперь таймер безусловный,
       // а снимает его сам колбэк: судим по тому, ЧТО ПРОИЗОШЛО, а не по тому, что
       // объект существует.
       . 'var bxOk=false;'
       . 'setTimeout(function(){if(bxOk)return;var n=document.getElementById("splash-note");'
       . 'if(n)n.textContent="Портал не отвечает. Закройте вкладку и откройте приложение из меню Битрикс24 заново.";},8000);'
       . 'if(window.BX24)BX24.init(function(){bxOk=true;'
       . 'try{BX24.installFinish();}catch(e){console.warn("BX24.installFinish не отработал:",e);}'
       . 'setTimeout(function(){location.reload();},' . $reloadAfter . ');});});</script>'
       . '</body></html>';
}
