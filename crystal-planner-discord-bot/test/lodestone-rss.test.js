import test from "node:test";
import assert from "node:assert/strict";
import { LODESTONE_INITIAL_ITEMS, parseRssFeed, syncRssFeed } from "../src/modules/lodestone.js";

const FEED = { key: "news", label: "News", emoji: "📰", color: 123, url: "https://eu.finalfantasyxiv.com/lodestone/news/news.xml" };

function rss(count = 12, offset = 0) {
  const items = [];
  for (let i = 0; i < count; i++) {
    const n = i + offset;
    const date = new Date(Date.UTC(2026, 7, 20, 12, 0, 0) - n * 86400000).toUTCString();
    items.push(`<item>
<title><![CDATA[Official news ${n}]]></title>
<link>https://eu.finalfantasyxiv.com/lodestone/news/detail/id-${n}</link>
<guid>id-${n}</guid>
<pubDate>${date}</pubDate>
<description><![CDATA[<p>Description ${n}</p><img src="https://img.finalfantasyxiv.com/${n}.jpg">]]></description>
</item>`);
  }
  return `<?xml version="1.0"?><rss version="2.0"><channel>${items.join("")}</channel></rss>`;
}

class MemoryStore {
  constructor() {
    this.state = { lodestone: { feeds: { news: { channelId: "", initialized: false, baselinePending: false, seenIds: [], messages: {} } } } };
  }
  getLodestoneFeedState(key) { return this.state.lodestone.feeds[key]; }
  saveLodestoneState() {}
}

class MockDiscord {
  constructor() { this.next = 100000000000000; this.messages = new Map(); this.created=[]; this.edited=[]; this.deleted=[]; }
  async sendMessage(channel, payload) { const id=String(this.next++); this.messages.set(id,payload); this.created.push({channel,id,payload}); return {id}; }
  async editMessageIfExists(channel,id,payload) { if(!this.messages.has(id)) return false; this.messages.set(id,payload); this.edited.push({channel,id,payload}); return true; }
  async deleteMessageIfExists(channel,id) { this.deleted.push({channel,id}); return this.messages.delete(id); }
  async getMessage(channel,id) { return this.messages.has(id) ? {id} : null; }
}
const logger = { info() {}, warn() {}, error() {} };

test("RSS parser returns official items newest-first with RSS description and image", () => {
  const items = parseRssFeed(rss(12), FEED);
  assert.equal(items.length, 12);
  assert.equal(items[0].id, "id-0");
  assert.equal(items[0].title, "Official news 0");
  assert.equal(items[0].description, "Description 0");
  assert.equal(items[0].image, "https://img.finalfantasyxiv.com/0.jpg");
});

test("first RSS sync publishes only the latest 10 and establishes a baseline for the whole current feed", async () => {
  const store = new MemoryStore();
  const discord = new MockDiscord();
  const items = parseRssFeed(rss(15), FEED);
  await syncRssFeed({ feed: FEED, channelId: "123456789012345", items, discord, store, logger });

  assert.equal(discord.created.length, LODESTONE_INITIAL_ITEMS);
  assert.equal(discord.deleted.length, 0);
  assert.equal(discord.edited.length, 0);
  assert.equal(store.state.lodestone.feeds.news.initialized, true);
  assert.equal(store.state.lodestone.feeds.news.seenIds.length, 15);
  assert.equal(Object.keys(store.state.lodestone.feeds.news.messages).length, 10);
});

test("after initialization only unseen RSS entries are appended and old Discord messages are kept", async () => {
  const store = new MemoryStore();
  const discord = new MockDiscord();
  const channelId = "123456789012345";
  const initial = parseRssFeed(rss(12), FEED);
  await syncRssFeed({ feed: FEED, channelId, items: initial, discord, store, logger });
  const firstMessageIds = [...discord.messages.keys()];

  const newer1 = {
    id: "brand-new-1", title: "Brand new 1", url: "https://eu.finalfantasyxiv.com/lodestone/news/detail/new-1",
    description: "New 1", image: "", category: "", publishedAt: "2026-08-21T12:00:00.000Z", publishedRaw: ""
  };
  const newer2 = {
    id: "brand-new-2", title: "Brand new 2", url: "https://eu.finalfantasyxiv.com/lodestone/news/detail/new-2",
    description: "New 2", image: "", category: "", publishedAt: "2026-08-22T12:00:00.000Z", publishedRaw: ""
  };
  const next = [newer2, newer1, ...initial.slice(0, 8)];
  await syncRssFeed({ feed: FEED, channelId, items: next, discord, store, logger });

  assert.equal(discord.created.length, 12);
  assert.equal(discord.deleted.length, 0);
  assert.equal(discord.edited.length, 0);
  for (const id of firstMessageIds) assert.ok(discord.messages.has(id));
  assert.ok(store.state.lodestone.feeds.news.messages["brand-new-1"]);
  assert.ok(store.state.lodestone.feeds.news.messages["brand-new-2"]);
});

