<?php
declare(strict_types=1);

// ПРЯМОЙ HTTP К ЭТОМУ ФАЙЛУ — 404. Деплой публикует каталог целиком, поэтому под
// веб-корнем лежит и то, что вызывают только по require. Пустой ответ 200
// подтверждает существование файла и его путь (находка ревью 08.08.2026,
// BXREV-025). CLI и подключение из другого файла проверка не задевает: она
// смотрит, запрошен ли ИМЕННО этот файл.
if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
/**
 * Даты портала и деньги — два места, где ошибка не падает, а тихо врёт.
 *
 * Даты. Портал отдаёт время в ISO со смещением портала: «2026-08-02T00:30:00+03:00».
 * Привычное `date('Y-m-d', strtotime($v))` считает это в часовом поясе СЕРВЕРА —
 * и на хостинге с UTC ночная сделка уезжает во вчера. Отчёт при этом выглядит
 * правдоподобно и расходится с CRM только на краях суток, поэтому замечают его
 * через месяц и по жалобе клиента.
 *
 * Деньги. `float` не хранит копейки точно: 0.1 + 0.2 не равно 0.3, и после
 * десятка сложений итог расходится с CRM на копейки. Считаем в целых копейках,
 * округляем ровно один раз — на выводе.
 */

/**
 * Календарный день из даты портала: срез строки, без часовых поясов.
 *
 * Именно срез, а не разбор времени: день, к которому портал отнёс запись, уже
 * записан в первых десяти символах — пересчитывать его нельзя, только испортить.
 */
function b24Day(?string $iso): string {
    $s = trim((string)$iso);
    if ($s === '') return '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/', $s, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? substr($s, 0, 10) : '';
}

/**
 * Период для фильтра портала — полуоткрытый: `>=от` и `<до+1 день`.
 *
 * Так в выборку попадает весь последний день целиком. Привычное `<=до` теряет
 * всё, что произошло в этот день после полуночи, — то есть почти весь день.
 *
 * @return array{0:string,1:string} [от (включая), до (не включая)]
 */
function b24Range(string $fromDay, string $toDay): array {
    $from = b24Day($fromDay);
    $to   = b24Day($toDay);
    if ($from === '' || $to === '') {
        throw new InvalidArgumentException('b24Range: даты задаются как ГГГГ-ММ-ДД');
    }
    // Человек может перепутать поля местами. Пустой отчёт в таком случае похож
    // на правду, поэтому нормализуем порядок здесь, в общем helper, а не надеемся
    // на каждую форму отдельно.
    if ($from > $to) [$from, $to] = [$to, $from];
    // +1 день считаем календарно, без перевода в секунды: перевод спотыкается на
    // переходах летнего времени, а нам нужен именно следующий календарный день.
    $next = (new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC')))
        ->modify('+1 day')->format('Y-m-d');
    return [$from, $next];
}

/** Сегодня по времени ПОРТАЛА (а не сервера), как ГГГГ-ММ-ДД. */
function b24Today(?string $portalTz = null): string {
    $tz = $portalTz ?? (string)(loadSettings()['portal_tz'] ?? '');
    if ($tz === '') {
        throw new RuntimeException('b24Today: часовой пояс портала неизвестен; сначала получите его через b24PortalTz()');
    }
    try {
        $zone = new DateTimeZone($tz);
    } catch (Throwable $e) {
        throw new UnexpectedValueException('b24Today: сохранён неверный часовой пояс портала', 0, $e);
    }
    return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
}

/**
 * Часовой пояс портала — спрашиваем у самого портала и запоминаем.
 *
 * Без этого «сегодня» в приложении и «сегодня» у сотрудника — разные дни, и
 * дневной отчёт не сходится с тем, что человек видит в CRM.
 */
function b24TzFromOffset($rawOffset): string {
    if (!is_int($rawOffset) && !(is_string($rawOffset) && preg_match('/^-?\d+$/', $rawOffset))) {
        throw new UnexpectedValueException('profile.TIME_ZONE_OFFSET отсутствует или имеет неверный тип');
    }
    $offset = (int)$rawOffset;
    if (abs($offset) > 14 * 3600 || $offset % 60 !== 0) {
        throw new UnexpectedValueException('profile.TIME_ZONE_OFFSET вне допустимого диапазона');
    }

    // Offset — это НЕ IANA-геозона. timezone_name_from_abbr() подбирает случайную
    // географическую зону с таким смещением, а у неё может быть DST: зимой дата
    // верна, летом уезжает. Храним ровно то, что сообщил портал, включая :30/:45.
    $abs = abs($offset);
    return sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv($abs, 3600), intdiv($abs % 3600, 60));
}

