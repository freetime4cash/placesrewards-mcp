import { readFileSync } from 'node:fs';
const assets = new Map([
  ['/', ['index.html', 'text/html; charset=utf-8']],
  ['/dashboard.css', ['dashboard.css', 'text/css; charset=utf-8']],
  ['/dashboard-client.js', ['dashboard-client.js', 'text/javascript; charset=utf-8']],
].map(([route, [file, type]]) => [route, { type, body: readFileSync(new URL('./web/' + file, import.meta.url)) }]));

export function serveDashboard(req, res, pathname) {
  const asset = assets.get(pathname);
  if (!asset || req.method !== 'GET') return false;
  res.writeHead(200, {
    'Content-Type': asset.type, 'Content-Length': asset.body.length,
    'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer', 'X-Frame-Options': 'DENY',
    'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
  });
  res.end(asset.body); return true;
}
