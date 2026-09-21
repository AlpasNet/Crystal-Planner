import { requireSnowflake, sleep } from "../utils.js";

const API = "https://discord.com/api/v10";
const USER_AGENT = "DiscordBot (https://github.com/AlpasNet/Crystal-Planner, Crystal-Planner-Server/1.1.7)";
const SUPPRESS_NOTIFICATIONS = 1 << 12;
const CROSSPOSTED = 1 << 0;
const FOURTEEN_DAYS_MINUS_FIVE_MINUTES = (14 * 24 * 60 * 60 * 1000) - (5 * 60 * 1000);

export class DiscordApiError extends Error {
  constructor(message, { status = 0, code = null, body = "" } = {}) {
    super(message);
    this.name = "DiscordApiError";
    this.status = status;
    this.code = code;
    this.body = body;
  }
}

export class DiscordApi {
  constructor(token, logger) {
    const normalized = normalizeToken(token);
    if (!normalized) throw new Error("DISCORD_TOKEN is missing.");
    if (/\s/.test(normalized)) throw new Error("DISCORD_TOKEN contains whitespace.");
    if (normalized.length < 30) throw new Error("DISCORD_TOKEN looks too short.");
    this.authorization = `Bot ${normalized}`;
    this.logger = logger;
  }

  async #request(method, path, { body, maxAttempts = 5 } = {}) {
    const url = `${API}${path}`;
    let lastResponse;

