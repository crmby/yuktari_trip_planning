# Архитектура: расширенное приложение одного портала

PHP, vanilla JS, файловое состояние, единый защищённый handler и готовое ядро.
Модули: ACL, session, HTTP guard, OAuth/B24, pagination/batch, settings, tasks/queue,
capabilities, placements/events, журнал, уведомления, CSV и CRM-справочники; UI/frame/controls и локальные Inter fonts с лицензией.
Variable Inter сохранён побайтно как data URI в tokens.css (лицензия в www/fonts/);
неиспользуемые regular/semibold варианты не включены. Это только упаковка ассета, не изменение UI-архитектуры.
Ядро и проектная бизнес-логика разделены. Расширять app.js/style.css/template.html,
новые api/*.php и project-hooks.php; не прятать бизнес-код в security-controller или ядро.
Справочник endpoint'ов и инвентари capabilities/recipes сохранены; их original tooling
ожидает исходную документационную композицию и требует адаптации перед полным gate.

## Обязательные технические правила
- apiBoot() до первого действия, apiOk/apiFail для ответов, window.api для frontend transport.
- storeUpdate() держит чтение/запись под одним замком; LOCK_EX отдельно не заменяет транзакцию.
- Б24 через b24(); мутации: runOnce → callChecked/batchAllChecked → readBackChecked
  той же сущности с тем же снимком полей → auditLog. Удаления/действия через deleteVerified/actionVerified.
- Списки через fetchAll/fetchAllBatched, а не только первую страницу; деньги/даты через fmt.php.
- Новые endpoint'ы описывать в MAP/BLOCKS и project_extensions capabilities со своими tests/checks/companions.
- ACL перечитывается на запросе; X-App-Token для мутаций, cookie не является доказательством.
- OAuth refresh под замком; unknown outcome нельзя слепо повторять; сырые ошибки/PII не логировать.
- Shared state вне webroot и release; PHP 404-prefix/права/HTTP 404 проверяются отдельно.
- ENV — исполняемый контракт. Изменение структуры требует schema bump и регрессии; смена значений сама по себе не требует bump.
- Никаких браузерных alert/confirm вместо готового UI, никакого обхода security gate комментариями.

## Граница проверки
Исходные suites сохранены как технический материал. Полный gate зависит от внешнего стандарта,
agent settings/hooks/plugins, отсутствующих deploy-файлов и исходных docs-путей.
Исполняемый env.example.php schema 13 создан по public code/tests; bootstrap отдельно
проверен синтетически, но это не регрессия всего приложения.
Они намеренно не включены в портативный lint и не объявляются зелёными.
Портативная команда проверяет синтаксис, а не эти контракты. Перед live-выпуском нужен
отдельный перенос полного изолированного gate и интеграционная приёмка; не создавать внешнюю базу правил.
