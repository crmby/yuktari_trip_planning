import { AppError } from './errors.js';

const BASE = 'https://vibecode.bitrix24.tech/v1';
function requestLabel(path) {
  const pathname = path.split('?')[0];
  const labels = {
    '/me':'проверка ключа и сессии', '/users/me':'проверка профиля сотрудника',
    '/deals/fields':'загрузка полей сделок', '/contacts/fields':'загрузка полей контактов',
    '/userfields/contacts':'загрузка настроек пользовательских полей контакта',
    '/deal-categories':'загрузка воронок сделок', '/deals':'поиск и календарь сделок',
    '/users':'загрузка сотрудников', '/placements/bind':'привязка вкладки сделки',
  };
  if (labels[pathname]) return labels[pathname];
  if (/^\/lists\/\d+\/elements$/.test(pathname)) return 'загрузка справочника маршрутов';
  if (/^\/deals\/\d+\/products$/.test(pathname)) return 'загрузка услуг сделки';
  if (/^\/deals\/\d+$/.test(pathname)) return 'доступ к карточке сделки';
  if (/^\/contacts\/\d+$/.test(pathname)) return 'доступ к карточке контакта';
  if (/^\/users\/\d+$/.test(pathname)) return 'доступ к карточке сотрудника';
  return 'доступ к данным';
}
function accessMessage(status, code) {
  if (['MISSING_API_KEY','INVALID_API_KEY','KEY_INACTIVE','KEY_EXPIRED','KEY_NOT_FOUND'].includes(code)) return 'VibeCode не принимает ключ приложения. Проверьте VIBE_APP_KEY в переменных работающего сервера: нужен действующий ключ этого OAuth-приложения. Изменение локального .env вступит в силу после доставки настроек на сервер.';
  if (code === 'SESSION_APP_MISMATCH') return 'Сессия выдана другому OAuth-приложению. VIBE_APP_KEY на сервере должен принадлежать тому приложению, через которое открыт этот интерфейс.';
  if (code === 'WRONG_AUTH_SCHEME') return 'VibeCode отклонил схему авторизации. Ключ приложения передаётся в X-Api-Key, сессия сотрудника — в Authorization.';
  if (code === 'NO_PORTAL') return 'OAuth-приложение не связано с порталом. Проверьте его подключение к Юктари в VibeCode.';
  if (code === 'PORTAL_CREDENTIALS_REJECTED') return 'Портал отклонил доступ приложения. Проверьте подключение OAuth-приложения в VibeCode.';
  if (status === 401) return 'Нужно заново войти или выдать приложению доступ. Закройте приложение и откройте его заново из меню Битрикс24; простое обновление старой вкладки сессию не восстановит. При первом входе авторизуйте приложение.';
  if (status === 403) return 'У сотрудника или приложения нет доступа к этим данным Битрикс24.';
  return 'Битрикс24 не выполнил запрос.';
}
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
      const status = [400, 401, 403, 404, 409, 422, 429].includes(response.status) ? response.status : 502;
      const code = /^[A-Z0-9_]{1,80}$/.test(result.error?.code || '') ? result.error.code : 'VIBE_REQUEST_FAILED';
      const step = requestLabel(path);
      const b24Code = /^[A-Z0-9_]{1,80}$/.test(result.error?.b24Code || '') ? result.error.b24Code : '';
      const causes = {b24_permission:'Портал сообщил о недостатке прав сотрудника.',b24_scope:'Портал сообщил о недостатке OAuth-разрешений приложения.',key_scope_missing:'У ключа приложения отсутствует разрешение на этот запрос.'};
      const cause = Object.hasOwn(causes,result.error?.cause) ? causes[result.error.cause] : '';
      throw new AppError(status, code, `${accessMessage(status, code)} Код: ${code}${b24Code ? `; код Битрикс24: ${b24Code}` : ''}; ${step}.${cause ? ` ${cause}` : ''}`);
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
