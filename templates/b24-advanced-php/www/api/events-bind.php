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
 * ПОДПИСКА НА СОБЫТИЯ ПОРТАЛА — объявил списком, приложение привязало само.
 *
 * ЗАЧЕМ. Приём событий в шаблоне собран целиком: `api/event.php` принимает,
 * `api/queue.php` держит очередь с арендой и ящиком неразобранных, `bin/process.php`
 * разбирает. Не хватало ровно одного звена — самой подписки, и её каждый раз
 * писали руками: замер 05.08.2026 — восемнадцать проектов написали это заново,
 * каждый по-своему. Хуже другое: если подписка не встала, приложение выглядит
 * совершенно здоровым и просто не получает событий. Узнаёт об этом клиент.
 *
 * КАК ПОЛЬЗОВАТЬСЯ. В `env.php`:
 *     define('APP_EVENTS', ['ONCRMDEALUPDATE', 'ONCRMDEALADD']);
 * Пустой список — ноль обращений к порталу, ничего не делается.
 *
 * ПОЧЕМУ ПО ОБРАЗЦУ ПЛЕЙСМЕНТОВ. Тот же жизненный цикл: объявили в настройках,
 * привязали при установке, переустановили — привязали заново (портал снимает
 * обработчики при обновлении приложения, это описано в документации event.bind).
 * Второй способ делать то же самое означал бы вторую логику отпечатков, вторую
 * обработку «уже привязано» и второе место, где можно ошибиться адресом.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/journal.php';

/**
 * Адрес приёмника событий. Подставляется сам из адреса приложения — руками его
 * вписывать нельзя: при переносе тест→бой в нём оставался старый адрес, и события
 * боевого портала улетали на тестовый стенд.
 */
function eventsHandlerUrl(string $appUrl): string {
    return rtrim($appUrl, '/') . '/api/event.php';
}

/**
 * Служебная подписка, которая ставится ВСЕГДА, независимо от настроек проекта.
 *
 * Удаление приложения с портала — единственный момент, когда мы можем убрать за
 * собой: токены, доступ, настройки, очередь. Шаблон это прямо обещает
 * (env.php, раздел «Уборка при удалении приложения»), а обработчик готов
 * (bin/process.php → handleEvent). Не хватало ровно одного: самой подписки.
 * Пока её не было, обещание не выполнялось НИКОГДА — приложение удаляли, а на
 * диске оставались рабочие токены портала и список доступа, и доставались они
 * следующей установке.
 */
const EVENTS_ALWAYS = ['ONAPPUNINSTALL'];

/** Что объявлено в настройках проекта: список имён событий в виде портала. */
function eventsProjectSpec(): array {
    $list = defined('APP_EVENTS') ? APP_EVENTS : [];
    if (!is_array($list) || $list === []) return [];
    $spec = [];
    foreach ($list as $item) {
        // Допускаем и простую строку, и массив с полем code — как у плейсментов.
        $code = is_array($item) ? (string)($item['code'] ?? $item['event'] ?? '') : (string)$item;
        $code = strtoupper(trim($code));
        if ($code === '' || isset($spec[$code])) continue;
        $spec[$code] = true;
    }
    return array_keys($spec);
}

/** Полный список подписок: объявленное проектом плюс служебная уборка. */
function eventsSpec(): array {
    $spec = [];
    foreach (array_merge(eventsProjectSpec(), EVENTS_ALWAYS) as $code) {
        $spec[strtoupper(trim((string)$code))] = true;
    }
    unset($spec['']);
    return array_keys($spec);
}

/** Отпечаток объявленного: сменился список или адрес приложения — сменится он. */
function eventsFingerprint(): string {
    return md5((string)json_encode([eventsSpec(), eventsHandlerUrl(APP_URL)]));
}

/**
 * Портал снял наши обработчики — подписку надо ставить заново.
 *
 * Отпечаток отвечает на вопрос «изменилось ли У НАС», и этого мало: обработчики
 * исчезают на СТОРОНЕ ПОРТАЛА при обновлении и переустановке приложения
 * (api-reference/events/event-bind.md: «При удалении и обновлении приложения его
 * действия будут удаляться. Поэтому в инсталляторе каждой версии нужно их
 * выставлять с нуля»). У нас при этом не менялось ничего, отпечаток совпадал, и
 * bindEvents() честно отвечал «уже привязано» — приложение выглядело здоровым и
 * переставало получать события, включая собственное удаление, после которого оно
 * обязано убрать за собой токены портала (находка ревью 09.08.2026, R3-005).
 *
 * Метка ставится RMW под замком тем же storeUpdate, что и остальное состояние, и
 * снимается ТОЛЬКО после перечитывания списка подписок у портала.
 */
