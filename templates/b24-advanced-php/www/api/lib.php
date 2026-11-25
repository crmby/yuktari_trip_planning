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
$releaseFile = __DIR__ . '/../release.php';
if (is_file($releaseFile)) require_once $releaseFile;
require_once __DIR__ . '/store.php';

/**
 * Generic-хелперы шаблона. Здесь живёт всё, что НЕ привязано к Б24 или к
 * конкретной бизнес-логике: маскировка секретов для логов, загрузка/запись
 * settings, обёртка над cURL для HTTP-вызовов внешних API.
 *
 * Сюда дописывать новые helper'ы, которые имеют смысл в любом проекте.
 * Бизнес-специфичные функции — в отдельные файлы под /api/.
 */

/**
 * Убрать секреты из чего угодно, что собираешься записать в лог или показать.
 *
 * Зачем. Токен утекает не через «вывел токен», а через соседние вещи: текст
 * исключения от HTTP-клиента несёт URL целиком (`...?auth=<токен>`), дамп
 * ответа Б24 несёт access_token и refresh_token, дамп входящего POST'а —
 * application_token. Записал такое в лог — секрет лежит в файле на диске
 * столько, сколько живёт лог.
 *
 * Правило одно: в лог уходит ТОЛЬКО то, что прошло через эту функцию, и
 * прогонять надо всю структуру целиком, а не «то поле, где по-моему секрет».
 * Маска не сохраняет ни первых символов, ни длины — по ним значение подбирают.
 *
 * Работает и с массивами (рекурсивно, по именам ключей), и со строками
 * (по типовым формам «ключ=значение» и заголовку Authorization).
 *
 * @param mixed $value Что угодно: строка, массив, скаляр.
 * @return mixed Та же структура, но с ***-заглушками вместо секретов.
 */
function maskSecrets($value, int $depth = 0) {
    // Имена, значение которых секретно само по себе — где бы оно ни лежало.
    // AUTH_ID / REFRESH_ID — так называются токены во входящем POST'е Битрикса.
    // member_id и domain НЕ маскируем намеренно: это не ключи доступа, а по ним
    // как раз и разбирают «почему приложение считает, что переехало на другой портал».
    static $secretKeys = 'auth|auth_id|refresh_id|access_token|refresh_token|application_token|'
                       . 'client_secret|password|passwd|secret|token|api_key|apikey|webhook|signature';

    if (is_array($value)) {
        if ($depth > 12) return '***';   // защита от самоссылающихся структур
        $out = [];
        foreach ($value as $k => $v) {
            // Ключ проверяем на ВХОЖДЕНИЕ секретного слова, а не на точное
            // совпадение. Точное совпадение пропускало ровно то, что чаще всего
            // и пишут в лог: secret_token, userAccessToken, b24_refresh_token.
            // Лишняя звёздочка в журнале не стоит ничего, утёкший токен — стоит.
            // Секретный ключ закрывает ВСЁ поддерево целиком — и строку, и
            // список, и вложенный объект. Голую строку-токен, лежащую под
            // НЕсекретным ключом, здесь не ловим намеренно: «двадцать знаков из
            // букв и цифр» — это и идентификатор сделки, и имя файла, и хеш;
            // маскировать их значит превратить журнал в звёздочки.
            $out[$k] = (is_string($k) && preg_match('~(?:' . $secretKeys . ')~i', $k) && $v !== '' && $v !== null)
                ? '***'
                : maskSecrets($v, $depth + 1);
        }
        return $out;
    }
    if (!is_string($value)) return $value;

    // Строку секрет покидает в разных обличьях, и ловить надо все ходовые:
    // разработчик пишет в лог не «токен», а дамп ответа или запроса.
    $s = $value;

    // 1. ключ=значение · "ключ": "значение" · 'ключ' => 'значение'
    //    (URL с ?auth=, тело ответа oauth, JSON в тексте ошибки, var_export).
    $s = (string)preg_replace(
        '~(?<![A-Za-z0-9_])([\'"]?)(' . $secretKeys . ')\1\s*(=>|[:=])\s*([\'"]?)[^\s\'",;&)}\]]*\4~i',
        '$1$2$1$3 ***', $s);

    // 2. print_r: [access_token] => abc…  — значение идёт до конца строки.
    $s = (string)preg_replace('~\[\s*(' . $secretKeys . ')\s*\]\s*=>[^\n]*~i', '[$1] => ***', $s);

    // 3. serialize: s:12:"access_token";s:40:"abc…";
    $s = (string)preg_replace('~(s:\d+:"(?:' . $secretKeys . ')";)s:\d+:"[^"]*";~i', '$1s:3:"***";', $s);

    // 4. Authorization: Bearer <токен> — здесь нет ни ключа, ни знака равенства.
    $s = (string)preg_replace('~\b(Bearer|Basic)\s+[A-Za-z0-9._\-+/=]+~i', '$1 ***', $s);

    // 5. Адрес портала с токеном в САМОМ ПУТИ: .../rest/<пользователь>/<токен>/метод.json.
    //    Ни ключа, ни знака равенства здесь нет, поэтому прежние правила его не
    //    видели — а именно в таком виде секрет и уезжает в журнал: текст ошибки
    //    HTTP-клиента несёт полный URL запроса. Поймано приманкой 08.08.2026.
    $s = (string)preg_replace('~(/rest/\d+/)[A-Za-z0-9]{8,}~', '$1***', $s);
    //    Тот же адрес без номера пользователя (вебхук приложения) и вид
    //    «/rest/<токен>/метод.json».
    $s = (string)preg_replace('~(/rest/)[A-Za-z0-9]{16,}(/)~', '$1***$2', $s);

    return $s;
}

/**
 * Запретить браузеру и прокси хранить этот ответ.
 *
 * Зачем. Кэш-бастер `?v=` спасает только css и js — саму страницу браузер
 * кладёт в кэш вместе с разметкой. Человек жмёт «обновить», видит вчерашний
 * экран и говорит «ты ничего не поправил». Внутри iframe Б24 это ещё заметнее:
 * жёсткая перезагрузка там недоступна обычному пользователю.
 *
 * Вызывать в начале каждой точки входа, которая что-то отдаёт человеку или
 * фронту, — до первого echo.
 */
function sendNoStoreHeaders(): void {
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    // Метка выкладки — чтобы деплой мог проверить не «сервер жив», а «на бою
    // именно та версия, которую отправили». Секрета в ней нет: это время
    // выкладки. deploy-prod.sh создаёт её в собираемом release.php, не меняя
    // исходный www/.
    if (defined('APP_RELEASE')) header('X-App-Release: ' . APP_RELEASE);
}

/**
 * Разметка главной страницы приложения (ревью 23.09.2026, zhivoy-1).
 *
 * Выпуск кладёт её в template.php за гардом «404 + exit» (tools/release-template.php):
 * прямой запрос постороннего получает пустой 404, а здесь файл читается КАК ТЕКСТ,
 * и срезается ровно строка гарда. Нет template.php — исходный template.html: так
 * работает локальная разработка, живые прогоны и проект со старым деплоем.
 */
function appTemplateHtml(string $wwwDir): string {
    $guarded = $wwwDir . '/template.php';
    if (is_file($guarded)) {
        $raw = (string)file_get_contents($guarded);
        $guard = "<?php http_response_code(404); exit; ?>\n";
        if (strncmp($raw, $guard, strlen($guard)) !== 0) {
            throw new RuntimeException('template.php без гарда 404 + exit — выпуск собран не tools/release-template.php');
        }
        return substr($raw, strlen($guard));
    }
    return (string)file_get_contents($wwwDir . '/template.html');
}

/**
 * Установлено ли приложение — по СОДЕРЖИМОМУ, а не по наличию файла.
 *
 * После удаления с портала файл токенов остаётся (пустой, с меткой удаления), и
 * проверка `is_file()` считала приложение установленным: cron продолжал ходить
 * в портал уже удалённого приложения и писать в журнал ошибки авторизации.
 */
/**
 * Запрос или событие относятся уже не к той установке приложения.
 *
 * Фаза говорит, ЧТО именно произошло, и без неё человек получал одно и то же
 * «приложение переустановили» на все случаи — включая самый частый: приложение
 * ещё вообще не установлено (выложили и открыли ссылку до установки в портале).
 * Такой текст отправляет разбираться не туда.
 */
class InstallGenerationMismatchException extends RuntimeException {
    /** empty — установки не было; uninstalled — удалено; pending — уборка; changed — сменилось поколение. */
    public string $phase;

