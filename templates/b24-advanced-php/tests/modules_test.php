<?php
declare(strict_types=1);
/** Регрессии готовых модулей: форматтеры, placement, health и строгие контракты API. */

suite('Готовые модули: точность и fail-closed контракты');

$root = dirname(__DIR__);
require_once $root . '/www/api/fmt.php';
require_once $root . '/www/api/placements.php';

// ── Даты и деньги ────────────────────────────────────────────────────────
check('календарная дата проверяется по-настоящему, а не одной регуляркой',
    b24Day('2025-02-29') === '' && b24Day('2024-02-29T23:10:00+03:00') === '2024-02-29'
    && b24Day('2024-02-29мусор') === '');
check('обратный диапазон нормализуется и остаётся полуоткрытым',
    b24Range('2026-08-10', '2026-08-01') === ['2026-08-01', '2026-08-11']);

$badMoney = false;
try { money('abc'); } catch (InvalidArgumentException $e) { $badMoney = true; }
check('мусорная сумма не превращается молча в ноль', $badMoney);
$floatMoney = false;
try { money(1.23); } catch (InvalidArgumentException $e) { $floatMoney = true; }
check('float отвергается до скрытой потери копеек', $floatMoney);
check('decimal-строка считается точно за границей точности float',
    money('90071992547409.91') === 9007199254740991);
check('округление decimal выполняется строками один раз',
    money('1.005') === 101 && money('-1.005') === -101);
check('формат для Б24 не переводит крупные копейки во float',
    moneyForB24(9007199254740991) === '90071992547409.91');
check('точная нижняя граница int не теряется и форматируется без abs/float',
    money('-92233720368547758.08') === PHP_INT_MIN
    && moneyForB24(PHP_INT_MIN) === '-92233720368547758.08');

// ── Сумма, ПРИШЕДШАЯ ИЗ ПОРТАЛА ────────────────────────────────────────────
// money() намеренно отвергает float: у человека и в настройках сумма приходит
// строкой, и молчаливое «1.1 → 1.10» скрыло бы потерю копеек. Но у ПОРТАЛА
// денежное поле документировано как `double`
// (/data/config/b24-rest-docs/api-reference/crm/universal/object-fields.md —
// «opportunity | double | Сумма»), и в официальном примере ответа стоит обычное
// JSON-число: "opportunity": 999.9 (там же, crm-item-get.md). После штатного
// json_decode(..., true) это PHP float — то есть обычная сделка с дробной суммой
// роняла весь отчёт исключением «float уже мог потерять копейки» (находка ревью
// 09.08.2026, R3-032). Проверяем на куске НАСТОЯЩЕГО ответа, а не на строке,
// придуманной под удобный контракт.
$otvetPortala = json_decode('{"result":{"item":{"id":250,"stageId":"IN_PROCESS",'
    . '"opportunity":999.9,"currencyId":"RUB","title":"Лид #250"}}}', true);
$summaPortala = $otvetPortala['result']['item']['opportunity'];
check('ОФИЦИАЛЬНЫЙ ОТВЕТ ПОРТАЛА ДАЁТ ИМЕННО FLOAT — контракт обязан это учитывать',
    is_float($summaPortala), gettype($summaPortala));
$kopeykiPortala = null;
$sboyPortala = '';
try { $kopeykiPortala = moneyFromB24($summaPortala); }
catch (Throwable $e) { $sboyPortala = get_class($e) . ': ' . $e->getMessage(); }
check('СУММА СДЕЛКИ ИЗ ОТВЕТА ПОРТАЛА СЧИТАЕТСЯ В КОПЕЙКИ, А НЕ ЛОМАЕТ ОТЧЁТ',
    $kopeykiPortala === 99990, $sboyPortala !== '' ? $sboyPortala : var_export($kopeykiPortala, true));
check('и показывается человеку той же суммой, что пришла из портала',
    $kopeykiPortala !== null && moneyStr($kopeykiPortala) === '999,90',
    $kopeykiPortala === null ? $sboyPortala : moneyStr($kopeykiPortala));
// Округление на копейке названо явно: половина идёт вверх — так же, как у
// decimal-строки. Иначе «то же число двумя путями» давало бы разные копейки.
check('половина копейки округляется вверх — так же, как у decimal-строки',
    moneyFromB24(1.005) === 101 && moneyFromB24(-1.005) === -101 && money('1.005') === 101,
    moneyFromB24(1.005) . ' | ' . moneyFromB24(-1.005));
// Целое и строка сохраняют прежний контракт: адаптер ничего не ослабляет.
check('целое и decimal-строка проходят прежним, строгим путём',
    moneyFromB24(1500) === 150000 && moneyFromB24('1 234 567,89') === 123456789
    && moneyFromB24(null) === 0 && moneyFromB24('') === 0,
    moneyFromB24(1500) . ' | ' . moneyFromB24('1 234 567,89'));
$otkazSummy = static function ($znachenie): string {
    try { moneyFromB24($znachenie); return ''; } catch (Throwable $e) { return get_class($e); }
};
check('НЕ-ЧИСЛО ОТ ПОРТАЛА ОТВЕРГАЕТСЯ, А НЕ СЧИТАЕТСЯ НУЛЁМ',
    $otkazSummy(NAN) === 'InvalidArgumentException'
    && $otkazSummy(INF) === 'InvalidArgumentException'
    && $otkazSummy(-INF) === 'InvalidArgumentException'
    && $otkazSummy(true) === 'InvalidArgumentException'
    && $otkazSummy(['сумма' => 1]) === 'InvalidArgumentException',
    $otkazSummy(NAN) . ' | ' . $otkazSummy(INF) . ' | ' . $otkazSummy(true));
