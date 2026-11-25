<?php
declare(strict_types=1);
/**
 * СПРАВОЧНИК CRM НА ПОДМЕНЁННОМ ТРАНСПОРТЕ.
 *
 * Раньше справочник проверялся только решающими функциями (код стадии, срок
 * годности) — то есть без единого настоящего ответа портала. А ломается он
 * именно на ответах: отказ по одному типу объектов, пустой ответ вместо данных,
 * нехватка прав, вторая страница статусов. Каждый такой случай выглядит как
 * рабочий экран, где вместо названий стоят коды.
 *
 * Транспорт подменяем (см. B24::testTransport) — в сеть не ходим.
 */
require_once dirname(__DIR__) . '/www/api/b24.php';
require_once dirname(__DIR__) . '/www/api/crm-dict.php';

suite('Справочник CRM: ответы портала');

testWipe(DATA_ROOT);
$dictK = new B24('local.test.app', 'secret');
$dictK->saveTokensFromInstall([
    'AUTH_ID' => 'dict-access', 'REFRESH_ID' => 'dict-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-dict.invalid', 'member_id' => 'MEMBER-DICT',
    'APPLICATION_TOKEN' => 'key-dict', 'APPLICATION_SCOPE' => 'crm,user',
]);

/**
 * Обвес портала: отвечает по имени метода. Всё, что не описано, — законный
 * «воронок у этого типа нет».
 *
 * @param array $plan ['crm.category.list' => callable|array, ...]
 */
$portal = static function (array $plan, array &$zvonki) {
    $odin = static function (string $method, array $params) use ($plan) {
        $answer = $plan[$method] ?? null;
        if (is_callable($answer)) return $answer($params);
        if (is_array($answer)) return $answer;
        return ['error' => 'ERROR_NOT_FOUND', 'error_description' => 'справочника нет'];
    };
    return static function (string $method, array $params) use ($odin, &$zvonki) {
        $zvonki[] = $method;
        // Страницы списка клиент просит пачкой, поэтому заглушка обязана
        // разбирать batch: без этого «вторая страница не пришла» выглядит как
        // дефект кода, хотя портал её честно отдал бы.
        if ($method !== 'batch') return $odin($method, $params);
        $result = [];
        $errors = [];
        foreach ((array)($params['cmd'] ?? []) as $key => $command) {
            $chasti = explode('?', (string)$command, 2);
            $podParams = [];
            if (isset($chasti[1])) parse_str($chasti[1], $podParams);
            $zvonki[] = $chasti[0];
            $otvet = $odin((string)$chasti[0], $podParams);
            if (isset($otvet['error'])) { $errors[$key] = $otvet['error']; continue; }
            $result[$key] = $otvet['result'] ?? null;
        }
        return ['result' => ['result' => $result, 'result_error' => $errors,
                             'result_total' => [], 'result_next' => [], 'result_time' => []]];
    };
};

// ── 1. Полный ответ: названия воронок, стадий и полей доходят до справочника ──
$zvonki = [];
$GLOBALS['__b24_transport'] = $portal([
    'crm.category.list' => static fn(array $p): array => (int)($p['entityTypeId'] ?? 0) === 2
        ? ['result' => ['categories' => [['id' => 0, 'name' => 'Основная'], ['id' => 5, 'name' => 'Сервис']]]]
        : ['error' => 'ERROR_NOT_FOUND'],
    'crm.item.fields' => static fn(array $p): array =>
        ['result' => ['fields' => ['TITLE' => ['title' => 'Название'], 'UF_CRM_X' => ['title' => 'Свой признак']]]],
    // Строки идут с ID: готовый обход листает курсором по нему и без ID
    // отказывается доказывать полноту выборки.
    'crm.status.list' => static fn(array $p): array => [
        'result' => [
            ['ID' => 1, 'ENTITY_ID' => 'DEAL_STAGE', 'STATUS_ID' => 'NEW', 'NAME' => 'Новая'],
            ['ID' => 2, 'ENTITY_ID' => 'DEAL_STAGE_5', 'STATUS_ID' => 'C5:NEW', 'NAME' => 'Заявка сервиса'],
        ],
        'total' => 2,
    ],
], $zvonki);

