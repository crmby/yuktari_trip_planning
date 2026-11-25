<?php
declare(strict_types=1);
/**
 * ТАРИФ ПОРТАЛА И ТЕМП ЗАПРОСОВ.
 *
 * Портал ограничивает не объём данных, а частоту обращений, и пороги зависят от
 * тарифа: Энтерпрайз — 5 запросов в секунду и корзина 250, прочие — 2 и 50
 * (официальные цифры из limits.md). Ошибка здесь тихая в обе стороны: на
 * обычном тарифе приложение упирается в отказ и теряет половину обхода, на
 * Энтерпрайзе — работает вдвое медленнее, чем позволено.
 *
 * Транспорт подменяем — в сеть не ходим.
 */
require_once dirname(__DIR__) . '/www/api/b24.php';
require_once dirname(__DIR__) . '/www/api/plan.php';

suite('Тариф портала и темп запросов');

// ── Разбор ответа app.info ──────────────────────────────────────────────────
$ent = planParseAppInfo(['LICENSE' => 'ru_ent10000', 'LICENSE_TYPE' => 'ent10000', 'LICENSE_FAMILY' => 'ent']);
check('Энтерпрайз опознан по семейству лицензии', $ent['enterprise'] === true, json_encode($ent, JSON_UNESCAPED_UNICODE));
check('у Энтерпрайза предел 5 запросов в секунду',
    planRatePerSecond($ent) === PLAN_RATE_ENTERPRISE && PLAN_RATE_ENTERPRISE === 5.0,
    (string)planRatePerSecond($ent));
check('у Энтерпрайза корзина 250', planBucket($ent) === 250, (string)planBucket($ent));

// Семейства может не быть — тогда решает идентификатор тарифа.
$entBezSemeystva = planParseAppInfo(['LICENSE' => 'de_ent250', 'LICENSE_TYPE' => 'ent250']);
check('Энтерпрайз опознан и без поля семейства', $entBezSemeystva['enterprise'] === true,
    json_encode($entBezSemeystva, JSON_UNESCAPED_UNICODE));

$obychnyj = planParseAppInfo(['LICENSE' => 'ru_project', 'LICENSE_TYPE' => 'project', 'LICENSE_FAMILY' => 'b24']);
check('обычный тариф не считается Энтерпрайзом', $obychnyj['enterprise'] === false,
    json_encode($obychnyj, JSON_UNESCAPED_UNICODE));
check('у обычного тарифа предел 2 запроса в секунду',
    planRatePerSecond($obychnyj) === PLAN_RATE_DEFAULT && PLAN_RATE_DEFAULT === 2.0,
    (string)planRatePerSecond($obychnyj));
check('у обычного тарифа корзина 50', planBucket($obychnyj) === 50, (string)planBucket($obychnyj));

// Похожее слово в названии тарифа не делает его Энтерпрайзом: «ru_entry» — это
// не «ent», и вести себя как на Энтерпрайзе здесь означало бы получить
// блокировку у клиента.
$pohozhij = planParseAppInfo(['LICENSE' => 'ru_entry', 'LICENSE_TYPE' => 'entry', 'LICENSE_FAMILY' => 'b24']);
check('ПОХОЖЕЕ НАЗВАНИЕ ТАРИФА НЕ ПРИНИМАЕТСЯ ЗА ЭНТЕРПРАЙЗ',
    $pohozhij['enterprise'] === false && planRatePerSecond($pohozhij) === PLAN_RATE_DEFAULT,
    json_encode($pohozhij, JSON_UNESCAPED_UNICODE));

// ── Неизвестный тариф — самый строгий предел ────────────────────────────────
check('ПРИ НЕИЗВЕСТНОМ ТАРИФЕ БЕРЁТСЯ САМЫЙ СТРОГИЙ ПРЕДЕЛ',
    planRatePerSecond([]) === PLAN_RATE_DEFAULT, (string)planRatePerSecond([]));
check('человеку сказано, что тариф ещё не спрашивали',
    mb_strpos(planHuman([]), 'не спрашивали') !== false, planHuman([]));

