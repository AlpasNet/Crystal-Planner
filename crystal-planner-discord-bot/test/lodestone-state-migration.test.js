import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { JsonStore } from "../src/storage/jsonStore.js";

test("v2 Lodestone state upgrades to current append-only state without losing tracked messages", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "cp-lodestone-state-"));
  const file = path.join(dir, "state.json");
  fs.writeFileSync(file, JSON.stringify({
    version: 2,
    lodestone: {
      feeds: {
        news: {
          channelId: "123456789012345",
          messages: {
            "rss-id-1": { messageId: "223456789012345", publishedAt: "2026-08-20T12:00:00.000Z" }
          }
        },
        topics: { channelId: "", messages: {} }
      }
    }
  }));

  const store = new JsonStore(file, { error() {} });
  const state = store.load();
  assert.equal(state.version, 4);
  assert.equal(state.lodestone.feeds.news.initialized, true);
  assert.equal(state.lodestone.feeds.news.baselinePending, true);
  assert.deepEqual(state.lodestone.feeds.news.seenIds, ["rss-id-1"]);
  assert.equal(state.lodestone.feeds.news.messages["rss-id-1"].messageId, "223456789012345");
});
