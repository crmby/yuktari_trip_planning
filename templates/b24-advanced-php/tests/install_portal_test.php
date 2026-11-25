<?php
declare(strict_types=1);
/**
 * Установка и портал: рамка iframe, домен из POST'а, подписка на события,
 * безопасный повтор пакета.
 *
 * Набор заведён по находкам глубокого ревью 09.08.2026 (R3-001, R3-004, R3-005,
 * R3-011). Общее у всех четырёх — отказ БЕЗ ошибки: страница установки не
 * открывается на коробке, безопасная дозапись ключа не срабатывает, приложение
 * теряет подписку на собственное удаление, «идемпотентный» пакет не повторяется.
 * Каждый случай проверяется наблюдаемым фактом (заголовок живого ответа, счёт
 * обращений к порталу), а не чтением кода.
 */

suite('Установка и портал: рамка, домен, события, идемпотентный пакет');

// ── R3-001. Свежая коробка не блокируется собственным CSP ──────────────────
//
// Заголовок frame-ancestors собирается ДО обработки install POST'а. Пока
// приложение не установлено, домена в сторе нет, и в рамку пускаются только
// облачные маски Битрикса. Коробочный портал с собственным именем в этот список
// не попадает никогда — браузер блокирует iframe раньше, чем установка успевает
// завершиться, и администратор видит пустую рамку без объяснения.
//
// Проверяем по ЖИВОМУ ответу: поднимаем сервер на копии www, где объявлен
// разовый билет установки для коробки (единственный документированный путь
// установки там, где криптообмен невозможен), и смотрим сам заголовок.
$envSrc = (string)file_get_contents(dirname(__DIR__) . '/www/env.php');
$biletSecret = str_repeat('u9Rt', 9);          // 36 знаков
$biletDomain = 'portal.box.invalid';
$envSrc = (string)preg_replace(
    "~define\('UNVERIFIED_FIRST_INSTALL', \[\]\);~",
    "define('UNVERIFIED_FIRST_INSTALL', " . var_export([
        'domain' => $biletDomain,
        'ticket' => $biletSecret,
        'until'  => date('c', time() + 3600),
    ], true) . ');',
    $envSrc,
    1
);
$cspSrv = testServer([], ['fixtures' => ['env.php' => $envSrc]]);
check('сервер для проверки рамки установки поднялся', !empty($cspSrv['ok']));
if (!empty($cspSrv['ok'])) {
    $cspCall = $cspSrv['call'];
    $installBody = static function (array $extra): string {
        return http_build_query($extra + [
            'AUTH_ID'         => 'box-install-access',
            'REFRESH_ID'      => 'box-install-refresh',
            'AUTH_EXPIRES'    => '3600',
            'member_id'       => 'MEMBER-BOX-CSP',
            'APPLICATION_SCOPE' => 'crm,user',
            'status'          => 'L',
            'PLACEMENT'       => 'DEFAULT',
        ]);
    };

    // Сначала fail-closed: чужая коробка без билета. Её имя в рамку попасть не
    // должно ни при каких условиях — иначе «починка» установки превратилась бы в
    // разрешение встраивать нас откуда угодно.
    testWipe(DATA_ROOT);
    [, , , $chuzhoyHead] = $cspCall('POST', '/index.php', [
        'type' => 'application/x-www-form-urlencoded', 'timeout' => 90,
        'body' => $installBody(['SERVER_ENDPOINT' => 'https://chuzhoy.box.invalid/rest/']),
    ]);
    check('НЕПОДТВЕРЖДЁННЫЙ ОРИГИН В РАМКУ НЕ ПОПАДАЕТ',
        stripos((string)$chuzhoyHead, 'chuzhoy.box.invalid') === false,
        trim((string)strstr((string)$chuzhoyHead, 'Content-Security-Policy')));

    // Отказ обязан оставить в журнале СОСТАВ тела установки. Живой случай: у
    // коробки клиента домен резолвился в хост SERVER_ENDPOINT (облачный сервер
    // авторизации), и по записи «домен из POST: oauth.bitrix24.tech» нельзя было
    // понять, прислал ли портал свой адрес хоть где-то. Без состава разбор
    // упирается в догадки, а второй попытки установки у человека может не быть.
    $otkazRows = journalQuery(['limit' => 50, 'level' => 'error'])['rows'] ?? [];
    $otkazText = '';
    foreach ($otkazRows as $otkazRow) {
        $otkazMsg = (string)(($otkazRow['ctx']['text'] ?? '') ?: ($otkazRow['msg'] ?? ''));
        if (strpos($otkazMsg, 'первая установка не подтверждена') !== false) { $otkazText = $otkazMsg; break; }
    }
    check('ОТКАЗ УСТАНОВКИ НАЗЫВАЕТ СОСТАВ ТЕЛА, А НЕ ТОЛЬКО ДОМЕН',
        $otkazText !== '' && strpos($otkazText, 'тело установки:') !== false
            && strpos($otkazText, 'REFRESH_ID') !== false
            && strpos($otkazText, 'SERVER_ENDPOINT=chuzhoy.box.invalid') !== false,
        $otkazText !== '' ? $otkazText : 'записи об отказе установки в журнале нет');
    check('в журнал ушли ИМЕНА полей, а не значения токенов',
        $otkazText !== '' && strpos($otkazText, 'box-install-refresh') === false
            && strpos($otkazText, 'box-install-access') === false,
        $otkazText);

    // А теперь та же свежая коробка, но с разовым билетом: установка принята,
    // значит и рамка обязана пускать именно её.
    testWipe(DATA_ROOT);
    // Ждём дольше обычного намеренно: портала в проверке нет, и приложение честно
    // отрабатывает все свои обращения к нему до отказа по таймауту.
    [$cspCode, , , $boxHead] = $cspCall('POST', '/index.php', [
        'type' => 'application/x-www-form-urlencoded', 'timeout' => 90,
        'body' => $installBody([
            'SERVER_ENDPOINT' => 'https://' . $biletDomain . '/rest/',
            'install_ticket'  => $biletSecret,
        ]),
    ]);
    $cspLine = trim((string)strstr((string)$boxHead, 'Content-Security-Policy'));
    check('УСТАНОВКА НА СВЕЖЕЙ КОРОБКЕ ОТДАЁТСЯ С РАМКОЙ СВОЕГО ПОРТАЛА',
        stripos($cspLine, 'https://' . $biletDomain) !== false,
        'код ' . $cspCode . '; ' . ($cspLine !== '' ? $cspLine : 'заголовка нет вовсе'));
    check('токены свежей коробки сохранены — установка действительно прошла',
        (string)(storeRead(B24_TOKENS_FILE)['domain'] ?? '') === $biletDomain);
    check('в рамке остался ровно один подтверждённый оригин, без облачных масок',
        stripos($cspLine, '*.bitrix24.') === false, $cspLine);

    // Билет БЕЗ секрета в теле — путь для коробки, где портал этого поля не
    // присылает. Он не должен превращаться в «пускаем всех»: подлинность обязан
    // подтвердить сам портал ответом на присланный токен. Портала в проверке
    // нет, значит подтверждения не будет — и установка обязана не пройти.
    // Иначе достаточно было бы знать адрес обработчика и имя портала.
    testWipe(DATA_ROOT);
    [$bezSekretaCode] = $cspCall('POST', '/index.php', [
        'type' => 'application/x-www-form-urlencoded', 'timeout' => 90,
        'body' => $installBody(['SERVER_ENDPOINT' => 'https://' . $biletDomain . '/rest/']),
    ]);
    // Постоянный ключ — рабочее состояние приложения. Прежде «установлено или
    // нет» считалось только по токенам, и приложение, которому есть чем ходить в
    // портал, встречало человека страницей «ещё не установлено» с советом нажать
    // «Установить» — кнопку, которая на таком портале сработать и не может.
    // Ключ задаётся настройкой ДО запуска, поэтому спрашиваем отдельный процесс:
    // в текущем константа уже определена пустой, и проверка выродилась бы в
    // сравнение пустого с пустым, ничего не доказав.
    $korenProekta = dirname(__DIR__);
    $sKlyuchom = static function (string $tokeny, string $klyuch) use ($korenProekta): string {
        $probe = sys_get_temp_dir() . '/bxapp-webhook-phase-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($probe, "<?php\nrequire " . var_export($korenProekta . '/www/api/lib.php', true)
            . ";\necho appInstallationPhaseFrom($tokeny);\n");
        $out = (string)shell_exec('BXAPP_WEBHOOK_URL=' . escapeshellarg($klyuch)
            . ' BXAPP_DATA_ROOT=' . escapeshellarg(DATA_ROOT)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
        @unlink($probe);
        return trim($out);
    };
    $klyuchObrazec = 'https://portal.box.invalid/rest/35/klyuchportala123/';
    check('С ПОСТОЯННЫМ КЛЮЧОМ ПРИЛОЖЕНИЕ НЕ СЧИТАЕТСЯ НЕУСТАНОВЛЕННЫМ',
        $sKlyuchom('[]', $klyuchObrazec) === 'installed', $sKlyuchom('[]', $klyuchObrazec));
    check('без ключа и без токенов приложение по-прежнему не установлено',
        $sKlyuchom('[]', '') === 'empty', $sKlyuchom('[]', ''));
    // Ключ кривой формы — не канал: иначе опечатка в настройке молча выдавала бы
    // приложение за работающее, а каждый вызов портала уходил бы в никуда.
    check('ключ негодной формы каналом не считается',
        $sKlyuchom('[]', 'http://portal.box.invalid/rest/35/klyuch/') === 'empty',
        $sKlyuchom('[]', 'http://portal.box.invalid/rest/35/klyuch/'));
    // Удаление и уборка главнее ключа: снесённую установку он воскрешать не должен.
    check('удалённая установка ключом не воскресает',
        $sKlyuchom('["uninstalled_at" => 1]', $klyuchObrazec) === 'uninstalled'
        && $sKlyuchom('["cleanup_pending" => true]', $klyuchObrazec) === 'pending');

    check('БЕЗ ПОДТВЕРЖДЕНИЯ ПОРТАЛА УСТАНОВКА ПО БИЛЕТУ НЕ ПРОХОДИТ',
        (int)$bezSekretaCode !== 200 && (storeRead(B24_TOKENS_FILE)['access_token'] ?? '') === '',
        'код ' . $bezSekretaCode . '; токены: '
            . json_encode(array_keys((array)(storeRead(B24_TOKENS_FILE) ?? [])), JSON_UNESCAPED_UNICODE));
}
testWipe(DATA_ROOT);

