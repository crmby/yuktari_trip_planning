/*
 * ЖИВОЙ ПРОГОН УСТАНОВКИ — самый дорогой сценарий приложения.
 *
 * Установка ломается тише всего: у владельца всё встало, а сотрудник видит
 * «приложение не установлено»; или наоборот — установка «прошла», а токенов на
 * диске нет и приложение мертво со следующего открытия. Разбором кода это не
 * ловится: тут сходятся портальный POST, обмен refresh на токены, фиксация
 * владельца и гейт доступа.
 *
 * Что настоящее: код приложения целиком, PHP-сервер, браузер, запись состояния.
 * Что поддельное: ровно два внешних адреса — сервер авторизации Битрикса и
 * короткий запрос «кто открыл приложение». Их подменяет обвязка; подмена
 * возможна только при уведённом сторе, на живом приложении её нет.
 *
 *   node tests/install-live.js
 */
const { runLive } = require('./live-harness');

// Портальный POST: браузер человека отправляет форму из iframe портала.
// Отправляем именно формой со страницы, а не fetch: так делает портал, и так
// проверяется весь путь целиком, включая заголовок источника.
// Портальный POST: так приложение открывает сам Битрикс — навигацией с формой
// из iframe портала. Заголовок источника подставляем перехватом запроса: на
// живом портале его ставит браузер, а тут другого способа нет — и именно по
// нему забор входа отличает открытие из портала от чужого POST.
async function portalPost(page, base, fields, referer, portalBase) {
  // Так открывает приложение сам портал: переход СО СТРАНИЦЫ ПОРТАЛА, формой,
  // методом POST. Именно отсюда берутся заголовок источника и признак «переход
  // с чужого сайта» — подставить их из сценария нельзя (браузер ставит их сам),
  // а вход приложения проверяет оба. Поэтому страница портала настоящая: её
  // отдаёт отдельный сервер на своём порту.
  await page.goto(portalBase + '/portal.html', { waitUntil: 'load' });
  const navigated = page.waitForNavigation({ waitUntil: 'load', timeout: 20000 });
  await page.evaluate(([url, data]) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    Object.keys(data).forEach((k) => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = data[k];
      form.appendChild(i);
    });
    document.body.appendChild(form);
    // Отправляем следующим тиком: иначе вызов уходит вместе со страницей и
    // сценарий получает обрыв контекста вместо результата.
    setTimeout(() => form.submit(), 0);
  }, [base + '/index.php', fields]);
  await navigated;
}

