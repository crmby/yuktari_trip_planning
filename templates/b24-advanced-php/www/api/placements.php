<?php
declare(strict_types=1);

// ПРЯМОЙ HTTP К ЭТОМУ ФАЙЛУ — 404. Деплой публикует каталог целиком, поэтому под
// веб-корнем лежит и то, что вызывают только по require. Пустой ответ 200
// подтверждает существование файла и его путь (находка ревью 08.08.2026,
// BXREV-025). CLI и подключение из другого файла проверка не задевает: она
// смотрит, запрошен ли ИМЕННО этот файл.
if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
/**
 * Плейсменты — места в портале, где появляется приложение: пункт левого меню,
 * вкладка в карточке сделки, кнопка в списке.
 *
 * Почему это модуль, а не страница-утилита, как было раньше. Прежняя схема
 * обещала в документации «плейсменты привязываются при установке», а на деле
 * требовала открыть служебную страницу — которую в браузере открыть нельзя
 * (пропуск живёт в cookie под партицией портала и на прямой адрес не уходит).
 * Итог: вкладка не появлялась, причина ниоткуда не следовала, и владелец шёл к
 * разработчику. Теперь список плейсментов объявлен в конфиге, а привязку делает
 * само приложение — при установке и при смене адреса.
 *
 * Портал не даёт «обновить» привязку: повторный bind того же места возвращает
 * ERROR_PLACEMENT_ALREADY_BINDED. Поэтому идемпотентность делаем сами: сначала
 * bind; при точном ALREADY читаем старую привязку через placement.get, и только
 * имея снимок для rollback выполняем проверенный unbind → bind. Если новый bind
 * не принят, старую привязку восстанавливаем и проверяем результат восстановления.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/b24.php';   // класс B24 в сигнатурах ниже
require_once __DIR__ . '/journal.php';   // привязка плейсментов пишет в журнал на успешном пути

/**
 * Что объявлено в конфиге, приведённое к виду, который принимает портал.
 *
 * HANDLER подставляется сам из APP_URL: он обязан совпадать с адресом
 * приложения, и это ровно то место, где ошибались руками — при переносе
 * тест→бой в HANDLER оставался старый адрес, и вкладка открывала чужой стенд.
 */
function placementHandlerUrl(string $appUrl): string {
    return rtrim($appUrl, '/') . '/index.php';
}

function placementSpec(): array {
    $list = defined('APP_PLACEMENTS') ? APP_PLACEMENTS : [];
    if (!is_array($list) || $list === []) return [];

    // Netangels не обязан резолвить POST на корень каталога через
    // DirectoryIndex. Битрикс открывает placement именно POST-запросом, поэтому
    // обработчик всегда указывает на реальный файл, как install URL.
    $handler = placementHandlerUrl(APP_URL);
    $spec = [];
    foreach ($list as $p) {
        $code = strtoupper(trim((string)($p['code'] ?? $p['PLACEMENT'] ?? '')));
        if ($code === '') continue;
        $spec[] = [
            'PLACEMENT'   => $code,
            'HANDLER'     => $handler,
            'TITLE'       => (string)($p['title'] ?? $p['TITLE'] ?? ''),
            'DESCRIPTION' => (string)($p['description'] ?? $p['DESCRIPTION'] ?? ''),
        ];
    }
    return $spec;
}

/**
 * Портал снимает зарегистрированные встройки при обновлении приложения.
 * Локальный fingerprint после принятого ONAPPUPDATE поэтому больше ничего не
 * доказывает: убираем его, чтобы следующее открытие выполнило bind + read-back.
 */
function placementsMarkRebindRequired(): void {
    storeUpdate(STATE_FILE, static function (array $state): array {
        unset($state['placements_fingerprint'], $state['placements_bound_at']);
        return $state;
    });
}

/** Отпечаток объявленного: сменился список или адрес приложения — сменится он. */
function placementFingerprint(): string {
    return md5((string)json_encode(placementSpec()));
}

/** Точный код «уже привязано»: по подстроке нельзя классифицировать REST-ошибки. */
function placementAlreadyBound(array $response): bool {
    return strtoupper((string)($response['error'] ?? '')) === 'ERROR_PLACEMENT_ALREADY_BINDED';
}

/** Человеческая причина REST-отказа; пустая строка означает успешный ответ. */
function placementResponseError(array $response, string $method): string {
    if (isset($response['error'])) {
        return (string)($response['error_description'] ?? $response['error']);
    }
    if (!array_key_exists('result', $response)) return $method . ': портал не вернул result';

    if ($method === 'placement.bind' && $response['result'] !== true) {
        return $method . ': портал не подтвердил привязку';
    }
    if ($method === 'placement.unbind') {
        $result = $response['result'];
        $count = is_array($result) ? (int)($result['count'] ?? 0) : ($result === true ? 1 : 0);
        if ($count <= 0) return $method . ': портал не подтвердил снятие старой привязки';
    }
    return '';
}

