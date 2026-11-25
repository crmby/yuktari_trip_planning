/* ЖИВОЙ ПРОГОН ОБЯЗАТЕЛЬНОГО МОДУЛЯ ПРАВ: поиск → фото → выдача → отзыв. */
const { runLive } = require('./live-harness');

runLive({ portal: true }, async (t) => {
  const { page, base, check, TOKEN } = t;
  await page.route('https://portal.invalid/avatar-*.png', async (route) => {
    if (route.request().url().includes('avatar-broken')) {
      await route.fulfill({ status: 200, contentType: 'image/png', body: 'not-an-image' });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'image/png',
      body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') });
  });

  async function selectedAvatar() {
    return page.$eval('#user-picker .chip-tag', (chip) => {
      const image = chip.querySelector('img.chip-avatar');
      const fallback = chip.querySelector('span.chip-avatar');
      return {
        images: chip.querySelectorAll('img.chip-avatar').length,
        fallback: fallback ? fallback.textContent.trim() : '',
        src: image ? image.src : '',
        loaded: image ? image.complete && image.naturalWidth > 0 : false,
      };
    });
  }

  const posts = [];
  page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().includes('/api/')) posts.push(r.url());
  });
  await page.goto(base + '/template.html', { waitUntil: 'load' });
  await page.click('.nav-item[data-tab="access"]');
  await page.waitForFunction(() => /Владелец/.test(
    document.querySelector('#acl-table tbody').textContent), null, { timeout: 7000 });
  check('обязательный экран прав открывается и показывает владельца',
    await page.$eval('#acl-table tbody', (n) => /владелец/i.test(n.textContent)));
  check('открытие экрана прав само ничего не меняет', posts.length === 0,
    posts.join(' · '));

  await page.fill('#user-picker input', 'пет');
  await page.waitForSelector('#user-picker .picker-opt img.picker-avatar', { timeout: 7000 });
  check('поиск сотрудника на экране прав загрузил фото Bitrix',
    await page.$eval('#user-picker .picker-opt img.picker-avatar', (img) =>
      img.complete && img.naturalWidth > 0 && /avatar-petrov/.test(img.src)));
  await page.click('#user-picker .picker-opt');
  const validAvatar = await selectedAvatar();
  check('валидное фото в выбранном сотруднике показано один раз без дубля-инициала',
    validAvatar.images === 1 && validAvatar.loaded
      && /avatar-petrov/.test(validAvatar.src) && validAvatar.fallback === '',
    JSON.stringify(validAvatar));
  await page.click('#user-picker .chip-tag .chip-x');

  await page.fill('#user-picker input', 'без');
  await page.waitForFunction(() => /Безфото Анна/.test(
    document.querySelector('#user-picker .picker-list').textContent), null, { timeout: 7000 });
  await page.click('#user-picker .picker-opt');
  const noPhotoAvatar = await selectedAvatar();
  check('сотрудник без фото в выбранном чипе показывает fallback-инициал',
    noPhotoAvatar.images === 0 && noPhotoAvatar.fallback === 'Б',
    JSON.stringify(noPhotoAvatar));
  await page.click('#user-picker .chip-tag .chip-x');

  await page.fill('#user-picker input', 'бит');
  await page.waitForFunction(() => /Битый Борис/.test(
    document.querySelector('#user-picker .picker-list').textContent), null, { timeout: 7000 });
  await page.click('#user-picker .picker-opt');
  await page.waitForTimeout(250);
  const brokenPhotoAvatar = await selectedAvatar();
  check('битое фото в выбранном чипе заменяется fallback-инициалом',
    brokenPhotoAvatar.images === 0 && brokenPhotoAvatar.fallback === 'Б',
    JSON.stringify(brokenPhotoAvatar));
  await page.click('#user-picker .chip-tag .chip-x');

  await page.fill('#user-picker input', 'пет');
  await page.waitForFunction(() => /Петров Пётр/.test(
    document.querySelector('#user-picker .picker-list').textContent), null, { timeout: 7000 });
  await page.click('#user-picker .picker-opt');
  check('после выбора сотрудника выдача прав доступна',
    !(await page.$eval('#btn-acl-add', (b) => b.disabled)));
  await page.click('#btn-acl-add');
  await page.waitForFunction(() => /Петров Пётр/.test(
    document.querySelector('#acl-table tbody').textContent), null, { timeout: 7000 });
  check('выдача права дошла до backend и появилась в таблице',
    await page.$eval('#acl-table tbody', (n) => /Петров Пётр/.test(n.textContent)));

  const acl = await page.evaluate(async (token) => {
    const r = await fetch('api/acl.php', { headers: { 'X-App-Token': token } });
    return r.json();
  }, TOKEN);
  const petrov = (acl.acl.users || []).find((u) => Number(u.uid) === 77);
  check('ACL сохранил роль и фото выбранного сотрудника',
    petrov && petrov.role === 'view' && /avatar-petrov/.test(petrov.photo || ''),
    JSON.stringify(petrov || null));

  await page.click('#acl-table [data-del="77"]');
  await page.waitForSelector('.modal .btn-danger');
  await page.click('.modal .btn-danger');
  await page.waitForFunction(() => !/Петров Пётр/.test(
    document.querySelector('#acl-table tbody').textContent), null, { timeout: 7000 });
  check('отзыв права удаляет сотрудника, владелец остаётся',
    await page.$eval('#acl-table tbody', (n) =>
      !/Петров Пётр/.test(n.textContent) && /Владелец/.test(n.textContent)));
  check('мутации произошли только после двух явных действий', posts.length === 2,
    'POST: ' + posts.length);
  check('в консоли браузера нет ошибок', t.errors.length === 0,
    t.errors.slice(0, 3).join(' | '));
  await page.screenshot({ path: '/tmp/bx-app-access.png', fullPage: true });
  console.log('\nснимок: /tmp/bx-app-access.png');
});
