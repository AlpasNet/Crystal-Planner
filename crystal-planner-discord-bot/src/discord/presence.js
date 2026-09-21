const GATEWAY_URL = "wss://gateway.discord.gg/?v=10&encoding=json";
const FATAL_CLOSE_CODES = new Set([4004, 4010, 4011, 4012, 4013, 4014]);
const CLEAR_SESSION_CLOSE_CODES = new Set([4007, 4009]);

export function buildIdentifyPayload(token) {
  return {
    op: 2,
    d: {
      token,
      intents: 0,
      properties: {
        os: process.platform,
        browser: "crystal-planner",
        device: "crystal-planner"
      },
      presence: {
        since: null,
        activities: [],
        status: "online",
        afk: false
      }
    }
  };
}

export function buildResumePayload(token, sessionId, sequence) {
  return {
    op: 6,
    d: {
      token,
      session_id: sessionId,
      seq: sequence
    }
  };
}

function withGatewayQuery(url) {
  const base = String(url || "wss://gateway.discord.gg").replace(/\/$/, "");
  return `${base}/?v=10&encoding=json`;
}

class DiscordPresenceGateway {
  constructor(token, logger, WebSocketImpl) {
    this.token = token;
    this.logger = logger;
    this.WebSocketImpl = WebSocketImpl;
    this.socket = null;
    this.stopping = false;
    this.sequence = null;
    this.sessionId = null;
    this.resumeGatewayUrl = null;
    this.heartbeatInterval = null;
    this.firstHeartbeatTimer = null;
    this.heartbeatAcked = true;
    this.reconnectTimer = null;
    this.reconnectDelayMs = 1000;
    this.hasBeenReady = false;
  }

  async start() {
    await this.#connect(true);
  }

  async stop() {
    this.stopping = true;
    this.#clearTimers();
    if (!this.socket) return;

    const socket = this.socket;
    this.socket = null;
    try {
      if (socket.readyState === this.WebSocketImpl.OPEN || socket.readyState === this.WebSocketImpl.CONNECTING) {
        socket.close(1000, "Crystal Planner stopped");
      }
    } catch {
      try { socket.terminate(); } catch { /* ignored */ }
    }
  }

