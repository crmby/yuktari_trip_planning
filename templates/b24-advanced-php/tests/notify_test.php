<?php
declare(strict_types=1);
/**
 * Уведомления людям: не теряются при молчащем портале, не приходят дважды,
 * безнадёжные не крутятся в очереди вечно.
 *
 * Портал подменён штатным швом `__b24_call` (работает только при уведённом
 * сторе). Настоящий портал в испытаниях не участвует.
 */

suite('Уведомления людям в портале');

require_once dirname(__DIR__) . '/www/api/notify.php';

/** Игрушечный портал: принимает уведомления, умеет отказывать по указанию. */
final class NotifyFakePortal {
    public array $delivered = [];   // TAG → текст (как у портала: та же метка заменяет прежнее)
    public array $failWith = [];    // TAG → код ошибки
    public bool $silent = false;    // портал не отвечает вовсе
    public int $batches = 0;
    /** @var callable|null Что происходит В МОМЕНТ отправки — второй запрос человека. */
    public $duringBatch = null;

    public function handle(string $method, array $params): array {
        if ($method !== 'batch') return ['error' => 'unexpected_method', 'error_description' => $method];
        $this->batches++;
        // Пока приложение говорит с порталом, оно не одно: параллельный запрос
        // человека тоже пишет в очередь. Именно здесь ловится гонка.
        if ($this->duringBatch !== null) { $fn = $this->duringBatch; $this->duringBatch = null; $fn(); }
        if ($this->silent) return ['error' => 'HTTP_ERROR', 'error_description' => 'портал не ответил'];
        $result = [];
        $errors = [];
        foreach ((array)($params['cmd'] ?? []) as $key => $line) {
            $query = (string)strstr((string)$line, '?');
            parse_str($query === '' ? '' : substr($query, 1), $q);
            if (isset($this->failWith[$key])) { $errors[$key] = $this->failWith[$key]; continue; }
            $this->delivered[(string)($q['TAG'] ?? $key)] = (string)($q['MESSAGE'] ?? '');
            $result[$key] = true;
        }
        return ['result' => ['result' => $result, 'result_error' => $errors]];
    }
}

storeWrite(B24_TOKENS_FILE, [
    'access_token' => 'a', 'refresh_token' => 'r',
    'domain' => 'portal.invalid', 'client_endpoint' => 'https://portal.invalid/rest/',
    'member_id' => 'M-NOTIFY', 'expires_at' => time() + 3600,
]);
storeWrite(NOTIFY_OUTBOX_FILE, []);

$portal = new NotifyFakePortal();
$GLOBALS['__b24_call'] = static fn(string $m, array $p): array => $portal->handle($m, $p);
$b24 = b24();

// 1. Постановка в очередь не ходит в портал: запрос человека не ждёт сеть.
$portal->batches = 0;
$tag = notifyQueue(7, 'Обмен с 1С упал, проверьте настройки');
check('уведомление встало в очередь', notifyPending() === 1, (string)notifyPending());
check('при постановке в очередь портал не дёргается', $portal->batches === 0, (string)$portal->batches);
check('вернулась метка сообщения', $tag !== '', $tag);

// 2. Отправка доставляет и очищает очередь.
$out = notifyDrain($b24);
check('уведомление доставлено', $out['sent'] === 1, json_encode($out));
check('очередь опустела', notifyPending() === 0, (string)notifyPending());
check('портал получил текст целиком',
    ($portal->delivered[$tag] ?? '') === 'Обмен с 1С упал, проверьте настройки',
    json_encode($portal->delivered, JSON_UNESCAPED_UNICODE));

// 3. ПОРТАЛ МОЛЧИТ — уведомление не теряется, а ждёт следующей попытки.
$portal->silent = true;
notifyQueue(7, 'Задача готова', ['key' => 'task-done']);
$out = notifyDrain($b24);
check('при молчащем портале ничего не доставлено', $out['sent'] === 0, json_encode($out));
check('УВЕДОМЛЕНИЕ НЕ ПОТЕРЯНО', notifyPending() === 1, (string)notifyPending());

// 4. Повтор раньше срока не долбит портал: пауза соблюдается.
$portal->silent = false;
$portal->batches = 0;
$out = notifyDrain($b24);
check('до истечения паузы повтора нет', $portal->batches === 0 && $out['sent'] === 0, json_encode($out));

