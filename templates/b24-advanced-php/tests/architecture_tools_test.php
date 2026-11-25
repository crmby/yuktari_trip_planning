<?php
declare(strict_types=1);
// R08-ARCH: запускается отдельно или автоматически из общего раннера.
$architectureStandalone = !function_exists('check');
$architectureFailures = 0;
$architectureCheck = static function (string $name, bool $ok, string $detail = '') use ($architectureStandalone, &$architectureFailures): void {
    if (!$architectureStandalone) { check($name, $ok, $detail); return; }
    if (!$ok) $architectureFailures++;
    fwrite(STDERR, ($ok ? 'ok ' : 'СБОЙ ') . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL);
};
$architectureRoot = dirname(__DIR__);
$architectureTmp = sys_get_temp_dir() . '/bxapp-architecture-' . bin2hex(random_bytes(6));
$architectureWipe = static function (string $dir) use (&$architectureWipe): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) $architectureWipe($path); else unlink($path);
    }
    rmdir($dir);
};
register_shutdown_function(static function () use ($architectureTmp, $architectureWipe): void { $architectureWipe($architectureTmp); });
if (function_exists('testRegisterTemp')) testRegisterTemp($architectureTmp);
try {
    foreach (['www', 'tools', 'tests'] as $dir) mkdir($architectureTmp . '/' . $dir, 0700, true);
    foreach (['www/env.php', 'tools/env-contract-bump.php', 'tools/env-define-names.php',
              'tests/env-contract-history.txt', 'tests/env-contract.txt'] as $file) {
        if (!copy($architectureRoot . '/' . $file, $architectureTmp . '/' . $file)) throw new RuntimeException('не скопировать fixture');
    }
    $oldContract = (string)file_get_contents($architectureTmp . '/tests/env-contract.txt');
    $env = (string)file_get_contents($architectureTmp . '/www/env.php');
    $env = preg_replace_callback("/define\('BXAPP_ENV_SCHEMA',\s*(\d+)\)/", static function (array $m): string {
        return "define('BXAPP_ENV_SCHEMA', " . ((int)$m[1] + 1) . ")";
    }, $env, 1, $replaced);
    if ($replaced !== 1) throw new RuntimeException('fixture не нашёл schema');
    file_put_contents($architectureTmp . '/www/env.php', $env . "\ndefine('BXAPP_ARCH_RECOVERY_SETTING', 'x');\n");
    $run = static function () use ($architectureTmp): array {
        $out = []; $rc = -1;
        exec('cd ' . escapeshellarg($architectureTmp) . ' && ' . escapeshellarg(PHP_BINARY)
             . ' tools/env-contract-bump.php 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    };
    $historyBefore = (string)file_get_contents($architectureTmp . '/tests/env-contract-history.txt');
    unlink($architectureTmp . '/tests/env-contract.txt');
    mkdir($architectureTmp . '/tests/env-contract.txt', 0700);
    [$firstRc] = $run();
    $historyPartial = (string)file_get_contents($architectureTmp . '/tests/env-contract-history.txt');
    $architectureCheck('env recovery: сбой второй публикации воспроизведён', $firstRc !== 0 && $historyPartial !== $historyBefore);
    [$stillBrokenRc] = $run();
    $architectureCheck('env recovery: неустранённая причина снова красная без лишней истории', $stillBrokenRc !== 0
        && file_get_contents($architectureTmp . '/tests/env-contract-history.txt') === $historyPartial);
    rmdir($architectureTmp . '/tests/env-contract.txt');
    file_put_contents($architectureTmp . '/tests/env-contract.txt', $oldContract);
    [$retryRc] = $run();
    $contractRecovered = (string)file_get_contents($architectureTmp . '/tests/env-contract.txt');
    $architectureCheck('env recovery: повтор восстанавливает эталон после частичной публикации',
        $retryRc === 0 && strpos($contractRecovered, 'BXAPP_ARCH_RECOVERY_SETTING') !== false);
    $architectureCheck('env recovery: повтор не дописывает историю второй раз',
        file_get_contents($architectureTmp . '/tests/env-contract-history.txt') === $historyPartial);
    [$normalRc] = $run();
    $architectureCheck('env recovery: исправная пара остаётся неизменной при повторе', $normalRc === 0
        && file_get_contents($architectureTmp . '/tests/env-contract.txt') === $contractRecovered
        && file_get_contents($architectureTmp . '/tests/env-contract-history.txt') === $historyPartial);
    unlink($architectureTmp . '/tests/env-contract.txt');
    [$missingRc] = $run();
    $architectureCheck('env recovery: отсутствующий эталон восстанавливается без новой версии', $missingRc === 0
        && is_file($architectureTmp . '/tests/env-contract.txt')
        && file_get_contents($architectureTmp . '/tests/env-contract.txt') === $contractRecovered);
    file_put_contents($architectureTmp . '/www/env.php', "\ndefine('BXAPP_ARCH_NO_SCHEMA_BUMP', 'x');\n", FILE_APPEND);
    [$rejectRc] = $run();
    $architectureCheck('env recovery: смена имён без schema по-прежнему отвергается', $rejectRc !== 0
        && file_get_contents($architectureTmp . '/tests/env-contract-history.txt') === $historyPartial);
} finally { $architectureWipe($architectureTmp); }
$loaderOut = []; $loaderRc = -1;
exec('node ' . escapeshellarg(__DIR__ . '/playwright-loader-test.js') . ' 2>&1', $loaderOut, $loaderRc);
$architectureCheck('живые сценарии используют общий переносимый resolver Playwright', $loaderRc === 0, implode(' | ', array_slice($loaderOut, -8)));
if ($architectureStandalone) exit($architectureFailures === 0 ? 0 : 1);
