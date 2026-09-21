import test from "node:test";
import assert from "node:assert/strict";
import { buildAnnouncementPayload, extractAvailableEvents, syncEventAnnouncements } from "../src/modules/eventAnnouncements.js";

const eventMessage = (title, start, end) => ({
  embeds: [{
    title: `📅 ${title}`,
    fields: [
      { name: "Start", value: `<t:${start}:F>` },
      { name: "End", value: `<t:${end}:F>` }
    ]
  }]
});

const pollMessage = { embeds: [{ title: "📊 Preferred day", fields: [{ value: "x" }, { value: "y" }] }] };

test("available event summary excludes polls and sorts by start time", () => {
  const events = extractAvailableEvents([
    eventMessage("Later", 200, 300),
    pollMessage,
    eventMessage("Sooner", 100, 150)
  ]);
  assert.deepEqual(events.map(event => event.title), ["Sooner", "Later"]);
});

test("announcement payload contains bilingual title, dates, configured Discord link and image", () => {
  const payload = buildAnnouncementPayload({
    events: extractAvailableEvents([eventMessage("Treasure Maps", 100, 150)]),
    discordUrl: "https://discord.gg/example",
    imageUrl: "https://example.com/banner.png"
  });
  assert.equal(payload.embeds[0].title, "Evénements disponibles / Available Events");
  assert.match(payload.embeds[0].description, /Treasure Maps/);
  assert.match(payload.embeds[0].description, /<t:100:F>/);
  assert.match(payload.embeds[0].description, /<t:150:F>/);
  assert.match(payload.embeds[0].description, /Discord:\*\* https:\/\/discord\.gg\/example/);
  assert.equal(payload.embeds[0].image.url, "https://example.com/banner.png");
});

class MemoryStore {
  constructor() { this.state = { channelId: "", messageId: "", hash: "" }; this.saves = 0; }
  getEventAnnouncementState() { return this.state; }
  saveEventAnnouncementState() { this.saves++; }
}

class MockDiscord {
  constructor() { this.next = 1; this.sent = []; this.edited = []; this.deleted = []; this.live = new Map(); }
  async sendMessage(channelId, payload) { const id = String(this.next++); this.sent.push({channelId,payload,id}); this.live.set(id, payload); return {id}; }
  async editMessageIfExists(channelId, id, payload) { if (!this.live.has(id)) return false; this.edited.push({channelId,id,payload}); this.live.set(id,payload); return true; }
  async deleteMessageIfExists(channelId, id) { this.deleted.push({channelId,id}); return this.live.delete(id); }
  async getMessage(channelId, id) { return this.live.has(id) ? { id } : null; }
}

test("summary creates once and edits the same message when the event list changes", async () => {
  const originalFetch = global.fetch;
  const responses = [
    { messages: [eventMessage("A", 100, 150)] },
    { messages: [eventMessage("A", 100, 150), eventMessage("B", 200, 250)] }
  ];
  global.fetch = async () => ({ ok: true, status: 200, headers: new Headers(), text: async () => JSON.stringify(responses.shift()) });
  try {
    const discord = new MockDiscord();
    const store = new MemoryStore();
    const config = {
      eventAnnouncements: { enabled: true, channelId: "123456789012345678", discordUrl: "https://discord.gg/example", imageUrl: "https://example.com/a.png" },
      urls: { events: "https://example.com/discord-bot-datas.json", generator: "https://example.com/generator.php" },
      jsonReadDelaySeconds: 0
    };
    const logger = { info() {}, warn() {}, error() {} };
    await syncEventAnnouncements({ config, discord, store, logger, callGenerator: false });
    assert.equal(discord.sent.length, 1);
    const firstId = store.state.messageId;
    await syncEventAnnouncements({ config, discord, store, logger, callGenerator: false });
    assert.equal(discord.sent.length, 1);
    assert.equal(discord.edited.length, 1);
    assert.equal(discord.edited[0].id, firstId);
  } finally {
    global.fetch = originalFetch;
  }
});