test("known RSS items are never edited or recreated automatically", async () => {
  const store = new MemoryStore();
  const discord = new MockDiscord();
  const channelId = "123456789012345";
  const items = parseRssFeed(rss(10), FEED);
  await syncRssFeed({ feed: FEED, channelId, items, discord, store, logger });

  const tracked = store.state.lodestone.feeds.news.messages[items[0].id];
  discord.messages.delete(tracked.messageId);
  const before = discord.created.length;

  const changed = items.map(item => item.id === items[0].id ? { ...item, description: "Changed official description" } : item);
  await syncRssFeed({ feed: FEED, channelId, items: changed, discord, store, logger });

  assert.equal(discord.created.length, before);
  assert.equal(discord.edited.length, 0);
  assert.equal(discord.deleted.length, 0);
});

test("changing the configured channel never deletes or republishes old Lodestone entries", async () => {
  const store = new MemoryStore();
  const discord = new MockDiscord();
  const items = parseRssFeed(rss(10), FEED);
  await syncRssFeed({ feed: FEED, channelId: "123456789012345", items, discord, store, logger });
  const createdBefore = discord.created.length;

  await syncRssFeed({ feed: FEED, channelId: "223456789012345", items, discord, store, logger });
  assert.equal(discord.created.length, createdBefore);
  assert.equal(discord.deleted.length, 0);
  assert.equal(store.state.lodestone.feeds.news.channelId, "223456789012345");
});

test("RSS parser decodes XML-escaped HTML and extracts its image", () => {
  const xml = `<?xml version="1.0"?><rss version="2.0"><channel><item>
<title>Escaped article</title>
<link>https://eu.finalfantasyxiv.com/lodestone/news/detail/escaped</link>
<guid>escaped-id</guid>
<pubDate>Sat, 22 Aug 2026 10:00:00 GMT</pubDate>
<description>&amp;lt;p&amp;gt;First line&amp;lt;br&amp;gt;Second &amp;amp; final&amp;lt;/p&amp;gt;&amp;lt;img src=&amp;quot;https://img.finalfantasyxiv.com/escaped.jpg?x=1&amp;amp;y=2&amp;quot;&amp;gt;</description>
</item></channel></rss>`;

  const [item] = parseRssFeed(xml, FEED);
  assert.ok(item);
  assert.equal(item.id, "escaped-id");
  assert.equal(item.description, "First line\nSecond & final");
  assert.equal(item.image, "https://img.finalfantasyxiv.com/escaped.jpg?x=1&y=2");
  assert.doesNotMatch(item.description, /<[^>]+>/);
});

test("RSS parser cleans escaped content:encoded and preserves readable lists", () => {
  const xml = `<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item>
<title><![CDATA[Encoded content]]></title>
<link>https://eu.finalfantasyxiv.com/lodestone/topics/detail/encoded</link>
<guid>encoded-content-id</guid>
<pubDate>Sat, 22 Aug 2026 11:00:00 GMT</pubDate>
<content:encoded><![CDATA[&lt;p&gt;Summary&lt;/p&gt;&lt;ul&gt;&lt;li&gt;One&lt;/li&gt;&lt;li&gt;Two&lt;/li&gt;&lt;/ul&gt;&lt;img src="/lodestone/topics/image.jpg" /&gt;]]></content:encoded>
</item></channel></rss>`;

  const [item] = parseRssFeed(xml, FEED);
  assert.ok(item);
  assert.equal(item.description, "Summary\n• One\n• Two");
  assert.equal(item.image, "https://eu.finalfantasyxiv.com/lodestone/topics/image.jpg");
});

test("News uses enclosure as the embed image and omits the RSS description", async () => {
  const xml = `<?xml version="1.0"?><rss version="2.0"><channel><item>
<title>News with enclosure</title>
<link>https://eu.finalfantasyxiv.com/lodestone/news/detail/enclosure-news</link>
<guid>enclosure-news</guid>
<pubDate>Sat, 22 Aug 2026 12:00:00 GMT</pubDate>
<description><![CDATA[<p>This text must not be displayed for News.</p><img src="https://img.finalfantasyxiv.com/fallback.jpg">]]></description>
<enclosure type="image/jpeg" length="0" url="https://img.finalfantasyxiv.com/official-enclosure.jpg" />
</item></channel></rss>`;
  const items = parseRssFeed(xml, FEED);
  assert.equal(items[0].image, "https://img.finalfantasyxiv.com/official-enclosure.jpg");

  const store = new MemoryStore();
  const discord = new MockDiscord();
  await syncRssFeed({ feed: FEED, channelId: "123456789012345", items, discord, store, logger });
  const embed = discord.created[0].payload.embeds[0];
  assert.equal(embed.image.url, "https://img.finalfantasyxiv.com/official-enclosure.jpg");
  assert.equal(Object.prototype.hasOwnProperty.call(embed, "description"), false);
});

