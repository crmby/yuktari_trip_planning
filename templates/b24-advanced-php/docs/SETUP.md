# Настройка нового проекта (не выполнена автоматически)

Сначала скопируйте только этот комплект в отдельный проект. Не публикуйте весь репозиторий-источник.
Согласуйте назначение, роли, портал/порталы, host, HTTPS, DocumentRoot, секреты и непубличное storage.
Заполните docs/PROJECT.md и memory/state.md фактами; не переносите чужие credentials.
Отдельно подготовьте бэкап/откат и план приёмки. Коммит, платный ресурс, install и deploy не происходят сами.

## Офлайн-проверка
Из корня комплекта: `python3 tools/portable-lint.py`.
Требуется Python 3.9+ (для публикационного selftest — Python 3.9+), PHP CLI для PHP-файлов, Node.js для JS.
Запуск приложения, чтение рабочего env и внешние API этой командой не выполняются.

## PHP: расширенный комплект
Рабочий www/env.php не прочитан и не включён. `www/env.example.php` — исполняемый
каркас schema 13 по public code/tests, а не копия конфигурации прежнего сервера.
1. PHP 7.4+ по исходному контракту; проверяйте целевой поддерживаемый PHP и curl/json.
2. DocumentRoot = `www/`. Подготовьте writable private каталог 0700 вне webroot;
   при immutable release — вне release. Не используйте symlink и публичные alias.
3. Скопируйте `www/env.example.php` в локальный `www/env.php`.
   Задайте BXAPP_DATA_ROOT (или APP_DATA_ROOT), BXAPP_CLIENT_ID/BXAPP_CLIENT_SECRET
   (или B24_CLIENT_ID/B24_CLIENT_SECRET), BXAPP_APP_URL (или APP_URL): точный HTTPS /index.php.
   По умолчанию каталог `data/` рядом с `www/`; bootstrap его НЕ создаёт.
4. Можно хранить credentials в private `creds.php` 0600 с защищённым PHP-префиксом:
   они имеют приоритет над средой. Формат, лимиты и release metadata — [ENV-CONTRACT](technical/ENV-CONTRACT.md).
5. Установите minimal scopes crm/user/placement в собственной карточке приложения;
   добавочные placements/events/methods проектируйте явно в конфигурации. Обход первой установки выключен.
6. Отсутствующие обязательные значения, небезопасные пути и повреждённое private state
   останавливают bootstrap. Он не мигрирует данные и не делает внешних запросов.

Исходный full self-check НЕ заявлен переносимым: завязан на внешний стандарт, agent settings,
оригинальные docs и отсутствующие deploy/scripts. tests и безопасные tools сохранены
для адаптации; полный historical gate здесь не запускался. Изолированная проверка конфигурации
в публикационном selftest не выполняет index.php, установку или вызовы Б24.
Далее отдельная DEV-приёмка: install/upgrade, ACL и отзыв прав, sessions/renew, задачи/queue,
unknown/reconcile, durable events, uninstall, private-store HTTP 404, UI и resource limits.
Перед публичной выкладкой выполните `php tools/release-template.php <staged-www>` и проверьте
404 для template.html/template.php; неизменённый source template.html не должен публиковаться напрямую.
Внешние hooks/plugins и глобальные каталоги не устанавливаются этим комплектом.
