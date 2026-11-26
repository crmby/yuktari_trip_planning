import { FUNNELS, ROUTES, STATUSES, DEMO_DATE, minutes, time, validDate, shiftFor, conflicts, findSlot, createDemoState, migrateDemoState, groupsFor, routesFor, typesFor } from './planner.js';

import { api, runtime } from './bridge.js';
const runtimeInfo = await runtime();
const live = runtimeInfo.mode === 'bitrix';
const params = new URLSearchParams(location.search);
let contextId = params.get('dealId');
try { const context = JSON.parse(params.get('placement_options') || '{}'); contextId ||= String(context.ID || context.id || ''); } catch {}
if (!/^[1-9]\d{0,14}$/.test(contextId || '')) contextId = null;
const $ = selector => document.querySelector(selector);
const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const money = (value, currency = 'BYN') => new Intl.NumberFormat('ru-BY', { maximumFractionDigits: 2 }).format(value) + ' ' + escape(currency || 'BYN');
const dateLabel = date => validDate(date) ? new Intl.DateTimeFormat('ru-BY', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`)) : 'Выберите дату';
const STORAGE_KEY = 'yuktari-trip-planning-demo-v1';
let state = live ? (runtimeInfo.state || { schema: 2, revision: 0, mode: 'bitrix', routeOptions: [], masters: [], groups: [], bookings: [], deals: [], audit: [] }) : createDemoState(), editingBooking = null, editingMaster = null, editingGroup = null, pendingConfirm = null;
let month = DEMO_DATE.slice(0, 7), currentView = 'sheet';
const form = $('#booking-form');
const field = name => form.elements.namedItem(name);
const routeOptions = () => live ? state.routeOptions || [] : ROUTES.map(id => ({id,label:id}));
const routeLabel = id => routeOptions().find(r => r.id === id)?.label || id || '—';
let pendingSync = null, dealResults = [], employeeResults = [], calendarCursor = null, calendarLoading = false;


function notify(message, error = false) {
  $('#notice').textContent = message;
  $('#notice').className = 'notice' + (error ? ' error' : '');
  $('#notice').hidden = false;
}
function readStorage() {
  const text = localStorage.getItem(STORAGE_KEY);
  if (text === null) return null;
  const saved = migrateDemoState(JSON.parse(text));
  if (saved.schema !== 2 || !Number.isInteger(saved.revision) || !Array.isArray(saved.bookings) || !Array.isArray(saved.masters) || !saved.masters.length || !Array.isArray(saved.deals) || !saved.deals.length || !Array.isArray(saved.audit) || !Array.isArray(saved.groups)
    || !saved.masters.every(m => typeof m.name === 'string' && Array.isArray(m.groupIds) && m.week && m.exceptions)
    || !saved.groups.every(g => typeof g.name === 'string' && Array.isArray(g.routes) && Array.isArray(g.types))
    || !saved.deals.every(d => typeof d.client === 'string' && Array.isArray(d.products))
    || !saved.bookings.every(b => typeof b.id === 'string' && validDate(b.date) && Number.isFinite(minutes(b.position)) && Object.hasOwn(STATUSES, b.status))) throw new Error('Некорректный формат демо-данных.');
  return saved;
}
try { if (!live) state = readStorage() || state; } catch { notify('Не удалось прочитать демо-данные. Новые изменения не будут сохранены до восстановления доступа к хранилищу или сброса демо.', true); }

async function commit(change, message) {
  if (live) return commitLive(change, message);
  let latest;
  try { latest = readStorage(); } catch { notify('Хранилище недоступно или данные повреждены. Сохранение не выполнено. Можно сбросить демо.', true); return false; }
  if ((latest?.revision ?? 0) !== state.revision) {
    state = latest || createDemoState(); renderAll(); notify('Данные изменились в другой вкладке. Они обновлены; проверьте форму и повторите действие.', true); return false;
  }
  const next = structuredClone(state);
  try { change(next); } catch (error) { notify(error.message, true); return false; }
  next.revision++;
  next.audit.push({ at: new Date().toISOString(), action: message });
  next.audit = next.audit.slice(-100);
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); } catch { notify('Браузер не сохранил изменения. Проверьте доступ к локальному хранилищу.', true); return false; }
  state = next; renderAll(); notify(message); return true;
}
function options(select, items, empty) {
  const previous = select.value;
  select.innerHTML = (empty === undefined ? '' : `<option value="">${escape(empty)}</option>`) + items.map(item => `<option value="${escape(item.value)}">${escape(item.label)}</option>`).join('');
  if ([...select.options].some(option => option.value === previous)) select.value = previous;
}
function renderSelectors() {
  const routes = routeOptions().map(route => ({ value: route.id, label: route.label }));
  options($('#route-filter'), routes, 'Все направления');
  options($('#booking-route'), routes);
  options($('#city-filter'), [...new Set(state.deals.map(d => d.city).concat(state.bookings.map(b => b.city)).filter(Boolean))].sort().map(city => ({ value: city, label: city })), 'Все города');
  options($('#funnel-filter'), FUNNELS.map((label, i) => ({ value: String(i), label })), 'Все воронки');
  const masters = state.masters.map(m => ({ value: m.id, label: m.name + (m.active ? '' : ' · неактивен') }));
  options($('#master-filter'), masters, 'Все мастера');
  options($('#schedule-master'), masters);
  const choices = [...new Map([...state.deals, ...dealResults].map(d => [d.id, d])).values()];
  options($('#booking-deal'), choices.map(d => ({ value: d.id, label: `№${d.id} · ${d.title || d.client}` })), live ? 'Найдите и выберите сделку' : undefined);
}
function showView(name) {
  if (!['sheet', 'booking', 'masters', 'schedule'].includes(name)) return;
  currentView = name;
  document.querySelectorAll('.view').forEach(view => view.hidden = view.id !== `view-${name}`);
  document.querySelectorAll('.main-nav button').forEach(button => {
    if (button.dataset.view === name) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
  });
  if (name === 'sheet') renderSheet();
  if (name === 'booking') updateAvailability();
  if (name === 'masters') renderMasters();
  if (name === 'schedule') renderSchedule();
  window.scrollTo({ top: 0, behavior: 'instant' });
  resizeFrame();
}
function filteredBookings() {
  const date = $('#sheet-date').value, route = $('#route-filter').value;
  const search = $('#search-filter').value.trim().toLocaleLowerCase('ru');
  return state.bookings.filter(b => {
    const deal = state.deals.find(d => d.id === b.dealId);
    return deal && b.date === date && (!route || b.route === route)
      && ($('#funnel-filter').value === '' || String(deal.funnel) === $('#funnel-filter').value)
      && (!$('#master-filter').value || b.masterId === $('#master-filter').value)
      && (!$('#type-filter').value || deal.type === $('#type-filter').value)
      && (!$('#city-filter').value || (b.city || deal.city) === $('#city-filter').value)
      && ($('#show-cancelled').checked || b.status !== 'cancelled')
      && (!search || [deal.client, b.city || deal.city, b.region, b.district, deal.phone, deal.id, b.address].join(' ').toLocaleLowerCase('ru').includes(search));
  });
}
function bookingRow(b, index) {
  const deal = state.deals.find(d => d.id === b.dealId);
  const master = state.masters.find(m => m.id === b.masterId);
  const errors = conflicts(b, state);
  return `<tr class="${b.special ? 'row-special ' : ''}${errors.length ? 'row-conflict ' : ''}${b.status === 'cancelled' ? 'row-cancelled' : ''}">
    <td class="slot-cell">${escape(b.position)}<span class="slot-index">№ ${index}</span></td><td>${escape(b.years || '—')}</td>
    <td><span class="cell-title">${escape(deal.client)}</span>${escape(b.city || deal.city)}<span class="cell-detail">${escape(routeLabel(b.route))}</span><button class="text-link cell-detail" type="button" data-edit="${escape(b.id)}">${live ? 'Сделка' : 'Демо-сделка'} №${escape(deal.id)}</button><span class="cell-detail">${escape(FUNNELS[deal.funnel])}</span></td>
    <td>${escape(b.address)}${b.apartment ? `<span class="cell-detail">Кв. / офис ${escape(b.apartment)}</span>` : ''}</td><td>${escape(deal.phone || '—')}</td>
    <td>${deal.products.map(p => `<div class="cell-product">${escape(p.name)}<span class="cell-detail">${escape(p.quantity ?? 1)} × ${money(p.price, p.currency)}</span></div>`).join('')}<span class="cell-detail">Работа: ${b.duration} мин.</span></td>
    <td><span class="cell-window">${escape(b.windowStart)}–${escape(b.windowEnd)}</span><div class="cell-comment">${escape(b.comment || 'Без дополнительных пожеланий')}</div><span class="cell-detail">Резерв мастера: ${escape(b.actualStart)}–${time(minutes(b.actualStart) + b.duration)}</span></td>
    <td><span class="cell-title">${escape(master?.name || 'Не назначен')}</span><span class="status ${escape(b.status)}">${live ? escape(deal.completion || STATUSES[b.status]) : STATUSES[b.status]}</span>${errors.map(error => `<span class="conflict-text">${escape(error)}</span>`).join('')}</td>
    <td><button class="button small" type="button" data-edit="${escape(b.id)}">Открыть</button></td></tr>`;
}
function renderSheet() {
  const date = $('#sheet-date').value, route = $('#route-filter').value;
  const bookings = filteredBookings(), active = bookings.filter(b => b.status !== 'cancelled');
  const availableMasters = state.masters.filter(m => m.active && groupsFor(m, state).some(group => (!route || group.routes.includes(route)) && (!$('#type-filter').value || group.types.includes($('#type-filter').value))) && (!$('#master-filter').value || m.id === $('#master-filter').value));
  const working = availableMasters.filter(m => shiftFor(m, date));
  const countConflicts = active.filter(b => conflicts(b, state).length).length;
  $('#sheet-subtitle').textContent = `${dateLabel(date)} · ${route ? routeLabel(route) : 'Все направления'}${live ? '' : ' · демо-данные'}`;
  const stats = [
    [active.length, 'Выезды', 'по выбранным фильтрам', '↗'],
    [working.length, 'Мастера на смене', 'в выбранном направлении', '♙'],
    [active.reduce((sum, b) => sum + b.duration, 0) / 60, 'Часы работ', 'без времени на дорогу', '◷'],
    [countConflicts, 'Конфликты', 'проверьте график и назначения', '!'],
  ];
  $('#stats').innerHTML = stats.map(([value, label, note, icon], i) => `<div class="stat ${i === 3 && value ? 'warning' : ''}"><div><div class="stat-label">${label}</div><div class="stat-value">${new Intl.NumberFormat('ru-BY').format(value)}</div><div class="stat-note">${note}</div></div><span class="stat-symbol" aria-hidden="true">${icon}</span></div>`).join('');
  let index = 0;
  $('#route-body').innerHTML = Array.from({ length: 18 }, (_, i) => time(540 + i * 30)).map(position => {
    const list = bookings.filter(b => b.position === position);
    return list.length ? list.map(b => bookingRow(b, ++index)).join('') : `<tr class="empty-row"><td class="slot-cell">${position}</td><td colspan="8"><button class="empty-slot" type="button" data-new="${position}">+ Свободная позиция в листе</button></td></tr>`;
  }).join('');
  $('#capacity-list').innerHTML = availableMasters.map(m => {
    const shift = shiftFor(m, date);
    const all = state.bookings.filter(b => b.masterId === m.id && b.date === date && b.status !== 'cancelled');
    const duration = all.reduce((sum, b) => sum + b.duration, 0);
    const capacity = shift ? minutes(shift.end) - minutes(shift.start) : 0;
    return `<div class="capacity-item ${shift ? '' : 'off'}"><strong>${escape(m.name)}</strong><p class="shift">${shift ? `${shift.start}–${shift.end}` : 'Выходной / не работает'}</p><p>${all.length} выездов · ${duration} мин. работ, все маршруты</p>${capacity ? `<div class="load-track"><meter min="0" max="${capacity}" value="${Math.min(capacity, duration)}" aria-label="Минуты работ из смены ${escape(m.name)}"></meter></div>` : ''}<button class="text-link cell-detail" type="button" data-schedule="${escape(m.id)}">Изменить график</button></div>`;
  }).join('') || '<p class="empty-message">Нет мастеров для выбранных фильтров.</p>';
}
function draftFromForm() {
  const data = Object.fromEntries(new FormData(form));
  return { city: '', region: '', district: '', lat: '', lon: '', ...data, dealId: field('dealId').value, duration: Number(data.duration), position: field('position').value || '09:00', special: false, status: state.bookings.find(b => b.id === editingBooking)?.status === 'done' ? 'done' : 'planned' };
}
function renderDealInfo() {
  const deal = state.deals.find(d => d.id === field('dealId').value);
  $('#deal-info').innerHTML = deal ? `<p><strong>${escape(deal.client)}</strong> · ${escape(deal.phone || '')}</p><p class="muted">${escape(FUNNELS[deal.funnel])}</p><ul class="product-list">${deal.products.map(p => `<li><span>${escape(p.name)} × ${escape(p.quantity ?? 1)}</span><span>${money(p.price, p.currency)}</span></li>`).join('')}</ul>` : '';
}
function updateAvailability() {
  const result = findSlot(draftFromForm(), state, editingBooking);
  $('#save-booking').disabled = Boolean(result.error) || Boolean(pendingSync) || Boolean(runtimeInfo.error);
  $('#availability-result').innerHTML = result.error
    ? `<h3 class="availability-title error">Проверьте параметры</h3><p class="availability-detail">${escape(result.error)}</p>`
    : `<h3 class="availability-title ok">Есть свободный мастер</h3><p class="availability-detail">Автоматическое назначение<strong>${escape(state.masters.find(m => m.id === result.masterId)?.name)}</strong></p><p class="availability-detail">Предлагаемый резерв<strong>${result.actualStart}–${time(minutes(result.actualStart) + Number(field('duration').value))}</strong></p><p class="availability-detail">Окно клиента<strong>${escape(field('windowStart').value)}–${escape(field('windowEnd').value)}</strong></p><p class="field-help">Доступность будет повторно проверена при сохранении.</p>`;
}
function fillBooking(values) {
  form.reset();
  for (const [name, value] of Object.entries(values)) {
    const control = field(name);
    if (control) { if (control.type === 'checkbox') control.checked = Boolean(value); else control.value = value; }
  }
  renderDealInfo(); updateAvailability();
}
function resetBooking(position = '09:00', preferredDeal) {
  editingBooking = null;
  const deal = state.deals.find(d => d.id === preferredDeal) || (live ? { id: '', address: '', apartment: '', years: '', comment: '' } : state.deals.find(d => !state.bookings.some(b => b.dealId === d.id && b.status !== 'cancelled')) || state.deals[0]);
  fillBooking({ dealId: deal.id, date: $('#sheet-date').value || DEMO_DATE, route: deal.route || $('#route-filter').value || routeOptions()[0]?.id || '', address: deal.address, city: deal.city, region: deal.region, district: deal.district, apartment: deal.apartment, years: deal.years, windowStart: position, windowEnd: time(Math.min(1080, minutes(position) + 180)), position, duration: 30, comment: deal.comment, status: 'planned', lat: '', lon: '' });
  $('#booking-title').textContent = 'Запись из сделки'; $('#save-booking').textContent = 'Подтвердить запись'; $('#cancel-booking').hidden = true;
}
async function editBooking(id) {
  if (live) { const item = state.bookings.find(b => b.id === id); if (item) await loadLiveDeal(item.dealId); return; }
  const booking = state.bookings.find(b => b.id === id);
  if (!booking) return;
  editingBooking = id;
  fillBooking({ ...booking, status: booking.status === 'cancelled' ? 'planned' : booking.status });
  $('#booking-title').textContent = `Выезд по демо-сделке №${booking.dealId}`;
  $('#save-booking').textContent = booking.status === 'cancelled' ? 'Восстановить запись' : 'Сохранить изменения';
  $('#cancel-booking').hidden = booking.status === 'cancelled'; showView('booking');
}
function askConfirm(message, action) {
  $('#confirm-text').textContent = message; pendingConfirm = action; $('#confirm-dialog').showModal();
}
function renderMasters() {
  $('#masters-grid').innerHTML = state.masters.map(m => {
    const count = state.bookings.filter(b => b.masterId === m.id && b.status !== 'cancelled').length;
    return `<article class="panel master-card ${m.active ? '' : 'inactive'}"><header><span class="avatar">${escape(m.name.split(' ').map(word => word[0]).slice(0, 2).join(''))}</span><div><h3>${escape(m.name)}</h3><span class="cell-detail">${m.active ? 'Активен' : 'Неактивен'} · ${escape(m.id)}</span></div></header><div class="tags">${routesFor(m, state).map(route => `<span class="tag">${escape(routeLabel(route))}</span>`).join('')}</div><p>${typesFor(m, state).map(type => type === 'individual' ? 'Физические лица' : 'Юридические лица').join(' · ')}</p><p class="cell-detail">Группы: ${groupsFor(m, state).map(g => escape(g.name)).join('; ') || 'нет активных групп'}</p><div class="master-count">${count} назначенных выездов · все даты</div><div class="master-actions"><button class="button small" type="button" data-master="${escape(m.id)}">Редактировать</button><button class="button small" type="button" data-schedule="${escape(m.id)}">График</button></div></article>`;
  }).join('');
  $('#groups-grid').innerHTML = state.groups.map(group => `<article class="panel master-card ${group.active ? '' : 'inactive'}"><h3>${escape(group.name)}</h3><div class="tags">${group.routes.map(route => `<span class="tag">${escape(routeLabel(route))}</span>`).join('')}</div><p>${group.types.map(type => type === 'individual' ? 'Физические лица' : 'Юридические лица').join(' · ')}</p><p class="cell-detail">${group.active ? 'Активна' : 'Неактивна'}</p><p>Состав: ${state.masters.filter(m => m.groupIds?.includes(group.id)).map(m => escape(m.name) + (m.active ? '' : ' (неактивен)')).join(', ') || 'мастера не назначены'}</p><button class="button small" type="button" data-group="${escape(group.id)}">Настроить группу</button></article>`).join('');
}
function openMaster(id = null) {
  editingMaster = id;
  const master = state.masters.find(m => m.id === id);
  const masterForm = $('#master-form'); masterForm.reset();
  masterForm.elements.namedItem('wialon').value = master?.wialon || '';
  $('#master-employee').disabled = Boolean(master);
  $('#employee-search').hidden = Boolean(master);
  $('#master-directions').innerHTML = routeOptions().map(route => `<label class="check"><input name="directions" type="checkbox" value="${escape(route.id)}" ${master?.directions?.includes(route.id) ? 'checked' : ''}>${escape(route.label)}</label>`).join('');
  employeeResults = master ? [{id: master.bitrixUserId || String(501 + state.masters.indexOf(master)),name: master.name}] : [];
  renderEmployees(master?.bitrixUserId || employeeResults[0]?.id || '');
  if (!master) loadEmployees('');
  masterForm.elements.namedItem('name').value = master?.name || '';
  masterForm.elements.namedItem('active').checked = master ? master.active : true;
  $('#master-groups').innerHTML = state.groups.map(group => `<label class="check"><input name="groups" type="checkbox" value="${escape(group.id)}" ${master?.groupIds?.includes(group.id) ? 'checked' : ''}>${escape(group.name)}${group.active ? '' : ' · неактивна'}</label>`).join('');
  $('#master-dialog-title').textContent = master ? 'Редактирование мастера' : 'Новый мастер';
  $('#master-error').hidden = true; $('#master-dialog').showModal();
}
function openGroup(id = null) {
  editingGroup = id;
  const group = state.groups.find(g => g.id === id), groupForm = $('#group-form');
  groupForm.reset(); groupForm.elements.namedItem('name').value = group?.name || '';
  groupForm.elements.namedItem('active').checked = group ? group.active : true;
  $('#group-routes').innerHTML = routeOptions().map(route => `<label class="check"><input name="routes" type="checkbox" value="${escape(route.id)}" ${group?.routes.includes(route.id) ? 'checked' : ''}>${escape(route.label)}</label>`).join('');
  groupForm.querySelectorAll('[name=types]').forEach(input => input.checked = group ? group.types.includes(input.value) : true);
  $('#group-error').hidden = true; $('#group-dialog-title').textContent = group ? 'Настройка группы' : 'Новая группа'; $('#group-dialog').showModal();
}
const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
function selectedMaster() { return state.masters.find(m => m.id === $('#schedule-master').value) || state.masters[0]; }
function renderSchedule() {
  const master = selectedMaster();
  if (!master) { $('#week-grid').innerHTML = '<p class="muted">Сначала добавьте мастера из сотрудников Битрикс24.</p>'; $('#month-grid').innerHTML = ''; $('#exceptions-list').textContent = 'Мастер не выбран.'; $('#schedule-conflicts').hidden = true; $('#week-form').querySelector('button[type=submit]').disabled = true; $('#exception-form').querySelectorAll('input,select,button').forEach(el=>el.disabled=true); return; }
  $('#week-form').querySelector('button[type=submit]').disabled = live && !runtimeInfo.canManage; $('#exception-form').querySelectorAll('input,select,button').forEach(el=>el.disabled=live && !runtimeInfo.canManage);
  $('#week-grid').innerHTML = [1, 2, 3, 4, 5, 6, 0].map(day => {
    const shift = master.week[day];
    return `<div class="week-row"><strong>${dayNames[day]}</strong><label class="check"><input name="work-${day}" type="checkbox" data-work="${day}" ${shift ? 'checked' : ''}>Рабочий</label><label>Начало<input name="start-${day}" type="time" value="${shift?.start || '09:00'}" ${shift ? '' : 'disabled'} required></label><label>Окончание<input name="end-${day}" type="time" value="${shift?.end || '18:00'}" ${shift ? '' : 'disabled'} required></label></div>`;
  }).join('');
  const problematic = state.bookings.filter(b => b.masterId === master.id && conflicts(b, state).length);
  $('#schedule-conflicts').hidden = !problematic.length;
  $('#schedule-conflicts').innerHTML = `Есть конфликтующие назначения (${problematic.length}): ` + problematic.map(b => `<button class="text-link" type="button" data-edit="${escape(b.id)}">№${escape(b.dealId)} · ${escape(b.date)} ${escape(b.actualStart)}</button>`).join(' · ');
  $('#exceptions-list').innerHTML = Object.entries(master.exceptions).sort(([a], [b]) => a.localeCompare(b)).map(([date, shift]) => `<div class="exception-item"><strong>${dateLabel(date)}</strong><span>${shift ? `${shift.start}–${shift.end}` : 'Выходной / отсутствие'}</span><button class="text-link" type="button" data-exception="${date}">Изменить</button></div>`).join('') || '<p class="muted">Исключений пока нет.</p>';
  renderMonth(); loadException();
}
function renderMonth() {
  const master = selectedMaster(); if (!master) return; const first = `${month}-01`, offset = (new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7;
  const days = new Date(Number(month.slice(0, 4)), Number(month.slice(5)), 0).getDate();
  $('#month-title').textContent = new Intl.DateTimeFormat('ru-BY', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${first}T12:00:00Z`));
  $('#month-grid').innerHTML = '<span></span>'.repeat(offset) + Array.from({ length: days }, (_, index) => {
    const date = `${month}-${String(index + 1).padStart(2, '0')}`, shift = shiftFor(master, date);
    return `<button type="button" class="month-day ${shift ? '' : 'off'} ${Object.hasOwn(master.exceptions, date) ? 'exception' : ''} ${date === $('#exception-date').value ? 'selected' : ''}" data-exception="${date}" aria-label="${dateLabel(date)}, ${shift ? `${shift.start}–${shift.end}` : 'выходной'}"><strong>${index + 1}</strong><span>${shift ? `${shift.start}–${shift.end}` : 'Выходной'}</span></button>`;
  }).join('');
}
function loadException() {
  const master = selectedMaster(); if (!master) return; const date = $('#exception-date').value;
  const shift = shiftFor(master, date);
  $('#exception-mode').value = shift ? 'shift' : 'off';
  $('#exception-form').elements.namedItem('start').value = shift?.start || '09:00';
  $('#exception-form').elements.namedItem('end').value = shift?.end || '18:00';
  toggleExceptionTimes();
}
function toggleExceptionTimes() {
  const off = $('#exception-mode').value === 'off';
  $('#exception-times').hidden = off;
  $('#exception-times').querySelectorAll('input').forEach(input => input.disabled = off);
}
function renderAll() { renderSelectors(); renderSheet(); renderMasters(); renderSchedule(); if (currentView === 'booking') { renderDealInfo(); updateAvailability(); } }