/**
 * Часовой пояс из официального ответа profile.
 *
 * Документация обещает result.TIME_ZONE. На части порталов он пуст, но offset
 * виден в time.date_start; старые ответы также встречаются с
 * result.TIME_ZONE_OFFSET. IANA-зоне отдаём приоритет: она сама знает DST.
 */
function b24TzFromProfileResponse(array $response): string {
    $result = $response['result'] ?? null;
    if (!is_array($result)) throw new UnexpectedValueException('profile.result отсутствует');

    $named = trim((string)($result['TIME_ZONE'] ?? ''));
    if ($named !== '') {
        try {
            new DateTimeZone($named);
            return $named;
        } catch (Throwable $e) {
            // Невалидное имя не превращаем в случайную геозону: ниже пробуем
            // только явно сообщённый порталом offset.
        }
    }
    if (array_key_exists('TIME_ZONE_OFFSET', $result)) {
        try {
            return b24TzFromOffset($result['TIME_ZONE_OFFSET']);
        } catch (Throwable $e) {
            // Некоторые версии отдают битое legacy-поле, хотя корректный
            // offset всё ещё есть в time.date_start.
        }
    }

    $dateStart = trim((string)($response['time']['date_start'] ?? ''));
    if (preg_match('/(Z|([+-])(\d{2}):(\d{2}))$/', $dateStart, $m)) {
        if ($m[1] === 'Z') return '+00:00';
        $seconds = ((int)$m[3] * 3600 + (int)$m[4] * 60) * ($m[2] === '-' ? -1 : 1);
        return b24TzFromOffset($seconds);
    }
    throw new UnexpectedValueException('profile не сообщил корректный TIME_ZONE или offset');
}

/**
 * Вынесено из b24PortalTz(), чтобы контракт «ошибку не кэшировать» проверялся
 * без настоящего портала: callback обязан вернуть сырой ответ метода profile.
 */
function b24PortalTzResolve(callable $profile): string {
    $settings = loadSettings();
    $saved = (string)($settings['portal_tz'] ?? '');
    $savedValid = false;
    if ($saved !== '') {
        try {
            new DateTimeZone($saved);
            $savedValid = true;
        } catch (Throwable $e) {
            // Старое/ручное битое значение не должно навсегда отключить повторную
            // попытку спросить портал. Ниже попробуем получить корректное заново.
        }
    }
    // Даже корректный fixed offset устаревает при DST, а IANA-зона/настройка
    // пользователя может измениться. Раз в час обновляем, но при отказе
    // оставляем рабочее старое значение и НЕ двигаем отметку проверки.
    $checkedAt = (int)($settings['portal_tz_checked_at'] ?? 0);
    if ($savedValid && $checkedAt >= time() - 3600) return $saved;

    try {
        $r = $profile();
        if (!is_array($r) || isset($r['error'])) {
            throw new RuntimeException('profile: ' . (string)($r['error_description'] ?? $r['error'] ?? 'неверный ответ'));
        }
        $tz = b24TzFromProfileResponse($r);
    } catch (Throwable $e) {
        // Доверять можно только ранее подтверждённой зоне портала. Серверная TZ
        // может отличаться на сутки у границы дня; выдавать её как портал —
        // тихая порча отчётов. Без кэша останавливаемся явно и оставляем
        // следующему запросу возможность снова спросить profile.
        if ($savedValid) return $saved;
        throw new RuntimeException('Часовой пояс портала пока не получен: profile временно недоступен', 0, $e);
    }
    try {
        updateSettings(static function (array $s) use ($tz): array {
            $s['portal_tz'] = $tz;
            $s['portal_tz_checked_at'] = time();
            return $s;
        });
    } catch (Throwable $e) {
        // Кеш — оптимизация. Если профиль уже дал точную зону, сбой записи кеша
        // не должен подменять её серверной зоной в текущем запросе.
    }
    return $tz;
}

