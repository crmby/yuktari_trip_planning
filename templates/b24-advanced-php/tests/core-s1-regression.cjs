'use strict';
const {spawn,spawnSync}=require('child_process');
const fs=require('fs'),os=require('os'),path=require('path');
const projectRoot=path.resolve(process.argv[2]||path.join(__dirname,'..'));
const kind='current';
const sourceApi=path.join(projectRoot,'www/api');
const sourceEnv=path.join(projectRoot,'www/env.php');
const codeRoot=fs.mkdtempSync(path.join(os.tmpdir(),'kg-review-s1-code-'));
const copiedApi=path.join(codeRoot,'www/api');fs.mkdirSync(copiedApi,{recursive:true});
// Копируются только PHP-исходники. Рабочие данные, credentials и токены не читаются.
for(const name of fs.readdirSync(sourceApi).filter(n=>n.endsWith('.php'))){
 const source=path.join(sourceApi,name);
 if(!fs.lstatSync(source).isFile())throw Error('Expected regular source file: '+name);
 fs.copyFileSync(source,path.join(copiedApi,name));
}
fs.copyFileSync(sourceEnv,path.join(codeRoot,'www/env.php'));
console.log(JSON.stringify({kind,sourceProject:projectRoot,sourceOnlyCodeFixture:codeRoot}));
function fixture(kind){
 const data=fs.mkdtempSync(path.join(os.tmpdir(),'kg-review-s1-test-'));
 const env={...process.env,BXAPP_DATA_ROOT:data,BXAPP_CLIENT_ID:'local.review',BXAPP_CLIENT_SECRET:'test-only'};
 const core=path.join(codeRoot,'www/api/b24.php');
 const boot=`require ${JSON.stringify(path.join(codeRoot,'www/env.php'))};require ${JSON.stringify(core)};`;
 return {data,env,boot};
}
function php(f,code){
 const r=spawnSync('php',['-r',f.boot+code],{env:f.env,encoding:'utf8'});
 if(r.status!==0)throw Error(`PHP exit ${r.status}: ${r.stderr}`);
 return r.stdout;
}
function calls(output){return output.split('OAUTH_CALL').length-1;}
const transport='$GLOBALS["__b24_transport"]=function($what,$params){echo "OAUTH_CALL\\n";return ["error"=>"invalid_grant"];};';
const verify='$b=new B24("local.review","test-only");$b->verifyAndAdopt(["REFRESH_ID"=>"invalid-test-only"],true);';
async function concurrent(kind){
 const f=fixture(kind);php(f,'storeWrite(STATE_FILE,[]);');
 const h=spawn('php',['-r',f.boot+'$h=fopen(STATE_FILE.".lock.php","c+b");flock($h,LOCK_EX);echo "HELD\\n";fflush(STDOUT);fgets(STDIN);flock($h,LOCK_UN);'],{env:f.env,stdio:['pipe','pipe','pipe']});
 await new Promise(resolve=>h.stdout.once('data',resolve));
 const workers=[];
 for(let i=0;i<6;i++){
  const p=spawn('php',['-r',f.boot+transport+'echo "STARTED\\n";fflush(STDOUT);'+verify],{env:f.env});
  let out='',err='';p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);
  workers.push(new Promise((resolve,reject)=>p.on('close',rc=>rc===0?resolve(out):reject(Error(err)))));
 }
 await new Promise(resolve=>setTimeout(resolve,500));h.stdin.end('release\n');
 const output=await Promise.all(workers);
 const count=output.reduce((a,b)=>a+calls(b),0);
 const fails=Number(php(f,'echo (storeRead(STATE_FILE)["migrationVerifyFails"]??0);'));
 return {scenario:'concurrent-six',kind,oauthCalls:count,fails,passed:count===3&&fails===3};
}
function sequential(kind){
 const f=fixture(kind);php(f,'storeWrite(STATE_FILE,[]);');
 const out=php(f,transport+'for($i=0;$i<5;$i++){'+verify+'}echo "FAILS=".(storeRead(STATE_FILE)["migrationVerifyFails"]??0);');
 return {scenario:'sequential-three-then-denied',kind,oauthCalls:calls(out),passed:calls(out)===3&&out.includes('FAILS=3')};
}
function expired(kind){
 const f=fixture(kind);
 const out=php(f,'storeWrite(STATE_FILE,["migrationVerifyFails"=>3,"migrationVerifyAt"=>time()-6]);'+transport+verify+verify);
 return {scenario:'expired-window-one-attempt-then-denied',kind,oauthCalls:calls(out),passed:calls(out)===1};
}
function successfulReset(kind){
 const f=fixture(kind);
 const out=php(f,'storeWrite(STATE_FILE,["migrationVerifyFails"=>3,"migrationVerifyAt"=>time()-6]);'+
 '$GLOBALS["__b24_transport"]=function($what,$params){echo "OAUTH_CALL\\n";return ["access_token"=>"fake-access","refresh_token"=>"fake-refresh","member_id"=>"fake-member","client_endpoint"=>"https://portal.invalid/rest/","expires_in"=>3600];};'+
 '$GLOBALS["__b24_portal_get"]=function($domain,$method,$auth){return ["result"=>$method==="user.current"?["ID"=>1,"UF_DEPARTMENT"=>[]]:true];};'+
 '$b=new B24("local.review","test-only");echo "ADOPT=".($b->verifyAndAdopt(["REFRESH_ID"=>"invalid-test-only"],true)?"YES":"NO");$s=storeRead(STATE_FILE);echo "CLEARED=".(!array_key_exists("migrationVerifyFails",$s)&&!array_key_exists("migrationVerifyAt",$s)?"YES":"NO");');
 return {scenario:'verified-success-clears-counter',kind,oauthCalls:calls(out),passed:calls(out)===1&&out.includes('ADOPT=YES')&&out.includes('CLEARED=YES')};
}
function staleGeneration(kind){
 const f=fixture(kind);
 const out=php(f,'storeWrite(STATE_FILE,[]);'+transport+'$b=new B24("local.review","test-only");storeWrite(B24_TOKENS_FILE,["access_token"=>"fake-access","refresh_token"=>"fake-refresh","member_id"=>"fake-member","domain"=>"portal.invalid","client_id"=>"local.review","install_generation"=>"new-generation"]);$b->verifyAndAdopt(["REFRESH_ID"=>"invalid-test-only"],true);');
 return {scenario:'changed-generation-no-network',kind,oauthCalls:calls(out),passed:calls(out)===0};
}
(async()=>{
 const rows=[await concurrent(kind),sequential(kind),expired(kind),successfulReset(kind),staleGeneration(kind)];
 rows.forEach(row=>console.log(JSON.stringify(row)));
 const fail=rows.some(r=>!r.passed);console.log(fail?'CORE_S1_CONCURRENT_LIMIT_BROKEN':'CORE_S1_ALL_CHECKS_PASS');process.exitCode=fail?1:0;
})().catch(e=>{console.error(e.message);process.exitCode=2;});