$dict = crmDict($dictK, true);
check('воронки сделок попали в справочник с названиями',
    ($dict['categories'][2][5] ?? '') === 'Сервис', json_encode($dict['categories'] ?? [], JSON_UNESCAPED_UNICODE));
check('стадия первой воронки и стадия пятой различаются по коду',
    ($dict['stages'][2]['NEW'] ?? '') === 'Новая' && ($dict['stages'][2]['C5:NEW'] ?? '') === 'Заявка сервиса',
    json_encode($dict['stages'] ?? [], JSON_UNESCAPED_UNICODE));
check('названия полей, включая пользовательские, доходят до справочника',
    ($dict['fields'][2]['UF_CRM_X'] ?? '') === 'Свой признак',
    json_encode($dict['fields'][2] ?? [], JSON_UNESCAPED_UNICODE));
check('ОТСУТСТВИЕ ВОРОНОК У КОНТАКТОВ НЕ ДЕЛАЕТ СПРАВОЧНИК НЕПОЛНЫМ',
    !empty($dict['complete']), json_encode(['complete' => $dict['complete'] ?? null]));

// ── 2. Нехватка прав на один тип: справочник неполный, но не пустой ──────────
// Приложению, которое работает со сделками, отсутствие прав на лиды мешать не
// должно. А вот запоминать такой ответ на сутки нельзя — иначе разовая нехватка
// прав замораживает коды вместо названий до завтра.
testWipe(DATA_ROOT);
$dictK2 = new B24('local.test.app', 'secret');
$dictK2->saveTokensFromInstall([
    'AUTH_ID' => 'dict-access2', 'REFRESH_ID' => 'dict-refresh2', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-dict.invalid', 'member_id' => 'MEMBER-DICT',
    'APPLICATION_TOKEN' => 'key-dict', 'APPLICATION_SCOPE' => 'crm,user',
]);
$zvonki = [];
$GLOBALS['__b24_transport'] = $portal([
    'crm.category.list' => static fn(array $p): array => (int)($p['entityTypeId'] ?? 0) === 2
        ? ['result' => ['categories' => [['id' => 0, 'name' => 'Основная']]]]
        : ['error' => 'ERROR_NOT_FOUND'],
    'crm.item.fields' => static fn(array $p): array => (int)($p['entityTypeId'] ?? 0) === 1
        ? ['__code' => 403, 'error' => 'ACCESS_DENIED', 'error_description' => 'нет доступа к лидам']
        : ['result' => ['fields' => ['TITLE' => ['title' => 'Название']]]],
    'crm.status.list' => static fn(array $p): array => ['result' => [], 'total' => 0],
], $zvonki);

$dictPart = crmDict($dictK2, true);
check('НЕХВАТКА ПРАВ НА ОДИН ТИП НЕ ОБНУЛЯЕТ ОСТАЛЬНОЙ СПРАВОЧНИК',
    ($dictPart['fields'][2]['TITLE'] ?? '') === 'Название',
    json_encode($dictPart['fields'] ?? [], JSON_UNESCAPED_UNICODE));
check('справочник с отказом помечен НЕПОЛНЫМ',
    empty($dictPart['complete']), json_encode(['complete' => $dictPart['complete'] ?? null]));
check('неполный справочник живёт минуты, а не сутки',
    crmDictAgeLeft($dictPart) <= CRM_DICT_PARTIAL_SEC && CRM_DICT_PARTIAL_SEC < CRM_DICT_TTL_SEC,
    'осталось ' . crmDictAgeLeft($dictPart) . ' с, частичный порог ' . CRM_DICT_PARTIAL_SEC);

// ── 3. Портал перестал отвечать: показываем последнее хорошее ────────────────
// Вчерашние названия лучше, чем коды. Но подмена старого пустотой — это как раз
// то, что человек замечает последним: экран не падает, он просто врёт.
$zvonki = [];
$GLOBALS['__b24_transport'] = $portal([
    'crm.category.list' => ['__code' => 0, 'error' => 'http_error'],
    'crm.item.fields'   => ['__code' => 0, 'error' => 'http_error'],
    'crm.status.list'   => ['__code' => 0, 'error' => 'http_error'],
], $zvonki);
$dictAfterSilence = crmDict($dictK2, true);
check('ПОРТАЛ МОЛЧИТ — ОТДАЁМ СОХРАНЁННЫЙ СПРАВОЧНИК, А НЕ ПУСТОЙ',
    ($dictAfterSilence['fields'][2]['TITLE'] ?? '') === 'Название',
    json_encode($dictAfterSilence['fields'] ?? [], JSON_UNESCAPED_UNICODE));
