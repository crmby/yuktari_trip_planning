/*
 * СВОИ КОНТРОЛЫ вместо системных.
 *
 * ЗАЧЕМ. Нативные select, number, date и search рисует операционная система:
 * внутри тёмного портала список всплывает белым прямоугольником с чужим
 * шрифтом, у числа появляется спиннер (на портале его задевают случайно и
 * молча меняют значение), календарь везде свой. Человек читает это как
 * «приложение недоделали», хотя код исправен.
 *
 * ЧТО ЗДЕСЬ. Готовые контролы одного вида для всех приложений компании:
 *   ui.select(...)      — раскрывающийся список (с поиском, если пунктов много)
 *   ui.number(...)      — число с кнопками «минус/плюс» и границами
 *   ui.search(...)      — поиск с задержкой и своим крестиком
 *   ui.dateField(...)   — дата со своим календарём (понедельник первым)
 *   ui.switchBox(...)   — переключатель «включено/выключено»
 *   ui.chipGroup(...)   — выбор одного значения из нескольких чипами
 *   ui.pickPeople(...)  — выбор сотрудников И отделов, сразу нескольких
 *
 * ОБЩЕЕ ПРАВИЛО. Каждый возвращает объект с методами get()/set()/destroy() и
 * сам убирает за собой обработчики на документе: контрол, оставивший слушателя
 * после удаления узла, продолжает ловить клики и мешает следующему экрану.
 */
