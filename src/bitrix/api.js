import { createVibeClient, createAuthenticator } from './client.js';
import { createCrm } from './crm.js';
import { createStore } from './store.js';
import { createPlanningService } from './service.js';
import { AppError } from './errors.js';
import { version } from '../build.js';
import { createReadJobs } from './read-jobs.js';
import { join } from 'node:path';
import { createHash } from 'node:crypto';

export function createBitrixApi(config, dependencies = {}) {
  const call = dependencies.call || createVibeClient({ appKey: config.appKey, fetchImpl: dependencies.fetchImpl });
  const authenticate = dependencies.authenticate || createAuthenticator(call, config.portal);
  const crm = dependencies.crm || createCrm(call, {...config,referenceStore:dependencies.referenceStore || createStore(join(config.dataDir,'references'))});
  const store = dependencies.store || createStore(config.dataDir);
  const service = createPlanningService({ crm, store, managerIds: config.managerIds, appUrl: config.appUrl });
  const jobs = createReadJobs({allowWrites:true});
  function send(res, status, data) { res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(data)); }
  async function readBody(req) {
    if (!String(req.headers['content-type'] || '').startsWith('application/json')) throw new AppError(415, 'JSON_REQUIRED', 'Требуется JSON-запрос.');
    let size = 0; const chunks = [];
    for await (const chunk of req) { size += chunk.length; if (size > 1024 * 1024) throw new AppError(413, 'BODY_TOO_LARGE', 'Слишком большой запрос.'); chunks.push(chunk); }
    try { const value = JSON.parse(Buffer.concat(chunks).toString('utf8')); if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(); return value; }
    catch { throw new AppError(400, 'INVALID_JSON', 'Некорректный JSON-запрос.'); }
  }
  function failure(error) {
    const known = error instanceof AppError;
    return {status:known ? error.status : 500,body:{success:false,error:{code:known ? error.code : 'INTERNAL_ERROR',message:known ? error.message : 'Не удалось выполнить операцию. Обратитесь к администратору приложения.'}}};
  }
  async function execute(req, url, options = {}, suppliedBody) {
    const body = () => suppliedBody === undefined ? readBody(req) : suppliedBody;
    try {
      const context = { ...await authenticate(req,options), ...options, portal: config.portal };
      if (req.method === 'POST') {
        const origin = config.appUrl ? new URL(config.appUrl).origin : '';
        if (!origin || req.headers.origin !== origin || req.headers['x-requested-with'] !== 'yuktari-planner') throw new AppError(403, 'CSRF_REJECTED', 'Запрос должен быть отправлен из интерфейса приложения.');
      }
      let result;
      if (req.method === 'GET' && url.pathname === '/api/bootstrap') result = await service.bootstrap(context,{refresh:url.searchParams.get('refresh')==='1'});
      else if (req.method === 'GET' && url.pathname === '/api/deals') result = await crm.searchDeals(url.searchParams.get('q'), context);
      else if (req.method === 'GET' && /^\/api\/deals\/[1-9]\d*$/.test(url.pathname)) result = await service.deal(url.pathname.split('/').at(-1), context);
      else if (req.method === 'GET' && url.pathname === '/api/calendar') result = await service.calendar(url.searchParams.get('date'), url.searchParams.get('cursor'), context);
      else if (req.method === 'GET' && /^\/api\/bookings\/pending\/[a-f0-9-]{36}$/i.test(url.pathname)) result = await service.pending(url.pathname.split('/').at(-1), context);
      else if (req.method === 'GET' && url.pathname === '/api/employees') {
        if (!service.canManage(context)) throw new AppError(403, 'MANAGER_REQUIRED', 'Выбор мастеров доступен назначенному менеджеру приложения.');
        result = await crm.employees(url.searchParams.get('q'), context);
      }
      else if (req.method === 'POST' && url.pathname === '/api/bookings') result = await service.save(await body(), context);
      else if (req.method === 'POST' && url.pathname === '/api/bookings/reconcile') result = await service.reconcile(await body(), context);
      else if (req.method === 'POST' && url.pathname === '/api/settings') result = await service.settings(await body(), context);
      else if (req.method === 'POST' && url.pathname === '/api/placement') { await body(); result = await service.placement(context); }
      else throw new AppError(404, 'NOT_FOUND', 'Маршрут API не найден.');
      return {status:200,body:{success:true,data:result}};
    } catch (error) {
      return failure(error);
    }
  }
  return async function handle(req,res,url) {
    try {
      if (req.method === 'GET' && url.pathname === '/api/runtime') return send(res,200,{mode:'bitrix',version});
      let result;
      if (req.method === 'GET' && /^\/api\/read-jobs\/[a-f0-9-]{36}$/.test(url.pathname)) result=jobs.poll(req,url.pathname.split('/').at(-1));
      else if(req.method==='POST' && req.headers['x-planner-async']==='1' && ['/api/bookings','/api/bookings/reconcile','/api/settings'].includes(url.pathname)){
        const origin=config.appUrl ? new URL(config.appUrl).origin : '';
        if(!origin || req.headers.origin!==origin || req.headers['x-requested-with']!=='yuktari-planner')throw new AppError(403,'CSRF_REJECTED','Запрос должен быть отправлен из интерфейса приложения.');
        const input=await readBody(req),key='POST:'+url.pathname+':'+createHash('sha256').update(JSON.stringify(input)).digest('hex');
        result=jobs.start(req,key,options=>execute(req,url,options,input));
      }
      else if (req.method === 'GET' && req.headers['x-planner-async'] === '1') result=jobs.start(req,url.pathname+url.search,options=>execute(req,url,options));
      else result=await execute(req,url);
      send(res,result.status,result.body);
    } catch(error) { const result=failure(error); send(res,result.status,result.body); }
  };
}
