/*
 * ЖИВОЙ ПРОГОН ДОЛГОЙ ЗАДАЧИ.
 *
 * Смысл блока — «закрыл окно, задача продолжается». Проверить это можно только
 * так, как делает человек: запустить с экрана, ЗАКРЫТЬ страницу, дать поработать
 * фоновому рабочему, открыть заново и увидеть результат, а не «запустите снова».
 * Плюс отмена: она обязана срабатывать между порциями, а не обрывать работу.
 *
 * Порядок шагов выбран не случайно. Заглушка порции в шаблоне короткая, и один
 * тик рабочего успевает всю задачу целиком — поэтому отмена проверяется ДО
 * первого тика, иначе отменять было бы уже нечего.
 *
 *   node tests/task-live.js
 */
const { runLive } = require('./live-harness');

runLive({}, async (t) => {
  const { page, base, check, worker } = t;

  await page.goto(base + '/template.html', { waitUntil: 'load' });
  await page.waitForSelector('#task-start', { timeout: 5000 });
  check('без запусков видно, что задача не запускалась',
    (await page.textContent('#task-box')).includes('не запускалась'));

  // ── Запуск и отмена между порциями ──────────────────────────────────────
  await page.click('#task-start');
  await page.waitForSelector('#task-cancel', { timeout: 5000 });
  // Подпись зависит от того, есть ли кому считать: при живом планировщике это
  // «Выполняется», при молчащем — честное «Ждём фоновую обработку» (полоса на
  // нуле без объяснения читается как поломка). Проверяем сам факт хода, а не
  // конкретное слово.
  const hodZadachi = await page.textContent('#task-box');
  check('после запуска экран показывает ход и кнопку отмены',
    hodZadachi.includes('Выполняется') || hodZadachi.includes('Ждём фоновую обработку'),
    hodZadachi.slice(0, 120));

  // Планировщик в свежем приложении ещё ни разу не отмечался — считать задачу
  // некому. Экран обязан сказать это СРАЗУ: иначе «Задача запущена, можно
  // закрыть окно» пять минут выглядит правдой, а потом превращается в «нет
  // признаков жизни», и человек успевает уйти.
  const warnedAtStart = await page.textContent('#task-box');
  check('при молчащем планировщике экран сразу предупреждает, а не обещает успех',
    warnedAtStart.includes('Фоновая обработка ни разу не запускалась'),
    warnedAtStart.slice(0, 160));

  // Поздний GET, начатый до cancel, не должен затереть ответ POST. Удерживаем
  // реальный ответ очередного poll до тех пор, пока cancel уже принят сервером.
  let releaseStale;
  let staleStartedResolve;
  const staleStarted = new Promise((resolve) => { staleStartedResolve = resolve; });
  let holdOneGet = true;
  await page.route('**/api/task.php', async (route) => {
    if (holdOneGet && route.request().method() === 'GET') {
      holdOneGet = false;
      const response = await route.fetch();
      staleStartedResolve();
      await new Promise((resolve) => { releaseStale = resolve; });
      await route.fulfill({ response });
      return;
    }
    await route.continue();
  });
  await Promise.race([staleStarted, page.waitForTimeout(4500).then(() => { throw new Error('poll GET не начался'); })]);
  await page.click('#task-cancel');
  await page.waitForTimeout(400);
  check('до следующей порции экран честно пишет, что останавливаемся',
    (await page.textContent('#task-box')).includes('останавливаем после текущей порции'));
  releaseStale();
  await page.waitForTimeout(350);
  check('поздний GET до команды не откатывает принятый cancel',
    (await page.textContent('#task-box')).includes('останавливаем после текущей порции'));
  await page.unroute('**/api/task.php');

  worker();
  await page.waitForTimeout(2600);          // экран сам опрашивает раз в 2 секунды
  const cancelled = await page.textContent('#task-box');
  check('рабочий увидел просьбу и остановился, экран это показал',
    cancelled.includes('Остановлено'), cancelled.slice(0, 120));
  // Рабочий отработал — предупреждение обязано УЙТИ само. Предупреждение,
  // которое висит после того, как причина исчезла, обесценивает все остальные.
  check('после первого же тика рабочего предупреждение исчезает',
    !cancelled.includes('Фоновая обработка ни разу не запускалась'),
    cancelled.slice(0, 160));
  await page.waitForSelector('#task-start', { timeout: 5000 });
  check('после остановки задачу можно запустить заново', true);

  await page.click('#task-forget');
  await page.waitForTimeout(500);
  check('завершённую задачу можно убрать с экрана',
    (await page.textContent('#task-box')).includes('не запускалась'));

  // ── Закрытое окно ничего не обрывает ────────────────────────────────────
  await page.click('#task-start');
  await page.waitForSelector('#task-cancel', { timeout: 5000 });
  const started = await page.$eval('.progress-fill', (e) => e.style.width);

  await page.goto('about:blank');           // человек закрыл приложение
  const tick = worker();                     // работа идёт дальше без окна
  check('фоновый рабочий отработал без открытого окна', tick.status === 0,
    'код ' + tick.status + ' ' + String(tick.stderr || '').slice(0, 120));

  await page.goto(base + '/template.html', { waitUntil: 'load' });
  await page.waitForSelector('#task-box .progress', { timeout: 5000 });
  const afterReopen = await page.textContent('#task-box');
  const width = await page.$eval('.progress-fill', (e) => e.style.width);
  check('открытое заново окно показывает результат работы, а не «запустите снова»',
    afterReopen.includes('Готово') && afterReopen.includes('Обработано'),
    afterReopen.slice(0, 140));
  check('полоса дошла до конца, пока окно было закрыто',
    parseInt(width) > parseInt(started) && parseInt(width) === 100, started + ' → ' + width);

  // ── ВИДИМЫЙ ОТКЛИК КНОПОК ЗАДАЧИ ────────────────────────────────────────
  //
  // «Запустить», «Отменить», «Убрать» меняют состояние на сервере, и между
  // нажатием и ответом портала проходит секунда-другая. Всё это время кнопка
  // обязана выглядеть занятой: иначе человек жмёт второй раз, а приложение
  // молча глотает нажатие — с его стороны это «ничего не происходит».
  // Задержку ответа задаём сами: на быстром стенде портал отвечает мгновенно,
  // и этот сценарий не наступает НИКОГДА — ровно поэтому его и не замечали.
  await page.route('**/api/task.php', async (route) => {
    await new Promise((r) => setTimeout(r, 700));
    await route.continue();
  });
  let komand = 0;
  page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().includes('api/task.php')) komand++;
  });

  await page.click('#task-forget');
  await page.waitForTimeout(220);
  const otklik = await page.evaluate(() => {
    const b = document.getElementById('task-forget');
    return b ? { est: true, vykl: b.disabled, kruzhok: !!b.querySelector('.spinner-inline') }
             : { est: false };
  });
  check('нажатая кнопка задачи сразу выключается и показывает кружок',
    otklik.est && otklik.vykl && otklik.kruzhok, JSON.stringify(otklik));

  // Второе нажатие в обход disabled: так дублируют операцию повторные события и
  // скрипты, а не человек мышью.
  await page.evaluate(() => {
    const b = document.getElementById('task-forget');
    if (b && b.onclick) b.onclick({ currentTarget: b });
  });
  await page.waitForTimeout(1500);
  check('второе нажатие не отправляет порталу вторую команду', komand === 1, 'команд: ' + komand);
  check('после ответа портала экран снова живой',
    (await page.textContent('#task-box')).includes('не запускалась'));
  await page.unroute('**/api/task.php');


  // R31-CODE-01: stalled направляет туда, где действительно настраивается cron.
  await page.route('**/api/task.php', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ ok: true, task: { status: 'stalled', percent: 0, done: 0 },
      cron: { silent: false, hint: '' } }),
  }));
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#task-start');
  const stalledHint = await page.textContent('#task-box');
  check('R31-CODE-01 stalled даёт выполнимую инструкцию в панели хостинга',
    /панели хостинга/.test(stalledHint) && !/Служебное/.test(stalledHint));
  await page.unroute('**/api/task.php');

  const unexpected = t.errors.filter((m) => !/Bitrix24 JS library/i.test(m));
  check('в консоли браузера нет посторонних ошибок', unexpected.length === 0,
    unexpected.slice(0, 2).join(' | '));

  await page.screenshot({ path: '/tmp/bx-app-task.png', fullPage: true });
  console.log('\nснимок: /tmp/bx-app-task.png');
});
