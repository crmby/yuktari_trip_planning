// Проверяется реальная строка импорта каждого runner в изолированном module resolution.
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.join(__dirname, '..');
const fallback = '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright';
const loader = path.join(__dirname, 'playwright-loader.js');
let failures = 0;
function check(name, ok) { console.log((ok ? 'ok ' : 'СБОЙ ') + name); if (!ok) failures++; }
function environment(available, explicit) {
  const marker = { chromium: { marker: true } };
  function missing(id) { const e = new Error('module missing: ' + id); e.code = 'MODULE_NOT_FOUND'; return e; }
  function fakeRequire(id) {
    if (id === 'path') return path;
    if (id === './playwright-loader') {
      if (!fs.existsSync(loader)) throw missing(id);
      const module = { exports: {} };
      vm.runInNewContext(fs.readFileSync(loader, 'utf8'), { require: fakeRequire, module,
        process: { env: explicit ? { BXAPP_PLAYWRIGHT_PATH: explicit } : {} } }, { filename: loader });
      return module.exports;
    }
    if (available.includes(id)) return marker;
    throw missing(id);
  }
  fakeRequire.resolve = function (id) { if (available.includes(id)) return id; throw missing(id); };
  return fakeRequire;
}
for (const runner of ['live-harness.js', 'ui-stand.js']) {
  const source = fs.readFileSync(path.join(__dirname, runner), 'utf8');
  const line = source.match(/^const \{ chromium \} = require\([^\n]+\);$/m);
  for (const [label, available, explicit, expected] of [
    ['обычная установка без machine-cache', ['playwright'], '', true],
    ['штатный старый cache', [fallback], '', true],
    ['явный путь', ['/tmp/declared-playwright'], '/tmp/declared-playwright', true],
    ['ошибочный явный путь не скрывается cache', [fallback], '/tmp/missing-playwright', false],
    ['нет Playwright', [], '', false],
  ]) {
    let loaded = false;
    try { loaded = vm.runInNewContext(line ? line[0] + '\nchromium.marker;' : 'false', { require: environment(available, explicit) }) === true; }
    catch (e) { if (e.code !== 'MODULE_NOT_FOUND') console.log('diagnostic ' + e.message); }
    check(runner + ': ' + label, loaded === expected);
  }
}
const self = fs.readFileSync(path.join(root, 'tools/self-check.sh'), 'utf8');
check('preflight использует тот же resolver, что runners', self.includes('node -e "require(\'./tests/playwright-loader\')"'));
process.exitCode = failures ? 1 : 0;
