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
 * Выгрузка таблицы в CSV, который откроется в Excel без плясок.
 *
 * Три вещи, на которых спотыкаются каждый раз:
 *   1) BOM в начале файла — без него Excel читает кириллицу как «ЛЙЖ»;
 *   2) разделитель «;» — в русской локали Excel ждёт именно его, с запятой
 *      весь файл ложится в одну колонку;
 *   3) экранирование формул — значение, начинающееся с =, +, -, @, Excel
 *      выполняет. Это не косметика: так из выгрузки делают атаку на того, кто
 *      её открыл. Поэтому такие значения предваряются апострофом.
 *
 * Пример использования в своём эндпоинте:
 *
 *     require_once __DIR__ . '/export-csv.php';
 *     $ctx = apiBoot(['methods' => ['GET'], 'role' => 'view']);
 *     csvDownload('сделки-' . date('Y-m-d') . '.csv',
 *         ['ID', 'Название', 'Сумма'],
 *         array_map(fn($d) => [$d['id'], $d['title'], moneyStr($d['cents'])], $deals));
 */

require_once __DIR__ . '/lib.php';

// Ограничения — часть контракта готового модуля. Генератор строк может быть
// бесконечным, а одна ячейка — случайно содержать целый файл: оба случая должны
// завершаться управляемой ошибкой ДО отправки HTTP-заголовков, а не OOM/диском.
if (!defined('CSV_EXPORT_MAX_BYTES')) define('CSV_EXPORT_MAX_BYTES', 128 * 1048576);
if (!defined('CSV_EXPORT_MAX_ROWS'))  define('CSV_EXPORT_MAX_ROWS', 250000);
if (!defined('CSV_BUILD_MAX_BYTES'))  define('CSV_BUILD_MAX_BYTES', 8 * 1048576);
if (!defined('CSV_TEMP_MEMORY_BYTES')) define('CSV_TEMP_MEMORY_BYTES', 1048576);
if (!defined('CSV_MAX_COLUMNS'))      define('CSV_MAX_COLUMNS', 512);
if (!defined('CSV_CELL_MAX_BYTES'))   define('CSV_CELL_MAX_BYTES', 1048576);
if (!defined('CSV_ROW_MAX_BYTES'))    define('CSV_ROW_MAX_BYTES', 2 * 1048576);

/** Одно значение → безопасная ячейка CSV. */
function csvCell($value): string {
    $s = (string)($value ?? '');
    // Истинное число доверено: -5 должно остаться числом. Строка же недоверена,
    // даже если формула спрятана после пробелов/табов/перевода строки.
    $trustedNumber = is_int($value) || (is_float($value) && is_finite($value))
        // REST Битрикса отдаёт даже деньги строками. Точное
        // отрицательное decimal-число без пробелов безопасно оставить
        // числом; `-1+cmd` под этот узкий формат не попадает.
        || (is_string($value) && preg_match('/^-\d+(?:[\.,]\d+)?$/D', $value) === 1);
    if (!$trustedNumber && preg_match('/^[ \t\r\n]*[=+\-@]/', $s) === 1) $s = "'" . $s;
    // Кавычки удваиваются, значение целиком берётся в кавычки: перенос строки
    // и точка с запятой внутри текста иначе разваливают таблицу.
    return '"' . str_replace('"', '""', $s) . '"';
}

/** Лимит вызывающий вправе только уменьшить, но не обойти. */
function csvLimit(array $limits, string $name, int $hard): int {
    $hard = max(1, $hard);
    if (!array_key_exists($name, $limits)) return $hard;
    return max(1, min($hard, (int)$limits[$name]));
}

/** Одна строка CSV с ограничением числа/размера ячеек и итогового размера. */
function csvLine(array $values, array $limits = []): string {
    $maxColumns = csvLimit($limits, 'max_columns', (int)CSV_MAX_COLUMNS);
    $maxCell = csvLimit($limits, 'max_cell_bytes', (int)CSV_CELL_MAX_BYTES);
    $maxRow = csvLimit($limits, 'max_row_bytes', (int)CSV_ROW_MAX_BYTES);
    if (count($values) > $maxColumns) {
        throw new RuntimeException('CSV: слишком много колонок в строке.');
    }

    $cells = [];
    $bytes = 2; // завершающий CRLF
    foreach ($values as $value) {
        $raw = (string)($value ?? '');
        if (strlen($raw) > $maxCell) {
            throw new RuntimeException('CSV: ячейка превышает допустимый размер.');
        }
        $cell = csvCell($value);
        $bytes += strlen($cell) + ($cells === [] ? 0 : 1);
        if ($bytes > $maxRow) {
            throw new RuntimeException('CSV: строка превышает допустимый размер.');
        }
        $cells[] = $cell;
    }
    return implode(';', $cells) . "\r\n";
}

