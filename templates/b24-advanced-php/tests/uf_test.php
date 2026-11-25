<?php
declare(strict_types=1);
/**
 * Поля приложения в карточке портала: повтор не плодит дублей, частичный отказ
 * виден, список читается со всех страниц.
 *
 * Портал подменён штатным швом `__b24_call` (работает только при уведённом
 * сторе) — настоящий портал в испытаниях не участвует.
 */

suite('Поля приложения в карточке портала');

require_once dirname(__DIR__) . '/www/api/uf.php';

/** Игрушечный портал: помнит созданные поля, умеет ломаться по указанию. */
final class UfFakePortal {
    public array $fields = [];      // FIELD_NAME → описание
    public array $calls = [];       // все обращения — чтобы видеть, сколько раз звали
    public array $failCreate = [];  // код поля → текст ошибки
    public bool $listBroken = false;
    public int $pageSize = 50;
    private int $nextId = 0;   // как на портале: идентификаторы только растут

    /** Поле, которое на портале уже есть (например, заведено человеком). */
    public function addExisting(string $name, string $xmlId = ''): void {
        if (!isset($this->fields[$name])) {
            $id = ++$this->nextId;
            // SORT у полей портала свой и с ID никак не связан. Из-за этого
            // список, отсортированный порталом по умолчанию (SORT, потом ID),
            // идёт с идентификаторами вразнобой — ровно как на бою.
            $this->fields[$name] = ['ID' => $id, 'FIELD_NAME' => $name, 'XML_ID' => $xmlId,
                'SORT' => 100 + ($id % 7) * 10];
        }
    }

    /** Порядок портала по умолчанию: SORT ASC, при равном SORT — ID ASC. */
    private function sortedFields(): array {
        $all = array_values($this->fields);
        usort($all, static function (array $a, array $b): int {
            $s = (int)($a['SORT'] ?? 100) <=> (int)($b['SORT'] ?? 100);
            return $s !== 0 ? $s : ((int)$a['ID'] <=> (int)$b['ID']);
        });
        return $all;
    }

