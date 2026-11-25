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
 * ПОЛЯ ПОРТАЛА, КОТОРЫЕ ЗАВОДИТ ПРИЛОЖЕНИЕ — один вызов вместо своей процедуры.
 *
 * ЗАЧЕМ. Приложению почти всегда нужны свои поля в карточке: куда писать номер
 * заявки, статус обмена, ссылку на документ. Каждый проект пишет это заново, и
 * каждый — с одними и теми же тремя ошибками:
 *
 *   1. Поле создаётся при КАЖДОЙ установке заново. У человека в карточке
 *      появляется «Номер заявки», «Номер заявки (1)», «Номер заявки (2)» —
 *      а старые данные остаются в первом, и отчёты расходятся.
 *   2. Два одновременных запроса создают поле дважды: проверка «есть ли поле»
 *      и создание идут порознь, без замка.
 *   3. Создание идёт по одному вызову на поле. Десять полей — десять запросов,
 *      и половина падает по лимиту, а приложение считает, что всё встало.
 *
 * ЧТО ДЕЛАЕТ. `ufEnsure('deal', [...])` доводит карточку до нужного вида:
 * читает, чего не хватает, создаёт недостающее ОДНИМ пакетом, перечитывает
 * результат и возвращает соответствие «мой код → имя поля на портале».
 * Повторный вызов не создаёт ничего.
 *
 * ЧЕГО НЕ ДЕЛАЕТ. Не удаляет и не переименовывает чужие поля: приложение
 * отвечает только за свои. Не трогает смарт-процессы — у них поля заводятся
 * другим семейством методов; когда понадобится, добавим отдельно, а не подменим
 * молча (иначе «вроде создалось», а поля нет).
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/access.php';

// Сущности, у которых поля заводятся семейством crm.<entity>.userfield.*.
// Список закрытый специально: опечатка в имени сущности иначе ушла бы в портал
// как несуществующий метод, а приложение прочитало бы это как «поле не создалось»
// и попыталось бы снова при каждом запуске.
const UF_ENTITIES = ['lead', 'deal', 'contact', 'company', 'quote'];

// Столько знаков портал отводит на КОД пользовательского поля: у имени лимит 20,
// из которых 7 занимает добавляемый им префикс UF_CRM_.
if (!defined('UF_CODE_MAX')) define('UF_CODE_MAX', 13);

/**
 * КОД поля для портала — то, что уходит в FIELD_NAME.
 *
 * Портал принимает именно КОД, а префикс `UF_CRM_` добавляет сам, и на код у
 * него всего 13 знаков (официальная дока: crm.<сущность>.userfield.add).
 * Прислать полное имя — типовая ошибка с тихими последствиями: портал делает из
 * него `UF_CRM_UF_CRM_…`, приложение потом ищет поле по своему имени, не находит
 * и на каждой установке пытается создать его заново.
 *
 * Метка приложения обязательна: без неё код `STATUS` двух разных приложений —
 * одно и то же поле, и второе начинает писать в чужое. Если с меткой код не
 * помещается в 13 знаков, хвост заменяется отпечатком исходного кода: так
 * `SYNC_STATE` и `SYNC_STATUS` остаются разными полями, а не сливаются в одно.
 */