// ── Спрашиваем портал и запоминаем ──────────────────────────────────────────
testWipe(DATA_ROOT);
$planK = new B24('local.test.app', 'secret');
$planK->saveTokensFromInstall([
    'AUTH_ID' => 'plan-access', 'REFRESH_ID' => 'plan-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-plan.invalid', 'member_id' => 'MEMBER-PLAN',
    'APPLICATION_TOKEN' => 'key-plan', 'APPLICATION_SCOPE' => 'crm,user',
]);

$zvonki = [];
$GLOBALS['__b24_transport'] = static function (string $method, array $params) use (&$zvonki): array {
    $zvonki[] = $method;
    if ($method === 'app.info') {
        return ['result' => [
            'ID' => 1, 'CODE' => 'local.test', 'INSTALLED' => true,
            'LICENSE' => 'ru_ent10000', 'LICENSE_TYPE' => 'ent10000', 'LICENSE_FAMILY' => 'ent',
        ]];
    }
    return ['result' => 'ок'];
};

$poluchen = planRefresh($planK);
check('тариф спрошен у портала и разобран', !empty($poluchen['enterprise']),
    json_encode($poluchen, JSON_UNESCAPED_UNICODE));
check('тариф сохранён в состоянии между запросами',
    !empty(planRead()['enterprise']), json_encode(planRead(), JSON_UNESCAPED_UNICODE));
check('темп для клиента взят по сохранённому тарифу',
    planRatePerSecond(planRead()) === PLAN_RATE_ENTERPRISE, (string)planRatePerSecond(planRead()));

// Свежий тариф портал не переспрашивает: лишний запрос — это часть той самой
// корзины, которую мы бережём.
$zvonki = [];
planRefresh($planK);
check('свежий тариф не переспрашивается', $zvonki === [], 'запросов: ' . count($zvonki));

// ── Портал не ответил: прежнее знание не затирается ─────────────────────────
$GLOBALS['__b24_transport'] = static fn(string $m, array $p): array => ['__code' => 0, 'error' => 'http_error'];
$posleSboya = planRefresh($planK, true);
check('ОТКАЗ ПОРТАЛА НЕ ЗАТИРАЕТ ИЗВЕСТНЫЙ ТАРИФ',
    !empty($posleSboya['enterprise']) && !empty(planRead()['enterprise']),
    json_encode(planRead(), JSON_UNESCAPED_UNICODE));

// Старый запрос может вернуться после нового. Момент записи здесь не годится:
// ответ старого как раз записывается позже. Побеждает запрос, НАЧАТЫЙ позже.
storeWrite(PLAN_FILE, ['license' => 'newer', 'refresh_started_at' => 200.0, 'at' => 200]);
$afterOlder = planPersistFreshest(['license' => 'older', 'at' => 300], 100.0);
check('ПОЗДНИЙ ОТВЕТ СТАРОГО ЗАМЕРА НЕ ЗАТИРАЕТ БОЛЕЕ СВЕЖИЙ ТАРИФ',
    ($afterOlder['license'] ?? '') === 'newer' && (planRead()['license'] ?? '') === 'newer',
    json_encode(planRead(), JSON_UNESCAPED_UNICODE));
$afterNewest = planPersistFreshest(['license' => 'newest', 'at' => 400], 300.0);
check('действительно более свежий замер сохраняется',
    ($afterNewest['license'] ?? '') === 'newest' && (planRead()['license'] ?? '') === 'newest',
    json_encode(planRead(), JSON_UNESCAPED_UNICODE));

// А если тарифа не знали вовсе — остаёмся на строгом пределе и не падаем.
testWipe(DATA_ROOT);
$planK2 = new B24('local.test.app', 'secret');
$planK2->saveTokensFromInstall([
    'AUTH_ID' => 'plan-access2', 'REFRESH_ID' => 'plan-refresh2', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-plan.invalid', 'member_id' => 'MEMBER-PLAN',
    'APPLICATION_TOKEN' => 'key-plan', 'APPLICATION_SCOPE' => 'crm,user',
]);
$pusto = planRefresh($planK2, true);
check('без ответа портала тариф остаётся неизвестным, а работа продолжается',
    $pusto === [] && planRatePerSecond($pusto) === PLAN_RATE_DEFAULT,
    json_encode($pusto, JSON_UNESCAPED_UNICODE));

