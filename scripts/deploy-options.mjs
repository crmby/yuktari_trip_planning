export function deploymentArguments(args) {
  let keySource = 'api', dryRun = false;
  for (const arg of args) {
    if (arg === '--dry-run') dryRun = true;
    else if (arg === '--key-source=api') keySource = 'api';
    else if (arg === '--key-source=app') keySource = 'app';
    else throw new Error('Допустимые параметры: --dry-run, --key-source=api или --key-source=app.');
  }
  if (args.includes('--key-source=api') && args.includes('--key-source=app')) throw new Error('Допустим только один источник ключа доставки.');
  return { keySource, dryRun };
}
export function selectDeploymentKey(env, keySource = 'api') {
  const variable = keySource === 'app' ? 'VIBE_APP_KEY' : keySource === 'api' ? 'VIBE_API_KEY' : null;
  if (!variable) throw new Error('Заполните корректный источник ключа доставки.');
  const key = env[variable];
  if (!key || (keySource === 'app' && !/^vibe_app_[A-Za-z0-9_-]+$/.test(key))) throw new Error(`Заполните ${variable} для явно выбранного источника доставки. Другой ключ автоматически не используется.`);
  return { key, variable };
}

export function needsCatalogChoice(server) {
  return server.showInBitrix24 === 'NOT_CHOSEN' && server.b24CatalogSync?.itemId === null;
}
export function catalogSummary(server) {
  const sync = server.b24CatalogSync;
  if (!sync) return 'Показ в Битрикс24: состояние недоступно текущему ключу.';
  if (sync.status === 'FAILED' || sync.status === 'ORPHANED') return 'Показ в Битрикс24: синхронизация требует проверки в кабинете.';
  if (sync.pendingOp || sync.status === 'PENDING') return 'Показ в Битрикс24: действие ещё в очереди.';
  if (sync.itemId != null) return 'Показ в Битрикс24: приложение показывается.';
  if (server.showInBitrix24 === 'NO') return 'Показ в Битрикс24: приложение скрыто.';
  if (server.showInBitrix24 === 'YES') return 'Показ в Битрикс24: показ выбран, создание карточки ещё не подтверждено.';
  return 'Показ в Битрикс24: карточки нет, выбор не задан.';
}