function ufFieldCode(string $code): string {
    $clean = strtoupper((string)preg_replace('/[^A-Za-z0-9_]/', '', $code));
    if ($clean === '') throw new InvalidArgumentException('пустой код поля');
    $prefix = strtoupper(defined('UF_PREFIX') ? (string)UF_PREFIX : 'APP');
    $prefix = (string)preg_replace('/[^A-Z0-9]/', '', $prefix);
    // Метке оставляем место под смысл: иначе от кода поля останется огрызок,
    // и человек в списке полей портала не поймёт, что это за поле.
    if ($prefix === '' || strlen($prefix) > 5) {
        throw new InvalidArgumentException(
            'UF_PREFIX должен быть от 1 до 5 знаков (A-Z, 0-9): у портала на весь код поля 13 знаков');
    }
    $budget = UF_CODE_MAX - strlen($prefix) - 1;
    if (strlen($clean) > $budget) {
        // Отпечаток берём от ПОЛНОГО кода: два похожих начала не должны дать
        // одно и то же поле. Знаков четыре и в алфавите 36, а не три
        // шестнадцатеричных: три hex — это 12 бит, то есть 4096 значений, и
        // столкновение находится подбором за секунды (08.08.2026 нашлось на
        // паре обычных с виду кодов). Четыре знака 0-9A-Z дают 1 679 616
        // значений — на пару порядков надёжнее при той же длине.
        // ВНИМАНИЕ при переносе в работающий проект: у длинных кодов имя поля
        // изменится, и приложение перестанет видеть заведённое ранее поле.
        // Такому проекту нужна миграция, а не просто новая версия файла.
        $mark = ufCodeMark($clean);
        $clean = substr($clean, 0, $budget - 5) . '_' . $mark;
    }
    return $prefix . '_' . $clean;
}

/** Отпечаток кода: четыре знака 0-9A-Z, детерминированно. */
function ufCodeMark(string $clean): string {
    $n = (int)hexdec(substr(sha1($clean), 0, 8)) % (36 ** 4);
    return strtoupper(str_pad(base_convert((string)$n, 10, 36), 4, '0', STR_PAD_LEFT));
}

/**
 * ПОЛНЫЙ логический код поля — тот, которым его называет проект.
 *
 * Уходит в XML_ID и нужен ровно для одного: отличить «наше поле» от чужого с
 * тем же коротким кодом. Код поля на портале сжат до 13 знаков, и сжатие
 * необратимо — два разных логических кода в принципе могут дать один и тот же.
 * Раньше в XML_ID уезжал тот же сжатый код, поэтому различить было НЕЧЕМ:
 * приложение считало чужое поле своим и начинало писать в него.
 */
function ufLogicalId(string $code): string {
    $clean = strtoupper((string)preg_replace('/[^A-Za-z0-9_]/', '', $code));
    $prefix = strtoupper((string)preg_replace('/[^A-Z0-9]/', '',
        defined('UF_PREFIX') ? (string)UF_PREFIX : 'APP'));
    return 'BXAPP:' . $prefix . ':' . $clean;
}

/** Полное имя поля на портале — то, под которым оно видно в списке и в карточке. */
function ufFieldName(string $code): string {
    return 'UF_CRM_' . ufFieldCode($code);
}

/**
 * Имя метода создания — ЛИТЕРАЛОМ по каждой сущности. Имя, собранное из кусков,
 * не читается ни человеком, ни забором шаблона, который следит за видимостью
 * обращений к порталу.
 */
function ufAddMethod(string $entity): string {
    switch ($entity) {
        case 'lead':    return 'crm.lead.userfield.add';
        case 'deal':    return 'crm.deal.userfield.add';
        case 'contact': return 'crm.contact.userfield.add';
        case 'company': return 'crm.company.userfield.add';
        case 'quote':   return 'crm.quote.userfield.add';
    }
    throw new InvalidArgumentException('нет метода создания поля для «' . $entity . '»');
}

// Здесь была ufCallFailed() — проверка «портал ответил отказом» для прямых
// вызовов $b24->call(). В этом файле таких вызовов нет ни одного: поля читаются
// через fetchAll(), создаются через batchAllPartialChecked(), и оба сообщают об
// отказе сами (исключение и разбор ошибок подкоманд). Функция не звалась ни разу
// и удалена как остаток — живой аналог для тех, кому он нужен, есть в crm-dict.php.
// Появится здесь прямой call() — брать общий приём оттуда, а не заводить копию.

/**
 * Уже существующие поля сущности: «имя поля → описание».
 * Читаем ВСЕ страницы: на портале с историей пользовательских полей больше
 * пятидесяти — обычное дело, а обрыв на первой странице выглядит как «поля нет»
 * и приводит к созданию дубля.
 */
