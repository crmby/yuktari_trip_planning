'use strict';
const fs=require('fs'),os=require('os'),path=require('path');
const {spawnSync}=require('child_process');
const projectRoot=path.resolve(process.argv[2]||path.join(__dirname,'..'));
const kind='current';
const codeRoot=fs.mkdtempSync(path.join(os.tmpdir(),'kg-review-s2-code-'));
const copiedApi=path.join(codeRoot,'www/api');fs.mkdirSync(copiedApi,{recursive:true});
// Только PHP-исходники; рабочий data/, credentials и токены не читаются.
for(const name of fs.readdirSync(path.join(projectRoot,'www/api')).filter(n=>n.endsWith('.php'))){
 const src=path.join(projectRoot,'www/api',name);
 if(!fs.lstatSync(src).isFile())throw Error('Expected regular source file: '+name);
 fs.copyFileSync(src,path.join(copiedApi,name));
}
// Сценарий пустого списка событий не зависит от настроек конкретного проекта.
const sourceEnv=fs.readFileSync(path.join(projectRoot,'www/env.php'),'utf8');
const fixtureEnv=sourceEnv.replace(/define\('APP_EVENTS', \[[\s\S]*?\n\]\);/, "define('APP_EVENTS', []);");
if(fixtureEnv===sourceEnv&&!sourceEnv.includes("define('APP_EVENTS', []);"))throw Error('APP_EVENTS fixture declaration not found');
fs.writeFileSync(path.join(codeRoot,'www/env.php'),fixtureEnv);
const lint=spawnSync('php',['-l',path.join(copiedApi,'events-bind.php')],{encoding:'utf8'});
if(lint.status!==0)throw Error(lint.stdout+lint.stderr);
console.log(JSON.stringify({kind,sourceProject:projectRoot,sourceOnlyCodeFixture:codeRoot}));
function scenario(name,fake,expectedStatus,expectedCleanup,expectedVerified){
 const data=fs.mkdtempSync(path.join(os.tmpdir(),'kg-review-s2-data-'));
 const env={...process.env,BXAPP_DATA_ROOT:data,BXAPP_CLIENT_ID:'local.review',BXAPP_CLIENT_SECRET:'test-only'};
 const boot=`require ${JSON.stringify(path.join(codeRoot,'www/env.php'))};require ${JSON.stringify(path.join(copiedApi,'events-bind.php'))};`;
 const code=boot+'storeWrite(B24_TOKENS_FILE,["access_token"=>"fake-access","expires_at"=>time()+3600,"domain"=>"portal.invalid","member_id"=>"fake-member","client_id"=>"local.review","install_generation"=>"fake-generation","installed"=>true]);if(eventsProjectSpec()!==[]){fwrite(STDERR,"This fixture needs empty APP_EVENTS");exit(2);}$calls=0;'+
 '$GLOBALS["__b24_call"]=function($method,$params)use(&$calls){$calls++;if($method!=="event.get")throw new RuntimeException("unexpected fake method");'+fake+'};'+
 '$r=eventsHealth(new B24("local.review","test-only"));echo json_encode(["status"=>$r["status"],"cleanup_bound"=>$r["cleanup_bound"],"declared"=>$r["declared"],"missing"=>$r["missing"],"verified"=>$r["status"]!=="unknown","calls"=>$calls]);';
 const r=spawnSync('php',['-r',code],{env,encoding:'utf8'});
 if(r.status!==0)throw Error('PHP exit '+r.status+': '+r.stderr);
 const actual=JSON.parse(r.stdout);
 if(actual.calls!==1)throw Error("Fixture transport not reached: "+name);
 const passed=actual.status===expectedStatus&&actual.cleanup_bound===expectedCleanup&&actual.verified===expectedVerified&&actual.declared===0&&actual.missing.length===0&&actual.calls===1;
 return {scenario:name,kind,...actual,passed};
}
try{
 const rows=[
  scenario('event-list-rejected','return ["error"=>"insufficient_scope"];','unknown',null,false),
  scenario('event-list-throws','throw new RuntimeException("fake offline");','unknown',null,false),
  scenario('known-only-cleanup','return ["result"=>[["event"=>"ONAPPUNINSTALL","handler"=>eventsHandlerUrl(APP_URL)]]];','off',true,true),
  scenario('known-empty-list','return ["result"=>[]];','broken',false,true),
 ];
 rows.forEach(row=>console.log(JSON.stringify(row)));
 const fail=rows.some(row=>!row.passed);
 console.log(fail?'CORE_S2_UNKNOWN_EVENT_LIST_RC1':'CORE_S2_ALL_CHECKS_PASS');process.exitCode=fail?1:0;
}catch(e){console.error(e.message);process.exitCode=2;}
