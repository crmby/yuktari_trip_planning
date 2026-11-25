<?php
declare(strict_types=1);
/**
 * Бюджет выдачи: эндпоинт не имеет права отдавать наружу неограниченный список.
 *
 * ЗАЧЕМ (находка ревью R3-030, 09.08.2026). Готовый `fetchAll()` честно
 * дочитывает ВСЕ страницы портала — и в этом же его опасность: на большом
 * портале он соберёт сотни страниц, а эндпоинт отдаст их одним JSON. Замер
 * ревью на 24 000 строк: CSV 4,8 МБ, JSON 11,7 МБ, пик памяти PHP 49,9 МБ — и
 * это ещё без конверта REST и без браузера, который следом синхронно строит
 * 24 000 строк таблицы. Формально данные полные, фактически — таймаут,
 * memory_limit или намертво повисший iframe.
 *
 * Отдельно важно, ЧТО это воспроизвёл чистый агент, работавший строго по
 * шаблону: он правильно переиспользовал готовые блоки и правильно доказал
 * полноту выборки. Правил про полную пагинацию в базе много, а про верхнюю
 * границу выдачи не было ни одного — значит виноват не агент, а пробел в
 * контракте.
 *
 * ЧТО СЧИТАЕТСЯ НАРУШЕНИЕМ. В одном файле-эндпоинте результат полной выборки
 * (`fetchAll(` / `batchAll(`) уходит в ответ (`apiOk(` / `echo json_encode(`)
 * и при этом в файле нет ни одного признака бюджета: среза строк, страницы,
 * потолка байт или типизированного отказа `too_large`.
 *
 *   php tools/bounded-output-check.php                 # корень шаблона
 *   php tools/bounded-output-check.php --root=/путь    # чужой корень (образцы)
 *   php tools/bounded-output-check.php --quiet
 */

$root = dirname(__DIR__);
$quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--quiet') { $quiet = true; continue; }
    if (strpos($a, '--root=') === 0) $root = rtrim(substr($a, 7), '/');
}

$apiDir = $root . '/www/api';
if (!is_dir($apiDir)) {
    fwrite(STDERR, "нет каталога эндпоинтов: $apiDir\n");
    exit(2);
}

/** Признаки того, что выдача ограничена осознанно. Любого достаточно. */
$priznakiByudzheta = [
    'MAX_ROWS', 'MAX_BYTES', 'too_large', 'resumable',
    'array_slice(', 'LIMIT_ROWS', '$limit', "'limit'", '"limit"',
    '$page', "'page'", '"page"', 'OUTPUT_BUDGET', 'ROWS_BUDGET',
];

$vydacha = ['apiOk(', 'json_encode('];
$polnayaVyborka = ['fetchAll(', 'batchAll('];

$problems = [];
$files = glob($apiDir . '/*.php') ?: [];
sort($files);
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    $rel = ltrim(substr($f, strlen($root)), '/');
    $beryotVsyo = false;
    foreach ($polnayaVyborka as $p) if (strpos($src, $p) !== false) { $beryotVsyo = true; break; }
    if (!$beryotVsyo) continue;
    $otdayot = false;
    foreach ($vydacha as $p) if (strpos($src, $p) !== false) { $otdayot = true; break; }
    if (!$otdayot) continue;
    $ogranichen = false;
    foreach ($priznakiByudzheta as $p) if (strpos($src, $p) !== false) { $ogranichen = true; break; }
    if ($ogranichen) continue;
    $problems[] = $rel . ' — полная выборка портала уходит в ответ целиком: нет ни среза строк, '
        . 'ни страницы, ни потолка байт, ни типизированного отказа too_large/resumable. '
        . 'На большом портале это таймаут, memory_limit или зависший iframe при формально полных данных.';
}

if ($problems) {
    fwrite(STDERR, "ВЫДАЧА БЕЗ БЮДЖЕТА (" . count($problems) . "):\n");
    foreach ($problems as $p) fwrite(STDERR, "  ✗ $p\n");
    exit(1);
}
if (!$quiet) echo "✓ Бюджет выдачи: полная выборка нигде не уходит наружу без верхней границы\n";
exit(0);
