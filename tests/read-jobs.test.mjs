import test from 'node:test';
import assert from 'node:assert/strict';
import { createReadJobs } from '../src/bitrix/read-jobs.js';

test('фоновые чтения ограничены, истекают и не выдают результат другой сессии',async()=>{
  let now=0,release;
  const jobs=createReadJobs({now:()=>now}),req={headers:{'x-vibe-authorization':'Bearer vibe_session_test'}};
  assert.throws(()=>jobs.start({...req,method:'POST'},'/bookings',()=>Promise.resolve()),{code:'READ_ONLY_JOB'});
  const gate=new Promise(resolve=>{release=resolve;});
  const job=jobs.start(req,'/bootstrap',async()=>{await gate;return {status:401,body:{success:false,error:{code:'AUTH_REQUIRED'}}};});
  for(let i=0;i<3;i++)jobs.start(req,'/read'+i,()=>gate);
  assert.throws(()=>jobs.start(req,'/too-many',()=>gate),{code:'READ_BUSY'});
  assert.throws(()=>jobs.poll({headers:{'x-vibe-authorization':'Bearer vibe_session_other'}},job.body.jobId),{code:'READ_JOB_NOT_FOUND'});
  release();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(jobs.poll(req,job.body.jobId).status,401);
  now=60001;assert.throws(()=>jobs.poll(req,job.body.jobId),{code:'READ_JOB_NOT_FOUND'});
  assert.throws(()=>jobs.start({headers:{'x-vibe-user-id':'7'}},'/bootstrap',()=>gate),{code:'AUTH_REQUIRED'});
});
