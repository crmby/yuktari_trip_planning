import { createHash, randomUUID } from 'node:crypto';
import { AppError } from './errors.js';

// Only reads run here. A result is published only after the normal authorization
// and CRM permission checks; polling cannot start a CRM request or write.
export function createReadJobs({ now = Date.now } = {}) {
  const jobs = new Map();
  function owner(req) {
    const authorization = req.headers['x-vibe-authorization'];
    if (req.headers['x-vibe-caller-kind'] === 'external-api' || !/^Bearer vibe_session_[A-Za-z0-9_-]+$/.test(authorization || '')) throw new AppError(401,'AUTH_REQUIRED','Откройте приложение из Битрикс24.');
    return createHash('sha256').update(authorization).digest('hex');
  }
  function clean() { for (const [id,job] of jobs) if (job.expires <= now()) { job.controller.abort(); jobs.delete(id); } }
  function start(req, key, execute) {
    clean(); const session = owner(req);
    const own = [...jobs.values()].filter(job => job.owner === session);
    const previous = own.find(job => job.key === key && !job.result);
    if (previous) return {status:202,body:{success:true,jobId:previous.id}};
    if (jobs.size >= 100 || own.filter(job => !job.result).length >= 4) throw new AppError(429,'READ_BUSY','Дождитесь текущей загрузки данных.');
    const id = randomUUID(), controller = new AbortController();
    const job = {id,owner:session,key,controller,expires:now()+600_000,result:null}; jobs.set(id,job);
    // Do not wait for VibeCode before acknowledging the job to the gateway.
    void Promise.resolve().then(() => execute({timeoutMs:150_000,signal:AbortSignal.any([controller.signal,AbortSignal.timeout(600_000)])})).then(result => {
      job.result = result; job.expires = now()+60_000;
    }).catch(() => { job.result = {status:500,body:{success:false,error:{code:'INTERNAL_ERROR',message:'Не удалось загрузить данные.'}}}; job.expires=now()+60_000; });
    return {status:202,body:{success:true,jobId:id}};
  }
  function poll(req, id) {
    clean(); const session=owner(req),job=jobs.get(id);
    if (!job || job.owner !== session) throw new AppError(404,'READ_JOB_NOT_FOUND','Загрузка не найдена или истекла. Нажмите «Повторить загрузку».');
    return job.result || {status:202,body:{success:true,jobId:id}};
  }
  return {start,poll};
}
