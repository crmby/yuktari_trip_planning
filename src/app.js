import { readFile } from 'node:fs/promises';

const assets = new Map([
  ['/', ['index.html', 'text/html; charset=utf-8']],
  ['/css/b24-tokens.css', ['css/b24-tokens.css', 'text/css; charset=utf-8']],
  ['/css/app.css', ['css/app.css', 'text/css; charset=utf-8']],
  ['/js/app.js', ['js/app.js', 'text/javascript; charset=utf-8']],
]);

export function createApp({ portal = '' } = {}) {
  return async (req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self' https://vibecode.bitrix24.tech" + (portal ? ` https://${portal}` : ''));
    const path = new URL(req.url, 'http://localhost').pathname;
    if (!['GET', 'HEAD'].includes(req.method)) {
      res.writeHead(405, { Allow: 'GET, HEAD' });
      return res.end();
    }
    if (path === '/health') {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      return res.end(req.method === 'HEAD' ? undefined : JSON.stringify({ status: 'ok', app: 'yuktari-trip-planning' }));
    }
    // Until verified per-user authentication is implemented, no portal data APIs exist.
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
