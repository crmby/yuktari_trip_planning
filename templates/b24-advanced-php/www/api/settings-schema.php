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
 * НАСТРОЙКИ, СОБИРАЕМЫЕ ИЗ ОПИСАНИЯ.
 *
 * ЗАЧЕМ. Свой экран настроек написали вручную 13 проектов, а 14 из 30 до сих пор
 * держат выбор воронки или ответственного прямо в тексте программы — то есть
 * поменять их можно только новой выкладкой, силами разработчика. Каждый такой
 * экран заново решает одни и те же четыре задачи: нарисовать поля, проверить
 * ввод, не отдать секрет наружу, не затереть сохранённое пустым полем. Ошибка
 * в любой из них тихая: приложение работает, но с чужим значением.
 *
 * КАК ПОЛЬЗОВАТЬСЯ. Проект объявляет свои настройки СПИСКОМ — здесь, в
 * settingsSchema(). Форма, проверка ввода и сохранение строятся по этому списку
 * сами; фронт получает описание вместе со значениями и рисует поля.
 *
 * ТИПОВ РОВНО ШЕСТЬ, и список закрыт намеренно. Конструктора форм (условия
 * показа, вложенные группы, проверки в виде кода) здесь не будет: он превращает
 * настройки в отдельный язык, который потом некому сопровождать.
 *
 *   text       — короткая строка
 *   multiline  — текст в несколько строк
 *   number     — целое число (min/max обязательны — см. ниже про пустое поле)
 *   bool       — да/нет
 *   select     — выбор одного из значений (options: код => название)
 *   secret     — ключ, пароль, токен: наружу НИКОГДА не отдаётся
 *
 * ТРИ ПРАВИЛА, НА КОТОРЫХ УЖЕ ОБЖИГАЛИСЬ:
 *
 * 1. ПУСТОЕ ПОЛЕ НЕ ЗАТИРАЕТ СОХРАНЁННОЕ. Человек стирает содержимое, жмёт
 *    «Сохранить» — и число превращается в 0, а 0 подтягивается к минимуму.
 *    Так интервал опроса упал с 30 минут до 5 и порт начал получать в шесть раз
 *    больше запросов. Пустая строка означает «не меняй это поле». Чтобы
 *    ОЧИСТИТЬ текст осознанно, фронт шлёт null.
 * 2. СЕКРЕТ НЕ ВОЗВРАЩАЕТСЯ. В ответе на чтение вместо значения — признак
 *    «задано / не задано». Иначе ключ уезжает в разметку страницы и в журнал
 *    браузера, откуда его достанет любой, кто откроет вкладку разработчика.
 * 3. СЛУЖЕБНЫЕ КЛЮЧИ НЕДОСТУПНЫ. Адрес приложения, признак установки и всё
 *    остальное служебное живёт в другом файле состояния, но объявить настройку
 *    с таким именем — значит однажды получить её в том же массиве. Объявление
 *    зарезервированного имени роняет приложение СРАЗУ, на первом же обращении,
 *    а не молча когда-нибудь потом.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';

// КОД ПРОЕКТА — В СВОЁМ ФАЙЛЕ, А НЕ ЗДЕСЬ (находка ревью 23.09.2026, modulnost-2).
// Прежде настройки проекта вписывались прямо в settingsSchema() этого файла: первая
// же настройка делала его «правленным под себя», и сверка ядра больше никогда не
// привозила сюда починок — ни проверки ввода, ни защиты секретов. Теперь тело
// проекта живёт в www/api/project-hooks.php: в шаблоне этого файла нет, поэтому
// сверка ядра его не трогает, а у функций там СВОИ имена — двойного объявления с
// ядром не бывает. Нет файла — поведение прежнее (пустой список настроек).
// Подключение — внутри функции: на верхнем уровне библиотеки допустимы только
// объявления, иначе гейт перестаёт считать файл библиотекой.
function settingsProjectHooksLoad(): void {
    if (is_file(__DIR__ . '/project-hooks.php')) require_once __DIR__ . '/project-hooks.php';
}

/** Типы, которые понимает форма. Список закрытый. */
const SETTINGS_TYPES = ['text', 'multiline', 'number', 'bool', 'select', 'secret'];

/**
 * Имена, которые настройкой быть не могут: их пишет само приложение.
 * Держим здесь, а не «в голове»: список нужен и проверке объявления, и защите
 * от присланного ключа.
 */
const SETTINGS_RESERVED = [
    'app_url', 'installed', 'install_generation', 'tokens', 'access_token',
    'refresh_token', 'application_token', 'member_id', 'mode', 'owner_id',
];

/** Потолки на значение: без них в настройку кладут мегабайт текста. */
const SETTINGS_TEXT_MAX = 500;
const SETTINGS_MULTILINE_MAX = 5000;
const SETTINGS_SECRET_MAX = 500;

