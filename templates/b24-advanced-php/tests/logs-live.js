/*
 * ЖИВОЙ ПРОГОН ЖУРНАЛА С CURSOR-ПАГИНАЦИЕЙ.
 *
 * Настоящие PHP journal/logs.php, настоящий template.html и браузер. Проверяем
 * наблюдаемый контракт: в таблице не бывает полотна больше 25 строк, страницы
 * листаются в обе стороны, новая запись между кликами не повторяет старые, а
 * смена каждого фильтра начинает независимую выборку с первой страницы.
 *
 *   node tests/logs-live.js
 */
const path = require('path');
const { spawnSync } = require('child_process');
const { runLive } = require('./live-harness');

runLive({}, async (t) => {
  const { page, base, check, TOKEN, appRoot, dataRoot } = t;
  const phpEnv = {
    ...process.env,
    BXAPP_DATA_ROOT: dataRoot,
    BXAPP_CLIENT_ID: 'local.test.app',
    BXAPP_CLIENT_SECRET: 'test-secret',
    BXAPP_OAUTH_URL: 'http://127.0.0.1:9/oauth/token/',
  };
  const journal = path.join(appRoot, 'www/api/journal.php');

  function writeRows(from, to, suffix, level, moveToDay) {
    const code = `
      $GLOBALS['__app_log_install_generation'] = 'live-generation-1';
      require ${JSON.stringify(journal)};
      for ($i = ${from}; $i <= ${to}; $i++) {
        appLog(${JSON.stringify(level || 'info')}, 'live.page.' . $i . ${JSON.stringify(suffix || '')}, [],
          ['msg' => 'Строка живого журнала ' . $i]);
      }
      ${moveToDay ? `
      $source = journalFile();
      $target = journalFile(${JSON.stringify(moveToDay)});
      if (!@rename($source, $target)) { fwrite(STDERR, 'MOVE_FAILED'); exit(7); }
      ` : ''}
      echo 'WROTE';
    `;
    return spawnSync('php', ['-r', code], { env: phpEnv, encoding: 'utf8' });
  }

  const pastDay = spawnSync('php', ['-r', 'echo date("Y-m-d", time() - 86400);'],
    { env: phpEnv, encoding: 'utf8' }).stdout.trim();
  const pastRows = writeRows(301, 330, '.past-day', 'error', pastDay);
  const seeded = writeRows(1, 56, '', 'info');
  const warningRows = writeRows(101, 130, '.warn-hit', 'warn');
  const queryRows = writeRows(201, 207, '.query-hit', 'info');
  const prepared = [pastRows, seeded, warningRows, queryRows];
  check('подготовлены страницы и независимые наборы для трёх фильтров',
    /^\d{4}-\d{2}-\d{2}$/.test(pastDay) && prepared.every((r) =>
      r.status === 0 && String(r.stdout).includes('WROTE')),
    prepared.map((r) => r.stderr || r.stdout).join(' | '));

  const mutations = [];
  const logRequests = [];
  page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().includes('/api/')) mutations.push(r.url());
    if (r.method() === 'GET' && r.url().includes('/api/logs.php')) logRequests.push(r.url());
  });

  await page.goto(base + '/template.html', { waitUntil: 'load' });
  await page.click('.nav-item[data-tab="log"]');
  await page.waitForFunction(() =>
    document.querySelectorAll('#log-table tbody tr').length === 25
      && !document.getElementById('log-next').disabled,
    null, { timeout: 7000 });

  const pageOne = await page.$$eval('#log-table tbody tr', (rows) =>
    rows.map((r) => r.textContent.trim()));
  check('первая страница ограничена 25 строками', pageOne.length === 25,
    'строк: ' + pageOne.length);
  check('открытие журнала не отправляет изменяющих запросов', mutations.length === 0,
    mutations.join(' · '));

  // Запись приходит ПОСЛЕ открытия страницы. Offset здесь повторил бы строку
  // с границы; cursor обязан продолжить от конкретного rid.
  const appended = writeRows(57, 57, '.after-open');
  check('между страницами пришла новая запись',
    appended.status === 0 && String(appended.stdout).includes('WROTE'),
    appended.stderr || appended.stdout);

  await page.click('#log-next');
  await page.waitForFunction(() =>
    document.getElementById('log-page-status').textContent.includes('Страница 2')
      && document.querySelectorAll('#log-table tbody tr').length === 25,
    null, { timeout: 7000 });
  const pageTwo = await page.$$eval('#log-table tbody tr', (rows) =>
    rows.map((r) => r.textContent.trim()));
  check('вторая страница тоже ограничена 25 строками', pageTwo.length === 25,
    'строк: ' + pageTwo.length);
  check('после новой записи соседние страницы не пересекаются',
    pageOne.every((row) => !pageTwo.includes(row)));
  check('на второй странице доступны обе стороны пагинации',
    await page.$eval('#log-pager', (p) => {
      const prev = p.querySelector('#log-prev');
      const next = p.querySelector('#log-next');
      return !prev.disabled && !next.disabled;
    }));

  await page.click('#log-prev');
  await page.waitForFunction(() =>
    document.getElementById('log-page-status').textContent.includes('Страница 1')
      && document.querySelectorAll('#log-table tbody tr').length <= 25,
    null, { timeout: 7000 });
  check('кнопка «Назад» возвращает на первую страницу',
    await page.$eval('#log-page-status', (n) => n.textContent.includes('Страница 1')));
  async function openSecondPage() {
    await page.reload({ waitUntil: 'load' });
    await page.click('.nav-item[data-tab=log]');
    await page.waitForFunction(() =>
      document.querySelectorAll('#log-table tbody tr').length === 25
        && !document.getElementById('log-next').disabled,
      null, { timeout: 7000 });
    await page.click('#log-next');
    await page.waitForFunction(() =>
      document.getElementById('log-page-status').textContent.includes('Страница 2')
        && document.querySelectorAll('#log-table tbody tr').length === 25,
      null, { timeout: 7000 });
    const rows = await page.$$eval('#log-table tbody tr', (nodes) =>
      nodes.map((n) => n.textContent.trim()));
    const request = new URL(logRequests[logRequests.length - 1]);
    return { rows, cursor: request.searchParams.get('cursor') || '' };
  }

  async function checkFilterReset(label, marker, changeFilter) {
    const before = await openSecondPage();
    const requestStart = logRequests.length;
    await changeFilter();
    await page.waitForFunction((expected) => {
      const rows = Array.from(document.querySelectorAll('#log-table tbody tr'));
      return document.getElementById('log-page-status').textContent.includes('Страница 1')
        && document.getElementById('log-prev').disabled
        && rows.length > 0 && rows.every((r) => r.textContent.includes(expected));
    }, marker, { timeout: 3000 }).catch(() => {});
    const after = await page.$$eval('#log-table tbody tr', (nodes) =>
      nodes.map((n) => n.textContent.trim()));
    const filterRequests = logRequests.slice(requestStart).map((url) => new URL(url));
    const cursors = filterRequests.map((url) => url.searchParams.get('cursor')).filter(Boolean);
    check(label + ': старая позиция cursor не отправлена',
      Boolean(before.cursor) && filterRequests.length > 0 && cursors.length === 0,
      JSON.stringify({ old_cursor: before.cursor, sent_cursors: cursors }));
    check(label + ': показана страница 1 и «Назад» выключена',
      await page.$eval('#log-pager', (p) =>
        p.querySelector('#log-page-status').textContent.includes('Страница 1')
          && p.querySelector('#log-prev').disabled));
    check(label + ': строки второй страницы заменены новой выборкой',
      JSON.stringify(before.rows) !== JSON.stringify(after)
        && after.length > 0 && after.every((row) => row.includes(marker)),
      JSON.stringify({ before: before.rows.slice(0, 2), after: after.slice(0, 2) }));
  }

  await checkFilterReset('Поиск', '.query-hit', async () => {
    await page.fill('#log-q input', '.query-hit');
    await page.press('#log-q input', 'Enter');
  });
  await checkFilterReset('Уровень', '.warn-hit', async () => {
    await page.click('#log-level .ui-select-btn');
    await page.locator('#log-level .ui-select-opt')
      .filter({ hasText: 'Предупреждения и ошибки' }).click();
  });
  await checkFilterReset('День', '.past-day', async () => {
    await page.click('#log-day .ui-select-btn');
    await page.locator('#log-day .ui-select-opt')
      .filter({ hasText: pastDay }).click();
  });

  const serverPage = await page.evaluate(async (token) => {
    const r = await fetch('api/logs.php?level=debug', {
      headers: { 'X-App-Token': token },
    });
    return r.json();
  }, TOKEN);
  check('сервер сам ограничивает страницу, UI не режет полотно постфактум',
    serverPage.ok === true && serverPage.page_size === 25
      && serverPage.rows.length === 25 && Boolean(serverPage.next_cursor),
    JSON.stringify({ ok: serverPage.ok, page_size: serverPage.page_size,
      rows: serverPage.rows && serverPage.rows.length }));

  check('в консоли браузера нет ошибок', t.errors.length === 0,
    t.errors.slice(0, 3).join(' | '));
  await page.screenshot({ path: '/tmp/bx-app-logs.png', fullPage: true });
  console.log('\nснимок: /tmp/bx-app-logs.png');
});