// За 2^53 у двоичного числа шаг больше рубля: такая «сумма» уже не сумма, и
// молча превращать её в копейки нельзя.
check('сумма за пределом точности двоичного числа отвергается, а не досчитывается',
    $otkazSummy(1.0e18) === 'OverflowException' && $otkazSummy(-1.0e18) === 'OverflowException'
    && moneyFromB24(1.0e15) === 100000000000000000,
    $otkazSummy(1.0e18) . ' | ' . $otkazSummy(-1.0e18));
// Запись через экспоненту — тот же float, и восстановление обязано дать ту же
// сумму, а не «0» и не строку «1.0E-5».
check('число в экспоненциальной записи не проходит мимо разбора',
    moneyFromB24(1.0e3) === 100000 && moneyFromB24(1.5e2) === 15000,
    moneyFromB24(1.0e3) . ' | ' . moneyFromB24(1.5e2));
// Длинная дробная часть: копейки берутся половиной вверх, разряды не теряются.
check('крупная сумма с копейками восстанавливается разряд в разряд',
    moneyFromB24(12345678901234.56) === 1234567890123456,
    (string)moneyFromB24(12345678901234.56));

// ── Деньги ГЛАЗАМИ ЧЕЛОВЕКА ────────────────────────────────────────────────
// moneyStr() — единственное место, которое показывает суммы на экране и в
// выгрузке, и до круга 2 ревью её не проверяла ни одна строка: ошибка здесь тихо
// врёт человеку про деньги, а тесты остаются зелёными.
check('копейки печатаются с разрядами и запятой',
    moneyStr(123456789) === '1 234 567,89' && moneyStr(5) === '0,05' && moneyStr(0) === '0,00',
    moneyStr(123456789) . ' | ' . moneyStr(5) . ' | ' . moneyStr(0));
check('минус ставится один раз и перед всей суммой',
    moneyStr(-123456789) === '-1 234 567,89' && moneyStr(-5) === '-0,05',
    moneyStr(-123456789) . ' | ' . moneyStr(-5));
// Без копеек ОКРУГЛЯЕМ, а не отбрасываем: иначе итог отчёта не сходится с
// порталом, а −0,49 печатается как «-0» — минуса у нуля не бывает.
check('сумма без копеек округляется, а не обрезается',
    moneyStr(15050, false) === '151' && moneyStr(15049, false) === '150'
    && moneyStr(-49, false) === '0' && moneyStr(-51, false) === '-1',
    moneyStr(15050, false) . ' | ' . moneyStr(15049, false) . ' | ' . moneyStr(-49, false));
check('крупные суммы не уезжают во float и не теряют разряды',
    moneyStr(PHP_INT_MAX) === '92 233 720 368 547 758,07'
    && moneyStr(PHP_INT_MIN) === '-92 233 720 368 547 758,08',
    moneyStr(PHP_INT_MAX) . ' | ' . moneyStr(PHP_INT_MIN));
// Граница типа должна проходить через КАЖДУЮ ветку публичного форматтера.
// Иначе отдельные зелёные тесты «край int с копейками» и «без копеек на
// обычном числе» оставляют непроверенным ровно их опасный cross-product.
$moneyBoundaryMatrix = [
    [PHP_INT_MIN, '-92 233 720 368 547 758,08', '-92 233 720 368 547 758'],
    [PHP_INT_MIN + 1, '-92 233 720 368 547 758,07', '-92 233 720 368 547 758'],
    [-51, '-0,51', '-1'], [-50, '-0,50', '-1'], [-49, '-0,49', '0'],
    [0, '0,00', '0'], [49, '0,49', '0'], [50, '0,50', '1'], [51, '0,51', '1'],
    [PHP_INT_MAX - 1, '92 233 720 368 547 758,06', '92 233 720 368 547 758'],
    [PHP_INT_MAX, '92 233 720 368 547 758,07', '92 233 720 368 547 758'],
];
$moneyBoundaryErrors = [];
foreach ($moneyBoundaryMatrix as [$boundaryValue, $withExpected, $withoutExpected]) {
    foreach ([[true, $withExpected], [false, $withoutExpected]] as [$withCents, $expected]) {
        try { $actual = moneyStr($boundaryValue, $withCents); }
        catch (Throwable $e) { $actual = 'THREW ' . get_class($e); }
        if ($actual !== $expected) {
            $moneyBoundaryErrors[] = $boundaryValue . '/' . ($withCents ? 'cents' : 'rubles')
                . ': ' . $actual . ' != ' . $expected;
        }
    }
}
check('обе ветки moneyStr проходят единую матрицу int-boundary без float и минуса ноль',
    $moneyBoundaryErrors === [], implode(' | ', $moneyBoundaryErrors));
// Показ и отправка в портал обязаны говорить об одной сумме: расхождение здесь
// означает «на экране одно, в сделке другое».
check('показанное человеку и отправленное в портал — одна сумма',
    moneyStr(money('1 234 567,89')) === '1 234 567,89'
    && moneyForB24(money('1 234 567,89')) === '1234567.89',
    moneyStr(money('1 234 567,89')) . ' | ' . moneyForB24(money('1 234 567,89')));

// Offset остаётся offset: +05:30 нельзя подменить геозоной с чужими DST-правилами.
check('дробный timezone offset сохраняется без округления и ложной геозоны',
    b24TzFromOffset(19800) === '+05:30' && b24TzFromOffset(-12600) === '-03:30');
storeWrite(SETTINGS_FILE, ['greeting' => 'до timezone']);
$profileCalls = 0;
$missingTzError = null;
try {
    b24PortalTzResolve(static function () use (&$profileCalls): array {
        $profileCalls++;
        return ['error' => 'TEMPORARY', 'error_description' => 'временный отказ'];
    });
} catch (RuntimeException $e) { $missingTzError = $e->getMessage(); }
check('ошибка profile без кэша не выдаёт timezone сервера за зону портала',
    is_string($missingTzError) && $missingTzError !== ''
    && !array_key_exists('portal_tz', loadSettings()));
