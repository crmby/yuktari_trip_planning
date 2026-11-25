<?php
declare(strict_types=1);
/** Хранилище состояния: защита файлов, целостность при параллельной правке, порча. */

// Набор самодостаточен: последовательный раннер раньше случайно приносил
// cacheRemember() из одного из предыдущих suites.
require_once dirname(__DIR__) . '/www/api/cache.php';

suite('Хранилище состояния');

$file = DATA_ROOT . '/t-store.php';

// 1. Новая запись сама отвечает 404 до данных — даже при ошибочной публикации.
storeWrite($file, ['a' => 1]);
$raw = (string)file_get_contents($file);
check('файл состояния закрыт точным 404+exit-префиксом', privatePhpHas404Prefix($raw));
check('состояние читается обратно', (storeRead($file)['a'] ?? null) === 1);

$zeroTimeoutFile = DATA_ROOT . '/t-store-zero-timeout.php';
$zeroTimeoutResult = storeUpdate($zeroTimeoutFile, static function (array $state): array {
    $state['attempted'] = true;
    return $state;
}, 0.0);
check('storeUpdate с нулевым timeout делает одну попытку и берёт свободный lock',
    ($zeroTimeoutResult['attempted'] ?? false) === true
    && (storeRead($zeroTimeoutFile)['attempted'] ?? false) === true);

$zeroLifecycleLocked = false;
try {
    appLifecycleAcquire(LOCK_SH, 0.0);
    $zeroLifecycleLocked = true;
} catch (Throwable $e) {
    // Свободный lock при нулевом timeout обязан браться с первой попытки.
} finally {
    if ($zeroLifecycleLocked) appLifecycleRelease();
}
check('lifecycle lock с нулевым timeout делает одну попытку', $zeroLifecycleLocked);

$zeroJournalLocked = false;
try {
    appJournalMutationLockAcquire(0.0);
    $zeroJournalLocked = true;
} catch (Throwable $e) {
    // Свободный lock при нулевом timeout обязан браться с первой попытки.
} finally {
    if ($zeroJournalLocked) appJournalMutationLockRelease();
}
check('journal lock с нулевым timeout делает одну попытку', $zeroJournalLocked);

// 2. Права на файл — только владельцу процесса.
check('файл состояния не читается посторонними (0600)',
    (fileperms($file) & 0077) === 0, 'права: ' . substr(sprintf('%o', fileperms($file)), -4));
$lockFile = $file . '.lock.php';
check('lock-файл тоже создаётся private и с исполняемым header',
    is_file($lockFile)
    && (fileperms($lockFile) & 0077) === 0
    && privatePhpHas404Prefix((string)file_get_contents($lockFile)));
$permissionProbe = DATA_ROOT . '/t-permissions.php';
file_put_contents($permissionProbe, BXAPP_PRIVATE_PHP_HEADER);
chmod($permissionProbe, 0644);
check('private-права проверяются как обязательный шаг, а не best-effort chmod',
    storeApplyPrivatePermissions($permissionProbe)
    && (fileperms($permissionProbe) & 0777) === 0600);
@unlink($permissionProbe);

// Совместимый reader нужен для двух выкладок: сначала новый код учится читать
// оба формата, и лишь следующая версия меняет writer. Иначе rollback первой
// версии перестанет читать state, который успел записать новый live.
$legacyDecoded = storeDecode("<?php exit; ?>\n{\"format\":\"legacy\"}");
$notFoundDecoded = storeDecode("<?php http_response_code(404); exit; ?>\n{\"format\":\"404\"}");
check('reader принимает legacy и новый 404-префикс для безопасного rollout',
    ($legacyDecoded['format'] ?? '') === 'legacy'
    && ($notFoundDecoded['format'] ?? '') === '404');

// 3. Правка «прочитал → поправил → записал» идёт под замком целиком: значение,
//    записанное соседом во время нашей правки, не пропадает.
storeWrite($file, ['n' => 0]);
storeUpdate($file, static function (array $st): array {
    // Внутри мутатора состояние уже под замком — правим переданный массив.
    $st['n'] = (int)($st['n'] ?? 0) + 1;
    $st['inner'] = true;
    return $st;
});
$after = storeRead($file);
check('изменения мутатора сохраняются целиком', ($after['n'] ?? null) === 1 && ($after['inner'] ?? null) === true);

// 4. Вложенная правка того же файла — ошибка, а не тихая потеря данных.
$nestedBlocked = false;
try {
    storeUpdate($file, static function (array $st) use ($file): array {
        storeUpdate($file, static fn(array $x): array => $x);   // так делать нельзя
        return $st;
    });
} catch (Throwable $e) {
    $nestedBlocked = true;
}
check('вложенная правка того же файла запрещена явной ошибкой', $nestedBlocked);

// 5. Повреждённое состояние не выдаётся за «пусто»: иначе поверх испорченных
//    данных запишутся новые и восстанавливать будет нечего.
$bad = DATA_ROOT . '/t-broken.php';
file_put_contents($bad, "<?php exit; ?>\n{ это не json");
$corruptDetected = false;
try { storeRead($bad); } catch (StoreCorruptedException $e) { $corruptDetected = true; }
check('битое состояние останавливает работу, а не читается как пустое', $corruptDetected);

// ОБРЕЗАННЫЙ файл — отдельный случай: начало правильное, конец пропал (кончилось
// место, процесс убит на записи). Именно он чаще всего и читается как «{}»:
// глазами файл выглядит нормальным, а разбор молча даёт пустоту.
$obrezan = DATA_ROOT . '/t-obrezan.php';
file_put_contents($obrezan, "<?php exit; ?>\n{\"счётчик\": 57, \"строки\": [1, 2, 3");
$obrezanPoyman = false;
try { storeRead($obrezan); } catch (StoreCorruptedException $e) { $obrezanPoyman = true; }
check('ОБРЕЗАННЫЙ ФАЙЛ СОСТОЯНИЯ НЕ СТАНОВИТСЯ ПУСТЫМ', $obrezanPoyman,
    'storeRead вернул значение вместо отказа');
$obrezanKopii = glob(DATA_ROOT . '/t-obrezan.bad-*.php') ?: [];
check('копия обрезанного файла отложена рядом', count($obrezanKopii) === 1,
    'копий: ' . count($obrezanKopii));
$brokenCopies = glob(DATA_ROOT . '/t-broken.bad-*.php') ?: [];
check('копия битого файла отложена рядом', count($brokenCopies) === 1);
check('карантин legacy-state перепакован с 404+exit первым байтом',
    count($brokenCopies) === 1
    && privatePhpHas404Prefix((string)file_get_contents($brokenCopies[0])));
check('quarantine-копия получает private-права до данных',
    count($brokenCopies) === 1 && (fileperms($brokenCopies[0]) & 0777) === 0600);

// Карантин может не создаться физически (битый путь/нет места). В таком случае
// исключение не вправе выдавать вычисленное имя за существующую копию.
$notDirectory = DATA_ROOT . '/t-not-directory';
file_put_contents($notDirectory, 'x');
$missingCopy = storeQuarantine($notDirectory . '/broken.php', '{bad');
$missingCopyError = new StoreCorruptedException($notDirectory . '/broken.php', $missingCopy);
check('не созданная quarantine-копия не объявляется существующей',
    $missingCopy === '' && $missingCopyError->backupFile === ''
    && strpos($missingCopyError->getMessage(), 'создать не удалось') !== false);
@unlink($notDirectory);

// Детерминированное имя могло остаться от оборванной записи. Нулевой/частичный
// файл и symlink не являются доказанной копией, даже если is_file() для них true.
$staleRaw = '{старая частичная копия';
$staleBackup = preg_replace('/\.php$/', '', $bad) . '.bad-' . substr(sha1($staleRaw), 0, 8) . '.php';
file_put_contents($staleBackup, BXAPP_PRIVATE_PHP_HEADER);
$staleReported = storeQuarantine($bad, $staleRaw);
check('частичная старая quarantine-копия не подтверждается как полная',
    $staleReported === '' && (string)file_get_contents($staleBackup) === BXAPP_PRIVATE_PHP_HEADER);
@unlink($staleBackup);