// ── Работа постоянным ключом: приложение отвечает, а не отсылает «установите» ──
//
// Приложение на КАЖДОМ запросе требует поколение установки. При работе ключом
// установки нет, поколения взять неоткуда — и живое приложение с рабочим ключом
// отвечало «приложение ещё не установлено» вообще всем, советуя нажать кнопку,
// которая на таком портале и не может сработать. Проверяем по живому ответу, а
// не по коду: именно этот ответ видит человек.
$envSKlyuchom = (string)preg_replace(
    "~\\\$webhook = \\(string\\)\\(getenv\\('BXAPP_WEBHOOK_URL'\\) \\?: ''\\);~",
    "\$webhook = 'https://portal.box.invalid/rest/35/klyuchportala123/';",
    (string)file_get_contents(dirname(__DIR__) . '/www/env.php'),
    1
);
check('фикстура настроек с постоянным ключом собралась',
    strpos($envSKlyuchom, 'klyuchportala123') !== false);
$whSrv = testServer([], ['fixtures' => ['env.php' => $envSKlyuchom]]);
check('сервер для проверки работы по ключу поднялся', !empty($whSrv['ok']));
if (!empty($whSrv['ok'])) {
    testWipe(DATA_ROOT);
    // Тело берём СЫРЫМ (третий элемент): второй — уже разобранный JSON, а здесь
    // ответ страничный, и разбор дал бы пустой массив вместо текста.
    [$whCode, , $whRaw, $whHead] = $whSrv['call']('GET', '/index.php', ['timeout' => 60]);
    check('С ПОСТОЯННЫМ КЛЮЧОМ ПРИЛОЖЕНИЕ НЕ ОТВЕЧАЕТ «ЕЩЁ НЕ УСТАНОВЛЕНО»',
        strpos((string)$whRaw, 'Приложение ещё не установлено') === false,
        'код ' . $whCode . '; ' . mb_substr(trim(strip_tags((string)$whRaw)), 0, 160));
    // Рамка обязана пускать ИМЕННО этот портал. Иначе браузер не отрисует
    // приложение внутри портала, и человек увидит «отказано в подключении» —
    // ошибку, по которой ни один текст приложения до него не доедет.
    $whCsp = trim((string)strstr((string)$whHead, 'Content-Security-Policy'));
    check('РАМКА С ПОСТОЯННЫМ КЛЮЧОМ ПУСКАЕТ СВОЙ ПОРТАЛ, А НЕ ОБЛАЧНЫЕ МАСКИ',
        stripos($whCsp, 'https://portal.box.invalid') !== false
            && stripos($whCsp, '*.bitrix24.') === false,
        $whCsp !== '' ? $whCsp : 'заголовка рамки нет вовсе');
}
testWipe(DATA_ROOT);

