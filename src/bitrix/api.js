import { createVibeClient, createAuthenticator } from './client.js';
import { createCrm } from './crm.js';
import { createStore } from './store.js';
import { createPlanningService } from './service.js';
import { AppError } from './errors.js';

export function createBitrixApi(config, dependencies = {}) {
  const call = dependencies.call || createVibeClient({ appKey: config.appKey, fetchImpl: dependencies.fetchImpl });
  const authenticate = dependencies.authenticate || createAuthenticator(call, config.portal);
  const crm = dependencies.crm || createCrm(call, config);
  const store = dependencies.store || createStore(config.dataDir);
  const service = createPlanningService({ crm, store, managerIds: config.managerIds, appUrl: config.appUrl });
  function send(res, status, data) { res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(data)); }
  async function readBody(req) {
    if (!String(req.headers['content-type'] || '').startsWith('application/json')) throw new AppError(415, 'JSON_REQUIRED', 'Требуется JSON-запрос.');
    let size = 0; const chunks = [];
    for await (const chunk of req) { size += chunk.length; if (size > 1024 * 1024) throw new AppError(413, 'BODY_TOO_LARGE', 'Слишком большой запрос.'); chunks.push(chunk); }
    try { const value = JSON.parse(Buffer.concat(chunks).toString('utf8')); if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(); return value; }
    catch { throw new AppError(400, 'INVALID_JSON', 'Некорректный JSON-запрос.'); }
  }
  return async function handle(req, res, url) {
    try {
      if (req.method === 'GET' && url.pathname === '/api/runtime') return send(res, 200, { mode: 'bitrix' });
      const context = { ...await authenticate(req), portal: config.portal };
      if (req.method === 'POST') {
        const origin = config.appUrl ? new URL(config.appUrl).origin : '';
        if (!origin || req.headers.origin !== origin || req.headers['x-requested-with'] !== 'yuktari-planner') throw new AppError(403, 'CSRF_REJECTED', 'Запрос должен быть отправлен из интерфейса приложения.');
      }
      let result;
      if (req.method === 'GET' && url.pathname === '/api/bootstrap') result = await service.bootstrap(context);
      else if (req.method === 'GET' && url.pathname === '/api/deals') result = await crm.searchDeals(url.searchParams.get('q'), context);
      else if (req.method === 'GET' && /^\/api\/deals\/[1-9]\d*$/.test(url.pathname)) result = await service.deal(url.pathname.split('/').at(-1), context);
      else if (req.method === 'GET' && url.pathname === '/api/calendar') result = await service.calendar(url.searchParams.get('date'), url.searchParams.get('cursor'), context);
      else if (req.method === 'GET' && /^\/api\/bookings\/pending\/[a-f0-9-]{36}$/i.test(url.pathname)) result = await service.pending(url.pathname.split('/').at(-1), context);
      else if (req.method === 'GET' && url.pathname === '/api/employees') {
        if (!service.canManage(context)) throw new AppError(403, 'MANAGER_REQUIRED', 'Выбор мастеров доступен назначенному менеджеру приложения.');
        result = await crm.employees(url.searchParams.get('q'), context);
      }
      else if (req.method === 'POST' && url.pathname === '/api/bookings') result = await service.save(await readBody(req), context);
      else if (req.method === 'POST' && url.pathname === '/api/bookings/reconcile') result = await service.reconcile(await readBody(req), context);
      else if (req.method === 'POST' && url.pathname === '/api/settings') result = await service.settings(await readBody(req), context);
      else if (req.method === 'POST' && url.pathname === '/api/placement') { await readBody(req); result = await service.placement(context); }
      else throw new AppError(404, 'NOT_FOUND', 'Маршрут API не найден.');
      send(res, 200, { success: true, data: result });
    } catch (error) {
      const known = error instanceof AppError;
      if (!res.headersSent) send(res, known ? error.status : 500, { success: false, error: { code: known ? error.code : 'INTERNAL_ERROR', message: known ? error.message : 'Не удалось выполнить операцию. Обратитесь к администратору приложения.' } });
      else res.end();
    }
  };
}
