/*
 * ОБВЯЗКА ЖИВЫХ ПРОГОНОВ: копия приложения, настоящий PHP-сервер, готовый пропуск.
 *
 * Живых сценариев больше одного (настройки, долгая задача), и у всех одна и та
 * же подготовка. Держать её копиями — значит однажды починить порт в одном файле
 * и не починить в другом.
 *
 *   const { runLive } = require('./live-harness');
 *   runLive({ patchSchema: true }, async (ctx) => { ... });
 *
 * ctx: {base, page, browser, TOKEN, check, worker(), appRoot, dataRoot}
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const net = require('net');
const { spawn, spawnSync } = require('child_process');
const { chromium } = require('./playwright-loader');

const ROOT = path.join(__dirname, '..');
// ЖЁСТКИЙ СРОК ЖИЗНИ ТЕСТОВЫХ СЕРВЕРОВ. Прогон снимают по SIGKILL чаще, чем
// кажется (прерванная задача, перезапуск, упавший агент), и тогда обработчики
// выхода не срабатывают, а `php -S` живёт сиротой. Замер 08.08.2026: 718 таких
// серверов возрастом до 19 часов. `timeout` пересылает сигнал дочернему php,
// поэтому обычное снятие сервера продолжает работать как прежде.
const TTL = ['-k', '5', String(Number(process.env.BXAPP_TEST_SERVER_TTL) || 900)];
const GENERATION = 'live-generation-1';
const TOKEN = 'c'.repeat(64);

// Описание из всех шести типов берём ИЗ обещанного агенту copyable блока.
// Иначе документация и живой сценарий снова разойдутся: тест будет проверять
// свою скрытую schema, а человек копировать другую.
function demoSchemaFromDocs() {
  const blocks = fs.readFileSync(path.join(ROOT, 'docs/BLOCKS.md'), 'utf8');
  const section = blocks.match(/<!-- settings-schema-example:start -->([\s\S]*?)<!-- settings-schema-example:end -->/);
  const code = section && section[1].match(/```php\s*([\s\S]*?)```/);
  if (!code || !/function\s+projectSettingsSchema\s*\(\)\s*:\s*array/.test(code[1])) {
    throw new Error('в docs/BLOCKS.md нет копируемого projectSettingsSchema всех шести типов');
  }
  return code[1].trim();
}
const DEMO_SCHEMA = demoSchemaFromDocs();

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.on('error', reject);
    s.listen(0, '127.0.0.1', () => {
      const port = s.address().port;
      s.close(() => resolve(port));
    });
  });
}

function waitPort(port) {
  return new Promise((resolve, reject) => {
    let tries = 0;
    (function attempt() {
      const s = net.connect(port, '127.0.0.1');
      s.on('connect', () => { s.end(); resolve(); });
      s.on('error', () => {
        s.destroy();
        if (++tries > 100) return reject(new Error('сервер не поднялся'));
        setTimeout(attempt, 50);
      });
    })();
  });
}

function runLive(opts, scenario) {
  const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'bxapp-live-data-'));
  const appRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'bxapp-live-app-'));
  const failures = [];
  let server = null;
  let oauth = null;
  let portalBase = null;
  let browser = null;

  const env = {
    ...process.env, BXAPP_DATA_ROOT: dataRoot, BXAPP_CLIENT_ID: 'local.test.app',
    BXAPP_CLIENT_SECRET: 'test-secret', BXAPP_OAUTH_URL: 'http://127.0.0.1:9/oauth/token/',
  };

  function check(what, ok, detail) {
    console.log((ok ? '  ✓ ' : '  ✖ ') + what + (ok || !detail ? '' : ' — ' + detail));
    if (!ok) failures.push(what);
  }

  function cleanup() {
    if (server && server.pid) { try { process.kill(server.pid); } catch (e) { /* уже умер */ } }
    if (oauth && oauth.pid) { try { process.kill(oauth.pid); } catch (e) { /* уже умер */ } }
    fs.rmSync(dataRoot, { recursive: true, force: true });
    fs.rmSync(appRoot, { recursive: true, force: true });
  }
  process.on('exit', cleanup);

  // Копируем ВЕСЬ www: код настоящий, ядро не подменяется вовсе. Список настроек
  // приходит так же, как в проекте, — файлом api/project-hooks.php (ревью
  // 23.09.2026, modulnost-2): прежде стенд переписывал тело settingsSchema() ядра.
  fs.cpSync(path.join(ROOT, 'www'), path.join(appRoot, 'www'), { recursive: true });
  if (opts.patchSchema) {
    const hooksPath = path.join(appRoot, 'www/api/project-hooks.php');
    if (fs.existsSync(hooksPath)) { console.error('в копии www уже есть project-hooks.php'); process.exit(1); }
    fs.writeFileSync(hooksPath, "<?php\ndeclare(strict_types=1);\n"
      + "if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {\n"
      + "    http_response_code(404);\n    exit;\n}\n" + DEMO_SCHEMA + "\n");
  }

  // ── Портал и сервер авторизации — поддельные, приложение настоящее ─────────
  //
  // Установка проверяется ТОЛЬКО так: код обменивает присланный refresh на
  // токены нашим client_secret и спрашивает портал, кто открыл. Подменяем ровно
  // эти два внешних адреса — сам разбор, запись состояния и гейт доступа
  // работают как на бою. Подмена короткого запроса к порталу включается только
  // при уведённом сторе (см. b24PortalGet), поэтому на живом приложении её нет.
  if (opts.portal) {
    fs.writeFileSync(path.join(appRoot, 'prepend.php'), `<?php
$GLOBALS['__b24_portal_get'] = function (string $domain, string $method, string $authId) {
    // Сценарий вправе «уронить» портал прямо во время прогона: молчание портала
    // и отказ в правах человек различает только по тексту на экране, и проверить
    // это можно лишь на живом отказе.
    if (is_file(getenv('BXAPP_DATA_ROOT') . '/portal-down')) return null;
    $employee = strpos($authId, 'employee') !== false;
    if ($method === 'user.current') {
        return ['result' => ['ID' => $employee ? 77 : 10, 'NAME' => $employee ? 'Сотрудник' : 'Владелец',
                             'LAST_NAME' => 'Тестовый', 'UF_DEPARTMENT' => [3]]];
    }
    if ($method === 'user.admin') return ['result' => !$employee];
    if ($method === 'app.info') return ['result' => ['INSTALLED' => true, 'SCOPE' => ['crm', 'user']]];
    return ['result' => []];
};
// Полный REST-вызов клиента (им приложение фиксирует владельца, привязывает
// места в портале и проверяет установку) — тот же штатный шов, включённый
// только при уведённом сторе.
$GLOBALS['__b24_call'] = function (string $method, array $params) {
    if ($method === 'user.current') {
        return ['result' => ['ID' => 10, 'NAME' => 'Владелец', 'LAST_NAME' => 'Тестовый']];
    }
    if ($method === 'app.info') return ['result' => ['INSTALLED' => true, 'SCOPE' => ['crm', 'user']]];
    if ($method === 'placement.get') return ['result' => []];
    if ($method === 'placement.bind' || $method === 'placement.unbind') return ['result' => true];
    if ($method === 'event.get') return ['result' => []];
    if ($method === 'scope') return ['result' => ['crm', 'user', 'placement']];
    if ($method === 'user.get') {
        $query = (string)($params['FILTER']['NAME_SEARCH'] ?? '');
        if (strpos($query, 'без') !== false) return ['result' => [[
            'ID' => 78, 'NAME' => 'Анна', 'LAST_NAME' => 'Безфото', 'ACTIVE' => true,
            'WORK_POSITION' => 'дизайнер', 'UF_DEPARTMENT' => [3],
        ]], 'total' => 1];
        if (strpos($query, 'бит') !== false) return ['result' => [[
            'ID' => 79, 'NAME' => 'Борис', 'LAST_NAME' => 'Битый', 'ACTIVE' => true,
            'WORK_POSITION' => 'редактор', 'UF_DEPARTMENT' => [3],
            'PERSONAL_PHOTO' => 'https://portal.invalid/avatar-broken.png',
        ]], 'total' => 1];
        return ['result' => [[
            'ID' => 77, 'NAME' => 'Пётр', 'LAST_NAME' => 'Петров', 'ACTIVE' => true,
            'WORK_POSITION' => 'аналитик', 'UF_DEPARTMENT' => [3],
            'PERSONAL_PHOTO' => 'https://portal.invalid/avatar-petrov.png',
        ]], 'total' => 1];
    }
    if ($method === 'department.get') return ['result' => [[
        'ID' => 3, 'NAME' => 'Аналитика', 'PARENT' => 0,
    ]], 'total' => 1];
    return ['result' => []];
};
`);
    fs.mkdirSync(path.join(appRoot, 'oauth'), { recursive: true });
    // Страница «портала»: с неё браузер отправляет форму на приложение — и сам
    // проставляет заголовок источника. Подменить его из сценария нельзя, а
    // забор входа именно по нему отличает открытие из портала от чужого POST.
    fs.writeFileSync(path.join(appRoot, 'oauth/portal.html'),
      '<!doctype html><meta charset="utf-8"><title>Портал</title><body>портал</body>');
    fs.writeFileSync(path.join(appRoot, 'oauth/index.php'), `<?php
header('Content-Type: application/json');
echo json_encode([
    'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 3600,
    'member_id' => 'MEMBER-LIVE', 'client_endpoint' => 'https://portal.invalid/rest/',
    'scope' => 'crm,user,placement',
]);
`);
  }

  // Токены, список доступа и пропуск кладём ШТАТНЫМ store'ом: формат приватного
  // файла знает только он, а написанный руками проверял бы нашу же выдумку.
  const seed = `
    require ${JSON.stringify(path.join(appRoot, 'www/env.php'))};
    require ${JSON.stringify(path.join(appRoot, 'www/api/store.php'))};
    storeWrite(B24_TOKENS_FILE, [
      'access_token' => 'live-access', 'refresh_token' => 'live-refresh',
      'expires_at' => time() + 3600, 'domain' => 'portal.invalid',
      'member_id' => 'MEMBER-LIVE', 'client_id' => 'local.test.app',
      'application_token' => 'live-app-token', 'installed' => true,
      'install_generation' => ${JSON.stringify(GENERATION)}, 'owner_id' => 10,
    ]);
    storeWrite(ACL_FILE, [
      'owner_uid' => 10,
      'users' => [['uid' => 10, 'name' => 'Владелец', 'photo' => '', 'role' => 'admin']],
      'depts' => [],
    ]);
    storeWrite(SESSIONS_FILE, [[
      'token' => ${JSON.stringify(TOKEN)}, 'uid' => 10, 'role' => 'admin',
      'member_id' => 'MEMBER-LIVE', 'departments' => [],
      'install_generation' => ${JSON.stringify(GENERATION)},
      'createdAt' => time(), 'expiresAt' => time() + 3600,
    ]]);
    echo "SEEDED";
  `;
  // Сценарий установки начинается с ЧИСТОГО приложения: подготовленное
  // состояние сделало бы проверку бессмысленной — она бы шла по ветке «уже
  // установлено» и ни разу не тронула саму установку.
  if (!opts.fresh) {
    const seeded = spawnSync('php', ['-r', seed], { env, encoding: 'utf8' });
    if (!String(seeded.stdout).includes('SEEDED')) {
      console.error('не удалось подготовить store: ' + (seeded.stderr || seeded.stdout));
      process.exit(1);
    }
  }

  // Один тик фонового рабочего — так проверяется, что задача считается НЕ в
  // запросе, а снаружи, и продолжается после закрытия окна.
  function worker() {
    return spawnSync('php', [path.join(appRoot, 'www/bin/process.php')],
      { env, encoding: 'utf8', timeout: 60000 });
  }

  (async () => {
    // Порт берём у системы: на общей машине живут десятки чужих тестовых
    // серверов, и вычисленный порт однажды совпал с чужим — прогон молча
    // разговаривал с ЧУЖИМ приложением.
    if (opts.portal) {
      const oauthPort = await freePort();
      oauth = spawn('timeout', TTL.concat(['php', '-S', '127.0.0.1:' + oauthPort, '-t', path.join(appRoot, 'oauth')]),
        { cwd: appRoot, stdio: 'ignore' });
      oauth.unref();
      await waitPort(oauthPort);
      env.BXAPP_OAUTH_URL = 'http://127.0.0.1:' + oauthPort + '/index.php';
      // Страницу «портала» открываем по ДРУГОМУ имени хоста (localhost вместо
      // 127.0.0.1): вход отличает портал от самого приложения по имени хоста в
      // заголовке источника, и с одинаковым именем сценарий проверял бы не то —
      // приложение законно считало бы такой переход своим собственным.
      portalBase = 'http://localhost:' + oauthPort;
    }
    const port = await freePort();
    const serverArgs = ['php', '-S', '127.0.0.1:' + port, '-t', path.join(appRoot, 'www')];
    if (opts.portal) serverArgs.push('-d', 'auto_prepend_file=' + path.join(appRoot, 'prepend.php'));
    server = spawn('timeout', TTL.concat(serverArgs), { cwd: appRoot, stdio: 'ignore', env });
    server.unref();   // иначе живой дочерний сервер держит цикл событий и зелёный прогон висит
    await waitPort(port);
    const base = 'http://127.0.0.1:' + port;

    // У ЧИСТОГО приложения настроек ещё нет: там законен отказ «не установлено».
    // Проверяем лишь то, ради чего проба и заведена, — что на порту наше
    // приложение, а не чужой сервер.
    const probeOk = opts.fresh ? [409, 503, 403] : [200];
    const probe = await fetch(base + '/api/settings.php', { headers: { 'X-App-Token': TOKEN } })
      .then((r) => r.status).catch((e) => 'нет связи: ' + e.message);
    if (!probeOk.includes(probe)) {
      console.error('сервер прогона отвечает не по-нашему (код ' + probe + ') — порт занят чужим?');
      process.exit(1);
    }

    browser = await chromium.launch({ args: ['--no-sandbox'] });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

    // Страницу отдаёт index.php только после портального POST; для этих сценариев
    // это лишний круг — подставляем пропуск, который он подставил бы сам.
    // В сценарии установки пропуск подставлять НЕЛЬЗЯ: его обязан выдать сам
    // index.php после портального POST — это и проверяется.
    await page.addInitScript((seed) => {
      if (seed.token) { window.APP_SESSION = seed.token; window.APP_ROLE = 'admin'; }
      window.BX24 = {
        init: function (cb) { cb && cb(); },
        resizeWindow: function () {},
        installFinish: function () { window.__installFinishCalled = true; },
      };
      // БРАУЗЕРНЫЕ ДИАЛОГИ ПОДМЕНЕНЫ ЗАГЛУШКОЙ, КОТОРАЯ ПАДАЕТ. В рамке портала
      // alert/confirm/prompt блокируют не только приложение, но и сам портал:
      // человек видит замерший экран и закрывает вкладку. Запрет на них есть в
      // заборе по разбору кода, но забор судит форму — здесь проверяется факт:
      // если сценарий их вызовет, прогон упадёт с внятным текстом, а не пройдёт.
      const запрет = (имя) => function () {
        const ошибка = new Error('вызван браузерный ' + имя + ' — в рамке портала он замораживает и портал');
        window.__browserDialogCalled = имя;
        throw ошибка;
      };
      window.alert = запрет('alert');
      window.confirm = запрет('confirm');
      window.prompt = запрет('prompt');
    }, { token: opts.fresh ? null : TOKEN });

    await scenario({ base, page, browser, TOKEN, check, worker, errors, appRoot, dataRoot, portalBase });

    await browser.close();
    if (failures.length) { console.log('\nПРОВАЛ: ' + failures.length); process.exit(1); }
    console.log('\nвсе проверки пройдены');
    process.exit(0);
  })().catch((e) => { console.error('сценарий упал: ' + e.message); process.exit(1); });
}

module.exports = { runLive, TOKEN };