/** Зарегистрированные обработчики (не placement.list: тот отдаёт лишь доступные коды). */
function placementRegisteredUsing(callable $call): array {
    $response = $call('placement.get', []);
    $error = placementResponseError(is_array($response) ? $response : [], 'placement.get');
    if ($error !== '') throw new RuntimeException($error);
    $rows = $response['result'];
    if (!is_array($rows)) throw new RuntimeException('placement.get: result не является списком');
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('placement.get: портал вернул повреждённую строку');
    }
    return array_values($rows);
}

/** Строка placement.get → payload placement.bind, достаточный для rollback. */
function placementRowToBind(array $row): array {
    $spec = [
        'PLACEMENT'   => strtoupper((string)($row['placement'] ?? $row['PLACEMENT'] ?? '')),
        'HANDLER'     => (string)($row['handler'] ?? $row['HANDLER'] ?? ''),
        'TITLE'       => (string)($row['title'] ?? $row['TITLE'] ?? ''),
        'DESCRIPTION' => (string)($row['description'] ?? $row['DESCRIPTION'] ?? ''),
    ];
    $userId = (int)($row['userId'] ?? $row['USER_ID'] ?? 0);
    if ($userId > 0) $spec['USER_ID'] = $userId;
    $options = $row['options'] ?? $row['OPTIONS'] ?? null;
    if (is_array($options) && $options !== []) $spec['OPTIONS'] = $options;
    $langAll = $row['langAll'] ?? $row['LANG_ALL'] ?? null;
    if (is_array($langAll) && $langAll !== []) $spec['LANG_ALL'] = $langAll;
    $groupName = (string)($row['groupName'] ?? $row['GROUP_NAME'] ?? '');
    if ($groupName !== '') $spec['GROUP_NAME'] = $groupName;
    return $spec;
}

/** Рекурсивно убрать незначимую разницу порядка ключей в REST-объектах. */
function placementCanonicalValue($value) {
    if (!is_array($value) || $value === []) return $value;
    $out = [];
    foreach ($value as $key => $item) $out[$key] = placementCanonicalValue($item);
    $keys = array_keys($out);
    if ($keys !== range(0, count($out) - 1)) ksort($out, SORT_STRING);
    return $out;
}

function placementRowMatches(array $row, array $spec, bool $full = false): bool {
    $old = placementRowToBind($row);
    if (($old['PLACEMENT'] ?? '') !== ($spec['PLACEMENT'] ?? '')
        || ($old['HANDLER'] ?? '') !== ($spec['HANDLER'] ?? '')) return false;
    if (!$full) return true;
    return ($old['TITLE'] ?? '') === ($spec['TITLE'] ?? '')
        && ($old['DESCRIPTION'] ?? '') === ($spec['DESCRIPTION'] ?? '')
        // placement.get может дополнить ответ вычисленными LANG_ALL/OPTIONS,
        // которых bind не задавал. Сверяем optional-поля, только когда они были
        // частью ожидаемого payload. Для rollback они входят в $old, потому что
        // $old собран из полного снимка placement.get, и проверка остаётся точной.
        && (!array_key_exists('GROUP_NAME', $spec)
            || ($old['GROUP_NAME'] ?? '') === $spec['GROUP_NAME'])
        && (!array_key_exists('USER_ID', $spec)
            || (int)($old['USER_ID'] ?? 0) === (int)$spec['USER_ID'])
        && (!array_key_exists('OPTIONS', $spec)
            || placementCanonicalValue($old['OPTIONS'] ?? []) === placementCanonicalValue($spec['OPTIONS']))
        && (!array_key_exists('LANG_ALL', $spec)
            || placementCanonicalValue($old['LANG_ALL'] ?? []) === placementCanonicalValue($spec['LANG_ALL']));
}

/** Проверить реальное состояние портала после записи. */
function placementExistsUsing(callable $call, array $spec, bool $full = true): bool {
    foreach (placementRegisteredUsing($call) as $row) {
        if (placementRowMatches($row, $spec, $full)) return true;
    }
    return false;
}

/**
 * Восстановить старую привязку и проверить её через placement.get.
 * Один ALREADY ничего не доказывает: занято может быть уже другим handler.
 */
function placementRestoreUsing(callable $call, array $old): string {
    try {
        $response = $call('placement.bind', $old);
        if (!is_array($response)) $response = [];
        if (!placementAlreadyBound($response)) {
            $error = placementResponseError($response, 'placement.bind');
            if ($error !== '') return 'rollback: ' . $error;
        }
        if (!placementExistsUsing($call, $old, true)) {
            return 'rollback: placement.get не подтвердил точную старую привязку';
        }
        return '';
    } catch (Throwable $e) {
        return 'rollback: ' . (string)maskSecrets($e->getMessage());
    }
}

