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
 * СПРАВОЧНИКИ CRM — человеческие названия воронок, стадий и полей.
 *
 * ЗАЧЕМ. Портал возвращает коды: стадия `C5:NEW`, поле `UF_CRM_1690000000`,
 * воронка `7`. Клиент видит их в таблицах и выгрузках и не понимает ничего.
 * Замер 05.08.2026: справочники запрашивают 28 проектов, 26 сами собирают код
 * стадии — и каждый по-своему, потому что правило «стадия воронки N выглядит как
 * C<N>:CODE, а у первой воронки префикса нет» нигде не записано. Отсюда отчёты,
 * где часть сделок не попадает ни в одну стадию.
 *
 * ЧТО ДЕЛАЕТ. Один вызов `crmDict($b24)` отдаёт всё сразу: воронки, стадии по
 * воронкам, названия полей (включая пользовательские) — и помнит это сутки, чтобы
 * не спрашивать портал на каждое открытие экрана.
 *
 * ПОЧЕМУ ПАМЯТЬ РАЗНАЯ ПО СРОКУ. Полный ответ живёт сутки: справочники меняются
 * редко. Неполный (портал не отдал часть из-за прав или сбоя) — пять минут: иначе
 * получасовая неполадка портала застыла бы в приложении на сутки, и человек весь
 * день видел бы коды вместо названий, не понимая почему.
 *
 * ЗАПИСЬ В ПАМЯТЬ ИДЁТ ЧЕРЕЗ storeUpdate(): два одновременных открытия приложения
 * не затрут ответ друг друга.
 *
 * ЧАСТИЧНЫЙ ОТКАЗ НЕ ЗАТИРАЕТ ПАМЯТЬ. Раньше сохранённое отдавалось только когда
 * портал не ответил ВООБЩЕ ничего. Если же падал один вызов из девяти (тяжёлые
 * поля сделки не уложились в бюджет, разовый 5xx, снятое право), полный вчерашний
 * справочник переписывался урезанным — и человек видел коды вместо названий, пока
 * держалась неполадка. Теперь запись помнит, ЧТО именно не удалось прочитать
 * (`failed`), и доливает эти разделы из памяти; всё, что прочитано успешно,
 * заменяется целиком — иначе удалённые воронки и стадии не исчезали бы никогда
 * (находка ревью 09.08.2026, K1-05).
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/journal.php';

// CRM_DICT_FILE объявлен в env.php вместе с остальным состоянием — и это не
// вкусовщина: списки уборки при удалении и переносе приложения собираются
// по объявлениям ИМЕННО там. Пока файл объявлялся здесь, он не попадал ни в
// один из них, и названия воронок прежнего клиента доставались следующей
// установке — вместе с его структурой продаж.
if (!defined('CRM_DICT_TTL_SEC'))     define('CRM_DICT_TTL_SEC', 86400);   // полный ответ — сутки
if (!defined('CRM_DICT_PARTIAL_SEC')) define('CRM_DICT_PARTIAL_SEC', 300); // неполный — пять минут
// Страница списка воронок у портала — 50 записей (документация crm.category.list:
// «returns a single page (max 50 records)»), дальше через start.
if (!defined('CRM_DICT_PAGE_SIZE'))   define('CRM_DICT_PAGE_SIZE', 50);
// Потолок страниц: воронок у типа единицы-десятки, тысяча страниц означает, что
// портал отдаёт курсор, который не двигается. Упёрлись — справочник считаем
// неполным (запомним на пять минут, а не на сутки), но работу не останавливаем.
if (!defined('CRM_DICT_MAX_PAGES'))   define('CRM_DICT_MAX_PAGES', 40);

// Один REST-ответ ограничен transport-клиентом, но итоговый dictionary сливает
// много ответов и старые разделы. Поэтому отдельно ограничены code/label,
// число полей одного типа, число всех entries и полный private PHP snapshot.
if (!defined('CRM_DICT_LABEL_MAX_BYTES'))       define('CRM_DICT_LABEL_MAX_BYTES', 16 * 1024);
if (!defined('CRM_DICT_CODE_MAX_BYTES'))        define('CRM_DICT_CODE_MAX_BYTES', 1024);
if (!defined('CRM_DICT_MAX_FIELDS_PER_ENTITY')) define('CRM_DICT_MAX_FIELDS_PER_ENTITY', 5000);
if (!defined('CRM_DICT_MAX_ITEMS'))             define('CRM_DICT_MAX_ITEMS', 20000);
// CRM_DICT_MAX_BYTES объявлен общим store.php: тот же ceiling нужен lifecycle
// replacement ещё до загрузки этого owner-модуля.

/** Portal/legacy dictionary вышел за предметный resource contract. */
final class CrmDictStateLimitException extends RuntimeException {}

