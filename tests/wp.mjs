// Tiny WordPress host: php-wasm 8.2 + WordPress on the host filesystem.
//
//   node wp.mjs serve <port>          -> http server on 0.0.0.0 (live preview)
//   node wp.mjs req <method> <path>   -> single request, prints status + body
//
// Used by the plugin smoke tests (run-tests.mjs).
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { spawn } from 'node:child_process';
import http from 'node:http';

export const WP_ROOT = process.env.WP_ROOT || '/tmp/wp/wordpress';
export const PHPV = process.env.PHPV || '8.2';   // 8.1 / 8.2 / 8.3 / 8.4 ...
export const PORT = Number(process.env.PORT || 9400);

let seq = Number(process.env.PHPPID || 1);

async function makePhp() {
  const id = await loadNodeRuntime(PHPV, { emscriptenOptions: { processId: seq++ } });
  const php = new PHP(id);
  useHostFilesystem(php);
  await php.setSapiName('cli');
  // no real process spawning inside wasm
  await php.setSpawnHandler(() => spawn('/bin/true'));
  return php;
}

export async function createHandler(absoluteUrl = `http://127.0.0.1:${PORT}`) {
  const handler = new PHPRequestHandler({
    documentRoot: WP_ROOT,
    absoluteUrl,
    maxPhpInstances: 1,
    phpFactory: async () => makePhp(),
  });
  return handler;
}

// Simple cookie jar: { name: value }
export async function request(handler, method, path, body, headers = {}, jar = {}) {
  const hdrs = {
    'Content-Type': body ? 'application/x-www-form-urlencoded' : 'text/plain',
    ...headers,
  };
  const cookie = Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
  if (cookie) hdrs['Cookie'] = cookie;

  const res = await handler.request({ method, url: path, body, headers: hdrs });
  const headersOut = res.headers || {};
  const raw = headersOut['set-cookie'] || headersOut['Set-Cookie'] || [];
  for (const line of [].concat(raw)) {
    const [pair] = String(line).split(';');
    const idx = pair.indexOf('=');
    if (idx > 0) jar[pair.slice(0, idx).trim()] = pair.slice(idx + 1).trim();
  }
  return {
    status: res.statusCode ?? res.httpStatusCode ?? 0,
    text: res.text || '',
    headers: headersOut,
  };
}

// Log in to wp-admin and return the cookie jar
export async function login(handler, user = 'admin', pass = 'Admin@12345') {
  const jar = {};
  await request(handler, 'GET', '/wp-login.php', undefined, {}, jar);
  const body = `log=${encodeURIComponent(user)}&pwd=${encodeURIComponent(pass)}` +
               `&wp-submit=Log+In&redirect_to=${encodeURIComponent('/wp-admin/')}&testcookie=1`;
  const r = await request(handler, 'POST', '/wp-login.php', body, {}, jar);
  const loggedIn = Object.keys(jar).some((k) => k.startsWith('wordpress_logged_in_'));
  return { jar, loggedIn, status: r.status };
}

// ------------------------------------------------------------------ CLI mode
if (import.meta.url === `file://${process.argv[1]}`) {
  const mode = process.argv[2];
  const handler = await createHandler();

  if (mode === 'req') {
    const [, , , method, path, body] = process.argv;
    const r = await request(handler, method || 'GET', path || '/', body);
    process.stdout.write(`STATUS ${r.status}\n${r.text}`);
    process.exit(0);
  }

  if (mode === 'serve') {
    const port = Number(process.argv[3] || PORT);
    const server = http.createServer(async (req, res) => {
      let body = '';
      await new Promise((resolve) => {
        req.on('data', (c) => (body += c));
        req.on('end', resolve);
      });
      const headers = {};
      for (const [k, v] of Object.entries(req.headers)) {
        headers[k] = Array.isArray(v) ? v.join(', ') : v;
      }
      try {
        const r = await request(handler, req.method, req.url, req.method === 'POST' ? body : undefined, headers);
        res.writeHead(r.status || 200, {
          'Content-Type': r.headers['content-type'] || 'text/html; charset=utf-8',
          'X-Powered-By': 'php-wasm-8.2',
        });
        res.end(r.text);
      } catch (e) {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('harness error: ' + (e && e.message));
      }
    });
    server.listen(port, '0.0.0.0', () => console.log(`WordPress (php 8.2) on http://0.0.0.0:${port}`));
  }
}