$linkRaw = '{копия через ссылку';
$linkBackup = preg_replace('/\.php$/', '', $bad) . '.bad-' . substr(sha1($linkRaw), 0, 8) . '.php';
$linkCreated = function_exists('symlink') && @symlink($file, $linkBackup);
$linkReported = $linkCreated ? storeQuarantine($bad, $linkRaw) : '';
check('symlink не подтверждается как quarantine-копия', !$linkCreated || $linkReported === '');
if ($linkCreated) @unlink($linkBackup);

// Валидный JSON сам по себе ещё не делает файл безопасным: без точного
// Без нашего исполняемого префикса с первого байта web-сервер отдаст state текстом.
$unsafePrefix = DATA_ROOT . '/t-unsafe-prefix.php';
file_put_contents($unsafePrefix, "не php ?>\n{\"access_token\":\"leak\"}");
$unsafePrefixBlocked = false;
try { storeRead($unsafePrefix); } catch (StoreCorruptedException $e) { $unsafePrefixBlocked = true; }
check('state без точной исполняемой заглушки отклоняется даже при валидном JSON', $unsafePrefixBlocked);

// Пустой существующий state — след оборванной записи, а не отсутствие файла.
// Если вернуть здесь null, ближайший storeUpdate молча запишет поверх и лишит
// разработчика возможности увидеть исходную поломку.
$emptyState = DATA_ROOT . '/t-empty.php';
file_put_contents($emptyState, '');
$emptyBlocked = false;
try { storeRead($emptyState); } catch (StoreCorruptedException $e) { $emptyBlocked = true; }
check('пустой существующий state останавливает работу fail-closed', $emptyBlocked);

// Внешняя ошибка может содержать битый байт до секрета. Регулярки с флагом /u
// тогда возвращали null, и маскировщик терял весь текст вместо безопасной
// диагностики. Секрет всё равно обязан исчезнуть, а остальная строка — остаться.
$invalidUtf8Masked = (string)maskSecrets("prefix\xFF access_token=abc123 suffix");
check('битый UTF-8 не отключает маскировку и не стирает всю диагностику',
    $invalidUtf8Masked !== ''
    && strpos($invalidUtf8Masked, 'prefix') === 0
    && strpos($invalidUtf8Masked, 'abc123') === false
    && strpos($invalidUtf8Masked, '***') !== false);

// 5.5. Замок по бизнес-ключу: одна работа по ключу за раз.
suite('Замок по бизнес-ключу');

$ranOnce = withKeyLock('t-work', static fn(): string => 'сделано');
check('работа под замком выполняется и отдаёт свой результат', $ranOnce === 'сделано');

$lockFiles = glob(DATA_ROOT . '/lock-t-work-*.php') ?: [];
check('файл замка создан private и закрыт исполняемым header',
    count($lockFiles) === 1
    && (fileperms($lockFiles[0]) & 0077) === 0
    && privatePhpHas404Prefix((string)file_get_contents($lockFiles[0])));

// Замок обязан сниматься при исключении: иначе одна ошибка внутри работы
// запирает ключ до перезапуска процесса, и приложение молча перестаёт работать.
try {
    withKeyLock('t-work', static function (): void { throw new RuntimeException('шаг упал'); });
} catch (RuntimeException $e) { /* ожидаемо: исключение работы проходит наружу как есть */ }
$again = withKeyLock('t-work', static fn(): string => 'снова можно');
check('исключение внутри работы не оставляет ключ занятым', $again === 'снова можно');

// Вложенный замок того же ключа — ошибка в коде, а не ожидание самого себя.
$nested = null;
try {
    withKeyLock('t-work', static function () use (&$nested): void {
        withKeyLock('t-work', static function (): void { $GLOBALS['__never'] = true; });
    });
} catch (RuntimeException $e) { $nested = $e->getMessage(); }
check('вложенный замок того же ключа отвергается понятной ошибкой, а не зависает',
    is_string($nested) && strpos($nested, 'уже занят') !== false, (string)$nested);

// Разные ключи друг другу не мешают — иначе один общий замок остановит всё.
$other = withKeyLock('t-work', static fn(): string
    => withKeyLock('t-other', static fn(): string => 'параллельно'));
check('замок одного ключа не держит другой ключ', $other === 'параллельно');

// ГОНКА — только вторым процессом. Первый берёт ключ и держит полсекунды,
// второй с нулевым ожиданием обязан получить отказ, а не второй проход работы.
$helperLock = sys_get_temp_dir() . '/bxapp-keylock-race-' . getmypid() . '.php';
file_put_contents($helperLock, "<?php\nrequire " . var_export(dirname(__DIR__) . '/www/env.php', true)
    . ";\nrequire " . var_export(dirname(__DIR__) . '/www/api/store.php', true)
    . ";\n\$hold = (float)(\$argv[1] ?? 0);\n"
    . "try {\n  echo withKeyLock('t-race', function () use (\$hold) { usleep((int)(\$hold * 1000000)); return 'РАБОТА'; }, (float)(\$argv[2] ?? 0));\n"
    . "} catch (Throwable \$e) { echo 'ОТКАЗ'; }\n");
$descrLock = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$envLock = ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')];
$holder = proc_open([PHP_BINARY, $helperLock, '0.6', '0'], $descrLock, $pipesHold, null, $envLock);
usleep(250000);   // дать первому взять замок
$racer  = proc_open([PHP_BINARY, $helperLock, '0', '0'], $descrLock, $pipesRace, null, $envLock);
$outRace = [];
foreach ([[$holder, &$pipesHold], [$racer, &$pipesRace]] as [$p, $pipesOne]) {
    if (!is_resource($p)) { $outRace[] = 'НЕ ЗАПУСТИЛСЯ'; continue; }
    $outRace[] = trim((string)stream_get_contents($pipesOne[1]));
    fclose($pipesOne[1]); fclose($pipesOne[2]);
    proc_close($p);
}
@unlink($helperLock);
check('второй процесс с тем же ключом получает отказ, а не делает работу повторно',
    $outRace === ['РАБОТА', 'ОТКАЗ'], implode(' | ', $outRace));

// Пустой ключ — не «замок на всё подряд», а ошибка вызова.
$emptyKey = null;
try { withKeyLock('  ', static fn(): bool => true); }
catch (InvalidArgumentException $e) { $emptyKey = $e->getMessage(); }
check('пустой ключ отвергается вызовом, а не превращается в общий замок', $emptyKey !== null);

// 5.6. Память о тяжёлом ответе портала.
suite('Память о тяжёлом ответе');

$calls = 0;
$load = static function () use (&$calls): array { $calls++; return ['отделы' => ['Продажи'], 'n' => $calls]; };

$first  = cacheRemember('t-dept', 60, $load);
$second = cacheRemember('t-dept', 60, $load);
check('второй запрос берёт запомненное, а не идёт в портал заново',
    $calls === 1 && $second === $first, 'обращений: ' . $calls);

$forced = cacheRemember('t-dept', 60, $load, true);
check('«Обновить» обходит память и получает свежий ответ',
    $calls === 2 && ($forced['n'] ?? 0) === 2);

$cacheFile = cacheFileFor('t-dept');
check('файл памяти закрыт исполняемым header и правами 0600',
    is_file($cacheFile)
    && (fileperms($cacheFile) & 0077) === 0
    && privatePhpHas404Prefix((string)file_get_contents($cacheFile)));

// Протухание: подменяем срок годности прошлым временем — заново спросить обязан.
storeUpdate($cacheFile, static function (array $row): array { $row['expires'] = time() - 1; return $row; });
cacheRemember('t-dept', 60, $load);
check('устаревшее значение спрашивается заново', $calls === 3);

// Отказ загрузчика не должен застывать в памяти на весь срок.
cacheForget('t-fail');
$failed = null;
try {
    cacheRemember('t-fail', 60, static function (): array { throw new RuntimeException('портал отказал'); });
} catch (RuntimeException $e) { $failed = $e->getMessage(); }
check('отказ загрузчика проходит наружу и ничего не запоминает',
    $failed === 'портал отказал' && !is_file(cacheFileFor('t-fail')));

// null — тоже ответ: «поле пустое» не должно каждый раз ходить в портал.
$nullCalls = 0;
$nullLoad = static function () use (&$nullCalls) { $nullCalls++; return null; };
cacheRemember('t-null', 60, $nullLoad);
cacheRemember('t-null', 60, $nullLoad);
check('пустой ответ запоминается так же, как непустой', $nullCalls === 1);

// Забыть — значит спросить заново.
cacheForget('t-dept');
check('забытое значение исчезает с диска', !is_file($cacheFile));

