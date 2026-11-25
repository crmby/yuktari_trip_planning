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
 * ПОЛНАЯ ВЫБОРКА ДЛЯ ПРОЕКТОВ СО СТАРЫМ ДВИЖКОМ.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ФАЙЛ, ЕСЛИ В ШАБЛОНЕ УЖЕ ЕСТЬ `$b24->fetchAll()`.
 * Затем, что в шаблоне-то есть, а в проектах — нет: замер 05.08.2026 показал
 * 256 мест в 44 проектах, где список портала читается без перелистывания вовсе,
 * то есть наружу уходят первые 50 записей и выдаются за все. Обновить движок в
 * этих проектах — отдельная работа с проверкой каждого (у старых копий другое
 * поведение отказов), а брак идёт уже сейчас.
 *
 * Этот файл кладётся в проект ПОВЕРХ его собственного движка и ничего в нём не
 * меняет: ему нужен только способ позвать портал — любая функция вида
 * `function ($метод, $параметры): array`. Поэтому он работает и с самодельным
 * клиентом десятилетней давности.
 *
 * В САМОМ ШАБЛОНЕ ПОЛЬЗУЙСЯ `$b24->fetchAll()` — там оно богаче (батчи,
 * обновление токена, журнал). Здесь — совместимость, а не замена.
 *
 * ПОЧЕМУ КУРСОР ПО ИДЕНТИФИКАТОРУ, А НЕ СЧЁТЧИК СТРАНИЦ. Счётчик `start` врёт:
 * если во время чтения запись удалили, страницы сдвигаются и часть данных
 * пропадает молча. Курсор «дай записи с идентификатором больше последнего»
 * от этого не страдает.
 */

/**
 * Список ли это — то есть ключи 0,1,2,… подряд.
 *
 * ПОЧЕМУ НЕ array_is_list(). Она появилась только в PHP 8.1, а весь смысл этого
 * файла — работать в проектах на СТАРОМ движке (7.4 и 8.0). Там её вызов даёт не
 * ошибку выборки, а фатал «Call to undefined function» на первой же странице:
 * приложение падает целиком, и происходит это ровно у тех, ради кого файл написан.
 * Проверка ниже делает то же самое средствами 7.4.
 *
 * Имя с приставкой модуля намеренно: файл кладётся ПОВЕРХ чужого проекта, где
 * может уже быть своя isList(), и одноимённая функция дала бы фатал повторного
 * объявления — ту же беду, от которой мы здесь и уходим.
 */
function fetchAllCompatIsList(array $a): bool {
    $expected = 0;
    foreach ($a as $key => $_) {
        if ($key !== $expected) return false;
        $expected++;
    }
    return true;
}

/**
 * Забрать ВЕСЬ список портала.
 *
 * @param callable $call    function (string $метод, array $параметры): array — ответ портала как есть
 * @param string   $method  метод списка, например 'crm.item.list'
 * @param array    $params  фильтр и прочее; сортировку задаём здесь сами, а нижнюю
 *                          границу по полю идентификатора (`'>ID' => 100`) забираем себе
 *                          и продолжаем курсор от неё — записи ниже границы не вернутся
 * @param string|null $idField имя поля идентификатора; null определяет его по методу:
 *                             'ID' у большинства методов, 'id' у crm.item.*
 * @param int      $maxPages предохранитель от бесконечного цикла
 *
 * @return array Все записи. Неполной выборки не бывает: если упёрлись в предел
 *               страниц или портал ответил неизвестной формой — исключение, а не
 *               обрезанный список. Молча вернуть часть нельзя: вызывающий примет
 *               её за всё и, например, снимет признак у «всех остальных» записей.
 */