function crmDictAssertLabelWithinLimit(string $label): void {
    if (strlen($label) > CRM_DICT_LABEL_MAX_BYTES) {
        throw new CrmDictStateLimitException('crm-dict: название больше допустимого предела');
    }
}

function crmDictAssertCodeWithinLimit(string $code): void {
    if (strlen($code) > CRM_DICT_CODE_MAX_BYTES) {
        throw new CrmDictStateLimitException('crm-dict: код больше допустимого предела');
    }
}

/** Число category/stage/field entries во всём snapshot. */
function crmDictItemCount(array $dict): int {
    $total = 0;
    foreach (['categories', 'stages', 'fields'] as $section) {
        $groups = $dict[$section] ?? [];
        if (!is_array($groups)) continue;
        foreach ($groups as $rows) {
            if (is_array($rows)) $total += count($rows);
        }
    }
    return $total;
}

/** Точный размер dictionary вместе с исполняемым private-header. */
function crmDictStoredBytes(array $dict): int {
    try {
        return strlen(storeEncode($dict));
    } catch (Throwable $e) {
        throw new CrmDictStateLimitException('crm-dict: состояние не сериализуется', 0, $e);
    }
}

/** Проверить field/count/aggregate ceilings у legacy или write candidate. */
function crmDictAssertStateWithinLimits(array $dict): array {
    $items = 0;
    foreach (['categories', 'stages', 'fields'] as $section) {
        $groups = $dict[$section] ?? [];
        if (!is_array($groups)) {
            throw new CrmDictStateLimitException('crm-dict: раздел ' . $section . ' должен быть массивом');
        }
        foreach ($groups as $rows) {
            if (!is_array($rows)) {
                throw new CrmDictStateLimitException('crm-dict: группа ' . $section . ' должна быть массивом');
            }
            if ($section === 'fields' && count($rows) > CRM_DICT_MAX_FIELDS_PER_ENTITY) {
                throw new CrmDictStateLimitException('crm-dict: слишком много полей одного типа');
            }
            foreach ($rows as $code => $label) {
                if (!is_string($label)) {
                    throw new CrmDictStateLimitException('crm-dict: название должно быть строкой');
                }
                crmDictAssertCodeWithinLimit((string)$code);
                crmDictAssertLabelWithinLimit($label);
                $items++;
                if ($items > CRM_DICT_MAX_ITEMS) {
                    throw new CrmDictStateLimitException('crm-dict: слишком много записей');
                }
            }
        }
    }
    if (crmDictStoredBytes($dict) > CRM_DICT_MAX_BYTES) {
        throw new CrmDictStateLimitException('crm-dict: файл больше допустимого предела');
    }
    return $dict;
}

/**
 * Добавить label во время fetch, не дожидаясь сборки oversized snapshot.
 * $contentBytes — консервативная сумма code+label+JSON overhead.
 */
function crmDictPutLabel(
    array &$dict,
    string $section,
    int $entityTypeId,
    $code,
    string $label,
    int &$items,
    int &$contentBytes
): void {
    if (!in_array($section, ['categories', 'stages', 'fields'], true)) {
        throw new LogicException('crm-dict: неизвестный раздел');
    }
    $codeString = (string)$code;
    crmDictAssertCodeWithinLimit($codeString);
    crmDictAssertLabelWithinLimit($label);
    if (!isset($dict[$section][$entityTypeId]) || !is_array($dict[$section][$entityTypeId])) {
        $dict[$section][$entityTypeId] = [];
    }
    $bucket =& $dict[$section][$entityTypeId];
    $exists = array_key_exists($code, $bucket);
    if (!$exists) {
        if ($section === 'fields' && count($bucket) >= CRM_DICT_MAX_FIELDS_PER_ENTITY) {
            throw new CrmDictStateLimitException('crm-dict: слишком много полей одного типа');
        }
        if ($items >= CRM_DICT_MAX_ITEMS) {
            throw new CrmDictStateLimitException('crm-dict: слишком много записей');
        }
    }
    $oldCost = $exists ? strlen($codeString) + strlen((string)$bucket[$code]) + 64 : 0;
    $newCost = strlen($codeString) + strlen($label) + 64;
    if ($contentBytes - $oldCost + $newCost > CRM_DICT_MAX_BYTES) {
        throw new CrmDictStateLimitException('crm-dict: накопленный справочник больше допустимого предела');
    }
    $bucket[$code] = $label;
    if (!$exists) $items++;
    $contentBytes = $contentBytes - $oldCost + $newCost;
    unset($bucket);
}

