import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createApp } from '../src/app.js';
import { readConfig } from '../src/config.js';

test('порт и домен проверяются до запуска', () => {
  assert.throws(() => readConfig({ PORT: '0' }));
  assert.throws(() => readConfig({ B24_DOMAIN: 'example.com; https://other.com' }));
  assert.equal(readConfig({}).host, '127.0.0.1');
});

test('сервис отдаёт только каркас и health; конфиги, шаблоны и данные недоступны', async t => {
  const server = createServer(createApp({ portal: 'example.bitrix24.by' }));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise(resolve => { server.close(resolve); server.closeAllConnections(); }));
  const base = `http://127.0.0.1:${server.address().port}`;
  const page = await fetch(base);
  assert.equal(page.status, 200);
  assert.match(await page.text(), /Планирование выездов/);
  assert.match(page.headers.get('content-security-policy'), /frame-ancestors 'self' https:\/\/vibecode.bitrix24.tech https:\/\/example.bitrix24.by/);
  assert.equal(page.headers.has('x-frame-options'), false);
  assert.equal(page.headers.get('cache-control'), 'no-store');
  for (const path of ['/.env', '/.git/config', '/templates/b24-advanced-php/www/template.html', '/src/config.js', '/data/state.json', '/%2e%2e/.env']) {
    assert.equal((await fetch(base + path)).status, 404, path);
  }
  assert.equal((await fetch(base + '/api/trips')).status, 503);
  assert.equal((await fetch(base, { method: 'POST' })).status, 405);
  assert.equal((await fetch(base, { method: 'HEAD' })).status, 200);
  assert.equal((await fetch(base + '/health')).status, 200);
  for (const path of ['/css/b24-tokens.css', '/css/app.css', '/js/app.js', '/js/planner.js']) assert.equal((await fetch(base + path)).status, 200);
});
