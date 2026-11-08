#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run this installer with sudo."
    exit 1
fi

PANEL="${PANEL_DIR:-/var/www/pterodactyl}"
INSTALL_DIR="/opt/steam-game-updates"
DATA_DIR="$PANEL/storage/app/steam-update-checker"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for command in python3 flock; do
    command -v "$command" >/dev/null || {
        echo "Missing dependency: $command"
        exit 1
    }
done

[[ -d "$PANEL" ]] || {
    echo "Pterodactyl panel directory not found: $PANEL"
    exit 1
}

install -d -m 755 "$INSTALL_DIR"
if [[ ! -d "$DATA_DIR" ]]; then
    install -d -o www-data -g www-data -m 750 "$DATA_DIR"
fi

if [[ ! -w "$DATA_DIR" ]]; then
    echo "Warning: Check ownership and permissions of $DATA_DIR"
fi

install -m 644 "$SCRIPT_DIR/check.py" "$INSTALL_DIR/check.py"

if [[ ! -f "$INSTALL_DIR/config.json" ]]; then
    install -m 644 "$SCRIPT_DIR/config.example.json" \
        "$INSTALL_DIR/config.json"
fi

CRON="/etc/cron.d/steam-game-updates"

cat > "$CRON" <<EOF
# Steam Game Updates - Blueprint Extension
* * * * * root /usr/bin/flock -n /run/lock/steam-game-updates.lock /usr/bin/python3 $INSTALL_DIR/check.py --config $INSTALL_DIR/config.json --settings $DATA_DIR/settings.json --admin-settings $DATA_DIR/admin.json --output $DATA_DIR/status.json --notify >> /var/log/steam-game-updates.log 2>&1
EOF

chmod 644 "$CRON"

echo "Steam Game Updates host checker installed."
echo "Panel: $PANEL"
echo "Checker: $INSTALL_DIR/check.py"
echo "Cron: $CRON"
echo "Existing Discord settings were not modified."
