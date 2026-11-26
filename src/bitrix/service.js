import { randomUUID } from 'node:crypto';
import { AppError, requireId } from './errors.js';
import { findSlot, validateDraft, minutes, time, validDate } from '../../public/js/planner.js';

const properties = ['address', 'apartment', 'route', 'date', 'windowStart', 'windowEnd', 'duration', 'comment', 'years'];
function cleanDraft(input) {
  const draft = { dealId: requireId(input?.dealId, 'сделки'), city: '', region: '', district: '', lat: '', lon: '', special: false, status: 'planned' };
  for (const property of properties) draft[property] = property === 'duration' ? Number(input[property]) : String(input[property] ?? '').trim();
  if (draft.address.length > 2000 || draft.apartment.length > 100 || draft.comment.length > 10000) throw new AppError(400, 'VALUE_TOO_LONG', 'Слишком длинный адрес, квартира или комментарий.');
  draft.position = Number.isFinite(minutes(draft.windowStart)) ? time(Math.max(540, Math.min(1050, Math.floor(minutes(draft.windowStart) / 30) * 30))) : '09:00';
  return draft;
}
function matches(deal, draft) {
  return properties.every(key => key === 'duration' ? Number(deal[key]) === Number(draft[key]) : key === 'years' && draft[key] !== '' && Number.isFinite(Number(draft[key])) ? Number(deal[key]) === Number(draft[key]) : String(deal[key] ?? '') === String(draft[key] ?? ''));
}
function recordFromDeal(deal, old) {
  if (!deal.date && old?.status !== 'cancelled') return null;
  const from = minutes(deal.windowStart);
  return {
    id: old?.id || `crm-${deal.id}`, dealId: deal.id, date: deal.date || old?.date,
    address: deal.address, apartment: deal.apartment, route: deal.route, years: deal.years,
    windowStart: deal.windowStart, windowEnd: deal.windowEnd, duration: deal.duration, comment: deal.comment,
    position: old?.position || (Number.isFinite(from) ? time(Math.max(540, Math.min(1050, Math.floor(from / 30) * 30))) : '09:00'),
    actualStart: old?.actualStart || deal.windowStart || '09:00', masterId: old?.masterId || '',
    status: old?.status === 'cancelled' ? 'cancelled' : deal.completion === 'Выполнен' ? 'done' : 'planned',
    syncState: old?.syncState || 'synced', special: false, city: '', region: '', district: '', lat: '', lon: '',
  };
}
export function createPlanningService({ crm, store, managerIds = [], appUrl }) {
  const canManage = context => context.isAdmin || managerIds.includes(context.id);
  async function bootstrap(context) {
    const meta = await crm.metadata(context), saved = await store.read();
    return { mode: 'bitrix', portal: context.portal, user: { id: context.id, name: context.name, isAdmin: context.isAdmin }, canManage: canManage(context),
      pendingOperations: saved.operations.filter(op => op.userId === context.id && op.status !== 'done').map(op => ({operationId:op.id, dealId:op.dealId})),
      state: { schema: 2, revision: saved.revision, mode: 'bitrix', routeOptions: meta.routes, masters: saved.masters, groups: saved.groups, bookings: [], deals: [], audit: [] } };
  }
  async function deal(id, context) {
    const item = await crm.getDeal(id, context), saved = await store.read();
    return { deal: item, booking: recordFromDeal(item, saved.bookings.find(b => b.dealId === item.id)) };
  }
  async function calendar(date, cursor, context) {
    if (!validDate(date) || (cursor && !/^\d{1,2}:[1-9]?\d{0,14}$/.test(cursor))) throw new AppError(400, 'INVALID_CALENDAR_QUERY', 'Некорректная дата или страница календаря.');
    const page = await crm.calendar(date, cursor, context), saved = await store.read();
    return { ...page, bookings: page.deals.map(d => recordFromDeal(d, saved.bookings.find(b => b.dealId === d.id))).filter(Boolean), revision: saved.revision };
  }
  async function save(input, context) {
    const operationId = String(input?.operationId || '');
    if (!/^[a-f0-9-]{36}$/i.test(operationId)) throw new AppError(400, 'OPERATION_ID_REQUIRED', 'Повторите сохранение из формы приложения.');
    return store.transaction(async (state, persist) => {
      const previous = state.operations.find(op => op.id === operationId && op.userId === context.id);
      if (previous) {
        const current = await crm.getDeal(previous.dealId, context);
        if (current.contactId === previous.contactId && matches(current, previous.draft)) {
          const booking = state.bookings.find(b => b.dealId === previous.dealId);
          if (booking) { booking.syncState = 'synced'; if (previous.cancel) booking.status = 'cancelled'; } previous.status = 'done'; await persist(state);
          return { deal: current, booking: recordFromDeal(current, booking), revision: state.revision };
        }
        throw new AppError(409, 'CRM_RECONCILIATION_REQUIRED', 'Предыдущее сохранение выполнено не полностью. Проверьте контакт и сделку в CRM; автоматическая повторная запись остановлена.');
      }
      let draft = cleanDraft(input.draft); const meta = await crm.metadata(context), cancelling = input.cancel === true;
      const current = await crm.getDeal(draft.dealId, context);
      if (!input.expectedRevision || current.revision !== input.expectedRevision) throw new AppError(409, 'CRM_CHANGED', 'Контакт или сделка изменены после открытия формы. Загрузите актуальные данные и повторите сохранение.');
      if (state.bookings.some(b => b.dealId === draft.dealId && b.syncState === 'pending')) throw new AppError(409, 'CRM_RECONCILIATION_REQUIRED', 'По этой сделке есть незавершённая синхронизация. Проверьте результат предыдущей операции.');
      const old = state.bookings.find(b => b.dealId === draft.dealId);
      if (cancelling) draft = cleanDraft({ ...current, dealId: current.id });
      const planning = { ...state, mode: 'bitrix', deals: [current], routeOptions: meta.routes };
      const error = validateDraft(draft, planning); if (!cancelling && error) throw new AppError(400, 'INVALID_BOOKING', error);
      const slot = cancelling ? { masterId: old?.masterId || '', actualStart: old?.actualStart || current.windowStart || '09:00' } : findSlot(draft, planning, old?.id);
      if (slot.error) throw new AppError(409, 'NO_CAPACITY', slot.error);
      const booking = { ...draft, ...slot, id: old?.id || randomUUID(), syncState: 'pending', updatedBy: context.id };
      state.bookings = state.bookings.filter(b => b.dealId !== draft.dealId); state.bookings.push(booking);
      const writeDraft = cancelling ? { ...draft, date: '', windowStart: '', windowEnd: '' } : draft;
      const operation = { id: operationId, userId: context.id, dealId: draft.dealId, contactId: current.contactId, draft: writeDraft, cancel: cancelling, status: 'pending', at: new Date().toISOString() };
      state.operations.push(operation); state.operations = state.operations.filter(op => op.status !== 'done').concat(state.operations.filter(op => op.status === 'done').slice(-100));
      await persist(state); // Reserve durably before any CRM write; unresolved writes keep the reservation.
      try {
        const fields = crm.writeFields(writeDraft, meta);
        if (cancelling) { fields.contact = {}; fields.deal = Object.fromEntries(['date', 'windowStart', 'windowEnd'].map(key => [meta.deal[key].key, fields.deal[meta.deal[key].key]])); }
        // Only supplied UF fields are patched: no stages, products or Wialon calls.
        await crm.write(draft.dealId, current.contactId, fields, context);
        const actual = await crm.getDeal(draft.dealId, context);
        if (actual.contactId !== current.contactId || !matches(actual, writeDraft)) throw new AppError(502, 'CRM_READBACK_MISMATCH', 'CRM не подтвердила все сохранённые значения. Проверьте результат в сделке и контакте.');
        booking.syncState = 'synced'; if (cancelling) booking.status = 'cancelled'; operation.status = 'done';
        state.audit.push({ at: new Date().toISOString(), userId: context.id, action: 'booking.saved', dealId: draft.dealId, operationId });
        await persist(state); return { deal: actual, booking: recordFromDeal(actual, booking), revision: state.revision };
      } catch (error) {
        operation.status = 'uncertain'; await persist(state);
        throw new AppError(502, 'CRM_SYNC_UNCERTAIN', 'Синхронизация не подтверждена полностью. Резерв сохранён. Нажмите «Проверить результат»; не создавайте дубликат выезда.');
      }
    });
  }
  async function settings(input, context) {
    if (!canManage(context)) throw new AppError(403, 'MANAGER_REQUIRED', 'Настройка мастеров и графиков доступна администратору или назначенному менеджеру приложения.');
    const meta = await crm.metadata(context);
    return store.transaction(async (state, persist) => {
      if (input.revision !== state.revision) throw new AppError(409, 'STATE_CHANGED', 'Настройки изменены другим сотрудником. Обновите страницу.');
      if (!Array.isArray(input.groups) || !Array.isArray(input.masters) || input.groups.length > 100 || input.masters.length > 300) throw new AppError(400, 'INVALID_SETTINGS', 'Некорректные настройки мастеров.');
      const groups = input.groups.map(group => {
        if (typeof group.id !== 'string' || !/^[a-zA-Z0-9-]{1,80}$/.test(group.id) || typeof group.name !== 'string' || !group.name.trim() || group.name.length > 100 || !Array.isArray(group.routes) || !group.routes.length || group.routes.some(id => !meta.routes.some(r => r.id === id)) || !Array.isArray(group.types) || !group.types.length || group.types.some(t => !['individual', 'company'].includes(t))) throw new AppError(400, 'INVALID_GROUP', 'Проверьте название, направления и типы клиентов группы.');
        return { id: group.id, name: group.name.trim(), routes: [...new Set(group.routes)], types: [...new Set(group.types)], active: group.active === true };
      });
      const masters = [];
      for (const inputMaster of input.masters) {
        const existing = state.masters.find(m => m.id === inputMaster.id);
        const userId = requireId(inputMaster.bitrixUserId, 'сотрудника');
        if (existing && existing.bitrixUserId !== userId) throw new AppError(400, 'EMPLOYEE_IMMUTABLE', 'Для замены сотрудника создайте нового мастера; история старого должна сохраниться.');
        const employee = (await crm.api(`/users/${userId}`, context)).data;
        if (!employee || (employee.active === false && inputMaster.active)) throw new AppError(400, 'EMPLOYEE_INACTIVE', 'Выберите действующего сотрудника Битрикс24.');
        if (!Array.isArray(inputMaster.groupIds) || !inputMaster.groupIds.length || inputMaster.groupIds.some(id => !groups.some(g => g.id === id))) throw new AppError(400, 'INVALID_MASTER_GROUP', 'Выберите группы мастера.');
        const validateShift = shift => shift === null || (shift && Number.isFinite(minutes(shift.start)) && Number.isFinite(minutes(shift.end)) && minutes(shift.start) < minutes(shift.end));
        const week = {}, exceptions = {};
        for (const [day, shift] of Object.entries(inputMaster.week || {})) {
          if (!/^[0-6]$/.test(day) || !shift || !validateShift(shift)) throw new AppError(400, 'INVALID_SHIFT', 'Проверьте недельный график мастера.'); week[day] = { start: shift.start, end: shift.end };
        }
        for (const [date, shift] of Object.entries(inputMaster.exceptions || {})) {
          if (!validDate(date) || !validateShift(shift)) throw new AppError(400, 'INVALID_SHIFT', 'Проверьте исключения в графике мастера.'); exceptions[date] = shift ? { start: shift.start, end: shift.end } : null;
        }
        if (Object.keys(exceptions).length > 730) throw new AppError(400, 'TOO_MANY_EXCEPTIONS', 'Слишком много исключений графика.');
        const directions = inputMaster.directions || [];
        if (!Array.isArray(directions) || directions.some(id => !meta.routes.some(r => r.id === id))) throw new AppError(400, 'INVALID_DIRECTIONS', 'Выберите направления из справочника маршрутов.');
        masters.push({ id: existing?.id || randomUUID(), bitrixUserId: userId, name: [employee.lastName, employee.name, employee.secondName].filter(Boolean).join(' '), active: inputMaster.active === true, groupIds: [...new Set(inputMaster.groupIds)], directions: [...new Set(directions)], wialon: String(inputMaster.wialon || '').slice(0, 100), week, exceptions });
      }
      if (new Set(masters.map(m => m.bitrixUserId)).size !== masters.length || new Set(groups.map(g => g.id)).size !== groups.length) throw new AppError(400, 'DUPLICATE_SETTINGS', 'Сотрудник или группа добавлены повторно.');
      if (state.masters.some(m => !masters.some(updated => updated.id === m.id))) throw new AppError(400, 'MASTER_HISTORY_REQUIRED', 'Мастеров с историей нельзя удалять: используйте деактивацию.');
      state.masters = masters; state.groups = groups;
      state.audit.push({ at: new Date().toISOString(), userId: context.id, action: 'settings.saved' }); await persist(state);
      return { masters, groups, revision: state.revision };
    });
  }
  async function placement(context) {
    if (!context.isAdmin) throw new AppError(403, 'ADMIN_REQUIRED', 'Встраивание настраивает администратор портала.');
    if (!appUrl || !/^https:\/\/[a-z0-9.-]+\/?$/i.test(appUrl)) throw new AppError(503, 'APP_URL_REQUIRED', 'Укажите точный HTTPS APP_URL сервера приложения.');
    const result = await crm.api('/placements/bind', context, { method: 'POST', body: { placement: 'CRM_DEAL_DETAIL_TAB', handler: appUrl, title: 'Планирование выездов' } });
    return { submitted: true, message: 'VibeCode принял привязку вкладки. Откройте сделку и проверьте появление приложения.' };
  }
  async function reconcile(input, context) {
    return store.transaction(async (state, persist) => {
      const operation = state.operations.find(op => op.id === input.operationId && op.userId === context.id);
      if (!operation) throw new AppError(404, 'OPERATION_NOT_FOUND', 'Операция сохранения не найдена.');
      const current = await crm.getDeal(operation.dealId, context);
      if (current.contactId !== operation.contactId || !matches(current, operation.draft)) throw new AppError(409, 'CRM_RECONCILIATION_REQUIRED', 'CRM пока не подтверждает все значения. Проверьте контакт и сделку; резерв сохранён.');
      const booking = state.bookings.find(b => b.dealId === operation.dealId);
      if (booking) { booking.syncState = 'synced'; if (operation.cancel) booking.status = 'cancelled'; }
      operation.status = 'done'; await persist(state);
      return { deal: current, booking: recordFromDeal(current, booking), revision: state.revision };
    });
  }
  async function pending(id, context) {
    const saved = await store.read(), operation = saved.operations.find(op => op.id === id && op.userId === context.id && op.status !== 'done');
    if (!operation) throw new AppError(404, 'OPERATION_NOT_FOUND', 'Незавершённая операция не найдена.');
    await crm.getDeal(operation.dealId, context); // Recheck current CRM access before returning the private draft.
    return { draft:operation.draft, cancel:operation.cancel };
  }
  return { bootstrap, deal, calendar, save, reconcile, pending, settings, placement, canManage };
}