// ── Коробка: облачная корзина интенсивности не применяется ─────────────
//
// САМАЯ ДОРОГАЯ ОШИБКА ЗДЕСЬ — ВЫДАТЬ ДОГАДКУ ЗА ФАКТ. Коробке не назначается облачная
// leaky-bucket корзина; ёмкость сервера и REST resource guard — отдельные ограничения.
// Назначать ей «энтерпрайзовые» 5 запросов в секунду нельзя в обе стороны:
// мощную коробку мы этим искусственно душим, слабую — заваливаем, и в обоих
// случаях человек видит на экране выдуманное число как серверный факт.
// Поэтому без настройки коробка идёт по САМОМУ ОСТОРОЖНОМУ бюджету, а откуда
// взялось число — говорится вслух.
$korobka = planParseAppInfo(['LICENSE' => 'ru_selfhosted', 'LICENSE_TYPE' => 'selfhosted', 'LICENSE_FAMILY' => 'selfhosted']);
check('коробочный портал опознан', !empty($korobka['box']), json_encode($korobka, JSON_UNESCAPED_UNICODE));
check('КОРОБКЕ НЕ НАЗНАЧАЕТСЯ ОБЛАЧНЫЙ ПРЕДЕЛ ЭНТЕРПРАЙЗА',
    planRatePerSecond($korobka) !== PLAN_RATE_ENTERPRISE, (string)planRatePerSecond($korobka));
check('без настройки коробка идёт по самому осторожному бюджету',
    planRatePerSecond($korobka) === PLAN_RATE_DEFAULT, (string)planRatePerSecond($korobka));
check('ИСТОЧНИК ЧИСЛА НАЗВАН: это осторожная оценка, а не предел портала',
    planRateSource($korobka) === 'fallback', planRateSource($korobka));
check('человеку про коробку сказано отдельно',
    mb_strpos(planHuman(['at' => time()] + $korobka), 'коробоч') !== false,
    planHuman(['at' => time()] + $korobka));
check('ЧЕЛОВЕКУ СКАЗАНО, ЧТО ЁМКОСТЬ КОРОБКИ НЕ ИЗМЕРЕНА',
    mb_strpos(planHuman(['at' => time()] + $korobka), 'не измер') !== false,
    planHuman(['at' => time()] + $korobka));
$boxHumanFallback = planHuman(['at' => time()] + $korobka);
check('ДИАГНОСТИКА КОРОБКИ НАЗЫВАЕТ ТОЛЬКО ОТСУТСТВИЕ ОБЛАЧНОЙ КОРЗИНЫ',
    preg_match('~облачн\S*\s+(?:leaky-bucket\s+)?корзин\S*\s+интенсивност\S*\s+не\s+примен~iu', $boxHumanFallback) === 1,
    $boxHumanFallback);
check('ДИАГНОСТИКА КОРОБКИ НЕ ВЫДАЁТ СЕРВЕРНУЮ ЁМКОСТЬ ЗА ОБЛАЧНЫЙ ЛИМИТ',
    preg_match('~(?:без\s+предела|лимит\S*\s+нет\s+вовсе|интенсивност\S*\s+не\s+огранич)~iu', $boxHumanFallback) !== 1,
    $boxHumanFallback);
check('ДИАГНОСТИКА КОРОБКИ НЕ МОЛЧИТ ОБ ОТДЕЛЬНОМ REST RESOURCE GUARD',
    mb_stripos($boxHumanFallback, 'resource guard') !== false
    && mb_stripos($boxHumanFallback, 'не провер') !== false,
    $boxHumanFallback);

// Настройка перебивает догадку: у клиента померили — работаем по мерке.
putenv('BXAPP_RATE_PER_SEC=12');
check('заданный настройкой темп применяется', planRatePerSecond($korobka) === 12.0,
    (string)planRatePerSecond($korobka));
check('источник заданного темпа — настройка', planRateSource($korobka) === 'configured',
    planRateSource($korobka));
check('заданный темп виден человеку', mb_strpos(planHuman(['at' => time()] + $korobka), 'настрой') !== false,
    planHuman(['at' => time()] + $korobka));
