<?php
declare(strict_types=1);
/**
 * Записать текущий набор обязательных настроек в эталон и в историю.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ИНСТРУМЕНТ. Считать sha256 руками никто не станет: человек
 * либо отключит проверку, либо перепишет строку истории «на глаз» — и цепочка,
 * ради которой всё затевалось, порвётся в первый же раз. Пусть считает машина.
 *
 *   php tools/env-contract-bump.php
 *
 * Новую историю пишет, когда версия конфига (BXAPP_ENV_SCHEMA) БОЛЬШЕ последней
 * записанной; повтор восстанавливает эталон после частичной публикации.
 * Набор настроек меняют вместе с версией, иначе выкладка со старым конфигом
 * останавливается с невнятной причиной.
 */

$root = dirname(__DIR__);
$envFile = $root . '/www/env.php';
$contractFile = $root . '/tests/env-contract.txt';
$historyFile = $root . '/tests/env-contract-history.txt';

function fail(string $text): void { fwrite(STDERR, $text . "\n"); exit(1); }

if (!is_file($envFile)) fail('нет ' . $envFile);
if (!is_file($historyFile)) fail('нет истории ' . $historyFile . ' — её нельзя создавать заново, она и есть след прошлого');

// Замок на ВСЁ «прочитал историю → решил → дописал»: два запуска подряд (человек
// и агент, или два агента веером) читают одну и ту же последнюю строку и пишут
// поверх друг друга — цепочка хешей рвётся молча, а она и есть смысл файла.
// Замок держим на ОТДЕЛЬНОМ файле: сама история кладётся переименованием, и
// замок на её дескрипторе после подмены сторожил бы уже удалённый файл.
// ПОЧЕМУ НЕ storeUpdate(). Готовая обёртка из api/store.php пишет состояние
// ПРИЛОЖЕНИЯ: файлы в data/, закрытые exit-префиксом в первой строке, и
// разбираемые как PHP-массив. Сам префикс здесь НЕ пишем дословно:
// закрывающий тег PHP внутри комментария закрывает PHP-режим, и остаток
// файла становится текстом — линт этого не видит, а инструмент молча
// перестаёт работать (находка ревью K4-04 09.08.2026).
// Здесь другое — эталон контракта и его история в tests/: это обычный текст,
// который читают человек и деплой, и он обязан остаться текстом. Прогнать его
// через storeUpdate() значит превратить в защищённый PHP-файл и сломать обе
// стороны. Поэтому замок и атомарная подмена сделаны здесь вручную — тем же
// способом, что и внутри storeUpdate(): эксклюзивный flock на всё чтение-запись
// плюс временный файл с уникальным именем и переименование.
//
// Судьба замка: файл пустой, живёт рядом с историей в tests/, на боевой не
// уезжает (деплой шлёт только www/) и создаётся заново при каждом запуске.
// Удалять его отдельно не нужно ни при переносе проекта, ни при удалении
// приложения — состояния в нём нет, только сам факт занятости.
$lockFile = $historyFile . '.lock';
$zamok = @fopen($lockFile, 'c');
if ($zamok === false) fail('не открыть файл замка ' . $lockFile);
if (!flock($zamok, LOCK_EX)) fail('не взять замок на историю ' . $historyFile);

// Класть только через временный файл с уникальным именем и переименование:
// оборванная запись (нет места, убили процесс) иначе оставляет эталон
// наполовину записанным, а деплой читает его как настоящий.
$zapisatAtomarno = static function (string $put, string $soderzhimoe): void {
    $vremennyy = $put . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (file_put_contents($vremennyy, $soderzhimoe) !== strlen($soderzhimoe) || !rename($vremennyy, $put)) {
        @unlink($vremennyy);
        fail('не записать ' . $put);
    }
};

$names = [];
exec('php ' . escapeshellarg($root . '/tools/env-define-names.php') . ' ' . escapeshellarg($envFile), $names, $code);
if ($code !== 0) fail('не прочитать имена настроек из env.php');
$names = array_values(array_filter(array_map('trim', $names)));
sort($names);
if ($names === []) fail('в env.php не нашлось ни одной обязательной настройки — так не бывает');
$sum = hash('sha256', implode("\n", $names) . "\n");

preg_match("/define\(\s*'BXAPP_ENV_SCHEMA'\s*,\s*(\d+)\s*\)/", (string)file_get_contents($envFile), $m);
$schema = (int)($m[1] ?? 0);
if ($schema <= 0) fail('в env.php не нашлась версия конфига BXAPP_ENV_SCHEMA');

$lines = explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($historyFile)));
$records = [];
$lastRaw = '';
foreach ($lines as $line) {
    if (trim($line) === '' || $line[0] === '#') continue;
    $records[] = $line;
    $lastRaw = $line;
}
$last = $records === [] ? null : explode("\t", $lastRaw);
$lastSchema = $last === null ? 0 : (int)$last[0];

$header = "# Обязательные настройки env.php на схему " . $schema . " (BXAPP_ENV_SCHEMA).\n"
    . "# Меняешь набор — увеличь схему в www/env.php и обнови этот файл ТЕМ ЖЕ заходом:\n"
    . "# иначе деплой на боевой сервер со старым конфигом остановится с невнятной причиной.\n";
$contractText = $header . implode("\n", $names) . "\n";

if ($schema === $lastSchema) {
    if (($last[1] ?? '') === $sum) {
        // История могла успеть опубликоваться до отказа второго rename.
        // Повтор восстанавливает эталон под тем же lock без новой строки истории.
        if (!is_file($contractFile) || file_get_contents($contractFile) !== $contractText) {
            $zapisatAtomarno($contractFile, $contractText);
            echo "Эталон восстановлен по текущей версии; история не менялась.\n";
        } else {
            echo "Набор настроек не менялся — писать нечего.\n";
        }
        exit(0);
    }
    fail('набор настроек изменился, а версия конфига осталась ' . $schema . '.' . PHP_EOL
        . 'Поднимите BXAPP_ENV_SCHEMA в www/env.php и повторите: иначе боевой сервер со старым'
        . ' конфигом остановит выкладку, не сказав, что именно устарело.');
}
if ($schema < $lastSchema) {
    fail('версия конфига ' . $schema . ' меньше записанной ' . $lastSchema . ' — откат версии запрещён');
}

$prev = $lastRaw === '' ? '-' : hash('sha256', $lastRaw);
$zapisatAtomarno($historyFile, rtrim((string)file_get_contents($historyFile), "\n") . "\n"
    . $schema . "\t" . $sum . "\t" . $prev . "\n");

$zapisatAtomarno($contractFile, $contractText);

flock($zamok, LOCK_UN);
fclose($zamok);

echo "Записано: версия $schema, настроек " . count($names) . ".\n";
echo "Эталон:  $contractFile\n";
echo "История: $historyFile\n";
