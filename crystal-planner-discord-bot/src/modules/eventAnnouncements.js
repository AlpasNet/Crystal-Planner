import { cacheBusted, sha256, sleep, stableStringify } from "../utils.js";
import { getJson, getText } from "../http.js";

const TITLE = "Evénements disponibles / Available Events";
const EMPTY_TEXT = "Aucun événement disponible pour le moment. / No event is currently available.";

export async function syncEventAnnouncements({
  config,
  discord,
  store,
  logger,
  callGenerator = false
}) {
  const settings = config.eventAnnouncements;
  if (!settings?.enabled) {
    logger.info("Event announcements summary: disabled.");
    return 0;
  }

  if (callGenerator) {
    logger.info(`Event announcements summary: calling generator ${config.urls.generator}`);
    await getText(cacheBusted(config.urls.generator));
    if (config.jsonReadDelaySeconds > 0) {
      await sleep(Math.min(config.jsonReadDelaySeconds, 30) * 1000);
    }
  }

  logger.info(`Event announcements summary: reading ${config.urls.events}`);
  const root = await getJson(cacheBusted(config.urls.events));
  if (!root || !Array.isArray(root.messages)) {
    throw new Error("Event announcements summary: invalid discord-bot-datas.json, missing messages array.");
  }

  const availableEvents = extractAvailableEvents(root.messages);
  const payload = buildAnnouncementPayload({
    events: availableEvents,
    discordUrl: settings.discordUrl,
    imageUrl: settings.imageUrl
  });
  const hash = sha256(`event-announcements-v1|${stableStringify(payload)}`);
  const state = store.getEventAnnouncementState();

  let changed = 0;
  let messageId = String(state.messageId ?? "").trim();
  let crossposted = Boolean(state.crossposted) || settings.publishAnnouncements === false;
  const previousChannel = String(state.channelId ?? "").trim();

  if (previousChannel && previousChannel !== settings.channelId && messageId) {
    try {
      await discord.deleteMessageIfExists(previousChannel, messageId);
      logger.info(`Event announcements summary: removed tracked message ${messageId} from old channel ${previousChannel}.`);
    } catch (error) {
      logger.warn(`Event announcements summary: unable to remove old tracked message ${messageId}: ${error.message}`);
    }
    messageId = "";
    crossposted = settings.publishAnnouncements === false;
  }

  if (!messageId) {
    const created = await discord.sendMessage(settings.channelId, payload);
    messageId = String(created?.id ?? "").trim();
    if (!messageId) throw new Error("Discord did not return the Event announcements summary message ID.");
    state.channelId = settings.channelId;
    state.messageId = messageId;
    state.hash = hash;
    state.crossposted = crossposted;
    store.saveEventAnnouncementState();
    logger.info(`Event announcements summary: created Discord message ${messageId} with ${availableEvents.length} event(s).`);
    if (settings.publishAnnouncements !== false) {
      crossposted = await tryPublishAnnouncement({ discord, logger, channelId: settings.channelId, messageId });
      state.crossposted = crossposted;
      store.saveEventAnnouncementState();
    }
    return 1;
  }

  if (state.hash !== hash || state.channelId !== settings.channelId) {
    const edited = await discord.editMessageIfExists(settings.channelId, messageId, payload);
    if (!edited) {
      const created = await discord.sendMessage(settings.channelId, payload);
      messageId = String(created?.id ?? "").trim();
      if (!messageId) throw new Error("Discord did not return the recreated Event announcements summary message ID.");
      crossposted = settings.publishAnnouncements === false;
      logger.info(`Event announcements summary: recreated missing Discord message ${messageId}.`);
    } else {
      logger.info(`Event announcements summary: updated Discord message ${messageId} with ${availableEvents.length} event(s).`);
    }
    changed = 1;
  } else {
    // Even without a content change, verify that the tracked summary still exists.
    const existing = await discord.getMessage(settings.channelId, messageId);
    if (!existing) {
      const created = await discord.sendMessage(settings.channelId, payload);
      messageId = String(created?.id ?? "").trim();
      if (!messageId) throw new Error("Discord did not return the recreated Event announcements summary message ID.");
      crossposted = settings.publishAnnouncements === false;
      logger.info(`Event announcements summary: recreated manually deleted Discord message ${messageId}.`);
      changed = 1;
    }
  }

  if (settings.publishAnnouncements !== false && !crossposted && messageId) {
    crossposted = await tryPublishAnnouncement({ discord, logger, channelId: settings.channelId, messageId });
    if (crossposted) changed++;
  }

  state.channelId = settings.channelId;
  state.messageId = messageId;
  state.hash = hash;
  state.crossposted = crossposted;
  store.saveEventAnnouncementState();
  return changed;
}