document.addEventListener('click', event => {
  const button = event.target.closest('button'); if (!button) return;
  if (button.dataset.view) { $('#notice').hidden = true; showView(button.dataset.view); }
  if (button.dataset.new) { if (live && contextId) loadLiveDeal(contextId); else { resetBooking(button.dataset.new); showView('booking'); } }
  if (button.dataset.edit) editBooking(button.dataset.edit);
  if (button.dataset.master) openMaster(button.dataset.master);
  if (button.dataset.group) openGroup(button.dataset.group);
  if (button.dataset.schedule) { $('#schedule-master').value = button.dataset.schedule; showView('schedule'); }
  if (button.dataset.exception) { $('#exception-date').value = button.dataset.exception; month = button.dataset.exception.slice(0, 7); loadException(); renderMonth(); }
});
for (const id of ['sheet-date', 'route-filter', 'city-filter', 'funnel-filter', 'master-filter', 'type-filter', 'show-cancelled']) $('#' + id).addEventListener('change', renderSheet);
$('#search-filter').addEventListener('input', renderSheet);
$('#new-booking').addEventListener('click', () => { if (live && contextId) { loadLiveDeal(contextId); return; } resetBooking(); showView('booking'); });
form.addEventListener('input', updateAvailability);
$('#booking-deal').addEventListener('change', async () => {
  if (live) { await loadLiveDeal(field('dealId').value); return; }
  const id = field('dealId').value, existing = state.bookings.find(b => b.dealId === id && b.status !== 'cancelled');
  if (existing) editBooking(existing.id); else resetBooking(field('position').value, id);
});
form.addEventListener('submit', async event => {
  event.preventDefault(); const draft = draftFromForm();
  if (await commit(next => {
    if (next.bookings.some(b => b.dealId === draft.dealId && b.status !== 'cancelled' && b.id !== editingBooking)) throw new Error('В этой сделке уже есть активный выезд. Откройте его для переноса.');
    const slot = findSlot(draft, next, editingBooking); if (slot.error) throw new Error(slot.error);
    const booking = { ...draft, ...slot, id: editingBooking || crypto.randomUUID() };
    const index = next.bookings.findIndex(b => b.id === editingBooking);
    if (index >= 0) next.bookings[index] = booking; else next.bookings.push(booking);
    const deal = next.deals.find(d => d.id === draft.dealId);
    Object.assign(deal, { address: draft.address, city: draft.city, region: draft.region, district: draft.district, apartment: draft.apartment, years: draft.years, comment: draft.comment, date: draft.date, route: draft.route, windowStart: draft.windowStart, windowEnd: draft.windowEnd, duration: draft.duration, service: deal.products.map(p => p.name).join('; '), demoStage: 'Время назначено' });
  }, editingBooking ? 'Выезд изменён. Демо-сделка и маршрутный лист обновлены.' : 'Выезд сохранён. Мастер назначен автоматически.')) {
    $('#sheet-date').value = draft.date; $('#route-filter').value = draft.route; $('#city-filter').value = ''; $('#funnel-filter').value = ''; $('#master-filter').value = ''; $('#type-filter').value = ''; $('#search-filter').value = ''; showView('sheet');
  }
});
$('#cancel-booking').addEventListener('click', () => {
  const id = editingBooking;
  askConfirm('Отменить выезд? В режиме CRM будут очищены дата и границы окна в сделке; резерв освободится после подтверждения записи.', async () => {
    if (await commit(next => {
      const booking = next.bookings.find(b => b.id === id); if (!booking) throw new Error('Запись не найдена.');
      booking.status = 'cancelled'; next.deals.find(d => d.id === booking.dealId).demoStage = 'Пересогласовать время';
    }, 'Выезд отменён, резерв мастера освобождён.')) { resetBooking(); showView('sheet'); }
  });
});
$('#confirm-no').addEventListener('click', () => { pendingConfirm = null; $('#confirm-dialog').close(); });
$('#confirm-yes').addEventListener('click', () => { const action = pendingConfirm; pendingConfirm = null; $('#confirm-dialog').close(); action?.(); });
$('#confirm-dialog').addEventListener('cancel', () => pendingConfirm = null);
$('#reset-demo').addEventListener('click', () => askConfirm('Сбросить вымышленные записи и графики в этом браузере? Все ваши изменения демо будут заменены исходным примером.', () => {
  const next = createDemoState(); next.revision = state.revision + 1;
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); } catch { notify('Не удалось сбросить демо: хранилище браузера недоступно.', true); return; }
  state = next; $('#sheet-date').value = DEMO_DATE; $('#route-filter').value = ROUTES[0]; $('#city-filter').value = ''; $('#funnel-filter').value = ''; $('#master-filter').value = ''; $('#type-filter').value = ''; $('#search-filter').value = ''; $('#show-cancelled').checked = false;
  renderAll(); resetBooking(); showView('sheet'); notify('Демонстрационные данные восстановлены.');
}));
$('#add-master').addEventListener('click', () => openMaster());
$('#close-master').addEventListener('click', () => $('#master-dialog').close());
$('#master-form').addEventListener('submit', async event => {
  event.preventDefault(); const data = new FormData(event.currentTarget);
  const bitrixUserId = $('#master-employee').value;
  const name = employeeResults.find(p => p.id === bitrixUserId)?.name || state.masters.find(m => m.id === editingMaster)?.name || '';
  const groupIds = data.getAll('groups');
  if (!bitrixUserId || !name || !groupIds.length) { $('#master-error').textContent = 'Укажите ФИО и хотя бы одну группу мастера.'; $('#master-error').hidden = false; return; }
  if (await commit(next => {
    const existing = next.masters.find(m => m.id === editingMaster);
    const changes = { name, bitrixUserId, groupIds, directions: data.getAll('directions'), wialon: String(data.get('wialon') || ''), active: data.has('active') };
    if (existing) Object.assign(existing, changes);
    else next.masters.push({ ...changes, id: crypto.randomUUID(), week: structuredClone(createDemoState().masters[0].week), exceptions: {} });
  }, 'Данные мастера сохранены. Доступность и конфликты пересчитаны.')) $('#master-dialog').close();
});
$('#add-group').addEventListener('click', () => openGroup());
$('#close-group').addEventListener('click', () => $('#group-dialog').close());
$('#group-form').addEventListener('submit', async event => {
  event.preventDefault(); const data = new FormData(event.currentTarget);
  const name = data.get('name').trim(), routes = data.getAll('routes'), types = data.getAll('types');
  if (!name || !routes.length || !types.length) { $('#group-error').textContent = 'Укажите название, маршруты и типы клиентов.'; $('#group-error').hidden = false; return; }
  if (await commit(next => {
    const changes = { name, routes, types, active: data.has('active') };
    const group = next.groups.find(g => g.id === editingGroup);
    if (group) Object.assign(group, changes); else next.groups.push({ ...changes, id: crypto.randomUUID() });
  }, 'Правила группы сохранены. Доступность и конфликты пересчитаны.')) $('#group-dialog').close();
});
$('#schedule-master').addEventListener('change', renderSchedule);
$('#week-form').addEventListener('change', event => {
  const day = event.target.dataset.work; if (day === undefined) return;
  for (const key of ['start', 'end']) $('#week-form').elements.namedItem(`${key}-${day}`).disabled = !event.target.checked;
});
$('#week-form').addEventListener('submit', async event => {
  event.preventDefault(); const data = new FormData(event.currentTarget), week = {};
  for (let day = 0; day < 7; day++) {
    if (!data.has(`work-${day}`)) continue;
    const start = data.get(`start-${day}`), end = data.get(`end-${day}`);
    if (!Number.isFinite(minutes(start)) || !Number.isFinite(minutes(end)) || minutes(start) >= minutes(end)) { notify(`${dayNames[day]}: окончание смены должно быть позже начала.`, true); return; }
    week[day] = { start, end };
  }
  const id = selectedMaster().id;
  await commit(next => next.masters.find(m => m.id === id).week = week, 'Рабочая неделя сохранена. Проверьте конфликтующие назначения.');
});
$('#exception-mode').addEventListener('change', toggleExceptionTimes);
$('#exception-date').addEventListener('change', () => { if (!validDate($('#exception-date').value)) return; month = $('#exception-date').value.slice(0, 7); loadException(); renderMonth(); });
$('#exception-form').addEventListener('submit', async event => {
  event.preventDefault(); const data = new FormData(event.currentTarget), date = data.get('date'), id = selectedMaster().id;
  if (!validDate(date)) { notify('Выберите корректную дату исключения.', true); return; }
  const shift = data.get('mode') === 'off' ? null : { start: data.get('start'), end: data.get('end') };
  if (shift && (!Number.isFinite(minutes(shift.start)) || !Number.isFinite(minutes(shift.end)) || minutes(shift.start) >= minutes(shift.end))) { notify('Окончание смены должно быть позже начала.', true); return; }
  await commit(next => next.masters.find(m => m.id === id).exceptions[date] = shift, 'Исключение сохранено. Доступность выездов пересчитана.');
});
$('#remove-exception').addEventListener('click', async () => {
  const date = $('#exception-date').value, id = selectedMaster().id;
  if (!validDate(date)) { notify('Выберите дату.', true); return; }
  await commit(next => { delete next.masters.find(m => m.id === id).exceptions[date]; }, 'Для выбранной даты восстановлен обычный график.');
});
function moveMonth(direction) { const next = new Date(`${month}-15T12:00:00Z`); next.setUTCMonth(next.getUTCMonth() + direction); month = next.toISOString().slice(0, 7); renderMonth(); }
$('#month-prev').addEventListener('click', () => moveMonth(-1)); $('#month-next').addEventListener('click', () => moveMonth(1));
window.addEventListener('storage', event => {
  if (live) return;
  if (event.key !== STORAGE_KEY && event.key !== null) return;
  try { state = readStorage() || createDemoState(); renderAll(); notify('Демо-данные обновлены в другой вкладке. Проверьте несохранённые формы.'); } catch { notify('Не удалось загрузить изменения из другой вкладки.', true); }
});

