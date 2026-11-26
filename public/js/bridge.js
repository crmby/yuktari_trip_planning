async function request(path, body, background = false) {
  let response;
  try {
    response = await fetch('/api' + path, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(25_000),
      ...(background ? {headers:{'X-Planner-Async':'1'}} : {}),
      ...(body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'yuktari-planner' }, body: JSON.stringify(body) }),
    });
  } catch { throw Object.assign(new Error('Нет ответа сервера приложения. Если выполнялось сохранение, сначала проверьте его результат.'), { code: 'APP_UNAVAILABLE' }); }
  let payload;
  try { payload = await response.json(); } catch { throw Object.assign(new Error('Сервер вернул неожиданный ответ. Проверьте результат сохранения.'), {code:'APP_UNAVAILABLE'}); }
  if (!response.ok || payload.success === false) {
    const code = payload.error?.code || (typeof payload.error === 'string' && /^[A-Z0-9_]{1,80}$/.test(payload.error) ? payload.error : '');
    const fallback = response.status === 401 ? 'Нужно заново войти или выдать приложению доступ. Закройте приложение и откройте заново из меню Битрикс24.' : 'Не удалось загрузить данные Битрикс24.';
    throw Object.assign(new Error(payload.error?.message || fallback + (code ? ` Код: ${code}.` : '')), { code, status: response.status });
  }
  if (response.status === 202 && /^[a-f0-9-]{36}$/.test(payload.jobId || '')) return {pendingJob:payload.jobId};
  return payload.data ?? payload;
}

export async function api(path, body) {
  let result = await request(path,body,body === undefined);
  const deadline = Date.now()+610_000;
  while (result?.pendingJob) {
    if (Date.now() > deadline) throw Object.assign(new Error('Загрузка занимает слишком много времени. Повторите загрузку данных.'),{code:'READ_TIMEOUT'});
    await new Promise(resolve => setTimeout(resolve,1000));
    result = await request('/read-jobs/'+result.pendingJob);
  }
  return result;
}

export async function runtime() {
  try {
    const info = await api('/runtime');
    if (info.mode === 'demo') return info;
    try { return { ...await api('/bootstrap'), version:info.version }; } catch (error) { return { mode: 'bitrix', version:info.version, error: error.message, errorCode:error.code, canManage: false }; }
  } catch (error) { return { mode: 'bitrix', error: error.message, canManage: false }; }
}
