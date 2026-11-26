import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { loadLocalEnv } from '../src/config.js';

const base = 'https://vibecode.bitrix24.tech/v1';
const safeCode = value => /^[A-Z0-9_]{1,80}$/.test(value || '') ? value : 'UNKNOWN';
const safeId = value => typeof value === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(value) ? value : null;
function failure(message) { return Object.assign(new Error(message), { safe:true }); }
function address(value) {
  try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password && !url.search && !url.hash && url.pathname === '/' ? url.origin : null; }
  catch { return null; }
}

// No session or CRM records are requested. Only allowlisted comparisons leave this function.
export async function inspectApp(env, fetchImpl = fetch) {
  const key = env.VIBE_APP_KEY, portal = String(env.B24_DOMAIN || '').toLowerCase();
  const expectedUrl = address(env.APP_URL), serverId = safeId(env.SERVER_ID);
  if (!/^vibe_app_[A-Za-z0-9_-]+$/.test(key || '')) throw failure('VIBE_APP_KEY отсутствует или имеет неверный формат. Значение не выводится.');
  if (!portal || !expectedUrl || !serverId) throw failure('Заполните B24_DOMAIN, HTTPS APP_URL и SERVER_ID для сравнения.');
  async function get(path, credential) {
    let response;
    try { response = await fetchImpl(base + path, { method:'GET',headers:{'X-Api-Key':credential,Accept:'application/json'},redirect:'error',signal:AbortSignal.timeout(15000) }); }
    catch { throw failure('HTTPS-соединение с VibeCode не установлено. Принадлежность ключа не проверена.'); }
    let body;
    try { body = await response.json(); } catch { throw failure(`VibeCode: HTTP ${response.status}, неожиданный ответ. Проверка не завершена.`); }
    if (!response.ok || body.success !== true) throw failure(`VibeCode: HTTP ${response.status}, код ${safeCode(body.error?.code)}. Проверка не завершена.`);
    return body.data;
  }
  const me = await get('/me',key);
  const appId = safeId(me?.app?.id);
  const report = { oauthKeyAccepted:me?.type === 'oauth_app',portalMatches:typeof me?.portal === 'string' && me.portal.toLowerCase() === portal,oauthAppId:appId,
    missingScopes:['crm','user','lists','placement'].filter(scope=>!me?.scopes?.includes(scope)),appUrlMatches:null,serverUrlMatches:null,
    verdict:'INCOMPLETE',runtimeKeyChecked:false };
  if (!report.oauthKeyAccepted || !report.portalMatches) { report.verdict='MISMATCH';return report; }
  if (!appId || !env.VIBE_API_KEY) return report;
  // This is the existing deployment credential; never transfer it into the runtime.
  let app, server;
  try { app = await get(`/apps/${encodeURIComponent(appId)}`,env.VIBE_API_KEY); }
  catch (error) { report.detailCheckError = error.safe ? error.message : 'Не удалось проверить карточку приложения.'; return report; }
  if (safeId(app?.id) !== appId) throw failure('Ответ карточки OAuth-приложения не соответствует проверяемому ID.');
  report.appUrlMatches = address(app?.appUrl) === expectedUrl;
  try { server = await get(`/infra/servers/${encodeURIComponent(serverId)}`,env.VIBE_API_KEY); }
  catch (error) { report.detailCheckError = error.safe ? error.message : 'Не удалось проверить сервер.'; return report; }
  if (safeId(server?.id) !== serverId) throw failure('Ответ сервера не соответствует настроенному SERVER_ID.');
  report.serverUrlMatches = address(server?.appUrl) === expectedUrl;
  report.verdict = report.appUrlMatches && report.serverUrlMatches ? 'MATCH' : 'MISMATCH';
  return report;
}

export function describeReport(report) {
  const yes = value => value === null ? 'не проверено' : value ? 'да' : 'нет';
  return [
    `OAuth-ключ принят: ${yes(report.oauthKeyAccepted)}.`, `Портал совпадает: ${yes(report.portalMatches)}.`,
    `ID OAuth-приложения: ${report.oauthAppId || 'не получен'}.`,
    `Адрес OAuth-приложения совпадает с APP_URL: ${yes(report.appUrlMatches)}.`,
    `Адрес настроенного сервера совпадает с APP_URL: ${yes(report.serverUrlMatches)}.`,
    `Недостающие доступы: ${report.missingScopes.join(', ') || 'нет'}.`,
    report.verdict === 'MATCH' ? 'Локальный VIBE_APP_KEY относится к OAuth-приложению с нужным адресом сервера.' : report.verdict === 'MISMATCH' ? 'Обнаружено несовпадение. Настройки и ключи не изменялись.' : 'Проверка неполная: ' + (report.detailCheckError || 'для карточки OAuth-приложения нужен доступ существующего VIBE_API_KEY.'),
    'Ключ в среде уже работающего сервера и приложение, выдавшее сессию посетителя, этой командой не проверяются.',
  ];
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try { loadLocalEnv(); const report = await inspectApp(process.env); console.log(describeReport(report).join('\n')); if (report.verdict !== 'MATCH') process.exitCode = 1; }
  catch (error) { console.error(error.safe ? error.message : 'Проверка не завершена. Проверьте локальную конфигурацию; значения не выводятся.'); process.exitCode=1; }
}
