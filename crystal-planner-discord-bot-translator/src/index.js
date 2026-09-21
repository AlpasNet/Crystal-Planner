import path from 'node:path';
import {
  AttachmentBuilder,
  Client,
  GatewayIntentBits,
  MessageFlags,
  Partials,
  PermissionFlagsBits
} from 'discord.js';
import { loadConfig } from './config.js';
import { BergamotService } from './translator/BergamotService.js';
import { MessageStore } from './storage/MessageStore.js';
import { WebhookManager } from './discord/WebhookManager.js';
import { splitTranslatedMessage, translationFooter } from './discord/message-format.js';
import { logger } from './utils/logger.js';
import { SerialFifoQueue } from './queue/SerialFifoQueue.js';

const config = await loadConfig();
const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.MessageContent
  ],
  partials: [Partials.Message, Partials.Channel]
});

const translator = new BergamotService(config);
const store = new MessageStore(path.join(config.rootDir, 'data/messages.json'), config.retentionMs);
const webhooks = new WebhookManager(client, path.join(config.rootDir, 'data/webhooks.json'), config.webhookName);

const relayQueue = new SerialFifoQueue({
  onStart: ({ id, label, pending }) => logger.info(`FIFO #${id} started: ${label} (${pending} queued/in progress)`),
  onFinish: ({ id, label, pending }) => logger.info(`FIFO #${id} finished: ${label} (${pending} remaining)`)
});

const routes = new Map([
  [config.channels.fr, { from: 'fr', to: 'en', targetChannelId: config.channels.en }],
  [config.channels.en, { from: 'en', to: 'fr', targetChannelId: config.channels.fr }]
]);

function displayName(message) {
  return message.member?.displayName || message.author.globalName || message.author.username;
}

function avatarUrl(message) {
  return message.member?.displayAvatarURL({ extension: 'png', size: 128 }) ||
    message.author.displayAvatarURL({ extension: 'png', size: 128 });
}

function withinEditWindow(entry) {
  return Date.now() - Number(entry.createdAt || 0) <= config.editWindowMs;
}

async function replyPrefix(message, route) {
  const referencedId = message.reference?.messageId;
  if (!referencedId) return '';
  const mapped = store.get(referencedId);
  if (!mapped || mapped.targetChannelId !== route.targetChannelId || !mapped.translatedMessageIds?.[0]) return '';
  const url = `https://discord.com/channels/${message.guildId}/${mapped.targetChannelId}/${mapped.translatedMessageIds[0]}`;
  return `-# ↪ [Reply to translated message](${url})`;
}

function attachmentFiles(message) {
  if (!config.copyAttachments) return [];
  return [...message.attachments.values()].map((attachment) =>
    new AttachmentBuilder(attachment.url, { name: attachment.name || 'attachment' })
  );
}

async function getTargetChannel(channelId) {
  const channel = await client.channels.fetch(channelId);
  if (!channel?.isTextBased() || typeof channel.createWebhook !== 'function') {
    throw new Error(`Target channel ${channelId} is not a webhook-capable text channel.`);
  }
  return channel;
}

async function sendViaWebhook(targetChannel, payload) {
  const silentPayload = {
    ...payload,
    flags: MessageFlags.SuppressNotifications
  };

  let webhook = await webhooks.get(targetChannel);
  try {
    return await webhook.send(silentPayload);
  } catch (error) {
    if (![10015, 50027].includes(error.code)) throw error;
    await webhooks.reset(targetChannel.id);
    webhook = await webhooks.get(targetChannel);
    return webhook.send(silentPayload);
  }
}

async function editViaWebhook(targetChannel, messageId, content) {
  let webhook = await webhooks.get(targetChannel);
  try {
    return await webhook.editMessage(messageId, {
      content,
      allowedMentions: { parse: [] }
    });
  } catch (error) {
    if (![10015, 50027].includes(error.code)) throw error;
    await webhooks.reset(targetChannel.id);
    throw new Error('Webhook credentials changed; existing translated messages cannot be edited with the new webhook.');
  }
}

async function translateContent(message, route) {
  const translated = await translator.translate(message.content || '', route.from, route.to);
  const prefix = await replyPrefix(message, route);
  const footer = translationFooter(route.from, route.to, config.translationLabel);
  return splitTranslatedMessage(translated, { prefix, footer });
}

client.once('ready', async () => {
  logger.info(`Connected as ${client.user.tag}`);
  await Promise.all([store.load(), webhooks.load()]);

  for (const [channelId, route] of routes) {
    const source = await client.channels.fetch(channelId);
    const target = await client.channels.fetch(route.targetChannelId);
    if (!source?.isTextBased() || !target?.isTextBased()) {
      throw new Error('Configured FR/EN channel IDs must point to text channels.');
    }

    const me = target.guild?.members.me;
    const permissions = target.permissionsFor(me);
    const required = [
      PermissionFlagsBits.ViewChannel,
      PermissionFlagsBits.SendMessages,
      PermissionFlagsBits.ManageWebhooks,
      PermissionFlagsBits.ReadMessageHistory
    ];
    const missing = required.filter((permission) => !permissions?.has(permission));
    if (missing.length) logger.warn(`Missing Discord permissions in #${target.name}: ${missing.join(', ')}`);
  }

  logger.info('FR ↔ EN relay is ready. Models are downloaded on first use (or with npm run warmup).');
});

