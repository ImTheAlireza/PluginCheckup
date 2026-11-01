// Real runtime smoke test: install every plugin into a live WordPress 6.9.11
// running on PHP 8.2 (php-wasm) and see what actually breaks.
//
//   node run-tests.mjs [--root /tmp/wp/wordpress] [--only slug,slug] [--out file.json]
//
// Per plugin: resolve its "Requires Plugins" dependencies -> copy it in ->
// activate -> force it into active_plugins (the real-life state when a site is
// already running) -> request the front page, dashboard, plugin list, WooCommerce
// shipping settings and the plugin's own admin pages -> read debug.log ->
// deactivate -> remove.
//
// Verdicts:
//   ENCODED   the file is ionCube/SourceGuardian encoded and the loader is missing
//   CRASH     PHP fatal/parse error or a WSOD ("critical error") on any page
//   WARNINGS  runs, but PHP warnings/deprecations were emitted
//   OK        runs clean
//   NO_ACT    WordPress refused to activate it (dependency missing etc.)
import { createHandler, request, login, PHPV } from './wp.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';

const argv = process.argv.slice(2);
const arg = (name, def) => {
  const i = argv.indexOf('--' + name);
  return i >= 0 ? argv[i + 1] : def;
};
const WP_ROOT = arg('root', process.env.WP_ROOT || '/tmp/wp/wordpress');
const ONLY = (arg('only', '') || '').split(',').filter(Boolean);
const OUT = arg('out', '/tmp/wptest/results.json');

const PLUGINS_DST = path.join(WP_ROOT, 'wp-content/plugins');
const DB = path.join(WP_ROOT, 'wp-content/database/.ht.sqlite');
const DEBUG_LOG = path.join(WP_ROOT, 'wp-content/debug.log');
const PHP_LOG = path.join(WP_ROOT, 'wp-content/php-errors.log');
const SRC = '/tmp/plugins';
const META = JSON.parse(fs.readFileSync('/tmp/scan/meta.json', 'utf8'));

// plugins that stay installed in wp-content/plugins as dependencies
const KEEP = new Set(['woocommerce', 'elementor', 'persian-woocommerce']);
const isBaseSlug = /^(woocommerce|elementor|persian-woocommerce)$/;
const BASE_PLUGINS = ['woocommerce/woocommerce.php'];

// ------------------------------------------------------------------ helpers
function clearLogs() {
  for (const f of [DEBUG_LOG, PHP_LOG]) {
    try { fs.writeFileSync(f, ''); } catch {}
  }
}