    /**
     * Сохраняем стандартный контракт RuntimeException: прикладной код шаблона
     * вправе передать code/previous. Фаза — дополнительный четвёртый аргумент,
     * а не несовместимая подмена стандартного второго.
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null,
        string $phase = 'changed') {
        $this->phase = in_array($phase, ['empty', 'uninstalled', 'pending', 'changed'], true)
            ? $phase : 'changed';
        parent::__construct($message, $code, $previous);
    }

    public static function forPhase(string $message, string $phase): self {
        return new self($message, 0, null, $phase);
    }
}

/** Не удалось вовремя войти в общий барьер жизненного цикла установки. */
class InstallLifecycleBusyException extends RuntimeException {}

/** APP_PURGE_EXTRA содержит путь, который уборке установки трогать нельзя. */
class InstallPurgeConfigurationException extends InvalidArgumentException {}

/** Уборка после удаления началась, но один или несколько файлов не очищены. */
class InstallCleanupIncompleteException extends RuntimeException {
    /** @var string[] */
    public array $failed;
    /** @param string[] $failed */
    public function __construct(array $failed) {
        $this->failed = array_values($failed);
        parent::__construct('Уборка установки не завершена: ' . implode(', ', $this->failed));
    }
}

/** Новое непредсказуемое поколение установки; не является секретом. */
function appNewInstallGeneration(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Техническая ISO-метка состояния → Unix timestamp.
 *
 * Это НЕ дата портала: helper нужен health/cron для сравнения служебных меток,
 * которые само приложение пишет через date('c'). Прикладной код не должен
 * снова тащить сырой strtotime(): он молча принимает неоднозначные строки и
 * превращает повреждённое состояние в правдоподобную дату.
 */
function appIsoTimestamp(?string $iso): ?int {
    $value = trim((string)$iso);
    if ($value === '' || preg_match(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
        $value
    ) !== 1) return null;

    try {
        $parsed = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors)
            && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0)) {
            return null;
        }
        return $parsed->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

/** Поколение из снимка токенов либо пустая строка для старого/пустого формата. */
function appInstallGenerationFrom(array $tokens): string {
    $generation = $tokens['install_generation'] ?? '';
    return is_string($generation) ? trim($generation) : '';
}

/**
 * Поколение установки, когда приложение работает постоянным ключом.
 *
 * Поколение отвечает на вопрос «та же это установка или уже другая»: по нему
 * запрос понимает, что портал переустановили, а журнал — чьи строки показывать.
 * Токенов при работе ключом нет вовсе, поэтому поколение берётся из самого
 * ключа: пока ключ тот же — привязка та же; сменили ключ (другой портал, другие
 * права, перевыпуск) — поколение меняется само, и прежние строки не смешиваются
 * с новыми. В сторе оно не хранится: хранить производную от секрета незачем, а
 * лишняя запись создавала бы «состояние установки» там, где установки нет.
 *
 * Отпечаток берём от ключа, а не сам ключ: поколение попадает в журнал и в
 * ответы, и секрету там не место.
 */
function appWebhookInstallGeneration(): string {
    if (!function_exists('b24WebhookBase')) require_once __DIR__ . '/b24.php';
    $klyuch = b24WebhookBase();
    if ($klyuch === '') return '';
    return 'wh-' . substr(hash('sha256', $klyuch), 0, 16);
}

/**
 * Поколение доверенного предпросмотра — третий случай работы без установки.
 *
 * Разработчик открывает приложение с дев-портала, портала за ним нет вовсе, и
 * без собственного поколения гейт установки отвечал бы «приложение ещё не
 * установлено» на каждый запрос: интерфейс не открыть, а посмотреть его как раз
 * и надо. Отпечаток берём от ключа предпросмотра — он свой у каждой публикации,
 * поэтому предпросмотровые строки журнала не смешиваются ни с боевыми, ни между
 * проектами. Вне предпросмотра функция возвращает пустую строку и ничего не меняет.
 */
function appPreviewInstallGeneration(): string {
    if (!function_exists('previewActive')) return '';   // CLI и фон: предпросмотра там нет
    if (!previewActive()) return '';
    $key = previewKeyValue();
    if ($key === '') return '';
    return 'pv-' . substr(hash('sha256', $key), 0, 16);
}

/**
 * Поколение работы БЕЗ установки: постоянный ключ портала, а если его нет —
 * доверенный предпросмотр. Одна точка на оба случая, чтобы гейт установки не
 * приходилось учить каждому из них по отдельности.
 */
function appKeylessInstallGeneration(): string {
    $webhook = appWebhookInstallGeneration();
    return $webhook !== '' ? $webhook : appPreviewInstallGeneration();
}

/**
 * Поколения, чьи строки журнала принадлежат ЭТОМУ порталу: текущее и прежние
 * регистрации той же площадки.
 *
 * Зачем список, а не одно поколение. Переустановка на том же портале меняет
 * поколение, и журнал с аудитом разом исчезали с экрана — расследовать «кому и
 * когда выдали доступ» после переустановки было нечем. При смене ПОРТАЛА история
 * не наследуется (её просто нет в новых токенах), поэтому чужие строки в выборку
 * не попадают.
 *
 * @return string[] от текущего к более старым
 */
function appInstallGenerationsOfThisPortal(array $tokens): array {
    $out = [];
    $current = appInstallGenerationFrom($tokens);
    // В webhook-only режиме token-state закономерно пуст: текущее поколение
    // производно от ключа и обязано участвовать в том же фильтре журнала.
    // Tombstone/cleanup главнее ключа и не должны возвращать старую историю.
    if ($current === '' && empty($tokens['access_token'])
        && empty($tokens['uninstalled_at']) && empty($tokens['cleanup_pending'])) {
        $current = appKeylessInstallGeneration();
    }
    if ($current !== '') $out[] = $current;
    $prior = $tokens['prior_generations'] ?? [];
    if (is_array($prior)) {
        foreach ($prior as $g) {
            $g = trim((string)$g);
            if ($g !== '' && !in_array($g, $out, true)) $out[] = $g;
        }
    }
    return $out;
}

/** installed / pending / uninstalled / empty — состояние CAS жизненного цикла. */
function appInstallationPhaseFrom(array $tokens): string {
    if (!empty($tokens['cleanup_pending'])) return 'pending';
    if (!empty($tokens['uninstalled_at'])) return 'uninstalled';
    if (!empty($tokens['access_token'])) return 'installed';
    // Постоянный ключ — тоже рабочее состояние: приложению есть чем обращаться
    // к порталу, даже если установка под приложением не проходила и токенов нет.
    // Иначе человек видел бы «приложение ещё не установлено» на работающем
    // приложении и шёл нажимать «Установить», которая на таком портале и не
    // может сработать. Удаление и уборка проверены выше и остаются главнее:
    // ключ не должен воскрешать снесённую установку.
    if (!function_exists('b24WebhookBase')) require_once __DIR__ . '/b24.php';
    if (b24WebhookBase() !== '') return 'installed';
    return 'empty';
}

/**
 * Единый контракт отказа для страницы и JSON API.
 *
 * Если держать эту матрицу в двух обработчиках, новая lifecycle-фаза неизбежно
 * доедет только в один из них. Неизвестную фазу трактуем как changed: наружу не
 * выдаём детали внутреннего состояния и просим начать запрос заново.
 *
 * @return array{status:int,code:string,api_error:string,title:string,reason:string,
 *               action:string,tone:string,retry_after:int}
 */
function appInstallationFailureSpec(string $phase): array {
    static $specs = [
        'empty' => [
            'status' => 409, 'code' => 'not_installed',
            'api_error' => 'Приложение ещё не установлено в портале',
            'title' => 'Приложение ещё не установлено',
            'reason' => 'На сервере нет данных портала: установка из карточки приложения ещё не проходила.',
            'action' => 'Откройте карточку local-app на портале (Приложения → Разработчикам → это приложение) '
                . 'и нажмите «Установить» под администратором. Прямая ссылка приложение не устанавливает.',
            'tone' => 'info', 'retry_after' => 0,
        ],
        'uninstalled' => [
            'status' => 409, 'code' => 'uninstalled',
            'api_error' => 'Приложение удалено с портала',
            'title' => 'Приложение удалено с портала',
            'reason' => 'Установку удалили, поэтому данные портала здесь больше не хранятся.',
            'action' => 'Если приложение нужно снова — установите его из карточки на портале.',
            'tone' => 'warn', 'retry_after' => 0,
        ],
        'pending' => [
            'status' => 503, 'code' => 'installation_cleanup_pending',
            'api_error' => 'Идёт очистка после удаления приложения, повторите запрос',
            'title' => 'Приложение убирает за собой',
            'reason' => 'Идёт очистка после удаления установки.',
            'action' => 'Подождите минуту и откройте приложение заново.',
            'tone' => 'info', 'retry_after' => 60,
        ],
        'changed' => [
            'status' => 409, 'code' => 'installation_changed',
            'api_error' => 'Установка приложения изменилась, откройте его заново',
            'title' => 'Установка приложения изменилась',
            'reason' => 'Приложение было переустановлено или перенесено во время открытия.',
            'action' => 'Откройте приложение из меню портала заново.',
            'tone' => 'warn', 'retry_after' => 0,
        ],
    ];
    return $specs[$phase] ?? $specs['changed'];
}

/**
 * ЗАЯВКА «ЖДУ ЭКСКЛЮЗИВНЫЙ ЗАМОК».
 *
 * Зачем. Установка, переустановка и удаление берут эксклюзивный замок и ждут его
 * 25 секунд, после чего показывают человеку «Приложение обновляется». Фоновый
 * работник в это время держит общий (SH) замок и берёт его снова и снова:
 * очередь событий разбирается до 45 секунд, следом идёт порция долгой задачи.
 * flock не выстраивает очередь по справедливости — ожидающий эксклюзив проигрывает
 * каждому новому читателю, и установка отваливалась, хотя ставить ей никто не
 * мешал по существу: работник просто не знал, что его ждут.
 *
 * Знать он может только так: тот, кто ждёт эксклюзив, оставляет заявку — файл со
 * свежей отметкой времени. Работник смотрит на неё МЕЖДУ порциями и уступает
 * тик. Проверка стоит один stat, а установка перестаёт зависеть от везения.
 *
 * Файл служебный (`.lock.php`), лежит рядом с самим замком и, как всё в data/,
 * закрыт exit-заголовком.
 */
function appLifecycleWriterWaitFile(): string {
    return DATA_ROOT . '/lifecycle-writer.lock.php';
}

/** Объявить/продлить заявку. Вспомогательная: её сбой установку не останавливает. */
function appLifecycleAnnounceWriterWait(): void {
    $file = appLifecycleWriterWaitFile();
    try {
        storeEnsureDir(DATA_ROOT);
        $fh = fopen($file, 'c');
        if ($fh === false) {
            appLifecycleWriterWaitFailed('не открыть файл заявки');
            return;
        }
        if (filesize($file) === 0) storeWritePrivateHeader($fh, $file);
        elseif (!storeApplyPrivatePermissions($file)) {
            fclose($fh);
            appLifecycleWriterWaitFailed('не выставить приватные права на файл заявки');
            return;
        }
        fclose($fh);
        // Отметку времени обновляем ЯВНО и результат проверяем: заявка без свежей
        // отметки — то же самое, что её отсутствие, и работник спокойно заберёт
        // замок себе. Молча проглотить такой отказ нельзя: установка снова начнёт
        // отваливаться, а причина будет невидима.
        if (!touch($file)) appLifecycleWriterWaitFailed('не обновить отметку заявки');
        clearstatcache(true, $file);
    } catch (Throwable $e) {
        appLifecycleWriterWaitFailed(maskSecrets($e->getMessage()));
    }
}

/**
 * Заявка не поставилась. Сама по себе это не причина отменять установку — она
 * пойдёт как раньше, просто без форы, — но в журнал попасть обязано: иначе
 * «установка иногда отваливается» останется без объяснения.
 */
function appLifecycleWriterWaitFailed(string $reason): void {
    static $said = false;
    if ($said) return;                     // одна запись на процесс, а не на каждую попытку
    $said = true;
    try { lifecycleDenyLog('lifecycle: заявка на эксклюзивный замок не поставлена — ' . $reason); }
    catch (Throwable $e) { /* журнал недоступен — установку из-за этого не останавливаем */ }
}

/** Снять свою заявку: замок получен (или ждать перестали). */
function appLifecycleForgetWriterWait(): void {
    $file = appLifecycleWriterWaitFile();
    // Файла может не быть вовсе (никто не ждал) — это норма, а не отказ.
    if (is_file($file) && !unlink($file)) {
        appLifecycleWriterWaitFailed('не снять файл заявки');
    }
    clearstatcache(true, $file);
}

/**
 * Кто-то прямо сейчас ждёт эксклюзивный замок?
 *
 * Свежесть обязательна: процесс, убитый в момент ожидания, оставил бы заявку
 * навсегда, и фоновая обработка встала бы совсем — предохранитель, который сам
 * себя не отпускает. Живой ожидающий обновляет отметку каждые две секунды.
 */
function appLifecycleWriterWaiting(int $freshSeconds = 10): bool {
    $file = appLifecycleWriterWaitFile();
    clearstatcache(true, $file);
    $at = @filemtime($file);
    if ($at === false) return false;
    return (time() - (int)$at) <= $freshSeconds;
}

/**
 * Общий RW-барьер жизненного цикла установки.
 *
 * Замков отдельных state-файлов недостаточно: запрос A мог пройти apiBoot(),
 * затем uninstall очищал A и устанавливался B, после чего A записывал settings
 * уже в пустое состояние B. API держит shared-lock от apiBoot() до конца
 * запроса; удаление и смена поколения берут exclusive-lock. Поэтому либо вся
 * мутация A закончится до перехода, либо начнётся уже после него и не пройдёт
 * generation/session-гейт.
 *
 * Состояние замка хранится в $GLOBALS, чтобы вложенные готовые модули (например,
 * persist() внутри API-запроса) не брали второй flock того же процесса. Повысить
 * SH до EX нельзя: такой путь обязан сначала отпустить запросный fence.
 */
function appLifecycleAcquire(int $mode, float $timeout = STORE_LOCK_TIMEOUT): void {
    if ($mode !== LOCK_SH && $mode !== LOCK_EX) {
        throw new InvalidArgumentException('lifecycle: допустим только shared/exclusive lock');
    }

    $held = $GLOBALS['__app_lifecycle_lock'] ?? null;
    if (is_array($held) && isset($held['handle'], $held['mode'], $held['depth'])) {
        $heldMode = (int)$held['mode'];
        if ($heldMode === LOCK_EX || $heldMode === $mode) {
            $held['depth'] = (int)$held['depth'] + 1;
            $GLOBALS['__app_lifecycle_lock'] = $held;
            return;
        }
        throw new LogicException('lifecycle: нельзя повысить shared-lock до exclusive в одном запросе');
    }

    storeEnsureDir(DATA_ROOT);
    $file = DATA_ROOT . '/lifecycle.lock.php';
    $fh = @fopen($file, 'c');
    if ($fh === false) {
        throw new InstallLifecycleBusyException('lifecycle: не открыть общий файл замка');
    }
    if (filesize($file) === 0) storeWritePrivateHeader($fh, $file);
    elseif (!storeApplyPrivatePermissions($file)) {
        throw new InstallLifecycleBusyException('lifecycle: не выставить приватные права на файл замка');
    }

    $deadline = microtime(true) + max(0.0, $timeout);
    // Пока ждём эксклюзив — держим заявку свежей, чтобы фоновый работник видел,
    // что его ждут, и не забирал общий замок себе снова (см. заявку выше).
    $keepWaitMark = static function (): void {
        static $last = 0.0;
        $now = microtime(true);
        if ($now - $last < 2.0) return;
        $last = $now;
        appLifecycleAnnounceWriterWait();
    };
    $take = static function ($handle, int $wanted, float $until, ?callable $tick = null): bool {
        do {
            if (flock($handle, $wanted | LOCK_NB)) return true;
            if ($tick !== null) $tick();
            usleep(20000);
        } while (microtime(true) < $until);
        return false;
    };

    if ($mode === LOCK_EX) {
        appLifecycleAnnounceWriterWait();
        if (!$take($fh, LOCK_EX, $deadline, $keepWaitMark)) {
            appLifecycleForgetWriterWait();
            fclose($fh);
            throw new InstallLifecycleBusyException('lifecycle: установка меняется дольше ' . $timeout . ' с');
        }
        // Замок наш — ждать больше нечего, заявку снимаем. Второй ожидающий,
        // если он есть, вернёт свою через пару секунд сам.
        appLifecycleForgetWriterWait();
        try {
            // Любой EX-вход, а не только install-flow, начинает с recovery.
            // Иначе appEnsureInstallGeneration/cleanup-helper мог прочитать и
            // переписать state незавершённого transition до его владельца.
            if (is_file(appStateTransitionManifestFile())) {
                appRecoverStateTransitionLocked();
                $tokens = storeRead(B24_TOKENS_FILE);
                appBindLogGeneration(is_array($tokens) ? appInstallGenerationFrom($tokens) : null);
            }
        } catch (Throwable $e) {
            flock($fh, LOCK_UN);
            fclose($fh);
            throw $e;
        }
        $GLOBALS['__app_lifecycle_lock'] = ['handle' => $fh, 'mode' => LOCK_EX, 'depth' => 1];
        return;
    }

    // Оптимистично берём SH. Если preloaded-процесс ждал writer, который погиб
    // с manifest, читать state ещё нельзя: отпускаем SH, берём EX, выполняем
    // recovery и только затем повторяем выдачу SH. Upgrade SH→EX не делаем —
    // он способен взаимно заблокировать двух читателей.
    while (true) {
        if (!$take($fh, LOCK_SH, $deadline)) {
            fclose($fh);
            throw new InstallLifecycleBusyException('lifecycle: установка меняется дольше ' . $timeout . ' с');
        }
        if (!is_file(appStateTransitionManifestFile())) {
            $GLOBALS['__app_lifecycle_lock'] = ['handle' => $fh, 'mode' => LOCK_SH, 'depth' => 1];
            return;
        }
        flock($fh, LOCK_UN);
        appLifecycleAnnounceWriterWait();
        if (!$take($fh, LOCK_EX, $deadline, $keepWaitMark)) {
            appLifecycleForgetWriterWait();
            fclose($fh);
            throw new InstallLifecycleBusyException('lifecycle: recovery не начался за ' . $timeout . ' с');
        }
        appLifecycleForgetWriterWait();
        try {
            appRecoverStateTransitionLocked();
            $tokens = storeRead(B24_TOKENS_FILE);
            appBindLogGeneration(is_array($tokens) ? appInstallGenerationFrom($tokens) : null);
        } catch (Throwable $e) {
            flock($fh, LOCK_UN);
            fclose($fh);
            throw $e;
        }
        flock($fh, LOCK_UN);
    }
}

/** Отпустить один уровень реентерабельного lifecycle-lock. */
function appLifecycleRelease(): void {
    $held = $GLOBALS['__app_lifecycle_lock'] ?? null;
    if (!is_array($held) || !isset($held['handle'], $held['depth'])) return;
    $depth = (int)$held['depth'] - 1;
    if ($depth > 0) {
        $held['depth'] = $depth;
        $GLOBALS['__app_lifecycle_lock'] = $held;
        return;
    }
    $fh = $held['handle'];
    unset($GLOBALS['__app_lifecycle_lock']);
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** @return mixed */
function appWithLifecycleReadLock(callable $work, float $timeout = STORE_LOCK_TIMEOUT) {
    appLifecycleAcquire(LOCK_SH, $timeout);
    try { return $work(); }
    finally { appLifecycleRelease(); }
}

/** @return mixed */
function appWithLifecycleWriteLock(callable $work, float $timeout = 25.0) {
    appLifecycleAcquire(LOCK_EX, $timeout);
    try { return $work(); }
    finally { appLifecycleRelease(); }
}

/**
 * Выполнить короткое чтение/изменение state только пока установка та же.
 *
 * Нужен операциям, которые отпускают lifecycle-lock ради долгого сетевого
 * вызова. Их поздний callback не вправе записать служебную метку установки A
 * уже после того, как migration опубликовал B. Сравниваем и generation, и фазу:
 * пустая первая установка и tombstone с одинаковым пустым id — разные вещи.
 *
 * @return mixed
 */
function appWithMatchingInstallationReadLock(array $expectedTokens, callable $work,
    float $timeout = STORE_LOCK_TIMEOUT) {
    $expectedGeneration = appInstallGenerationFrom($expectedTokens);
    $expectedPhase = appInstallationPhaseFrom($expectedTokens);
    if ($expectedGeneration === '' && $expectedPhase === 'installed'
        && empty($expectedTokens['access_token'])) {
        $expectedGeneration = appKeylessInstallGeneration();
    }
    return appWithLifecycleReadLock(static function () use (
        $expectedGeneration, $expectedPhase, $work
    ) {
        $current = storeRead(B24_TOKENS_FILE) ?? [];
        $currentPhase = appInstallationPhaseFrom($current);
        $currentGeneration = appInstallGenerationFrom($current);
        if ($currentGeneration === '' && $currentPhase === 'installed'
            && empty($current['access_token'])) {
            $currentGeneration = appKeylessInstallGeneration();
        }
        if (!hash_equals($expectedGeneration, $currentGeneration)
            || $currentPhase !== $expectedPhase) {
            throw new InstallGenerationMismatchException(
                'Установка изменилась до записи служебного состояния'
            );
        }
        return $work();
    }, $timeout);
}

/**
 * Поставить сквозной shared-fence для API-запроса и вернуть его поколение.
 * Замок освобождается PHP shutdown'ом, то есть после всех endpoint-мутаций.
 */
function appLifecycleHoldRequest(): string {
    $known = $GLOBALS['__app_request_install_generation'] ?? null;
    if (is_string($known) && $known !== '') return $known;

    // Legacy-установке поколение выдаём ДО shared-lock: повышение SH→EX запрещено.
    $before = storeRead(B24_TOKENS_FILE);
    if (is_array($before) && !empty($before['access_token'])
        && empty($before['uninstalled_at']) && empty($before['cleanup_pending'])
        && appInstallGenerationFrom($before) === '') {
        appEnsureInstallGeneration();
    }

    appLifecycleAcquire(LOCK_SH, STORE_LOCK_TIMEOUT);
    try {
        $tokens = storeRead(B24_TOKENS_FILE);
        $generation = is_array($tokens) ? appInstallGenerationFrom($tokens) : '';
        // Работа постоянным ключом: установки под приложением нет, значит нет и
        // токенов с поколением — а без поколения этот гейт отвечал «приложение
        // ещё не установлено» на КАЖДЫЙ запрос, и приложение с рабочим ключом
        // было недоступно вообще никому. Удаление и уборку ключ не перебивает:
        // они проверены ниже и по-прежнему останавливают запрос.
        $tokensArr = is_array($tokens) ? $tokens : [];
        if ($generation === '' && empty($tokensArr['access_token'])
            && empty($tokensArr['uninstalled_at']) && empty($tokensArr['cleanup_pending'])) {
            $generation = appKeylessInstallGeneration();
        }
        if ((!is_array($tokens) && $generation === '')
            || (is_array($tokens) && empty($tokens['access_token']) && $generation === '')
            || !empty($tokensArr['uninstalled_at']) || !empty($tokensArr['cleanup_pending'])
            || $generation === '') {
            // Фазу передаём наружу: «ещё не устанавливали», «удалили», «идёт
            // очистка» и «сменилось поколение» требуют четырёх разных действий.
            $phase = is_array($tokens) ? appInstallationPhaseFrom($tokens) : 'empty';
            if ($phase === 'installed') $phase = 'changed';   // токены есть, а поколения нет
            throw InstallGenerationMismatchException::forPhase(
                'Активная установка сменилась или удалена', $phase
            );
        }
        $GLOBALS['__app_request_install_generation'] = $generation;
        // Тот же canonical generation нужен журналу этого запроса. В
        // webhook-only состоянии ранний bootstrap lib.php не мог взять его из
        // пустого token-state, поэтому привязываем сразу после lifecycle gate.
        appBindLogGeneration($generation);
        if (empty($GLOBALS['__app_request_lifecycle_shutdown'])) {
            $GLOBALS['__app_request_lifecycle_shutdown'] = true;
            register_shutdown_function(static function (): void {
                unset($GLOBALS['__app_request_install_generation']);
                appLifecycleRelease();
            });
        }
        return $generation;
    } catch (Throwable $e) {
        appLifecycleRelease();
        throw $e;
    }
}

/**
 * Одноразовая миграция уже установленного приложения на generation-протокол.
 *
 * Два параллельных запроса получают одно и то же значение: проверка и запись
 * идут под замком токенов. Пустое состояние и старое надгробие не оживляем.
 */
function appEnsureInstallGeneration(): ?string {
    return appWithLifecycleWriteLock(static function (): ?string {
        return appEnsureInstallGenerationLocked();
    });
}

/** Внутренняя часть legacy-миграции; вызывающий уже держит lifecycle EX. */
function appEnsureInstallGenerationLocked(): ?string {
    $snapshot = storeRead(B24_TOKENS_FILE);
    if (!is_array($snapshot)) return null;
    $known = appInstallGenerationFrom($snapshot);
    if ($known !== '') return $known;
    if (empty($snapshot['access_token']) || !empty($snapshot['uninstalled_at'])) return null;

    $updated = storeUpdate(B24_TOKENS_FILE, static function (array $tokens): ?array {
        if (appInstallGenerationFrom($tokens) !== '') return null;
        if (empty($tokens['access_token']) || !empty($tokens['uninstalled_at'])) return null;
        $tokens['install_generation'] = appNewInstallGeneration();
        return $tokens;
    });
    $generation = appInstallGenerationFrom($updated);
    return $generation !== '' ? $generation : null;
}

/** Текущее поколение активной установки или надгробия. */
function appCurrentInstallGeneration(): ?string {
    $tokens = storeRead(B24_TOKENS_FILE);
    $tokensArr = is_array($tokens) ? $tokens : [];
    $generation = appInstallGenerationFrom($tokensArr);
    if ($generation === '' && !empty($tokensArr['access_token']) && empty($tokensArr['uninstalled_at'])) {
        return appEnsureInstallGeneration();
    }
    // Работа постоянным ключом: поколение производно от ключа. Без него журнал и
    // аудит остались бы без пометки «чья это установка», а запрос — без ответа на
    // вопрос «та же привязка или уже другая». Удалённую установку не воскрешаем.
    if ($generation === '' && empty($tokensArr['access_token'])
        && empty($tokensArr['uninstalled_at']) && empty($tokensArr['cleanup_pending'])) {
        $webhookGeneration = appKeylessInstallGeneration();
        if ($webhookGeneration !== '') return $webhookGeneration;
    }
    return $generation !== '' ? $generation : null;
}

/** Незавершённая двухфазная уборка, которую должен продолжить cron. */
function appPendingCleanupGeneration(): ?string {
    $tokens = storeRead(B24_TOKENS_FILE);
    if (!is_array($tokens) || empty($tokens['cleanup_pending'])) return null;
    $generation = appInstallGenerationFrom($tokens);
    return $generation !== '' ? $generation : null;
}

/** Привязать все журнальные строки этого запроса к его снимку установки. */
function appBindLogGeneration(?string $generation): void {
    $GLOBALS['__app_log_install_generation'] = is_string($generation) ? trim($generation) : '';
}

// Снимок берём при загрузке общего helper-файла, то есть в начале запроса, а не
// лениво в момент записи лога. Иначе запрос A, зависший до переустановки B,
// прочитал бы уже B и пометил старые данные новым поколением.
if (!array_key_exists('__app_log_install_generation', $GLOBALS)) {
    try {
        $logTokens = storeRead(B24_TOKENS_FILE);
        appBindLogGeneration(is_array($logTokens) ? appInstallGenerationFrom($logTokens) : null);
    } catch (Throwable $e) {
        appBindLogGeneration(null);
    }
    unset($logTokens);
}

/** Стабильный замок writer/rotation/cleanup журнала; файл живёт вне logs/. */
function appJournalMutationLockAcquire(float $timeout = 25.0): void {
    $held = $GLOBALS['__app_journal_mutation_lock'] ?? null;
    if (is_array($held) && isset($held['handle'], $held['depth'])) {
        $held['depth'] = (int)$held['depth'] + 1;
        $GLOBALS['__app_journal_mutation_lock'] = $held;
        return;
    }

    storeEnsureDir(DATA_ROOT);
    $file = DATA_ROOT . '/journal.lock.php';
    $fh = @fopen($file, 'c+b');
    if ($fh === false) throw new RuntimeException('journal: не открыть стабильный файл замка');
    if (!storeApplyPrivatePermissions($file)) {
        throw new RuntimeException('journal: не выставить приватные права на файл замка');
    }
    $deadline = microtime(true) + max(0.0, $timeout);
    $locked = false;
    do {
        if (@flock($fh, LOCK_EX | LOCK_NB)) { $locked = true; break; }
        usleep(20000);
    } while (microtime(true) < $deadline);
    if (!$locked) {
        fclose($fh);
        throw new RuntimeException('journal: writer/cleanup занят дольше ' . $timeout . ' с');
    }
    $stat = @fstat($fh);
    if ((int)($stat['size'] ?? 0) === 0) {
        $header = BXAPP_PRIVATE_PHP_HEADER;
        if (@fwrite($fh, $header) !== strlen($header) || !@fflush($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
            throw new RuntimeException('journal: не записать стабильный файл замка');
        }
    }
    $GLOBALS['__app_journal_mutation_lock'] = ['handle' => $fh, 'depth' => 1];
}

function appJournalMutationLockHeld(): bool {
    $held = $GLOBALS['__app_journal_mutation_lock'] ?? null;
    return is_array($held) && isset($held['handle'], $held['depth']);
}

function appJournalMutationLockRelease(): void {
    $held = $GLOBALS['__app_journal_mutation_lock'] ?? null;
    if (!is_array($held) || !isset($held['handle'], $held['depth'])) return;
    $depth = (int)$held['depth'] - 1;
    if ($depth > 0) {
        $held['depth'] = $depth;
        $GLOBALS['__app_journal_mutation_lock'] = $held;
        return;
    }
    $fh = $held['handle'];
    unset($GLOBALS['__app_journal_mutation_lock']);
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** @return mixed */
function appWithJournalMutationLock(callable $work, float $timeout = 25.0) {
    appJournalMutationLockAcquire($timeout);
    try { return $work(); }
    finally { appJournalMutationLockRelease(); }
}

/**
 * Удалить физические журналы прежней установки.
 *
 * Поздняя строка уже начавшегося запроса A всё ещё возможна после unlink, поэтому
 * это дополняет, а не заменяет generation-фильтр journal.php. Любая ошибка
 * возвращается вызывающему и оставляет cleanup_pending — новая установка не
 * должна получить доступ к старому журналу.
 *
 * @return array{cleared:string[],failed:string[]}
 */
function appPurgeJournalFiles(): array {
    return appWithJournalMutationLock(static function (): array {
        $dir = DATA_ROOT . '/logs';
        if (!is_dir($dir)) return ['cleared' => [], 'failed' => []];
        $entries = @scandir($dir);
        if (!is_array($entries)) return ['cleared' => [], 'failed' => ['logs']];

        $cleared = [];
        $failed = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            $label = 'logs/' . $entry;
            // Не удаляем неизвестные подкаталоги рекурсивно: это уже отдельное
            // состояние проекта. Считаем уборку незавершённой и требуем явного
            // решения, чтобы случайно не снести чужие файлы.
            if (is_dir($path) && !is_link($path)) {
                $failed[] = $label;
                continue;
            }
            if (@unlink($path) || (!is_file($path) && !is_link($path))) $cleared[] = $label;
            else $failed[] = $label;
        }
        return ['cleared' => $cleared, 'failed' => $failed];
    });
}

/**
 * Файлы журнала, которые относятся к текущей установке и должны исчезнуть при
 * межпортальном переходе. Никаких мутаций: список нужен транзакции ниже, чтобы
 * ошибка в одном state-файле не успела удалить журнал или соседнее состояние.
 *
 * @return string[]
 */
function appJournalTransitionTargets(): array {
    if (!appJournalMutationLockHeld()) {
        throw new LogicException('journal: transition targets требуют стабильный cleanup-lock');
    }
    $dir = DATA_ROOT . '/logs';
    if (!file_exists($dir) && !is_link($dir)) return [];
    if (is_link($dir) || !is_dir($dir)) {
        throw new InstallCleanupIncompleteException(['logs']);
    }
    $entries = @scandir($dir);
    if (!is_array($entries)) throw new InstallCleanupIncompleteException(['logs']);

    $files = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            throw new InstallCleanupIncompleteException(['logs/' . $entry]);
        }
        if (is_file($path) || is_link($path)) $files[] = $path;
        elseif (file_exists($path)) throw new InstallCleanupIncompleteException(['logs/' . $entry]);
    }
    return $files;
}

/** Безопасно подготовить закрытый временный файл одной state-транзакции. */
function appStageStatePayload(string $path, string $payload): void {
    $fh = @fopen($path, 'xb');
    if ($fh === false) throw new RuntimeException('reset: не создать временный файл ' . basename($path));
    $ok = @chmod($path, 0600);
    if ($ok) $ok = @fwrite($fh, $payload) === strlen($payload);
    if ($ok) $ok = @fflush($fh);
    if ($ok && function_exists('fsync')) $ok = @fsync($fh);
    if (!@fclose($fh)) $ok = false;
    if (!$ok) {
        @unlink($path);
        throw new RuntimeException('reset: временная запись ' . basename($path) . ' оборвалась');
    }
}

/** Манифест незавершённой межфайловой транзакции. */
function appStateTransitionManifestFile(): string {
    return DATA_ROOT . '/state-transition.php';
}

/** Есть ли обычный файл либо symlink по указанному пути. */
function appTransitionPathExists(string $path): bool {
    return is_file($path) || is_link($path);
}

/**
 * Проверить один путь transition до первой мутации.
 *
 * DATA_ROOT в immutable-deploy указывает прямо на private shared/data; ни один
 * контролируемый компонент или путь ВНУТРИ него symlink быть не вправе. Лексического prefix
 * недостаточно: DATA_ROOT/../outside.php и data/cache-link/file.php иначе
 * выводят rename/unlink за пределы state.
 */
function appAssertTransitionManagedPath(string $path): void {
    $root = rtrim(DATA_ROOT, '/');
    if ($root === '' || $root[0] !== '/' || !is_dir($root)
        || $path === '' || strpos($path, "\0") !== false
        || strncmp($path, $root . '/', strlen($root) + 1) !== 0
        || substr($path, -4) !== '.php') {
        throw new RuntimeException('reset: путь transition вне DATA_ROOT');
    }

    $relative = substr($path, strlen($root) + 1);
    $parts = explode('/', $relative);
    if ($relative === '' || in_array('', $parts, true) || in_array('.', $parts, true)
        || in_array('..', $parts, true) || $path === appStateTransitionManifestFile()) {
        throw new RuntimeException('reset: недопустимый путь transition');
    }

    $probe = $root;
    $last = count($parts) - 1;
    for ($i = 0; $i < $last; $i++) {
        $probe .= '/' . $parts[$i];
        if (is_link($probe) || !is_dir($probe)) {
            throw new RuntimeException('reset: вложенный каталог transition недоступен или является symlink');
        }
    }
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('reset: transition разрешён только для обычного state-файла');
    }
}

