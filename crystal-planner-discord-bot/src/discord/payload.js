import { truncate } from "../utils.js";

function putIfNotBlank(target, key, value) {
  const text = String(value ?? "").trim();
  if (text) target[key] = text;
}

function normalizeNamedObject(raw, key, maxNameLength, includeUrl) {
  const source = raw?.[key];
  if (!source || typeof source !== "object") return undefined;
  const nameKey = key === "footer" ? "text" : "name";
  const name = truncate(source[nameKey], maxNameLength);
  if (!name) return undefined;
  const result = { [nameKey]: name };
  if (includeUrl) putIfNotBlank(result, "url", source.url);
  putIfNotBlank(result, "icon_url", source.icon_url);
  return result;
}

function normalizeUrlObject(raw, key) {
  const source = raw?.[key];
  const url = String(source?.url ?? "").trim();
  return url ? { url } : undefined;
}

export function normalizeEmbed(raw = {}) {
  const embed = {};
  putIfNotBlank(embed, "title", truncate(raw.title, 256));
  putIfNotBlank(embed, "description", truncate(raw.description, 4096));
  putIfNotBlank(embed, "url", raw.url);
  if (Object.prototype.hasOwnProperty.call(raw, "color") && Number.isFinite(Number(raw.color))) {
    embed.color = Number(raw.color) | 0;
  }
  if (Object.prototype.hasOwnProperty.call(raw, "timestamp")) {
    putIfNotBlank(embed, "timestamp", raw.timestamp);
  }

  const author = normalizeNamedObject(raw, "author", 256, true);
  const image = normalizeUrlObject(raw, "image");
  const thumbnail = normalizeUrlObject(raw, "thumbnail");
  const footer = normalizeNamedObject(raw, "footer", 2048, false);
  if (author) embed.author = author;
  if (image) embed.image = image;
  if (thumbnail) embed.thumbnail = thumbnail;
  if (footer) embed.footer = footer;

  if (Array.isArray(raw.fields)) {
    const fields = raw.fields.slice(0, 25).map(field => ({
      name: truncate(field?.name, 256) || "Field",
      value: truncate(field?.value, 1024) || "-",
      inline: Boolean(field?.inline)
    }));
    if (fields.length) embed.fields = fields;
  }
  return embed;
}

export function normalizeMessage(raw = {}) {
  const message = {};
  const content = truncate(raw.content, 2000);
  if (content) message.content = content;
  if (Array.isArray(raw.embeds)) {
    const embeds = raw.embeds.slice(0, 10).filter(value => value && typeof value === "object").map(normalizeEmbed);
    if (embeds.length) message.embeds = embeds;
  }
  message.allowed_mentions = { parse: [] };
  return message;
}

export function hasDiscordPayload(payload) {
  return Boolean(String(payload?.content ?? "").trim()) || (Array.isArray(payload?.embeds) && payload.embeds.length > 0);
}

export function putGuideLinkBelowImage(payload, rawMessage) {
  const embeds = payload?.embeds;
  const rawEmbeds = rawMessage?.embeds;
  if (!Array.isArray(embeds) || !embeds.length || !Array.isArray(rawEmbeds)) return payload;
  const link = rawEmbeds.map(embed => String(embed?.url ?? "").trim())
    .find(url => url.startsWith("https://") || url.startsWith("http://"));
  if (link) embeds[0].footer = { text: link };
  return payload;
}
