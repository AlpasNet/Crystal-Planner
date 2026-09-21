import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { copyBergamotWorkerRuntime } from '../src/translator/NodeWorkerRuntime.js';

test('Bergamot Node worker is copied as CommonJS with its runtime assets', async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), 'crystal-translator-worker-'));
  const source = path.join(root, 'source');
  const target = path.join(root, 'runtime');

  try {
    await mkdir(source, { recursive: true });
    const workerSource = "const fs = require('node:fs'); console.log(__dirname, fs);\n";
    await writeFile(path.join(source, 'translator-worker.js'), workerSource);
    await writeFile(path.join(source, 'bergamot-translator-worker.js'), '/* glue */\n');
    await writeFile(path.join(source, 'bergamot-translator-worker.wasm'), Buffer.from([0, 97, 115, 109]));

    const workerUrl = await copyBergamotWorkerRuntime(source, target);
    assert.equal(path.extname(fileURLToPath(workerUrl)), '.cjs');
    assert.equal(await readFile(path.join(target, 'translator-worker.cjs'), 'utf8'), workerSource);
    assert.equal(await readFile(path.join(target, 'bergamot-translator-worker.js'), 'utf8'), '/* glue */\n');
    assert.deepEqual(
      await readFile(path.join(target, 'bergamot-translator-worker.wasm')),
      Buffer.from([0, 97, 115, 109])
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