/**
 * Разобрать manifest как недоверенное состояние ДО первого rename/unlink.
 * Возвращается только каноническое подмножество полей, которое recovery вправе
 * снова записать при переходе в фазу rollback.
 *
 * @return array{tx:string,created_at:string,phase:string,operations:array<int,array>}
 */
function appValidateStateTransitionManifest(array $manifest): array {
    $tx = $manifest['tx'] ?? null;
    $operations = $manifest['operations'] ?? null;
    $phase = $manifest['phase'] ?? 'applying'; // manifest предыдущей версии
    if (!is_string($tx) || preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/D', $tx) !== 1
        || !is_string($phase) || !in_array($phase, ['preparing', 'applying', 'rolling_back'], true)
        || !is_array($operations) || $operations === []
        || array_keys($operations) !== range(0, count($operations) - 1)) {
        throw new RuntimeException('reset: повреждён заголовок manifest transition');
    }

    $validated = [];
    $claimed = [];
    $last = count($operations) - 1;
    foreach ($operations as $index => $operation) {
        if (!is_array($operation) || !is_string($operation['path'] ?? null)
            || !array_key_exists('stage', $operation)
            || !is_string($operation['backup'] ?? null)
            || !array_key_exists('had', $operation) || !is_bool($operation['had'])) {
            throw new RuntimeException('reset: повреждена операция manifest transition');
        }
        $path = (string)$operation['path'];
        $stage = $operation['stage'];
        $backup = (string)$operation['backup'];
        $had = $operation['had'];
        if ($stage !== null && !is_string($stage)) {
            throw new RuntimeException('reset: повреждён stage manifest transition');
        }
        if ($stage === null && !$had) {
            throw new RuntimeException('reset: delete transition не имел исходного файла');
        }

        appAssertTransitionManagedPath($path);
        $expectedStage = $path . '.transition-' . $tx . '.php';
        $expectedBackup = $path . '.rollback-' . $tx . '.php';
        if (($stage !== null && $stage !== $expectedStage) || $backup !== $expectedBackup) {
            throw new RuntimeException('reset: имена артефактов не соответствуют tx');
        }
        if ($stage !== null) appAssertTransitionManagedPath($stage);
        appAssertTransitionManagedPath($backup);

        $artifacts = [$path, $backup];
        if (is_string($stage)) $artifacts[] = $stage;
        foreach ($artifacts as $artifact) {
            if (isset($claimed[$artifact])) {
                throw new RuntimeException('reset: пути операций manifest пересекаются');
            }
            $claimed[$artifact] = true;
        }
        if ($path === B24_TOKENS_FILE && $index !== $last) {
            throw new RuntimeException('reset: токены не являются последним commit marker');
        }

        $validated[] = ['path' => $path, 'stage' => $stage,
            'backup' => $backup, 'had' => $had];
    }

    $createdAt = is_string($manifest['created_at'] ?? null)
        ? (string)$manifest['created_at'] : '';
    return ['tx' => $tx, 'created_at' => $createdAt,
        'phase' => $phase, 'operations' => $validated];
}

/**
 * Невалидный manifest копируем в quarantine и оставляем на месте: пока человек
 * его не разберёт, переход fail-closed и никаких указанных им путей не трогает.
 */
function appReadValidatedStateTransitionManifest(string $manifestFile): array {
    try {
        $manifest = storeRead($manifestFile);
        if (!is_array($manifest)) throw new RuntimeException('reset: manifest transition исчез');
        return appValidateStateTransitionManifest($manifest);
    } catch (StoreCorruptedException $e) {
        throw $e;
    } catch (StoreUnreadableException $e) {
        // Файл есть, но прочитать его не дали. Это не порча содержимого:
        // класть в карантин пустую копию и объявлять манифест испорченным
        // значило бы обвинить данные в чужой беде с правами доступа.
        throw $e;
    } catch (Throwable $e) {
        $raw = (string)@file_get_contents($manifestFile);
        throw new StoreCorruptedException($manifestFile, storeQuarantine($manifestFile, $raw));
    }
}

