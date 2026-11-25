<?php
declare(strict_types=1);
/**
 * UX СБОЕВ: что видит человек, когда что-то пошло не так (ревью 23.09.2026, ux_sboev-*).
 *
 * Каждая проверка гоняет НАСТОЯЩИЙ фронтовой файл шаблона в node:vm с подменённым
 * DOM и транспортом, а не копию функции: копия проходила бы и на сломанном файле.
 */
suite('UX сбоев: исход ошибки понятен и из него есть выход');

$uxNode = trim((string)@shell_exec('command -v node 2>/dev/null'));
$uxWww = dirname(__DIR__) . '/www';

/**
 * Прогнать JS-стенд над файлами фронта; вернуть строки вывода и код.
 * Стенд печатает «OK:<имя>» на каждую пройденную проверку и «FAIL:<имя>:<почему>».
 */
$uxRun = static function (string $harness, array $args) use ($uxNode): array {
    if ($uxNode === '') return ['rc' => 127, 'out' => ['нет node']];
    $file = DATA_ROOT . '/ux-harness-' . bin2hex(random_bytes(4)) . '.js';
    file_put_contents($file, $harness);
    $out = []; $rc = 1;
    @exec(escapeshellarg($uxNode) . ' ' . escapeshellarg($file) . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
    @unlink($file);
    return ['rc' => $rc, 'out' => $out];
};
$uxOk = static function (array $r, string $name): bool {
    return in_array('OK:' . $name, $r['out'], true);
};
$uxWhy = static function (array $r, string $name): string {
    foreach ($r['out'] as $line) if (strpos($line, 'FAIL:' . $name) === 0 || strpos($line, 'Error') !== false) return $line;
    return implode(' | ', array_slice($r['out'], -3));
};

// ── ui.js: общий стенд ───────────────────────────────────────────────────────
$uiHarness = <<<'JS'
const fs = require('fs');
const vm = require('vm');
function flush() { return new Promise(function (r) { setImmediate(r); }); }

function node(tag) {
  const n = {
    tagName: tag || 'DIV', children: [], style: {}, attrs: {}, innerHTML: '', textContent: '',
    className: '', parentNode: null, _q: {},
    classList: { add() {}, remove() {}, toggle() {} },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; },
    addEventListener() {}, removeEventListener() {},
    querySelector(sel) {
      if (sel === '[data-retry]' && /data-retry/.test(this.innerHTML)) {
        this._retry = this._retry || { onclick: null, sel };
        return this._retry;
      }
      return null;
    },
  };
  return n;
}

function makeUi(opts) {
  opts = opts || {};
  const calls = { scroll: [], reload: 0 };
  const body = node('BODY');
  let toasts = null;
  const document = {
    body,
    activeElement: null,
    getElementById(id) { return id === 'toasts' ? toasts : null; },
    createElement(tag) { const n = node(String(tag).toUpperCase()); return n; },
    querySelector() { return null; },
    addEventListener() {}, removeEventListener() {},
    contains() { return true; },
  };
  const origAppend = body.appendChild.bind(body);
  body.appendChild = function (c) { if (c.id === 'toasts') toasts = c; return origAppend(c); };
  const window = {
    BX24: {
      scrollParentWindow(y) { calls.scroll.push(y); },
      reloadWindow() { calls.reload++; },
    },
    matchMedia() { return { matches: true }; },
    setTimeout: function () { return 0; }, clearTimeout() {},
    AbortController,
    fetch: opts.fetch,
  };
  if (opts.io) window.IntersectionObserver = opts.io;
  const ctx = { window, document, Promise, AbortController, console, setTimeout: function () { return 0; },
    clearTimeout() {}, fetch: opts.fetch, Error, JSON, String, Number, isFinite, Math };
  if (opts.io) ctx.IntersectionObserver = opts.io;
  vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: process.argv[2] });
  return { ui: window.ui, window, document, calls, getToasts: () => toasts };
}

const res = (status, data) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });

