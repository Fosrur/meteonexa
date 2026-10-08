import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));

function canonicalBytes(data) {
    if (data.includes(0)) return data;
    try {
        new TextDecoder('utf-8', { fatal: true }).decode(data);
    } catch {
        return data;
    }
    return Buffer.from(data.toString('utf8').replace(/\r\n?/g, '\n'), 'utf8');
}

function normalizedPath(value) {
    return String(value || '').replaceAll('\\\\', '/').replace(/^\.\//, '');
}

function isReleaseFile(value) {
    const path = normalizedPath(value);
    if (!path || path === 'SHA256SUMS.txt') return false;
    if (path.startsWith('payload/')) return false;
    if (/^apply_(?:meteonexa|roadmap).*\.py$/i.test(path)) return false;
    return true;
}

let manifest;
try {
    manifest = await readFile(resolve(root, 'SHA256SUMS.txt'), 'utf8');
} catch {
    console.error('sha256 verify FAILED: SHA256SUMS.txt non trovato.');
    process.exit(1);
}

const expected = new Map();
const malformed = [];
for (const rawLine of manifest.replace(/\r\n?/g, '\n').split('\n')) {
    const line = rawLine.trimEnd();
    if (!line) continue;
    const match = line.match(/^([a-f0-9]{64})\s{2}\.\/(.+)$/i);
    if (!match) {
        malformed.push(line);
        continue;
    }
    expected.set(normalizedPath(match[2]), match[1].toLowerCase());
}

const git = spawnSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], { cwd: root, encoding: 'buffer' });
if (git.status !== 0) {
    process.stderr.write(git.stderr || Buffer.from('git ls-files failed\n'));
    process.exit(git.status || 1);
}

const actualFiles = [...new Set(git.stdout.toString('utf8').split('\0').filter(isReleaseFile).map(normalizedPath))].sort();
const actualSet = new Set(actualFiles);
const failures = [];

for (const line of malformed) failures.push(`riga manifest non valida: ${line.slice(0, 160)}`);

for (const path of actualFiles) {
    if (!expected.has(path)) {
        failures.push(`manca dal manifest: ./${path}`);
        continue;
    }
    let data;
    try {
        data = await readFile(resolve(root, path));
    } catch {
        failures.push(`file non leggibile: ./${path}`);
        continue;
    }
    const actualHash = createHash('sha256').update(canonicalBytes(data)).digest('hex');
    if (actualHash !== expected.get(path)) failures.push(`checksum diverso: ./${path}`);
}

for (const path of expected.keys()) {
    if (!actualSet.has(path)) failures.push(`manifest obsoleto: ./${path}`);
}

if (failures.length) {
    console.error(`sha256 verify FAILED: ${failures.length} errori, ${actualFiles.length} file verificati.`);
    for (const failure of failures.slice(0, 80)) console.error(` - ${failure}`);
    if (failures.length > 80) console.error(` - altri ${failures.length - 80} errori`);
    process.exit(1);
}

console.log(`sha256 verify PASS (${actualFiles.length} file, canonical UTF-8/LF).`);
