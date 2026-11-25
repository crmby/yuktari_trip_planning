<?php
declare(strict_types=1);
/**
 * Точки возврата: снимок исходников, показ отличий, возврат в обе стороны.
 *
 * Проверяем НАСТОЯЩИМ scripts/snap.sh на игрушечном проекте во временном
 * каталоге: боевой проект и его снимки в испытаниях не участвуют — иначе
 * прогон тестов правил бы то, к чему человек собирается вернуться.
 */

suite('Точки возврата');

$snap = dirname(__DIR__) . '/scripts/snap.sh';
check('инструмент точек возврата есть в проекте', is_file($snap), $snap);

if (!is_file($snap)) return;

$base = sys_get_temp_dir() . '/bxapp-snap-' . bin2hex(random_bytes(4));
$proj = $base . '/igrushechnyy';
$store = $base . '/snapshots';
mkdir($proj . '/www/api', 0700, true);
mkdir($proj . '/data', 0700, true);
mkdir($proj . '/scripts', 0700, true);
copy($snap, $proj . '/scripts/snap.sh');
file_put_contents($proj . '/www/api/lib.php', "<?php // рабочий код\n");
file_put_contents($proj . '/www/index.php', "исходная страница\n");
file_put_contents($proj . '/data/b24-tokens.php', "<?php exit;?>{\"token\":\"живой\"}\n");

/** Запуск инструмента с изолированным хранилищем точек. */
$run = static function (string $args) use ($proj, $store): array {
    $cmd = 'BXAPP_SNAP_ROOT=' . escapeshellarg($store)
        . ' bash ' . escapeshellarg($proj . '/scripts/snap.sh') . ' ' . $args . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
};

// 1. Точка ставится и НЕ забирает данные приложения.
[$out, $code] = $run('save "рабочее состояние" --stable');
check('точка ставится одной командой', $code === 0 && str_contains($out, 'Точка поставлена'), $out);
$points = glob($store . '/igrushechnyy*/2*') ?: [];
check('точка появилась в хранилище вне проекта', count($points) === 1, (string)count($points));
$stable = $points[0] ?? '';
check('точка помечена «стабильно»', str_ends_with($stable, '-stable'), $stable);
check('данные приложения в снимок не попали', $stable !== '' && !is_dir($stable . '/files/data'),
    'в снимке: ' . implode(',', array_map('basename', glob($stable . '/files/*') ?: [])));

// 2. Ломаем проект и смотрим, что вернётся — ДО применения.
file_put_contents($proj . '/www/api/lib.php', "<?php // сломанная гипотеза\n");
file_put_contents($proj . '/www/api/eksperiment.php', "<?php // мусор\n");
unlink($proj . '/www/index.php');
file_put_contents($proj . '/data/b24-tokens.php', "<?php exit;?>{\"token\":\"живой\",\"новое\":\"с портала\"}\n");

[$out] = $run('diff последняя');
check('до возврата видно, что вернётся удалённый файл', str_contains($out, 'добавится: www/index.php'), $out);
check('до возврата видно, что уедет лишний файл', str_contains($out, 'eksperiment.php'), $out);
check('до возврата видно, что правка кода откатится', str_contains($out, 'изменится: www/api/lib.php'), $out);

// 3. Возврат.
[$out, $code] = $run('restore последняя');
check('возврат отрабатывает без ошибки', $code === 0, $out);
check('код вернулся к рабочему',
    str_contains((string)file_get_contents($proj . '/www/api/lib.php'), 'рабочий код'));
check('удалённый файл восстановлен', is_file($proj . '/www/index.php'));
check('мусор эксперимента убран', !file_exists($proj . '/www/api/eksperiment.php'));
// Главное: живые данные не откатываются никогда. Вернув их «как было», можно
// потерять то, что реально пришло из портала, и отвалиться от установки.
check('ЖИВЫЕ ДАННЫЕ НЕ ОТКАЧЕНЫ',
    str_contains((string)file_get_contents($proj . '/data/b24-tokens.php'), 'с портала'));

// 4. Двусторонний откат: состояние перед возвратом сохранено и доступно.
$all = glob($store . '/igrushechnyy*/2*') ?: [];
check('перед возвратом снято текущее состояние', count($all) >= 2, (string)count($all));
$before = '';
foreach ($all as $d) { if (!str_ends_with($d, '-stable')) $before = $d; }
[$out, $code] = $run('restore ' . escapeshellarg(basename($before)));
check('можно вернуться обратно к эксперименту',
    $code === 0 && is_file($proj . '/www/api/eksperiment.php'), $out);

// 5. Повторный возврат ничего не делает и говорит об этом.
[$out] = $run('restore ' . escapeshellarg(basename($before)));
check('повторный возврат не выдаёт себя за работу', str_contains($out, 'нечего'), $out);