/**
 * ОПИСАНИЕ НАСТРОЕК ЭТОГО ПРИЛОЖЕНИЯ. Сюда НЕ пишут: проект объявляет список в
 * projectSettingsSchema() своего файла www/api/project-hooks.php (образец —
 * docs/BLOCKS.md, раздел «Настройки приложения»), а эта функция его отдаёт.
 *
 * Поля описания:
 *   key      обязательное имя (латиница, цифры, подчёркивание)
 *   title    как называется для человека
 *   type     один из SETTINGS_TYPES
 *   default  значение, пока ничего не сохранено
 *   hint     подпись под полем: что изменится, если это поменять
 *   min/max  для number — границы (обязательны)
 *   options  для select — массив «код => название»
 *
 * @return array<int, array<string, mixed>>
 */
function settingsSchema(): array {
    // ПУСТО, ПОКА ПРОЕКТ НЕ ОБЪЯВИЛ СВОЁ. Раньше здесь стоял образец «Приветствие
    // на главной»: он сохранялся, но его НИКТО не читал — ни один экран не выводил
    // этот текст. Настройка без последствий хуже её отсутствия (убрано 26.08.2026
    // по замечанию владельца проекта). Образцы всех шести типов полей лежат в
    // docs/BLOCKS.md, а каждое объявленное поле обязано где-то читаться.
    settingsProjectHooksLoad();
    if (!function_exists('projectSettingsSchema')) return [];
    $schema = projectSettingsSchema();
    if (!is_array($schema)) {
        throw new LogicException('projectSettingsSchema() обязана вернуть массив описаний полей');
    }
    return $schema;
}

/**
 * Проверить само ОПИСАНИЕ. Зовётся при каждом обращении к настройкам: ошибка в
 * описании — это ошибка программиста, и она должна всплыть на первом же запуске,
 * а не превратиться в поле, которое молча ничего не сохраняет.
 *
 * @return array<string, array<string, mixed>> ключ => описание
 */
