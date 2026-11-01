<?php
/**
 * PHPCS + PHPCompatibility wrapper used inside the php-wasm 8.2 runtime.
 *
 * Called by scanner.mjs as:  php phpcs_wrapper.php <targetDir> <reportFile|stdout>
 * Configuration comes from environment variables (set by scanner.mjs):
 *   PHPCS_BIN          path to bin/phpcs
 *   PHPCS_UTILS        path to PHPCSUtils checkout
 *   PHPCS_EXTRA        path to PHPCSExtra checkout
 *   PHPCOMPAT          path to PHPCompatibility checkout
 *   PHPCS_TESTVERSION  e.g. 8.2-
 */
ini_set('memory_limit', '1536M');

$target = $GLOBALS['argv'][2] ?? '';
$report = $GLOBALS['argv'][3] ?? '';

// values are injected as PHP constants by scanner.mjs (the wasm runtime does not
// inherit the host process environment); getenv() is only a fallback.
function cfg(string $const, string $env, string $default = '')
{
    if (defined($const)) {
        return (string) constant($const);
    }
    $v = getenv($env);
    return ($v === false || $v === '') ? $default : $v;
}

$paths = array_filter([
    cfg('PHPCOMPAT', 'PHPCOMPAT'),
    cfg('PHPCS_UTILS', 'PHPCS_UTILS'),
    cfg('PHPCS_EXTRA', 'PHPCS_EXTRA'),
]);

$args = [
    'phpcs', '-q',
    '--standard=PHPCompatibility',
    '--runtime-set', 'installed_paths', implode(',', $paths),
    '--runtime-set', 'testVersion', cfg('PHPCS_TESTVERSION', 'PHPCS_TESTVERSION', '8.2-'),
    '--extensions=php',
    '--report-width=200',
    '--report=json',
    '--ignore=*/node_modules/*',
];
if ($report && $report !== 'stdout') {
    $args[] = '--report-file=' . $report;
}
$args[] = $target;

$_SERVER['argv'] = $args;
$GLOBALS['argv'] = $args;
require cfg('PHPCS_BIN', 'PHPCS_BIN', '/tmp/tools/PHP_CodeSniffer-4.0.4/bin/phpcs');
