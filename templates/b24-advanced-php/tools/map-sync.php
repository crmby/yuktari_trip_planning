<?php
declare(strict_types=1);
/**
 * ИНВЕНТАРЬ ДЛЯ `docs/MAP.md` — собирается машиной, сверяется прогоном.
 *
 * ЗАЧЕМ. Карта устаревает не потому, что её лень писать, а потому, что ничто не
 * заставляет её обновить: код уехал, текст остался, и агент уверенно зовёт то,
 * чего уже нет. Хуже: устаревшая карта выглядит достоверной ровно так же, как
 * свежая. Поэтому карта разделена на две части — «почему так» и потоки пишет
 * человек, а ИНВЕНТАРЬ (что за файлы, что каждый даёт наружу, куда приходит
 * запрос, где лежит состояние) собирает этот скрипт в блок между маркерами.
 *
 * КАК ДЕРЖИТСЯ АКТУАЛЬНОЙ. `--check` сравнивает блок с кодом и возвращает 3, если
 * они разошлись. Проверка живёт в наборе испытаний, а набор — гейт деплоя: завёл
 * новый эндпоинт и не пересобрал карту — прогон красный, выкатить нельзя. Ручную
 * часть карты скрипт не трогает никогда.
 *
 * ЗАПУСК:
 *   php tools/map-sync.php           пересобрать инвентарь в docs/MAP.md
 *   php tools/map-sync.php --check   только сказать, разошлось ли (код 3 — да)
 *
 * Скрипт едет в проекты вместе с ядром и работает по своему каталогу, поэтому
 * настройки путей ему не нужны.
 */

const MAP_MARKER_START = '<!-- MAP-INVENTORY:START -->';
const MAP_MARKER_END   = '<!-- MAP-INVENTORY:END -->';

$koren = dirname(__DIR__);
$karta = $koren . '/docs/MAP.md';
$tolkoProverka = in_array('--check', $argv, true);

if (!is_dir($koren . '/www')) {
    fwrite(STDERR, "Не найден www/ в $koren — это не корень bx-app-проекта\n");
    exit(1);
}

// Признак «точка входа» один на весь проект и живёт в предикатах гейта: своя
// копия логики разошлась бы с проверками, и карта расходилась бы с прогоном.
// Сам gate-lib.php — только объявления функций, при подключении ничего не делает.
$predikaty = $koren . '/tests/gate-lib.php';
if (!is_file($predikaty)) {
    fwrite(STDERR, "Нет tests/gate-lib.php — без предикатов гейта вход от библиотеки не отличить; подтяните ядро из шаблона\n");
    exit(1);
}
require_once $predikaty;

/** Первая содержательная строка docblock — назначение файла одной фразой. */
function mapNaznachenie(string $src): string {
    // ШАПКА ФАЙЛА, А НЕ ПЕРВЫЙ ВСТРЕЧНЫЙ docblock. Здесь стоял поиск первого
    // `/** … */` по всему исходнику, и у файлов с шапкой из строчных
    // комментариев он находил комментарий к ВНУТРЕННЕЙ КОНСТАНТЕ: карта
    // объявляла назначением cron-воркера фразу «Как часто спрашиваем портал про
    // тех, у кого есть живой пропуск», а главный вход шёл «(без описания в
    // docblock)» (находка ревью K4-36 09.08.2026). Читаем ровно тот блок
    // комментариев, которым файл НАЧИНАЕТСЯ, — в каком бы виде он ни был написан.
    $blok = [];
    $vid = '';
    foreach (preg_split('~\R~u', $src) as $stroka) {
        $t = trim($stroka);
        if ($vid === '') {
            // До шапки законны только открывающий тег, пустые строки и declare.
            if ($t === '' || $t === '<' . '?php' || strpos($t, 'declare(') === 0) continue;
            if (strpos($t, '/*') === 0) $vid = 'blok';
            elseif (strpos($t, '//') === 0) $vid = 'stroki';
            else break;                      // сразу код — шапки у файла нет
        }
        if ($vid === 'stroki') {
            if (strpos($t, '//') !== 0) break;
            $blok[] = ltrim(substr($t, 2));
            continue;
        }
        $blok[] = $t;
        if (strpos($t, '*/') !== false) break;
    }
    foreach ($blok as $line) {
        $line = trim($line, " \t*/");
        // Рамки из псевдографики — украшение, а не назначение.
        $line = trim($line, " \t║╔╗╚╝═╠╣│┌┐└┘─—-");
        if ($line === '' || !preg_match('~\p{L}~u', $line)) continue;
        // «api/users.php — поиск сотрудников»: берём хвост после тире.
        if (preg_match('~^[a-z0-9/._-]+\.php\s+[—-]\s+(.+)$~ui', $line, $mm)) $line = $mm[1];
        return rtrim($line, '.');
    }
    return '';
}

/** Функции, объявленные на верхнем уровне файла. */
function mapFunkcii(string $src): array {
    $out = [];
    foreach (preg_split('~\R~u', $src) as $line) {
        if (preg_match('~^function\s+([A-Za-z0-9_]+)\s*\(~', $line, $m)) $out[] = $m[1];
    }
    sort($out);
    return $out;
}

