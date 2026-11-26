import test from 'node:test';
import assert from 'node:assert/strict';
import { inspectApp, describeReport } from '../scripts/check-app.mjs';
const env={VIBE_APP_KEY:'vibe_app_test',VIBE_API_KEY:'vibe_api_test',B24_DOMAIN:'example.bitrix24.by',APP_URL:'https://app-test.vibecode.bitrix24.tech',SERVER_ID:'server-test'};
function fixture(overrides={}) {
  const calls=[];
  return {calls,fetch:async(url,options)=>{
    calls.push({url,options});assert.equal(options.method,'GET');assert.equal(options.redirect,'error');
    assert.equal(url.startsWith('https://vibecode.bitrix24.tech/v1/'),true);
    const path=new URL(url).pathname;
    const data=path==='/v1/me'?{type:'oauth_app',app:{id:'oauth-test'},portal:env.B24_DOMAIN,scopes:['crm','user','lists','placement'],...overrides.me}:
      path==='/v1/apps/oauth-test'?{id:'oauth-test',appUrl:env.APP_URL,...overrides.app}:{id:env.SERVER_ID,appUrl:env.APP_URL,...overrides.server};
    return new Response(JSON.stringify({success:true,data}));
  }};
}
test('проверка принадлежности использует только чтение и не подтверждает ключ runtime',async()=>{
  const f=fixture(),report=await inspectApp(env,f.fetch);
  assert.equal(report.verdict,'MATCH');assert.equal(report.runtimeKeyChecked,false);
  assert.equal(f.calls[0].options.headers['X-Api-Key'],env.VIBE_APP_KEY);
  assert.equal(f.calls[1].options.headers['X-Api-Key'],env.VIBE_API_KEY);
  const output=describeReport(report).join('\n');assert.equal(output.includes(env.VIBE_APP_KEY),false);assert.equal(output.includes(env.VIBE_API_KEY),false);
});
test('другой портал и адрес не считаются подтверждением',async()=>{
  const foreign=fixture({me:{portal:'foreign.bitrix24.by'}});assert.equal((await inspectApp(env,foreign.fetch)).verdict,'MISMATCH');assert.equal(foreign.calls.length,1);
  const other=fixture({app:{appUrl:'https://other.invalid'}});assert.equal((await inspectApp(env,other.fetch)).verdict,'MISMATCH');
});
test('без доступа к карточке результат остаётся неполным',async()=>{
  const f=fixture(),report=await inspectApp({...env,VIBE_API_KEY:''},f.fetch);
  assert.equal(report.verdict,'INCOMPLETE');assert.equal(f.calls.length,1);
  const denied=await inspectApp(env,async(url,options)=>url.endsWith('/me')?f.fetch(url,options):new Response(JSON.stringify({success:false,error:{code:'SCOPE_DENIED',message:env.VIBE_API_KEY}}),{status:403}));
  assert.equal(denied.verdict,'INCOMPLETE');assert.equal(denied.oauthAppId,'oauth-test');assert.match(describeReport(denied).join('\n'),/SCOPE_DENIED/);
});
test('ошибка API и сеть не раскрывают исходный ответ или credentials',async()=>{
  await assert.rejects(inspectApp(env,async()=>new Response(JSON.stringify({success:false,error:{code:'INVALID_API_KEY',message:env.VIBE_APP_KEY}}),{status:401})),error=>error.message.includes('INVALID_API_KEY')&&!error.message.includes(env.VIBE_APP_KEY));
  await assert.rejects(inspectApp(env,async()=>{throw new Error(env.VIBE_API_KEY);}),error=>!error.message.includes(env.VIBE_API_KEY));
});