    public function handle(string $method, array $params): array {
        $this->calls[] = $method;
        if (str_ends_with($method, '.userfield.list')) {
            if ($this->listBroken) return ['error' => 'ACCESS_DENIED', 'error_description' => 'нет прав'];
            // КУРСОРА ПО ID У ЭТОГО МЕТОДА НЕТ, и заглушка обязана врать не
            // больше портала. Фильтр `*.userfield.list` документирован ТОЛЬКО
            // по равенству (ID, FIELD_NAME, USER_TYPE_ID, XML_ID, SORT,
            // MULTIPLE… — api-reference/crm/companies/userfields/
            // crm-company-userfield-list.md): условие '>ID' портал молча
            // выбрасывает, страницы листаются смещением `start`, а порядок по
            // умолчанию — SORT, потом ID. Прежняя заглушка исполняла '>ID' как
            // курсор, поэтому курсорный обход был здесь зелёным, а на боевом
            // портале с 60 полями падал «курсор ID не двигается» (обращение
            // 0921-101006-3f84).
            $all = $this->sortedFields();
            foreach ((array)($params['filter'] ?? []) as $k => $v) {
                $k = (string)$k;
                if ($k === '' || !ctype_alpha($k[0])) continue;   // операторы ('>ID') портал игнорирует
                $all = array_values(array_filter($all, static fn($f) => (string)($f[$k] ?? '') === (string)$v));
            }
            $start = (int)($params['start'] ?? 0);
            if ($start < 0) {
                // start = -1: портал отдаёт первую страницу и НЕ считает total.
                return ['result' => array_slice($all, 0, $this->pageSize)];
            }
            return ['result' => array_slice($all, $start, $this->pageSize), 'total' => count($all)];
        }
        if ($method === 'batch') {
            $result = [];
            $errors = [];
            foreach ((array)($params['cmd'] ?? []) as $key => $line) {
                // Разбираем команду так же, как её собрал клиент: метод?query.
                // Вопросительный знак отрезаем: с ним первый ключ становится
                // «?fields», поля уходят в никуда, и заглушка врёт про портал.
                $query = (string)strstr((string)$line, '?');
                parse_str($query === '' ? '' : substr($query, 1), $q);
                // В пакет попадают не только создания полей: страницы списка
                // клиент тоже просит пачкой. Отдаём их той же логикой портала —
                // иначе заглушка «теряет» вторую страницу и врёт про выборку.
                $vlozhennyj = (string)strstr((string)$line, '?', true);
                if ($vlozhennyj === '') $vlozhennyj = (string)$line;
                if (str_ends_with($vlozhennyj, '.userfield.list')) {
                    $otvet = $this->handle($vlozhennyj, $q);
                    if (isset($otvet['error'])) { $errors[$key] = (string)$otvet['error']; continue; }
                    $result[$key] = $otvet['result'];
                    continue;
                }
                $fields = (array)($q['fields'] ?? []);
                $code = (string)($fields['FIELD_NAME'] ?? '');
                if (isset($this->failCreate[$key])) { $errors[$key] = $this->failCreate[$key]; continue; }
                // Портал ведёт себя ИМЕННО так (официальная дока
                // crm-deal-userfield-add.md): в FIELD_NAME приходит КОД поля, к
                // нему портал сам добавляет префикс UF_CRM_, а полезная длина
                // кода — 13 знаков. Прежняя заглушка сохраняла присланное имя
                // как есть, поэтому пропускала главный дефект: приложение
                // отправляло полное имя, портал делал из него UF_CRM_UF_CRM_…,
                // и перечитывание не находило поле.
                if (strlen($code) > 13) {
                    $errors[$key] = 'ERROR_FIELD_NAME_TOO_LONG: код поля длиннее 13 знаков';
                    continue;
                }
                if (preg_match('/[^A-Z0-9_]/', $code)) {
                    $errors[$key] = 'INVALID_ARG_VALUE: в коде поля допустимы только A-Z, 0-9 и _';
                    continue;
                }
                $name = 'UF_CRM_' . $code;
                // Идентификатор берём из монотонного счётчика, а не из размера
                // массива: при перезаписи существующего поля размер не менялся,
                // два поля получали один ID, и обход курсором терял записи —
                // заглушка врала про портал.
                if (!isset($this->fields[$name])) {
                    $id = ++$this->nextId;
                    $this->fields[$name] = ['ID' => $id, 'FIELD_NAME' => $name, 'SORT' => 100 + ($id % 7) * 10];
                }
                // Подпись запоминаем: именно её человек видит в карточке. Без
                // неё портал показывает машинный код поля, и никакой read-back
                // этого не ловит — поле-то создано.
                $this->fields[$name]['LABEL'] = (string)(((array)($fields['EDIT_FORM_LABEL'] ?? []))['ru'] ?? '');
                // Внешний код портал хранит и отдаёт обратно списком. По нему
                // приложение отличает своё поле от чужого с тем же коротким
                // кодом — без этого в заглушке проверять было бы нечего.
                $this->fields[$name]['XML_ID'] = (string)($fields['XML_ID'] ?? '');
                $result[$key] = $this->fields[$name]['ID'];
            }
            return ['result' => ['result' => $result, 'result_error' => $errors]];
        }
        return ['error' => 'unexpected_method', 'error_description' => $method];
    }
}

// Токены нужны, чтобы клиент вообще собрался: подмена портала стоит ПОСЛЕ
// проверки установки, а не вместо неё.
storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'a', 'refresh_token' => 'r',
    'domain' => 'portal.invalid', 'client_endpoint' => 'https://portal.invalid/rest/',
    'member_id' => 'M-UF', 'expires_at' => time() + 3600,
]);

$portal = new UfFakePortal();
$GLOBALS['__b24_call'] = static fn(string $m, array $p): array => $portal->handle($m, $p);
$b24 = b24();

$spec = [
    ['code' => 'ORDER_NO', 'type' => 'string', 'label' => 'Номер заявки'],
    ['code' => 'SYNC_STATE', 'type' => 'enumeration', 'label' => 'Статус обмена', 'list' => ['Новый', 'Готово']],
];

// 1. Первый заход создаёт поля и возвращает соответствие «мой код → поле портала».
$first = ufEnsure($b24, 'deal', $spec);
check('поля созданы с первого раза', count($first['created']) === 2, json_encode($first['created'], JSON_UNESCAPED_UNICODE));
check('вернулось соответствие кода и имени поля на портале',
    ($first['map']['ORDER_NO'] ?? '') === 'UF_CRM_APP_ORDER_NO', json_encode($first['map'], JSON_UNESCAPED_UNICODE));
