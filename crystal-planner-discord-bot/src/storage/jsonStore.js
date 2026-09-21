import fs from "node:fs";
import path from "node:path";

const DEFAULT_STATE = {
  version: 5,
  seen: {},
  boardHashes: {},
  events: {
    channelId: "",
    initialized: false,
    messages: {}
  },
  eventAnnouncements: {
    channelId: "",
    messageId: "",
    hash: "",
    crossposted: false
  },
  lodestone: {
    feeds: {
      news: { channelId: "", initialized: false, baselinePending: false, seenIds: [], messages: {} },
      topics: { channelId: "", initialized: false, baselinePending: false, seenIds: [], messages: {} }
    }
  },
  lastRun: {}
};

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function normalizeFeedState(raw, previousVersion) {
  const messages = raw?.messages && typeof raw.messages === "object" ? raw.messages : {};
  const existingSeen = Array.isArray(raw?.seenIds) ? raw.seenIds.map(String).filter(Boolean) : [];
  const messageIds = Object.keys(messages);
  const migratedFromTop10 = Number(previousVersion || 0) < 3 && messageIds.length > 0;
  return {
    channelId: String(raw?.channelId ?? ""),
    initialized: raw?.initialized === true || migratedFromTop10,
    baselinePending: raw?.baselinePending === true || migratedFromTop10,
    seenIds: [...new Set([...existingSeen, ...messageIds])],
    messages
  };
}

function normalizeState(raw) {
  const previousVersion = Number(raw?.version || 1);
  const lodestoneFeeds = raw?.lodestone?.feeds && typeof raw.lodestone.feeds === "object"
    ? raw.lodestone.feeds
    : {};
  return {
    ...clone(DEFAULT_STATE),
    ...(raw && typeof raw === "object" ? raw : {}),
    version: 5,
    seen: raw?.seen && typeof raw.seen === "object" ? raw.seen : {},
    boardHashes: raw?.boardHashes && typeof raw.boardHashes === "object" ? raw.boardHashes : {},
    events: {
      ...clone(DEFAULT_STATE.events),
      ...(raw?.events && typeof raw.events === "object" ? raw.events : {}),
      messages: raw?.events?.messages && typeof raw.events.messages === "object" ? raw.events.messages : {}
    },
    eventAnnouncements: {
      ...clone(DEFAULT_STATE.eventAnnouncements),
      ...(raw?.eventAnnouncements && typeof raw.eventAnnouncements === "object" ? raw.eventAnnouncements : {})
    },
    lodestone: {
      feeds: {
        news: normalizeFeedState(lodestoneFeeds.news, previousVersion),
        topics: normalizeFeedState(lodestoneFeeds.topics, previousVersion)
      }
    },
    lastRun: raw?.lastRun && typeof raw.lastRun === "object" ? raw.lastRun : {}
  };
}

export class JsonStore {
  constructor(filePath, logger) {
    this.filePath = path.resolve(filePath);
    this.logger = logger;
    this.state = normalizeState(null);
  }

  load() {
    fs.mkdirSync(path.dirname(this.filePath), { recursive: true });
    if (!fs.existsSync(this.filePath)) {
      this.state = normalizeState(null);
      this.save();
      return this.state;
    }
    try {
      this.state = normalizeState(JSON.parse(fs.readFileSync(this.filePath, "utf8")));
      this.save();
    } catch (error) {
      const backup = `${this.filePath}.corrupt-${Date.now()}`;
      fs.copyFileSync(this.filePath, backup);
      this.logger?.error(`State JSON is invalid. A copy was saved to ${backup}. Refusing to continue to avoid duplicate or destructive Discord operations.`);
      throw new Error(`Unable to parse state JSON ${this.filePath}: ${error.message}`);
    }
    return this.state;
  }

  save() {
    fs.mkdirSync(path.dirname(this.filePath), { recursive: true });
    const temporary = `${this.filePath}.tmp-${process.pid}`;
    const data = `${JSON.stringify(this.state, null, 2)}\n`;
    fs.writeFileSync(temporary, data, { mode: 0o600 });
    JSON.parse(fs.readFileSync(temporary, "utf8"));
    fs.renameSync(temporary, this.filePath);
    try { fs.chmodSync(this.filePath, 0o600); } catch {}
  }

  getSeen(category) {
    const values = Array.isArray(this.state.seen[category]) ? this.state.seen[category] : [];
    return values.map(value => String(value).trim()).filter(Boolean);
  }

  saveSeen(category, values) {
    const unique = [...new Set(values.map(value => String(value).trim()).filter(Boolean))];
    this.state.seen[category] = unique.slice(-200);
    this.save();
  }

  setBoardHash(key, hash) {
    this.state.boardHashes[key] = String(hash ?? "");
    this.save();
  }

  getBoardHash(key) {
    return String(this.state.boardHashes[key] ?? "");
  }

  getEventState() {
    return this.state.events;
  }

  saveEventState() {
    this.save();
  }

  getEventAnnouncementState() {
    return this.state.eventAnnouncements;
  }

  saveEventAnnouncementState() {
    this.save();
  }

  getLodestoneFeedState(key) {
    if (!this.state.lodestone?.feeds?.[key]) {
      if (!this.state.lodestone) this.state.lodestone = { feeds: {} };
      if (!this.state.lodestone.feeds) this.state.lodestone.feeds = {};
      this.state.lodestone.feeds[key] = {
        channelId: "",
        initialized: false,
        baselinePending: false,
        seenIds: [],
        messages: {}
      };
    }
    return this.state.lodestone.feeds[key];
  }

  saveLodestoneState() {
    this.save();
  }

  setLastRun(success, summary) {
    this.state.lastRun = {
      timestamp: Date.now(),
      success: Boolean(success),
      summary: String(summary ?? "")
    };
    this.save();
  }
}
