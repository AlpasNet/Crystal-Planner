import test from "node:test";
import assert from "node:assert/strict";
import { buildEventEntries, eventIdentity } from "../src/modules/events.js";
import { normalizeMessage } from "../src/discord/payload.js";

test("explicit event_id is preferred", () => {
  const raw = { event_id: "42", embeds: [{ title: "Event" }] };
  assert.equal(eventIdentity(raw, normalizeMessage(raw), 0), "id-42");
});

test("image URL is a durable fallback event identity", () => {
  const raw = { embeds: [{ title: "Event", image: { url: "https://example.com/event.png" } }] };
  const id1 = eventIdentity(raw, normalizeMessage(raw), 0);
  const changed = { embeds: [{ title: "New title", image: { url: "https://example.com/event.png" } }] };
  const id2 = eventIdentity(changed, normalizeMessage(changed), 0);
  assert.equal(id1, id2);
});

test("participant/position changes alter the event payload hash", () => {
  const first = buildEventEntries([{ event_id: "7", embeds: [{ title: "Run", fields: [{ name: "Players", value: "Alice · WHM · H1" }] }] }]);
  const second = buildEventEntries([{ event_id: "7", embeds: [{ title: "Run", fields: [{ name: "Players", value: "Alice · WHM · H1\nBob · PLD · MT" }] }] }]);
  assert.notEqual(first.get("id-7").hash, second.get("id-7").hash);
});