/** Единственная bounded read-точка dictionary, включая legacy pre-decode. */
function crmDictStoreRead(): ?array {
    $dict = storeRead(CRM_DICT_FILE, CRM_DICT_MAX_BYTES);
    return is_array($dict) ? crmDictAssertStateWithinLimits($dict) : null;
}

/** Единственная bounded RMW-точка: merged candidate проверяется под EX-lock. */
function crmDictStoreUpdate(callable $mutator): array {
    return storeUpdate(CRM_DICT_FILE, $mutator, STORE_LOCK_TIMEOUT, CRM_DICT_MAX_BYTES,
        static fn(array $next): array => crmDictAssertStateWithinLimits($next));
}

/**
 * Отказал ли портал. Ответ клиента НЕ бросает исключение: он отдаёт то, что
 * пришло. Значит «нет ключа result» и «есть ключ error» — единственные признаки
 * неудачи, и проверять их надо явно, иначе `?? []` превращает сбой в пустоту.
 */
function crmDictCallFailed($response): bool {
    if (!is_array($response)) return true;
    if (!empty($response['error'])) return true;
    return !array_key_exists('result', $response);
}

/**
 * Типы объектов CRM, у которых воронок НЕ БЫВАЕТ вовсе.
 *
 * Список именно типов, а не кодов ошибок, — это и есть суть предохранителя.
 * Воронки есть у сделок и смарт-процессов; у лида, контакта и компании их нет
 * (документация crm/universal/category: «Чаще всего их настраивают для сделок и
 * смарт-процессов», а crm.category.list на неподдерживаемом типе отвечает
 * ENTITY_TYPE_NOT_SUPPORTED). Значит отказ по этим типам — норма, а по всем
 * остальным — сбой, каким бы кодом он ни назвался.
 */
if (!defined('CRM_DICT_TYPES_WITHOUT_CATEGORIES')) {
    define('CRM_DICT_TYPES_WITHOUT_CATEGORIES', [
        1 => 'лид',
        3 => 'контакт',
        4 => 'компания',
    ]);
}

/**
 * Отказ означает «у этого типа воронок не бывает», а не «портал недоступен»?
 *
 * ПОЧЕМУ РЕШАЕТ ТИП, А НЕ КОД ОШИБКИ. Раньше здесь стоял ровно обратный разбор:
 * нормой считался ЛЮБОЙ непустой код, кроме сетевого. Из-за этого снятое право
 * или упёртый лимит портала на воронках СДЕЛОК выглядели как «воронок нет» —
 * справочник объявлялся полным и запоминался на сутки, и человек весь день видел
 * коды `C5:NEW` и «воронка 7» вместо названий, причём без единой строки о сбое
 * (находка ревью 09.08.2026, R3-007). Список кодов «всё в порядке» составить
 * нельзя: их у портала больше, чем документировано, и любой новый код по старой
 * логике автоматически означал бы «воронок нет».
 *
 * Тип, которого нет в списке (в том числе смарт-процесс), считается имеющим
 * воронки: неизвестное — сбой, а не норма.
 *
 * @param mixed $response     Ответ портала как есть.
 * @param int   $entityTypeId Тип объекта CRM, у которого спрашивали воронки.
 */
function crmDictAbsentCategories($response, int $entityTypeId = 0): bool {
    if (!is_array($response)) return false;
    // Воронки у типа бывают — значит отказ по ним никогда не «норма».
    if (!array_key_exists($entityTypeId, CRM_DICT_TYPES_WITHOUT_CATEGORIES)) return false;
    $code = strtoupper(trim((string)($response['error'] ?? '')));
    // Сетевой сбой и неразобранный ответ к «воронок не бывает» отношения не
    // имеют даже у типа без воронок: портал в этот заход просто не ответил.
    if ($code === 'HTTP_ERROR' || $code === 'BAD_JSON' || $code === '') return false;
    return true;
}

/**
 * Какие справочники статусов спрашивать: стадии лида, стадии первой воронки
 * сделок и по одному справочнику на каждую остальную воронку.
 *
 * Спрашивать «все статусы разом» нельзя: метод отдаёт одну страницу в 50 записей
 * на ВСЕ справочники портала сразу — от источников до отраслей, — и стадии
 * вытесняются оттуда чужими записями.
 */
function crmDictStatusEntities(array $dict): array {
    $entities = ['STATUS', 'DEAL_STAGE'];
    foreach (array_keys($dict['categories'][2] ?? []) as $catId) {
        $catId = (int)$catId;
        if ($catId > 0) $entities[] = 'DEAL_STAGE_' . $catId;
    }
    return array_values(array_unique($entities));
}

