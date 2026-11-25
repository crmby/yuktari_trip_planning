import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

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
  return { port, host: env.HOST || '127.0.0.1', portal };
}
