<?php
declare(strict_types=1);
/**
 * СВЕРКА КАТАЛОГА РЕЦЕПТОВ С ЖИВЫМИ ДОНОРАМИ.
 *
 * ЗАЧЕМ. `docs/RECIPES.md` отправляет за кодом в ЧУЖИЕ проекты: «возьми вот это
 * оттуда». Такая ссылка молча гниёт: проект переименовали, файл перенесли,
 * функцию убрали — а каталог по-прежнему уверенно шлёт по адресу, которого нет.
 * Человек идёт, не находит и пишет своё, хотя готовое лежит рядом под другим
 * именем. Номера строк гниют ещё быстрее: они уезжают при любой правке донора.
 *
 * ЧТО ДЕЛАЕТ.
 *   1) каждый донор из `docs/recipes.json` существует на диске — иначе ОШИБКА;
 *   2) опорные символы (имена функций/классов) всё ещё в файле — иначе ОШИБКА:
 *      файл на месте, но это уже другой файл, и ссылка ведёт не туда;
 *   3) отпечаток файла совпадает — иначе ПРЕДУПРЕЖДЕНИЕ: донор правили, цитаты
 *      по номерам строк могли уехать, стоит перепроверить;
 *   4) ссылки в `RECIPES.md` и записи реестра совпадают в обе стороны — чтобы
 *      каталог не оброс донорами, которых никто не сверяет.
 *
 * ЧУЖИЕ ПРОЕКТЫ ТОЛЬКО ЧИТАЕМ. Ничего не создаём и не правим.
 *
 * ПРОПУСК — НЕ «ВСЁ ХОРОШО». Нет каталога проектов (другая машина, перенос
 * шаблона) — так и говорим отдельным словом «не проверено», и код выхода 0
 * только потому, что проверять было нечем.
 *
 *   php tools/recipes-check.php            человеку
 *   php tools/recipes-check.php --json     машине (и для испытаний)
 */

$koren = dirname(__DIR__);
$json = in_array('--json', $argv, true);

$reestrFile = $koren . '/docs/recipes.json';
$katalogFile = $koren . '/docs/RECIPES.md';