// Срок годности ограничен сверху: «помним навсегда» — это скрытая поломка,
// при которой смена настроек портала не видна неделями.
$badTtl = null;
try { cacheRemember('t-ttl', 90000, static fn(): bool => true); }
catch (InvalidArgumentException $e) { $badTtl = $e->getMessage(); }
check('срок памяти дольше суток отвергается', $badTtl !== null);

// Уборка при удалении приложения обязана видеть файлы памяти: внутри данные
// портала (структура отделов), и следующему владельцу они не принадлежат.
cacheRemember('t-uninstall', 60, static fn(): array => ['портал' => 'A']);
check('файлы памяти перечисляются для уборки',
    in_array(cacheFileFor('t-uninstall'), cacheFiles(), true));

// ── ГОНКА ЗА ПАМЯТЬЮ — ТОЛЬКО ВТОРЫМ ПРОЦЕССОМ (находка ревью R3-009) ────────
// Рассуждением этот класс не проверяется: в одном процессе замок всегда свободен,
// и обе ветки ниже выглядят исправными. Поэтому оба сценария гоняются двумя
// настоящими процессами с общим стором.
//
//  1. ХОЛОДНАЯ ПАМЯТЬ. Сосед держит замок дольше отведённого, значения на диске
//     ещё нет. Прежний код запускал загрузчик ВНЕ замка: два похода в портал за
//     одним и тем же ответом, и клиенты получали РАЗНЫЕ значения.
//  2. ЗАБВЕНИЕ. `cacheForget()` снимал файл без того же замка. Успешный «забудь»
//     возвращался, пока чужой загрузчик ещё работал, — и через секунду тот
//     публиковал СТАРОЕ значение поверх забытого: оно воскресало и снова
//     считалось свежим.
$cacheHelper = sys_get_temp_dir() . '/bxapp-cache-race-' . getmypid() . '.php';
file_put_contents($cacheHelper, "<?php\n"
    . "require " . var_export(dirname(__DIR__) . '/www/env.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/www/api/store.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/www/api/cache.php', true) . ";\n"
    . "\$rezhim = (string)\$argv[1]; \$klyuch = (string)\$argv[2];\n"
    . "\$sek = (float)\$argv[3]; \$sled = (string)\$argv[4];\n"
    . "try {\n"
    . "  if (\$rezhim === 'forget') { usleep((int)(\$sek * 1000000)); cacheForget(\$klyuch); echo 'ЗАБЫТО'; }\n"
    . "  else {\n"
    . "    \$v = cacheRemember(\$klyuch, 60, function () use (\$sek, \$sled) {\n"
    . "      file_put_contents(\$sled, getmypid() . \"\\n\", FILE_APPEND);\n"
    . "      usleep((int)(\$sek * 1000000));\n"
    . "      return ['кто' => getmypid()];\n"
    . "    });\n"
    . "    echo 'ЗНАЧЕНИЕ:' . (int)(\$v['кто'] ?? 0);\n"
    . "  }\n"
    . "} catch (Throwable \$e) { echo 'ОТКАЗ:' . get_class(\$e); }\n");
$envCache = ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')];
$descrCache = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
/** Забрать вывод пары процессов по порядку. */
$sobrat = static function (array $procs): array {
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        if (!is_resource($p)) { $out[] = 'НЕ ЗАПУСТИЛСЯ'; continue; }
        $out[] = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
    }
    return $out;
};

// 1. Два холодных клиента на один ключ — ровно ОДИН поход в портал.
$sledZagruzok = sys_get_temp_dir() . '/bxapp-cache-loaders-' . getmypid() . '.txt';
@unlink($sledZagruzok);
// Держим замок дольше STORE_LOCK_TIMEOUT: только так второй процесс попадает
// в ту самую ветку «замок занят», ради которой находка и заведена.
$derzhit = (string)(STORE_LOCK_TIMEOUT + 0.6);
$pA = proc_open([PHP_BINARY, $cacheHelper, 'remember', 't-stampede', $derzhit, $sledZagruzok],
    $descrCache, $pipesA, null, $envCache);
usleep(300000);   // дать первому взять замок и уйти в загрузчик
$pB = proc_open([PHP_BINARY, $cacheHelper, 'remember', 't-stampede', '0', $sledZagruzok],
    $descrCache, $pipesB, null, $envCache);
$outCache = $sobrat([[$pA, $pipesA], [$pB, $pipesB]]);
$zagruzok = count(array_filter(explode("\n", (string)@file_get_contents($sledZagruzok)), 'strlen'));
check('ХОЛОДНАЯ ПАМЯТЬ: ДВА ПРОЦЕССА — ОДИН ПОХОД В ПОРТАЛ',
    $zagruzok === 1, 'загрузчиков запущено: ' . $zagruzok);
check('оба клиента получают ОДНО И ТО ЖЕ значение, а не каждый своё',
    $outCache[0] === $outCache[1] && strpos($outCache[0], 'ЗНАЧЕНИЕ:') === 0,
    implode(' | ', $outCache));
@unlink($sledZagruzok);

// 2. Успешный «забудь» не даёт поздней публикации воскресить значение.
$sledZabyt = sys_get_temp_dir() . '/bxapp-cache-forget-' . getmypid() . '.txt';
@unlink($sledZabyt);
@unlink(cacheFileFor('t-forget'));
$pL = proc_open([PHP_BINARY, $cacheHelper, 'remember', 't-forget', '2.5', $sledZabyt],
    $descrCache, $pipesL, null, $envCache);
usleep(600000);   // загрузчик уже в работе, на диске ещё пусто
$pF = proc_open([PHP_BINARY, $cacheHelper, 'forget', 't-forget', '0', $sledZabyt],
    $descrCache, $pipesF, null, $envCache);
$outForget = $sobrat([[$pL, $pipesL], [$pF, $pipesF]]);
clearstatcache();
$posleZabytiya = cachePeek('t-forget');
check('ПОСЛЕ УСПЕШНОГО «ЗАБУДЬ» ПОЗДНИЙ ЗАГРУЗЧИК НЕ ВОСКРЕШАЕТ ЗНАЧЕНИЕ',
    $outForget[1] === 'ЗАБЫТО' && $posleZabytiya['hit'] === false
        && !is_file(cacheFileFor('t-forget')),
    implode(' | ', $outForget) . ' · на диске: ' . var_export(is_file(cacheFileFor('t-forget')), true));
@unlink($sledZabyt);

// 3. Разные ключи одного coordination shard не держат друг друга весь loader.
$collisionA = 't-collision-a';
$collisionB = '';
for ($i = 0; $i < 10000; $i++) {
    $candidate = 't-collision-b-' . $i;
    if ($candidate !== $collisionA && cacheEntryLockKey($candidate) === cacheEntryLockKey($collisionA)) {
        $collisionB = $candidate; break;
    }
}
$sledCollision = sys_get_temp_dir() . '/bxapp-cache-collision-' . getmypid() . '.txt';
@unlink($sledCollision);
$pSlow = proc_open([PHP_BINARY, $cacheHelper, 'remember', $collisionA, '1.8', $sledCollision],
    $descrCache, $pipesSlow, null, $envCache);
$waitUntil = microtime(true) + 2.0;
while (!is_file($sledCollision) && microtime(true) < $waitUntil) usleep(20000);
$startedAt = microtime(true);
$pFast = proc_open([PHP_BINARY, $cacheHelper, 'remember', $collisionB, '0', $sledCollision],
    $descrCache, $pipesFast, null, $envCache);
$fastOut = is_resource($pFast) ? trim((string)stream_get_contents($pipesFast[1])) : 'НЕ ЗАПУСТИЛСЯ';
if (is_resource($pFast)) { fclose($pipesFast[1]); fclose($pipesFast[2]); proc_close($pFast); }
$fastElapsed = microtime(true) - $startedAt;
$slowOut = $sobrat([[$pSlow, $pipesSlow]])[0];
check('РАЗНЫЕ КЛЮЧИ ОДНОГО SHARD: БЫСТРЫЙ LOADER НЕ ЖДЁТ МЕДЛЕННЫЙ',
    $collisionB !== '' && strpos($fastOut, 'ЗНАЧЕНИЕ:') === 0
        && strpos($slowOut, 'ЗНАЧЕНИЕ:') === 0 && $fastElapsed < 1.0,
    'shard=' . cacheEntryLockKey($collisionA) . ' · fast=' . round($fastElapsed, 3)
        . ' · ' . $slowOut . ' | ' . $fastOut);

