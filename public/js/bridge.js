export async function api(path, body) {
  let response;
  try {
    response = await fetch('/api' + path, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(25_000),
      ...(body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'yuktari-planner' }, body: JSON.stringify(body) }),
    });
  } catch { throw Object.assign(new Error('Нет ответа сервера приложения. Если выполнялось сохранение, сначала проверьте его результат.'), { code: 'APP_UNAVAILABLE' }); }
  let payload;
  try { payload = await response.json(); } catch { throw Object.assign(new Error('Сервер вернул неожиданный ответ. Проверьте результат сохранения.'), {code:'APP_UNAVAILABLE'}); }
  if (!response.ok || payload.success === false) throw Object.assign(new Error(payload.error?.message || 'Не удалось загрузить данные Битрикс24.'), { code: payload.error?.code, status: response.status });
  return payload.data ?? payload;
}

export async function runtime() {
  try {
    const info = await api('/runtime');
    if (info.mode === 'demo') return info;
    try { return await api('/bootstrap'); } catch (error) { return { mode: 'bitrix', error: error.message, canManage: false }; }
  } catch (error) { return { mode: 'bitrix', error: error.message, canManage: false }; }
}
