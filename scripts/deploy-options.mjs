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