/**
 * Один справочник статусов целиком.
 *
 * Спрашиваем ПО ОДНОМУ справочнику: метод отдаёт максимум 50 записей и валит в
 * один ответ все справочники портала разом — источники, типы контактов, отрасли
 * и стадии всех воронок. У клиента с тремя воронками стадии просто вытеснялись
 * чужими записями, и отчёт молча показывал коды вместо названий.
 *
 * @return array{ok:bool, rows:array} ok=false — портал не ответил; пустой rows при
 *         ok=true означает «такого справочника на портале нет», это не сбой.
 */
function crmDictStatusRows(B24 $b24, string $entityId): array {
    try {
        // Перелистывание — готовым fetchAllBatched(), а не своим циклом по
        // `start` и не курсором: фильтр crm.status.list документирован только
        // по равенству (api-reference/crm/status/crm-status-list.md), условие
        // «>ID» портал молча выбрасывает, и курсорный обход встаёт на месте —
        // тот же дефект, что ломал чтение полей компании (0921-101006-3f84).
        // Готовый страничный обход сверяет число записей с `total`, поэтому
        // неполноту он не прячет, а справочник за время чтения не «живёт» так,
        // как список сделок.
        return ['ok' => true, 'rows' => $b24->fetchAllBatched('crm.status.list', [
            'filter' => ['ENTITY_ID' => $entityId],
        ])];
    } catch (Throwable $e) {
        // Портал не ответил или отдал не список: справочник считаем неполным,
        // а не пустым — иначе разовый сбой замораживает коды вместо названий.
        return ['ok' => false, 'rows' => []];
    }
}

/** Типы объектов CRM, чьи воронки и стадии нужны почти всегда. */
if (!defined('CRM_DICT_ENTITY_TYPES')) {
    define('CRM_DICT_ENTITY_TYPES', [
        2 => 'сделка',
        1 => 'лид',
        3 => 'контакт',
        4 => 'компания',
    ]);
}

/**
 * Код стадии так, как его хранит портал.
 *
 * ПРАВИЛО, НА КОТОРОМ ОШИБАЮТСЯ ЧАЩЕ ВСЕГО: у первой воронки стадии идут без
 * префикса (`NEW`, `PREPARATION`), у остальных — с префиксом воронки (`C5:NEW`).
 * Двадцать шесть проектов собирали это самостоятельно, и расхождения дают отчёты,
 * где часть сделок не попадает ни в одну стадию.
 */
function crmDictStageCode(int $categoryId, string $status): string {
    $status = trim($status);
    if ($status === '') return '';
    if (strpos($status, ':') !== false) return $status;   // уже с префиксом
    return $categoryId > 0 ? 'C' . $categoryId . ':' . $status : $status;
}

/** Название стадии по коду; не нашли — возвращаем сам код, а не пустоту. */
function crmDictStageName(array $dict, int $entityTypeId, string $stage): string {
    $stages = $dict['stages'][$entityTypeId] ?? [];
    return (string)($stages[$stage] ?? $stage);
}

/** Название воронки по идентификатору; не нашли — «воронка N». */
function crmDictCategoryName(array $dict, int $entityTypeId, int $categoryId): string {
    $cats = $dict['categories'][$entityTypeId] ?? [];
    return (string)($cats[$categoryId] ?? ('воронка ' . $categoryId));
}

/** Человеческое название поля по его коду; не нашли — сам код. */
function crmDictFieldName(array $dict, int $entityTypeId, string $field): string {
    $fields = $dict['fields'][$entityTypeId] ?? [];
    return (string)($fields[$field] ?? $field);
}

/**
 * Первые страницы нескольких команд ОДНИМ пакетом, в форме ответа одиночного вызова.
 *
 * Ревью 23.09.2026 (skorost-3): справочник собирался двумя десятками запросов
 * ПОДРЯД, каждый с выдержкой темпа, — первый открывший экран ждал 6–10 секунд, а
 * неполный справочник повторял это каждые пять минут. Здесь первые страницы
 * приходят за одно обращение; добирать отдельными вызовами приходится только
 * хвост длиннее страницы и то, что пакет не донёс.
 *
 * Ответ по ключу — как у call(): `result` (+ `next`/`total`) либо `error` с КОДОМ
 * отказа. Код важен: по нему crmDictAbsentCategories() отличает «воронок у типа не
 * бывает» от сбоя, а готовый batchAll() оставляет только текст описания.
 *
 * @return array|null null — пакет не прошёл целиком (сеть, токен): вызывающий
 *         идёт прежним путём по одной команде. Ключа нет в ответе — тоже по одной.
 */
