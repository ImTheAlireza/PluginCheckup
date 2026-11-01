// Runs a PHP script inside the php-wasm 8.2 runtime with the host filesystem mounted.
// Usage: node runphp.mjs <script.php> [arg1 arg2 ...]
import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import { useHostFilesystem } from '@php-wasm/node';
import { spawn } from 'node:child_process';

const [, , script, ...args] = process.argv;
if (!script) {
  console.error('usage: runphp.mjs <script.php> [args...]');
  process.exit(2);
}

const pid = Number(process.env.PHPPID || 1);
const t0 = Date.now();
const id = await loadNodeRuntime('8.2', {
  emscriptenOptions: { processId: pid },
});
const php = new PHP(id);
useHostFilesystem(php);
await php.setSapiName('cli');
// php-wasm cannot really spawn processes; answer with a silent no-op process
// so PHP code that shells out (e.g. `stty size`) never hangs the runtime.
await php.setSpawnHandler(() => spawn('/bin/true'));

const code = `<?php
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w'));
$_SERVER['argv'] = array_merge(['php', ${JSON.stringify(script)}], ${JSON.stringify(args)});
$_SERVER['argc'] = count($_SERVER['argv']);
$GLOBALS['argv'] = $_SERVER['argv'];
$GLOBALS['argc'] = $_SERVER['argc'];
require ${JSON.stringify(script)};
`;

let exitCode = 0;
try {
  const res = await php.run({ code, throwParseError: false });
  process.stdout.write(res.text || '');
  if (res.errors) process.stderr.write(res.errors + '\n');
  exitCode = res.exitCode ?? 0;
} catch (e) {
  process.stderr.write('RUN ERROR: ' + (e && e.message ? e.message : String(e)) + '\n');
  exitCode = 1;
}
process.stderr.write(`[runner] elapsed ${((Date.now() - t0) / 1000).toFixed(1)}s phpExit=${exitCode}\n`);
process.exit(exitCode);
