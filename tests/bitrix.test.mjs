import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { createServer } from 'node:http';
import { createAuthenticator, createVibeClient } from '../src/bitrix/client.js';
import { createCrm } from '../src/bitrix/crm.js';
import { createStore } from '../src/bitrix/store.js';
import { createPlanningService } from '../src/bitrix/service.js';
import { createApp } from '../src/app.js';
import { version } from '../src/build.js';
import { AppError } from '../src/bitrix/errors.js';
import { fixture, context } from './fixtures/crm.mjs';

async function setup(t) {
  const directory=await mkdtemp(join(tmpdir(),'yuktari-test-'));
  t.after(()=>rm(directory,{recursive:true,force:true}));
  const f=fixture(),store=createStore(directory);
  await store.transaction(async(state,persist)=>{state.masters=f.masters;state.groups=f.groups;await persist(state);});
  return {...f,store,directory,service:createPlanningService({crm:f.crm,store,appUrl:'https://test.invalid'})};
}
async function input(f,id='101') {
  const deal=await f.crm.getDeal(id,context);
  return {operationId:randomUUID(),expectedRevision:deal.revision,draft:{...deal,dealId:id,date:'2026-11-25',windowStart:'09:00',windowEnd:'10:00',duration:60,route:'81',address:'Новый адрес',apartment:'25',years:'4',comment:'Позвонить перед приездом'}};
}
test('авторизация проверяет сессию через VibeCode; ID, роль и URL не подтверждают вход',async()=>{
  let requests=0;
  const call=createVibeClient({appKey:'vibe_app_test',fetchImpl:async()=>{requests++;throw new Error();}});
  const auth=createAuthenticator(call,context.portal);
  await assert.rejects(auth({headers:{'x-vibe-user-id':'7','x-vibe-user-role':'admin'}}),{code:'AUTH_REQUIRED'});
  assert.equal(requests,0);
  const f=fixture(),valid=createAuthenticator(f.call,context.portal);
  assert.equal((await valid({headers:{'x-vibe-authorization':context.authorization}})).id,'7');
  await assert.rejects(createAuthenticator(f.call,'foreign.bitrix24.by')({headers:{'x-vibe-authorization':context.authorization}}),{code:'PORTAL_MISMATCH'});
  await assert.rejects(valid({headers:{'x-vibe-caller-kind':'external-api'}}),{code:'AUTH_REQUIRED'});
});
test('канонические UF-поля, все услуги и ID инфоблока загружаются без демонстрационных значений',async()=>{
  const f=fixture(),meta=await f.crm.metadata(context),deal=await f.crm.getDeal('101',context);
  assert.deepEqual(meta.routes.map(r=>r.id),['81','82']);
  assert.equal(deal.apartment,'14');assert.equal(deal.route,'81');assert.equal(deal.phone,'+375000000000');
  assert.equal(deal.products.length,2);assert.equal(deal.products[0].quantity,2);
  assert.equal(deal.years,'3');assert.equal(deal.comment,'Отдельный комментарий');
});
test('произвольная длительность в минутах сохраняется, координаты адресного поля не теряются',async t=>{
  const f=await setup(t);f.contacts.get('201')[f.contactKeys.address]='Тестовый адрес|53.9;27.5';
  const request=await input(f);request.draft.address='Тестовый адрес';request.draft.duration=75;request.draft.windowEnd='11:00';
  const result=await f.service.save(request,context);
  assert.equal(result.deal.duration,75);assert.equal(result.deal.address,'Тестовый адрес');
  assert.equal(f.contacts.get('201')[f.contactKeys.address],'Тестовый адрес|53.9;27.5');
});
test('календарь читает свежие поля из списка без отдельного get каждой сделки и переживает недоступный контакт',async()=>{
  const f=fixture(),requests=[];for(const d of f.deals.values())d[f.dealKeys.date]='2026-11-25';
  const crm=createCrm(async(path,options)=>{requests.push(path);if(path==='/contacts/202')throw new AppError(404,'ENTITY_NOT_FOUND','Нет контакта');return f.call(path,options);});
  const page=await crm.calendar('2026-11-25',null,context);
  assert.deepEqual(page.deals.map(d=>d.id),['101']);assert.equal(page.unavailable,1);
  assert.equal(requests.filter(path=>/^\/deals\/\d+$/.test(path)).length,0);
});
test('частично сохранённый выезд виден из календаря; чужая незавершённая сделка не блокирует выбранную',async t=>{
  const f=await setup(t),request=await input(f);f.setFailure(true);
  await assert.rejects(f.service.save(request,context),{code:'CRM_SYNC_UNCERTAIN'});
  const page=await f.service.calendar('2026-11-25',null,context);
  assert.equal(page.bookings[0].dealId,'101');assert.equal(page.bookings[0].date,'2026-11-25');assert.equal(page.bookings[0].syncState,'pending');
  const independent=await f.service.deal('102',context);assert.equal(independent.pendingOperation,null);assert.equal(independent.deal.id,'102');
  const originalGet=f.crm.getDeal;
  f.crm.getDeal=async(id,ctx)=>{if(id==='999')throw new AppError(404,'ENTITY_NOT_FOUND','Сделка недоступна');return originalGet(id,ctx);};
  await f.store.transaction(async(state,persist)=>{state.bookings.push({...state.bookings[0],id:'old',dealId:'999'});await persist(state);});
  assert.deepEqual((await f.service.calendar('2026-11-25',null,context)).bookings.map(b=>b.dealId),['101']);
  assert.equal(f.writes.length,2);
});
test('потерянный ответ подтверждается автоматически при чтении текущей сделки без повторной записи',async t=>{
  const f=await setup(t),request=await input(f);f.setLostReply(true);
  await assert.rejects(f.service.save(request,context),{code:'CRM_SYNC_UNCERTAIN'});
  const result=await f.service.deal('101',context);
  assert.equal(result.pendingOperation,null);assert.equal(result.booking.syncState,'synced');assert.equal(f.writes.length,2);
});
test('фильтры используют заданные ID воронок, контекст сделки и права CRM независимы от названия',async()=>{
  const f=fixture(),requests=[];
  const crm=createCrm(async(path,options)=>{
    requests.push(path);
    if(path.startsWith('/categories/2?')) return {data:[{id:0,name:'Продажа и аренда'},{id:42,name:'Новая воронка'}]};
    return f.call(path,options);
  });
  f.deals.get('101').categoryId=0;f.deals.get('102').categoryId=42;f.deals.get('102').companyId=10;
  const first=await crm.getDeal('101',context),second=await crm.getDeal('102',context);
  assert.equal(first.funnel,'0');assert.equal(first.funnelName,'Продажа и аренда');assert.equal(first.type,'individual');
  assert.equal(second.funnel,'42');assert.equal(second.funnelName,'Воронка №42');assert.equal(second.type,'company');
  assert.equal((await crm.searchDeals('Тест',context)).length,1);
  for(const deal of f.deals.values()) deal[f.dealKeys.date]='2026-11-25';
  assert.equal((await crm.calendar('2026-11-25',null,context)).deals.length,1);
  assert.ok(requests.filter(path=>path.startsWith('/deals?')).every(path=>!new URL(path,'https://test.invalid').searchParams.has('filter[categoryId]')));
  f.deals.get('101').categoryId=500;
  assert.equal((await crm.getDeal('101',context)).funnel,'500');
  const denied=createCrm(async(path,options)=>{if(path==='/deals/101')throw Object.assign(new Error('Нет доступа'),{status:403});return f.call(path,options);});
  await assert.rejects(denied.getDeal('101',context),{status:403});
});
test('воронки выбираются по четырём ID из полного постраничного справочника',async()=>{
  const f=fixture(),offsets=[];
  const crm=createCrm(async(path,options)=>{
    if(path.startsWith('/categories/2?')) {
      const offset=Number(new URL(path,'https://test.invalid').searchParams.get('offset'));offsets.push(offset);
      return {data:Array.from({length:offset===0?50:1},(_,i)=>({id:offset+i,name:`Воронка ${offset+i}`}))};
    }
    return f.call(path,options);
  });
  assert.deepEqual((await crm.metadata(context)).funnels.map(f=>f.id),['6','0','8','10']);assert.deepEqual(offsets,[0,50]);
});
test('справочники сохраняются между запусками, изолированы по сотруднику и обновляются явно',async t=>{
  const f=await setup(t),referenceStore=createStore(join(f.directory,'references'));let calls=0,renamed=false,fail=false;
  const call=async(path,options)=>{
    calls++;if(fail)throw new Error('Недоступно');
    if(path.startsWith('/categories/2?'))return {data:[{id:0,name:renamed?'Новое имя':'Старое имя'},{id:88,name:'Не показывать'}]};
    if(path.startsWith('/lists/55/elements') && renamed)return {data:[{ID:'98',NAME:'Новый маршрут'}]};
    return f.call(path,options);
  };
  const first=createCrm(call,{referenceStore});await first.metadata(context);const count=calls;
  renamed=true;
  const restarted=createCrm(call,{referenceStore});
  assert.equal((await restarted.metadata({...context,authorization:'Bearer vibe_session_rotated'})).funnels[0].name,'Старое имя');assert.equal(calls,count);
  const updated=await restarted.metadata(context,{refresh:true});assert.equal(updated.funnels[0].name,'Новое имя');assert.deepEqual(updated.routes.map(r=>r.id),['98']);
  const afterRefresh=calls;await restarted.metadata({...context,id:'9'});assert.ok(calls>afterRefresh);
  fail=true;await assert.rejects(restarted.metadata(context,{refresh:true}));
  assert.equal((await restarted.metadata(context)).funnels[0].name,'Новое имя');assert.equal(f.writes.length,0);
});
test('поиск без текста даёт 10 активных, по названию читает все страницы четырёх воронок',async()=>{
  const f=fixture(),template=f.deals.get('101');f.deals.clear();
  for(let i=1;i<=75;i++)f.deals.set(String(100+i),{...template,id:String(100+i),categoryId:[6,0,8,10,99][i%5],title:'Поиск '+i,closed:i%3===0});
  const initial=await f.crm.searchDeals('',context);assert.equal(initial.length,10);
  assert.ok(initial.every(d=>['6','0','8','10'].includes(d.funnel) && f.deals.get(d.id).closed===false));
  const found=await f.crm.searchDeals('Поиск',context);assert.equal(found.length,60);assert.equal(new Set(found.map(d=>d.id)).size,60);
  assert.ok(found.some(d=>f.deals.get(d.id).closed));assert.equal((await f.crm.searchDeals('104',context)).length,0);
  assert.equal((await f.crm.searchDeals('101',context))[0].id,'101');
});
test('весь список сотрудников листается и кэшируется, поиск по ФИО выполняется без новых CRM-запросов',async()=>{
  const f=fixture();let calls=0;
  const crm=createCrm(async(path,options)=>{
    if(path.startsWith('/users?')){
      calls++;const params=new URL(path,'https://test.invalid').searchParams;assert.equal(params.get('filter[active]'),'true');
      const offset=Number(params.get('offset'));return {data:Array.from({length:offset===0?50:2},(_,i)=>({id:offset+i+1,name:'Иван',lastName:offset+i===50?'Петров':'Сотрудник '+(offset+i),active:offset+i!==51})),meta:{hasMore:offset===0}};
    }
    return f.call(path,options);
  });
  assert.equal((await crm.employees('',context)).length,51);
  assert.deepEqual((await crm.employees('петров иВан',context)).map(p=>p.id),['51']);assert.equal(calls,2);
  await crm.employeeDirectory(context,{refresh:true});assert.equal(calls,4);
});
test('маршруты используют подтверждённый тип инфоблока, без предположения lists и серверного фильтра',async()=>{
  const f=fixture(),requests=[];
  const crm=createCrm(async(path,options)=>{
    requests.push(path);
    if (path==='/lists/55/type') return {data:{iblockTypeId:'bitrix_processes'}};
    if (path.startsWith('/lists/55/elements')) {
      const query=new URL(path,'https://test.invalid').searchParams;
      assert.equal(query.get('iblockTypeId'),'bitrix_processes');assert.equal(query.has('filter'),false);
      if(query.get('start')==='0') return {data:[{ID:'81',NAME:'Рабочий маршрут',ACTIVE:'Y'},{ID:'88',NAME:'Неактивный',ACTIVE:'N'}],meta:{next:50}};
      return {data:[{ID:'82',NAME:'Второй маршрут'}],meta:{}};
    }
    return f.call(path,options);
  },{routeIblockType:'lists'});
  const meta=await crm.metadata(context);
  assert.deepEqual(meta.routes.map(r=>r.id),['81','82']);
  assert.equal(requests.filter(p=>p==='/lists/55/type').length,1);
  assert.ok(requests.indexOf('/lists/55/type')<requests.findIndex(p=>p.startsWith('/lists/55/elements')));
});
test('неизвестный тип или отказ определения типа не вызывают перебор справочников',async()=>{
  const f=fixture();
  for (const result of [{data:{iblockTypeId:'catalog'}},null]) {
    const requests=[];
    const crm=createCrm(async(path,options)=>{requests.push(path);if(path==='/lists/55/type'){if(result)return result;throw Object.assign(new Error('denied'),{code:'SCOPE_DENIED'});}return f.call(path,options);});
    await assert.rejects(crm.metadata(context),{code:result?'ROUTE_LIST_NOT_CONFIGURED':'SCOPE_DENIED'});
    assert.equal(requests.some(p=>p.includes('/elements')),false);
  }
});
test('ошибки ключа, несовпадения приложения и сессии различаются без утечки ответа API',async()=>{
  for (const [status,code,expected] of [[401,'INVALID_API_KEY',/не принимает ключ/],[401,'KEY_EXPIRED',/не принимает ключ/],[403,'SESSION_APP_MISMATCH',/другому OAuth-приложению/],[401,'INVALID_SESSION',/заново войти или выдать/],[401,'TOKEN_MISSING',/При первом входе/]]) {
    const call=createVibeClient({appKey:'vibe_app_test',fetchImpl:async()=>new Response(JSON.stringify({success:false,error:{code,message:'private remote response',details:{secret:'private'}}}),{status})});
    await assert.rejects(call('/me',{authorization:context.authorization}),error=>{
      assert.equal(error.code,code);assert.match(error.message,expected);assert.match(error.message,/проверка ключа и сессии/);
      assert.equal(error.message.includes('private'),false);assert.equal(error.message.includes('vibe_session_'),false);return true;
    });
  }
});
test('отказ начальной загрузки сообщает этап и код Битрикс24 без текста CRM и параметров',async()=>{
  const call=createVibeClient({appKey:'vibe_app_test',fetchImpl:async()=>new Response(JSON.stringify({success:false,error:{code:'BITRIX_ERROR',b24Code:'ACCESS_DENIED_EXTEND',cause:'b24_permission',message:'private client address',details:{token:'private token'}}}),{status:422})});
  for (const [path,label] of [['/deals/fields','загрузка полей сделок'],['/contacts/fields','загрузка полей контактов'],['/userfields/contacts','загрузка настроек пользовательских полей контакта'],['/categories/2?offset=0','загрузка воронок сделок'],['/lists/123/elements?filter=private-search','загрузка справочника маршрутов']]) {
    await assert.rejects(call(path,{authorization:context.authorization}),error=>{
      assert.equal(error.status,422);assert.equal(error.code,'BITRIX_ERROR');assert.equal(error.message.includes(label),true);
      assert.match(error.message,/ACCESS_DENIED_EXTEND/);assert.match(error.message,/прав сотрудника/);
      assert.equal(error.message.includes('private'),false);assert.equal(error.message.includes('123'),false);return true;
    });
  }
});
test('произвольные подробности в коде причины Битрикс24 не отображаются',async()=>{
  const call=createVibeClient({appKey:'vibe_app_test',fetchImpl:async()=>new Response(JSON.stringify({success:false,error:{code:'BITRIX_ERROR',b24Code:'vibe_session_private',cause:'private explanation',message:'private description'}}),{status:422})});
  await assert.rejects(call('/contacts/fields',{authorization:context.authorization}),error=>!error.message.includes('private')&&error.message.includes('загрузка полей контактов'));
});
test('сетевой отказ сообщает этап и безопасную причину, не превращая запись в повтор',async()=>{
  for (const [failure,expected] of [[Object.assign(new Error('private'),{name:'TimeoutError'}),'TIMEOUT'],[Object.assign(new Error('private'),{cause:{code:'ENOTFOUND',message:'private'}}),'DNS'],[Object.assign(new Error('private'),{cause:{code:'CERT_HAS_EXPIRED'}}),'TLS']]) {
    const call=createVibeClient({appKey:'vibe_app_test',fetchImpl:async()=>{throw failure;}});
    await assert.rejects(call('/lists/55/type',{authorization:context.authorization}),error=>error.code==='VIBE_UNAVAILABLE'&&error.message.includes(expected)&&error.message.includes('определение типа инфоблока маршрутов')&&!error.message.includes('private'));
    await assert.rejects(call('/deals/101',{authorization:context.authorization,method:'PATCH',body:{}}),{code:'CRM_WRITE_UNCERTAIN'});
  }
});
test('сохранение обновляет только согласованные поля контакта и сделки и проверяет чтением',async t=>{
  const f=await setup(t),request=await input(f),result=await f.service.save(request,context);
  assert.equal(result.booking.syncState,'synced');assert.equal(result.booking.masterId,'m1');
  assert.equal(result.deal.address,'Новый адрес');assert.equal(result.deal.years,'4');
  assert.equal(result.deal.windowStart,'09:00');assert.equal(result.deal.comment,request.draft.comment);
  assert.deepEqual(Object.keys(f.writes[0].body).sort(),Object.values(f.contactKeys).sort());
  assert.deepEqual(Object.keys(f.writes[1].body).sort(),Object.values(f.dealKeys).sort());
  assert.equal(f.writes.some(w=>/products|wialon/i.test(w.path)),false);
  assert.equal(f.writes.some(w=>'stageId' in w.body),false);
  await f.service.save(request,context);assert.equal(f.writes.length,2,'повтор операции не повторяет PATCH');
  const persisted=await readFile(join(f.directory,'planning.json'),'utf8');
  assert.equal(persisted.includes('vibe_session_'),false);assert.equal(persisted.includes('vibe_app_'),false);
});
test('изменение CRM после открытия формы не затирается',async t=>{
  const f=await setup(t),request=await input(f);
  f.contacts.get('201')[f.contactKeys.address]='Адрес изменён другим менеджером';
  await assert.rejects(f.service.save(request,context),{code:'CRM_CHANGED'});assert.equal(f.writes.length,0);
});
test('два одновременных сохранения не занимают последнее окно дважды',async t=>{
  const f=await setup(t),a=await input(f),b=await input(f,'102');
  const results=await Promise.allSettled([f.service.save(a,context),f.service.save(b,context)]);
  assert.equal(results.filter(r=>r.status==='fulfilled').length,1);
  assert.equal(results.find(r=>r.status==='rejected').reason.code,'NO_CAPACITY');
});
test('частичная запись сохраняет резерв; проверка результата не повторяет запись',async t=>{
  const f=await setup(t),request=await input(f);f.setFailure(true);
  await assert.rejects(f.service.save(request,context),{code:'CRM_SYNC_UNCERTAIN'});
  assert.equal((await f.store.read()).bookings[0].syncState,'pending');
  const count=f.writes.length;
  await assert.rejects(f.service.reconcile(request,context),{code:'CRM_RECONCILIATION_REQUIRED'});assert.equal(f.writes.length,count);
  assert.equal((await f.service.bootstrap(context)).pendingOperations[0].operationId,request.operationId);
  assert.equal((await f.service.pending(request.operationId,context)).draft.comment,request.draft.comment);
  await assert.rejects(f.service.pending(request.operationId,{...context,id:'9'}),{code:'OPERATION_NOT_FOUND'});
});
test('потерянный ответ после записи подтверждается чтением без дубликата',async t=>{
  const f=await setup(t),request=await input(f);f.setLostReply(true);
  await assert.rejects(f.service.save(request,context),{code:'CRM_SYNC_UNCERTAIN'});
  const result=await f.service.reconcile(request,context);
  assert.equal(result.booking.syncState,'synced');assert.equal(f.writes.length,2);
});
test('отмена очищает дату и окно сделки, сохраняет контакт, услуги и комментарий',async t=>{
  const f=await setup(t);await f.service.save(await input(f),context);
  const request=await input(f);request.cancel=true;
  const result=await f.service.save(request,context);
  assert.equal(result.booking.status,'cancelled');assert.equal(result.deal.date,'');
  assert.equal(f.writes.length,3);assert.equal(f.writes.at(-1).path,'/deals/101');
  assert.deepEqual(Object.keys(f.writes.at(-1).body).sort(),['date','windowStart','windowEnd'].map(p=>f.dealKeys[p]).sort());
});
test('мастер проверяется по сотруднику CRM, сохраняет направления и доступ ограничен',async t=>{
  const f=await setup(t),state=await f.store.read();
  await assert.rejects(f.service.settings({revision:state.revision,masters:state.masters,groups:state.groups},{...context,isAdmin:false}),{code:'MANAGER_REQUIRED'});
  const result=await f.service.settings({revision:state.revision,masters:[{...state.masters[0],name:'Поддельное имя'}],groups:state.groups},context);
  assert.equal(result.masters[0].name,'Сотрудник Иван');assert.deepEqual(result.masters[0].directions,['81']);assert.equal(f.writes.length,0);
});
test('API защищает запись от другого сайта, не отдаёт токены и приватное хранилище',async t=>{
  const f=await setup(t),server=createServer(createApp({mode:'bitrix',portal:context.portal,dataDir:f.directory,appUrl:'https://test.invalid'},{call:f.call}));
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>{server.close(resolve);server.closeAllConnections();}));
  const base=`http://127.0.0.1:${server.address().port}`,headers={'x-vibe-authorization':context.authorization};
  const runtime=await fetch(base+'/api/runtime');
  assert.equal(runtime.status,200);assert.equal((await runtime.json()).version,version);
  const bootstrap=await fetch(base+'/api/bootstrap',{headers});assert.equal(bootstrap.status,200);
  assert.equal((await bootstrap.text()).includes('vibe_session_'),false);
  assert.equal((await fetch(base+'/api/settings',{method:'POST',headers:{...headers,'Content-Type':'application/json',Origin:'https://other.invalid'},body:'{}'})).status,403);
  assert.equal((await fetch(base+'/var/planning/planning.json',{headers})).status,404);
});
test('фоновое чтение отвечает до авторизации, результат закрыт до проверки и изолирован по сессии',async t=>{
  const f=await setup(t);let release,authCalls=0,authOptions;
  const gate=new Promise(resolve=>{release=resolve;});
  const server=createServer(createApp({mode:'bitrix',portal:context.portal,dataDir:f.directory,appUrl:'https://test.invalid'},{call:f.call,authenticate:async(req,options)=>{authCalls++;authOptions=options;await gate;return context;}}));
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>{release();server.close(resolve);server.closeAllConnections();}));
  const base=`http://127.0.0.1:${server.address().port}`,headers={'x-vibe-authorization':context.authorization,'x-planner-async':'1'};
  const initial=await fetch(base+'/api/bootstrap',{headers,signal:AbortSignal.timeout(2000)});
  assert.equal(initial.status,202);const {jobId}=await initial.json();
  const poll=()=>fetch(base+'/api/read-jobs/'+jobId,{headers});
  assert.equal((await poll()).status,202);
  assert.equal((await (await fetch(base+'/api/bootstrap',{headers})).json()).jobId,jobId);assert.equal(authCalls,1);
  assert.equal((await fetch(base+'/api/read-jobs/'+jobId,{headers:{'x-vibe-authorization':'Bearer vibe_session_other'}})).status,404);
  assert.equal((await fetch(base+'/api/read-jobs/'+jobId)).status,401);
  assert.equal(authOptions.timeoutMs,150000);assert.ok(authOptions.signal instanceof AbortSignal);
  release();
  let result;
  for(let i=0;i<30;i++){result=await poll();if(result.status!==202)break;await new Promise(resolve=>setTimeout(resolve,10));}
  assert.equal(result.status,200);const text=await result.text();assert.ok(text.includes('funnelOptions'));assert.equal(text.includes('vibe_session_'),false);assert.equal(authCalls,1);assert.equal(f.writes.length,0);
});
test('фоновое сохранение быстро подтверждает приём, защищено от CSRF и опрос не повторяет PATCH',async t=>{
  const f=await setup(t),request=await input(f);let release;const gate=new Promise(resolve=>{release=resolve;});
  const call=async(path,options)=>{if(path==='/contacts/201'&&options?.method==='PATCH')await gate;return f.call(path,options);};
  const server=createServer(createApp({mode:'bitrix',portal:context.portal,dataDir:f.directory,appUrl:'https://test.invalid'},{call,store:f.store}));
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));t.after(()=>new Promise(resolve=>{release();server.close(resolve);server.closeAllConnections();}));
  const base=`http://127.0.0.1:${server.address().port}`,headers={'x-vibe-authorization':context.authorization,'X-Planner-Async':'1','Content-Type':'application/json','X-Requested-With':'yuktari-planner',Origin:'https://test.invalid'};
  const post=()=>fetch(base+'/api/bookings',{method:'POST',headers,body:JSON.stringify(request),signal:AbortSignal.timeout(2000)});
  assert.equal((await fetch(base+'/api/bookings',{method:'POST',headers:{...headers,Origin:'https://other.invalid'},body:JSON.stringify(request)})).status,403);
  const accepted=await post();assert.equal(accepted.status,202);const {jobId}=await accepted.json();
  assert.equal((await (await post()).json()).jobId,jobId);
  const poll=()=>fetch(base+'/api/read-jobs/'+jobId,{headers:{'x-vibe-authorization':context.authorization}});
  assert.equal((await poll()).status,202);release();let result;
  for(let i=0;i<60;i++){result=await poll();if(result.status!==202)break;await new Promise(resolve=>setTimeout(resolve,10));}
  assert.equal(result.status,200);assert.equal((await result.json()).data.booking.syncState,'synced');
  await poll();assert.equal(f.writes.length,2);
});
