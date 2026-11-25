import { createServer } from 'node:http';
import { createApp } from './app.js';
import { loadLocalEnv, readConfig } from './config.js';

loadLocalEnv();
const config = readConfig();
const server = createServer(createApp(config));
server.on('error', () => { console.error('Не удалось запустить сервер: проверьте адрес и свободный порт.'); process.exitCode = 1; });
server.listen(config.port, config.host, () => console.log(`Планирование выездов: http://${config.host}:${config.port}`));
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => server.close());