// 4. Forget другого ключа того же shard тоже не ждёт чужой loader.
cacheRemember($collisionB . '-forget', 60, static fn(): array => ['old' => true]);
$forgetKey = $collisionB . '-forget';
for ($i = 0; $i < 10000 && cacheEntryLockKey($forgetKey) !== cacheEntryLockKey($collisionA); $i++) {
    $forgetKey = 't-collision-forget-' . $i;
}
cacheRemember($forgetKey, 60, static fn(): array => ['old' => true]);
@unlink($sledCollision);
// Берём гарантированную коллизию с точным ключом долгой работы.
$slowForgetKey = $collisionA . '-slow-forget';
for ($i = 0; $i < 10000; $i++) {
    $candidate = 't-slow-forget-' . $i;
    if (cacheEntryLockKey($candidate) === cacheEntryLockKey($forgetKey)) { $slowForgetKey = $candidate; break; }
}
@unlink($sledCollision);
$pSlowForget = proc_open([PHP_BINARY, $cacheHelper, 'remember', $slowForgetKey, '1.8', $sledCollision],
    $descrCache, $pipesSlowForget, null, $envCache);
$waitUntil = microtime(true) + 2.0;
while (!is_file($sledCollision) && microtime(true) < $waitUntil) usleep(20000);
$startedAt = microtime(true);
$pForgetOther = proc_open([PHP_BINARY, $cacheHelper, 'forget', $forgetKey, '0', $sledCollision],
    $descrCache, $pipesForgetOther, null, $envCache);
$forgetOut = is_resource($pForgetOther) ? trim((string)stream_get_contents($pipesForgetOther[1])) : 'НЕ ЗАПУСТИЛСЯ';
if (is_resource($pForgetOther)) { fclose($pipesForgetOther[1]); fclose($pipesForgetOther[2]); proc_close($pForgetOther); }
$forgetElapsed = microtime(true) - $startedAt;
$slowForgetOut = $sobrat([[$pSlowForget, $pipesSlowForget]])[0];
clearstatcache(true, cacheFileFor($forgetKey));
check('РАЗНЫЕ КЛЮЧИ ОДНОГО SHARD: FORGET НЕ ЖДЁТ ЧУЖОЙ LOADER',
    $forgetOut === 'ЗАБЫТО' && strpos($slowForgetOut, 'ЗНАЧЕНИЕ:') === 0
        && $forgetElapsed < 1.0 && !is_file(cacheFileFor($forgetKey)),
    'shard=' . cacheEntryLockKey($forgetKey) . ' · forget=' . round($forgetElapsed, 3)
        . ' · ' . $slowForgetOut . ' | ' . $forgetOut);
@unlink($sledCollision);
@unlink($cacheHelper);

// 6. Журнал действий и защита от повторного выполнения.
suite('Журнал действий и повторные операции');

auditLog('test.action', ['secret_token' => 'abc123', 'id' => 7], 42);
$found = journalQuery(['kind' => 'action', 'limit' => 20])['rows'];
$row = $found[0] ?? [];
check('действие попало в журнал с автором',
    ($row['event'] ?? '') === 'test.action' && (int)($row['uid'] ?? 0) === 42);
check('секрет в журнале замаскирован', ($row['ctx']['secret_token'] ?? '') === '***');
check('у записи есть код обращения, по которому её найдут', ($row['ref'] ?? '') !== '');

// Персональные данные не должны лежать в журнале открытым текстом: он живёт
// две недели и его читают несколько человек.
auditLog('test.person', ['phone' => '+79990001122', 'id' => 8], 42);
$pii = journalQuery(['kind' => 'action', 'limit' => 5])['rows'][0] ?? [];
check('телефон клиента уходит в журнал хешем, а не текстом',
    strpos((string)($pii['ctx']['phone'] ?? ''), 'pii:') === 0, (string)($pii['ctx']['phone'] ?? ''));

// ПРИМАНКА-СЕКРЕТ. Проверять поле мало: секрет попадает в журнал не через поле,
// а через текст ошибки — адрес портала с токеном, ответ сервиса, сообщение
// исключения. Кладём уникальную строку во все такие места и ищем её по ВСЕМУ
// файлу журнала: если она нашлась хоть раз, значит где-то маскировку обошли.
// Приманка выглядит как настоящий токен: латиница и цифры. Кириллическая
// приманка ничего не доказывает — часть правил маскировки ищет именно такой вид.
$primanka = 'PRIMANKA' . bin2hex(random_bytes(6));
appLog('error', 'проба маскировки', [
    'access_token' => $primanka,
    // Секретный ключ обязан закрывать и список, и вложенный объект целиком.
    'вложенное' => ['refresh_token' => $primanka, 'tokens' => [$primanka, $primanka]],
]);
logAppError('вебхук приложения: https://portal.invalid/rest/' . $primanka . $primanka . '/crm.item.add.json');
logAppError('внешний сервис ответил: https://portal.invalid/rest/1/' . $primanka . '/crm.item.add.json');
try {
    throw new RuntimeException('сбой обращения к https://portal.invalid/rest/1/' . $primanka . '/x.json');
} catch (Throwable $e) {
    appLog('error', 'исключение', ['текст' => maskSecrets($e->getMessage())]);
}
auditLog('test.primanka', ['client_secret' => $primanka], 42);
$jur = (string)@file_get_contents(journalFile());
check('ПРИМАНКА-СЕКРЕТ НЕ НАШЛАСЬ В ЖУРНАЛЕ НИ РАЗУ',
    strpos($jur, $primanka) === false,
    'приманка встречается ' . substr_count($jur, $primanka) . ' раз(а)');
check('при этом сами записи в журнале появились',
    strpos($jur, 'проба маскировки') !== false && strpos($jur, 'test.primanka') !== false,
    'записи не найдены — проверка приманки ничего не значит');

// Экран читает журнал страницами по устойчивому ref-cursor. Обычный offset
// сдвигается, когда между кликами пришла новая запись, и повторяет строки.
$pageMarker = 'PAGE-' . bin2hex(random_bytes(5));
for ($i = 1; $i <= 7; $i++) {
    appLog('info', 'test.page', [], ['msg' => $pageMarker . '-' . $i]);
}
$pageOne = journalQuery(['q' => $pageMarker, 'limit' => 3]);
$cursor = (string)($pageOne['rows'][2]['rid'] ?? '');
appLog('info', 'test.page', [], ['msg' => $pageMarker . '-new-after-open']);
$pageTwo = journalQuery(['q' => $pageMarker, 'limit' => 3, 'cursor' => $cursor]);
$refsOne = array_column($pageOne['rows'], 'rid');
$refsTwo = array_column($pageTwo['rows'], 'rid');
check('ЖУРНАЛ: У КАЖДОЙ ЗАПИСИ ЕСТЬ СВОЙ CURSOR, НЕ REQUEST-WIDE REF',
    count($refsOne) === 3 && count(array_unique($refsOne)) === 3
        && !in_array('', $refsOne, true),
    json_encode($refsOne, JSON_UNESCAPED_UNICODE));
check('ЖУРНАЛ: CURSOR ДАЁТ СЛЕДУЮЩУЮ СТРАНИЦУ БЕЗ ПОВТОРОВ ПОСЛЕ НОВОЙ ЗАПИСИ',
    !empty($pageTwo['cursor_found']) && count($refsOne) === 3 && count($refsTwo) === 3
        && array_intersect($refsOne, $refsTwo) === [],
    json_encode([$refsOne, $refsTwo, $pageTwo], JSON_UNESCAPED_UNICODE));
$missingCursorPage = journalQuery(['q' => $pageMarker, 'limit' => 3, 'cursor' => 'missing-page-ref']);
check('ЖУРНАЛ: ПРОПАВШИЙ CURSOR НЕ ВЫДАЁТ ПЕРВУЮ СТРАНИЦУ ЗА СЛЕДУЮЩУЮ',
    empty($missingCursorPage['cursor_found']) && $missingCursorPage['rows'] === []);

// Портал отдаёт телефон и почту НЕ строкой, а мультиполем — и раньше защита на
// этой форме не срабатывала: карточка контакта ложилась в журнал открытым текстом.
auditLog('test.person.multi', ['PHONE' => [['ID' => 1, 'VALUE' => '+79161234567', 'VALUE_TYPE' => 'WORK']],
                              'EMAIL' => [['VALUE' => 'ivan@example.com']]], 42);