check('ничего не провалилось', $first['failed'] === [], json_encode($first['failed'], JSON_UNESCAPED_UNICODE));
check('в имени поля есть метка приложения — чужое поле с тем же кодом не перепутать',
    str_contains($first['map']['SYNC_STATE'] ?? '', '_APP_'));

// 1б. Подпись поля доходит до портала. Пустая подпись — это карточка, в которой
// сотрудник видит «UF_CRM_APP_ORDER_NO» вместо «Номер заявки».
check('ПОДПИСЬ ПОЛЯ ДОШЛА ДО ПОРТАЛА',
    ($portal->fields['UF_CRM_APP_ORDER_NO']['LABEL'] ?? '') === 'Номер заявки',
    json_encode($portal->fields['UF_CRM_APP_ORDER_NO'] ?? [], JSON_UNESCAPED_UNICODE));
$noLabel = ufBuildSpec('APP_BEZ_PODPISI', ['type' => 'string']);
check('поле без явной подписи получает хотя бы свой код, а не пустоту',
    (string)(($noLabel['EDIT_FORM_LABEL'] ?? [])['ru'] ?? '') !== '',
    json_encode($noLabel, JSON_UNESCAPED_UNICODE));

// 2. Повтор НИЧЕГО не создаёт. Это главное: иначе при каждой установке в карточке
//    появляется «Номер заявки (1)», «(2)», а данные остаются в первом поле.
$portal->calls = [];
$second = ufEnsure($b24, 'deal', $spec);
check('ПОВТОР НЕ СОЗДАЁТ ДУБЛЕЙ', $second['created'] === [], json_encode($second['created'], JSON_UNESCAPED_UNICODE));
check('повтор видит поля как уже существующие', count($second['existed']) === 2, json_encode($second['existed'], JSON_UNESCAPED_UNICODE));
check('при повторе портал не просят создавать ничего',
    !in_array('batch', $portal->calls, true), implode(',', $portal->calls));
check('полей на портале по-прежнему два', count($portal->fields) === 2, (string)count($portal->fields));

// 3. Частичный отказ виден поимённо, а уцелевшее не теряется.
$portal->failCreate = ['DOC_LINK' => 'INSUFFICIENT_RIGHTS'];
$third = ufEnsure($b24, 'deal', [
    ['code' => 'DOC_LINK', 'type' => 'string', 'label' => 'Ссылка на документ'],
    ['code' => 'MANAGER_NOTE', 'type' => 'string', 'label' => 'Заметка'],
]);
check('упавшее поле названо поимённо с причиной',
    isset($third['failed']['DOC_LINK']) && str_contains($third['failed']['DOC_LINK'], 'RIGHTS'),
    json_encode($third['failed'], JSON_UNESCAPED_UNICODE));
check('соседнее поле при этом создано', in_array('MANAGER_NOTE', $third['created'], true),
    json_encode($third['created'], JSON_UNESCAPED_UNICODE));

// 4. Список полей читается ДО КОНЦА. Обрыв на первой странице выглядит как
//    «поля нет» и приводит к созданию дубля поверх существующего.
//    Портал отдаёт РОВНО по 50 записей на страницу и на неполной странице список
//    считается законченным — поэтому многостраничность моделируем настоящим
//    объёмом, а не искусственно уменьшенной страницей: на маленькой странице
//    проверка была бы зелёной и на коде, который читает только первую.
$portal->failCreate = [];
for ($i = 0; $i < 60; $i++) {
    $portal->addExisting('UF_CRM_CHUZHOE_' . $i);   // чужие поля портала
}
$fourth = ufEnsure($b24, 'deal', [['code' => 'ORDER_NO', 'type' => 'string', 'label' => 'Номер заявки']]);
check('поле со второй страницы найдено, дубль не создан',
    $fourth['created'] === [] && $fourth['existed'] === ['ORDER_NO'],
    json_encode($fourth, JSON_UNESCAPED_UNICODE));
check('на портале действительно больше одной страницы полей',
    count($portal->fields) > 50, (string)count($portal->fields));

// 5. Портал молчит → громкий отказ, а не «полей нет».
$beforeBroken = count($portal->fields);
$portal->listBroken = true;
$thrown = '';
try { ufEnsure($b24, 'deal', $spec); } catch (Throwable $e) { $thrown = $e->getMessage(); }
check('нечитаемый список полей не превращается в «полей нет»',
    str_contains($thrown, 'не прочитан список полей'), $thrown);
