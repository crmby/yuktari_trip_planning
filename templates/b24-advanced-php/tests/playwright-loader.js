// Один resolver для preflight и всех живых сценариев.
// Явный путь авторитетен: ошибку его настройки не скрываем другим пакетом.
const path = require('path');
const configured = (process.env.BXAPP_PLAYWRIGHT_PATH || '').trim();
const candidates = configured ? [path.resolve(configured)] : [
  'playwright',
  '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright',
];
let resolved = null;
for (const candidate of candidates) {
  try { resolved = require.resolve(candidate); break; }
  catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
}
if (resolved === null) {
  const error = new Error('Не найден Playwright: установите пакет или задайте BXAPP_PLAYWRIGHT_PATH.');
  error.code = 'MODULE_NOT_FOUND';
  throw error;
}
// Ошибку загрузки найденного пакета не маскируем fallback: это уже не отсутствие.
module.exports = require(resolved);
if (!module.exports || !module.exports.chromium) throw new Error('Пакет Playwright не предоставляет chromium.');
