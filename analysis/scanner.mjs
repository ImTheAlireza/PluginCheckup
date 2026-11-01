// Scan extracted plugins with PHPCS + PHPCompatibility inside a php-wasm 8.2 runtime.
//
//   node scanner.mjs <outDir> <pluginDir> [<pluginDir> ...]
//
// Requires:  npm i @php-wasm/node   (run from a directory that has node_modules)
// Env:       PHPCS_BIN / PHPCOMPAT / PHPCS_UTILS / PHPCS_EXTRA  (see phpcs_wrapper.php)
//
// One node process handles many plugins on purpose: booting the wasm runtime
// once and reusing it is far cheaper than one boot per plugin.
import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import { useHostFilesystem } from '@php-wasm/node';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const [, , outDir, ...targets] = process.argv;
if (!outDir || targets.length === 0) {
  console.error('usage: scanner.mjs <outDir> <pluginDir> [...]');
  process.exit(2);
}
fs.mkdirSync(outDir, { recursive: true });

const wrapper = path.resolve(new URL('.', import.meta.url).pathname, 'phpcs_wrapper.php');

const id = await loadNodeRuntime('8.2', {
  emscriptenOptions: { processId: Number(process.env.PHPPID || 1) },
});
const php = new PHP(id);
useHostFilesystem(php);
await php.setSapiName('cli');
// php-wasm cannot really spawn processes; answer with a silent no-op process so
// PHP code that shells out (e.g. `stty size`) never hangs the runtime.
await php.setSpawnHandler(() => spawn('/bin/true'));

for (const target of targets) {
  const name = path.basename(target);
  const report = path.join(outDir, name + '.json');
  if (fs.existsSync(report) && fs.statSync(report).size > 0) {
    console.log(`[skip] ${name}`);
    continue;
  }
  const defs = [
    ['PHPCOMPAT', 'PHPCOMPAT', '/tmp/tools/PHPCompatibility-10.0.0-alpha2'],
    ['PHPCS_UTILS', 'PHPCS_UTILS', '/tmp/tools/PHPCSUtils-1.2.3'],
    ['PHPCS_EXTRA', 'PHPCS_EXTRA', '/tmp/tools/PHPCSExtra-1.5.1'],
    ['PHPCS_BIN', 'PHPCS_BIN', '/tmp/tools/PHP_CodeSniffer-4.0.4/bin/phpcs'],
    ['PHPCS_TESTVERSION', 'PHPCS_TESTVERSION', '8.2-'],
  ].map(([c, e, d]) => `if (!defined('${c}')) define('${c}', getenv('${e}') ?: ${JSON.stringify(d)});`).join('\n');
  const code = `<?php
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
${defs}
if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w'));
$_SERVER['argv'] = array_merge(['php', ${JSON.stringify(wrapper)}, ${JSON.stringify(target)}, ${JSON.stringify(report)}]);
$GLOBALS['argv'] = $_SERVER['argv'];
require ${JSON.stringify(wrapper)};
`;
  const t = Date.now();
  try {
    await php.run({ code, throwParseError: false });
  } catch (e) {
    // phpcs exits 1/2 when it finds issues - that is normal, not a failure
    if (!fs.existsSync(report)) {
      console.log(`[error] ${name}: ${e && e.message ? e.message.split('\n')[0] : e}`);
    }
  }
  console.log(`[done] ${name} in ${((Date.now() - t) / 1000).toFixed(1)}s -> ` +
              `${fs.existsSync(report) ? fs.statSync(report).size : 0} bytes`);
}
console.log('[worker] finished');
process.exit(0);