(async function () {
  // ux_sboev-7: истёкший пропуск — не «только из Bitrix24» и не вечное «Повторить».
  {
    const t = makeUi({ fetch: () => res(403, { ok: false, code: 'no_session',
      error: 'Доступ только из Bitrix24. Откройте приложение в левом меню портала.' }) });
    let err = null;
    try { await t.ui.api('GET', 'api/acl.php'); } catch (e) { err = e; }
    const box = node('DIV');
    t.ui.showState(box, 'error', { text: err && err.message, onRetry: function () { throw new Error('повтор того же запроса'); } });
    const btn = box.querySelector('[data-retry]');
    const ok = err && /Сеанс/.test(err.message) && !/только из Bitrix24/.test(err.message)
      && /Открыть заново/.test(box.innerHTML) && !/Повторить/.test(box.innerHTML) && btn && typeof btn.onclick === 'function';
    if (ok) btn.onclick();
    console.log(ok && t.calls.reload === 1 ? 'OK:session' : 'FAIL:session:' + JSON.stringify({
      msg: err && err.message, html: box.innerHTML, reload: t.calls.reload }));
  }
  // Обычная ошибка по-прежнему даёт «Повторить».
  {
    const t = makeUi({ fetch: () => res(500, { ok: false, code: 'internal', error: 'Сбой' }) });
    let err = null;
    try { await t.ui.api('GET', 'api/acl.php'); } catch (e) { err = e; }
    const box = node('DIV');
    t.ui.showState(box, 'error', { text: err.message, onRetry: function () {} });
    console.log(/Повторить/.test(box.innerHTML) && !/Открыть заново/.test(box.innerHTML)
      ? 'OK:retry-kept' : 'FAIL:retry-kept:' + box.innerHTML);
  }
  // ux_sboev-5: тост не прокручивает портал, а встаёт в видимую часть окна.
  {
    const IO = function (cb) {
      this.observe = function (el) {
        setImmediate(function () { cb([{ target: el, isIntersecting: true,
          intersectionRect: { top: 1200, height: 700 }, boundingClientRect: { top: 0 } }]); });
      };
      this.disconnect = function () {};
    };
    const t = makeUi({ io: IO });
    t.ui.toast('Сохранено', 'ok');
    await flush(); await flush();
    const box = t.getToasts();
    const ok = t.calls.scroll.length === 0 && box && box.style.position === 'absolute' && box.style.top === '1216px';
    console.log(ok ? 'OK:toast-noscroll' : 'FAIL:toast-noscroll:' + JSON.stringify({ scroll: t.calls.scroll, style: box && box.style }));
  }
  // Без IntersectionObserver — прежний безопасный путь: вернуть портал к началу.
  {
    const t = makeUi({});
    t.ui.toast('Сохранено', 'ok');
    await flush();
    console.log(t.calls.scroll.length === 1 ? 'OK:toast-fallback' : 'FAIL:toast-fallback:' + JSON.stringify(t.calls.scroll));
  }
})().catch(function (e) { console.log('FAIL:harness:' + (e && e.stack || e)); process.exitCode = 1; });
JS;

$uiRes = $uxRun($uiHarness, [$uxWww . '/js/ui.js']);
check('ux: истёкший пропуск объяснён и даёт «Открыть заново» через BX24.reloadWindow',
    $uxOk($uiRes, 'session'), $uxWhy($uiRes, 'session'));
check('ux: обычная ошибка по-прежнему даёт «Повторить»',
    $uxOk($uiRes, 'retry-kept'), $uxWhy($uiRes, 'retry-kept'));
check('ux: тост встаёт в видимую часть окна и не прокручивает портал',
    $uxOk($uiRes, 'toast-noscroll'), $uxWhy($uiRes, 'toast-noscroll'));
check('ux: без IntersectionObserver тост возвращает портал к началу, как прежде',
    $uxOk($uiRes, 'toast-fallback'), $uxWhy($uiRes, 'toast-fallback'));

