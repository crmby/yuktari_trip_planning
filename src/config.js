import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

export const projectRoot = fileURLToPath(new URL('../', import.meta.url));

export function loadLocalEnv() {
  const path = fileURLToPath(new URL('../.env', import.meta.url));
  if (existsSync(path)) process.loadEnvFile(path);
}

export function readConfig(env = process.env) {
  const port = Number(env.PORT || 3000);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('PORT должен быть от 1 до 65535.');
  const portal = env.B24_DOMAIN || '';
  if (portal && !/^(?=.{1,253}$)[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/i.test(portal)) {
    throw new Error('B24_DOMAIN должен содержать только домен портала без протокола и пути.');
  }
  const mode = env.APP_MODE || (env.VIBE_APP_KEY ? 'bitrix' : 'demo');
  if (!['demo', 'bitrix'].includes(mode)) throw new Error('APP_MODE должен быть demo или bitrix.');
  const appUrl = env.APP_URL || '';
  if (appUrl && !/^https:\/\/[a-z0-9.-]+\/?$/i.test(appUrl)) throw new Error('APP_URL должен быть точным HTTPS-адресом приложения.');
  return { port, host: env.HOST || '127.0.0.1', portal, mode, appUrl,
    appKey: env.VIBE_APP_KEY || '', dataDir: env.DATA_DIR || resolve(projectRoot, 'var/planning'),
    managerIds: String(env.PLANNER_MANAGER_IDS || '').split(',').map(s => s.trim()).filter(s => /^[1-9]\d*$/.test(s)),
    routeIblockId: env.B24_ROUTE_IBLOCK_ID || '', routeIblockType: env.B24_ROUTE_IBLOCK_TYPE || 'lists',
  };
}
