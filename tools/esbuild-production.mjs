#!/usr/bin/env node
import { build, version as esbuildVersion } from 'esbuild';
import { mkdir, readdir, rm, writeFile } from 'node:fs/promises';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const sourceRoot = join(root, 'modules', 'esm');
const outRoot = join(root, '.build', 'esbuild-production');

async function collect(dir) {
  const entries = [];
  for (const item of await readdir(dir, { withFileTypes: true })) {
    const full = join(dir, item.name);
    if (item.isDirectory()) entries.push(...await collect(full));
    else if (item.isFile() && item.name.endsWith('.mjs')) entries.push(full);
  }
  return entries;
}

const entryPoints = (await collect(sourceRoot)).sort();
if (!entryPoints.length) throw new Error('METEONEXA_ESBUILD_NO_ENTRIES');

await rm(outRoot, { recursive: true, force: true });
await mkdir(outRoot, { recursive: true });
const result = await build({
  entryPoints,
  outdir: outRoot,
  outbase: root,
  entryNames: '[dir]/[name]',
  outExtension: { '.js': '.mjs' },
  bundle: true,
  splitting: false,
  format: 'esm',
  platform: 'browser',
  target: ['es2022'],
  minify: true,
  treeShaking: true,
  legalComments: 'none',
  charset: 'utf8',
  sourcemap: false,
  metafile: true,
  write: true,
  logLevel: 'warning',
});

const metadata = {
  builder: 'esbuild',
  esbuildVersion,
  target: 'es2022',
  format: 'esm',
  minified: true,
  entries: entryPoints.map(path => relative(root, path).replaceAll('\\', '/')),
  outputs: Object.keys(result.metafile.outputs).map(path => relative(root, resolve(path)).replaceAll('\\', '/')).sort(),
};
await writeFile(join(outRoot, 'build-info.json'), JSON.stringify(metadata, null, 2) + '\n');
console.log(`PASS production ESM build (${entryPoints.length} entries, esbuild ${esbuildVersion})`);