// ── R3-004. Подлинность install POST'а смотрит на домен ОДНИМ резолвером ───
//
// installPostAuthenticity() читала $post['DOMAIN'] напрямую и сравнивала ===.
// Коробка поля DOMAIN не присылает вовсе (у неё SERVER_ENDPOINT), а облако
// вполне может прислать имя в другом регистре. В обоих случаях безопасная
// дозапись application_token не запускалась, и приложение навсегда оставалось
// без строгой сверки — то есть без самой сильной проверки подлинности.
$tofuInstall = static function (string $domain, string $memberId) {
    testWipe(DATA_ROOT);
    $b = new B24('local.test.app', 'secret');
    // Ставим БЕЗ application_token: именно так выглядит старая установка, ради
    // которой и существует одноразовое доверие.
    $b->saveTokensFromInstall([
        'AUTH_ID' => 'installer-access', 'REFRESH_ID' => 'installer-refresh',
        'AUTH_EXPIRES' => 3600, 'DOMAIN' => $domain, 'member_id' => $memberId,
        'PROTOCOL' => '1',
    ]);
    return new B24('local.test.app', 'secret');
};
$portalSaysAdmin = static function (bool $admin): callable {
    return static function (string $domain, string $method, string $authId) use ($admin): ?array {
        if ($method === 'user.current') return ['result' => ['ID' => '7', 'UF_DEPARTMENT' => [1]]];
        if ($method === 'user.admin')   return ['result' => $admin];
        return null;
    };
};