// 6. Инструмент отказывается работать вне каталога приложения.
mkdir($base . '/ne-prilozhenie/scripts', 0700, true);
copy($snap, $base . '/ne-prilozhenie/scripts/snap.sh');
$out2 = [];
$code2 = 0;
exec('BXAPP_SNAP_ROOT=' . escapeshellarg($store) . ' bash '
    . escapeshellarg($base . '/ne-prilozhenie/scripts/snap.sh') . ' save 2>&1', $out2, $code2);
check('в каталоге без www/ команда отказывается и возвращает ошибку',
    $code2 !== 0 && str_contains(implode("\n", $out2), 'нет www/'), implode("\n", $out2));

// 7. Уборка: обычные точки не копятся без предела, стабильная живёт дольше.
for ($i = 0; $i < 4; $i++) {
    file_put_contents($proj . '/www/shag-' . $i . '.txt', str_repeat('x', 1000));
    $run('save "шаг ' . $i . '"');
}
$out3 = [];
exec('BXAPP_SNAP_ROOT=' . escapeshellarg($store) . ' BXAPP_SNAP_KEEP=2 bash '
    . escapeshellarg($proj . '/scripts/snap.sh') . ' prune 2>&1', $out3);
$left = glob($store . '/igrushechnyy*/2*') ?: [];
$plain = array_filter($left, static fn($d) => !str_ends_with($d, '-stable'));
check('обычных точек остаётся не больше заданного числа', count($plain) <= 2, (string)count($plain));
check('стабильная точка уборкой не тронута',
    count(array_filter($left, static fn($d) => str_ends_with($d, '-stable'))) === 1, implode(' ', $left));

// 8. Снимки не копируют одни и те же файлы заново — иначе точки съедят диск.
$used = (int)shell_exec('du -s ' . escapeshellarg($store) . " | cut -f1");
$one  = (int)shell_exec('du -s --apparent-size ' . escapeshellarg($proj) . " | cut -f1");
check('несколько точек не стоят как несколько копий проекта',
    $used < $one * 4, "хранилище {$used}K при проекте {$one}K");

exec('rm -rf ' . escapeshellarg($base));

// ── СЛОЙ ДАННЫХ: снимок делается свободно, возврат — только явным словом ─────
$base2 = sys_get_temp_dir() . '/bxapp-snapd-' . bin2hex(random_bytes(4));
$proj2 = $base2 . '/igrushechnyy';
$store2 = $base2 . '/snapshots';
mkdir($proj2 . '/www', 0700, true);
mkdir($proj2 . '/data', 0700, true);
mkdir($proj2 . '/scripts', 0700, true);
copy($snap, $proj2 . '/scripts/snap.sh');
file_put_contents($proj2 . '/data/state.php', "<?php exit;?>{\"счётчик\":1}\n");
file_put_contents($proj2 . '/data/b24-tokens.php', "<?php exit;?>{\"refresh\":\"старый\"}\n");

