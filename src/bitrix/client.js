import { AppError } from './errors.js';

const BASE = 'https://vibecode.bitrix24.tech/v1';
export function createVibeClient({ appKey, fetchImpl = fetch }) {
  return async function call(path, { authorization, method = 'GET', body, signal } = {}) {
    if (!/^vibe_app_[A-Za-z0-9_-]+$/.test(appKey || '')) throw new AppError(503, 'APP_NOT_CONFIGURED', 'Добавьте VIBE_APP_KEY OAuth-приложения в настройки сервера.');
    if (!/^Bearer vibe_session_[A-Za-z0-9_-]+$/.test(authorization || '')) throw new AppError(401, 'AUTH_REQUIRED', 'Откройте приложение из Битрикс24 и разрешите доступ OAuth-приложению.');
    if (!path.startsWith('/') || path.startsWith('//')) throw new AppError(500, 'INVALID_API_PATH', 'Ошибка адреса API.');
    let response;
    try {
      response = await fetchImpl(BASE + path, {
        method, redirect: 'error', signal: signal || AbortSignal.timeout(10_000),
        headers: { 'X-Api-Key': appKey, Authorization: authorization, 'Content-Type': 'application/json', 'X-Crm-Api': 'v2' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
    } catch { throw new AppError(502, method === 'GET' ? 'VIBE_UNAVAILABLE' : 'CRM_WRITE_UNCERTAIN', method === 'GET' ? 'VibeCode не ответил. Проверьте соединение и повторите загрузку.' : 'Ответ на запись CRM не получен. Результат нужно перепроверить перед повторным сохранением.'); }
    let result;
    try { result = await response.json(); } catch { throw new AppError(502, 'VIBE_INVALID_RESPONSE', 'VibeCode вернул неожиданный ответ.'); }
    if (!response.ok || result.success !== true) {
      const status = [401, 403, 404, 409, 429].includes(response.status) ? response.status : 502;
      const code = /^[A-Z0-9_]{1,80}$/.test(result.error?.code || '') ? result.error.code : 'VIBE_REQUEST_FAILED';
      throw new AppError(status, code, status === 401 ? 'Сессия Битрикс24 истекла. Переоткройте приложение из портала.' : status === 403 ? 'У сотрудника или приложения нет доступа к этим данным Битрикс24.' : `Битрикс24 не выполнил запрос (${code}).`);
    }
    if (method !== 'GET' && result.meta?.warnings?.some(w => w.code === 'UNRECOGNIZED_WRITE_FIELD')) throw new AppError(502, 'CRM_WRITE_UNCERTAIN', 'Часть полей CRM могла не сохраниться. Требуется проверка результата.');
    return result;
  };
}

export function createAuthenticator(call, portal) {
  return async function authenticate(req) {
    if (req.headers['x-vibe-caller-kind'] === 'external-api') throw new AppError(401, 'AUTH_REQUIRED', 'Откройте приложение от имени сотрудника Битрикс24.');
    const authorization = req.headers['x-vibe-authorization'];
    const { data } = await call('/me', { authorization });
    const actual = typeof data?.portal === 'string' ? data.portal : data?.portal?.domain;
    const id = String(data?.currentUser?.bitrixUserId || '');
    if (data.type !== 'oauth_app' || !actual || actual.replace(/^https:\/\//, '').replace(/\/$/, '').toLowerCase() !== portal.toLowerCase() || !/^[1-9]\d*$/.test(id)) throw new AppError(403, 'PORTAL_MISMATCH', 'VibeCode не подтвердил сотрудника целевого портала.');
    const profile = (await call('/users/me', { authorization })).data;
    if (String(profile?.id) !== id || profile.active === false) throw new AppError(403, 'USER_NOT_CONFIRMED', 'Не удалось подтвердить активного сотрудника.');
    return { id, name: [profile.lastName, profile.name].filter(Boolean).join(' '), isAdmin: profile.isAdmin === true, authorization };
  };
}
