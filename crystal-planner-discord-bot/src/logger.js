const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };

export class Logger {
  constructor(level = process.env.LOG_LEVEL || "info") {
    this.level = LEVELS[String(level).toLowerCase()] ?? LEVELS.info;
  }

  #write(level, message, extra) {
    if (LEVELS[level] < this.level) return;
    const line = `${new Date().toISOString()} [${level.toUpperCase()}] ${message}`;
    const fn = level === "error" ? console.error : level === "warn" ? console.warn : console.log;
    if (extra === undefined) fn(line);
    else fn(line, extra);
  }

  debug(message, extra) { this.#write("debug", message, extra); }
  info(message, extra) { this.#write("info", message, extra); }
  warn(message, extra) { this.#write("warn", message, extra); }
  error(message, extra) { this.#write("error", message, extra); }
}