// ── ux_sboev-1: задача с неизвестным исходом — есть выход после ручной проверки ──
require_once dirname(__DIR__) . '/www/api/task-state.php';
$uxSeedUnknown = static function (string $id, int $beatAgo): void {
    taskStoreUpdate(static function (array $prev) use ($id, $beatAgo): array {
        $t = taskEmpty();
        $t['id'] = $id; $t['status'] = 'running'; $t['startedAt'] = time() - 7200;
        $t['heartbeat'] = time() - $beatAgo; $t['done'] = 40; $t['total'] = 100;
        $t['step'] = ['key' => $id . ':abc', 'attempt' => 'att-' . $id, 'at' => time() - $beatAgo, 'cursor' => 40];
        return $t;
    });
};
$uxSeedUnknown('ux-live', 5);
$uxAliveRefused = function_exists('taskResolveManually') ? !taskResolveManually('ux-live', 7, 'проверено') : false;
$uxAliveState = taskRead();
$uxSeedUnknown('ux-dead', 86400);
$uxWrongIdRefused = function_exists('taskResolveManually') ? !taskResolveManually('чужая', 7, 'проверено') : false;
$uxResolved = function_exists('taskResolveManually') ? taskResolveManually('ux-dead', 7, 'проверено в портале') : false;
$uxAfter = taskRead();
$uxClient = taskForClient();
check('ux: живую порцию вручную снять нельзя — рабочий ещё может её закончить',
    $uxAliveRefused && ($uxAliveState['step']['attempt'] ?? '') === 'att-ux-live',
    json_encode(['refused' => $uxAliveRefused, 'status' => $uxAliveState['status'] ?? null]));
check('ux: ручное снятие unknown сверяет номер задачи',
    $uxWrongIdRefused, 'чужой id снял блокировку');
check('ux: после ручной проверки unknown снимается, задачу можно запустить заново',
    $uxResolved && ($uxAfter['step'] ?? null) === null && ($uxClient['status'] ?? '') === 'failed'
    && (($uxAfter['reconciliation']['outcome'] ?? '') === 'manual')
    && (int)($uxAfter['reconciliation']['uid'] ?? 0) === 7,
    json_encode(['resolved' => $uxResolved, 'status' => $uxClient['status'] ?? null,
        'rec' => $uxAfter['reconciliation'] ?? null]));
$uxTaskApi = (string)file_get_contents(dirname(__DIR__) . '/www/api/task.php');
check('ux: api/task.php принимает «resolve» только от администратора и пишет аудит',
    strpos($uxTaskApi, "'resolve'") !== false && strpos($uxTaskApi, "task.resolve") !== false
    && preg_match("~resolve[\\s\\S]{0,400}roleAtLeast\\(\\\$ctx\\['role'\\], 'admin'\\)~u", $uxTaskApi) === 1,
    'нет ветки resolve с ролью admin и auditLog');