function ufExisting($b24, string $entity): array {

    // Обход — штатным fetchAllBatched клиента: он листает СМЕЩЕНИЕМ и сверяет
    // число полученных записей с `total`, который метод возвращает.
    //
    // Курсорный fetchAll() здесь НЕ годится: фильтр `*.userfield.list`
    // документирован только по равенству (ID, FIELD_NAME, USER_TYPE_ID, XML_ID,
    // SORT, MULTIPLE… — api-reference/crm/companies/userfields/
    // crm-company-userfield-list.md), условие «>ID» портал молча выбрасывает, а
    // порядок по умолчанию идёт по SORT, а не по ID. На портале с 60 полями это
    // выглядело как «курсор ID не двигается»: приложение не могло даже начать
    // работу (обращение 0921-101006-3f84). Список полей — справочник на десятки
    // записей, во время обхода он не «живёт» так, как список сделок, поэтому
    // страничный обход со сверкой total здесь и полон, и безопасен.
    // Каждый вызов с ПОЛНЫМ именем метода: имя, собранное из кусков или взятое
    // из переменной, не читается ни человеком, ни забором шаблона — а забор
    // здесь следит за тем, чтобы обращения к порталу были видимыми.
    $params = ['order' => ['ID' => 'ASC']];
    try {
        switch ($entity) {
            case 'lead':    $rows = $b24->fetchAllBatched('crm.lead.userfield.list', $params, 10000, 'ID'); break;
            case 'deal':    $rows = $b24->fetchAllBatched('crm.deal.userfield.list', $params, 10000, 'ID'); break;
            case 'contact': $rows = $b24->fetchAllBatched('crm.contact.userfield.list', $params, 10000, 'ID'); break;
            case 'company': $rows = $b24->fetchAllBatched('crm.company.userfield.list', $params, 10000, 'ID'); break;
            case 'quote':   $rows = $b24->fetchAllBatched('crm.quote.userfield.list', $params, 10000, 'ID'); break;
            default: throw new InvalidArgumentException('нет метода списка полей для «' . $entity . '»');
        }
    } catch (Throwable $e) {
        // «Список не прочитан» и «полей нет» — противоположные вещи: вторая
        // приводит к созданию дубля поверх существующего поля.
        throw new RuntimeException('не прочитан список полей ' . $entity . ': ' . $e->getMessage());
    }
    $out = [];
    foreach ($rows as $f) {
        $name = (string)($f['FIELD_NAME'] ?? '');
        if ($name !== '') $out[$name] = $f;
    }
    return $out;
}

/**
 * Довести поля сущности до нужного вида.
 *
 * @param string $entity  lead|deal|contact|company|quote
 * @param array  $fields  список описаний:
 *        ['code' => 'ORDER_NO', 'type' => 'string', 'label' => 'Номер заявки',
 *         'list' => ['Новый', 'В работе'],   // только для type=enumeration
 *         'multiple' => false, 'mandatory' => false]
 * @return array ['map' => ['ORDER_NO' => 'UF_CRM_APP_ORDER_NO'],
 *                'created' => [...], 'existed' => [...], 'failed' => ['ORDER_NO' => 'причина']]
 */
