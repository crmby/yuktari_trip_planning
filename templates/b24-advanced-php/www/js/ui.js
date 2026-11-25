/**
 * Готовые куски интерфейса: сообщить, спросить, показать состояние, сходить в API.
 *
 * Зачем в шаблоне. Браузерные alert/confirm/prompt в приложении запрещены — в
 * iframe они подвешивают не только приложение, а весь портал целиком, и человек
 * идёт перезагружать вкладку. Но запретить, не дав замену, значит получить их
 * обратно в первом же проекте. Здесь замена: toast() — сообщить, confirmBox() —
 * спросить, showState() — объяснить, что происходит на пустом экране.
 */
(function (global) {
  'use strict';

  // resizeWindow растягивает iframe по высоте контента. position:fixed тогда
  // привязан ко всему высокому iframe, а не к видимой части портала. Перед
  // показом overlay возвращаем родительское окно к началу, где toast/modal
  // закреплены CSS; вне портала и в слайдере вызов безопасно игнорируется.
  function revealOverlay() {
    try {
      if (global.BX24 && typeof global.BX24.scrollParentWindow === 'function') {
        global.BX24.scrollParentWindow(0);
      }
    } catch (e) { /* интерфейс всё равно показываем */ }
  }

  /** Удалить overlay после exit-анимации; при reduced-motion — сразу. */
  function motionReduced() {
    try {
      return typeof global.matchMedia === 'function'
        && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { return false; }
  }

  function motionRemove(node, className, done) {
    if (!node) { if (done) done(); return; }
    var finished = false;
    var timer = null;
    function finish() {
      if (finished) return;
      finished = true;
      if (timer !== null) global.clearTimeout(timer);
      node.removeEventListener('animationend', onEnd);
      if (node.parentNode) node.parentNode.removeChild(node);
      if (done) done();
    }
    function onEnd(e) { if (e.target === node) finish(); }
    if (motionReduced()) { finish(); return; }
    node.addEventListener('animationend', onEnd);
    node.classList.add(className);
    // Страховка для старого браузера или проектного CSS без keyframes.
    timer = global.setTimeout(finish, 240);
  }

  /**
   * Поставить тосты в ВИДИМУЮ часть окна, не двигая портал (ревью 23.09.2026,
   * ux_sboev-5). Прежде каждый тост звал scrollParentWindow(0): человек, нажавший
   * «Сохранить» внизу длинной формы, вместе с «Сохранено» улетал в начало страницы.
   * IntersectionObserver и в чужом iframe отдаёт пересечение с окном портала — по
   * нему и ставим контейнер. Нет наблюдателя — прежний путь: вернуть портал к началу.
   */
  function placeToasts(box) {
    var IO = global.IntersectionObserver;
    var root = document.body;
    if (typeof IO !== 'function' || !root) { revealOverlay(); return; }
    try {
      var io = new IO(function (entries) {
        io.disconnect();
        var e = entries && entries[0];
        var top = e && e.isIntersecting && e.intersectionRect ? Number(e.intersectionRect.top) : 0;
        if (!isFinite(top) || top <= 0) return;   // видно начало окна — fixed справляется сам
        box.style.position = 'absolute';
        // UI-геометрия: отступ контейнера в пикселях, не дата и не деньги.
        box.style.top = Math.round(top + 16) + 'px';
      });
      io.observe(root);
    } catch (err) { revealOverlay(); }
  }

  /** Сообщить человеку, ничего не спрашивая. Само исчезает. */
  function toast(text, kind) {
    var box = document.getElementById('toasts');
    if (!box) {
      box = document.createElement('div');
      box.id = 'toasts';
      document.body.appendChild(box);
    }
    placeToasts(box);
    // Область живых сообщений: без неё экранный диктор не произносит ни одного
    // тоста — человек видит «Готово», а незрячий не слышит ничего (находка
    // ревью 08.08.2026, BXREV-022). Ставим КАЖДЫЙ раз, а не только при
    // создании: контейнер бывает уже в разметке страницы, и тогда атрибутов на
    // нём нет (поймано живым прогоном стенда).
    box.setAttribute('aria-live', 'polite');
    box.setAttribute('aria-atomic', 'true');
    var el = document.createElement('div');
    el.className = 'toast' + (kind ? ' toast-' + kind : '');
    // Ошибку читаем немедленно, остальное — в порядке очереди: перебивать
    // человека ради «Сохранено» не нужно, а вот отказ он обязан услышать сразу.
    el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    el.textContent = String(text);
    box.appendChild(el);
    // Ошибку и предупреждение держим дольше: их читают внимательнее, часто
    // переписывают код обращения, а предупреждение вдобавок объясняет, почему
    // «успех» на самом деле ещё ничего не значит.
    setTimeout(function () { motionRemove(el, 'is-leaving'); },
      (kind === 'error' || kind === 'warn') ? 8000 : 4000);
    return el;
  }

  /**
   * ЗАПРЕТ ПРОКРУТКИ ФОНА — со счётчиком, а не флажком.
   *
   * Флажок «поставил класс / снял класс» ломается, как только окон становится
   * два (диалог поверх панели): закрылось верхнее — прокрутка вернулась, хотя
   * нижнее ещё открыто. Считаем открытые окна и снимаем запрет на последнем.
   */
  var scrollLocks = 0;
  function scrollLock(on) {
    scrollLocks = Math.max(0, scrollLocks + (on ? 1 : -1));
    document.body.classList.toggle('modal-open', scrollLocks > 0);
  }

  // ── Очередь окон: на экране всегда ровно одно ───────────────────────────
  //
  // Кнопка спрашивает «точно?» ДО того, как станет занятой (занятой её делает
  // уже ui.action, после ответа человека). Значит второе нажатие — дребезг
  // мыши, повторное событие, нетерпеливый двойной клик — задаёт тот же вопрос
  // ещё раз. Раньше это рисовало ВТОРОЙ overlay поверх первого с теми же id:
  // человек отвечал, окно закрывалось, и под ним обнаруживалось точно такое же,
  // а первый вопрос так и оставался без ответа (находка ревью 09.08.2026).
  //
  // Вопросы становятся в очередь и показываются ПО ОДНОМУ, каждый со своим
  // ответом. Склеиваются в один вопрос только вызовы с одинаковым `key` — это
  // и есть дребезг одной и той же кнопки: ui.confirmBox({key: 'acl-del-42', …}).
  var modalSeq = 0;
  var modalOchered = [];
  var modalTekushiy = null;

  function modalPodpis(opts) {
    // Склеиваем ТОЛЬКО по явному ключу. По тексту вопроса было бы опасно: в
    // списке он одинаков у всех строк («Убрать доступ? Сотрудник сразу
    // перестанет открывать приложение»), и склейка по тексту схлопнула бы
    // снятие доступа у ДВУХ РАЗНЫХ сотрудников в один вопрос — человек
    // подтверждает одно, а происходит два. Без ключа вопросы просто встают в
    // очередь и каждый получает свой ответ: два быстрых клика по разным
    // строкам дадут два вопроса подряд, и это безопасный исход.
    return opts.key ? 'key:' + String(opts.key) : null;
  }

  function modalDalshe() {
    if (modalTekushiy || !modalOchered.length) return;
    var zapis = modalOchered.shift();
    modalTekushiy = zapis;
    pokazatDialog(zapis.opts, function (answer) {
      modalTekushiy = null;
      zapis.resolve(answer);
      modalDalshe();
    });
  }

  /**
   * Спросить «точно?» и дождаться ответа.
   *
   * @returns {Promise<boolean>} true — согласился.
   */
  function confirmBox(opts) {
    opts = opts || {};
    var podpis = modalPodpis(opts);
    // Без ключа НИЧЕГО не склеиваем: сравнение двух null объединило бы два
    // разных вопроса в один — ровно то, от чего ключ и заведён.
    if (podpis !== null) {
      var uzhe = (modalTekushiy && modalTekushiy.podpis === podpis) ? modalTekushiy : null;
      for (var i = 0; !uzhe && i < modalOchered.length; i++) {
        if (modalOchered[i].podpis === podpis) uzhe = modalOchered[i];
      }
      if (uzhe) return uzhe.promise;
    }

    var zapis = { podpis: podpis, opts: opts, resolve: null, promise: null };
    zapis.promise = new Promise(function (resolve) { zapis.resolve = resolve; });
    modalOchered.push(zapis);
    modalDalshe();
    return zapis.promise;
  }

  /** Нарисовать окно и позвать done(ответ) ровно один раз. */
  function pokazatDialog(opts, done) {
    revealOverlay();
    // Свой номер у каждого окна: с общим id="modal-title" второе окно делает
    // ссылку aria-labelledby неоднозначной, и диктор читает заголовок ЧУЖОГО.
    var n = ++modalSeq;
    var idTitle = 'modal-title-' + n;
    var idText  = 'modal-text-' + n;

    var back = document.createElement('div');
    back.className = 'modal-back';
    back.innerHTML =
      // Заголовок и текст обязаны быть НАЗВАНЫ диалогу: иначе диктор
      // объявляет «диалог» и замолкает, а человек не знает, о чём его спросили.
      '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="' + idTitle +
        '" aria-describedby="' + idText + '">' +
        '<h2 id="' + idTitle + '"></h2><p id="' + idText + '"></p>' +
        '<div class="modal-actions">' +
          '<button class="btn" data-no></button>' +
          '<button class="btn" data-yes></button>' +
        '</div>' +
      '</div>';

    back.querySelector('h2').textContent = opts.title || 'Подтвердите действие';
    back.querySelector('p').textContent  = opts.text  || '';
    var noBtn  = back.querySelector('[data-no]');
    var yesBtn = back.querySelector('[data-yes]');
    noBtn.textContent  = opts.cancelText || 'Отмена';
    yesBtn.textContent = opts.okText || 'Продолжить';
    yesBtn.className   = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');

    // Куда вернуть фокус после закрытия: без этого человек с клавиатуры
    // оказывается в начале страницы и ищет, где он был.
    var returnFocus = document.activeElement;
    var zakryto = false;

    function close(answer) {
      // Закрыть можно четырьмя способами сразу (кнопка, Escape, клик мимо,
      // Enter) — второй заход снял бы чужой запрет прокрутки и ответил дважды.
      if (zakryto) return;
      zakryto = true;
      document.removeEventListener('keydown', onKey);
      motionRemove(back, 'is-leaving', function () {
        scrollLock(false);
        // Возвращаем фокус туда, откуда пришли, — но только если элемент ещё на
        // странице: после перерисовки списка кнопки, открывшей диалог, может уже
        // не быть, и фокусировать нечего.
        if (returnFocus && returnFocus.focus && document.contains(returnFocus)) returnFocus.focus();
        done(answer);
      });
    }
    function onKey(e) {
      if (e.key === 'Escape') { close(false); return; }
      if (e.key === 'Enter') {
        // Enter подтверждает ТО, ЧТО ВЫБРАНО. Раньше он всегда отвечал «да»:
        // человек переводил фокус на «Отмена», нажимал Enter — и действие
        // (например, снятие доступа) всё равно выполнялось.
        if (document.activeElement === noBtn) { close(false); return; }
        close(true);
        return;
      }
      // Фокус не выпускаем из окна: Tab по странице за спиной диалога уводит
      // человека к кнопкам, которых он сейчас не видит.
      if (e.key === 'Tab') {
        var first = noBtn, last = yesBtn;
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }

    noBtn.onclick  = function () { close(false); };
    yesBtn.onclick = function () { close(true); };
    // Клик мимо окна = отказ: так ведут себя все привычные диалоги.
    back.onclick = function (e) { if (e.target === back) close(false); };
    document.addEventListener('keydown', onKey);

    document.body.appendChild(back);
    scrollLock(true);
    yesBtn.focus();
  }

  /**
   * Состояние экрана вместо пустоты: загрузка, пусто, ошибка.
   *
   * Пустой экран без объяснения — самая частая жалоба «ничего не работает»:
   * человек не знает, идёт ли загрузка, нет ли данных или всё сломалось.
   */
  function showState(target, state, opts) {
    opts = opts || {};
    var el = typeof target === 'string' ? document.querySelector(target) : target;
    if (!el) return;

    // Состояние можно показывать ПРЯМО В ТАБЛИЦЕ. Раньше единственным способом
    // было отдать сюда родителя таблицы — и тогда сама таблица стиралась вместе с
    // содержимым: кнопка «Повторить» после этого искала исчезнувший tbody и
    // роняла экран целиком. Теперь для tbody/table состояние заворачивается в
    // строку, и разметка остаётся на месте.
    function put(html) {
      var tag = (el.tagName || '').toUpperCase();
      if (tag === 'TBODY' || tag === 'TABLE') {
        var cols = 1;
        var head = el.closest ? el.closest('table') : null;
        if (head) {
          var hr = head.querySelector('thead tr');
          if (hr) cols = hr.children.length || 1;
        }
        el.innerHTML = '<tr><td colspan="' + cols + '">' + html + '</td></tr>';
      } else {
        el.innerHTML = html;
      }
      var btn = el.querySelector('[data-retry]');
      if (btn && opts.onRetry) btn.onclick = opts.onRetry;
    }

    if (state === 'loading') {
      // aria-busy + живая область: диктор говорит «загружается», а не молчит,
      // пока на экране крутится кружок.
      put('<div class="state" role="status" aria-live="polite" aria-busy="true">'
        + '<div class="spinner" aria-hidden="true"></div>' + esc(opts.text || 'Загружаем…') + '</div>');
      return;
    }
    if (state === 'empty') {
      put('<div class="state" role="status" aria-live="polite" aria-busy="false"><b>'
        + esc(opts.title || 'Пока пусто') + '</b>' + esc(opts.text || '') + '</div>');
      return;
    }
    if (state === 'error') {
      var html = '<div class="state" role="alert" aria-live="assertive" aria-busy="false"><b>'
        + esc(opts.title || 'Не удалось загрузить') + '</b>' +
        esc(opts.text || 'Попробуйте обновить страницу.');
      if (opts.ref) html += '<div style="margin-top:8px;font-family:var(--font-mono);font-size:12px;color:var(--text-mute)">код обращения: ' + esc(opts.ref) + '</div>';
      // Пропуск кончился — повтор того же запроса не поможет никогда: новый
      // пропуск выдаёт только открытие приложения заново (ux_sboev-7).
      if (sessionLost) html += '<button class="btn btn-sm" data-retry>Открыть заново</button>';
      else if (opts.onRetry) html += '<button class="btn btn-sm" data-retry>Повторить</button>';
      put(html + '</div>');
      if (sessionLost) {
        var again = el.querySelector('[data-retry]');
        if (again) again.onclick = reopenApp;
      }
    }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * Запрос к своему API.
   *
   * Пропуск идёт ЗАГОЛОВКОМ, а не cookie: cookie к запросу приложит и чужой
   * сайт, а заголовок со стороны не поставить. Ошибку показываем ту, что
   * прислал сервер, — там человеческий текст и код обращения.
   */
  var API_TIMEOUT_MS = 45000;

  // Пропуск приложения кончился (8 часов или сняли доступ). Сервер отвечает общим
  // «Доступ только из Bitrix24» — внутри Битрикс24 это читается как бессмыслица,
  // а «Повторить» повторяет тот же отказ вечно (ревью 23.09.2026, ux_sboev-7).
  var sessionLost = false;
  var SESSION_LOST_TEXT = 'Сеанс работы с приложением закончился: пропуск действует 8 часов '
    + 'или доступ только что изменили. Откройте приложение заново.';

  /**
   * Индикатор связи и версия выпуска в шапке (ревью 23.09.2026, ux_sboev-6):
   * узлы #conn и #app-version стояли в разметке заглушками, и ничто их не
   * заполняло. Любой HTTP-ответ — сервер на связи; обрыв сети — нет. Версию
   * отдаёт сервер заголовком X-App-Release (его ставит развёрнутый выпуск).
   */
  function paintLink(online, response) {
    try {
      var conn = document.getElementById('conn');
      if (conn) {
        conn.hidden = false;
        conn.classList.toggle('is-ok', !!online);
        conn.classList.toggle('is-err', !online);
        var t = document.getElementById('conn-text');
        if (t) t.textContent = online ? 'на связи' : 'нет связи с сервером';
      }
      var rel = response && response.headers && typeof response.headers.get === 'function'
        ? response.headers.get('X-App-Release') : '';
      var ver = document.getElementById('app-version');
      if (ver && rel) ver.textContent = 'версия ' + String(rel);
    } catch (e) { /* шапка — подсказка, её сбой не должен ронять запрос */ }
  }

  /** Открыть приложение заново: портал сам выдаст новый пропуск. */
  function reopenApp() {
    try {
      if (global.BX24 && typeof global.BX24.reloadWindow === 'function') { global.BX24.reloadWindow(); return; }
    } catch (e) { /* ниже — перезагрузка своими силами */ }
    try { global.location.reload(); } catch (e) { /* вне браузера перезагружать нечего */ }
  }

  function api(method, path, body, options) {
    options = options || {};
    var headers = { 'Content-Type': 'application/json' };
    if (global.APP_SESSION) headers['X-App-Token'] = global.APP_SESSION;

    var timeoutMs = Number(options.timeoutMs);
    if (!isFinite(timeoutMs) || timeoutMs <= 0) timeoutMs = API_TIMEOUT_MS;
    var timeoutText = 'Сервер не ответил за ' + Math.ceil(timeoutMs / 1000) + ' секунд';

    // Один контроллер объединяет отмену вызывающего и общий таймаут. Даже в
    // старом браузере без AbortController Promise.race снимет вечную крутилку;
    // сам сетевой запрос там завершится уже в фоне и UI не перерисует.
    var controller = typeof global.AbortController === 'function'
      ? new global.AbortController()
      : null;
    var externalSignal = options.signal || null;
    var externalAbort = null;
    if (controller && externalSignal) {
      externalAbort = function () { controller.abort(); };
      if (externalSignal.aborted) controller.abort();
      else externalSignal.addEventListener('abort', externalAbort, { once: true });
    }

    var timedOut = false;
    var timer = null;
    var timeout = new Promise(function (_, reject) {
      timer = setTimeout(function () {
        timedOut = true;
        if (controller) controller.abort();
        var err = new Error(timeoutText);
        err.code = 'timeout';
        reject(err);
      }, timeoutMs);
    });

    var request = Promise.resolve().then(function () {
      // Внешняя отмена могла прийти между вызовом api() и этой микрозадачей.
      // fetch обычно сам отклоняет уже отменённый signal, но не полагаемся на
      // это: вызывающий в любом случае должен сразу получить `aborted`.
      if ((controller && controller.signal.aborted) ||
          (!controller && externalSignal && externalSignal.aborted)) {
        var cancelled = new Error('Запрос отменён');
        cancelled.code = 'aborted';
        throw cancelled;
      }
      return fetch(path, {
        method: method,
        headers: headers,
        body: body == null ? undefined : JSON.stringify(body),
        signal: controller ? controller.signal : externalSignal || undefined
      });
    }).then(function (r) {
      paintLink(true, r);
      return r.json().catch(function () { return { ok: false, error: 'Сервер ответил не по формату' }; })
        .then(function (data) {
          if (!r.ok || data.ok === false) {
            var err = new Error(data.code === 'no_session' ? SESSION_LOST_TEXT : (data.error || ('Ошибка ' + r.status)));
            err.code = data.code;
            if (data.code === 'no_session') { sessionLost = true; err.reload = true; }
            err.ref  = data.ref;
            // Полезная нагрузка отказа тоже нужна экрану: по ней форма
            // подсвечивает КОНКРЕТНЫЕ поля, а «задача уже идёт» показывает саму
            // задачу вместо ошибки. Без неё эти ветки молча не работали:
            // сообщение приходило, а данные для него терялись здесь.
            err.data = data;
            throw err;
          }
          return data;
        });
    });

    function cleanup() {
      if (timer !== null) clearTimeout(timer);
      if (externalSignal && externalAbort) externalSignal.removeEventListener('abort', externalAbort);
    }

    return Promise.race([request, timeout]).then(function (data) {
      cleanup();
      return data;
    }, function (err) {
      cleanup();
      if (timedOut) {
        var timeoutErr = new Error(timeoutText);
        timeoutErr.code = 'timeout';
        throw timeoutErr;
      }
      if (err && err.name === 'AbortError') {
        var abortErr = new Error('Запрос отменён');
        abortErr.code = 'aborted';
        throw abortErr;
      }
      if (err && (err.name === 'TypeError' || err.message === 'Failed to fetch')) {
        paintLink(false, null);
        var networkErr = new Error('Не удалось связаться с сервером. Проверьте соединение и попробуйте ещё раз.');
        networkErr.code = 'network';
        throw networkErr;
      }
      throw err;
    });
  }

  /** Показать ошибку запроса человеку — одинаково во всём приложении. */
  function apiFailToast(e) {
    toast((e && e.message ? e.message : 'Не удалось выполнить') +
      (e && e.ref ? ' (код обращения ' + e.ref + ')' : ''), 'error');
  }

  global.ui = { toast: toast, confirmBox: confirmBox, showState: showState, esc: esc,
                api: api, apiFailToast: apiFailToast, reopenApp: reopenApp };
})(window);