$GLOBALS['__b24_portal_get'] = $portalSaysAdmin(true);
$boxAuth = installPostAuthenticity(
    $tofuInstall('portal.box.invalid', 'MEMBER-TOFU-BOX'),
    ['AUTH_ID' => 'admin-token-box', 'SERVER_ENDPOINT' => 'https://portal.box.invalid/rest/'],
    true
);
check('КОРОБОЧНЫЙ POST БЕЗ DOMAIN ДОХОДИТ ДО ПРОВЕРКИ ПОДЛИННОСТИ',
    !empty($boxAuth['tofu']) && !empty($boxAuth['authentic']),
    json_encode($boxAuth, JSON_UNESCAPED_UNICODE));

$caseAuth = installPostAuthenticity(
    $tofuInstall('portal-case.invalid', 'MEMBER-TOFU-CASE'),
    ['AUTH_ID' => 'admin-token-case', 'DOMAIN' => 'Portal-Case.INVALID'],
    true
);
check('ДОМЕН В ДРУГОМ РЕГИСТРЕ — ТОТ ЖЕ ПОРТАЛ, А НЕ ЧУЖОЙ',
    !empty($caseAuth['tofu']), json_encode($caseAuth, JSON_UNESCAPED_UNICODE));

$foreignAuth = installPostAuthenticity(
    $tofuInstall('portal-own.invalid', 'MEMBER-TOFU-OWN'),
    ['AUTH_ID' => 'admin-token-foreign', 'DOMAIN' => 'portal-chuzhoy.invalid'],
    true
);
check('POST с чужого портала одноразовое доверие не получает',
    empty($foreignAuth['tofu']), json_encode($foreignAuth, JSON_UNESCAPED_UNICODE));

