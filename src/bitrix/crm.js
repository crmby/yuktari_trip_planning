import { createHash } from 'node:crypto';
import { CONTACT_FIELDS, DEAL_FIELDS, FUNNEL_NAMES, normalizeField } from './fields.js';
import { AppError, requireId } from './errors.js';

const asArray = value => Array.isArray(value) ? value : [];
const scalar = value => value === null || value === undefined || value === false ? '' : Array.isArray(value) ? value.length === 1 ? String(value[0]) : value.length ? value.join(', ') : '' : typeof value === 'object' ? '' : String(value);
export function crmDate(value) {
  if (/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return value;
  const date = new Date(value);
  if (!value || Number.isNaN(date.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en', { timeZone: 'Europe/Minsk', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  return ['year', 'month', 'day'].map(key => parts.find(p => p.type === key).value).join('-');
}
export function crmTime(value) {
  const text = scalar(value);
  if (/^\d{2}:\d{2}(?::\d{2})?$/.test(text)) return text.slice(0, 5);
  const date = new Date(text);
  return text && !Number.isNaN(date.getTime()) ? new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Minsk', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date) : '';
}
function bindFields(schema, mapping) {
  const result = {};
  for (const [property, code] of Object.entries(mapping)) {
    const key = Object.keys(schema.fields || {}).find(key => normalizeField(key) === normalizeField(code));
    if (!key || schema.fields[key].readonly === true || schema.fields[key].isReadOnly === true) throw new AppError(503, 'FIELD_NOT_AVAILABLE', `Поле ${code} отсутствует в доступной схеме или закрыто для записи.`);
    result[property] = { key, ...schema.fields[key] };
  }
  return result;
}
export function snapshotRevision(deal, contact, mapping) {
  return createHash('sha256').update(JSON.stringify({ contactId: String(contact.id), contact: Object.values(mapping.contact).map(f => contact[f.key] ?? null), deal: Object.values(mapping.deal).map(f => deal[f.key] ?? null) })).digest('hex');
}
export function createCrm(call, { routeIblockId = '' } = {}) {
  const cache = new Map();
  async function api(path, context, options = {}) { return call(path, { authorization: context.authorization, ...options }); }
  async function metadata(context) {
    const cacheKey = createHash('sha256').update(context.authorization).digest('hex');
    const cached = cache.get(cacheKey); if (cached && cached.until > Date.now()) return cached.value;
    const [deal, contact, fields, categories] = await Promise.all([
      api('/deals/fields', context), api('/contacts/fields', context), api('/userfields/contacts', context), api('/deal-categories', context),
    ]);
    const mapping = { deal: bindFields(deal.data, DEAL_FIELDS), contact: bindFields(contact.data, CONTACT_FIELDS) };
    const field = asArray(fields.data).find(f => normalizeField(f.fieldName) === normalizeField(CONTACT_FIELDS.route));
    const settings = field?.settings || {};
    const iblockId = requireId(routeIblockId || settings.IBLOCK_ID || settings.iblockId, 'инфоблока маршрутов (настройки поля контакта)');
    const detected = await api(`/lists/${iblockId}/type`, context);
    const iblockType = scalar(detected.data?.iblockTypeId);
    if (!['lists', 'lists_socnet', 'bitrix_processes', 'structure'].includes(iblockType)) throw new AppError(503, 'ROUTE_LIST_NOT_CONFIGURED', 'API не подтвердил поддерживаемый тип инфоблока поля «Маршрут». Проверьте, что поле ссылается на универсальный список, а не на товарный каталог.');
    mapping.contact.route.multiple = field?.multiple === 'Y' || field?.multiple === true || mapping.contact.route.multiple === true;
    const routes = [], seen = new Set(); let start = 0;
    for (let page = 0; page < 100; page++) {
      const query = new URLSearchParams({ iblockTypeId: iblockType, start: String(start), select: 'ID,NAME,ACTIVE' });
      const result = await api(`/lists/${iblockId}/elements?${query}`, context);
      for (const item of asArray(result.data)) { if (item.ACTIVE === 'N' || item.ACTIVE === false) continue; const id = requireId(item.ID); if (!routes.some(r => r.id === id)) routes.push({ id, label: scalar(item.NAME) }); }
      if (result.meta?.next === undefined || result.meta?.next === null) break;
      const next = Number(result.meta.next);
      if (!Number.isFinite(next) || next <= start || seen.has(next) || page === 99) throw new AppError(502, 'ROUTE_PAGINATION_FAILED', 'Не удалось полностью загрузить справочник маршрутов.');
      seen.add(next); start = next;
    }
    if (!routes.length) throw new AppError(503, 'ROUTES_EMPTY', 'В инфоблоке маршрутов нет доступных активных элементов.');
    const funnels = asArray(categories.data).map(category => ({ id: String(category.id ?? category.ID), name: scalar(category.name ?? category.NAME) })).map(category => ({ ...category, index: FUNNEL_NAMES.findIndex(name => name.trim().toLowerCase() === category.name.trim().toLowerCase()) })).filter(f => f.index >= 0);
    if (!funnels.length) throw new AppError(503, 'FUNNELS_NOT_FOUND', 'Не найдены согласованные воронки. Проверьте названия и права сотрудника.');
    const value = { ...mapping, routes, funnels, iblockId, iblockType };
    if (cache.size > 50) cache.clear(); cache.set(cacheKey, { value, until: Date.now() + 30_000 }); return value;
  }
  async function getRaw(id, context, meta) {
    const deal = (await api(`/deals/${requireId(id, 'сделки')}`, context)).data;
    const funnel = meta.funnels.find(f => f.id === String(deal.categoryId));
    if (!funnel) throw new AppError(403, 'FUNNEL_NOT_ALLOWED', 'Сделка не относится к согласованным воронкам.');
    const contacts = asArray(deal.contactIds).filter(Boolean);
    const contactId = deal.contactId || (contacts.length === 1 ? contacts[0] : null);
    if (!contactId) throw new AppError(409, 'PRIMARY_CONTACT_REQUIRED', 'Укажите основной контакт в карточке сделки. Адрес, квартира и маршрут берутся из него.');
    const [contact, products] = await Promise.all([api(`/contacts/${requireId(contactId, 'контакта')}`, context), api(`/deals/${requireId(id)}/products`, context)]);
    return { deal, contact: contact.data, products: asArray(products.data), funnel };
  }
  function normalize(raw, meta, context) {
    const { deal, contact, products, funnel } = raw;
    const c = property => contact[meta.contact[property].key], d = property => deal[meta.deal[property].key];
    const routeValue = c('route');
    const routeId = Array.isArray(routeValue) ? routeValue.length === 1 ? scalar(routeValue[0]) : '' : scalar(routeValue);
    return {
      id: requireId(deal.id), title: scalar(deal.title), contactId: requireId(contact.id), funnel: funnel.index,
      type: funnel.index === 0 || Number(deal.companyId) > 0 ? 'company' : 'individual',
      client: [contact.lastName, contact.name, contact.secondName].filter(Boolean).join(' ') || scalar(deal.title),
      phone: scalar(contact.phone), address: scalar(c('address')), apartment: scalar(c('apartment')), route: routeId,
      date: crmDate(d('date')), windowStart: crmTime(d('windowStart')), windowEnd: crmTime(d('windowEnd')),
      duration: Number(d('duration')) || 30, comment: scalar(d('comment')), years: scalar(d('years')),
      products: products.map(p => ({ name: scalar(p.productName), price: Number(p.price) || 0, quantity: Number(p.quantity) || 1, currency: scalar(deal.currency) })),
      revision: snapshotRevision(deal, contact, meta), url: `https://${context.portal}/crm/deal/details/${deal.id}/`,
      completion: scalar(deal.stageSemanticId) === 'S' ? 'Выполнен' : 'В работе',
    };
  }
  async function getDeal(id, context) { const meta = await metadata(context); return normalize(await getRaw(id, context, meta), meta, context); }
  async function searchDeals(query, context) {
    const meta = await metadata(context), q = String(query || '').trim().slice(0, 120);
    if (/^[1-9]\d{0,14}$/.test(q)) { try { const d = await getDeal(q, context); return [{ id: d.id, title: d.title, client: d.client, funnel: d.funnel }]; } catch (error) { if ([403, 404].includes(error.status)) return []; throw error; } }
    if (q.length < 2) return [];
    const pages = await Promise.all(meta.funnels.map(f => api('/deals?' + new URLSearchParams({ 'filter[categoryId]': f.id, 'filter[%title]': q, limit: '5', select: 'id,title,categoryId', 'order[id]': 'desc' }), context)));
    return pages.flatMap((page, i) => asArray(page.data).map(deal => ({ id: String(deal.id), title: scalar(deal.title), funnel: meta.funnels[i].index })));
  }
  async function calendar(date, cursor, context) {
    const meta = await metadata(context); const result = [];
    let next = null;
    // One funnel per page keeps every response small enough for the platform gateway.
    const index = Number(cursor?.split(':')[0] || 0), after = cursor?.split(':')[1] || '';
    const funnel = meta.funnels[index]; if (!funnel) return { deals: [], next: null };
    const key = meta.deal.date.key, dateTime = meta.deal.date.type === 'datetime';
    const query = new URLSearchParams({ 'filter[categoryId]': funnel.id, limit: '5', 'order[id]': 'asc', select: 'id', withTotal:'false' });
    if (dateTime) { query.set(`filter[>=${key}]`, `${date}T00:00:00+03:00`); query.set(`filter[<=${key}]`, `${date}T23:59:59+03:00`); }
    else query.set(`filter[${key}]`, date);
    if (after) query.set('filter[>id]', requireId(after));
    const page = await api('/deals?' + query, context);
    if (page.meta?.pageErrorSample || page.meta?.warnings?.some(w => /TRUNCATED|PARTIAL/.test(w.code))) throw new AppError(502, 'CALENDAR_PARTIAL', 'Битрикс24 вернул неполную страницу выездов. Повторите загрузку.');
    for (let offset = 0; offset < asArray(page.data).length; offset += 3) result.push(...await Promise.all(page.data.slice(offset, offset + 3).map(deal => getDeal(deal.id, context))));
    if (page.meta?.hasMore) {
      const last = scalar(page.meta.nextAfterId || page.data.at(-1)?.id);
      if (!last || last === after) throw new AppError(502, 'CALENDAR_CURSOR_FAILED', 'Ошибка продолжения списка сделок.');
      next = `${index}:${last}`;
    } else if (index + 1 < meta.funnels.length) next = `${index + 1}:`;
    return { deals: result, next };
  }
  async function employees(query, context) {
    const q = String(query || '').trim().slice(0, 100);
    const responses = await Promise.all((q ? ['%name', '%lastName'] : ['']).map(field => {
      const params = new URLSearchParams({ limit: '50', 'filter[active]': 'true' }); if (field) params.set(`filter[${field}]`, q);
      return api('/users?' + params, context);
    }));
    const people = responses.flatMap(r => asArray(r.data)).filter(p => p.active !== false);
    return [...new Map(people.map(p => [String(p.id), { id: requireId(p.id), name: [p.lastName, p.name, p.secondName].filter(Boolean).join(' '), position: scalar(p.workPosition) }])).values()];
  }
  function fieldValue(field, value, draft, property) {
    const type = field.type;
    if (value === '' && ['date', 'windowStart', 'windowEnd'].includes(property)) return field.nullable === true ? null : '';
    if (property === 'route') return field.multiple ? [Number(value)] : Number(value);
    if (property === 'date') return type === 'datetime' ? `${value}T00:00:00+03:00` : value;
    if (['windowStart', 'windowEnd'].includes(property)) return type === 'datetime' ? `${draft.date}T${value}:00+03:00` : value;
    if (['integer', 'double', 'number', 'float'].includes(type)) return value === '' ? null : Number(value);
    if (field.multiple === true) return value === '' ? [] : [String(value)];
    return value;
  }
  function writeFields(draft, meta) {
    return {
      contact: Object.fromEntries(Object.entries(meta.contact).map(([property, field]) => [field.key, fieldValue(field, draft[property], draft, property)])),
      deal: Object.fromEntries(Object.entries(meta.deal).map(([property, field]) => [field.key, fieldValue(field, draft[property], draft, property)])),
    };
  }
  async function write(id, contactId, fields, context) {
    if (Object.keys(fields.contact).length) await api(`/contacts/${requireId(contactId)}`, context, { method: 'PATCH', body: fields.contact });
    if (Object.keys(fields.deal).length) await api(`/deals/${requireId(id)}`, context, { method: 'PATCH', body: fields.deal });
  }
  return { metadata, getDeal, getRaw, normalize, searchDeals, calendar, employees, writeFields, write, api };
}
