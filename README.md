# Steam Game Updates

A Blueprint extension for Pterodactyl by IceEagle132.

Monitor Steam game updates directly from your Pterodactyl panel.

## Features

- Automatic Steam game server discovery
- Update status displayed on each server's Console page
- Administrator-controlled global update intervals
- Per-server Discord webhook notifications
- Duplicate update notification protection
- Dashboard showing installed and latest Steam builds
- Read-only monitoring; never installs updates or restarts servers

## Requirements

- Pterodactyl Panel with Blueprint
- Python 3, cron, and flock
- Access to game server Steam manifests
- Internet access for Steam build checks

## Installation

1. Download steamupdates.blueprint from GitHub Releases.
2. Install it in your Pterodactyl panel.
3. Clone this repository on the host machine.
4. Run sudo bash host/install.sh.
5. Configure the check interval under Admin > Extensions > Steam Game Updates.
6. Configure Discord notifications under each server's Settings page.

The current host checker assumes the panel and game volumes
are accessible on the same machine.

## Tested Games

- RuneScape: Dragonwilds
- DayZ Server

Other Steam-based game servers may also work.

## License

MIT

## Author

IceEagle132
