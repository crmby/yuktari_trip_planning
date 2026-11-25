<?php
declare(strict_types=1);
/**
 * Наборы самопроверки приложения. Прямой запуск: `php tests/run.php`; штатная
 * единая команда `bash tools/self-check.sh` добавляет PHP/JS lint и зовётся деплоем.
 *
 * Зачем это в шаблоне. Ревью раз за разом находило в разных проектах одни и те
 * же вещи: мутация проходит по cookie, отзыв доступа не действует до конца дня,
 * повторный клик создаёт вторую запись, состояние теряется при параллельной
 * записи. Это проверяемо машиной — значит, проверять должна машина, а не
 * человек глазами на ревью.
 *
 * Тесты не ходят в сеть и не трогают боевые данные: стор подменяется временной
 * папкой через BXAPP_DATA_ROOT, обращения к порталу — заглушкой.
 *
 * Итог прогона обязан быть неподделываемым ИЗНУТРИ набора: наборы подключаются
 * require'ом в этом же процессе, то есть код проекта исполняется рядом с
 * раннером и видит те же $GLOBALS. Поэтому счёт проверок, список провалов и
 * признак «раннер дошёл до итога» лежат там, куда набору не дотянуться (статик
 * функции и локальные переменные замыкания), а глобалы служат только каналом
 * приёма. Находка ревью K2-02 (09.08.2026): одной строки
 * `$GLOBALS['__tests_failed'] = []` в своём `*_test.php` хватало, чтобы прогон
 * с провалами напечатал «Все проверки пройдены» и отдал код 0.
 */

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/bxapp-tests-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
putenv('BXAPP_DATA_ROOT=' . $tmp);

// Уборка временного стора регистрируется ПЕРВЫМ делом. Раньше она стояла после
// загрузки наборов, и фатальная ошибка в любом из них оставляла папку в /tmp
// навсегда — за месяц их накопились десятки, хотя комментарий обещал обратное.
register_shutdown_function(static function () use ($tmp): void {
    if (function_exists('testWipe')) testWipe($tmp, true);
});

/**
 * Приватный счёт прогона: единственный источник итога.
 *
 * Пополнять его можно только через check() и обработчик предупреждений —
 * стереть, обнулить или подменить нечем: статик функции набору не виден, а
 * наружу отдаётся копия (массивы в PHP — значения). Всё, что лежит в $GLOBALS,
 * для набора своя переменная, поэтому итог считается не по ним.
 */
function testLedger(bool $countCheck = false, ?string $failure = null, ?string $warning = null): array
{
    static $total = 0, $failed = [], $warnings = [];
    if ($countCheck) $total++;
    if ($failure !== null) $failed[] = $failure;
    if ($warning !== null) $warnings[] = $warning;
    return ['total' => $total, 'failed' => $failed, 'warnings' => $warnings];
}

/**
 * Имена ВЫПОЛНЕННЫХ проверок, в порядке вызова.
 *
 * Реестр `docs/capabilities.json` называет проверки, которые доказывают поведение
 * готового блока. Раньше их существование сверялось `strpos` по ИСХОДНИКУ набора —
 * и заглушка, положившая те же имена в комментарий, проходила как доказательство
 * (находка K4-01 ревью 09.08.2026: 205 проверок регрессий исчезали, прогон
 * печатал «Все проверки пройдены»). Текст в файле ничего не исполняет; считать
 * доказательством можно только вызов.
 *
 * Список приватный, как и счёт: набору его не стереть и не переписать, а раннер
 * берёт разницу «до набора / после набора» и потому знает, КТО что выполнил.
 */
function testExecutedNames(?string $name = null): array
{
    static $names = [];
    if ($name !== null) { $names[] = $name; return []; }
    return $names;
}

// Предупреждения PHP — это сбой, а не фон. Прогон с 40 warning'ами «Cannot modify
// header information» много недель заканчивался словами «Все проверки пройдены»:
// сигнал был, но зелёный итог его перекрывал.
$GLOBALS['__tests_warnings'] = [];

/**
 * Забор предупреждений — ИМЕНОВАННАЯ функция, а не замыкание.
 *
 * Раньше здесь стояло замыкание, поставленное один раз на весь прогон, и сам
 * механизм никем не стерёгся: любому набору хватало одной строки
 * `error_reporting(0)` (или своего `set_error_handler`), чтобы ВСЕ следующие
 * наборы, включая наборы шаблона, потеряли предупреждения PHP молча — ровно тот
 * сценарий, ради которого забор и заведён (находка K4-06 ревью 09.08.2026:
 * набор проекта с именем на `aa_` исполняется первым и глушит весь прогон).
 *
 * Имя нужно для сверки: PHP не даёт переобъявить функцию, а `set_error_handler`
 * возвращает то, что стояло до него, — значит раннер может после каждого набора
 * спросить «мой ли обработчик стоит и не занижен ли error_reporting».
 */
function testWarningHandler(int $no, string $msg, string $file = '', int $line = 0): bool
{
    if (!(error_reporting() & $no)) return false;   // подавление через @ уважаем
    $text = $msg . ' (' . basename($file) . ':' . $line . ')';
    testLedger(false, null, $text);
    $GLOBALS['__tests_warnings'][] = $text;
    return true;   // не печатаем поток предупреждений, а копим и показываем в конце
}
error_reporting(E_ALL);
set_error_handler('testWarningHandler');

