import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '..');

async function readJson(relativePath) {
  const raw = await readFile(path.join(rootDir, relativePath), 'utf8');
  return JSON.parse(raw);
}

export async function loadConfig() {
  const [config, terms] = await Promise.all([
    readJson('config.json'),
    readJson('terms.json')
  ]);

  const token = process.env.DISCORD_TOKEN?.trim();
  if (!token) {
    throw new Error('DISCORD_TOKEN is missing. Copy .env.example to .env and add the bot token.');
  }

  const fr = String(config.channels?.fr ?? '').trim();
  const en = String(config.channels?.en ?? '').trim();
  if (!/^\d{15,22}$/.test(fr) || !/^\d{15,22}$/.test(en) || fr === en) {
    throw new Error('config.json must contain two different Discord channel IDs in channels.fr and channels.en.');
  }

  return {
    ...config,
    token,
    terms,
    rootDir,
    editWindowMs: Math.max(1, Number(config.editWindowMinutes || 60)) * 60_000,
    retentionMs: Math.max(1, Number(config.messageMappingRetentionDays || 30)) * 86_400_000,
    model: {
      architecture: config.model?.architecture || 'base-memory',
      releaseStatus: config.model?.releaseStatus || 'Release',
      registryUrl: config.model?.registryUrl,
      refreshMozillaRegistry: Boolean(config.model?.refreshMozillaRegistry),
      cacheDir: path.resolve(rootDir, config.model?.cacheDir || './data/models'),
      downloadTimeoutMs: Number(config.model?.downloadTimeoutMs || 120_000)
    }
  };
}