$todayWithoutTzError = null;
try { b24Today(); } catch (RuntimeException $e) { $todayWithoutTzError = $e->getMessage(); }
check('b24Today без подтверждённой зоны портала останавливается явно',
    is_string($todayWithoutTzError) && strpos($todayWithoutTzError, 'часовой пояс портала') !== false);
$resolved = b24PortalTzResolve(static function () use (&$profileCalls): array {
    $profileCalls++;
    // Официальный profile может не заполнить TIME_ZONE; offset тогда берётся
    // из системного блока времени ответа, без подбора случайной геозоны.
    return ['result' => ['TIME_ZONE' => ''], 'time' => ['date_start' => '2026-08-03T12:00:00+05:45']];
});
check('после временной ошибки следующий вызов снова спрашивает портал и сохраняет точный offset',
    $profileCalls === 2 && $resolved === '+05:45' && (loadSettings()['portal_tz'] ?? '') === '+05:45'
    && (int)(loadSettings()['portal_tz_checked_at'] ?? 0) > 0);
$cached = b24PortalTzResolve(static function () use (&$profileCalls): array {
    $profileCalls++;
    return ['error' => 'SHOULD_NOT_BE_CALLED'];
});
check('свежая timezone берётся из кеша без лишнего REST-вызова',
    $cached === '+05:45' && $profileCalls === 2);
storeWrite(SETTINGS_FILE, ['greeting' => 'до именованной timezone']);
$named = b24PortalTzResolve(static fn(): array => [
    'result' => ['TIME_ZONE' => 'Europe/Berlin', 'TIME_ZONE_OFFSET' => 10800],
]);
check('официальная IANA timezone имеет приоритет над временным offset и сохраняет DST',
    $named === 'Europe/Berlin' && (loadSettings()['portal_tz'] ?? '') === 'Europe/Berlin');

// ── Placement: старое не теряется при неудачном rebind ──────────────────
$old = ['placement' => 'CRM_DEAL_DETAIL_TAB', 'handler' => 'https://old.example/app/',
    'title' => 'Старая вкладка', 'description' => ''];
$wanted = ['PLACEMENT' => 'CRM_DEAL_DETAIL_TAB', 'HANDLER' => 'https://new.example/app/',
    'TITLE' => 'Новая вкладка', 'DESCRIPTION' => ''];
$placementState = 'old';
$firstBind = true;
$placementCalls = [];
$placementCall = static function (string $method, array $params) use (
    &$placementState, &$firstBind, &$placementCalls, $old, $wanted
): array {
    $placementCalls[] = [$method, $params];
    if ($method === 'placement.get') {
        return ['result' => $placementState === 'old' ? [$old]
            : ($placementState === 'new' ? [[
                'placement' => $wanted['PLACEMENT'], 'handler' => $wanted['HANDLER'],
                'title' => $wanted['TITLE'], 'description' => '',
            ]] : [])];
    }
    if ($method === 'placement.unbind') {
        $placementState = 'none';
        return ['result' => ['count' => 1]];
    }
    if ($method === 'placement.bind' && ($params['HANDLER'] ?? '') === $wanted['HANDLER']) {
        if ($firstBind) {
            $firstBind = false;
            return ['error' => 'ERROR_PLACEMENT_ALREADY_BINDED', 'error_description' => 'already'];
        }
        return ['error' => 'TEMPORARY_BIND_FAILURE', 'error_description' => 'новый bind не принят'];
    }
    if ($method === 'placement.bind' && ($params['HANDLER'] ?? '') === $old['handler']) {
        $placementState = 'old';
        return ['result' => true];
    }
    return ['error' => 'UNEXPECTED_CALL'];
};
$placementResult = placementBindOneUsing($placementCall, $wanted);
check('неудачный bind после ALREADY восстанавливает старую привязку',
    !$placementResult['ok'] && $placementState === 'old'
    && strpos($placementResult['error'], 'восстановлена') !== false);
$methods = array_column($placementCalls, 0);
check('rebind сначала снимает проверенный снимок и действительно проверяет unbind',
    $methods === ['placement.bind', 'placement.get', 'placement.unbind',
                  'placement.bind', 'placement.get', 'placement.bind', 'placement.get']);

$freshCalls = [];
$fresh = placementBindOneUsing(static function (string $method, array $params) use (&$freshCalls, $wanted): array {
    $freshCalls[] = $method;
    if ($method === 'placement.bind') return ['result' => true];
    if ($method === 'placement.get') return ['result' => [[
        'placement' => $wanted['PLACEMENT'], 'handler' => $wanted['HANDLER'],
        'title' => $wanted['TITLE'], 'description' => $wanted['DESCRIPTION'],
        // Портал вправе дополнить get вычисленной локализацией, хотя bind её
        // не задавал: это не должно делать корректную привязку «неверной».
        'langAll' => ['ru' => ['TITLE' => $wanted['TITLE'], 'DESCRIPTION' => '', 'GROUP_NAME' => '']],
    ]]];
    return ['error' => 'UNEXPECTED_CALL'];
}, $wanted);
check('успешный первый bind подтверждается read-back через placement.get',
    $fresh['ok'] && $freshCalls === ['placement.bind', 'placement.get']);

$falseAlready = placementRestoreUsing(static function (string $method, array $params) use ($wanted): array {
    if ($method === 'placement.bind') return ['error' => 'ERROR_PLACEMENT_ALREADY_BINDED'];
    if ($method === 'placement.get') return ['result' => [[
        'placement' => $wanted['PLACEMENT'], 'handler' => $wanted['HANDLER'],
        'title' => 'Чужая привязка', 'description' => '',
    ]]];
    return ['error' => 'UNEXPECTED_CALL'];
}, $old);
check('ALREADY при rollback не считается восстановлением без точного read-back',
    $falseAlready !== '' && strpos($falseAlready, 'не подтвердил') !== false);