function b24PortalTz(B24 $b24): string {
    return b24PortalTzResolve(static fn(): array => $b24->call('profile'));
}

/**
 * Рубли (строка/число от портала или человека) → целые копейки.
 *
 * @param string|int|float|null $value Тип в докблоке, а не в сигнатуре: шаблон
 *        обещает работу на PHP 7.4, а объединения типов появились в 8.0.
 */
function money($value): int {
    if ($value === null || $value === '') return 0;
    if (is_float($value)) {
        throw new InvalidArgumentException('money: float уже мог потерять копейки; передавайте decimal строкой');
    }
    if (!is_int($value) && !is_string($value)) {
        throw new InvalidArgumentException('money: сумма должна быть целым числом рублей или decimal-строкой');
    }

    $s = preg_replace('/[ \x{00A0}\x{202F}]/u', '', trim((string)$value));
    if (!is_string($s) || !preg_match('/^([+-]?)(\d+)(?:[\.,](\d*))?$/', $s, $m)) {
        throw new InvalidArgumentException('money: неверный формат суммы');
    }
    $negative = $m[1] === '-';
    $whole = ltrim($m[2], '0');
    $whole = $whole === '' ? '0' : $whole;
    $fraction = (string)($m[3] ?? '');
    $two = str_pad(substr($fraction, 0, 2), 2, '0');
    $digits = ltrim($whole . $two, '0');
    $digits = $digits === '' ? '0' : $digits;

    // Округление half-up выполняем строками: преобразование через float теряло
    // единицы уже далеко до предела int (например, после 2^53).
    if (isset($fraction[2]) && $fraction[2] >= '5') {
        $carry = 1;
        for ($i = strlen($digits) - 1; $i >= 0 && $carry; $i--) {
            $n = (ord($digits[$i]) - 48) + $carry;
            $digits[$i] = chr(48 + ($n % 10));
            $carry = intdiv($n, 10);
        }
        if ($carry) $digits = '1' . $digits;
    }

    // У отрицательного int на единицу больше модуль (PHP_INT_MIN), поэтому
    // проверка только против PHP_INT_MAX ошибочно отвергала ровно одно
    // корректное значение. Не вызываем abs(PHP_INT_MIN): он превращается во
    // float и возвращает нас к той самой потере точности, от которой уходим.
    $negativeLimit = ltrim((string)PHP_INT_MIN, '-');
    $limit = $negative ? $negativeLimit : (string)PHP_INT_MAX;
    if (strlen($digits) > strlen($limit)
        || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        throw new OverflowException('money: сумма в копейках не помещается в int');
    }
    if ($negative && $digits === $negativeLimit) return PHP_INT_MIN;
    $cents = (int)$digits;
    return $negative && $cents !== 0 ? -$cents : $cents;
}

/**
 * Денежное поле ИЗ ОТВЕТА ПОРТАЛА → целые копейки.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ ФУНКЦИЯ. `money()` намеренно отвергает float: от человека и из
 * настроек сумма приходит строкой, и молчаливое «1.1 → 1.10» скрыло бы потерю
 * копеек. Но портал — не человек: `opportunity` документирован как `double`
 * (b24-rest-docs, crm/universal/object-fields.md), и в официальном примере ответа
 * стоит обычное JSON-число `"opportunity": 999.9` (crm-item-get.md). После
 * штатного `json_decode($raw, true)` это PHP float — то есть строгий `money()`
 * на обычной сделке с дробной суммой ронял ВЕСЬ отчёт исключением, и обойти это
 * без адаптера можно было только ослабив контракт для всех (находка ревью
 * 09.08.2026, R3-032).
 *
 * ПОЧЕМУ НЕЛЬЗЯ ПРОСТО `(string)$float`. `(string)` печатает число с настройкой
 * `precision` (по умолчанию 14 значащих цифр) — крупная сумма теряет разряды
 * молча, а `1.0E+15` вообще не является decimal-записью. Поэтому ищем САМУЮ
 * КОРОТКУЮ запись с фиксированной точкой, которая читается обратно ровно в это
 * же число: она и есть та decimal-лексема, которую прислал портал. Дальше —
 * обычный строковый `money()`, то есть половина копейки вверх, как везде.
 *
 * ГРАНИЦА. Свыше 2^53 у двоичного числа шаг больше рубля: «сумма» перестаёт быть
 * суммой, и досчитывать её нельзя — отказ, а не тихая копейка. NaN, ∞, логическое
 * и массив тоже отказ: ноль вместо суммы человек заметит последним.
 *
 * @param string|int|float|null $value Значение поля как его вернул json_decode().
 * @throws InvalidArgumentException Не число, не строка, NaN/∞ или не разобралось.
 * @throws OverflowException Сумма вне точности двоичного числа или не влезает в int.
 */
