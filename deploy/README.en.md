# Drivecraft · local installation

[Español](README.md) · [English](README.en.md) · [Project overview](../README.en.md)

This distribution installs Drivecraft only, without the author's stored files, accounts or development-environment configuration.

## Requirements

- Docker Engine and Compose v2 on Linux, or Docker Desktop with **Linux containers** on Windows/macOS.
- Internet access for the initial image build. PHP, MariaDB, FFmpeg, yt-dlp and Python do not need to be installed on the host.
- **64-bit Raspberry Pi (`linux/arm64`)** or **x86_64 PC (`linux/amd64`)**. 32-bit ARM is not provided.
- Enough space for Docker images and your files. The first build can take several minutes, particularly on Raspberry Pi.

## Installation

Clone or download and extract the repository. On Linux/macOS:

```bash
cd drivecraft/deploy
bash install.sh
```

On Windows, open `deploy/Instalar.cmd`, or run:

```powershell
cd drivecraft\deploy
.\Instalar.cmd
```

The installer currently displays its prompts in Spanish. It asks for the deployment name, architecture, HTTP port, localhost/LAN access, initial administrator username and password. Docker's architecture determines the suggested platform. Pressing Enter at the password prompt generates a random password.

Settings are stored in **`deploy/.env`**, excluded from Git. Internal database passwords are generated automatically. The selected/generated initial password can be found in that private file; there is no universal administrator password.

Default address: **http://localhost:8088/drive/**. Suggested username: **`admin`**. Use your selected values if different. LAN access requires selecting that option and using `http://SERVER_IP:PORT/drive/`.

First startup creates the empty schema and administrator account. The initial password must be changed at first login. Rerunning the installer preserves `.env`, accounts and data. **Changing `DC_ADMIN_PASSWORD` later does not reset an existing account's password.**

To configure without building or starting containers:

```bash
bash install.sh --configure-only
```

```powershell
.\Instalar.cmd -ConfigureOnly
```

## Storage and deduplication

Use Linux storage with real hardlink support, such as **ext3, ext4, XFS or Btrfs**, within the same filesystem/volume. Permissions must allow hardlinks. Startup verifies support by creating a real link and comparing device, inode and link count.

The deployment uses **Docker named volumes**. On Windows/macOS these live inside Docker Desktop's Linux VM. Do not replace `/drive` with an NTFS/exFAT/FAT/SMB shared folder and expect the same deduplication guarantees. Content-hash detection and physical savings through hardlinks are distinct features.

Volumes are isolated by deployment name: `storage` contains files, thumbnails, sessions, temporary files and private maintenance data; `database` contains MariaDB. The database does not expose a host port.

## Included components

- Apache/PHP, GD/Imagick, Poppler, FFmpeg/FFprobe, yt-dlp and Deno. Downloads/conversions run on the deployment server.
- MariaDB with automatic creation of the complete empty schema. No production rows are imported.
- An integrated supervisor for imports, previews, conversions and external-service maintenance. **No host cron jobs are required.**
- Spanish/English application interface. Installer prompts are currently Spanish.
- No Nginx, Let's Encrypt, mandatory domain, external Docker network, host-specific Raspberry paths, Docker CLI/socket or services from other projects.

Local access uses **HTTP**. Sharing and receiving links use the scheme and port opened in the browser. For Internet exposure, configure HTTPS in your infrastructure and set `DC_COOKIE_SECURE=1` in `.env`. This package does not install a public proxy or certificates. Browser features requiring a secure context may need HTTPS outside `localhost`.

Optional weekly AI maintenance starts disabled, without an API token. Configure it in the application if wanted. Each installation keeps its own private encryption key for that token.

## Operations

From `deploy/`:

```bash
docker compose ps
docker compose logs --tail 80 app
docker compose logs --tail 80 db
docker compose stop
docker compose start
docker compose down
```

`down` preserves volumes. **`down -v` permanently deletes that deployment's database and stored files.** Only use it when intentionally removing that copy entirely.

Back up **both volumes** and `.env` before upgrades. If the packaged schema changes, startup requests a reviewed migration rather than modifying existing data blindly. Runtime adapter repairs in the container layer are replaced when the image is recreated; private repair backups remain in the volume.

To update yt-dlp, set `DC_YTDLP_VERSION` to a published version, then run `docker compose build --pull app` and `docker compose up -d`. AI maintenance can propose bounded adapter changes; it does not install system dependencies.

## Building a distributable archive

Maintainers with Python 3 can run:

```bash
python3 deploy/export-release.py
```

The archive in `deploy/dist/` includes allowlisted application/deployment files, documentation, screenshots, third-party notices and a SHA-256 manifest. `.env`, host configuration, databases, backups, volumes and uploaded files are excluded. A checksum file is also generated.

Publish only a clean export, not a live installation directory. Original code is MIT licensed; bundled dependencies retain their notices. `schema.json` contains table definitions only. `export-schema.php` is a maintainer CLI utility for producing reviewed schema updates.

## Validation

See [validation results](TESTS.en.md). x86_64 clean installation and restart persistence were tested; this distribution still needs native ARM64 validation and a real YouTube import test in the clean environment.

## Dependency references

- [Official PHP image](https://hub.docker.com/_/php)
- [MariaDB Docker healthcheck](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/using-healthcheck-sh)
- [yt-dlp dependencies](https://github.com/yt-dlp/yt-dlp#dependencies)
- [Initially pinned yt-dlp version](https://pypi.org/project/yt-dlp/2026.10.26/)
