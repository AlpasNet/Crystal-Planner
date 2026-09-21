import test from "node:test";
import assert from "node:assert/strict";
import { buildIdentifyPayload, buildResumePayload } from "../src/discord/presence.js";

test("Gateway identify requests online presence with no activity and no intents", () => {
  const payload = buildIdentifyPayload("secret-token");
  assert.equal(payload.op, 2);
  assert.equal(payload.d.token, "secret-token");
  assert.equal(payload.d.intents, 0);
  assert.equal(payload.d.presence.status, "online");
  assert.deepEqual(payload.d.presence.activities, []);
  assert.equal(payload.d.presence.afk, false);
  assert.equal(payload.d.presence.since, null);
});

test("Gateway resume keeps the existing session and sequence", () => {
  const payload = buildResumePayload("secret-token", "session-1", 42);
  assert.deepEqual(payload, {
    op: 6,
    d: {
      token: "secret-token",
      session_id: "session-1",
      seq: 42
    }
  });
});
