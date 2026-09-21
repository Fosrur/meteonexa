#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const git = spawnSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'buffer' });
if (git.status !== 0) {
  process.stderr.write(git.stderr || Buffer.from('git ls-files failed\n'));
  process.exit(git.status || 1);
}
const files = git.stdout.toString('utf8').split('\0').filter(Boolean).filter((p) => p !== 'SHA256SUMS.txt').sort();
const lines = [];
for (const path of files) {
  const data = await readFile(resolve(root, path));
  const hash = createHash('sha256').update(data).digest('hex');
  lines.push(`${hash}  ./${path.replaceAll('\\', '/')}`);
}
await writeFile(resolve(root, 'SHA256SUMS.txt'), `${lines.join('\n')}\n`, 'utf8');
console.log(`SHA256SUMS.txt aggiornato: ${lines.length} file.`);