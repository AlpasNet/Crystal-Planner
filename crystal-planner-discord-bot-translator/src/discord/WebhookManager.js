import { WebhookClient } from 'discord.js';
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';

export class WebhookManager {
  constructor(client, filePath, webhookName) {
    this.client = client;
    this.filePath = filePath;
    this.webhookName = webhookName || 'Crystal Translator';
    this.credentials = {};
    this.clients = new Map();
  }

  async load() {
    await mkdir(path.dirname(this.filePath), { recursive: true });
    try {
      this.credentials = JSON.parse(await readFile(this.filePath, 'utf8'));
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
      this.credentials = {};
    }
  }

  async get(channel) {
    const channelId = channel.id;
    if (this.clients.has(channelId)) return this.clients.get(channelId);

    const saved = this.credentials[channelId];
    if (saved?.id && saved?.token) {
      const client = new WebhookClient({ id: saved.id, token: saved.token });
      this.clients.set(channelId, client);
      return client;
    }

    const hooks = await channel.fetchWebhooks();
    let webhook = hooks.find((hook) =>
      hook.name === this.webhookName &&
      hook.owner?.id === this.client.user.id &&
      hook.token
    );

    if (!webhook) {
      webhook = await channel.createWebhook({
        name: this.webhookName,
        reason: 'Crystal Translator automatic FR/EN relay'
      });
    }

    if (!webhook.token) throw new Error(`Discord did not return a token for webhook in #${channel.name}`);

    this.credentials[channelId] = { id: webhook.id, token: webhook.token };
    await this.#save();

    const client = new WebhookClient({ id: webhook.id, token: webhook.token });
    this.clients.set(channelId, client);
    return client;
  }

  async reset(channelId) {
    const client = this.clients.get(channelId);
    client?.destroy();
    this.clients.delete(channelId);
    delete this.credentials[channelId];
    await this.#save();
  }

  destroy() {
    for (const client of this.clients.values()) client.destroy();
    this.clients.clear();
  }

  async #save() {
    const temp = `${this.filePath}.tmp`;
    await writeFile(temp, JSON.stringify(this.credentials, null, 2), { mode: 0o600 });
    await rename(temp, this.filePath);
  }
}