// Число полей до попытки — чтобы проверка не зависела от того, сколько их
// накопили предыдущие шаги.
check('при нечитаемом списке ничего не создаётся', count($portal->fields) === $beforeBroken,
    'было ' . $beforeBroken . ', стало ' . count($portal->fields));
$portal->listBroken = false;

// 6. Опечатка в имени сущности ловится сразу, а не уходит в портал.
$thrown = '';
try { ufEnsure($b24, 'deals', $spec); } catch (Throwable $e) { $thrown = $e->getMessage(); }
check('неизвестная сущность отвергается с понятным текстом',
    str_contains($thrown, 'поля можно заводить только для'), $thrown);

// 7. Поле-список без вариантов — это сломанное поле для человека.
$thrown = '';
try { ufEnsure($b24, 'deal', [['code' => 'PUSTOY', 'type' => 'enumeration', 'label' => 'Пустой список']]); }
catch (Throwable $e) { $thrown = $e->getMessage(); }
check('список без вариантов выбора не заводится молча',
    str_contains($thrown, 'без вариантов выбора'), $thrown);

// 8. КОНТРАКТ ИМЕНИ ПОЛЯ. Портал принимает КОД (13 знаков), а префикс UF_CRM_
//    добавляет сам. Прислать полное имя — значит получить UF_CRM_UF_CRM_… и
//    приложение, которое на каждой установке создаёт поле заново.
check('в портал уходит код без префикса', strpos(ufFieldCode('ORDER_NO'), 'UF_CRM_') === false,
    ufFieldCode('ORDER_NO'));
check('код поля помещается в 13 знаков', strlen(ufFieldCode('ORDER_NO')) <= 13, ufFieldCode('ORDER_NO'));
check('длинный код усечён, но всё ещё помещается',
    strlen(ufFieldCode('OCHEN_DLINNOE_IMYA_POLYA')) <= 13, ufFieldCode('OCHEN_DLINNOE_IMYA_POLYA'));
check('два похожих длинных кода НЕ сливаются в одно поле',
    ufFieldCode('SYNC_STATE_LONG_A') !== ufFieldCode('SYNC_STATE_LONG_B'),
    ufFieldCode('SYNC_STATE_LONG_A') . ' / ' . ufFieldCode('SYNC_STATE_LONG_B'));
check('в коде поля только разрешённые портом символы',
    preg_match('/^[A-Z0-9_]+$/', ufFieldCode('мой-код 42')) === 1, ufFieldCode('мой-код 42'));
check('полное имя — это то, что вернёт портал',
    ufFieldName('ORDER_NO') === 'UF_CRM_' . ufFieldCode('ORDER_NO'), ufFieldName('ORDER_NO'));

// 9. Длинный код доходит до портала и создаётся: раньше портал отвечал отказом
//    по длине, а приложение считало, что поле есть.
$long = ufEnsure($b24, 'deal', [['code' => 'OCHEN_DLINNOE_IMYA_POLYA', 'type' => 'string', 'label' => 'Длинное']]);
check('поле с длинным кодом реально создаётся на портале',
    $long['failed'] === [] && count($long['created']) === 1,
    json_encode($long, JSON_UNESCAPED_UNICODE));
check('приложение знает настоящее имя созданного поля',
    isset($portal->fields[$long['map']['OCHEN_DLINNOE_IMYA_POLYA']]),
    json_encode(array_keys($portal->fields), JSON_UNESCAPED_UNICODE));

// 10. СТОЛКНОВЕНИЯ КОДОВ. Код поля на портале короче логического, сжатие
//     необратимо — значит два разных кода проекта в принципе могут дать один и
//     тот же. Проверяем не одну подобранную пару, а три вещи: на большом наборе
//     столкновений нет; известное столкновение приводит к ОТКАЗУ, а не к тихому
//     слиянию; чужое поле на портале не считается своим.
$corpus = [];
$dubli = [];
foreach (['ORDER', 'SYNC', 'CLIENT', 'INVOICE', 'DELIVERY', 'MANAGER', 'STATUS', 'PAYMENT'] as $osnova) {
    foreach (range(1, 250) as $n) {
        $kod = $osnova . '_DOPOLNITELNOE_POLE_' . $n;
        $imya = ufFieldCode($kod);
        if (isset($corpus[$imya])) $dubli[] = $corpus[$imya] . ' = ' . $kod;
        $corpus[$imya] = $kod;
    }
}
check('НА ДВУХ ТЫСЯЧАХ ОБЫЧНЫХ КОДОВ СТОЛКНОВЕНИЙ НЕТ',
    $dubli === [], 'столкнулись: ' . implode('; ', array_slice($dubli, 0, 3)));

