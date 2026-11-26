export class AppError extends Error {
  constructor(status, code, message) { super(message); this.status = status; this.code = code; }
}
export function requireId(value, label = 'ID') {
  const id = String(value ?? '');
  if (!/^[1-9]\d{0,14}$/.test(id)) throw new AppError(400, 'INVALID_ID', `Некорректный ${label}.`);
  return id;
}