check('подмена сохранённого пустотой в состояние не записана',
    (($stored = storeRead(CRM_DICT_FILE)) && ($stored['fields'][2]['TITLE'] ?? '') === 'Название'),
    json_encode(($stored['fields'] ?? []), JSON_UNESCAPED_UNICODE));

// ── 4. Свежий справочник из памяти портал не переспрашивает ──────────────────
$zvonki = [];
$dictCached = crmDict($dictK2);
check('свежий справочник берётся из памяти без запросов к порталу',
    $zvonki === [], 'запросов: ' . count($zvonki));

// ── 5. Стадий больше страницы: перелистывание обязано дойти до конца ─────────
// Метод crm.status.list отдаёт максимум 50 записей за раз, и в этот ответ
// попадают ВСЕ справочники портала. Без перелистывания часть стадий пропадает,
// и отчёт молча показывает коды у части сделок.
testWipe(DATA_ROOT);
$dictK3 = new B24('local.test.app', 'secret');
$dictK3->saveTokensFromInstall([
    'AUTH_ID' => 'dict-access3', 'REFRESH_ID' => 'dict-refresh3', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-dict.invalid', 'member_id' => 'MEMBER-DICT',
    'APPLICATION_TOKEN' => 'key-dict', 'APPLICATION_SCOPE' => 'crm,user',
]);
$zvonki = [];
$GLOBALS['__b24_transport'] = $portal([
    'crm.category.list' => static fn(array $p): array => (int)($p['entityTypeId'] ?? 0) === 2
        ? ['result' => ['categories' => [['id' => 0, 'name' => 'Основная']]]]
        : ['error' => 'ERROR_NOT_FOUND'],
    'crm.item.fields' => static fn(array $p): array => ['result' => ['fields' => []]],
    // Страничный обход: вторая страница просится смещением `start`. Курсор по
    // идентификатору здесь НЕ моделируем, и это принципиально — фильтр
    // crm.status.list документирован только по равенству, условие «>ID» портал
    // выбрасывает молча. Заглушка, исполнявшая «>ID» как курсор, была умнее
    // портала и прятала дефект, из-за которого приложение падало на боевом
    // портале (0921-101006-3f84). Полная страница — ровно 50 строк, поэтому
    // обход обязан пойти за добавкой.
    'crm.status.list' => static function (array $p): array {
        $vse = [];
        for ($id = 1; $id <= 51; $id++) {
            $vse[] = ['ID' => $id, 'ENTITY_ID' => 'DEAL_STAGE',
                      'STATUS_ID' => 'S' . $id,
                      'NAME' => $id === 51 ? 'Стадия с второй страницы' : 'Стадия ' . $id];
        }
        $start = (int)($p['start'] ?? 0);
        if ($start < 0) $start = 0;      // start = -1 у портала значит «первая страница без счёта»
        return ['result' => array_slice($vse, $start, 50), 'total' => 51];
    },
], $zvonki);
$dictPaged = crmDict($dictK3, true);
check('СТАДИЯ СО ВТОРОЙ СТРАНИЦЫ НЕ ПОТЕРЯЛАСЬ',
    ($dictPaged['stages'][2]['S51'] ?? '') === 'Стадия с второй страницы',
    'стадий собрано: ' . count($dictPaged['stages'][2] ?? []));
check('собраны все 51 стадия, а не первая страница',
    count($dictPaged['stages'][2] ?? []) === 51,
    'стадий: ' . count($dictPaged['stages'][2] ?? []));