$boxHumanConfigured = planHuman(['at' => time()] + $korobka);
check('ДАЖЕ С ЗАДАННЫМ ТЕМПОМ ДИАГНОСТИКА ОТДЕЛЯЕТ ОБЛАЧНУЮ КОРЗИНУ И RESOURCE GUARD',
    mb_stripos($boxHumanConfigured, 'облачн') !== false
    && mb_stripos($boxHumanConfigured, 'корзин') !== false
    && mb_stripos($boxHumanConfigured, 'resource guard') !== false,
    $boxHumanConfigured);
// Настройка НЕ должна ломать облачные пороги: там предел ставит портал, а не мы.
check('НАСТРОЙКА НЕ ПЕРЕБИВАЕТ ПРЕДЕЛ ОБЛАЧНОГО ПОРТАЛА',
    planRatePerSecond($obychnyj) === PLAN_RATE_DEFAULT
    && planRatePerSecond($ent) === PLAN_RATE_ENTERPRISE,
    'обычный: ' . planRatePerSecond($obychnyj) . ', энтерпрайз: ' . planRatePerSecond($ent));
putenv('BXAPP_RATE_PER_SEC');
check('источник для облачного тарифа — предел портала', planRateSource($ent) === 'plan', planRateSource($ent));

// Бессмысленное значение настройки игнорируется молча только в одну сторону:
// работать по «0 запросов в секунду» нельзя — это остановка приложения.
putenv('BXAPP_RATE_PER_SEC=0');
check('нулевой темп из настройки не принимается', planRatePerSecond($korobka) === PLAN_RATE_DEFAULT,
    (string)planRatePerSecond($korobka));
putenv('BXAPP_RATE_PER_SEC=-3');
check('отрицательный темп из настройки не принимается', planRatePerSecond($korobka) === PLAN_RATE_DEFAULT,
    (string)planRatePerSecond($korobka));
putenv('BXAPP_RATE_PER_SEC');

// ── Темп действительно держится ─────────────────────────────────────────────
// Проверяем не «есть функция», а поведение: на строгом тарифе три запроса
// подряд не могут уложиться быстрее, чем позволяет предел.
testWipe(DATA_ROOT);
$planK3 = new B24('local.test.app', 'secret');
$planK3->saveTokensFromInstall([
    'AUTH_ID' => 'plan-access3', 'REFRESH_ID' => 'plan-refresh3', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-plan.invalid', 'member_id' => 'MEMBER-PLAN',
    'APPLICATION_TOKEN' => 'key-plan', 'APPLICATION_SCOPE' => 'crm,user',
]);
storeWrite(PLAN_FILE, ['license' => 'ru_project', 'type' => 'project', 'family' => 'b24',
                       'enterprise' => false, 'box' => false, 'at' => time()]);
$GLOBALS['__b24_transport'] = static fn(string $m, array $p): array => ['result' => 'ок'];
$nachalo = microtime(true);
$planK3->call('crm.item.list', []);
$planK3->call('crm.item.list', []);
$planK3->call('crm.item.list', []);
$proshlo = microtime(true) - $nachalo;
// Два промежутка по 0.5 с (2 запроса в секунду), с запасом на медленную машину.
check('НА ОБЫЧНОМ ТАРИФЕ ТЕМП ДЕЙСТВИТЕЛЬНО СДЕРЖИВАЕТСЯ',
    $proshlo >= 0.8, 'три запроса заняли ' . round($proshlo, 3) . ' с, ожидали не меньше 0.8');

unset($GLOBALS['__b24_transport']);