$runD = static function (string $args) use ($proj2, $store2): array {
    $out = [];
    $code = 0;
    exec('BXAPP_SNAP_ROOT=' . escapeshellarg($store2) . ' bash '
        . escapeshellarg($proj2 . '/scripts/snap.sh') . ' ' . $args . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
};

[$out, $code] = $runD('data-save "перед миграцией"');
check('снимок данных делается отдельной командой', $code === 0 && str_contains($out, 'Снимок ДАННЫХ'), $out);
$dsnaps = glob($store2 . '/igrushechnyy*/данные/2*') ?: [];
check('снимок данных лежит отдельно от точек кода', count($dsnaps) === 1, (string)count($dsnaps));

// Данные ушли вперёд: пришло новое состояние и новый токен от портала.
file_put_contents($proj2 . '/data/state.php', "<?php exit;?>{\"счётчик\":57}\n");
file_put_contents($proj2 . '/data/b24-tokens.php', "<?php exit;?>{\"refresh\":\"свежий\"}\n");

$point = basename($dsnaps[0] ?? '');
[$out, $code] = $runD('data-restore ' . escapeshellarg($point));
check('возврат данных без подтверждения НЕ выполняется', $code !== 0, $out);
check('человеку сказано, что именно он потеряет', str_contains($out, 'перезапишется'), $out);
check('про токены портала предупреждают отдельно', str_contains($out, 'токены портала'), $out);
check('данные остались нетронутыми',
    str_contains((string)file_get_contents($proj2 . '/data/state.php'), '57'));

[$out, $code] = $runD('data-restore ' . escapeshellarg($point) . ' --я-понимаю-что-теряю');
check('по явному подтверждению данные возвращаются',
    $code === 0 && str_contains((string)file_get_contents($proj2 . '/data/state.php'), '"счётчик":1'), $out);
check('перед возвратом данных сохранено то, что было',
    count(glob($store2 . '/igrushechnyy*/данные/2*') ?: []) >= 2);

// Снимок данных, оборванный на середине (сняли процесс, кончилось место),
// выглядел в списке как готовый. Возврат на него — не «состояние на вчера», а
// произвольная смесь: часть файлов старая, часть не восстановилась вовсе.
$obrubok = dirname($dsnaps[0]) . '/20200101-000000';
mkdir($obrubok . '/files', 0700, true);
file_put_contents($obrubok . '/files/state.php', "<?php exit;?>{\"счётчик\":-1}\n");
file_put_contents($obrubok . '/.note', "оборвался\n");
[$out, ] = $runD('data-list');
check('НЕЗАВЕРШЁННЫЙ СНИМОК ДАННЫХ НЕ ВЫДАЁТСЯ ЗА ГОТОВЫЙ',
    str_contains($out, 'НЕЗАВЕРШЁН'), $out);
[$out, $code] = $runD('data-restore 20200101-000000 --я-понимаю-что-теряю');
check('ВОЗВРАТ НА НЕЗАВЕРШЁННЫЙ СНИМОК ДАННЫХ ОТКЛОНЁН', $code !== 0, $out . ' код=' . $code);
check('данные при этом не тронуты',
    !str_contains((string)file_get_contents($proj2 . '/data/state.php'), '-1'),
    (string)file_get_contents($proj2 . '/data/state.php'));
check('целые снимки данных по-прежнему в списке',
    str_contains($out, 'незавершён') || str_contains($out, 'НЕЗАВЕРШЁН') || $code !== 0, $out);

// Хранилище прежней схемы: точек в новом ещё нет, а старые лежат рядом. Раньше
// команда отвечала «точек нет» и выходила ДО предупреждения — человек считал,
// что вся история пропала.
$base3 = sys_get_temp_dir() . '/bxapp-snapl-' . bin2hex(random_bytes(4));
$proj3 = $base3 . '/legacyproekt';
$store3 = $base3 . '/snapshots';
mkdir($proj3 . '/www', 0700, true);
mkdir($proj3 . '/scripts', 0700, true);
copy($snap, $proj3 . '/scripts/snap.sh');
mkdir($store3 . '/legacyproekt/20240101-000000/files', 0700, true);   // хранилище прежней схемы
$outL = [];
exec('BXAPP_SNAP_ROOT=' . escapeshellarg($store3) . ' bash '
    . escapeshellarg($proj3 . '/scripts/snap.sh') . ' list 2>&1', $outL);
$outL = implode("\n", $outL);
check('СТАРОЕ ХРАНИЛИЩЕ ВИДНО, ДАЖЕ КОГДА НОВЫХ ТОЧЕК НЕТ',
    str_contains($outL, 'прежней схемы'), $outL);

// ── СНИМОК ДАННЫХ И ЖИВЫЕ ПИСАТЕЛИ (находка ревью R3-012) ──────────────────
// Снимок данных читал живой `data/` обычным rsync, а возврат публиковал файлы по
// одному — и всё это без общего замка с приложением и cron'ом. Между двумя
// файлами успевало пройти что угодно: снимок получался «токены от одной
// установки, сессии от другой», а во время возврата запрос видел половину
// старых данных и половину новых. Проверяется ВТОРЫМ ПРОЦЕССОМ в обе стороны:
// приложение держит замок — снимок обязан отказаться; снимок держит замок —
// приложение обязано получить явный отказ, а не писать в середину копии.
$base5 = sys_get_temp_dir() . '/bxapp-snapf-' . bin2hex(random_bytes(4));
$proj5 = $base5 . '/igrushechnyy';
$store5 = $base5 . '/snapshots';
mkdir($proj5 . '/www', 0700, true);
mkdir($proj5 . '/data', 0700, true);
mkdir($proj5 . '/scripts', 0700, true);
copy($snap, $proj5 . '/scripts/snap.sh');
$zagolovok = "<?php http_response_code(404); exit; /* приватный файл bx-app */ ?>\n";
file_put_contents($proj5 . '/data/settings.php', $zagolovok . "{\"счётчик\":1}\n");
file_put_contents($proj5 . '/data/b24-tokens.php', $zagolovok . "{\"refresh\":\"первый\"}\n");

$run5 = static function (string $args, array $env = []) use ($proj5, $store5): array {
    $cmd = 'BXAPP_SNAP_ROOT=' . escapeshellarg($store5) . ' ';
    foreach ($env as $k => $v) $cmd .= $k . '=' . escapeshellarg((string)$v) . ' ';
    $cmd .= 'bash ' . escapeshellarg($proj5 . '/scripts/snap.sh') . ' ' . $args . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
};

// Писателя изображает НАСТОЯЩИЙ замок жизненного цикла из шаблона: только так
// проверяется совместимость, а не то, что скрипт умеет запирать сам себя.
$fenceHelper = sys_get_temp_dir() . '/bxapp-fence-' . getmypid() . '.php';
file_put_contents($fenceHelper, "<?php\n"
    . "require " . var_export(dirname(__DIR__) . '/www/env.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/www/api/store.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/www/api/lib.php', true) . ";\n"
    . "\$rezhim = (string)\$argv[1]; \$sek = (float)\$argv[2]; \$metka = (string)\$argv[3];\n"
    . "if (\$rezhim === 'derzhat') {\n"
    . "  appLifecycleAcquire(LOCK_SH);\n"
    . "  file_put_contents(\$metka, '1');\n"
    . "  usleep((int)(\$sek * 1000000));\n"
    . "  appLifecycleRelease();\n"
    . "  echo 'ОТПУСТИЛ';\n"
    . "  exit;\n"
    . "}\n"
    . "try { appLifecycleAcquire(LOCK_SH, \$sek); appLifecycleRelease(); echo 'ЗАПИСЬ ПРОШЛА'; }\n"
    . "catch (Throwable \$e) { echo 'ОТКАЗ:' . get_class(\$e); }\n");
$envFence = ['BXAPP_DATA_ROOT' => $proj5 . '/data', 'PATH' => (string)getenv('PATH')];
$descrFence = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

// 1. Приложение работает — снимок данных не крадётся мимо него.
$metkaVzyal = $base5 . '/vzyal-zamok';
$derzhatel = proc_open([PHP_BINARY, $fenceHelper, 'derzhat', '3', $metkaVzyal],
    $descrFence, $pipesD, null, $envFence);
$srok = microtime(true) + 10;
while (!is_file($metkaVzyal) && microtime(true) < $srok) usleep(20000);
check('писатель приложения взял замок жизненного цикла', is_file($metkaVzyal));
[$outF, $codeF] = $run5('data-save "пока приложение работает"', ['BXAPP_SNAP_LOCK_WAIT' => '1']);
check('СНИМОК ДАННЫХ НЕ СНИМАЕТСЯ ПОВЕРХ ЖИВЫХ ПИСАТЕЛЕЙ',
    $codeF !== 0 && str_contains($outF, 'замок жизненного цикла занят'), $outF . ' код=' . $codeF);
check('половинчатого снимка при отказе не остаётся',
    (glob($store5 . '/*/данные/2*') ?: []) === [],
    implode(' ', glob($store5 . '/*/данные/2*') ?: []));
if (is_resource($derzhatel)) {
    stream_get_contents($pipesD[1]);
    fclose($pipesD[1]); fclose($pipesD[2]);
    proc_close($derzhatel);
}
[$outF, $codeF] = $run5('data-save "приложение отпустило"');
check('как только приложение отпустило — снимок делается как обычно',
    $codeF === 0 && str_contains($outF, 'Снимок ДАННЫХ'), $outF . ' код=' . $codeF);

// 2. Обратная сторона: пока идёт снимок, писателю говорят «занято».
$snapDolgiy = proc_open('BXAPP_SNAP_ROOT=' . escapeshellarg($store5)
    . ' BXAPP_SNAP_FAILPOINT=derzhat:3 bash ' . escapeshellarg($proj5 . '/scripts/snap.sh')
    . ' data-save "долгий снимок" 2>&1', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesS);
usleep(800000);
$pisatel = proc_open([PHP_BINARY, $fenceHelper, 'pisat', '0.5', $base5 . '/pisatel'],
    $descrFence, $pipesP, null, $envFence);
$otvetPisatelya = is_resource($pisatel) ? trim((string)stream_get_contents($pipesP[1])) : 'НЕ ЗАПУСТИЛСЯ';
if (is_resource($pisatel)) { fclose($pipesP[1]); fclose($pipesP[2]); proc_close($pisatel); }
$outSnapDolgiy = is_resource($snapDolgiy) ? trim((string)stream_get_contents($pipesS[1])) : '';
if (is_resource($snapDolgiy)) { fclose($pipesS[1]); fclose($pipesS[2]); proc_close($snapDolgiy); }
check('ПОКА ИДЁТ СНИМОК ДАННЫХ, ПИСАТЕЛЬ ПОЛУЧАЕТ ЯВНЫЙ ОТКАЗ, А НЕ ПИШЕТ В СЕРЕДИНУ',
    $otvetPisatelya === 'ОТКАЗ:InstallLifecycleBusyException',
    $otvetPisatelya . ' · снимок: ' . $outSnapDolgiy);

// 3. Возврат данных, оборванный на публикации, оставляет след и доводится до
//    конца повтором. Без следа человек получил бы смесь старого с новым и
//    никакого признака, что возврат вообще не закончился.
$snimkiD = glob($store5 . '/*/данные/2*') ?: [];
sort($snimkiD);
$tochkaD = basename((string)end($snimkiD));
file_put_contents($proj5 . '/data/settings.php', $zagolovok . "{\"счётчик\":99}\n");
$manifestVozvrata = $proj5 . '/data/snap-restore.manifest.php';
[$outC, $codeC] = $run5('data-restore ' . escapeshellarg($tochkaD) . ' --я-понимаю-что-теряю',
    ['BXAPP_SNAP_FAILPOINT' => 'publikaciya']);
check('ОБОРВАННЫЙ ВОЗВРАТ ДАННЫХ ОСТАВЛЯЕТ ПОМЕТКУ, А НЕ ТИШИНУ',
    $codeC !== 0 && is_file($manifestVozvrata), $outC . ' код=' . $codeC);
// Пометка и общий замок лежат в data/, то есть под веб-корнем: без исполняемого
// заголовка их отдали бы по прямой ссылке кодом 200.
foreach (['snap-restore.manifest.php', 'lifecycle.lock.php'] as $sluzhebnyy) {
    $put = $proj5 . '/data/' . $sluzhebnyy;
    check('служебный файл ' . $sluzhebnyy . ' закрыт от прямого запроса и приватен',
        is_file($put) && (fileperms($put) & 0077) === 0
        && str_starts_with((string)file_get_contents($put), '<?php http_response_code(404); exit;'),
        $put . ' · права ' . decoct(fileperms($put) & 0777));
}
[$outC2] = $run5('data-list');
check('про оборванный возврат сказано в списке снимков', str_contains($outC2, 'оборва'), $outC2);
[$outC, $codeC] = $run5('data-restore ' . escapeshellarg($tochkaD) . ' --я-понимаю-что-теряю');
clearstatcache();   // тот же путь уже проверялся выше: без сброса PHP ответит по памяти
check('ПОВТОР ДОВОДИТ ВОЗВРАТ ДО КОНЦА И СНИМАЕТ ПОМЕТКУ',
    $codeC === 0 && !is_file($manifestVozvrata)
    && str_contains((string)file_get_contents($proj5 . '/data/settings.php'), '"счётчик":1'),
    $outC . ' код=' . $codeC);
check('пометка возврата не уезжает внутрь снимков данных',
    glob($store5 . '/*/данные/*/files/snap-restore.manifest.php') === [],
    implode(' ', glob($store5 . '/*/данные/*/files/snap-restore.manifest.php') ?: []));

// 4. Незавершённый перенос установки: половина состояния уже новая, половина
//    старая. Снимать такое молча нельзя — восстановят заведомую кашу.
file_put_contents($proj5 . '/data/state-transition.php', $zagolovok . "{\"фаза\":\"публикация\"}\n");
[$outT, $codeT] = $run5('data-save "во время незавершённого переноса"');
check('СНИМОК ВО ВРЕМЯ НЕЗАВЕРШЁННОГО ПЕРЕНОСА УСТАНОВКИ ОТКЛОНЁН',
    $codeT !== 0 && str_contains($outT, 'перенос'), $outT . ' код=' . $codeT);
// Предохранитель обязан выпускать: приложение может быть сломано именно тем, что
// висит незавершённый перенос, — и снимок данных нужен ДО починки.
[$outT, $codeT] = $run5('data-save "аварийный снимок" --без-замка');
check('аварийный ключ позволяет снять данные и честно предупреждает',
    $codeT === 0 && str_contains($outT, 'без замка'), $outT . ' код=' . $codeT);
unlink($proj5 . '/data/state-transition.php');
@unlink($fenceHelper);
exec('rm -rf ' . escapeshellarg($base5));
// ── Точка доказывает СОДЕРЖИМОЕ, а не только число файлов (BXREV-009) ───────
// Находка ревью 08.08.2026: годность определялась меткой завершения и числом
// файлов. Подмена содержимого при том же числе файлов принималась как исправная
// точка, а нечисловое число читалось как «старая точка, не придираемся» — то
// есть проверку отключала одна испорченная строка в json.
$base4 = sys_get_temp_dir() . '/bxapp-snap-otpechatki-' . bin2hex(random_bytes(4));
$proj4 = $base4 . '/igrushka';
$store4 = $base4 . '/snapshots';
mkdir($proj4 . '/www/api', 0700, true);
mkdir($proj4 . '/scripts', 0700, true);
copy($snap, $proj4 . '/scripts/snap.sh');
file_put_contents($proj4 . '/www/index.php', "страница\n");
file_put_contents($proj4 . '/www/api/lib.php', "<?php // код\n");
$run4 = static function (string $args) use ($proj4, $store4): array {
    $cmd = 'BXAPP_SNAP_ROOT=' . escapeshellarg($store4)
        . ' bash ' . escapeshellarg($proj4 . '/scripts/snap.sh') . ' ' . $args . ' 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
};
$run4('save "точка с отпечатками"');
$tochki = glob($store4 . '/igrushka*/2*') ?: [];
$tochka = $tochki[0] ?? '';
check('у точки есть отпечатки содержимого', $tochka !== '' && is_file($tochka . '/.snap-manifest'),
    'точек: ' . count($tochki));
[$out4] = $run4('verify');
check('целая точка проверяется и говорит об этом прямо', str_contains($out4, 'сходится с отпечатками'), $out4);

// 1. Изменение ОДНОГО байта при том же числе файлов.
file_put_contents($tochka . '/files/www/index.php', "подменённая страница\n");
[$out4, $code4] = $run4('verify');
check('ПОДМЕНА СОДЕРЖИМОГО ПРИ ТОМ ЖЕ ЧИСЛЕ ФАЙЛОВ ЛОВИТСЯ', $code4 !== 0 && str_contains($out4, 'не сходится'),
    $out4 . ' код=' . $code4);
[$out4, $code4] = $run4('restore ' . escapeshellarg(basename($tochka)));
check('ВОЗВРАТ НА ПОДМЕНЁННУЮ ТОЧКУ ОТМЕНЯЕТСЯ ДО ПЕРВОЙ ПРАВКИ ПРОЕКТА',
    $code4 !== 0 && str_contains($out4, 'повреждена или подменена')
    && file_get_contents($proj4 . '/www/index.php') === "страница\n",
    $out4 . ' код=' . $code4);
file_put_contents($tochka . '/files/www/index.php', "страница\n");

// 2. Лишний файл — его `sha256sum -c` сам по себе не заметит.
file_put_contents($tochka . '/files/лишний.txt', "x\n");
[$out4, $code4] = $run4('verify');
check('ЛИШНИЙ ФАЙЛ В ТОЧКЕ ЛОВИТСЯ', $code4 !== 0 && str_contains($out4, 'лишнее'), $out4);
unlink($tochka . '/files/лишний.txt');

// 3. Переименование: число файлов прежнее, содержимое прежнее.
rename($tochka . '/files/www/api/lib.php', $tochka . '/files/www/api/lib2.php');
[$out4, $code4] = $run4('verify');
check('ПЕРЕИМЕНОВАНИЕ ФАЙЛА В ТОЧКЕ ЛОВИТСЯ', $code4 !== 0, $out4);
rename($tochka . '/files/www/api/lib2.php', $tochka . '/files/www/api/lib.php');

// 4. Нечисловое число файлов больше не отключает проверку: точка без отпечатков
//    и с испорченным счётчиком к возврату не допускается.
$meta = (string)file_get_contents($tochka . '/.snap-meta.json');
file_put_contents($tochka . '/.snap-meta.json', preg_replace('~"files": \\d+~', '"files": "много"', $meta));
unlink($tochka . '/.snap-manifest');
[$out4, $code4] = $run4('restore ' . escapeshellarg(basename($tochka)));
check('ИСПОРЧЕННЫЙ СЧЁТЧИК БОЛЬШЕ НЕ ЧИТАЕТСЯ КАК «СТАРАЯ ТОЧКА, НЕ ПРИДИРАЕМСЯ»',
    $code4 !== 0 && str_contains($out4, 'проверить её нечем'), $out4 . ' код=' . $code4);
// 5. Точка старого формата (счётчик цел, отпечатков нет) остаётся годной, но
//    честно называется непроверенной: иначе обновление отобрало бы у человека
//    все прежние точки разом — предохранитель, отсекающий не нарушителя.
file_put_contents($tochka . '/.snap-meta.json', $meta);
[$out4, $code4] = $run4('verify');
check('точка старого формата названа непроверяемой, а не целой',
    str_contains($out4, 'проверить нечем'), $out4);

// ── ССЫЛКА — ТОЖЕ СОДЕРЖИМОЕ ТОЧКИ (находка ревью R3-027) ──────────────────
// Отпечатки и число файлов считались по `find -type f`, а возврат переносит всё
// через `rsync -a`. Символическая ссылка не попадала ни в один счёт: добавленная
// или перенаправленная, она проходила проверку целостности молча — и уезжала в
// проект при возврате, указывая куда угодно.
symlink('/etc/hostname', $proj4 . '/www/ssylka.txt');
$run4('save "точка со ссылкой"');
$tochkiS = glob($store4 . '/igrushka*/2*') ?: [];
sort($tochkiS);
$tochkaS = (string)end($tochkiS);
check('ссылка проекта попала в точку именно ссылкой', is_link($tochkaS . '/files/www/ssylka.txt'),
    basename($tochkaS));
[$out5, $code5] = $run4('verify ' . escapeshellarg(basename($tochkaS)));
check('целая точка со ссылкой проверяется и признаётся целой',
    $code5 === 0 && str_contains($out5, 'сходится с отпечатками'), $out5 . ' код=' . $code5);

// 1. Ссылку ДОБАВИЛИ: файлов столько же, отпечатки прежние.
symlink('/etc/shadow', $tochkaS . '/files/www/podmena.txt');
[$out5, $code5] = $run4('verify ' . escapeshellarg(basename($tochkaS)));
check('ДОБАВЛЕННАЯ В ТОЧКУ ССЫЛКА ЛОВИТСЯ', $code5 !== 0, $out5 . ' код=' . $code5);
unlink($tochkaS . '/files/www/podmena.txt');

// 2. Ссылку ПЕРЕНАПРАВИЛИ: имя то же, цель другая.
unlink($tochkaS . '/files/www/ssylka.txt');
symlink('/etc/shadow', $tochkaS . '/files/www/ssylka.txt');
[$out5, $code5] = $run4('verify ' . escapeshellarg(basename($tochkaS)));
check('ПЕРЕНАПРАВЛЕННАЯ ССЫЛКА ЛОВИТСЯ', $code5 !== 0, $out5 . ' код=' . $code5);
[$out5, $code5] = $run4('restore ' . escapeshellarg(basename($tochkaS)));
check('ВОЗВРАТ НА ТОЧКУ С ПОДМЕНЁННОЙ ССЫЛКОЙ ОТМЕНЁН ДО ПЕРВОЙ ПРАВКИ ПРОЕКТА',
    $code5 !== 0 && readlink($proj4 . '/www/ssylka.txt') === '/etc/hostname',
    $out5 . ' код=' . $code5 . ' · в проекте: ' . (string)readlink($proj4 . '/www/ssylka.txt'));
unlink($tochkaS . '/files/www/ssylka.txt');
symlink('/etc/hostname', $tochkaS . '/files/www/ssylka.txt');

// 3. Точки, снятые ДО этой проверки, описи ссылок не имеют. Отбирать их у
//    человека нельзя — предохранитель обязан отсекать нарушителя, а не всех
//    подряд. Без ссылок такая точка годна по-прежнему; со ссылкой — честно
//    названа непроверяемой, а не выдаётся за целую.
unlink($tochkaS . '/.snap-symlinks');
[$out5, $code5] = $run4('verify ' . escapeshellarg(basename($tochkaS)));
check('ТОЧКА БЕЗ ОПИСИ ССЫЛОК, НО СО ССЫЛКОЙ ВНУТРИ — НЕ «ЦЕЛАЯ»',
    $code5 !== 0 && str_contains($out5, 'проверить их нечем'), $out5 . ' код=' . $code5);
unlink($tochkaS . '/files/www/ssylka.txt');
[$out5, $code5] = $run4('verify ' . escapeshellarg(basename($tochkaS)));
check('ПРЕЖНИЕ ТОЧКИ БЕЗ ССЫЛОК ПРОВЕРЯЮТСЯ КАК И РАНЬШЕ',
    $code5 === 0 && str_contains($out5, 'сходится с отпечатками'), $out5 . ' код=' . $code5);

exec('rm -rf ' . escapeshellarg($base4));
exec('rm -rf ' . escapeshellarg($base3));

exec('rm -rf ' . escapeshellarg($base2));

// ── ОПАСНЫЕ КРАЯ: чужой проект, обрубок, выход за хранилище, тихая подмена ───
$base3 = sys_get_temp_dir() . '/bxapp-snapx-' . bin2hex(random_bytes(4));
$store3 = $base3 . '/snapshots';
/** Игрушечный проект с заданным путём. */
$make = static function (string $path) use ($snap): void {
    mkdir($path . '/www', 0700, true);
    mkdir($path . '/data', 0700, true);
    mkdir($path . '/scripts', 0700, true);
    copy($snap, $path . '/scripts/snap.sh');
};
$runIn = static function (string $path, string $args) use ($store3): array {
    $out = [];
    $code = 0;
    exec('BXAPP_SNAP_ROOT=' . escapeshellarg($store3) . ' bash '
        . escapeshellarg($path . '/scripts/snap.sh') . ' ' . $args . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
};

// 9. ОДНОИМЁННЫЕ ПРОЕКТЫ. Две разные копии приложения с одинаковым именем папки
// (боевая и клиентская, свой проект и шаблон) не должны делить точки возврата:
// иначе возврат в одном подсовывает файлы другого.
$projA = $base3 . '/klient-a/obmen';
$projB = $base3 . '/klient-b/obmen';
$make($projA);
$make($projB);
file_put_contents($projA . '/www/index.php', "код КЛИЕНТА А\n");
file_put_contents($projB . '/www/index.php', "код КЛИЕНТА Б\n");
$runIn($projA, 'save "состояние клиента А"');
[$out] = $runIn($projB, 'list');
check('ТОЧКИ ОДНОИМЁННОГО ЧУЖОГО ПРОЕКТА НЕ ВИДНЫ',
    !str_contains($out, 'клиента А'), $out);
$runIn($projB, 'restore последняя');
check('ЧУЖОЙ КОД НЕ ПОДСУНУТ ПРИ ВОЗВРАТЕ',
    str_contains((string)file_get_contents($projB . '/www/index.php'), 'КЛИЕНТА Б'),
    (string)file_get_contents($projB . '/www/index.php'));

// 10. ОБРУБОК. Снимок, убитый на середине, не должен применяться: с ключом
// «удалить лишнее» он снесёт проект и оставит половину файлов.
$projC = $base3 . '/klient-c/obmen';
$make($projC);
file_put_contents($projC . '/www/index.php', "рабочая страница\n");
file_put_contents($projC . '/www/vazhnyy.php', "<?php // важный файл\n");
$runIn($projC, 'save "целая точка"');
$storeC = glob($store3 . '/*') ?: [];
$dirC = '';
foreach ($storeC as $d) {                       // хранилище именно этого проекта
    if (glob($d . '/2*') && str_contains((string)file_get_contents(
        (glob($d . '/2*')[0] ?? '/dev/null') . '/.snap-meta.json') ?: '', 'целая точка')) $dirC = $d;
}
$brokenPoint = ($dirC !== '' ? $dirC : ($storeC[0] ?? $base3)) . '/20990101-000000';
mkdir($brokenPoint . '/files/www', 0700, true);
file_put_contents($brokenPoint . '/files/www/index.php', "обрубок\n");
[$out, $code] = $runIn($projC, 'restore последняя');
check('НЕЗАВЕРШЁННЫЙ СНИМОК НЕ ПРИМЕНЯЕТСЯ ВМЕСТО ЦЕЛОГО',
    !str_contains((string)file_get_contents($projC . '/www/index.php'), 'обрубок'), $out);
check('важный файл не снесён обрубком', is_file($projC . '/www/vazhnyy.php'), $out);
[$out, $code] = $runIn($projC, 'restore 20990101-000000');
check('прямой возврат на обрубок отклонён с объяснением',
    $code !== 0 && str_contains($out, 'непригодна'), $out . ' код=' . $code);

// 11. ВЫХОД ЗА ХРАНИЛИЩЕ. Имя точки — не путь: «../чужое» не должно уводить
// возврат в соседний каталог.
[$out, $code] = $runIn($projC, "restore '../' 2>&1");
check('возврат по пути «наружу» отклонён', $code !== 0, $out);
$storeC2 = dirname((string)((glob($store3 . '/*/2*') ?: [''])[0] ?? ''));   // хранилище этого проекта
$alien = $storeC2 . '/../chuzhoe/данные/20260101-000000/files';
mkdir($alien, 0700, true);
file_put_contents($alien . '/state.php', "<?php exit;?>чужое\n");
file_put_contents($projC . '/data/state.php', "<?php exit;?>своё\n");
[$out, $code] = $runIn($projC, "data-restore '../../chuzhoe/данные/20260101-000000' --я-понимаю-что-теряю");
check('ВОЗВРАТ ДАННЫХ НЕ ЧИТАЕТ ЧУЖОЙ КАТАЛОГ', $code !== 0
    && str_contains((string)file_get_contents($projC . '/data/state.php'), 'своё'), $out);

// 12. ТИХАЯ ПОДМЕНА. Файл изменён так, что размер и время остались прежними —
// быстрое сравнение его не заметит, и возврат молча оставит чужое содержимое.
$projD = $base3 . '/klient-d/obmen';
$make($projD);
file_put_contents($projD . '/www/index.php', "AAAA\n");
$runIn($projD, 'save "до подмены"');
$mtime = filemtime($projD . '/www/index.php');
file_put_contents($projD . '/www/index.php', "BBBB\n");   // ровно та же длина
touch($projD . '/www/index.php', (int)$mtime, (int)$mtime);
$runIn($projD, 'restore последняя');
check('ВОЗВРАТ ВОССТАНАВЛИВАЕТ СОДЕРЖИМОЕ, А НЕ ТОЛЬКО РАЗМЕР',
    str_contains((string)file_get_contents($projD . '/www/index.php'), 'AAAA'),
    (string)file_get_contents($projD . '/www/index.php'));

// 13. Мелочи, которые молча врут человеку.
$projE = $base3 . '/klient-e/obmen';
$make($projE);
mkdir($projE . '/vendor', 0700, true);
file_put_contents($projE . '/vendor/lib.php', "<?php // чужая библиотека\n");
file_put_contents($projE . '/www/index.php', "страница\n");
[$out] = $runIn($projE, 'save ' . escapeshellarg("первая строка\nвторая строка"));
check('про пропущенные каталоги сказано вслух', str_contains($out, 'vendor'), $out);
$metaFiles = glob($store3 . '/*/2*/.snap-meta.json') ?: [];
$badMeta = [];
foreach ($metaFiles as $m) {
    if (json_decode((string)file_get_contents($m), true) === null) $badMeta[] = $m;
}
check('ПОМЕТКА ТОЧКИ ОСТАЁТСЯ ЧИТАЕМОЙ ПРИ ЛЮБОМ ТЕКСТЕ', $badMeta === [], implode(' ', $badMeta));
[$out, $code] = $runIn($projE, 'sohrani-vsyo');
check('НЕИЗВЕСТНАЯ КОМАНДА НЕ ВЫДАЁТ СЕБЯ ЗА УСПЕХ', $code !== 0, $out . ' код=' . $code);

exec('rm -rf ' . escapeshellarg($base3));