$loggedRaw = (string)file_get_contents(journalFile());
check('телефон и почта из карточки портала не попадают в журнал открытым текстом',
    strpos($loggedRaw, '+79161234567') === false && strpos($loggedRaw, 'ivan@example.com') === false);

// Журнал закрыт так же, как остальное private-состояние.
$logToday = journalFile();
check('файл журнала закрыт 404+exit-префиксом',
    is_file($logToday) && privatePhpHas404Prefix((string)file_get_contents($logToday)));
check('журнал подтверждает запись только в файле с private-правами',
    is_file($logToday) && (fileperms($logToday) & 0777) === 0600);

// Активная legacy-установка без generation и нечитаемый tokens-state не дают
// безопасно привязать строку к lifecycle. Обычный журнал остаётся нетронутым,
// ref не обещается, но короткая диагностика уходит в системный error_log.
$fallbackLog = DATA_ROOT . '/journal-fallback-test.log';
$previousErrorLog = (string)ini_get('error_log');
ini_set('error_log', $fallbackLog);
storeWrite(B24_TOKENS_FILE, ['access_token' => 'legacy-active-without-generation']);
appBindLogGeneration(null);
$journalBeforeFallback = (string)file_get_contents($logToday);
$missingGenerationRef = appLog('error', 'test.missing_generation', [], ['msg' => 'диагностика missing']);
$journalAfterMissing = (string)file_get_contents($logToday);
file_put_contents(B24_TOKENS_FILE, "<?php exit; ?>\n{broken");
$unreadableGenerationRef = appLog('error', 'test.unreadable_generation', [], ['msg' => 'диагностика unreadable']);
$fallbackRaw = is_file($fallbackLog) ? (string)file_get_contents($fallbackLog) : '';
check('без generation журнал не обещает ref и не пишет строку без lifecycle-fence',
    $missingGenerationRef === '' && $journalAfterMissing === $journalBeforeFallback);
check('нечитаемый generation тоже не обещает ref, но диагностика не теряется молча',
    $unreadableGenerationRef === ''
    && strpos($fallbackRaw, 'install_generation_missing') !== false
    && strpos($fallbackRaw, 'install_generation_unreadable') !== false
    && strpos($fallbackRaw, 'test.unreadable_generation') !== false);
ini_set('error_log', $previousErrorLog);
@unlink(B24_TOKENS_FILE);
foreach (glob(preg_replace('/\.php$/', '', B24_TOKENS_FILE) . '.bad-*.php') ?: [] as $fallbackCopy) {
    @unlink($fallbackCopy);
}
storeWrite(B24_TOKENS_FILE, []);
appBindLogGeneration(null);
@unlink($fallbackLog);

$runs = 0;
$first  = runOnce('t-op-1', function () use (&$runs) { $runs++; return ['id' => 100]; });
$second = runOnce('t-op-1', function () use (&$runs) { $runs++; return ['id' => 200]; });
check('повторный вызов операции не выполняет работу заново', $runs === 1);
check('повтор возвращает результат первой попытки', ($second['result']['id'] ?? 0) === 100 && $second['repeated'] === true);
check('первая попытка помечена как выполненная впервые', $first['repeated'] === false);

// TTL принадлежит записи, а не следующему случайному вызову runOnce. Короткая
// операция B не должна очистить ещё действующий долгий результат A.
storeWrite(OPS_FILE, ['t-op-long' => [
    'at' => time() - 120, 'expires_at' => time() + 3600, 'ttl' => 3600,
    'status' => 'done', 'result' => ['id' => 501],
]]);
runOnce('t-op-short', static fn(): array => ['id' => 502], 60);
$longRuns = 0;
$longAgain = runOnce('t-op-long', static function () use (&$longRuns): array {
    $longRuns++; return ['id' => 999];
}, 3600);
check('короткий TTL одного ключа не удаляет долгий результат другого',
    $longRuns === 0 && !empty($longAgain['repeated']) && ($longAgain['result']['id'] ?? 0) === 501);

// Сбой работы: исключение обязано дойти до вызывающего — иначе прикладной код
// решит, что операция выполнена. Ловим ИМЕННО его и проверяем, что это оно.
$failed = 0;
$thrown = null;
try {
    runOnce('t-op-2', function () use (&$failed) { $failed++; throw new RuntimeException('сбой'); });
} catch (Throwable $e) {
    $thrown = $e;
}
check('сбой работы доходит до вызывающего, а не проглатывается',
    $thrown instanceof RuntimeException && $thrown->getMessage() === 'сбой',
    $thrown === null ? 'исключения не было' : get_class($thrown) . ': ' . $thrown->getMessage());

// По тексту исключения нельзя узнать, успело ли внешнее действие выполниться.
// Повтор блокируется до явного read-back и runOnceForget().
$blocked = runOnce('t-op-2', function () use (&$failed) { $failed++; return ['ok' => true]; });
check('после любого сбоя слепой повтор блокируется как unknown',
    $failed === 1 && !empty($blocked['repeated']) && !empty($blocked['result']['unknown']),
    'попыток: ' . $failed);
runOnceForget('t-op-2');
$afterReadBack = runOnce('t-op-2', function () use (&$failed) { $failed++; return ['ok' => true]; });
check('после read-back и явного снятия ключа повтор разрешён',
    $failed === 2 && empty($afterReadBack['repeated']));

// TTL чистит только подтверждённые результаты. Неизвестный исход не становится
// безопасным от того, что прошла неделя: без read-back автоматический повтор
// способен второй раз выполнить уже совершившийся внешний эффект.
storeWrite(OPS_FILE, ['t-op-old-unknown' => [
    'at' => time() - 10000, 'status' => 'unknown', 'error' => 'старый неизвестный исход',
]]);
$unknownRuns = 0;
$oldUnknown = runOnce('t-op-old-unknown', function () use (&$unknownRuns) {
    $unknownRuns++; return ['ok' => true];
}, 1);
check('старый unknown не истекает сам и требует явного read-back',
    $unknownRuns === 0 && !empty($oldUnknown['repeated']) && !empty($oldUnknown['result']['unknown']));

// То же для зависшего running: короткий TTL раньше удалял ключ раньше
// пяти минут и разрешал конкурентный дубль. Теперь запись сначала становится
// unknown, а callback не запускается.
storeWrite(OPS_FILE, ['t-op-old-running' => [
    'at' => time() - 600, 'status' => 'running',
]]);
$runningRuns = 0;
$oldRunning = runOnce('t-op-old-running', function () use (&$runningRuns) {
    $runningRuns++; return ['ok' => true];
}, 1);
check('короткий TTL не снимает зависший running и не повторяет эффект',
    $runningRuns === 0 && !empty($oldRunning['repeated']) && !empty($oldRunning['result']['unknown']));

// Возраст не доказывает, что callback умер. Пока аренда ключа реально удерживается,
// запись остаётся running/pending и даже явный forget обязан отказаться.
$liveKey = 't-op-live-long';
[$heldLease, $heldLeasePath] = runOnceLeaseAcquire($liveKey);
storeWrite(OPS_FILE, [$liveKey => [
    'at' => time() - 600, 'status' => 'running', 'attempt' => 'live-attempt', 'ttl' => 3600,
]]);
$liveRuns = 0;
$whileLive = runOnce($liveKey, static function () use (&$liveRuns): array {
    $liveRuns++; return ['ok' => true];
});
$forgetLiveBlocked = false;
try { runOnceForget($liveKey); } catch (RuntimeException $e) { $forgetLiveBlocked = true; }
$stillRunning = storeRead(OPS_FILE)[$liveKey]['status'] ?? '';
check('живой долгий callback не объявляется оборванным только по возрасту',
    is_resource($heldLease) && $liveRuns === 0 && !empty($whileLive['result']['pending'])
    && $forgetLiveBlocked && $stillRunning === 'running');
runOnceLeaseRelease($heldLease, $heldLeasePath);
$afterLeaseGone = runOnce($liveKey, static function () use (&$liveRuns): array {
    $liveRuns++; return ['ok' => true];
});
check('stale running становится unknown только после освобождения реальной аренды',
    $liveRuns === 0 && !empty($afterLeaseGone['result']['unknown']));