function crmDictBatchFirst(B24 $b24, array $commands): ?array {
    $out = [];
    foreach (array_chunk($commands, 50, true) as $chunk) {
        try { $raw = $b24->batch($chunk); } catch (Throwable $e) { return null; }
        if (!is_array($raw) || !empty($raw['error']) || !is_array($raw['result'] ?? null)) return null;
        $body = $raw['result'];
        $ok = is_array($body['result'] ?? null) ? $body['result'] : [];
        $bad = is_array($body['result_error'] ?? null) ? $body['result_error'] : [];
        $next = is_array($body['result_next'] ?? null) ? $body['result_next'] : [];
        $total = is_array($body['result_total'] ?? null) ? $body['result_total'] : [];
        foreach ($chunk as $key => $_) {
            if (array_key_exists($key, $bad)) {
                $e = $bad[$key];
                $code = is_array($e) ? (string)($e['error'] ?? '') : (string)$e;
                $out[$key] = ['error' => $code !== '' ? $code : 'ERROR',
                    'error_description' => is_array($e) ? (string)($e['error_description'] ?? '') : ''];
            } elseif (array_key_exists($key, $ok)) {
                $one = ['result' => $ok[$key]];
                if (isset($next[$key])) $one['next'] = $next[$key];
                if (isset($total[$key])) $one['total'] = $total[$key];
                $out[$key] = $one;
            }
        }
    }
    return $out;
}

/**
 * Спросить портал. Возвращает справочник и признак полноты.
 *
 * Отказ ОДНОГО типа объектов не отменяет остальные: приложению, которое работает
 * со сделками, не должно мешать отсутствие прав на лиды. Но полным такой ответ
 * не считается — и запомнится он на пять минут, а не на сутки.
 */