$endpointAuth = installPostAuthenticity(
    $tofuInstall('portal-own2.invalid', 'MEMBER-TOFU-OWN2'),
    ['AUTH_ID' => 'admin-token-ep', 'SERVER_ENDPOINT' => 'https://chuzhoy2.box.invalid/rest/'],
    true
);
check('чужой SERVER_ENDPOINT одноразовое доверие не получает',
    empty($endpointAuth['tofu']), json_encode($endpointAuth, JSON_UNESCAPED_UNICODE));

$GLOBALS['__b24_portal_get'] = $portalSaysAdmin(false);
$notAdminAuth = installPostAuthenticity(
    $tofuInstall('portal.box.invalid', 'MEMBER-TOFU-BOX2'),
    ['AUTH_ID' => 'user-token-box', 'SERVER_ENDPOINT' => 'https://portal.box.invalid/rest/'],
    true
);
check('обычный сотрудник коробки одноразовое доверие не получает',
    empty($notAdminAuth['tofu']), json_encode($notAdminAuth, JSON_UNESCAPED_UNICODE));
unset($GLOBALS['__b24_portal_get']);
testWipe(DATA_ROOT);

// ── R3-005. ONAPPUPDATE снимает отпечаток подписки на события ──────────────
//
// Портал удаляет обработчики событий при обновлении приложения
// (api-reference/events/event-bind.md: «При удалении и обновлении приложения его
// действия будут удаляться»). Отпечаток в состоянии при этом остаётся прежним, и
// bindEvents() считает, что подписывать нечего. Приложение выглядит здоровым и
// молча не узнаёт о собственном удалении — то есть не убирает за собой токены
// портала.
require_once dirname(__DIR__) . '/www/api/events-bind.php';

// Offline-подписка того же события — не online webhook приложения. У неё нет
// handler, и раньше пустой handler ошибочно считался нашим.
testWipe(DATA_ROOT);
$eventsActualB24 = new B24('local.test.app', 'secret');
$eventsActualB24->saveTokensFromInstall([
    'AUTH_ID' => 'events-actual-access', 'REFRESH_ID' => 'events-actual-refresh',
    'AUTH_EXPIRES' => 3600, 'DOMAIN' => 'portal-events.invalid',
    'member_id' => 'MEMBER-EVENTS-ACTUAL', 'APPLICATION_TOKEN' => 'events-actual-token',
    'PROTOCOL' => '1',
]);
$actualRows = static function (array $rows): array {
    $GLOBALS['__b24_call'] = static function (string $method, array $params) use ($rows): array {
        return $method === 'event.get' ? ['result' => $rows] : ['result' => true];
    };
    return eventsActual(b24());
};
$onlyOffline = $actualRows([['event' => 'ONAPPUNINSTALL', 'handler' => '', 'offline' => 1]]);
check('OFFLINE-ПОДПИСКА НЕ ПОДТВЕРЖДАЕТ ONLINE WEBHOOK',
    !in_array('ONAPPUNINSTALL', $onlyOffline['bound'] ?? [], true), json_encode($onlyOffline));
$foreignOnline = $actualRows([['event' => 'ONAPPUNINSTALL', 'handler' => 'https://foreign.invalid/event.php']]);
check('чужой online handler не считается нашим',
    !in_array('ONAPPUNINSTALL', $foreignOnline['bound'] ?? [], true), json_encode($foreignOnline));
$ourOnline = $actualRows([['event' => 'ONAPPUNINSTALL', 'handler' => eventsHandlerUrl(APP_URL)]]);
check('точный непустой online handler подтверждает подписку',
    in_array('ONAPPUNINSTALL', $ourOnline['bound'] ?? [], true), json_encode($ourOnline));