runLive({ fresh: true, portal: true }, async (t) => {
  const { page, base, check, dataRoot } = t;
  const fs = require('fs');
  const path = require('path');

  // ── 1. Прямое открытие: приложение не должно падать и не должно пускать ──
  const direct = await page.goto(base + '/index.php', { waitUntil: 'load' });
  const directText = await page.textContent('body');
  check('прямое открытие даёт понятный экран, а не ошибку сервера',
    direct.status() !== 500 && !/Fatal error|Warning:/i.test(directText),
    'код ' + direct.status());
  check('человеку сказано, что открывать надо из портала',
    /портал/i.test(directText), directText.slice(0, 120));

  // ── 2. Портал ставит приложение ──────────────────────────────────────────
  // Referer подставляет портал; страницу открываем с адреса приложения, чтобы
  // форма ушла тем же путём, каким её шлёт iframe.
  await portalPost(page, base, {
    AUTH_ID: 'installer-auth', REFRESH_ID: 'installer-refresh',
    // Имя портала в POST намеренно ЧУЖОЕ: поле подделываемо, и приложение
    // обязано взять имя из ответа сервера авторизации. Совпади они — проверка
    // ниже была бы зелёной и на коде, который верит присланному полю.
    DOMAIN: 'podmena.invalid', APP_SID: 'sid-install', PROTOCOL: '1', LANG: 'ru',
    member_id: 'MEMBER-LIVE', PLACEMENT: 'DEFAULT',
    // Ключ приложения портал присылает вместе с установкой; дальше вход
    // сверяет именно его — доказательство сильнее заголовка источника.
    APPLICATION_TOKEN: 'live-app-token',
  }, 'https://portal.invalid/marketplace/', t.portalBase);
  const installText = await page.textContent('body');
  check('установка не заканчивается ошибкой на экране',
    !/Fatal error|Warning:|не подтверждена/i.test(installText), installText.slice(0, 140));

  // Состояние на диске — главное доказательство: экран может выглядеть
  // прилично и при том, что записать токены не удалось.
  const tokensFile = path.join(dataRoot, 'b24-tokens.php');
  const tokens = fs.existsSync(tokensFile) ? fs.readFileSync(tokensFile, 'utf8') : '';
  check('токены портала записаны на диск', /"member_id":\s*"MEMBER-LIVE"/.test(tokens),
    'в data/: ' + fs.readdirSync(dataRoot).join(', '));
  check('домен взят из ответа сервера авторизации, а не из присланного POST',
    /"domain":\s*"portal\.invalid"/.test(tokens) && !/podmena\.invalid/.test(tokens),
    (tokens.match(/"domain":\s*"[^"]*"/) || ['—'])[0]);
  check('файл токенов закрыт от прямого запроса',
    tokens.startsWith('<?php http_response_code(404); exit;'));

  // ── 3. Владелец открывает приложение снова ───────────────────────────────
  // Ошибки консоли считаем с этого места: до установки приложение законно
  // отказывает («ещё не установлено»), и эти отказы — не поломка фронта.
  t.errors.length = 0;
  await portalPost(page, base, {
    AUTH_ID: 'installer-auth', DOMAIN: 'portal.invalid', APP_SID: 'sid-work',
    PLACEMENT: 'DEFAULT', member_id: 'MEMBER-LIVE', APPLICATION_TOKEN: 'live-app-token',
  }, 'https://portal.invalid/company/', t.portalBase);
  const owner = await page.evaluate(() => ({
    session: typeof window.APP_SESSION === 'string' && window.APP_SESSION.length > 20,
    role: window.APP_ROLE || null,
    nav: document.querySelectorAll('.nav-item').length,
    text: document.body.textContent.slice(0, 160),
  }));
  check('владелец получает рабочий экран с пропуском', owner.session, owner.text);
  check('владельцу выдана роль администратора', owner.role === 'admin', String(owner.role));
  check('на экране есть разделы приложения', owner.nav > 0, 'разделов: ' + owner.nav);

  // Владелец зафиксирован в списке доступа — иначе после переустановки
  // приложение осталось бы без хозяина.
  const acl = fs.existsSync(path.join(dataRoot, 'acl.php'))
    ? fs.readFileSync(path.join(dataRoot, 'acl.php'), 'utf8') : '';
  check('установщик записан владельцем приложения', /"owner_uid":\s*"?10"?/.test(acl),
    acl ? acl.replace(/\s+/g, ' ').slice(-200) : 'файла списка доступа нет');

  // ── 4. Сотрудник без доступа ─────────────────────────────────────────────
  // Он ОБЯЗАН увидеть заглушку «нет доступа», а не «приложение не установлено»:
  // второе отправляет человека переустанавливать чужое рабочее приложение.
  //
  // Отдельное окно браузера обязательно: в прежнем осталась cookie владельца, и
  // приложение узнало бы по ней ЕГО. Проверка была бы зелёной на любом коде.
  const strangerCtx = await t.browser.newContext({ viewport: { width: 1280, height: 900 } });
  const strangerPage = await strangerCtx.newPage();
  await portalPost(strangerPage, base, {
    AUTH_ID: 'employee-auth', DOMAIN: 'portal.invalid', APP_SID: 'sid-employee',
    PLACEMENT: 'DEFAULT', member_id: 'MEMBER-LIVE', APPLICATION_TOKEN: 'live-app-token',
  }, 'https://portal.invalid/company/', t.portalBase);
  const stranger = await strangerPage.evaluate(() => ({
    session: typeof window.APP_SESSION === 'string' && window.APP_SESSION.length > 20,
    text: document.body.textContent.replace(/\s+/g, ' ').trim().slice(0, 200),
  }));
  check('сотруднику без доступа пропуск не выдаётся', !stranger.session, stranger.text);
  check('сотрудник видит «нет доступа», а не «приложение не установлено»',
    !/не установлен/i.test(stranger.text) && /доступ/i.test(stranger.text), stranger.text);
  await strangerCtx.close();

  // ── 5. Портал молчит ─────────────────────────────────────────────────────
  // Самая обидная подмена смысла: связь с порталом пропала, а человек читает
  // «нет доступа» и идёт просить права, которые у него есть. Экран обязан
  // сказать «портал не отвечает, попробуйте позже».
  fs.writeFileSync(path.join(dataRoot, 'portal-down'), '');
  const downCtx = await t.browser.newContext({ viewport: { width: 1280, height: 900 } });
  const downPage = await downCtx.newPage();
  await portalPost(downPage, base, {
    AUTH_ID: 'installer-auth', DOMAIN: 'portal.invalid', APP_SID: 'sid-down',
    PLACEMENT: 'DEFAULT', member_id: 'MEMBER-LIVE', APPLICATION_TOKEN: 'live-app-token',
  }, 'https://portal.invalid/company/', t.portalBase);
  const down = await downPage.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
  check('молчание портала не выдаётся за отказ в доступе',
    /не отвеча/i.test(down) && !/Нет доступа/i.test(down), down.slice(0, 160));
  check('человеку сказано, что делать при молчании портала',
    /обнов|подожд|позже/i.test(down), down.slice(0, 200));
  await downCtx.close();
  fs.unlinkSync(path.join(dataRoot, 'portal-down'));

  check('в консоли браузера нет ошибок', t.errors.length === 0, t.errors.slice(0, 2).join(' | '));
});