async function handleMessageCreate(message) {
  const route = routes.get(message.channelId);
  if (!route || !message.guildId || message.author.bot || message.webhookId) return;

  const targetChannel = await getTargetChannel(route.targetChannelId);
  const chunks = await translateContent(message, route);
  const translatedMessageIds = [];

  for (let i = 0; i < chunks.length; i++) {
    const sent = await sendViaWebhook(targetChannel, {
      content: chunks[i],
      username: displayName(message).slice(0, 80),
      avatarURL: avatarUrl(message),
      files: i === 0 ? attachmentFiles(message) : [],
      allowedMentions: { parse: [] }
    });
    translatedMessageIds.push(sent.id);
  }

  await store.set(message.id, {
    translatedMessageIds,
    guildId: message.guildId,
    sourceChannelId: message.channelId,
    targetChannelId: route.targetChannelId,
    sourceLang: route.from,
    targetLang: route.to,
    createdAt: message.createdTimestamp || Date.now()
  });
}

async function handleMessageUpdate(newMessage) {
  if (newMessage.partial) newMessage = await newMessage.fetch();
  const entry = store.get(newMessage.id);
  if (!entry || !withinEditWindow(entry) || newMessage.author.bot || newMessage.webhookId) return;

  const route = routes.get(newMessage.channelId);
  if (!route) return;
  const targetChannel = await getTargetChannel(entry.targetChannelId);
  const chunks = await translateContent(newMessage, route);
  const ids = [...(entry.translatedMessageIds || [])];

  const common = Math.min(ids.length, chunks.length);
  for (let i = 0; i < common; i++) {
    await editViaWebhook(targetChannel, ids[i], chunks[i]);
  }

  if (chunks.length > ids.length) {
    for (let i = ids.length; i < chunks.length; i++) {
      const sent = await sendViaWebhook(targetChannel, {
        content: chunks[i],
        username: displayName(newMessage).slice(0, 80),
        avatarURL: avatarUrl(newMessage),
        allowedMentions: { parse: [] }
      });
      ids.push(sent.id);
    }
  } else if (ids.length > chunks.length) {
    const webhook = await webhooks.get(targetChannel);
    const surplus = ids.splice(chunks.length);
    for (const id of surplus) await webhook.deleteMessage(id).catch(() => {});
  }

  await store.set(newMessage.id, { ...entry, translatedMessageIds: ids });
  logger.info(`Updated translation for message ${newMessage.id}`);
}

async function handleMessageDelete(message) {
  const entry = store.get(message.id);
  if (!entry) return;

  if (config.syncDeletesWithinEditWindow && withinEditWindow(entry)) {
    const targetChannel = await getTargetChannel(entry.targetChannelId);
    const webhook = await webhooks.get(targetChannel);
    for (const id of entry.translatedMessageIds || []) {
      await webhook.deleteMessage(id).catch(() => {});
    }
  }
  await store.delete(message.id);
}

client.on('messageCreate', (message) => {
  const route = routes.get(message.channelId);
  if (!route || !message.guildId || message.author.bot || message.webhookId) return;

  relayQueue
    .enqueue(() => handleMessageCreate(message), `CREATE ${message.id} ${route.from.toUpperCase()}→${route.to.toUpperCase()}`)
    .catch((error) => logger.error(`Translation failed for message ${message.id}:`, error));
});

client.on('messageUpdate', (_oldMessage, newMessage) => {
  const route = routes.get(newMessage.channelId);
  if (!route) return;

  relayQueue
    .enqueue(() => handleMessageUpdate(newMessage), `UPDATE ${newMessage.id} ${route.from.toUpperCase()}→${route.to.toUpperCase()}`)
    .catch((error) => logger.error(`Could not update translated message ${newMessage.id}:`, error));
});

client.on('messageDelete', (message) => {
  if (!routes.has(message.channelId)) return;

  // Queue deletions too. This guarantees that a delete received immediately
  // after a create cannot overtake the original translation/store write.
  relayQueue
    .enqueue(() => handleMessageDelete(message), `DELETE ${message.id}`)
    .catch((error) => logger.error(`Could not synchronize deletion for message ${message.id}:`, error));
});

async function shutdown(signal) {
  logger.info(`${signal} received, shutting down...`);
  try {
    logger.info(`Waiting for ${relayQueue.size} queued relay operation(s) to finish...`);
    await relayQueue.drain();
    await translator.close();
  } catch (error) {
    logger.warn('Bergamot shutdown error:', error);
  }
  webhooks.destroy();
  client.destroy();
  process.exit(0);
}

process.once('SIGINT', () => shutdown('SIGINT'));
process.once('SIGTERM', () => shutdown('SIGTERM'));

client.login(config.token).catch((error) => {
  logger.error('Discord login failed:', error);
  process.exit(1);
});