function moneyFromB24($value): int {
    if ($value === null || $value === '') return 0;
    // Целое и строку отдаём прежнему строгому пути без единой поблажки.
    if (is_int($value) || is_string($value)) return money($value);
    if (!is_float($value)) {
        throw new InvalidArgumentException('money: сумма от портала должна быть числом или строкой');
    }
    if (is_nan($value) || is_infinite($value)) {
        throw new InvalidArgumentException('money: портал прислал не число (NaN/∞) вместо суммы');
    }
    // 2^53 — последнее целое, которое двоичное число хранит точно.
    $predel = 9007199254740992.0;
    if ($value > $predel || $value < -$predel) {
        throw new OverflowException('money: сумма пришла числом за пределом точности — разряды уже потеряны');
    }
    // Фиксированная точка, а не %G: экспоненциальная запись не является decimal и
    // потребовала бы второго разбора. Растим число знаков, пока запись не начнёт
    // читаться обратно ровно в исходное число.
    $decimal = null;
    for ($znakov = 0; $znakov <= 20; $znakov++) {
        $proba = sprintf('%.' . $znakov . 'F', $value);
        if ((float)$proba === $value) { $decimal = $proba; break; }
    }
    if ($decimal === null) {
        // Ветка достижима на числах мельче 1e-20: копеек там нет, но и врать
        // нулём нельзя — пусть вызывающий решает, что это было.
        throw new InvalidArgumentException('money: сумму не восстановить из числа без потери разрядов');
    }
    return money($decimal);
}

/** Копейки → строка для человека: «1 234,50». */
function moneyStr(int $cents, bool $withCents = true): string {
    // Без копеек — ОКРУГЛЯЕМ до рубля, а не отбрасываем хвост. Отбрасывание давало
    // две неприятности сразу: 150,50 показывалось как «150» (в итоговой строке
    // отчёта копейки исчезали и сумма не сходилась с порталом) и, что заметнее,
    // −0,49 печаталось как «-0» — минус ноль, которого не бывает.
    if (!$withCents) {
        // Частное уже далеко от границ int, а остаток лежит в -99..99. Так
        // округление half-away-from-zero не вызывает abs(PHP_INT_MIN) и не
        // складывает PHP_INT_MAX + 50, где PHP тихо переходил в float.
        $rubles = intdiv($cents, 100);
        $remainder = $cents % 100;
        if ($remainder >= 50) $rubles++;
        elseif ($remainder <= -50) $rubles--;
        $sign = $rubles < 0 ? '-' : '';
        $head = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', ltrim((string)$rubles, '-'));
        return $sign . (string)$head;
    }
    $sign = $cents < 0 ? '-' : '';
    $digits = ltrim((string)$cents, '-');
    $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
    $rub = substr($digits, 0, -2);
    $kop = substr($digits, -2);
    $head = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $rub);
    return $sign . (string)$head . ',' . $kop;
}

/** Копейки → число для отправки в портал (рубли с двумя знаками). */
function moneyForB24(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $digits = str_pad(ltrim((string)$cents, '-'), 3, '0', STR_PAD_LEFT);
    return $sign . substr($digits, 0, -2) . '.' . substr($digits, -2);
}