// ── 6. ВОРОНОК больше страницы: тот же спрос, что и со стадий ────────────────
// «Полсотни воронок не бывает» — это про типичный портал, а не про контракт:
// crm.category.list тоже отдаёт максимум 50 записей за раз (документация
// crm/universal/category). У крупного клиента с историей воронок хвост исчезал
// МОЛЧА — справочник выглядел полным, а часть сделок показывала код вместо
// названия (находка ревью 09.08.2026). Портал в этом обвесе НЕ присылает `next`:
// именно так ведёт себя часть методов, и обход обязан досчитать сам.
testWipe(DATA_ROOT);
$dictK4 = new B24('local.test.app', 'secret');
$dictK4->saveTokensFromInstall([
    'AUTH_ID' => 'dict-access4', 'REFRESH_ID' => 'dict-refresh4', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-dict.invalid', 'member_id' => 'MEMBER-DICT',
    'APPLICATION_TOKEN' => 'key-dict', 'APPLICATION_SCOPE' => 'crm,user',
]);
$zvonki = [];
$stranicVoronok = 0;
$GLOBALS['__b24_transport'] = $portal([
    'crm.category.list' => static function (array $p) use (&$stranicVoronok): array {
        if ((int)($p['entityTypeId'] ?? 0) !== 2) return ['error' => 'ERROR_NOT_FOUND'];
        $stranicVoronok++;
        $start = (int)($p['start'] ?? 0);
        if ($start >= 100) return ['result' => ['categories' => [['id' => 101, 'name' => 'Воронка с третьей страницы']]]];
        $cat = [];
        for ($i = 1; $i <= 50; $i++) $cat[] = ['id' => $start + $i, 'name' => 'Воронка ' . ($start + $i)];
        return ['result' => ['categories' => $cat]];
    },
    'crm.item.fields' => static fn(array $p): array => ['result' => ['fields' => []]],
    'crm.status.list' => static fn(array $p): array => ['result' => [], 'total' => 0],
], $zvonki);
$dictCat = crmDict($dictK4, true);
check('ВОРОНКА С ТРЕТЬЕЙ СТРАНИЦЫ НЕ ПОТЕРЯЛАСЬ',
    ($dictCat['categories'][2][101] ?? '') === 'Воронка с третьей страницы',
    'воронок собрано: ' . count($dictCat['categories'][2] ?? []) . ', страниц запрошено: ' . $stranicVoronok);
check('за первой страницей воронок пошли следующие, а не остановились на 50',
    $stranicVoronok >= 3 && count($dictCat['categories'][2] ?? []) === 101,
    'страниц ' . $stranicVoronok . ', воронок ' . count($dictCat['categories'][2] ?? []));

// ── 7. Отказ по воронкам СДЕЛОК — это сбой, а не «воронок нет» ───────────────
// Раньше «воронок у этого типа не бывает» определялось по коду ошибки: любой
// ответ с непустым `error`, кроме сетевого, считался нормой. Из-за этого снятое
// право или упёртый лимит на воронках СДЕЛОК выглядели как «воронок нет»,
// справочник объявлялся полным и жил сутки: человек весь день видел коды вместо
// названий воронок и стадий. Воронок не бывает у ЛИДА, КОНТАКТА и КОМПАНИИ — это
// свойство типа, а не текста ошибки (документация crm/universal/category).
$otkazNaSdelkah = static function (array $oshibka) use ($portal, &$zvonki): array {
    testWipe(DATA_ROOT);
    $k = new B24('local.test.app', 'secret');
    $k->saveTokensFromInstall([
        'AUTH_ID' => 'dict-access7', 'REFRESH_ID' => 'dict-refresh7', 'AUTH_EXPIRES' => 3600,
        'DOMAIN' => 'portal-dict.invalid', 'member_id' => 'MEMBER-DICT',
        'APPLICATION_TOKEN' => 'key-dict', 'APPLICATION_SCOPE' => 'crm,user',
    ]);
    $zvonki = [];
    $GLOBALS['__b24_transport'] = $portal([
        'crm.category.list' => static fn(array $p): array => (int)($p['entityTypeId'] ?? 0) === 2
            ? $oshibka
            : ['error' => 'ENTITY_TYPE_NOT_SUPPORTED', 'error_description' => 'Entity type is not supported'],
        'crm.item.fields' => static fn(array $p): array =>
            ['result' => ['fields' => ['TITLE' => ['title' => 'Название']]]],
        'crm.status.list' => static fn(array $p): array => [
            'result' => [['ID' => 1, 'ENTITY_ID' => 'DEAL_STAGE', 'STATUS_ID' => 'NEW', 'NAME' => 'Новая']],
            'total' => 1,
        ],
    ], $zvonki);
    return crmDict($k, true);
};