/** Снимок существования одной уже провалидированной операции. */
function appTransitionOperationSnapshot(array $operation): array {
    return [
        'path' => appTransitionPathExists((string)$operation['path']),
        'stage' => is_string($operation['stage'])
            && appTransitionPathExists((string)$operation['stage']),
        'backup' => appTransitionPathExists((string)$operation['backup']),
    ];
}

/**
 * Состояние операции в фазе применения. Любая невозможная комбинация — стоп,
 * а не догадка с удалением единственной оставшейся копии.
 */
function appTransitionApplyingState(array $operation): string {
    $exists = appTransitionOperationSnapshot($operation);
    $had = $operation['had'] === true;
    $isWrite = is_string($operation['stage']);

    if ($isWrite && $had) {
        if ($exists['path'] && $exists['stage'] && !$exists['backup']) return 'untouched';
        if (!$exists['path'] && $exists['stage'] && $exists['backup']) return 'backed_up';
        if ($exists['path'] && !$exists['stage'] && $exists['backup']) return 'installed';
        if ($exists['path'] && !$exists['stage'] && !$exists['backup']) return 'cleaned';
    } elseif ($isWrite) {
        if (!$exists['path'] && $exists['stage'] && !$exists['backup']) return 'untouched';
        if ($exists['path'] && !$exists['stage'] && !$exists['backup']) return 'installed';
    } else {
        if ($exists['path'] && !$exists['backup']) return 'untouched';
        if (!$exists['path'] && $exists['backup']) return 'installed';
        if (!$exists['path'] && !$exists['backup']) return 'cleaned';
    }
    throw new InstallCleanupIncompleteException([basename((string)$operation['path'])]);
}

/** Проверить состояния после уже начатого recovery rollback. */
function appAssertTransitionRollbackState(array $operation): void {
    $exists = appTransitionOperationSnapshot($operation);
    $had = $operation['had'] === true;
    $isWrite = is_string($operation['stage']);
    $valid = false;

    if ($isWrite && $had) {
        $valid = $exists['backup']
            ? ((!$exists['path'] && $exists['stage']) || ($exists['path'] && !$exists['stage']))
            : $exists['path'];
    } elseif ($isWrite) {
        $valid = !$exists['backup'] && !($exists['path'] && $exists['stage']);
    } else {
        $valid = ($exists['path'] && !$exists['backup'])
            || (!$exists['path'] && $exists['backup']);
    }
    if (!$valid) {
        throw new InstallCleanupIncompleteException([basename((string)$operation['path'])]);
    }
}

/**
 * Восстановить оборванную транзакцию по состоянию её stage/backup-файлов.
 * Вызывающий держит lifecycle EX: промежуточные rename никому не видны.
 */
function appRecoverStateTransitionLocked(): void {
    $manifestFile = appStateTransitionManifestFile();
    if (!is_file($manifestFile)) return;
    $manifest = appReadValidatedStateTransitionManifest($manifestFile);
    $operations = $manifest['operations'];
    $phase = (string)$manifest['phase'];
    $journalLocked = false;
    $journalPrefix = rtrim(DATA_ROOT, '/') . '/logs/';
    foreach ($operations as $operation) {
        $path = (string)$operation['path'];
        if (strncmp($path, $journalPrefix, strlen($journalPrefix)) === 0) {
            appJournalMutationLockAcquire();
            $journalLocked = true;
            break;
        }
    }

    try {
        // Manifest пишется ДО первого stage. Поэтому process crash при подготовке
        // оставляет точный перечень только своих orphan-файлов: можно удалить их
        // без glob и не задеть неизвестный либо активный transition.
        if ($phase === 'preparing') {
            foreach ($operations as $operation) {
                $exists = appTransitionOperationSnapshot($operation);
                if ($exists['backup'] || $exists['path'] !== ($operation['had'] === true)) {
                    throw new InstallCleanupIncompleteException([basename((string)$operation['path'])]);
                }
            }
            foreach ($operations as $operation) {
                $stage = $operation['stage'];
                if (is_string($stage) && appTransitionPathExists($stage) && !@unlink($stage)) {
                    throw new InstallCleanupIncompleteException([basename($stage)]);
                }
            }
            if (!@unlink($manifestFile) && is_file($manifestFile)) {
                throw new InstallCleanupIncompleteException([basename($manifestFile)]);
            }
            return;
        }

        if ($phase === 'rolling_back') {
            foreach ($operations as $operation) appAssertTransitionRollbackState($operation);
            $committed = false;
        } else {
            $states = [];
            foreach ($operations as $operation) $states[] = appTransitionApplyingState($operation);
            $markerState = $states[count($states) - 1];
            $committed = $markerState === 'installed' || $markerState === 'cleaned';

            if ($committed) {
                foreach ($states as $index => $state) {
                    if ($state !== 'installed' && $state !== 'cleaned') {
                        throw new InstallCleanupIncompleteException([
                            basename((string)$operations[$index]['path']),
                        ]);
                    }
                }
            } else {
                // Прямой apply идёт строго слева направо: installed*, максимум один
                // backed_up, затем untouched*. Cleanup до marker ещё невозможен.
                $previousRank = 2;
                foreach ($states as $index => $state) {
                    $rank = $state === 'installed' ? 2 : ($state === 'backed_up' ? 1 : 0);
                    if ($state === 'cleaned' || $rank > $previousRank) {
                        throw new InstallCleanupIncompleteException([
                            basename((string)$operations[$index]['path']),
                        ]);
                    }
                    $previousRank = $rank;
                }
                // Если процесс погибнет уже во время rollback, следующему recovery
                // нужна отдельная фаза, чтобы отличить восстановленную old-цель от
                // опубликованной new-цели с пропавшим backup.
                $manifest['phase'] = 'rolling_back';
                storeWrite($manifestFile, $manifest);
            }
        }

        if ($committed) {
            // Marker публикуется последним, поэтому все предыдущие операции обязаны
            // уже иметь целевое состояние. Противоречие не «угадываем».
            foreach ($operations as $operation) {
                $path = (string)$operation['path'];
                $stage = $operation['stage'];
                if (is_string($stage) && appTransitionPathExists($stage) && !@unlink($stage)) {
                    throw new InstallCleanupIncompleteException([basename($stage)]);
                }
                $backup = (string)$operation['backup'];
                if (appTransitionPathExists($backup) && !@unlink($backup)) {
                    throw new InstallCleanupIncompleteException([basename($backup)]);
                }
            }
        } else {
            // Commit marker ещё не опубликован: возвращаем прежние файлы в обратном
            // порядке. Для изначально отсутствующей цели удаляем уже поставленный
            // новый файл; для существовавшей — backup является источником истины.
            for ($i = count($operations) - 1; $i >= 0; $i--) {
                $operation = $operations[$i];
                $path = (string)$operation['path'];
                $backup = (string)$operation['backup'];
                $stage = $operation['stage'];
                $had = $operation['had'] === true;
                if (appTransitionPathExists($backup)) {
                    if (appTransitionPathExists($path) && !@unlink($path)) {
                        throw new InstallCleanupIncompleteException([basename($path)]);
                    }
                    if (!@rename($backup, $path)) {
                        throw new InstallCleanupIncompleteException([basename($path)]);
                    }
                } elseif (!$had && is_string($stage) && !appTransitionPathExists($stage)
                    && appTransitionPathExists($path) && !@unlink($path)) {
                    throw new InstallCleanupIncompleteException([basename($path)]);
                }
                if (is_string($stage) && appTransitionPathExists($stage) && !@unlink($stage)) {
                    throw new InstallCleanupIncompleteException([basename($stage)]);
                }
            }
        }
        if (!@unlink($manifestFile) && is_file($manifestFile)) {
            throw new InstallCleanupIncompleteException([basename($manifestFile)]);
        }
    } finally {
        if ($journalLocked) appJournalMutationLockRelease();
    }
}

/**
 * Сменить несколько state-файлов как одну логическую транзакцию.
 *
 * Каждый новый payload сначала полностью пишется и fsync-ится рядом с целью.
 * Затем старые файлы переименовываются в закрытые backup-файлы, а новые одним
 * rename занимают их место. B24_TOKENS_FILE всегда публикуется ПОСЛЕДНИМ. Пока
 * держится lifecycle EX, штатные API/worker не видят промежуточное состояние.
 * Любая ошибка до публикации токенов откатывает уже сделанные rename.
 *
 * @param array<string,array> $writes path => новое state
 * @param string[] $deletes файлы, которые нужно удалить в той же транзакции
 * @return string[] Изменённые имена файлов
 */
function appCommitStateTransition(array $writes, array $deletes = []): array {
    // Предыдущий процесс мог погибнуть между rename. До новой транзакции
    // обязательно доводим его manifest до commit либо rollback.
    appRecoverStateTransitionLocked();
    $tx = getmypid() . '-' . bin2hex(random_bytes(6));
    $tokenWrite = null;
    $operations = [];
    $claimed = [];

    $appendWrite = static function (string $path, array $state) use (
        &$operations, &$claimed, $tx
    ): void {
        appAssertTransitionManagedPath($path);
        $stage = $path . '.transition-' . $tx . '.php';
        $backup = $path . '.rollback-' . $tx . '.php';
        appAssertTransitionManagedPath($stage);
        appAssertTransitionManagedPath($backup);
        foreach ([$path, $stage, $backup] as $artifact) {
            if (isset($claimed[$artifact])) {
                throw new RuntimeException('reset: повторная или пересекающаяся цель ' . basename($path));
            }
            $claimed[$artifact] = true;
        }
        if (appTransitionPathExists($stage) || appTransitionPathExists($backup)) {
            throw new RuntimeException('reset: tx-артефакт уже существует для ' . basename($path));
        }
        $operations[] = ['path' => $path, 'stage' => $stage, 'backup' => $backup,
            'had' => is_file($path), 'payload' => storeEncode($state),
            'backup_moved' => false, 'installed' => false];
    };

    try {
        // Токены — commit marker всей смены портала, поэтому ставим их в конец.
        foreach ($writes as $path => $state) {
            if (!is_string($path) || !is_array($state)) {
                throw new InvalidArgumentException('reset: writes должен содержать path => array');
            }
            if ($path === B24_TOKENS_FILE) { $tokenWrite = [$path, $state]; continue; }
            $appendWrite($path, $state);
        }
        foreach ($deletes as $path) {
            if (!is_string($path)) {
                throw new RuntimeException('reset: недопустимая или повторная цель удаления');
            }
            appAssertTransitionManagedPath($path);
            if (isset($claimed[$path])) {
                throw new RuntimeException('reset: повторная или пересекающаяся цель удаления');
            }
            if (!is_file($path)) continue;
            $backup = $path . '.rollback-' . $tx . '.php';
            appAssertTransitionManagedPath($backup);
            foreach ([$path, $backup] as $artifact) {
                if (isset($claimed[$artifact])) {
                    throw new RuntimeException('reset: повторная или пересекающаяся цель удаления');
                }
                $claimed[$artifact] = true;
            }
            if (appTransitionPathExists($backup)) {
                throw new RuntimeException('reset: rollback-артефакт уже существует для ' . basename($path));
            }
            $operations[] = ['path' => $path, 'stage' => null,
                'backup' => $backup, 'had' => true,
                'backup_moved' => false, 'installed' => false];
        }
        if (is_array($tokenWrite)) $appendWrite($tokenWrite[0], $tokenWrite[1]);
        if ($operations === []) return [];

        $manifest = [
            'tx' => $tx,
            'created_at' => date('c'),
            'phase' => 'preparing',
            'operations' => array_map(static function (array $operation): array {
                return [
                    'path' => $operation['path'],
                    'stage' => $operation['stage'],
                    'backup' => $operation['backup'],
                    'had' => $operation['had'],
                ];
            }, $operations),
        ];
        // Manifest появляется ДО первого stage: crash подготовки больше не
        // оставляет бесхозный файл с токенами без точного scoped-cleanup плана.
        // Здесь же отмечаем факт для ответа при аварийном завершении: дальше
        // переписываются НЕСКОЛЬКО файлов, и обрыв в середине оставляет
        // состояние наполовину применённым — обещать «данные целы» нельзя.
        appChangesStarted('перезапись состояния');
        storeWrite(appStateTransitionManifestFile(), $manifest);
        foreach ($operations as $operation) {
            if (is_string($operation['stage'])) {
                appStageStatePayload($operation['stage'], (string)$operation['payload']);
            }
        }
        $manifest['phase'] = 'applying';
        storeWrite(appStateTransitionManifestFile(), $manifest);

        foreach ($operations as $i => $operation) {
            $path = (string)$operation['path'];
            if (!empty($operation['had'])) {
                if (!@rename($path, (string)$operation['backup'])) {
                    throw new RuntimeException('reset: не отложить прежний ' . basename($path));
                }
                $operations[$i]['backup_moved'] = true;
            }
            if (is_string($operation['stage'])) {
                if (!@rename($operation['stage'], $path)) {
                    throw new RuntimeException('reset: не опубликовать новый ' . basename($path));
                }
                $operations[$i]['installed'] = true;
                if (!storeApplyPrivatePermissions($path)) {
                    throw new RuntimeException('reset: не выставить приватные права на ' . basename($path));
                }
            }
        }
    } catch (Throwable $failure) {
        if (is_file(appStateTransitionManifestFile())) {
            try { appRecoverStateTransitionLocked(); }
            catch (Throwable $rollbackFailure) {
                throw new InstallCleanupIncompleteException([
                    basename(appStateTransitionManifestFile()),
                    maskSecrets($rollbackFailure->getMessage()),
                ]);
            }
            throw $failure;
        }
        $rollbackFailed = [];
        for ($i = count($operations) - 1; $i >= 0; $i--) {
            $operation = $operations[$i];
            $path = (string)$operation['path'];
            if (!empty($operation['installed']) && (is_file($path) || is_link($path)) && !@unlink($path)) {
                $rollbackFailed[] = basename($path);
            }
            if (!empty($operation['backup_moved'])
                && !@rename((string)$operation['backup'], $path)) {
                $rollbackFailed[] = basename($path);
            }
            if (is_string($operation['stage']) && (is_file($operation['stage']) || is_link($operation['stage']))) {
                @unlink($operation['stage']);
            }
        }
        if ($rollbackFailed !== []) {
            throw new InstallCleanupIncompleteException(array_values(array_unique($rollbackFailed)));
        }
        throw $failure;
    }

    $changed = [];
    $cleanupOk = true;
    foreach ($operations as $operation) {
        $changed[] = basename((string)$operation['path']);
        $backup = (string)$operation['backup'];
        if (appTransitionPathExists($backup) && !@unlink($backup)) $cleanupOk = false;
    }
    $manifestFile = appStateTransitionManifestFile();
    if ($cleanupOk && !@unlink($manifestFile) && is_file($manifestFile)) {
        // Commit уже опубликован. Манифест оставляем намеренно: следующий
        // запрос безопасно повторит только cleanup, а не откатит новый портал.
    }
    return $changed;
}

// SIGKILL/аварийное завершение процесса не вызывает catch/finally. Если остался
// manifest, восстанавливаем согласованное состояние ДО первого API/worker,
// который мог бы прочитать частично переименованные файлы. Это process-crash
// гарантия: без fsync каталогов порядок rename при потере питания не обещаем.
if (is_file(appStateTransitionManifestFile())) {
    try {
        appWithLifecycleWriteLock(static function (): void {
            appRecoverStateTransitionLocked();
            $tokens = storeRead(B24_TOKENS_FILE);
            appBindLogGeneration(is_array($tokens) ? appInstallGenerationFrom($tokens) : null);
        }, 25.0);
    } catch (Throwable $e) {
        // ОТКАЗ ВОССТАНОВЛЕНИЯ ОБЯЗАН БЫТЬ ВИДЕН.
        //
        // Этот блок выполняется при ЗАГРУЗКЕ файла, то есть раньше, чем точка
        // входа успела позвать installFailureHandler(). Пока своего перехвата
        // здесь не было, исключение уходило в пустоту: человек получал белый
        // экран с 500, а в журнале не появлялось ни строки — то есть самый
        // тяжёлый случай (состояние осталось наполовину переименованным) был
        // ещё и самым немым.
        appRecoveryFailureResponse($e);
    }
}

