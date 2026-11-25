/**
 * Точка входа фронта. Тело под проект пишется в разделе «ЭКРАН ПРОЕКТА».
 *
 * Что уже готово и переписывать не нужно:
 *   window.APP_SESSION — пропуск (его подставляет index.php после гейта)
 *   window.APP_ROLE    — роль зрителя: view | edit | admin
 *   ui.api / ui.toast / ui.confirmBox / ui.showState — см. js/ui.js
 *
 * Служебные разделы «Настройки → Служебное», «Доступ», «Журнал» работают из
 * коробки и закрывают то, ради чего раньше звали разработчика: выдать доступ
 * сотруднику, восстановить привязки в портале, посмотреть, что произошло.
 */
(function () {
  'use strict';

  var role = window.APP_ROLE || 'view';
  var isAdmin = role === 'admin';
  // Право на изменения: у ролей edit и admin. Фронт по нему прячет кнопки,
  // которым сервер всё равно откажет, — чтобы не обещать несуществующее.
  var canEdit = role === 'edit' || role === 'admin';

  // ── Разделы бокового меню ──────────────────────────────────────────────
  var adminNav = document.getElementById('nav-admin');
  if (adminNav && isAdmin) adminNav.classList.remove('hidden');  // служебное — только администратору

  var TITLES = { work: 'Работа', settings: 'Настройки', access: 'Доступ', log: 'Журнал' };

  function openTab(name) {
    document.querySelectorAll('.nav-item').forEach(function (t) {
      var tekuschiy = t.dataset.tab === name;
      t.classList.toggle('is-active', tekuschiy);
      // Диктор обязан знать, где человек находится: подсветка — это только для
      // глаз, а `aria-current` произносится вслух (BXREV-022, 09.08.2026).
      if (tekuschiy) t.setAttribute('aria-current', 'page');
      else t.removeAttribute('aria-current');
    });
    // Прячем классом, а не атрибутом hidden: у раздела в стилях может стоять
    // display, и тогда атрибут молча перестаёт работать — экран показывает
    // сразу два раздела внахлёст.
    document.querySelectorAll('.pane').forEach(function (p) {
      p.classList.toggle('hidden', p.dataset.pane !== name);
    });
    var title = document.getElementById('app-title');
    if (title && TITLES[name]) title.textContent = TITLES[name];
    if (name === 'work')     loadTask();
    if (name === 'settings') { loadSettings(); loadService(); }
    if (name === 'access') loadAcl();
    if (name === 'log')    loadLog();
    // Высоту окна пересчитываем сами: переключение раздела меняет только
    // видимость, а наблюдатель за содержимым такого не видит — после длинного
    // журнала короткий экран оставался в старой высоте с пустотой внизу.
    if (window.appFrame) appFrame.sync();
  }
  document.querySelectorAll('.nav-item').forEach(function (t) {
    t.onclick = function () { openTab(t.dataset.tab); };
  });
  // Счётчик поколения и отмена прежнего запроса — как у остальных загрузчиков.
  // Экран задачи опрашивается по таймеру и вручную кнопкой: ответы приходят не в
  // том порядке, в каком их спрашивали, и поздний ответ прежнего запроса
  // перерисовывал экран поверх свежего (находка ревью 09.08.2026).
  //
  // ОБЪЯВЛЕНЫ ЗДЕСЬ, А НЕ РЯДОМ С loadTask НИЖЕ, И ЭТО НЕСУЩЕЕ. Первый вызов
  // идёт строкой ниже — до места объявления. У `var` объявление всплывает, а
  // присваивание нет: в этот момент счётчик равен undefined, `++` даёт NaN,
  // и пришедший ответ сравнивается как «поколение NaN против 0» — то есть
  // отбрасывается ВСЕГДА. Экран «Работа» навсегда застревал на «Проверяем, есть
  // ли работа…», молча: ни ошибки в консоли, ни отказа сервера. Поймал живой
  // сценарий tests/task-live.js (09.08.2026), чтение кода — нет.
  var taskGeneration = 0;
  var taskController = null;

  // Раздел «Работа» открыт сразу, без клика по меню: состояние задачи надо
  // показать при первом же открытии — человек за это время мог закрыть окно и
  // вернуться, а задача продолжала идти.
  loadTask();

  // ── Служебное: восстановить привязки и снять зависшую операцию ─────────
  //
  // Раздела «Состояние» в шаблоне больше нет (убран 26.08.2026): он показывал
  // домен, права и время последнего запуска фона — сведения для разработчика, а
  // не для того, кто работает в приложении. Обычному администратору портала они
  // ничего не говорили, зато строка «фоновая обработка не запускалась» пугала в
  // приложениях, которым фон вовсе не нужен.
  //
  // Но через тот раздел проходили ДВА действия, которые больше делать негде:
  // пересобрать пропавшие в портале места приложения и снять операцию с
  // неизвестным исходом. Без них администратор идёт к разработчику с доступом
  // к серверу, поэтому оба переехали сюда — вниз настроек, где их видит тот же
  // человек, который и открывает настройки.
  var serviceGeneration = 0;

  function loadService() {
    var box = document.getElementById('service-box');
    if (!box) return;
    var generation = ++serviceGeneration;
    ui.api('GET', 'api/health.php').then(function (d) {
      if (generation !== serviceGeneration) return;
      box.className = '';
      box.innerHTML = '';

      // Зависшая операция: исход неизвестен, повтор заблокирован. Совет «снимите
      // ключ» без способа его снять отправлял администратора к разработчику.
      (d.stuck_ops || []).forEach(function (key) {
        var el = document.createElement('div');
        el.className = 'alert alert-warn';
        el.innerHTML = '<b>Операция «' + ui.esc(key) + '» с неизвестным исходом</b>' +
          '<p style="margin:6px 0 10px">Связь оборвалась между работой и записью ' +
          'результата. Посмотрите в портале, что получилось на самом деле, и снимите ' +
          'операцию — после этого действие можно повторить.</p>';
        var btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-danger';
        btn.textContent = 'Снять';
        btn.onclick = function () {
          ui.confirmBox({
            key: 'ops-' + key,
            title: 'Снять операцию?',
            text: 'Снимается защита от повторного выполнения «' + key + '». Делайте это, ' +
                  'только если посмотрели в портале, что получилось на самом деле.',
            okText: 'Снять', danger: true,
          }).then(function (yes) {
            if (!yes) return;
            ui.action(btn, function () {
              return ui.api('POST', 'api/ops.php', { key: key }).then(function () {
                ui.toast('Операция снята', 'ok');
                loadService();
              });
            });
          });
        };
        el.appendChild(btn);
        box.appendChild(el);
      });

      // Здесь три состояния, а не два: подтверждено / сломано / неизвестно.
      // Разовый отказ портала не имеет права превращаться в зелёное «на месте».
      // И одного совпавшего placement-кода мало: старый HANDLER ведёт на прежний
      // адрес приложения, хотя сам код по-прежнему есть в placement.get.
      var placements = d.placements || {};
      var mesta = Array.isArray(placements.объявлено) ? placements.объявлено : [];
      var privyazano = Array.isArray(placements.привязано) ? placements.привязано : [];
      var raskhozhdeniya = Array.isArray(placements.расхождение) ? placements.расхождение : [];
      var mestaProvereny = placements.проверено === true;

      var events = d.events || {};
      var obyavlenoSobytij = Number(events.объявлено);
      if (!Number.isFinite(obyavlenoSobytij) || obyavlenoSobytij < 0) obyavlenoSobytij = 0;
      var podklyuchenoSobytij = Number(events.подключено);
      if (!Number.isFinite(podklyuchenoSobytij) || podklyuchenoSobytij < 0) podklyuchenoSobytij = 0;
      var netSobytij = Array.isArray(events.не_подключены) ? events.не_подключены : [];
      var sobytiyaProvereny = events.проверено === true;
      var uborka = events.уборка_при_удалении;

      var slomano = [];
      var neizvestno = [];
      if (mesta.length && !mestaProvereny) {
        neizvestno.push('места приложения в портале');
      } else if (mestaProvereny) {
        var netMest = mesta.filter(function (code) { return privyazano.indexOf(code) < 0; });
        var staryjAdres = raskhozhdeniya.filter(function (code) {
          return privyazano.indexOf(code) >= 0;
        });
        if (netMest.length) slomano.push('не хватает мест: ' + netMest.join(', '));
        if (staryjAdres.length) {
          slomano.push('неверный адрес обработчика у мест: ' + staryjAdres.join(', '));
        }
      }

      if (!sobytiyaProvereny) {
        neizvestno.push('подписку на события');
      } else if (netSobytij.length) {
        slomano.push('портал не шлёт события: ' + netSobytij.join(', '));
      } else if (podklyuchenoSobytij < obyavlenoSobytij) {
        // Защита от неполного/устаревшего ответа: пустой список missing сам по
        // себе не доказывает здоровье, если числовой read-back расходится.
        slomano.push('подключено событий ' + podklyuchenoSobytij + ' из ' + obyavlenoSobytij);
      }
      if (uborka === false) {
        slomano.push('не подключена уборка при удалении приложения');
      } else if (uborka !== true) {
        neizvestno.push('уборку при удалении приложения');
      }

      var text = document.createElement('p');
      text.className = 'small';
      text.style.color = 'var(--text-dim)';
      if (slomano.length) {
        text.dataset.state = 'broken';
        text.textContent = 'Нужно восстановить: ' + slomano.join('; ') + '.';
        if (neizvestno.length) {
          text.textContent += ' Не удалось подтвердить: ' + neizvestno.join(', ') + '.';
        }
      } else if (neizvestno.length) {
        text.dataset.state = 'unknown';
        text.textContent = 'Не удалось подтвердить: ' + neizvestno.join(', ') +
          '. Это не означает, что всё исправно; обновите экран или пересоберите привязки.';
      } else if (!mesta.length && obyavlenoSobytij === 0) {
        text.dataset.state = 'healthy';
        text.textContent = 'Места и события проекта не объявлены; уборка при удалении приложения подключена.';
      } else {
        text.dataset.state = 'healthy';
        text.textContent = 'Места приложения и подписка на события на месте.';
      }
      box.appendChild(text);

      // Кнопка не нужна только когда проверено, что проект ничего не объявляет,
      // а служебная подписка уборки уже на месте. Unknown не прячем: иначе после
      // восстановления связи у администратора нет пути починить подписку.
      var estChtoVosstanavlivat = mesta.length > 0 || obyavlenoSobytij > 0 || uborka !== true;
      if (!estChtoVosstanavlivat) {
        if (window.appFrame) appFrame.sync();
        return;
      }

      var rb = document.createElement('button');
      rb.className = 'btn btn-sm';
      rb.textContent = 'Пересобрать привязки';
      rb.onclick = function () {
        ui.action(rb, function () {
          return ui.api('POST', 'api/bind.php').then(function (d2) {
            var bad = Object.keys(d2.errors || {});
            var badEvents = Object.keys((d2.events && d2.events.errors) || {});
            if (bad.length) ui.toast('Не удалось привязать: ' + bad.join(', '), 'error');
            else if (badEvents.length) {
              ui.toast('Места в портале на месте, но подписка на события не встала: '
                + badEvents.join(', '), 'warn');
            } else if (d2.note) {
              // Мест не объявлено — пересобрана только подписка. Прежде фронт и
              // на такой ответ писал «проверьте меню портала», и человек шёл
              // искать пункт меню, которого не появится никогда.
              ui.toast(d2.note, 'warn');
            } else ui.toast('Готово — проверьте меню портала', 'ok');
            loadService();
          });
        });
      };
      box.appendChild(rb);
      if (window.appFrame) appFrame.sync();
    }).catch(function (e) {
      if (generation !== serviceGeneration) return;
      // Служебный блок не имеет права ломать сами настройки: форма выше уже
      // прочитана и работает. Но строка без «Повторить» оставляла администратора,
      // пришедшего чинить привязки, без единственного нужного действия (ревью
      // 23.09.2026, ux_sboev-3) — поэтому тот же контракт, что у остальных экранов.
      box.className = '';
      ui.showState(box, 'error', { title: 'Служебные сведения сейчас недоступны',
        text: e.message, ref: e.ref, onRetry: loadService });
    });
  }


  // ── Доступ: список сотрудников ─────────────────────────────────────────
  var ROLE_NAMES = { view: 'Только смотреть', edit: 'Смотреть и менять', admin: 'Полные права' };
  var aclGeneration = 0;
  var aclController = null;

  function loadAcl() {
    var generation = ++aclGeneration;
    if (aclController) aclController.abort();
    aclController = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;

    var body = document.querySelector('#acl-table tbody');
    body.innerHTML = '<tr><td colspan="3"><div class="state"><div class="spinner"></div>Загружаем…</div></td></tr>';
    ui.api('GET', 'api/acl.php', null, {
      signal: aclController ? aclController.signal : null
    }).then(function (d) {
      if (generation !== aclGeneration) return;
      var acl = d.acl || d;
      var owner = acl.owner_uid;
      var users = acl.users || [];
      var transferBox = document.getElementById('owner-transfer');
      var claimOwner = document.getElementById('btn-claim-owner');
      var canClaimOwner = !!(d.owner_transfer && d.owner_transfer.can_claim);
      if (transferBox) transferBox.classList.toggle('hidden', !canClaimOwner);
      if (claimOwner) {
        claimOwner.disabled = false;
        claimOwner.onclick = canClaimOwner ? function () {
          ui.confirmBox({
            key: 'claim-owner',
            title: 'Стать владельцем приложения?',
            text: 'Прежний владелец сразу потеряет доступ, включая уже открытые вкладки.',
            okText: 'Передать владение', danger: true
          }).then(function (yes) {
            if (!yes) return;
            ui.action(claimOwner, function () {
              return ui.api('POST', 'api/acl.php', { action: 'claim_owner' })
                .then(function () { ui.toast('Владелец изменён, прежний доступ отозван', 'ok'); loadAcl(); });
            });
          });
        } : null;
      }
      var depts = acl.depts || [];
      if (!users.length && !depts.length) {
        body.innerHTML = '<tr><td colspan="3"><div class="state"><b>Пока только владелец</b>' +
          'Добавьте сотрудников или отделы ниже — остальные видят заглушку.</div></td></tr>';
        return;
      }
      // Отделы идут первыми: доступ чаще всего выдан именно отделом, и на вопрос
      // «почему у новичка уже есть доступ» ответ должен попадаться первым.
      var deptRows = depts.map(function (d) {
        return '<tr><td><span class="chip chip-tag chip-dept">' + ui.esc(d.name || ('отдел №' + d.id)) + '</span>' +
          ' <span class="small muted">все сотрудники отдела</span></td>' +
          '<td>' + ui.esc(ROLE_NAMES[d.role] || d.role) + '</td>' +
          '<td style="text-align:right">' +
            '<button class="btn btn-sm btn-danger" data-del-dept="' + d.id + '">Убрать</button>' +
          '</td></tr>';
      });
      body.innerHTML = deptRows.join('') + users.map(function (u) {
        var isOwner = u.uid === owner;
        return '<tr><td>' + ui.esc(u.name || ('сотрудник №' + u.uid)) +
          (isOwner ? ' <span class="badge">владелец</span>' : '') + '</td>' +
          '<td>' + ui.esc(ROLE_NAMES[u.role] || u.role) + '</td>' +
          '<td style="text-align:right">' +
            (isOwner ? '<span class="small muted">убрать нельзя</span>'
                     : '<button class="btn btn-sm btn-danger" data-del="' + u.uid + '">Убрать</button>') +
          '</td></tr>';
      }).join('');

      body.querySelectorAll('[data-del-dept]').forEach(function (b) {
        b.onclick = function () {
          var id = parseInt(b.dataset.delDept, 10);
          ui.confirmBox({
            key: 'acl-del-dept-' + id,
            title: 'Убрать доступ у отдела?',
            text: 'Доступ потеряют все сотрудники отдела, у кого нет отдельной записи в списке. Открытые вкладки перестанут работать сразу.',
            okText: 'Убрать', danger: true
          }).then(function (yes) {
            if (!yes) return;
            // ui.action — как у остальных мутаций: показывает спиннер на самой
            // кнопке, глушит повторный клик и сам выводит причину отказа. Без
            // него между кликом и ответом портала не происходило ничего видимого,
            // и человек жал «Убрать» второй раз (находка ревью 09.08.2026).
            ui.action(b, function () {
              return ui.api('POST', 'api/acl.php', { action: 'delete', kind: 'dept', dept: id })
                .then(function () { ui.toast('Доступ отдела снят', 'ok'); loadAcl(); });
            }, { key: 'acl-del-dept-' + id });
          });
        };
      });
      body.querySelectorAll('[data-del]').forEach(function (b) {
        b.onclick = function () {
          var uid = parseInt(b.dataset.del, 10);
          ui.confirmBox({
            key: 'acl-del-user-' + uid,
            title: 'Убрать доступ?',
            text: 'Сотрудник сразу перестанет открывать приложение — прямо в текущей вкладке.',
            okText: 'Убрать', danger: true
          }).then(function (yes) {
            if (!yes) return;
            // Та же оправа, что у снятия доступа у отдела: спиннер на кнопке,
            // защита от второго клика, показ причины отказа.
            ui.action(b, function () {
              return ui.api('POST', 'api/acl.php', { action: 'delete', kind: 'user', uid: uid })
                .then(function () { ui.toast('Доступ снят', 'ok'); loadAcl(); });
            }, { key: 'acl-del-user-' + uid });
          });
        };
      });
    }).catch(function (e) {
      if (generation !== aclGeneration || (e && e.code) === 'aborted') return;
      // Ошибку показываем ВНУТРИ таблицы. Раньше состояние ставили на родителя, и
      // оно затирало саму таблицу: кнопка «Повторить» потом искала исчезнувший
      // tbody и роняла экран — после первого же сбоя связи вкладка не работала
      // вовсе, пока человек не перезагрузит страницу.
      ui.showState(body, 'error', { text: e.message, ref: e.ref, onRetry: loadAcl });
    }).then(function () {
      if (generation === aclGeneration) aclController = null;
    });
  }

  // ── Кому дать доступ: сотрудники И отделы, сразу несколько ─────────────
  // Поиск идёт через наш ui.api(): сервер сам проверит роль, ограничит частоту
  // и соберёт все страницы готовым B24-клиентом. Контрол в сеть не ходит —
  // у приложения одна точка выхода наружу.
  var picker = null, roleSelect = null;
  var addBtn = document.getElementById('btn-acl-add');

  // Выключенная кнопка обязана объяснять, ЧЕГО не хватает. Немая серая кнопка
  // читается человеком как «приложение сломалось», и следующий его шаг —
  // написать разработчику. Подсказку ставим и снимаем ровно там же, где меняем
  // disabled, иначе она переживёт причину и станет враньём.
  function setAddBtn(btn, skolko) {
    if (!btn) return;
    btn.disabled = !skolko;
    if (skolko) btn.removeAttribute('title');
    else btn.title = 'Сначала выберите сотрудника или отдел';
  }

  if (document.getElementById('user-picker')) {
    picker = ui.pickPeople('#user-picker', {
      labelledBy: 'l-user-picker',
      placeholder: 'Фамилия сотрудника или название отдела',
      search: function (q, opts) {
        // fired=1 — ищем и уволенных, помечая их в списке. Без флага сервер
        // отдаёт только действующих, и снять доступ у уволенного было нельзя:
        // в поиске он не находился, а пометка «· уволен» не показывалась ни
        // разу (находка ревью 09.08.2026).
        // Отмену контрол даёт вторым доводом: человек стёр запрос — запрос
        // обрывается, а не доедает лимит портала ради выдачи, которую уже
        // некому показывать.
        return ui.api('GET', 'api/users.php?q=' + encodeURIComponent(q) + '&fired=1',
          null, { signal: opts && opts.signal });
      },
      onChange: function (list) { setAddBtn(addBtn, list.length); },
    });
    // Стартовое состояние — по факту, а не по разметке: пока никого не выбрали,
    // кнопка обязана быть выключена. Иначе первый же клик по ней «работал» вхолостую.
    setAddBtn(addBtn, (picker.get() || []).length);
  }
  if (document.getElementById('user-role')) {
    roleSelect = ui.select('#user-role', {
      labelledBy: 'l-user-role',
      options: [
        { value: 'view',  label: 'Только смотреть' },
        { value: 'edit',  label: 'Смотреть и менять' },
        { value: 'admin', label: 'Полные, включая доступ' },
      ],
      value: 'view',
    });
  }

  if (addBtn) {
    addBtn.onclick = function () {
      var chosen = picker ? picker.get() : [];
      if (!chosen.length) return;
      var role = roleSelect ? roleSelect.get() : 'view';
      // Выдаём по одному запросу на выбранного: сервер решает про каждого сам
      // (проверка прав, журнал, защита от повтора), а частичный отказ не должен
      // отменять остальных. Итог человеку показываем ЧИСЛАМИ, а не «готово».
      var done = 0, failed = [];
      ui.action(addBtn, function () {
        return chosen.reduce(function (p, it) {
          return p.then(function () {
            return ui.api('POST', 'api/acl.php', {
              action: 'add',
              kind: it.kind,
              uid: it.kind === 'user' ? it.id : undefined,
              dept: it.kind === 'dept' ? it.id : undefined,
              name: it.name,
              photo: it.kind === 'user' ? it.photo : undefined,
              role: role,
            }).then(function () { done++; })
              .catch(function (e) { failed.push(it.name + ' — ' + (e && e.message ? e.message : 'отказ')); });
          });
        }, Promise.resolve());
      }).then(function () {
        if (picker) picker.clear();
        if (done) ui.toast('Доступ выдан: ' + done, 'ok');
        if (failed.length) ui.toast('Не удалось: ' + failed.join('; '), 'error');
        loadAcl();
      });
    };
  }

  // ── Долгая задача ──────────────────────────────────────────────────────
  // Экран НЕ считает и не держит работу: он ставит задачу, показывает ход и
  // просит остановиться. Считает фоновый рабочий. Отсюда два следствия, ради
  // которых блок и сделан: закрытое окно ничего не обрывает, а открытое заново
  // показывает актуальное состояние, а не «запустите снова».
  var taskTimer = null;

  var TASK_WORDS = {
    running:   'Выполняется',
    done:      'Готово',
    failed:    'Остановлено ошибкой',
    cancelled: 'Остановлено',
    stalled:   'Нет признаков жизни',
    unknown:   'Исход порции неизвестен',
    idle:      'Не запускалась',
  };

  function taskStopPolling() {
    if (taskTimer) { clearTimeout(taskTimer); taskTimer = null; }
  }

  function loadTask(silent) {
    var box = document.getElementById('task-box');
    if (!box) return;
    var generation = ++taskGeneration;
    if (taskController) taskController.abort();
    taskController = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;
    if (!silent) ui.showState(box, 'loading', { text: 'Проверяем, есть ли работа…' });

    ui.api('GET', 'api/task.php', null, {
      signal: taskController ? taskController.signal : null
    }).then(function (d) {
      if (generation !== taskGeneration) return;
      renderTask(d.task || {}, d.cron);
    }).catch(function (e) {
      if (generation !== taskGeneration || (e && e.code) === 'aborted') return;
      taskStopPolling();
      box.classList.add('state');
      ui.showState(box, 'error', { text: e.message, ref: e.ref, onRetry: function () { loadTask(); } });
    });
  }

  // cron: {silent, hint} — молчащий планировщик. Без этого «Запустить» выглядел
  // как успех, а считать задачу было некому: правда всплывала через пять минут,
  // когда задача становилась «без признаков жизни», и человек уже уходил.
  var taskCron = null;

  function renderTask(t, cron) {
    var box = document.getElementById('task-box');
    if (!box) return;
    if (cron) taskCron = cron;
    box.classList.remove('state');
    var running = t.status === 'running';
    var canStart = ['idle', 'done', 'failed', 'cancelled', 'stalled'].includes(t.status);

    var html = '';
    if (running || t.status === 'done' || t.status === 'failed'
        || t.status === 'cancelled' || t.status === 'stalled' || t.status === 'unknown') {
      html += '<div class="progress"><div class="progress-track">' +
              '<div class="progress-fill" style="width:' + (t.percent || 0) + '%"></div></div>' +
              '<div class="progress-meta"><span>' + ui.esc(TASK_WORDS[t.status] || t.status) +
              (t.cancelling ? ' — останавливаем после текущей порции' : '') + '</span>' +
              '<span>' + (t.done || 0) + (t.total ? ' из ' + t.total : '') + '</span></div></div>';
    }
    if (t.status === 'idle') {
      html += '<p class="muted small">Задача ещё не запускалась.</p>';
    }
    // Предупреждение показываем ДО кнопок и только когда оно к делу: на idle
    // человек ещё ничего не ждёт, а вот запущенная при молчащем планировщике
    // задача обязана объяснить, почему полоса стоит на месте.
    if (taskCron && taskCron.silent && t.status !== 'idle') {
      html += '<div class="alert alert-warn">' + ui.esc(taskCron.hint) + '</div>';
    }
    if (t.error) html += '<div class="alert alert-error">' + ui.esc(t.error) + '</div>';
    if (t.result) html += '<p class="small">' + ui.esc(t.result) + '</p>';
    if (t.status === 'stalled') {
      // Прямо говорим, что делать: «зависла» без выхода — это тупик, из которого
      // человек уходит к разработчику.
      html += '<div class="alert alert-warn">Фоновая обработка давно не отмечалась. ' +
              'Попросите администратора хостинга проверить задание планировщика в панели хостинга ' +
              '(команду печатает деплой). После восстановления запустите задачу заново.</div>';
    }
    if (t.status === 'unknown') {
      // Ни start, ни forget здесь нет намеренно: оба стёрли бы квитанцию порции
      // до проверки. Выход — снять блокировку ПОСЛЕ ручной проверки в портале, и
      // только администратору (ревью 23.09.2026, ux_sboev-1): прежде экран писал
      // жаргон про read-back и не давал ни одной кнопки — задача вставала навсегда.
      html += '<div class="alert alert-warn">Работа оборвалась посередине порции, и приложение ' +
              'не знает, успела ли эта порция примениться в портале. Повторять её вслепую нельзя — ' +
              'можно задвоить изменения. ' + (isAdmin
                ? 'Посмотрите в портале записи последней порции; если всё проверено, снимите блокировку — ' +
                  'задачу можно будет запустить заново.'
                : 'Снять блокировку после проверки может администратор приложения — сообщите ему.') +
              '</div>';
    }

    html += '<div class="settings-foot">';
    // Кнопки действий — только тем, кому сервер и правда разрешит. Роль «только
    // смотреть» видела «Запустить», жала её и получала отказ 403: кнопка обещала
    // то, чего не бывает, и человек шёл выяснять, что у него сломалось.
    // Это подсказка, а не защита: право всё равно проверяет api/task.php.
    if (canEdit) {
      if (running) {
        html += '<button class="btn btn-danger" id="task-cancel"' + (t.cancelling ? ' disabled' : '') +
                '>Отменить</button>';
      } else if (canStart) {
        html += '<button class="btn btn-primary" id="task-start">Запустить</button>';
        if (t.status !== 'idle') html += '<button class="btn btn-sm" id="task-forget">Убрать</button>';
      } else if (t.status === 'unknown' && isAdmin) {
        html += '<button class="btn btn-danger" id="task-resolve">Проверено вручную — снять блокировку</button>';
      }
    } else if (!running && t.status === 'idle') {
      html += '<p class="small">Запустить обработку может сотрудник с правом на изменения.</p>';
    }
    html += '</div>';
    box.innerHTML = html;

    // Кнопку передаём в taskDo: именно на ней человек и должен увидеть, что
    // команда ушла и идёт работа.
    var start = document.getElementById('task-start');
    if (start) start.onclick = function (e) { taskDo(e.currentTarget, 'start'); };
    var cancel = document.getElementById('task-cancel');
    if (cancel) cancel.onclick = function (e) { taskDo(e.currentTarget, 'cancel'); };
    var forget = document.getElementById('task-forget');
    if (forget) forget.onclick = function (e) { taskDo(e.currentTarget, 'forget'); };
    var resolve = document.getElementById('task-resolve');
    if (resolve) resolve.onclick = function (e) {
      var btn = e.currentTarget;
      ui.confirmBox({
        key: 'task-resolve-' + t.id,
        title: 'Снять блокировку задачи?',
        text: 'Делайте это, только если посмотрели в портале, что успела сделать последняя порция. ' +
              'Задача будет остановлена, следующий запуск начнёт её заново.',
        okText: 'Снять блокировку', danger: true,
      }).then(function (yes) { if (yes) taskDo(btn, 'resolve', { id: t.id }); });
    };

    // Опрашиваем, ТОЛЬКО пока задача идёт: постоянный опрос впустую держит
    // PHP-воркеры и разряжает ноутбук на открытой вкладке.
    taskStopPolling();
    if (running) taskTimer = setTimeout(function () { loadTask(true); }, 2000);

    if (window.appFrame) appFrame.sync();
  }

  /**
   * Команда порталу по кнопке задачи: запустить, отменить, убрать.
   *
   * Идёт через готовый ui.action, а не через свой флажок «занято». Флажок
   * защищал от второй команды, но человек об этом не знал: кнопка оставалась
   * яркой и нажималась, приложение молча глотало нажатие, и со стороны это
   * выглядело как «ничего не происходит» — а портал отвечает не мгновенно
   * (находка ревью 09.08.2026). ui.action сразу гасит кнопку, ставит в неё
   * кружок, держит ширину и возвращает всё обратно при любом исходе.
   *
   * Ключ у всех трёх кнопок ОДИН: пока идёт «Запустить», «Отменить» и «Убрать»
   * тоже недоступны — это одна и та же задача, и порядок команд по ней важен.
   */
  function taskDo(btn, action, extra) {
    if (!btn) return Promise.resolve();
    var generation = 0;
    return ui.action(btn, function () {
      // GET, начатый до команды, описывает прежнее состояние. Одна эпоха для
      // GET и POST не даёт ему затереть уже принятый start/cancel/forget.
      generation = ++taskGeneration;
      if (taskController) taskController.abort();
      taskController = null;
      taskStopPolling();
      var body = { do: action };
      if (extra && extra.id) body.id = extra.id;
      return ui.api('POST', 'api/task.php', body).then(function (d) {
        if (generation !== taskGeneration) return;
        if (action === 'start') {
          // Обещать «можно закрыть окно» имеет право только приложение с живым
          // планировщиком. Иначе человек уходит, а работа не начинается вовсе.
          ui.toast(d.cron && d.cron.silent
            ? 'Задача поставлена, но фоновая обработка молчит — смотрите предупреждение'
            : 'Задача запущена — можно закрыть окно', d.cron && d.cron.silent ? 'warn' : 'ok');
        }
        if (action === 'cancel') ui.toast('Остановим после текущей порции', 'ok');
        if (action === 'resolve') ui.toast('Блокировка снята — задачу можно запустить заново', 'ok');
        renderTask(d.task || {}, d.cron);
      });
    }, {
      key: 'task',
      // Сообщение об отказе показываем сами: «уже выполняется» — это не ошибка,
      // а состояние, и человеку надо показать задачу, а не красный тост.
      toast: false,
    }).then(function (res) {
      if (generation !== taskGeneration) return;
      if (res.ok || res.busy) return;
      var e = res.error || {};
      if (e.code === 'task_running' && e.data && e.data.task) {
        renderTask(e.data.task);
        ui.toast('Задача уже выполняется', 'ok');
        return;
      }
      ui.toast(e.message || 'Не удалось выполнить', 'error');
      loadTask(true);
    });
  }

  // ── Настройки ──────────────────────────────────────────────────────────
  // Форма НЕ пишется под каждый ключ: сервер отдаёт описание полей, а здесь по
  // нему создаются свои контролы. Добавили настройку в api/settings-schema.php —
  // поле появилось само, и проверка ввода у него уже есть.
  //
  // Три правила описания, которые фронт обязан соблюдать (см. шапку схемы):
  //   1. пустая строка = «не меняй это поле», осознанная очистка = null;
  //   2. секрет наружу не приходит — виден только признак «задан»;
  //   3. ошибки приходят по полям и показываются у своего поля, а не одной кучей.
  var settingsGeneration = 0;
  var settingsController = null;
  // Отдельная версия пользовательского ввода: GET/POST epoch не видит набор текста.
  var settingsEditGeneration = 0;
  function settingsEdited() { settingsEditGeneration++; }
  var settingsControls = [];      // {key, type, get, isCleared, err, destroy}

  function settingsClearControls() {
    settingsControls.forEach(function (c) { if (c.destroy) c.destroy(); });
    settingsControls = [];
  }

  // Чтение и запись — два действия ОДНОГО владельца UI. Единая epoch не даёт
  // позднему GET перерисовать уже принятый POST, а Abort не тратит ресурсы
  // на ответ, который всё равно не имеет права менять форму.
  function settingsInvalidateLoad() {
    var generation = ++settingsGeneration;
    if (settingsController) settingsController.abort();
    settingsController = null;
    return generation;
  }

  function loadSettings() {
    var generation = settingsInvalidateLoad();
    settingsController = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;

    var box = document.getElementById('settings-form');
    var foot = document.getElementById('settings-foot');
    if (!box) return;
    if (foot) foot.classList.add('hidden');
    ui.showState(box, 'loading', { text: 'Читаем настройки…' });

    ui.api('GET', 'api/settings.php', null, { signal: settingsController && settingsController.signal })
      .then(function (d) {
        if (generation !== settingsGeneration) return;   // пришёл ответ на прошлый заход
        renderSettings(d.fields || []);
      })
      .catch(function (e) {
        if (generation !== settingsGeneration || (e && e.code) === 'aborted') return;
        box.classList.add('state');
        ui.showState(box, 'error', { text: e.message, ref: e.ref, onRetry: loadSettings });
      });
  }

  function renderSettings(fields) {
    var box = document.getElementById('settings-form');
    var foot = document.getElementById('settings-foot');
    settingsClearControls();
    box.classList.remove('state');
    box.innerHTML = '';

    if (!fields.length) {
      ui.showState(box, 'empty', { text: 'У приложения пока нет настраиваемых полей.' });
      return;
    }

    fields.forEach(function (f) {
      // div, а не label. Внутри строки лежат СВОИ контролы — степпер числа,
      // переключатель, свой список. <label> без for отдаёт клик первому
      // элементу внутри себя, поэтому клик по подписи числового поля нажимал
      // кнопку «−» и молча уменьшал значение (находка ревью 09.08.2026).
      var row = document.createElement('div');
      row.className = 'field';
      row.dataset.key = f.key;

      // Ключ настройки приходит с сервера — в id его пускаем только через
      // фильтр: пробел или кавычка в id ломают ссылку aria-labelledby молча.
      var safe = String(f.key).replace(/[^A-Za-z0-9_-]/g, '_');
      var title = document.createElement('span');
      title.id = 'set-l-' + safe;
      title.textContent = f.title || f.key;
      row.appendChild(title);

      var slot = document.createElement('div');
      slot.className = 'field-slot';
      row.appendChild(slot);

      var hintId = '';
      if (f.hint) {
        var hint = document.createElement('div');
        hint.className = 'field-hint';
        hint.id = 'set-h-' + safe;
        hintId = hint.id;
        hint.textContent = f.hint;
        row.appendChild(hint);
      }

      // Подпись и подсказка связываются с самим полем: рядом лежащий текст
      // именем контрола не становится, а отказ без aria-invalid для диктора
      // не существует вовсе (находка ревью 09.08.2026).
      settingsControls.push(buildSettingField(f, slot, {
        labelledBy: title.id,
        describedBy: hintId || undefined,
      }));
      box.appendChild(row);
    });

    if (foot) foot.classList.remove('hidden');
    if (window.appFrame) appFrame.sync();
  }

  // Один контрол на тип. Системных полей здесь нет: ни select, ни number, ни
  // спиннера браузера — внутри тёмного портала они всплывают белым.
  function buildSettingField(f, slot, podpisi) {
    var control = { key: f.key, type: f.type, isCleared: function () { return false; } };
    var obshie = { labelledBy: podpisi.labelledBy, describedBy: podpisi.describedBy };

    if (f.type === 'bool') {
      var sw = ui.switchBox(slot, { labelledBy: obshie.labelledBy,
        describedBy: obshie.describedBy, value: !!f.value, label: '', onChange: settingsEdited });
      control.get = function () { return sw.get(); };
      control.setError = sw.setError;
      control.destroy = sw.destroy;
      return control;
    }
    if (f.type === 'select') {
      var options = Object.keys(f.options || {}).map(function (code) {
        return { value: code, label: String(f.options[code]) };
      });
      var sel = ui.select(slot, { labelledBy: obshie.labelledBy,
        describedBy: obshie.describedBy, options: options, value: f.value, onChange: settingsEdited });
      control.get = function () { return sel.get(); };
      control.setError = sel.setError;
      control.destroy = sel.destroy;
      return control;
    }
    if (f.type === 'number') {
      var num = ui.number(slot, { labelledBy: obshie.labelledBy,
        describedBy: obshie.describedBy, value: f.value, min: f.min, max: f.max, onChange: settingsEdited });
      // ui.number нормализует по blur; сырой набор тоже уже является новым вводом.
      num.el.addEventListener('input', settingsEdited);
      control.get = function () { return num.get(); };
      control.setError = num.setError;
      control.destroy = num.destroy;
      return control;
    }

    // text / multiline / secret — своё поле ввода плюс кнопка осознанной очистки.
    var multiline = f.type === 'multiline';
    var input = document.createElement(multiline ? 'textarea' : 'input');
    input.className = 'input';
    // У textarea свойство type только для чтения: присваивание роняет весь
    // разбор описания, и экран показывает ошибку вместо формы.
    if (multiline) {
      input.rows = 4;
      input.value = f.value == null ? '' : String(f.value);
    } else if (f.type === 'secret') {
      input.type = 'password';
      input.autocomplete = 'new-password';
      input.placeholder = f.isSet ? 'Ключ задан — оставьте пустым, чтобы не менять' : 'Ключ не задан';
    } else {
      input.type = 'text';
      input.value = f.value == null ? '' : String(f.value);
    }
    input.addEventListener('input', settingsEdited);
    slot.appendChild(input);
    // Своё поле — тот же контракт, что у готовых контролов: имя от подписи,
    // подсказка через describedby, отказ через aria-invalid и role="alert".
    var dost = ui.fieldA11y(input, obshie, slot);
    control.setError = dost.setError;

    // Пустое поле означает «не менять», поэтому стереть значение можно только
    // отдельным действием. Иначе человек стирал текст, жал «Сохранить» и не
    // понимал, почему прежнее значение осталось.
    var cleared = false;
    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'btn btn-sm';
    clear.textContent = 'Очистить';
    clear.onclick = function () {
      settingsEdited();
      cleared = !cleared;
      input.value = '';
      input.disabled = cleared;
      clear.classList.toggle('is-active', cleared);
      clear.textContent = cleared ? 'Не очищать' : 'Очистить';
    };
    slot.appendChild(clear);

    control.get = function () { return cleared ? null : input.value; };
    control.isCleared = function () { return cleared; };
    control.destroy = function () { slot.innerHTML = ''; };
    return control;
  }

  var settingsSaveBtn = document.getElementById('settings-save');
  if (settingsSaveBtn) settingsSaveBtn.onclick = function () {
    var editGeneration = settingsEditGeneration;
    var hasUnsavedEdits = false;
    var payload = {};
    settingsControls.forEach(function (c) {
      if (c.setError) c.setError('');
      var v = c.get();
      // Пустую строку не шлём вовсе: сервер трактует её как «не трогать», и
      // отправлять её значит зря гонять поле через проверку.
      if (v === '' && !c.isCleared()) return;
      payload[c.key] = v;
    });

    var note = document.getElementById('settings-note');
    if (note) note.textContent = 'Сохраняем…';

    // Начало mutation отменяет ранее начатый read. Если позже начнётся новый
    // read, его epoch также защитит свежую форму от запоздалого отказа POST.
    var mutationGeneration = settingsInvalidateLoad();

    // Сообщение об ошибке здесь своё (оно раскладывается по полям формы),
    // поэтому общий показ отключаем: два сообщения об одном отказе сбивают.
    ui.action(settingsSaveBtn, function () {
      return ui.api('POST', 'api/settings.php', { settings: payload })
        .then(function (d) {
          // Принятая запись авторитетна: она закрывает и GET, начатый уже
          // после клика «Сохранить», но успевший прочитать старое состояние.
          mutationGeneration = settingsInvalidateLoad();
          var saved = (d.saved || []).length;
          ui.toast(saved ? 'Сохранено полей: ' + saved : 'Менять было нечего', 'ok');
          // Ответ описывает снимок при клике, а не последующий ввод человека.
          hasUnsavedEdits = editGeneration !== settingsEditGeneration;
          if (!hasUnsavedEdits) renderSettings(d.fields || []);
        })
        .catch(function (e) {
          if (mutationGeneration !== settingsGeneration) return;
          // Ошибки приходят по полям — показываем каждую у своего поля, а не
          // одной строкой внизу: иначе в форме из восьми полей человек ищет,
          // какое именно не приняли.
          var byField = (e.data && e.data.fields) || null;
          if (byField) {
            settingsControls.forEach(function (c) {
              if (!byField[c.key] || !c.setError) return;
              c.setError(byField[c.key]);
            });
            ui.toast('Проверьте выделенные поля', 'error');
          } else {
            ui.toast(e.message, 'error');
          }
        });
    }, { toast: false, busyText: 'Сохраняем…' }).then(function () {
      if (note) note.textContent = hasUnsavedEdits ? 'Есть несохранённые изменения — сохраните ещё раз.' : '';
      if (window.appFrame) appFrame.sync();
    });
  };

  // ── Журнал ─────────────────────────────────────────────────────────────
  var logGeneration = 0;
  var logController = null;
  var logCursor = '';
  var logBack = [];
  var logNextCursor = '';
  var logFilterKey = '';

  function paintLogPager(pageSize) {
    var prev = document.getElementById('log-prev');
    var next = document.getElementById('log-next');
    var status = document.getElementById('log-page-status');
    if (prev) prev.disabled = logBack.length === 0;
    if (next) next.disabled = !logNextCursor;
    if (status) status.textContent = 'Страница ' + (logBack.length + 1) +
      ' · до ' + (pageSize || 25) + ' записей';
  }

  function loadLog() {
    var generation = ++logGeneration;
    if (logController) logController.abort();
    logController = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;

    var body = document.querySelector('#log-table tbody');
    var selectedDay = daySelect ? daySelect.get() : '';
    var selectedLevel = levelSelect ? levelSelect.get() : 'debug';
    var selectedQuery = searchBox ? searchBox.get() : '';
    var filterKey = selectedDay + '|' + selectedLevel + '|' + selectedQuery;
    if (filterKey !== logFilterKey) {
      logCursor = ''; logBack = []; logNextCursor = ''; logFilterKey = filterKey;
    }
    var params = '?level=' + selectedLevel +
                 '&q=' + encodeURIComponent(selectedQuery) +
                 (selectedDay ? '&day=' + selectedDay : '') +
                 (logCursor ? '&cursor=' + encodeURIComponent(logCursor) : '');
    body.innerHTML = '<tr><td colspan="3"><div class="state"><div class="spinner"></div>Читаем журнал…</div></td></tr>';
    ui.api('GET', 'api/logs.php' + params, null, {
      signal: logController ? logController.signal : null
    }).then(function (d) {
      if (generation !== logGeneration) return;
      // Список дней пересобираем на каждом ответе и возвращаем выбранное значение:
      // иначе выпадашка показывала день, а таблица — «записей нет» за другой.
      // Опорное значение — тот день, который сервер РЕАЛЬНО прочитал (d.day).
      // Раньше здесь брался самый свежий день из списка, и в частом случае «сегодня
      // ещё пусто, вчера есть записи» человек видел в списке вчерашнюю дату, а в
      // таблице — пустой сегодняшний ответ, и никакого способа это понять.
      var cur = selectedDay || d.day || (d.days[0] || '');
      var days = d.days.slice();
      // Показанного дня может не быть в списке (сегодня записей ещё нет) — тогда
      // добавляем его сами, иначе выпадашка молча покажет чужую дату.
      if (cur && days.indexOf(cur) < 0) days.unshift(cur);
      if (days.length && daySelect) {
        daySelect.setOptions(days.map(function (x) { return { value: x, label: x }; }));
        daySelect.set(cur);
      }
      // Первый ответ канонизирует пустой день в фактически прочитанный. Иначе
      // первый клик «Дальше» счёл бы это сменой фильтра и вернул страницу 1.
      logFilterKey = cur + '|' + selectedLevel + '|' + selectedQuery;
      logNextCursor = String(d.next_cursor || '');
      paintLogPager(d.page_size || 25);
      if (!d.rows.length) {
        // Фильтр включён — пустота значит «не подошло», а не «ничего не было»
        // (ревью 23.09.2026, ux_sboev-2): прежде экран уверял, что за день ничего
        // не происходило, и человек уходил с ложным выводом.
        var filtered = selectedLevel !== 'debug' || selectedQuery !== '';
        body.innerHTML = d.truncated
          ? '<tr><td colspan="3"><div class="state"><b>Журнал прочитан не полностью</b>' +
            'В доступной части подходящих записей нет.</div></td></tr>'
          : filtered
            ? '<tr><td colspan="3"><div class="state"><b>Под фильтр ничего не попало</b>' +
              'Записи за этот день есть, но уровень или поиск их отсекли.' +
              '<button class="btn btn-sm" id="log-reset">Сбросить фильтры</button></div></td></tr>'
            : '<tr><td colspan="3"><div class="state"><b>Записей нет</b>За этот день ничего не происходило.</div></td></tr>';
        var reset = document.getElementById('log-reset');
        if (reset) reset.onclick = function () {
          if (levelSelect) levelSelect.set('debug');
          if (searchBox) searchBox.set('');
          loadLog();
        };
        return;
      }
      var incomplete = d.truncated
        ? '<tr><td colspan="3"><div class="state"><span class="badge badge-warn">неполный журнал</span>' +
          '<b>Показана только прочитанная часть</b>' +
          'Часть строк повреждена или превышен предел чтения.</div></td></tr>'
        : '';
      // Раскладываем по ТЕМ ЖЕ колонкам, что объявлены в шапке: «Когда · Что ·
      // Подробности». Раньше вся запись жила в одной ячейке под тремя
      // заголовками — таблица занимала треть ширины, две колонки стояли пустыми,
      // а заголовки обещали разбивку, которой не было.
      body.innerHTML = incomplete + d.rows.map(function (r) {
        var color = r.level === 'error' ? 'danger' : (r.level === 'warn' ? 'warn' : 'ok');
        return '<tr>' +
          '<td style="white-space:nowrap;font-family:var(--font-mono);color:var(--text-dim)">' +
            ui.esc(String(r.ts).slice(11, 19)) +
          '</td>' +
          '<td><span class="badge badge-' + color + '">' + ui.esc(r.level) + '</span> ' +
            '<b style="font-weight:500">' + ui.esc(r.event) + '</b></td>' +
          '<td style="color:var(--text-dim)">' + ui.esc(r.msg || '') +
            (r.uid ? ' · сотрудник №' + r.uid : '') +
            ' · <span style="font-family:var(--font-mono)">' + ui.esc(r.ref) + '</span>' +
          '</td></tr>';
      }).join('');
    }).catch(function (e) {
      if (generation !== logGeneration || (e && e.code) === 'aborted') return;
      if ((e && e.code) === 'journal_cursor') {
        logCursor = ''; logBack = []; logNextCursor = '';
        loadLog();
        return;
      }
      ui.showState(body, 'error', { text: e.message, ref: e.ref, onRetry: loadLog });
    }).then(function () {
      if (generation === logGeneration) logController = null;
    });
  }
  // Фильтры журнала — свои контролы: системная выпадашка внутри тёмного портала
  // всплывает белым прямоугольником, а у поиска браузер рисует свой крестик.
  var daySelect = null, levelSelect = null, searchBox = null;
  // Каждому фильтру — имя: подписей у них на экране нет (место в панели), и без
  // label человек на слух получает три безымянных списка подряд.
  if (document.getElementById('log-day')) {
    daySelect = ui.select('#log-day', {
      label: 'День журнала', options: [], placeholder: 'День', onChange: loadLog,
    });
  }
  if (document.getElementById('log-level')) {
    levelSelect = ui.select('#log-level', {
      label: 'Уровень записей',
      value: 'debug',
      options: [
        { value: 'debug', label: 'Все записи' },
        { value: 'info',  label: 'Информация и важнее' },
        { value: 'warn',  label: 'Предупреждения и ошибки' },
        { value: 'error', label: 'Только ошибки' },
      ],
      onChange: loadLog,
    });
  }
  if (document.getElementById('log-q')) {
    searchBox = ui.search('#log-q', {
      label: 'Поиск по журналу', placeholder: 'Поиск по тексту', onInput: loadLog,
    });
  }
  var logPrev = document.getElementById('log-prev');
  var logNext = document.getElementById('log-next');
  if (logPrev) logPrev.onclick = function () {
    if (!logBack.length) return;
    logCursor = logBack.pop() || '';
    logNextCursor = '';
    loadLog();
  };
  if (logNext) logNext.onclick = function () {
    if (!logNextCursor) return;
    logBack.push(logCursor);
    logCursor = logNextCursor;
    logNextCursor = '';
    loadLog();
  };
  paintLogPager(25);

  // ── ЭКРАН ПРОЕКТА ──────────────────────────────────────────────────────
  // Здесь начинается приложение. Портал уже опознал человека, пропуск выдан,
  // роль известна — можно сразу дёргать свои эндпоинты через ui.api().
  // SDK нужен только для корректной инициализации iframe; прикладной REST
  // фронт вызывает только через ui.api() и серверные готовые модули.
  if (window.BX24) BX24.init(function () { paintHeaderPortal(); });

  // Шапка: имя приложения и портал вместо заглушек «Приложение» и «портал»
  // (ревью 23.09.2026, ux_sboev-6). Имя берём из <title> template.html — его
  // проект обязан назвать своим; домен — у SDK портала.
  function paintHeaderPortal() {
    var portal = document.getElementById('app-portal');
    try {
      var domain = window.BX24 && typeof BX24.getDomain === 'function' ? BX24.getDomain() : '';
      if (portal && domain) portal.textContent = String(domain);
    } catch (e) { /* вне портала домена нет — остаётся подпись из разметки */ }
  }
  var appName = document.getElementById('app-name');
  if (appName && document.title) appName.textContent = document.title;

  // Совместимость: прежний код проектов зовёт window.api(...).
  window.api = ui.api;
})();