// ── Поздний forget не стирает чужую запись (находка ревью R3-002, 09.08.2026) ──
// Экран «Состояние» читает операцию, человек жмёт «Снять». Между чтением и
// снятием ключ успевает уйти и вернуться: его сняли из командной строки, работа
// прошла заново и записала «сделано». Прежний forget удалял то, что лежит ПРЯМО
// СЕЙЧАС, — то есть единственное доказательство, что внешний эффект уже был.
// Следующий вызов runOnce после этого списывал деньги второй раз.
$staleKey = 't-op-stale-forget';
storeWrite(OPS_FILE, [$staleKey => [
    'at' => time() - 60, 'status' => 'unknown', 'attempt' => 'A', 'error' => 'связь оборвалась',
]]);
$seenByScreen = (storeRead(OPS_FILE) ?? [])[$staleKey];   // это увидел человек
storeWrite(OPS_FILE, [$staleKey => [
    'at' => time(), 'expires_at' => time() + 600, 'ttl' => 600,
    'status' => 'done', 'attempt' => 'B', 'result' => ['id' => 4242],
]]);
$staleRefused = false;
try { runOnceForget($staleKey, $seenByScreen); } catch (RuntimeException $e) { $staleRefused = true; }
$afterStale = (storeRead(OPS_FILE) ?? [])[$staleKey] ?? null;
check('ПОЗДНЕЕ СНЯТИЕ НЕ СТИРАЕТ УЖЕ ЗАПИСАННОЕ «СДЕЛАНО»',
    $staleRefused && ($afterStale['status'] ?? '') === 'done' && ($afterStale['attempt'] ?? '') === 'B',
    'осталось: ' . json_encode($afterStale, JSON_UNESCAPED_UNICODE));
$repeatRuns = 0;
$afterStaleRun = runOnce($staleKey, static function () use (&$repeatRuns): array {
    $repeatRuns++; return ['id' => 9999];
}, 600);
check('после отказа поздний forget не открыл дорогу второму внешнему эффекту',
    $repeatRuns === 0 && ($afterStaleRun['result']['id'] ?? 0) === 4242,
    'повторов работы: ' . $repeatRuns);

// Предохранитель обязан выпускать: неизменившийся ключ снимается как прежде.
$sameKey = 't-op-fresh-forget';
storeWrite(OPS_FILE, [$sameKey => ['at' => time(), 'status' => 'unknown', 'attempt' => 'C', 'error' => 'обрыв']]);
runOnceForget($sameKey, (storeRead(OPS_FILE) ?? [])[$sameKey]);
check('неизменившийся ключ с неизвестным исходом снимается как прежде',
    !array_key_exists($sameKey, storeRead(OPS_FILE) ?? []));

// Последний рубеж человека — снятие БЕЗ сверки (командная строка сервера): им
// разжимают журнал операций, упёршийся в потолок, когда экран уже отказывает.
storeWrite(OPS_FILE, [$sameKey => ['at' => time(), 'status' => 'unknown', 'attempt' => 'D']]);
runOnceForget($sameKey);
check('принудительное снятие без сверки состояния по-прежнему работает',
    !array_key_exists($sameKey, storeRead(OPS_FILE) ?? []));

// Сверка бесполезна, если экранная кнопка ею не пользуется: ops.php обязан
// передавать в снятие ту запись, которую сам прочитал, и отвечать на отмену
// отдельной причиной — «состояние изменилось», а не «операция выполняется».
// Живую гонку по HTTP не подстроить (вклиниться между чтением и снятием внутри
// одного запроса нечем), поэтому здесь проверяется вызов на стороне эндпоинта.
$opsSource = (string)@file_get_contents(dirname(__DIR__) . '/www/api/ops.php');
check('кнопка «Снять» отдаёт увиденную запись на сверку, а не снимает вслепую',
    preg_match('~runOnceForget\(\s*\$key\s*,\s*\$rec\s*\)~', $opsSource) === 1,
    'в www/api/ops.php снятие идёт без сверки состояния');
check('отменённое снятие названо своей причиной version_conflict',
    strpos($opsSource, 'RunOnceStaleForgetException') !== false
    && strpos($opsSource, 'version_conflict') !== false);

// 6.5. Пропуска: привязка к порталу и потолок на пользователя.
suite('Пропуска');

require_once dirname(__DIR__) . '/www/api/session.php';

storeWrite(B24_TOKENS_FILE, ['access_token' => 'a', 'refresh_token' => 'r',
    'domain' => 'x.bitrix24.ru', 'member_id' => 'MEMBER-A', 'expires_at' => time() + 3600]);
aclSetOwner(7, 'Владелец');

$mine = createSession(7, 'admin');
check('выданный пропуск помнит, к какому порталу он относится',
    ($mine['member_id'] ?? '') === 'MEMBER-A');
check('свой пропуск действует', findSessionByToken($mine['token']) !== null);

// Приложение переустановили на другой портал.
storeUpdate(B24_TOKENS_FILE, static function (array $t): array { $t['member_id'] = 'MEMBER-B'; return $t; });
check('пропуск прежнего портала перестаёт действовать', findSessionByToken($mine['token']) === null);

// Токенов нет вовсе (стёрли или файл повреждён) — определить свой портал нечем.
$saveTokens = storeRead(B24_TOKENS_FILE);
storeWrite(B24_TOKENS_FILE, []);
$orphan = str_repeat('9', 64);
storeWrite(SESSIONS_FILE, [['token' => $orphan, 'uid' => 7, 'role' => 'admin',
    'member_id' => 'MEMBER-A', 'createdAt' => time(), 'expiresAt' => time() + 3600]]);
check('без известного портала пропуск не принимается (fail-closed)', findSessionByToken($orphan) === null);
storeWrite(B24_TOKENS_FILE, $saveTokens ?? []);

// ...а вот с постоянным ключом портал известен, и пропуск обязан действовать.
// Живой случай 18.08.2026: приложение работало ключом, страница открывалась, но
// «свой портал» брался только из токенов установки — которых при этом канале нет
// вовсе. Каждый выданный пропуск тут же гасился как чужой, и любой экран
// приложения отвечал «доступ только из Bitrix24». Проверяем отдельным процессом:
// ключ читается на старте, в уже запущенном его не подменить.
$klyuchKoren = sys_get_temp_dir() . '/bxapp-sess-wh-' . getmypid();
@mkdir($klyuchKoren, 0700, true);
testRegisterTemp($klyuchKoren); // уборки не было: каталог оставался после каждого прогона
$klyuchProba = $klyuchKoren . '-probe.php';
file_put_contents($klyuchProba, '<?php' . "\n"
    . 'require_once ' . var_export(dirname(__DIR__) . '/www/api/session.php', true) . ';' . "\n"
    . 'aclSetOwner(7, \'Владелец\');' . "\n"
    . '$s = createSession(7, \'admin\');' . "\n"
    . 'echo json_encode([\'portal\' => (string)($s[\'member_id\'] ?? \'\'),' . "\n"
    . '  \'deystvuet\' => findSessionByToken((string)$s[\'token\']) !== null], JSON_UNESCAPED_UNICODE);' . "\n");
$klyuchDesc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$klyuchProc = proc_open([PHP_BINARY, $klyuchProba], $klyuchDesc, $klyuchPipes, dirname(__DIR__), [
    'BXAPP_DATA_ROOT' => $klyuchKoren, 'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    'BXAPP_WEBHOOK_URL' => 'https://portal.box.invalid/rest/35/klyuchportala777/',
]);
$klyuchOut = '';
if (is_resource($klyuchProc)) {
    fclose($klyuchPipes[0]);
    $klyuchOut = (string)stream_get_contents($klyuchPipes[1]);
    (string)stream_get_contents($klyuchPipes[2]);
    fclose($klyuchPipes[1]); fclose($klyuchPipes[2]); proc_close($klyuchProc);
}
$klyuchJson = json_decode(trim($klyuchOut), true);
check('С ПОСТОЯННЫМ КЛЮЧОМ ПРОПУСК ДЕЙСТВУЕТ, А НЕ ГАСНЕТ КАК ЧУЖОЙ',
    is_array($klyuchJson) && ($klyuchJson['deystvuet'] ?? null) === true
    && strpos((string)($klyuchJson['portal'] ?? ''), 'portal.box.invalid') !== false,
    'ответ пробы: ' . mb_substr($klyuchOut, 0, 200));
@unlink($klyuchProba);

