/*
 * ЖИВОЙ ПРОГОН СТЕНДА СТИЛЯ.
 *
 * Проверка «класс объявлен в css» проходит и на сломанной вёрстке — поэтому
 * здесь настоящий браузер: кликаем контролы, меряем ширины и высоты, смотрим
 * консоль. Всё, на что жаловался владелец (обрезка, пустоты, узкие графики,
 * системные выпадашки), ловится именно так.
 *
 *   node tests/ui-stand.js
 *
 * Resolver общий с preflight: установленный пакет, явный путь либо старый cache.
 */
const path = require('path');
const { chromium } = require('./playwright-loader');

const failures = [];
function check(what, ok, detail) {
  console.log((ok ? '  ✓ ' : '  ✖ ') + what + (ok || !detail ? '' : ' — ' + detail));
  if (!ok) failures.push(what);
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  // Ссылку на фото в бою отдаёт Битрикс. На изолированном стенде сохраняем
  // тот же https-origin и подменяем только байты картинки, чтобы браузер
  // проверил именно загрузившийся <img>, а не наличие строки в исходнике.
  await page.route('https://portal.invalid/avatar-*.png', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    });
  });

  await page.goto('file://' + path.join(__dirname, 'ui-stand.html'), { waitUntil: 'load' });
  await page.waitForTimeout(400);

  // R08-UI-02: Space должен выбирать чип, сохраняя клавиатурный фокус.
  // Обратный край — повторное нажатие уже выбранного элемента.
  for (const choice of [{ index: 0, value: 'all' }, { index: 1, value: 'err' }]) {
    await page.locator('#c-chips button').nth(choice.index).focus();
    await page.keyboard.press('Space');
    const focusedChip = await page.evaluate(() => ({
      value: window.__ctl.chips.get(),
      focusedInside: document.querySelector('#c-chips').contains(document.activeElement),
      selectedFocused: document.activeElement.getAttribute('aria-checked') === 'true',
    }));
    check('Space на чипе ' + choice.value + ' сохраняет фокус выбранного элемента',
      focusedChip.value === choice.value && focusedChip.focusedInside && focusedChip.selectedFocused,
      JSON.stringify(focusedChip));
  }

  // ── Тёмная тема и шрифт ────────────────────────────────────────────────
  const look = await page.evaluate(() => {
    const bg = getComputedStyle(document.body).backgroundColor;
    const nums = (bg.match(/\d+/g) || []).map(Number);
    return { bg, dark: nums.length >= 3 && (nums[0] + nums[1] + nums[2]) / 3 < 60 };
  });
  check('тема тёмная', look.dark, look.bg);

  // Шрифт обязан РЕАЛЬНО грузиться: объявленный, но не подгруженный, молча
  // подменяется системным, и вся типографика оказывается фикцией.
  // Списка `document.fonts` мало: он говорит, что файл скачался, а не что им
  // РИСУЮТ. Меряем ширину одной и той же строки, набранной Inter и заведомо
  // несуществующим шрифтом с тем же запасным. Совпали — значит Inter не
  // применился и вся типографика держится на системном шрифте.
  const font = await page.evaluate(async () => {
    await document.fonts.ready;
    const meryat = (ff) => {
      const s = document.createElement('span');
      s.textContent = 'Приложение Битрикс24 · 0123456789';
      s.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;' +
                        'font-size:32px;font-family:' + ff;
      document.body.appendChild(s);
      const w = Math.round(s.getBoundingClientRect().width * 100) / 100;
      s.remove();
      return w;
    };
    const bodyFont = getComputedStyle(document.body).fontFamily;
    return {
      inter: meryat("'Inter', sans-serif"),
      sistemnyj: meryat("'ZavedomoNetTakogoShrifta', sans-serif"),
      vSpiske: [...document.fonts].some((f) => /Inter/i.test(f.family) && f.status === 'loaded'),
      uBody: /Inter/i.test(bodyFont),
      proverka: document.fonts.check('32px Inter'),
    };
  });
  check('шрифт Inter реально рисует текст, а не только объявлен',
    font.vSpiske && font.proverka && font.uBody && font.inter !== font.sistemnyj,
    'Inter ' + font.inter + 'px против системного ' + font.sistemnyj + 'px');

  // Межстрочный интервал у текста, который переносится: плотные строки
  // читаются тяжело, а на тёмном фоне — особенно.
  const stroki = await page.evaluate(() => {
    const tesno = [];
    document.querySelectorAll('.content p, .content .row-title, .content .row-note, body').forEach((n) => {
      const cs = getComputedStyle(n);
      const razmer = parseFloat(cs.fontSize);
      const vysota = parseFloat(cs.lineHeight);
      if (!razmer || !vysota) return;
      const k = Math.round((vysota / razmer) * 100) / 100;
      if (k < 1.45) tesno.push((n.className || n.tagName) + ' ' + k);
    });
    return tesno;
  });
  check('межстрочный интервал у текста не меньше 1.45', stroki.length === 0, stroki.join('; '));

  // ── Вёрстка: влезает в окно, без пустот ────────────────────────────────
  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('страница не разъезжается по горизонтали', overflow <= 2, 'лишних ' + overflow + 'px');

  const holes = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('.card, .kpi').forEach((b) => {
      const kids = [...b.children].filter((k) => k.getBoundingClientRect().height > 0);
      if (!kids.length) return;
      const last = kids[kids.length - 1].getBoundingClientRect();
      const box = b.getBoundingClientRect();
      const pad = parseFloat(getComputedStyle(b).paddingBottom) || 0;
      const gap = Math.round(box.bottom - last.bottom - pad);
      if (gap > 60) out.push((b.querySelector('.card-title, .kpi-label') || {}).textContent + ' → ' + gap + 'px');
    });
    return out;
  });
  check('в блоках нет пустоты под содержимым', holes.length === 0, holes.join('; '));

  // ── Высота документа НЕ привязана к окну ───────────────────────────────
  // Внутри портала vh — это высота окна приложения, которую приложение само и
  // заказывает по высоте документа. Замыкание круга даёт то самое «окно не
  // сжимается / приложение обрезано снизу».
  const vhTrap = await page.evaluate(() => {
    const bad = [];
    for (const el of [document.documentElement, document.body, document.querySelector('.layout')]) {
      if (!el) continue;
      const cs = getComputedStyle(el);
      const vh = window.innerHeight;
      // Сравниваем с высотой окна: min-height:100vh даёт ровно её.
      if (Math.abs(parseFloat(cs.minHeight) - vh) < 2) bad.push(el.tagName + '.' + el.className + ' min-height');
      if (Math.abs(parseFloat(cs.height) - vh) < 2 && el !== document.documentElement) {
        bad.push(el.tagName + '.' + el.className + ' height');
      }
    }
    return bad;
  });
  check('высота страницы не привязана к высоте окна', vhTrap.length === 0, vhTrap.join('; '));

  // ── Свои контролы вместо системных ─────────────────────────────────────
  const native = await page.evaluate(() =>
    document.querySelectorAll('select, input[type=number], input[type=date], input[type=search]').length);
  check('на странице нет системных контролов', native === 0, 'найдено ' + native);

  // Списков на стенде два, и безымянный `.ui-select-btn` брал ПЕРВЫЙ попавшийся
  // в разметке — короткий. Проверки выглядели общими, а сторожили ровно тот
  // случай, который не ломался (находка ревью K4-23). Адрес каждого списка
  // назван явно: третий список на стенде больше не переклеит их молча.
  await page.click('#c-select .ui-select-btn');
  await page.waitForTimeout(120);
  const listOpen = await page.$$('#c-select .ui-select-list .ui-select-opt');
  check('свой список раскрывается', listOpen.length >= 3, 'пунктов ' + listOpen.length);
  await page.click('#c-select .ui-select-list .ui-select-opt:last-child');
  const picked = await page.$eval('#c-select .ui-select-value', (e) => e.textContent.trim());
  check('выбор в списке применяется', /Полные/.test(picked), picked);

  // ── ДЛИННЫЙ СПИСОК С ПОИСКОМ (#c-select-long) ──────────────────────────
  // От восьми пунктов список сам рисует строку поиска и уводит в неё фокус —
  // и весь клавиатурный сценарий начинает жить в другом узле. Именно там он и
  // ломался трижды подряд: обработчик клавиш висел на кнопке, поле поиска
  // рождалось заново на каждый символ, курсор назывался кнопке, а не полю.
  // Короткий список из трёх строк ничего этого не показывает вовсе.
  await page.click('#c-select-long .ui-select-btn');
  await page.waitForTimeout(220);
  const dlinnyy = await page.evaluate(() => {
    const list = document.querySelector('#c-select-long .ui-select-list');
    const inp = list && list.querySelector('.ui-select-search input');
    return {
      punktov: list ? list.querySelectorAll('.ui-select-opt').length : 0,
      poleEst: !!inp,
      fokusVPole: !!inp && document.activeElement === inp,
    };
  });
  check('длинный список сам показывает поиск и уводит в него фокус',
    dlinnyy.punktov === 12 && dlinnyy.poleEst && dlinnyy.fokusVPole, JSON.stringify(dlinnyy));

  // НАБОР В СЕРЕДИНУ ЗАПРОСА. Человек набрал «ябр», понял, что не хватает
  // начала, вернулся клавишей Home и допечатал «окт». Пока поле поиска
  // пересоздавалось на каждый символ, фокус и каретка уезжали в конец строки, и
  // выходило «оябркт» — список при этом молчал, потому что такого месяца нет.
  await page.keyboard.type('ябр', { delay: 40 });
  await page.waitForTimeout(150);
  await page.keyboard.press('Home');
  await page.keyboard.type('окт', { delay: 40 });
  await page.waitForTimeout(180);
  const nabor = await page.evaluate(() => {
    const inp = document.querySelector('#c-select-long .ui-select-search input');
    const opts = [].map.call(document.querySelectorAll('#c-select-long .ui-select-opt'),
      (o) => o.textContent.trim());
    return {
      znachenie: inp ? inp.value : null,
      karetka: inp ? inp.selectionStart : null,
      fokus: !!inp && document.activeElement === inp,
      punkty: opts,
    };
  });
  check('набор в середину запроса не переставляет буквы и не роняет каретку',
    nabor.znachenie === 'октябр' && nabor.karetka === 3 && nabor.fokus
    && nabor.punkty.length === 1 && /Октябрь/.test(nabor.punkty[0] || ''),
    JSON.stringify(nabor));

  // ПУСТАЯ ВЫДАЧА обязана быть объявлена: иначе человек с опечаткой получает
  // тишину вместо причины, и курсор остаётся висеть на пункте, которого нет.
  await page.keyboard.press('Control+a');
  await page.keyboard.type('ззз', { delay: 30 });
  await page.waitForTimeout(180);
  const pusto = await page.evaluate(() => {
    const e = document.querySelector('#c-select-long .ui-select-empty');
    const inp = document.querySelector('#c-select-long .ui-select-search input');
    return {
      est: !!e, rol: e ? e.getAttribute('role') : null,
      tekst: e ? e.textContent.trim() : null,
      punktov: document.querySelectorAll('#c-select-long .ui-select-opt').length,
      kursor: inp ? inp.getAttribute('aria-activedescendant') : 'поля нет',
    };
  });
  check('пустая выдача длинного списка объявлена, а не молчит',
    pusto.est && pusto.rol === 'status' && /нашлось/.test(pusto.tekst || '')
    && pusto.punktov === 0 && pusto.kursor === null, JSON.stringify(pusto));

  // СТРЕЛКИ И ENTER В ДЛИННОМ СПИСКЕ. Фокус в поле поиска, поэтому и обработчик
  // клавиш, и имя курсора обязаны жить там же, а не на кнопке.
  await page.keyboard.press('Control+a');
  await page.keyboard.press('Backspace');
  await page.waitForTimeout(180);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.waitForTimeout(150);
  const strelki = await page.evaluate(() => {
    const inp = document.querySelector('#c-select-long .ui-select-search input');
    const btn = document.querySelector('#c-select-long .ui-select-btn');
    const kursor = document.querySelector('#c-select-long .ui-select-opt.is-cursor');
    return {
      podsvechen: kursor ? kursor.textContent.trim() : null,
      naPole: inp ? inp.getAttribute('aria-activedescendant') : 'поля нет',
      naKnopke: btn ? btn.getAttribute('aria-activedescendant') : null,
      sovpadaet: !!(kursor && inp && inp.getAttribute('aria-activedescendant') === kursor.id),
    };
  });
  check('в длинном списке стрелки водят курсор и называют его полю поиска',
    strelki.podsvechen === 'Февраль' && strelki.sovpadaet && strelki.naKnopke === null,
    JSON.stringify(strelki));
  await page.keyboard.press('Enter');
  await page.waitForTimeout(180);
  const vybor = await page.evaluate(() => ({
    znachenie: document.querySelector('#c-select-long .ui-select-value').textContent.trim(),
    otkryt: !!document.querySelector('#c-select-long .ui-select-list'),
    izKontrola: window.__ctl.selectLong.get(),
  }));
  check('Enter в длинном списке выбирает пункт под курсором и закрывает список',
    vybor.znachenie === 'Февраль' && vybor.izKontrola === 2 && !vybor.otkryt,
    JSON.stringify(vybor));

  const before = await page.$eval('.ui-number input', (e) => e.value);
  await page.click('.ui-number button:last-child');
  const after = await page.$eval('.ui-number input', (e) => e.value);
  check('число прибавляется своей кнопкой', Number(after) === Number(before) + 10, before + ' → ' + after);

  await page.click('.ui-date-btn');
  await page.waitForTimeout(120);
  const days = await page.$$('.ui-cal-day');
  const dow = await page.$eval('.ui-cal-dow', (e) => e.textContent.trim());
  check('свой календарь раскрывается и начинается с понедельника', days.length >= 28 && dow === 'пн',
    'дней ' + days.length + ', первый столбец ' + dow);
  await page.click('.ui-cal-day:nth-of-type(10)');
  await page.waitForTimeout(100);

  // ── Выбор людей и отделов ──────────────────────────────────────────────
  const chipsBefore = await page.$$('.picker .chip-tag');
  await page.click('.picker-field input');
  await page.fill('.picker-field input', 'ива');
  await page.waitForTimeout(600);
  const groups = await page.$$eval('.picker-group', (els) => els.map((e) => e.textContent.trim()));
  check('в выдаче есть и отделы, и сотрудники', groups.length === 2, groups.join(' | '));
  // Уволенных смотрим ДО выбора: после клика список перерисовывается, и
  // проверка «после» мерила бы пустоту, а не пометку.
  const fired = await page.$$('.picker-opt.is-fired');
  check('уволенный помечен, а не спрятан', fired.length >= 1, 'помечено ' + fired.length);
  const fotoVPoiske = await page.$eval('.picker-opt img.picker-avatar', (img) => ({
    src: img.src, loaded: img.complete && img.naturalWidth > 0,
  }));
  check('поиск сотрудника показывает загруженное фото из Bitrix URL',
    fotoVPoiske.loaded && fotoVPoiske.src.indexOf('https://portal.invalid/avatar-') === 0,
    JSON.stringify(fotoVPoiske));
  await page.click('.picker-list .picker-opt');
  await page.waitForTimeout(200);
  const chipsAfter = await page.$$('.picker .chip-tag');
  check('выбранное добавляется чипом', chipsAfter.length === chipsBefore.length + 1,
    chipsBefore.length + ' → ' + chipsAfter.length);
  check('фото сотрудника сохраняется в выбранном чипе',
    await page.$eval('.picker .chip-tag img.chip-avatar', (img) =>
      img.complete && img.naturalWidth > 0 && /^https:\/\/portal\.invalid\/avatar-/.test(img.src)));

  // ВЫДАЧА НЕ СХЛОПЫВАЕТСЯ ПОСЛЕ ПЕРВОГО ЖЕ ЧЕЛОВЕКА. Доступ выдают пачкой
  // («весь отдел продаж и двое из бухгалтерии»), и пока в add() стоял run(''),
  // список закрывался после КАЖДОГО выбранного: следующего приходилось искать
  // заново, набирая фамилию целиком. Заодно добавленный обязан получить пометку
  // «уже выбран» и перестать кликаться повторно.
  const vtoroy = await page.evaluate(async () => {
    const root = document.querySelector('#c-picker .picker');
    const spisok = root.querySelector('.picker-list');
    const vidno = spisok ? spisok.querySelectorAll('.picker-opt').length : 0;
    const pometka = spisok ? spisok.querySelectorAll('.picker-opt.is-taken').length : 0;
    const svobodnyy = spisok ? spisok.querySelector('.picker-opt:not(.is-taken)') : null;
    const imya = svobodnyy ? svobodnyy.textContent.trim().split('\n')[0] : null;
    if (svobodnyy) svobodnyy.click();
    await new Promise((r) => setTimeout(r, 200));
    return {
      vidno, pometka, imya,
      chipov: root.querySelectorAll('.chip-tag').length,
      spisokPosle: root.querySelectorAll('.picker-opt').length,
    };
  });
  check('после первого выбранного выдача остаётся на экране, и второго берут оттуда же',
    vtoroy.vidno >= 2 && vtoroy.pometka === 1 && vtoroy.imya !== null
    && vtoroy.chipov === chipsAfter.length + 1 && vtoroy.spisokPosle >= 2,
    JSON.stringify(vtoroy));

  // Очистка контрола — такое же изменение выбора, как и клик по человеку. Пока о
  // ней не сообщали, кнопка «Дать доступ» после успешной выдачи оставалась
  // включённой с пустым выбором, и следующий клик молча не делал ничего.
  const changesAfterClear = await page.evaluate(() => {
    const before = window.__pickerChanges.length;
    window.__picker.clear();
    return { added: window.__pickerChanges.length - before, last: window.__pickerChanges.slice(-1)[0] };
  });
  check('очистка выбора сообщает слушателю, что выбор пуст',
    changesAfterClear.added === 1 && changesAfterClear.last === 0,
    JSON.stringify(changesAfterClear));
  check('после очистки на экране не остаётся выбранных',
    (await page.$$('.picker .chip-tag')).length === 0);

  // ОТВЕТ ПОИСКА, ПРИШЕДШИЙ ПОЗЖЕ, ЧЕМ НУЖНО.
  //
  // Портал отвечает за секунду-две. За это время человек успевает стереть
  // запрос, укоротить его, закрыть выдачу кликом мимо или вовсе уйти с экрана.
  // Ответ приходит в пустоту — и раскрывает список поверх того, что человек
  // уже делает: следующий Enter выдаёт доступ тому, кого никто не выбирал.
  // Живым поиском это не воспроизвести, поэтому промис здесь разрешается
  // вручную, ровно после того как человек «передумал».
  const race = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    async function scenario(peredumal) {
      const mount = document.createElement('div');
      document.body.appendChild(mount);
      const otlozhennye = [];
      const ctl = ui.pickPeople(mount, {
        search: () => new Promise((resolve) => {
          otlozhennye.push(() => resolve({ depts: [], users: [{ id: 91, name: 'Поздняков Пётр' }] }));
        }),
      });
      const root = mount.querySelector('.picker');
      const inp = root.querySelector('input');
      inp.value = 'ива';
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      await wait(450);                          // задержка контрола — 350 мс
      const otpravlen = otlozhennye.length;
      peredumal(ctl, inp, root);
      await wait(450);
      otlozhennye.splice(0).forEach((f) => f());
      await wait(150);
      const punktov = root.querySelectorAll('.picker-opt').length;
      // Клавиатурой выбираем то, чего человек не просил: именно так поздняя
      // выдача превращается в выданный не тому доступ.
      inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
      inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      await wait(80);
      const chipov = root.querySelectorAll('.chip-tag').length;
      mount.remove();
      return { otpravlen, punktov, chipov };
    }
    return {
      steronoRukoy: await scenario((c, inp) => {
        inp.value = ''; inp.dispatchEvent(new Event('input', { bubbles: true }));
      }),
      korotkiyZapros: await scenario((c, inp) => {
        inp.value = 'и'; inp.dispatchEvent(new Event('input', { bubbles: true }));
      }),
      klikMimo: await scenario(() => {
        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      }),
      ushelSEkrana: await scenario((c) => { c.destroy(); }),
    };
  });
  Object.keys(race).forEach((imya) => {
    const r = race[imya];
    check('поздний ответ поиска не открывает выбор людей (' + imya + ')',
      r.otpravlen === 1 && r.punktov === 0 && r.chipov === 0, JSON.stringify(r));
  });

  // ВЫДАЧА УЖЕ БЫЛА, А ПОТОМ ЭКРАН СМЕНИЛСЯ НА «Ищем…» (K4-21).
  //
  // Человек нашёл «ива», увидел выдачу, стёр строку и набрал «петр». Портал
  // отвечает не мгновенно: на экране «Ищем…» и НИ ОДНОГО пункта. Стрелка вниз и
  // Enter в этот момент добавляли «Бухгалтерию» из ПРОШЛОЙ выдачи — доступ
  // получал целый отдел, которого человек не видел и не выбирал. Причина: hint()
  // чистил разметку списка, но оставлял список для клавиатуры (l._items), а
  // Enter берёт кого добавить именно из него. Проверка защиты от поздних
  // ответов (выше) этот путь не закрывает: там выдачи не было вовсе.
  const posleVydachi = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    async function scenario(vtoroyShag) {
      const mount = document.createElement('div');
      document.body.appendChild(mount);
      const otlozhennye = [];
      const ctl = ui.pickPeople(mount, {
        search: () => new Promise((resolve) => {
          otlozhennye.push(() => resolve({
            depts: [{ id: 7, name: 'Бухгалтерия', path: 'Компания → Бухгалтерия' }],
            users: [{ id: 21, name: 'Иванова Анна', post: 'менеджер' }],
          }));
        }),
      });
      const root = mount.querySelector('.picker');
      const inp = root.querySelector('input');
      inp.value = 'ива';
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      await wait(450);                          // задержка контрола — 350 мс
      otlozhennye.splice(0).forEach((f) => f()); // портал ответил, выдача на экране
      await wait(150);
      const bylo = root.querySelectorAll('.picker-opt').length;
      vtoroyShag(inp);
      await wait(450);                          // «Ищем…» либо «нужно два символа»
      const punktov = root.querySelectorAll('.picker-opt').length;
      const ekran = [].map.call(root.querySelectorAll('.picker-list > *'),
        (n) => n.textContent.trim()).slice(0, 2);
      inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
      inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      await wait(80);
      const chipy = [].map.call(root.querySelectorAll('.chip-tag'),
        (n) => n.textContent.replace('×', '').trim());
      otlozhennye.splice(0);
      ctl.destroy(); mount.remove();
      return { bylo, punktov, ekran, chipy };
    }
    return {
      'ждём ответ портала': await scenario((inp) => {
        inp.value = 'петр'; inp.dispatchEvent(new Event('input', { bubbles: true }));
      }),
      'запрос укоротили до буквы': await scenario((inp) => {
        inp.value = 'и'; inp.dispatchEvent(new Event('input', { bubbles: true }));
      }),
    };
  });
  Object.keys(posleVydachi).forEach((imya) => {
    const r = posleVydachi[imya];
    check('Enter на экране без пунктов никого не добавляет (' + imya + ')',
      r.bylo >= 2 && r.punktov === 0 && r.chipy.length === 0,
      'до этого пунктов ' + r.bylo + ', сейчас на экране ' + JSON.stringify(r.ekran)
      + ', видимых пунктов ' + r.punktov + ', чипы ' + JSON.stringify(r.chipy));
  });

  // Портал может отдать людей, но не дать справочник подразделений. Это не
  // пустой результат: контрол обязан показать ограничение рядом с найденными
  // людьми, а при полностью пустой выдаче — объяснить причину вместо ложного
  // «никого и ничего не нашлось» (K4-28).
  const netOtdelov = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    async function scenario(users) {
      const mount = document.createElement('div');
      document.body.appendChild(mount);
      const note = 'Поиск по отделам сейчас недоступен — ищем только людей.';
      const ctl = ui.pickPeople(mount, {
        label: 'Кому дать доступ',
        search: () => Promise.resolve({
          users, depts: [], depts_available: false, depts_note: note,
        }),
      });
      const inp = mount.querySelector('input');
      inp.value = 'ива'; inp.dispatchEvent(new Event('input', { bubbles: true }));
      await wait(550);
      const result = {
        options: mount.querySelectorAll('.picker-opt').length,
        note: (mount.querySelector('.picker-note, .picker-hint') || {}).textContent || '',
        genericEmpty: mount.textContent.indexOf('Никого и ничего не нашлось') >= 0,
      };
      ctl.destroy(); mount.remove();
      return result;
    }
    return {
      withUser: await scenario([{ id: 21, name: 'Иванова Анна' }]),
      empty: await scenario([]),
    };
  });
  check('недоступные подразделения объяснены рядом с найденными людьми',
    netOtdelov.withUser.options === 1 && /отдел/i.test(netOtdelov.withUser.note),
    JSON.stringify(netOtdelov.withUser));
  check('недоступные подразделения не выдаются за пустой результат',
    netOtdelov.empty.options === 0 && /отдел/i.test(netOtdelov.empty.note)
    && !netOtdelov.empty.genericEmpty,
    JSON.stringify(netOtdelov.empty));

  // ESCAPE НЕ ДОЛЖЕН ГЛОХНУТЬ ПОСЛЕ ВЫБОРА ИЗ СПИСКА.
  //
  // Контрол вешает обработчик Escape на документ в capture-фазе и глушит событие,
  // а снимал его только при закрытии «снаружи» — кликом мимо. Выбор пункта
  // КЛАВИАТУРОЙ закрывает список изнутри, обработчик остаётся висеть, и первая же
  // следующая Escape уходит ему, а не диалогу: человек, работающий с клавиатуры,
  // не может закрыть окно подтверждения вообще.
  //
  // Сценарий строго клавиатурный: любой клик мимо контрола снял бы обработчик и
  // спрятал поломку — прежняя версия этой проверки была зелёной и на сломанном коде.
  await page.focus('#c-select .ui-select-btn');
  await page.keyboard.press('ArrowDown');                // раскрыть список
  await page.waitForTimeout(120);
  await page.keyboard.press('ArrowDown');                // встать на пункт
  await page.keyboard.press('Enter');                    // выбрать — закрытие ИЗНУТРИ
  await page.waitForTimeout(120);
  await page.focus('#b-modal');
  await page.keyboard.press('Enter');                    // открыть диалог тоже с клавиатуры
  await page.waitForTimeout(200);
  const modalOpened = !!(await page.$('.modal-back'));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(250);
  check('Escape закрывает диалог после выбора из списка клавиатурой',
    modalOpened && !(await page.$('.modal-back')),
    modalOpened ? 'диалог не закрылся — Escape перехватил список' : 'диалог не открылся');

  // ── Диалог и сообщение ─────────────────────────────────────────────────
  await page.click('#b-modal');
  await page.waitForTimeout(150);
  check('диалог открывается своим окном, а не системным', !!(await page.$('.modal-back .modal')));
  await page.keyboard.press('Escape');
  const modalExit = await page.evaluate(() => {
    const n = document.querySelector('.modal-back.is-leaving');
    if (!n) return 0;
    const value = getComputedStyle(n).animationDuration || '0s';
    return parseFloat(value) * (/ms/.test(value) ? 1 : 1000);
  });
  check('закрытие диалога видно до удаления DOM и занимает 120–200 мс',
    modalExit >= 120 && modalExit <= 200, String(modalExit));
  await page.waitForTimeout(250);
  check('диалог закрывается по Escape', !(await page.$('.modal-back')));

  await page.click('#b-toast');
  await page.waitForTimeout(150);
  check('сообщение показывается тостом', !!(await page.$('#toasts .toast')));

  // ── Действие по кнопке: видимый отклик и защита от второго нажатия ──────
  // Рассуждением это не проверяется: «кнопка выключается» и «человек видит, что
  // идёт работа» — разные вещи, и вторая ловится только замером на живой кнопке.
  const actionBtn = await page.$('#b-action');
  const widthBefore = (await actionBtn.boundingBox()).width;
  await actionBtn.click();
  await page.waitForTimeout(120);
  const busy = await page.evaluate(() => {
    const b = document.getElementById('b-action');
    return {
      disabled: b.disabled,
      spinner: !!b.querySelector('.spinner-inline'),
      width: b.getBoundingClientRect().width,
      spins: !!b.querySelector('.spinner-inline')
        && getComputedStyle(b.querySelector('.spinner-inline')).animationName !== 'none',
    };
  });
  check('во время работы кнопка выключена и показывает кружок',
    busy.disabled && busy.spinner && busy.spins,
    JSON.stringify(busy));
  check('ширина кнопки не прыгает на время работы',
    Math.abs(busy.width - widthBefore) < 1, widthBefore + ' → ' + busy.width);

  // Второе нажатие во время работы не должно запускать её ещё раз. Кликаем в
  // обход disabled — через прямой вызов обработчика: именно так дублируют
  // операцию скрипты и повторные события, а не человек мышью.
  await page.evaluate(() => document.getElementById('b-action').onclick(
    { currentTarget: document.getElementById('b-action') }));
  await page.waitForTimeout(800);
  const runs = await page.evaluate(() => window.__actionRuns());
  check('повторный запуск во время работы не делает операцию дважды', runs === 1, 'запусков: ' + runs);

  const restored = await page.evaluate(() => {
    const b = document.getElementById('b-action');
    return { disabled: b.disabled, text: b.textContent.trim(), spinner: !!b.querySelector('.spinner-inline') };
  });
  check('после работы кнопка возвращается в исходный вид',
    !restored.disabled && restored.text === 'Провести операцию' && !restored.spinner,
    JSON.stringify(restored));

  await page.click('#b-action-fail');
  await page.waitForTimeout(500);
  const failed = await page.evaluate(() => {
    const b = document.getElementById('b-action-fail');
    return { disabled: b.disabled, text: b.textContent.trim(), toast: !!document.querySelector('#toasts .toast') };
  });
  check('отказ возвращает кнопку в строй и показывает сообщение',
    !failed.disabled && failed.text === 'Отказ портала' && failed.toast, JSON.stringify(failed));

  // ── ДОСТУПНАЯ СЕМАНТИКА СВОИХ КОНТРОЛОВ (BXREV-022) ────────────────────
  // Свои контролы рисуются кнопками и div-ами: глазами они «список» и «диалог»,
  // а экранному диктору — просто кнопка и безымянная область. Проверяем ЖИВЫМ
  // окном то, что услышит человек, а не то, что написано в разметке.
  const selectA11y = await page.evaluate(async () => {
    const knopka = document.querySelector('#c-select .ui-select-btn, #c-select button');
    if (!knopka) return { est: false };
    const doOtkrytiya = {
      role: knopka.getAttribute('role'),
      expanded: knopka.getAttribute('aria-expanded'),
      haspopup: knopka.getAttribute('aria-haspopup'),
    };
    knopka.click();
    await new Promise((r) => setTimeout(r, 150));
    const spisok = document.querySelector('#c-select .ui-select-list');
    const punkt = spisok ? spisok.querySelector('.ui-select-opt') : null;
    const posle = {
      expanded: knopka.getAttribute('aria-expanded'),
      controls: knopka.getAttribute('aria-controls'),
      spisokRole: spisok ? spisok.getAttribute('role') : null,
      punktRole: punkt ? punkt.getAttribute('role') : null,
      punktSelected: punkt ? punkt.getAttribute('aria-selected') : null,
      spisokId: spisok ? spisok.id : null,
    };
    return { est: true, doOtkrytiya, posle };
  });
  check('свой список объявлен списком, а не кнопкой',
    selectA11y.est && selectA11y.doOtkrytiya.role === 'combobox'
    && selectA11y.doOtkrytiya.haspopup === 'listbox'
    && selectA11y.doOtkrytiya.expanded === 'false',
    JSON.stringify(selectA11y.doOtkrytiya || {}));
  check('открытый список слышно: состояние, связь и роли пунктов',
    selectA11y.est && selectA11y.posle.expanded === 'true'
    && selectA11y.posle.controls === selectA11y.posle.spisokId
    && selectA11y.posle.spisokRole === 'listbox'
    && selectA11y.posle.punktRole === 'option'
    && ['true', 'false'].includes(selectA11y.posle.punktSelected),
    JSON.stringify(selectA11y.posle || {}));

  const dynamicSelect = await page.evaluate(() => {
    const ctl = window.__ctl.select;
    const button = document.querySelector('#c-select .ui-select-btn');
    if (!ctl || !button) return { ok: false };
    if (!document.querySelector('#c-select .ui-select-list')) button.click();
    ctl.setOptions([{ value: 'new-only', label: 'Только новый' }]);
    const labels = [].map.call(document.querySelectorAll('#c-select .ui-select-opt'),
      (n) => n.textContent.trim());
    const option = document.querySelector('#c-select .ui-select-opt');
    if (option) option.click();
    const selected = ctl.get();
    ctl.setOptions([
      { value: 'view', label: 'Только смотреть' },
      { value: 'edit', label: 'Смотреть и менять' },
      { value: 'admin', label: 'Полные, включая доступ' },
    ]);
    return { ok: true, labels, selected };
  });
  check('setOptions перерисовывает уже открытый список и не оставляет старые пункты',
    dynamicSelect.ok && dynamicSelect.labels.length === 1
    && dynamicSelect.labels[0] === 'Только новый' && dynamicSelect.selected === 'new-only',
    JSON.stringify(dynamicSelect));

  const stateA11y = await page.evaluate(() => {
    const host = document.getElementById('c-empty');
    ui.showState(host, 'empty', { text: 'Нет строк' });
    const empty = host.querySelector('.state');
    const a = { role: empty && empty.getAttribute('role'), live: empty && empty.getAttribute('aria-live') };
    ui.showState(host, 'error', { text: 'Повторите загрузку', onRetry: function () {} });
    const error = host.querySelector('.state');
    return { empty: a, error: {
      role: error && error.getAttribute('role'), live: error && error.getAttribute('aria-live'),
      retry: !!host.querySelector('[data-retry]')
    } };
  });
  check('динамические empty и error объявляются экранному диктору',
    stateA11y.empty.role === 'status' && stateA11y.empty.live === 'polite'
    && stateA11y.error.role === 'alert' && stateA11y.error.live === 'assertive'
    && stateA11y.error.retry, JSON.stringify(stateA11y));

  const toastA11y = await page.evaluate(async () => {
    const b = document.getElementById('b-toast');
    if (b) b.click();
    await new Promise((r) => setTimeout(r, 150));
    const box = document.getElementById('toasts');
    const t = box ? box.querySelector('.toast') : null;
    return { live: box ? box.getAttribute('aria-live') : null, role: t ? t.getAttribute('role') : null };
  });
  check('сообщение произносится, а не только показывается',
    toastA11y.live === 'polite' && ['status', 'alert'].includes(toastA11y.role),
    JSON.stringify(toastA11y));

  const modalA11y = await page.evaluate(async () => {
    const b = document.getElementById('b-modal');
    if (b) b.click();
    await new Promise((r) => setTimeout(r, 150));
    const m = document.querySelector('.modal[role="dialog"]');
    if (!m) return { est: false };
    const by = m.getAttribute('aria-labelledby');
    const desc = m.getAttribute('aria-describedby');
    return {
      est: true, modal: m.getAttribute('aria-modal'),
      zagolovok: by ? !!document.getElementById(by) : false,
      tekst: desc ? !!document.getElementById(desc) : false,
    };
  });
  check('диалог назван и объяснён, а не просто «диалог»',
    modalA11y.est && modalA11y.modal === 'true' && modalA11y.zagolovok && modalA11y.tekst,
    JSON.stringify(modalA11y));
  // Диалог закрываем — иначе следующий снимок экрана делается поверх него.
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);

  // ── ОКНО ПОДТВЕРЖДЕНИЯ: ОДНО НА ЭКРАНЕ, ФОН ПОД НИМ СТОИТ ──────────────
  //
  // Кнопка спрашивает «точно?» ДО того, как станет занятой, — значит второе
  // нажатие (дребезг мыши, повторное событие) задаёт тот же вопрос ещё раз.
  // Раньше это рисовало второй overlay поверх первого: человек отвечал один
  // раз и видел ровно такое же окно снова, а под ним оставался неотвеченный
  // первый. Плюс фон: пока окно открыто, страница за ним прокручиваться не
  // должна — иначе человек листает список и теряет из виду то, что у него
  // спросили. Правило `body.modal-open` в css было, а класс не ставил никто.
  // Раскрытый список забирает Escape себе, поэтому от прошлых проверок на
  // экране мог остаться диалог. Начинаем с чистого экрана и говорим об этом
  // вслух: иначе следующая проверка мерила бы чужой мусор.
  await page.keyboard.press('Escape');
  await page.waitForTimeout(120);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
  check('перед проверкой диалогов экран чист', (await page.$$('.modal-back')).length === 0);

  const dialog = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    window.scrollTo(0, 0);
    window.__otvety = [];
    const vopros = { key: 'acl-del-user-42', title: 'Убрать доступ?',
                     text: 'Сотрудник перестанет открывать приложение.',
                     okText: 'Убрать', danger: true };
    ui.confirmBox(vopros).then((v) => window.__otvety.push(v));
    ui.confirmBox(vopros).then((v) => window.__otvety.push(v));   // дребезг нажатия
    await wait(150);
    const idy = [...document.querySelectorAll('.modal-back [id]')].map((n) => n.id);
    return {
      okon: document.querySelectorAll('.modal-back').length,
      dubliId: idy.length !== new Set(idy).size,
      zamok: document.body.classList.contains('modal-open'),
      fon: getComputedStyle(document.body).overflowY,
      byloY: window.scrollY,
    };
  });
  // Класс на body сам по себе ничего не доказывает: `overflow:hidden` не мешает
  // прокрутке из кода — он останавливает КОЛЕСО. Крутим колесом, как человек.
  await page.mouse.move(640, 450);
  await page.mouse.wheel(0, 600);
  await page.waitForTimeout(250);
  dialog.staloY = await page.evaluate(() => window.scrollY);

  const posle = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    document.querySelector('.modal-back [data-yes]').click();
    await wait(250);
    return {
      otvetov: window.__otvety.length,
      otvet: window.__otvety.join(','),
      ostalos: document.querySelectorAll('.modal-back').length,
      zamokPosle: document.body.classList.contains('modal-open'),
      fonPosle: getComputedStyle(document.body).overflowY,
    };
  });
  Object.assign(dialog, posle);
  // Прокрутку колесом проверяем ещё раз — уже без диалога: если фон не поехал и
  // сейчас, значит замок не сняли, и человек остался с неподвижной страницей.
  await page.mouse.wheel(0, 600);
  await page.waitForTimeout(250);
  dialog.svobodnoY = await page.evaluate(() => window.scrollY);

  check('повторный вопрос не открывает второе окно поверх первого',
    dialog.okon === 1 && !dialog.dubliId, JSON.stringify(dialog));
  check('пока открыт диалог, фон под ним не прокручивается колесом',
    dialog.zamok && dialog.fon === 'hidden' && dialog.staloY === dialog.byloY,
    JSON.stringify(dialog));
  check('один ответ доходит до обоих спрашивавших, окно закрывается насовсем',
    dialog.otvetov === 2 && dialog.otvet === 'true,true' && dialog.ostalos === 0,
    JSON.stringify(dialog));
  check('после закрытия диалога прокрутка страницы возвращается',
    !dialog.zamokPosle && dialog.fonPosle !== 'hidden' && dialog.svobodnoY > dialog.staloY,
    JSON.stringify(dialog));
  await page.evaluate(() => window.scrollTo(0, 0));

  // ОБРАТНАЯ СТОРОНА ТОГО ЖЕ ПРЕДОХРАНИТЕЛЯ: склейка не должна съедать РАЗНЫЕ
  // вопросы. В списке текст у всех строк одинаков («Убрать доступ? Сотрудник
  // сразу перестанет открывать приложение»), поэтому склейка по тексту
  // схлопнула бы снятие доступа у двух разных сотрудников в один вопрос —
  // человек подтверждает одно, а происходит два. Без ключа вопросы обязаны
  // встать в очередь и получить по своему ответу.
  const raznye = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const otvety = [];
    const odin = { title: 'Убрать доступ?', text: 'Сотрудник перестанет открывать приложение.',
                   okText: 'Убрать', danger: true };
    ui.confirmBox(odin).then((v) => otvety.push('первый:' + v));
    ui.confirmBox(odin).then((v) => otvety.push('второй:' + v));
    await wait(150);
    const srazu = document.querySelectorAll('.modal-back').length;
    document.querySelector('.modal-back [data-yes]').click();
    await wait(250);
    const vtoroy = document.querySelectorAll('.modal-back').length;
    if (vtoroy) document.querySelector('.modal-back [data-no]').click();
    await wait(250);
    return { srazu, vtoroy, otvety: otvety.join(' '),
             ostalos: document.querySelectorAll('.modal-back').length,
             zamok: document.body.classList.contains('modal-open') };
  });
  check('два разных вопроса не склеиваются в один, а идут по очереди',
    raznye.srazu === 1 && raznye.vtoroy === 1 && raznye.ostalos === 0 && !raznye.zamok
    && raznye.otvety === 'первый:true второй:false',
    JSON.stringify(raznye));

  // ── ИМЯ, ОПИСАНИЕ И ОШИБКА У КАЖДОГО ГОТОВОГО КОНТРОЛА ─────────────────
  //
  // Подпись, лежащая рядом в <span>, именем контрола НЕ становится: связи
  // между ними нет ни для браузера, ни для диктора. Человек, работающий на
  // слух, слышит «кнопка, Смотреть и менять» и не знает, что это за поле —
  // а в форме настроек таких полей восемь. То же с отказом: красная строчка
  // под полем видна глазами и не существует для диктора, пока поле не помечено
  // aria-invalid и не связано с текстом ошибки (находка ревью 09.08.2026).
  const imena = await page.evaluate(() => {
    const imya = (n) => {
      const by = n.getAttribute('aria-labelledby');
      if (by) {
        const t = by.split(/\s+/)
          .map((id) => ((document.getElementById(id) || {}).textContent || '')).join(' ').trim();
        if (t) return t;
      }
      return (n.getAttribute('aria-label') || '').trim();
    };
    const uzly = [
      ['список', '#c-select .ui-select-btn'],
      ['список с поиском', '#c-select-long .ui-select-btn'],
      ['число', '#c-number input'],
      ['кнопка «меньше»', '#c-number button:first-child'],
      ['кнопка «больше»', '#c-number button:last-child'],
      ['дата', '#c-date .ui-date-btn'],
      ['поиск', '#c-search input'],
      ['крестик поиска', '#c-search .ui-search-clear'],
      ['переключатель', '#c-switch .ui-switch'],
      ['группа чипов', '#c-chips .chips'],
      ['выбор людей', '#c-picker .picker-field input'],
    ];
    const bez = [];
    uzly.forEach((p) => {
      const n = document.querySelector(p[1]);
      if (!n) { bez.push(p[0] + ' (узел не найден)'); return; }
      if (!imya(n)) bez.push(p[0]);
    });
    return bez;
  });
  check('у каждого готового контрола есть объявленное имя', imena.length === 0,
    'без имени: ' + imena.join(', '));

  const opisanie = await page.evaluate(() => {
    const n = document.querySelector('#c-number input');
    const by = n ? (n.getAttribute('aria-describedby') || '') : '';
    return by.split(/\s+/).filter(Boolean)
      .map((id) => ((document.getElementById(id) || {}).textContent || '').trim()).join(' | ');
  });
  check('подсказка под полем связана с самим полем', /Шаг 10/.test(opisanie), opisanie || 'связи нет');

  const oshibki = await page.evaluate(() => {
    const out = {};
    Object.keys(window.__ctl).forEach((imya) => {
      const c = window.__ctl[imya];
      if (!c || typeof c.setError !== 'function') { out[imya] = 'нет setError'; return; }
      c.setError('Так нельзя');
      const uzel = c.el.querySelector('[aria-invalid="true"]')
        || (c.el.getAttribute('aria-invalid') === 'true' ? c.el : null);
      const by = uzel ? (uzel.getAttribute('aria-describedby') || '') : '';
      const tekst = by.split(/\s+/).filter(Boolean)
        .map((id) => ((document.getElementById(id) || {}).textContent || '').trim()).join(' ');
      const slyshno = uzel && /Так нельзя/.test(tekst)
        && !!c.el.querySelector('[role="alert"], [role="status"]');
      c.setError('');
      const snyato = !c.el.querySelector('[aria-invalid="true"]')
        && c.el.getAttribute('aria-invalid') !== 'true';
      out[imya] = (slyshno && snyato) ? 'ok'
        : 'помечено=' + !!uzel + ' слышно=' + !!slyshno + ' снято=' + snyato;
    });
    return out;
  });
  const plohie = Object.keys(oshibki).filter((k) => oshibki[k] !== 'ok');
  check('контрол умеет объявить отказ и снять его', plohie.length === 0,
    plohie.map((k) => k + ': ' + oshibki[k]).join('; '));

  // Выбор людей — это combobox со списком, а не безымянное поле ввода.
  const pickerA11y = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const root = document.querySelector('#c-picker .picker');
    const inp = root.querySelector('input');
    const doOtkr = {
      role: inp.getAttribute('role'),
      expanded: inp.getAttribute('aria-expanded'),
      avtodop: inp.getAttribute('aria-autocomplete'),
    };
    inp.value = 'ива';
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    await wait(600);
    const spisok = root.querySelector('.picker-list');
    const punkt = spisok ? spisok.querySelector('.picker-opt') : null;
    inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    await wait(80);
    const posle = {
      expanded: inp.getAttribute('aria-expanded'),
      controls: inp.getAttribute('aria-controls'),
      spisokId: spisok ? spisok.id : null,
      spisokRole: spisok ? spisok.getAttribute('role') : null,
      punktRole: punkt ? punkt.getAttribute('role') : null,
      kursor: inp.getAttribute('aria-activedescendant'),
      kursorEst: !!document.getElementById(inp.getAttribute('aria-activedescendant') || 'нет'),
    };
    inp.value = '';
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    await wait(450);
    return { doOtkr, posle, zakryt: inp.getAttribute('aria-expanded') };
  });
  check('выбор людей объявлен списком с поиском, а не пустым полем',
    pickerA11y.doOtkr.role === 'combobox' && pickerA11y.doOtkr.expanded === 'false'
    && pickerA11y.doOtkr.avtodop === 'list'
    && pickerA11y.posle.expanded === 'true'
    && pickerA11y.posle.controls === pickerA11y.posle.spisokId
    && pickerA11y.posle.spisokRole === 'listbox'
    && pickerA11y.posle.punktRole === 'option'
    && pickerA11y.posle.kursorEst
    && pickerA11y.zakryt === 'false',
    JSON.stringify(pickerA11y));

  // Календарь: своё окно, названные числа и ходьба стрелками.
  const calA11y = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const root = document.querySelector('#c-date .ui-date');
    const btn = root.querySelector('.ui-date-btn');
    const doOtkr = {
      popup: btn.getAttribute('aria-haspopup'),
      expanded: btn.getAttribute('aria-expanded'),
    };
    btn.focus();
    btn.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    await wait(200);
    const cal = root.querySelector('.ui-cal');
    const den = cal ? cal.querySelector('.ui-cal-day') : null;
    const vybran = cal ? cal.querySelector('.ui-cal-day.is-selected') : null;
    const snimok = {
      otkryt: btn.getAttribute('aria-expanded'),
      calRole: cal ? cal.getAttribute('role') : null,
      calImya: cal ? (cal.getAttribute('aria-label') || '') : '',
      denImya: den ? (den.getAttribute('aria-label') || '') : '',
      vybranSlyshno: vybran ? vybran.getAttribute('aria-selected') : null,
      fokusNaDne: !!(document.activeElement && document.activeElement.classList
        && document.activeElement.classList.contains('ui-cal-day')),
    };
    // Стрелка вправо обязана перевести на следующее число, а не увести из окна.
    const bylo = document.activeElement;
    document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    await wait(80);
    snimok.strelkaHodit = document.activeElement !== bylo
      && !!(document.activeElement.classList && document.activeElement.classList.contains('ui-cal-day'));
    document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await wait(150);
    snimok.zakrylsya = btn.getAttribute('aria-expanded') === 'false' && !root.querySelector('.ui-cal');
    return { doOtkr, snimok };
  });
  check('календарь открывается с клавиатуры, называет числа и ходит стрелками',
    calA11y.doOtkr.popup === 'dialog' && calA11y.doOtkr.expanded === 'false'
    && calA11y.snimok.otkryt === 'true' && calA11y.snimok.calRole === 'dialog'
    && /дат/i.test(calA11y.snimok.calImya)
    && /август/i.test(calA11y.snimok.denImya)
    && calA11y.snimok.vybranSlyshno === 'true'
    && calA11y.snimok.fokusNaDne && calA11y.snimok.strelkaHodit && calA11y.snimok.zakrylsya,
    JSON.stringify(calA11y));

  // ── КОНТРАСТ ТЕКСТА ────────────────────────────────────────────────────
  //
  // Считаем по ОТРИСОВАННЫМ цветам, а не по списку токенов: токен может быть
  // хорош, а текст положен на неожиданный фон — на плашку, на выделенную
  // строку, на приподнятую панель. Меряем то, что видит человек.
  const kontrast = await page.evaluate(() => {
    const razobrat = (s) => {
      const m = String(s).match(/rgba?\(([^)]+)\)/);
      if (!m) return null;
      const p = m[1].split(',').map((x) => parseFloat(x));
      return { r: p[0], g: p[1], b: p[2], a: p[3] == null ? 1 : p[3] };
    };
    const nalozhit = (v, na) => ({
      r: v.r * v.a + na.r * (1 - v.a),
      g: v.g * v.a + na.g * (1 - v.a),
      b: v.b * v.a + na.b * (1 - v.a), a: 1,
    });
    const yarkost = (c) => {
      const f = [c.r, c.g, c.b].map((v) => {
        v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
    };
    const otnoshenie = (a, b) => {
      const l1 = yarkost(a), l2 = yarkost(b);
      return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    };
    // Фон ищем вверх по предкам: у самого узла он почти всегда прозрачный.
    const fon = (n) => {
      let sloi = [];
      for (let p = n; p; p = p.parentElement) {
        const c = razobrat(getComputedStyle(p).backgroundColor);
        if (c && c.a > 0) { sloi.unshift(c); if (c.a === 1) break; }
      }
      if (!sloi.length) return { r: 15, g: 17, b: 21, a: 1 };
      return sloi.reduce((niz, verh) => (verh.a === 1 ? verh : nalozhit(verh, niz)));
    };
    const plohie = [];
    document.querySelectorAll('.content *, .sidebar *').forEach((n) => {
      if (n.children.length) return;                     // мерим листья с текстом
      const tekst = (n.textContent || '').trim();
      if (!tekst) return;
      if (n.closest('[aria-hidden="true"]') || n.disabled) return;
      const cs = getComputedStyle(n);
      if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.9) return;
      const cvet = razobrat(cs.color);
      if (!cvet) return;
      const podlozhka = fon(n);
      const k = otnoshenie(cvet.a === 1 ? cvet : nalozhit(cvet, podlozhka), podlozhka);
      if (k < 4.5) {
        plohie.push(tekst.slice(0, 18) + ' (' + (n.className || n.tagName) + ') ' + (Math.round(k * 100) / 100));
      }
    });
    return plohie;
  });
  check('текст читается: контраст к своему фону не ниже 4.5:1',
    kontrast.length === 0, kontrast.slice(0, 4).join('; ') + (kontrast.length > 4 ? ' … всего ' + kontrast.length : ''));

  // Кнопка, которой не задали оправу, остаётся СИСТЕМНОЙ: браузер рисует серую
  // плашку buttonface с белой рамкой. В тёмной теме это принимаешь за оттенок
  // фона — а на самом деле это ровно то «недоделано», от которого весь набор
  // своих контролов и заведён. Сравниваем с эталонной ненастроенной кнопкой.
  const sistemnye = await page.evaluate(() => {
    const etalon = document.createElement('button');
    etalon.textContent = 'x';
    document.body.appendChild(etalon);
    const e = getComputedStyle(etalon);
    const nativeBg = e.backgroundColor, nativeBorder = e.borderTopColor + ' ' + e.borderTopStyle;
    etalon.remove();
    const plohie = [];
    document.querySelectorAll('.layout button').forEach((b) => {
      const cs = getComputedStyle(b);
      if (cs.backgroundColor === nativeBg && (cs.borderTopColor + ' ' + cs.borderTopStyle) === nativeBorder) {
        plohie.push(b.className || b.textContent.trim().slice(0, 12) || 'button');
      }
    });
    return plohie;
  });
  check('ни одна кнопка не осталась в системной оправе', sistemnye.length === 0,
    sistemnye.slice(0, 4).join('; '));

  // ── СВОИ ПОЛОСЫ ПРОКРУТКИ НА КАЖДОМ ПРОКРУЧИВАЕМОМ БЛОКЕ ───────────────
  //
  // Системная полоса внутри тёмного приложения — светлая: она выдаёт
  // «недоделанность» сильнее любой мелочи вёрстки. Правило состоит из ДВУХ
  // половин, и одна без другой красит только половину браузеров:
  // `scrollbar-width`/`scrollbar-color` понимает Firefox, `::-webkit-scrollbar*`
  // — Chrome, Edge и Safari. Проверяем обе и на КАЖДОМ прокручиваемом месте, а
  // не только на странице целиком: списки, таблица, диалог и меню прокручиваются
  // сами и легко остаются с системной полосой.
  const polosy = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    // Раскрываем всё, что прокручивается только в открытом виде.
    document.querySelector('#c-select-long .ui-select-btn').click();
    const pInp = document.querySelector('#c-picker .picker-field input');
    pInp.value = 'ива';
    pInp.dispatchEvent(new Event('input', { bubbles: true }));
    ui.confirmBox({ title: 'Полоса в диалоге', text: 'т'.repeat(40) });
    await wait(700);
    const mesta = {
      'страница': document.body,
      'длинный список': document.querySelector('.ui-select-list'),
      'выбор людей': document.querySelector('.picker-list'),
      'таблица': document.querySelector('.table-wrap'),
      'боковое меню': document.querySelector('.sidebar'),
      'диалог': document.querySelector('.modal'),
    };
    const plohie = [];
    Object.keys(mesta).forEach((imya) => {
      const n = mesta[imya];
      if (!n) { plohie.push(imya + ': нет узла'); return; }
      const cs = getComputedStyle(n);
      const webkit = getComputedStyle(n, '::-webkit-scrollbar');
      const polzunok = getComputedStyle(n, '::-webkit-scrollbar-thumb');
      const bedy = [];
      if (cs.scrollbarWidth !== 'thin') bedy.push('нет scrollbar-width');
      if (!cs.scrollbarColor || cs.scrollbarColor === 'auto') bedy.push('нет scrollbar-color');
      if (!/^\d/.test(webkit.width || '') || parseFloat(webkit.width) > 12) bedy.push('нет ::-webkit-scrollbar');
      const zalivka = polzunok.backgroundColor || '';
      if (!zalivka || /rgba\(0, 0, 0, 0\)/.test(zalivka)) bedy.push('нет ::-webkit-scrollbar-thumb');
      if (bedy.length) plohie.push(imya + ': ' + bedy.join(', '));
    });
    return plohie;
  });
  check('свои полосы прокрутки на каждом прокручиваемом блоке, обеими половинами',
    polosy.length === 0, polosy.join('; '));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);

  // ── ПЛАВНОСТЬ ──────────────────────────────────────────────────────────
  // Мгновенная смена цвета читается как «мигнуло», долгая — как «тормозит».
  // 120–200 мс — та полка, на которой отклик кажется живым.
  const plavnost = await page.evaluate(() => {
    const plohie = [];
    [['кнопка', '.btn'], ['карточка', '.card'], ['пункт меню', '.nav-item'],
     ['чип', '.chip'], ['переключатель', '.ui-switch-track'], ['ячейка таблицы', '.table tbody td'],
     ['псевдоэлемент поиска', '.ui-search-icon', '::after']]
      .forEach((p) => {
        const n = document.querySelector(p[1]);
        if (!n) { plohie.push(p[0] + ': нет узла'); return; }
        const cs = getComputedStyle(n, p[2] || null);
        const dl = (cs.transitionDuration || '').split(',')
          .map((s) => parseFloat(s) * (/ms/.test(s) ? 1 : 1000)).filter((x) => x > 0);
        if (!dl.length) { plohie.push(p[0] + ': перехода нет'); return; }
        const hudo = dl.filter((ms) => ms < 120 || ms > 200);
        if (hudo.length) plohie.push(p[0] + ': ' + hudo.join('/') + ' мс');
      });
    return plohie;
  });
  check('наведение и фокус плавные, 120–200 мс', plavnost.length === 0, plavnost.join('; '));

  // ── ШЕСТЬ ВОПРОСОВ UX/UI, ЗАДАННЫЕ РУКАМИ НА СТЕНДЕ ────────────────────
  // Правило владельца («интерфейс красивый и удобный, а не просто рабочий»)
  // требует пройти перед «готово» шесть вопросов. Четыре из них уже закрыты
  // выше: отклик на нажатие за 200 мс (кнопка с кружком), окно от 320 px,
  // клавиатура (стрелки и Enter в списках, календарь, Escape). Ниже — три
  // оставшихся, каждый живым замером, а не чтением CSS.

  // ПУСТОЕ СОСТОЯНИЕ ГОВОРИТ, ЧТО ДЕЛАТЬ. «Пока пусто» без продолжения человек
  // читает как поломку и идёт спрашивать, вместо того чтобы нажать кнопку.
  const pustoe = await page.evaluate(() => {
    const plohie = [];
    document.querySelectorAll('.state').forEach((n) => {
      if (n.querySelector('.spinner')) return;      // это «загружаем», а не пустота
      const zag = n.querySelector('b');
      if (!zag || zag.textContent.trim().length < 3) {
        plohie.push('состояние без заголовка: ' + n.textContent.trim().slice(0, 30));
        return;
      }
      const podskazka = n.textContent.replace(zag.textContent, '').trim();
      if (podskazka.length < 15) plohie.push('«' + zag.textContent.trim() + '»: сказано, что пусто, но не что делать');
    });
    return plohie;
  });
  check('пустое состояние говорит, что делать, а не только «здесь пусто»',
    pustoe.length === 0, pustoe.join('; '));

  // ДЛИННЫЙ ТЕКСТ НЕ ВЫЛЕЗАЕТ. Ссылка из журнала и идентификатор поля приходят
  // из портала одним словом без пробелов и разносят блок вбок молча.
  const dlinnyyTekst = await page.evaluate(() => {
    const plohie = [];
    document.querySelectorAll('.card, .row-item, .state, .kpi').forEach((n) => {
      // Таблица прокручивается вбок НАМЕРЕННО, в своей рамке — это не поломка.
      if (n.closest('.table-wrap')) return;
      if (n.scrollWidth > n.clientWidth + 1) {
        plohie.push((n.className || n.tagName) + ': +' + (n.scrollWidth - n.clientWidth) + 'px');
      }
    });
    return plohie.slice(0, 3);
  });
  check('длинное слово без пробелов не разносит свой блок вбок',
    dlinnyyTekst.length === 0, dlinnyyTekst.join('; '));

  // ВИДИМЫЙ ФОКУС. Идём Tab'ом, как человек без мыши: у каждого элемента, до
  // которого доходит очередь, обводка или тень обязаны быть видны — иначе
  // человек не понимает, где он находится, и нажимает наугад.
  await page.evaluate(() => { window.scrollTo(0, 0); if (document.activeElement) document.activeElement.blur(); });
  const fokusy = [];
  for (let i = 0; i < 14; i++) {
    await page.keyboard.press('Tab');
    const r = await page.evaluate(() => {
      const n = document.activeElement;
      if (!n || n === document.body || n === document.documentElement) return null;
      const cs = getComputedStyle(n);
      return {
        kto: String(n.id || n.className || n.tagName).slice(0, 40),
        obvodka: parseFloat(cs.outlineWidth || '0'),
        stil: cs.outlineStyle,
        ten: (cs.boxShadow || 'none') !== 'none',
      };
    });
    if (r) fokusy.push(r);
  }
  const nevidimyeFokusy = fokusy.filter((f) => !((f.obvodka > 0 && f.stil !== 'none') || f.ten));
  check('у каждого элемента, до которого доходит Tab, фокус видно',
    fokusy.length >= 6 && nevidimyeFokusy.length === 0,
    'проверено ' + fokusy.length + ', без обводки: '
    + (nevidimyeFokusy.map((f) => f.kto).slice(0, 3).join(', ') || 'нет'));

  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.waitForTimeout(120);
  const bezDvizheniya = await page.evaluate(() => {
    const plohie = [];
    document.querySelectorAll('body *').forEach((n) => {
      [null, '::before', '::after'].forEach((pseudo) => {
        const cs = getComputedStyle(n, pseudo);
        const dvizh = (cs.transitionDuration || '0s').split(',').some((s) => parseFloat(s) > 0);
        const anim = cs.animationName && cs.animationName !== 'none';
        if (dvizh || anim) plohie.push((n.className || n.tagName) + (pseudo || ''));
      });
    });
    return plohie.slice(0, 3);
  });
  check('reduced-motion гасит core/project элементы и оба псевдоэлемента',
    bezDvizheniya.length === 0, bezDvizheniya.join('; '));
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.waitForTimeout(120);

  // ── УЗКОЕ ОКНО ПОРТАЛА ─────────────────────────────────────────────────
  //
  // Приложение живёт в iframe, ширину которого задаёт портал, а не мы: слайдер
  // сделки, узкий монитор, увеличенный масштаб — и рабочая область оказывается
  // в 300 px. Проверка на одном 1280 px этого не ловит совсем: сетка карточек
  // задана минимальной шириной колонки, и она просто вылезает за край, унося
  // кнопки за пределы видимого. Горизонтальной прокрутки внутри портала у
  // человека нет — он видит обрезанное приложение и считает его сломанным.
  for (const shirina of [320, 360, 768, 900]) {
    await page.setViewportSize({ width: shirina, height: 900 });
    await page.waitForTimeout(160);
    const uzko = await page.evaluate(() => {
      const de = document.documentElement;
      const vylezli = [];
      // Внутри блока, который прокручивается сам (таблица, полоса меню в узком
      // окне), «вылезти» невозможно: там это и есть работа — уехать и уехать
      // обратно колесом. Считаем только то, что живёт в общем потоке страницы.
      const vSkrollere = (el) => {
        for (let p = el; p && p !== document.documentElement; p = p.parentElement) {
          if (getComputedStyle(p).overflowX !== 'visible') return true;
        }
        return false;
      };
      document.querySelectorAll('.layout, .layout *').forEach((el) => {
        if (vSkrollere(el)) return;
        // Мерим по краю окна, а не по краю родителя: значение имеет только то,
        // что человек не может увидеть и нажать. Рисованные мелочи вроде ручки
        // лупы торчат из своей рамки намеренно и до края окна не достают.
        const pravyj = el.getBoundingClientRect().left + el.scrollWidth;
        if (pravyj > de.clientWidth + 1) {
          vylezli.push((el.className || el.tagName) + ' до ' + Math.round(pravyj) + 'px');
        }
      });
      return { stranica: de.scrollWidth - de.clientWidth, vylezli: vylezli.slice(0, 3) };
    });
    check('окно ' + shirina + 'px: ничего не вылезает за край',
      uzko.stranica <= 1 && uzko.vylezli.length === 0,
      'страница +' + uzko.stranica + 'px; ' + (uzko.vylezli.join('; ') || 'блоки в порядке'));
  }
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.waitForTimeout(160);

  check('в консоли браузера нет ошибок', errors.length === 0, errors.slice(0, 2).join(' | '));

  await page.screenshot({ path: '/tmp/bx-app-ui-stand.png', fullPage: true });
  console.log('\nснимок: /tmp/bx-app-ui-stand.png');

  await browser.close();
  if (failures.length) { console.log('\nПРОВАЛ: ' + failures.length); process.exit(1); }
  console.log('\nстиль на месте: все проверки пройдены');
})().catch((e) => { console.error('сценарий упал: ' + e.message); process.exit(1); });