require_once $root . '/www/env.php';
require_once $root . '/www/api/store.php';
require_once $root . '/www/api/lib.php';
require_once $root . '/www/api/access.php';
require_once $root . '/www/api/http.php';   // константы и хелперы единого входа
require_once $root . '/www/api/journal.php';   // журнал приложения (один на всё)
require_once __DIR__ . '/lib.php';          // обвес проверок по живому HTTP

// Канал приёма от наборов. Раньше здесь же лежал ИТОГ, и набор его переписывал.
$GLOBALS['__tests_total'] = 0;
$GLOBALS['__tests_failed'] = [];
// Ловушка, а не флаг: настоящий признак завершения лежит в замыкании ниже. Набор,
// который выставит здесь true (так работал сценарий 2 находки K2-02), не завершит
// прогон, а получит фатальный сбой со своим именем.
$GLOBALS['__tests_completed'] = false;

// Ход прогона печатаем в поток ошибок, а не в стандартный вывод. Причина не в
// красоте: в CLI любой вывод в stdout «отправляет заголовки», и дальше каждая
// проверка, которая ставит cookie или заголовок, спотыкается о предупреждение
// PHP. Сорок таких предупреждений висели в каждом прогоне и выглядели как норма.
function check(string $what, bool $ok, string $detail = ''): void {
    $entry = $what . ($detail !== '' ? " — $detail" : '');
    testLedger(true, $ok ? null : $entry);
    testExecutedNames($what);   // доказательство поведения — по исполнению, не по тексту
    // Канал держим в том же состоянии, что и приватный счёт: наборы и раннер
    // видят одно и то же, но итог берётся из счёта, а не отсюда.
    $GLOBALS['__tests_total'] = (int)($GLOBALS['__tests_total'] ?? 0) + 1;
    if ($ok) { fwrite(STDERR, "  ok   $what\n"); return; }
    if (!is_array($GLOBALS['__tests_failed'] ?? null)) $GLOBALS['__tests_failed'] = [];
    $GLOBALS['__tests_failed'][] = $entry;
    fwrite(STDERR, "  СБОЙ $what" . ($detail !== '' ? " — $detail" : '') . "\n");
}

function suite(string $name): void { fwrite(STDERR, "\n$name\n"); }

// Сколько проверок обязано выполниться. Без нижней границы «самопроверка»
// становится ритуалом: удалили наборы — раннер бодро сообщает «всё пройдено»,
// и деплой уезжает на бой без единой проверки.
const MIN_CHECKS = 70;
const MIN_SUITES = 9;

/**
 * Наборы, без которых прогон не считается самопроверкой, — ПОИМЁННО.
 *
 * Счётчика мало. Стороннее ревью 03.08.2026 показало: удалить два набора-забора
 * (`gate_test.php` и `uses_template_test.php`) можно было штатно — оставалось
 * ровно пять файлов, порог не срабатывал, итог был зелёный «128 проверок».
 * То есть исчезал ровно тот забор, который заставляет пользоваться готовым, и
 * ничего об этом не сообщало. Теперь список обязательных наборов — часть
 * контракта; тот же список продублирован в tools/self-check.sh, и gate_test.php
 * сверяет обе копии между собой.
 */
const REQUIRED_SUITES = [
    'b24_test.php',           // клиент портала: пакеты, пагинация, безопасные повторы
    'gate_test.php',          // проверяет сам гейт на образцах обхода
    'http_test.php',          // единый вход эндпоинтов
    'install_portal_test.php', // установка: коробка, CSP, события, безопасный batch retry
    'modules_test.php',       // готовые модули: форматы, health, placements, JSON
    'resource_limits_test.php', // пределы журнала/CSV и устойчивость под малой памятью
    'review_fixes_test.php',  // регрессии исправлений глубокого ревью
    'security_test.php',      // доступ, роли, сессии
    'snap_test.php',          // точки возврата: снимок, отличия, возврат в обе стороны
    'notify_test.php',        // уведомления людям: очередь, повтор, недоставка
    'fatal_test.php',         // сбой без исключения (память кончилась): что видит человек
    'store_test.php',         // состояние под замком, маскировка секретов
    'uf_test.php',            // поля приложения в карточке портала: повтор, частичный отказ
    'task_test.php',          // долгая задача: ход, отмена, продолжение после падения
    'crm_dict_test.php',      // справочник CRM на подменённом транспорте портала
    'plan_test.php',          // тариф портала и темп запросов по его лимитам
    'capability_test.php',    // доступны ли приложению методы портала (важно для коробки)
    'uses_template_test.php', // гейт «пользуйся готовым» по коду проекта
    // Ревью 23.09.2026 (dubli-5): лежали вне контракта и выдавались за наборы проекта.
    'architecture_tools_test.php',     // инструменты архитектуры: карта, реестр, границы
    'authorization_boundary_test.php', // границы авторизации эндпоинтов
    'effective_role_test.php',         // единая роль сессии для UI и endpoints
    'ux_test.php',                     // UX сбоев: истёкший пропуск, unknown-задача, фильтр журнала (ревью 23.09.2026)
    'perf_test.php',                   // скорость горячих путей: пропуска, ACL, справочник CRM (ревью 23.09.2026)
];

