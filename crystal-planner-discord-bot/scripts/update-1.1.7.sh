#!/usr/bin/env bash
set -euo pipefail
if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run this updater as root: sudo ./scripts/update-1.1.7.sh" >&2
  exit 1
fi
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR=/opt/crystal-planner
if [[ ! -d "$APP_DIR/src" || ! -f "$APP_DIR/package.json" ]]; then
  echo "Crystal Planner is not installed in $APP_DIR." >&2
  exit 1
fi
install -d -m 0755 "$APP_DIR/src/modules" "$APP_DIR/src/storage" "$APP_DIR/src/discord"
install -m 0644 "$SOURCE_DIR/src/modules/eventAnnouncements.js" "$APP_DIR/src/modules/eventAnnouncements.js"
install -m 0644 "$SOURCE_DIR/src/config.js" "$APP_DIR/src/config.js"
install -m 0644 "$SOURCE_DIR/src/engine.js" "$APP_DIR/src/engine.js"
install -m 0644 "$SOURCE_DIR/src/index.js" "$APP_DIR/src/index.js"
install -m 0644 "$SOURCE_DIR/src/storage/jsonStore.js" "$APP_DIR/src/storage/jsonStore.js"
install -m 0644 "$SOURCE_DIR/src/discord/api.js" "$APP_DIR/src/discord/api.js"
install -m 0644 "$SOURCE_DIR/package.json" "$APP_DIR/package.json"
cd "$APP_DIR"
if command -v npm >/dev/null 2>&1; then npm install --omit=dev --no-audit --no-fund; fi
systemctl restart crystal-planner.service
echo "Crystal Planner Server 1.1.7 installed and restarted."
echo "Regular Events/Polls crossposting is disabled."
echo "Use eventAnnouncements.channelId for the Discord Announcement channel."
echo "Follow logs: journalctl -u crystal-planner -f"
