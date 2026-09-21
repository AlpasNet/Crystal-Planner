import { TranslatorBacking } from '@browsermt/bergamot-translator/translator.js';
import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { prepareBergamotNodeWorkerRuntime } from './NodeWorkerRuntime.js';
import path from 'node:path';
import { gunzip as gunzipCallback } from 'node:zlib';
import { promisify } from 'node:util';

const gunzip = promisify(gunzipCallback);
const DEFAULT_REGISTRY = 'https://storage.googleapis.com/moz-fx-translations-data--303e-prod-translations-data/db/models.json';
const DEFAULT_BASE_URL = 'https://storage.googleapis.com/moz-fx-translations-data--303e-prod-translations-data';
const MOZILLA_GITHUB_RAW = 'https://github.com/mozilla/firefox-translations-models/raw/refs/heads/main';
const FIREFOX_MODELS_HF_MIRROR = 'https://huggingface.co/mukowaty/firefox-translations/resolve/main';

// Snapshot of the current Firefox Release base-memory FR<->EN models.
// These exact model hashes are also present in Mozilla's archived
// firefox-translations-models repository, which gives us an official fallback
// if the production GCS bucket returns HTTP 403 from a particular VPS/network.
const BUILTIN_RELEASE_MODELS = {
  'fr-en': {
    architecture: 'base-memory',
    releaseStatus: 'Release',
    sourceLanguage: 'fr',
    targetLanguage: 'en',
    files: {
      lexicalShortlist: {
        path: 'models/fr-en/retrain_hr_EFgIftH_RrCyzl5gjemVNg/exported/lex.50.50.fren.s2t.bin.gz'
      },
      model: {
        path: 'models/fr-en/retrain_hr_EFgIftH_RrCyzl5gjemVNg/exported/model.fren.intgemm.alphas.bin.gz',
        uncompressedSize: 31561787,
        uncompressedHash: '15f997bc0d13808b0b0fbd0786e684a3c8a52adcd8071844b76123fdacbf2b90'
      },
      vocab: {
        path: 'models/fr-en/retrain_hr_EFgIftH_RrCyzl5gjemVNg/exported/vocab.fren.spm.gz'
      }
    }
  },
  'en-fr': {
    architecture: 'base-memory',
    releaseStatus: 'Release',
    sourceLanguage: 'en',
    targetLanguage: 'fr',
    files: {
      lexicalShortlist: {
        path: 'models/en-fr/retrain_hr_NLIxDbE1TBGyOTI-zwZagw/exported/lex.50.50.enfr.s2t.bin.gz'
      },
      model: {
        path: 'models/en-fr/retrain_hr_NLIxDbE1TBGyOTI-zwZagw/exported/model.enfr.intgemm.alphas.bin.gz',
        uncompressedSize: 31561787,
        uncompressedHash: '6322e296d4fecfe395a8d5723da4ec37ecbe6d7613bb1dfcf4b28e2a47498b68'
      },
      vocab: {
        path: 'models/en-fr/retrain_hr_NLIxDbE1TBGyOTI-zwZagw/exported/vocab.enfr.spm.gz'
      }
    }
  }
};

function toArrayBuffer(buffer) {
  return buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength);
}

async function maybeGunzip(buffer) {
  try {
    return await gunzip(buffer);
  } catch {
    // Node/Undici may already have decoded Content-Encoding automatically.
    return buffer;
  }
}

function builtinRegistryEntries() {
  return Object.values(BUILTIN_RELEASE_MODELS).map((model) => ({
    from: model.sourceLanguage,
    to: model.targetLanguage,
    model,
    baseUrl: DEFAULT_BASE_URL,
    mirrorBaseUrl: FIREFOX_MODELS_HF_MIRROR
  }));
}