function eventsMarkRebindRequired(): void {
    storeUpdate(STATE_FILE, static function (array $s): array {
        $s['events_rebind_required'] = date('c');
        return $s;
    });
}

/**
 * Похоже ли на «уже подписаны». Это ПОДСКАЗКА, а не решение.
 *
 * Официальная документация метода перечисляет только `ERROR_EVENT_NOT_FOUND` и
 * `ACCESS_DENIED` (api-reference/events/event-bind.md) — кода повторной привязки
 * там нет вовсе. Опираться на угаданное имя опасно в обе стороны: имя сменится —
 * и повторная установка объявит ошибкой то, что на самом деле работает.
 * Поэтому окончательный ответ даёт перечитывание списка подписок, а этот код
 * лишь избавляет от лишней записи в журнал в обычном случае.
 */
function eventsAlreadyBound(array $response): bool {
    return strtoupper((string)($response['error'] ?? '')) === 'ERROR_EVENT_BINDING_EXISTS';
}

/**
 * Свести ответ портала со списком реальных подписок.
 *
 * Здесь живёт всё правило, и оно устроено симметрично:
 *   — портал сказал «принято», а в списке подписки НЕТ → это ошибка: молчаливо
 *     не вставшая подписка выглядит как рабочее приложение, которое не реагирует;
 *   — портал сказал «отказ», а в списке подписка ЕСТЬ → это НЕ ошибка: так
 *     выглядит повторная установка на тот же портал. Код повторной привязки в
 *     документации не описан, и приговор по нему означал бы ложную ошибку в
 *     журнале при каждой переустановке.
 * Портал не ответил на перечитывание — ничего не меняем: исход «неизвестно» не
 * повод ни подтверждать, ни объявлять поломку.
 *
 * @param array<string,bool>   $bound   события, на которых портал ответил успехом
 * @param array<string,string> $errors  события с отказом и текстом отказа
 * @param array<string,bool>   $recheck события, чей отказ надо перепроверить списком
 * @param array{ok:bool,bound:string[]} $confirm ответ eventsActual()
 * @return array{0:array<string,bool>,1:array<string,string>}
 */
function eventsReconcile(array $bound, array $errors, array $recheck, array $confirm): array {
    if (empty($confirm['ok'])) return [$bound, $errors];
    $actual = (array)($confirm['bound'] ?? []);

    foreach (array_keys($bound) as $code) {
        if (in_array($code, $actual, true)) continue;
        unset($bound[$code]);
        $errors[$code] = 'портал принял подписку, но в списке подписок её нет';
    }
    foreach (array_keys($recheck) as $code) {
        if (!in_array($code, $actual, true)) continue;
        unset($errors[$code]);
        $bound[$code] = true;
    }
    return [$bound, $errors];
}

/**
 * На какие события портал реально шлёт нам сейчас.
 *
 * Отвечает на вопрос «а работает ли вообще»: объявить можно что угодно, значение
 * имеет только то, что портал подтвердил. Пустой массив при непустом объявлении —
 * это и есть картина «приложение выглядит здоровым и молчит».
 *
 * @return array{ok:bool, bound:string[], error:string} ok=false означает «неизвестно»,
 *         а не «ноль»: портал мог не ответить, и выдавать это за отсутствие подписок нельзя.
 */
