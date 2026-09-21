import test from "node:test";
import assert from "node:assert/strict";
import { syncEvents } from "../src/modules/events.js";

class MemoryStore {
  constructor() {
    this.state = { events: { channelId: "", initialized: false, messages: {} }, boardHashes: {} };
    this.saves = 0;
  }
  getEventState() { return this.state.events; }
  saveEventState() { this.saves++; }
  setBoardHash(key, hash) { this.state.boardHashes[key] = hash; }
}

class MockDiscord {
  constructor() {
    this.next = 100000000000000;
    this.created = [];
    this.edited = [];
    this.deleted = [];
    this.cleared = [];
    this.crossposted = [];
    this.messages = new Set();
  }
  async clearChannel(channel) { this.cleared.push(channel); }
  async sendMessage(channel, payload) {
    const id = String(this.next++);
    this.created.push({ channel, payload, id });
    this.messages.add(id);
    return { id };
  }
  async editMessageIfExists(channel, id, payload) {
    if (!this.messages.has(id)) return false;
    this.edited.push({ channel, id, payload });
    return true;
  }
  async deleteMessageIfExists(channel, id) {
    this.deleted.push({ channel, id });
    return this.messages.delete(id);
  }
  async crosspostMessage(channel, id) { this.crossposted.push({ channel, id }); }
  async isMessageCrossposted() { return false; }
}

const logger = { info() {}, warn() {}, error() {} };
const channelId = "123456789012345";

test("event sync creates, edits, then deletes only the affected message", async () => {
  const discord = new MockDiscord();
  const store = new MemoryStore();

  await syncEvents({
    discord, store, logger, channelId, crosspostAnnouncements: true,
    messages: [{ event_id: "42", embeds: [{ title: "Run", fields: [{ name: "Players", value: "Alice · H1" }] }] }]
  });
  assert.equal(discord.cleared.length, 1);
  assert.equal(discord.created.length, 1);
  assert.equal(discord.crossposted.length, 1);
  const id = discord.created[0].id;

  await syncEvents({
    discord, store, logger, channelId, crosspostAnnouncements: true,
    messages: [{ event_id: "42", embeds: [{ title: "Run", fields: [{ name: "Players", value: "Alice · H1\nBob · MT" }] }] }]
  });
  assert.equal(discord.created.length, 1);
  assert.equal(discord.edited.length, 1);
  assert.equal(discord.edited[0].id, id);

  await syncEvents({ discord, store, logger, channelId, crosspostAnnouncements: true, messages: [] });
  assert.equal(discord.deleted.length, 1);
  assert.equal(discord.deleted[0].id, id);
  assert.equal(Object.keys(store.state.events.messages).length, 0);
});
