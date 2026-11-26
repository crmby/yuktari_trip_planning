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
  for (const [path,label] of [['/deals/fields','загрузка полей сделок'],['/contacts/fields','загрузка полей контактов'],['/userfields/contacts','загрузка настроек пользовательских полей контакта'],['/deal-categories','загрузка воронок сделок'],['/lists/123/elements?filter=private-search','загрузка справочника маршрутов']]) {
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