// Отматываем паузу назад — так же сделает время.
storeUpdate(NOTIFY_OUTBOX_FILE, static function (array $q): array {
    foreach (($q['items'] ?? []) as $i => $_) $q['items'][$i]['next_try_at'] = 0;
    return $q;
});
$out = notifyDrain($b24);
check('после паузы сообщение уходит', $out['sent'] === 1 && notifyPending() === 0, json_encode($out));

// 5. Дублей нет: то же уведомление, поставленное дважды, уходит одной меткой.
notifyQueue(7, 'Проверьте документ', ['key' => 'doc-1']);
notifyQueue(7, 'Проверьте документ (уточнено)', ['key' => 'doc-1']);
check('повторная постановка не плодит второе сообщение', notifyPending() === 1, (string)notifyPending());
notifyDrain($b24);
check('человек получил последний текст, а не два сообщения',
    ($portal->delivered[notifyTag(7, '', 'doc-1')] ?? '') === 'Проверьте документ (уточнено)',
    json_encode($portal->delivered, JSON_UNESCAPED_UNICODE));

// Прежний 32-bit suffix смешивал эти два разных logical keys в одной строке.
$collisionTagA = notifyQueue(7, 'first', ['key' => 'sameЖЦЧЯЖЖ']);
$collisionTagB = notifyQueue(7, 'second', ['key' => 'sameЩЯЭЩЭЫ']);
$collisionItems = (array)(storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? []);
check('разные notify keys не смешиваются при прежней 32-bit tag-коллизии',
    $collisionTagA !== $collisionTagB && count($collisionItems) === 2
    && count(array_unique(array_column($collisionItems, 'identity'))) === 2
    && array_column($collisionItems, 'text') === ['first', 'second'],
    json_encode(['tags' => [$collisionTagA, $collisionTagB], 'items' => $collisionItems], JSON_UNESCAPED_UNICODE));
notifyDrain($b24);

// Default identity прежде считалась от sha1(text)[0:12], поэтому эта пара
// разных текстов имела одинаковые tag и identity и схлопывалась в одну row.
$defaultTextA = 'notify-default-09fafba09345';
$defaultTextB = 'notify-default-798df7d93864';
$defaultTagA = notifyQueue(7, $defaultTextA);
$defaultTagB = notifyQueue(7, $defaultTextB);
$defaultCollisionItems = (array)(storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? []);
check('default notify identity считается из полного текста, а не 48-bit сокращения',
    $defaultTagA !== $defaultTagB && count($defaultCollisionItems) === 2
    && count(array_unique(array_column($defaultCollisionItems, 'identity'))) === 2
    && array_column($defaultCollisionItems, 'text') === [$defaultTextA, $defaultTextB],
    json_encode(['tags' => [$defaultTagA, $defaultTagB], 'items' => $defaultCollisionItems], JSON_UNESCAPED_UNICODE));
notifyDrain($b24);

$legacyDefaultText = 'legacy default pending';
storeWrite(NOTIFY_OUTBOX_FILE, ['items' => [[
    'tag' => notifyLegacyTag(7, $legacyDefaultText),
    'user_id' => 7,
    'text' => $legacyDefaultText,
    'out' => '',
    'queued_at' => time(),
    'rev' => 1,
    'tries' => 0,
    'next_try_at' => 0,
]], 'failed' => []]);
$migratedDefaultTag = notifyQueue(7, $legacyDefaultText);
$migratedDefaultItems = (array)(storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? []);
check('legacy default notify мигрирует только по точному полному тексту',
    count($migratedDefaultItems) === 1
    && ($migratedDefaultItems[0]['tag'] ?? '') === $migratedDefaultTag
    && ($migratedDefaultItems[0]['identity'] ?? '') === notifyIdentity($legacyDefaultText),
    json_encode($migratedDefaultItems, JSON_UNESCAPED_UNICODE));
notifyDrain($b24);

// 6. Безнадёжный отказ убирается из очереди с причиной, а не крутится вечно.
notifyQueue(999, 'Кому-то, кого нет', ['key' => 'ghost']);
$portal->failWith = [notifyTag(999, '', 'ghost') => 'ERROR_USER_NOT_FOUND'];
$out = notifyDrain($b24);
check('несуществующий получатель не остаётся в очереди навсегда',
    notifyPending() === 0 && $out['failed'] === 1, json_encode($out));
