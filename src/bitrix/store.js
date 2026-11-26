import { mkdir, readFile, writeFile, rename, lstat, open, unlink } from 'node:fs/promises';
import { resolve, join, relative, isAbsolute } from 'node:path';
import { randomUUID } from 'node:crypto';
import { AppError } from './errors.js';
import { fileURLToPath } from 'node:url';

export function emptyState() { return { schema: 3, revision: 0, masters: [], groups: [], bookings: [], operations: [], audit: [] }; }
export function createStore(directory) {
  const root = resolve(directory), file = join(root, 'planning.json'), lock = join(root, 'planning.lock');
  const publicRoot = resolve(fileURLToPath(new URL('../../public', import.meta.url)));
  const inside = relative(publicRoot, root);
  if (inside === '' || (!inside.startsWith('..') && !isAbsolute(inside))) throw new AppError(500, 'UNSAFE_DATA_DIR', 'Хранилище должно находиться вне public.');
  let queue = Promise.resolve();
  async function provision() {
    await mkdir(root, { recursive: true, mode: 0o700 });
    const stat = await lstat(root);
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw new AppError(500, 'UNSAFE_DATA_DIR', 'Небезопасный каталог хранилища.');
  }
  async function read() {
    await provision();
    try {
      const stat = await lstat(file);
      if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 4 * 1024 * 1024) throw new Error();
      const value = JSON.parse(await readFile(file, 'utf8'));
      if (value.schema !== 3 || !Number.isInteger(value.revision) || !['masters', 'groups', 'bookings', 'operations', 'audit'].every(k => Array.isArray(value[k]))) throw new Error();
      return value;
    } catch (error) { if (error.code === 'ENOENT') return emptyState(); throw new AppError(500, 'STORE_INVALID', 'Хранилище приложения повреждено или недоступно.'); }
  }
  async function save(state) {
    state.revision++;
    state.audit = state.audit.slice(-300);
    const data = JSON.stringify(state);
    if (Buffer.byteLength(data) > 4 * 1024 * 1024) throw new AppError(507, 'STORE_LIMIT', 'Хранилище переполнено; обратитесь к администратору.');
    const temp = file + '.' + randomUUID() + '.tmp';
    await writeFile(temp, data, { mode: 0o600, flag: 'wx' });
    try { await rename(temp, file); } catch (error) { await unlink(temp).catch(() => {}); throw error; }
  }
  function transaction(run) {
    const result = queue.then(async () => {
      await provision();
      let handle;
      try { handle = await open(lock, 'wx', 0o600); }
      catch (error) { if (error.code === 'EEXIST') throw new AppError(409, 'STORE_BUSY', 'Другой процесс сохраняет данные. Повторите позже.'); throw error; }
      try { return await run(await read(), save); } finally { await handle.close(); await unlink(lock); }
    });
    queue = result.catch(() => {}); return result;
  }
  return { read, transaction };
}
