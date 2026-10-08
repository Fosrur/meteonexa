#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { resolve, relative, isAbsolute } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const manifestPath = resolve(root, 'SHA256SUMS.txt');

function canonicalBytes(data) {
  if (data.includes(0)) return data;
  try {
    new TextDecoder('utf-8', { fatal: true }).decode(data);
  } catch {
    return data;
  }
  return Buffer.from(data.toString('utf8').replaceAll('\r\n', '\n'), 'utf8');
}

let manifest;
try {
  manifest = await readFile(manifestPath, 'utf8');
} catch (error) {
  process.stderr.write(`SHA256SUMS.txt non leggibile: ${error?.message || error}\n`);
  process.exit(1);
}

const rows = manifest.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
if (!rows.length) {
  process.stderr.write('SHA256SUMS.txt vuoto.\n');
  process.exit(1);
}

let failed = 0;
let checked = 0;
const listed = new Set();

for (const row of rows) {
  const match = row.match(/^([0-9a-fA-F]{64})\s+\*?(.+)$/);
  if (!match) {
    process.stderr.write(`Riga checksum non valida: ${row}\n`);
    failed += 1;
    continue;
  }

  const expected = match[1].toLowerCase();
  const path = match[2].replace(/^\.\//, '').replaceAll('\\', '/');
  const absolute = resolve(root, path);
  const relativePath = relative(root, absolute);
  if (!path || path === 'SHA256SUMS.txt' || isAbsolute(path) || relativePath === '..' || relativePath.startsWith('../') || relativePath.startsWith('..\\') || listed.has(path)) {
    process.stderr.write(`${match[2]}: FAILED path non valido o duplicato\n`);
    failed += 1;
    continue;
  }
  listed.add(path);

  let data;
  try {
    data = await readFile(absolute);
  } catch {
    process.stderr.write(`${match[2]}: FAILED open or read\n`);
    failed += 1;
    continue;
  }

  const actual = createHash('sha256').update(canonicalBytes(data)).digest('hex');
  checked += 1;
  if (actual === expected) {
    process.stdout.write(`${match[2]}: OK\n`);
  } else {
    process.stderr.write(`${match[2]}: FAILED\n`);
    failed += 1;
  }
}

if (failed) {
  process.stderr.write(`sha256 verify FAILED: ${failed} errori, ${checked} file verificati.\n`);
  process.exit(1);
}

process.stdout.write(`sha256 verify PASS: ${checked} file verificati (UTF-8/LF canonico).\n`);