function gcsDownloadCandidates(baseUrl, relativePath) {
  const cleanBase = baseUrl.replace(/\/$/, '');
  const cleanPath = relativePath.replace(/^\//, '');
  const candidates = [`${cleanBase}/${cleanPath}`];

  try {
    const parsed = new URL(cleanBase);
    let bucket = null;

    if (parsed.hostname === 'storage.googleapis.com') {
      bucket = parsed.pathname.split('/').filter(Boolean)[0] || null;
    } else if (parsed.hostname.endsWith('.storage.googleapis.com')) {
      bucket = parsed.hostname.slice(0, -'.storage.googleapis.com'.length);
    }

    if (bucket) {
      const objectName = cleanPath;
      const encodedObject = encodeURIComponent(objectName);
      candidates.push(
        `https://storage.googleapis.com/download/storage/v1/b/${bucket}/o/${encodedObject}?alt=media`,
        `https://www.googleapis.com/download/storage/v1/b/${bucket}/o/${encodedObject}?alt=media`,
        `https://${bucket}.storage.googleapis.com/${objectName}`
      );
    }
  } catch {
    // Keep the original URL only for non-GCS/invalid custom base URLs.
  }

  return [...new Set(candidates)];
}

function huggingFaceModelCandidate(relativePath, { from, to }, mirrorBaseUrl = FIREFOX_MODELS_HF_MIRROR) {
  if (!from || !to || !mirrorBaseUrl) return null;
  const filename = path.posix.basename(relativePath);
  return `${mirrorBaseUrl.replace(/\/$/, '')}/${from}-${to}/${filename}?download=true`;
}

function githubModelCandidate(relativePath, { from, to, architecture }) {
  if (!from || !to || !architecture) return null;
  const filename = path.posix.basename(relativePath);
  return `${MOZILLA_GITHUB_RAW}/models/${architecture}/${from}${to}/${filename}`;
}

async function fetchWithTimeout(url, { timeoutMs, signal, accept = '*/*' }) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
  const abortFromParent = () => controller.abort();
  signal?.addEventListener('abort', abortFromParent, { once: true });

  try {
    return await fetch(url, {
      signal: controller.signal,
      redirect: 'follow',
      headers: {
        'User-Agent': 'Crystal-Translator/0.1.5',
        Accept: accept
      }
    });
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener('abort', abortFromParent);
  }
}

export class MozillaModelBacking extends TranslatorBacking {
  constructor(options = {}) {
    // TranslatorBacking.loadWorker() sends this.options to a worker thread via
    // postMessage(). Functions cannot be structured-cloned by Node.js, so keep
    // the error callback on the main thread only and never put it in
    // this.options. This avoids DOMException [DataCloneError] on Node 22+.
    const { onerror, ...workerSafeOptions } = options;
    super(workerSafeOptions);

    if (typeof onerror === 'function') {
      this.onerror = onerror;
    }
  }

  /**
   * @browsermt/bergamot-translator@0.4.9 declares itself as ESM but ships a
   * Node worker that still calls require() and uses __dirname/__filename.
   * Loading that .js file directly on Node 20/22 therefore fails. We run an
   * unchanged copy of the official worker as .cjs and keep the generated WASM
   * and glue files next to it.
   *
   * This method also fixes an upstream failure mode where a worker error can
   * leave initialize() pending forever: any worker error rejects all pending
   * calls immediately.
   */
  async loadWorker() {
    const runtimeDir = path.resolve(
      this.options.workerRuntimeDir || './data/bergamot-worker-runtime'
    );
    const workerUrl = await prepareBergamotNodeWorkerRuntime(runtimeDir);
    const worker = new Worker(workerUrl);

    let serial = 0;
    const pending = new Map();

    const call = (name, ...args) => new Promise((resolve, reject) => {
      const id = ++serial;
      pending.set(id, { resolve, reject, name });
      worker.postMessage({ id, name, args });
    });

    worker.addEventListener('message', ({ data: { id, result, error } }) => {
      const request = pending.get(id);
      if (!request) return;
      pending.delete(id);

      if (error !== undefined) {
        request.reject(Object.assign(new Error(error.message || `Bergamot ${request.name} failed`), error));
      } else {
        request.resolve(result);
      }
    });

    worker.addEventListener('error', (event) => {
      const cause = event?.data instanceof Error
        ? event.data
        : new Error(event?.data?.message || event?.message || 'Bergamot worker failed');

      for (const request of pending.values()) request.reject(cause);
      pending.clear();
      this.onerror(cause);
    });

    // Only send options understood by the WASM worker. Keeping our Mozilla
    // download/cache settings on the main thread also guarantees the payload is
    // structured-clone safe.
    await call('initialize', {
      cacheSize: Math.max(Number(this.options.cacheSize || 0), 0),
      useNativeIntGemm: Boolean(this.options.useNativeIntGemm)
    });

    return {
      worker,
      exports: new Proxy({}, {
        get(_target, name) {
          if (name === 'then') return undefined;
          return (...args) => call(name, ...args);
        }
      })
    };
  }