  #clearHeartbeat() {
    if (this.firstHeartbeatTimer) clearTimeout(this.firstHeartbeatTimer);
    if (this.heartbeatInterval) clearInterval(this.heartbeatInterval);
    this.firstHeartbeatTimer = null;
    this.heartbeatInterval = null;
    this.heartbeatAcked = true;
  }

  #clearTimers() {
    this.#clearHeartbeat();
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
    this.reconnectTimer = null;
  }

  #send(payload) {
    if (!this.socket || this.socket.readyState !== this.WebSocketImpl.OPEN) return false;
    this.socket.send(JSON.stringify(payload));
    return true;
  }

  #sendHeartbeat() {
    if (!this.heartbeatAcked) {
      this.logger.warn("Discord Gateway heartbeat was not acknowledged; reconnecting.");
      try { this.socket?.terminate(); } catch { /* ignored */ }
      return;
    }
    this.heartbeatAcked = false;
    this.#send({ op: 1, d: this.sequence });
  }

  #startHeartbeat(intervalMs) {
    this.#clearHeartbeat();
    this.heartbeatAcked = true;

    const firstDelay = Math.floor(Math.random() * Math.max(1, intervalMs));
    this.firstHeartbeatTimer = setTimeout(() => {
      this.#sendHeartbeat();
      this.heartbeatInterval = setInterval(() => this.#sendHeartbeat(), intervalMs);
    }, firstDelay);
  }

  #identifyOrResume() {
    if (this.sessionId && this.sequence !== null) {
      this.#send(buildResumePayload(this.token, this.sessionId, this.sequence));
      return;
    }
    this.#send(buildIdentifyPayload(this.token));
  }

  #scheduleReconnect() {
    if (this.stopping || this.reconnectTimer) return;
    const delay = this.reconnectDelayMs;
    this.reconnectDelayMs = Math.min(this.reconnectDelayMs * 2, 30000);
    this.logger.warn(`Discord Gateway reconnect scheduled in ${Math.ceil(delay / 1000)} second(s).`);
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.#connect(false).catch(error => {
        if (!this.stopping) {
          this.logger.error(`Discord Gateway reconnect failed: ${error?.message || error}`);
          this.#scheduleReconnect();
        }
      });
    }, delay);
  }

  async #connect(initial) {
    if (this.stopping) return;
    const endpoint = this.sessionId && this.resumeGatewayUrl
      ? withGatewayQuery(this.resumeGatewayUrl)
      : GATEWAY_URL;

    await new Promise((resolve, reject) => {
      let settled = false;
      let readyTimeout = null;
      const socket = new this.WebSocketImpl(endpoint, { perMessageDeflate: false });
      this.socket = socket;

      const settleResolve = () => {
        if (settled) return;
        settled = true;
        if (readyTimeout) clearTimeout(readyTimeout);
        resolve();
      };
      const settleReject = error => {
        if (settled) return;
        settled = true;
        if (readyTimeout) clearTimeout(readyTimeout);
        reject(error);
      };

      readyTimeout = setTimeout(() => {
        const error = new Error("Discord Gateway did not become ready within 30 seconds.");
        if (initial) settleReject(error);
        try { socket.terminate(); } catch { /* ignored */ }
      }, 30000);

      socket.on("open", () => {
        this.logger.info("Discord Gateway WebSocket connected; waiting for READY.");
      });

      socket.on("message", raw => {
        let payload;
        try {
          payload = JSON.parse(raw.toString());
        } catch {
          this.logger.warn("Discord Gateway sent an unreadable payload.");
          return;
        }

        if (payload.s !== null && payload.s !== undefined) this.sequence = payload.s;

        switch (payload.op) {
          case 10: {
            const interval = Number(payload.d?.heartbeat_interval || 0);
            if (!Number.isFinite(interval) || interval <= 0) {
              this.logger.error("Discord Gateway HELLO did not contain a valid heartbeat interval.");
              try { socket.terminate(); } catch { /* ignored */ }
              return;
            }
            this.#startHeartbeat(interval);
            this.#identifyOrResume();
            break;
          }
          case 11:
            this.heartbeatAcked = true;
            break;
          case 1:
            this.#send({ op: 1, d: this.sequence });
            break;
          case 7:
            this.logger.warn("Discord Gateway requested a reconnect.");
            try { socket.close(4000, "Gateway requested reconnect"); } catch { socket.terminate(); }
            break;
          case 9: {
            const canResume = payload.d === true;
            if (!canResume) {
              this.sessionId = null;
              this.resumeGatewayUrl = null;
              this.sequence = null;
            }
            this.logger.warn(`Discord Gateway session invalid; ${canResume ? "resume" : "identify"} will be retried.`);
            const delay = 1000 + Math.floor(Math.random() * 4000);
            setTimeout(() => {
              try { socket.close(4000, "Invalid session"); } catch { socket.terminate(); }
            }, delay);
            break;
          }
          case 0:
            if (payload.t === "READY") {
              this.sessionId = payload.d?.session_id || null;
              this.resumeGatewayUrl = payload.d?.resume_gateway_url || null;
              this.hasBeenReady = true;
              this.reconnectDelayMs = 1000;
              this.logger.info(`Discord Gateway READY as ${payload.d?.user?.username || "bot"}. Presence: online (green dot), no activity.`);
              settleResolve();
            } else if (payload.t === "RESUMED") {
              this.hasBeenReady = true;
              this.reconnectDelayMs = 1000;
              this.logger.info("Discord Gateway session resumed. Presence: online.");
              settleResolve();
            }
            break;
          default:
            break;
        }
      });

      socket.on("error", error => {
        this.logger.error(`Discord Gateway WebSocket error: ${error?.message || error}`);
      });

      socket.on("close", (code, reasonBuffer) => {
        if (this.socket === socket) this.socket = null;
        this.#clearHeartbeat();
        const reason = reasonBuffer?.toString?.() || "";

        if (this.stopping) {
          settleResolve();
          return;
        }

        if (CLEAR_SESSION_CLOSE_CODES.has(code)) {
          this.sessionId = null;
          this.resumeGatewayUrl = null;
          this.sequence = null;
        }

        if (FATAL_CLOSE_CODES.has(code)) {
          const error = new Error(`Discord Gateway closed with fatal code ${code}${reason ? `: ${reason}` : ""}`);
          this.logger.error(error.message);
          settleReject(error);
          return;
        }

        const message = `Discord Gateway disconnected (code ${code}${reason ? `: ${reason}` : ""}).`;
        if (initial && !this.hasBeenReady) {
          settleReject(new Error(message));
          return;
        }

        this.logger.warn(message);
        settleResolve();
        this.#scheduleReconnect();
      });
    });
  }
}

export async function startPresence(token, logger) {
  if (!token) throw new Error("DISCORD_TOKEN is required for Discord presence.");
  const { default: WebSocket } = await import("ws");
  const gateway = new DiscordPresenceGateway(token, logger, WebSocket);
  await gateway.start();
  return gateway;
}

export async function stopPresence(gateway, logger) {
  if (!gateway) return;
  try {
    await gateway.stop();
    logger.info("Discord Gateway disconnected. Presence will appear offline.");
  } catch (error) {
    logger.warn(`Unable to close Discord Gateway cleanly: ${error?.message || error}`);
  }
}
