<?php
declare(strict_types=1);
/**
 * КАРКАС ИНТЕРФЕЙСА В ВЫПУСКЕ — ТОЛЬКО ЗА ГАРДОМ (ревью 23.09.2026, zhivoy-1).
 *
 * ЗАЧЕМ. www/template.html лежал в webroot: /index.php постороннему отвечал 403
 * «только из портала», а /template.html — 200 с полной разметкой, подписями и
 * комментариями разработчика, без no-store и без frame-ancestors, то есть чужой
 * сайт мог показать его в своей рамке «как это приложение». `.htaccess` шаблон не
 * использует намеренно, поэтому закрываем тем же приёмом, что и state: выпуск
 * кладёт разметку в template.php, первая строка которого — ранний 404 + exit.
 * index.php читает файл как текст (appTemplateHtml() в api/lib.php) и эту строку
 * срезает, а прямой запрос исполняет её и получает пустой 404.
 *
 * В исходниках проекта остаётся template.html: его правят руками, по нему ходят
 * живые прогоны. Превращение делает только деплой, в собранном каталоге выпуска.
 *
 *   php tools/release-template.php <каталог выпуска>
 *
 * Код 0 — template.php собран, template.html убран; 1 — разметки нет или запись
 * не удалась (деплой останавливается: публиковать без каркаса нельзя).
 */

$stage = rtrim((string)($argv[1] ?? ''), '/');
if ($stage === '' || !is_dir($stage)) {
    fwrite(STDERR, "release-template: укажите каталог выпуска\n");
    exit(1);
}
$src = $stage . '/template.html';
$dst = $stage . '/template.php';
if (!is_file($src)) {
    // Повторный запуск по уже собранному каталогу — не ошибка.
    if (is_file($dst)) exit(0);
    fwrite(STDERR, "release-template: в выпуске нет template.html — публиковать без каркаса нельзя\n");
    exit(1);
}
$html = file_get_contents($src);
if (!is_string($html) || $html === '') {
    fwrite(STDERR, "release-template: template.html не читается или пуст\n");
    exit(1);
}
// Гард — ОДНА строка: appTemplateHtml() срезает ровно её и ничего больше.
$guard = "<?php http_response_code(404); exit; ?>\n";
$tmp = $dst . '.tmp-' . getmypid();
if (file_put_contents($tmp, $guard . $html, LOCK_EX) === false || !rename($tmp, $dst)) {
    @unlink($tmp);
    fwrite(STDERR, "release-template: не записать template.php\n");
    exit(1);
}
if (!unlink($src)) {
    fwrite(STDERR, "release-template: не убрать template.html из выпуска\n");
    exit(1);
}
exit(0);
