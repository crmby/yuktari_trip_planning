<?php
// Главный handler — один URL для install POST'а и runtime'а этой установки.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/api/store.php';
require_once __DIR__ . '/api/b24.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/plan.php';      // тариф портала: темп запросов по его лимитам

// Упало что-то неожиданное (например, состояние на диске повреждено) — человек
// увидит объяснение, а не пустую вкладку.
installFailureHandler();

// Ни одна страница приложения не кэшируется: правку разметки человек должен
// видеть после обычного обновления, а не после жёсткой перезагрузки (которой
// внутри iframe Б24 у него и нет). Ставим сразу, до любого вывода, — тогда
// покрыты все ветки: сплэш установки, заглушки 403/409 и сам интерфейс.
sendNoStoreHeaders();

// ── Кто имеет право встраивать нас в iframe ──────────────────────────────
// Приложение живёт внутри портала, поэтому X-Frame-Options ставить нельзя — он
// сломает встраивание. Но и «пусть встраивает кто угодно» не годится: страницу с
// кнопками, меняющими CRM, можно накрыть прозрачным слоем на чужом сайте и
// заставить человека нажать не то. Поэтому рамку задаём явно: пока приложение не
// установлено — маски порталов Битрикса, после установки — ровно домен своего
// портала. Само правило живёт в api/b24.php одной функцией: этот же ответ ниже,
// после подтверждённой установки, обязан пересобрать заголовок от свежего домена.
$frameAncestorsDomain = (static function (): string {
    $domain = '';
    try {
        // Через общий резолвер, а не своим чтением токенов: при работе постоянным
        // ключом токенов нет вовсе, а портал известен — он записан в самом ключе.
        // Своё чтение оставляло здесь пустую строку, приложение отдавало облачные
        // маски, и портал на собственном домене не мог встроить нас в рамку —
        // человек видел «отказано в подключении» вместо приложения.
        $domain = (string)(b24Portal() ?? '');
    } catch (Throwable $e) { $domain = ''; }
    appSendFrameAncestors($domain);
    return $domain;
})();