/**
 * Один безопасный bind. Вынесен на callable, чтобы аварийный порядок и rollback
 * проверялись детерминированно, без обращения к настоящему порталу.
 *
 * @return array{ok:bool,changed:bool,error:string}
 */
function placementBindOneUsing(callable $call, array $spec): array {
    try {
        $response = $call('placement.bind', $spec);
    } catch (Throwable $e) {
        return ['ok' => false, 'changed' => false, 'error' => (string)maskSecrets($e->getMessage())];
    }
    if (!is_array($response)) $response = [];
    if (!placementAlreadyBound($response)) {
        $error = placementResponseError($response, 'placement.bind');
        if ($error !== '') return ['ok' => false, 'changed' => false, 'error' => $error];
        try {
            if (!placementExistsUsing($call, $spec, true)) {
                return ['ok' => false, 'changed' => true,
                    'error' => 'placement.bind принят, но placement.get не подтвердил новую привязку'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'changed' => true,
                'error' => 'placement.bind принят, но read-back не удался: '
                    . (string)maskSecrets($e->getMessage())];
        }
        return ['ok' => true, 'changed' => true, 'error' => ''];
    }

    // До снятия обязательно получаем полный снимок старого обработчика. Если
    // портал недоступен или выбор неоднозначен, ничего разрушительного не делаем.
    try {
        $rows = placementRegisteredUsing($call);
    } catch (Throwable $e) {
        return ['ok' => false, 'changed' => false,
            'error' => 'старую привязку не удалось прочитать; она оставлена как есть: '
                . (string)maskSecrets($e->getMessage())];
    }
    foreach ($rows as $row) {
        if (placementRowMatches($row, $spec, true)) {
            return ['ok' => true, 'changed' => false, 'error' => ''];
        }
    }

    $sameCode = array_values(array_filter($rows, static function (array $row) use ($spec): bool {
        return (placementRowToBind($row)['PLACEMENT'] ?? '') === ($spec['PLACEMENT'] ?? '');
    }));
    $sameHandler = array_values(array_filter($sameCode, static function (array $row) use ($spec): bool {
        return (placementRowToBind($row)['HANDLER'] ?? '') === ($spec['HANDLER'] ?? '');
    }));
    // unbind умеет фильтровать только по placement+handler и удалит сразу все
    // совпадения. При дублях либо нескольких старых handler нельзя выбрать
    // «тот самый» без риска — оставляем всё на месте и просим ручной разбор.
    if (count($sameHandler) === 1) $oldRow = $sameHandler[0];
    elseif ($sameHandler === [] && count($sameCode) === 1) $oldRow = $sameCode[0];
    else {
        return ['ok' => false, 'changed' => false,
            'error' => 'старая привязка неоднозначна; автоматический unbind отменён'];
    }
    $old = placementRowToBind($oldRow);

    $unbind = ['PLACEMENT' => $old['PLACEMENT'], 'HANDLER' => $old['HANDLER']];
    if (!empty($old['USER_ID'])) $unbind['USER_ID'] = $old['USER_ID'];
    try {
        $unbound = $call('placement.unbind', $unbind);
        $unbindError = placementResponseError(is_array($unbound) ? $unbound : [], 'placement.unbind');
    } catch (Throwable $e) {
        $unbindError = 'placement.unbind: неизвестный исход — ' . (string)maskSecrets($e->getMessage());
    }
    if ($unbindError !== '') {
        // При потерянном ответе unbind мог всё же выполниться. Read-back отличает
        // «старое на месте» от «надо восстановить»; если read-back тоже упал,
        // безопаснее всё равно попробовать идемпотентный rollback.
        try {
            foreach (placementRegisteredUsing($call) as $row) {
                if (placementRowMatches($row, $old, false)) {
                    return ['ok' => false, 'changed' => false, 'error' => $unbindError . '; старая привязка сохранена'];
                }
            }
        } catch (Throwable $e) {
            // Портал не ответил на сверку — идём восстанавливать старую привязку,
            // но след обязателен: молча проглоченный отказ здесь читается как
            // «сверка прошла и ничего не нашла», а это разные вещи.
            logAppError('placements: сверка привязок не удалась: ' . maskSecrets($e->getMessage()));
        }
        $rollbackError = placementRestoreUsing($call, $old);
        return ['ok' => false, 'changed' => false, 'error' => $unbindError
            . ($rollbackError === '' ? '; старая привязка восстановлена' : '; не удался rollback: ' . $rollbackError)];
    }

    try {
        $bound = $call('placement.bind', $spec);
        $bindError = placementResponseError(is_array($bound) ? $bound : [], 'placement.bind');
    } catch (Throwable $e) {
        $bindError = 'placement.bind: неизвестный исход — ' . (string)maskSecrets($e->getMessage());
    }
    if ($bindError === '') {
        try {
            if (placementExistsUsing($call, $spec, true)) {
                return ['ok' => true, 'changed' => true, 'error' => ''];
            }
            $bindError = 'placement.bind принят, но placement.get не подтвердил новую привязку';
        } catch (Throwable $e) {
            // Ответ bind успешный, поэтому разрушительный rollback при одном
            // только сбое read-back опаснее: новая привязка, вероятнее всего,
            // уже работает. Не трогаем её, но и успех без проверки не заявляем.
            return ['ok' => false, 'changed' => true,
                'error' => 'placement.bind принят, но read-back не удался: '
                    . (string)maskSecrets($e->getMessage())];
        }
    }

    // Новый bind мог выполниться, а ответ потеряться. Если read-back видит его,
    // повтор/rollback не нужен. Иначе восстанавливаем точный старый payload.
    try {
        foreach (placementRegisteredUsing($call) as $row) {
            if (placementRowMatches($row, $spec, true)) {
                return ['ok' => true, 'changed' => true, 'error' => ''];
            }
        }
    } catch (Throwable $e) {
        // Тот же случай на другом краю: read-back не состоялся, значит считаем
        // привязку неподтверждённой и откатываемся — но с записью причины.
        logAppError('placements: read-back привязки не удался: ' . maskSecrets($e->getMessage()));
    }
    $rollbackError = placementRestoreUsing($call, $old);
    return ['ok' => false, 'changed' => false, 'error' => $bindError
        . ($rollbackError === '' ? '; старая привязка восстановлена' : '; не удался rollback: ' . $rollbackError)];
}

/**
 * Привязать объявленные плейсменты. Дёшево звать на каждом открытии: пока
 * список и адрес не менялись, функция ничего не делает и в портал не ходит.
 *
 * Ошибки НЕ пробрасываются наверх намеренно: приложение должно открыться, даже
 * если портал сейчас не в духе, — иначе один сбой placement.bind закрывает
 * людям весь инструмент. Причина уходит в журнал и видна на экране состояния.
 *
 * @param bool $force Перепривязать, даже если ничего не менялось (кнопка
 *                    «Пересобрать» — когда человек удалил пункт меню руками).
 * @return array{changed:bool, bound:array<string,bool>, errors:array<string,string>}
 */
function bindPlacements(B24 $b24, bool $force = false): array {
    $spec = placementSpec();
    $out  = ['changed' => false, 'bound' => [], 'errors' => []];
    if ($spec === []) return $out;

    $fingerprint = placementFingerprint();
    $state = storeRead(STATE_FILE) ?? [];
    if (!$force && ($state['placements_fingerprint'] ?? '') === $fingerprint) return $out;

    foreach ($spec as $p) {
        $code = $p['PLACEMENT'];
        try {
            $one = placementBindOneUsing(static fn(string $method, array $params): array => $b24->call($method, $params), $p);
            if ($one['changed']) $out['changed'] = true;
            if (!$one['ok']) {
                $out['errors'][$code] = (string)maskSecrets($one['error']);
                $out['bound'][$code] = false;
            } else {
                $out['bound'][$code] = true;
            }
        } catch (Throwable $e) {
            $out['errors'][$code] = (string)maskSecrets($e->getMessage());
            $out['bound'][$code] = false;
        }
    }

    // Отпечаток запоминаем только при полном успехе: иначе неудачная привязка
    // «зафиксируется» и повторной попытки не будет никогда.
    if ($out['errors'] === []) {
        storeUpdate(STATE_FILE, static function (array $s) use ($fingerprint): array {
            $s['placements_fingerprint'] = $fingerprint;
            $s['placements_bound_at']    = date('c');
            return $s;
        });
    } else {
        logAppError('placements: не привязались — ' . json_encode($out['errors'], JSON_UNESCAPED_UNICODE));
    }
    return $out;
}

/**
 * Что портал считает привязанным сейчас — для экрана состояния.
 *
 * Сравнение «объявлено против привязано» показывает ровно ту поломку, которую
 * иначе никто не замечает: приложение работает, а вкладки в карточке нет.
 */
function placementsActualUsing(callable $call): array {
    $out = [];
    foreach (placementRegisteredUsing($call) as $r) {
        $code = (string)($r['placement'] ?? $r['PLACEMENT'] ?? '');
        if ($code !== '') $out[$code] = (string)($r['handler'] ?? $r['HANDLER'] ?? '');
    }
    return $out;
}

function placementsActual(B24 $b24): array {
    return placementsActualUsing(static fn(string $method, array $params): array => $b24->call($method, $params));
}