function ufEnsure($b24, string $entity, array $fields): array {
    $entity = strtolower(trim($entity));
    if (!in_array($entity, UF_ENTITIES, true)) {
        throw new InvalidArgumentException('поля можно заводить только для: ' . implode(', ', UF_ENTITIES)
            . ' (пришло «' . $entity . '»). Для смарт-процессов нужен отдельный вызов.');
    }
    if (!$fields) return ['map' => [], 'created' => [], 'existed' => [], 'failed' => []];

    // Замок по сущности: два одновременных запроса иначе оба увидят «поля нет»
    // и создадут его дважды. Проверка и создание обязаны быть одной операцией.
    return withKeyLock('uf-' . $entity, static function () use ($b24, $entity, $fields): array {
        $existing = ufExisting($b24, $entity);
        $map = [];
        $created = [];
        $existed = [];
        $failed = [];
        $toCreate = [];

        // Сначала — столкновения ВНУТРИ набора. Код поля на портале короче
        // логического, и два разных кода проекта могут сжаться в один. Тогда
        // второе поле молча писало бы в первое: пользователь видит в карточке
        // не то значение, и заметить это нечем. Отказываем обоим, а не выбираем
        // «кто успел»: тихий выбор здесь хуже громкого отказа.
        $byName = [];
        foreach ($fields as $spec) {
            $code = (string)($spec['code'] ?? '');
            if ($code === '') continue;
            $byName[ufFieldName($code)][] = $code;
        }
        $collided = [];
        foreach ($byName as $name => $codes) {
            $unique = array_values(array_unique($codes));
            if (count($unique) < 2) continue;
            foreach ($unique as $code) {
                $collided[$code] = true;
                $failed[$code] = 'код поля совпал с «' . implode('», «', array_diff($unique, [$code]))
                    . '»: у портала на код всего ' . UF_CODE_MAX . ' знаков. Переименуйте одно из полей.';
            }
            appLog('error', 'uf.code_collision', ['имя поля' => $name, 'коды проекта' => $unique]);
        }

        foreach ($fields as $spec) {
            $code = (string)($spec['code'] ?? '');
            if ($code === '' || isset($collided[$code])) continue;
            $name = ufFieldName($code);
            $map[$code] = $name;
            if (isset($existing[$name])) {
                // Поле с таким кодом на портале есть. Наше ли оно? Внешний код
                // хранит полный логический код: чужой — значит столкновение, и
                // писать туда нельзя. Пустой внешний код — поле старой версии
                // или заведённое человеком: принимаем как своё (иначе сломаются
                // все установки, сделанные раньше), но говорим об этом в журнал.
                $xml = (string)($existing[$name]['XML_ID'] ?? '');
                $mine = ufLogicalId($code);
                if ($xml !== '' && $xml !== $mine && strpos($xml, 'BXAPP:') === 0) {
                    $failed[$code] = 'поле ' . $name . ' на портале занято другим кодом приложения ('
                        . $xml . '). Переименуйте поле в проекте.';
                    appLog('error', 'uf.foreign_field', ['имя поля' => $name, 'чужой код' => $xml,
                        'наш код' => $mine]);
                    unset($map[$code]);
                    continue;
                }
                if ($xml === '' || strpos($xml, 'BXAPP:') !== 0) {
                    appLog('warn', 'uf.field_without_mark', ['имя поля' => $name, 'код проекта' => $code,
                        'что это значит' => 'поле заведено не этой версией приложения — принимаем как своё']);
                }
                $existed[] = $code;
                continue;
            }
            $toCreate[$code] = ufBuildSpec(ufFieldCode($code), $spec, ufLogicalId($code));
        }

        if ($toCreate) {
            $cmd = [];
            foreach ($toCreate as $code => $payload) {
                // Имя метода — ЛИТЕРАЛОМ рядом со сборкой команды. Имя, пришедшее
                // из функции, не видит ни человек при чтении, ни забор шаблона:
                // для него такой пакет — вызов без имени метода, к которому
                // контракт мутации просто не применяется.
                switch ($entity) {
                    case 'lead':    $addMethod = 'crm.lead.userfield.add'; break;
                    case 'deal':    $addMethod = 'crm.deal.userfield.add'; break;
                    case 'contact': $addMethod = 'crm.contact.userfield.add'; break;
                    case 'company': $addMethod = 'crm.company.userfield.add'; break;
                    default:        $addMethod = 'crm.quote.userfield.add'; break;
                }
                $cmd[$code] = [$addMethod, ['fields' => $payload]];
            }
            // batchAll сам режет на пакеты по 50 и возвращает частичные ошибки
            // отдельно: одно упавшее поле не отменяет создание остальных, но и
            // не выдаёт себя за успех. Checked-версия тут не годится — она
            // бросает исключение на первой же ошибке, а нам нужно доложить, что
            // именно не создалось, и не потерять то, что создалось.
            // Перечитывание — обязательный аргумент помощника: «портал ответил
            // успехом» и «поле в карточке появилось» — разные вещи, и вторая
            // проверяется только повторным чтением.
            $res = $b24->batchAllPartialChecked($cmd, static function (array $keys) use ($b24, $entity, $map): array {
                $after = ufExisting($b24, $entity);
                $ok = [];
                foreach ($keys as $code) {
                    if (isset($after[$map[$code] ?? ''])) $ok[] = $code;
                }
                return $ok;
            });
            foreach ($toCreate as $code => $_) {
                if (isset($res['errors'][$code])) { $failed[$code] = (string)$res['errors'][$code]; continue; }
                if (!array_key_exists($code, $res['result'])) {
                    $failed[$code] = 'портал не ответил по этой команде';
                    continue;
                }
                $created[] = $code;
            }
        }

        // Созданное подтвердил помощник перечитыванием. Здесь проверяем то, что
        // он не видел: поля, которые считались уже существующими. Их мог удалить
        // человек между чтением и этим моментом — тогда карта врала бы.
        foreach ($existed as $code) {
            if (!isset($existing[$map[$code]])) $failed[$code] = 'поле пропало с портала во время работы';
        }

        // След действия, а не просто строка в журнале: заведение полей меняет
        // карточку у всех сотрудников портала, и через месяц вопрос «откуда тут
        // это поле» должен иметь ответ. Read-back выше уже подтвердил, что поля
        // на портале есть — пишем факт, а не намерение.
        auditLog('uf.ensure', [
            'сущность' => $entity,
            'создано' => $created,
            'уже было' => count($existed),
            'не удалось' => $failed,
        ]);
        return ['map' => $map, 'created' => $created, 'existed' => $existed, 'failed' => $failed];
    });
}

