import { cacheBusted, sha256, sleep, stableStringify } from "../utils.js";
import { getJson, getText } from "../http.js";
import { hasDiscordPayload, normalizeMessage, putGuideLinkBelowImage } from "../discord/payload.js";
import { syncEvents } from "./events.js";

export async function syncBoard({
  key,
  label,
  enabled,
  channelId,
  jsonUrl,
  generatorUrl = "",
  generatorDelaySeconds = 0,
  discord,
  store,
  logger,
  crosspostAnnouncements = true
}) {
  if (!enabled) {
    logger.info(`${label}: disabled.`);
    return 0;
  }

  if (generatorUrl) {
    logger.info(`${label}: calling generator ${generatorUrl}`);
    await getText(cacheBusted(generatorUrl));
    if (generatorDelaySeconds > 0) await sleep(Math.min(generatorDelaySeconds, 30) * 1000);
  }

  logger.info(`${label}: reading ${jsonUrl}`);
  const root = await getJson(cacheBusted(jsonUrl));
  if (!root || !Array.isArray(root.messages)) {
    throw new Error(`${label}: invalid JSON, missing messages array.`);
  }

  if (key === "events") {
    const changed = await syncEvents({
      discord,
      store,
      logger,
      channelId,
      messages: root.messages,
      crosspostAnnouncements
    });
    logger.info(changed ? `${label}: ${changed} change(s) applied.` : `${label}: no change detected.`);
    return changed;
  }

  const renderingRevision = key === "guides" ? "guides-embed-footer-v1|" : "";
  const hash = sha256(`${renderingRevision}${stableStringify(root.messages)}`);
  if (store.getBoardHash(key) === hash) {
    logger.info(`${label}: no change detected.`);
    return 0;
  }

  await discord.clearChannel(channelId);
  let published = 0;
  for (const raw of root.messages) {
    let payload = normalizeMessage(raw);
    if (key === "guides") payload = putGuideLinkBelowImage(payload, raw);
    if (!hasDiscordPayload(payload)) continue;
    await discord.sendMessage(channelId, payload);
    published++;
    await sleep(800);
  }

  store.setBoardHash(key, hash);
  logger.info(`${label}: ${published} Discord message(s) published.`);
  return published;
}
