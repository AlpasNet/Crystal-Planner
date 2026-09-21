import { getText } from "../http.js";
import { normalizeMessage } from "../discord/payload.js";
import { sha256, sleep, truncate } from "../utils.js";

export const LODESTONE_INITIAL_ITEMS = 10;

export const LODESTONE_FEEDS = [
  {
    key: "news",
    label: "News",
    url: "https://eu.finalfantasyxiv.com/lodestone/news/news.xml",
    channelConfigKey: "newsChannelId",
    color: 0x3498DB,
    emoji: "📰"
  },
  {
    key: "topics",
    label: "Topics",
    url: "https://eu.finalfantasyxiv.com/lodestone/news/topics.xml",
    channelConfigKey: "topicsChannelId",
    color: 0xF1C40F,
    emoji: "⭐"
  }
];

export async function syncLodestone({ config, discord, store, logger }) {
  if (!config.lodestone.enabled) {
    logger.info("Lodestone RSS: disabled.");
    return { sent: 0, errors: 0 };
  }

  let sent = 0;
  let errors = 0;
  for (const feed of LODESTONE_FEEDS) {
    const channelId = String(config.lodestone[feed.channelConfigKey] ?? "").trim();
    if (!channelId) {
      logger.info(`Lodestone ${feed.label} RSS: skipped, no channel configured.`);
      continue;
    }

    try {
      const xml = await getText(feed.url, {
        headers: {
          "Accept": "application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.1"
        }
      });
      const items = parseRssFeed(xml, feed);
      if (!items.length) {
        throw new Error(`The RSS feed returned no usable item: ${feed.url}`);
      }
      const result = await syncRssFeed({ feed, channelId, items, discord, store, logger });
      sent += result.created;
    } catch (error) {
      errors++;
      logger.error(`Lodestone ${feed.label} RSS: ${error.message}`);
    }
  }

  return { sent, errors };
}

export async function syncRssFeed({ feed, channelId, items, discord, store, logger }) {
  const state = store.getLodestoneFeedState(feed.key);
  let created = 0;

  // Changing the destination channel never deletes or republishes old Lodestone
  // messages. Only future RSS entries are sent to the newly configured channel.
  state.channelId = channelId;

  const sorted = sortNewestFirst(items);
  const seen = new Set(Array.isArray(state.seenIds) ? state.seenIds.map(String) : []);

  if (!state.initialized) {
    // First activation: seed the Discord channel with the latest 10 entries only.
    // All entries currently visible in the RSS feed are then marked as known so
    // older feed history cannot be published on the next synchronization.
    const initial = sorted.slice(0, LODESTONE_INITIAL_ITEMS);

    for (let index = initial.length - 1; index >= 0; index--) {
      const item = initial[index];
      if (state.messages[item.id]?.messageId) continue;

      const message = await discord.sendMessage(channelId, buildRssPayload(item, feed));
      if (!message?.id) throw new Error(`Discord did not return a message ID for Lodestone ${feed.label}.`);
      state.messages[item.id] = {
        messageId: String(message.id),
        publishedAt: item.publishedAt || ""
      };
      store.saveLodestoneState();
      created++;
      await sleep(250);
    }

    for (const item of sorted) seen.add(item.id);
    state.seenIds = [...seen];
    state.initialized = true;
    state.baselinePending = false;
    store.saveLodestoneState();

    logger.info(`Lodestone ${feed.label} RSS initialized: ${created} of the latest ${Math.min(LODESTONE_INITIAL_ITEMS, sorted.length)} item(s) published. Older Discord messages will never be removed automatically.`);
    return { created, edited: 0, deleted: 0 };
  }

  // Compatibility with state files created by 1.1.0. The old release tracked
  // only the latest 10 messages. Establish the current RSS as the baseline once,
  // but still publish entries that are clearly newer than the newest tracked one.
  if (state.baselinePending) {
    const trackedDates = Object.values(state.messages || {})
      .map(entry => Date.parse(entry?.publishedAt || ""))
      .filter(Number.isFinite);
    const newestTracked = trackedDates.length ? Math.max(...trackedDates) : Number.NaN;

    const definitelyNew = sorted.filter(item => {
      if (seen.has(item.id)) return false;
      const published = Date.parse(item.publishedAt || item.publishedRaw || "");
      return Number.isFinite(newestTracked) && Number.isFinite(published) && published > newestTracked;
    });

    for (let index = definitelyNew.length - 1; index >= 0; index--) {
      const item = definitelyNew[index];
      const message = await discord.sendMessage(channelId, buildRssPayload(item, feed));
      if (!message?.id) throw new Error(`Discord did not return a message ID for Lodestone ${feed.label}.`);
      state.messages[item.id] = { messageId: String(message.id), publishedAt: item.publishedAt || "" };
      seen.add(item.id);
      state.seenIds = [...seen];
      store.saveLodestoneState();
      created++;
      await sleep(250);
    }

    for (const item of sorted) seen.add(item.id);
    state.seenIds = [...seen];
    state.baselinePending = false;
    store.saveLodestoneState();
    logger.info(`Lodestone ${feed.label} RSS migration baseline established: ${created} new item(s) published; no old Discord message deleted.`);
    return { created, edited: 0, deleted: 0 };
  }

  // Normal operation: append-only. Known entries are left untouched even if
  // their RSS representation changes or the Discord message was deleted by hand.
  // Only item IDs never seen before are published.
  const newItems = sorted.filter(item => !seen.has(item.id));
  for (let index = newItems.length - 1; index >= 0; index--) {
    const item = newItems[index];
    const message = await discord.sendMessage(channelId, buildRssPayload(item, feed));
    if (!message?.id) throw new Error(`Discord did not return a message ID for Lodestone ${feed.label}.`);

    state.messages[item.id] = {
      messageId: String(message.id),
      publishedAt: item.publishedAt || ""
    };
    seen.add(item.id);
    state.seenIds = [...seen];
    store.saveLodestoneState();
    created++;
    await sleep(250);
  }

  logger.info(`Lodestone ${feed.label} RSS: ${created} new item(s) published; existing Discord messages kept unchanged.`);
  return { created, edited: 0, deleted: 0 };
}