function settingsSchemaMap(): array {
    static $map = null;
    if ($map !== null) return $map;

    $map = [];
    foreach (settingsSchema() as $i => $field) {
        $where = 'настройка №' . ($i + 1);
        if (!is_array($field)) {
            throw new RuntimeException('settingsSchema: ' . $where . ' — описание должно быть массивом');
        }
        $key = (string)($field['key'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/i', $key)) {
            throw new RuntimeException('settingsSchema: ' . $where . ' — имя «' . $key
                . '» не годится: латиница, цифры и подчёркивание, до 40 знаков');
        }
        if (isset($map[$key])) {
            throw new RuntimeException('settingsSchema: имя «' . $key . '» объявлено дважды');
        }
        if (in_array($key, SETTINGS_RESERVED, true)) {
            throw new RuntimeException('settingsSchema: имя «' . $key . '» служебное — его пишет само '
                . 'приложение (адрес, токены, признак установки). Возьмите другое');
        }
        $type = (string)($field['type'] ?? '');
        if (!in_array($type, SETTINGS_TYPES, true)) {
            throw new RuntimeException('settingsSchema: у настройки «' . $key . '» тип «' . $type
                . '» неизвестен. Есть только: ' . implode(', ', SETTINGS_TYPES));
        }
        if ($type === 'select') {
            $options = $field['options'] ?? null;
            if (!is_array($options) || $options === []) {
                throw new RuntimeException('settingsSchema: у настройки «' . $key
                    . '» тип «select», но список значений (options) пуст');
            }
        }
        if ($type === 'number') {
            // Границы обязательны именно потому, что пустое поле не затирает
            // значение: без границ «сколько угодно» превращается в ноль или в
            // миллион, и узнаём мы об этом по счёту от хостинга.
            if (!isset($field['min']) || !isset($field['max'])) {
                throw new RuntimeException('settingsSchema: у числовой настройки «' . $key
                    . '» обязаны быть границы min и max');
            }
            if ((int)$field['min'] > (int)$field['max']) {
                throw new RuntimeException('settingsSchema: у настройки «' . $key . '» min больше max');
            }
        }
        $map[$key] = $field;
    }
    return $map;
}

/** Значения по умолчанию из описания — то, что действует, пока ничего не сохранено. */
function settingsDefaults(): array {
    $out = [];
    foreach (settingsSchemaMap() as $key => $field) {
        $out[$key] = $field['default'] ?? settingsEmptyValue((string)$field['type']);
    }
    return $out;
}

function settingsEmptyValue(string $type) {
    if ($type === 'bool') return false;
    if ($type === 'number') return 0;
    return '';
}

/**
 * Значения с подставленными умолчаниями. Чужие ключи, оставшиеся в файле от
 * прежних версий приложения, НЕ выбрасываем из хранилища — но и наружу их не
 * отдаём: настройка, снятая из описания, может понадобиться при откате.
 */
function settingsEffective(): array {
    $saved = loadSettings();
    $out = [];
    foreach (settingsSchemaMap() as $key => $field) {
        $out[$key] = array_key_exists($key, $saved)
            ? $saved[$key]
            : ($field['default'] ?? settingsEmptyValue((string)$field['type']));
    }
    return $out;
}

/**
 * Что отдать фронту: описание + значения, но БЕЗ секретов.
 *
 * Секрет заменяется признаком «задано / не задано». Этого достаточно, чтобы
 * нарисовать поле («ключ сохранён, введите новый, чтобы заменить») и невозможно
 * прочитать сам ключ из разметки страницы.
 */
function settingsForClient(): array {
    $values = settingsEffective();
    $fields = [];
    foreach (settingsSchemaMap() as $key => $field) {
        $type = (string)$field['type'];
        $item = [
            'key'   => $key,
            'title' => (string)($field['title'] ?? $key),
            'type'  => $type,
            'hint'  => (string)($field['hint'] ?? ''),
        ];
        if ($type === 'select')  $item['options'] = $field['options'];
        if ($type === 'number') { $item['min'] = (int)$field['min']; $item['max'] = (int)$field['max']; }
        if ($type === 'secret') {
            $item['isSet'] = trim((string)($values[$key] ?? '')) !== '';
            $fields[] = $item;
            continue;                        // значение секрета наружу не уходит НИКОГДА
        }
        $item['value'] = $values[$key];
        $fields[] = $item;
    }
    return $fields;
}

/**
 * Проверить присланное по описанию.
 *
 * @param array $incoming что прислал фронт: ключ => значение
 * @return array{values: array, errors: array<string,string>} values — только то,
 *         что реально надо записать (пустые поля сюда не попадают).
 */
function settingsValidate(array $incoming): array {
    $map = settingsSchemaMap();
    $values = [];
    $errors = [];

    foreach ($incoming as $key => $raw) {
        $key = (string)$key;
        if (!isset($map[$key])) {
            // Служебное имя называем отдельно: «неизвестная настройка» на
            // app_url звучит как опечатка, хотя это попытка сменить адрес.
            $errors[$key] = in_array($key, SETTINGS_RESERVED, true)
                ? 'Это служебное поле приложения, менять его через настройки нельзя'
                : 'Такой настройки нет';
            continue;
        }
        $field = $map[$key];
        $type = (string)$field['type'];
        $title = (string)($field['title'] ?? $key);

        // ЯВНАЯ ОЧИСТКА. null означает «сделать пустым» — в отличие от пустой
        // строки, которая значит «не трогай».
        if ($raw === null) {
            if ($type === 'bool' || $type === 'number') {
                $errors[$key] = $title . ': это поле нельзя очистить, у него всегда есть значение';
                continue;
            }
            if ($type === 'select') {
                $errors[$key] = $title . ': выберите одно из значений';
                continue;
            }
            $values[$key] = '';
            continue;
        }

        if ($type === 'bool') {
            if (!is_bool($raw)) { $errors[$key] = $title . ': ожидается «да» или «нет»'; continue; }
            $values[$key] = $raw;
            continue;
        }

        if ($type === 'number') {
            // Пустое поле — «не менять». Именно здесь ломался интервал опроса.
            if ($raw === '' ) continue;
            if (is_string($raw) && !preg_match('/^-?\d+$/', trim($raw))) {
                $errors[$key] = $title . ': нужно целое число';
                continue;
            }
            if (!is_int($raw) && !is_string($raw)) {
                $errors[$key] = $title . ': нужно целое число';
                continue;
            }
            $n = (int)$raw;
            $min = (int)$field['min']; $max = (int)$field['max'];
            if ($n < $min || $n > $max) {
                $errors[$key] = $title . ': допустимо от ' . $min . ' до ' . $max;
                continue;
            }
            $values[$key] = $n;
            continue;
        }

        if (!is_string($raw)) { $errors[$key] = $title . ': ожидается текст'; continue; }

        if ($type === 'select') {
            if (!array_key_exists($raw, (array)$field['options'])) {
                $errors[$key] = $title . ': такого значения нет в списке';
                continue;
            }
            $values[$key] = $raw;
            continue;
        }

        if ($type === 'secret') {
            // Пустая строка от секрета приходит на КАЖДОМ сохранении формы:
            // поле показано пустым, потому что значение наружу не отдаётся.
            // Считать это «стереть ключ» — значит терять доступ при правке
            // любой соседней настройки.
            if (trim($raw) === '') continue;
            if (settingsLen($raw) > SETTINGS_SECRET_MAX) {
                $errors[$key] = $title . ': длиннее ' . SETTINGS_SECRET_MAX . ' знаков';
                continue;
            }
            $values[$key] = $raw;
            continue;
        }

        // text / multiline
        if ($raw === '') continue;                       // пустое — «не менять»
        $limit = $type === 'multiline' ? SETTINGS_MULTILINE_MAX : SETTINGS_TEXT_MAX;
        if (settingsLen($raw) > $limit) {
            $errors[$key] = $title . ': длиннее ' . $limit . ' знаков';
            continue;
        }
        $values[$key] = $raw;
    }

    return ['values' => $values, 'errors' => $errors];
}

function settingsLen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/**
 * Имена настроек, чьи значения нельзя показывать и писать в журнал.
 * Нужен журналу: в аудит уходит СПИСОК изменённых полей, а не их значения, —
 * но список секретов пригодится любому, кто соберётся показать «что поменялось».
 */
function settingsSecretKeys(): array {
    $out = [];
    foreach (settingsSchemaMap() as $key => $field) {
        if (($field['type'] ?? '') === 'secret') $out[] = $key;
    }
    return $out;
}
