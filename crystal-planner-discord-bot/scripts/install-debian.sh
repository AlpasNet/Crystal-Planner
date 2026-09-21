#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run this installer as root: sudo ./scripts/install-debian.sh" >&2
  exit 1
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR=/opt/crystal-planner
CONFIG_DIR=/etc/crystal-planner
STATE_DIR=/var/lib/crystal-planner
SERVICE_USER=crystalplanner

if ! command -v node >/dev/null 2>&1; then
  echo "Node.js 20+ is required. Install it first, then run this script again." >&2
  exit 1
fi
NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
if [[ "$NODE_MAJOR" -lt 20 ]]; then
  echo "Node.js 20+ is required; found $(node --version)." >&2
  exit 1
fi
if ! id "$SERVICE_USER" >/dev/null 2>&1; then
  useradd --system --home-dir "$STATE_DIR" --shell /usr/sbin/nologin "$SERVICE_USER"
fi

install -d -m 0755 "$APP_DIR"
install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_DIR"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_USER" "$STATE_DIR"

# Deploy source without local state/secrets/node_modules.
rm -rf "$APP_DIR/src" "$APP_DIR/scripts" "$APP_DIR/systemd" "$APP_DIR/config" "$APP_DIR/test" "$APP_DIR/node_modules" "$APP_DIR/package-lock.json"
cp -a "$SOURCE_DIR/src" "$APP_DIR/"
cp -a "$SOURCE_DIR/scripts" "$APP_DIR/"
cp -a "$SOURCE_DIR/systemd" "$APP_DIR/"
cp -a "$SOURCE_DIR/test" "$APP_DIR/"
cp -a "$SOURCE_DIR/package.json" "$APP_DIR/"
if [[ -f "$SOURCE_DIR/package-lock.json" ]]; then cp -a "$SOURCE_DIR/package-lock.json" "$APP_DIR/"; fi

echo "Installing Node.js runtime dependencies..."
cd "$APP_DIR"
if [[ -f package-lock.json ]]; then
  npm ci --omit=dev --no-audit --no-fund
else
  npm install --omit=dev --no-audit --no-fund
fi

if [[ ! -f "$CONFIG_DIR/config.json" ]]; then
  install -m 0640 -o root -g "$SERVICE_USER" "$SOURCE_DIR/config/config.example.json" "$CONFIG_DIR/config.json"
  echo "Created $CONFIG_DIR/config.json"
fi
if [[ ! -f "$CONFIG_DIR/crystal-planner.env" ]]; then
  install -m 0640 -o root -g "$SERVICE_USER" "$SOURCE_DIR/.env.example" "$CONFIG_DIR/crystal-planner.env"
  echo "Created $CONFIG_DIR/crystal-planner.env"
fi

install -m 0644 "$SOURCE_DIR/systemd/crystal-planner.service" /etc/systemd/system/crystal-planner.service
systemctl daemon-reload
systemctl enable crystal-planner.service

cat <<MSG

Crystal Planner Server installed.

1. Edit the Discord token:
   nano $CONFIG_DIR/crystal-planner.env

2. Edit channel IDs / Web folder:
   nano $CONFIG_DIR/config.json

3. Validate:
   sudo -u $SERVICE_USER env \$(grep -v '^#' $CONFIG_DIR/crystal-planner.env | xargs) \
     CRYSTAL_PLANNER_CONFIG=$CONFIG_DIR/config.json \
     CRYSTAL_PLANNER_STATE=$STATE_DIR/state.json \
     node $APP_DIR/src/index.js check

4. Start:
   systemctl start crystal-planner

5. Logs:
   journalctl -u crystal-planner -f
MSG
