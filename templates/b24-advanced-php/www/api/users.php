<?php
declare(strict_types=1);
/**
 * api/users.php — поиск сотрудников И ОТДЕЛОВ для выдачи доступа (admin-only).
 *
 * GET ?q=Ива → {ok:true, users:[{id,name,post,dept,fired}], depts:[{id,name,path,size}],
 *               matched:N, limited:bool}
 *
 * ПОЧЕМУ ОТДЕЛЫ ТОЖЕ. Доступ почти никогда не выдают поимённо: «весь отдел
 * продаж и двое из бухгалтерии». Пока в поиске были только люди, владелец
 * добавлял отдел по одному человеку, забывал новичков — и через месяц шёл к
 * разработчику с вопросом «почему у нового сотрудника нет доступа».
 *
 * Браузер не ходит в BX24 напрямую: вход, роль, частота и единый ответ держит
 * apiBoot(), а полный список совпадений собирает готовый B24-клиент. Наружу
 * отдаём минимум: ID, имя, должность и отдел — без почты и телефонов.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/cache.php';   // справочник отделов помним между запросами

installFailureHandler(true);

// Поиск запускается после debounce, но остаётся тяжёлым GET: один человек не
// должен занять все воркеры и REST-квоту портала быстрым вводом.
$ctx = apiBoot(['methods' => ['GET'], 'role' => 'admin', 'rate' => [30, 60]]);

const USER_SEARCH_RESULT_LIMIT = 8;
const USER_SEARCH_HARD_LIMIT = 500;
const DEPT_SEARCH_RESULT_LIMIT = 6;
const DEPT_HARD_LIMIT = 1000;

$query = apiStr($_GET, 'q', ['max' => 100]);
if (preg_match('//u', $query) !== 1) {
    apiFail('Запрос должен быть текстом в UTF-8', 400, 'bad_field');
}
$queryLength = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
if ($queryLength < 2) {
    apiFail('Введите не меньше двух символов', 400, 'bad_field');
}

// Уволенных ищем тоже — но помечаем. Иначе доступ месяцами висит на человеке,
// которого в компании нет, и в списке доступа он выглядит обычной строкой.
//
// Флаг НЕОБЯЗАТЕЛЬНЫЙ. Пока он был обязательным полем, любой ввод в «Кому дать
// доступ» получал 400 «Не передано поле «fired»» — фронт его не передавал, и
// выдать доступ было нельзя вообще никому (находка ревью 09.08.2026). Проект,
// которому уволенные в выдаче мешают, просто не передаёт флаг.
$withFired = apiStr($_GET, 'fired', ['required' => false, 'default' => '', 'max' => 1]) === '1';

try {
    // Официальный user.get поддерживает NAME_SEARCH, ACTIVE и USER_TYPE в
    // FILTER. fetchAllBatched() сверяет total, размер каждой страницы и
    // уникальность ID. Если совпадений больше hard-limit или хоть одна страница
    // потеряна, он бросает исключение: partial-выборка не попадёт в интерфейс.
    $filter = ['NAME_SEARCH' => $query, 'USER_TYPE' => 'employee'];
    if (!$withFired) $filter['ACTIVE'] = true;
    // IMAGE_RESIZE просим сразу: без него портал отдаёт ссылку на исходный
    // файл — это мегабайты на каждого сотрудника в списке выбора.
    // 150×150 хватает аватарке с запасом (api-reference/user/user-get.md).
    $rows = b24()->fetchAllBatched('user.get', ['FILTER' => $filter, 'IMAGE_RESIZE' => 'small'],
        USER_SEARCH_HARD_LIMIT, 'ID');
} catch (Throwable $e) {
    // «Совпадений больше предела» — это не сбой приложения, а слишком широкий
    // запрос: человеку надо дописать букву, а не звать разработчика. Один и тот
    // же ответ 500 «внутренняя ошибка» на оба случая заставлял читать журнал
    // там, где хватало подсказки на экране.
    if (strpos($e->getMessage(), 'больше предела') !== false) {
        apiFail('Слишком много совпадений — уточните запрос', 400, 'bad_field');
    }
    apiFailFromThrowable(
        $e,
        'Не удалось завершить поиск. Уточните запрос или повторите позже.'
    );
}

// Справочник отделов читаем целиком: он маленький (сотни записей на большой
// компании) и нужен и для поиска по названию, и чтобы подписать сотруднику его
// отдел. Метод department.get официальный, пагинация — тем же готовым клиентом.
//
// Помним справочник четверть часа: поиск идёт по каждому набранному слову, и
// без памяти полный department.get уходил в портал на КАЖДЫЙ ввод — человек
// ждал, а корзина запросов портала тратилась на один и тот же неизменный
// ответ. Отделы заводят редко, четверть часа задержки здесь незаметна.
$deptById = [];
// Признак «справочник отделов прочитан». Без него отказ портала приходил на
// экран как ПУСТОЙ список отделов — то есть ровно так же, как «отдела с таким
// названием нет». Админ читал «ничего не нашлось», делал вывод «отдела в поиске
// нет» и выдавал доступ поимённо, забывая новичков (находка ревью K1-07).
$deptsAvailable = true;
try {
    $deptRows = cacheRemember('departments', 900, static fn(): array
        => b24()->fetchAllBatched('department.get', [], DEPT_HARD_LIMIT, 'ID'));
    foreach ($deptRows as $d) {
        $id = (int)($d['ID'] ?? 0);
        if ($id <= 0) continue;
        $deptById[$id] = [
            'id'     => $id,
            'name'   => trim((string)($d['NAME'] ?? '')),
            'parent' => (int)($d['PARENT'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    // Отделы — дополнение к поиску людей, а не его условие: если справочник не
    // отдали (нет права department, портал ответил отказом), поиск сотрудников
    // должен продолжать работать. Молча не глотаем — пишем в журнал.
    //
    // Второй аргумент — слаг события (по нему ищут в журнале и группируют), а
    // человеческая фраза идёт в 'msg': экран журнала рисует именно её, поле ctx
    // на нём не показывается вовсе. Свой maskSecrets здесь не нужен — appLog
    // маскирует и ctx, и msg сам.
    appLog('warn', 'department.get.failed', maskSecrets(['ошибка' => $e->getMessage()]),
        ['msg' => 'справочник отделов не прочитан, ищем только людей']);
    $deptById = [];
    $deptsAvailable = false;
}

/** Путь отдела «Компания → Продажи → Опт»: без него одноимённые отделы неразличимы. */
$deptPath = static function (int $id) use ($deptById): string {
    $names = [];
    $guard = 0;
    while ($id > 0 && isset($deptById[$id]) && $guard++ < 20) {
        array_unshift($names, $deptById[$id]['name']);
        $id = $deptById[$id]['parent'];
    }
    return implode(' → ', $names);
};

