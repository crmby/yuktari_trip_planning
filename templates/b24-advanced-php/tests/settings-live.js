/*
 * ЖИВОЙ ПРОГОН ЭКРАНА НАСТРОЕК.
 *
 * Проверка «класс есть в разметке» проходит и на сломанной форме. Здесь всё
 * настоящее: PHP-сервер приложения, пропуск в приватном store, браузер. Ответ
 * приходит от настоящего api/settings.php — значит проверяются и описание полей,
 * и отказ по полю, и правило «пустое поле не затирает сохранённое».
 *
 *   node tests/settings-live.js
 *
 * В tests/run.php намеренно НЕ включён: браузер есть не на каждой машине, а
 * самопроверка обязана оставаться переносимой.
 */
const { runLive } = require('./live-harness');

// patchSchema: в копию подставляется описание из всех шести типов — образец в
// шаблоне намеренно короткий, а проверять надо каждый тип поля.
runLive({ patchSchema: true }, async (t) => {
  const { page, base, check, TOKEN } = t;

  // Служебный блок проверяем на том же живом экране, но внешний портал здесь
  // заменяет детерминированный стенд на уровне HTTP. Важен именно браузерный
  // путь: настоящий ui.api(), реальные клики, confirmBox, POST и следующий GET
  // read-back. Подмена PHP/DOM-функций скрыла бы ошибки привязки обработчиков.
  const healthyService = () => ({
    placements: {
      'объявлено': ['CRM_DEAL_DETAIL_TAB'],
      'привязано': ['CRM_DEAL_DETAIL_TAB'],
      'расхождение': [],
      'проверено': true,
    },
    events: {
      'объявлено': 1,
      'подключено': 1,
      'не_подключены': [],
      'проверено': true,
      'уборка_при_удалении': true,
    },
    stuck_ops: [],
  });
  let serviceState = healthyService();
  const serviceTraffic = { health: 0, bind: 0, ops: 0, bindMethods: [], opsMethods: [], keys: [] };
  await page.route('**/api/health.php', async (route) => {
    serviceTraffic.health++;
    await route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ ok: true, ...serviceState }) });
  });
  await page.route('**/api/bind.php', async (route) => {
    serviceTraffic.bind++;
    serviceTraffic.bindMethods.push(route.request().method());
    serviceState = healthyService();
    await route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ ok: true, bound: { CRM_DEAL_DETAIL_TAB: true },
        errors: {}, events: { bound: ['ONCRMDEALUPDATE'], errors: {} } }) });
  });
  await page.route('**/api/ops.php', async (route) => {
    serviceTraffic.ops++;
    serviceTraffic.opsMethods.push(route.request().method());
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch (e) { /* проверка ниже */ }
    serviceTraffic.keys.push(body.key || '');
    serviceState = { ...healthyService(), stuck_ops: [] };
    await route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ ok: true, key: body.key || '' }) });
  });

  // ОТКРЫТИЕ ЭКРАНА НИЧЕГО НЕ МЕНЯЕТ НА СЕРВЕРЕ. Живая находка 25.08.2026:
  // `ui.action(кнопка, работа)` ЗАПУСКАЕТ работу, а не подписывает её на клик, и
  // вызов при отрисовке отправлял запросы сам — настройка затиралась пустым
  // значением, а на соседнем экране списывался одноразовый код. По коду это
  // выглядит как обычная привязка действия, поэтому ловим по факту: считаем
  // изменяющие запросы, пока человек ничего не нажимал.
  const menyayushie = [];
  page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().includes('/api/')) menyayushie.push(r.url());
  });

  await page.goto(base + '/template.html', { waitUntil: 'load' });
  await page.click('.nav-item[data-tab="settings"]');
  await page.waitForSelector('#settings-form .field', { timeout: 5000 });
  await page.waitForTimeout(400);
  check('открытие экранов ничего не меняет на сервере без нажатия',
    menyayushie.length === 0, menyayushie.join(' · '));

  async function reopenSettings() {
    await page.click('.nav-item[data-tab="work"]');
    await page.click('.nav-item[data-tab="settings"]');
    await page.waitForFunction(() => {
      const box = document.getElementById('service-box');
      return box && !box.classList.contains('state') && box.textContent.trim() !== '';
    });
  }

  // RED R28-06: «не удалось проверить» — третье состояние, а не синоним
  // «всё на месте». До регрессии loadService игнорировал оба `проверено=false`
  // и рисовал зелёную фразу на неизвестном результате.
  serviceState = {
    placements: {
      'объявлено': ['CRM_DEAL_DETAIL_TAB'], 'привязано': [],
      'расхождение': [], 'проверено': false,
    },
    events: {
      'объявлено': 1, 'подключено': 0, 'не_подключены': [],
      'проверено': false, 'уборка_при_удалении': null,
    },
    stuck_ops: [],
  };
  await reopenSettings();
  const unknownService = await page.textContent('#service-box');
  check('неизвестные placements/events не выдаются за исправные',
    /не удалось подтвердить|неизвест/i.test(unknownService || '')
      && !/на месте/i.test(unknownService || ''), unknownService);

  // Один и тот же код placement может существовать, но вести на старый handler.
  // Одновременно проверяем missing event и потерянную служебную уборку.
  serviceState = {
    placements: {
      'объявлено': ['CRM_DEAL_DETAIL_TAB'],
      'привязано': ['CRM_DEAL_DETAIL_TAB'],
      'расхождение': ['CRM_DEAL_DETAIL_TAB'], 'проверено': true,
    },
    events: {
      'объявлено': 1, 'подключено': 0,
      'не_подключены': ['ONCRMDEALUPDATE'],
      'проверено': true, 'уборка_при_удалении': false,
    },
    stuck_ops: [],
  };
  await reopenSettings();
  const brokenService = await page.textContent('#service-box');
  check('handler mismatch, missing event и cleanup названы человеку',
    /адрес/i.test(brokenService || '')
      && /ONCRMDEALUPDATE/.test(brokenService || '')
      && /удален/i.test(brokenService || '')
      && !/на месте/i.test(brokenService || ''), brokenService);
  await page.screenshot({ path: '/tmp/bx-app-settings-service-broken.png', fullPage: true });
  check('просмотр неизвестного и сломанного состояния не отправляет POST',
    serviceTraffic.bind === 0 && serviceTraffic.ops === 0 && menyayushie.length === 0,
    JSON.stringify(serviceTraffic));

  // Мутация — только после настоящего клика. Успехом считается не ответ POST,
  // а следующий GET, который перечитал уже здоровое состояние.
  const readsBeforeBind = serviceTraffic.health;
  await page.click('#service-box button:has-text("Пересобрать привязки")');
  await page.waitForFunction(() => /на месте/i.test(
    (document.getElementById('service-box') || {}).textContent || ''));
  check('клик пересборки делает один POST и обязательный GET read-back',
    serviceTraffic.bind === 1 && serviceTraffic.bindMethods[0] === 'POST'
      && serviceTraffic.health > readsBeforeBind,
    JSON.stringify(serviceTraffic));

  // Снятие unknown-операции проходит через собственное подтверждение. После
  // POST строка обязана исчезнуть только по read-back, а не оптимистично.
  serviceState = { ...healthyService(), stuck_ops: ['probe-op'] };
  await reopenSettings();
  const readsBeforeOps = serviceTraffic.health;
  await page.click('#service-box button:has-text("Снять")');
  await page.click('.modal [data-yes]');
  await page.waitForFunction(() => !/probe-op/.test(
    (document.getElementById('service-box') || {}).textContent || ''));
  check('подтверждённый клик снимает exact operation и перечитывает состояние',
    serviceTraffic.ops === 1 && serviceTraffic.opsMethods[0] === 'POST'
      && serviceTraffic.keys[0] === 'probe-op'
      && serviceTraffic.health > readsBeforeOps,
    JSON.stringify(serviceTraffic));

  // R31-TEST-01: приложение вправе не иметь placements, но объявлять события.
  // Такая подписка всё ещё требует ручного восстановления — поэтому кнопка
  // обязана жить по числу событий, а не только по числу пунктов меню.
  serviceState = {
    placements: {
      'объявлено': [], 'привязано': [], 'расхождение': [], 'проверено': true,
    },
    events: {
      'объявлено': 1, 'подключено': 0,
      'не_подключены': ['ONCRMDEALUPDATE'],
      'проверено': true, 'уборка_при_удалении': true,
    },
    stuck_ops: [],
  };
  await reopenSettings();
  const eventOnlyButton = await page.$('#service-box button:has-text("Пересобрать привязки")');
  const eventOnlyVisible = !!eventOnlyButton && await eventOnlyButton.isVisible();
  check('приложение только с событиями оставляет кнопку пересборки видимой',
    eventOnlyVisible);

  const readsBeforeEventOnlyBind = serviceTraffic.health;
  const bindsBeforeEventOnlyBind = serviceTraffic.bind;
  if (eventOnlyVisible) {
    await eventOnlyButton.click();
    await page.waitForFunction(() => /на месте/i.test(
      (document.getElementById('service-box') || {}).textContent || ''));
  }
  check('клик event-only пересборки делает POST и следующий GET read-back',
    eventOnlyVisible
      && serviceTraffic.bind === bindsBeforeEventOnlyBind + 1
      && serviceTraffic.bindMethods[serviceTraffic.bindMethods.length - 1] === 'POST'
      && serviceTraffic.health > readsBeforeEventOnlyBind,
    JSON.stringify(serviceTraffic));

  // Обратная грань: у полностью пустого приложения служебная cleanup-подписка
  // уже здорова, восстанавливать нечего — лишняя кнопка не должна появляться.
  serviceState = {
    placements: {
      'объявлено': [], 'привязано': [], 'расхождение': [], 'проверено': true,
    },
    events: {
      'объявлено': 0, 'подключено': 0, 'не_подключены': [],
      'проверено': true, 'уборка_при_удалении': true,
    },
    stuck_ops: [],
  };
  const bindsBeforeEmptyApp = serviceTraffic.bind;
  await reopenSettings();
  const emptyAppButton = await page.$('#service-box button:has-text("Пересобрать привязки")');
  check('пустое приложение со здоровой cleanup-подпиской не показывает пересборку',
    emptyAppButton === null && serviceTraffic.bind === bindsBeforeEmptyApp,
    JSON.stringify(serviceTraffic));

  const fields = await page.$$eval('#settings-form .field', (els) => els.map((e) => e.dataset.key));
  check('форма собралась из описания полей', fields.length === 6, 'полей: ' + fields.join(', '));

  const native = await page.$$eval('#settings-form', (els) =>
    els[0].querySelectorAll('select, input[type=number], input[type=date]').length);
  check('в форме нет системных контролов', native === 0, 'найдено ' + native);

  const own = await page.$$eval('#settings-form', (els) => ({
    number: els[0].querySelectorAll('.ui-number').length,
    sw: els[0].querySelectorAll('.ui-switch').length,
    sel: els[0].querySelectorAll('.ui-select').length,
    area: els[0].querySelectorAll('textarea.input').length,
  }));
  check('каждому типу поля достался свой контрол',
    own.number === 1 && own.sw === 1 && own.sel === 1 && own.area === 1, JSON.stringify(own));

  // КЛИК ПО ПОДПИСИ НАСТРОЙКИ НИЧЕГО НЕ МЕНЯЕТ.
  //
  // Строка настройки обязана быть div, а не <label>: <label> без for отдаёт
  // клик ПЕРВОМУ элементу внутри себя. Клик по подписи «Интервал опроса, минут»
  // нажимал кнопку «−» степпера и молча уменьшал значение, а клик по «Слать
  // уведомления» переворачивал переключатель — человек просто читал форму
  // мышкой и менял настройки, не понимая этого (находка ревью 09.08.2026).
  const poPodpisi = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const stroka = (k) => document.querySelector('#settings-form .field[data-key="' + k + '"]');
    const chislo = () => stroka('interval').querySelector('.ui-number input').value;
    const perekl = () => stroka('notify').querySelector('.ui-switch').getAttribute('aria-checked');
    const tegi = ['interval', 'notify'].map((k) => stroka(k).tagName);
    const do_ = { chislo: chislo(), perekl: perekl() };
    stroka('interval').querySelector('span').click();
    stroka('notify').querySelector('span').click();
    await wait(250);
    return { tegi, do: do_, posle: { chislo: chislo(), perekl: perekl() } };
  });
  check('клик по подписи настройки не меняет ни число, ни переключатель',
    poPodpisi.tegi.every((t) => t === 'DIV')
    && poPodpisi.do.chislo === poPodpisi.posle.chislo
    && poPodpisi.do.perekl === poPodpisi.posle.perekl,
    JSON.stringify(poPodpisi));

  // ── Секрет наружу не приходит ─────────────────────────────────────────
  const secret = await page.evaluate(async (token) => {
    const r = await fetch('api/settings.php', { headers: { 'X-App-Token': token } });
    const d = await r.json();
    const s = (d.fields || []).filter((f) => f.type === 'secret');
    return { count: s.length, withValue: s.filter((f) => 'value' in f).length };
  }, TOKEN);
  check('значение секрета не приходит на страницу',
    secret.count === 1 && secret.withValue === 0, JSON.stringify(secret));

  // ── Отказ показывается У СВОЕГО поля ──────────────────────────────────
  const refusal = await page.evaluate(async (token) => {
    // Контрол честно зажимает значение в границы, поэтому негодное шлём прямым
    // запросом: проверяем СЕРВЕР.
    const r = await fetch('api/settings.php', {
      method: 'POST', headers: { 'X-App-Token': token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ settings: { interval: 999999 } }),
    });
    return { status: r.status, body: await r.json() };
  }, TOKEN);
  check('сервер отказывает по конкретному полю',
    refusal.status === 400 && refusal.body.fields && refusal.body.fields.interval,
    JSON.stringify(refusal).slice(0, 160));

  // Тот же отказ, но через ЭКРАН: подсветка поля обязана появиться. Именно эта
  // ветка молча не работала, пока транспорт терял полезную нагрузку отказа.
  await page.evaluate(() => {
    var fake = new Error('плохо');
    fake.code = 'bad_field';
    fake.data = { fields: { interval: 'Значение больше допустимого' } };
    var real = window.ui.api;
    window.ui.api = function (m, p) {
      if (m === 'POST' && p === 'api/settings.php') { window.ui.api = real; return Promise.reject(fake); }
      return real.apply(null, arguments);
    };
    document.getElementById('settings-save').click();
  });
  await page.waitForTimeout(500);
  const shownErr = await page.textContent('.field[data-key="interval"] .field-err');
  check('отказ сервера показан у своего поля, а не одной строкой',
    (shownErr || '').includes('Значение больше допустимого'), shownErr);

  // ── Сохранение работает и ничего не затирает ──────────────────────────
  // ЗАПОЛНЯЕМ ФОРМУ РУКАМИ, а не через fetch. Прежний прогон просто жал «Сохранить»
  // на нетронутой форме и проверял, что она перерисовалась и появился тост, —
  // а это было бы зелёным и на форме, которая не отправляет НИ ОДНОГО значения:
  // ровно тот класс поломки, ради которого живой прогон и заведён.
  const typedGreeting = 'Здравствуйте, коллеги ' + '№7';
  const typedNote = 'Заметка из живого прогона';
  await page.fill('.field[data-key="greeting"] input', typedGreeting);
  await page.fill('.field[data-key="note"] textarea', typedNote);
  await page.fill('.field[data-key="interval"] input', '45');
  // Переключатель и выпадающий список — свои контролы, кликаем как человек.
  await page.click('.field[data-key="notify"] .ui-switch');
  await page.click('.field[data-key="strictness"] .ui-select-btn');
  await page.waitForTimeout(150);
  await page.click('.ui-select-opt:has-text("Строгий")');

  await page.click('#settings-save');
  await page.waitForTimeout(700);
  check('после сохранения форма перерисована, а не стёрта',
    (await page.$$('#settings-form .field')).length === 6);
  check('исход сохранения показан тостом', !!(await page.$('#toasts .toast')));

  // Главное: сервер получил то, что человек набрал на экране. Читаем не форму, а
  // сохранённое состояние — форма могла бы показывать введённое и никуда его не слать.
  const saved = await page.evaluate(async (token) => {
    const r = await fetch('api/settings.php', { headers: { 'X-App-Token': token } });
    return (await r.json()).settings;
  }, TOKEN);
  check('введённое в форму дошло до сервера ровно тем, что набрали',
    saved.greeting === typedGreeting && saved.note === typedNote
    && Number(saved.interval) === 45 && saved.notify === false && saved.strictness === 'hard',
    JSON.stringify(saved));


  // R08-UI-01: ответ старого POST не вправе стереть новый ввод.
  let releaseSave, saveReached;
  const delayedSave = new Promise((resolve) => { saveReached = resolve; });
  const releaseResponse = new Promise((resolve) => { releaseSave = resolve; });
  await page.route('**/api/settings.php', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    saveReached();
    await releaseResponse;
    await route.fulfill({ response });
  });
  await page.fill('.field[data-key="greeting"] input', 'Снимок при отправке');
  await page.click('#settings-save');
  await delayedSave;
  await page.fill('.field[data-key="greeting"] input', 'Новый несохранённый текст');
  await page.fill('.field[data-key="note"] textarea', 'Поздняя заметка');
  await page.fill('.field[data-key="interval"] input', '46');
  await page.click('.field[data-key="notify"] .ui-switch');
  await page.click('.field[data-key="strictness"] .ui-select-btn');
  await page.click('.ui-select-opt:has-text("Бережный")');
  releaseSave();
  await page.waitForFunction(() => !document.getElementById('settings-save').disabled);
  await page.unroute('**/api/settings.php');
  check('R08-UI-01 поздний ввод всех обычных типов сохраняется после ответа POST',
    await page.inputValue('.field[data-key="greeting"] input') === 'Новый несохранённый текст'
      && await page.inputValue('.field[data-key="note"] textarea') === 'Поздняя заметка'
      && await page.inputValue('.field[data-key="interval"] input') === '46'
      && await page.getAttribute('.field[data-key="notify"] .ui-switch', 'aria-checked') === 'true'
      && /Бережный/.test(await page.textContent('.field[data-key="strictness"] .ui-select-btn')));
  check('R08-UI-01 несохранённый остаток явно показан',
    /несохранённые/i.test(await page.textContent('#settings-note')));
  await page.click('#settings-save');
  await page.waitForFunction(() => !document.getElementById('settings-save').disabled);
  const lateSaved = await page.evaluate(async (token) => {
    const r = await fetch('api/settings.php', { headers: { 'X-App-Token': token } });
    return (await r.json()).settings;
  }, TOKEN);
  check('R08-UI-01 следующий явный клик сохраняет поздний ввод на сервере',
    lateSaved.greeting === 'Новый несохранённый текст' && lateSaved.note === 'Поздняя заметка'
      && Number(lateSaved.interval) === 46 && lateSaved.notify === true && lateSaved.strictness === 'soft');


  // Каждый callback проверяется отдельно: общий ввод маскирует пропущенный тип.
  const lateCases = [
    ['number', () => page.fill('.field[data-key="interval"] input', '43'),
      () => page.inputValue('.field[data-key="interval"] input')],
    ['bool', () => page.click('.field[data-key="notify"] .ui-switch'),
      () => page.getAttribute('.field[data-key="notify"] .ui-switch', 'aria-checked')],
    ['select', async () => {
      const current = await page.textContent('.field[data-key="strictness"] .ui-select-btn');
      await page.click('.field[data-key="strictness"] .ui-select-btn');
      await page.click('.ui-select-opt:has-text("' + (/Строгий/.test(current) ? 'Бережный' : 'Строгий') + '")');
    }, () => page.textContent('.field[data-key="strictness"] .ui-select-btn')],
    ['secret', () => page.fill('.field[data-key="api_key"] input', 'synthetic-late-secret'),
      () => page.inputValue('.field[data-key="api_key"] input')],
    ['clear', () => page.click('.field[data-key="greeting"] button:has-text("Очистить")'),
      async () => JSON.stringify([await page.isDisabled('.field[data-key="greeting"] input'),
        await page.textContent('.field[data-key="greeting"] button')])],
  ];
  for (const [name, edit, read] of lateCases) {
    let arrived, release;
    const ready = new Promise((resolve) => { arrived = resolve; });
    const hold = new Promise((resolve) => { release = resolve; });
    await page.route('**/api/settings.php', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch();
      arrived();
      await hold;
      await route.fulfill({ response });
    });
    await page.click('#settings-save');
    await ready;
    await edit();
    const expected = await read();
    release();
    await page.waitForFunction(() => !document.getElementById('settings-save').disabled);
    check('R08-UI-01 изолированный поздний ' + name + ' не теряется', await read() === expected);
    check('R08-UI-01 изолированный поздний ' + name + ' помечен несохранённым',
      /несохранённые/.test(await page.textContent('#settings-note')));
    await page.unroute('**/api/settings.php');
    await page.click('#settings-save');
    await page.waitForFunction(() => !document.getElementById('settings-save').disabled);
    check('R08-UI-01 повторное сохранение ' + name + ' очищает отметку',
      !/несохранённые/.test(await page.textContent('#settings-note')));
  }

  const before = await page.evaluate(async (token) => {
    const r = await fetch('api/settings.php', { headers: { 'X-App-Token': token } });
    return (await r.json()).settings;
  }, TOKEN);
  const after = await page.evaluate(async (token) => {
    await fetch('api/settings.php', {
      method: 'POST', headers: { 'X-App-Token': token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ settings: {} }),
    });
    const r = await fetch('api/settings.php', { headers: { 'X-App-Token': token } });
    return (await r.json()).settings;
  }, TOKEN);
  check('сохранение без изменений ничего не затирает',
    JSON.stringify(before) === JSON.stringify(after));

  // СПРЯТАННОЕ ОБЯЗАНО БЫТЬ СПРЯТАННЫМ. Браузерное [hidden]{display:none} слабее
  // любого правила компонента: .btn{display:inline-flex} перебивал его молча, и
  // кнопка «Пересобрать», которую код честно помечал hidden, оставалась на экране
  // (то же было с пилюлей связи .conn). Проверяем не «правило есть в файле», а то,
  // что видит браузер, — и на тех самых классах, где это ломалось.
  const hiddenDisplays = await page.evaluate(() => {
    const out = {};
    for (const cls of ['btn', 'conn', 'card']) {
      const el = document.createElement('div');
      el.className = cls;
      el.hidden = true;
      document.body.appendChild(el);
      out[cls] = getComputedStyle(el).display;
      el.remove();
    }
    return out;
  });
  check('элемент, помеченный hidden, не виден ни в одном компоненте',
    Object.values(hiddenDisplays).every((d) => d === 'none'), JSON.stringify(hiddenDisplays));

  // Две ошибки ожидаемы и к экрану отношения не имеют: SDK Битрикса не
  // инициализируется вне портала, а 400 — ответ на НАШ же негодный запрос.
  const unexpected = t.errors.filter((m) =>
    !/Bitrix24 JS library/i.test(m) && !/status of 400/i.test(m));
  check('в консоли браузера нет посторонних ошибок', unexpected.length === 0,
    unexpected.slice(0, 2).join(' | '));

  await page.screenshot({ path: '/tmp/bx-app-settings.png', fullPage: true });
  console.log('\nснимок: /tmp/bx-app-settings.png');
});
