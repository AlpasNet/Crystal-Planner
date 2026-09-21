# Crystal Translator

> **v0.1.6** — Stable FR↔EN model download: no GCS registry dependency during normal startup.

Automatic **French ↔ English** Discord translation relay powered locally by **Bergamot**.

## What this version does

- `#fr` → automatic English translation in `#en`.
- `#en` → automatic French translation in `#fr`.
- Translated messages use a Discord webhook with the original member's server display name and avatar.
- All translated webhook messages are sent as **silent Discord messages** (`SuppressNotifications`), so relays never generate channel notifications.
- Small `FR → EN` / `EN → FR` marker.
- FFXIV/community protected terms and a directional dictionary in `terms.json`.
- Discord mentions, custom emojis, timestamps, URLs and code are protected from translation.
- Message edits are re-translated for **60 minutes** by default.
- Message deletions are synchronized during the same 60-minute window (configurable).
- Attachments are copied to the translated message.
- Replies link to the already translated counterpart when one is known.
- Message IDs are retained for 30 days so reply links can still work; message text is **not** stored.
- Strict application-level FIFO: creations, edits and deletions are relayed one at a time in Discord arrival order.
- One Bergamot worker, batch size 1, with Mozilla `base-memory` FR/EN release models.
- Model files are cached on disk after the first download.

## Requirements

- Linux VPS (2 GB RAM is comfortable for this configuration).
- Node.js 20.12+ (Node.js 22 LTS recommended).
- A Discord bot/application.
- Internet access for the first model download.

## Discord setup

In the Discord Developer Portal, enable the **Message Content Intent** for the bot.

Invite it with permissions for both translation channels:

- View Channel
- Send Messages
- Read Message History
- Manage Webhooks
- Attach Files
- Embed Links

The bot automatically creates one `Crystal Translator` webhook in each target channel when needed.

## Installation

```bash
cp .env.example .env
nano .env
nano config.json
npm install
npm run warmup
npm start
```

Put the bot token in `.env`:

```env
DISCORD_TOKEN=your_token_here
```

Put the two Discord channel IDs in `config.json`:

```json
{
  "channels": {
    "fr": "123456789012345678",
    "en": "234567890123456789"
  }
}
```

Do not commit `.env` or `data/webhooks.json`; both contain credentials.

## Discord references

Mentions, channel references and emotes are never sent to Bergamot. Crystal Translator preserves native Discord references such as `<@user>`, `<@&role>`, `<#channel>`, `@everyone`, `@here`, literal names such as `@Seije`, `#general-fr`, emoji-prefixed channel names like `#✒️-questions-and-issues`, custom Discord emotes such as `<:mogdance:123456789012345678>` / `<a:mogdance:123456789012345678>`, and normal Unicode emojis.

Channel names, Discord references and emotes are kept out of Bergamot entirely.

## Protected terms

Edit `terms.json` without touching the source code.

`protectedTerms` stay exactly as written:

```json
"protectedTerms": [
  "FFXIV",
  "Party Finder",
  "Duty Finder",
  "Crystal Planner",
  "Cozy Events"
]
```

The directional dictionary forces specific translations:

```json
"dictionary": {
  "fr-en": {
    "Sadique": "Savage",
    "Extrême": "Extreme"
  },
  "en-fr": {
    "Savage": "Sadique",
    "Extreme": "Extrême"
  }
}
```

## Edit window

Default:

```json
"editWindowMinutes": 60,
"syncDeletesWithinEditWindow": true
```

During that hour, editing the original message re-runs Bergamot and edits the translated webhook message. After the hour, the old translation is left unchanged.

## Strict FIFO queue

All relay operations use one global in-process FIFO queue:

```text
Message A ─┐
Message B ─┼──► FIFO ─► translate/publish A ─► translate/publish B ─► translate/publish C
Message C ─┘
```

A task does not start until the previous task has fully finished, including webhook publication and message-ID storage. Edits and deletions use the same queue, so they cannot overtake a message that is still being translated. A failed task is logged and does not block later tasks.

The queue is intentionally in memory only: after a process restart Discord resumes with new events; it does not persist stale translation jobs.

## Bergamot models

Crystal Translator is intentionally limited to FR↔EN, so the exact `Release` + `base-memory` metadata for `fr-en` and `en-fr` is bundled with the bot. Normal startup does **not** contact Mozilla's GCS registry.

The model files are downloaded first from the `mukowaty/firefox-translations` Hugging Face mirror of Firefox Translation models, then GitHub/GCS are only fallback sources. The uncompressed model SHA-256 is checked before Bergamot uses it.

The compressed downloads are unpacked and cached under `data/models/`. On later restarts they are read locally.

## Running with systemd

An example unit is included at:

```text
systemd/crystal-translator.service.example
```

Adjust the Linux user and installation path, copy it to `/etc/systemd/system/crystal-translator.service`, then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now crystal-translator
sudo systemctl status crystal-translator
```

## Notes

Incoming translated webhook messages are ignored by the bot, preventing FR → EN → FR loops.

The bot disables `allowed_mentions` on relayed messages. Mentions remain visually recognizable, but users/roles are not pinged a second time by the translation.

### v0.1.2 — HTTP 403 model download fallback

If Mozilla's production Google Cloud Storage registry or model bucket returns HTTP 403 from a VPS/network, Crystal Translator now falls back automatically:

1. The current FR→EN and EN→FR `base-memory` Release metadata is bundled in the application.
2. Model downloads try several official Google Cloud Storage download endpoints.
3. If GCS is still blocked, the exact matching model files are downloaded from Mozilla's archived `firefox-translations-models` GitHub/LFS repository.
4. Once downloaded, all files are kept in `data/models/`, so normal bot restarts require no network access for the models.

The SHA-256 of the uncompressed model is still checked before Bergamot uses it.

## Node.js 20/22 compatibility (v0.1.4)

`@browsermt/bergamot-translator@0.4.9` is published as an ES module, but its Node worker still contains CommonJS `require()` calls. Crystal Translator works around this upstream packaging issue without editing `node_modules`: before starting Bergamot, it copies the package's official worker runtime to `data/bergamot-worker-runtime/` and executes the worker entry point as `translator-worker.cjs`.

This runtime folder is generated automatically. It can be deleted safely while Crystal Translator is stopped; it will be recreated on the next translation or `npm run warmup`.


## v0.1.6 — FR/EN model source fix

The normal FR↔EN path no longer fetches Mozilla's Google Cloud model registry. Crystal Translator uses its bundled FR/EN Release metadata and downloads the three files for each direction from the Firefox-model Hugging Face mirror first:

- `model.*.intgemm.alphas.bin.gz`
- `vocab.*.spm.gz`
- `lex.50.50.*.s2t.bin.gz`

This avoids the HTTP 401/403 responses seen from the Mozilla GCS bucket on some VPS networks. Set `model.refreshMozillaRegistry` to `true` only if you explicitly want to refresh metadata from Mozilla; it is `false` by default.

## Mention protection

Discord `@` mentions, channel references and emotes are **hard-protected** and never sent to Bergamot at all. Native user/role mentions (`<@id>`, `<@&role>`), `@everyone`, `@here`, literal handles such as `@name`, channel mentions/names (`<#channel>`, `#general-fr`), custom emotes (`<:name:id>`, `<a:name:id>`) and Unicode emojis are split out before translation and reinserted unchanged afterwards. Webhook relays use `allowedMentions: { parse: [] }`, so relayed translations do not generate a second ping.