$failed = notifyFailed();
check('причина недоставки сохранена и видна',
    isset($failed[0]['reason']) && str_contains((string)$failed[0]['reason'], 'USER_NOT_FOUND'),
    json_encode($failed, JSON_UNESCAPED_UNICODE));

// 7. Всплеск не выжигает лимит портала: за проход уходит не больше порции.
$portal->failWith = [];
for ($i = 0; $i < 120; $i++) notifyQueue(7, 'Сделка ' . $i . ' обработана', ['key' => 'deal-' . $i]);
check('в очередь встали все 120', notifyPending() === 120, (string)notifyPending());
$out = notifyDrain($b24, 50);
check('за один проход уходит не больше порции', $out['sent'] === 50, json_encode($out));
check('остальное осталось ждать', $out['left'] === 70, json_encode($out));

// 8. Мусор на входе отвергается сразу, а не превращается в пустое сообщение.
$thrown = '';
try { notifyQueue(0, 'текст'); } catch (Throwable $e) { $thrown = $e->getMessage(); }
check('уведомление без получателя не заводится', str_contains($thrown, 'без получателя'), $thrown);
$thrown = '';
try { notifyQueue(7, '   '); } catch (Throwable $e) { $thrown = $e->getMessage(); }
check('пустой текст не заводится', str_contains($thrown, 'без текста'), $thrown);

// 9. Приложение сняли с портала — очередь чужого портала не должна остаться.
require_once dirname(__DIR__) . '/www/api/lib.php';
check('файл очереди объявлен среди состояния, которое чистится при удалении',
    in_array(NOTIFY_OUTBOX_FILE, appPortalBoundResetTargets(), true)
    || array_key_exists(NOTIFY_OUTBOX_FILE, appPortalBoundResetTargets()),
    'файл: ' . NOTIFY_OUTBOX_FILE);

// 10. ГОНКА: пока идёт отправка, человек уточняет то же сообщение. Уточнение не
// должно исчезнуть вместе с завершением старой отправки — иначе получатель
// навсегда останется с устаревшим текстом, и никто об этом не узнает.
storeWrite(NOTIFY_OUTBOX_FILE, []);
$portal->delivered = [];
notifyQueue(7, 'Обмен идёт', ['key' => 'race-1']);
$portal->duringBatch = static function (): void {
    notifyQueue(7, 'Обмен ЗАВЕРШЁН', ['key' => 'race-1']);   // второй запрос, пока портал думает
};
$out = notifyDrain($b24);
check('УТОЧНЕНИЕ, ПРИШЕДШЕЕ ВО ВРЕМЯ ОТПРАВКИ, НЕ ПОТЕРЯНО',
    notifyPending() === 1, 'в очереди осталось: ' . notifyPending() . ', ' . json_encode($out));
$out = notifyDrain($b24);
check('человек получает уточнённый текст следующим проходом',
    ($portal->delivered[notifyTag(7, '', 'race-1')] ?? '') === 'Обмен ЗАВЕРШЁН',
    json_encode($portal->delivered, JSON_UNESCAPED_UNICODE));
check('после доставки уточнения очередь пуста', notifyPending() === 0, (string)notifyPending());

// Тот же край с отказом: старая отправка провалилась безнадёжно, но текст уже
// уточнён — списывать в недоставленные нужно старую версию, а не хоронить новую.
storeWrite(NOTIFY_OUTBOX_FILE, []);
$portal->delivered = [];
notifyQueue(7, 'Первый текст', ['key' => 'race-2']);
$portal->failWith = [notifyTag(7, '', 'race-2') => 'ERROR_USER_NOT_FOUND'];
$portal->duringBatch = static function (): void {
    notifyQueue(7, 'Исправленный текст', ['key' => 'race-2']);
};
notifyDrain($b24);
check('уточнение не списано в недоставленные вместе со старой попыткой',
    notifyPending() === 1, (string)notifyPending());
$portal->failWith = [];
notifyDrain($b24);
check('исправленный текст всё-таки доходит',
    ($portal->delivered[notifyTag(7, '', 'race-2')] ?? '') === 'Исправленный текст',
    json_encode($portal->delivered, JSON_UNESCAPED_UNICODE));