/**
 * Нижняя граница — для КАЖДОГО обязательного набора отдельно.
 *
 * Общий MIN_CHECKS не защищает от подмены одного `*_test.php` на зелёную
 * заглушку с одним check(): остальные большие наборы всё ещё перебивают
 * общий порог. Новый suite обязан получить явный порог.
 *
 * ЧИСЛО — 80% ОТ ФАКТА, а не «поменьше, чтобы не мешало». Прежние пороги
 * ставились однажды и отставали годами: у review_fixes_test.php стояло 100 при
 * 305 выполняемых проверках, у gate_test.php — 112 при 361. То есть заглушку,
 * уносящую две трети забора, контракт принимал молча (K4-01). Запас в 20% даёт
 * набору расти без правки контракта; отставание больше полутора раз раннер
 * теперь называет сам (SUITE_MIN_LAG_LIMIT) и говорит, до чего поднять.
 */
const REQUIRED_SUITE_MIN_CHECKS = [
    'b24_test.php'             => 107,
    'gate_test.php'            => 347,
    'http_test.php'            => 68,
    'install_portal_test.php'  => 30,
    'modules_test.php'         => 54,
    'resource_limits_test.php' => 19,
    'review_fixes_test.php'    => 250,
    'security_test.php'        => 70,
    'snap_test.php'            => 57,
    'notify_test.php'          => 47,
    'fatal_test.php'           => 15,
    'store_test.php'           => 73,
    'uf_test.php'              => 28,
    'task_test.php'            => 56,
    'crm_dict_test.php'        => 16,
    'plan_test.php'            => 38,
    'capability_test.php'      => 21,
    'uses_template_test.php'   => 93,
    'architecture_tools_test.php'     => 6,
    'authorization_boundary_test.php' => 8,
    'effective_role_test.php'         => 5,
    'ux_test.php'                     => 12,
    'perf_test.php'                   => 8,
];

/**
 * Наборы-ЗАБОРЫ способность не описывают — их доказывают поимённо здесь.
 *
 * Реестр `docs/capabilities.json` говорит, какая проверка доказывает поведение
 * готового блока, и подмена такого набора заглушкой краснеет. Но два набора
 * шаблона не описывают блок вовсе — они стерегут сам код («пользуйся готовым»,
 * пределы ресурсов), и до находки K4-05 их не стерегло НИЧТО: восемь пустых
 * check() вместо resource_limits_test.php проходили молча, счётчик падал на два.
 * Поэтому у каждого набора шаблона обязана быть хотя бы одна ИМЕНОВАННАЯ
 * проверка — здесь или в реестре; сверяется она по исполнению.
 *
 * Проверку переименовали — контракт обновить тем же заходом: имя в этом списке
 * и есть договор о том, что набор делает.
 */
const REQUIRED_SUITE_CHECK_NAMES = [
    'install_portal_test.php' => [
        'УСТАНОВКА НА СВЕЖЕЙ КОРОБКЕ ОТДАЁТСЯ С РАМКОЙ СВОЕГО ПОРТАЛА',
        'ПОСЛЕ ONAPPUPDATE ПОДПИСКА СТАВИТСЯ ЗАНОВО, А НЕ СЧИТАЕТСЯ ЦЕЛОЙ',
        'БЕЗ ПОДТВЕРЖДЕНИЯ ПОРТАЛА УСТАНОВКА ПО БИЛЕТУ НЕ ПРОХОДИТ',
        'С ПОСТОЯННЫМ КЛЮЧОМ ПРИЛОЖЕНИЕ НЕ СЧИТАЕТСЯ НЕУСТАНОВЛЕННЫМ',
    ],
    'uses_template_test.php' => [
        'машинный реестр готовых блоков есть',
        'каждый файл api/ описан в карте проекта docs/MAP.md',
    ],
    'resource_limits_test.php' => [
        'cache/task/CRM state bounded: field/item/aggregate границы и no-mutation',
        'cache family quota под общим lock не пробивается разными keys и не оставляет rejected locks',
        'разные cache keys не смешиваются при прежней 32-bit коллизии, legacy удалён',
        'cache failure/crash не оставляет неограниченные tmp и orphan store-lock',
        'cache read/delete сериализованы коротким family owner-lock',
        'lifecycle task/CRM replacement использует owner pre-decode ceilings и no-mutation',
        'cache/task/CRM inventory машинно привязан к bounded helpers без bypass',
    ],
    'review_fixes_test.php' => [
        'Q-PERF-01a creds bootstrap принимает limit-1/limit и limit+1 отвергает без mutation',
        'Q-PERF-01a credential writer принимает limit-1/limit, limit+1 не публикует',
        'credential writer останавливается на flush/fsync failure до mutation',
    ],
    // Ревью 23.09.2026 (dubli-5): наборы, прежде лежавшие вне контракта.
    'architecture_tools_test.php' => [
        'env recovery: повтор восстанавливает эталон после частичной публикации',
    ],
    'authorization_boundary_test.php' => [
        'AUTHZ RED: отозванный автор не возвращает себе admin',
    ],
    'effective_role_test.php' => [
        'effective role: обычный вариант 0',
    ],
    'perf_test.php' => [
        'perf: сборка справочника укладывается в 3 обращения к порталу, а не в два десятка',
        'perf: пропуска заблокированных снимаются ОДНОЙ записью файла сессий',
    ],
    'ux_test.php' => [
        'ux: после ручной проверки unknown снимается, задачу можно запустить заново',
        'ux: истёкший пропуск объяснён и даёт «Открыть заново» через BX24.reloadWindow',
    ],
    'notify_test.php' => [
        'разные notify keys не смешиваются при прежней 32-bit tag-коллизии',
        'default notify identity считается из полного текста, а не 48-bit сокращения',
        'legacy default notify мигрирует только по точному полному тексту',
    ],
];

