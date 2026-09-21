import fs from "node:fs";
import path from "node:path";
import { assertHttps, isSnowflake, normalizeFolder } from "./utils.js";

const DEFAULT_CONFIG = {
  syncIntervalMinutes: 15,
  webFolderUrl: "",
  jsonReadDelaySeconds: 3,
  events: { enabled: false, channelId: "", crosspostAnnouncements: false },
  eventAnnouncements: { enabled: false, channelId: "", discordUrl: "", imageUrl: "", publishAnnouncements: true },
  rules: { enabled: false, channelId: "" },
  guides: { enabled: false, channelId: "" },
  macros: { enabled: false, channelId: "" },
  lodestone: {
    enabled: true,
    newsChannelId: "",
    topicsChannelId: ""
  }
};

export function loadDotEnv(filePath = path.resolve(".env")) {
  if (!fs.existsSync(filePath)) return;
  const content = fs.readFileSync(filePath, "utf8");
  for (const line of content.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;
    const eq = trimmed.indexOf("=");
    if (eq <= 0) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!(key in process.env)) process.env[key] = value;
  }
}

function firstObject(...values) {
  for (const value of values) {
    if (value && typeof value === "object" && !Array.isArray(value)) return value;
  }
  return {};
}

function configBoolean(value, fallback = false) {
  if (value === undefined || value === null || value === "") return fallback;
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value !== 0;
  const normalized = String(value).trim().toLowerCase();
  if (["1", "true", "yes", "on", "enabled"].includes(normalized)) return true;
  if (["0", "false", "no", "off", "disabled"].includes(normalized)) return false;
  return Boolean(value);
}

function mergeConfig(raw = {}) {
  const announcementRaw = firstObject(
    raw.eventAnnouncements,
    raw.eventAnnouncement,
    raw.announcements,
    raw["event-announcements"],
    raw.events?.eventAnnouncements,
    raw.events?.eventAnnouncement,
    raw.events?.announcements
  );
  const mergedAnnouncements = { ...DEFAULT_CONFIG.eventAnnouncements, ...announcementRaw };
  // If a complete announcement block is present but `enabled` was omitted,
  // consider it enabled. An explicit enabled:false always wins.
  if (!("enabled" in announcementRaw) && Object.keys(announcementRaw).length > 0) {
    mergedAnnouncements.enabled = true;
  }
  return {
    ...DEFAULT_CONFIG,
    ...raw,
    events: { ...DEFAULT_CONFIG.events, ...(raw.events || {}) },
    eventAnnouncements: mergedAnnouncements,
    rules: { ...DEFAULT_CONFIG.rules, ...(raw.rules || {}) },
    guides: { ...DEFAULT_CONFIG.guides, ...(raw.guides || {}) },
    macros: { ...DEFAULT_CONFIG.macros, ...(raw.macros || {}) },
    lodestone: { ...DEFAULT_CONFIG.lodestone, ...(raw.lodestone || {}) }
  };
}

export function loadConfig(configPath) {
  if (!fs.existsSync(configPath)) {
    throw new Error(`Configuration file not found: ${configPath}`);
  }
  let raw;
  try {
    raw = JSON.parse(fs.readFileSync(configPath, "utf8"));
  } catch (error) {
    throw new Error(`Unable to parse configuration ${configPath}: ${error.message}`);
  }
  const config = mergeConfig(raw);
  validateConfig(config);
  return deriveUrls(config);
}

export function validateConfig(config) {
  const interval = Number(config.syncIntervalMinutes);
  if (!Number.isFinite(interval) || interval < 1 || interval > 1440) {
    throw new Error("syncIntervalMinutes must be between 1 and 1440.");
  }
  config.syncIntervalMinutes = Math.floor(interval);

  const delay = Number(config.jsonReadDelaySeconds);
  if (!Number.isFinite(delay) || delay < 0 || delay > 30) {
    throw new Error("jsonReadDelaySeconds must be between 0 and 30.");
  }
  config.jsonReadDelaySeconds = Math.floor(delay);

  config.webFolderUrl = normalizeFolder(config.webFolderUrl);
  const webBoardsEnabled = [config.events, config.rules, config.guides, config.macros, config.eventAnnouncements].some(board => Boolean(board.enabled));
  if (webBoardsEnabled) {
    if (!config.webFolderUrl) throw new Error("webFolderUrl is required when a Web board is enabled.");
    assertHttps(config.webFolderUrl, "webFolderUrl");
  }

  for (const [name, board] of Object.entries({ events: config.events, rules: config.rules, guides: config.guides, macros: config.macros })) {
    board.enabled = Boolean(board.enabled);
    board.channelId = String(board.channelId ?? "").trim();
    if (board.enabled && !isSnowflake(board.channelId)) {
      throw new Error(`${name}.channelId must be a valid Discord channel ID when enabled.`);
    }
  }
  // Since 1.1.7 the regular Events/Polls channel is never crossposted.
  // Announcements are published only from eventAnnouncements.channelId.
  config.events.crosspostAnnouncements = false;

  config.eventAnnouncements.enabled = configBoolean(config.eventAnnouncements.enabled, false);
  config.eventAnnouncements.channelId = String(config.eventAnnouncements.channelId ?? "").trim();
  config.eventAnnouncements.discordUrl = String(config.eventAnnouncements.discordUrl ?? "").trim();
  config.eventAnnouncements.imageUrl = String(config.eventAnnouncements.imageUrl ?? "").trim();
  config.eventAnnouncements.publishAnnouncements = configBoolean(config.eventAnnouncements.publishAnnouncements, true);
  if (config.eventAnnouncements.enabled) {
    if (!isSnowflake(config.eventAnnouncements.channelId)) {
      throw new Error("eventAnnouncements.channelId must be a valid Discord channel ID when enabled.");
    }
    if (!config.eventAnnouncements.discordUrl) throw new Error("eventAnnouncements.discordUrl is required when enabled.");
    if (!config.eventAnnouncements.imageUrl) throw new Error("eventAnnouncements.imageUrl is required when enabled.");
    assertHttps(config.eventAnnouncements.discordUrl, "eventAnnouncements.discordUrl");
    assertHttps(config.eventAnnouncements.imageUrl, "eventAnnouncements.imageUrl");
  }

  config.lodestone.enabled = Boolean(config.lodestone.enabled);

  // v1.0 compatibility: an old configuration may still contain the three
  // HTML-category channels. The first configured legacy News channel is used
  // only as a fallback until newsChannelId is explicitly set.
  const legacyNewsChannels = [
    config.lodestone.noticesChannelId,
    config.lodestone.maintenanceChannelId,
    config.lodestone.updatesChannelId
  ].map(value => String(value ?? "").trim()).filter(Boolean);
  config.lodestone.newsChannelId = String(config.lodestone.newsChannelId ?? legacyNewsChannels[0] ?? "").trim();
  config.lodestone.topicsChannelId = String(config.lodestone.topicsChannelId ?? "").trim();
  for (const key of ["newsChannelId", "topicsChannelId"]) {
    if (config.lodestone[key] && !isSnowflake(config.lodestone[key])) {
      throw new Error(`lodestone.${key} is not a valid Discord channel ID.`);
    }
  }
}

export function deriveUrls(config) {
  const folder = normalizeFolder(config.webFolderUrl);
  const join = filename => folder ? `${folder}/${filename}` : "";
  return {
    ...config,
    urls: {
      generator: join("generate_discord_bot_datas.php"),
      events: join("discord-bot-datas.json"),
      rules: join("discord-rules.json"),
      guides: join("discord-guides.json"),
      macros: join("discord-macros.json")
    }
  };
}