function eventsActual(B24 $b24): array {
    try {
        $res = $b24->call('event.get', []);
    } catch (Throwable $e) {
        return ['ok' => false, 'bound' => [], 'error' => 'портал не ответил на запрос списка подписок'];
    }
    $rows = $res['result'] ?? null;
    if (!is_array($rows)) {
        return ['ok' => false, 'bound' => [], 'error' => 'портал вернул список подписок в неизвестном виде'];
    }
    $ours = eventsHandlerUrl(APP_URL);
    $bound = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $handler = (string)($row['handler'] ?? $row['HANDLER'] ?? '');
        $code    = strtoupper((string)($row['event'] ?? $row['EVENT'] ?? ''));
        if ($code === '') continue;
        // Offline-подписка хранится в очереди портала и handler не имеет.
        // Совпадение одного кода события не доказывает online webhook нам.
        $offline = $row['offline'] ?? $row['OFFLINE'] ?? null;
        $eventType = strtolower(trim((string)($row['event_type'] ?? $row['EVENT_TYPE'] ?? '')));
        if ($eventType === 'offline' || $offline === true || $offline === 1 || $offline === '1') continue;
        // Неизвестную строку и чужой online handler трактуем fail-closed.
        if ($handler === '' || rtrim($handler, '/') !== rtrim($ours, '/')) continue;
        $bound[$code] = true;
    }
    return ['ok' => true, 'bound' => array_keys($bound), 'error' => ''];
}

/**
 * Привязать объявленные события.
 *
 * Сбой подписки НЕ имеет права мешать установке: приложение без событий работает,
 * просто не реагирует на изменения в портале, — а вот не установившееся приложение
 * не работает вовсе. Поэтому каждый отказ ложится в ответ и в журнал, а установка
 * продолжается. Видно это на экране «Состояние»: объявлено столько-то, подключено
 * столько-то.
 *
 * @return array{bound:array<string,bool>, errors:array<string,string>}
 */
/**
 * @param bool $force Переставить подписку, даже если ничего не менялось
 *                    (кнопка «Пересобрать» — когда обработчик сняли руками).
 */
function bindEvents(B24 $b24, bool $force = false): array {
    $spec = eventsSpec();
    if ($spec === []) return ['bound' => [], 'errors' => []];

    // ДЁШЕВО ЗВАТЬ НА КАЖДОМ ОТКРЫТИИ. Раньше каждое открытие приложения слало
    // порталу event.bind по всем событиям плюс общий event.get на перечитывание —
    // и писало в аудит «подписались на события», хотя не менялось ничего. Аудит
    // безопасности из-за этого состоял из ложных записей: настоящую перепривязку
    // (кто-то снял обработчик, сменился адрес) в этом потоке было не разглядеть,
    // а квота портала тратилась на каждый заход каждого сотрудника.
    $fingerprint = eventsFingerprint();
    $state = storeRead(STATE_FILE) ?? [];
    // Метка «портал снял обработчики» сильнее совпавшего отпечатка: у нас-то не
    // менялось ничего, поэтому по отпечатку такая установка выглядит целой.
    $rebindRequired = !empty($state['events_rebind_required']);
    if (!$force && !$rebindRequired
        && (string)($state['events_fingerprint'] ?? '') === $fingerprint) {
        return ['bound' => [], 'errors' => [], 'unchanged' => true];
    }

    $handler = eventsHandlerUrl(APP_URL);
    $bound = $errors = $recheck = [];
    foreach ($spec as $code) {
        try {
            $res = $b24->call('event.bind', [
                'event'   => $code,
                'handler' => $handler,
            ]);
            if (empty($res['result']) && !eventsAlreadyBound($res)) {
                // Отказ ЗАПОМИНАЕМ, но приговор выносит перечитывание ниже:
                // код повторной привязки не документирован, и решать по нему
                // судьбу подписки значит объявлять поломкой рабочую установку.
                $errors[$code] = (string)($res['error_description'] ?? $res['error'] ?? 'портал отказал без объяснения');
                $recheck[$code] = true;
                continue;
            }
        } catch (Throwable $e) {
            // Текст исключения наружу не отдаём: в нём бывают адреса с токеном.
            // Связь могла оборваться ПОСЛЕ того, как портал принял подписку, —
            // поэтому такое событие тоже идёт на перечитывание.
            $errors[$code] = 'связь с порталом прервалась';
            $recheck[$code] = true;
            continue;
        }
        $bound[$code] = true;
    }

    // READ-BACK. «Портал принял» и «портал действительно шлёт» — разные вещи:
    // подписка молча не встаёт при нехватке прав, а ответ при этом успешный.
    // Спрашиваем ОДИН раз списком, а не по событию: лишние обращения к порталу
    // на установке стоят времени, а ответ здесь общий на всех.
    $confirmedByPortal = false;
    if ($bound !== [] || $recheck !== []) {
        $confirm = eventsActual($b24);
        // «Портал не ответил» — это не подтверждение: ниже от этого зависит,
        // снимать ли метку пересборки.
        $confirmedByPortal = !empty($confirm['ok']);
        // Решение о судьбе каждого события — в отдельной чистой функции: сеть
        // здесь заканчивается, а правило начинается, и проверять правило надо
        // таблицей, а не живым порталом.
        [$bound, $errors] = eventsReconcile($bound, $errors, $recheck, $confirm);
    }
    // Отпечаток запоминаем только при полном успехе: иначе неудачная подписка
    // «зафиксируется» и повторной попытки не будет никогда — приложение молча
    // осталось бы без событий портала.
    if ($errors === []) {
        storeUpdate(STATE_FILE, static function (array $s) use ($fingerprint, $confirmedByPortal): array {
            $s['events_fingerprint'] = $fingerprint;
            $s['events_bound_at']    = date('c');
            // Метку пересборки снимает только ПЕРЕЧИТАННЫЙ список подписок.
            // Ответ портала «принято» без read-back'а уже однажды оказывался
            // ложным (нехватка прав отвечает успехом), и снятая по нему метка
            // вернула бы ровно то состояние, ради которого она заведена.
            if ($confirmedByPortal) unset($s['events_rebind_required']);
            return $s;
        });
    }
    // Аудит: подписка меняет поведение приложения для всего портала, и по журналу
    // должно быть видно, когда и на что оно подписалось. Запись идёт только когда
    // подписку действительно ставили — повтор без изменений сюда уже не доходит.
    if ($bound !== []) {
        auditLog('events.bind', ['события' => array_keys($bound), 'обработчик' => $handler]);
    }
    if ($errors !== []) {
        logAppError('события: не удалось подписаться на ' . count($errors) . ' из ' . count($spec)
            . ' — ' . maskSecrets(implode('; ', array_map(
                static fn($c, $t) => $c . ': ' . $t, array_keys($errors), $errors))));
    } elseif ($bound !== []) {
        appLog('info', 'events.bound', ['события' => array_keys($bound)],
            ['msg' => 'подписка на события портала подтверждена']);
    }
    return ['bound' => $bound, 'errors' => $errors];
}

