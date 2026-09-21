import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';

export class MessageStore {
  constructor(filePath, retentionMs) {
    this.filePath = filePath;
    this.retentionMs = retentionMs;
    this.entries = new Map();
    this.saveQueue = Promise.resolve();
  }

  async load() {
    await mkdir(path.dirname(this.filePath), { recursive: true });
    try {
      const data = JSON.parse(await readFile(this.filePath, 'utf8'));
      for (const entry of data.entries || []) {
        if (entry?.originalMessageId) this.entries.set(entry.originalMessageId, entry);
      }
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    await this.cleanup();
  }

  get(originalMessageId) {
    return this.entries.get(originalMessageId) || null;
  }

  async set(originalMessageId, entry) {
    this.entries.set(originalMessageId, { ...entry, originalMessageId });
    await this.save();
  }

  async delete(originalMessageId) {
    this.entries.delete(originalMessageId);
    await this.save();
  }

  async cleanup() {
    const cutoff = Date.now() - this.retentionMs;
    let changed = false;
    for (const [id, entry] of this.entries) {
      if (Number(entry.createdAt || 0) < cutoff) {
        this.entries.delete(id);
        changed = true;
      }
    }
    if (changed) await this.save();
  }

  save() {
    this.saveQueue = this.saveQueue.then(async () => {
      const temp = `${this.filePath}.tmp`;
      const payload = JSON.stringify({ entries: [...this.entries.values()] }, null, 2);
      await writeFile(temp, payload, 'utf8');
      await rename(temp, this.filePath);
    });
    return this.saveQueue;
  }
}