function crmDictFetch(B24 $b24): array {
    $dict = ['categories' => [], 'stages' => [], 'fields' => [], 'at' => time()];
    $items = 0;
    $contentBytes = 0;
    $complete = true;
    // Разделы, которые в ЭТОТ заход прочитать не удалось: `categories.2`,
    // `fields.1`, `stages.2`. Одного флага полноты для записи мало — по нему
    // видно только «что-то не так», а доливать из памяти нужно ровно те разделы,
    // которые не прочитаны, и ни одного лишнего. Ключи массива, а не строки в
    // список, чтобы один и тот же раздел не попал дважды.
    $sboi = [];

    // Первые страницы воронок и поля всех типов — одним пакетом (skorost-3).
    $pervye = [];
    foreach (CRM_DICT_ENTITY_TYPES as $t => $_) {
        $pervye['cat_' . (int)$t] = ['crm.category.list', ['entityTypeId' => (int)$t]];
        $pervye['fld_' . (int)$t] = ['crm.item.fields', ['entityTypeId' => (int)$t]];
    }
    $paket = crmDictBatchFirst($b24, $pervye) ?? [];

    foreach (CRM_DICT_ENTITY_TYPES as $entityTypeId => $human) {
        $entityTypeId = (int)$entityTypeId;
        // Воронки есть не у всех типов: у контактов и компаний их нет вовсе,
        // и отказ портала здесь — норма, а не сбой.
        //
        // Собранные страницы объявлены ДО try: обрыв на второй странице не должен
        // выбрасывать то, что уже прочитано на первой. Раньше разбор строк стоял
        // внутри try, и исключение посреди перелистывания оставляло раздел пустым,
        // причём с признаком «полный» — то есть на сутки.
        $rows = [];
        try {
            // Воронки читаем СТРАНИЦАМИ, а не одним вызовом. «Полсотни воронок не
            // бывает» — это про типичный портал, а не про контракт метода: сам
            // crm.category.list отдаёт максимум 50 записей за раз и дальше требует
            // start (документация crm/universal/category). У крупного клиента с
            // историей воронок хвост просто исчезал, причём МОЛЧА: справочник
            // выглядел полным, а часть сделок показывала код вместо названия
            // (находка ревью 09.08.2026).
            //
            // ПОЧЕМУ НЕ ГОТОВЫЙ fetchAll(). Он ходит КУРСОРОМ: добавляет к запросу
            // `filter[>ID]` и `order`, а crm.category.list ни того, ни другого не
            // принимает — в его описании единственный параметр `entityTypeId`, и
            // там же прямо сказано, что список не принимает `order`. Курсор такому
            // методу передать нечем, поэтому здесь обычная постраничная навигация
            // по `start` — тот же приём, что у соседнего справочника статусов.
            $start = 0;
            $stranic = 0;
            do {
                $res = ($start === 0 && isset($paket['cat_' . $entityTypeId]))
                    ? $paket['cat_' . $entityTypeId]
                    : $b24->call('crm.category.list',
                        ['entityTypeId' => $entityTypeId] + ($start > 0 ? ['start' => $start] : []));
                // У контактов и компаний воронок нет вовсе, и отказ здесь — норма.
                // Но отличать «воронок нет» от «портал не ответил» всё равно надо:
                // во втором случае справочник неполон, и запоминать его на сутки нельзя.
                if (crmDictCallFailed($res)) {
                    if (!crmDictAbsentCategories($res, $entityTypeId)) {
                        $complete = false;
                        $sboi['categories.' . $entityTypeId] = true;
                        // Без этой строки неполнота видна только по экрану с
                        // кодами: справочник тихо помечается неполным и через
                        // пять минут спрашивается снова, а причина — нет прав,
                        // лимит, отключённый CRM — не выясняется никогда.
                        // Пишем ТОЛЬКО код отказа: описание приходит от портала
                        // и может нести данные клиента. Журнал маскирует сам.
                        appLog('warn', 'crm.dict.categories.fail', [
                            'тип' => $entityTypeId,
                            'код' => substr(strtoupper(trim((string)($res['error'] ?? ''))), 0, 64) ?: 'нет ответа',
                        ], ['msg' => 'воронки типа ' . $entityTypeId . ' прочитать не удалось — справочник неполный']);
                    }
                    // ВАЖНО: здесь стоял `continue`, и он пропускал ВСЮ остальную
                    // работу по типу — то есть названия полей. У контактов и компаний
                    // воронок не бывает никогда, поэтому их поля не собирались вообще
                    // ни разу: экран показывал коды вместо названий, и выглядело это
                    // как «у контактов полей нет». Отказ по воронкам к полям
                    // отношения не имеет — идём дальше по тому же типу.
                    break;
                }
                $kusok = $res['result']['categories'] ?? $res['result'] ?? [];
                if (!is_array($kusok) || $kusok === []) break;
                foreach ($kusok as $row) $rows[] = $row;
                // `next` портал присылает, пока есть следующая страница. Если его
                // нет, а страница пришла ПОЛНАЯ — считаем сами: молча оборвать
                // обход на полной странице значит потерять хвост ровно там, где
                // он есть. Счётчик страниц не даёт зациклиться, если портал
                // вернёт `next`, который не двигается.
                $next = $res['next'] ?? null;
                $preds = $start;
                if (is_numeric($next))                       $start = (int)$next;
                elseif (count($kusok) >= CRM_DICT_PAGE_SIZE) $start += CRM_DICT_PAGE_SIZE;
                else                                         $start = 0;
                if ($start > 0 && $start <= $preds) {   // курсор не двигается
                    $complete = false;
                    $sboi['categories.' . $entityTypeId] = true;
                    break;
                }
            } while ($start > 0 && ++$stranic < CRM_DICT_MAX_PAGES);
            if ($stranic >= CRM_DICT_MAX_PAGES) {
                $complete = false;
                $sboi['categories.' . $entityTypeId] = true;
            }
        } catch (Throwable $e) {
            // Отказ портала приходит ОТВЕТОМ, а не исключением: сюда попадает
            // обрыв уровнем ниже — не удалось обновить токен, кончилась память.
            // «Воронок у этого типа не бывает» так не выглядит, поэтому раздел
            // считаем непрочитанным: иначе усечённый список запомнится на сутки.
            $complete = false;
            $sboi['categories.' . $entityTypeId] = true;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = (int)($row['id'] ?? $row['ID'] ?? 0);
            $name = trim((string)($row['name'] ?? $row['NAME'] ?? ''));
            if ($name !== '') {
                crmDictPutLabel($dict, 'categories', $entityTypeId, $id, $name, $items, $contentBytes);
            }
        }

        // Названия полей, включая пользовательские.
        try {
            $res = $paket['fld_' . $entityTypeId]
                ?? $b24->call('crm.item.fields', ['entityTypeId' => $entityTypeId]);
            // Отказ портала НЕ бросает исключение: call() отдаёт ответ как есть,
            // и `$res['result']['fields'] ?? []` превращал любую ошибку в пустой
            // список — то есть в «полей нет», а не в «спросить не удалось».
            // Справочник при этом считался полным и запоминался на сутки: разовый
            // сбой связи замораживал экран с кодами вместо названий до завтра.
            if (crmDictCallFailed($res)) {
                $complete = false;
                $sboi['fields.' . $entityTypeId] = true;
                continue;
            }
            $fields = $res['result']['fields'] ?? [];
            if (is_array($fields)) {
                foreach ($fields as $code => $meta) {
                    $title = is_array($meta) ? trim((string)($meta['title'] ?? $meta['formLabel'] ?? '')) : '';
                    if ($title !== '') {
                        crmDictPutLabel($dict, 'fields', $entityTypeId, (string)$code, $title,
                            $items, $contentBytes);
                    }
                }
            } else {
                $complete = false;
                $sboi['fields.' . $entityTypeId] = true;
            }
        } catch (CrmDictStateLimitException $e) {
            throw $e;
        } catch (Throwable $e) {
            $complete = false;
            $sboi['fields.' . $entityTypeId] = true;
        }
    }

    // Список справочников стадий строится ПО ВОРОНКАМ сделок. Не дочитали
    // воронки — не спросили и стадии тех, которых не увидели: раздел стадий
    // сделок в этом заходе тоже неполный, даже если каждый заданный вопрос
    // получил ответ.
    if (!empty($sboi['categories.2'])) $sboi['stages.2'] = true;

    // Стадии лежат отдельным справочником статусов и раскладываются по воронкам
    // по тому самому правилу префикса.
    //
    // Спрашиваем КАЖДЫЙ справочник отдельно и с перелистыванием. Раньше здесь
    // стоял один вызов без фильтра, и документация метода прямо предупреждает:
    // он отдаёт ОДНУ страницу максимум в 50 записей, а в этот ответ попадают все
    // справочники портала разом — источники, типы контактов, отрасли, стадии
    // всех воронок. У клиента с тремя воронками стадии просто не помещались, и
    // отчёт молча показывал коды вместо названий у части сделок.
    // Первые страницы всех справочников стадий — тоже пакетом. Полная страница
    // или total больше пришедшего — хвост добирает готовый страничный обход.
    $statusy = [];
    foreach (crmDictStatusEntities($dict) as $entityId) {
        $statusy['st_' . $entityId] = ['crm.status.list', ['filter' => ['ENTITY_ID' => $entityId]]];
    }
    $paketStatusov = crmDictBatchFirst($b24, $statusy) ?? [];
    foreach (crmDictStatusEntities($dict) as $entityId) {
        $gotovo = $paketStatusov['st_' . $entityId] ?? null;
        $stroki = is_array($gotovo) && !crmDictCallFailed($gotovo) ? B24::unwrapList($gotovo['result']) : null;
        $page = ($stroki !== null && count($stroki) < CRM_DICT_PAGE_SIZE
                && (int)($gotovo['total'] ?? count($stroki)) <= count($stroki))
            ? ['ok' => true, 'rows' => $stroki]
            : crmDictStatusRows($b24, $entityId);
        if (!$page['ok']) {
            $complete = false;
            // Стадии лида и стадии сделок лежат в разных разделах справочника:
            // отказ по стадиям одной воронки не должен объявлять непрочитанными
            // стадии лида, и наоборот.
            $sboi[strpos($entityId, 'DEAL_STAGE') === 0 ? 'stages.2' : 'stages.1'] = true;
            continue;
        }
        foreach ($page['rows'] as $row) {
            if (!is_array($row)) continue;
            $entity = strtoupper((string)($row['ENTITY_ID'] ?? $entityId));
            $status = (string)($row['STATUS_ID'] ?? '');
            $name   = trim((string)($row['NAME'] ?? ''));
            if ($status === '' || $name === '') continue;
            // DEAL_STAGE — первая воронка, DEAL_STAGE_5 — воронка 5.
            if (strpos($entity, 'DEAL_STAGE') === 0) {
                $catId = (int)substr($entity, strlen('DEAL_STAGE_'));
                crmDictPutLabel($dict, 'stages', 2, crmDictStageCode($catId, $status), $name,
                    $items, $contentBytes);
            } elseif ($entity === 'STATUS') {
                crmDictPutLabel($dict, 'stages', 1, $status, $name, $items, $contentBytes);
            }
        }
    }

    $dict['complete'] = $complete;
    // Список непрочитанного едет вместе со справочником: по нему запись решает,
    // какие разделы долить из памяти, а какие заменить целиком.
    $dict['failed'] = array_keys($sboi);
    return crmDictAssertStateWithinLimits($dict);
}

