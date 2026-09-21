import { cp, mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);

/**
 * Copy Bergamot's generated worker runtime outside its npm package and expose
 * translator-worker.js as .cjs. The published @browsermt package declares
 * "type": "module", while that worker still uses CommonJS-only globals such
 * as require/__dirname/__filename. Node therefore needs the worker entry point
 * to have a .cjs extension.
 */
export async function copyBergamotWorkerRuntime(sourceDir, runtimeDir) {
  await mkdir(runtimeDir, { recursive: true });

  const entries = await readdir(sourceDir, { withFileTypes: true });
  for (const entry of entries) {
    const source = path.join(sourceDir, entry.name);
    const target = path.join(runtimeDir, entry.name);
    await cp(source, target, { recursive: true, force: true });
  }

  const sourceWorker = path.join(sourceDir, 'translator-worker.js');
  const commonJsWorker = path.join(runtimeDir, 'translator-worker.cjs');
  const workerSource = await readFile(sourceWorker, 'utf8');
  await writeFile(commonJsWorker, workerSource, 'utf8');

  return pathToFileURL(commonJsWorker);
}

export async function prepareBergamotNodeWorkerRuntime(runtimeDir) {
  const translatorPath = require.resolve('@browsermt/bergamot-translator/translator.js');
  const packageRoot = path.dirname(translatorPath);
  const sourceDir = path.join(packageRoot, 'worker');
  return copyBergamotWorkerRuntime(sourceDir, runtimeDir);
}