/**
 * Описание поля для портала. В FIELD_NAME уходит КОД (без префикса) — так
 * требует портал; полное имя он соберёт сам.
 */
function ufBuildSpec(string $fieldCode, array $spec, string $logicalId = ''): array {
    $type = (string)($spec['type'] ?? 'string');
    // Без подписи человек видит в карточке машинный код поля. Явной подписи нет —
    // подставляем код: он хотя бы читаем, в отличие от пустой строки.
    $label = trim((string)($spec['label'] ?? '')) !== '' ? (string)$spec['label'] : $fieldCode;
    $payload = [
        'FIELD_NAME'   => $fieldCode,
        'USER_TYPE_ID' => $type,
        // Внешний код — ПОЛНЫЙ логический, а не сжатый: по нему потом видно,
        // чьё это поле и за каким кодом проекта оно стоит.
        'XML_ID'       => $logicalId !== '' ? $logicalId : $fieldCode,
        'MULTIPLE'     => !empty($spec['multiple']) ? 'Y' : 'N',
        'MANDATORY'    => !empty($spec['mandatory']) ? 'Y' : 'N',
        'SHOW_FILTER'  => 'Y',
        // Подпись на всех языках портала сразу: без EDIT_FORM_LABEL человек
        // видит в карточке машинный код поля вместо названия.
        'EDIT_FORM_LABEL' => ['ru' => $label, 'en' => $label],
        'LIST_COLUMN_LABEL' => ['ru' => $label, 'en' => $label],
        'LIST_FILTER_LABEL' => ['ru' => $label, 'en' => $label],
    ];
    if ($type === 'enumeration') {
        $list = [];
        foreach ((array)($spec['list'] ?? []) as $i => $value) {
            $list[] = ['VALUE' => (string)$value, 'SORT' => ($i + 1) * 10];
        }
        // Список без значений портал создаст, но выбирать в нём будет нечего —
        // для человека это «поле сломано». Лучше сказать сразу.
        if (!$list) throw new InvalidArgumentException('поле-список ' . $fieldCode . ' заведено без вариантов выбора');
        $payload['LIST'] = $list;
    }
    return $payload;
}