/**
 * Строка для экрана «Состояние»: объявлено — подключено, и что не так.
 *
 * @return array{declared:int, connected:int, missing:string[], status:string, text:string}
 */
function eventsHealth(B24 $b24): array {
    // На экране считаем то, что объявил ПРОЕКТ: служебная подписка на удаление
    // приложения стоит всегда, и включать её в счётчик — значит показывать
    // «объявлено 1, подключено 1» приложению, которое событиями не пользуется.
    // Но и молчать о ней нельзя: если она отвалилась, уборки при удалении не
    // будет, а узнать об этом можно только постфактум.
    $spec = eventsProjectSpec();
    $actual = eventsActual($b24);
    $cleanupBound = $actual['ok']
        ? in_array('ONAPPUNINSTALL', $actual['bound'], true) : null;
    if (!$actual['ok']) {
        // Неизвестно — это не «ноль». Иначе разовый сбой связи выглядел бы как
        // потеря всех подписок и звал бы чинить то, что не сломано.
        return ['declared' => count($spec), 'connected' => 0, 'missing' => [],
                'cleanup_bound' => null, 'status' => 'unknown',
                'text' => 'объявлено ' . count($spec) . ', подключено неизвестно: ' . $actual['error']];
    }
    if ($spec === []) {
        return ['declared' => 0, 'connected' => 0, 'missing' => [],
                'cleanup_bound' => $cleanupBound,
                'status' => $cleanupBound === false ? 'broken' : 'off',
                'text' => $cleanupBound === false
                    ? 'события не используются, но и уборка при удалении приложения не подключена'
                    : 'события не используются'];
    }
    $missing = array_values(array_diff($spec, $actual['bound']));
    $connected = count($spec) - count($missing);
    return [
        'declared' => count($spec),
        'connected' => $connected,
        'missing' => $missing,
        'cleanup_bound' => $cleanupBound,
        'status' => ($missing === [] && $cleanupBound !== false) ? 'ok' : 'broken',
        'text' => $missing === []
            ? 'объявлено ' . count($spec) . ', подключено ' . $connected
            : 'объявлено ' . count($spec) . ', подключено ' . $connected
              . ' — портал не шлёт: ' . implode(', ', $missing),
    ];
}
