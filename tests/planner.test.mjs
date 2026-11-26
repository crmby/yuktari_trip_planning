import test from 'node:test';
import assert from 'node:assert/strict';
import { createDemoState, findSlot, conflicts, shiftFor, validateDraft, addDays, migrateDemoState } from '../public/js/planner.js';

function draft(overrides = {}) {
  return { dealId: '90008', date: '2026-11-25', route: 'Светлогорск', address: 'Тестовый адрес', city: 'Жлобин', apartment: '', years: '3', lat: '', lon: '', position: '13:00', windowStart: '14:00', windowEnd: '16:00', duration: 30, ...overrides };
}
test('позиция строки независима от клиентского окна и реального резерва', () => {
  const state = createDemoState();
  state.bookings = [];
  const result = findSlot(draft(), state);
  assert.deepEqual(result, { masterId: 'm1', actualStart: '14:00' });
  assert.deepEqual(findSlot(draft({ position: '09:00' }), state), result);
});
test('резерв учитывает длительность и 30 минут на дорогу между работами', () => {
  const state = createDemoState();
  state.masters = [state.masters[0]];
  state.bookings = [{ ...state.bookings[0], actualStart: '09:00', duration: 60 }];
  assert.equal(findSlot(draft({ windowStart: '10:00', windowEnd: '12:00', duration: 60 }), state).actualStart, '10:30');
  assert.ok(findSlot(draft({ windowStart: '10:00', windowEnd: '11:00', duration: 60 }), state).error);
  state.bookings[0].status = 'cancelled';
  assert.equal(findSlot(draft({ windowStart: '10:00', windowEnd: '11:00', duration: 60 }), state).actualStart, '10:00');
});
test('выходной и сокращённая смена изменяют доступность; деактивация сохраняет конфликт', () => {
  const state = createDemoState();
  state.masters = [state.masters[1]];
  state.bookings = [];
  assert.equal(shiftFor(state.masters[0], '2026-11-25').end, '12:00');
  assert.ok(findSlot(draft(), state).error);
  state.masters[0].exceptions['2026-11-25'] = { start: '09:00', end: '18:00' };
  const slot = findSlot(draft(), state);
  assert.equal(slot.actualStart, '14:00');
  const booking = { ...draft(), ...slot, id: 'test', status: 'planned' };
  state.bookings.push(booking);
  state.masters[0].exceptions['2026-11-25'] = null;
  assert.ok(conflicts(booking, state).includes('Выезд вне рабочего графика'));
  state.masters[0].active = false;
  assert.ok(conflicts(booking, state).some(reason => reason.includes('недоступен')));
  assert.equal(state.bookings.length, 1);
});
test('автоматическое назначение соблюдает маршрут и тип клиента', () => {
  const state = createDemoState(); state.bookings = [];
  assert.equal(findSlot(draft({ route: 'Минск', dealId: '90005' }), state).masterId, 'm3');
  assert.equal(findSlot(draft({ route: 'Минск', dealId: '90006' }), state).masterId, 'm4');
  assert.equal(findSlot(draft({ route: 'Гродно' }), state).masterId, 'm5');
});
test('перенос исключает текущую запись, но учитывает чужие резервы', () => {
  const state = createDemoState(); state.masters = [state.masters[0]];
  state.bookings = [state.bookings[2]];
  const input = draft({ windowEnd: '14:30' });
  assert.ok(findSlot(input, state).error);
  assert.equal(findSlot(input, state, state.bookings[0].id).actualStart, '14:00');
});
test('отклоняются некорректные даты, координаты и недостаточное окно', () => {
  const state = createDemoState();
  assert.ok(validateDraft(draft({ date: '2026-02-30' }), state));
  assert.ok(validateDraft(draft({ duration: 120, windowEnd: '15:00' }), state));
  assert.ok(validateDraft(draft({ lat: '91', lon: '25' }), state));
  assert.ok(validateDraft(draft({ lat: '53', lon: '' }), state));
  assert.equal(addDays('2026-11-30', 1), '2026-12-01');
});
test('начальные данные всех четырёх воронок не содержат конфликтующих назначений', () => {
  const state = createDemoState();
  assert.equal(new Set(state.bookings.map(b => state.deals.find(d => d.id === b.dealId).funnel)).size, 4);
  for (const booking of state.bookings) assert.deepEqual(conflicts(booking, state), [], booking.id);
});
test('группы поддерживают нескольких мастеров и не смешивают правила маршрута с другой группой', () => {
  const state = createDemoState(); state.bookings = []; state.masters = [state.masters[2]];
  state.masters[0].groupIds = ['g2', 'g1'];
  assert.equal(findSlot(draft(), state).masterId, 'm3');
  state.groups.find(g => g.id === 'g1').active = false;
  assert.ok(findSlot(draft(), state).error);
  state.groups = [
    { id: 'a', active: true, routes: ['Минск'], types: ['company'] },
    { id: 'b', active: true, routes: ['Светлогорск'], types: ['individual'] },
  ];
  state.masters[0].groupIds = ['a', 'b'];
  assert.ok(findSlot(draft({ route: 'Минск', dealId: '90005' }), state).error);
});
test('переход от ранней демо-модели сохраняет записи и соответствия мастеров', () => {
  const previous = createDemoState(); previous.schema = 1; delete previous.groups;
  previous.masters.forEach(master => delete master.groupIds);
  const next = migrateDemoState(previous);
  assert.equal(next.schema, 2); assert.equal(next.bookings.length, previous.bookings.length);
  assert.equal(next.groups.length, next.masters.length);
  assert.equal(next.masters[0].groupIds.length, 1);
  assert.deepEqual(next.groups[0].routes, previous.masters[0].routes);
  for (const booking of next.bookings) assert.deepEqual(conflicts(booking, next), []);
});
