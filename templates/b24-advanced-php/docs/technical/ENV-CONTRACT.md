# Исполняемый ENV-контракт schema 13

[www/env.example.php](../../www/env.example.php) — самостоятельный безопасный конфигурационный
каркас по разрешённым public code/tests, не копия рабочего env. Список обязательных define:
[tests/env-contract.txt](../../tests/env-contract.txt). Имена и schema 13 сохранены;
STATE_MAX_BYTES и B24_CREDS_MAX_BYTES — отдельные const, как требует bounded-state контракт.

## Настройка и приоритеты
- DocumentRoot только `www/`. Создайте каталог private state от имени владельца PHP, 0700,
  вне всех публичных DocumentRoot/alias. Bootstrap не создаёт каталоги и не меняет права.
- DATA_ROOT: BXAPP_RELEASE_DATA_ROOT из доверенного `www/release.php`, затем BXAPP_DATA_ROOT,
  затем APP_DATA_ROOT, затем `data/` рядом с `www/`. Все пути абсолютные, canonical, без symlink
  в любом компоненте. В immutable release state должен быть вне release.
- B24_CREDS_FILE: BXAPP_CREDS_FILE или DATA_ROOT/creds.php. Только `.php` непосредственно
  внутри DATA_ROOT. Поля `client_id`, `client_secret` имеют приоритет над переменными среды.
  Если private credentials отсутствуют: BXAPP_CLIENT_ID/BXAPP_CLIENT_SECRET, затем
  B24_CLIENT_ID/B24_CLIENT_SECRET. Оба значения обязательны; значения ошибок не содержат секретов.
- APP_URL_OVERRIDE: BXAPP_APP_URL, затем APP_URL. Если они пусты — сохранённый `app_url`
  из bounded state.php. Не используется Host, X-Forwarded-Host, Referer или request DOMAIN.
  Принимается только фиксированный HTTPS handler `/index.php` (корень домена также разрешён),
  без userinfo/query/fragment и нестандартного порта. URL нового проекта задайте явно.
- B24_OAUTH_URL фиксирован по public b24.php: `https://oauth.bitrix24.tech/oauth/token/`.
  Переопределение endpoint для тестов делается отдельным изолированным harness, не в production env.
  B24_WEBHOOK_URL пустой; credentials OAuth обязательны в базовом профиле.
- APP_SCOPES = crm/user/placement; APP_METHODS_REQUIRED = user.current/app.info;
  optional methods, placements и business events пусты до проектирования. Это не обход
  binding/lifecycle: runtime modules сохранены без изменений и выполняют собственные guards.
- UF_PREFIX = APP, APP_LOG_LEVEL = info, LOG_RETENTION_DAYS = 14, INBOX_MAX = 1000.
  UNVERIFIED_FIRST_INSTALL по умолчанию пустой массив, никогда не boolean bypass.
  Специальный ограниченный билет может задаваться оператором только после изучения
  installUnverifiedTicketCheck; публичный пример не включает билетов или тестовых исключений.

## Private-state и ошибки
Все state-константы указывают на разные `.php` в DATA_ROOT. Чтение creds/state — как данных,
никогда через require. До JSON decode проверяются lstat/обычный файл/одна hardlink/0600,
затем размер и bounded read max+1: credentials 64 KiB, state 2 MiB. Размер включает PHP-префикс.
Поддерживаются ровно два префикса: текущий 404+exit и legacy rollback bridge из store.php.
Новые файлы пишет только store.php своим текущим 404-префиксом; env не переопределяет его.
Некорректный JSON, открытый JSON без префикса, ссылки, непубличные права и oversized
не превращаются в пустое состояние. Bootstrap ничего не исправляет/не перемещает/не выполняет.
StateFileLimitException: 1701 oversized read, 1702 oversized write (store.php),
1703 invalid/config, 1704 unreadable, 1705 corruption. Сообщения не выводят входные значения.
Для точного api/health.php возможен только диагностический режим повреждённого state:
APP_URL пустой, категория в __bxapp_state_bootstrap; исходный apiBoot всё равно требует admin.
Ошибка credentials/путей не получает такого исключения. Сам env при прямом HTTP даёт 404/no-store.

## Release и границы
Опциональный `www/release.php` — доверенный operator-owned PHP metadata (не private state).
Он может объявлять APP_RELEASE, BXAPP_RELEASE_DATA_ROOT и BXAPP_RELEASE_WRITE_LEGACY;
последний понимает исходный dual-reader/writer store.php. Symlink release.php запрещён.
Каркас не создаёт release, не выполняет deploy, migrations, сетевые запросы, cron или install.
Не переносите старые данные под новыми filenames без отдельной проверки и бэкапа.
Публичный template.html — исходник; перед внешним выпуском преобразуйте его штатным
[release-template.php](../../tools/release-template.php) в защищённый template.php на staging-копии.

## Что проверено и что нет
`scripts/b24-template-kits-selftest.py` в исходном публикационном репозитории проверяет
сгенерированный config в отдельных PHP `-n` процессах, с synthetic env и временными каталогами:
полный набор define, успешный bootstrap, private credentials, state/legacy, limit-1/exact/+1,
ошибки, symlinks/permissions, release metadata, неизменность state. Оригинальные
`gateEnvEffectLines` / `gateEnvPrivateStateReaderContract` не ослаблены и также проверяются.
Single-PHP конфигурация и Marketplace config array также загружаются отдельно без приложения.
Это НЕ исполнение index.php, ACL/lifecycle suites, HTTP-приёмка или вызовы живого Б24.
Исторический full gate связан с внешними стандартами, deploy scripts, исходной документацией
и историей контракта; его переносимость не заявлена, отсутствующие компоненты не подменены.
Остались целевой PHP/runtime, Web-server/alias 404-проверки, install/reinstall/upgrade,
portal fence, ACL/renew/queue/tasks/uninstall и live acceptance на отдельном тестовом портале.
