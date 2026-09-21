import crypto from "node:crypto";

const IGNORED_COMPARE_KEYS = new Set([
  "timestamp", "generated_at", "generatedAt", "updated_at", "updatedAt",
  "created_at", "createdAt", "cache_buster", "cacheBuster"
]);

export function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

export function truncate(value, maxLength) {
  const text = String(value ?? "").trim();
  if (text.length <= maxLength) return text;
  if (maxLength <= 3) return text.slice(0, maxLength);
  return `${text.slice(0, maxLength - 3)}...`;
}

export function sha256(value) {
  return crypto.createHash("sha256").update(String(value), "utf8").digest("hex");
}

export function stableStringify(value) {
  if (value === null || value === undefined) return "null";
  if (Array.isArray(value)) {
    return `[${value.map(stableStringify).join(",")}]`;
  }
  if (typeof value === "object") {
    const keys = Object.keys(value).filter(key => !IGNORED_COMPARE_KEYS.has(key)).sort();
    return `{${keys.map(key => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(",")}}`;
  }
  return JSON.stringify(value);
}

export function cacheBusted(url) {
  const separator = url.includes("?") ? "&" : "?";
  return `${url}${separator}_=${Date.now()}`;
}

export function normalizeFolder(value) {
  return String(value ?? "").trim().replace(/\/+$/, "");
}

export function assertHttps(rawUrl, label = "URL") {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    throw new Error(`${label} is invalid: ${rawUrl}`);
  }
  if (parsed.protocol !== "https:") {
    throw new Error(`${label} must use HTTPS: ${rawUrl}`);
  }
  return parsed.toString();
}

export function isSnowflake(value) {
  return /^[0-9]{15,22}$/.test(String(value ?? "").trim());
}

export function requireSnowflake(value, label = "Discord ID") {
  const id = String(value ?? "").trim();
  if (!isSnowflake(id)) throw new Error(`${label} is invalid: ${id || "<empty>"}`);
  return id;
}

export function firstNonBlank(...values) {
  for (const value of values) {
    const text = String(value ?? "").trim();
    if (text) return text;
  }
  return "";
}