$dictDenied = $otkazNaSdelkah(['__code' => 403, 'error' => 'ACCESS_DENIED', 'error_description' => 'Доступ запрещён']);
check('НЕТ ПРАВ НА ВОРОНКИ СДЕЛОК — СПРАВОЧНИК НЕПОЛНЫЙ, А НЕ «ВОРОНОК НЕТ»',
    empty($dictDenied['complete']),
    json_encode(['complete' => $dictDenied['complete'] ?? null, 'failed' => $dictDenied['failed'] ?? []], JSON_UNESCAPED_UNICODE));
check('непрочитанными помечены и воронки сделок, и их стадии',
    in_array('categories.2', $dictDenied['failed'] ?? [], true)
    && in_array('stages.2', $dictDenied['failed'] ?? [], true),
    json_encode($dictDenied['failed'] ?? [], JSON_UNESCAPED_UNICODE));
check('такой справочник переспросят через минуты, а не через сутки',
    crmDictAgeLeft($dictDenied) <= CRM_DICT_PARTIAL_SEC,
    'осталось ' . crmDictAgeLeft($dictDenied) . ' с');

$dictLimit = $otkazNaSdelkah(['__code' => 503, 'error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'Слишком много запросов']);
check('УПЁРТЫЙ ЛИМИТ ПОРТАЛА НА ВОРОНКАХ СДЕЛОК ТОЖЕ НЕ «ВОРОНОК НЕТ»',
    empty($dictLimit['complete']) && in_array('categories.2', $dictLimit['failed'] ?? [], true),
    json_encode(['complete' => $dictLimit['complete'] ?? null, 'failed' => $dictLimit['failed'] ?? []], JSON_UNESCAPED_UNICODE));

// Обратная сторона того же предохранителя: у контакта, компании и лида воронок
// не бывает НИКОГДА, и документированный отказ по ним обязан остаться нормой —
// иначе справочник у любого портала станет вечно неполным и начнёт дёргать
// портал каждые пять минут.
testWipe(DATA_ROOT);
$dictK8 = new B24('local.test.app', 'secret');
$dictK8->saveTokensFromInstall([
    'AUTH_ID' => 'dict-access8', 'REFRESH_ID' => 'dict-refresh8', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-dict.invalid', 'member_id' => 'MEMBER-DICT',
    'APPLICATION_TOKEN' => 'key-dict', 'APPLICATION_SCOPE' => 'crm,user',
]);
$zvonki = [];
$GLOBALS['__b24_transport'] = $portal([
    'crm.category.list' => static fn(array $p): array => (int)($p['entityTypeId'] ?? 0) === 2
        ? ['result' => ['categories' => [['id' => 0, 'name' => 'Основная']]]]
        : ['error' => 'ENTITY_TYPE_NOT_SUPPORTED', 'error_description' => 'Entity type is not supported'],
    'crm.item.fields' => static fn(array $p): array => ['result' => ['fields' => ['TITLE' => ['title' => 'Название']]]],
    'crm.status.list' => static fn(array $p): array => [
        'result' => [['ID' => 1, 'ENTITY_ID' => 'DEAL_STAGE', 'STATUS_ID' => 'NEW', 'NAME' => 'Новая']],
        'total' => 1,
    ],
], $zvonki);
$dictNoCat = crmDict($dictK8, true);
check('У КОНТАКТА, КОМПАНИИ И ЛИДА ОТСУТСТВИЕ ВОРОНОК ОСТАЛОСЬ НОРМОЙ',
    !empty($dictNoCat['complete']) && ($dictNoCat['failed'] ?? []) === [],
    json_encode(['complete' => $dictNoCat['complete'] ?? null, 'failed' => $dictNoCat['failed'] ?? []], JSON_UNESCAPED_UNICODE));
check('полный справочник по-прежнему живёт сутки',
    crmDictAgeLeft($dictNoCat) > CRM_DICT_PARTIAL_SEC,
    'осталось ' . crmDictAgeLeft($dictNoCat) . ' с');

unset($GLOBALS['__b24_transport']);