// Пара найдена перебором по этому же алгоритму — то есть столкновение возможно
// и обязано кончаться понятным отказом, а не тем, что второе поле пишет в первое.
$para = ['FIELD_LONG_NAME_469', 'FIELD_LONG_NAME_1292'];
check('подобранная пара действительно даёт один код поля',
    ufFieldCode($para[0]) === ufFieldCode($para[1]), ufFieldCode($para[0]));
$poleyDo = count($portal->fields);
$stolknovenie = ufEnsure($b24, 'deal', [
    ['code' => $para[0], 'type' => 'string', 'label' => 'Первое'],
    ['code' => $para[1], 'type' => 'string', 'label' => 'Второе'],
]);
check('СТОЛКНУВШИЕСЯ КОДЫ ОТКАЗЫВАЮТСЯ ОБА, А НЕ СЛИВАЮТСЯ',
    isset($stolknovenie['failed'][$para[0]]) && isset($stolknovenie['failed'][$para[1]])
    && $stolknovenie['created'] === [],
    json_encode($stolknovenie, JSON_UNESCAPED_UNICODE));
check('при столкновении в портале ничего не заводится',
    count($portal->fields) === $poleyDo, count($portal->fields) . ' против ' . $poleyDo);
check('человеку сказано, что именно переименовать',
    mb_strpos((string)($stolknovenie['failed'][$para[0]] ?? ''), $para[1]) !== false,
    (string)($stolknovenie['failed'][$para[0]] ?? ''));

// Чужое поле с тем же коротким кодом: внешний код хранит ПОЛНЫЙ логический,
// поэтому подмена видна. Раньше различить было нечем — приложение начинало
// писать в чужое поле, и в карточке у людей появлялись чужие значения.
$chuzhoe = 'DRUGOJ_OCHEN_DLINNYJ_KOD_POLYA';
$portal->addExisting(ufFieldName($chuzhoe), 'BXAPP:APP:SOVSEM_DRUGOJ_KOD_POLYA_PROEKTA');
$chuzhoeItog = ufEnsure($b24, 'deal', [['code' => $chuzhoe, 'type' => 'string', 'label' => 'Чужое']]);
check('ЧУЖОЕ ПОЛЕ НА ПОРТАЛЕ НЕ СЧИТАЕТСЯ СВОИМ',
    isset($chuzhoeItog['failed'][$chuzhoe]) && !isset($chuzhoeItog['map'][$chuzhoe]),
    json_encode($chuzhoeItog, JSON_UNESCAPED_UNICODE));

// Поле, заведённое прежней версией приложения или человеком: внешнего кода у
// него нет. Отказывать нельзя — сломаются все прежние установки; принимаем как
// своё, но оставляем след в журнале.
$staroe = 'STAROE_POLE_BEZ_VNESHNEGO_KODA';
$portal->addExisting(ufFieldName($staroe), '');
$staroeItog = ufEnsure($b24, 'deal', [['code' => $staroe, 'type' => 'string', 'label' => 'Старое']]);
check('поле без внешнего кода принимается как своё, а не ломает установку',
    $staroeItog['failed'] === [] && in_array($staroe, $staroeItog['existed'], true),
    json_encode($staroeItog, JSON_UNESCAPED_UNICODE));

check('СОЗДАННОЕ ПОЛЕ ПОМЕЧЕНО ПОЛНЫМ КОДОМ ПРОЕКТА',
    ($portal->fields[$long['map']['OCHEN_DLINNOE_IMYA_POLYA']]['XML_ID'] ?? '')
        === ufLogicalId('OCHEN_DLINNOE_IMYA_POLYA'),
    json_encode($portal->fields[$long['map']['OCHEN_DLINNOE_IMYA_POLYA']] ?? [], JSON_UNESCAPED_UNICODE));

unset($GLOBALS['__b24_call']);