// 11. Уточнение МЕЖДУ попытками. Прежний текст словил временный отказ и ждёт
// паузу в час; человек тем временем исправил сообщение. Новый текст не обязан
// отбывать чужое наказание: пауза и сожжённые попытки относились к старому
// содержимому, и с ними исправление уходит вместо минуты через час, а до
// «безнадёжно» ему остаётся всего одна попытка вместо пяти.
storeWrite(NOTIFY_OUTBOX_FILE, []);
$portal->delivered = [];
notifyQueue(7, 'Старый текст', ['key' => 'pause-1']);
$portal->silent = true;
notifyDrain($b24);            // попытка 1 — портал молчит
$portal->silent = false;
$posle = (storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? [])[0] ?? [];
check('после отказа строка ждёт паузу и помнит попытку',
    (int)($posle['tries'] ?? 0) === 1 && (int)($posle['next_try_at'] ?? 0) > time(),
    json_encode($posle, JSON_UNESCAPED_UNICODE));

notifyQueue(7, 'Исправленный текст', ['key' => 'pause-1']);
$utochnenie = (storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? [])[0] ?? [];
check('УТОЧНЁННЫЙ ТЕКСТ НЕ ЖДЁТ ПАУЗУ, НАЗНАЧЕННУЮ ПРЕЖНЕМУ',
    (int)($utochnenie['next_try_at'] ?? -1) === 0,
    json_encode($utochnenie, JSON_UNESCAPED_UNICODE));
check('УТОЧНЁННЫЙ ТЕКСТ ПОЛУЧАЕТ ПОПЫТКИ ЗАНОВО',
    (int)($utochnenie['tries'] ?? -1) === 0,
    json_encode($utochnenie, JSON_UNESCAPED_UNICODE));
check('уточнение считается новой версией содержимого',
    (int)($utochnenie['rev'] ?? 0) === 2, json_encode($utochnenie, JSON_UNESCAPED_UNICODE));
$out = notifyDrain($b24);
check('исправление уходит тем же проходом, а не через час',
    ($portal->delivered[notifyTag(7, '', 'pause-1')] ?? '') === 'Исправленный текст',
    json_encode($portal->delivered, JSON_UNESCAPED_UNICODE) . ' ' . json_encode($out));

// Обратный край: тот же самый текст поставили заново. Это не уточнение, а
// повтор — обнулять паузу нельзя, иначе каждый такт крона будет долбить портал
// одним и тем же сообщением, ради защиты от которого пауза и заведена.
storeWrite(NOTIFY_OUTBOX_FILE, []);
$portal->delivered = [];
notifyQueue(7, 'Тот же текст', ['key' => 'pause-2']);
$portal->silent = true;
notifyDrain($b24);
$portal->silent = false;
notifyQueue(7, 'Тот же текст', ['key' => 'pause-2']);
$povtor = (storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? [])[0] ?? [];
check('ПОВТОР ТОГО ЖЕ ТЕКСТА ПАУЗУ НЕ СБРАСЫВАЕТ',
    (int)($povtor['next_try_at'] ?? 0) > time() && (int)($povtor['tries'] ?? 0) === 1,
    json_encode($povtor, JSON_UNESCAPED_UNICODE));

// Текст для внешних каналов — часть содержимого: раньше уточнение почтовой
// версии просто терялось, человек получал письмо со старым текстом.
storeWrite(NOTIFY_OUTBOX_FILE, []);
notifyQueue(7, 'Текст', ['key' => 'pause-3', 'out' => 'старая почта']);
notifyQueue(7, 'Текст', ['key' => 'pause-3', 'out' => 'новая почта']);
$pochta = (storeRead(NOTIFY_OUTBOX_FILE)['items'] ?? [])[0] ?? [];
check('УТОЧНЁННЫЙ ТЕКСТ ДЛЯ ПОЧТЫ НЕ ТЕРЯЕТСЯ',
    ($pochta['out'] ?? '') === 'новая почта', json_encode($pochta, JSON_UNESCAPED_UNICODE));

storeWrite(NOTIFY_OUTBOX_FILE, []);
unset($GLOBALS['__b24_call']);

// ── ВРАЖДЕБНЫЕ СЛУЧАИ ОЧЕРЕДИ ───────────────────────────────────────────────
// Порча файла очереди и смена портала под очередью. Оба случая тихие: снаружи
// приложение работает, а сообщения либо теряются, либо уезжают чужим людям.