$safeState = 'old';
$safeFirst = true;
$safeCalls = [];
$unbindDenied = static function (string $method, array $params) use (&$safeState, &$safeFirst, &$safeCalls, $old, $wanted): array {
    $safeCalls[] = $method;
    if ($method === 'placement.bind' && $safeFirst) {
        $safeFirst = false;
        return ['error' => 'ERROR_PLACEMENT_ALREADY_BINDED'];
    }
    if ($method === 'placement.get') return ['result' => [$old]];
    if ($method === 'placement.unbind') return ['error' => 'ACCESS_DENIED', 'error_description' => 'нет права'];
    return ['error' => 'UNEXPECTED_CALL'];
};
$denied = placementBindOneUsing($unbindDenied, $wanted);
check('ошибка unbind останавливает новый bind и оставляет старую привязку',
    !$denied['ok'] && $safeState === 'old' && count(array_filter($safeCalls,
        static fn(string $m): bool => $m === 'placement.bind')) === 1);

$actualFailed = false;
try {
    placementsActualUsing(static fn(string $method, array $params): array =>
        ['error' => 'TEMPORARY', 'error_description' => 'портал недоступен']);
} catch (RuntimeException $e) {
    $actualFailed = true;
}
check('сбой чтения плейсментов не маскируется под пустой список', $actualFailed);

// ── Живые API-контракты settings/ACL/health ─────────────────────────────
$generation = 'test-generation-modules';
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
    'domain' => 'example.bitrix24.ru', 'member_id' => 'MEMBER-MODULES',
    'scope' => 'crm,user,placement', 'application_token' => 'event-token',
    'install_generation' => $generation, 'expires_at' => time() + 3600,
]);
aclSetOwner(501, 'Владелец модулей');
aclUpdate(static function (array $acl): array {
    $acl['users'][] = ['uid' => 502, 'name' => 'Редактор', 'photo' => '', 'role' => 'edit'];
    return $acl;
});
$adminToken = str_repeat('m', 64);
$editorToken = str_repeat('e', 64);
storeWrite(SESSIONS_FILE, [
    [
        'token' => $adminToken, 'uid' => 501, 'role' => 'admin', 'isAdmin' => false,
        'member_id' => 'MEMBER-MODULES', 'install_generation' => $generation,
        'createdAt' => time(), 'expiresAt' => time() + 3600,
    ],
    [
        'token' => $editorToken, 'uid' => 502, 'role' => 'edit', 'isAdmin' => false,
        'member_id' => 'MEMBER-MODULES', 'install_generation' => $generation,
        'createdAt' => time(), 'expiresAt' => time() + 3600,
    ],
]);
// Ключ берём ИЗ СХЕМЫ ПРОЕКТА, а не по имени из шаблона. Раньше здесь стоял
// литерал 'greeting' — образец шаблона, — и проект, убравший ненужную ему
// настройку, получал красный прогон на ровном месте: тест требовал держать
// чужой пример в своей схеме (жалоба 25.08.2026).
require_once $root . '/www/api/settings-schema.php';
$textKey = '';
foreach (['text', 'multiline', 'secret'] as $iskomyiTip) {
    foreach (settingsSchema() as $pole) {
        if ((string)($pole['type'] ?? '') === $iskomyiTip) { $textKey = (string)$pole['key']; break 2; }
    }
}
// Строковой настройки может не быть вовсе — это законно, и красным такой проект
// быть не должен. Тогда разбор строки проверять не на чем, и мы это говорим.
if ($textKey === '') {
    check('строковых настроек в схеме нет — разбор строки проверять не на чем', true);
}
if ($textKey !== '') storeWrite(SETTINGS_FILE, [$textKey => 'исходное']);

$srv = testServer();
check('сервер для проверки готовых модулей запущен', $srv['ok']);
if (!$srv['ok']) return;
$call = $srv['call'];

// Поиск сотрудников обязан пользоваться всеми готовыми слоями одновременно:
// единым HTTP-входом, валидаторами, B24-клиентом с полной пагинацией и ui.api.
// Эти проверки краснеют при возврате к прямому BX24.callMethod первой страницы.
$usersEndpoint = (string)file_get_contents($root . '/www/api/users.php');
$usersFrontend = (string)file_get_contents($root . '/www/js/app.js');
$usersControls = (string)file_get_contents($root . '/www/js/controls.js');
check('поиск сотрудников входит через admin-only apiBoot с отдельным лимитом частоты',
    strpos($usersEndpoint, "apiBoot(['methods' => ['GET'], 'role' => 'admin', 'rate' => [30, 60]])") !== false);
check('поисковая строка проходит общий валидатор и нижнюю границу длины',
    strpos($usersEndpoint, "apiStr(\$_GET, 'q', ['max' => 100])") !== false
    && strpos($usersEndpoint, '$queryLength < 2') !== false);
check('поиск сотрудников использует готовую полную пагинацию с жёстким пределом',
    strpos($usersEndpoint, "fetchAllBatched('user.get'") !== false
    && strpos($usersEndpoint, "'NAME_SEARCH' => \$query") !== false
    && strpos($usersEndpoint, "\$filter['ACTIVE'] = true") !== false
    && strpos($usersEndpoint, "'USER_TYPE' => 'employee'") !== false
    && strpos($usersEndpoint, 'USER_SEARCH_HARD_LIMIT') !== false);
