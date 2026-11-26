export const FUNNELS = ['Сервисное обслуживание юридических лиц', 'Сервисное обслуживание физических лиц', 'Продажа/Аренда', 'Безвозмездное пользование'];
export const ROUTES = ['Светлогорск', 'Минск', 'Борисов', 'Гомель', 'Гродно'];
export const STATUSES = { planned: 'Запланирован', enroute: 'В пути', done: 'Выполнен', cancelled: 'Отменён' };
export const DEMO_DATE = '2026-11-25';
export const BUFFER = 30;

export function minutes(value) {
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value || '')) return NaN;
  return Number(value.slice(0, 2)) * 60 + Number(value.slice(3));
}
export function time(value) { return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`; }
export function addDays(date, days) {
  const result = new Date(`${date}T12:00:00Z`);
  result.setUTCDate(result.getUTCDate() + days);
  return result.toISOString().slice(0, 10);
}
export function validDate(date) {
  return /^\d{4}-\d{2}-\d{2}$/.test(date || '') && !Number.isNaN(Date.parse(`${date}T12:00:00Z`)) && new Date(`${date}T12:00:00Z`).toISOString().slice(0, 10) === date;
}
export function shiftFor(master, date) {
  if (!master?.active || !validDate(date)) return null;
  if (Object.hasOwn(master.exceptions, date)) return master.exceptions[date];
  return master.week[new Date(`${date}T12:00:00Z`).getUTCDay()] || null;
}
export function groupsFor(master, state) {
  return (state.groups || []).filter(group => group.active && master.groupIds?.includes(group.id));
}
export function routesFor(master, state) { return [...new Set(groupsFor(master, state).flatMap(group => group.routes))].filter(route => !master.directions?.length || master.directions.includes(route)); }
export function typesFor(master, state) { return [...new Set(groupsFor(master, state).flatMap(group => group.types))]; }
export function eligible(master, draft, deal, state) {
  return master.active && (!master.directions?.length || master.directions.includes(draft.route)) && groupsFor(master, state).some(group => group.routes.includes(draft.route) && group.types.includes(deal.type));
}
function blocks(state, masterId, date, ignoreId) {
  return state.bookings.filter(b => b.id !== ignoreId && b.masterId === masterId && b.date === date && b.status !== 'cancelled');
}
function fits(start, duration, bookings) {
  return bookings.every(b => start >= minutes(b.actualStart) + b.duration + BUFFER || start + duration + BUFFER <= minutes(b.actualStart));
}
export function validateDraft(draft, state) {
  if (!state.deals.some(d => d.id === draft.dealId)) return 'Выберите сделку.';
  if (!validDate(draft.date)) return 'Укажите корректную дату.';
  if (!(state.routeOptions ? state.routeOptions.map(r => r.id) : ROUTES).includes(draft.route)) return 'Выберите маршрут.';
  if (!draft.address?.trim()) return 'Укажите адрес обслуживания.';
  if (![30, 60, 120].includes(draft.duration)) return 'Выберите длительность: 30, 60 или 120 минут.';
  const from = minutes(draft.windowStart), to = minutes(draft.windowEnd);
  if (!Number.isFinite(from) || !Number.isFinite(to) || from >= to) return 'Окончание окна приезда должно быть позже начала.';
  if (to - from < draft.duration) return 'В тестовой модели работа должна целиком помещаться в окно приезда.';
  if (!Number.isFinite(minutes(draft.position))) return 'Укажите ориентировочное время в маршрутном листе.';
  if (minutes(draft.position) < 540 || minutes(draft.position) > 1050 || minutes(draft.position) % 30 !== 0) return 'Позиция на шкале: с 09:00 до 17:30, шаг 30 минут.';
  if (draft.years !== '' && (!Number.isFinite(Number(draft.years)) || Number(draft.years) < 0 || Number(draft.years) > 100)) return 'Возраст системы: от 0 до 100 лет.';
  if (draft.lat !== '' && (!Number.isFinite(Number(draft.lat)) || Math.abs(Number(draft.lat)) > 90)) return 'Широта должна быть от −90 до 90.';
  if (draft.lon !== '' && (!Number.isFinite(Number(draft.lon)) || Math.abs(Number(draft.lon)) > 180)) return 'Долгота должна быть от −180 до 180.';
  if ((draft.lat === '') !== (draft.lon === '')) return 'Укажите обе координаты или оставьте обе пустыми.';
  return null;
}

// Demo rule: first free 30-minute slot, whole job inside the window and shift,
// 30 minutes between jobs. Display position never affects resource reservation.
export function findSlot(draft, state, ignoreId = null) {
  const error = validateDraft(draft, state);
  if (error) return { error };
  const deal = state.deals.find(d => d.id === draft.dealId);
  const slots = [];
  for (const master of state.masters.filter(m => eligible(m, draft, deal, state))) {
    const shift = shiftFor(master, draft.date);
    if (!shift) continue;
    const from = Math.ceil(Math.max(minutes(draft.windowStart), minutes(shift.start)) / 30) * 30;
    const to = Math.min(minutes(draft.windowEnd), minutes(shift.end));
    for (let start = from; start + draft.duration <= to; start += 30) {
      if (fits(start, draft.duration, blocks(state, master.id, draft.date, ignoreId))) { slots.push({ masterId: master.id, actualStart: time(start) }); break; }
    }
  }
  slots.sort((a, b) => a.actualStart.localeCompare(b.actualStart) || a.masterId.localeCompare(b.masterId));
  return slots[0] || { error: 'Нет свободного мастера в этом окне. Измените дату, окно приезда или график.' };
}
export function conflicts(booking, state) {
  if (booking.status === 'cancelled') return [];
  const master = state.masters.find(m => m.id === booking.masterId);
  const deal = state.deals.find(d => d.id === booking.dealId);
  const reasons = [];
  if (!master || !deal || !eligible(master, booking, deal, state)) reasons.push('Мастер недоступен для маршрута или типа клиента');
  const shift = shiftFor(master, booking.date);
  const start = minutes(booking.actualStart);
  if (!shift || start < minutes(shift.start) || start + booking.duration > minutes(shift.end)) reasons.push('Выезд вне рабочего графика');
  if (master && !fits(start, booking.duration, blocks(state, master.id, booking.date, booking.id))) reasons.push('Пересечение выездов с учётом дороги');
  return reasons;
}

export function createDemoState() {
  const week = { 1: { start: '09:00', end: '18:00' }, 2: { start: '09:00', end: '18:00' }, 3: { start: '09:00', end: '18:00' }, 4: { start: '09:00', end: '18:00' }, 5: { start: '09:00', end: '18:00' } };
  const master = (id, name, routes, types, exceptions = {}) => ({ id, name, routes, types, active: true, wialon: '', week: structuredClone(week), exceptions });
  const masters = [
    master('m1', 'Иван Соколов', ['Светлогорск', 'Гомель'], ['individual', 'company'], { '2026-11-26': null }),
    master('m2', 'Мария Волкова', ['Светлогорск', 'Гомель'], ['individual', 'company'], { '2026-11-25': { start: '09:00', end: '12:00' } }),
    master('m3', 'Андрей Лис', ['Минск', 'Борисов'], ['individual']),
    master('m4', 'Сергей Коваль', ['Минск', 'Борисов'], ['company']),
    master('m5', 'Светлана Орлова', ['Гродно'], ['individual', 'company']),
  ];
  const groups = [
    { id: 'g1', name: 'Гомельская область', routes: ['Светлогорск', 'Гомель'], types: ['individual', 'company'], active: true },
    { id: 'g2', name: 'Минск и направления · физлица', routes: ['Минск', 'Борисов'], types: ['individual'], active: true },
    { id: 'g3', name: 'Минск и направления · юрлица', routes: ['Минск', 'Борисов'], types: ['company'], active: true },
    { id: 'g4', name: 'Гродненская область', routes: ['Гродно'], types: ['individual', 'company'], active: true },
  ];
  masters.forEach((m, i) => m.groupIds = [i < 2 ? 'g1' : `g${i}`]);
  const deal = (id, client, funnel, type, city, address, products) => ({ id, client, funnel, type, city, address, phone: 'Тестовый контакт', products, apartment: '', years: '3', comment: '' });
  const deals = [
    deal('90001', 'Демо · Семья А', 1, 'individual', 'Светлогорск', 'ул. Лесная, 12', [{ name: 'Замена комплекта фильтров', price: 90 }]),
    deal('90002', 'Демо · Компания Б', 0, 'company', 'Жлобин', 'ул. Садовая, 8', [{ name: 'Сервисное обслуживание', price: 120 }, { name: 'Диагностика системы', price: 35 }]),
    deal('90003', 'Демо · Семья В', 2, 'individual', 'Речица', 'ул. Центральная, 24', [{ name: 'Установка системы', price: 240 }]),
    deal('90004', 'Демо · Компания Г', 3, 'company', 'Светлогорск', 'пр-т Мира, 5', [{ name: 'Плановая замена картриджа', price: 65 }]),
    deal('90005', 'Демо · Семья Д', 1, 'individual', 'Минск', 'ул. Парковая, 10', [{ name: 'Замена комплекта фильтров', price: 90 }]),
    deal('90006', 'Демо · Компания Е', 0, 'company', 'Борисов', 'ул. Заречная, 14', [{ name: 'Сервисное обслуживание', price: 120 }]),
    deal('90007', 'Демо · Семья Ж', 2, 'individual', 'Гродно', 'ул. Новая, 3', [{ name: 'Установка системы', price: 240 }]),
    deal('90008', 'Демо · Семья З', 1, 'individual', 'Жлобин', 'ул. Луговая, 7', [{ name: 'Плановая замена картриджа', price: 65 }]),
  ];
  const booking = (id, dealId, position, windowStart, windowEnd, duration, masterId, actualStart, extra = {}) => ({
    id, dealId, date: DEMO_DATE, route: 'Светлогорск', position, windowStart, windowEnd, duration, masterId, actualStart,
    address: deals.find(d => d.id === dealId).address, apartment: '', years: '3', lat: '', lon: '', comment: '', special: false, status: 'planned', ...extra,
  });
  deals.forEach(d => Object.assign(d, { region: d.city === 'Гродно' ? 'Гродненская область' : ['Минск', 'Борисов'].includes(d.city) ? 'Минская область' : 'Гомельская область', district: `${d.city === 'Речица' ? 'Речицкий' : d.city === 'Жлобин' ? 'Жлобинский' : d.city === 'Светлогорск' ? 'Светлогорский' : d.city === 'Борисов' ? 'Борисовский' : d.city === 'Гродно' ? 'Гродненский' : 'Минский'} район` }));
  const state = { schema: 2, revision: 0, masters, groups, deals, bookings: [
    booking('b1', '90001', '09:00', '09:00', '12:00', 60, 'm1', '09:00', { apartment: '14', status: 'done' }),
    booking('b2', '90002', '10:00', '10:00', '13:00', 30, 'm2', '10:00', { status: 'enroute', special: true, comment: 'Позвонить за 30 минут. Вход со стороны двора, шлагбаум откроет администратор. Нужны все услуги из сделки.' }),
    booking('b3', '90003', '13:00', '14:00', '16:00', 30, 'm1', '14:00', { apartment: '7', comment: 'Клиент будет дома после 14:00. Позиция 13:00 в листе — ориентир, окно приезда согласовано отдельно.' }),
    booking('b4', '90004', '11:00', '11:00', '12:00', 30, 'm2', '11:00'),
    booking('b5', '90005', '09:30', '09:00', '12:00', 60, 'm3', '09:30', { route: 'Минск' }),
    booking('b6', '90006', '12:00', '12:00', '15:00', 120, 'm4', '12:00', { route: 'Борисов' }),
    booking('b7', '90007', '10:00', '10:00', '13:00', 60, 'm5', '10:00', { route: 'Гродно', date: '2026-11-26' }),
  ], audit: [] };
  state.bookings.forEach(b => { const d = deals.find(deal => deal.id === b.dealId); Object.assign(b, { city: d.city, region: d.region, district: d.district }); });
  return state;
}

export function migrateDemoState(saved) {
  if (saved.schema !== 1) return saved;
  const next = structuredClone(saved);
  next.schema = 2;
  next.groups = next.masters.map((master, i) => {
    const id = `migrated-group-${i}`;
    master.groupIds = [id];
    return { id, name: `${master.name} · группа`, routes: [...master.routes], types: [...master.types], active: true };
  });
  next.deals.forEach(deal => { deal.region ||= ''; deal.district ||= ''; });
  next.bookings.forEach(booking => {
    const deal = next.deals.find(d => d.id === booking.dealId);
    booking.city ||= deal?.city || ''; booking.region ||= ''; booking.district ||= '';
  });
  return next;
}