(function (global) {
  'use strict';

  var ui = global.ui = global.ui || {};

  // Сквозной счётчик для id списков: без своего id нечем связать кнопку со
  // списком (aria-controls) и назвать курсор (aria-activedescendant).
  var selectSeq = 0;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  /** Куда монтировать: узел или строка-селектор. Ошибку называем сразу. */
  function host(target, who) {
    var node = typeof target === 'string' ? document.querySelector(target) : target;
    if (!node) throw new Error(who + ': не найден узел для монтирования (' + target + ')');
    return node;
  }

  // Сквозной счётчик для своих id: два контрола на экране не должны делить один
  // id, иначе ссылка aria-describedby ведёт в чужое поле.
  var ctlSeq = 0;

  /**
   * ОБЩИЙ КОНТРАКТ ДОСТУПНОСТИ. Одинаков у всех готовых контролов.
   *
   * ЗАЧЕМ. Контролы нарисованы кнопками и div-ами. Глазами человек видит слева
   * подпись «Права», а рядом список; для браузера и для диктора между ними нет
   * никакой связи — читается «кнопка, Смотреть и менять», и в форме из восьми
   * полей неясно, о каком поле речь. Так же и с отказом: красная строчка под
   * полем существует только для зрячего, пока поле не помечено `aria-invalid`
   * и не связано с текстом причины (находка ревью 09.08.2026).
   *
   * ЧТО ПРИНИМАЕТ КАЖДЫЙ КОНТРОЛ:
   *   label       — имя строкой («Права»);
   *   labelledBy  — id узла с подписью; годится, когда подпись уже есть на
   *                 экране: тогда имя не дублируется и меняется вместе с ней;
   *   describedBy — id пояснения под полем (подсказка, формат, ограничение).
   *
   * ЧТО ВОЗВРАЩАЕТ: setError(текст) — объявить отказ, setError('') — снять.
   *
   * @param opts  параметры контрола
   * @param root  корневой узел контрола (туда кладётся текст ошибки)
   * @param node  узел, который РЕАЛЬНО получает фокус: имя и отметка об
   *              ошибке обязаны стоять на нём, а не на обёртке
   * @param who   имя контрола для сообщения разработчику
   */
  function a11y(opts, root, node, who) {
    var n = ++ctlSeq;
    if (opts.labelledBy) node.setAttribute('aria-labelledby', String(opts.labelledBy));
    else if (opts.label) node.setAttribute('aria-label', String(opts.label));
    else if (opts.placeholder) node.setAttribute('aria-label', String(opts.placeholder));
    else if (!node.getAttribute('aria-label') && !node.getAttribute('aria-labelledby')) {
      // Не бросаем исключение: забытая подпись не должна гасить человеку весь
      // экран. Но и молчать нельзя — иначе о ней узнают только от того, кто
      // работает на слух. Живой прогон стенда ловит это машинно.
      if (global.console && console.warn) {
        console.warn(who + ': контролу не дали ни label, ни labelledBy — ' +
                     'экранный диктор прочитает его без имени');
      }
    }

    var opisaniya = opts.describedBy ? String(opts.describedBy).split(/\s+/).filter(Boolean) : [];
    var errId = 'ui-err-' + n;
    var errNode = null;

    function opisat() {
      var list = opisaniya.slice();
      if (errNode) list.push(errId);
      if (list.length) node.setAttribute('aria-describedby', list.join(' '));
      else node.removeAttribute('aria-describedby');
    }
    opisat();

    return {
      setError: function (text) {
        if (!text) {
          if (errNode) { errNode.remove(); errNode = null; }
          node.removeAttribute('aria-invalid');
          opisat();
          return;
        }
        if (!errNode) {
          errNode = el('div', 'field-err');
          errNode.id = errId;
          // role="alert" — причину человек обязан УСЛЫШАТЬ сразу, а не найти
          // глазами: он только что нажал «Сохранить» и ждёт результата.
          errNode.setAttribute('role', 'alert');
          root.appendChild(errNode);
        }
        errNode.textContent = String(text);
        node.setAttribute('aria-invalid', 'true');
        opisat();
      },
      nomer: n,
    };
  }

  /**
   * Тот же контракт для СВОЕЙ разметки — обычного input/textarea в форме.
   *
   * Форма настроек рисует текстовые поля сама, и без этого у них не было ни
   * связи с подписью, ни объявляемого отказа: красная строка под полем видна
   * только зрячему.
   *
   *   var d = ui.fieldA11y(input, {labelledBy: 'l-key', describedBy: 'h-key'}, slot);
   *   d.setError('Слишком большое значение');   d.setError('');
   */
  ui.fieldA11y = function (node, opts, root) {
    return a11y(opts || {}, root || node.parentNode || node, node, 'ui.fieldA11y');
  };

  /**
   * Закрытие по клику вне и по Esc — одинаково у всех раскрывающихся контролов.
   * Слушатели вешаются ТОЛЬКО на время раскрытия: постоянные обработчики от
   * десятка контролов на экране заметно тормозят прокрутку.
   */
  function autoClose(root, onClose) {
    function onDoc(e) { if (!root.contains(e.target)) fire(); }
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); fire(); } }
    // Возвращаем именно СНЯТИЕ слушателей, а не «закрыть»: контрол закрывают и
    // изнутри (выбрали пункт), и тогда снимать обработчики некому — они
    // остаются на документе и продолжают ловить клики следующего экрана.
    function detach() {
      document.removeEventListener('mousedown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    }
    function fire() { detach(); onClose(); }
    document.addEventListener('mousedown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    return detach;
  }

  // ── Раскрывающийся список ─────────────────────────────────────────────
  /**
   * ui.select(target, {options, value, placeholder, searchFrom, onChange})
   * options: [{value, label, sub}] либо ['строка', …]
   * searchFrom: с какого числа пунктов показывать строку поиска (по умолчанию 8)
   */
  ui.select = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.select');
    var items = (opts.options || []).map(function (o) {
      return typeof o === 'object' ? o : { value: o, label: String(o) };
    });
    var value = opts.value != null ? opts.value : (items[0] ? items[0].value : null);
    var searchFrom = opts.searchFrom == null ? 8 : opts.searchFrom;
    var open = false, closeAuto = null, cursor = -1, filter = '';

    var root = el('div', 'ui-select');
    var btn = el('button', 'ui-select-btn');
    btn.type = 'button';
    var valueEl = el('span', 'ui-select-value');
    var caret = el('span', 'ui-select-caret');
    btn.appendChild(valueEl); btn.appendChild(caret);
    root.appendChild(btn);
    mount.innerHTML = '';
    mount.appendChild(root);

    function current() {
      for (var i = 0; i < items.length; i++) if (items[i].value === value) return items[i];
      return null;
    }
    function paintValue() {
      var it = current();
      valueEl.textContent = it ? it.label : (opts.placeholder || 'Не выбрано');
      valueEl.classList.toggle('is-placeholder', !it);
    }
    function visible() {
      if (!filter) return items;
      var q = filter.toLowerCase();
      return items.filter(function (i) { return String(i.label).toLowerCase().indexOf(q) !== -1; });
    }
    /**
     * Назвать клавиатурный курсор ТОМУ узлу, который сейчас в фокусе: диктор
     * читает aria-activedescendant только у активного элемента. В списке от
     * восьми пунктов фокус уходит в поле поиска, а подсказка оставалась на
     * кнопке — то есть у длинного списка, где стрелки и нужны, курсор не
     * назывался вовсе (находка ревью 09.08.2026).
     */
    function nameCursor(list, optId) {
      var inp = list.querySelector('.ui-select-search input');
      var owner = inp || btn;
      if (optId) owner.setAttribute('aria-activedescendant', optId);
      else owner.removeAttribute('aria-activedescendant');
      // Со второго узла подсказку снимаем: два разных курсора в одной разметке
      // диктор читает как рассинхрон, а после выбора она ещё и повиснет на id,
      // которого больше нет.
      if (inp) btn.removeAttribute('aria-activedescendant');
    }
    function paintList(list) {
      // Экранный диктор понимает список только по ролям: без них он читает
      // «кнопка», и человек не знает ни что список открыт, ни что в нём выбрано
      // (находка ревью 08.08.2026, BXREV-022). Разметку не меняем — добавляем
      // смысл, который у зрячего и так есть.
      list.setAttribute('role', 'listbox');
      list.id = list.id || ('ui-select-list-' + (++selectSeq));
      btn.setAttribute('aria-controls', list.id);
      // ПОЛЕ ПОИСКА ПЕРЕЖИВАЕТ ПЕРЕРИСОВКУ. Здесь стояло `list.innerHTML = ''`, и
      // поле рождалось заново на КАЖДЫЙ набранный символ: старый узел вместе с
      // фокусом исчезал, новый получал фокус только следующим тиком, а каретка
      // вставала в конец строки. Правка запроса в середине превращалась в кашу —
      // набрал «ябр», допечатал начало «окт», получил «оябркт» (проверено живым
      // браузером 09.08.2026). Перерисовываем только пункты.
      var box = list.querySelector('.ui-select-search');
      // Раз поле переживает перерисовку, его надо и убирать: setOptions умеет
      // укоротить список прямо при раскрытом окне, и оставленное поле продолжало
      // бы прятать пункты по старому запросу — с виду просто «список опустел».
      if (box && items.length < searchFrom) { box.remove(); box = null; filter = ''; }
      if (items.length >= searchFrom && !box) {
        box = el('div', 'ui-select-search');
        var inp = el('input', 'input');
        inp.placeholder = 'Поиск'; inp.value = filter;
        inp.style.margin = '2px 2px 6px';
        inp.style.width = 'calc(100% - 4px)';
        inp.setAttribute('aria-label', 'Поиск по списку');
        inp.setAttribute('aria-controls', list.id);
        inp.setAttribute('aria-autocomplete', 'list');
        inp.addEventListener('input', function () { filter = inp.value; cursor = -1; paintList(list); });
        inp.addEventListener('click', function (e) { e.stopPropagation(); });
        box.appendChild(inp); list.appendChild(box);
        setTimeout(function () { inp.focus(); }, 0);
      }
      [].slice.call(list.children).forEach(function (n) { if (n !== box) n.remove(); });
      var vis = visible();
      if (!vis.length) {
        // Пустую выдачу надо СЛЫШАТЬ, а не только видеть: без role="status"
        // диктор не объявляет исчезновение пунктов, и человек, набравший запрос
        // с опечаткой, получает тишину вместо причины (находка ревью 09.08.2026).
        var empty = el('div', 'ui-select-empty', 'Ничего не нашлось');
        empty.setAttribute('role', 'status');
        list.appendChild(empty);
        nameCursor(list, null);
        return;
      }
      var cursorId = null;
      vis.forEach(function (it, idx) {
        var o = el('button', 'ui-select-opt' + (it.value === value ? ' is-selected' : '') + (idx === cursor ? ' is-cursor' : ''));
        o.type = 'button';
        o.setAttribute('role', 'option');
        o.setAttribute('aria-selected', it.value === value ? 'true' : 'false');
        o.id = list.id + '-opt-' + idx;
        // Клавиатурный курсор обязан быть НАЗВАН: без aria-activedescendant
        // диктор молчит при переходе стрелками — визуально подсветка есть, на
        // слух её нет.
        if (idx === cursor) cursorId = o.id;
        o.appendChild(el('span', '', it.label));
        if (it.sub) o.appendChild(el('span', 'picker-sub', it.sub));
        o.addEventListener('click', function () { pick(it.value); });
        list.appendChild(o);
      });
      nameCursor(list, cursorId);
    }
    function pick(v) {
      var changed = v !== value;
      value = v; paintValue(); hide();
      if (changed && opts.onChange) opts.onChange(v, current());
    }
    function show() {
      if (open) return;
      open = true; filter = ''; cursor = -1;
      btn.setAttribute('aria-expanded', 'true');
      var list = el('div', 'ui-select-list');
      root.appendChild(list);
      paintList(list);
      root.classList.add('is-open');
      closeAuto = autoClose(root, hide);
    }
    function hide() {
      if (!open) return;
      open = false;
      btn.setAttribute('aria-expanded', 'false');
      btn.removeAttribute('aria-activedescendant');
      // Список удаляется вместе с закрытием, поэтому ссылка на него у кнопки
      // тоже снимается: aria-controls, указывающий на несуществующий элемент,
      // экранный диктор читает как сломанную разметку.
      btn.removeAttribute('aria-controls');
      root.classList.remove('is-open');
      var list = root.querySelector('.ui-select-list');
      if (list) list.remove();
      // Слушатели документа СНИМАЕМ. Здесь стояло `c === hide || null` — сравнение
      // вместо вызова, то есть строка не делала ничего: обработчик Escape (он в
      // capture-фазе и глушит событие) оставался на документе навсегда, и после
      // первого же выбора из списка Escape переставал работать во всём
      // приложении — диалог подтверждения им было не закрыть.
      if (closeAuto) { var c = closeAuto; closeAuto = null; c(); }
    }
    btn.addEventListener('click', function () { open ? hide() : show(); });
    // Клавиатура: список должен работать без мыши — иначе контрол недоступен
    // тем, кто ходит по форме табом.
    //
    // Слушаем на КОРНЕ, а не на кнопке. В списке от восьми пунктов появляется
    // поле поиска и фокус уходит в него — на кнопке события клавиш больше не
    // возникают, и стрелки с Enter переставали работать ровно там, где список
    // длинный и нужны они больше всего (находка ревью 09.08.2026). Корень
    // содержит и кнопку, и поле поиска, поэтому ловит оба случая.
    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open) { show(); return; }
        var vis = visible();
        cursor = e.key === 'ArrowDown' ? Math.min(cursor + 1, vis.length - 1) : Math.max(cursor - 1, 0);
        paintList(root.querySelector('.ui-select-list'));
      } else if (e.key === 'Enter' && open) {
        e.preventDefault();
        var v = visible();
        if (cursor >= 0 && v[cursor]) pick(v[cursor].value);
      }
    });

    // Роль и состояние выставляем СРАЗУ, а не при первом открытии: закрытый
    // список тоже должен читаться как список, а не как обычная кнопка.
    btn.setAttribute('role', 'combobox');
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    var dost = a11y(opts, root, btn, 'ui.select');
    paintValue();
    return {
      get: function () { return value; },
      set: function (v) { value = v; paintValue(); },
      setError: dost.setError,
      setOptions: function (list) {
        items = (list || []).map(function (o) { return typeof o === 'object' ? o : { value: o, label: String(o) }; });
        if (!current()) value = items[0] ? items[0].value : null;
        // Асинхронный ответ может прийти при открытом списке: обновляем и DOM,
        // иначе старый видимый пункт остаётся кликабельным при новом value.
        filter = '';
        cursor = -1;
        if (open) {
          var openList = root.querySelector('.ui-select-list');
          if (openList) paintList(openList);
        }
        paintValue();
      },
      el: root,
      destroy: function () { hide(); root.remove(); },
    };
  };

  // ── Число ─────────────────────────────────────────────────────────────
  /** ui.number(target, {value, min, max, step, onChange}) */
  ui.number = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.number');
    var min = opts.min == null ? -Infinity : opts.min;
    var max = opts.max == null ? Infinity : opts.max;
    var step = opts.step || 1;
    var value = clamp(opts.value == null ? min === -Infinity ? 0 : min : opts.value);

    var root = el('div', 'ui-number');
    var less = el('button', '', '−'); less.type = 'button'; less.title = 'Меньше';
    var inp = el('input'); inp.type = 'text'; inp.inputMode = 'numeric';
    var more = el('button', '', '+'); more.type = 'button'; more.title = 'Больше';
    // «−» и «+» на слух — это «минус» и «плюс» без указания, чего именно. Имя
    // даём словом: title диктор читает не всегда и не первым.
    less.setAttribute('aria-label', 'Меньше на ' + step);
    more.setAttribute('aria-label', 'Больше на ' + step);
    root.appendChild(less); root.appendChild(inp); root.appendChild(more);
    mount.innerHTML = ''; mount.appendChild(root);
    // Поле числа со своими стрелками — это spinbutton: диктор объявляет и
    // текущее значение, и границы, в которые человек обязан уложиться.
    inp.setAttribute('role', 'spinbutton');
    var dost = a11y(opts, root, inp, 'ui.number');

    function clamp(v) {
      v = Number(v);
      if (!isFinite(v)) v = min === -Infinity ? 0 : min;
      return Math.min(max, Math.max(min, v));
    }
    function paint() {
      inp.value = String(value);
      less.disabled = value <= min;
      more.disabled = value >= max;
      inp.setAttribute('aria-valuenow', String(value));
      if (min !== -Infinity) inp.setAttribute('aria-valuemin', String(min));
      if (max !== Infinity) inp.setAttribute('aria-valuemax', String(max));
    }
    function set(v, notify) {
      var next = clamp(v);
      var changed = next !== value;
      value = next; paint();
      if (changed && notify && opts.onChange) opts.onChange(value);
    }
    less.addEventListener('click', function () { set(value - step, true); });
    more.addEventListener('click', function () { set(value + step, true); });
    // Набранное руками разбираем отдельно от стрелок (ревью 23.09.2026,
    // ux_sboev-4): пустое, «сто», «12,5» и число за границей прежде молча
    // превращались в границу — человек видел другое число, а на сервер уезжало
    // то, чего он не вводил. Теперь понятное принимаем (запятая = точка, пробелы
    // не считаются), непонятное и чужое оставляют прежнее значение и объясняют.
    function granitsy() {
      var ot = min === -Infinity ? null : min, doo = max === Infinity ? null : max;
      if (ot !== null && doo !== null) return 'Введите число от ' + ot + ' до ' + doo;
      if (ot !== null) return 'Введите число не меньше ' + ot;
      if (doo !== null) return 'Введите число не больше ' + doo;
      return 'Введите число';
    }
    function typed() {
      var raw = String(inp.value == null ? '' : inp.value).replace(/\s+/g, '').replace(',', '.');
      var v = raw === '' ? NaN : Number(raw);
      if (!isFinite(v) || v < min || v > max) {
        paint();
        dost.setError(granitsy());
        return;
      }
      dost.setError('');
      set(v, true);
    }
    // Правим значение по уходу из поля, а не на каждый символ: иначе «10» при
    // наборе успевает стать «1» и подставить границу.
    inp.addEventListener('blur', typed);
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); typed(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); set(value + step, true); }
      if (e.key === 'ArrowDown') { e.preventDefault(); set(value - step, true); }
    });

    paint();
    return { get: function () { return value; }, set: function (v) { set(v, false); }, el: root,
             setError: dost.setError,
             destroy: function () { root.remove(); } };
  };

  // ── Поиск ─────────────────────────────────────────────────────────────
  /** ui.search(target, {placeholder, delay, value, onInput}) */
  ui.search = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.search');
    var delay = opts.delay == null ? 250 : opts.delay;
    var timer = null;

    var root = el('label', 'ui-search');
    var icon = el('span', 'ui-search-icon');
    var inp = el('input');
    inp.type = 'text';
    inp.placeholder = opts.placeholder || 'Поиск';
    inp.value = opts.value || '';
    inp.autocomplete = 'off';
    var clear = el('button', 'ui-search-clear', '×'); clear.type = 'button'; clear.title = 'Очистить';
    // Крестик на слух — это символ «×», то есть ничего. Имя даём словом.
    clear.setAttribute('aria-label', 'Очистить поиск');
    icon.setAttribute('aria-hidden', 'true');
    root.appendChild(icon); root.appendChild(inp); root.appendChild(clear);
    mount.innerHTML = ''; mount.appendChild(root);
    inp.setAttribute('role', 'searchbox');
    var dost = a11y(opts, root, inp, 'ui.search');

    function paint() { clear.style.display = inp.value ? '' : 'none'; }
    function fire() {
      clearTimeout(timer);
      // Задержка обязательна: без неё каждый символ уходит запросом на сервер,
      // и портал отвечает отказом по частоте раньше, чем человек дописал слово.
      timer = setTimeout(function () { if (opts.onInput) opts.onInput(inp.value.trim()); }, delay);
    }
    inp.addEventListener('input', function () { paint(); fire(); });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && inp.value) { e.stopPropagation(); inp.value = ''; paint(); fire(); }
      if (e.key === 'Enter') { clearTimeout(timer); if (opts.onInput) opts.onInput(inp.value.trim()); }
    });
    clear.addEventListener('click', function () { inp.value = ''; paint(); fire(); inp.focus(); });

    paint();
    return { get: function () { return inp.value.trim(); },
             set: function (v) { inp.value = v || ''; paint(); },
             focus: function () { inp.focus(); }, el: root,
             setError: dost.setError,
             destroy: function () { clearTimeout(timer); root.remove(); } };
  };

  // ── Дата ──────────────────────────────────────────────────────────────
  var MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль',
                'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
  // Родительный падеж — для «6 августа 2026». В заголовке календаря нужен
  // именительный («август 2026»), в дате рядом с числом — родительный; одним
  // списком получается «06 август 2026», и это читается как опечатка.
  var MONTHS_OF = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
                   'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  var DOW = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
  var DOW_FULL = ['понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];

  /**
   * ui.dateField(target, {value: 'ГГГГ-ММ-ДД', min, max, placeholder, onChange})
   * Значение — всегда строка ISO: дата приложения не должна зависеть от часового
   * пояса браузера, иначе «сегодня» у портала и у человека расходится на день.
   */
  ui.dateField = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.dateField');
    var value = opts.value || '';
    var open = false, closeCal = null;
    var view = value ? parseIso(value) : todayParts();

    var root = el('div', 'ui-date');
    var btn = el('button', 'ui-date-btn'); btn.type = 'button';
    var label = el('span');
    btn.appendChild(label);
    root.appendChild(btn);
    mount.innerHTML = ''; mount.appendChild(root);

    // UI-календарь: сегодняшняя клетка сетки, не предметная дата.
    function todayParts() { var d = new Date(); return { y: d.getFullYear(), m: d.getMonth() }; }
    function parseIso(s) { return { y: Number(s.slice(0, 4)), m: Number(s.slice(5, 7)) - 1 }; }
    function iso(y, m, d) {
      return String(y) + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }
    function human(s) {
      if (!s) return opts.placeholder || 'Выберите дату';
      return s.slice(8, 10) + ' ' + MONTHS_OF[Number(s.slice(5, 7)) - 1] + ' ' + s.slice(0, 4);
    }
    function paintValue() {
      label.textContent = human(value);
      label.className = value ? '' : 'is-placeholder';
    }
    function paintCal(cal, fokusIso) {
      cal.innerHTML = '';
      var head = el('div', 'ui-cal-head');
      var prev = el('button', 'ui-cal-nav', '‹'); prev.type = 'button';
      var next = el('button', 'ui-cal-nav', '›'); next.type = 'button';
      var title = el('div', 'ui-cal-title', MONTHS[view.m] + ' ' + view.y);
      prev.setAttribute('aria-label', 'Предыдущий месяц');
      next.setAttribute('aria-label', 'Следующий месяц');
      prev.addEventListener('click', function (e) {
        e.stopPropagation();
        view.m--; if (view.m < 0) { view.m = 11; view.y--; }
        paintCal(cal);
      });
      next.addEventListener('click', function (e) {
        e.stopPropagation();
        view.m++; if (view.m > 11) { view.m = 0; view.y++; }
        paintCal(cal);
      });
      head.appendChild(prev); head.appendChild(title); head.appendChild(next);
      cal.appendChild(head);

      var grid = el('div', 'ui-cal-grid');
      DOW.forEach(function (d, i) {
        var c = el('div', 'ui-cal-dow', d);
        // «пн» диктор читает буквами. Полное название прячем в подпись, а
        // видимое сокращение убираем из речи — иначе он произносит оба.
        c.setAttribute('aria-label', DOW_FULL[i]);
        grid.appendChild(c);
      });
      // Неделя начинается с понедельника: у getDay() воскресенье нулевое,
      // и без сдвига календарь съезжает на день — заметно не сразу, а когда
      // человек уже выбрал не ту дату.
      // UI-календарь: раскладка клеток месяца.
      var first = new Date(view.y, view.m, 1);
      var shift = (first.getDay() + 6) % 7;
      // UI-календарь: сколько клеток в месяце.
      var daysInMonth = new Date(view.y, view.m + 1, 0).getDate();
      var todayIso = (function () {
        // UI-календарь: подсветка сегодняшнего числа.
        var t = new Date();
        return iso(t.getFullYear(), t.getMonth(), t.getDate());
      })();
      for (var i = 0; i < shift; i++) grid.appendChild(el('div'));
      for (var d = 1; d <= daysInMonth; d++) {
        (function (day) {
          var s = iso(view.y, view.m, day);
          var b = el('button', 'ui-cal-day', String(day)); b.type = 'button';
          // Число без месяца на слух — просто «двенадцать»: человек не понимает,
          // куда попал, особенно перелистнув месяц стрелками.
          b.setAttribute('aria-label', human(s));
          b.dataset.iso = s;
          if (s === value) { b.classList.add('is-selected'); b.setAttribute('aria-selected', 'true'); }
          else b.setAttribute('aria-selected', 'false');
          if (s === todayIso) { b.classList.add('is-today'); b.setAttribute('aria-current', 'date'); }
          if ((opts.min && s < opts.min) || (opts.max && s > opts.max)) {
            b.disabled = true; b.classList.add('is-other');
          }
          b.addEventListener('click', function () {
            value = s; paintValue(); hide();
            if (opts.onChange) opts.onChange(value);
          });
          grid.appendChild(b);
        })(d);
      }
      cal.appendChild(grid);
      // После перерисовки (перелистнули месяц стрелками) фокус обязан остаться
      // на числе, иначе клавиатура выбрасывает человека в начало страницы.
      if (fokusIso) {
        var kletka = grid.querySelector('.ui-cal-day[data-iso="' + fokusIso + '"]:not([disabled])');
        if (kletka) kletka.focus();
      }
      if (opts.clearable !== false && value) {
        var clr = el('button', 'btn btn-sm', 'Очистить'); clr.type = 'button';
        clr.style.marginTop = '8px'; clr.style.width = '100%';
        clr.addEventListener('click', function () {
          value = ''; paintValue(); hide(); if (opts.onChange) opts.onChange('');
        });
        cal.appendChild(clr);
      }
    }
    // Сдвиг клетки на N дней. Date здесь уместен — это раскладка сетки, а не
    // предметная дата: результат сразу переводится обратно в ISO.
    function sdvinut(s, dney) {
      // UI-календарь: соседняя клетка сетки.
      var d = new Date(Number(s.slice(0, 4)), Number(s.slice(5, 7)) - 1, Number(s.slice(8, 10)));
      d.setDate(d.getDate() + dney);
      return iso(d.getFullYear(), d.getMonth(), d.getDate());
    }
    /**
     * Ходьба по календарю стрелками.
     *
     * Без неё календарь с клавиатуры не работал вовсе: до нужного числа надо
     * было прожать Tab тридцать раз, а перелистнуть месяц — вернуться к
     * стрелкам вверху. Человек, который не пользуется мышью, просто не мог
     * выбрать дату (находка ревью 09.08.2026).
     */
    function onCalKey(e) {
      var cur = document.activeElement;
      if (!cur || !cur.classList || !cur.classList.contains('ui-cal-day')) return;
      var shag = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 }[e.key];
      if (!shag) return;
      e.preventDefault();
      var cel = sdvinut(cur.dataset.iso, shag);
      if ((opts.min && cel < opts.min) || (opts.max && cel > opts.max)) return;
      var m = parseIso(cel);
      if (m.y !== view.y || m.m !== view.m) view = m;
      paintCal(e.currentTarget, cel);
    }
    /** Куда встать при открытии с клавиатуры: выбранное → сегодня → первое. */
    function fokusDen() {
      var cal = root.querySelector('.ui-cal');
      if (!cal) return;
      var kletka = cal.querySelector('.ui-cal-day.is-selected:not([disabled])')
        || cal.querySelector('.ui-cal-day.is-today:not([disabled])')
        || cal.querySelector('.ui-cal-day:not([disabled])');
      if (kletka) kletka.focus();
    }
    function show() {
      if (open) return;
      open = true;
      btn.setAttribute('aria-expanded', 'true');
      view = value ? parseIso(value) : todayParts();
      var cal = el('div', 'ui-cal');
      // Календарь — своё окно, а не безымянная россыпь кнопок: без роли и имени
      // диктор объявляет тридцать кнопок с числами и ни слова о том, что это.
      cal.setAttribute('role', 'dialog');
      cal.setAttribute('aria-label', 'Выбор даты');
      cal.addEventListener('keydown', onCalKey);
      root.appendChild(cal); paintCal(cal);
      root.classList.add('is-open');
      // Возврат autoClose здесь раньше выбрасывался — и слушатели календаря тоже
      // оставались на документе после закрытия изнутри (выбрали дату).
      closeCal = autoClose(root, hide);
    }
    function hide() {
      if (!open) return;
      open = false;
      btn.setAttribute('aria-expanded', 'false');
      root.classList.remove('is-open');
      var cal = root.querySelector('.ui-cal');
      // Фокус был внутри календаря — вернуть его на кнопку. Иначе после Escape
      // человек оказывается в начале страницы и заново ищет, где он был.
      var bylFokus = !!(cal && cal.contains(document.activeElement));
      if (cal) cal.remove();
      if (closeCal) { var cc = closeCal; closeCal = null; cc(); }
      if (bylFokus) btn.focus();
    }
    btn.addEventListener('click', function () { open ? hide() : show(); });
    btn.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      e.preventDefault();
      if (!open) show();
      fokusDen();
    });

    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-expanded', 'false');
    var dost = a11y(opts, root, btn, 'ui.dateField');
    paintValue();
    return { get: function () { return value; },
             set: function (v) { value = v || ''; paintValue(); }, el: root,
             setError: dost.setError,
             destroy: function () { hide(); root.remove(); } };
  };

  // ── Переключатель ─────────────────────────────────────────────────────
  /** ui.switchBox(target, {value, label, onChange}) */
  /**
   * ДЕЙСТВИЕ ПО КНОПКЕ: «в процессе» видно сразу, дважды не запускается.
   *
   * ЗАЧЕМ. Между нажатием и ответом портала проходит секунда-другая. Без
   * видимого отклика человек жмёт второй раз — и создаётся вторая запись, уходит
   * второе письмо. Замер 06.08.2026: своё гашение кнопки написано в 20 проектах
   * из 27, и почти везде оно снимается только в `.catch()` — исключение до
   * промиса оставляет кнопку выключенной насовсем, и человек считает, что
   * приложение зависло.
   *
   * ЧТО ДЕЛАЕТ. Сразу выключает кнопку и показывает в ней вращающийся кружок,
   * держит ширину прежней (интерфейс не дёргается), а в конце — что бы ни
   * случилось — возвращает кнопку в исходный вид.
   *
   * ЧТО ВОЗВРАЩАЕТ. Промис `{ok: true, value: <что вернула работа>}` либо
   * `{ok: false, error: <ошибка>}`; повторное нажатие во время работы —
   * `{ok: false, busy: true}` и работа НЕ запускается. Промис никогда не
   * отклоняется: висящий необработанный отказ в консоли ничего не лечит, а
   * сообщение человеку показывается само (отключается `{toast: false}`).
   *
   * @param btn  кнопка (или любой элемент с disabled)
   * @param work function(): Promise|любое значение — сама работа
   * @param opts {key: 'сделка-42'} — общая очередь для НЕСКОЛЬКИХ кнопок одной
   *             записи: пока идёт «Провести», не сработает «Удалить».
   *             {busyText: 'Сохраняем…'} — подпись на время работы.
   *             {toast: false} — не показывать сообщение об ошибке самому.
   */
  var actionBusyKeys = {};
  ui.action = function (btn, work, opts) {
    opts = opts || {};
    var key = opts.key || null;
    var done = function (res) { return Promise.resolve(res); };

    if (!btn || btn.dataset.busy === '1' || (key && actionBusyKeys[key])) {
      return done({ ok: false, busy: true });
    }

    btn.dataset.busy = '1';
    if (key) actionBusyKeys[key] = true;
    var wasDisabled = !!btn.disabled;
    var wasHtml = btn.innerHTML;
    // Ширину фиксируем ДО подмены содержимого: иначе строка «Сохраняем…» и
    // кружок меняют размер кнопки, и соседние элементы прыгают под курсором.
    var wasMinWidth = btn.style.minWidth;
    var box = btn.getBoundingClientRect();
    // UI-геометрия: ширина кнопки в пикселях.
    if (box.width > 0) btn.style.minWidth = Math.ceil(box.width) + 'px';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-inline" aria-hidden="true"></span>'
      + (opts.busyText ? '<span>' + ui.esc(opts.busyText) + '</span>' : '');

    var restore = function () {
      btn.innerHTML = wasHtml;
      btn.style.minWidth = wasMinWidth;
      btn.disabled = wasDisabled;
      delete btn.dataset.busy;
      if (key) delete actionBusyKeys[key];
    };

    // try нужен на случай, когда работа падает СИНХРОННО (опечатка, обращение к
    // пустому полю): без него кнопка осталась бы выключенной навсегда.
    var started;
    try { started = Promise.resolve(work()); }
    catch (e) { started = Promise.reject(e); }

    return started.then(function (value) {
      restore();
      return { ok: true, value: value };
    }, function (error) {
      restore();
      if (opts.toast !== false && ui.apiFailToast) ui.apiFailToast(error);
      return { ok: false, error: error };
    });
  };

  ui.switchBox = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.switchBox');
    var value = !!opts.value;

    // Обёртка нужна не для красоты: текст отказа некуда положить внутрь
    // <button>, а он обязан лежать рядом с контролом и быть с ним связан.
    var box = el('div', 'ui-switch-box');
    var root = el('button', 'ui-switch'); root.type = 'button';
    var track = el('span', 'ui-switch-track');
    track.appendChild(el('span', 'ui-switch-dot'));
    root.appendChild(track);
    if (opts.label) root.appendChild(el('span', 'ui-switch-label', opts.label));
    box.appendChild(root);
    mount.innerHTML = ''; mount.appendChild(box);

    // Переключатель — это switch, а не «нажатая кнопка»: диктор произносит
    // «включено/выключено», а не «нажато», и человек понимает, что перед ним.
    root.setAttribute('role', 'switch');
    var dost = a11y(opts, box, root, 'ui.switchBox');

    function paint() {
      root.classList.toggle('is-on', value);
      root.setAttribute('aria-checked', value ? 'true' : 'false');
    }
    root.addEventListener('click', function () {
      value = !value; paint();
      if (opts.onChange) opts.onChange(value);
    });

    paint();
    return { get: function () { return value; },
             set: function (v) { value = !!v; paint(); }, el: box,
             setError: dost.setError,
             destroy: function () { box.remove(); } };
  };

  // ── Чипы: один из нескольких ──────────────────────────────────────────
  /** ui.chipGroup(target, {options, value, onChange}) */
  ui.chipGroup = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.chipGroup');
    var items = (opts.options || []).map(function (o) {
      return typeof o === 'object' ? o : { value: o, label: String(o) };
    });
    var value = opts.value != null ? opts.value : (items[0] ? items[0].value : null);

    var box = el('div', 'chips-box');
    var root = el('div', 'chips');
    box.appendChild(root);
    mount.innerHTML = ''; mount.appendChild(box);

    // Чипы — это выбор одного из нескольких, то есть группа переключателей.
    // Без роли диктор читает подряд четыре безымянные кнопки и ни слова о том,
    // что они об одном и что выбрана одна.
    root.setAttribute('role', 'radiogroup');
    var dost = a11y(opts, box, root, 'ui.chipGroup');

    function paint() {
      // Перестройка не должна выбрасывать клавиатурный фокус из группы.
      var focusIndex = Array.prototype.indexOf.call(root.children, document.activeElement);
      root.innerHTML = '';
      items.forEach(function (it, index) {
        var b = el('button', 'chip' + (it.kind ? ' chip-' + it.kind : '') + (it.value === value ? ' is-active' : ''), it.label);
        b.type = 'button';
        b.setAttribute('role', 'radio');
        b.setAttribute('aria-checked', it.value === value ? 'true' : 'false');
        b.addEventListener('click', function () {
          if (it.value === value) return;
          value = it.value; paint();
          if (opts.onChange) opts.onChange(value);
        });
        root.appendChild(b);
        if (index === focusIndex) b.focus();
      });
    }

    paint();
    return { get: function () { return value; },
             set: function (v) { value = v; paint(); }, el: box,
             setError: dost.setError,
             destroy: function () { box.remove(); } };
  };

  // ── Выбор сотрудников и отделов ───────────────────────────────────────
  /**
   * ui.pickPeople(target, {search, value, placeholder, onChange})
   *
   * search(query, {signal}) → Promise<{users:[{id,name,post,dept,fired}], depts:[{id,name,path}],
   *   depts_available?:boolean, depts_note?:string}>
   * Запрос делает ВЫЗЫВАЮЩИЙ код через ui.api — контрол в сеть не ходит сам:
   * так у приложения остаётся одна точка, где считаются права и частота.
   * Второй довод — отмена: передайте его в ui.api как {signal}, и запрос,
   * ответ которого уже никому не нужен, оборвётся, не тратя лимит портала.
   * Поиск, который отмену не читает, ничего не теряет — лишнее поле игнорируется.
   *
   * Выбранное живёт списком [{kind:'user'|'dept', id, name}] и показывается
   * чипами: доступ выдают пачкой («весь отдел продаж и двое из бухгалтерии»),
   * и по одному это делать невыносимо.
   */
  ui.pickPeople = function (target, opts) {
    opts = opts || {};
    var mount = host(target, 'ui.pickPeople');
    var chosen = (opts.value || []).slice();
    var open = false, seq = 0, detach = null, cursor = -1, timer = null, otmena = null;
    // Последняя показанная выдача: по ней перерисовываем список после добавления
    // человека, не спрашивая портал заново.
    var lastData = null;

    var root = el('div', 'picker');
    var field = el('div', 'picker-field');
    var input = el('input');
    input.type = 'text';
    input.placeholder = opts.placeholder || 'Фамилия или название отдела';
    input.autocomplete = 'off';
    field.appendChild(input);
    root.appendChild(field);
    mount.innerHTML = ''; mount.appendChild(root);

    // Поле поиска людей — это combobox со списком, а не безымянная строка:
    // без ролей диктор объявляет «поле ввода» и молчит обо всём остальном —
    // ни что список раскрылся, ни сколько в нём человек, ни на ком курсор.
    var listId = 'ui-picker-list-' + (++ctlSeq);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', listId);
    var dost = a11y(opts, root, input, 'ui.pickPeople');

    /**
     * Ответ, которого человек уже не ждёт, объявляем недействительным.
     *
     * Поколение обязано расти на КАЖДОМ изменении строки и на КАЖДОМ закрытии
     * выдачи, а не только при отправке нового запроса. Пока оно росло лишь на
     * удачном запросе, четыре обычных действия оставляли пришедший позже ответ
     * «актуальным»: стёр строку, укоротил её до одной буквы, закрыл выдачу
     * кликом мимо, ушёл с экрана. Ответ приходил в пустоту и всё равно
     * раскрывал список — а следующий Enter добавлял человека, которого никто не
     * выбирал, и доступ уходил не тому (находка ревью 09.08.2026).
     *
     * Заодно обрываем сам запрос: портал считает лимит и на ту выборку, которую
     * уже никто не прочитает.
     */
    function invalidate() {
      seq++;
      if (otmena) {
        try { otmena.abort(); } catch (e) { /* браузер без отмены — гасим по поколению */ }
        otmena = null;
      }
    }

    function key(it) { return it.kind + ':' + it.id; }
    function taken(it) {
      return chosen.some(function (c) { return key(c) === key(it); });
    }
    function paintChips() {
      // Чипы всегда перед полем ввода — иначе при добавлении курсор «прыгает».
      [].slice.call(field.querySelectorAll('.chip-tag')).forEach(function (n) { n.remove(); });
      chosen.forEach(function (it) {
        var chip = el('span', 'chip chip-tag' + (it.kind === 'dept' ? ' chip-dept' : ''));
        if (it.kind !== 'dept') {
          var chipFoto;
          if (typeof it.photo === 'string' && it.photo.indexOf('https://') === 0) {
            chipFoto = el('img', 'chip-avatar');
            chipFoto.alt = '';
            chipFoto.loading = 'lazy';
            // Ссылка Bitrix может устареть уже после поиска: выбранный человек
            // всё равно остаётся узнаваемым по инициалу, а не пустому месту.
            chipFoto.addEventListener('error', function () {
              var zamena = el('span', 'chip-avatar', firstLetter(it.name));
              if (chipFoto.parentNode) chipFoto.parentNode.replaceChild(zamena, chipFoto);
            });
            chipFoto.src = it.photo;
          } else {
            chipFoto = el('span', 'chip-avatar', firstLetter(it.name));
          }
          chip.appendChild(chipFoto);
        }
        chip.appendChild(el('span', '', it.name));
        var x = el('button', 'chip-x', '×');
        x.type = 'button'; x.title = 'Убрать';
        // Крестик на слух — это символ «×». Говорим, КОГО убираем: чипов на
        // экране бывает десяток, и «убрать» без имени выбирается наугад.
        x.setAttribute('aria-label', 'Убрать: ' + it.name);
        x.addEventListener('click', function (e) {
          e.stopPropagation();
          chosen = chosen.filter(function (c) { return key(c) !== key(it); });
          paintChips();
          if (opts.onChange) opts.onChange(chosen.slice());
        });
        chip.appendChild(x);
        field.insertBefore(chip, input);
      });
    }
    function add(it) {
      if (taken(it)) return;
      chosen.push(it);
      paintChips();
      // Строка очищается кодом, события input не будет — гасим прежний запрос
      // руками, иначе его ответ перерисует выдачу под уже другим запросом.
      invalidate();
      input.value = '';
      if (opts.onChange) opts.onChange(chosen.slice());
      // Список НЕ закрываем: чаще всего добавляют сразу нескольких из одной
      // выдачи. Здесь стоял run(''), а он при пустом запросе делает hide() —
      // то есть выдача схлопывалась после КАЖДОГО человека, и следующего
      // приходилось искать заново, набирая фамилию целиком (находка ревью
      // 09.08.2026: комментарий обещал одно, код делал другое).
      // Перерисовываем ту же выдачу: заодно добавленный получает пометку
      // «уже выбран» и перестаёт кликаться повторно.
      if (lastData) paint(lastData); else hide();
      input.focus();
    }
    function list() {
      var l = root.querySelector('.picker-list');
      if (!l) {
        l = el('div', 'picker-list');
        l.id = listId;
        l.setAttribute('role', 'listbox');
        root.appendChild(l);
      }
      return l;
    }
    function show() {
      if (open) return;
      open = true; root.classList.add('is-open');
      input.setAttribute('aria-expanded', 'true');
      detach = autoClose(root, hide);
    }
    function hide() {
      // Гасим ожидаемый ответ ДО проверки «а был ли список открыт»: выдачу
      // закрывают и тогда, когда она ещё не успела раскрыться, а запрос уже ушёл.
      invalidate();
      if (!open) return;
      open = false; root.classList.remove('is-open');
      input.setAttribute('aria-expanded', 'false');
      // Курсор указывал на пункт закрытого списка: ссылка в никуда читается
      // диктором как сломанная разметка.
      input.removeAttribute('aria-activedescendant');
      if (detach) { detach(); detach = null; }
      var l = root.querySelector('.picker-list');
      if (l) l.remove();
    }
    function hint(text) {
      show();
      var l = list(); l.innerHTML = '';
      // Разметку почистили — ЧИСТИМ И СПИСОК ДЛЯ КЛАВИАТУРЫ. Пока этого не было,
      // «Ищем…» и «Введите хотя бы два символа» рисовались поверх прежней
      // выдачи, а l._items оставался от НЕЁ: стрелка вниз и Enter добавляли
      // человека или целый отдел, которого на экране нет и который никто не
      // выбирал (находка ревью K4-21 09.08.2026). Клавиатура обязана работать с
      // тем же, что видно глазами, — иначе доступ уходит не тому.
      l._items = [];
      l._seq = seq;
      cursor = -1;
      input.removeAttribute('aria-activedescendant');
      l.appendChild(el('div', 'picker-hint', text));
    }
    function paint(data) {
      lastData = data;
      var l = list(); l.innerHTML = '';
      cursor = -1;
      var items = [];
      var deptsAvailable = data.depts_available !== false;
      var deptsNote = deptsAvailable ? '' : String(data.depts_note ||
        'Поиск по отделам сейчас недоступен — ищем только людей.').trim();
      var users = (data.users || []).map(function (u) {
        return { kind: 'user', id: u.id, name: u.name, photo: u.photo,
          post: u.post, dept: u.dept, fired: u.fired };
      });
      var depts = (data.depts || []).map(function (d) {
        return { kind: 'dept', id: d.id, name: d.name, path: d.path };
      });
      if (!users.length && !depts.length) {
        hint(deptsNote || 'Никого и ничего не нашлось');
        return;
      }

      // Частичный ответ не выдаём за полный: человек может выбрать найденного
      // сотрудника, но одновременно видит, что отделы сейчас не проверены.
      if (deptsNote) {
        var note = el('div', 'picker-note', deptsNote);
        note.setAttribute('role', 'status');
        l.appendChild(note);
      }

      if (depts.length) {
        l.appendChild(el('div', 'picker-group', 'Отделы — доступ получат все сотрудники'));
        depts.forEach(function (d) { l.appendChild(option(d, items.length)); items.push(d); });
      }
      if (users.length) {
        l.appendChild(el('div', 'picker-group', 'Сотрудники'));
        users.forEach(function (u) { l.appendChild(option(u, items.length)); items.push(u); });
      }
      l._items = items;
      // Метка поколения на самом списке: клавиатура берёт пункты только у
      // выдачи, показанной ПРЯМО СЕЙЧАС. Любой новый способ показать
      // промежуточный экран закрыт этим сразу, без правки обработчика клавиш.
      l._seq = seq;
      // Курсор ещё не поставлен — ссылку на прежний пункт снимаем.
      input.removeAttribute('aria-activedescendant');
    }
    function option(it, idx) {
      var isTaken = taken(it);
      var b = el('button', 'picker-opt' + (isTaken ? ' is-taken' : '') + (it.fired ? ' is-fired' : ''));
      b.type = 'button';
      b.id = listId + '-opt-' + idx;
      b.setAttribute('role', 'option');
      b.setAttribute('aria-selected', isTaken ? 'true' : 'false');
      if (isTaken) b.setAttribute('aria-disabled', 'true');
      // Фото сотрудника, если портал его дал: человека в списке узнают по лицу
      // быстрее, чем по строчке текста. Нет фото (или это отдел) — прежняя
      // буква. Ссылку ставим только на https-адрес: сюда она приходит из ответа
      // портала, но подставляется прямо в разметку.
      var av;
      if (it.kind !== 'dept' && typeof it.photo === 'string' && it.photo.indexOf('https://') === 0) {
        av = el('img', 'picker-avatar');
        av.src = it.photo;
        av.alt = '';
        av.loading = 'lazy';
        // Портал иногда отдаёт ссылку на уже удалённый файл: молча заменяем
        // сломанную картинку буквой, а не показываем «битое изображение».
        av.addEventListener('error', function () {
          var zamena = el('span', 'picker-avatar', firstLetter(it.name));
          if (av.parentNode) av.parentNode.replaceChild(zamena, av);
        });
      } else {
        av = el('span', 'picker-avatar', it.kind === 'dept' ? '▤' : firstLetter(it.name));
      }
      var body = el('span', 'picker-name');
      body.appendChild(el('span', '', it.name + (it.fired ? ' · уволен' : '')));
      var sub = it.kind === 'dept' ? it.path : [it.post, it.dept].filter(Boolean).join(' · ');
      if (sub) { var s = el('div', 'picker-sub', sub); body.appendChild(s); }
      b.appendChild(av); b.appendChild(body);
      if (isTaken) b.appendChild(el('span', 'picker-sub', 'уже выбран'));
      if (!isTaken) b.addEventListener('click', function () { add(it); });
      return b;
    }
    function firstLetter(name) {
      return (String(name || '?').trim()[0] || '?').toUpperCase();
    }
    function run(q) {
      if (!opts.search) return;
      // Новый запрос отменяет прежний целиком — и по поколению, и по сети.
      invalidate();
      // Меньше двух символов — портал всё равно откажет, а запрос уже потрачен.
      if (q.length && q.length < 2) { hint('Введите хотя бы два символа'); return; }
      if (!q.length) { hide(); return; }
      var my = seq;
      var ctrl = (typeof AbortController === 'function') ? new AbortController() : null;
      otmena = ctrl;
      hint('Ищем…');
      // Отмена передаётся вторым доводом: ui.api умеет её принимать, а поиск,
      // который её не читает, просто получит лишнее поле и не сломается.
      var zapros;
      try { zapros = Promise.resolve(opts.search(q, ctrl ? { signal: ctrl.signal } : {})); }
      catch (e) { zapros = Promise.reject(e); }
      zapros.then(function (data) {
        // Ответы приходят не в том порядке, в каком их спрашивали: без сверки
        // поколения выдача по «Ива» затирает выдачу по «Петров», и человек
        // выдаёт доступ не тому.
        if (my !== seq) return;
        // Сверяем и сам запрос: run() зовут не только из поля ввода, а
        // поколение отвечает за порядок, но не за то, что человек ищет ИМЕННО
        // это прямо сейчас.
        if (input.value.trim() !== q) return;
        paint(data || {});
      }).catch(function (e) {
        if (my !== seq) return;
        // Свою же отмену показывать человеку нечего: он её и вызвал.
        if (e && (e.code === 'aborted' || e.name === 'AbortError')) return;
        hint(e && e.message ? e.message : 'Поиск не удался');
      });
    }

    field.addEventListener('click', function () { input.focus(); });
    input.addEventListener('input', function () {
      clearTimeout(timer);
      // Прежний ответ перестал быть нужен в момент правки строки, а не через
      // 350 мс, когда уйдёт следующий запрос: между этими событиями он как раз
      // и успевает прийти.
      invalidate();
      var q = input.value.trim();
      timer = setTimeout(function () { run(q); }, 350);
    });
    input.addEventListener('keydown', function (e) {
      var l = root.querySelector('.picker-list');
      // Поколение списка обязано совпасть с текущим: устаревшая выдача для
      // клавиатуры не существует, даже если её массив ещё лежит в памяти.
      var items = (l && l._items && l._seq === seq) ? l._items : [];
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!items.length) return;
        e.preventDefault();
        cursor = e.key === 'ArrowDown'
          ? Math.min(cursor + 1, items.length - 1)
          : Math.max(cursor - 1, 0);
        [].slice.call(l.querySelectorAll('.picker-opt')).forEach(function (n, i) {
          n.classList.toggle('is-cursor', i === cursor);
          if (i === cursor) {
            // Курсор обязан быть НАЗВАН: без этого подсветка есть глазами и не
            // существует на слух — человек ходит стрелками в тишине.
            input.setAttribute('aria-activedescendant', n.id);
            if (n.scrollIntoView) n.scrollIntoView({ block: 'nearest' });
          }
        });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (cursor >= 0 && items[cursor]) add(items[cursor]);
      } else if (e.key === 'Backspace' && !input.value && chosen.length) {
        // Пустое поле + Backspace снимает последний чип: так работают все
        // привычные поля с метками, и человек делает это не задумываясь.
        chosen.pop(); paintChips();
        if (opts.onChange) opts.onChange(chosen.slice());
      }
    });

    paintChips();
    return {
      get: function () { return chosen.slice(); },
      set: function (v) { chosen = (v || []).slice(); paintChips(); },
      // Очистка — такое же изменение выбора, как и всякое другое: без onChange
      // тот, кто слушает контрол, остаётся с прежней картиной. Так кнопка «Дать
      // доступ» после успешной выдачи оставалась включённой при пустом выборе, и
      // следующий клик молча не делал ничего.
      clear: function () {
        chosen = []; paintChips(); input.value = ''; hide();
        if (opts.onChange) opts.onChange(chosen.slice());
      },
      el: root,
      setError: dost.setError,
      destroy: function () { clearTimeout(timer); hide(); root.remove(); },
    };
  };

  global.ui = ui;
})(window);
