#!/usr/bin/env node
import path from "node:path";
import process from "node:process";
import { loadConfig, loadDotEnv } from "./config.js";
import { CrystalPlannerEngine } from "./engine.js";
import { Logger } from "./logger.js";
import { startPresence, stopPresence } from "./discord/presence.js";
import { JsonStore } from "./storage/jsonStore.js";
import { sleep } from "./utils.js";

function parseArgs(argv) {
  const result = { command: "run", config: null, state: null };
  const args = [...argv];
  if (args[0] && !args[0].startsWith("-")) result.command = args.shift();
  while (args.length) {
    const key = args.shift();
    if (key === "--config") result.config = args.shift();
    else if (key === "--state") result.state = args.shift();
    else if (key === "--help" || key === "-h") result.command = "help";
    else throw new Error(`Unknown argument: ${key}`);
  }
  return result;
}

function usage() {
  console.log(`Crystal Planner Server\n\nUsage:\n  node src/index.js run [--config FILE] [--state FILE]\n  node src/index.js sync [--config FILE] [--state FILE]\n  node src/index.js check [--config FILE] [--state FILE]\n  node src/index.js clear-lodestone [--config FILE] [--state FILE]\n\nEnvironment:\n  DISCORD_TOKEN              required\n  CRYSTAL_PLANNER_CONFIG     default config path\n  CRYSTAL_PLANNER_STATE      default state path\n  LOG_LEVEL                  debug|info|warn|error\n`);
}

async function main() {
  loadDotEnv(path.resolve(".env"));
  const args = parseArgs(process.argv.slice(2));
  if (args.command === "help") return usage();

  const logger = new Logger();
  const configPath = path.resolve(args.config || process.env.CRYSTAL_PLANNER_CONFIG || "config/config.json");
  const statePath = path.resolve(args.state || process.env.CRYSTAL_PLANNER_STATE || "data/state.json");
  const token = process.env.DISCORD_TOKEN || "";
  const config = loadConfig(configPath);
  logger.info(`Configuration file loaded: ${configPath}`);
  logger.info(`State file: ${statePath}`);
  const store = new JsonStore(statePath, logger);
  store.load();
  const engine = new CrystalPlannerEngine({ config, store, logger, token });

  if (args.command === "check") {
    await engine.check();
    logger.info(`Configuration OK. State file: ${statePath}`);
    return;
  }
  if (args.command === "sync") {
    const summary = await engine.runOnce();
    process.exitCode = summary.errors ? 2 : 0;
    return;
  }
  if (args.command === "clear-lodestone") {
    await engine.clearLodestoneChannels();
    return;
  }
  if (args.command !== "run") throw new Error(`Unknown command: ${args.command}`);

  await engine.check();

  let stopping = false;
  const stop = signal => {
    if (!stopping) logger.info(`${signal} received; Crystal Planner will stop after the current operation.`);
    stopping = true;
  };
  process.on("SIGTERM", () => stop("SIGTERM"));
  process.on("SIGINT", () => stop("SIGINT"));

  // The REST synchronization remains unchanged; the Gateway is used only
  // to expose a green Online presence while the systemd service is running.
  let presenceClient = null;
  try {
    presenceClient = await startPresence(token, logger);

    while (!stopping) {
      try {
        await engine.runOnce();
      } catch (error) {
        store.setLastRun(false, error.message);
        logger.error(`Synchronization cycle failed: ${error.stack || error.message}`);
      }
      if (stopping) break;
      const waitMs = config.syncIntervalMinutes * 60 * 1000;
      logger.info(`Next synchronization in ${config.syncIntervalMinutes} minute(s).`);
      // Sleep in short chunks so systemd stop requests are observed quickly.
      let remaining = waitMs;
      while (!stopping && remaining > 0) {
        const chunk = Math.min(remaining, 5000);
        await sleep(chunk);
        remaining -= chunk;
      }
    }
  } finally {
    await stopPresence(presenceClient, logger);
  }

  logger.info("Crystal Planner stopped.");
}

main().catch(error => {
  console.error(`${new Date().toISOString()} [ERROR] ${error.stack || error.message}`);
  process.exitCode = 1;
});