/**
 * Что показать, когда восстановление после аварийного завершения не удалось.
 *
 * Продолжать нельзя: на диске лежит наполовину применённая транзакция состояния,
 * и любая следующая запись затрёт то, что ещё можно спасти. Поэтому здесь только
 * два дела — оставить след в журнале и честно сказать, что происходит.
 */
function appRecoveryFailureResponse(Throwable $e): void {
    $ref = '';
    try {
        $ref = logAppError('ФАТАЛ восстановления состояния: '
            . get_class($e) . ': ' . maskSecrets($e->getMessage()));
    } catch (Throwable $ignored) {
        // Журнал сам может быть частью незавершённой транзакции — тогда пишем
        // хотя бы в журнал сервера, иначе следа не останется вовсе.
        // Даже в аварийном пути текст идёт через маскировку: сообщение
        // исключения от HTTP-клиента несёт URL с токеном целиком, а журнал
        // сервера переживёт и ротацию токена, и передачу проекта.
        @error_log('bx-app: восстановление состояния не удалось: ' . maskSecrets($e->getMessage()));
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Приложение не восстановило состояние после аварийного завершения.\n"
            . ($ref !== '' ? "Код обращения: $ref\n" : '')
            . "Разбор — по журналу приложения; до этого запись состояния остановлена.\n");
        exit(1);
    }

    renderServicePage(
        'Приложение восстанавливается после сбоя',
        'Прошлый запрос оборвался на середине записи состояния, и завершить её не удалось. '
        . 'Приложение остановлено намеренно: если продолжить, поверх недописанных данных '
        . 'запишутся новые и восстановить будет нечего.',
        'Подождите минуту и откройте приложение заново. Если повторяется — назовите разработчику '
        . ($ref !== '' ? 'код обращения ' . $ref : 'время открытия') . '.',
        ['code' => 503, 'tone' => 'warn']
    );
    exit;
}

function appInstalled(): bool {
    try {
        $t = storeRead(B24_TOKENS_FILE);
    } catch (Throwable $e) {
        // Повреждённое состояние — это НЕ «приложение не установлено». Отвечаем
        // fail-closed, но молчать нельзя: иначе владелец видит «приложение не
        // установлено», переустанавливает по кругу, а причина (битый файл
        // токенов) не всплывает нигде.
        logAppError('состояние установки не читается: ' . maskSecrets($e->getMessage()));
        return false;
    }
    return appInstallationPhaseFrom(is_array($t) ? $t : []) === 'installed';
}

/**
 * Очистить состояние, привязанное к конкретной регистрации local-app.
 *
 * member_id при пересоздании приложения на том же портале не меняется, но
 * сессии, placement fingerprint и события прежней регистрации уже недействительны.
 * Пользовательские settings/ACL/switches сохраняем: портал и его user ID те же.
 * OPS тоже сохраняем намеренно — забытый runOnce разрешил бы повтор внешнего
 * эффекта (платёж/создание сущности) только из-за перевыпуска client_id.
 * Долгую задачу (TASK_FILE) по той же причине НЕ трогаем: портал и его данные
 * те же, курсор остаётся верным, и работа честно продолжится с той же позиции.
 * При смене портала и при удалении приложения — наоборот, обязательно чистим.
 *
 * @return array<string,array> План новых значений без мутаций.
 */
function appRegistrationBoundResetTargets(): array {
    $oldState = storeRead(STATE_FILE) ?? [];
    $state = [];
    $appUrl = is_string($oldState['app_url'] ?? null) ? trim((string)$oldState['app_url']) : '';
    if ($appUrl !== '' && strncmp($appUrl, 'https://', 8) === 0) $state['app_url'] = $appUrl;

    return [
        SESSIONS_FILE   => [],
        STATE_FILE      => $state,
        RATELIMIT_FILE  => [],
        DEADLETTER_FILE => [],
        INBOX_FILE      => [],
        NOTIFY_OUTBOX_FILE => [],
    ];
}

/**
 * Проверить пользовательские state-файлы, которые разрешено очищать вместе с
 * установкой.
 *
 * APP_PURGE_EXTRA — конфигурация, а не доверенный runtime-ввод. Но ошибка в ней
 * особенно опасна: раньше туда можно было случайно добавить b24-tokens.php,
 * inbox.php либо путь через `..`, после чего reset/uninstall нарушал собственный
 * CAS и порядок двухфазной уборки. Поэтому неверна одна запись — не трогаем
 * вообще ничего и падаем до первой мутации.
 *
 * Разрешены только отдельные `.php`-файлы строго внутри DATA_ROOT. Core-state,
 * lock-файлы, credentials и журналы обслуживаются своими протоколами и всегда
 * запрещены. Проверяем также уже существующие symlink-компоненты, чтобы
 * лексически внутренний путь не оказался физически снаружи DATA_ROOT.
 *
 * @param mixed[] $extras
 * @return string[] Канонические относительно строки DATA_ROOT пути без дублей.
 */
function appNormalizePurgeExtraFiles(array $extras): array {
    $root = rtrim(DATA_ROOT, '/');
    $rootReal = realpath(DATA_ROOT);
    if ($root === '' || $root[0] !== '/' || $rootReal === false || !is_dir($rootReal)) {
        throw new InstallPurgeConfigurationException('APP_PURGE_EXTRA: DATA_ROOT недоступен');
    }
    $rootReal = rtrim($rootReal, '/');

    $reserved = [
        B24_TOKENS_FILE, SESSIONS_FILE, ACL_FILE, STATE_FILE, SETTINGS_FILE,
        RATELIMIT_FILE, OPS_FILE, SWITCHES_FILE, INBOX_FILE, DEADLETTER_FILE, NOTIFY_OUTBOX_FILE, TASK_FILE,
        CRM_DICT_FILE, PLAN_FILE, RATE_FILE, CAP_FILE, QUEUE_DONE_FILE,
        DATA_ROOT . '/lifecycle.lock.php', DATA_ROOT . '/cron.lock.php',
        DATA_ROOT . '/app-errors.log.php', DATA_ROOT . '/audit.php',
        appStateTransitionManifestFile(),
    ];
    if (defined('B24_CREDS_FILE')) $reserved[] = B24_CREDS_FILE;

    $blocked = [];
    foreach ($reserved as $coreFile) {
        if (!is_string($coreFile) || $coreFile === '') continue;
        $blocked[$coreFile] = true;
        // storeUpdate держит отдельный control-файл рядом с каждым core-state.
        $blocked[$coreFile . '.lock.php'] = true;
    }

    $normalized = [];
    foreach ($extras as $index => $extra) {
        $label = 'APP_PURGE_EXTRA[' . (is_int($index) ? $index : 'key') . ']';
        if (!is_string($extra) || $extra === '' || trim($extra) !== $extra
            || strpos($extra, "\0") !== false || substr($extra, -4) !== '.php') {
            throw new InstallPurgeConfigurationException($label . ': нужен абсолютный путь к .php-файлу');
        }
        if ($extra[0] !== '/' || strncmp($extra, $root . '/', strlen($root) + 1) !== 0) {
            throw new InstallPurgeConfigurationException($label . ': файл должен быть строго внутри DATA_ROOT');
        }

        $relative = substr($extra, strlen($root) + 1);
        $parts = explode('/', $relative);
        if ($relative === '' || in_array('', $parts, true) || in_array('.', $parts, true)
            || in_array('..', $parts, true)) {
            throw new InstallPurgeConfigurationException($label . ': путь не должен содержать пустые, . или .. сегменты');
        }
        if (isset($blocked[$extra]) || substr(basename($extra), -9) === '.lock.php'
            || $parts[0] === 'logs' || $parts[0] === 'cron-logs') {
            throw new InstallPurgeConfigurationException($label . ': core/control-файл запрещён');
        }
        if (is_link($extra)) {
            throw new InstallPurgeConfigurationException($label . ': symlink вместо state-файла запрещён');
        }

        // Для ещё не созданного файла поднимаемся до ближайшего существующего
        // родителя. Так DATA_ROOT/cache-link/new.php не обойдёт fence через
        // cache-link -> /чужой/каталог.
        $probe = dirname($extra);
        while (!file_exists($probe) && !is_link($probe) && $probe !== $root) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
        }
        if (!is_dir($probe)) {
            throw new InstallPurgeConfigurationException($label . ': родитель пути не является каталогом');
        }
        $probeReal = realpath($probe);
        if ($probeReal === false
            || ($probeReal !== $rootReal && strncmp($probeReal, $rootReal . '/', strlen($rootReal) + 1) !== 0)) {
            throw new InstallPurgeConfigurationException($label . ': путь физически выходит из DATA_ROOT');
        }
        if (file_exists($extra)) {
            if (!is_file($extra)) {
                throw new InstallPurgeConfigurationException($label . ': разрешён только обычный файл');
            }
            $extraReal = realpath($extra);
            if ($extraReal === false
                || ($extraReal !== $rootReal && strncmp($extraReal, $rootReal . '/', strlen($rootReal) + 1) !== 0)) {
                throw new InstallPurgeConfigurationException($label . ': файл физически выходит из DATA_ROOT');
            }
        }

        $normalized[$extra] = $extra;
    }
    return array_values($normalized);
}

/** @return string[] */
function appPurgeExtraFiles(): array {
    if (!defined('APP_PURGE_EXTRA')) return [];
    $extras = constant('APP_PURGE_EXTRA');
    if (!is_array($extras)) {
        throw new InstallPurgeConfigurationException('APP_PURGE_EXTRA должен быть массивом путей');
    }
    return appNormalizePurgeExtraFiles($extras);
}

/**
 * Полная замена state из lifecycle-кода с ceiling предметного владельца.
 * Обычный storeWrite читает прежнее состояние под EX-lock перед заменой;
 * task/CRM legacy oversize поэтому обязан остановиться до JSON decode.
 */
function appLifecycleStoreWrite(string $file, array $state): void {
    $maxReadBytes = 0;
    if ($file === TASK_FILE) $maxReadBytes = TASK_MAX_BYTES;
    elseif ($file === CRM_DICT_FILE) $maxReadBytes = CRM_DICT_MAX_BYTES;
    storeWrite($file, $state, $maxReadBytes);
}

/**
 * Очистить состояние, которое нельзя переносить между разными порталами.
 *
 * Строит план внутри exclusive lifecycle-fence. Из state сохраняется только
 * проверенный app_url — это адрес нашего приложения, а не данные портала;
 * placement fingerprint и любые кэши удаляются.
 *
 * @return array<string,array> План новых значений без мутаций.
 */
function appPortalBoundResetTargets(): array {
    $extraFiles = appPurgeExtraFiles();
    $oldState = storeRead(STATE_FILE) ?? [];
    $state = [];
    $appUrl = is_string($oldState['app_url'] ?? null) ? trim((string)$oldState['app_url']) : '';
    if ($appUrl !== '' && strncmp($appUrl, 'https://', 8) === 0) $state['app_url'] = $appUrl;

    $targets = [
        SESSIONS_FILE  => [],
        ACL_FILE       => ['owner_uid' => 0, 'users' => []],
        STATE_FILE     => $state,
        SETTINGS_FILE  => [],
        RATELIMIT_FILE => [],
        OPS_FILE       => [],
        SWITCHES_FILE  => [],
        DEADLETTER_FILE => [],
        INBOX_FILE      => [],
        // Уведомления адресованы сотрудникам ПРЕЖНЕГО портала: на новом эти
        // идентификаторы принадлежат другим людям, и недоставленное сообщение
        // ушло бы постороннему.
        NOTIFY_OUTBOX_FILE => [],
        // Задача считалась для ПРЕЖНЕГО портала: её курсор указывает на чужие
        // записи. Оставить её — значит дать фоновому рабочему продолжить обход
        // сделок одного портала по позиции, снятой в другом.
        TASK_FILE       => [],
        // Названия воронок, стадий и полей — структура продаж КОНКРЕТНОГО
        // клиента. На новом портале они не просто бесполезны: пока справочник
        // не устарел, экраны показывают чужие названия как свои.
        CRM_DICT_FILE   => [],
        // Тариф — свойство КОНКРЕТНОГО портала. На новом он другой, и работа по
        // чужому пределу означает либо блокировку метода (если там тариф ниже),
        // либо лишнюю медлительность. Пока не спросим заново — самый строгий.
        PLAN_FILE       => [],
        // Отметка темпа — про ПРОШЛЫЙ портал: держать по ней паузу перед первым
        // запросом к новому незачем, а вреда от старого времени быть не должно.
        RATE_FILE       => [],
        // Доступность методов — свойство КОНКРЕТНОГО портала: на новом состав
        // модулей и права другие. Работа по чужой карте означала бы либо отказ
        // без причины, либо обещание возможности, которой там нет.
        CAP_FILE        => [],
        // Реестр закрытых событий — про события ПРЕЖНЕГО портала. На новом их
        // метки ничего не значат, а совпадение метки (12 шестнадцатеричных
        // знаков) отсекло бы чужое событие как «уже сделанное».
        QUEUE_DONE_FILE => [],
    ];
    foreach ($extraFiles as $extra) {
        // Отсутствующий пользовательский state уже очищен. Не создаём ради
        // reset пустой файл и тем более новый вложенный каталог.
        if (is_file($extra)) $targets[$extra] = [];
    }
    return $targets;
}

/** @return string[] Очищенные файлы. */
function appResetPortalBoundStateLocked(): array {
    // Единый порядок замков: lifecycle EX уже у вызывающего, затем стабильный
    // journal-lock. Он покрывает и scandir, и все rename/delete транзакции.
    return appWithJournalMutationLock(static function (): array {
        appRecoverStateTransitionLocked();
        return appCommitStateTransition(appPortalBoundResetTargets(),
            array_merge(appJournalTransitionTargets(), appQuarantineFiles()));
    });
}

/** Атомарный относительно API/worker вход для явной межпортальной очистки. */
function appResetPortalBoundState(): array {
    return appWithLifecycleWriteLock(static function (): array {
        return appResetPortalBoundStateLocked();
    });
}

/**
 * Стереть ВСЁ состояние, относящееся к установке в портале.
 *
 * Зовётся при событии ONAPPUNINSTALL. Раньше чистились только три файла из
 * девяти — на диске оставались настройки, счётчики, очередь событий, режимы и
 * журнал операций уже удалённого приложения; следующая установка (в том числе
 * другого портала) видела чужое состояние как своё.
 *
 * Файлы проекта добавляются через APP_PURGE_EXTRA в env.php — чтобы уборка
 * жила в одном месте, а не расползалась по обработчикам.
 */
function appPurgeInstallState(?string $expectedGeneration = null): array {
    return appWithLifecycleWriteLock(static function () use ($expectedGeneration): array {
        return appPurgeInstallStateLocked($expectedGeneration);
    });
}

/**
 * Карантинные копии повреждённого состояния, лежащие в data/.
 *
 * Их кладёт storeQuarantine(), когда файл состояния не разобрался: содержимое
 * откладывается на разбор человеком под именем `<файл>.bad-<отпечаток>.php`.
 * Для уборки установки это такое же состояние портала, как settings или ACL, —
 * значит уезжает вместе с ними.
 *
 * @return string[] абсолютные пути
 */
function appQuarantineFiles(): array {
    $found = glob(DATA_ROOT . '/*.bad-*.php') ?: [];
    $out = [];
    foreach ($found as $file) {
        if (is_file($file)) $out[] = $file;
    }
    sort($out);
    return $out;
}