$mixed = $actualRows([
    ['event' => 'ONAPPUNINSTALL', 'handler' => '', 'EVENT_TYPE' => 'offline'],
    ['event' => 'ONAPPUNINSTALL', 'handler' => eventsHandlerUrl(APP_URL)],
]);
check('в смешанном ответе подписку подтверждает только наш online handler',
    ($mixed['bound'] ?? []) === ['ONAPPUNINSTALL'], json_encode($mixed));
unset($GLOBALS['__b24_call']);
testWipe(DATA_ROOT);

$eventsFresh = static function (string $token = 'key-ev'): B24 {
    testWipe(DATA_ROOT);
    $b = new B24('local.test.app', 'secret');
    $b->saveTokensFromInstall([
        'AUTH_ID' => 'ev-access', 'REFRESH_ID' => 'ev-refresh', 'AUTH_EXPIRES' => 3600,
        'DOMAIN' => 'portal-events.invalid', 'member_id' => 'MEMBER-EV',
        'APPLICATION_TOKEN' => $token, 'PROTOCOL' => '1',
    ]);
    // Состояние «всё уже привязано»: ровно так выглядит приложение, отработавшее
    // установку неделю назад.
    storeUpdate(STATE_FILE, static function (array $s): array {
        $s['events_fingerprint'] = eventsFingerprint();
        $s['events_bound_at']    = date('c');
        $s['placements_fingerprint'] = 'placements-before-update';
        $s['placements_bound_at'] = date('c');
        return $s;
    });
    return $b;
};
$eventsPortal = static function (array &$calls, bool $portalListsUs = true): callable {
    return static function (string $method, array $params) use (&$calls, $portalListsUs): array {
        $calls[] = $method;
        if ($method === 'event.bind') return ['result' => true];
        if ($method === 'event.get') {
            return ['result' => $portalListsUs
                ? [['event' => 'ONAPPUNINSTALL', 'handler' => eventsHandlerUrl(APP_URL)]]
                : []];
        }
        return ['result' => []];
    };
};

// Опора: без события ничего не пересобирается — иначе каждое открытие
// приложения слало бы порталу подписки заново.
$b24ev = $eventsFresh();
$calmCalls = [];
$GLOBALS['__b24_call'] = $eventsPortal($calmCalls);
$calm = bindEvents($b24ev);
check('без события подписка не пересобирается — открытие приложения бесплатно',
    !empty($calm['unchanged']) && $calmCalls === [], implode(',', $calmCalls));

// Неаутентифицированный ONAPPUPDATE не имеет права ничего менять.
$fakeLife = lifecycleTokenEvent([
    'event' => 'ONAPPUPDATE',
    'auth'  => ['application_token' => 'key-ev', 'member_id' => 'CHUZHOY-MEMBER',
                'domain' => 'portal-events.invalid'],
]);
$fakeCalls = [];
$GLOBALS['__b24_call'] = $eventsPortal($fakeCalls);
$afterFake = bindEvents(new B24('local.test.app', 'secret'));
check('чужой ONAPPUPDATE отклонён и подписку не трогает',
    is_array($fakeLife) && empty($fakeLife['ok']) && !empty($afterFake['unchanged']) && $fakeCalls === [],
    json_encode([$fakeLife, $fakeCalls], JSON_UNESCAPED_UNICODE));
check('чужой ONAPPUPDATE не снимает отпечаток плейсментов',
    (string)((storeRead(STATE_FILE) ?? [])['placements_fingerprint'] ?? '') === 'placements-before-update');

// Настоящий ONAPPUPDATE с тем же ключом приложения.
$life = lifecycleTokenEvent([
    'event' => 'ONAPPUPDATE',
    'auth'  => ['application_token' => 'key-ev', 'member_id' => 'MEMBER-EV',
                'domain' => 'portal-events.invalid'],
]);
check('ONAPPUPDATE с тем же ключом принят порталом-владельцем',
    is_array($life) && !empty($life['ok']), json_encode($life, JSON_UNESCAPED_UNICODE));