export function parseRssFeed(xml, feed = {}) {
  const source = String(xml ?? "");
  const blocks = [...source.matchAll(/<item\b[^>]*>([\s\S]*?)<\/item>/gi)].map(match => match[1]);
  if (!blocks.length) {
    return parseAtomFeed(source, feed);
  }

  const seen = new Set();
  const items = [];
  for (const block of blocks) {
    const title = cleanText(readTag(block, "title"));
    const guid = cleanText(readTag(block, "guid"));
    const link = extractArticleUrl(block, readTag(block, "link"), guid, feed.url || "");
    const publishedRaw = cleanText(readTag(block, "pubDate") || readTag(block, "dc:date") || readTag(block, "date"));
    const descriptionHtml = readTag(block, "description") || readTag(block, "content:encoded");
    const description = truncate(htmlToText(descriptionHtml), 3500);
    const image = extractImage(block, descriptionHtml, link || feed.url || "");
    const category = cleanText(readTag(block, "category"));
    const publishedAt = normalizeDate(publishedRaw);
    const id = cleanText(guid) || link || sha256(`${title}\n${publishedRaw}\n${description}`).slice(0, 32);

    if (!id || !title || seen.has(id)) continue;
    seen.add(id);
    items.push({ id, title, url: link, description, image, category, publishedAt, publishedRaw });
  }

  return sortNewestFirst(items);
}

function parseAtomFeed(xml, feed) {
  const blocks = [...String(xml ?? "").matchAll(/<entry\b[^>]*>([\s\S]*?)<\/entry>/gi)].map(match => match[1]);
  const seen = new Set();
  const items = [];
  for (const block of blocks) {
    const title = cleanText(readTag(block, "title"));
    const guid = cleanText(readTag(block, "id"));
    const link = extractArticleUrl(block, "", guid, feed.url || "");
    const publishedRaw = cleanText(readTag(block, "published") || readTag(block, "updated"));
    const descriptionHtml = readTag(block, "summary") || readTag(block, "content");
    const description = truncate(htmlToText(descriptionHtml), 3500);
    const image = extractImage(block, descriptionHtml, link || feed.url || "");
    const publishedAt = normalizeDate(publishedRaw);
    const id = guid || link || sha256(`${title}\n${publishedRaw}\n${description}`).slice(0, 32);
    if (!id || !title || seen.has(id)) continue;
    seen.add(id);
    items.push({ id, title, url: link, description, image, category: "", publishedAt, publishedRaw });
  }
  return sortNewestFirst(items);
}

function buildRssPayload(item, feed) {
  const embed = {
    author: { name: `The Lodestone - ${feed.label}` },
    title: `${feed.emoji} ${truncate(item.title, 250)}`,
    color: feed.color,
    footer: { text: "FINAL FANTASY XIV - The Lodestone · Official RSS" }
  };
  if (item.url) {
    embed.url = item.url;
    embed.fields = [{ name: "Link", value: `[Open the article on The Lodestone](${item.url})`, inline: false }];
  }
  // News RSS entries are intentionally image-first: do not repeat the HTML-derived
  // description in Discord. Topics keep their cleaned description.
  if (feed.key !== "news" && item.description) embed.description = truncate(item.description, 4096);
  if (item.publishedAt) embed.timestamp = item.publishedAt;
  if (item.image) embed.image = { url: item.image };
  return normalizeMessage({ embeds: [embed] });
}