/** Внутренняя часть uninstall; вызывающий уже держит lifecycle EX. */
function appPurgeInstallStateLocked(?string $expectedGeneration = null): array {
    // До отсечения токенов и выставления cleanup_pending проверяем всю
    // конфигурацию очистки. Иначе неверный extra оставлял активную установку в
    // полуразобранном tombstone, хотя ни одного допустимого файла не очистил.
    $extraFiles = appPurgeExtraFiles();
    $expectedGeneration = $expectedGeneration ?? appEnsureInstallGeneration() ?? appCurrentInstallGeneration();
    if (!is_string($expectedGeneration) || $expectedGeneration === '') {
        throw new InstallGenerationMismatchException('У удаления нет поколения установки');
    }

    // Фаза 1: под тем же замком, что persist/refresh/install, отрезаем рабочие
    // токены. CAS не даёт запоздалому ONAPPUNINSTALL от установки A стереть B.
    $alreadyDone = false;
    storeUpdate(B24_TOKENS_FILE, static function (array $tokens) use ($expectedGeneration, &$alreadyDone): ?array {
        if (!hash_equals(appInstallGenerationFrom($tokens), $expectedGeneration)) {
            throw new InstallGenerationMismatchException('Поколение удаления устарело');
        }
        if (!empty($tokens['uninstalled_at']) && empty($tokens['cleanup_pending'])) {
            $alreadyDone = true;
            return null;
        }
        if (empty($tokens['access_token']) && empty($tokens['cleanup_pending'])) {
            throw new InstallGenerationMismatchException('Установка уже не активна');
        }
        if (!empty($tokens['cleanup_pending'])) return null; // повтор после обрыва продолжает фазу 2
        return [
            'install_generation' => $expectedGeneration,
            'uninstalled_at'     => time(),
            'cleanup_pending'    => true,
            'cleanup_started_at' => date('c'),
        ];
    }, 25.0);
    if ($alreadyDone) return [];

    // Очереди чистим последними. Если любой обычный state не очистился,
    // ONAPPUNINSTALL остаётся в inbox и не будет подтверждён воркером.
    // TASK_FILE здесь обязателен: незавершённая долгая задача принадлежит ЭТОЙ
    // установке вместе с её курсором. Пережив удаление, она заставляла фонового
    // рабочего следующей установки продолжать чужую работу с середины — на
    // данных уже другого портала, — а экран показывал «Выполняется» у задачи,
    // которую здесь никто не запускал.
    $files = [SESSIONS_FILE, ACL_FILE, STATE_FILE, SETTINGS_FILE, RATELIMIT_FILE,
              OPS_FILE, SWITCHES_FILE, TASK_FILE, CRM_DICT_FILE, PLAN_FILE, RATE_FILE,
              CAP_FILE, NOTIFY_OUTBOX_FILE, QUEUE_DONE_FILE];
    foreach ($extraFiles as $extra) $files[] = $extra;
    // Карантинные копии повреждённого состояния (`*.bad-<отпечаток>.php`) — это
    // ТО ЖЕ состояние установки, только отложенное на разбор: настройки, список
    // доступа, счётчики прежнего портала. Пережив удаление, они оставались на
    // диске у следующей установки и у нового владельца — данные чужого портала
    // без единого следа о том, откуда они. Удаляем ФАЙЛЫ целиком, а не пишем в
    // них пустоту: пустая карантинная копия смысла не имеет и только путает.
    $quarantine = appQuarantineFiles();

    $cleared = [];
    $failed  = [];
    foreach (array_unique($files) as $file) {
        try {
            if ($file === ACL_FILE)      { appLifecycleStoreWrite($file, ['owner_uid' => 0, 'users' => []]); }
            else                         { appLifecycleStoreWrite($file, []); }
            $cleared[] = basename($file);
        } catch (Throwable $e) {
            // Один неудавшийся файл не должен останавливать уборку остальных — но
            // и молчать нельзя: на диске остались данные удалённого портала, и
            // узнать об этом можно только из журнала.
            $failed[] = basename($file);
            logAppError('uninstall: не удалось очистить ' . basename($file) . ': ' . maskSecrets($e->getMessage()));
        }
    }
    if ($failed !== []) {
        appLog('error', 'app.uninstall.partial', ['не_очищено' => $failed], ['src' => 'cron',
            'msg' => 'уборка после удаления приложения не завершена — cron повторит её']);
        throw new InstallCleanupIncompleteException($failed);
    }

    foreach ($quarantine as $bad) {
        try {
            if (is_file($bad) && !unlink($bad)) throw new RuntimeException('не удалить карантинную копию');
            $cleared[] = basename($bad);
        } catch (Throwable $e) {
            $failed[] = basename($bad);
            logAppError('uninstall: не удалось убрать карантинную копию ' . basename($bad)
                . ': ' . maskSecrets($e->getMessage()));
        }
    }

    // Подключаем здесь, а не сверху файла: уборка случается раз за жизнь
    // установки, а CLI-проба выкладки грузит lib.php в урезанной раскладке, где
    // соседних модулей ещё нет.
    require_once __DIR__ . '/cache.php';
    // Запомненные ответы портала (`cache-*.php`) — данные ТОГО портала: отделы,
    // поля, справочники. Пережив удаление, они достались бы следующей установке
    // и показали бы новому владельцу структуру чужой компании. Файл памяти
    // удаляем целиком: пустой кеш смысла не имеет, а значение всегда можно
    // получить заново.
    try {
        $cacheRemoved = cacheForgetAll();
        if ($cacheRemoved > 0) $cleared[] = 'cache-family-' . $cacheRemoved;
    } catch (Throwable $e) {
        $failed[] = 'cache-family';
        logAppError('uninstall: не удалось убрать память: ' . maskSecrets($e->getMessage()));
    }
    if ($failed !== []) {
        appLog('error', 'app.uninstall.partial', ['не_очищено' => $failed], ['src' => 'cron',
            'msg' => 'уборка после удаления приложения не завершена — cron повторит её']);
        throw new InstallCleanupIncompleteException($failed);
    }

    // Журнал содержит portal/member-контекст и поэтому принадлежит поколению
    // установки так же, как settings или sessions. Чистим до inbox: при любой
    // ошибке uninstall остаётся неподтверждённым. Поздние строки старого запроса
    // дополнительно отсекаются generation-фильтром журнала.
    $journalCleanup = appPurgeJournalFiles();
    foreach ($journalCleanup['cleared'] as $file) $cleared[] = $file;
    foreach ($journalCleanup['failed'] as $file) {
        $failed[] = $file;
        logAppError('uninstall: не удалось очистить ' . $file);
    }
    if ($failed !== []) {
        appLog('error', 'app.uninstall.partial', ['не_очищено' => $failed], ['src' => 'cron',
            'msg' => 'уборка после удаления приложения не завершена — cron повторит её']);
        throw new InstallCleanupIncompleteException($failed);
    }

    // Inbox с самим ONAPPUNINSTALL удаляем лишь после успешной очистки
    // остального state. Иначе частичная ошибка одновременно теряла команду на
    // повтор уборки. DLQ также относится к поколению установки и очищается перед
    // inbox, но только когда обычные файлы уже гарантированно очищены.
    foreach ([DEADLETTER_FILE, INBOX_FILE, NOTIFY_OUTBOX_FILE] as $file) {
        try {
            storeWrite($file, []);
            $cleared[] = basename($file);
        } catch (Throwable $e) {
            $failed[] = basename($file);
            logAppError('uninstall: не удалось очистить ' . basename($file) . ': ' . maskSecrets($e->getMessage()));
        }
    }
    if ($failed !== []) {
        appLog('error', 'app.uninstall.partial', ['не_очищено' => $failed], ['src' => 'cron',
            'msg' => 'уборка после удаления приложения не завершена — cron повторит её']);
        throw new InstallCleanupIncompleteException($failed);
    }

    // Фаза 3: только после полной очистки снимаем pending. Повторный CAS нужен:
    // даже ошибочная новая установка не должна быть затёрта финальным шагом A.
    storeUpdate(B24_TOKENS_FILE, static function (array $tokens) use ($expectedGeneration): array {
        if (!hash_equals(appInstallGenerationFrom($tokens), $expectedGeneration)
            || empty($tokens['uninstalled_at'])) {
            throw new InstallGenerationMismatchException('Поколение изменилось во время уборки');
        }
        unset($tokens['cleanup_pending']);
        $tokens['cleanup_completed_at'] = date('c');
        return $tokens;
    }, 25.0);
    $cleared[] = basename(B24_TOKENS_FILE);
    return $cleared;
}

/**
 * Записать подробности сбоя в защищённый журнал и вернуть код обращения.
 *
 * Журнал лежит в private DATA_ROOT и обязан быть .php с 404+exit-заглушкой: иначе его
 * скачают прямой ссылкой вместе со всем, что туда попало. Текст проходит через
 * maskSecrets — в сообщениях ошибок регулярно оказывается URL с токеном.
 *
 * @return string Короткий код, который человек называет разработчику.
 */
function logAppError(string $text): string {
    // Обёртка над общим журналом: сигнатура прежняя, поэтому все места вызова
    // (их больше десятка) остались как были, а запись теперь одна на приложение
    // и читается и человеком на экране, и командой с сервера.
    require_once __DIR__ . '/journal.php';
    // Маскируем ЗДЕСЬ, а не надеемся на вызывающего. Мест вызова больше десятка,
    // и хватает одного забывшего: текст ошибки HTTP-клиента несёт полный адрес
    // запроса вместе с токеном. Поймано приманкой 08.08.2026 — секрет лежал в
    // журнале открытым, хотя правило «в лог только через maskSecrets» есть с
    // самого начала. Правило, которое держится на памяти, рано или поздно не
    // срабатывает; это же правило, применённое в одном месте, работает всегда.
    $text = maskSecrets($text);
    return appLog('error', 'app.error', ['text' => $text], ['msg' => $text]);
}

/**
 * Отметить, что запрос УЖЕ начал менять данные.
 *
 * Зачем. Ответ при аварийном завершении раньше утверждал: «Данные не пострадали:
 * незавершённое действие не сохраняется». Это обещание никто не проверял, и оно
 * прямо ложно в двух обычных случаях: фатал пришёл после того, как в портал ушёл
 * `crm.item.add`, либо в середине перезаписи нескольких state-файлов. Человек
 * читал «всё цело», повторял действие — и получал вторую сделку.
 *
 * Признак живёт в памяти процесса и ставится ДО отправки изменения. Диск здесь
 * не годится: обработчик аварийного завершения работает на исчерпанной памяти,
 * и любое лишнее чтение может не выполниться.
 *
 * @param string $what Короткая метка, что именно менялось (для журнала).
 */
function appChangesStarted(string $what = ''): void {
    if (!isset($GLOBALS['__changes_started']) || !is_array($GLOBALS['__changes_started'])) {
        $GLOBALS['__changes_started'] = [];
    }
    $label = $what !== '' ? $what : 'изменение';
    // Список нужен только для одной строки в журнале — держим короткий хвост,
    // иначе пакет из тысячи команд вырастет в память ровно там, где её нет.
    if (count($GLOBALS['__changes_started']) < 8
        && !in_array($label, $GLOBALS['__changes_started'], true)) {
        $GLOBALS['__changes_started'][] = $label;
    }
}

/**
 * Могло ли начатое в этом запросе уже примениться?
 *
 * false — не «наверняка ничего не изменилось вообще», а «изменений в портал не
 * отправляли и многофайловую перезапись не начинали». Одиночная запись state
 * атомарна (tmp+rename), поэтому половинчатой записи после обрыва не остаётся.
 */
function appChangesMayBeApplied(): bool {
    return !empty($GLOBALS['__changes_started']);
}

/** Чем именно менялись данные — короткой строкой для журнала. */
function appChangesStartedLabel(): string {
    $list = $GLOBALS['__changes_started'] ?? [];
    return is_array($list) && $list !== [] ? implode(', ', $list) : '';
}

/**
 * Показывать человеческий ответ вместо пустого экрана, если запрос упал.
 *
 * Зачем. `display_errors` выключен (и правильно — иначе в браузер уедет путь и
 * кусок кода), поэтому непойманное исключение выглядит как белая страница или
 * оборванный ответ: человек видит «приложение не работает» без единой зацепки.
 * Отдельно стоит случай повреждённого состояния — там нужно не «ошибка 500»,
 * а внятное «данные испорчены, копия отложена, вот что делать».
 *
 * Вызывать первой строкой в каждой точке входа.
 *
 * @param bool $json Точка входа отвечает JSON'ом (эндпоинты api/), а не страницей.
 */
/**
 * РАЗОВЫЙ БИЛЕТ НА УСТАНОВКУ БЕЗ ПОДТВЕРЖДЕНИЯ ПОРТАЛА.
 *
 * Нужен там, где криптодоказательство физически невозможно: коробочный портал
 * со своим сервером авторизации не умеет обменять `REFRESH_ID` через
 * `oauth.bitrix24.tech` (действующий адрес сервера авторизации приложений —
 * settings/oauth/index.md:59,115; адрес задаётся `B24_OAUTH_URL` в env.php, и
 * менять его нужно как раз на коробке со своим OAuth-сервером). Прежде на этот
 * случай был выключатель `true/false`, и
 * включённый он принимал ЛЮБУЮ первую установку с ЛЮБОГО адреса — забыть
 * вернуть его обратно достаточно одного раза (находка ревью 08.08.2026,
 * BXREV-011).
 *
 * Билет ограничен со всех сторон сразу, и каждое ограничение закрывает свой
 * способ им воспользоваться:
 *   ДОМЕН   — точный, заранее названный оператором. Не IP и не внутренний адрес:
 *             установка «с 127.0.0.1» означает, что кто-то ходит по машине.
 *   СРОК    — до указанного момента. Забытый билет перестаёт работать сам.
 *   СЕКРЕТ  — 32+ знака, сверяется `hash_equals`; приходит в теле установки
 *             полем `install_ticket`.
 *   ОДИН РАЗ — отпечаток применённого билета запоминается; второй раз он не
 *             пройдёт даже в срок.
 *
 * @param array $post тело установки от портала
 * @return array{ok:bool,причина:string,домен:string,отпечаток:string}
 */