  async loadModelRegistery() {
    // Crystal Translator intentionally supports only FR <-> EN.  The two
    // Release/base-memory records are bundled, so startup never depends on
    // Mozilla's GCS registry.  A remote refresh can still be enabled
    // explicitly for maintainers, but the normal bot path is fully stable.
    if (!this.options.refreshMozillaRegistry) {
      return builtinRegistryEntries();
    }

    const registryUrl = this.options.mozillaRegistryUrl || DEFAULT_REGISTRY;
    const timeoutMs = Number(this.options.downloadTimeout || 120_000);

    try {
      const response = await fetchWithTimeout(registryUrl, {
        timeoutMs,
        accept: 'application/json'
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      const architecture = this.options.modelArchitecture || 'base-memory';
      const releaseStatus = this.options.modelReleaseStatus || 'Release';

      return ['fr-en', 'en-fr'].map((pair) => {
        const candidates = data.models?.[pair] || [];
        const selected =
          candidates.find((m) => m.architecture === architecture && m.releaseStatus === releaseStatus) ||
          candidates.find((m) => m.architecture === architecture) ||
          candidates.find((m) => m.releaseStatus === releaseStatus) ||
          candidates[0];

        if (!selected) throw new Error(`No Mozilla model found for ${pair}`);
        return {
          from: selected.sourceLanguage,
          to: selected.targetLanguage,
          model: selected,
          baseUrl: data.baseUrl || DEFAULT_BASE_URL,
          mirrorBaseUrl: FIREFOX_MODELS_HF_MIRROR
        };
      });
    } catch (error) {
      console.warn(`[Models] Mozilla registry refresh failed (${error.message}); using bundled FR/EN metadata.`);
      return builtinRegistryEntries();
    }
  }

  // Deliberately avoid TranslatorBacking's permanent in-process ArrayBuffer cache.
  // Bergamot's worker keeps loaded models; disk cache is enough and saves RAM.
  getTranslationModel(pair, options) {
    return this.loadTranslationModel(pair, options);
  }

  async loadTranslationModel({ from, to }, options) {
    const entries = await this.registry;
    const entry = entries.find((model) => model.from === from && model.to === to);
    if (!entry) throw new Error(`No Mozilla model available for ${from}->${to}`);

    const modelInfo = entry.model;
    const files = modelInfo.files;
    const identity = createHash('sha256')
      .update(JSON.stringify({
        model: files.model?.path,
        vocab: files.vocab?.path,
        lex: files.lexicalShortlist?.path
      }))
      .digest('hex')
      .slice(0, 16);

    const cacheRoot = path.resolve(this.options.modelCacheDir || './data/models');
    const pairDir = path.join(cacheRoot, `${from}-${to}`, identity);
    await mkdir(pairDir, { recursive: true });

    const sourceInfo = {
      from,
      to,
      architecture: modelInfo.architecture || this.options.modelArchitecture || 'base-memory'
    };

    const [model, vocab, shortlist] = await Promise.all([
      this.#getFile(entry.baseUrl, entry.mirrorBaseUrl, files.model.path, path.join(pairDir, 'model.bin'), options, sourceInfo),
      this.#getFile(entry.baseUrl, entry.mirrorBaseUrl, files.vocab.path, path.join(pairDir, 'vocab.spm'), options, sourceInfo),
      this.#getFile(entry.baseUrl, entry.mirrorBaseUrl, files.lexicalShortlist.path, path.join(pairDir, 'lex.bin'), options, sourceInfo)
    ]);

    const expectedHash = files.model?.uncompressedHash;
    if (expectedHash) {
      const actualHash = createHash('sha256').update(model).digest('hex');
      if (actualHash !== expectedHash) {
        throw new Error(`SHA-256 mismatch for ${from}->${to} model`);
      }
    }

    return {
      model: toArrayBuffer(model),
      shortlist: toArrayBuffer(shortlist),
      vocabs: [toArrayBuffer(vocab)],
      config: {}
    };
  }

  async #getFile(baseUrl, mirrorBaseUrl, relativePath, cachePath, options, sourceInfo) {
    try {
      return await readFile(cachePath);
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }

    const timeoutMs = Number(this.options.downloadTimeout || 120_000);
    const huggingFaceMirror = huggingFaceModelCandidate(relativePath, sourceInfo, mirrorBaseUrl);
    const githubFallback = githubModelCandidate(relativePath, sourceInfo);
    const candidates = [
      ...(huggingFaceMirror ? [huggingFaceMirror] : []),
      ...(githubFallback ? [githubFallback] : []),
      ...gcsDownloadCandidates(baseUrl, relativePath)
    ];

    const errors = [];

    for (const url of [...new Set(candidates)]) {
      try {
        const response = await fetchWithTimeout(url, {
          timeoutMs,
          signal: options?.signal,
          accept: 'application/octet-stream,*/*'
        });

        if (!response.ok) {
          errors.push(`${response.status} ${new URL(url).hostname}`);
          continue;
        }

        const compressed = Buffer.from(await response.arrayBuffer());
        const content = relativePath.endsWith('.gz') ? await maybeGunzip(compressed) : compressed;
        await writeFile(cachePath, content);

        const host = new URL(url).hostname;
        if (host === 'huggingface.co' || host.endsWith('.huggingface.co')) {
          console.log(`[Models] Downloaded ${path.posix.basename(relativePath)} via Firefox-model Hugging Face mirror.`);
        } else if (host.includes('github') || host.includes('githubusercontent')) {
          console.log(`[Models] Downloaded ${path.posix.basename(relativePath)} via Mozilla GitHub fallback.`);
        }
        return content;
      } catch (error) {
        errors.push(`${error.name || 'Error'} ${new URL(url).hostname}: ${error.message}`);
      }
    }

    throw new Error(
      `Unable to download ${relativePath}. Tried ${candidates.length} source(s): ${errors.join(' | ')}`
    );
  }
}