/**
 * Есть ли у файла забор прямого HTTP (404 на запрос ИМЕННО этого файла).
 *
 * Это ОТДЕЛЬНОЕ свойство файла, а не граница «вход / библиотека»: оно только
 * заполняет колонку в таблице библиотек. Границей оно было до 09.08.2026 и это
 * был вывернутый признак — новый ОТКРЫТЫЙ файл, у которого нет ни забора, ни
 * проверки прав, объявлялся точкой входа, и карта узаконивала дыру вместо того,
 * чтобы её показать (проверено исполнением: подсаженный api/probe-open.php
 * уехал в раздел точек входа). Теперь такой файл попадает в библиотеки со
 * словом **НЕТ** в колонке — то есть виден как дыра.
 */
function mapEstZabor(string $src): bool {
    return strpos($src, "SCRIPT_FILENAME") !== false && strpos($src, 'http_response_code(404)') !== false;
}

/**
 * Точка входа — файл, который САМ аутентифицирует запрос при загрузке.
 *
 * Признак положительный и общий с проверками: `gateTopLevelCalls()` даёт имена,
 * которые файл фактически зовёт при обращении к нему (включая раннюю защиту в
 * замыканиях `www/index.php`), и вход — тот, кто зовёт `apiBoot()` либо сверяет
 * токен приложения через `hash_equals()`. Объявление `apiBoot()` в `api/http.php`
 * и `hash_equals` внутри функций `b24.php`/`session.php` входом не считаются:
 * при загрузке файла они не исполняются.
 */
function mapEstVhod(string $put): bool {
    foreach (gateTopLevelCalls($put) as $imya) {
        if (strcasecmp($imya, 'apiBoot') === 0 || strcasecmp($imya, 'hash_equals') === 0) return true;
    }
    return false;
}

$vhody = [];
$biblioteki = [];
$fon = [];
foreach (gatePhpSourceFilesUnder($koren . '/www') as $put) {
    if (basename($put) === 'release.php') continue;   // его создаёт деплой
    $otn = gateRelativePath($koren, $put);
    $src = (string)file_get_contents($put);
    $zapis = [
        'файл'       => $otn,
        'назначение' => mapNaznachenie($src),
        'функции'    => mapFunkcii($src),
        'забор'      => mapEstZabor($src),
    ];
    if (strpos($otn, 'www/bin/') === 0) $fon[] = $zapis;      // фон, снаружи не зовут
    elseif (mapEstVhod($put))           $vhody[] = $zapis;    // сам проверяет запрос
    else                                $biblioteki[] = $zapis;
}
foreach ([&$vhody, &$biblioteki, &$fon] as &$spisok) {
    usort($spisok, static fn(array $a, array $b): int => strcmp($a['файл'], $b['файл']));
}
unset($spisok);

// Файлы состояния объявлены в env.php одной колодой — читаем оттуда, а не списком руками.
$sostoyanie = [];
$envSrc = (string)@file_get_contents($koren . '/www/env.php');
if (preg_match_all('~define\(\s*\'([A-Z0-9_]*FILE)\'\s*,\s*(.+?)\);~', $envSrc, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
        $znach = trim($m[2]);
        if (preg_match('~[\'"]([A-Za-z0-9._/-]+\.php)[\'"]~', $znach, $f)) $znach = $f[1];
        $sostoyanie[] = [$m[1], $znach];
    }
}

$fronty = [];
foreach (glob($koren . '/www/js/*.js') ?: [] as $put) {
    $fronty[] = [basename($put), substr_count((string)file_get_contents($put), "\n") + 1];
}

// Отпечаток: по нему видно, той ли версии кода соответствует карта.
$hashi = [];
foreach (array_merge($vhody, $biblioteki, $fon) as $z) {
    $hashi[] = $z['файл'] . ':' . substr(hash_file('sha256', $koren . '/' . $z['файл']), 0, 12);
}
$otpechatok = substr(hash('sha256', implode('|', $hashi)), 0, 16);