// ── app.js: общий стенд экрана ───────────────────────────────────────────────
$appHarness = <<<'JS'
const fs = require('fs');
const vm = require('vm');
function flush() { return new Promise(function (r) { setImmediate(r); }); }
function mk(id) {
  return { id, innerHTML: '', textContent: '', className: '', hidden: false, disabled: false, style: {},
    dataset: {}, children: [], classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
    setAttribute() {}, removeAttribute() {}, appendChild(c) { this.children.push(c); return c; },
    querySelector() { return null; }, querySelectorAll() { return []; }, addEventListener() {}, onclick: null };
}
function stand(opts) {
  opts = opts || {};
  const nodes = {};
  const byId = (id) => (nodes[id] = nodes[id] || mk(id));
  const apiCalls = [], states = [], confirms = [];
  const sel = {};
  const document = {
    title: opts.title || 'Приложение',
    getElementById: byId,
    querySelector(s) { return s === '#log-table tbody' ? byId('log-body') : null; },
    querySelectorAll() { return []; },
    createElement(t) { return mk('new-' + t); },
  };
  const ui = {
    api(method, path, body) {
      apiCalls.push({ method, path, body });
      const h = opts.api && opts.api(method, path, body);
      return h || new Promise(function () {});
    },
    esc(v) { return String(v == null ? '' : v); },
    toast() {},
    confirmBox(o) { confirms.push(o); return Promise.resolve(true); },
    action(btn, work) { return Promise.resolve().then(work).then(() => ({ ok: true })); },
    showState(target, state, o) { states.push({ target, state, o: o || {} }); },
    select(s, o) { sel[s] = o.value || ''; return { get: () => sel[s], set(v) { sel[s] = v; }, setOptions() {} }; },
    search(s) { sel[s] = ''; return { get: () => sel[s], set(v) { sel[s] = v; } }; },
    pickPeople() { return { get: () => [], set() {}, setError() {} }; },
    number(t, o) { return { get: () => (o && o.value) || 0, set() {}, setError() {} }; },
    switchBox() { return { get: () => false, set() {}, setError() {} }; },
  };
  const window = { APP_ROLE: opts.role || 'admin', AbortController,
    BX24: { init(cb) { cb(); }, getDomain() { return 'firma.bitrix24.ru'; } } };
  const src = fs.readFileSync(process.argv[2], 'utf8').replace(/\}\)\(\);\s*$/,
    'window.__t = { renderTask, loadService, loadLog, sel: function(){}}; })();');
  vm.runInNewContext(src, { window, document, ui, Promise, AbortController, console, setTimeout() { return 0; },
    clearTimeout() {}, encodeURIComponent, isFinite, Number, BX24: window.BX24 }, { filename: process.argv[2] });
  return { t: window.__t, byId, apiCalls, states, confirms, sel };
}
(async function () {
  // ux-1: unknown — человеческий текст и кнопка снятия для администратора.
  {
    const s = stand({ role: 'admin' });
    s.t.renderTask({ id: 't1', status: 'unknown', done: 40, total: 100, needsReconciliation: true,
      unknownStep: { key: 't1:abc', at: 1 } });
    const html = s.byId('task-box').innerHTML;
    const btn = s.byId('task-resolve');
    let ok = /не знает, успела ли/.test(html) && !/read-back/.test(html) && /id="task-resolve"/.test(html)
      && typeof btn.onclick === 'function';
    if (ok) { btn.onclick({ currentTarget: btn }); await flush(); await flush(); }
    const post = s.apiCalls.find((c) => c.method === 'POST');
    ok = ok && s.confirms.length === 1 && post && post.body.do === 'resolve' && post.body.id === 't1';
    console.log(ok ? 'OK:unknown-exit' : 'FAIL:unknown-exit:' + JSON.stringify({ html, post, c: s.confirms.length }));
  }
  {
    const s = stand({ role: 'edit' });
    s.t.renderTask({ id: 't1', status: 'unknown', needsReconciliation: true, unknownStep: { key: 'k' } });
    const html = s.byId('task-box').innerHTML;
    console.log(!/id="task-resolve"/.test(html) && /администратор/.test(html)
      ? 'OK:unknown-nonadmin' : 'FAIL:unknown-nonadmin:' + html);
  }
  // ux-3: служебный блок после сбоя — стандартная ошибка с «Повторить».
  {
    const s = stand({ api: (m, p) => p === 'api/health.php' ? Promise.reject(Object.assign(new Error('Портал не ответил'), { ref: 'R1' })) : null });
    s.t.loadService(); await flush(); await flush();
    const st = s.states.find((x) => x.target && x.target.id === 'service-box' && x.state === 'error');
    console.log(st && typeof st.o.onRetry === 'function' && st.o.ref === 'R1'
      ? 'OK:service-retry' : 'FAIL:service-retry:' + JSON.stringify(s.states.map((x) => x.state)));
  }
  // ux-2: пустой журнал под фильтром — не «ничего не происходило», а сброс фильтра.
  {
    const s = stand({ api: (m, p) => /api\/logs\.php/.test(p) ? Promise.resolve({ rows: [], days: ['2026-09-23'], day: '2026-09-23', next_cursor: '' }) : null });
    s.sel['#log-level'] = 'error';
    s.t.loadLog(); await flush(); await flush();
    const html = s.byId('log-body').innerHTML;
    const reset = s.byId('log-reset');
    let ok = /Под фильтр ничего не попало/.test(html) && !/ничего не происходило/.test(html)
      && /id="log-reset"/.test(html) && typeof reset.onclick === 'function';
    if (ok) { reset.onclick(); await flush(); await flush(); }
    ok = ok && s.sel['#log-level'] === 'debug';
    const s2 = stand({ api: (m, p) => /api\/logs\.php/.test(p) ? Promise.resolve({ rows: [], days: [], day: '2026-09-23', next_cursor: '' }) : null });
    s2.t.loadLog(); await flush(); await flush();
    ok = ok && /За этот день ничего не происходило/.test(s2.byId('log-body').innerHTML);
    console.log(ok ? 'OK:log-filter' : 'FAIL:log-filter:' + html);
  }
  // ux-6: шапка — имя из <title>, домен портала из BX24.getDomain().
  {
    const s = stand({ title: 'Сделки без хвостов' });
    await flush();
    const ok = s.byId('app-name').textContent === 'Сделки без хвостов'
      && s.byId('app-portal').textContent === 'firma.bitrix24.ru';
    console.log(ok ? 'OK:header' : 'FAIL:header:' + JSON.stringify([s.byId('app-name').textContent, s.byId('app-portal').textContent]));
  }
})().catch(function (e) { console.log('FAIL:harness:' + (e && e.stack || e)); process.exitCode = 1; });
JS;
$appRes = $uxRun($appHarness, [$uxWww . '/js/app.js']);
check('ux: unknown-задача объяснена по-человечески и снимается администратором после проверки',
    $uxOk($appRes, 'unknown-exit'), $uxWhy($appRes, 'unknown-exit'));
