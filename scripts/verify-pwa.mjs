import { readFileSync } from 'node:fs';

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

const manifest = JSON.parse(readFileSync('public/build/manifest.webmanifest', 'utf8'));
const blade = readFileSync('resources/views/welcome.blade.php', 'utf8');
const nginx = readFileSync('docker/frontend/nginx.conf', 'utf8');

assert(manifest.start_url === '/', `PWA start_url must be "/", received "${manifest.start_url}".`);
assert(manifest.scope === '/', `PWA scope must be "/", received "${manifest.scope}".`);
assert(manifest.lang === 'es', `PWA language must be "es", received "${manifest.lang}".`);
assert(blade.includes('rel="manifest"') && blade.includes('/build/manifest.webmanifest'), 'The application shell must link the web manifest.');
assert(nginx.includes('Service-Worker-Allowed "/"'), 'Nginx must allow /build/sw.js to control the root scope.');
assert(nginx.includes('application/manifest+json'), 'Nginx must serve the manifest with its correct media type.');

console.log('PWA contract verified.');