// Потолок на пользователя: один человек не должен раздувать файл пропусков.
storeWrite(SESSIONS_FILE, []);
for ($i = 0; $i < SESSION_MAX_PER_USER + 6; $i++) createSession(7, 'admin');
$mineNow = array_filter(storeRead(SESSIONS_FILE) ?? [], static fn($s) => (int)($s['uid'] ?? 0) === 7);
check('число пропусков одного сотрудника ограничено',
    count($mineNow) <= SESSION_MAX_PER_USER, 'на диске: ' . count($mineNow));

// А вытесняется тот, которым дольше всего не пользовались, — не тот, что выдан
// раньше всех. Иначе вкладка, открытая утром и живая весь день, вылетает первой:
// человек посреди работы получает «Доступ запрещён», ничего для этого не сделав.
storeWrite(SESSIONS_FILE, []);
$morning = createSession(7, 'admin');                    // открыта утром, ею пользуются
for ($i = 0; $i < SESSION_MAX_PER_USER - 1; $i++) createSession(7, 'admin');
storeUpdate(SESSIONS_FILE, static function (array $all) use ($morning): array {
    foreach ($all as $i => $row) {
        // Утренняя вкладка: выдана давно, но запрос был только что.
        $isMorning = hash_equals((string)($row['token'] ?? ''), $morning['token']);
        $all[$i]['createdAt'] = $isMorning ? time() - 7000 : time();
        $all[$i]['lastSeen']  = $isMorning ? time()        : time() - 6000;
    }
    return $all;
});
createSession(7, 'admin');                               // 17-й пропуск — кого-то вытеснит
$tokensNow = array_column(storeRead(SESSIONS_FILE) ?? [], 'token');
check('переполнение выбрасывает давно не используемый пропуск, а не активную вкладку',
    in_array($morning['token'], $tokensNow, true));

// 6.9. Разбор списков: элементы проверяются, а не пропускаются оптом.
suite('Разбор входа');
$listOk = apiList(['ids' => ['7', 8]], 'ids', ['items' => 'int']);
check('список чисел приводится к числам', $listOk === [7, 8]);
// Проверка «вложенная структура отклоняется» требует HTTP-контекста (apiFail
// завершает запрос), поэтому здесь смотрим только положительный путь; отказ
// проверяется живым запросом в http_test.

// 7. Роли: сравнение «не ниже» — одно на всё приложение.
suite('Роли');
check('администратор проходит проверку на право правки', roleAtLeast('admin', 'edit'));
check('право просмотра не даёт править',              !roleAtLeast('view', 'edit'));
check('отсутствие роли не даёт ничего',               !roleAtLeast(null, 'view'));

// 8. ТОЧКИ ОТКАЗА: «а что, если процесс умрёт ровно здесь».
//
// Самые дорогие ошибки живут в местах обрыва, а не в обычном пути. Раньше такие
// сценарии проверялись рассуждением — то есть не проверялись. Теперь у записи
// состояния есть две точки, которые испытание включает и обрывает выполнение
// именно там, где страшно.
suite('Точки отказа при записи состояния');

$tochkaFile = DATA_ROOT . '/tochka.php';
storeWrite($tochkaFile, ['версия' => 'первая']);

// Обрыв ДО публикации: на диске обязана остаться прежняя версия целиком.
$GLOBALS['__failpoints']['store.before_rename'] = true;
$oborvalos = '';
try { storeWrite($tochkaFile, ['версия' => 'вторая']); }
catch (Throwable $e) { $oborvalos = $e->getMessage(); }
check('обрыв до публикации действительно происходит', strpos($oborvalos, 'точка отказа') !== false, $oborvalos);
check('ПОСЛЕ ОБРЫВА ДО ПУБЛИКАЦИИ НА ДИСКЕ ПРЕЖНЕЕ СОСТОЯНИЕ ЦЕЛИКОМ',
    (storeRead($tochkaFile)['версия'] ?? '') === 'первая',
    json_encode(storeRead($tochkaFile), JSON_UNESCAPED_UNICODE));

// Мусорный временный файл при этом остаётся рядом — и он не должен мешать
// следующей записи и не должен читаться как состояние.
$musor = glob(DATA_ROOT . '/tochka.php.tmp-*') ?: [];
check('временный файл после обрыва не подменяет собой состояние',
    count($musor) >= 1 && (storeRead($tochkaFile)['версия'] ?? '') === 'первая',
    'временных файлов: ' . count($musor));

// Обрыв СРАЗУ ПОСЛЕ публикации: состояние уже новое, а вызывающий об этом не
// узнал. Именно отсюда берётся «повторил — получил второе».
$GLOBALS['__failpoints']['store.after_rename'] = true;
$oborvalos2 = '';
try { storeWrite($tochkaFile, ['версия' => 'третья']); }
catch (Throwable $e) { $oborvalos2 = $e->getMessage(); }
check('обрыв после публикации происходит', strpos($oborvalos2, 'точка отказа') !== false, $oborvalos2);
check('ПОСЛЕ ОБРЫВА ПОСЛЕ ПУБЛИКАЦИИ ЗАПИСЬ УЖЕ ПРИМЕНЕНА',
    (storeRead($tochkaFile)['версия'] ?? '') === 'третья',
    json_encode(storeRead($tochkaFile), JSON_UNESCAPED_UNICODE));

// Точка срабатывает ОДИН раз: иначе из неё не выйти, и следующая запись в том же
// прогоне падала бы без причины.
$posle = true;
try { storeWrite($tochkaFile, ['версия' => 'четвёртая']); }
catch (Throwable $e) { $posle = false; }
check('точка отказа срабатывает один раз и не ломает остальной прогон',
    $posle && (storeRead($tochkaFile)['версия'] ?? '') === 'четвёртая',
    json_encode(storeRead($tochkaFile), JSON_UNESCAPED_UNICODE));

// Обрыв ПОСЛЕ публикации и перечитывания, но ДО возврата: состояние на диске
// новое, а наверх ушла ошибка. Это самый обманчивый случай — вызывающий видит
// сбой и хочет повторить, а работа уже сделана.
$GLOBALS['__failpoints']['store.after_reread'] = true;
$oborvalos3 = '';
try { storeUpdate($tochkaFile, static fn(array $p): array => ['версия' => 'пятая']); }
catch (Throwable $e) { $oborvalos3 = $e->getMessage(); }
check('обрыв после перечитывания происходит',
    strpos($oborvalos3, 'точка отказа') !== false, $oborvalos3);
check('ПОСЛЕ ОБРЫВА НА ПЕРЕЧИТЫВАНИИ ЗАПИСЬ УЖЕ ПРИМЕНЕНА, ХОТЯ ВЕРНУЛАСЬ ОШИБКА',
    (storeRead($tochkaFile)['версия'] ?? '') === 'пятая',
    json_encode(storeRead($tochkaFile), JSON_UNESCAPED_UNICODE));
// Замок при этом обязан быть снят: иначе следующая запись встанет намертво и
// вместо честного «неизвестно» приложение получит зависание.
$zamokSnyat = true;
try { storeWrite($tochkaFile, ['версия' => 'шестая']); }
catch (Throwable $e) { $zamokSnyat = false; }
check('после обрыва на перечитывании замок снят и следующая запись проходит',
    $zamokSnyat && (storeRead($tochkaFile)['версия'] ?? '') === 'шестая',
    json_encode(storeRead($tochkaFile), JSON_UNESCAPED_UNICODE));

// И главное: без подменённого стора точек отказа не существует. На боевом
// сервере включить их снаружи нечем.
$boevoy = getenv('BXAPP_DATA_ROOT');
putenv('BXAPP_DATA_ROOT=');
$GLOBALS['__failpoints']['store.before_rename'] = true;
$naboyu = true;
try { failpoint('store.before_rename'); } catch (Throwable $e) { $naboyu = false; }
putenv('BXAPP_DATA_ROOT=' . (string)$boevoy);
unset($GLOBALS['__failpoints']['store.before_rename']);
check('НА БОЕВОМ ТОЧЕК ОТКАЗА НЕ СУЩЕСТВУЕТ', $naboyu, 'точка сработала без подменённого стора');

