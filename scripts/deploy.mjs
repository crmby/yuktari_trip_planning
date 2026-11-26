import { mkdtemp, readdir, lstat, rm, copyFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { loadLocalEnv, projectRoot } from '../src/config.js';
import { needsCatalogChoice, catalogSummary, deploymentArguments, selectDeploymentKey } from './deploy-options.mjs';
import { createInterface } from 'node:readline/promises';

const entries = ['src', 'public', 'package.json', 'package-lock.json'];
const baseUrl = 'https://vibecode.bitrix24.tech/v1';
let temporary;

async function review(path) {
  const stat = await lstat(path);
  if (stat.isSymbolicLink()) throw new Error('Архив не должен содержать символические ссылки.');
  if (stat.isDirectory()) {
    for (const name of await readdir(path)) {
      if (name.startsWith('.') || /\.(env|pem|key|log)$/i.test(name) || ['node_modules', 'data', 'var'].includes(name)) {
        throw new Error('В рабочем каталоге обнаружен файл, не предназначенный для публикации.');
      }
      await review(join(path, name));
    }
  } else if (!stat.isFile()) throw new Error('В архив можно включать только обычные файлы.');
}

function tar(args) {
  const result = spawnSync(process.platform === 'win32' ? 'tar.exe' : 'tar', args, { cwd: projectRoot, encoding: 'utf8' });
  if (result.error || result.status !== 0) throw new Error('Не удалось обработать архив. Проверьте наличие tar.');
  return result.stdout;
}

async function api(path, key, options = {}) {
  const operation = `${options.method || 'GET'} /v1${path}`;
  let response;
  try { response = await fetch(`${baseUrl}${path}`, {
    ...options, headers: { 'X-Api-Key': key }, redirect: 'error', signal: AbortSignal.timeout(options.method === 'POST' ? 900_000 : 15_000),
  }); } catch { throw new Error(options.method === 'POST' ? `VibeCode: ${operation}: ответ доставки не получен. Проверьте журнал сервера перед повтором.` : `VibeCode: ${operation}: HTTPS-соединение не установлено. Деплой не начат.`); }
  let body;
  try { body = await response.json(); } catch { throw new Error(`VibeCode: ${operation}: HTTP ${response.status}, неожиданный формат ответа.`); }
  if (!response.ok || body.success !== true) {
    const code = /^[A-Z0-9_]{1,80}$/.test(body.error?.code || '') ? body.error.code : 'UNKNOWN';
    throw new Error(`VibeCode: ${operation}: HTTP ${response.status}, код ${code}.`);
  }
  return body.data;
}

try {
  const { dryRun, keySource } = deploymentArguments(process.argv.slice(2));
  // Packaging can be checked without opening .env or accessing the network.
  for (const entry of entries) await review(join(projectRoot, entry));
  temporary = await mkdtemp(join(tmpdir(), 'yuktari-deploy-'));
  const archive = join(temporary, 'app.tar.gz');
  tar(['-czf', archive, ...entries]);
  const contents = tar(['-tzf', archive]).split(/\r?\n/).filter(Boolean);
  if (!contents.includes('src/server.js') || contents.some(name => !entries.some(entry => name === entry || name.startsWith(`${entry}/`)))) {
    throw new Error('Содержимое архива не соответствует списку публикации.');
  }
  if (dryRun) {
    await copyFile(archive, join(projectRoot, 'deploy-preview.tar.gz'));
    console.log(`Архив проверен: ${contents.length} записей. Файл deploy-preview.tar.gz. Сетевые запросы не выполнялись.`);
  } else {
    loadLocalEnv();
    const { SERVER_ID: serverId, B24_DOMAIN: portal } = process.env;
    const { key, variable } = selectDeploymentKey(process.env,keySource);
    if (!serverId || !portal) throw new Error('Заполните SERVER_ID и B24_DOMAIN в локальном .env.');
    console.log(`Источник ключа доставки: ${variable}.`);
    if (!/^[a-z0-9.-]+$/i.test(portal)) throw new Error('B24_DOMAIN должен содержать домен без протокола и пути.');
    const me = await api('/me', key);
    if (typeof me.portal !== 'string' || me.portal.toLowerCase() !== portal.toLowerCase()) throw new Error('VibeCode: ключ доставки не подтвердил целевой портал.');
    const id = encodeURIComponent(serverId);
    const server = await api(`/infra/servers/${id}`, key);
    if (server.mode !== 'BLACKHOLE') throw new Error('Для этого проекта требуется существующий сервер в режиме BLACKHOLE. Режим доступа не изменён.');
    const mode = process.env.APP_MODE || (process.env.VIBE_APP_KEY ? 'bitrix' : 'demo');
    if (!['demo', 'bitrix'].includes(mode)) throw new Error('Заполните APP_MODE: demo или bitrix.');
    if (mode === 'bitrix' && (!/^vibe_app_[A-Za-z0-9_-]+$/.test(process.env.VIBE_APP_KEY || '') || !/^https:\/\/[a-z0-9.-]+\/?$/i.test(process.env.APP_URL || ''))) throw new Error('Заполните VIBE_APP_KEY и точный HTTPS APP_URL для режима bitrix.');
    if (process.env.APP_URL && (!server.appUrl || new URL(server.appUrl).origin !== new URL(process.env.APP_URL).origin)) throw new Error('VibeCode: APP_URL не совпадает с адресом выбранного сервера.');
    let catalogChoice;
    if (needsCatalogChoice(server) && process.stdin.isTTY && process.stdout.isTTY) {
      const prompt = createInterface({input:process.stdin,output:process.stdout});
      try {
        const answer = (await prompt.question('Показывать ли приложение сотрудникам в Битрикс24 — карточку в каталоге и чат приложения? ')).trim().toLowerCase();
        if (['да','yes','y'].includes(answer)) catalogChoice = true;
        else if (['нет','no','n'].includes(answer)) catalogChoice = false;
      } finally { prompt.close(); }
    }
    const dataDir = '/opt/data/yuktari-trip-planning';
    const runtimeEnv = { NODE_ENV:'production',HOST:'0.0.0.0',PORT:'3000',B24_DOMAIN:portal,APP_MODE:mode,DATA_DIR:dataDir };
    for (const name of ['VIBE_APP_KEY','APP_URL','PLANNER_MANAGER_IDS','B24_ROUTE_IBLOCK_ID']) if (process.env[name]) runtimeEnv[name] = process.env[name];
    const form = new FormData();
    form.set('archive', new Blob([readFileSync(archive)], { type: 'application/gzip' }), 'app.tar.gz');
    for (const [name, value] of Object.entries({
      runtime: 'node20', install: 'cd /opt/app && npm ci --omit=dev',
      start: 'cd /opt/app && node src/server.js', port: '3000', cleanDeploy: 'true',
      displayName: 'Планирование выездов', description: 'Планирование выездов сотрудников Юктари',
      changelog: process.env.DEPLOY_CHANGELOG || 'Уточнены фильтры календаря и активных сделок. Сохранённые брони остаются видны при отказе фильтра CRM; сообщения указывают этап и имена полей без раскрытия данных.',
      env: JSON.stringify(runtimeEnv), dataDirs: JSON.stringify([dataDir]),
    })) form.set(name, value);
    if (catalogChoice !== undefined) form.set('showInBitrix24',String(catalogChoice));
    // The management key is never passed into the application runtime.
    console.log('Загрузка и деплой приложения…');
    const result = await api(`/infra/servers/${id}/deploy?stream=false`, key, { method: 'POST', body: form });
    if (result.steps?.some(step => ['failed', 'error'].includes(String(step.status).toLowerCase()))) throw new Error('Один из шагов деплоя завершился ошибкой. Проверьте журнал VibeCode.');
    console.log('VibeCode подтвердил деплой. Адрес и журнал доступны в карточке сервера.');
    try { console.log(catalogSummary(await api(`/infra/servers/${id}`, key))); }
    catch { console.log('Показ в Битрикс24: текущее состояние после доставки проверить не удалось.'); }
  }
} catch (error) {
  // Never print remote bodies, environment values or raw network exceptions.
  const safe = error.message.startsWith('VibeCode') || /^(Заполните|Для этого|Не удалось|Архив|Содержимое|В рабочем|В архив|Допустимый|B24_DOMAIN|Один из)/.test(error.message);
  console.error(safe ? error.message : 'Операция не завершена. Проверьте файлы проекта, сеть и настройки VibeCode.');
  process.exitCode = 1;
} finally {
  // mkdtemp creates this task-owned directory; never remove an environment-provided path.
  if (temporary) await rm(temporary, { recursive: true, force: true });
}