check('ПОИСК СОТРУДНИКОВ ПРОНОСИТ УМЕНЬШЕННОЕ ФОТО БИТРИКС ДО PICKER И ЧИПА',
    strpos($usersEndpoint, "'IMAGE_RESIZE' => 'small'") !== false
    && strpos($usersEndpoint, "'photo' => userPhotoUrl(\$row['PERSONAL_PHOTO']") !== false
    && strpos($usersControls, 'photo: u.photo') !== false
    && strpos($usersControls, "el('img', 'picker-avatar')") !== false
    && strpos($usersControls, "el('img', 'chip-avatar')") !== false,
    'цепочка user.get → users.php → app.js → ui.pickPeople неполна');
// Отделы ищутся тем же готовым клиентом, но их отсутствие не должно ронять
// поиск людей: у портала может не быть выданного scope department.
// Второй аргумент appLog — слаг события: по нему запись ищут и группируют, а
// человеческую фразу экран журнала берёт из 'msg'. Раньше здесь ждали фразу
// прямо в слаге, и запись приходила на экран без единого слова причины.
check('отделы читаются готовой пагинацией и не ломают поиск людей при отказе',
    strpos($usersEndpoint, "fetchAllBatched('department.get'") !== false
    && strpos($usersEndpoint, 'DEPT_HARD_LIMIT') !== false
    && strpos($usersEndpoint, "appLog('warn', 'department.get.failed'") !== false
    && strpos($usersEndpoint, "'msg' => 'справочник отделов не прочитан") !== false);
check('наружу из поиска выходят только нужные для выбора поля, без почты и телефонов',
    strpos($usersEndpoint, 'array_slice($rows, 0, USER_SEARCH_RESULT_LIMIT)') !== false
    && preg_match('~\\$users\\[\\] = \\[[^;]*\'id\'[^;]*\'name\'[^;]*\'post\'[^;]*\'dept\'[^;]*\'fired\'[^;]*\\];~s', $usersEndpoint) === 1
    && strpos($usersEndpoint, 'EMAIL') === false
    && strpos($usersEndpoint, 'PERSONAL_MOBILE') === false);
check('фронт ищет сотрудников через ui.api без прямого BX24.callMethod',
    strpos($usersFrontend, "ui.api('GET', 'api/users.php?q='") !== false
    && strpos($usersFrontend, "BX24.callMethod('user.search'") === false);
// Доступ выдают пачкой и отделами — контрол обязан быть своим, а не нативным
// полем с одиночным добавлением: иначе владелец добавляет отдел по человеку и
// забывает новичков.
check('выбор людей и отделов идёт через готовый контрол шаблона',
    strpos($usersFrontend, "ui.pickPeople('#user-picker'") !== false
    && strpos($usersFrontend, "kind: 'dept'") !== false
    && strpos($usersFrontend, "photo: it.kind === 'user' ? it.photo : undefined") !== false);

[$code, $json, ] = $call('POST', '/api/users.php', [
    'token' => $adminToken, 'body' => '{}',
]);
check('поиск сотрудников не принимает изменяющий метод',
    $code === 405 && ($json['code'] ?? '') === 'method_not_allowed', 'код ' . $code);
[$code, $json, ] = $call('GET', '/api/users.php?q=Ivan', ['token' => $editorToken]);
check('поиск сотрудников закрыт от роли ниже admin до обращения в портал',
    $code === 403 && ($json['code'] ?? '') === 'forbidden', 'код ' . $code);
[$code, $json, ] = $call('GET', '/api/users.php?q=%D0%98', ['token' => $adminToken]);
check('один символ отклоняется до обращения в портал',
    $code === 400 && ($json['code'] ?? '') === 'bad_field', 'код ' . $code);
// Флаг «искать и уволенных» ставит галочка в интерфейсе, а не обязанность фронта:
// поиск обязан работать и без него. Проверка краснела, пока флаг был обязательным
// полем — тогда ЛЮБОЙ ввод в «Кому дать доступ» получал 400 и доступ не выдавался
// никому вовсе. Смотрим именно на missing_field: дальше запрос уходит в портал,
// которого на стенде нет, и любой другой код здесь законен.
[$code, $json, ] = $call('GET', '/api/users.php?q=%D0%98%D0%B2%D0%B0', ['token' => $adminToken]);
check('поиск сотрудников работает без флага уволенных — фронт его не передаёт',
    ($json['code'] ?? '') !== 'missing_field',
    'код ' . $code . ' ' . (string)($json['error'] ?? ''));

if ($textKey !== '') {
    [$code, $json, ] = $call('POST', '/api/settings.php', [
        'token' => $adminToken, 'body' => json_encode(['settings' => [$textKey => 123]]),
    ]);
    check('текстовая настройка принимает только строку', $code === 400 && ($json['code'] ?? '') === 'bad_field'
        && (loadSettings()[$textKey] ?? '') === 'исходное', 'код ' . $code);
    [$code, $json, ] = $call('POST', '/api/settings.php', [
        'token' => $adminToken,
        'body' => json_encode(['settings' => [$textKey => str_repeat('я', 501)]], JSON_UNESCAPED_UNICODE),
    ]);
    check('текстовая настройка ограничена по длине до записи', $code === 400 && ($json['code'] ?? '') === 'bad_field'
        && (loadSettings()[$textKey] ?? '') === 'исходное', 'код ' . $code);
}

[$code, $json, ] = $call('POST', '/api/acl.php', [
    'token' => $adminToken, 'body' => json_encode(['action' => 'update', 'uid' => 502]),
]);
check('ACL update без явно названной роли отклоняется и не понижает сотрудника',
    $code === 400 && ($json['code'] ?? '') === 'missing_field' && aclUserRole(502) === 'edit', 'код ' . $code);

$badUtfJson = apiJsonEncode(['ok' => true, 'text' => "битая:\xB1"]);
check('невалидная UTF-8 не превращает JSON-ответ в пустое тело',
    $badUtfJson !== '' && is_array(json_decode($badUtfJson, true)) && json_last_error() === JSON_ERROR_NONE);