check('ПРИНЯТЫЙ ONAPPUPDATE СНИМАЕТ УСТАРЕВШИЙ ОТПЕЧАТОК ПЛЕЙСМЕНТОВ',
    !array_key_exists('placements_fingerprint', storeRead(STATE_FILE) ?? []),
    json_encode(storeRead(STATE_FILE), JSON_UNESCAPED_UNICODE));
$updCalls = [];
$GLOBALS['__b24_call'] = $eventsPortal($updCalls);
$afterUpdate = bindEvents(new B24('local.test.app', 'secret'));
check('ПОСЛЕ ONAPPUPDATE ПОДПИСКА СТАВИТСЯ ЗАНОВО, А НЕ СЧИТАЕТСЯ ЦЕЛОЙ',
    empty($afterUpdate['unchanged']) && in_array('event.bind', $updCalls, true)
    && in_array('event.get', $updCalls, true) && ($afterUpdate['errors'] ?? []) === [],
    implode(',', $updCalls) . ' | ' . json_encode($afterUpdate, JSON_UNESCAPED_UNICODE));

// Подтверждено перечитыванием — метку снимаем, следующее открытие снова
// бесплатное.
$settledCalls = [];
$GLOBALS['__b24_call'] = $eventsPortal($settledCalls);
$settled = bindEvents(new B24('local.test.app', 'secret'));
check('подтверждённая подписка больше не пересобирается на каждом открытии',
    !empty($settled['unchanged']) && $settledCalls === [], implode(',', $settledCalls));

// Тот же update, но портал повернул ключ приложения: подлинность доказывает
// ответ портала на присланный access_token. Подписку это обязано пересобрать так
// же — обработчики сняты независимо от того, менялся ключ или нет.
$eventsFresh('key-ev-old');
$GLOBALS['__b24_portal_get'] = static function (string $domain, string $method, string $authId): ?array {
    if ($method === 'user.current') return ['result' => ['ID' => '3', 'UF_DEPARTMENT' => [1]]];
    if ($method === 'user.admin')   return ['result' => true];
    return null;
};
$rotated = lifecycleTokenEvent([
    'event' => 'ONAPPUPDATE',
    'auth'  => ['application_token' => 'key-ev-new', 'member_id' => 'MEMBER-EV',
                'domain' => 'portal-events.invalid', 'access_token' => 'rotate-access'],
]);
unset($GLOBALS['__b24_portal_get']);
$rotCalls = [];
$GLOBALS['__b24_call'] = $eventsPortal($rotCalls);
$afterRotate = bindEvents(new B24('local.test.app', 'secret'));
check('ПОСЛЕ РОТАЦИИ КЛЮЧА ПОДПИСКА ТОЖЕ СТАВИТСЯ ЗАНОВО',
    is_array($rotated) && !empty($rotated['ok']) && !empty($rotated['adopted'])
    && empty($afterRotate['unchanged']) && in_array('event.bind', $rotCalls, true),
    json_encode($rotated, JSON_UNESCAPED_UNICODE) . ' | ' . implode(',', $rotCalls));

// Портал не подтвердил список — метку не снимаем: «неизвестно» это не «готово».
$b24ev2 = $eventsFresh('key-ev2');
lifecycleTokenEvent([
    'event' => 'ONAPPUPDATE',
    'auth'  => ['application_token' => 'key-ev2', 'member_id' => 'MEMBER-EV',
                'domain' => 'portal-events.invalid'],
]);
$blindCalls = [];
$GLOBALS['__b24_call'] = static function (string $method, array $params) use (&$blindCalls): array {
    $blindCalls[] = $method;
    if ($method === 'event.bind') return ['result' => true];
    if ($method === 'event.get')  return ['error' => 'http_error'];   // портал молчит
    return ['result' => []];
};
bindEvents(new B24('local.test.app', 'secret'));
$retryCalls = [];
$GLOBALS['__b24_call'] = $eventsPortal($retryCalls);
$afterBlind = bindEvents(new B24('local.test.app', 'secret'));
check('БЕЗ ПЕРЕЧИТЫВАНИЯ СПИСКА МЕТКА ПЕРЕСБОРКИ НЕ СНИМАЕТСЯ',
    empty($afterBlind['unchanged']) && in_array('event.get', $retryCalls, true),
    implode(',', $retryCalls) . ' | ' . json_encode($afterBlind, JSON_UNESCAPED_UNICODE));
