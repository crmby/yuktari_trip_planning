import { loadLocalEnv, readConfig } from '../src/config.js';

const base = 'https://vibecode.bitrix24.tech/v1';

async function get(path, key, label) {
  let response;
  try {
    response = await fetch(base + path, {
      headers: { 'X-Api-Key': key }, redirect: 'error', signal: AbortSignal.timeout(15_000),
    });
  } catch (error) {
    const code = error.cause?.code;
    const dns = ['ENOTFOUND', 'EAI_AGAIN'].includes(code);
    throw new Error(dns
      ? `${label}: DNS не определил адрес VibeCode. Ключ не проверен.`
      : `${label}: HTTPS-соединение с VibeCode не установлено. Проверьте сеть, VPN и доступ Node.js. Ключ не проверен.`);
  }
  let body;
  try { body = await response.json(); } catch { throw new Error(`${label}: HTTP ${response.status}, неожиданный формат ответа.`); }
  if (!response.ok || body.success !== true) {
    // Remote bodies and messages can contain sensitive data: expose only the status.
    throw new Error(`${label}: VibeCode отклонил запрос, HTTP ${response.status}. Подробности проверьте в кабинете VibeCode.`);
  }
  console.log(`${label}: HTTP ${response.status}, успешно.`);
  return body.data;
}

try {
  loadLocalEnv();
  const { VIBE_API_KEY: key, SERVER_ID: serverId } = process.env;
  const { portal } = readConfig();
  if (!key || !serverId || !portal) throw new Error('Заполните VIBE_API_KEY, SERVER_ID и B24_DOMAIN в локальном .env.');
  const me = await get('/me', key, 'Проверка ключа');
  const actual = typeof me?.portal === 'string' ? me.portal : me?.portal?.domain;
  if (typeof actual !== 'string') throw new Error('Ключ принят, но домен портала не распознан в ответе. Принадлежность порталу не подтверждена.');
  if (actual.replace(/^https?:\/\//, '').replace(/\/$/, '').toLowerCase() !== portal.toLowerCase()) {
    throw new Error('Ключ принят, но относится к другому порталу. Проверка сервера остановлена.');
  }
  console.log('Домен портала совпадает с настройками.');
  const server = await get(`/infra/servers/${encodeURIComponent(serverId)}/ssh`, key, 'Доступ к серверу');
  if (!['BLACKHOLE', 'OPEN'].includes(server?.mode)) throw new Error('Сервер доступен, но его режим не распознан.');
  console.log(`Режим сервера: ${server.mode}.`);
  if (server.mode === 'OPEN') console.log('Подготовленный скрипт деплоя требует BLACKHOLE; режим не изменялся.');
  console.log('Проверка завершена. Деплой и удалённые команды не выполнялись.');
} catch (error) {
  // Print only locally composed messages, never exceptions from the .env loader.
  const safe = /^(Проверка ключа:|Доступ к серверу:|Заполните VIBE_API_KEY,|Ключ принят,|Сервер доступен,)/.test(error.message || '');
  console.error(safe ? error.message : 'Не удалось проверить подключение. Проверьте локальную конфигурацию; значения не выводятся.');
  process.exitCode = 1;
}