check('ux: не-администратору unknown объясняет, к кому идти',
    $uxOk($appRes, 'unknown-nonadmin'), $uxWhy($appRes, 'unknown-nonadmin'));
check('ux: сбой служебного блока показывает ошибку с «Повторить» и кодом обращения',
    $uxOk($appRes, 'service-retry'), $uxWhy($appRes, 'service-retry'));
check('ux: пустой журнал под фильтром говорит о фильтре и даёт его сбросить',
    $uxOk($appRes, 'log-filter'), $uxWhy($appRes, 'log-filter'));
check('ux: шапка показывает имя приложения и домен портала, а не заглушки',
    $uxOk($appRes, 'header'), $uxWhy($appRes, 'header'));

// ── ux_sboev-4: числовое поле не подменяет непонятый ввод молча ──────────────
$numHarness = <<<'JS'
const fs = require('fs');
const vm = require('vm');
function mk(tag) {
  const n = { tagName: tag, children: [], attrs: {}, style: {}, value: '', textContent: '', className: '',
    disabled: false, listeners: {},
    setAttribute(k, v) { this.attrs[k] = String(v); }, getAttribute(k) { return this.attrs[k] || null; },
    removeAttribute(k) { delete this.attrs[k]; },
    appendChild(c) { this.children.push(c); c.parent = this; return c; },
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((x) => x !== this); },
    addEventListener(e, f) { (this.listeners[e] = this.listeners[e] || []).push(f); },
    fire(e, ev) { (this.listeners[e] || []).forEach((f) => f(ev || { preventDefault() {} })); },
  };
  Object.defineProperty(n, 'innerHTML', { set() { n.children = []; }, get() { return ''; } });
  return n;
}
const mount = mk('DIV');
const document = { createElement: mk, querySelector: () => mount, addEventListener() {}, removeEventListener() {} };
const window = { ui: {} };
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), { window, document, console, Number, Math, isFinite, String },
  { filename: process.argv[2] });