/**
 * Во сколько раз факт может обогнать порог, прежде чем порог считается отставшим.
 *
 * Порог намеренно ниже факта, но НАСКОЛЬКО ниже — это и есть вопрос. У
 * review_fixes_test.php порог 100 стоял при 205 выполненных проверках: заглушка
 * на 100 пустых check() проходила контракт и уносила с собой 105 проверок
 * (K4-01). Полтора раза — запас на рост набора без правки контракта; больше —
 * порог отстал и его поднимают.
 */
const SUITE_MIN_LAG_LIMIT = 1.5;

// Тело раннера живёт в замыкании, а не на верхнем уровне файла: переменные
// верхнего уровня — это глобалы, и подключённый набор дотянулся бы до состояния
// прогона через $GLOBALS. Здесь его локальные переменные набору не видны.
$exitCode = (static function (string $root, string $tmp): int {
    // Признак «раннер сам вынес итог». Заменил $GLOBALS['__tests_completed']:
    // набору хватало одной строки, чтобы объявить самопроверку завершённой,
    // напечатать свой BXAPP_SELF_CHECK_COMPLETE и выйти с кодом 0 (K2-02).
    $state = new stdClass();
    $state->verdict = false;
    $state->code = 86;
    $state->suite = '';
    $verdict = static function (int $code) use ($state): int {
        $state->verdict = true;
        $state->code = $code;
        return $code;
    };

    // Include — это исполняемый PHP: набор может случайно оставить `exit(0)` и
    // оборвать раннер посередине с зелёным кодом. Признак итога выставляется только
    // после всех наборов и итоговых проверок; shutdown-гейт превращает любой ранний
    // выход (включая успешный) в отказ.
    //
    // Он же говорит последнее слово о коде возврата. Набор может зарегистрировать
    // свой shutdown с `exit(0)` и подменить им красный код — но exit внутри
    // shutdown отменяет ТОЛЬКО следующие обработчики, а этот зарегистрирован до
    // наборов и потому выполняется раньше их.
    register_shutdown_function(static function () use ($state): void {
        if (!$state->verdict) {
            $where = $state->suite !== '' ? ' на наборе ' . $state->suite : '';
            fwrite(STDERR, "САМОПРОВЕРКА НЕ ЗАВЕРШЕНА: раннер оборвался$where до итогового маркера.\n");
            exit(86);
        }
        if ($state->code !== 0) exit($state->code);
    });

    $files = glob(__DIR__ . '/*_test.php') ?: [];
    if (count($files) < MIN_SUITES) {
        fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: наборов найдено " . count($files) . ", ожидается не меньше " . MIN_SUITES . ".\n");
        return $verdict(1);
    }
    $missingSuites = array_values(array_diff(REQUIRED_SUITES, array_map('basename', $files)));
    if ($missingSuites !== []) {
        fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: нет обязательных наборов: " . implode(', ', $missingSuites) . ".\n");
        fwrite(STDERR, "Это наборы-заборы: без них проверять код нечем, а зелёный итог означал бы только то, что проверок нет.\n");
        return $verdict(1);
    }
    $suiteNames = array_map('basename', $files);
    $contractNames = array_keys(REQUIRED_SUITE_MIN_CHECKS);
    $contractMissing = array_values(array_diff(REQUIRED_SUITES, $contractNames));
    $contractExtra = array_values(array_diff($contractNames, REQUIRED_SUITES));
    if ($contractMissing !== [] || $contractExtra !== []) {
        $parts = [];
        if ($contractMissing !== []) $parts[] = 'без порога: ' . implode(', ', $contractMissing);
        if ($contractExtra !== []) $parts[] = 'лишние пороги: ' . implode(', ', $contractExtra);
        fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: нарушен per-suite контракт (" . implode('; ', $parts) . ").\n");
        return $verdict(1);
    }
    // Набор ПРОЕКТА подхватывается сам, регистрировать его в контракте не нужно.
    // Раньше любой новый `*_test.php` останавливал прогон словами «незарегистрированы»,
    // и чтобы дописать свои проверки, проект был обязан править tests/run.php и
    // tools/self-check.sh — то есть ЯДРО, которое обновляется из шаблона. Живой
    // прогон агента 09.08.2026: он полез выяснять, можно ли расширить контракт, —
    // вместо того чтобы просто написать проверки. Контракт по-прежнему стережёт
    // наборы ШАБЛОНА: их список и пороги менять нельзя, подмена на заглушку ловится.
    $projectSuites = array_values(array_diff($suiteNames, $contractNames));
    // Что подхватилось само — видно в прогоне, а не только в коде. Прежде список
    // вычислялся и никуда не шёл: читающий делал вывод, что у наборов проекта
    // есть свой порог, хотя порог у них один — «хотя бы одна проверка» (K4-45).
    fwrite(STDERR, $projectSuites === []
        ? "\nНаборы проекта: нет — свои проверки проекта не написаны.\n"
        : "\nНаборы проекта (подхватываются сами, порог — 1 проверка): "
          . implode(', ', $projectSuites) . "\n");

    // Наборы можно раздать нескольким независимым процессам. Каждая доля
    // проверяет свой per-suite минимум и публикует атомарный JSON; отдельный
    // агрегатор принимает только полный набор долей и повторяет общие договоры.
    $allFiles = $files;
    sort($allFiles);
    $allSuiteNames = array_map('basename', $allFiles);
    $shardTotal = (int)(getenv('BXAPP_SHARD_TOTAL') ?: 1);
    $shardIndex = (int)(getenv('BXAPP_SHARD_INDEX') ?: 0);
    $shardReport = (string)getenv('BXAPP_SHARD_REPORT');
    $aggregateDir = (string)getenv('BXAPP_SHARD_AGGREGATE_DIR');
    $aggregateMode = $aggregateDir !== '';
    if ($shardTotal < 1 || (!$aggregateMode && ($shardIndex < 0 || $shardIndex >= $shardTotal))) {
        fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: доля прогона задана неверно (index $shardIndex из $shardTotal).\n");
        return $verdict(1);
    }
    if ($aggregateMode && $shardTotal < 2) {
        fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: агрегатор нельзя запускать без нескольких долей.\n");
        return $verdict(1);
    }
    if ($shardTotal > 1 && !$aggregateMode) {
        $mine = [];
        foreach ($allFiles as $i => $suiteFile) {
            if ($i % $shardTotal === $shardIndex) $mine[] = $suiteFile;
        }
        $files = $mine;
        fwrite(STDERR, "\nДоля прогона " . ($shardIndex + 1) . " из $shardTotal: "
            . implode(', ', array_map('basename', $files)) . "\n");
    } elseif ($aggregateMode) {
        $files = [];
        fwrite(STDERR, "\nОбъединение $shardTotal долей прогона.\n");
    }
    foreach (['BXAPP_SHARD_TOTAL', 'BXAPP_SHARD_INDEX', 'BXAPP_SHARD_REPORT',
              'BXAPP_SHARD_AGGREGATE_DIR'] as $shardEnvName) {
        putenv($shardEnvName);
    }

    $executedBySuite = [];   // набор => какие проверки в нём РЕАЛЬНО выполнились
    $factChecks = [];        // набор => сколько проверок он выполнил
    foreach ($files as $suiteFile) {
        // Каждый набор начинает с чистого состояния. Иначе они зависят друг от
        // друга и от алфавитного порядка файлов: счётчик частоты, исчерпанный
        // предыдущим набором, ронял следующий с 429 — и это выглядело как дефект
        // кода, а не как грязь в тестах.
        // Сбрасываем и процессный снимок: PHP-глобалы не исчезают после
        // удаления файлов. Без этого store_test писал журнал с generation от
        // предыдущего suite, и fail-closed writer корректно отклонял такую строку.
        for ($release = 0; $release < 100 && isset($GLOBALS['__app_lifecycle_lock']); $release++) {
            appLifecycleRelease();
        }
        for ($release = 0; $release < 100 && appJournalMutationLockHeld(); $release++) {
            appJournalMutationLockRelease();
        }
        unset(
            $GLOBALS['__app_request_install_generation'],
            $GLOBALS['__app_request_lifecycle_shutdown'],
            $GLOBALS['__session_denial_reason'],
            $GLOBALS['__log_ref'],
            $GLOBALS['__b24_portal_get']
        );
        logContextClear();
        testWipe($tmp);
        appBindLogGeneration(null);
        $suiteName = basename($suiteFile); // require не должен иметь возможности подменить имя
        $state->suite = $suiteName;        // чтобы обрыв назвал виновника поимённо
        $beforeSuite = testLedger();
        $namesBefore = count(testExecutedNames());
        // Канал приводим к истине ДО набора: тогда сверка после него показывает
        // ровно то, что сделал набор.
        $GLOBALS['__tests_total'] = $beforeSuite['total'];
        $GLOBALS['__tests_failed'] = $beforeSuite['failed'];
        $GLOBALS['__tests_warnings'] = $beforeSuite['warnings'];
        $GLOBALS['__tests_completed'] = false;
        // Забор предупреждений PHP взводим ЗАНОВО перед каждым набором — вместе с
        // прочим сбросом состояния. Предыдущий набор мог занизить error_reporting
        // или поставить свой обработчик; без этого один такой набор глушил
        // предупреждения во всех следующих (K4-06).
        error_reporting(E_ALL);
        set_error_handler('testWarningHandler');
        // include наследует локальную область видимости. Без оболочки `$file`,
        // `$files`, `$tmp` и `$root` из suite перезаписывали переменные раннера:
        // resource-набор уводил следующий store_test в уже удалённый tmp, а его
        // `$file = .../t-store.php` ломал per-suite lookup. Даём suite нужные пути,
        // но не позволяем его рабочим переменным вытечь наружу.
        try {
            (static function (string $__suiteFile, string $root, string $tmp): void {
                require $__suiteFile;
            })($suiteFile, $root, $tmp);
        } finally {
            // testServer регистрирует только собственные proc_open handles.
            // Закрываем их на границе suite, а shutdown/TTL оставляем страховкой
            // для fatal/SIGKILL: следующий набор не наследует десятки listeners.
            testServersCleanup();
        }
        putenv('BXAPP_DATA_ROOT=' . $tmp);

        // Сверка канала с приватным счётом. Набор вправе добавлять свои проверки
        // и провалы, но не вправе трогать чужие результаты: занижённый счётчик,
        // укороченный список провалов или взведённая ловушка завершения — сами по
        // себе фатальный сбой, а итог берётся из приватного счёта.
        $afterSuite = testLedger();
        $tampered = [];
        $chanTotal = $GLOBALS['__tests_total'] ?? null;
        if (!is_int($chanTotal) || $chanTotal < $afterSuite['total']) {
            $tampered[] = 'счётчик проверок занижен до ' . var_export($chanTotal, true)
                . ' при ' . $afterSuite['total'] . ' выполненных';
        }
        $chanFailed = $GLOBALS['__tests_failed'] ?? null;
        if (!is_array($chanFailed)
            || array_slice($chanFailed, 0, count($afterSuite['failed'])) !== $afterSuite['failed']) {
            $tampered[] = 'список провалов стёрт или подменён (было ' . count($afterSuite['failed'])
                . ', осталось ' . (is_array($chanFailed) ? count($chanFailed) : 'не массив') . ')';
        }
        $chanWarnings = $GLOBALS['__tests_warnings'] ?? null;
        if (!is_array($chanWarnings)
            || array_slice($chanWarnings, 0, count($afterSuite['warnings'])) !== $afterSuite['warnings']) {
            $tampered[] = 'список предупреждений PHP стёрт или подменён';
        }
        if (!empty($GLOBALS['__tests_completed'])) {
            $tampered[] = 'набор объявил самопроверку завершённой';
        }
        // Сам механизм забора предупреждений — тоже результат прогона. Ставим
        // свой обработчик и E_ALL обратно и СМОТРИМ, что было после набора:
        // подменённый обработчик или занижённый error_reporting означают, что
        // предупреждения PHP этого набора (а раньше — и всех следующих) не
        // считались вовсе.
        $handlerAfter = set_error_handler('testWarningHandler');
        $reportingAfter = error_reporting(E_ALL);
        if ($handlerAfter !== 'testWarningHandler') {
            $tampered[] = 'подменил обработчик предупреждений PHP — предупреждения набора не попали в итог';
        }
        if (($reportingAfter & E_ALL) !== E_ALL) {
            $tampered[] = 'занизил error_reporting до ' . $reportingAfter
                . ' — предупреждения PHP перестают быть сбоем';
        }
        if ($tampered !== []) {
            $entry = "набор $suiteName вмешался в общий прогон: " . implode('; ', $tampered);
            testLedger(false, $entry);
            fwrite(STDERR, "  СБОЙ $entry\n");
        }

        $suiteChecks = $afterSuite['total'] - $beforeSuite['total'];
        // Кто что выполнил — считает раннер, а не набор: разницу списка имён
        // «до/после» изнутри набора не подделать.
        $suiteNamesRun = array_slice(testExecutedNames(), $namesBefore);
        $executedBySuite[$suiteName] = array_fill_keys($suiteNamesRun, true)
            + ($executedBySuite[$suiteName] ?? []);
        $factChecks[$suiteName] = ($factChecks[$suiteName] ?? 0) + $suiteChecks;
        fwrite(STDERR, "  · набор $suiteName: $suiteChecks проверок"
            . (isset(REQUIRED_SUITE_MIN_CHECKS[$suiteName])
                ? ' (минимум ' . REQUIRED_SUITE_MIN_CHECKS[$suiteName] . ')' : ' (набор проекта)') . "\n");
        // У набора проекта порог один: хотя бы одна проверка. Пустой файл-заглушка
        // с именем `*_test.php` не должен молча считаться проверкой.
        $suiteMinimum = REQUIRED_SUITE_MIN_CHECKS[$suiteName] ?? 1;
        if ($suiteChecks < $suiteMinimum) {
            testLedger(false, $suiteChecks === 0
                ? "набор $suiteName не выполнил ни одной проверки при минимуме $suiteMinimum"
                : "набор $suiteName выполнил $suiteChecks проверок при минимуме $suiteMinimum");
            fwrite(STDERR, "  СБОЙ набор $suiteName: $suiteChecks проверок, ожидается не меньше $suiteMinimum\n");
        }

        // Канал снова говорит правду: следующий набор видит восстановленный итог.
        $restored = testLedger();
        $GLOBALS['__tests_total'] = $restored['total'];
        $GLOBALS['__tests_failed'] = $restored['failed'];
        $GLOBALS['__tests_warnings'] = $restored['warnings'];
        $GLOBALS['__tests_completed'] = false;
    }
    $state->suite = '';

    if ($shardTotal > 1 && !$aggregateMode) {
        $part = testLedger();
        $partWarnings = array_values(array_unique($part['warnings']));
        if ($part['failed'] !== [] || $partWarnings !== []) {
            echo 'Провалено в доле ' . ($shardIndex + 1) . ': ' . count($part['failed']) . " из {$part['total']}:\n";
            foreach ($part['failed'] as $failure) echo "  - $failure\n";
            foreach (array_slice($partWarnings, 0, 10) as $warning) echo "  ! $warning\n";
            return $verdict(1);
        }
        if ($shardReport === '' || dirname($shardReport) === '.' || !is_dir(dirname($shardReport))) {
            fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: доле некуда записать отчёт.\n");
            return $verdict(1);
        }
        $report = [
            'schema' => 1,
            'index' => $shardIndex,
            'total_shards' => $shardTotal,
            'all_suites' => $allSuiteNames,
            'executed_by_suite' => array_map('array_keys', $executedBySuite),
            'fact_checks' => $factChecks,
            'check_total' => $part['total'],
        ];
        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reportTmp = $shardReport . '.tmp-' . getmypid();
        if (!is_string($encoded)
            || file_put_contents($reportTmp, $encoded . "\n", LOCK_EX) === false
            || !@chmod($reportTmp, 0600)
            || !@rename($reportTmp, $shardReport)) {
            @unlink($reportTmp);
            fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: доля не опубликовала отчёт атомарно.\n");
            return $verdict(1);
        }
        $code = $verdict(0);
        echo "BXAPP_SHARD_COMPLETE:$shardIndex:$shardTotal:{$part['total']}\n";
        return $code;
    }

    $aggregateBaseTotal = 0;
    if ($aggregateMode) {
        $executedBySuite = [];
        $factChecks = [];
        $seenSuites = [];
        for ($expectedIndex = 0; $expectedIndex < $shardTotal; $expectedIndex++) {
            $path = rtrim($aggregateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . 'report-' . $expectedIndex . '.json';
            $raw = @file_get_contents($path);
            $report = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($report)
                || ($report['schema'] ?? null) !== 1
                || ($report['index'] ?? null) !== $expectedIndex
                || ($report['total_shards'] ?? null) !== $shardTotal
                || ($report['all_suites'] ?? null) !== $allSuiteNames
                || !is_array($report['executed_by_suite'] ?? null)
                || !is_array($report['fact_checks'] ?? null)
                || !is_int($report['check_total'] ?? null)) {
                fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: отчёт доли " . ($expectedIndex + 1)
                    . " отсутствует, повреждён или относится к другому дереву.\n");
                return $verdict(1);
            }
            $expectedSuitesForShard = [];
            foreach ($allSuiteNames as $position => $suiteName) {
                if ($position % $shardTotal === $expectedIndex) $expectedSuitesForShard[] = $suiteName;
            }
            $actualSuitesForShard = array_keys($report['executed_by_suite']);
            sort($actualSuitesForShard);
            sort($expectedSuitesForShard);
            if ($actualSuitesForShard !== $expectedSuitesForShard) {
                fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: доля " . ($expectedIndex + 1)
                    . " исполнила не свой полный набор файлов.\n");
                return $verdict(1);
            }
            $sum = 0;
            foreach ($report['executed_by_suite'] as $suiteName => $names) {
                if (isset($seenSuites[$suiteName]) || !is_array($names)
                    || !isset($report['fact_checks'][$suiteName])
                    || !is_int($report['fact_checks'][$suiteName])) {
                    fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: отчёт долей содержит дубль или неверный счёт.\n");
                    return $verdict(1);
                }
                $seenSuites[$suiteName] = true;
                $executedBySuite[$suiteName] = array_fill_keys(array_map('strval', $names), true);
                $factChecks[$suiteName] = $report['fact_checks'][$suiteName];
                $sum += $report['fact_checks'][$suiteName];
            }
            if ($sum !== $report['check_total']) {
                fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: счёт доли " . ($expectedIndex + 1)
                    . " не равен сумме её наборов.\n");
                return $verdict(1);
            }
            $aggregateBaseTotal += $sum;
        }
        $seenSuiteNames = array_keys($seenSuites);
        sort($seenSuiteNames);
        $expectedAll = $allSuiteNames;
        sort($expectedAll);
        if ($seenSuiteNames !== $expectedAll) {
            fwrite(STDERR, "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: после объединения потерян или задвоен набор.\n");
            return $verdict(1);
        }
    }

    // ── ДОКАЗАТЕЛЬСТВО ПОВЕДЕНИЯ СЧИТАЕТСЯ ПО ИСПОЛНЕНИЮ (K4-01, K4-05) ──────
    //
    // Реестр готовых блоков называет проверки, которые доказывают поведение. До
    // 09.08.2026 их существование сверялось `strpos` по исходнику набора — и
    // заглушка, положившая те же имена в КОММЕНТАРИЙ, считалась доказательством.
    // Здесь сверяется список фактически выполненных проверок, собранный раннером.
    //
    // Реестра нет — сверять нечем, и это не «пройдено»: само наличие реестра
    // требует uses_template_test.php, который входит в обязательные наборы.
    $registryFile = $root . '/docs/capabilities.json';
    if (is_file($registryFile)) {
        $registry = json_decode((string)file_get_contents($registryFile), true);
        $bezDokazatelstv = [];
        $dokazannye = [];
        $registryContracts = array_merge(
            (array)($registry['capabilities'] ?? []),
            (array)($registry['project_extensions'] ?? [])
        );
        foreach ($registryContracts as $cap) {
            $capId = (string)($cap['id'] ?? '?');
            $capSuites = [];
            foreach ((array)($cap['tests'] ?? []) as $t) $capSuites[] = basename((string)$t);
            foreach ((array)($cap['checks'] ?? []) as $nazvanie) {
                $nazvanie = (string)$nazvanie;
                $gde = '';
                foreach ($capSuites as $capSuite) {
                    if (isset($executedBySuite[$capSuite][$nazvanie])) { $gde = $capSuite; break; }
                }
                if ($gde === '') {
                    $bezDokazatelstv[] = $capId . ': проверка «' . $nazvanie . '» не выполнилась ни в одном из названных наборов ('
                        . ($capSuites === [] ? 'наборы не названы' : implode(', ', $capSuites)) . ')';
                    continue;
                }
                $dokazannye[$gde] = true;
            }
        }
        check('НАЗВАННАЯ РЕЕСТРОМ ПРОВЕРКА ПОВЕДЕНИЯ РЕАЛЬНО ВЫПОЛНИЛАСЬ',
            $bezDokazatelstv === [], implode(' · ', $bezDokazatelstv));

        $netImeni = [];
        foreach (REQUIRED_SUITE_CHECK_NAMES as $nabor => $imena) {
            foreach ($imena as $imya) {
                if (isset($executedBySuite[$nabor][$imya])) { $dokazannye[$nabor] = true; continue; }
                $netImeni[] = $nabor . ': «' . $imya
                    . '» не выполнялась — набор подменён или проверку переименовали, обновите REQUIRED_SUITE_CHECK_NAMES';
            }
        }
        check('НАБОР-ЗАБОР ВЫПОЛНИЛ НАЗВАННЫЕ В КОНТРАКТЕ ПРОВЕРКИ',
            $netImeni === [], implode(' · ', $netImeni));

        $bezImeni = array_values(array_diff(REQUIRED_SUITES, array_keys($dokazannye)));
        check('У КАЖДОГО НАБОРА ШАБЛОНА ЕСТЬ ХОТЯ БЫ ОДНА ИМЕНОВАННАЯ ПРОВЕРКА',
            $bezImeni === [], $bezImeni === [] ? ''
                : 'подменяются заглушкой молча: ' . implode(', ', $bezImeni)
                  . ' — назовите их проверки в docs/capabilities.json или в REQUIRED_SUITE_CHECK_NAMES');
    } else {
        fwrite(STDERR, "\nРеестра docs/capabilities.json нет — «названное реестром выполнилось» не сверялось.\n");
    }

    // ── ПОРОГ НЕ ОТСТАЛ ОТ ФАКТА ────────────────────────────────────────────
    // Порог ниже факта — так и задумано, но у review_fixes_test.php он стоял 100
    // при 205 выполненных: заглушка на 100 пустых check() уносила половину
    // забора и проходила контракт (K4-01). Отставший порог — это не аккуратность,
    // это дыра, поэтому раннер называет её сам и говорит, до чего поднять.
    $otstavshie = [];
    foreach (REQUIRED_SUITE_MIN_CHECKS as $nabor => $porog) {
        $fakt = $factChecks[$nabor] ?? null;
        if ($fakt === null || $porog < 1 || $fakt < $porog) continue;   // недобор ловит проверка выше
        if ($fakt > (int)ceil($porog * SUITE_MIN_LAG_LIMIT)) {
            $otstavshie[] = "$nabor: порог $porog при $fakt выполненных, поднимите до " . (int)floor($fakt * 0.8);
        }
    }
    check('ПОРОГ НАБОРА ШАБЛОНА НЕ ОТСТАЛ ОТ ФАКТА', $otstavshie === [], implode(' · ', $otstavshie));

    $final = testLedger();
    $total  = $aggregateBaseTotal + $final['total'];
    $failed = $final['failed'];
    $warnings = array_values(array_unique($final['warnings']));
    echo "\n";
    if ($warnings !== []) {
        echo "ПРЕДУПРЕЖДЕНИЯ PHP (" . count($warnings) . " видов) — прогон считается неуспешным:\n";
        foreach (array_slice($warnings, 0, 10) as $w) echo "  ! $w\n";
        echo "\n";
    }
    if ($failed === [] && $total < MIN_CHECKS) {
        echo "САМОПРОВЕРКА НЕ ВЫПОЛНЕНА: выполнено $total проверок, ожидается не меньше " . MIN_CHECKS . ".\n";
        echo "Проверки удалены или набор не догрузился — это не повод считать код проверенным.\n";
        return $verdict(1);
    }
    if ($failed === [] && $warnings === []) {
        $code = $verdict(0);
        echo "BXAPP_SELF_CHECK_COMPLETE:$total\n";
        echo "Все проверки пройдены: $total\n";
        return $code;
    }
    if ($failed === []) {
        echo "Проверки пройдены ($total), но PHP ругался — это тоже сбой: чините предупреждения выше.\n";
        return $verdict(1);
    }
    echo 'Провалено ' . count($failed) . " из $total:\n";
    foreach ($failed as $f) echo "  - $f\n";
    return $verdict(1);
})($root, $tmp);

exit($exitCode);