let lastHeight = 0;
function resizeFrame() {
  const height = Math.ceil($('main').getBoundingClientRect().bottom + window.scrollY + 24);
  if (window.parent !== window && height !== lastHeight) { lastHeight = height; window.parent.postMessage({ type: 'vibe:resize', height }, '*'); }
}
new ResizeObserver(resizeFrame).observe($('main'));
window.addEventListener('load', resizeFrame);

function mergeDeal(result) {
  const deal = result.deal;
  state.deals = state.deals.filter(d => d.id !== deal.id).concat(deal);
  state.bookings = state.bookings.filter(b => b.dealId !== deal.id);
  if (result.booking) state.bookings.push(result.booking);
  if (result.revision !== undefined) state.revision = result.revision;
}
async function loadLiveDeal(id) {
  if (!id || pendingSync) return;
  try {
    if (contextId && id !== contextId) { notify('Вкладка открыта из другой сделки. Для поиска используйте отдельное приложение.', true); return; }
    const result = await api(`/deals/${encodeURIComponent(id)}`);
    mergeDeal(result); renderSelectors();
    const deal = result.deal, booking = result.booking;
    editingBooking = booking?.status !== 'cancelled' ? booking?.id || null : null;
    fillBooking({ ...deal, dealId: deal.id, date: deal.date || $('#sheet-date').value,
      windowStart: deal.windowStart || '09:00', windowEnd: deal.windowEnd || '12:00',
      position: booking?.position || '09:00', duration: deal.duration || 30 });
    $('#booking-title').textContent = `Выезд по сделке №${deal.id}`;
    $('#save-booking').textContent = booking && booking.status !== 'cancelled' ? 'Сохранить изменения' : 'Подтвердить запись';
    $('#cancel-booking').hidden = !booking || booking.status === 'cancelled';
    showView('booking');
  } catch (error) { notify(error.message, true); }
}
let calendarGeneration = 0;
async function loadLiveCalendar(more = false) {
  if (!live || runtimeInfo.error || pendingSync) return;
  if (more && calendarLoading) return;
  const generation = ++calendarGeneration, date = $('#sheet-date').value;
  if (!validDate(date)) return;
  calendarLoading = true; $('#refresh-calendar').disabled = true;
  try {
    let cursor = more ? calendarCursor : null, first = true;
    for (let page = 0; page < 12; page++) {
      const result = await api('/calendar?' + new URLSearchParams({ date, ...(cursor ? {cursor} : {}) }));
      if (generation !== calendarGeneration) return;
      if (first && !more) state.bookings = state.bookings.filter(b => b.date !== date);
      first = false;
      for (const deal of result.deals) state.deals = state.deals.filter(d => d.id !== deal.id).concat(deal);
      for (const booking of result.bookings) state.bookings = state.bookings.filter(b => b.dealId !== booking.dealId).concat(booking);
      state.revision = result.revision;
      cursor = result.next; calendarCursor = cursor;
      renderSheet();
      if (!cursor) break;
    }
    $('#calendar-more').hidden = !calendarCursor;
  } catch (error) { if (generation === calendarGeneration) notify(error.message, true); }
  finally { if (generation === calendarGeneration) { calendarLoading = false; $('#refresh-calendar').disabled = false; } }
}
async function commitLive(change) {
  if (pendingSync) { notify('Сначала проверьте результат предыдущего сохранения.', true); return false; }
  try {
    const next = structuredClone(state); change(next);
    const changed = next.bookings.find(b => JSON.stringify(b) !== JSON.stringify(state.bookings.find(old => old.id === b.id)));
    if (changed) {
      const deal = state.deals.find(d => d.id === changed.dealId);
      if (!deal?.revision) throw new Error('Загрузите актуальную сделку перед сохранением.');
      pendingSync = { operationId: crypto.randomUUID(), expectedRevision: deal.revision, draft: changed, cancel: changed.status === 'cancelled' };
      updateAvailability();
      const result = await api('/bookings', pendingSync);
      mergeDeal(result); pendingSync = null; $('#recheck-save').hidden = true;
      renderAll(); notify('Выезд сохранён. Значения в контакте и сделке проверены.');
    } else {
      const result = await api('/settings', { revision: state.revision, masters: next.masters, groups: next.groups });
      Object.assign(state, result); renderAll(); notify('Настройки сохранены на сервере приложения.');
    }
    return true;
  } catch (error) {
    if (!['CRM_SYNC_UNCERTAIN', 'APP_UNAVAILABLE'].includes(error.code) && !(error.status >= 500)) pendingSync = null;
    $('#recheck-save').hidden = !pendingSync;
    updateAvailability(); notify(error.message, true); return false;
  }
}
function renderEmployees(selected = '') {
  const existing = state.masters.filter(m => m.id !== editingMaster).map(m => m.bitrixUserId || String(501 + state.masters.indexOf(m)));
  options($('#master-employee'), employeeResults.filter(p => !existing.includes(p.id)).map(p => ({value:p.id,label:p.name})), 'Выберите сотрудника');
  if (selected) $('#master-employee').value = selected;
}
async function loadEmployees(query) {
  try {
    employeeResults = live ? await api('/employees?' + new URLSearchParams({q:query})) : [
      ...createDemoState().masters.map((m,i) => ({id:String(501+i),name:m.name})),
      {id:'601',name:'Тестовый мастер Алексей'}, {id:'602',name:'Тестовый мастер Ольга'},
    ].filter(p => !query || p.name.toLowerCase().includes(query.toLowerCase()));
    renderEmployees();
  } catch (error) { $('#master-error').textContent = error.message; $('#master-error').hidden = false; }
}
let employeeTimer, dealTimer, dealSearchGeneration = 0;
$('#employee-search').addEventListener('input', event => { clearTimeout(employeeTimer); employeeTimer = setTimeout(() => loadEmployees(event.target.value), 350); });
$('#deal-search').addEventListener('input', event => {
  clearTimeout(dealTimer); const query = event.target.value.trim(), generation = ++dealSearchGeneration;
  dealTimer = setTimeout(async () => {
    try {
      const results = live ? (query.length >= 2 || /^\d+$/.test(query) ? await api('/deals?' + new URLSearchParams({q:query})) : []) : state.deals.filter(d => `${d.id} ${d.client}`.toLowerCase().includes(query.toLowerCase()));
      if (generation !== dealSearchGeneration) return;
      dealResults = results;
      options($('#booking-deal'), results.map(d => ({value:d.id,label:`№${d.id} · ${d.title || d.client}`})), results.length ? 'Выберите найденную сделку' : 'Сделки не найдены');
    } catch (error) { notify(error.message, true); }
  }, 350);
});
$('#refresh-calendar').addEventListener('click', () => loadLiveCalendar());
$('#calendar-more').addEventListener('click', () => loadLiveCalendar(true));
$('#sheet-date').addEventListener('change', () => { if (live) loadLiveCalendar(); });
$('#reload-deal').addEventListener('click', () => { if (live) loadLiveDeal(field('dealId').value); else resetBooking('09:00',field('dealId').value); });
$('#recheck-save').addEventListener('click', async () => {
  if (!pendingSync) return;
  try { const result = await api('/bookings/reconcile', {operationId:pendingSync.operationId}); mergeDeal(result); pendingSync = null; $('#recheck-save').hidden = true; renderAll(); notify('Сохранение в CRM подтверждено.'); }
  catch (error) { if (error.code === 'OPERATION_NOT_FOUND') { pendingSync = null; $('#recheck-save').hidden = true; } notify(error.message, true); }
  updateAvailability();
});
$('#bind-placement').addEventListener('click', () => askConfirm('Добавить вкладку «Планирование выездов» в сделки Битрикс24?', async () => {
  try { const result = await api('/placement', {}); notify(result.message); } catch (error) { notify(error.message, true); }
}));