function classify(line) {
  if (/sqlite-database-integration/.test(line)) return 'infra';
  if (/SQLSTATE|Error occurred at line \d+ in Function/.test(line)) return 'db';
  if (/Something may be wrong with WordPress\.org|wp_update_plugins|plugins_api|translations_api|wp_remote_|cURL error|Could not open handle|failed to open stream: .*https?:\/\//.test(line)) return 'network';
  if (/Cannot modify header information|headers already sent/.test(line)) return 'network';
  if (/PHP Fatal error|Uncaught (Error|TypeError|ValueError|ArgumentCountError|DivisionByZeroError|ErrorException)/.test(line)) return 'fatal';
  if (/PHP Parse error|syntax error/.test(line)) return 'parse';
  if (/PHP Warning/.test(line)) return 'warning';
  if (/PHP Deprecated|Deprecated:/.test(line)) return 'deprecated';
  if (/PHP Notice/.test(line)) return 'notice';
  return 'other';
}

function readLogs() {
  const out = { fatal: [], parse: [], warning: [], deprecated: [], notice: [], db: [], infra: [], network: [], other: [] };
  for (const f of [DEBUG_LOG, PHP_LOG]) {
    if (!fs.existsSync(f)) continue;
    let txt = '';
    try { txt = fs.readFileSync(f, 'utf8'); } catch { continue; }
    for (const line of txt.split('\n')) {
      const l = line.trim();
      if (!l) continue;
      out[classify(l)].push(l.length > 400 ? l.slice(0, 400) + '…' : l);
    }
  }
  return out;
}

const ENCODER = /ionCube|SourceGuardian|is protected by|ixed\.\d|requires a loader|Loader for PHP needs to be installed|\u0633\u0648\u0631\u0633\s*\u06af\u0627\u0631\u062f\u06cc\u0646/i;
const WSOD = /critical error on this website|Fatal error|Parse error|Uncaught |There has been a critical/i;

function snippet(text, marker) {
  const i = typeof marker === 'string' ? text.indexOf(marker) : marker.exec(text)?.index ?? -1;
  if (i < 0) return (text || '').replace(/\s+/g, ' ').slice(0, 300).trim();
  return text.slice(Math.max(0, i - 100), i + 260).replace(/\s+/g, ' ').trim();
}

async function phpRun(php, code) {
  let text = '';
  try {
    const res = await php.run({ code });
    text = res.text || '';
  } catch (e) {
    text = (e && e.response && e.response.text) || '';
    if (e && e.message && /Fatal|Uncaught|exit code/i.test(e.message)) text += '\n[harness] ' + String(e.message).split('\n')[0];
  }
  return text;
}

async function setActive(php, list) {
  const ser = (await phpRun(php, `<?php echo serialize(${JSON.stringify(list)});`)).trim();
  await phpRun(php, `<?php
$db = new PDO('sqlite:${DB}');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$st = $db->prepare("UPDATE wp_options SET option_value = ? WHERE option_name = 'active_plugins'");
$st->execute(array(${JSON.stringify(ser)}));
echo "rows=" . $st->rowCount() . "\\n";
`);
}

async function activate(php, pluginFile) {
  const code = `<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/wp-admin/plugins.php';
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
define('WP_ADMIN', true);
require_once '${WP_ROOT}/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$p = ${JSON.stringify(pluginFile)};
if (!file_exists(WP_PLUGIN_DIR . '/' . $p)) { echo "MISSING_FILE\\n"; exit(0); }
$r = activate_plugin($p, '', false, true);
if (is_wp_error($r)) {
    echo "WP_ERROR: " . $r->get_error_code() . " :: " . wp_strip_all_tags($r->get_error_message()) . "\\n";
    $data = $r->get_error_data();
    if ($data) { $d = is_array($data) || is_object($data) ? print_r($data, true) : (string) $data; echo "OUTPUT: " . str_replace("\\n", " ", wp_strip_all_tags(substr($d, 0, 1500))) . "\\n"; }
} else {
    echo "ACTIVATED\\n";
}
echo "ACTIVE_NOW: " . implode(',', get_option('active_plugins', array())) . "\\n";
`;
  return phpRun(php, code);
}

function headerOf(pluginDir, mainRel, name) {
  try {
    const head = fs.readFileSync(path.join(pluginDir, mainRel), 'utf8').slice(0, 8000);
    const m = head.match(new RegExp(`^[\\s*#/]*${name}\\s*:\\s*(.+)$`, 'im'));
    return m ? m[1].trim() : null;
  } catch { return null; }
}

// capabilities are not page slugs
const CAPS = new Set(['manage_options', 'manage_woocommerce', 'manage_categories', 'manage_links',
  'activate_plugins', 'install_plugins', 'edit_plugins', 'edit_users', 'edit_posts', 'edit_pages',
  'edit_theme_options', 'edit_files', 'publish_posts', 'delete_posts', 'read', 'read_private_posts',
  'import', 'export', 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'subscriber',
  'output', 'instance', 'admin_init', 'general', 'tools', 'options']);

// admin menu slugs declared by the plugin -> /wp-admin/admin.php?page=<slug>
function adminPages(pluginDir) {
  const slugs = new Set();
  let files = [];
  try {
    files = execSync(`grep -rlE "add_(menu|submenu|options|management|dashboard|woocommerce)_page\\s*\\(" ${JSON.stringify(pluginDir)} --include="*.php" | head -60`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).split('\n').filter(Boolean);
  } catch {}
  for (const f of files) {
    let txt = '';
    try { txt = fs.readFileSync(f, 'utf8'); } catch { continue; }
    const re = /add_(?:menu|submenu|options|management|dashboard|users|woocommerce|posts|media|links|pages|comments|theme|plugins)_page\s*\(([^;]{0,400})\)/gi;
    let m;
    while ((m = re.exec(txt))) {
      for (const lit of m[1].matchAll(/['"]([a-z0-9][a-z0-9_\-]{2,40})['"]/g)) {
        if (!CAPS.has(lit[1])) slugs.add(lit[1]);
      }
    }
    if (slugs.size > 10) break;
  }
  return [...slugs].slice(0, 6);
}

const MENU_RE = /href=['"]((?:admin\.php|(?:edit|options-general|tools|users|themes|plugins|upload)\.php)\?page=[^'"]*)['"]/g;

function menuPages(html) {
  const found = new Map(); // slug -> relative url under /wp-admin/
  for (const m of html.matchAll(MENU_RE)) {
    const url = m[1].replace(/&#0?38;/g, '&');
    const slug = new URLSearchParams(url.split('?')[1] || '').get('page');
    if (slug && slug.length > 2 && !['about', 'credits', 'freedoms', 'privacy', 'wpseo_dashboard'].includes(slug)) {
      found.set(slug, url);
    }
  }
  return found;
}

async function discoverMenuPages(handler, jar, baseMenu) {
  // visit the dashboard and pick the menu links this plugin added
  let html = '';
  try {
    const r = await request(handler, 'GET', '/wp-admin/', undefined, {}, jar);
    html = r.text || '';
  } catch { return []; }
  const seen = menuPages(html);
  const out = [];
  for (const [slug, url] of seen) if (!baseMenu.has(slug)) out.push(url);
  return out.slice(0, 8).map((u) => '/wp-admin/' + u);
}

function copyPlugin(slug) {
  const dst = path.join(PLUGINS_DST, slug);
  if (fs.existsSync(dst)) return true;
  const a = path.join(SRC, slug, slug);
  const b = path.join(SRC, slug);
  const from = fs.existsSync(a) ? a : (fs.existsSync(b) ? b : null);
  if (!from) return false;
  execSync(`cp -r ${JSON.stringify(from)} ${JSON.stringify(dst)}`, { stdio: 'ignore' });
  return true;
}

// ------------------------------------------------------------------ main
const handler = await createHandler();
const php = await handler.getPrimaryPhp();
const session = await login(handler);
const jar = session.jar;
console.log('[login] logged-in =', session.loggedIn, '| php', PHPV);

// sanity check: the site must be alive before we start (SQLite drop-in present)
{
  const probe = await request(handler, 'GET', '/wp-admin/', undefined, {}, jar);
  if (![200, 302].includes(probe.status) || /error establishing a database/i.test(probe.text)) {
    console.error('[fatal] WordPress is not reachable:', probe.status, probe.text.slice(0, 300));
    process.exit(2);
  }
  console.log('[sanity] wp-admin reachable, status', probe.status);
}

// baseline admin menu (only the dependency plugins are active)
for (const dep of KEEP) copyPlugin(dep);
await setActive(php, BASE_PLUGINS);
// warm up: WooCommerce builds its menu/ tables on the first requests
for (const w of ['/wp-admin/', '/wp-admin/admin.php?page=wc-settings', '/wp-admin/plugins.php', '/wp-admin/']) {
  try { await request(handler, 'GET', w, undefined, {}, jar); } catch {}
}
const baseHtml = (await request(handler, 'GET', '/wp-admin/', undefined, {}, jar)).text || '';
const BASE_MENU = menuPages(baseHtml);
console.log('[baseline] admin menu entries:', BASE_MENU.size, '(must be > 5)');
if (BASE_MENU.size < 5) { console.error('[fatal] baseline admin menu is too small, aborting'); process.exit(3); }

fs.mkdirSync(path.dirname(OUT), { recursive: true });
const results = fs.existsSync(OUT) ? JSON.parse(fs.readFileSync(OUT, 'utf8')) : {};


const order = [
  'digits', 'RTL-CareUnit', 'zhaket-updater', 'cta-button', 'woocommerce-invoice',
  'elementor-pro', 'persian-woocommerce-shipping', 'wp-parsidate', 'woo-order-export-lite',
  'litespeed-cache', 'wp-optimize', 'wp-statistics', 'wordpress-seo', 'elementor',
  'woocommerce', 'persian-woocommerce', 'plugin-check', 'woo-wallet', 'woodmart-core',
];
const plugins = Object.keys(META).sort((a, b) => {
  const ia = order.indexOf(a), ib = order.indexOf(b);
  return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
});

let tested = 0;
for (const slug of plugins) {
  if (ONLY.length && !ONLY.includes(slug)) continue;
  if (results[slug] && results[slug].done && !process.env.FORCE) { console.log(`[skip] ${slug}`); continue; }

  const meta = META[slug];
  const mainRel = (meta.main_file || '').split('/').slice(1).join('/');
  const pluginFile = mainRel ? `${slug}/${mainRel}` : null;
  const t0 = Date.now();
  console.log(`\n[test] ${slug} (${pluginFile || 'no main file'})`);

  const entry = { slug, name: meta['hdr_Plugin Name'] || meta.hdr_Plugin_Name || slug, version: meta.hdr_Version, pluginFile, php: PHPV };
  try { fs.rmSync(path.join(PLUGINS_DST, slug), { recursive: true, force: true }); } catch {}
  if (!copyPlugin(slug)) {
    entry.verdict = 'NO_SOURCE'; entry.done = true; results[slug] = entry;
    fs.writeFileSync(OUT, JSON.stringify(results, null, 1));
    continue;
  }
  if (!pluginFile || !fs.existsSync(path.join(PLUGINS_DST, pluginFile))) {
    entry.verdict = 'NO_MAIN_FILE'; entry.done = true; results[slug] = entry;
    fs.writeFileSync(OUT, JSON.stringify(results, null, 1));
    continue;
  }

  // dependencies from the "Requires Plugins" header
  const deps = (headerOf(path.join(PLUGINS_DST, slug), mainRel, 'Requires Plugins') || '')
    .split(',').map((s) => s.trim()).filter(Boolean);
  const base = BASE_PLUGINS.slice();
  const depFiles = [];
  for (const dep of deps) {
    if (fs.existsSync(path.join(SRC, dep, dep)) || fs.existsSync(path.join(SRC, dep))) {
      copyPlugin(dep);
      // find that plugin's main file from our own metadata
      const dm = META[dep] ? (META[dep].main_file || '').split('/').slice(1).join('/') : null;
      if (dm) { const df = `${dep}/${dm}`; depFiles.push(df); if (!base.includes(df)) base.push(df); }
    } else if (dep === 'elementor-pro' || dep === 'elementor') {
      copyPlugin(dep);
      const dm = META[dep] ? (META[dep].main_file || '').split('/').slice(1).join('/') : null;
      if (dm) { const df = `${dep}/${dm}`; depFiles.push(df); if (!base.includes(df)) base.push(df); }
    }
  }
  entry.requires_plugins = deps;
  entry.active_base = base;

  await setActive(php, base);
  clearLogs();
  const actOut = await activate(php, pluginFile);
  entry.activation_output = actOut.slice(-4000);
  entry.active_now = ((actOut.match(/ACTIVE_NOW: ?(.*)/) || [])[1] || '').split(',').filter(Boolean);
  entry.activated = /ACTIVATED/.test(actOut);
  const werr = actOut.match(/WP_ERROR: ([^:\n]*)/);
  entry.activation_error = werr ? werr[1].trim() : null;
  entry.activation_message = (actOut.match(/WP_ERROR:[^\n]*/) || [null])[0];
  entry.activation_stdout = (actOut.match(/OUTPUT:[^\n]*/) || [null])[0];
  const actLogs = readLogs();
  entry.activation_fatal = actLogs.fatal.concat(actLogs.parse).slice(0, 3);
  entry.encoder_message = ENCODER.test(actOut) ? snippet(actOut, ENCODER).slice(0, 400) : null;

  // real-life state: the plugin is active while the site is on PHP 8.2
  const active = base.concat([pluginFile]);
  await setActive(php, active);
  clearLogs();

  const pages = [
    '/',
    '/wp-admin/',
    '/wp-admin/plugins.php',
    '/wp-admin/options-general.php',
    '/wp-admin/edit.php?post_type=product',
    '/wp-admin/admin.php?page=wc-settings',
    '/wp-admin/admin.php?page=wc-settings&tab=shipping',
  ];
  const extra = process.env.NO_MENU_PAGES ? [] : adminPages(path.join(PLUGINS_DST, slug));
  entry.admin_pages_regex = extra;
  for (const s of extra) pages.push(`/wp-admin/admin.php?page=${s}`);
  if (!process.env.NO_MENU_PAGES && BASE_MENU) {
    const activeNames = new Set(active.map((f) => f.split('/')[0]));
    const foreign = [...KEEP].filter((k) => !activeNames.has(k));
    // pages owned by the base plugins (WooCommerce: wc-*, Elementor: elementor-*) are not the
    // tested plugin's pages - they would only add noise (and their own bugs) to every result
    const FOREIGN_PAGE = /page=(wc-|woocommerce|elementor|persian-woocommerce|pws-)/;
    const menuSlugs = (await discoverMenuPages(handler, jar, BASE_MENU))
      .filter((u) => !foreign.some((f) => u.includes(`page=${f}`)))
      // base plugins keep their own pages; other plugins must not inherit theirs
      .filter((u) => isBaseSlug.test(slug) || !FOREIGN_PAGE.test(u));
    entry.admin_pages_menu = menuSlugs;
    for (const s of menuSlugs) pages.push(s);
  }

  const probes = [];
  for (const p of pages) {
    let r;
    try {
      r = await request(handler, 'GET', p, undefined, {}, jar);
    } catch (e) {
      probes.push({ path: p, status: 0, len: 0, fatal: 'harness: ' + String(e.message).split('\n')[0] });
      continue;
    }
    const text = r.text || '';
    const marker = /critical error on this website|Fatal error|Parse error|Uncaught |ionCube|SourceGuardian|is protected by|\u0633\u0648\u0631\u0633\s*\u06af\u0627\u0631\u062f\u06cc\u0646/;
    const hasMarker = marker.test(text);
    probes.push({
      path: p,
      status: r.status,
      len: text.length,
      fatal: hasMarker ? snippet(text, marker) : null,
      http500: r.status >= 500 && !hasMarker ? text.replace(/\s+/g, ' ').slice(0, 300) : null,
    });
  }
  entry.probes = probes;
  entry.probe_fatal = probes.filter((p) => p.fatal).map((p) => ({ path: p.path, status: p.status, snippet: p.fatal }));

  const logs = readLogs();
  entry.logs = {
    fatal: logs.fatal.slice(0, 5),
    parse: logs.parse.slice(0, 3),
    warning: logs.warning.slice(0, 6),
    deprecated: logs.deprecated.slice(0, 8),
    counts: { fatal: logs.fatal.length, parse: logs.parse.length, warning: logs.warning.length, deprecated: logs.deprecated.length, db: logs.db.length, infra: logs.infra.length, network: logs.network.length },

  };

  const encPages = probes.filter((p) => p.fatal && ENCODER.test(p.fatal || ''));
  const encDead = !!(entry.encoder_message && !/ACTIVATED/.test(actOut)) ||
    encPages.some((p) => p.status >= 500 || (p.len || 0) < 3000);
  entry.encoder_pages = encPages.map((p) => ({ path: p.path, status: p.status, len: p.len }));
  entry.verdict =
    entry.encoder_message && encDead ? 'ENCODED'
    : (entry.encoder_message || encPages.length) ? 'LOADER_NOTICE'
    : (entry.logs.counts.fatal || entry.logs.counts.parse || entry.probe_fatal.length || entry.activation_fatal.length) ? 'CRASH'
    : !entry.activated ? 'NO_ACT'
    : (entry.logs.counts.deprecated || entry.logs.counts.warning) ? 'WARNINGS'
    : 'OK';

  // cleanup: back to the dependency baseline, remove the tested plugin
  await setActive(php, BASE_PLUGINS);
  if (!KEEP.has(slug)) { try { fs.rmSync(path.join(PLUGINS_DST, slug), { recursive: true, force: true }); } catch {} }
  clearLogs();

  entry.duration_s = Math.round((Date.now() - t0) / 1000);
  entry.done = true;
  results[slug] = entry;
  fs.writeFileSync(OUT, JSON.stringify(results, null, 1));
  tested++;
  console.log(`[${entry.verdict}] ${slug} ${entry.duration_s}s activated=${entry.activated} ` +
    `fatal=${entry.logs.counts.fatal} parse=${entry.logs.counts.parse} depr=${entry.logs.counts.deprecated} warn=${entry.logs.counts.warning}`);
}

console.log(`\n[done] tested ${tested} plugins -> ${OUT}`);
process.exit(0);