const changes = [];
const n = window.ui.number(mount, { label: 'Порог', value: 500, min: 10, max: 1000, onChange: (v) => changes.push(v) });
const root = mount.children[0];
const inp = root.children[1];
const errText = () => { const e = root.children.find((c) => c.className === 'field-err'); return e ? e.textContent : ''; };
const out = [];
inp.value = ''; inp.fire('blur');
out.push(['пусто', n.get() === 500 && inp.value === '500' && /число от 10 до 1000/.test(errText())]);
inp.value = 'сто'; inp.fire('blur');
out.push(['буквы', n.get() === 500 && /число от 10 до 1000/.test(errText())]);
inp.value = '5000'; inp.fire('blur');
out.push(['за границей', n.get() === 500 && /от 10 до 1000/.test(errText())]);
inp.value = '12,5'; inp.fire('blur');
out.push(['запятая', n.get() === 12.5 && errText() === '']);
inp.value = ' 300 '; inp.fire('blur');
out.push(['пробелы', n.get() === 300 && errText() === '' && changes.join(',') === '12.5,300']);
const bad = out.filter((x) => !x[1]).map((x) => x[0]);
console.log(bad.length ? 'FAIL:number:' + bad.join(',') + ' err=' + errText() + ' v=' + n.get() : 'OK:number');
JS;
$numRes = $uxRun($numHarness, [$uxWww . '/js/controls.js']);
check('ux: числовое поле не подменяет пустой/непонятый/чужой ввод молча и принимает запятую',
    $uxOk($numRes, 'number'), $uxWhy($numRes, 'number'));

// ── ux_sboev-6 (остаток): версия выпуска и индикатор связи оживают из ui.api ─
$connHarness = <<<'JS'
const fs = require('fs');
const vm = require('vm');
function mk(id) { return { id, hidden: true, textContent: '', className: 'conn', attrs: {},
  classList: { _s: new Set(), add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); },
    toggle(c, on) { on ? this._s.add(c) : this._s.delete(c); }, contains(c) { return this._s.has(c); } },
  setAttribute(k, v) { this.attrs[k] = v; } }; }
const nodes = { conn: mk('conn'), 'conn-text': mk('conn-text'), 'app-version': mk('app-version') };
nodes['app-version'].textContent = '';
const document = { getElementById: (id) => nodes[id] || null, body: null, createElement: () => mk('x') };
let mode = 'ok';
const fetch = () => mode === 'net'
  ? Promise.reject(new TypeError('Failed to fetch'))
  : Promise.resolve({ ok: true, status: 200, headers: { get: (h) => /x-app-release/i.test(h) ? '20260923-1200' : null },
      json: () => Promise.resolve({ ok: true }) });
const window = { fetch, AbortController, setTimeout, clearTimeout };
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), { window, document, fetch, AbortController, console,
  setTimeout, clearTimeout, Promise, Error, JSON, String, Number, isFinite, Math }, { filename: process.argv[2] });
(async function () {
  await window.ui.api('GET', 'api/x.php');
  const okState = !nodes.conn.hidden && nodes.conn.classList.contains('is-ok') && /20260923-1200/.test(nodes['app-version'].textContent);
  mode = 'net';
  try { await window.ui.api('GET', 'api/x.php'); } catch (e) {}
  const errState = nodes.conn.classList.contains('is-err') && !nodes.conn.classList.contains('is-ok');
  console.log(okState && errState ? 'OK:conn' : 'FAIL:conn:' + JSON.stringify({ okState, errState,
    v: nodes['app-version'].textContent, hidden: nodes.conn.hidden, cls: [...nodes.conn.classList._s] }));
})().catch((e) => console.log('FAIL:conn:' + e));
JS;
$connRes = $uxRun($connHarness, [$uxWww . '/js/ui.js']);
check('ux: индикатор связи и версия выпуска в шапке живые, а не заглушки',
    $uxOk($connRes, 'conn'), $uxWhy($connRes, 'conn'));