/** @return array{ok:bool,skipped:bool,errors:string[],warnings:string[],checked:int} */
function recipesCheck(string $reestrFile, string $katalogFile): array
{
    // ИСХОДА ТРИ, А НЕ ДВА.
    //
    // Находка ревью 08.08.2026 (BXREV-021): пропавший ДОНОР — код в ЧУЖОМ
    // проекте — красил основной набор шаблона. Шаблон при этом не менялся ни на
    // байт: чужой проект переименовали, перенесли или удалили. Это дрейф
    // внешнего мира, а не поломка шаблона, и разбирать его нужно отдельно.
    //
    //   errors   — сломан САМ шаблон: нет реестра, битый json, запись без пути,
    //              каталог ссылается на донора вне реестра. Это красное.
    //   drift    — расхождение с чужими проектами: донора нет, символы уехали,
    //              отпечаток другой. Отдельный отчёт, а не приговор шаблону.
    //   skipped  — сверять нечем: чужих проектов на машине нет вовсе.
    $itog = ['ok' => true, 'skipped' => false, 'errors' => [], 'drift' => [],
             'warnings' => [], 'checked' => 0, 'проверено' => date('c')];

    if (!is_file($reestrFile)) {
        $itog['ok'] = false;
        $itog['errors'][] = 'нет docs/recipes.json — сверять каталог рецептов нечем';
        return $itog;
    }
    $reestr = json_decode((string)file_get_contents($reestrFile), true);
    if (!is_array($reestr) || !is_array($reestr['donors'] ?? null)) {
        $itog['ok'] = false;
        $itog['errors'][] = 'docs/recipes.json не читается как реестр доноров';
        return $itog;
    }

    // Корень чужих проектов задаётся снаружи: испытание и проверочный контур
    // должны уметь показать на свой каталог, а не на живой /data/projects.
    $izSredy = (string)(getenv('BXAPP_DONOR_ROOT') ?: '');
    $kornProektov = $izSredy !== '' ? rtrim($izSredy, '/') : (string)($reestr['projects_root'] ?? '/data/projects');
    if (!is_dir($kornProektov)) {
        // Пропуск называем вслух: молчаливый пропуск читается как «проверено».
        $itog['skipped'] = true;
        $itog['warnings'][] = 'каталог проектов ' . $kornProektov . ' недоступен — доноры НЕ ПРОВЕРЕНЫ';
        return $itog;
    }

    foreach ($reestr['donors'] as $zapis) {
        $otn = (string)($zapis['donor'] ?? '');
        if ($otn === '') {
            $itog['ok'] = false;
            $itog['errors'][] = 'в реестре есть запись без пути донора';
            continue;
        }
        $itog['checked']++;
        $put = $kornProektov . '/' . $otn;
        if (!is_file($put)) {
            $itog['drift'][] = $otn . ' — донора нет на диске: каталог рецептов шлёт по адресу,'
                . ' которого не существует (чужой проект переименовали, перенесли или удалили)';
            continue;
        }
        $tekst = (string)file_get_contents($put);
        $simvoly = is_array($zapis['symbols'] ?? null) ? $zapis['symbols'] : [];
        $yakorya = is_array($zapis['anchors'] ?? null) ? $zapis['anchors'] : [];
        if ($simvoly === [] && $yakorya === []) {
            $itog['ok'] = false;
            $itog['errors'][] = $otn . ' — нет ни опорного объявления symbols, ни процедурного anchors';
            continue;
        }
        $poteryany = [];
        foreach ($simvoly as $simvol) {
            $simvol = (string)$simvol;
            if ($simvol === '') continue;
            // Ищем объявление, а не любое упоминание: вызов из другого места
            // доказательством не является.
            if (!preg_match('~(function|class)\s+' . preg_quote($simvol, '~') . '\b~', $tekst)) {
                $poteryany[] = $simvol;
            }
        }
        if ($poteryany !== []) {
            $itog['drift'][] = $otn . ' — файл на месте, но опорных объявлений в нём больше нет ('
                . implode(', ', $poteryany) . '): ссылка ведёт не туда';
            continue;
        }
        $poteryanyYakorya = [];
        foreach ($yakorya as $yakor) {
            $yakor = (string)$yakor;
            if ($yakor === '' || strpos($tekst, $yakor) === false) $poteryanyYakorya[] = $yakor;
        }
        if ($poteryanyYakorya !== []) {
            $itog['drift'][] = $otn . ' — процедурные опорные фрагменты исчезли ('
                . implode(', ', $poteryanyYakorya) . '): ссылка ведёт не к обещанному решению';
            continue;
        }
        $otpechatok = substr(hash('sha256', $tekst), 0, 12);
        if ($otpechatok !== (string)($zapis['sha256_12'] ?? '')) {
            $stroki = is_array($zapis['cited_ranges'] ?? null) ? $zapis['cited_ranges'] : [];
            // Где опорные объявления лежат СЕЙЧАС (ревью 23.09.2026, doki-1): прежде
            // сверка говорила «проверить цитаты по строкам 1796-1864», а функции
            // давно уехали на 2355 — куда смотреть, не знал никто.
            $gde = [];
            foreach ($simvoly as $simvol) {
                if (preg_match('~(function|class)\s+' . preg_quote((string)$simvol, '~') . '\b~', $tekst, $m, PREG_OFFSET_CAPTURE)) {
                    $gde[] = 1 + substr_count(substr($tekst, 0, (int)$m[0][1]), "\n");
                }
            }
            $vDiapazone = static function (int $line) use ($stroki): bool {
                foreach ($stroki as $r) {
                    if (preg_match('~^(\d+)-(\d+)$~', (string)$r, $mm) && $line >= (int)$mm[1] && $line <= (int)$mm[2]) return true;
                }
                return false;
            };
            $uehali = $stroki !== [] && $gde !== [] && array_filter($gde, static fn(int $l): bool => !$vDiapazone($l)) !== [];
            $itog['drift'][] = $otn . ' — донор изменился (' . (string)($zapis['sha256_12'] ?? '?')
                . ' → ' . $otpechatok . ')'
                . ($uehali
                    ? '; цитата по строкам ' . implode(', ', $stroki) . ' устарела: опорные функции теперь на строках '
                      . min($gde) . '-' . max($gde) . ' — поправить RECIPES.md или сослаться на имена функций'
                    : ($stroki ? '; проверить цитаты по строкам ' . implode(', ', $stroki) : ''))
                . '. Символы на месте, поэтому это не поломка, а повод обновить отпечаток';
        }
    }

    // Каталог и реестр обязаны знать одних и тех же доноров.
    if (is_file($katalogFile)) {
        $md = (string)file_get_contents($katalogFile);
        $svoi = ['www', 'api', 'tools', 'tests', 'docs', 'bin', 'scripts'];
        preg_match_all('~`([a-z0-9][a-z0-9-]*)/([A-Za-z0-9_./-]+\.(?:php|js|mjs|sh))(?::[0-9]+(?:-[0-9]+)?)?`~',
            $md, $sovpadeniya, PREG_SET_ORDER);
        $vKataloge = [];
        foreach ($sovpadeniya as $s) {
            if (in_array($s[1], $svoi, true)) continue;   // путь внутри шаблона, не донор
            $vKataloge[$s[1] . '/' . $s[2]] = true;
        }
        $vReestre = [];
        foreach ($reestr['donors'] as $zapis) $vReestre[(string)($zapis['donor'] ?? '')] = true;
        $tolkoKatalog = array_diff_key($vKataloge, $vReestre);
        $tolkoReestr  = array_diff_key($vReestre, $vKataloge);
        if ($tolkoKatalog !== []) {
            $itog['ok'] = false;
            $itog['errors'][] = 'в RECIPES.md есть доноры вне реестра (их никто не сверяет): '
                . implode(', ', array_keys($tolkoKatalog));
        }
        if ($tolkoReestr !== []) {
            $itog['warnings'][] = 'в реестре есть доноры, на которых каталог больше не ссылается: '
                . implode(', ', array_keys($tolkoReestr));
        }
    }

    return $itog;
}