$L = [];
$L[] = MAP_MARKER_START;
$L[] = '';
$L[] = '## Инвентарь — собирается машиной';
$L[] = '';
$L[] = '> Этот раздел пересобирается `php tools/map-sync.php` и сверяется прогоном испытаний';
$L[] = '> (`--check` краснеет, если код разошёлся с картой). **Руками не править** — правка';
$L[] = '> исчезнет при следующей сборке. Всё, что объясняет «почему так» и как связаны потоки,';
$L[] = '> пишется в остальных разделах карты вручную и скриптом не трогается.';
$L[] = '';
$L[] = '### Куда приходит запрос снаружи';
$L[] = '';
$L[] = 'Точка входа сама разбирает запрос ПРИ ЗАГРУЗКЕ: зовёт `apiBoot()` либо сверяет токен';
$L[] = 'приложения. Всё остальное под веб-корнем — библиотеки, и на прямой HTTP они обязаны';
$L[] = 'отвечать 404: деплой публикует каталог целиком. Поэтому `НЕТ` в колонке «Забор прямого';
$L[] = 'HTTP» ниже — это не пометка, а открытый файл: он отвечает по прямой ссылке и никого не';
$L[] = 'спрашивает.';
$L[] = '';
foreach ($vhody as $z) {
    $L[] = '- **`' . $z['файл'] . '`** — ' . ($z['назначение'] !== '' ? $z['назначение'] : '(без описания в docblock)');
}
$L[] = '';
$L[] = '### Фоновая работа';
$L[] = '';
foreach ($fon as $z) {
    $L[] = '- **`' . $z['файл'] . '`** — ' . ($z['назначение'] !== '' ? $z['назначение'] : '(без описания в docblock)');
}
$L[] = '';
$L[] = '### Библиотеки ядра — что даёт каждая';
$L[] = '';
$L[] = '| Файл | Забор прямого HTTP | Назначение |';
$L[] = '|---|---|---|';
foreach ($biblioteki as $z) {
    $L[] = '| `' . $z['файл'] . '` | ' . ($z['забор'] ? 'есть' : '**НЕТ**') . ' | '
        . ($z['назначение'] !== '' ? $z['назначение'] : '(без описания в docblock)') . ' |';
}
$L[] = '';
foreach ($biblioteki as $z) {
    if ($z['функции'] === []) continue;
    $L[] = '**`' . $z['файл'] . '`** · `' . implode('` · `', $z['функции']) . '`';
    $L[] = '';
}
$L[] = '### Состояние';
$L[] = '';
$L[] = 'Живёт в `DATA_ROOT`, только `.php` с префиксом-заглушкой: прямой HTTP к любому файлу';
$L[] = 'состояния обязан давать 404. Мутация — чтение-правка-запись под эксклюзивным замком';
$L[] = '(`storeUpdate`), а не «прочитал, потом записал».';
$L[] = '';
foreach ($sostoyanie as [$imya, $znach]) {
    $L[] = '- `' . $imya . '` → `' . $znach . '`';
}
$L[] = '';
$L[] = '### Фронт';
$L[] = '';
foreach ($fronty as [$imya, $strok]) {
    $L[] = '- `www/js/' . $imya . '` — ' . $strok . ' строк';
}
$L[] = '';
$L[] = 'Отпечаток кода: `' . $otpechatok . '` · точек входа ' . count($vhody)
    . ', библиотек ' . count($biblioteki) . ', фоновых ' . count($fon)
    . ', файлов состояния ' . count($sostoyanie) . '.';
$L[] = '';
$L[] = MAP_MARKER_END;
$blok = implode("\n", $L);

if (!is_file($karta)) {
    fwrite(STDERR, "Нет docs/MAP.md — карта обязательна в каждом проекте, заведите её и повторите\n");
    exit(1);
}
$tekst = (string)file_get_contents($karta);

$nachalo = strpos($tekst, MAP_MARKER_START);
$konec   = strpos($tekst, MAP_MARKER_END);
if ($nachalo === false || $konec === false || $konec < $nachalo) {
    if ($tolkoProverka) {
        fwrite(STDERR, "В docs/MAP.md нет блока инвентаря. Собрать: php tools/map-sync.php\n");
        exit(3);
    }
    // Первая сборка: блок дописываем в конец, ручной текст не трогаем.
    $novoe = rtrim($tekst) . "\n\n---\n\n" . $blok . "\n";
} else {
    $novoe = substr($tekst, 0, $nachalo) . $blok . substr($tekst, $konec + strlen(MAP_MARKER_END));
}

if ($tolkoProverka) {
    if ($novoe === $tekst) { echo "карта совпадает с кодом (отпечаток $otpechatok)\n"; exit(0); }
    fwrite(STDERR, "КАРТА РАЗОШЛАСЬ С КОДОМ (нынешний отпечаток $otpechatok). "
        . "Пересобрать: php tools/map-sync.php\n");
    exit(3);
}

// Пишем как всё остальное в шаблоне: под эксклюзивным замком и через временный
// файл с уникальным именем. Прямая запись не атомарна — обрыв на середине оставит
// половину карты, и она будет выглядеть настоящей; а сборку могут запустить руками
// ровно тогда, когда её же запустил прогон испытаний.
$zamok = fopen($karta, 'c');
if ($zamok === false || !flock($zamok, LOCK_EX)) {
    fwrite(STDERR, "Карта занята другим процессом — попробуйте ещё раз\n");
    if ($zamok !== false) fclose($zamok);
    exit(1);
}
$vremennyy = $karta . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
$zapisano = file_put_contents($vremennyy, $novoe);
if ($zapisano !== strlen($novoe) || !rename($vremennyy, $karta)) {
    @unlink($vremennyy);
    flock($zamok, LOCK_UN);
    fclose($zamok);
    fwrite(STDERR, "Не удалось записать карту (нет места или прав на docs/)\n");
    exit(1);
}
flock($zamok, LOCK_UN);
fclose($zamok);
echo "инвентарь карты пересобран: docs/MAP.md\n";
echo "точек входа " . count($vhody) . ", библиотек " . count($biblioteki)
    . ", фоновых " . count($fon) . ", файлов состояния " . count($sostoyanie)
    . ", отпечаток $otpechatok\n";
