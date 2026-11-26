import { readFile } from 'node:fs/promises';
import { createBitrixApi } from './bitrix/api.js';
import { version } from './build.js';

const assets = new Map([
  ['/', ['index.html', 'text/html; charset=utf-8']],
  ['/css/b24-tokens.css', ['css/b24-tokens.css', 'text/css; charset=utf-8']],
  ['/css/app.css', ['css/app.css', 'text/css; charset=utf-8']],
  ['/js/app.js', ['js/app.js', 'text/javascript; charset=utf-8']],
  ['/js/planner.js', ['js/planner.js', 'text/javascript; charset=utf-8']],
  ['/js/bridge.js', ['js/bridge.js', 'text/javascript; charset=utf-8']],
  ['/js/picker.js', ['js/picker.js', 'text/javascript; charset=utf-8']],
]);

export function createApp(config = {}, dependencies = {}) {
  const { portal = '', mode = 'demo' } = config;
  const bitrixApi = mode === 'bitrix' ? createBitrixApi(config, dependencies) : null;
  return async (req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-App-Version', version);
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self' https://vibecode.bitrix24.tech" + (portal ? ` https://${portal}` : ''));
    let path, url;
    try { url = new URL(req.url, 'http://localhost'); path = url.pathname; } catch { res.writeHead(400); return res.end(); }
    if (path.startsWith('/api/') && bitrixApi) return bitrixApi(req, res, url);
    if (path === '/api/runtime' && req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' }); return res.end(JSON.stringify({ mode: 'demo', version }));
    }
    if (!['GET', 'HEAD'].includes(req.method)) {
      res.writeHead(405, { Allow: 'GET, HEAD' });
      return res.end();
    }
    if (path === '/health') {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      return res.end(req.method === 'HEAD' ? undefined : JSON.stringify({ status: 'ok', app: 'yuktari-trip-planning', version }));
    }
    // Demo mode never exposes portal data APIs.
    if (path.startsWith('/api/')) {
      res.writeHead(503, { 'Content-Type': 'application/json; charset=utf-8' });
      return res.end(req.method === 'HEAD' ? undefined : JSON.stringify({ error: 'INTEGRATION_NOT_CONFIGURED' }));
    }
    const asset = assets.get(path);
    if (!asset) { res.writeHead(404); return res.end(); }
    try {
      const body = await readFile(new URL(`../public/${asset[0]}`, import.meta.url));
      res.writeHead(200, { 'Content-Type': asset[1] });
      res.end(req.method === 'HEAD' ? undefined : body);
    } catch {
      res.writeHead(500);
      res.end('Не удалось загрузить приложение.');
    }
  };
}