export function extractAvailableEvents(messages) {
  const events = [];
  for (const raw of Array.isArray(messages) ? messages : []) {
    if (!raw || typeof raw !== "object") continue;
    const embed = Array.isArray(raw.embeds) ? raw.embeds[0] : null;
    if (!embed || typeof embed !== "object") continue;

    const rawTitle = String(embed.title ?? "").trim();
    if (!rawTitle) continue;
    // Poll entries generated by Cozy Events use the chart prefix and are not
    // part of the "Available Events" announcement.
    if (/^📊/u.test(rawTitle)) continue;

    const title = rawTitle.replace(/^📅\s*/u, "").trim() || rawTitle;
    const fields = Array.isArray(embed.fields) ? embed.fields : [];
    const start = String(fields[0]?.value ?? "").trim();
    const end = String(fields[1]?.value ?? "").trim();
    if (!start && !end) continue;

    events.push({
      title,
      start: start || "—",
      end: end || "—",
      startTimestamp: discordTimestampValue(start)
    });
  }

  events.sort((a, b) => {
    const aTime = Number.isFinite(a.startTimestamp) ? a.startTimestamp : Number.MAX_SAFE_INTEGER;
    const bTime = Number.isFinite(b.startTimestamp) ? b.startTimestamp : Number.MAX_SAFE_INTEGER;
    return aTime - bTime || a.title.localeCompare(b.title, undefined, { sensitivity: "base" });
  });
  return events;
}

export function buildAnnouncementPayload({ events, discordUrl, imageUrl }) {
  const blocks = (Array.isArray(events) ? events : []).map(event =>
    `📅 **${sanitizeTitle(event.title)}**\n` +
    `Début / Start : ${event.start}\n` +
    `Fin / End : ${event.end}`
  );

  let description = blocks.length ? blocks.join("\n\n") : EMPTY_TEXT;
  const linkLine = `🔗 **Discord:** ${discordUrl}`;
  const maximumBeforeLink = 4096 - linkLine.length - 4;
  if (description.length > maximumBeforeLink) {
    description = `${description.slice(0, Math.max(0, maximumBeforeLink - 80)).trimEnd()}\n\n… Liste abrégée / List truncated`;
  }
  description = `${description}\n\n${linkLine}`;

  const embed = {
    title: TITLE,
    description,
    color: 0x5865f2
  };
  if (imageUrl) embed.image = { url: imageUrl };

  return {
    content: "",
    embeds: [embed]
  };
}

function discordTimestampValue(value) {
  const match = String(value ?? "").match(/<t:(\d{1,12})(?::[A-Za-z])?>/);
  return match ? Number(match[1]) : Number.NaN;
}

function sanitizeTitle(value) {
  return String(value ?? "")
    .replace(/\r?\n/g, " ")
    .replace(/\*\*/g, "")
    .trim()
    .slice(0, 180) || "Event";
}

async function tryPublishAnnouncement({ discord, logger, channelId, messageId }) {
  try {
    await discord.crosspostMessage(channelId, messageId);
    logger.info(`Event announcements summary: published announcement ${messageId}.`);
    return true;
  } catch (error) {
    try {
      if (await discord.isMessageCrossposted(channelId, messageId)) {
        logger.info(`Event announcements summary: announcement ${messageId} was already published.`);
        return true;
      }
    } catch {}
    logger.warn(`Event announcements summary: unable to publish ${messageId}: ${error.message}. Ensure eventAnnouncements.channelId is a Discord Announcement channel and the bot can publish messages. It will retry later.`);
    return false;
  }
}