/** Записать кусок полностью и не зависнуть на fwrite(), вернувшем ноль. */
function csvWriteChunk($stream, string $chunk, int &$bytes, int $maxBytes): void {
    $length = strlen($chunk);
    if ($length > $maxBytes - $bytes) {
        throw new RuntimeException('CSV: выгрузка превышает допустимый размер.');
    }
    $offset = 0;
    while ($offset < $length) {
        $written = @fwrite($stream, substr($chunk, $offset));
        if (!is_int($written) || $written <= 0) {
            throw new RuntimeException('CSV: не удалось записать временную выгрузку.');
        }
        $offset += $written;
        $bytes += $written;
    }
}

/**
 * Потоковая сборка CSV в переданный stream.
 *
 * @return array{bytes:int,rows:int} Точный размер (для Content-Length) и число
 *         строк данных без шапки.
 */
function csvWrite($stream, array $header, iterable $rows, array $limits = []): array {
    if (!is_resource($stream)) throw new InvalidArgumentException('CSV: ожидается открытый stream.');

    $maxBytes = csvLimit($limits, 'max_bytes', (int)CSV_EXPORT_MAX_BYTES);
    $maxRows = csvLimit($limits, 'max_rows', (int)CSV_EXPORT_MAX_ROWS);
    $lineLimits = [
        'max_columns' => csvLimit($limits, 'max_columns', (int)CSV_MAX_COLUMNS),
        'max_cell_bytes' => csvLimit($limits, 'max_cell_bytes', (int)CSV_CELL_MAX_BYTES),
        'max_row_bytes' => csvLimit($limits, 'max_row_bytes', (int)CSV_ROW_MAX_BYTES),
    ];
    $bytes = 0;
    $rowCount = 0;
    csvWriteChunk($stream, "\xEF\xBB\xBF", $bytes, $maxBytes); // BOM для Excel
    if ($header !== []) csvWriteChunk($stream, csvLine($header, $lineLimits), $bytes, $maxBytes);
    foreach ($rows as $row) {
        if ($rowCount >= $maxRows) {
            throw new RuntimeException('CSV: слишком много строк в выгрузке.');
        }
        csvWriteChunk($stream, csvLine((array)$row, $lineLimits), $bytes, $maxBytes);
        $rowCount++;
    }
    return ['bytes' => $bytes, 'rows' => $rowCount];
}

/**
 * Строки → текст CSV (без отдачи в браузер).
 *
 * Это совместимый helper только для небольших CSV. В отличие от прежнего
 * безлимитного .= он бросает управляемое исключение до разрастания PHP-памяти.
 */
function csvBuild(array $header, iterable $rows, array $limits = []): string {
    $buildMax = csvLimit($limits, 'max_bytes', (int)CSV_BUILD_MAX_BYTES);
    $limits['max_bytes'] = $buildMax;
    $memory = max(65536, min((int)CSV_TEMP_MEMORY_BYTES, $buildMax));
    $tmp = @fopen('php://temp/maxmemory:' . $memory, 'w+b');
    if (!$tmp) throw new RuntimeException('CSV: не удалось открыть временный stream.');
    try {
        csvWrite($tmp, $header, $rows, $limits);
        if (!@rewind($tmp)) throw new RuntimeException('CSV: не удалось прочитать временную выгрузку.');
        $out = stream_get_contents($tmp);
        if (!is_string($out)) throw new RuntimeException('CSV: не удалось прочитать временную выгрузку.');
        return $out;
    } finally {
        fclose($tmp);
    }
}

/**
 * Отдать CSV браузером как файл и завершить запрос.
 *
 * Имя файла отдаём дважды: обычным filename для старых браузеров и filename*
 * с кодировкой — иначе кириллица в имени превращается в «_____.csv».
 */
function csvDownload(string $filename, array $header, iterable $rows, array $limits = []): void {
    // php://temp держит в памяти только небольшой порог, затем прозрачно
    // переходит на временный файл. Полностью собираем ДО headers: если генератор
    // сломан или превышен cap, HTTP ещё можно вернуть как нормальную ошибку.
    $memory = max(65536, min((int)CSV_TEMP_MEMORY_BYTES, (int)CSV_EXPORT_MAX_BYTES));
    $tmp = @fopen('php://temp/maxmemory:' . $memory, 'w+b');
    if (!$tmp) throw new RuntimeException('CSV: не удалось открыть временную выгрузку.');
    try {
        $meta = csvWrite($tmp, $header, $rows, $limits);
        if (!@rewind($tmp)) throw new RuntimeException('CSV: не удалось прочитать временную выгрузку.');
    } catch (Throwable $e) {
        fclose($tmp);
        throw $e;
    }

    $safe = preg_replace('/[^\w\-. ]+/u', '_', $filename) ?: 'export.csv';

    if (!headers_sent()) {
        sendNoStoreHeaders();                    // выгрузка не должна кэшироваться
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe . '"; '
             . "filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . $meta['bytes']);
    }
    while (!feof($tmp)) {
        $chunk = fread($tmp, 65536);
        if (!is_string($chunk) || $chunk === '') break;
        echo $chunk;
    }
    fclose($tmp);
    exit;
}
