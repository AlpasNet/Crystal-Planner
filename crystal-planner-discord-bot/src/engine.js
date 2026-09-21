import { DiscordApi } from "./discord/api.js";
import { syncBoard } from "./modules/boards.js";
import { LODESTONE_FEEDS, syncLodestone } from "./modules/lodestone.js";
import { syncEventAnnouncements } from "./modules/eventAnnouncements.js";

export class CrystalPlannerEngine {
  constructor({ config, store, logger, token }) {
    this.config = config;
    this.store = store;
    this.logger = logger;
    this.discord = new DiscordApi(token, logger);
  }

  async check() {
    const botName = await this.discord.verifyBot();
    this.logger.info(`Discord authentication successful: ${botName}`);
    this.#logPaths();
    this.#logAnnouncementConfig();
    return botName;
  }

  async runOnce() {
    const summary = { lodestoneMessages: 0, boardMessages: 0, errors: 0 };
    this.logger.info("Global synchronization started.");
    this.#logPaths();

    const botName = await this.discord.verifyBot();
    this.logger.info(`Discord authentication successful: ${botName}`);
    this.#logAnnouncementConfig();

    try {
      const lodestoneResult = await syncLodestone({
        config: this.config,
        discord: this.discord,
        store: this.store,
        logger: this.logger
      });
      summary.lodestoneMessages = lodestoneResult.sent;
      summary.errors += lodestoneResult.errors;
    } catch (error) {
      summary.errors++;
      this.logger.error(`Lodestone synchronization error: ${error.message}`);
    }

    const boards = [
      {
        key: "events", label: "Events/Polls", board: this.config.events,
        jsonUrl: this.config.urls.events, generatorUrl: this.config.urls.generator,
        generatorDelaySeconds: this.config.jsonReadDelaySeconds,
        crosspostAnnouncements: false
      },
      { key: "rules", label: "Rules", board: this.config.rules, jsonUrl: this.config.urls.rules },
      { key: "guides", label: "Guides", board: this.config.guides, jsonUrl: this.config.urls.guides },
      { key: "macros", label: "Macros", board: this.config.macros, jsonUrl: this.config.urls.macros }
    ];

    for (const entry of boards) {
      try {
        summary.boardMessages += await syncBoard({
          key: entry.key,
          label: entry.label,
          enabled: entry.board.enabled,
          channelId: entry.board.channelId,
          jsonUrl: entry.jsonUrl,
          generatorUrl: entry.generatorUrl || "",
          generatorDelaySeconds: entry.generatorDelaySeconds || 0,
          crosspostAnnouncements: entry.crosspostAnnouncements ?? true,
          discord: this.discord,
          store: this.store,
          logger: this.logger
        });
      } catch (error) {
        summary.errors++;
        this.logger.error(`${entry.label} synchronization error: ${error.message}`);
      }
    }

    try {
      summary.boardMessages += await syncEventAnnouncements({
        config: this.config,
        discord: this.discord,
        store: this.store,
        logger: this.logger,
        callGenerator: Boolean(this.config.eventAnnouncements.enabled && !this.config.events.enabled)
      });
    } catch (error) {
      summary.errors++;
      this.logger.error(`Event announcements summary synchronization error: ${error.message}`);
    }

    const description = `${summary.lodestoneMessages} Lodestone message(s), ${summary.boardMessages} board change(s), ${summary.errors} error(s)`;
    this.store.setLastRun(summary.errors === 0, description);
    this.logger.info(`Global synchronization complete: ${description}.`);
    return summary;
  }

  async clearLodestoneChannels() {
    await this.discord.verifyBot();
    const unique = new Set();
    let total = 0;
    for (const feed of LODESTONE_FEEDS) {
      const channelId = String(this.config.lodestone[feed.channelConfigKey] ?? "").trim();
      if (!channelId) continue;
      if (!unique.has(channelId)) {
        unique.add(channelId);
        this.logger.info(`Clearing Lodestone ${feed.label} RSS channel ${channelId}.`);
        total += await this.discord.clearChannel(channelId);
      }
      const feedState = this.store.getLodestoneFeedState(feed.key);
      feedState.channelId = channelId;
      feedState.initialized = false;
      feedState.baselinePending = false;
      feedState.seenIds = [];
      feedState.messages = {};
      this.store.saveLodestoneState();
    }
    if (!unique.size) throw new Error("No Lodestone channel is configured.");
    this.logger.info(`Lodestone cleanup complete: ${total} message(s) deleted.`);
    return total;
  }

  #logAnnouncementConfig() {
    const settings = this.config.eventAnnouncements || {};
    if (settings.enabled) {
      this.logger.info(`Event announcements summary: ENABLED on channel ${settings.channelId}; publish=${settings.publishAnnouncements !== false}.`);
    } else {
      this.logger.info("Event announcements summary: DISABLED by effective configuration.");
    }
    this.logger.info("Events/Polls automatic crosspost: disabled; announcement publishing belongs to Event announcements summary.");
  }

  #logPaths() {
    if (!this.config.webFolderUrl) return;
    this.logger.info(`Web folder: ${this.config.webFolderUrl}`);
    this.logger.info(`Events generator: ${this.config.urls.generator}`);
    this.logger.info(`Events/Polls JSON: ${this.config.urls.events}`);
    this.logger.info(`Rules JSON: ${this.config.urls.rules}`);
    this.logger.info(`Guides JSON: ${this.config.urls.guides}`);
    this.logger.info(`Macros JSON: ${this.config.urls.macros}`);
  }
}
