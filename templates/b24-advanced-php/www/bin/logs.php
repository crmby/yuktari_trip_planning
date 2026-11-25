<?php
declare(strict_types=1);
/**
 * bin/logs.php — журнал приложения для разработчика, с сервера.
 *
 * Второе окно в тот же журнал, куда пишет всё приложение (первое — экран внутри
 * приложения). Нужно, чтобы разбор «у клиента не работает» не начинался с
 * чтения сырых файлов: команда сама найдёт день, уровень и всю историю запроса
 * по коду обращения, который человек назвал.
 *
 *   php www/bin/logs.php tail              последние 40 записей за сегодня
 *   php www/bin/logs.php tail --level warn --limit 100
 *   php www/bin/logs.php errors            только ошибки за сегодня
 *   php www/bin/logs.php ref 0802-153012-ab12    вся история одного запроса
 *   php www/bin/logs.php grep текст        поиск по подстроке
 *   php www/bin/logs.php stats             файлы, размеры, счётчики, уборка
 *   php www/bin/logs.php prune --apply     удалить журналы старше срока
 *
 * С рабочей машины то же самое одной командой: bash scripts/logs.sh <подкоманда>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../api/journal.php';

$argv = $_SERVER['argv'] ?? [];
$cmd  = $argv[1] ?? '';

/** Значение именованного параметра: --level warn */
$opt = static function (string $name, ?string $default = null) use ($argv): ?string {
    $i = array_search('--' . $name, $argv, true);
    return ($i !== false && isset($argv[$i + 1])) ? (string)$argv[$i + 1] : $default;
};
$has = static fn(string $name): bool => in_array('--' . $name, $argv, true);

/** Одна запись в читаемом виде: дата, уровень, событие, подробности. */
$fmt = static function (array $r): string {
    $time = substr((string)($r['ts'] ?? ''), 11, 8);
    $lvl  = strtoupper(substr((string)($r['level'] ?? '?'), 0, 4));
    $who  = (int)($r['uid'] ?? 0) > 0 ? ' uid=' . $r['uid'] : '';
    $ms   = isset($r['ms']) ? ' ' . $r['ms'] . 'мс' : '';
    $msg  = (string)($r['msg'] ?? '');
    $ctx  = !empty($r['ctx']) ? ' ' . json_encode($r['ctx'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    return sprintf('%s %-5s %-18s %s%s%s%s', $time, $lvl, (string)($r['event'] ?? ''),
        (string)($r['ref'] ?? ''), $who, $ms, ($msg !== '' ? ' — ' . $msg : '') . $ctx);
};

$day   = $opt('date', date('Y-m-d'));
$limit = (int)($opt('limit', '40'));

switch ($cmd) {
    case 'tail':
    case 'errors':
    case 'grep':
    case 'ref':
        $filter = [
            'day'   => $day,
            'level' => $cmd === 'errors' ? 'error' : (string)$opt('level', 'debug'),
            'limit' => max(1, min(500, $limit)),
        ];
        if ($cmd === 'grep') $filter['q']   = (string)($argv[2] ?? '');
        if ($cmd === 'ref')  $filter['ref'] = (string)($argv[2] ?? '');
        if ($cmd === 'ref' && $filter['ref'] === '') {
            fwrite(STDERR, "Нужен код обращения: php www/bin/logs.php ref 0802-153012-ab12\n");
            exit(1);
        }
        // Код обращения ищем по всем дням: человек называет его, не помня даты.
        $days = $cmd === 'ref' ? journalDays() : [$day];
        $printed = 0;
        $truncated = false;
        foreach ($days as $d) {
            $filter['day'] = $d;
            $found = journalQuery($filter);
            $truncated = $truncated || !empty($found['truncated']);
            foreach (array_reverse($found['rows']) as $r) { echo $fmt($r), "\n"; $printed++; }
            if ($cmd !== 'ref' || $printed > 0) break;
        }
        if ($printed === 0) {
            echo ($truncated ? "В прочитанной части записей не найдено" : "Записей не найдено")
               . " (день $day). Есть журналы: " . implode(', ', journalDays()) . "\n";
        }
        if ($truncated) {
            fwrite(STDERR, "ВНИМАНИЕ: журнал прочитан не полностью; результат может быть неполным.\n");
        }
        break;

    case 'stats':
        $days = journalDays();
        echo "Журнал приложения\n";
        echo "  дней в журнале: " . count($days) . ($days ? ' (' . $days[count($days) - 1] . ' … ' . $days[0] . ')' : '') . "\n";
        $total = 0;
        foreach (glob(DATA_ROOT . '/' . LOG_DIR_NAME . '/*.log.php') ?: [] as $f) $total += (int)filesize($f);
        echo "  занято на диске: " . round($total / 1048576, 2) . " МБ\n";
        echo "  срок хранения: " . LOG_RETENTION_DAYS . " дней, порог записи: " . APP_LOG_LEVEL . "\n";
        $today = journalQuery(['day' => date('Y-m-d'), 'limit' => 500]);
        $byLevel = [];
        foreach ($today['rows'] as $r) {
            $l = (string)($r['level'] ?? '?');
            $byLevel[$l] = ($byLevel[$l] ?? 0) + 1;
        }
        echo "  сегодня (последние 500): " . (json_encode($byLevel, JSON_UNESCAPED_UNICODE) ?: '{}') . "\n";
        // Следы прежних журналов: если они есть, их стоит убрать руками.
        foreach ([DATA_ROOT . '/app-errors.log.php', DATA_ROOT . '/audit.php', DATA_ROOT . '/cron-logs'] as $legacy) {
            if (file_exists($legacy)) echo "  ! остался прежний журнал: $legacy\n";
        }
        break;

    case 'prune':
        if (!$has('apply')) {
            echo "Это удалит журналы старше " . LOG_RETENTION_DAYS . " дней. Повторите с --apply.\n";
            exit(0);
        }
        echo "Удалено файлов: " . journalPrune() . "\n";
        break;

    default:
        echo "Журнал приложения. Подкоманды:\n";
        echo "  tail [--date ГГГГ-ММ-ДД] [--level warn] [--limit 40]   последние записи\n";
        echo "  errors [--date ГГГГ-ММ-ДД]                             только ошибки\n";
        echo "  ref <код обращения>                                    вся история одного запроса\n";
        echo "  grep <текст> [--date ГГГГ-ММ-ДД]                        поиск по подстроке\n";
        echo "  stats                                                  объём, дни, счётчики\n";
        echo "  prune --apply                                          убрать старые журналы\n";
        break;
}