function readTag(source, name) {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const match = String(source ?? "").match(new RegExp(`<${escaped}\\b[^>]*>([\\s\\S]*?)<\\/${escaped}>`, "i"));
  return match ? unwrapCdata(match[1]) : "";
}

function extractArticleUrl(itemBlock, directLink, guid, feedUrl) {
  const decoded = decodeXmlRepeated(String(itemBlock ?? ""));
  const candidates = [];

  // RSS normally provides a text <link> containing the article URL. Prefer it,
  // but validate it because some feeds also expose image/enclosure links.
  if (directLink) candidates.push(directLink);

  // Atom-style feeds can contain multiple <link href=...> elements. The
  // rel="alternate" link is the article; rel="enclosure" is artwork/media.
  for (const tag of decoded.matchAll(/<link\b([^>]*)\/?\s*>/gi)) {
    const attrs = tag[1] || "";
    const href = readAttribute(attrs, "href");
    if (!href) continue;
    const rel = cleanText(readAttribute(attrs, "rel")).toLowerCase();
    if (rel === "alternate" || rel === "") candidates.push(href);
  }

  // Some publishers use the GUID as the canonical article URL.
  if (guid) candidates.push(guid);

  // Last-resort discovery: find Lodestone detail URLs in the item. This also
  // protects against an enclosure/image link appearing before the article link.
  for (const match of decoded.matchAll(/https?:\/\/[^\s<>'"]+/gi)) {
    candidates.push(match[0]);
  }

  const normalized = [];
  for (const candidate of candidates) {
    const url = absoluteUrl(decodeXmlRepeated(candidate), feedUrl || "https://eu.finalfantasyxiv.com/");
    if (url && !normalized.includes(url)) normalized.push(url);
  }

  // The official article pages use /lodestone/news/detail/... or
  // /lodestone/topics/detail/.... Never use an image/enclosure URL here.
  const canonical = normalized.find(isLodestoneArticleUrl);
  if (canonical) return canonical;

  // Defensive fallback for a future Lodestone URL shape: still require the
  // official site and explicitly reject image-looking URLs.
  return normalized.find(url => isLodestonePageUrl(url) && !isLikelyImageUrl(url)) || "";
}

function isLodestoneArticleUrl(value) {
  try {
    const url = new URL(value);
    return isFinalFantasyXivHost(url.hostname)
      && /^\/lodestone\/(?:news|topics)\/detail\//i.test(url.pathname)
      && !isLikelyImageUrl(url.toString());
  } catch {
    return false;
  }
}

function isLodestonePageUrl(value) {
  try {
    const url = new URL(value);
    return isFinalFantasyXivHost(url.hostname) && url.pathname.startsWith("/lodestone/");
  } catch {
    return false;
  }
}

function isFinalFantasyXivHost(hostname) {
  const host = String(hostname ?? "").toLowerCase();
  return host === "finalfantasyxiv.com" || host.endsWith(".finalfantasyxiv.com");
}

function isLikelyImageUrl(value) {
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase();
    if (host === "img.finalfantasyxiv.com" || host.endsWith(".img.finalfantasyxiv.com")) return true;
    return /\.(?:avif|bmp|gif|jpe?g|png|svg|webp)(?:$|\?)/i.test(`${url.pathname}${url.search}`);
  } catch {
    return false;
  }
}

function readAttribute(attributeText, name) {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const source = String(attributeText ?? "");
  const quoted = source.match(new RegExp(`(?:^|\\s)${escaped}\\s*=\\s*["']([^"']+)["']`, "i"));
  if (quoted?.[1]) return decodeXmlRepeated(quoted[1]);
  const unquoted = source.match(new RegExp(`(?:^|\\s)${escaped}\\s*=\\s*([^\\s"'=<>]+)`, "i"));
  return unquoted?.[1] ? decodeXmlRepeated(unquoted[1]) : "";
}

function extractImage(itemBlock, descriptionHtml, baseUrl) {
  // The official Lodestone feeds expose the article artwork through <enclosure>.
  // Treat enclosure as authoritative and only fall back to media:* or HTML <img>.
  // Decode entities first because Square Enix can XML-escape embedded HTML.
  const decodedBlock = decodeXmlRepeated(String(itemBlock ?? ""));
  const decodedDescription = decodeXmlRepeated(String(descriptionHtml ?? ""));

  const enclosure = extractElementUrl(decodedBlock, "enclosure", baseUrl);
  if (enclosure) return enclosure;

  const mediaContent = extractElementUrl(decodedBlock, "media:content", baseUrl);
  if (mediaContent) return mediaContent;

  const mediaThumbnail = extractElementUrl(decodedBlock, "media:thumbnail", baseUrl);
  if (mediaThumbnail) return mediaThumbnail;

  const img = decodedDescription.match(/<img\b[^>]*\bsrc\s*=\s*["']([^"']+)["'][^>]*>/i)
    || decodedBlock.match(/<img\b[^>]*\bsrc\s*=\s*["']([^"']+)["'][^>]*>/i);
  return img ? absoluteUrl(decodeXmlRepeated(img[1]), baseUrl) : "";
}

function extractElementUrl(source, elementName, baseUrl) {
  const escaped = elementName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const tagName = elementName.includes(":")
    ? escaped
    : `(?:[A-Za-z_][\\w.-]*:)?${escaped}`;
  const text = String(source ?? "");

  const opening = text.match(new RegExp(`<${tagName}\\b([^>]*)\\/?\\s*>`, "i"));
  if (opening) {
    const attrs = opening[1] || "";
    // RSS 2.0 normally uses url=. RSS 1.0/RDF enclosure modules often use
    // rdf:resource=. Support both, plus common media-feed alternatives.
    for (const attr of ["url", "rdf:resource", "resource", "href", "src"]) {
      const raw = readAttribute(attrs, attr);
      if (!raw) continue;
      const url = absoluteUrl(raw, baseUrl);
      if (url) return url;
    }
  }

  // Also support feeds that put the URL as the element body.
  const body = text.match(new RegExp(`<${tagName}\\b[^>]*>([\\s\\S]*?)<\\/${tagName}>`, "i"));
  if (body?.[1]) {
    const url = absoluteUrl(cleanText(body[1]), baseUrl);
    if (url) return url;
  }

  return "";
}

function unwrapCdata(value) {
  const text = String(value ?? "").trim();
  const match = text.match(/^<!\[CDATA\[([\s\S]*?)\]\]>$/i);
  return match ? match[1] : text;
}

function htmlToText(value) {
  // Decode first. If the feed contains &lt;p&gt; instead of a literal <p>,
  // stripping tags before entity decoding would leak HTML tags into Discord.
  const decoded = decodeXmlRepeated(String(value ?? ""));
  return cleanText(decoded
    .replace(/<script\b[\s\S]*?<\/script>/gi, " ")
    .replace(/<style\b[\s\S]*?<\/style>/gi, " ")
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<\/(?:p|div|li|section|article|h[1-6]|ul|ol|blockquote)>/gi, "\n")
    .replace(/<li\b[^>]*>/gi, "• ")
    .replace(/<[^>]+>/g, " "));
}