    for (let attempt = 1; attempt <= Math.max(1, maxAttempts); attempt++) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 35000);
      let response;
      try {
        response = await fetch(url, {
          method,
          headers: {
            "Authorization": this.authorization,
            "User-Agent": USER_AGENT,
            "Accept": "application/json,text/plain,*/*",
            ...(body !== undefined ? { "Content-Type": "application/json; charset=utf-8" } : {})
          },
          body: body === undefined ? undefined : JSON.stringify(body),
          signal: controller.signal
        });
      } catch (error) {
        clearTimeout(timeout);
        if (attempt >= maxAttempts) throw new DiscordApiError(`Discord request failed: ${error.message}`);
        await sleep(Math.min(attempt * 1000, 5000));
        continue;
      }
      clearTimeout(timeout);

      const text = await response.text();
      lastResponse = { status: response.status, headers: response.headers, text };
      if (response.status !== 429 || attempt >= maxAttempts) return lastResponse;

      const waitMs = parseRetryAfterMs(lastResponse);
      this.logger?.warn(`Discord rate limit: retrying ${method} ${path} in ${Math.ceil(waitMs / 1000)}s (attempt ${attempt}/${maxAttempts}).`);
      await sleep(Math.min(Math.max(waitMs, 250), 120000));
    }
    return lastResponse;
  }

  #requireSuccess(response, operation) {
    if (response && response.status >= 200 && response.status < 300) return;
    const status = response?.status ?? 0;
    let data = {};
    try { data = JSON.parse(response?.text || "{}"); } catch {}
    const code = data?.code ?? null;
    const details = String(data?.message || "").trim() || "empty Discord response";
    if (status === 401) throw new DiscordApiError("Discord authentication failed (HTTP 401). Check the bot token.", { status, code, body: response?.text });
    if (status === 403) throw new DiscordApiError(`Discord permission denied during ${operation} (HTTP 403).`, { status, code, body: response?.text });
    if (status === 429) throw new DiscordApiError(`Discord rate limit during ${operation} (HTTP 429).`, { status, code, body: response?.text });
    throw new DiscordApiError(`${operation} failed: HTTP ${status}: ${details}${code !== null ? ` (code ${code})` : ""}`, { status, code, body: response?.text });
  }

  async verifyBot() {
    const response = await this.#request("GET", "/users/@me");
    this.#requireSuccess(response, "verify bot token");
    const user = JSON.parse(response.text || "{}");
    const discriminator = user.discriminator && user.discriminator !== "0" ? `#${user.discriminator}` : "";
    return `${user.username || "unknown"}${discriminator}`;
  }

  async sendMessage(channelId, payload) {
    const channel = requireSnowflake(channelId, "Discord channel ID");
    const silentPayload = structuredClone(payload || {});
    silentPayload.flags = (Number(silentPayload.flags) || 0) | SUPPRESS_NOTIFICATIONS;
    silentPayload.allowed_mentions = silentPayload.allowed_mentions || { parse: [] };
    const response = await this.#request("POST", `/channels/${channel}/messages`, { body: silentPayload });
    this.#requireSuccess(response, "send Discord message");
    return JSON.parse(response.text || "{}");
  }

  async editMessageIfExists(channelId, messageId, payload) {
    const channel = requireSnowflake(channelId, "Discord channel ID");
    const message = requireSnowflake(messageId, "Discord message ID");
    const response = await this.#request("PATCH", `/channels/${channel}/messages/${message}`, { body: payload });
    if (response.status === 404) return false;
    this.#requireSuccess(response, "edit Discord message");
    return true;
  }

  async deleteMessageIfExists(channelId, messageId) {
    const channel = requireSnowflake(channelId, "Discord channel ID");
    const message = requireSnowflake(messageId, "Discord message ID");
    const response = await this.#request("DELETE", `/channels/${channel}/messages/${message}`);
    if (response.status === 404) return false;
    this.#requireSuccess(response, "delete Discord message");
    return true;
  }

  async getMessage(channelId, messageId) {
    const channel = requireSnowflake(channelId, "Discord channel ID");
    const message = requireSnowflake(messageId, "Discord message ID");
    const response = await this.#request("GET", `/channels/${channel}/messages/${message}`);
    if (response.status === 404) return null;
    this.#requireSuccess(response, "read Discord message");
    return JSON.parse(response.text || "{}");
  }

  async isMessageCrossposted(channelId, messageId) {
    const message = await this.getMessage(channelId, messageId);
    return Boolean(message && ((Number(message.flags) || 0) & CROSSPOSTED));
  }

  async crosspostMessage(channelId, messageId) {
    const channel = requireSnowflake(channelId, "Discord channel ID");
    const message = requireSnowflake(messageId, "Discord message ID");
    const response = await this.#request("POST", `/channels/${channel}/messages/${message}/crosspost`, { maxAttempts: 1 });
    // Discord code 40033 means the message has already been crossposted.
    if (response.status === 400) {
      try {
        const data = JSON.parse(response.text || "{}");
        if (Number(data.code) === 40033) return;
      } catch {}
    }
    this.#requireSuccess(response, "publish Discord announcement");
  }

  async clearChannel(channelId) {
    const channel = requireSnowflake(channelId, "Discord channel ID");
    let totalDeleted = 0;

    for (let page = 0; page < 50; page++) {
      const response = await this.#request("GET", `/channels/${channel}/messages?limit=100`);
      this.#requireSuccess(response, "read Discord messages");
      const messages = JSON.parse(response.text || "[]");
      if (!Array.isArray(messages) || messages.length === 0) break;

      const threshold = Date.now() - FOURTEEN_DAYS_MINUS_FIVE_MINUTES;
      const recent = [];
      const old = [];
      for (const item of messages) {
        if (!item?.id) continue;
        const created = Date.parse(item.timestamp || "");
        if (Number.isFinite(created) && created > threshold) recent.push(item.id);
        else old.push(item.id);
      }

      let deletedThisRound = 0;
      if (recent.length >= 2) {
        try {
          await this.#bulkDelete(channel, recent);
          deletedThisRound += recent.length;
        } catch (error) {
          this.logger?.warn(`Bulk delete failed; falling back to individual deletion: ${error.message}`);
          deletedThisRound += await this.#deleteIndividually(channel, recent, 250);
        }
      } else if (recent.length === 1) {
        deletedThisRound += await this.#deleteIndividually(channel, recent, 250);
      }
      deletedThisRound += await this.#deleteIndividually(channel, old, 550);
      totalDeleted += deletedThisRound;

      if (deletedThisRound === 0 || messages.length < 100) break;
      await sleep(500);
    }

    this.logger?.info(`Discord channel ${channel} cleared: ${totalDeleted} message(s) deleted.`);
    return totalDeleted;
  }

  async #bulkDelete(channelId, messageIds) {
    const response = await this.#request("POST", `/channels/${channelId}/messages/bulk-delete`, { body: { messages: messageIds } });
    this.#requireSuccess(response, "bulk delete Discord messages");
  }

  async #deleteIndividually(channelId, messageIds, delayMs) {
    let count = 0;
    for (const messageId of messageIds) {
      try {
        const deleted = await this.deleteMessageIfExists(channelId, messageId);
        if (deleted) count++;
      } catch (error) {
        this.logger?.warn(`Unable to delete Discord message ${messageId}: ${error.message}`);
      }
      if (delayMs) await sleep(delayMs);
    }
    return count;
  }
}

function parseRetryAfterMs(response) {
  const header = response?.headers?.get?.("retry-after");
  if (header) {
    const seconds = Number(header);
    if (Number.isFinite(seconds)) return Math.ceil(seconds * 1000);
  }
  try {
    const data = JSON.parse(response?.text || "{}");
    const seconds = Number(data.retry_after);
    if (Number.isFinite(seconds)) return Math.ceil(seconds * 1000);
  } catch {}
  return 1000;
}

function normalizeToken(input) {
  let text = String(input ?? "").trim();
  if (!text) return "";
  if (text.startsWith("DISCORD_TOKEN=")) text = text.slice("DISCORD_TOKEN=".length).trim();
  if (/^Bot\s+/i.test(text)) text = text.replace(/^Bot\s+/i, "").trim();
  if ((text.startsWith('"') && text.endsWith('"')) || (text.startsWith("'") && text.endsWith("'"))) {
    text = text.slice(1, -1).trim();
  }
  return text;
}