unset($GLOBALS['__b24_call']);
testWipe(DATA_ROOT);

// ── R3-011. Идемпотентный пакет действительно повторяется ──────────────────
//
// batchAllIdempotent() требует объяснить, почему повтор безопасен, пишет это в
// журнал — и не передаёт признак дальше. Внутри отрабатывает обычный batch, для
// которого потерянный ответ означает «не повторять»: 502 от портала возвращался
// вызывающему после ОДНОЙ попытки. Обещание безопасного повтора не выполнялось
// вовсе, а на экране это выглядит как пропавшее уведомление.
$batchClient = new B24('local.test.app', 'secret');
$batchClient->saveTokensFromInstall([
    'AUTH_ID' => 'batch-access', 'REFRESH_ID' => 'batch-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-batch.invalid', 'member_id' => 'MEMBER-BATCH', 'PROTOCOL' => '1',
]);
$batchTries = 0;
$GLOBALS['__b24_transport'] = static function (string $method, array $params) use (&$batchTries): array {
    if ($method !== 'batch') return ['result' => []];
    $batchTries++;
    if ($batchTries === 1) return ['__code' => 502, 'error' => 'INTERNAL_SERVER_ERROR'];
    $cmd = (array)($params['cmd'] ?? []);
    $ok = [];
    foreach ($cmd as $key => $q) $ok[$key] = ['echo' => $key];
    return ['result' => ['result' => $ok, 'result_error' => []]];
};
$plainRes = $batchClient->batchAll(['x' => ['crm.item.add', ['fields' => ['TITLE' => 'раз']]]]);
check('обычный пакет мутаций после 502 НЕ повторяется — иначе задвоится сделка',
    $batchTries === 1 && ($plainRes['errors'] ?? []) !== [], 'попыток ' . $batchTries);

$batchTries = 0;
$idemRes = $batchClient->batchAllIdempotent(
    ['x' => ['im.notify.system.add', ['USER_ID' => 1, 'MESSAGE' => 'привет', 'TAG' => 'bxapp-1']]],
    'у каждого уведомления своя метка: портал заменяет прежнее сообщение, а не добавляет второе'
);
check('ИДЕМПОТЕНТНЫЙ ПАКЕТ ПОСЛЕ 502 ПОВТОРЯЕТСЯ И ДОХОДИТ',
    $batchTries === 2 && ($idemRes['errors'] ?? []) === [] && isset($idemRes['result']['x']),
    'попыток ' . $batchTries . '; ' . json_encode($idemRes, JSON_UNESCAPED_UNICODE));

// Тот же корень, соседнее место: повторять или нет, решалось по одному имени
// метода «batch». Пакет из ОДНИХ ЧТЕНИЙ ничего в портале не меняет, но после
// разового 502 не повторялся ни разу — а выглядит это как «половина данных
// пропала»: выборка молча обрывается на середине.
$batchTries = 0;
$readRes = $batchClient->batchAll([
    'p1' => ['crm.item.list', ['entityTypeId' => 2, 'start' => 0]],
    'p2' => ['crm.item.list', ['entityTypeId' => 2, 'start' => 50]],
]);
check('ПАКЕТ ИЗ ОДНИХ ЧТЕНИЙ ПОСЛЕ 502 ПОВТОРЯЕТСЯ — ВЫБОРКА НЕ ТЕРЯЕТ ХВОСТ',
    $batchTries === 2 && ($readRes['errors'] ?? []) === [] && count($readRes['result']) === 2,
    'попыток ' . $batchTries . '; ' . json_encode($readRes, JSON_UNESCAPED_UNICODE));
unset($GLOBALS['__b24_transport']);
testWipe(DATA_ROOT);