// 1. Файл очереди испорчен (обрыв записи, чужая правка руками). Это НЕ «очередь
// пуста»: считать порчу пустотой значит затереть единственную улику и молча
// потерять накопленные сообщения.
testWipe(DATA_ROOT);
notifyQueue(101, 'до порчи');
file_put_contents(NOTIFY_OUTBOX_FILE, BXAPP_PRIVATE_PHP_HEADER . '{это не наш формат');
$porcha = null;
try { notifyPending(); } catch (Throwable $e) { $porcha = $e; }
check('ИСПОРЧЕННАЯ ОЧЕРЕДЬ НЕ ЧИТАЕТСЯ КАК ПУСТАЯ',
    $porcha instanceof StoreCorruptedException, $porcha ? get_class($porcha) : 'исключения не было');
$karantin = glob(dirname(NOTIFY_OUTBOX_FILE) . '/notify-outbox.bad-*.php') ?: [];
check('копия испорченной очереди отложена на разбор, а не удалена',
    count($karantin) === 1, 'копий: ' . count($karantin));
check('карантинная копия видна человеку среди состояния приложения',
    in_array($karantin[0] ?? '', appQuarantineFiles(), true),
    json_encode(appQuarantineFiles(), JSON_UNESCAPED_UNICODE));

// 2. Портал сменился (приложение переустановили на другой портал). Уведомления
// адресованы людям ПРЕЖНЕГО портала: те же номера там принадлежат другим
// сотрудникам, и отправка после переустановки — это сообщение чужому человеку.
testWipe(DATA_ROOT);
$smenaK = new B24('local.test.app', 'secret');
$smenaK->saveTokensFromInstall([
    'AUTH_ID' => 'notify-access', 'REFRESH_ID' => 'notify-refresh', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-notify.invalid', 'member_id' => 'MEMBER-СТАРЫЙ',
    'APPLICATION_TOKEN' => 'key-notify', 'APPLICATION_SCOPE' => 'crm,user,im',
]);
notifyQueue(202, 'сообщение прежнего портала');
check('перед сменой портала сообщение в очереди', notifyPending() === 1, (string)notifyPending());
// Список сброса — это «файл ⇒ чем его заменить», поэтому спрашиваем ключ.
check('файл очереди назван среди состояния, привязанного к порталу',
    array_key_exists(NOTIFY_OUTBOX_FILE, appPortalBoundResetTargets()),
    'очереди нет в списке сброса при смене портала: '
    . json_encode(array_map('basename', array_keys(appPortalBoundResetTargets())), JSON_UNESCAPED_UNICODE));
appResetPortalBoundState();
check('ПОСЛЕ СМЕНЫ ПОРТАЛА ОЧЕРЕДЬ ПУСТА — ЧУЖИМ ЛЮДЯМ НИЧЕГО НЕ УЕДЕТ',
    notifyPending() === 0, (string)notifyPending());
check('недоставленные прежнего портала тоже не остались',
    notifyFailed() === [], json_encode(notifyFailed(), JSON_UNESCAPED_UNICODE));

