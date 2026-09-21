import test from "node:test";
import assert from "node:assert/strict";
import { normalizeMessage, putGuideLinkBelowImage } from "../src/discord/payload.js";
import { stableStringify } from "../src/utils.js";

test("normalizer prevents mentions and truncates Discord text", () => {
  const payload = normalizeMessage({
    content: "x".repeat(2500),
    embeds: [{ title: "y".repeat(300), description: "ok" }]
  });
  assert.equal(payload.content.length, 2000);
  assert.equal(payload.embeds[0].title.length, 256);
  assert.deepEqual(payload.allowed_mentions, { parse: [] });
});

test("guide link is placed in the same embed footer", () => {
  const raw = { embeds: [{ title: "Guide", url: "https://example.com/guide", image: { url: "https://example.com/i.png" } }] };
  const payload = putGuideLinkBelowImage(normalizeMessage(raw), raw);
  assert.equal(payload.embeds[0].footer.text, "https://example.com/guide");
  assert.equal(payload.embeds[0].url, "https://example.com/guide");
});

test("stable stringify ignores generated timestamps", () => {
  const a = { title: "A", generated_at: "one", nested: { timestamp: "x", value: 2 } };
  const b = { nested: { value: 2, timestamp: "y" }, generated_at: "two", title: "A" };
  assert.equal(stableStringify(a), stableStringify(b));
});