// ── Темп ОБЩИЙ для всех процессов, а не свой у каждого ──────────────────────
//
// Предел портала считает запросы ПРИЛОЖЕНИЯ, а не процесса. На хостинге разом
// работают веб-запрос, фоновый обработчик и ещё один воркер по расписанию —
// если каждый держит свой счётчик, приложение втроём спокойно превышает предел
// втрое и получает блокировку у клиента. Проверяем поведением: два процесса,
// запущенные одновременно, обязаны уложиться в ОБЩИЙ темп, а не каждый в свой.
$rabotnik = DATA_ROOT . '/rate-worker.php';
file_put_contents($rabotnik, <<<'PHP'
<?php
require_once getenv('BXAPP_APP_ROOT') . '/www/env.php';
require_once getenv('BXAPP_APP_ROOT') . '/www/api/lib.php';
require_once getenv('BXAPP_APP_ROOT') . '/www/api/store.php';
require_once getenv('BXAPP_APP_ROOT') . '/www/api/b24.php';
require_once getenv('BXAPP_APP_ROOT') . '/www/api/plan.php';
$GLOBALS['__b24_transport'] = static fn(string $m, array $p): array => ['result' => 'ок'];
$k = new B24('local.test.app', 'secret');
$n = (int)($argv[1] ?? 3);
for ($i = 0; $i < $n; $i++) $k->call('crm.item.list', []);
echo 'ok';
PHP
);
$koren = dirname(__DIR__);
$zapusk = static function (string $file, int $n) use ($koren): array {
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    return [proc_open(['php', $file, (string)$n], $desc, $pipes, $koren, [
        'BXAPP_DATA_ROOT' => DATA_ROOT,
        'BXAPP_APP_ROOT'  => $koren,
        'PATH' => (string)getenv('PATH'), 'BXAPP_RETRY_PAUSE_SCALE' => (string)(getenv('BXAPP_RETRY_PAUSE_SCALE') ?: '1'),
    ]), $pipes];
};
$nachalo2 = microtime(true);
[$p1, $pipes1] = $zapusk($rabotnik, 3);
[$p2, $pipes2] = $zapusk($rabotnik, 3);
$vyhod1 = is_resource($p1) ? stream_get_contents($pipes1[1]) : '';
$vyhod2 = is_resource($p2) ? stream_get_contents($pipes2[1]) : '';
foreach ([$pipes1, $pipes2] as $pp) foreach ($pp as $h) { if (is_resource($h)) fclose($h); }
foreach ([$p1, $p2] as $pr) if (is_resource($pr)) proc_close($pr);
$proshlo2 = microtime(true) - $nachalo2;
check('оба процесса отработали', $vyhod1 === 'ok' && $vyhod2 === 'ok', $vyhod1 . '/' . $vyhod2);
// Шесть запросов при пределе 2 в секунду — это минимум 2.5 секунды на двоих.
// Берём с запасом на медленную машину и на запуск самих процессов: важно
// отличить «общий темп» (≈2.5 с) от «у каждого свой» (≈1 с).
check('ТЕМП ОБЩИЙ ДЛЯ ВСЕХ ПРОЦЕССОВ ПРИЛОЖЕНИЯ, А НЕ СВОЙ У КАЖДОГО',
    $proshlo2 >= 1.8,
    'шесть запросов двумя процессами заняли ' . round($proshlo2, 3) . ' с, ожидали не меньше 1.8');
// ── Тот же темп при ШЕСТИ процессах, а не при двух ──────────────────────────
// Находка ревью 08.08.2026 (BXREV-002): на двух процессах очередь короткая и
// проверка проходила, а на шести общий учёт разваливался — процессы не
// дожидались замка (сон происходил ПОД ним) и уходили «каждый сам за себя».
// Замер Кодекса: шесть процессов по три запроса при пределе 2 в секунду
// укладывались в 2,07 секунды вместо 8,5. Проверяем именно ту нагрузку, на
// которой дефект виден, — двумя процессами его не поймать.
// Чистим ТОЛЬКО общую отметку: testWipe(DATA_ROOT) снёс бы вместе с ней и сам
// файл процесса-работника, и все шесть запусков умерли бы, не начавшись, —
// проверка при этом показала бы «0.06 с», то есть ложный провал темпа.
@unlink(RATE_FILE);
storeWrite(PLAN_FILE, ['license' => 'project', 'family' => 'project', 'enterprise' => false, 'at' => time()]);
$nachalo6 = microtime(true);
$processy = [];
for ($i = 0; $i < 6; $i++) $processy[] = $zapusk($rabotnik, 2);
$vyhody = [];
foreach ($processy as [$pr, $pp]) $vyhody[] = is_resource($pr) ? (string)stream_get_contents($pp[1]) : '';
foreach ($processy as [$pr, $pp]) {
    foreach ($pp as $h) if (is_resource($h)) fclose($h);
    if (is_resource($pr)) proc_close($pr);
}
$proshlo6 = microtime(true) - $nachalo6;
check('все шесть процессов отработали', count(array_filter($vyhody, static fn($v) => $v === 'ok')) === 6,
    implode('/', $vyhody));
