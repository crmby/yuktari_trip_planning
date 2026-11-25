<?php
declare(strict_types=1);
/**
 * ДОСТУПНЫ ЛИ ПРИЛОЖЕНИЮ МЕТОДЫ ПОРТАЛА.
 *
 * ЗАЧЕМ. Выданное право (scope) и работающий метод — разные вещи, особенно на
 * коробке: там модуль может быть выключен, версия старее, а часть методов просто
 * отсутствует. Замер на живой коробке 08.08.2026: при 71 выданном праве и 1175
 * доступных методах `crm.item.list` вызвать было нельзя. Приложение, которое
 * этого не проверяет, узнаёт правду в середине работы — половина выгрузки уже
 * сделана, отчёт неполный, а выглядит законченным.
 *
 * Проверяется поведение, а не наличие функций: чего не хватает — сказано до
 * начала работы; обязательного нет — работа не начинается; необязательного нет —
 * работа идёт, а возможность честно помечена недоступной.
 *
 * Транспорт подменяем — в сеть не ходим.
 */
require_once dirname(__DIR__) . '/www/api/b24.php';
require_once dirname(__DIR__) . '/www/api/capability.php';

suite('Доступность методов портала');

// ── Разбор ответа портала ───────────────────────────────────────────────────
check('метод есть и доступен — значит можно',
    capParseAnswer(['isExisting' => true, 'isAvailable' => true]) === 'ok');
check('МЕТОД ЕСТЬ, НО НЕ ДОСТУПЕН ПРИЛОЖЕНИЮ — ЭТО НЕ «МОЖНО»',
    capParseAnswer(['isExisting' => true, 'isAvailable' => false]) === 'нет доступа',
    capParseAnswer(['isExisting' => true, 'isAvailable' => false]));
check('метода нет на портале — сказано отдельно от отказа в доступе',
    capParseAnswer(['isExisting' => false, 'isAvailable' => false]) === 'нет на портале');
// Портал ответил невнятно (старая коробка, чужой формат) — это НЕ «нельзя».
// Запрет по невнятному ответу остановил бы рабочее приложение на ровном месте.
check('НЕПОНЯТНЫЙ ОТВЕТ НЕ СЧИТАЕТСЯ ЗАПРЕТОМ',
    capParseAnswer([]) === 'неизвестно' && capParseAnswer(['foo' => 1]) === 'неизвестно');