$('#mode-badge').textContent = live ? 'Битрикс24' : 'Тестовая версия';
$('#reset-demo').hidden = live;
$('#refresh-calendar').hidden = !live;
$('#bind-placement').hidden = !live || !runtimeInfo.user?.isAdmin;
$('#city-filter').closest('label').hidden = true;
if (live) {
  $('.demo-strip').textContent = 'Данные Битрикс24. Сохранение обновляет поля контакта и сделки. Услуги загружаются из товарных позиций.';
  $('.demo-rule').innerHTML = '<summary>Как считается доступность</summary><p>Работа целиком помещается в окно клиента и смену мастера. Шаг планирования — 30 минут, между выездами — 30 минут на дорогу. Итоговый резерв проверяется сервером при сохранении.</p>';
  $('#reload-deal').hidden = false;
  $('#view-booking .section-heading .muted').textContent = contextId ? 'Текущая сделка подставлена из вкладки Битрикс24.' : 'Найдите сделку в Битрикс24 и согласуйте время обслуживания.';
  $('.booking-aside .field-help').textContent = 'После сохранения приложение проверит значения в контакте и сделке. Услуги берутся из товарных позиций.';
  $('.app-footer span').textContent = 'Версия 0.3 · Битрикс24';
  const now = new Intl.DateTimeFormat('sv-SE', {timeZone:'Europe/Minsk'}).format(new Date());
  $('#sheet-date').value = now; $('#exception-date').value = now; month = now.slice(0,7);
  $('#add-master').disabled = !runtimeInfo.canManage; $('#add-group').disabled = !runtimeInfo.canManage;
}
renderSelectors();
$('#route-filter').value = routeOptions()[0]?.id || '';
renderAll(); resetBooking();
if (contextId && live) {
  $('#deal-search').hidden = true; $('#booking-deal').disabled = true;
  await loadLiveDeal(contextId);
} else if (contextId && !live && state.deals.some(d => d.id === contextId)) {
  const booking = state.bookings.find(b => b.dealId === contextId);
  if (booking) editBooking(booking.id); else { resetBooking('09:00',contextId); showView('booking'); }
} else if (live) await loadLiveCalendar();
const unfinished = runtimeInfo.pendingOperations?.find(op => !contextId || op.dealId === contextId);
if (live && unfinished) {
  const operation = unfinished;
  await loadLiveDeal(operation.dealId);
  try { const saved = await api('/bookings/pending/' + operation.operationId); fillBooking(saved.draft); }
  catch (error) { notify(error.message, true); }
  pendingSync = operation; $('#recheck-save').hidden = false; showView('booking');
  notify('Есть незавершённая синхронизация. Проверьте результат предыдущего сохранения.', true); updateAvailability();
}
if (runtimeInfo.error) {
  document.querySelectorAll('form input,form select,form button,#add-master,#add-group,#new-booking').forEach(el => el.disabled = true);
  notify(runtimeInfo.error, true);
}
setInterval(() => { if (live && currentView === 'sheet' && !calendarLoading && !document.querySelector('dialog[open]') && !document.activeElement?.matches('input,select,textarea')) loadLiveCalendar(); }, 60000);