/**
 * Ссылка на фото сотрудника — или пустая строка.
 *
 * Портал отдаёт `PERSONAL_PHOTO` ссылкой (при IMAGE_RESIZE — на уменьшенную
 * копию), но у части сотрудников фото нет вовсе, а у старых порталов в поле
 * попадается идентификатор файла. Всё, что не похоже на https-ссылку, к экрану
 * не допускаем.
 */
function userPhotoUrl($raw): string {
    $url = is_scalar($raw) ? trim((string)$raw) : '';
    if ($url === '' || strlen($url) > 500) return '';
    if (stripos($url, 'https://') !== 0) return '';
    return $url;
}

$users = [];
foreach (array_slice($rows, 0, USER_SEARCH_RESULT_LIMIT) as $row) {
    $id = (int)($row['ID'] ?? 0); // положительный ID уже проверен fetchAllBatched()
    $name = trim((string)($row['LAST_NAME'] ?? '') . ' ' . (string)($row['NAME'] ?? ''));
    if ($name === '') $name = 'сотрудник №' . $id;
    $depts = $row['UF_DEPARTMENT'] ?? [];
    $firstDept = is_array($depts) && $depts !== [] ? (int)$depts[0] : 0;
    $users[] = [
        'id'    => $id,
        'name'  => $name,
        'post'  => trim((string)($row['WORK_POSITION'] ?? '')),
        // Фото — только https-ссылка портала. Всё остальное (data:, javascript:,
        // http) отбрасываем здесь, а не на экране: адрес приходит из ответа
        // портала, но подставляется прямо в разметку.
        'photo' => userPhotoUrl($row['PERSONAL_PHOTO'] ?? null),
        'dept'  => $firstDept > 0 ? $deptPath($firstDept) : '',
        'fired' => !((bool)($row['ACTIVE'] ?? true)),
    ];
}

// Отделы фильтруем по названию на своей стороне: department.get не умеет
// искать по подстроке, а гонять справочник через REST на каждый символ нельзя.
$lower = static function (string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
};
$needle = $lower($query);
$depts = [];
foreach ($deptById as $d) {
    $name = $lower($d['name']);
    if ($name === '' || strpos($name, $needle) === false) continue;
    $depts[] = [
        'id'   => $d['id'],
        'name' => $d['name'],
        'path' => $deptPath($d['parent']),
    ];
    if (count($depts) >= DEPT_SEARCH_RESULT_LIMIT) break;
}

// depts_available=false — «поиск по отделам сейчас не работает», а не «совпадений
// нет». Поле аддитивное: старый фронт его просто не заметит, новый показывает
// причину вместо молчаливого «никого и ничего не нашлось».
apiOk([
    'users'           => $users,
    'depts'           => $depts,
    'depts_available' => $deptsAvailable,
    'depts_note'      => $deptsAvailable ? '' : 'Поиск по отделам сейчас недоступен — портал не отдал справочник отделов. Ищем только людей.',
    'matched'         => count($rows),
    'limited'         => count($rows) > USER_SEARCH_RESULT_LIMIT,
]);