check('поиск журнала сохраняет регистронезависимый русский fallback без mbstring',
    journalFallbackLower('ОШИБКА ЁЖ') === 'ошибка ёж'
    && journalTextContains('Системная ОШИБКА: ёж', 'ошибка: ЁЖ'));

appBindLogGeneration('old-installation');
appLog('error', 'modules.old.error', [], ['msg' => 'OLD-GENERATION-MARKER']);
appBindLogGeneration($generation);
appLog('error', 'modules.current.error', [], ['msg' => 'CURRENT-GENERATION-MARKER']);
[$code, $health, , , $healthGenerationDiag] = $call('GET', '/api/health.php', ['token' => $adminToken]);
$healthErrors = (string)json_encode($health['last_errors'] ?? [], JSON_UNESCAPED_UNICODE);
check('health показывает ошибки только текущего install_generation',
    $code === 200 && strpos($healthErrors, 'CURRENT-GENERATION-MARKER') !== false
    && strpos($healthErrors, 'OLD-GENERATION-MARKER') === false,
    'код ' . $code . ($code === 0 ? ', transport: ' . json_encode($healthGenerationDiag) : ''));

// Reader отдаёт bounded-результат вместе с явным truncated. Оба
// человеческих окна обязаны сохранить эту оговорку, а не сказать
// «ошибок нет» по неполному журналу.
file_put_contents(journalFile(), "{broken modules journal line\n", FILE_APPEND | LOCK_EX);
[$code, $health, , , $healthJournalDiag] = $call('GET', '/api/health.php', ['token' => $adminToken]);
$problemText = (string)json_encode($health['problems'] ?? [], JSON_UNESCAPED_UNICODE);
check('health сохраняет truncated и показывает неполный журнал как проблему',
    $code === 200 && !empty($health['journal']['truncated'])
    && journalTextContains($problemText, 'журнал ошибок прочитан не полностью'),
    'код ' . $code . ($code === 0 ? ', transport: ' . json_encode($healthJournalDiag) : ''));

// Ревью 23.09.2026 (bezopasnost-1): доступ заблокированного в портале сотрудника
// гасит только фон, раз в 10 минут, а пропуск живёт 8 часов. Тревога о молчащем
// фоне через СУТКИ опаздывала на три срока пропуска: владелец узнавал последним.
$cronStateBefore = storeRead(STATE_FILE) ?? [];
storeUpdate(STATE_FILE, static function (array $s): array {
    $s['cron_last_run_at'] = date('c', time() - 2 * 3600);
    $s['cron_last_ok'] = true;
    return $s;
});
[$code, $health] = $call('GET', '/api/health.php', ['token' => $adminToken]);
$cronProblems = (string)json_encode($health['problems'] ?? [], JSON_UNESCAPED_UNICODE);
check('молчащий два часа фон — тревога в «Состоянии» с последствием для доступа',
    $code === 200 && journalTextContains($cronProblems, 'фоновая обработка не запускалась')
    && journalTextContains($cronProblems, 'пропуск'),
    'код ' . $code . ', проблемы: ' . mb_substr($cronProblems, 0, 300));
storeUpdate(STATE_FILE, static function (array $s) use ($cronStateBefore): array {
    foreach (['cron_last_run_at', 'cron_last_ok'] as $k) {
        if (array_key_exists($k, $cronStateBefore)) $s[$k] = $cronStateBefore[$k]; else unset($s[$k]);
    }
    return $s;
});

$logCliEnv = [
    'BXAPP_DATA_ROOT' => DATA_ROOT,
    'BXAPP_OAUTH_URL' => 'http://127.0.0.1:9/oauth/token/',
    'BXAPP_CLIENT_ID' => 'local.test.app',
    'BXAPP_CLIENT_SECRET' => 'test-secret',
    'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
];
$logCliDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$logCli = proc_open([PHP_BINARY, $root . '/www/bin/logs.php', 'tail', date('Y-m-d')],
    $logCliDesc, $logCliPipes, $root, $logCliEnv);
$logCliRc = 255;
$logCliOut = '';
$logCliErr = 'logs.php не запущен';
if (is_resource($logCli)) {
    fclose($logCliPipes[0]);
    $logCliOut = (string)stream_get_contents($logCliPipes[1]);
    $logCliErr = (string)stream_get_contents($logCliPipes[2]);
    fclose($logCliPipes[1]);
    fclose($logCliPipes[2]);
    $logCliRc = proc_close($logCli);
}
check('CLI журнала предупреждает о truncated выборке',
    $logCliRc === 0 && journalTextContains($logCliErr, 'журнал прочитан не полностью'),
    'код ' . $logCliRc . ', stdout: ' . trim($logCliOut) . ', stderr: ' . trim($logCliErr));

$stateBefore = is_file(STATE_FILE) ? (string)file_get_contents(STATE_FILE) : null;
$stateBadPattern = preg_replace('/\.php$/', '', STATE_FILE) . '.bad-*.php';
$stateBadBefore = glob($stateBadPattern) ?: [];
$corruptStatePayload = 'повреждённое состояние без защитного префикса';
file_put_contents(STATE_FILE, $corruptStatePayload);
$corruptStateHash = hash_file('sha256', STATE_FILE);
$corruptStateStat = lstat(STATE_FILE);
$corruptStateFiles = glob(dirname(STATE_FILE) . '/state*') ?: [];
sort($corruptStateFiles);
[$code, $health, $corruptBody, , $healthCorruptDiag] = $call('GET', '/api/health.php', [
    'token' => $adminToken,
    'headers' => ['Host: poisoned.example.test', 'X-Forwarded-Host: forwarded-poison.example.test'],
]);
[$corruptRepeatCode, $corruptRepeatHealth, , , $healthCorruptRepeatDiag] = $call(
    'GET', '/api/health.php', ['token' => $adminToken]);