function cleanText(value) {
  return decodeXml(value)
    .replace(/\r/g, "")
    .split("\n")
    .map(line => line.replace(/[ \t]+/g, " ").trim())
    .filter(Boolean)
    .join("\n")
    .trim();
}

function decodeXml(value) {
  const named = {
    amp: "&", lt: "<", gt: ">", quot: '"', apos: "'", nbsp: " ",
    ndash: "–", mdash: "—", hellip: "…", rsquo: "’", lsquo: "‘", rdquo: "”", ldquo: "“"
  };
  return String(value ?? "").replace(/&(#x[0-9a-f]+|#\d+|[a-z][a-z0-9]+);/gi, (whole, entity) => {
    if (entity[0] === "#") {
      const hex = entity[1]?.toLowerCase() === "x";
      const code = Number.parseInt(entity.slice(hex ? 2 : 1), hex ? 16 : 10);
      if (Number.isFinite(code)) {
        try { return String.fromCodePoint(code); } catch { return whole; }
      }
    }
    return named[entity.toLowerCase()] ?? whole;
  });
}

function decodeXmlRepeated(value, maxPasses = 3) {
  let current = String(value ?? "");
  for (let i = 0; i < maxPasses; i++) {
    const decoded = decodeXml(current);
    if (decoded === current) break;
    current = decoded;
  }
  return current;
}

function normalizeDate(value) {
  const ms = Date.parse(String(value ?? "").trim());
  return Number.isFinite(ms) ? new Date(ms).toISOString() : "";
}

function sortNewestFirst(items) {
  return [...items].sort((a, b) => {
    const left = Date.parse(a.publishedAt || a.publishedRaw || "");
    const right = Date.parse(b.publishedAt || b.publishedRaw || "");
    if (Number.isFinite(left) && Number.isFinite(right) && left !== right) return right - left;
    return 0;
  });
}

function cleanUrl(value) {
  const text = cleanText(value).trim();
  if (!text) return "";
  try {
    const url = new URL(text);
    return url.protocol === "https:" ? url.toString() : "";
  } catch {
    return "";
  }
}

function absoluteUrl(value, baseUrl) {
  const text = String(value ?? "").trim();
  if (!text) return "";
  try {
    const url = new URL(text, baseUrl || "https://eu.finalfantasyxiv.com/");
    return url.protocol === "https:" ? url.toString() : "";
  } catch {
    return "";
  }
}