// ── ОДНОВРЕМЕННАЯ ЗАПИСЬ НАСТОЯЩИМИ ПРОЦЕССАМИ ──────────────────────────────
// До сих пор замок проверялся внутри одного процесса: вложенный вызов, чужой
// ключ, снятие после исключения. Но теряется правка именно между ПРОЦЕССАМИ:
// два запроса читают состояние, каждый прибавляет своё и пишет целиком — и тот,
// кто записал вторым, затирает первого. Ошибки при этом нет, счётчик просто
// меньше, чем должен быть, и заметить это можно только сверкой.
suite('Одновременная запись состояния разными процессами');

$gonkaFile = DATA_ROOT . '/gonka.php';
storeWrite($gonkaFile, ['счёт' => 0, 'кто' => []]);

$RABOTNIKOV = 4;
$SHAGOV = 15;
$rabotniki = [];
for ($nomer = 0; $nomer < $RABOTNIKOV; $nomer++) {
    // Дочерний процесс поднимает store сам и работает через storeUpdate —
    // ровно так, как это делает боевой запрос.
    $kod = '$root=' . var_export($root, true) . ';'
        . 'putenv(' . var_export('BXAPP_DATA_ROOT=' . DATA_ROOT, true) . ');'
        . "require \$root.'/www/env.php';require \$root.'/www/api/store.php';"
        . '$file=' . var_export($gonkaFile, true) . ';$nomer=' . $nomer . ';'
        . 'for($i=0;$i<' . $SHAGOV . ';$i++){'
        . 'storeUpdate($file, function(array $s) use ($nomer): array {'
        . '$s["счёт"]=(int)($s["счёт"]??0)+1;'
        . '$s["кто"][$nomer]=(int)($s["кто"][$nomer]??0)+1;return $s;});}';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $kod], $desc, $pipes, $root);
    if (!is_resource($proc)) { $rabotniki = []; break; }
    fclose($pipes[0]);
    $rabotniki[] = [$proc, $pipes];
}
$rabotnikiOk = $rabotniki !== [];
foreach ($rabotniki as [$proc, $pipes]) {
    $vyvod = (string)stream_get_contents($pipes[1]);
    $oshibki = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $vyvod !== '' || $oshibki !== '') {
        $rabotnikiOk = false;
    }
}
check('все процессы отработали без ошибок и без вывода', $rabotnikiOk);

$itog = storeRead($gonkaFile) ?? [];
check('НИ ОДНА ПРАВКА НЕ ПОТЕРЯЛАСЬ ПРИ ОДНОВРЕМЕННОЙ ЗАПИСИ',
    (int)($itog['счёт'] ?? 0) === $RABOTNIKOV * $SHAGOV,
    'ожидали ' . ($RABOTNIKOV * $SHAGOV) . ', в файле ' . (int)($itog['счёт'] ?? 0));
check('вклад каждого процесса виден целиком, а не частью',
    count($itog['кто'] ?? []) === $RABOTNIKOV
    && array_sum($itog['кто'] ?? []) === $RABOTNIKOV * $SHAGOV,
    json_encode($itog['кто'] ?? [], JSON_UNESCAPED_UNICODE));
check('после гонки файл состояния читается как обычно, без карантина',
    is_array($itog) && (glob(DATA_ROOT . '/gonka.bad-*.php') ?: []) === [],
    json_encode(glob(DATA_ROOT . '/gonka.bad-*.php') ?: []));
$vremennye = glob(DATA_ROOT . '/gonka.php.tmp-*') ?: [];
check('временные файлы гонки за собой убраны', $vremennye === [],
    'осталось: ' . count($vremennye));

// ── НЕДОСТУПНЫЙ ФАЙЛ СОСТОЯНИЯ — ЭТО НЕ ПОВРЕЖДЁННЫЙ ФАЙЛ ─────────────────
//
// ЖИВОЙ СЛУЧАЙ 05.09.2026 (приложение kg-analizseo). Владелец открыл экран и
// увидел: «Данные приложения повреждены. Файл с сохранённым состоянием не
// читается… покажите разработчику код обращения». Данные были ЦЕЛЫ до
// последнего байта: у файла сменился владелец, и процесс приложения просто не
// имел права его прочитать.
//
// Различие важно, потому что действия разные: повреждение разбирает
// разработчик по отложенной копии, отказ в доступе чинит администратор одной
// командой. Одно сообщение на оба случая уводит по ложному следу.
//
// ВТОРОЙ ДЕФЕКТ ТОЙ ЖЕ ПРИЧИНЫ: не сумев прочитать файл, приложение
// откладывало в карантин ПУСТУЮ копию — улики в ней нет, а в каталоге
// состояния она выглядит как след аварии, которой не было.

$dostupDir = DATA_ROOT . '/dostup-' . bin2hex(random_bytes(4));
@mkdir($dostupDir, 0700, true);
$dostupFile = $dostupDir . '/sostoyanie.php';
file_put_contents($dostupFile, BXAPP_PRIVATE_PHP_HEADER . '{"jobs":[{"id":"a1"}]}');

$celoe = storeRead($dostupFile);
check('целое состояние читается как прежде',
    is_array($celoe) && count($celoe['jobs'] ?? []) === 1);

// Под root права не действуют — он читает что угодно, и проверка была бы
// пустой. Тогда честно говорим, что она не выполнена, вместо зелёной галочки.
$otKogo = function_exists('posix_geteuid') ? posix_geteuid() : 0;
if ($otKogo === 0) {
    check('отказ в доступе к состоянию назван своим именем', true,
        'ПРОПУЩЕНА: прогон идёт от root, права на него не действуют');
    check('в сообщении об отказе названы права доступа', true, 'ПРОПУЩЕНА: прогон от root');
    check('пустая копия при отказе в доступе не откладывается', true, 'ПРОПУЩЕНА: прогон от root');
} else {
    chmod($dostupFile, 0000);
    $poluchilos = @file_get_contents($dostupFile);
    if ($poluchilos !== false) {
        check('отказ в доступе к состоянию назван своим именем', false,
            'файл всё равно читается — случай не воспроизвёлся');
        check('в сообщении об отказе названы права доступа', false, 'случай не воспроизвёлся');
        check('пустая копия при отказе в доступе не откладывается', false, 'случай не воспроизвёлся');
    } else {
        $klassOtkaza = '';
        $tekstOtkaza = '';
        try {
            storeRead($dostupFile);
        } catch (Throwable $e) {
            $klassOtkaza = get_class($e);
            $tekstOtkaza = $e->getMessage();
        }
        check('отказ в доступе к состоянию назван своим именем',
            $klassOtkaza === 'StoreUnreadableException',
            'получили ' . ($klassOtkaza === '' ? 'НИ ОДНОЙ ошибки' : $klassOtkaza . ': ' . $tekstOtkaza)
                . ' — человек прочтёт «данные повреждены» и пойдёт искать порчу вместо прав');
        check('в сообщении об отказе названы права доступа',
            mb_stripos($tekstOtkaza, 'прав') !== false || mb_stripos($tekstOtkaza, 'доступ') !== false,
            'сказано: ' . $tekstOtkaza);
        $pustye = glob($dostupDir . '/sostoyanie.bad-*.php') ?: [];
        check('пустая копия при отказе в доступе не откладывается', $pustye === [],
            'отложено файлов: ' . count($pustye) . ' — улики в них нет, разбирать нечего');
    }
    chmod($dostupFile, 0600);
}

// Обратный край: настоящая порча по-прежнему останавливает приложение, и копия
// откладывается С СОДЕРЖИМЫМ. Эта защита сберегла данные владельца в том же
// случае — ослаблять её нельзя.
$bityyFile = $dostupDir . '/bityy.php';
file_put_contents($bityyFile, BXAPP_PRIVATE_PHP_HEADER . '{"jobs":[{"id":');
$klassPorchi = '';
try {
    storeRead($bityyFile);
} catch (Throwable $e) {
    $klassPorchi = get_class($e);
}
check('битое содержимое по-прежнему останавливает приложение',
    $klassPorchi === 'StoreCorruptedException',
    'получили ' . ($klassPorchi === '' ? 'ничего' : $klassPorchi));
$kopiiPorchi = glob($dostupDir . '/bityy.bad-*.php') ?: [];
check('копия повреждённого файла откладывается С СОДЕРЖИМЫМ',
    count($kopiiPorchi) === 1
    && strpos((string)file_get_contents($kopiiPorchi[0]), '{"jobs"') !== false,
    'без содержимого разбирать в ней нечего');

foreach (glob($dostupDir . '/*') ?: [] as $f) { @chmod($f, 0600); @unlink($f); }
@rmdir($dostupDir);