$r = recipesCheck($reestrFile, $katalogFile);

if ($json) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(!$r['ok'] ? 1 : ($r['drift'] !== [] ? 3 : 0));
}

foreach ($r['errors'] as $e) echo "ОШИБКА  $e\n";
foreach ($r['drift'] as $d) echo "дрейф   $d\n";
foreach ($r['warnings'] as $w) echo "внимание  $w\n";
if ($r['skipped']) {
    echo "Каталог рецептов НЕ ПРОВЕРЕН: доноры недоступны на этой машине.\n";
    exit(0);
}
if (!$r['ok']) {
    echo "Каталог рецептов сломан — см. ОШИБКИ выше (это поломка шаблона, а не чужих проектов).\n";
    exit(1);
}
if ($r['drift'] !== []) {
    // Отдельный код: чужие проекты уехали. Шаблон при этом исправен, и его
    // набор испытаний краснеть не должен — но и молчать об этом нельзя.
    echo 'Каталог рецептов: ' . count($r['drift']) . " доноров разошлись с реестром (проверено "
        . $r['проверено'] . "). Шаблон исправен; обновить ссылки или отпечатки.\n";
    exit(3);
}
echo 'Каталог рецептов сверен: ' . $r['checked'] . " доноров на месте, символы живы (проверено "
    . $r['проверено'] . ").\n";
exit(0);