function fetchAllCompat(callable $call, string $method, array $params = [],
                        ?string $idField = null, int $maxPages = 500): array {
    if ($maxPages < 1) {
        throw new InvalidArgumentException('fetchAllCompat: предел страниц должен быть больше нуля');
    }
    $namedIdField = $idField === null ? '' : trim($idField);
    $methodLower = strtolower(trim($method));
    $idField = $namedIdField !== ''
        ? $namedIdField
        : (strpos($methodLower, 'crm.item.') === 0 ? 'id' : 'ID');
    // ФИЛЬТР ВЫЗЫВАЮЩЕГО РАЗБИРАЕМ ОДИН РАЗ, ДО ЦИКЛА.
    //
    // Прописная копия ключа — не редкость, а норма для адресата этого файла:
    // примеры Б24 и старый код сплошь пишут FILTER/ORDER/START. Оставить чужой
    // FILTER рядом со своим filter — значит отправить в портал ДВА фильтра
    // сразу, и какой победит, зависит от метода: выборка молча оказывается не
    // той, что просили. Тот же разбор — в www/api/b24.php, cursorPageParams().
    // Порядок написаний тот же, что в ядре: при совпадении условия побеждает
    // строчное 'filter'.
    $userFilter = [];
    foreach (['FILTER', 'filter'] as $klyuchFiltra) {
        if (!array_key_exists($klyuchFiltra, $params)) continue;
        if (!is_array($params[$klyuchFiltra])) {
            throw new InvalidArgumentException('fetchAllCompat ' . $method . ': «' . $klyuchFiltra
                . '» должен быть массивом условий, пришло ' . gettype($params[$klyuchFiltra]));
        }
        $userFilter = array_merge($userFilter, $params[$klyuchFiltra]);
    }
    // НИЖНЮЮ ГРАНИЦУ ВЫЗЫВАЮЩЕГО ЗАБИРАЕМ СЕБЕ, А НЕ ОТБРАСЫВАЕМ.
    //
    // «Дай записи новее N» — обычный запрос, и приходит он тем же ключом, что
    // и наш курсор ('>ID'). Рядом с курсором такой ключ жить не может: при
    // объединении побеждает один из двух, и оба исхода плохие. Победит чужой —
    // страница не двигается, обход крутится до предела и падает «слишком много
    // страниц», уводя разработчика искать ошибку в имени поля. Победит курсор —
    // граница теряется МОЛЧА: на первой странице курсор равен нулю, и записи
    // ниже границы уезжают вызывающему как «его» (находка ревью 09.08.2026).
    // Поэтому ключ снимаем, а его значение принимаем за старт курсора: смысл
    // условия сохранён, движение курсора не сломано.
    $lastId = 0;
    foreach ([$idField, strtoupper($idField), strtolower($idField)] as $pole) {
        $klyuchKursora = '>' . $pole;
        if (!array_key_exists($klyuchKursora, $userFilter)) continue;
        $lastId = max($lastId, (int)$userFilter[$klyuchKursora]);
        unset($userFilter[$klyuchKursora]);
    }
    // ИМЕНА ПАРАМЕТРОВ БЕРЁМ У МЕТОДА, А НЕ «КАК ПРИНЯТО».
    //
    // Строчные order/filter знают crm.*, tasks.* и прочие современные списки. Но
    // у части методов документированы другие имена, и адресат этого файла — как
    // раз старые проекты, где они и встречаются: у lists.element.get сортировка
    // называется ELEMENT_ORDER, а фильтр FILTER (строчного `filter` в
    // документации метода нет вовсе); у sonet_group.get — ORDER и FILTER.
    // Отправляя строчные, мы слали сортировку, которой у метода нет, и переносили
    // фильтр вызывающего под неизвестное имя: портал его отбрасывал, и «элементы
    // с именем %Тест%» превращались в список целиком — молча (K4-41, круг 4).
    // Источники: api-reference/lists/elements/lists-element-get.md:124 и :81,
    // api-reference/sonet-group/sonet-group-get.md:25. Тот же разбор — в
    // www/api/b24.php, cursorParamNames(); держим их в паре.
    $imenaPoSemeystvu = [
        'lists.element.' => ['order' => 'ELEMENT_ORDER', 'filter' => 'FILTER'],
        'sonet_group.'   => ['order' => 'ORDER',         'filter' => 'FILTER'],
    ];
    $imena = ['order' => 'order', 'filter' => 'filter'];
    $metodNizhnim = $methodLower;
    foreach ($imenaPoSemeystvu as $prefiks => $nabor) {
        if (strpos($metodNizhnim, $prefiks) === 0) { $imena = $nabor; break; }
    }
    // У user.get сортировка задаётся парой строк sort+order, а не картой
    // (api-reference/user/user-get.md:33-37) — курсорный обход выразить нечем.
    // Отказываем ДО сети: молча отправленная непонятная методу сортировка снова
    // отдала бы неполный список за полный.
    if ($metodNizhnim === 'user.get') {
        throw new InvalidArgumentException('fetchAllCompat user.get: сортировка у этого метода задаётся '
            . 'парой строк sort+order, а не картой, — курсором по идентификатору он не обходится. '
            . 'Листайте его смещением start и сверяйте total.');
    }
    // Та же таблица, что CURSOR_UNSUPPORTED_FILTER в www/api/b24.php (ревью
    // 23.09.2026, dubli-3): фильтр этих методов документирован только по равенству,
    // «>ID» портал молча выбрасывает — без отказа до сети обход падал «курсор не
    // двигается», отправляя искать ошибку в имени поля. Держим в паре с ядром.
    foreach ([
        'crm.status.list' => 'фильтр crm.status.list документирован только по равенству',
        '.userfield.list' => 'фильтр *.userfield.list документирован только по равенству, а порядок по умолчанию идёт по SORT',
    ] as $obrazec => $prichina) {
        $sovpalo = $obrazec[0] === '.'
            ? substr($metodNizhnim, -strlen($obrazec)) === $obrazec
            : $metodNizhnim === $obrazec;
        if ($sovpalo) {
            throw new InvalidArgumentException('fetchAllCompat ' . $method . ': курсором по идентификатору не '
                . 'обходится — ' . $prichina . ', условие «>ID» портал молча выбрасывает. Листайте смещением '
                . 'start и сверяйте total (в новом ядре — fetchAllBatched()).');
        }
    }

    $items = [];
    for ($page = 0; $page < $maxPages; $page++) {
        $p = $params;
        // Счётчику страниц и чужому порядку здесь не место, прописные копии —
        // тоже: уцелевший START => 0 вернул бы подсчёт общего количества
        // обратно, а ORDER/FILTER ушли бы в портал вторым, конфликтующим
        // набором. Содержимое чужого фильтра при этом не теряется — оно уже
        // забрано в $userFilter. Снимаем и строчные копии: у метода со своим
        // именем сортировки они ушли бы в портал лишним набором.
        unset(
            $p['start'], $p['limit'], $p['START'], $p['LIMIT'],
            $p['ORDER'], $p['FILTER'], $p['order'], $p['filter']
        );
        // Порядок и фильтр курсора задаём сами, под ИМЕНЕМ ЭТОГО МЕТОДА: чужие
        // order/filter по тому же полю сломали бы движение курсора. Условие
        // курсора ПОСЛЕДНИМ ключом, как в ядре (b24.php, cursorPageParams): заглушки
        // портала в наборах шаблона ищут курсор последним ключом (ревью 23.09.2026,
        // dubli-3). Ключ курсора из фильтра вызывающего уже снят выше, поэтому
        // array_merge его не затирает и конфликта нет.
        $p[$imena['order']]  = [$idField => 'ASC'];
        $p[$imena['filter']] = array_merge($userFilter, ['>' . $idField => $lastId]);
        // start = -1 — прямое требование документации для обхода курсором
        // (settings/performance/huge-data.md): портал перестаёт считать total на
        // КАЖДОЙ странице. Подсчёт капает в общее время метода, а оно упирается
        // в лимит портала (limits.md) — на больших выгрузках обход падал целиком.
        $p['start'] = -1;

        $r = $call($method, $p);
        if (!is_array($r)) {
            throw new RuntimeException('fetchAllCompat ' . $method . ': ответ портала не разобран');
        }
        if (isset($r['error'])) {
            throw new RuntimeException('fetchAllCompat ' . $method . ': '
                . (string)($r['error_description'] ?? $r['error']));
        }

        // Форма ответа у методов разная: список бывает прямо в result, а бывает
        // внутри result.items (crm.item.*) или result.tasks (задачи).
        $result = $r['result'] ?? [];
        $batch = null;
        if (is_array($result)) {
            if (fetchAllCompatIsList($result)) $batch = $result;
            else {
                foreach (['items', 'tasks', 'result'] as $key) {
                    if (isset($result[$key]) && is_array($result[$key])) { $batch = $result[$key]; break; }
                }
            }
        }
        if ($batch === null) {
            throw new RuntimeException('fetchAllCompat ' . $method . ': в ответе нет списка — '
                . 'этот метод отдаёт данные иначе, разберите ответ сами');
        }
        if ($batch === []) break;

        // Курсор обязан двигаться СТРОГО ПОСТРОЧНО, а не по максимуму страницы.
        //
        // Проверка по максимуму пропускала два ответа портала, и оба молча
        // (K4-11, круг 4). Первый: портал не понял нашу сортировку (у
        // lists.element.get она называется ELEMENT_ORDER, у user.get задаётся
        // парой sort+order) и отдал набор по УБЫВАНИЮ — максимум страницы сразу
        // равен максимуму всего набора, следующий запрос приходит пустым, и всё,
        // что между, теряется навсегда. Второй: портал понял '>N' как '>=N' —
        // каждая страница повторяет последнюю запись предыдущей, максимум растёт,
        // и дубли уезжают вызывающему. Одно построчное условие ловит оба разом.
        // То же решение в ядре — www/api/b24.php, cursorPageLastId().
        $nextId = $lastId;
        foreach ($batch as $poz => $row) {
            $raw = is_array($row)
                ? ($row[$idField] ?? $row[strtoupper($idField)] ?? $row[strtolower($idField)] ?? null)
                : null;
            $godnyj = is_int($raw) ? $raw > 0 : (is_string($raw) && ctype_digit($raw) && (int)$raw > 0);
            if (!$godnyj) {
                throw new RuntimeException('fetchAllCompat ' . $method . ': у записи ' . (string)$poz
                    . ' нет положительного «' . $idField . '» — полноту выборки нельзя проверить');
            }
            $id = (int)$raw;
            if ($id <= $nextId) {
                throw new RuntimeException('fetchAllCompat ' . $method . ': курсор «' . $idField
                    . '» не двигается (' . $id . ' после ' . $nextId . ') — выборка неполна. '
                    . 'Портал вернул страницу не по возрастанию ' . $idField
                    . ' или повторил записи; проверьте имя поля идентификатора и имя параметра сортировки у этого метода');
            }
            $nextId = $id;
        }
        $lastId = $nextId;
        foreach ($batch as $row) $items[] = $row;

        if (count($batch) < 50) break;                    // неполная страница — конец списка
        if ($page === $maxPages - 1) {
            throw new RuntimeException('fetchAllCompat ' . $method . ': выборка НЕ полная — упёрлись в предел '
                . $maxPages . ' страниц. Сузьте фильтр или поднимите предел осознанно');
        }
    }
    return $items;
}