$problemText = (string)json_encode($health['problems'] ?? [], JSON_UNESCAPED_UNICODE);
check('health явно показывает corruption, а не выдаёт повреждённый state за пустой',
    $code === 200 && $corruptRepeatCode === 200
    && journalTextContains($problemText, 'повреждено хранилище')
    && array_key_exists('последний_запуск', $health['cron'] ?? [])
    && $health['cron']['последний_запуск'] === null
    && array_key_exists('последний_запуск', $corruptRepeatHealth['cron'] ?? [])
    && $corruptRepeatHealth['cron']['последний_запуск'] === null
    && ($health['app_url'] ?? null) === ''
    && strpos($corruptBody, 'poisoned.example.test') === false
    && strpos($corruptBody, 'forwarded-poison.example.test') === false
    && hash_file('sha256', STATE_FILE) === $corruptStateHash
    && (int)((lstat(STATE_FILE))['size'] ?? -1) === (int)($corruptStateStat['size'] ?? -2)
    && (int)((lstat(STATE_FILE))['mtime'] ?? -1) === (int)($corruptStateStat['mtime'] ?? -2)
    && (glob($stateBadPattern) ?: []) === $stateBadBefore
    && (static function () use ($corruptStateFiles): bool {
        $now = glob(dirname(STATE_FILE) . '/state*') ?: [];
        sort($now);
        return $now === $corruptStateFiles;
    })(),
    'коды ' . $code . '/' . $corruptRepeatCode
    . ($code === 0 ? ', transport: ' . json_encode($healthCorruptDiag) : '')
    . ($corruptRepeatCode === 0 ? ', repeat: ' . json_encode($healthCorruptRepeatDiag) : ''));

[$anonymousHealthCode, , ] = $call('GET', '/api/health.php');
[$editorHealthCode, , ] = $call('GET', '/api/health.php', ['token' => $editorToken]);
check('diagnostic bootstrap повреждённого state не обходит session/admin gate',
    $anonymousHealthCode === 403 && $editorHealthCode === 403
    && hash_file('sha256', STATE_FILE) === $corruptStateHash);

$oversizedStatePayload = str_repeat('x', STATE_MAX_BYTES + 1);
file_put_contents(STATE_FILE, $oversizedStatePayload);
$oversizedStateHash = hash_file('sha256', STATE_FILE);
$oversizedStateStat = lstat(STATE_FILE);
$oversizedStateFiles = glob(dirname(STATE_FILE) . '/state*') ?: [];
sort($oversizedStateFiles);
[$oversizedHealthCode, $oversizedHealth, , , $healthOversizedDiag] = $call(
    'GET', '/api/health.php', ['token' => $adminToken]);
[$oversizedRepeatCode, $oversizedRepeatHealth, , , $healthOversizedRepeatDiag] = $call(
    'GET', '/api/health.php', ['token' => $adminToken]);
$oversizedProblemText = (string)json_encode($oversizedHealth['problems'] ?? [], JSON_UNESCAPED_UNICODE);
check('health диагностирует oversized state bounded и не меняет исходные байты',
    $oversizedHealthCode === 200 && $oversizedRepeatCode === 200
    && journalTextContains($oversizedProblemText, 'повреждено хранилище')
    && array_key_exists('последний_запуск', $oversizedHealth['cron'] ?? [])
    && $oversizedHealth['cron']['последний_запуск'] === null
    && array_key_exists('последний_запуск', $oversizedRepeatHealth['cron'] ?? [])
    && $oversizedRepeatHealth['cron']['последний_запуск'] === null
    && hash_file('sha256', STATE_FILE) === $oversizedStateHash
    && (int)((lstat(STATE_FILE))['size'] ?? -1) === (int)($oversizedStateStat['size'] ?? -2)
    && (int)((lstat(STATE_FILE))['mtime'] ?? -1) === (int)($oversizedStateStat['mtime'] ?? -2)
    && (glob($stateBadPattern) ?: []) === $stateBadBefore
    && (static function () use ($oversizedStateFiles): bool {
        $now = glob(dirname(STATE_FILE) . '/state*') ?: [];
        sort($now);
        return $now === $oversizedStateFiles;
    })(),
    'коды ' . $oversizedHealthCode . '/' . $oversizedRepeatCode
    . ($oversizedHealthCode === 0 ? ', transport: ' . json_encode($healthOversizedDiag) : '')
    . ($oversizedRepeatCode === 0 ? ', repeat: ' . json_encode($healthOversizedRepeatDiag) : ''));
@unlink(STATE_FILE);
if ($stateBefore !== null) file_put_contents(STATE_FILE, $stateBefore);
appBindLogGeneration(null);

$appJs = (string)file_get_contents($root . '/www/js/app.js');
// Экран состояния убран из шаблона 26.08.2026: он показывал домен, права и
// время последнего запуска фона — сведения для разработчика, а администратору
// портала они ничего не говорили, зато строка «фоновая обработка не
// запускалась» пугала приложения, которым фон не нужен вовсе. Пока экран ЕСТЬ,
// он обязан отличать «нет» от «не проверено»; когда экрана нет — в коде не
// должно остаться его половины: обработчик без разметки ломается молча.
$estEkranHealth = strpos($appJs, 'health-facts') !== false;
check('фронт health не выдаёт неизвестные факты за «нет»',
    $estEkranHealth
        ? (strpos($appJs, 'knownBool(d.portal.установлено') !== false
            && strpos($appJs, 'd.scopes.выданы === null') !== false
            && strpos($appJs, 'd.cron.последний_запуск === null') !== false
            && strpos($appJs, '!d.placements.проверено') !== false
            && strpos($appJs, 'не проверено') !== false)
        : (strpos($appJs, 'knownBool(') === false && strpos($appJs, 'loadHealth') === false),
    $estEkranHealth ? 'экран состояния на месте' : 'экран состояния убран целиком');
