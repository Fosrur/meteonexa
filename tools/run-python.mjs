import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const args = process.argv.slice(2);
if (!args.length) {
  process.stderr.write('Usage: node tools/run-python.mjs <script> [args...]\n');
  process.exit(2);
}
const candidates = process.platform === 'win32'
  ? [['py', ['-3']], ['python', []], ['python3', []]]
  : [['python3', []], ['python', []]];
for (const [command, prefix] of candidates) {
  const result = spawnSync(command, [...prefix, ...args], { cwd: root, stdio: 'inherit' });
  if (result.error?.code === 'ENOENT') continue;
  if (result.error) {
    process.stderr.write(`${result.error.message}\n`);
    process.exit(1);
  }
  process.exit(result.status ?? 1);
}
process.stderr.write('Python 3 non trovato. Installare Python 3 o aggiungerlo al PATH.\n');
process.exit(127);
