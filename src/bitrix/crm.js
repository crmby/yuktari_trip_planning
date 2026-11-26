import { createHash } from 'node:crypto';
import { CONTACT_FIELDS, DEAL_FIELDS, PLANNING_FUNNEL_IDS, normalizeField } from './fields.js';
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
export function createCrm(call, { routeIblockId = '', referenceStore } = {}) {
  const cache = new Map(), inflight = new Map();
  const cacheKey = context => createHash('sha256').update(`${context.portal || ''}:${context.id}`).digest('hex');
  async function cached(context,kind,refresh,load) {
    const key=cacheKey(context)+':'+kind;
    if(inflight.has(key)) { const value=await inflight.get(key); if(!refresh)return value; }
    if(!refresh) {
      const saved=referenceStore ? (await referenceStore.read()).referenceCaches?.[key] : cache.get(key);
      if(saved?.version===1)return saved.value;
      if(inflight.has(key))return inflight.get(key);
    }
    const task=(async()=>{
      const value=await load(),entry={version:1,updatedAt:new Date().toISOString(),value};
      if(referenceStore) await referenceStore.transaction(async(state,persist)=>{
        state.referenceCaches ||= {};delete state.referenceCaches[key];state.referenceCaches[key]=entry;
        const keys=Object.keys(state.referenceCaches);
        while(keys.length>1 && (keys.length>40 || Buffer.byteLength(JSON.stringify(state))>3*1024*1024))delete state.referenceCaches[keys.shift()];
        await persist(state);
      });
      else cache.set(key,entry);
      return value;
    })();
    inflight.set(key,task);
    try{return await task;}finally{if(inflight.get(key)===task)inflight.delete(key);}
  }
  async function api(path, context, options = {}) { return call(path, { authorization: context.authorization, timeoutMs: context.timeoutMs, signal: context.signal, ...options }); }
  async function categoriesFor(context) {
    const result = [];
    for (let offset = 0; offset < 5000; offset += 50) {
      const page = await api('/categories/2?' + new URLSearchParams({limit:'50',offset:String(offset)}), context);
      const items = asArray(page.data); result.push(...items);
      if (items.length < 50 && !page.meta?.hasMore) return {data:result};
    }
    throw new AppError(502, 'CATEGORIES_INCOMPLETE', 'Не удалось полностью загрузить воронки Битрикс24.');
  }
  async function metadata(context,{refresh=false}={}) { return cached(context,'metadata',refresh,async()=>{
    const [deal, contact, fields, categories] = await Promise.all([
      api('/deals/fields', context), api('/contacts/fields', context), api('/userfields/contacts', context), categoriesFor(context),
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
    const all = asArray(categories.data).map(category => ({ id: String(category.id ?? category.ID), name: scalar(category.name ?? category.NAME) }));
    const funnels = PLANNING_FUNNEL_IDS.map(id=>all.find(f=>f.id===id)).filter(Boolean);
    const value = { ...mapping, routes, funnels, iblockId, iblockType };
    return value;
  }); }
  async function getRaw(id, context, meta, providedDeal, contactsCache) {
    const deal = providedDeal || (await api(`/deals/${requireId(id, 'сделки')}`, context)).data;
    const categoryId = String(deal.categoryId ?? 0);
    const funnel = meta.funnels.find(f => f.id === categoryId) || {id:categoryId,name:`Воронка №${categoryId}`};
    const contacts = asArray(deal.contactIds).filter(Boolean);
    const contactId = deal.contactId || (contacts.length === 1 ? contacts[0] : null);
    if (!contactId) throw new AppError(409, 'PRIMARY_CONTACT_REQUIRED', 'Укажите основной контакт в карточке сделки. Адрес, квартира и маршрут берутся из него.');
    const contactPath=`/contacts/${requireId(contactId,'контакта')}`;
    let contactRequest=contactsCache?.get(contactPath);
    if(!contactRequest){contactRequest=api(contactPath,context);contactsCache?.set(contactPath,contactRequest);}
    const [contact, products] = await Promise.all([contactRequest, api(`/deals/${requireId(id)}/products`, context)]);
    return { deal, contact: contact.data, products: asArray(products.data), funnel };
  }
  function normalize(raw, meta, context) {
    const { deal, contact, products, funnel } = raw;
    const c = property => contact[meta.contact[property].key], d = property => deal[meta.deal[property].key];
    const routeValue = c('route');
    const routeId = Array.isArray(routeValue) ? routeValue.length === 1 ? scalar(routeValue[0]) : '' : scalar(routeValue);
    return {
      id: requireId(deal.id), title: scalar(deal.title), contactId: requireId(contact.id), funnel: funnel.id, funnelName: funnel.name,
      type: Number(deal.companyId) > 0 ? 'company' : 'individual',
      client: [contact.lastName, contact.name, contact.secondName].filter(Boolean).join(' ') || scalar(deal.title),
      phone: scalar(contact.phone), address: scalar(c('address')).split('|')[0].trim(), addressValue:scalar(c('address')), apartment: scalar(c('apartment')), route: routeId,
      date: crmDate(d('date')), windowStart: crmTime(d('windowStart')), windowEnd: crmTime(d('windowEnd')),
      duration: Number(d('duration')) || 30, comment: scalar(d('comment')), years: scalar(d('years')),
      products: products.map(p => ({ name: scalar(p.productName), price: Number(p.price) || 0, quantity: Number(p.quantity) || 1, currency: scalar(deal.currency) })),
      revision: snapshotRevision(deal, contact, meta), url: `https://${context.portal}/crm/deal/details/${deal.id}/`,
      completion: scalar(deal.stageSemanticId) === 'S' ? 'Выполнен' : 'В работе',
    };
  }
  async function getDeal(id, context) { const meta = await metadata(context); return normalize(await getRaw(id, context, meta), meta, context); }
  async function searchDeals(query, context) {
    const q=String(query || '').trim().slice(0,120),result=[];let after='';
    for(let page=0;page<100;page++) {
      const filter={categoryId:{$in:PLANNING_FUNNEL_IDS.map(Number)}};
      if(!q)filter.stageSemanticId='P';
      else if(/^[1-9]\d{0,14}$/.test(q))filter.id=Number(q);
      else filter.title={$contains:q};
      if(after)filter.id={$gt:Number(after)};
      const params=new URLSearchParams({filter:JSON.stringify(filter),limit:q?'50':'10',select:'id,title,categoryId,stageSemanticId','order[id]':'asc',withTotal:'false'});
      const response=await api('/deals?'+params,context,{operationLabel:'search'});
      if(response.meta?.pageErrorSample || response.meta?.warnings?.some(w=>/TRUNCATED|PARTIAL/.test(w.code)))throw new AppError(502,'SEARCH_PARTIAL','Поиск вернул неполные данные. Повторите запрос.');
      const items=asArray(response.data);
      result.push(...items.filter(d=>PLANNING_FUNNEL_IDS.includes(String(d.categoryId)) && (q || d.stageSemanticId==='P')).map(d=>({id:requireId(d.id),title:scalar(d.title),funnel:String(d.categoryId)})));
      if(!q || !response.meta?.hasMore || /^[1-9]\d{0,14}$/.test(q))return result;
      const next=scalar(response.meta.nextAfterId || items.at(-1)?.id);
      if(!/^[1-9]\d*$/.test(next) || Number(next)<=Number(after))throw new AppError(502,'SEARCH_CURSOR_FAILED','Не удалось продолжить поиск сделок.');
      after=next;
    }
    throw new AppError(422,'SEARCH_TOO_WIDE','Слишком много совпадений. Уточните название сделки.');
  }

  async function calendar(date, cursor, context) {
    const meta = await metadata(context); const result = [];
    let next = null;
    const after = cursor?.split(':')[1] || '';
    const key = meta.deal.date.key, dateTime = meta.deal.date.type === 'datetime';
    const query = new URLSearchParams({ limit: '20', 'order[id]': 'asc', select:['id','title','categoryId','contactId','companyId','currency','stageSemanticId',...Object.values(meta.deal).map(f=>f.key)].join(','), withTotal:'false' });
    const filter={categoryId:{$in:PLANNING_FUNNEL_IDS.map(Number)}};
    filter[key]=dateTime ? {$gte:`${date}T00:00:00+03:00`,$lte:`${date}T23:59:59+03:00`} : date;
    if(after)filter.id={$gt:Number(requireId(after))};
    query.set('filter',JSON.stringify(filter));
    const page = await api('/deals?' + query, context,{operationLabel:'calendar'});
    if (page.meta?.pageErrorSample || page.meta?.warnings?.some(w => /TRUNCATED|PARTIAL/.test(w.code))) throw new AppError(502, 'CALENDAR_PARTIAL', 'Битрикс24 вернул неполную страницу выездов. Повторите загрузку.');
    const contactsCache=new Map();let unavailable=0;
    for (let offset = 0; offset < asArray(page.data).length; offset += 4) {
      const rows=await Promise.all(page.data.slice(offset,offset+4).map(async deal=>{
        try{return normalize(await getRaw(deal.id,context,meta,Object.hasOwn(deal,meta.deal.date.key)?deal:undefined,contactsCache),meta,context);}
        catch(error){if([403,404,409].includes(error.status)){unavailable++;return null;}throw error;}
      }));result.push(...rows.filter(Boolean));
    }
    if (page.meta?.hasMore) {
      const last = scalar(page.meta.nextAfterId || page.data.at(-1)?.id);
      if (!last || last === after) throw new AppError(502, 'CALENDAR_CURSOR_FAILED', 'Ошибка продолжения списка сделок.');
      next = `0:${last}`;
    }
    return { deals: result, next, unavailable };
  }
  async function employeeDirectory(context,{refresh=false}={}) { return cached(context,'employees',refresh,async()=>{
    const people=[];
    for(let offset=0;offset<10000;offset+=50) {
      const params=new URLSearchParams({limit:'50',offset:String(offset),sort:'id','filter[active]':'true',select:'id,name,lastName,secondName,active,workPosition'});
      const response=await api('/users?'+params,context),items=asArray(response.data);
      if(response.meta?.pageErrorSample || response.meta?.warnings?.some(w=>/TRUNCATED|PARTIAL/.test(w.code)))throw new AppError(502,'EMPLOYEES_PARTIAL','Список сотрудников получен не полностью. Повторите загрузку.');
      for(const p of items)if(p.active!==false && p.active!=='N')people.push({id:requireId(p.id),name:[p.lastName,p.name,p.secondName].filter(Boolean).join(' ') || 'Сотрудник №'+p.id,position:scalar(p.workPosition)});
      if(!response.meta?.hasMore)return [...new Map(people.map(p=>[p.id,p])).values()];
      if(!items.length)throw new AppError(502,'EMPLOYEES_PARTIAL','Не удалось продолжить загрузку сотрудников.');
    }
    throw new AppError(502,'EMPLOYEES_PARTIAL','Список сотрудников слишком велик для одной загрузки.');
  }); }
  async function employees(query,context) {
    const q=String(query || '').trim().toLocaleLowerCase('ru');
    return (await employeeDirectory(context)).filter(p=>!q || q.split(/\s+/).every(word=>p.name.toLocaleLowerCase('ru').includes(word)));
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
  return { metadata, getDeal, getRaw, normalize, searchDeals, calendar, employees, employeeDirectory, writeFields, write, api };
}