// 12 запросов при 2 в секунду — это 5.5 секунды общего времени. Порог с запасом
// на медленную машину; «у каждого свой темп» дало бы около 1 секунды.
check('ОБЩИЙ ТЕМП ДЕРЖИТСЯ И ПРИ ШЕСТИ ПРОЦЕССАХ, А НЕ ТОЛЬКО ПРИ ДВУХ',
    $proshlo6 >= 4.0,
    '12 запросов шестью процессами заняли ' . round($proshlo6, 3) . ' с, ожидали не меньше 4.0');

// Отказ общей очереди обязан быть СЛЫШНЫМ: прежде процесс тихо переходил на
// свой счётчик, и приложение вдвоём превышало предел портала без единой строки
// в журнале.
// Журнал не вычищаем: каталога может ещё не быть, и testWipe по несуществующему
// пути даёт предупреждение PHP, которое раннер справедливо считает сбоем.
// Сравниваем «до» и «после» — этого достаточно.
$doZapisi = (string)@file_get_contents(journalFile());
planNoteDegraded('проверочная причина');
$degradedLog = (string)@file_get_contents(journalFile());
$pribavka = mb_substr($degradedLog, mb_strlen($doZapisi));
check('переход на «каждый сам себе» записан в журнал, а не молчит',
    strpos($pribavka, 'plan.rate.degraded') !== false
    && strpos($pribavka, 'общий темп запросов к порталу не соблюдается') !== false,
    mb_substr($pribavka, 0, 200));
// И не заливает журнал: повтор в пределах минуты пропускается.
$doPovtora = strlen($degradedLog);
planNoteDegraded('вторая причина подряд');
check('повтор в пределах минуты журнал не заливает',
    strlen((string)@file_get_contents(journalFile())) === $doPovtora);

// Потолок ожидания у крона и веба РАЗНЫЙ: человек за экраном ждёт полсекунды,
// фоновая работа — до пяти секунд. Общий потолок 0.5 и был причиной того, что
// фон уходил на свой счётчик.
check('потолок ожидания в фоне больше, чем в вебе',
    PHP_SAPI === 'cli' && planWaitCap() === (float)PLAN_WAIT_CAP_CLI && PLAN_WAIT_CAP_CLI > PLAN_WAIT_CAP,
    'контекст ' . PHP_SAPI . ', потолок ' . planWaitCap());

// У ДВУХ ПОТОЛКОВ РАЗНАЯ РОЛЬ, и путать их нельзя (находка ревью 09.08.2026):
// «сколько жду Я» — своё, а «какая очередь вообще законна» — общее, по самому
// терпеливому. Пока порог сброса считался своим потолком, ВЕБ-запрос принимал
// честную очередь крона за переведённые часы, обнулял её — и все ждавшие
// получали момент «сейчас», то есть залп по порталу.
$tNow = 1000.0;
$interval = 0.5;
$ochered = $tNow + 2.0;   // законная очередь крона: он готов ждать 5 секунд
$vWebe = planSlotDecide($ochered, $tNow, $interval, (float)PLAN_WAIT_CAP, planWaitCapMax());
check('веб не сбрасывает законную очередь крона, а просто не занимает слот',
    $vWebe['момент'] === 0.0 && $vWebe['сброшено'] === false,
    'момент ' . $vWebe['момент'] . ', сброшено ' . var_export($vWebe['сброшено'], true));
$vKrone = planSlotDecide($ochered, $tNow, $interval, (float)PLAN_WAIT_CAP_CLI, planWaitCapMax());
check('крон в той же очереди встаёт следующим, а не «сейчас»',
    abs($vKrone['момент'] - ($ochered + $interval)) < 0.0001 && $vKrone['сброшено'] === false,
    'момент ' . $vKrone['момент'] . ' вместо ' . ($ochered + $interval));
// Часы действительно переведены — отметка дальше любой законной очереди.
$daleko = planSlotDecide($tNow + 3600.0, $tNow, $interval, (float)PLAN_WAIT_CAP_CLI, planWaitCapMax());
check('отметка из чужого будущего сбрасывается, а не морозит приложение на час',
    $daleko['момент'] === $tNow && $daleko['сброшено'] === true,
    'момент ' . $daleko['момент'] . ', сброшено ' . var_export($daleko['сброшено'], true));