/**
 * Слить свежий ответ с сохранённым: из памяти берутся ТОЛЬКО те разделы, которые
 * в этот заход прочитать не удалось (`failed`).
 *
 * ПОЧЕМУ НЕ СЛИВАТЬ ВСЁ ПОДРЯД. Тогда удалённая на портале воронка, стадия или
 * поле не исчезали бы из приложения никогда: справочник рос бы, накапливая
 * то, чего в CRM давно нет. Прочитанный раздел заменяется целиком — это и есть
 * способ удалить лишнее.
 *
 * ПОЧЕМУ НЕ ОТКАЗАТЬСЯ ОТ ЗАПИСИ ЦЕЛИКОМ. Отказ обычно частичный: одно поле не
 * уложилось в бюджет вызова, остальные восемь ответов свежие. Выбросить их
 * значит на каждой неполадке возвращать человека ко вчерашним данным.
 *
 * @param array $prev  Что лежит в памяти (читается под тем же замком, что и запись).
 * @param array $fresh Ответ портала этого захода вместе со списком `failed`.
 * @return array Справочник для записи; в `restored` — какие разделы долиты из памяти.
 */
function crmDictMergePartial(array $prev, array $fresh): array {
    $fresh['restored'] = [];
    $failed = $fresh['failed'] ?? [];
    if (!is_array($failed) || $failed === [] || empty($prev['at'])) return $fresh;

    foreach ($failed as $put) {
        $chasti = explode('.', (string)$put, 2);
        if (count($chasti) !== 2) continue;
        [$razdel, $tip] = $chasti;
        if (!in_array($razdel, ['categories', 'stages', 'fields'], true)) continue;
        // Ключ типа объекта в состоянии числовой: json_decode превращает "2" в 2,
        // и по строке '2' раздел не найдётся.
        $klyuch = ctype_digit($tip) ? (int)$tip : $tip;
        $staroe = $prev[$razdel][$klyuch] ?? null;
        if (!is_array($staroe) || $staroe === []) continue;
        $novoe = $fresh[$razdel][$klyuch] ?? [];
        if (!is_array($novoe)) $novoe = [];
        // Свежий ответ сильнее памяти: `+` оставляет левую часть и добавляет из
        // правой только недостающие ключи. Переименованная стадия останется
        // новой, пропавшая из-за отказа — вернётся вчерашней.
        $slito = $novoe + $staroe;
        if (count($slito) > count($novoe)) $fresh['restored'][] = (string)$put;
        $fresh[$razdel][$klyuch] = $slito;
    }
    return $fresh;
}