// Страницу приложения отдаём только НАВИГАЦИИ (переход, iframe портала), но не
// фоновому запросу. Вторая половина той же защиты: закрыв iframe политикой выше,
// сосед по домену мог бы просто сделать fetch этой страницы — браузер приложил бы
// нашу cookie, а в ответе лежит пропуск. У такого запроса Sec-Fetch-Dest = empty,
// у настоящего открытия — document или iframe. Заголовка нет вовсе (старый
// браузер, curl) — пропускаем: ломать вход тем, у кого его не шлют, нельзя.
(static function (): void {
    // Смотрим режим, а не назначение: навигацией браузер называет и переход по
    // ссылке, и открытие в рамке, и отправку формы установки (POST) — всё это
    // наш штатный вход. Фоновый запрос навигацией не бывает никогда, а подделать
    // эти заголовки со страницы нельзя: их ставит сам браузер.
    $mode = (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
    $dest = (string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
    if ($mode === '' && $dest === '') return;                       // заголовков нет — старый браузер, не наказываем
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    // Same-origin сосед нельзя отличить по Origin: приложения разделены путями,
    // а не доменами. Он не может встроить нас из-за frame-ancestors и не может
    // fetch'нуть страницу, но мог открыть popup и прочитать APP_SESSION через
    // opener. Настоящий iframe портала — cross-site + iframe; install POST тоже
    // приходит с портала. Закрываем только top-level popup/переход соседа.
    if ($mode === 'navigate'
        && !($site === 'same-origin' && $dest === 'document')) return;
    if ($mode === '' && in_array($dest, ['document', 'iframe', 'frame', 'nested-document'], true)) return;
    // Единственное исключение из забора — доверенный предпросмотр дев-портала.
    // Оно НЕ ослабляет защиту от соседа по домену: сосед может подделать любой
    // заголовок, но не подпись на ключе, который лежит файлом на сервере и в
    // браузер не попадает. Нет ключа (боевой хостинг) — исключения нет вовсе.
    if (previewActive()) return;
    // Голая строка текста здесь была тупиком: человек видит запрет и не знает,
    // что делать дальше. renderServicePage() ещё не подключён (это самое начало
    // контроллера, до env.php), поэтому отдаём тот же смысл минимальным HTML:
    // причина плюс действие.
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Только из портала</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;background:#14161a;color:#e6e8eb;padding:40px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">Страница доступна только внутри Битрикс24</h1>'
        . '<p style="margin:0 0 8px;color:#a8b0b8">Приложение открывается в рамке портала, '
        . 'по прямой ссылке оно работать не может.</p>'
        . '<p style="margin:0;color:#a8b0b8">Откройте его из левого меню портала.</p></body>';
    exit;
})();

// ── 1. INSTALL POST handler ──────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    // Единый bounded parser используется и install/lifecycle, и session-гейтом.
    // 413 отдаётся до JSON/form decode и до любого обращения к порталу.
    $post = portalPostFields();
} catch (PortalPostBodyTooLargeException $e) {
    http_response_code(413);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Слишком большой запрос</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;background:#14161a;color:#e6e8eb;padding:40px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">Запрос слишком большой</h1>'
        . '<p style="margin:0 0 8px;color:#a8b0b8">Портал прислал больше данных, чем приложение принимает за раз.</p>'
        . '<p style="margin:0;color:#a8b0b8">Откройте приложение из меню портала заново; '
        . 'если повторится — сообщите администратору.</p></body>';
    exit;
}

// ── Серверное событие установки (ONAPPINSTALL) ───────────────────────────
// Портал шлёт его отдельным POST-ом с вложенным auth[]. В UI-шаблоне событие не
// может быть связано с конкретным поколением установки. Оно вправе только один
// раз заполнить пустой application_token при точном совпадении сохранённой
// browser OAuth-пары; OAuth/generation/confirmed не меняет. После привязки это
// state-no-op только с тем же ключом; произвольный POST не получает 200.
// ONAPPUPDATE отдельно умеет усвоить подтверждённую порталом ротацию ключа.
if ($method === 'POST' && is_array($post['auth'] ?? null)) {
    $life = lifecycleTokenEvent($post);
    if ($life !== null) {
        sendNoStoreHeaders();
        header('Content-Type: application/json; charset=utf-8');
        if (!$life['ok']) {
            http_response_code($life['code']);
            echo json_encode(['ok' => false, 'error' => $life['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$authId = (string)($post['AUTH_ID'] ?? '');
$needInstallFinish = false;
$appReinstallNeeded = false; // POST для другой привязки, усвоить не смогли (#app-migration)
$recordOwnerAfterFence = false;
$rememberUrlAfterFence = false;
$indexInstallGeneration = null;
$installationAdoptedThisRequest = false;
$portalInstalledStatus = null;
$currentUserContext = null;
$installFinishOutcomeUnknown = false;

$renderIndexInstallationFailure = static function (string $phase): void {
    $failure = appInstallationFailureSpec($phase);
    if ($failure['retry_after'] > 0 && !headers_sent()) {
        header('Retry-After: ' . $failure['retry_after']);
    }
    renderServicePage($failure['title'], $failure['reason'], $failure['action'], [
        'code' => $failure['status'], 'tone' => $failure['tone'],
    ]);
    exit;
};

// После окончательного token decision держим один SH до конца iframe-запроса.
// Без него A успевал опознать A-user, migration публиковал B, а createSession()
// перечитывал уже B identity и выдавал A-admin пропуск поколения B.
$holdIndexInstallation = static function (?B24 $snapshot = null) use ($renderIndexInstallationFailure): string {
    try {
        $generation = appLifecycleHoldRequest();
    } catch (InstallGenerationMismatchException $e) {
        $renderIndexInstallationFailure($e->phase);
    } catch (InstallLifecycleBusyException $e) {
        renderServicePage('Приложение обновляется',
            'Сейчас завершается установка или перенос приложения.',
            'Подождите несколько секунд и откройте приложение заново.', ['code' => 503, 'tone' => 'info']);
        exit;
    }
    $expected = $snapshot ? (string)($snapshot->installGeneration() ?? '') : $generation;
    if ($expected === '' || !hash_equals($expected, $generation)) {
        $renderIndexInstallationFailure('changed');
    }
    return $generation;
};

if ($method === 'POST' && $authId !== '') {
    storeEnsureDir(DATA_ROOT);

    $cid = defined('B24_CLIENT_ID') ? B24_CLIENT_ID : '';
    $sec = defined('B24_CLIENT_SECRET') ? B24_CLIENT_SECRET : '';
    $b24 = new B24($cid, $sec);

    // Browser install POST официально может НЕ содержать application_token. Поэтому
    // первая/повторная формальная установка подтверждается обменом REFRESH_ID нашим
    // client_secret, а DOMAIN/member_id/endpoint берутся из OAuth-ответа. Строгая
    // сверка application_token остаётся дополнительным быстрым каналом там, где ключ
    // действительно пришёл. KB: rule-b24-verify-application-token.
    $isFirst  = !$b24->hasTokens();
    $isFormal = ($post['INSTALL'] ?? '') === 'Y' || ($post['event'] ?? '') === 'ONAPPINSTALL';
    $savedAppToken = $b24->applicationToken();
    $postAppToken  = (string)($post['APPLICATION_TOKEN'] ?? $post['application_token'] ?? '');
    $oldDomain     = (string)($b24->domain() ?? '');
    // Через общий помощник, а не напрямую из POST: коробка DOMAIN не присылает,
    // и своё чтение давало здесь пустую строку — признак «POST пришёл с ДРУГОГО
    // портала» на коробке не срабатывал никогда, то есть перенос приложения
    // между коробочными порталами молча не запускался.
    $postDomain    = portalPostDomain($post);
    $oldMemberId   = $b24->memberId();
    $oldClientId   = $b24->clientId();
    $appChanged    = ($oldClientId !== null && $oldClientId !== '' && $cid !== '' && $oldClientId !== $cid);
    $portalTransportFenced = portalPostPassesTransportFence($post, $oldDomain);
    // На первой установке documented POST не содержит отдельного install-флага.
    // Само отсутствие токенов лишь запускает crypto-proof, но не даёт доверия.
    $processInstallPost = $isFirst || $isFormal || $portalTransportFenced || $appChanged;
    $installPostVerified = false;

    // Один AUTH_ID без APP_SID/точного DOMAIN/Referer больше не запускает ни
    // user.current, ни app.info. Formal install и операторская смена client_id
    // проходят отдельно: их подлинность доказывает refresh нашим client_secret.
    if ($processInstallPost) {
    // Строгая сверка application_token, если он уже сохранён. Для старых установок без него
    // (savedAppToken===null) — trust-on-first-use, но ТОЛЬКО на тот же портал (postDomain===oldDomain)
    // + валидный AUTH_ID, иначе POST увёл бы client_endpoint на чужой домен. backfill ниже запишет токен.
    //
    // Сама политика живёт в installPostAuthenticity() (api/b24.php) — одна на все
    // публичные обработчики. Держать её здесь копией нельзя: второй обработчик
    // (api/install.php) именно так и остался без проверки.
    $installAuth    = installPostAuthenticity($b24, $post, $portalTransportFenced);
    $strictMatch    = $installAuth['strict'];
    $tokenAuthentic = $installAuth['authentic'];

    // Смена scope (добавили bizproc/placement в карточку) приходит НЕ как INSTALL=Y, а обычным
    // PLACEMENT-POST'ом при открытии: Б24 шлёт свежий токен + актуальный APPLICATION_SCOPE, а
    // application_token НЕ меняется (strict проходит). Подхватываем свежий токен, если scope РЕАЛЬНО
    // изменился, POST strict-аутентичен И открывающий — АДМИН (иначе installer-токен деградировал бы
    // до прав не-админа → сервер перестал бы видеть данные). KB: rule-b24-verify-application-token. #reinstall-scope
    $postScope    = (string)($post['APPLICATION_SCOPE'] ?? '');
    $scopeChanged = ($postScope !== '' && $postScope !== $b24->savedScope());
    $scopeRefreshOk = false;
    if ($scopeChanged && $strictMatch && !$isFirst && $portalTransportFenced) {
        $ctx = resolveUserContextFromAuth($authId, $oldDomain);
        $scopeRefreshOk = is_array($ctx) && !empty($ctx['isAdmin']);
    }

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    // ── Переезд на другой портал / пересоздание local-app (#app-migration) ────────────────────
    // Признак: в env.php вписали креды ДРУГОГО local-app (client_id env != client_id токена) И/ИЛИ
    // Б24 прислал POST с ДРУГОГО портала (postDomain != oldDomain). Строгая сверка application_token
    // тут невозможна (он выдан прежней привязкой) → раньше сейв блокировался: токен «закисал» на
    // старом портале, member_id не менялся, автоочистка портал-специфичного data/ не запускалась.
    // Подлинность подтверждаем криптостойко внутри verifyAndAdopt (refresh нашим client_secret) +
    // admin-гейт + троттл. KB: b24-local-app-portal-migration-self-heal, rule-audit-contours #app-migration.
    // Регистр имени портала ничего не значит (RFC 1035): «PORTAL.bitrix24.ru» и
    // «portal.bitrix24.ru» — один и тот же портал. Точное сравнение объявляло бы
    // такое открытие переездом на ДРУГОЙ портал и гнало приложение в ветку
    // переустановки на ровном месте. Тот же резолвер и та же сверка, что в
    // installPostAuthenticity() (R3-004).
    $portalPosted = ($postDomain !== '' && $oldDomain !== ''
        && strcasecmp($postDomain, $oldDomain) !== 0);
    if ($isFirst) {
        // ПЕРВАЯ УСТАНОВКА — только по криптодоказательству.
        //
        // Раньше здесь действовало «доверие в первый раз»: раз токенов ещё нет,
        // сохраняем то, что прислали. Но адрес обработчика публичен, и любой POST
        // с выдуманными AUTH_ID/REFRESH_ID записывал наши токены. Хуже того: если
        // в таком POST указать РЕАЛЬНЫЙ домен портала, настоящая установка потом
        // уже не проходит (сверка не сходится) — приложение оказывается намертво
        // занятым чужой записью, и лечится это только ручной чисткой data/.
        //
        // Доказательство простое: обмениваем присланный REFRESH_ID на токены НАШИМ
        // client_secret. Пройдёт только то, что портал действительно выдал нашему
        // приложению. Домен и member_id берём из ответа oauth, а не из POST.
        $firstInstallOk = $b24->verifyAndAdopt($post, true);
        if (!$firstInstallOk) {
            // ЗАПАСНОЙ ПУТЬ ДЛЯ КОРОБКИ — по РАЗОВОМУ БИЛЕТУ, а не по выключателю.
            //
            // Прежде здесь стоял `ALLOW_UNVERIFIED_FIRST_INSTALL = true`, и
            // включённый он принимал ЛЮБУЮ первую установку с ЛЮБОГО адреса.
            // Забыть вернуть его обратно достаточно один раз, чтобы приложение
            // занял посторонний (находка ревью 08.08.2026, BXREV-011).
            //
            // Билет отсекает нарушителя, а не всех подряд: он назван на конкретный
            // домен, живёт до указанного момента, приходит в теле установки и
            // сгорает после первого применения.
            $bilet = installUnverifiedTicketCheck($post);
            // Секрета в теле нет — портал его не присылает и прислать не может.
            // Тогда подлинность доказывает сам портал: спрашиваем ЕГО присланным
            // токеном, кто открыл установку. Ответит только действующий токен
            // этого портала, а домен взят из билета, то есть назван оператором,
            // а не телом запроса. Требуем администратора: installer-токен несёт
            // права открывшего, и токен рядового сотрудника дал бы приложению
            // урезанный доступ, который потом лечится только переустановкой.
            if ($bilet['ok'] && !empty($bilet['доказать_токеном'])) {
                $ctxUstanovki = resolveUserContextFromAuth(
                    (string)($post['AUTH_ID'] ?? $post['auth_id'] ?? ''), (string)$bilet['домен']);
                if (!is_array($ctxUstanovki) || empty($ctxUstanovki['isAdmin'])) {
                    appLog('error', 'install.unverified_ticket_rejected',
                        ['домен' => $bilet['домен'], 'портал_ответил' => is_array($ctxUstanovki)],
                        ['msg' => 'разовый билет: портал не подтвердил присланный токен администратора']);
                    $bilet['ok'] = false;
                    $bilet['причина'] = is_array($ctxUstanovki)
                        ? 'установку открыл не администратор портала'
                        : 'портал не признал присланный токен (или не ответил)';
                }
            }
            if ($bilet['ok']) {
                try {
                    $b24->saveTokensFromInstall($post, $referer, (string)$bilet['домен']);
                    installUnverifiedTicketBurn($bilet['отпечаток']);
                    $firstInstallOk = true;
                    appLog('warn', 'install.unverified_ticket', ['домен' => $bilet['домен']],
                        ['msg' => 'первая установка принята по разовому билету — подтверждения портала не было']);
                } catch (RuntimeException $e) {
                    // Человеку — тёмная страница с понятным текстом, разработчику —
                    // причина в журнале. Раньше здесь печатался голый текст ошибки
                    // на белом фоне: администратор портала видел техническую фразу
                    // без объяснения, что делать (находка ревью 09.08.2026).
                    logAppError('install: токены по разовому билету не сохранены: '
                        . (string)maskSecrets($e->getMessage()));
                    renderServicePage(
                        'Установка не завершена',
                        'Портал прислал данные установки, но приложение не смогло их сохранить.',
                        'Повторите установку из карточки приложения. Если повторится — скажите разработчику, '
                        . 'он найдёт причину в журнале приложения.',
                        ['code' => 400, 'tone' => 'warn']
                    );
                    exit;
                }
            } elseif ($bilet['причина'] !== '') {
                // Билет объявлен, но не подошёл. Молчать нельзя: человек ставит
                // приложение прямо сейчас и должен понять, что именно не так.
                appLog('error', 'install.unverified_ticket_rejected', ['причина' => $bilet['причина']],
                    ['msg' => 'разовый билет установки отклонён: ' . $bilet['причина']]);
            }
        }
        if (!$firstInstallOk) {
            // Самая частая причина отказа — не подделка, а незаполненные креды:
            // приложению нечем доказать порталу, что установка его. Общий текст
            // «установка не подтверждена» отправлял человека проверять права
            // администратора, хотя дело в двух пустых полях конфига.
            if ($cid === '' || $sec === '') {
                logAppError('install: попытка установки при незаполненных creds local-app');
                renderServicePage(
                    'Приложение ещё не настроено',
                    'Не заданы client_id и client_secret локального приложения, поэтому портал и приложение '
                    . 'не могут подтвердить друг друга.',
                    'Скажите разработчику: нужно записать креды из карточки приложения командой '
                    . 'scripts/set-creds.sh — установка после этого пройдёт с первого раза.',
                    ['code' => 503, 'tone' => 'warn']
                );
                exit;
            }
            // Вместе с доменом пишем СОСТАВ тела установки — имена полей и адреса
            // из них. Без этого отказ на коробке неразбираем: домен резолвится в
            // хост SERVER_ENDPOINT, и по одному этому имени не отличить «портал со
            // своим сервером авторизации» от «портал, который вообще не прислал
            // своего адреса». Имена полей и endpoint'ы секретами не являются
            // (токены лежат в ЗНАЧЕНИЯХ AUTH_ID/REFRESH_ID, а их мы не пишем), но
            // строку всё равно ведём через maskSecrets: состав тела задаёт портал,
            // и подставить туда имя, похожее на токен, может кто угодно.
            $installPostShape = implode(',', array_keys($post));
            foreach (['SERVER_ENDPOINT', 'CLIENT_ENDPOINT', 'server_endpoint', 'client_endpoint'] as $endpointField) {
                $endpointValue = (string)($post[$endpointField] ?? '');
                if ($endpointValue === '') continue;
                $endpointHost = parse_url($endpointValue, PHP_URL_HOST);
                $installPostShape .= '; ' . $endpointField . '=' . (is_string($endpointHost) ? $endpointHost : '?');
            }
            logAppError('install: первая установка не подтверждена порталом (домен из POST: ' . $postDomain
                . '; тело установки: ' . (string)maskSecrets($installPostShape) . ')');
            renderServicePage(
                'Установка не подтверждена порталом',
                'Портал не подтвердил, что эта установка принадлежит приложению. Так отсекаются попытки '
                . 'занять приложение со стороны — адрес обработчика публичный.',
                'Запустите установку из карточки приложения на портале под администратором. '
                . 'Если портал коробочный и работает со своим сервером авторизации — скажите разработчику: '
                . 'нужен разовый обходной режим.',
                ['code' => 403]
            );
            exit;
        }
        $installPostVerified = true;
        $installationAdoptedThisRequest = true;
        $recordOwnerAfterFence = true;
        $rememberUrlAfterFence = true;
    } elseif (($appChanged || $portalPosted) && !$strictMatch) {
        // Новая привязка: усваиваем токен только если verifyAndAdopt подтвердил владение (нашим
        // секретом) И открывший — админ. Иначе токен НЕ трогаем и показываем инструкцию.
        $installPostVerified = $b24->verifyAndAdopt($post);
        $installationAdoptedThisRequest = $installPostVerified;
        $appReinstallNeeded = !$installPostVerified;
    } elseif ($tokenAuthentic && $scopeRefreshOk) {
        // Save policy: здесь перезаписываем токены только при подтверждённой смене
        // scope от portal-admin. Переустановка ниже определяется app.info=false,
        // а не недокументированным INSTALL. isAccessExpired НЕ в условии — протухший
        // access чинится refresh'ем, а не перезаписью токеном случайного открывшего. KB: b24-local-app-tokens-save-only-on-install.
        try {
            $b24->saveTokensFromInstall($post, $referer);
        } catch (RuntimeException $e) {
            // Та же оправа, что и у ветки с разовым билетом: человеку — страница
            // приложения с понятным текстом, разработчику — причина в журнале.
            logAppError('install: токены не сохранены: ' . (string)maskSecrets($e->getMessage()));
            renderServicePage(
                'Установка не завершена',
                'Портал прислал данные установки, но приложение не смогло их сохранить.',
                'Повторите установку из карточки приложения. Если повторится — скажите разработчику, '
                . 'он найдёт причину в журнале приложения.',
                ['code' => 400, 'tone' => 'warn']
            );
            exit;
        }
    }

    // РАМКА ЭТОГО ЖЕ ОТВЕТА — от домена, который портал только что подтвердил.
    //
    // До этой строки заголовок собран из того, что лежало в сторе на входе, а на
    // первой установке там пусто: свежая КОРОБКА получала список облачных масок,
    // своего имени в нём не находила и блокировала собственную установку. Ответ
    // ещё не отправлен (ни одного вывода выше нет), поэтому заголовок просто
    // заменяется. Берём домен из сохранённых токенов, а не из тела POST'а:
    // неподтверждённое имя в рамку попасть не должно.
    if ($installPostVerified || $installationAdoptedThisRequest) {
        $confirmedDomain = (string)($b24->domain() ?? '');
        if ($confirmedDomain !== '' && strcasecmp($confirmedDomain, $frameAncestorsDomain) !== 0) {
            appSendFrameAncestors($confirmedDomain);
            $frameAncestorsDomain = $confirmedDomain;
        }
    }

    // Formal-флаг публичен. Если запрос не прошёл ни browser-fence, ни
    // криптопроверку установки, дальше (owner/app.info/placements) не идём.
    if (!$portalTransportFenced && !$installPostVerified && !$strictMatch) {
        // След обязателен: без него разбор «установка не проходит» упирается в
        // слово человека. Частота записи ограничена тем же способом, что и у
        // отказа опознания, — иначе поток мусорных POST'ов вымоет диагностику.
        require_once __DIR__ . '/api/http.php';
        if (apiRateAllow('install-deny-log', 10, 60)) {
            logAppError('install: POST не подтверждён ни забором портала, ни криптопроверкой');
        }
        renderServicePage(
            'Установка не подтверждена',
            'Запрос на установку пришёл не из портала: приложение не может проверить, что его '
            . 'действительно ставит администратор вашего Битрикс24.',
            'Откройте карточку приложения на портале под администратором и запустите установку оттуда.',
            ['code' => 403, 'tone' => 'warn']
        );
        exit;
    }

    // Backfill application_token для старой установки без него — включает строгую
    // сверку подлинности без ручной переустановки.
    //
    // ⚠ ТОЛЬКО когда подлинность подтверждена админом того же портала ('tofu').
    // Раньше дозапись стояла отдельным блоком вне всяких проверок, и это была
    // дыра в два шага: посторонний присылал свой APPLICATION_TOKEN (шаг 1 —
    // сохранялся), а следующим запросом проходил строгую сверку с ним же (шаг 2)
    // и уводил адрес портала на свой хост. Найдено ревью 29.07.2026, воспроизведено
    // на стенде. Общий канон: /data/standards/bx-app/kb/b24-oauth-install.md.
    if ($postAppToken !== '' && $savedAppToken === null && $installAuth['tofu']) {
        $b24->backfillApplicationToken($postAppToken, true);
    }

    // Сменилась привязка — сбрасываем портал/регистрацию-специфичный стейт. Два триггера:
    //   • member_id — переехали на ДРУГОЙ портал (или первая установка);
    //   • client_id — пересоздали local-app (та же площадка, новая регистрация) — #app-migration.
    $newMemberId    = $b24->memberId();
    $newClientId    = $b24->clientId();
    // ПЕРВАЯ установка переездом не считается: старого портала не было, чистить
    // нечего, а владелец только что записан — сброс ACL стёр бы его сразу после
    // записи, и установщик упёрся бы в «нет доступа».
    $portalMigrated = ($oldMemberId !== null && $oldMemberId !== ''
                       && $newMemberId !== '' && $newMemberId !== $oldMemberId);
    $appMigrated    = ($newClientId !== null && $newClientId !== '' && $oldClientId !== null
                        && $oldClientId !== '' && $newClientId !== $oldClientId);
    if ($portalMigrated || $appMigrated) {
        // verifyAndAdopt() очистил portal/registration-bound state ДО публикации
        // нового поколения. Поздняя очистка здесь могла бы стереть уже B/C.
        // Владелец же пишется после точного generation-fence ниже.
        $recordOwnerAfterFence = true;
    }

    // INSTALL=Y не входит в документированный browser-контракт и не может быть
    // детектором переустановки. Единственный надёжный сигнал существующей
    // привязки — app.info.INSTALLED=false, полученный живым токеном текущего
    // portal-admin. Только после него пробно refresh'им REFRESH_ID нашим секретом.
    // Опознание стоит похода в портал и до шести секунд ожидания, а войти в эту
    // ветку может кто угодно: транспортный забор проверяет только те поля, которые
    // прислал сам запрос (APP_SID, AUTH_ID, DOMAIN и Referer), а домен портала не
    // секрет. Десяток параллельных мусорных POST'ов занимал все PHP-воркеры и лил
    // неудачные user.current в портал клиента с нашего IP.
    //
    // Считать один лимит на адрес нельзя: у офиса общий внешний адрес, и тогда
    // тридцать открытий делят на всех — приложение закрывается всей компании
    // из-за коллеги. Поэтому лимитов два: личный (адрес + отпечаток токена
    // открывающего — у каждого сотрудника свой) и потолок на адрес заметно выше,
    // против потока с подставными токенами. Отпечаток — необратимый срез, сам
    // токен в счётчики не попадает.
    if ($portalTransportFenced || $installPostVerified || $strictMatch) {
        require_once __DIR__ . '/api/http.php';
        $identifyIp   = apiClientIp();
        $identifyWho  = substr(hash('sha256', $identifyIp . "\0" . $authId), 0, 16);
        // Цепочка проверяется и записывается атомарно: исчерпанный global ceiling
        // не плодит dynamic keys, а запрос, уже отбитый по адресу/личному bucket,
        // не выжигает общий резерв остальных сотрудников.
        $identifyOk = apiRateAllowChain([
            ['identify-all', 600],
            ['identify-ip:' . $identifyIp, 120],
            ['identify-who:' . $identifyWho, 30],
        ], 60);
        if (!$identifyOk) {
            $identifyRateFailure = (string)($GLOBALS['__api_rate_failure'] ?? '');
            if ($identifyRateFailure !== '') {
                // Fail-closed сохраняем, но не выдаём поломку private store за
                // минутный лимит: ожидание здесь не поможет, а ложный 429
                // превращал любой новый вход в бесконечный тупик. Сам limiter
                // нельзя использовать и для записи incident — при storage/capacity
                // failure это создало бы запись на каждый вход и усилило сбой.
                renderServicePage(
                    'Защитное хранилище недоступно',
                    'Приложение не может безопасно проверить частоту входа из-за состояния служебного хранилища.',
                    'Администратору сервера нужно проверить свободное место, квоту и права каталога data, затем открыть приложение заново.',
                    ['code' => 503, 'tone' => 'danger']
                );
                exit;
            }
            // Отказ входа обязан оставлять след: иначе разбирать жалобу «у меня
            // не открывается» нечем — ни строки ни в журнале, ни на экране.
            // Частота записи ограничена, чтобы поток не вымыл диагностику.
            if (apiRateAllow('identify-deny-log', 10, 60)) {
                logAppError('вход: опознание отклонено по частоте запросов с адреса '
                    . maskSecrets($identifyIp));
            }
            renderServicePage(
                'Слишком много попыток входа',
                'За последнюю минуту приложение уже опознавало вас слишком часто. Так бывает, если '
                . 'страница перезагружается по кругу или приложение открыто сразу в нескольких вкладках.',
                'Закройте лишние вкладки, подождите минуту и откройте приложение из меню портала заново.',
                ['code' => 429, 'tone' => 'warn']
            );
            exit;
        }
        $currentUserContext = resolveUserContextFromAuth($authId, (string)$b24->domain());
    }
    if (is_array($currentUserContext) && !empty($currentUserContext['isAdmin'])) {
        // Выход из состояния «installFinish ушёл, подтверждения нет». Раньше выхода
        // не было вообще: маркер поколения снимала только resetInstallFinish(), а её
        // никто не вызывал — ни эндпоинт, ни CLI, ни ветка контроллера. Приложение
        // залипало на экране 409 навсегда, и переустановка из карточки не помогала,
        // потому что ротация токена идёт через ту же заблокированную ветку.
        // Повтор делает ЧЕЛОВЕК — администратор портала кнопкой на том экране:
        // автоматический повтор установочного эффекта по-прежнему запрещён.
        // Разрешение одноразовое и хранится на сервере: страница завершения
        // установки перезагружает себя, а перезагрузка шлёт ТО ЖЕ тело POST
        // вместе с флагом — без отметки один клик человека превращался в три
        // десятка автоматических повторов установки подряд.
        // Запись install-меты идёт под барьером установки и умеет отказать: если
        // прямо сейчас поколение сменилось (человек переустанавливает приложение
        // из карточки) или барьер занят переносом. Оба исхода — штатные, и экраны
        // для них уже написаны выше. Без этого перехвата исключение уходило в
        // общий обработчик и человек видел «Внутренняя ошибка» вместо понятного
        // «установка изменилась, откройте заново».
        try {
        if (($post['bxapp_retry_install_finish'] ?? '') === '1'
            && $b24->installFinishRequestedForCurrentGeneration()
            && !$b24->installFinishConfirmedForCurrentGeneration()
            && !$b24->manualInstallRetryUsedForCurrentGeneration()) {
            $generationBeforeRetry = appInstallGenerationFrom(storeRead(B24_TOKENS_FILE) ?? []);
            $b24->resetInstallFinish();
            $b24->markManualInstallRetryUsed();
            logAppError('install: администратор портала вручную разрешил повтор installFinish'
                . ($generationBeforeRetry !== '' ? ' (поколение ' . substr($generationBeforeRetry, 0, 8) . ')' : ''));
        }
        // Это single-flight, а не долгий кеш: после реальной переустановки
        // app.info должен быть перечитан уже на следующем открытии, а не через
        // пять минут. Параллельный iframe при этом не дублирует запрос.
        $appInfoCooldown = 5;
        if ($b24->claimAppInfoCheck($appInfoCooldown)) {
            $appInfoStartedAt = microtime(true);
            $appInfo = b24PortalGet((string)$b24->domain(), 'app.info', $authId);
            $portalInstalledStatus = b24InstalledStatus($appInfo);
            // ТАРИФ ПОРТАЛА берём из этого же ответа: он уже получен, и лишний
            // запрос ради него был бы платой ни за что. От тарифа зависит темп
            // обращений (Энтерпрайз — 5 запросов в секунду, прочие — 2), и без
            // него первый же обход шёл бы по самому строгому пределу.
            if (is_array($appInfo['result'] ?? null)) {
                try {
                    $planIzUstanovki = planParseAppInfo($appInfo['result']);
                    $planIzUstanovki['at'] = time();
                    planPersistFreshest($planIzUstanovki, $appInfoStartedAt);
                } catch (Throwable $e) {
                    // Тариф — ускорение, а не условие установки: молча остаёмся
                    // на строгом пределе, а след пишем в журнал.
                    logAppError('install: тариф портала не сохранён: ' . maskSecrets($e->getMessage()));
                }
            }
            if ($portalInstalledStatus === true) {
                $b24->confirmInstallFinishedForCurrentGeneration();
            } elseif ($portalInstalledStatus === false && !$installationAdoptedThisRequest) {
                $finishRequested = $b24->installFinishRequestedForCurrentGeneration();
                $finishConfirmed = $b24->installFinishConfirmedForCurrentGeneration();
                if ($finishRequested && !$finishConfirmed) {
                    // Прошлый HTML уже вызвал installFinish, но подтверждение не
                    // пришло. Повторять мутацию или создавать новое поколение
                    // автоматически нельзя: результат первого вызова неизвестен.
                    $installFinishOutcomeUnknown = true;
                } elseif ($finishConfirmed && $b24->verifyAndAdopt($post)) {
                    $installationAdoptedThisRequest = true;
                    $installPostVerified = true;
                    $recordOwnerAfterFence = true;
                    $rememberUrlAfterFence = true;
                    logAppError('install: повторная установка подтверждена app.info + OAuth');
                } elseif ($finishConfirmed) {
                    $appReinstallNeeded = true;
                    logAppError('install: app.info видит переустановку, но OAuth-proof не прошёл');
                }
                // Ни requested, ни confirmed: это ещё не завершённое текущее
                // поколение. Не создаём новое — ниже готовим placements и
                // разрешаем ровно первый атомарный claimInstallFinish().
            }
        }
        } catch (InstallGenerationMismatchException $e) {
            $renderIndexInstallationFailure($e->phase);
        } catch (InstallLifecycleBusyException $e) {
            renderServicePage('Приложение обновляется',
                'Сейчас завершается установка или перенос приложения.',
                'Подождите несколько секунд и откройте приложение заново.', ['code' => 503, 'tone' => 'info']);
            exit;
        } catch (InstallCleanupIncompleteException $e) {
            // Прошлую установку удалили, а уборка после неё не доделана (файл не
            // стёрся, диск был занят). Ставить приложение поверх нельзя — новая
            // установка увидела бы остатки чужого портала как свои. Для этого
            // случая экран уже написан («Приложение убирает за собой», 503 с
            // Retry-After), а без перехвата исключение уходило в общий обработчик,
            // и администратор в момент переустановки видел «Внутреннюю ошибку» —
            // то есть повод звонить, хотя надо просто подождать минуту.
            logAppError('install: уборка прошлой установки не завершена — '
                . maskSecrets($e->getMessage()));
            $renderIndexInstallationFailure('pending');
        }
    }

    if ($installFinishOutcomeUnknown) {
        // Нажатие кнопки — это обычный self-POST, и он обязан пройти тот же
        // транспортный забор, что и открытие из портала: без APP_SID и точного
        // DOMAIN запрос до install-ветки просто не доходит и админ получает
        // «Приложение открывается только из портала» вместо повтора. Поэтому
        // возвращаем в форму те поля текущего портального POST, которые забор
        // проверяет. Секретов среди них нет (документированный состав POST —
        // api-reference/widgets/left-menu.md), а личность всё равно
        // подтверждается ответом портала на AUTH_ID.
        // Поля берём теми же помощниками, что и сам забор: коробка присылает не
        // APP_SID/DOMAIN, а APPLICATION_TOKEN/SERVER_ENDPOINT. Иначе на коробке
        // кнопка повтора не рисуется вовсе, и установка остаётся незавершённой.
        $retryFields = [
            'AUTH_ID'    => $authId,
            'APP_SID'    => portalPostAppSid($post),
            'DOMAIN'     => portalPostDomain($post),
            'bxapp_retry_install_finish' => '1',
        ];
        // Кнопку рисуем, только если нажатие действительно сработает: нет полей
        // забора — не дойдёт до обработчика; повтор в этом поколении уже
        // израсходован — обработчик его намеренно не выполнит. Мёртвая кнопка
        // хуже её отсутствия: человек жмёт, ничего не происходит, и он думает,
        // что сломано приложение, а не что повтор уже был.
        $retryUsed = $b24->manualInstallRetryUsedForCurrentGeneration();
        $retryPossible = !$retryUsed
            && $retryFields['APP_SID'] !== '' && $retryFields['DOMAIN'] !== '';
        renderServicePage(
            'Нужно проверить результат установки',
            'Приложение уже отправляло сигнал о завершении установки, но портал пока не подтвердил '
            . 'результат. Сам по себе повтор заблокирован намеренно: неизвестно, сработала первая '
            . 'попытка или нет, а выполнять установку дважды нельзя.',
            $retryPossible
                ? 'Проверьте карточку приложения на портале: если оно там числится установленным — просто '
                  . 'откройте приложение из меню заново. Если нет — нажмите кнопку ниже, и приложение '
                  . 'повторит завершение установки. Кнопка доступна только администратору портала.'
                : ($retryUsed
                    ? 'Повтор в этой установке уже запускался, но портал по-прежнему не подтвердил '
                      . 'результат. Откройте карточку приложения на портале (Приложения → Разработчикам) '
                      . 'и нажмите «Переустановить» — новая установка начнёт всё с чистого листа.'
                    : 'Проверьте карточку приложения на портале: если оно там числится установленным — просто '
                      . 'откройте приложение из меню заново. Если нет — откройте приложение из левого меню '
                      . 'портала под администратором: на этом экране появится кнопка повтора.'),
            ['code' => 409, 'tone' => 'warn'] + ($retryPossible ? ['form' => [
                'label'  => 'Повторить завершение установки',
                'fields' => $retryFields,
            ]] : [])
        );
        exit;
    }

    // Переезд либо app.info=false подтверждены, но свежую установку усвоить не
    // удалось: старые токены не трогаем и показываем точное действие.
    if ($appReinstallNeeded) {
        renderServicePage(
            'Нужно переустановить приложение',
            'Портал сообщает о новой или незавершённой установке, но приложение не смогло криптографически подтвердить свежие токены. '
            . 'Старую привязку оно намеренно не перезаписывает.',
            'Откройте карточку приложения под администратором (Приложения → Разработчикам → это приложение) '
            . 'и нажмите «Переустановить»: новая привязка подхватится сама, старые данные подчистятся. '
            . 'Если только что переустановили — просто откройте приложение из меню портала заново.',
            ['code' => 409, 'tone' => 'warn']
        );
        exit;
    }

    $indexInstallGeneration = $holdIndexInstallation($b24);
    // ВТОРАЯ ПОПЫТКА — та самая, которую обещает текст на экране.
    //
    // Владелец писался ровно один раз: в момент установки. Сбой портала в эту
    // секунду (таймаут, 503) оставлял приложение вообще без владельца, а человек
    // читал «откройте под администратором, владелец запишется автоматически» —
    // обещание, которого в коде не было: следующее открытие флаг уже не ставило,
    // и приложение навсегда оставалось доступным только администраторам портала.
    //
    // Поэтому фиксируем и на обычном открытии, если владельца всё ещё нет. Это
    // безопасно: aclSetOwner() пишет ТОГО, кого портал подтвердил своим ответом на
    // токен самого зрителя, а забор ниже пускает сюда только администратора.
    if (!$recordOwnerAfterFence && aclOwnerUid() === 0) $recordOwnerAfterFence = true;
    if ($recordOwnerAfterFence) $ownerNotice = ownerNotFixedNotice(recordAppOwner($b24));
    if ($rememberUrlAfterFence) rememberAppUrl();

    // Сначала обязательная подготовка. По официальному контракту installFinish
    // можно вызвать только ПОСЛЕ успешного bind/read-back всех объявленных
    // плейсментов. На обычном открытии их временная ошибка не закрывает UI, но
    // формальную установку с незавершённой подготовкой успешно не объявляем.
    $placementsReady = true;
    $placementResult = ['changed' => false, 'bound' => [], 'errors' => []];
    // Причину сбоя показываем прямо на экране. Раньше её видел только тот, кто
    // дошёл до журнала, — а запирало это как раз администратора, единственного,
    // кому журнал доступен: он упирался в 503 «повторите установку» и не знал,
    // что именно повторять.
    $placementFailureText = '';
    if ($b24->hasTokens()) {
        require_once __DIR__ . '/api/placements.php';
        $placementResult = bindPlacements($b24);
        $placementsReady = ($placementResult['errors'] ?? []) === [];

        // Подписка на события портала — там же, где вкладки: портал снимает
        // обработчики при обновлении приложения, поэтому ставить их надо заново
        // на каждой установке (это прямо сказано в документации event.bind).
        // Отказ подписки установку НЕ останавливает: приложение без событий
        // работает и просто не реагирует на изменения, а неустановившееся не
        // работает вовсе. Что подписка не встала — видно на экране «Состояние».
        require_once __DIR__ . '/api/events-bind.php';
        bindEvents($b24);
        if (!$placementsReady) {
            // Внутренний код портала человеку ничего не говорит: 'http_error'
            // на экране выглядит поломкой приложения, хотя означает оборванную
            // связь и лечится повтором через минуту. Известные коды переводим,
            // остальные показываем как есть — но подписанными.
            $codeText = static function (string $code): string {
                switch ($code) {
                    case 'http_error':
                    case 'network_error':   return 'связь с порталом прервалась';
                    case 'ACCESS_DENIED':
                    case 'insufficient_scope': return 'у приложения не хватает прав в портале';
                    case 'METHOD_NOT_FOUND': return 'портал не знает такой метод (проверьте версию Битрикс24)';
                    default: return $code !== '' ? 'код портала ' . $code : '';
                }
            };
            $failures = [];
            foreach ((array)($placementResult['errors'] ?? []) as $code => $message) {
                $human = is_string($code) ? $codeText($code) : '';
                $failures[] = $human !== ''
                    ? $human . ' — ' . (string)maskSecrets((string)$message)
                    : (string)maskSecrets((string)$message);
                if (count($failures) >= 5) break;
            }
            $placementFailureText = $failures === []
                ? ''
                : 'Портал отказал по встройкам: ' . implode('; ', $failures) . '.';
        }
    }
    if (!$placementsReady && ($portalInstalledStatus === false || $installationAdoptedThisRequest)) {
        renderServicePage(
            'Установка пока не завершена',
            'Портал не подтвердил все объявленные встройки приложения, поэтому приложение не сообщает '
            . 'о завершении установки. ' . $placementFailureText,
            'Чаще всего дело в правах: если в списке причина «нет доступа к placement», добавьте этот '
            . 'скоуп в карточку приложения на портале и нажмите «Переустановить». Полная причина есть '
            . 'в журнале приложения.',
            ['code' => 503, 'tone' => 'warn']
        );
        exit;
    }

    // Единственный installFinish — только когда app.info этого же browser-admin
    // явно вернул false и все обязательные модули уже подтвердили подготовку.
    if ($placementsReady && $portalInstalledStatus === false
        && is_array($currentUserContext) && !empty($currentUserContext['isAdmin'])
        && !$b24->installFinishHandledForCurrentGeneration()) {
        // Отметка идёт через ту же запись install-меты под барьером: переустановка
        // из соседней вкладки и занятый барьер обязаны давать понятный экран, а не
        // «Внутреннюю ошибку». Обработчики те же, что выше.
        try {
            if ($b24->claimInstallFinishForCurrentGeneration()) $needInstallFinish = true;
        } catch (InstallGenerationMismatchException $e) {
            $renderIndexInstallationFailure($e->phase);
        } catch (InstallLifecycleBusyException $e) {
            renderServicePage('Приложение обновляется',
                'Сейчас завершается установка или перенос приложения.',
                'Подождите несколько секунд и откройте приложение заново.', ['code' => 503, 'tone' => 'info']);
            exit;
        } catch (InstallCleanupIncompleteException $e) {
            // Прошлую установку удалили, а уборка после неё не доделана (файл не
            // стёрся, диск был занят). Ставить приложение поверх нельзя — новая
            // установка увидела бы остатки чужого портала как свои. Для этого
            // случая экран уже написан («Приложение убирает за собой», 503 с
            // Retry-After), а без перехвата исключение уходило в общий обработчик,
            // и администратор в момент переустановки видел «Внутреннюю ошибку» —
            // то есть повод звонить, хотя надо просто подождать минуту.
            logAppError('install: уборка прошлой установки не завершена — '
                . maskSecrets($e->getMessage()));
            $renderIndexInstallationFailure('pending');
        }
    }
    }
}

// GET/reload тоже проходит session/ACL под тем же поколением до конца рендера.
if ($indexInstallGeneration === null) $indexInstallGeneration = $holdIndexInstallation();

// ── 2. installFinish-страница ────────────────────────────────────────────
if ($needInstallFinish) {
    renderInstallFinishPage($ownerNotice ?? '');
    exit;
}

// ── 3. Session gate ──────────────────────────────────────────────────────
$session = tryCreateSessionFromB24Post() ?? findSessionFromCookie() ?? previewSession();

if (!$session) {
    $reason = sessionDenialReason();
    // Раньше эта ветка молчала: человек говорил «не пускает», а в приложении не
    // оставалось следа — ни причины, ни времени, ни кто пытался войти.
    //
    // Но пишем не каждый отказ: адрес приложения публичен, и поисковый робот или
    // сканер портов раздул бы журнал строкой на запрос. Записываем только
    // похожее на живого человека — пришёл из портала, принёс токен или cookie —
    // и отдельно редкие «прочие», не чаще раза в минуту.
    require_once __DIR__ . '/api/journal.php';
    $looksHuman = $authId !== '' || !empty($_COOKIE[SESSION_COOKIE])
        || ($reason !== null && $reason !== 'no_app_sid');
    $throttleFile = DATA_ROOT . '/state.php';
    $mayLogRare = true;
    if (!$looksHuman) {
        $st = [];
        try { $st = storeRead($throttleFile) ?? []; } catch (Throwable $e) { $st = []; }
        $mayLogRare = (time() - (int)($st['anon_deny_logged_at'] ?? 0)) > 60;
        if ($mayLogRare) {
            try {
                storeUpdate($throttleFile, static function (array $s): array {
                    $s['anon_deny_logged_at'] = time(); return $s;
                });
            } catch (Throwable $e) { /* не смогли отметить — не беда */ }
        }
    }
    // Признак «за отказом живой человек» подделывается одним полем AUTH_ID, а
    // адрес обработчика публичен. Поэтому потолок частоты стоит и здесь, поверх
    // редкого «анонимного» троттла: иначе поток отказов вымывал из дневного
    // журнала настоящую диагностику и аудит — ровно то, ради чего журнал и ведём.
    require_once __DIR__ . '/api/http.php';
    if (($looksHuman || $mayLogRare) && apiRateAllow('index-deny-log', 20, 60)) {
        appLog($reason === 'portal_unreachable' ? 'error' : 'warn', 'access.deny',
            ['причина' => $reason ?? 'прямое открытие', 'источник' => 'страница приложения',
             'аноним' => !$looksHuman], ['src' => 'web']);
    }
    if ($reason === 'no_access') {
        renderServicePage(
            'Нет доступа к приложению',
            'Приложение открыто только сотрудникам из списка доступа и тому, кто его установил. '
            . 'Вас в списке пока нет.',
            'Попросите администратора приложения добавить вас: он открывает приложение и на вкладке «Доступ» находит вас по фамилии.',
            ['code' => 403, 'tone' => 'warn']
        );
    } elseif ($reason === 'portal_unreachable') {
        // Портал не ответил на опознание. Это временный сбой, а не отказ в правах:
        // раньше человек видел «нет доступа» и шёл просить админа — совет, который
        // ничего не чинит. Отдаём 503: это «попробуйте позже», а не «вам нельзя».
        renderServicePage(
            'Портал сейчас не отвечает',
            'Не удалось спросить у Битрикс24, кто вы. Обычно это разовый сбой связи, и права тут ни при чём.',
            'Подождите несколько секунд и обновите страницу. Если повторяется дольше пары минут — скажите разработчику.',
            ['code' => 503, 'tone' => 'info']
        );
    } elseif ($reason === 'not_admin') {
        renderServicePage(
            'Приложение ещё настраивается',
            'Пока приложение не установлено до конца, его открывают только администраторы портала.',
            'Попросите администратора портала открыть приложение один раз — после этого доступ появится у всех, кто есть в списке.',
            ['code' => 403, 'tone' => 'warn']
        );
    } else {
        renderServicePage(
            'Приложение открывается только из портала',
            'Эта страница работает внутри Битрикс24 и сама по себе, по прямой ссылке, ничего не показывает.',
            'Откройте приложение из меню портала Битрикс24.',
            ['code' => 403]
        );
    }
    exit;
}

// ── 4. Render app ────────────────────────────────────────────────────────
// Разметка — из template.php выпуска (за гардом 404) либо из template.html исходников.
$html = appTemplateHtml(__DIR__);

// Cache-bust: ?v=<mtime> к local css/js.
$html = preg_replace_callback(
    // Ловим и голую ссылку, и ссылку с плейсхолдером ?v=__V__ — он стоит в
    // разметке, чтобы было видно: метка версии здесь есть и ставится кодом.
    '~(href|src)="((?:css|js)/[^"?#]+)(?:\?v=__V__)?"~',
    function ($m) {
        $p = __DIR__ . '/' . $m[2];
        $v = is_file($p) ? filemtime($p) : '';
        return $m[1] . '="' . $m[2] . ($v ? '?v=' . $v : '') . '"';
    },
    $html
);

// Роль нужна фронту, чтобы не показывать служебные вкладки тому, кому они не
// положены. Это удобство, а не защита: решает всё равно сервер на каждом
// запросе — фронт лишь не рисует кнопку, которая всё равно ответит отказом.
$inject = '<script>'
        . 'window.APP_SESSION = ' . json_encode($session['token']) . ';'
        . 'window.APP_ROLE = ' . json_encode((string)($session['role'] ?? 'view')) . ';'
        . '</script>';
echo str_replace('</head>', $inject . '</head>', $html);