// ── Предел попыток соблюдается ТОЧНО (BXREV-024) ───────────────────────────
// Находка ревью 08.08.2026: в порцию уезжало СТАРОЕ число попыток, а в
// состояние писалось новое. Решение «попытки кончились» принималось по числу на
// единицу меньше — при пределе в пять портал звали шесть раз. Считаем вызовы.
testWipe(DATA_ROOT);
$limK = new B24('local.test.app', 'secret');
$limK->saveTokensFromInstall([
    'AUTH_ID' => 'notify-lim', 'REFRESH_ID' => 'notify-lim-r', 'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'portal-notify.invalid', 'member_id' => 'MEMBER-LIM',
    'APPLICATION_TOKEN' => 'key-lim', 'APPLICATION_SCOPE' => 'crm,user,im',
]);
$vyzovov = 0;
$GLOBALS['__b24_transport'] = static function (string $m, array $p) use (&$vyzovov): array {
    if ($m !== 'batch') return ['result' => []];
    // Портал занят: каждая команда пакета отвечает временной ошибкой.
    $oshibki = [];
    foreach (array_keys((array)($p['cmd'] ?? [])) as $k) {
        $vyzovov++;
        $oshibki[(string)$k] = ['error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'портал занят'];
    }
    return ['result' => ['result' => [], 'result_error' => $oshibki]];
};
notifyQueue(303, 'сообщение, которое портал не примет');
// Гоняем заведомо больше раз, чем позволено: пауза между попытками снимается
// перемоткой next_try_at — ждать минуты в испытании нельзя.
for ($i = 0; $i < NOTIFY_MAX_TRIES + 3; $i++) {
    notifyDrain($limK);
    storeUpdate(NOTIFY_OUTBOX_FILE, static function (array $q): array {
        foreach (($q['items'] ?? []) as $idx => $row) $q['items'][$idx]['next_try_at'] = 0;
        return $q;
    });
}
check('ПРИ ПРЕДЕЛЕ В ПЯТЬ ПОПЫТОК ПОРТАЛ ЗОВУТ РОВНО ПЯТЬ РАЗ',
    $vyzovov === NOTIFY_MAX_TRIES, 'вызовов: ' . $vyzovov . ', предел: ' . NOTIFY_MAX_TRIES);
check('исчерпавшее попытки ушло в недоставленные, а не крутится вечно',
    notifyPending() === 0 && count(notifyFailed()) === 1,
    'в очереди ' . notifyPending() . ', недоставленных ' . count(notifyFailed()));
unset($GLOBALS['__b24_transport']);

// ── BX13-02: очередь ограничена по item/count/serialized bytes ─────────────
// Граница одной записи считается тем же storeEncode(), которым она попадёт на
// диск. ASCII-подкладка добавляет ровно один сериализованный байт на символ.
testWipe(DATA_ROOT);
$itemBase = [
    'tag' => notifyTag(404, '', 'bytes-boundary'),
    'user_id' => 404,
    'identity' => notifyIdentity('', 'bytes-boundary'),
    'text' => '',
    'out' => '',
    'queued_at' => time(),
    'rev' => 1,
    'tries' => 0,
    'next_try_at' => 0,
];
$itemPayloadBytes = NOTIFY_ITEM_MAX_BYTES - notifyItemStoredBytes($itemBase);
$itemAtLimit = $itemBase;
$itemAtLimit['text'] = str_repeat('x', $itemPayloadBytes);
check('notify item: точная граница bytes вычислена в store-сериализации',
    notifyItemStoredBytes($itemAtLimit) === NOTIFY_ITEM_MAX_BYTES,
    (string)notifyItemStoredBytes($itemAtLimit));
notifyQueue(404, $itemAtLimit['text'], ['key' => 'bytes-boundary']);
$itemRawAtLimit = (string)file_get_contents(NOTIFY_OUTBOX_FILE);
check('notify item: ровно limit байт принимается', notifyPending() === 1,
    'pending=' . notifyPending());
$itemTooLarge = null;
try {
    notifyQueue(404, $itemAtLimit['text'] . 'x', ['key' => 'bytes-boundary']);
} catch (Throwable $e) {
    $itemTooLarge = $e;
}
check('notify item: limit+1 даёт typed item_bytes failure',
    $itemTooLarge instanceof NotifyQueueLimitException
        && $itemTooLarge->reason === 'item_bytes',
    $itemTooLarge ? get_class($itemTooLarge) . ':' . $itemTooLarge->getMessage() : 'нет исключения');
check('notify item: reject атомарен и не меняет outbox ни байта',
    (string)file_get_contents(NOTIFY_OUTBOX_FILE) === $itemRawAtLimit,
    'размер до=' . strlen($itemRawAtLimit)
        . ', после=' . (string)@filesize(NOTIFY_OUTBOX_FILE));

// Точная суммарная byte-граница. Семидесяти строк достаточно, чтобы набрать
// 4 MiB, не нарушив отдельный item cap; поле pad нужно только synthetic fixture.
$totalAtLimit = ['items' => [], 'failed' => []];
for ($i = 0; $i < 70; $i++) {
    $totalAtLimit['items'][] = [
        'tag' => 'app-1-total-' . $i,
        'user_id' => 1,
        'text' => 'x',
        'out' => '',
        'queued_at' => time(),
        'rev' => 1,
        'tries' => 0,
        'next_try_at' => 0,
        'pad' => '',
    ];
}
$totalNeed = NOTIFY_MAX_BYTES - notifyStoredBytes($totalAtLimit);
foreach ($totalAtLimit['items'] as $i => $row) {
    if ($totalNeed <= 0) break;
    $room = NOTIFY_ITEM_MAX_BYTES - notifyItemStoredBytes($row);
    $add = min($room, $totalNeed);
    $totalAtLimit['items'][$i]['pad'] = str_repeat('x', $add);
    $totalNeed -= $add;
}
check('notify state: synthetic fixture достиг ровно total byte limit',
    $totalNeed === 0 && notifyStoredBytes($totalAtLimit) === NOTIFY_MAX_BYTES,
    'осталось=' . $totalNeed . ', bytes=' . notifyStoredBytes($totalAtLimit));
$totalBoundaryError = null;
try { notifyAssertStateWithinLimits($totalAtLimit); } catch (Throwable $e) { $totalBoundaryError = $e; }
check('notify state: ровно total limit принимается', $totalBoundaryError === null,
    $totalBoundaryError ? $totalBoundaryError->getMessage() : 'ok');
$totalOver = $totalAtLimit;
$totalOver['items'][69]['pad'] .= 'x';
$totalOverError = null;
try { notifyAssertStateWithinLimits($totalOver); } catch (Throwable $e) { $totalOverError = $e; }
check('notify state: total limit+1 даёт typed total_bytes failure',
    $totalOverError instanceof NotifyQueueLimitException
        && $totalOverError->reason === 'total_bytes',
    $totalOverError ? get_class($totalOverError) . ':' . $totalOverError->getMessage() : 'нет исключения');

// Count boundary отдельно от bytes: строки минимальны и суммарный cap не мешает.
$countRow = [
    'tag' => 'app-1-count', 'user_id' => 1, 'text' => 'x', 'out' => '',
    'queued_at' => time(), 'rev' => 1, 'tries' => 0, 'next_try_at' => 0,
];
$countAtLimit = ['items' => array_fill(0, NOTIFY_MAX_ITEMS, $countRow), 'failed' => []];
$countBoundaryError = null;
try { notifyAssertStateWithinLimits($countAtLimit); } catch (Throwable $e) { $countBoundaryError = $e; }
check('notify count: ровно N записей принимается', $countBoundaryError === null,
    $countBoundaryError ? $countBoundaryError->getMessage() : 'ok');
$countOver = $countAtLimit;
$countOver['items'][] = $countRow;
$countOverError = null;
try { notifyAssertStateWithinLimits($countOver); } catch (Throwable $e) { $countOverError = $e; }
check('notify count: N+1 даёт typed count failure',
    $countOverError instanceof NotifyQueueLimitException && $countOverError->reason === 'count',
    $countOverError ? get_class($countOverError) . ':' . $countOverError->getMessage() : 'нет исключения');

// Terminal failed: оставляем новые, старые/лишние/огромные причины не раздувают
// файл. Pending при этой уборке вообще не участвует и не теряется.
$failedFixture = [];
$failedNow = time();
for ($i = 0; $i < NOTIFY_FAILED_MAX_ITEMS + 25; $i++) {
    $failedFixture[] = [
        'tag' => 'failed-' . $i,
        'user_id' => $i + 1,
        'reason' => str_repeat('я', NOTIFY_FAILED_REASON_MAX_BYTES),
        'at' => $failedNow + $i,
    ];
}
$failedFixture[] = [
    'tag' => 'expired', 'user_id' => 1, 'reason' => 'old',
    'at' => $failedNow - NOTIFY_KEEP_FAILED_DAYS * 86400 - 1,
];
$failedBounded = notifyBoundFailed($failedFixture,
    $failedNow - NOTIFY_KEEP_FAILED_DAYS * 86400);
check('notify failed: count и serialized bytes ограничены одновременно',
    count($failedBounded) <= NOTIFY_FAILED_MAX_ITEMS
        && notifyStoredBytes(['failed' => $failedBounded]) <= NOTIFY_FAILED_MAX_BYTES,
    'count=' . count($failedBounded)
        . ', bytes=' . notifyStoredBytes(['failed' => $failedBounded]));
check('notify failed: TTL и overflow удаляют старейшие, новый evidence остаётся',
    !in_array('expired', array_column($failedBounded, 'tag'), true)
        && in_array('failed-' . (NOTIFY_FAILED_MAX_ITEMS + 24), array_column($failedBounded, 'tag'), true),
    json_encode(array_column($failedBounded, 'tag'), JSON_UNESCAPED_UNICODE));

// Legacy-файл > cap проверяется по stat ДО storeRead: иначе сам decode уже OOM.
testWipe(DATA_ROOT);
$legacyBytes = NOTIFY_MAX_BYTES + 1;
file_put_contents(NOTIFY_OUTBOX_FILE,
    BXAPP_PRIVATE_PHP_HEADER . str_repeat('x', $legacyBytes - strlen(BXAPP_PRIVATE_PHP_HEADER)));
$legacyRawHash = hash_file('sha256', NOTIFY_OUTBOX_FILE);
$legacyError = null;
try { notifyDrain($limK); } catch (Throwable $e) { $legacyError = $e; }
check('notify legacy: oversized file отклонён typed preflight до decode/drain',
    $legacyError instanceof NotifyStateTooLargeException
        && $legacyError->reason === 'legacy_bytes',
    $legacyError ? get_class($legacyError) . ':' . $legacyError->getMessage() : 'нет исключения');
check('notify legacy: preflight не переписал и не quarantine-ил state',
    hash_file('sha256', NOTIFY_OUTBOX_FILE) === $legacyRawHash
        && (glob(dirname(NOTIFY_OUTBOX_FILE) . '/notify-outbox.bad-*.php') ?: []) === [],
    'hash=' . hash_file('sha256', NOTIFY_OUTBOX_FILE));

// Несколько producers стартуют у count=N-2. Внешний preflight не является
// защитой от гонки; доказательство — лимит повторно проверяется внутри CAS-lock.
testWipe(DATA_ROOT);
$nearCap = ['items' => [], 'failed' => []];
for ($i = 0; $i < NOTIFY_MAX_ITEMS - 2; $i++) {
    $row = $countRow;
    $row['tag'] = 'app-1-pre-' . $i;
    $nearCap['items'][] = $row;
}
storeWrite(NOTIFY_OUTBOX_FILE, $nearCap);
$producer = sys_get_temp_dir() . '/bxapp-notify-producer-' . getmypid() . '.php';
file_put_contents($producer, "<?php\nrequire "
    . var_export(dirname(__DIR__) . '/www/api/notify.php', true)
    . ";\ntry { notifyQueue((int)\$argv[1], 'producer-' . \$argv[1], ['key' => 'producer-' . \$argv[1]]); echo 'OK'; }"
    . " catch (NotifyQueueLimitException \$e) { echo 'LIMIT:' . \$e->reason; }\n");
$producerDesc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$producerProcs = [];
$producerPipes = [];
for ($i = 0; $i < 8; $i++) {
    $producerProcs[$i] = proc_open([PHP_BINARY, $producer, (string)(500 + $i)],
        $producerDesc, $producerPipes[$i], null,
        ['BXAPP_DATA_ROOT' => DATA_ROOT, 'PATH' => (string)getenv('PATH')]);
}
$producerOut = [];
foreach ($producerProcs as $i => $proc) {
    if (!is_resource($proc)) continue;
    $producerOut[] = trim((string)stream_get_contents($producerPipes[$i][1]));
    $producerErr = trim((string)stream_get_contents($producerPipes[$i][2]));
    fclose($producerPipes[$i][1]);
    fclose($producerPipes[$i][2]);
    $producerExit = proc_close($proc);
    if ($producerExit !== 0) $producerOut[] = 'EXIT:' . $producerExit . ':' . $producerErr;
}
@unlink($producer);
$producerOk = count(array_filter($producerOut, static fn(string $out): bool => $out === 'OK'));
$producerLimited = count(array_filter($producerOut,
    static fn(string $out): bool => $out === 'LIMIT:count'));
$producerState = storeRead(NOTIFY_OUTBOX_FILE) ?? [];
check('notify concurrency: producers не превышают cap и ровно свободные два проходят',
    $producerOk === 2 && $producerLimited === 6
        && count((array)($producerState['items'] ?? [])) === NOTIFY_MAX_ITEMS,
    implode(' | ', $producerOut) . '; count=' . count((array)($producerState['items'] ?? [])));
check('notify concurrency: итоговый state остаётся в item/count/bytes пределах',
    notifyStoredBytes($producerState) <= NOTIFY_MAX_BYTES,
    'bytes=' . notifyStoredBytes($producerState));

testWipe(DATA_ROOT);
