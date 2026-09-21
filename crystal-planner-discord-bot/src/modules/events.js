import { hasDiscordPayload, normalizeMessage } from "../discord/payload.js";
import { firstNonBlank, sha256, stableStringify } from "../utils.js";

export function buildEventEntries(messages) {
  const result = new Map();
  const occurrences = new Map();
  messages.forEach((raw, index) => {
    if (!raw || typeof raw !== "object") return;
    const payload = normalizeMessage(raw);
    if (!hasDiscordPayload(payload)) return;
    const baseKey = eventIdentity(raw, payload, index);
    const occurrence = (occurrences.get(baseKey) || 0) + 1;
    occurrences.set(baseKey, occurrence);
    const key = occurrence === 1 ? baseKey : `${baseKey}-${occurrence}`;
    const hash = sha256(`event-payload-v1|${stableStringify(payload)}`);
    result.set(key, { payload, hash });
  });
  return result;
}

export function eventIdentity(raw, payload, fallbackIndex) {
  let explicitId = firstNonBlank(raw?.event_id, raw?.eventId, raw?.id);
  if (explicitId) return `id-${safeKeyPart(explicitId)}`;

  const rawEmbed = Array.isArray(raw?.embeds) ? raw.embeds[0] : null;
  explicitId = firstNonBlank(rawEmbed?.event_id, rawEmbed?.eventId, rawEmbed?.id);
  if (explicitId) return `id-${safeKeyPart(explicitId)}`;

  const embed = Array.isArray(payload?.embeds) ? payload.embeds[0] : null;
  const imageUrl = String(embed?.image?.url ?? "").trim();
  if (imageUrl) return `image-${sha256(imageUrl).slice(0, 32)}`;

  const title = String(embed?.title ?? "").trim();
  const startValue = String(embed?.fields?.[0]?.value ?? "").trim();
  let basis = `${title}|${startValue}`;
  if (!basis.replace("|", "").trim()) {
    basis = `fallback-index-${fallbackIndex}|${stableStringify(payload)}`;
  }
  return `derived-${sha256(basis).slice(0, 32)}`;
}

function safeKeyPart(value) {
  const cleaned = String(value ?? "").trim().replace(/[^A-Za-z0-9_-]/g, "_").slice(0, 80);
  return cleaned || "unknown";
}

export async function syncEvents({ discord, store, logger, channelId, messages, crosspostAnnouncements = true }) {
  const currentEvents = buildEventEntries(messages);
  const eventState = store.getEventState();

  if (!eventState.initialized || eventState.channelId !== channelId) {
    logger.info("Events/Polls: initializing individual Discord message tracking. The configured channel will be cleared once.");
    await discord.clearChannel(channelId);
    eventState.channelId = channelId;
    eventState.initialized = true;
    eventState.messages = {};
    store.saveEventState();
  }

  let changed = 0;
  const currentKeys = new Set();

  for (const [eventKey, event] of currentEvents.entries()) {
    currentKeys.add(eventKey);
    let record = eventState.messages[eventKey] || {};
    let messageId = String(record.message_id ?? "").trim();
    const previousHash = String(record.hash ?? "").trim();
    let crossposted = Boolean(record.crossposted) || !crosspostAnnouncements;
    let crosspostAttemptedThisRun = false;

    if (!messageId) {
      const created = await discord.sendMessage(channelId, event.payload);
      messageId = String(created?.id ?? "").trim();
      if (!messageId) throw new Error("Discord did not return the created Event message ID.");

      // Persist first so a crash between creation and crosspost cannot create a duplicate.
      eventState.messages[eventKey] = eventRecord(messageId, event.hash, false, channelId);
      store.saveEventState();

      if (crosspostAnnouncements) {
        crossposted = await tryCrosspost({ discord, logger, channelId, messageId });
        crosspostAttemptedThisRun = true;
      }
      changed++;
      logger.info(`Events/Polls: created Discord message ${messageId}.`);
    } else if (event.hash !== previousHash) {
      const edited = await discord.editMessageIfExists(channelId, messageId, event.payload);
      if (!edited) {
        const created = await discord.sendMessage(channelId, event.payload);
        messageId = String(created?.id ?? "").trim();
        if (!messageId) throw new Error("Discord did not return the recreated Event message ID.");
        crossposted = !crosspostAnnouncements;
        eventState.messages[eventKey] = eventRecord(messageId, event.hash, crossposted, channelId);
        store.saveEventState();

        if (crosspostAnnouncements) {
          crossposted = await tryCrosspost({ discord, logger, channelId, messageId });
          crosspostAttemptedThisRun = true;
        }
        logger.info(`Events/Polls: recreated missing Discord message ${messageId}.`);
      } else {
        logger.info(`Events/Polls: updated Discord message ${messageId}.`);
      }
      changed++;
    }

    if (crosspostAnnouncements && !crossposted && !crosspostAttemptedThisRun) {
      crossposted = await tryCrosspost({ discord, logger, channelId, messageId });
      if (crossposted) changed++;
    }

    eventState.messages[eventKey] = eventRecord(messageId, event.hash, crossposted, channelId);
    store.saveEventState();
  }

  for (const key of Object.keys(eventState.messages)) {
    if (currentKeys.has(key)) continue;
    const record = eventState.messages[key] || {};
    const messageId = String(record.message_id ?? "").trim();
    const messageChannel = String(record.channel_id ?? channelId).trim() || channelId;
    if (messageId) {
      await discord.deleteMessageIfExists(messageChannel, messageId);
      logger.info(`Events/Polls: deleted obsolete Discord message ${messageId}.`);
      changed++;
    }
    delete eventState.messages[key];
    store.saveEventState();
  }

  store.setBoardHash("events", sha256(`events-individual-v1|${stableStringify(messages)}`));
  return changed;
}

function eventRecord(messageId, hash, crossposted, channelId) {
  return {
    message_id: messageId,
    hash,
    crossposted: Boolean(crossposted),
    channel_id: channelId
  };
}

async function tryCrosspost({ discord, logger, channelId, messageId }) {
  try {
    await discord.crosspostMessage(channelId, messageId);
    logger.info(`Events/Polls: announcement published for Discord message ${messageId}.`);
    return true;
  } catch (error) {
    try {
      if (await discord.isMessageCrossposted(channelId, messageId)) {
        logger.info(`Events/Polls: announcement was already published for Discord message ${messageId}.`);
        return true;
      }
    } catch {}
    logger.warn(`Events/Polls: automatic announcement publication failed for ${messageId}: ${error.message}. It will be retried on a later synchronization.`);
    return false;
  }
}