test("Topics keeps cleaned description and uses enclosure image", async () => {
  const topicsFeed = { key: "topics", label: "Topics", emoji: "⭐", color: 456, url: "https://eu.finalfantasyxiv.com/lodestone/news/topics.xml" };
  const xml = `<?xml version="1.0"?><rss version="2.0"><channel><item>
<title>Topic with enclosure</title>
<link>https://eu.finalfantasyxiv.com/lodestone/topics/detail/enclosure-topic</link>
<guid>enclosure-topic</guid>
<pubDate>Sat, 22 Aug 2026 12:30:00 GMT</pubDate>
<description><![CDATA[<p>Topic description</p>]]></description>
<enclosure url="https://img.finalfantasyxiv.com/topic-enclosure.jpg" type="image/jpeg" />
</item></channel></rss>`;
  const items = parseRssFeed(xml, topicsFeed);
  const store = new MemoryStore();
  store.state.lodestone.feeds.topics = { channelId: "", initialized: false, baselinePending: false, seenIds: [], messages: {} };
  const discord = new MockDiscord();
  await syncRssFeed({ feed: topicsFeed, channelId: "223456789012345", items, discord, store, logger });
  const embed = discord.created[0].payload.embeds[0];
  assert.equal(embed.description, "Topic description");
  assert.equal(embed.image.url, "https://img.finalfantasyxiv.com/topic-enclosure.jpg");
});

test("RSS enclosure URL in element body is supported", () => {
  const xml = `<?xml version="1.0"?><rss version="2.0"><channel><item>
<title>Body enclosure</title><link>https://eu.finalfantasyxiv.com/lodestone/news/detail/body</link><guid>body-enclosure</guid>
<pubDate>Sat, 22 Aug 2026 13:00:00 GMT</pubDate><description>Ignored description</description>
<enclosure>https://img.finalfantasyxiv.com/body-enclosure.jpg</enclosure>
</item></channel></rss>`;
  const [item] = parseRssFeed(xml, FEED);
  assert.equal(item.image, "https://img.finalfantasyxiv.com/body-enclosure.jpg");
});

test("Lodestone article link never resolves to the enclosure image and RDF enclosure is displayed", async () => {
  const xml = `<?xml version="1.0"?><rss version="2.0" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><channel><item>
<title>News with separate article and artwork links</title>
<link rel="enclosure" href="https://img.finalfantasyxiv.com/lds/h/example-news.jpg" />
<link rel="alternate" href="https://eu.finalfantasyxiv.com/lodestone/news/detail/real-article-id" />
<guid>real-article-id</guid>
<pubDate>Sun, 23 Aug 2026 12:00:00 GMT</pubDate>
<description><![CDATA[<p>This News description must not be displayed.</p>]]></description>
<enclosure rdf:resource="https://img.finalfantasyxiv.com/lds/h/official-rdf-image.jpg" type="image/jpeg" />
</item></channel></rss>`;

  const items = parseRssFeed(xml, FEED);
  assert.equal(items.length, 1);
  assert.equal(items[0].url, "https://eu.finalfantasyxiv.com/lodestone/news/detail/real-article-id");
  assert.equal(items[0].image, "https://img.finalfantasyxiv.com/lds/h/official-rdf-image.jpg");

  const store = new MemoryStore();
  const discord = new MockDiscord();
  await syncRssFeed({ feed: FEED, channelId: "123456789012345", items, discord, store, logger });
  const embed = discord.created[0].payload.embeds[0];
  assert.equal(embed.url, "https://eu.finalfantasyxiv.com/lodestone/news/detail/real-article-id");
  assert.equal(embed.fields[0].value, "[Open the article on The Lodestone](https://eu.finalfantasyxiv.com/lodestone/news/detail/real-article-id)");
  assert.equal(embed.image.url, "https://img.finalfantasyxiv.com/lds/h/official-rdf-image.jpg");
  assert.equal(Object.prototype.hasOwnProperty.call(embed, "description"), false);
});
