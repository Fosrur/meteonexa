import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
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

const git = spawnSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], { cwd: root, encoding: 'buffer' });
if (git.status !== 0) {
    process.stderr.write(git.stderr || Buffer.from('git ls-files failed\n'));
    process.exit(git.status || 1);
}

const files = [...new Set(git.stdout.toString('utf8').split('\0').filter(isReleaseFile).map(normalizedPath))].sort();
const lines = [];
for (const path of files) {
    let data;
    try {
        data = await readFile(resolve(root, path));
    } catch {
        continue;
    }
    const hash = createHash('sha256').update(canonicalBytes(data)).digest('hex');
    lines.push(`${hash}  ./${path}`);
}

await writeFile(resolve(root, 'SHA256SUMS.txt'), `${lines.join('\n')}\n`, 'utf8');
console.log(`SHA256SUMS.txt aggiornato: ${lines.length} file.`);
