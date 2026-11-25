<?php
declare(strict_types=1);
/**
 * Сбой, который НЕ проходит через обработчик исключений: кончилась память.
 *
 * Зачем отдельный набор. Человек в этот момент видит не «ошибку», а НИЧЕГО:
 * белый экран в окне Битрикса или зависший интерфейс, если запрос был фоновым.
 * Ни кода обращения, ни подсказки, что делать. В журнале запись есть — но её
 * читает разработчик, а не сотрудник, который сейчас стоит перед пустым окном.
 *
 * Проверяем в НАСТОЯЩЕМ веб-запросе: только так видно и код ответа, и заголовки,
 * и тело. Точка входа для сбоя кладётся в копию дерева — в шаблоне такого файла
 * нет и быть не должно.
 */

suite('Ответ при фатальном сбое (память кончилась)');

$eater = <<<'PHP'
<?php
// Точка входа ТОЛЬКО для испытаний: намеренно упирается в предел памяти.
ini_set('memory_limit', '24M');
require_once __DIR__ . '/api/lib.php';
installFailureHandler(isset($_GET['json']));
$eat = [];
for ($i = 0; $i < 100000; $i++) { $eat[] = str_repeat('x', 512 * 1024); }
echo 'сюда мы не дойдём';
PHP;

// Тот же сбой, но запрос УЖЕ успел отправить в портал изменение. Ответ обязан
// звучать иначе: обещание «данные целы» здесь враньё, из-за которого человек
// повторяет действие и заводит вторую сделку.
$eaterAfterChange = <<<'PHP'
<?php
// Точка входа ТОЛЬКО для испытаний: изменение начато, затем предел памяти.
ini_set('memory_limit', '24M');
require_once __DIR__ . '/api/lib.php';
installFailureHandler(isset($_GET['json']));
appChangesStarted('crm.deal.add');
$eat = [];
for ($i = 0; $i < 100000; $i++) { $eat[] = str_repeat('x', 512 * 1024); }
echo 'сюда мы не дойдём';
PHP;

$srv = testServer([], ['fixtures' => [
    'zz-pamyat.php' => $eater,
    'zz-pamyat-izm.php' => $eaterAfterChange,
]]);
if (!$srv['ok']) { check('встроенный сервер запущен', false); return; }
$call = $srv['call'];

// 1. Страница: человек обязан увидеть объяснение, а не пустое окно.
[$code, , $body, $head] = $call('GET', '/zz-pamyat.php', ['timeout' => 30]);
check('при нехватке памяти ответ приходит с кодом ошибки', $code === 500, "код $code");
check('ЧЕЛОВЕК ВИДИТ НЕ ПУСТОЙ ЭКРАН', trim($body) !== '', 'длина тела: ' . strlen($body));
check('в ответе есть человеческое объяснение, а не машинный текст',
    mb_strpos($body, 'Приложение') !== false || mb_strpos($body, 'ошибка') !== false
    || mb_strpos($body, 'Ошибка') !== false,
    mb_substr(strip_tags($body), 0, 160));
check('такой ответ не кэшируется', stripos($head, 'no-store') !== false,
    trim(explode("\r\n\r\n", $head)[0] ?? ''));
check('путь к файлу на сервере наружу не уходит',
    strpos($body, '/www/') === false && strpos($body, '.php:') === false,
    mb_substr(strip_tags($body), 0, 160));

// 2. Эндпоинт: фронт разбирает ответы одним куском кода — значит и здесь нужен
//    привычный формат {ok:false,...}, иначе пользователь увидит «ошибка 500»
//    вместо нашего текста.
[$code, $json, $body, ] = $call('GET', '/zz-pamyat.php?json=1', ['timeout' => 30]);
check('эндпоинт при нехватке памяти тоже отвечает кодом ошибки', $code === 500, "код $code");
check('ОТВЕТ ЭНДПОИНТА РАЗБИРАЕТСЯ ФРОНТОМ',
    is_array($json) && ($json['ok'] ?? null) === false, mb_substr($body, 0, 160));
check('в ответе назван вид отказа', ($json['code'] ?? '') !== '', mb_substr($body, 0, 160));

// 3. Главное: приложение не обещает того, чего не знает.
//    Раньше страница говорила «Данные не пострадали: незавершённое действие не
//    сохраняется» ВСЕГДА — в том числе после того, как в портал ушла мутация.
[, , $bodyTiho, ] = $call('GET', '/zz-pamyat.php', ['timeout' => 30]);
[, $jsonTiho, , ] = $call('GET', '/zz-pamyat.php?json=1', ['timeout' => 30]);
[, , $bodyIzm, ] = $call('GET', '/zz-pamyat-izm.php', ['timeout' => 30]);
[, $jsonIzm, $rawIzm, ] = $call('GET', '/zz-pamyat-izm.php?json=1', ['timeout' => 30]);

check('без изменений ответ говорит, что повторить можно',
    mb_stripos($bodyTiho, 'можно повторить') !== false, mb_substr(strip_tags($bodyTiho), 0, 200));
check('ПОСЛЕ НАЧАТОЙ МУТАЦИИ ОТВЕТ НЕ ОБЕЩАЕТ СОХРАННОСТЬ ДАННЫХ',
    mb_stripos($bodyIzm, 'не пострадали') === false && mb_stripos($bodyIzm, 'можно повторить') === false,
    mb_substr(strip_tags($bodyIzm), 0, 200));
check('после начатой мутации человека предупреждают о частичном применении',
    mb_stripos($bodyIzm, 'могла успеть примениться') !== false
    && mb_stripos($bodyIzm, 'проверьте результат') !== false,
    mb_substr(strip_tags($bodyIzm), 0, 200));
check('фронт видит машинный признак: изменений не начинали',
    ($jsonTiho['changes'] ?? '') === 'not_started', json_encode($jsonTiho, JSON_UNESCAPED_UNICODE));
check('ФРОНТ ВИДИТ МАШИННЫЙ ПРИЗНАК: ИЗМЕНЕНИЯ МОГЛИ ПРИМЕНИТЬСЯ',
    ($jsonIzm['changes'] ?? '') === 'maybe_applied', mb_substr($rawIzm, 0, 200));
check('ответ эндпоинта после мутации остаётся разбираемым JSON',
    is_array($jsonIzm) && ($jsonIzm['ok'] ?? null) === false, mb_substr($rawIzm, 0, 200));

// Журнал разработчика тоже обязан различать эти два случая: без отметки разбор
// «повторили ли действие дважды» упирается в догадки по времени.
$logIzm = (string)@file_get_contents(journalFile());
check('в журнале видно, что при сбое изменения уже были начаты',
    strpos($logIzm, 'изменения были начаты') !== false, mb_substr($logIzm, -300));

// 4. Журнал остаётся: разработчику по-прежнему видно, что именно случилось.
$log = (string)@file_get_contents(journalFile());
check('в журнале приложения записан фатальный сбой',
    strpos($log, 'ФАТАЛ') !== false || strpos($log, 'memory') !== false,
    mb_substr($log, -200));