/**
 * Справочник CRM: из памяти, а при устаревании — с обновлением из портала.
 *
 * @param bool $force Человек нажал «Обновить» — спрашиваем портал, не глядя на срок.
 */
function crmDict(B24 $b24, bool $force = false): array {
    $cached = crmDictStoreRead();
    if (!$force && is_array($cached) && !empty($cached['at'])) {
        $ttl = empty($cached['complete']) ? CRM_DICT_PARTIAL_SEC : CRM_DICT_TTL_SEC;
        if (time() - (int)$cached['at'] < $ttl) return $cached;
    }

    $fresh = crmDictFetch($b24);

    // Портал не отдал НИЧЕГО, а в памяти что-то есть — отдаём старое, даже
    // просроченное: показать вчерашние названия лучше, чем показать коды.
    $empty = $fresh['categories'] === [] && $fresh['stages'] === [] && $fresh['fields'] === [];
    if ($empty && is_array($cached) && !empty($cached['at'])) {
        logAppError('справочник CRM: портал не ответил, показываем сохранённый от '
            . date('c', (int)$cached['at']));
        return $cached;
    }

    // Запись под общим замком: два одновременных открытия приложения не затрут
    // ответ друг друга и не оставят половину справочника. Прежнее состояние
    // берётся ИЗ МУТАТОРА, а не из прочитанного выше `$cached`: между чтением и
    // записью соседний запрос мог обновить справочник, и доливать надо из того,
    // что лежит в файле сейчас.
    $saved = crmDictStoreUpdate(static function (array $prev) use ($fresh): array {
        return crmDictMergePartial($prev, $fresh);
    });
    $restored = $saved['restored'] ?? [];
    if ($restored !== []) {
        // Человеку это видно как «часть названий вчерашние»: без строки в журнале
        // разбираться пришлось бы по одним экранным кодам.
        logAppError('справочник CRM: портал ответил не полностью, разделы взяты из памяти — '
            . implode(', ', $restored));
    }
    appLog('info', 'crm.dict.refresh', [
        'воронок'      => array_sum(array_map('count', $saved['categories'])),
        'стадий'       => array_sum(array_map('count', $saved['stages'])),
        'полей'        => array_sum(array_map('count', $saved['fields'])),
        'полный'       => $saved['complete'],
        'непрочитано'  => $saved['failed'] ?? [],
        'из памяти'    => $restored,
    ], ['msg' => 'справочник CRM обновлён из портала']);
    // Возвращаем ЗАПИСАННОЕ, а не сырой ответ портала: иначе экран, из которого
    // пришёл этот запрос, показал бы коды вместо названий, хотя в памяти рядом
    // лежат вчерашние названия — ровно то, ради чего долив и сделан.
    return $saved;
}

/** Сколько справочник ещё считается свежим; 0 — пора обновлять. */
function crmDictAgeLeft(array $dict): int {
    $ttl = empty($dict['complete']) ? CRM_DICT_PARTIAL_SEC : CRM_DICT_TTL_SEC;
    return max(0, $ttl - (time() - (int)($dict['at'] ?? 0)));
}
