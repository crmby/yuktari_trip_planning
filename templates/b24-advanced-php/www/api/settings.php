<?php
declare(strict_types=1);
/**
 * api/settings.php — настройки приложения. И одновременно ОБРАЗЕЦ эндпоинта:
 * новый эндпоинт делается копией этого файла, а не с чистого листа.
 *
 * GET  → {ok:true, settings:{...}, me:{uid,role}}
 * POST → {ok:true, settings:{...}}   тело: {"settings":{...}}, только admin
 *
 * Обрати внимание, чего здесь НЕТ: проверки метода, разбора тела, проверки
 * пропуска и роли, заголовков, ограничения частоты, форматирования ошибок.
 * Всё это делает apiBoot() — один вызов первой строкой. Пиши только то, что
 * относится к делу.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/settings-schema.php';

installFailureHandler(true);           // непойманное исключение → человеческий JSON, а не белый экран

$ctx = apiBoot(['methods' => ['GET', 'POST'], 'role' => 'view']);

if ($ctx['method'] === 'GET') {
    apiOk([
        // `fields` — описание настроек: по нему фронт рисует форму, ничего не
        // зная о конкретном проекте. Значения секретов сюда не попадают, только
        // признак «задано».
        'fields'   => settingsForClient(),
        // `settings` оставлен для совместимости: на него смотрит код, написанный
        // до появления описания. Секреты из него вычищены — раньше их там просто
        // не было, и появиться они не должны.
        'settings' => array_diff_key(settingsEffective(), array_flip(settingsSecretKeys())),
        'me'       => ['uid' => $ctx['uid'], 'role' => $ctx['role']],
    ]);
}

// Менять настройки может только администратор приложения. Проверка ролью, а не
// «фронт не покажет кнопку»: фронт — это подсказка, а не защита.
if (!roleAtLeast($ctx['role'], 'admin')) {
    apiFail('Менять настройки может только администратор приложения', 403, 'forbidden');
}

/**
 * Что можно менять — определяет ОПИСАНИЕ настроек (api/settings-schema.php).
 * Список закрытый: имя, которого нет в описании, отвергается, а служебные поля
 * приложения (адрес, токены, признак установки) не объявляются настройкой в
 * принципе — попытка объявить такое имя роняет приложение на первом запросе.
 *
 * Проверка значений тоже идёт по описанию: тип, границы, длина, список значений.
 * Раньше её писали руками под каждый ключ, и на каждом новом ключе о ней забывали.
 */
$body = apiBody();
$incoming = $body['settings'] ?? null;
if (!is_array($incoming)) apiFail('Не передан объект «settings»', 400, 'bad_field');

$checked = settingsValidate($incoming);
if ($checked['errors'] !== []) {
    // Все ошибки сразу, а не первую: иначе человек с формой из восьми полей
    // сохраняет её восемь раз, узнавая о следующей ошибке после исправления
    // предыдущей.
    // Четвёртый аргумент apiFail — СТРОКА для журнала, пятый — данные ответу.
    // Массив на месте строки роняет запрос с TypeError, и человек вместо
    // «поле заполнено неверно» получает пустой сбой 500.
    apiFail(implode('; ', $checked['errors']), 400, 'bad_field',
        'поля: ' . implode(', ', array_keys($checked['errors'])),
        ['fields' => $checked['errors']]);
}

$toSave = $checked['values'];
if ($toSave === []) {
    // Прислали одни пустые поля — это НЕ ошибка: форма отдаёт все поля разом, и
    // пустой секрет означает «оставить прежний ключ». Отвечаем текущим состоянием.
    apiOk(['settings' => array_diff_key(settingsEffective(), array_flip(settingsSecretKeys())),
           'fields'   => settingsForClient(), 'saved' => []]);
}

updateSettings(static function (array $prev) use ($toSave): array {
    // Сливаем с прежними, а не заменяем целиком: два открытых окна приложения
    // иначе затирают правки друг друга.
    return array_merge($prev, $toSave);
});

// В аудит — ИМЕНА полей, без значений: среди них бывают ключи и пароли.
auditLog('settings.update', ['keys' => array_keys($toSave)], $ctx['uid']);
apiOk([
    'settings' => array_diff_key(settingsEffective(), array_flip(settingsSecretKeys())),
    'fields'   => settingsForClient(),
    'saved'    => array_keys($toSave),
]);