function installUnverifiedTicketCheck(array $post): array {
    $net = static fn(string $prichina): array =>
        ['ok' => false, 'причина' => $prichina, 'домен' => '', 'отпечаток' => '',
         'доказать_токеном' => false];

    $bilet = defined('UNVERIFIED_FIRST_INSTALL') ? (array)UNVERIFIED_FIRST_INSTALL : [];
    if ($bilet === []) return $net('');   // обход не объявлен — это норма, молчим

    $domen  = strtolower(trim((string)($bilet['domain'] ?? '')));
    $secret = (string)($bilet['ticket'] ?? '');
    $do     = trim((string)($bilet['until'] ?? ''));
    if ($domen === '' || $secret === '' || $do === '') {
        return $net('в билете должны быть заполнены domain, ticket и until');
    }
    if (strlen($secret) < 32) return $net('секрет билета короче 32 знаков — подбирается перебором');

    $srok = strtotime($do);
    if ($srok === false) return $net('срок билета (until) не разбирается как дата');
    if (time() > $srok) return $net('срок билета истёк ' . date('c', $srok));

    // Домен сверяем с тем, что прислал портал, и он обязан быть внешним именем.
    //
    // Берём его тем же резолвером, что и остальной путь установки: КОРОБКА поля
    // DOMAIN не присылает вовсе — у неё в теле SERVER_ENDPOINT. Пока здесь читался
    // только DOMAIN, билет отказывал на коробке всегда, со словами «в установке
    // нет домена портала» — то есть единственный документированный обход для
    // коробочного портала не работал именно на коробке (находка ревью 09.08.2026).
    if (!function_exists('portalPostDomain')) require_once __DIR__ . '/b24.php';
    $prislan = strtolower(trim(portalPostDomain($post)));
    // Портал может не прислать своего адреса ВООБЩЕ: у коробки без собственного
    // сервера авторизации в теле установки нет ни DOMAIN, ни CLIENT_ENDPOINT —
    // только SERVER_ENDPOINT чужого облачного сервера. Резолвер честно отдаёт
    // хост оттуда, и сверка «билет выписан на другой портал» отбивала
    // единственный оставшийся путь установки (живой случай коробки клиента,
    // 17.08.2026). Поэтому имя, которое ЗАВЕДОМО не может быть порталом —
    // сервер авторизации, — считаем «адреса нет», а не «адрес чужой»: домен
    // тогда берётся из билета, но подлинность доказывается токеном (ниже).
    if ($prislan !== '' && b24IsAuthServerHost($prislan)) $prislan = '';
    if ($prislan !== '' && !hash_equals($domen, $prislan)) {
        return $net('билет выписан на другой портал (' . $domen . '), а установка пришла с ' . $prislan);
    }
    if ($prislan === '') $prislan = $domen;
    if (filter_var($prislan, FILTER_VALIDATE_IP) !== false) {
        return $net('домен установки — это IP-адрес; билет выписывается на имя портала');
    }
    if (preg_match('~^(localhost|.*\.local|.*\.internal)$~i', $prislan) === 1) {
        return $net('домен установки указывает внутрь сети: ' . $prislan);
    }

    // Секрет в теле установки — сильное доказательство, но положить его туда
    // может только тот, кто сам формирует запрос. Портал этого поля не шлёт и
    // прислать не может, поэтому его отсутствие — не отказ, а требование
    // доказать подлинность иначе: рабочим токеном портала (проверяет index.php,
    // спрашивая ИМ же сам портал). Неверный секрет — по-прежнему отказ: значит
    // запрос собирал кто-то посторонний и промахнулся.
    $prislannyy = (string)($post['install_ticket'] ?? $post['INSTALL_TICKET'] ?? '');
    $dokazatTokenom = false;
    if ($prislannyy === '') {
        $dokazatTokenom = true;
    } elseif (!hash_equals($secret, $prislannyy)) {
        return $net('в теле установки неверный билет (поле install_ticket)');
    }

    // Отпечаток — по секрету и домену: он и есть «этот билет».
    $otpechatok = hash('sha256', $domen . '|' . $secret);
    $sostoyanie = function_exists('storeRead') && defined('STATE_FILE') ? (storeRead(STATE_FILE) ?? []) : [];
    $ispolzovannye = (array)($sostoyanie['install_tickets_used'] ?? []);
    if (in_array($otpechatok, $ispolzovannye, true)) {
        return $net('этот билет уже применяли — выпишите новый');
    }
    return ['ok' => true, 'причина' => '', 'домен' => $prislan, 'отпечаток' => $otpechatok,
        'доказать_токеном' => $dokazatTokenom];
}

/** Сжечь применённый билет: второй раз он не пройдёт даже в срок. */
function installUnverifiedTicketBurn(string $otpechatok): void {
    if ($otpechatok === '' || !function_exists('storeUpdate') || !defined('STATE_FILE')) return;
    storeUpdate(STATE_FILE, static function (array $s) use ($otpechatok): array {
        $bylo = (array)($s['install_tickets_used'] ?? []);
        if (!in_array($otpechatok, $bylo, true)) $bylo[] = $otpechatok;
        // Держим последние двадцать: список нужен для сверки, а не как архив.
        $s['install_tickets_used'] = array_slice($bylo, -20);
        return $s;
    });
}

function installFailureHandler(bool $json = false): void {
    // Фатальная ошибка (кончилась память на большом ответе портала, ошибка типов)
    // не проходит через обработчик исключений — без этого она давала белый экран
    // и ни строчки в журнале, то есть ровно то, ради чего функция и написана.
    // Запас памяти. Когда предел уже исчерпан, на сам ответ памяти не остаётся:
    // не выйдет ни собрать строку, ни закодировать JSON. Поэтому кусок держим
    // заранее и отпускаем первым же действием обработчика.
    $GLOBALS['__fatal_reserve'] = str_repeat('#', 256 * 1024);
    register_shutdown_function(static function () use ($json): void {
        unset($GLOBALS['__fatal_reserve']);
        $e = error_get_last();
        if (!$e || !($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) return;
        // Успел ли запрос начать изменения — читаем прямо из глобала: на
        // исчерпанной памяти лишний вызов может не выполниться, а ответ обязан
        // выйти. Разбор «что именно менялось» — только в журнал.
        $changed = !empty($GLOBALS['__changes_started']);
        $whatChanged = $changed && is_array($GLOBALS['__changes_started'])
            ? implode(', ', $GLOBALS['__changes_started']) : '';
        $ref = logAppError('ФАТАЛ: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']
            . ($changed ? ' | изменения были начаты: ' . $whatChanged : ' | изменения не начинались'));

        // Дальше — то, ради чего всё это. Без ответа человек видит ПУСТОЕ окно:
        // ни ошибки, ни подсказки, ни кода обращения. Он повторяет действие,
        // потом зовёт разработчика словами «оно просто не работает».
        if (headers_sent()) return;          // ответ уже начат — дописывать нельзя
        http_response_code(500);
        sendNoStoreHeaders();
        header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
        $tail = $ref !== '' ? ' Код обращения: ' . $ref . '.' : '';
        // Что сказать про сохранность данных. Приложение знает ровно две вещи:
        // отправляло ли оно изменения в портал и начинало ли перезапись
        // нескольких state-файлов. Одиночная запись state атомарна (tmp+rename),
        // поэтому её обрыв ничего наполовину не оставляет. Всё остальное —
        // догадка, и выдавать её человеку за факт нельзя: он повторит действие
        // и получит второй экземпляр.
        $dataText = $changed
            ? 'В этом запросе приложение уже отправляло изменения — часть могла успеть примениться. '
              . 'Проверьте результат в портале, прежде чем повторять действие.'
            : 'Изменения в портал в этом запросе не отправлялись, а состояние приложение '
              . 'записывает целиком или не записывает вовсе — недописанного не останется. '
              . 'Действие можно повторить.';
        if ($json) {
            // Тот же формат, что у любого другого отказа: фронт разбирает ответы
            // одним куском кода. Собираем строкой, а не json_encode: кодировщику
            // на исчерпанной памяти может не хватить даже отпущенного запаса.
            echo '{"ok":false,"code":"fatal","changes":"' . ($changed ? 'maybe_applied' : 'not_started') . '"'
                . ',"error":"Приложение прервало запрос: не хватило ресурсов сервера. '
                . str_replace('"', '', $dataText)
                . str_replace('"', '', $tail) . '"'
                . ($ref !== '' ? ',"ref":"' . preg_replace('/[^A-Za-z0-9_-]/', '', $ref) . '"' : '')
                . '}';
            return;
        }
        // Страницу тоже собираем вручную и без подключения шаблонов: любая
        // лишняя работа здесь может не выполниться.
        echo '<!doctype html><meta charset="utf-8"><title>Приложение прервало запрос</title>'
            . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:34em;margin:12vh auto;padding:0 1em;color:#e8e8ea;background:#17171a">'
            . '<h1 style="font-size:1.3em">Приложение прервало запрос</h1>'
            . '<p>Не хватило ресурсов сервера, чтобы закончить работу.</p>'
            . '<p>' . htmlspecialchars($dataText, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Если повторяется — назовите разработчику код обращения.'
            . htmlspecialchars($tail, ENT_QUOTES, 'UTF-8') . '</p></div>';
    });
    set_exception_handler(static function (Throwable $e) use ($json): void {
        if (!headers_sent()) {
            http_response_code(500);
            sendNoStoreHeaders();
            header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
        }
        $corrupt = $e instanceof StoreCorruptedException;
        // ОТКАЗ В ДОСТУПЕ — НЕ ПОВРЕЖДЕНИЕ. Данные целы, чинится возвратом прав,
        // и звать разработчика незачем: сказать об этом прямо дешевле, чем
        // потом объяснять, почему «повреждённые» данные оказались целыми.
        $nedostupno = $e instanceof StoreUnreadableException;
        $title = $nedostupno
            ? 'Нет доступа к данным приложения'
            : ($corrupt ? 'Данные приложения повреждены' : 'Внутренняя ошибка приложения');
        // Исключение посреди пакета мутаций оставляет ту же неопределённость,
        // что и фатал: часть команд портал уже выполнил. Молчать об этом — то же
        // самое, что обещать сохранность: человек повторит и получит дубль.
        $changed = appChangesMayBeApplied();
        $text  = $nedostupno
            ? 'Файл с сохранённым состоянием есть, но приложение не может его прочитать. '
              . 'Данные целы — дело в правах доступа: у файла сменился владелец или права. '
              . 'Приложение остановлено намеренно, чтобы не записать новое состояние поверх '
              . 'существующего. Верните файлу владельца и права того пользователя, от которого '
              . 'работает приложение, и обновите страницу.'
            : ($corrupt
            ? 'Файл с сохранённым состоянием не читается. Приложение остановлено намеренно: '
              . 'если продолжить, поверх испорченных данных запишутся новые и восстановить будет нечего. '
              . 'Копия отложена рядом — покажите разработчику код обращения ниже.'
            : ($changed
                ? 'Запрос не удалось выполнить, но изменения в портал уже отправлялись — часть могла '
                  . 'примениться. Проверьте результат, прежде чем повторять действие, и назовите '
                  . 'разработчику код обращения ниже.'
                : 'Запрос не удалось выполнить. Если повторяется — назовите разработчику код обращения ниже.'));

        // Подробности — в защищённый журнал, а человеку только код обращения.
        // На экран им нельзя: в сообщении PHP-ошибки лежит полный путь на сервере
        // (а это подсказка тому, кто будет искать дыру), и обычному сотруднику
        // такой текст всё равно ничего не говорит.
        $ref = logAppError(get_class($e) . ': ' . maskSecrets($e->getMessage())
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
            . ($changed ? ' | изменения были начаты: ' . appChangesStartedLabel() : ''));
        $hasRef = $ref !== '';
        $detail = $hasRef
            ? 'код обращения: ' . $ref
            : 'Запись в журнал приложения не создана; диагностика отправлена в системный журнал сервера.';

        if ($json) {
            // Тот же формат, что у любого другого отказа api/* ({ok,error,code}):
            // фронт разбирает ответы одним куском кода и показывает человеку наш
            // текст. Со «своим» форматом он показывал безликое «ошибка 500».
            $payload = [
                'ok'    => false,
                'code'  => $corrupt ? 'store_corrupted' : 'internal',
                // Машинный признак рядом с текстом: фронт по нему решает,
                // показывать ли кнопку «повторить» без оглядки.
                'changes' => $changed ? 'maybe_applied' : 'not_started',
                'error' => $title . '. ' . $text,
            ];
            if ($hasRef) $payload['ref'] = $ref;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }
        renderServicePage($title, $text,
            $hasRef
                ? 'Назовите разработчику код обращения — по нему в журнале приложения видно, что именно произошло.'
                : 'Сообщите разработчику точное время ошибки: журнал приложения недоступен, диагностика ушла в системный журнал сервера.',
            ['code' => 500, 'note' => $detail]);
    });
}

/**
 * Служебная страница приложения: отказ, заглушка, объяснение.
 *
 * Одна функция на все такие экраны намеренно. Раньше каждый случай рисовал свой
 * HTML — десять копий светлой вёрстки, которые разъезжались при первой правке, а
 * тексты в них писались как придётся. Здесь же задан контракт: заголовок — что
 * случилось, текст — почему, и главное `$action` — что человеку СДЕЛАТЬ. Экран
 * без действия бесполезен: человек всё равно идёт спрашивать.
 *
 * @param string      $title  Что случилось, человеческим языком.
 * @param string      $text   Почему так вышло.
 * @param string|null $action Что сделать, чтобы стало хорошо (может быть null,
 *                            если делать нечего — например «портал не отвечает»).
 * @param array       $opts   ['code' => HTTP-код, 'note' => мелкая строка внизу
 *                            (код обращения), 'tone' => 'error'|'warn'|'info']
 */
function renderServicePage(string $title, string $text, ?string $action = null, array $opts = []): void {
    $code = (int)($opts['code'] ?? 200);
    $tone = (string)($opts['tone'] ?? 'error');
    $note = (string)($opts['note'] ?? '');
    // Необязательная кнопка действия: ['label' => 'Текст', 'fields' => ['имя' => 'значение']].
    // Нужна там, где выход из состояния обязан сделать ЧЕЛОВЕК, а не код: автоповтор
    // установочного эффекта запрещён, но и тупик «обратитесь к разработчику» оставлять
    // нельзя. Форма шлёт обычный POST на тот же адрес — никакого JS и никаких
    // браузерных диалогов, страница работает даже если скрипты не загрузились.
    $form = is_array($opts['form'] ?? null) ? $opts['form'] : null;
    $formLabel = $form !== null ? trim((string)($form['label'] ?? '')) : '';
    $formFields = $form !== null && is_array($form['fields'] ?? null) ? $form['fields'] : [];
    if ($formLabel === '') $form = null;

    if (!headers_sent()) {
        http_response_code($code);
        sendNoStoreHeaders();
        header('Content-Type: text/html; charset=utf-8');
    }
    $accent = $tone === 'warn' ? '#f0b429' : ($tone === 'info' ? '#5b9dd9' : '#e5534b');
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // Вёрстка вписывается в окно портала при любой высоте: приложение живёт в
    // iframe, и страница, которая не помещается, обрезается без полосы прокрутки.
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $esc($title) . '</title><style>'
       . ':root{color-scheme:dark}'
       . '*{box-sizing:border-box}'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;'
       . 'background:#14161a;color:#e6e8eb;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
       . '-webkit-font-smoothing:antialiased}'
       . '.card{max-width:520px;width:100%;background:#1b1e24;border:1px solid #2a2f37;border-radius:14px;padding:26px 28px}'
       . 'h1{margin:0 0 10px;font-size:19px;font-weight:600;color:' . $accent . '}'
       . 'p{margin:0 0 12px;color:#b9bfc7}'
       . '.act{margin:16px 0 0;padding:13px 15px;background:#20242b;border-left:3px solid ' . $accent . ';'
       . 'border-radius:0 8px 8px 0;color:#e6e8eb}'
       . '.act b{display:block;margin-bottom:3px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#8b939e}'
       . '.note{margin:18px 0 0;font-size:12px;color:#6f7883;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}'
       . 'form{margin:16px 0 0}'
       . 'button{appearance:none;border:1px solid ' . $accent . ';background:transparent;color:' . $accent . ';'
       . 'font:inherit;font-weight:600;padding:10px 18px;border-radius:9px;cursor:pointer}'
       . 'button:hover{background:' . $accent . ';color:#14161a}'
       . '</style></head><body><div class="card">'
       . '<h1>' . $esc($title) . '</h1>'
       . '<p>' . $esc($text) . '</p>'
       . ($action !== null && $action !== ''
            ? '<div class="act"><b>Что сделать</b>' . $esc($action) . '</div>' : '')
       . ($form !== null
            ? '<form method="post">'
              . implode('', array_map(
                  static fn($k, $v): string => '<input type="hidden" name="' . $esc((string)$k)
                      . '" value="' . $esc((string)$v) . '">',
                  array_keys($formFields), array_values($formFields)))
              . '<button type="submit">' . $esc($formLabel) . '</button></form>'
            : '')
       . ($note !== '' ? '<p class="note">' . $esc($note) . '</p>' : '')
       . '</div></body></html>';
}

/**
 * Рубильник «примерка или боевой прогон».
 *
 * Массовая правка в CRM клиента откатывается вручную по сотням сущностей или не
 * откатывается вовсе. Поэтому у приложения есть один общий переключатель: пока
 * он в положении «примерка», код обязан считать и показать, ЧТО он собирается
 * сделать, но ничего не менять.
 *
 * Хранится в состоянии, а не в конфиге: остановить обработку можно без деплоя.
 * Переключают его пока из кода проекта — appSetMode('prod') / appSetStop(true);
 * кнопки в интерфейсе шаблона нет намеренно: пока в приложении нет ни одной
 * массовой операции, переключать нечего, а лишний admin-эндпоинт — лишняя дверь.
 * Появилась массовая операция — добавьте кнопку рядом с ней.
 *
 * Как пользоваться в прикладном коде:
 *
 *     if (appDryRun()) { $отчёт[] = 'обновил бы сделку ' . $id; continue; }
 *     $b24->callChecked('crm.item.update', [...]);
 */
function appDryRun(): bool {
    if (appStopped()) return true;                 // остановлено — значит ничего не меняем
    try {
        return (string)(storeRead(SWITCHES_FILE)['mode'] ?? 'test') !== 'prod';
    } catch (Throwable $e) {
        // Не смогли прочитать состояние — считаем, что это примерка. Ошибиться
        // в эту сторону безопасно, в обратную — нет.
        return true;
    }
}

/** Полная остановка обработки: аварийный тормоз для владельца. */
function appStopped(): bool {
    try {
        return !empty(storeRead(SWITCHES_FILE)['stop']);
    } catch (Throwable $e) {
        return true;
    }
}

/** Аварийный тормоз: остановить всю обработку (и снять остановку). */
function appSetStop(bool $on, int $uid = 0): void {
    storeUpdate(SWITCHES_FILE, static function (array $s) use ($on): array {
        $s['stop'] = $on;
        $s['changed_at'] = date('c');
        return $s;
    });
    auditLog('app.stop', ['остановлено' => $on], $uid);
}

/** Переключить режим: 'test' (примерка) или 'prod' (боевой). */
function appSetMode(string $mode, int $uid = 0): void {
    $mode = $mode === 'prod' ? 'prod' : 'test';
    storeUpdate(SWITCHES_FILE, static function (array $s) use ($mode): array {
        $s['mode'] = $mode;
        $s['changed_at'] = date('c');
        return $s;
    });
    auditLog('app.mode', ['режим' => $mode === 'prod' ? 'боевой' : 'примерка'], $uid);
}

/** Settings — всё, что админ настраивает через UI приложения. */
function loadSettings(): array {
    $d = storeRead(SETTINGS_FILE);
    return is_array($d) ? $d : [];
}

/**
 * Запомнить адрес приложения в момент установки.
 *
 * Зачем. Обычно APP_URL вычисляется из заголовков запроса, а на этом хостинге
 * заголовок Host подделывается через X-Forwarded-Host: посторонний одним
 * запросом заставил бы приложение считать своим адресом чужой сайт — а от
 * APP_URL зависят проверка «запрос со своей страницы», путь cookie и адреса
 * плейсментов. Установку же присылает сам Битрикс на адрес, который человек
 * вписал в карточку приложения, — это и есть достоверный адрес. Фиксируем его,
 * и дальше env.php берёт сохранённое значение, а не заголовок запроса.
 *
 * Зовётся из обоих обработчиков установки.
 */
function rememberAppUrl(): void {
    $url = defined('APP_URL') ? (string)APP_URL : '';
    if ($url === '' || strncmp($url, 'https://', 8) !== 0) return;   // http и пустое не фиксируем
    try {
        // Служебное состояние, а НЕ пользовательские настройки: настройки правит
        // администратор приложения через интерфейс, и адрес приложения не должен
        // оказаться в зоне его досягаемости.
        storeUpdate(STATE_FILE, static function (array $s) use ($url): array {
            $s['app_url'] = $url;
            return $s;
        });
    } catch (Throwable $e) {
        logAppError('rememberAppUrl: ' . maskSecrets($e->getMessage()));   // установку из-за этого не валим
    }
}

/**
 * Изменить часть настроек под замком.
 *
 * @param callable $mutator function(array $настройки): array — вернуть новые.
 * @return array Настройки, которые лежат на диске после вызова.
 */
function updateSettings(callable $mutator): array {
    return storeUpdate(SETTINGS_FILE, static function (array $onDisk) use ($mutator): array {
        $new = $mutator($onDisk);
        return is_array($new) ? $new : $onDisk;
    });
}

/**
 * Сколько ждать перед повтором, если сервис сам назвал срок.
 *
 * Заголовок `Retry-After` приходит в двух видах: числом секунд и датой по
 * Гринвичу. Понимать надо оба — сервис, попросивший подождать до 12:03:15, при
 * разборе «только числом» получал бы повтор через секунду, то есть ровно тот
 * шквал, от которого он защищается.
 *
 * @return int|null Секунды ожидания или null, если сервис срок не назвал.
 */
function httpRetryAfterSeconds(string $headers): ?int {
    if (!preg_match('~^retry-after:\s*(.+)$~mi', $headers, $m)) return null;
    $raw = trim($m[1]);
    if ($raw === '') return null;
    if (ctype_digit($raw)) return min(60, (int)$raw);
    $at = strtotime($raw);
    if ($at === false) return null;
    $wait = $at - time();
    return $wait > 0 ? min(60, $wait) : 0;
}

/** Повторять ли внешний вызов при таком ответе? Только то, что сервис просит повторить. */
function httpIsRetryable(int $code, bool $safeToRepeat): bool {
    // Отказ ДО выполнения: сервис не начал работу, повтор безопасен всегда.
    if ($code === 429) return true;
    // Оборванная связь и 5xx — другое дело: запрос мог дойти и выполниться,
    // потерялся только ответ. Слепой повтор здесь создаёт второй счёт, поэтому
    // повторяем лишь то, что заведомо ничего не меняет.
    if ($code === 0 || $code >= 500) return $safeToRepeat;
    return false;
}

/**
 * АДРЕС ВНЕШНЕГО ВЫЗОВА: разобрать, проверить и ЗАКРЕПИТЬ за конкретным IP.
 *
 * Находка ревью 08.08.2026 (BXREV-012): готовый блок проверял, по сути, только
 * схему и наличие хоста. Значит адрес из настройки или из ответа чужого сервиса
 * мог увести запрос внутрь машины (`127.0.0.1`, `169.254.169.254`, адреса
 * локальной сети), протащить пароль в `user:pass@`, уйти на нестандартный порт
 * или — при повторном разрешении имени — попасть на другой адрес, чем тот, что
 * проверяли (DNS rebinding). Токен интеграции при этом уезжает туда же.
 *
 * ЧТО ПРОВЕРЯЕТСЯ:
 *   1. Схема — только `https`. Открытый `http` разрешается явным
 *      `['allowInsecure' => true]`: он нужен встроенному серверу испытаний и
 *      внутренним сервисам, но должен быть виден в коде вызывающего.
 *   2. `user:pass@` в адресе запрещён вовсе: это утечка пароля в журналы.
 *   3. Порт — стандартный для схемы либо явно разрешённый
 *      (`['allowPorts' => [8443]]`).
 *   4. Имя разрешается ЗДЕСЬ, и каждый полученный адрес обязан быть публичным.
 *      Внутренние диапазоны отсекаются, если не сказано `allowPrivate`.
 *   5. Проверенный IP возвращается наружу и передаётся cURL через CURLOPT_RESOLVE
 *      — запрос идёт ИМЕННО туда, что проверили, второе разрешение имени ничего
 *      не меняет.
 *
 * @return array{host:string,port:int,ip:string,scheme:string}
 */
function httpResolveAndPin(string $url, array $opts = []): array {
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host   = (string)parse_url($url, PHP_URL_HOST);
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new RuntimeException('httpJson: адрес должен быть http(s) с именем хоста');
    }
    if ($scheme === 'http' && empty($opts['allowInsecure'])) {
        throw new RuntimeException('httpJson: открытый http запрещён — используйте https '
            . 'или явно разрешите его опцией allowInsecure для внутреннего сервиса');
    }
    if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
        throw new RuntimeException('httpJson: логин и пароль в адресе запрещены — '
            . 'они утекают в журналы; передавайте их заголовком');
    }
    $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
    $razresheny = array_map('intval', (array)($opts['allowPorts'] ?? []));
    $obychnyy = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    if (!$obychnyy && !in_array($port, $razresheny, true)) {
        throw new RuntimeException('httpJson: нестандартный порт ' . $port
            . ' — разрешите его явно опцией allowPorts, если так и задумано');
    }

    // Имя разрешаем сами: только так можно проверить КОНКРЕТНЫЙ адрес и потом
    // на нём же настоять.
    // IPv6-литерал приходит из parse_url В СКОБКАХ (`[::1]`), и без их снятия он
    // не опознаётся как адрес: приложение уходило в DNS за именем «[::1]» и
    // получало «имя не разрешается» вместо честного «это внутренний адрес»
    // (поймано при перечитывании собственной правки 09.08.2026).
    $hostGolyy = trim($host, '[]');
    $adresa = [];
    if (filter_var($hostGolyy, FILTER_VALIDATE_IP)) {
        $adresa[] = $hostGolyy;
    } else {
        foreach ((array)@dns_get_record($host, DNS_A) as $z) if (!empty($z['ip'])) $adresa[] = (string)$z['ip'];
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $z) if (!empty($z['ipv6'])) $adresa[] = (string)$z['ipv6'];
        if ($adresa === []) {
            $rezolv = @gethostbynamel($host);
            if (is_array($rezolv)) $adresa = $rezolv;
        }
    }
    if ($adresa === []) throw new RuntimeException('httpJson: имя ' . $host . ' не разрешается в адрес');

    $publichnye = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    foreach ($adresa as $ip) {
        if (!empty($opts['allowPrivate'])) continue;
        if (filter_var($ip, FILTER_VALIDATE_IP, $publichnye) === false) {
            throw new RuntimeException('httpJson: ' . $host . ' указывает на внутренний адрес ' . $ip
                . ' — запрос внутрь машины или в локальную сеть запрещён '
                . '(разрешить осознанно: allowPrivate)');
        }
    }
    // Признак «в адресе уже стоит IP»: закреплять там нечего — имени нет, DNS не
    // участвует, подменить его между проверкой и запросом невозможно. Отдаём это
    // наружу, чтобы не пытаться описать литерал в CURLOPT_RESOLVE: для IPv6
    // такая запись просто не разбирается curl (поймано вызовом 09.08.2026).
    return ['host' => $host, 'port' => $port, 'ip' => (string)$adresa[0], 'scheme' => $scheme,
            'литерал' => filter_var($hostGolyy, FILTER_VALIDATE_IP) !== false];
}

