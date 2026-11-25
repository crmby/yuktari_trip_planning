import { readdir, readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
let count = 0;
async function check(dir) {
  for (const entry of await readdir(new URL(dir, new URL('../', import.meta.url)), { withFileTypes: true })) {
    const path = `${dir}${entry.name}`;
    if (entry.isDirectory()) { await check(`${path}/`); continue; }
    if (!/\.(m?js)$/.test(path)) continue;
    const result = spawnSync(process.execPath, ['--check', path], { cwd: root, encoding: 'utf8' });
    if (result.status !== 0) throw new Error(result.stderr || `Ошибка проверки ${path}`);
    count++;
  }
}
for (const dir of ['src/', 'public/js/', 'scripts/', 'tests/']) await check(dir);
for (const path of ['package.json', 'package-lock.json']) JSON.parse(await readFile(new URL(`../${path}`, import.meta.url), 'utf8'));
console.log(`Синтаксис проверен: ${count} файлов JS и 2 файла JSON. PHP-шаблон не исполнялся.`);
