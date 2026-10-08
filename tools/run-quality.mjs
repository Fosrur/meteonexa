import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const mode = process.argv[2] || 'fast';
if (!['fast', 'release', 'full'].includes(mode)) {
  process.stderr.write('Usage: node tools/run-quality.mjs [fast|release|full] [quality-runner options]\n');
  process.exit(2);
}
const extra = process.argv.slice(3);
const result = spawnSync(process.execPath, ['tools/run-python.mjs', 'tools/quality-runner.py', mode, ...extra], { cwd: root, stdio: 'inherit' });
if (result.error) {
  process.stderr.write(`${result.error.message}\n`);
  process.exit(1);
}
process.exit(result.status ?? 1);