/**
 * Универсальная JSON-обёртка над cURL. На 4xx/5xx бросает RuntimeException
 * с message из тела ответа (Б24 и большинство REST-API шлют structured errors).
 *
 * Использовать для интеграций с внешними API (если будут).
 * Для вызовов Б24 REST — использовать B24->call() / B24->batch() (см. b24.php),
 * там уже встроены OAuth, refresh и обработка ошибок.
 *
 * ПОВТОР. `429` и `5xx` повторяются сами: сначала ровно столько, сколько
 * попросил сервис в `Retry-After`, иначе 1 → 2 → 4 секунды с небольшим
 * разбросом (без него все наши процессы возвращаются в одну и ту же секунду и
 * добивают уже перегруженный сервис). Изменяющий метод (POST/PUT/PATCH/DELETE)
 * после оборванной связи и 5xx НЕ повторяется: ответ мог потеряться уже после
 * выполнения, и повтор создаст вторую запись. Повторять его можно только явно —
 * `['safeToRepeat' => true]`, когда вызывающий знает, что операция идемпотентна.
 * Весь вызов вместе с паузами укладывается в общий бюджет: в вебе воркеров
 * считанные штуки, и зависший запрос — это отказ приложения целиком.
 *
 * @param array $opts headers, body, safeToRepeat, timeout (одна попытка), budget (весь вызов)
 */
function httpJson(string $method, string $url, array $opts = []): array {
    // Адрес проверяем до запроса И закрепляем за проверенным IP: helper зовут с
    // тем, что пришло из конфига или от чужого сервиса, а cURL послушно откроет
    // и file://, и адрес внутри машины (подробности — в httpResolveAndPin).
    $tsel = httpResolveAndPin($url, $opts);
    // Потолок ответа. Без него чужой сервис — по ошибке или намеренно — кладёт
    // приложение по памяти: тело читается целиком в строку.
    $maxBytes = max(1024, (int)($opts['maxBytes'] ?? 5 * 1048576));
    $attemptTimeout = max(1, (int)($opts['timeout'] ?? 30));
    $budget = max($attemptTimeout, (int)($opts['budget'] ?? (PHP_SAPI === 'cli' ? 120 : 45)));
    $safeToRepeat = array_key_exists('safeToRepeat', $opts)
        ? (bool)$opts['safeToRepeat']
        : in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    $deadline = microtime(true) + $budget;

    for ($try = 0; ; $try++) {
        $left = (int)ceil($deadline - microtime(true));
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
            CURLOPT_TIMEOUT        => max(1, min($attemptTimeout, $left)),
            // Отдельный потолок на СОЕДИНЕНИЕ. Без него молчащий хост съедает
            // весь общий таймаут на попытку установить связь, и на сам ответ
            // времени уже не остаётся.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => true,
            // Перенаправления НЕ ходим: 302 на внутренний адрес обнулил бы всю
            // проверку выше.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            // Идём на ПРОВЕРЕННЫЙ адрес: второе разрешение имени (DNS rebinding)
            // не уведёт запрос в другое место.
            // Закрепляем только ИМЯ: в адресе-литерале подменять нечего, а запись
            // вида `[::1]:443:[::1]` curl вовсе не разбирает и роняет вызов.
            CURLOPT_RESOLVE        => empty($tsel['литерал'])
                ? [$tsel['host'] . ':' . $tsel['port'] . ':' . $tsel['ip']]
                : [],
            // Обрываем чтение, как только тело переросло потолок — не после.
            CURLOPT_BUFFERSIZE     => 16384,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($maxBytes, &$tooLarge): int {
                if ($dlTotal > $maxBytes || $dlNow > $maxBytes) { $tooLarge = true; return 1; }   // 1 = прервать
                return 0;
            },
        ]);
        if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = curl_error($ch);
        curl_close($ch);

        $headers = $raw === false ? '' : substr((string)$raw, 0, $headerSize);
        $body    = $raw === false ? '' : substr((string)$raw, $headerSize);
        $failed  = $raw === false;

        // Обрыв по СВОЕМУ потолку — не сетевой сбой: второй такой же запрос не даст
        // тела меньше. Прежде он шёл в повтор как обрыв связи (до 4 скачиваний и 7 с
        // сна в веб-запросе) и кончался невнятным «Callback aborted» (ревью
        // 23.09.2026, otkazy-4). Симметрично RESPONSE_TOO_LARGE клиента портала.
        if ($tooLarge) {
            throw new RuntimeException('HTTP: ответ больше предела ' . $maxBytes
                . ' байт — повтор не поможет; сузьте запрос или поднимите maxBytes осознанно');
        }

        if ($failed || $code >= 400) {
            $retryable = httpIsRetryable($failed ? 0 : $code, $safeToRepeat);
            $pause = $retryable
                ? ($failed ? null : httpRetryAfterSeconds($headers))
                : null;
            if ($pause === null) {
                // 1 → 2 → 4 секунды плюс разброс до 250 мс: без разброса все
                // наши процессы возвращаются одной волной.
                $pause = (int)pow(2, $try);
            }
            $canWait = microtime(true) + $pause + 1 < $deadline;
            if ($retryable && $try < 3 && $canWait) {
                usleep((int)($pause * 1000000) + random_int(0, 250000));
                continue;
            }
            if ($failed) throw new RuntimeException("HTTP error: $err");
            $data = json_decode($body, true);
            $msg = $data['error']['message'] ?? $data['error_description'] ?? $body;
            throw new RuntimeException("HTTP $code: $msg");
        }

        $data = json_decode($body, true);
        // Успешный код, но тело не JSON — это ошибка интеграции (заглушка провайдера,
        // страница логина, HTML с ошибкой), а не «пустой ответ». Возвращать [] нельзя:
        // вызывающий примет её за «ничего не нашлось» и пойдёт дальше по коду.
        if (!is_array($data)) {
            $head = substr(trim($body), 0, 120);
            throw new RuntimeException("HTTP $code: ответ не JSON" . ($head !== '' ? " ($head)" : ''));
        }
        return $data;
    }
}