check('фронт журнала не выдаёт truncated-ответ за полный',
    substr_count($appJs, 'd.truncated') >= 2
    && strpos($appJs, 'Журнал прочитан не полностью') !== false
    && strpos($appJs, 'Показана только прочитанная часть') !== false);
if (is_callable($srv['close'] ?? null)) $srv['close']();

// ── Подписка на события: приговор выносит список подписок, а не код ошибки ──
// Официальная документация event.bind описывает только ERROR_EVENT_NOT_FOUND и
// ACCESS_DENIED — кода повторной привязки в ней нет. Пока решение принималось по
// угаданному коду, повторная установка на тот же портал писала в журнал ложную
// ошибку и показывала «подключено меньше, чем объявлено» у исправного приложения.
require_once dirname(__DIR__) . '/www/api/events-bind.php';

[$b1, $e1] = eventsReconcile(
    ['ONCRMDEALADD' => true], [], [],
    ['ok' => true, 'bound' => ['ONCRMDEALADD']]);
check('подтверждённая порталом подписка остаётся подтверждённой',
    $b1 === ['ONCRMDEALADD' => true] && $e1 === []);

[$b2, $e2] = eventsReconcile(
    ['ONCRMDEALADD' => true], [], [],
    ['ok' => true, 'bound' => []]);
check('принятая, но не вставшая подписка становится ошибкой',
    $b2 === [] && isset($e2['ONCRMDEALADD']));

[$b3, $e3] = eventsReconcile(
    [], ['ONCRMDEALADD' => 'портал отказал'], ['ONCRMDEALADD' => true],
    ['ok' => true, 'bound' => ['ONCRMDEALADD']]);
check('отказ на уже существующей подписке ошибкой не считается',
    $b3 === ['ONCRMDEALADD' => true] && $e3 === []);

[$b4, $e4] = eventsReconcile(
    [], ['ONCRMDEALADD' => 'портал отказал'], ['ONCRMDEALADD' => true],
    ['ok' => true, 'bound' => []]);
check('настоящий отказ остаётся ошибкой, а не прощается заодно',
    $b4 === [] && isset($e4['ONCRMDEALADD']));

[$b5, $e5] = eventsReconcile(
    ['ONCRMDEALADD' => true], ['ONCRMDEALUPDATE' => 'связь прервалась'],
    ['ONCRMDEALUPDATE' => true], ['ok' => false, 'bound' => []]);
check('портал не ответил на перечитывание — ничего не меняем, исход «неизвестно»',
    $b5 === ['ONCRMDEALADD' => true] && $e5 === ['ONCRMDEALUPDATE' => 'связь прервалась']);

// ── Уборка при удалении приложения подписывается всегда ────────────────────
// Шаблон обещает: «состояние стирается само при удалении приложения». Обещание
// держится на подписке ONAPPUNINSTALL, а её не было ни в одном списке: проект
// объявляет свои события, и если он не объявил ничего, приложение не узнавало о
// собственном удалении НИКОГДА — на диске оставались рабочие токены портала.
check('уборка при удалении подписывается даже без событий проекта',
    in_array('ONAPPUNINSTALL', eventsSpec(), true));
check('служебная подписка не считается объявленной проектом',
    !in_array('ONAPPUNINSTALL', eventsProjectSpec(), true)
    && count(eventsSpec()) === count(eventsProjectSpec()) + 1);

// ── Переполненный ящик не должен останавливать всю фоновую обработку ────────
// Событие, исчерпавшее попытки, переносится в ящик неразобранных. Перенос умеет
// отказывать (ящик не бесконечный), и раньше этот отказ вылетал ИЗ мутатора
// очереди наружу: падал не перенос одного протухшего события, а весь тик —
// вместе с разбором живой очереди и долгой задачей. Снаружи это выглядело как
// «крон перестал работать», причём после единственного ядовитого события.
require_once dirname(__DIR__) . '/www/api/queue.php';
storeWrite(INBOX_FILE, []);
storeWrite(DEADLETTER_FILE, []);
// Забиваем ящик до предела, чтобы перенос заведомо отказал.
$fullBox = [];
for ($i = 0; $i < DEADLETTER_MAX; $i++) {
    $fullBox[] = ['at' => date('c'), 'event' => 'OLD', 'data' => [], 'tries' => 3,
                  'error' => 'старое', 'eid' => 'old-' . $i, 'install_generation' => 'g'];
}
storeWrite(DEADLETTER_FILE, $fullBox);
// Одно исчерпавшее попытки событие и одно живое рядом.
storeWrite(INBOX_FILE, [
    ['eid' => 'poison', 'event' => 'ONCRMDEALADD', 'data' => [], 'tries' => QUEUE_MAX_TRIES,
     'taken' => 0, 'install_generation' => 'g'],
    ['eid' => 'fresh', 'event' => 'ONCRMDEALUPDATE', 'data' => [], 'tries' => 0,
     'taken' => 0, 'install_generation' => 'g'],
]);
$takeError = null;
$taken = [];
try { $taken = queueTake(50); } catch (Throwable $e) { $takeError = $e->getMessage(); }
check('переполненный ящик не роняет разбор очереди',
    $takeError === null, (string)$takeError);
check('живое событие всё равно берётся в работу',
    count(array_filter($taken, static fn($e) => ($e['eid'] ?? '') === 'fresh')) === 1);
check('протухшее событие не выдаётся в работу и не теряется',
    count(array_filter($taken, static fn($e) => ($e['eid'] ?? '') === 'poison')) === 0
    && count(array_filter(storeRead(INBOX_FILE) ?? [],
        static fn($e) => ($e['eid'] ?? '') === 'poison')) === 1);
storeWrite(INBOX_FILE, []);
storeWrite(DEADLETTER_FILE, []);