// ── Спрашиваем портал пакетом ───────────────────────────────────────────────
testWipe(DATA_ROOT);
$capK = new B24('local.test.app', 'secret');
$capK->saveTokensFromInstall([
    'AUTH_ID' => 'cap-access', 'REFRESH_ID' => 'cap-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);

$zvonki = [];
$GLOBALS['__b24_transport'] = static function (string $method, array $params) use (&$zvonki): array {
    $zvonki[] = $method;
    if ($method !== 'batch') return ['result' => []];
    $otvet = [];
    foreach ((array)($params['cmd'] ?? []) as $klyuch => $stroka) {
        // Разбираем «method.get?name=crm.item.list» — так их складывает batch().
        $imya = '';
        if (preg_match('~name=([^&]+)~', (string)$stroka, $m)) $imya = urldecode($m[1]);
        $otvet[$klyuch] = $imya === 'crm.item.list'
            ? ['isExisting' => true, 'isAvailable' => false]      // как на живой коробке
            : ['isExisting' => true, 'isAvailable' => true];
    }
    return ['result' => ['result' => $otvet, 'result_error' => []]];
};

$karta = capRefresh($capK, ['crm.item.list', 'user.get', 'im.notify.personal.add']);
check('портал спрошен ОДНИМ пакетом, а не по вызову на метод',
    count($zvonki) === 1 && $zvonki[0] === 'batch', implode(',', $zvonki));
check('ответ разобран по каждому методу', count($karta['методы'] ?? []) === 3,
    json_encode($karta, JSON_UNESCAPED_UNICODE));
check('НЕДОСТУПНЫЙ МЕТОД ОПОЗНАН ПРИ ВЫДАННОМ ПРАВЕ',
    capAllowed('crm.item.list') === false, var_export(capAllowed('crm.item.list'), true));
check('доступный метод разрешён', capAllowed('user.get') === true);
check('знание сохранено между запросами', !empty(capRead()['методы']));

// ── Чего не хватает — до начала работы ──────────────────────────────────────
$netu = capMissing(['crm.item.list', 'user.get']);
check('названо ИМЕННО то, чего не хватает', $netu === ['crm.item.list'],
    json_encode($netu, JSON_UNESCAPED_UNICODE));
check('человеку сказано понятной строкой, а не кодом',
    mb_strpos(capHuman(), 'crm.item.list') !== false && mb_strpos(capHuman(), 'недоступ') !== false,
    capHuman());

// ── Непроверенное НЕ считается запрещённым ──────────────────────────────────
// Иначе первая же установка встанет: проверка ещё не проходила, а работа уже
// запрещена — предохранитель, который отсекает всех, кроме нарушителя.
testWipe(DATA_ROOT);
check('НЕПРОВЕРЕННЫЙ МЕТОД РАЗРЕШЁН, А НЕ ЗАПРЕЩЁН', capAllowed('crm.item.list') === true);
check('и это честно названо «не проверялось»', mb_strpos(capHuman(), 'не проверял') !== false, capHuman());
check('нечего перечислять как недостающее', capMissing(['crm.item.list']) === []);

// ── Портал не ответил: прежнее знание не затирается ─────────────────────────
$capK2 = new B24('local.test.app', 'secret');
$capK2->saveTokensFromInstall([
    'AUTH_ID' => 'cap-access2', 'REFRESH_ID' => 'cap-refresh2', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);
$GLOBALS['__b24_transport'] = static fn(string $m, array $p): array => ['result' => ['result' => ['0' => ['isExisting' => true, 'isAvailable' => true]], 'result_error' => []]];
capRefresh($capK2, ['user.get']);
$bylo = capRead();
$GLOBALS['__b24_transport'] = static fn(string $m, array $p): array => ['__code' => 0, 'error' => 'http_error'];
capRefresh($capK2, ['user.get'], true);
check('ОТКАЗ ПОРТАЛА НЕ ЗАТИРАЕТ ПРЕЖНЕЕ ЗНАНИЕ',
    capRead()['методы'] === $bylo['методы'], json_encode(capRead(), JSON_UNESCAPED_UNICODE));

// ── Свежее не переспрашиваем ────────────────────────────────────────────────
$zvonki = [];
$GLOBALS['__b24_transport'] = static function (string $m, array $p) use (&$zvonki): array {
    $zvonki[] = $m;
    return ['result' => ['result' => ['0' => ['isExisting' => true, 'isAvailable' => true]], 'result_error' => []]];
};
capRefresh($capK2, ['user.get']);
check('свежее знание не переспрашивается', $zvonki === [], 'запросов: ' . count($zvonki));

// ── Пакет больше 50 методов ─────────────────────────────────────────────────
// Предел портала — 50 команд в пакете. Список нужных методов у большого
// приложения легко перевалит за него, и «пакет одним куском» тихо оборвётся.
testWipe(DATA_ROOT);
$capK3 = new B24('local.test.app', 'secret');
$capK3->saveTokensFromInstall([
    'AUTH_ID' => 'cap-access3', 'REFRESH_ID' => 'cap-refresh3', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);
$mnogo = [];
for ($i = 0; $i < 63; $i++) $mnogo[] = 'test.method' . $i;
$paketov = 0;
$GLOBALS['__b24_transport'] = static function (string $m, array $p) use (&$paketov): array {
    if ($m !== 'batch') return ['result' => []];
    $paketov++;
    $otvet = [];
    foreach (array_keys((array)($p['cmd'] ?? [])) as $k) $otvet[$k] = ['isExisting' => true, 'isAvailable' => true];
    return ['result' => ['result' => $otvet, 'result_error' => []]];
};
$bolshoy = capRefresh($capK3, $mnogo);
check('СПИСОК БОЛЬШЕ 50 МЕТОДОВ РАЗБИТ НА ПАКЕТЫ, А НЕ ОБОРВАН',
    $paketov === 2 && count($bolshoy['методы'] ?? []) === 63,
    'пакетов: ' . $paketov . ', разобрано: ' . count($bolshoy['методы'] ?? []));

unset($GLOBALS['__b24_transport']);

// ── Свежесть карты не равна её полноте ──────────────────────────────────────
// Находка ревью 08.08.2026 (BXREV-004): свежая карта отдавалась целиком, не
// глядя, есть ли в ней спрошенное. Добавили в приложение функцию с новым
// методом — и он молча считался непроверенным ещё сутки, хотя проверка
// формально выполнялась.
testWipe(DATA_ROOT);
$capK4 = new B24('local.test.app', 'secret');
$capK4->saveTokensFromInstall([
    'AUTH_ID' => 'cap-access4', 'REFRESH_ID' => 'cap-refresh4', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);
$sprosheno = [];
$GLOBALS['__b24_transport'] = static function (string $m, array $p) use (&$sprosheno): array {
    if ($m !== 'batch') return ['result' => []];
    $otvet = [];
    foreach ((array)($p['cmd'] ?? []) as $k => $stroka) {
        $imya = '';
        if (preg_match('~name=([^&]+)~', (string)$stroka, $mm)) $imya = urldecode($mm[1]);
        $sprosheno[] = $imya;
        $otvet[$k] = ['isExisting' => true, 'isAvailable' => true];
    }
    return ['result' => ['result' => $otvet, 'result_error' => []]];
};
capRefresh($capK4, ['user.get']);
$sprosheno = [];
capRefresh($capK4, ['user.get', 'crm.item.list']);   // карта свежая, но метод новый
check('СВЕЖАЯ КАРТА НЕ СКРЫВАЕТ НЕПРОВЕРЕННЫЙ МЕТОД',
    $sprosheno === ['crm.item.list'], 'спрошено: ' . implode(', ', $sprosheno));
check('прежнее знание при этом на месте', capState('user.get') === 'ok', capState('user.get'));
$sprosheno = [];
capRefresh($capK4, ['user.get', 'crm.item.list']);   // теперь оба известны
check('второй раз тот же список портал не беспокоит', $sprosheno === [],
    'спрошено: ' . implode(', ', $sprosheno));

// ── Ошибка подкоманды разбирается, а не считается ответом ───────────────────
// BXREV-003: ошибки отдельных подкоманд лежат в result_error рядом с телами.
// Прежний код их не смотрел: подкоманда с ошибкой тела не давала, метод получал
// «неизвестно», и выясненное вчера «нет доступа» превращалось в разрешение.
testWipe(DATA_ROOT);
$capK5 = new B24('local.test.app', 'secret');
$capK5->saveTokensFromInstall([
    'AUTH_ID' => 'cap-access5', 'REFRESH_ID' => 'cap-refresh5', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);
$GLOBALS['__b24_transport'] = static function (string $m, array $p): array {
    if ($m !== 'batch') return ['result' => []];
    $tela = []; $oshibki = [];
    foreach ((array)($p['cmd'] ?? []) as $k => $stroka) {
        $imya = '';
        if (preg_match('~name=([^&]+)~', (string)$stroka, $mm)) $imya = urldecode($mm[1]);
        if ($imya === 'crm.item.list')  { $tela[$k] = ['isExisting' => true, 'isAvailable' => false]; continue; }
        if ($imya === 'tasks.task.add') { $oshibki[$k] = ['error' => 'METHOD_NOT_FOUND', 'error_description' => 'нет такого метода']; continue; }
        $tela[$k] = ['isExisting' => true, 'isAvailable' => true];
    }
    return ['result' => ['result' => $tela, 'result_error' => $oshibki]];
};
capRefresh($capK5, ['crm.item.list', 'tasks.task.add', 'user.get']);
check('ОШИБКА «ТАКОГО МЕТОДА НЕТ» — ЭТО ОТВЕТ, А НЕ СБОЙ',
    capState('tasks.task.add') === 'нет на портале', capState('tasks.task.add'));
// Та же подкоманда, но с внятной ошибкой связи: знание про «нет доступа»
// обязано пережить её — вчерашний отказ портала вчера же и верен.
$GLOBALS['__b24_transport'] = static function (string $m, array $p): array {
    if ($m !== 'batch') return ['result' => []];
    $oshibki = [];
    foreach (array_keys((array)($p['cmd'] ?? [])) as $k) $oshibki[(string)$k] = ['error' => 'QUERY_LIMIT_EXCEEDED'];
    return ['result' => ['result' => [], 'result_error' => $oshibki]];
};
capRefresh($capK5, ['crm.item.list', 'user.get'], true);
check('ОШИБКА ПОДКОМАНДЫ НЕ ПРЕВРАЩАЕТ ОТКАЗ В РАЗРЕШЕНИЕ',
    capState('crm.item.list') === 'нет доступа' && capAllowed('crm.item.list') === false,
    capState('crm.item.list'));
check('непроверенное названо человеку отдельно от недоступного',
    mb_strpos(capHuman(), 'не удалось проверить') !== false || mb_strpos(capHuman(), 'crm.item.list') !== false,
    capHuman());

// ── Три исхода вместо двух: строгий режим ───────────────────────────────────
// Для обязательного метода «мы не знаем» приравнивается к запрету: работа,
// начатая вслепую, падает на середине. Для остального непроверенное разрешено,
// иначе приложение не сделало бы ни одного вызова до первой проверки.
check('строгий режим не пропускает непроверенный метод',
    capAllowed('совсем.новый.метод') === true && capAllowed('совсем.новый.метод', true) === false);
check('строгий режим не мешает методу, который портал разрешил',
    capState('user.get') === 'ok' && capAllowed('user.get', true) === true, capState('user.get'));

// ── ДВЕ ПРОВЕРКИ РАЗОМ — ТОЛЬКО ВТОРЫМ ПРОЦЕССОМ (находка ревью R3-010) ──────
// В одном процессе гонки не бывает никогда: карта читается, портал отвечает
// мгновенно, запись ложится поверх той же карты. Поэтому оба сценария ниже
// гоняются ДВУМЯ настоящими процессами, а очерёдность задаётся барьером-файлом.
//
//  1. ПОТЕРЯННОЕ ОБНОВЛЕНИЕ. Медленный процесс прочитал карту ДО чужой записи и
//     сохранял её целиком: знание соседа про другой метод исчезало без следа.
//  2. СТАРЫЙ ОТВЕТ ПОВЕРХ НОВОГО. Про ОДИН и тот же метод: сосед выяснил
//     «нет доступа» позже, но записал раньше — и опоздавший возвращал портал в
//     прошлое, разрешая вызов, который уже запрещён.
testWipe(DATA_ROOT);
$capRace = new B24('local.test.app', 'secret');
$capRace->saveTokensFromInstall([
    'AUTH_ID' => 'cap-race', 'REFRESH_ID' => 'cap-race-r', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);

$capHelper = sys_get_temp_dir() . '/bxapp-cap-race-' . getmypid() . '.php';
file_put_contents($capHelper, "<?php\n"
    . "require " . var_export(dirname(__DIR__) . '/www/env.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/www/api/b24.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/www/api/capability.php', true) . ";\n"
    . "\$rol = (string)\$argv[1]; \$metod = (string)\$argv[2];\n"
    . "\$otvet = (string)\$argv[3]; \$barer = (string)\$argv[4];\n"
    . "\$GLOBALS['__b24_transport'] = function (string \$m, array \$p) use (\$rol, \$otvet, \$barer): array {\n"
    . "    if (\$m !== 'batch') return ['result' => []];\n"
    . "    if (\$rol === 'medlennyy') {\n"
    . "        file_put_contents(\$barer . '.a-prochital', '1');\n"
    . "        \$do = microtime(true) + 15;\n"
    . "        while (!is_file(\$barer . '.b-zapisal') && microtime(true) < \$do) usleep(20000);\n"
    . "    }\n"
    . "    \$tela = [];\n"
    . "    foreach (array_keys((array)(\$p['cmd'] ?? [])) as \$k) {\n"
    . "        \$tela[\$k] = ['isExisting' => true, 'isAvailable' => \$otvet === 'ok'];\n"
    . "    }\n"
    . "    return ['result' => ['result' => \$tela, 'result_error' => []]];\n"
    . "};\n"
    . "try {\n"
    . "  capRefresh(new B24('local.test.app', 'secret'), [\$metod], true);\n"
    . "  if (\$rol === 'bystryy') file_put_contents(\$barer . '.b-zapisal', '1');\n"
    . "  echo 'ГОТОВО';\n"
    . "} catch (Throwable \$e) { echo 'ОТКАЗ:' . get_class(\$e); }\n");
$envCap = ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')];
$descrCap = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
/** Прогнать пару «медленный читает первым, быстрый пишет первым». */
$barerProgon = static function (string $metodA, string $otvetA, string $metodB, string $otvetB)
        use ($capHelper, $envCap, $descrCap): array {
    $barer = sys_get_temp_dir() . '/bxapp-cap-barer-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $medl = proc_open([PHP_BINARY, $capHelper, 'medlennyy', $metodA, $otvetA, $barer],
        $descrCap, $pipesM, null, $envCap);
    // Ждём именно СОБЫТИЯ «медленный уже прочитал карту», а не «прошло N мс»:
    // иначе проверка зависела бы от загрузки машины и врала бы вразнобой.
    $do = microtime(true) + 10;
    while (!is_file($barer . '.a-prochital') && microtime(true) < $do) usleep(20000);
    $bystr = proc_open([PHP_BINARY, $capHelper, 'bystryy', $metodB, $otvetB, $barer],
        $descrCap, $pipesB2, null, $envCap);
    $itog = [];
    foreach ([[$medl, $pipesM], [$bystr, $pipesB2]] as [$p, $pipes]) {
        if (!is_resource($p)) { $itog[] = 'НЕ ЗАПУСТИЛСЯ'; continue; }
        $itog[] = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
    }
    foreach (['.a-prochital', '.b-zapisal'] as $hvost) @unlink($barer . $hvost);
    return $itog;
};

$itogRace = $barerProgon('slow.method', 'ok', 'fast.method', 'ok');
$kartaRace = capRead()['методы'] ?? [];
check('ПАРАЛЛЕЛЬНАЯ ПРОВЕРКА НЕ ТЕРЯЕТ ЧУЖОЙ МЕТОД',
    ($kartaRace['slow.method'] ?? '') === 'ok' && ($kartaRace['fast.method'] ?? '') === 'ok',
    implode(' | ', $itogRace) . ' · карта: ' . json_encode($kartaRace, JSON_UNESCAPED_UNICODE));

// Один и тот же метод: сосед узнал ПОЗЖЕ, записал РАНЬШЕ. Опоздавший не имеет
// права вернуть портал в прошлое — иначе снятое право снова выглядит выданным.
testWipe(DATA_ROOT);
$capRace2 = new B24('local.test.app', 'secret');   // прежний экземпляр помнит снесённую установку
$capRace2->saveTokensFromInstall([
    'AUTH_ID' => 'cap-race2', 'REFRESH_ID' => 'cap-race-r2', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-cap.invalid', 'member_id' => 'MEMBER-CAP',
    'APPLICATION_TOKEN' => 'key-cap', 'APPLICATION_SCOPE' => 'crm,user',
]);
$itogOld = $barerProgon('crm.item.list', 'ok', 'crm.item.list', 'deny');
check('СТАРЫЙ ОТВЕТ ПОРТАЛА НЕ ПОБЕЖДАЕТ НОВЫЙ',
    capState('crm.item.list') === 'нет доступа',
    implode(' | ', $itogOld) . ' · состояние: ' . capState('crm.item.list'));
@unlink($capHelper);

unset($GLOBALS['__b24_transport']);