@unlink($rabotnik);

// ── Тип портала: слово оператора важнее догадки (BXREV-020) ────────────────
// Находка ревью 08.08.2026: коробка распознавалась ТОЛЬКО по строкам
// `selfhosted` в ответе `app.info`, которых документация портала не обещает.
// Не совпало написание — коробка молча считалась облаком: измеренная ёмкость
// игнорировалась, темп падал до облачного, а экран уверенно называл тип.
$doKind = getenv('BXAPP_PORTAL_KIND');
$doRate = getenv('BXAPP_RATE_PER_SEC');
putenv('BXAPP_PORTAL_KIND');
putenv('BXAPP_RATE_PER_SEC');

$neponyatnyy = planParseAppInfo([]);   // портал ответил, но про тип ничего не сказал
check('НЕПОНЯТНЫЙ ОТВЕТ НЕ ВЫДАЁТСЯ ЗА ОБЛАКО',
    planKindSource($neponyatnyy) === 'unknown', planKindSource($neponyatnyy));
check('и человеку сказано, что тип не определён и как это исправить',
    mb_strpos(planHuman(['at' => time()] + $neponyatnyy), 'тип портала не определён') !== false
    && mb_strpos(planHuman(['at' => time()] + $neponyatnyy), 'BXAPP_PORTAL_KIND=box') !== false,
    planHuman(['at' => time()] + $neponyatnyy));

putenv('BXAPP_RATE_PER_SEC=7');
check('ИЗМЕРЕННАЯ ЁМКОСТЬ ПРИНИМАЕТСЯ И ПРИ НЕОПОЗНАННОМ ТИПЕ ПОРТАЛА',
    planRatePerSecond(planParseAppInfo([])) === 7.0,
    (string)planRatePerSecond(planParseAppInfo([])));
// На облаке настройка НЕ действует — и об этом сказано вслух, а не молча.
$oblako = planParseAppInfo(['LICENSE' => 'ru_company', 'LICENSE_FAMILY' => 'company']);
check('на облаке измеренная ёмкость не применяется', planRatePerSecond($oblako) === PLAN_RATE_DEFAULT);
check('и об этом сказано прямо, а не молча проигнорировано',
    mb_strpos(planHuman(['at' => time()] + $oblako), 'НЕ действует') !== false,
    planHuman(['at' => time()] + $oblako));
putenv('BXAPP_RATE_PER_SEC');

// Слово оператора главнее любой строки лицензии — в обе стороны.
putenv('BXAPP_PORTAL_KIND=box');
$skazaliBox = planParseAppInfo(['LICENSE' => 'ru_company', 'LICENSE_FAMILY' => 'company']);
check('СКАЗАЛИ «КОРОБКА» — ЗНАЧИТ КОРОБКА, ДАЖЕ ЕСЛИ ЛИЦЕНЗИЯ ГОВОРИТ ИНОЕ',
    !empty($skazaliBox['box']) && planKindSource($skazaliBox) === 'configured');
putenv('BXAPP_PORTAL_KIND=cloud');
$skazaliCloud = planParseAppInfo(['LICENSE' => 'ru_selfhosted', 'LICENSE_FAMILY' => 'selfhosted']);
check('сказали «облако» — значит облако, даже если лицензия похожа на коробку',
    empty($skazaliCloud['box']) && planKindSource($skazaliCloud) === 'configured');
putenv('BXAPP_PORTAL_KIND');
// Догадка по лицензии осталась рабочей — её не выключили, а понизили в приоритете.
$poLicenzii = planParseAppInfo(['LICENSE' => 'ru_selfhosted', 'LICENSE_FAMILY' => 'selfhosted']);
check('без настройки коробка по-прежнему узнаётся по лицензии',
    !empty($poLicenzii['box']) && planKindSource($poLicenzii) === 'license');

if ($doKind !== false) putenv('BXAPP_PORTAL_KIND=' . $doKind);
if ($doRate !== false) putenv('BXAPP_RATE_PER_SEC=' . $doRate);
