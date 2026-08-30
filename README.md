<p align="center">
  <a href="https://github.com/mattiasghodsian/Minizo/">
    <img alt="Iroh" src="https://i.imgur.com/Wfanb6v.png" height="150">
  </a>
  <p  align="center">Minizo is a sleek web app lets users effortlessly <br> browse and manage their music collection and obtain tracks.</p>
</p>

<p align="center">
  <a href="https://www.buymeacoffee.com/mattiasghodsian" target="_new">
    <img src="https://img.shields.io/badge/Donate-Coffee-blue?style=for-the-badge&amp;logo=buymeacoffee" alt="Donate Coffee">
  </a>
  <a href="https://github.com/mattiasghodsian/Minizo/stargazers" target="_new">
    <img alt="GitHub Repo stars" src="https://img.shields.io/github/stars/mattiasghodsian/Minizo?style=for-the-badge&logo=github&label=Stars&color=blue">
  </a>
  <a href="https://github.com/mattiasghodsian/Minizo/network/members" target="_new">
    <img alt="GitHub forks" src="https://img.shields.io/github/forks/mattiasghodsian/Minizo?style=for-the-badge&logo=github&label=Forks&color=blue">
  </a>
  <br>
  <a href="https://github.com/mattiasghodsian/Minizo/actions" target="_new">
    <img alt="Build" src="https://img.shields.io/github/actions/workflow/status/mattiasghodsian/Minizo/docker-build-master.yml?style=for-the-badge&logo=github&label=Build&color=blue">
  </a>
  <a href="https://github.com/mattiasghodsian/Minizo/actions" target="_new">
    <img alt="Nightly" src="https://img.shields.io/github/actions/workflow/status/mattiasghodsian/Minizo/docker-build-nightly.yml?style=for-the-badge&logo=github&label=Nightly&color=blue">
  </a>
  <br>
  <a href="https://github.com/mattiasghodsian/Minizo/releases/latest" target="_new">
    <img alt="Latest Release" src="https://img.shields.io/github/v/release/mattiasghodsian/Minizo?style=for-the-badge&logo=github&label=Latest%20Release&color=blue">
  </a>
  <a href="https://hub.docker.com/r/rakma/minizo" target="_new">
    <img alt="Docker Pulls" src="https://img.shields.io/docker/pulls/rakma/minizo?style=for-the-badge&logo=docker&label=Pulls&color=blue">
  </a>
</p>

> Downloading copyrighted content without proper authorization is illegal in most countries and not endorsed. This project is intended for educational purposes only. Please ensure you have the right to download and use the content.

# FEATURES

Minizo is a self-hosted music library, backed by a folder of audio files on your own disk.
The filesystem stays the source of truth — there is no track database to fall out of sync
with what you actually own.

- **Library.** Browse, search and sort your folders with embedded cover art; move, rename, delete and download files.
- **Download.** Queue a URL and watch it land as a FLAC in the folder you picked, via yt-dlp and ffmpeg.
- **Metadata.** Search MusicBrainz, pick the release and track, and write tags and cover art into the file.
- **Feed.** Follow artists and see their new releases, refreshed hourly from TIDAL.
- **Share links.** Publish an expiring public link to a track or folder, with a kill switch and an audit trail.
- **Users & permissions.** Roles, per-folder access, and six permissions — edit, move, download, delete, downloader, share.
- **Accounts.** Password login, reset and verification, TOTP two-factor with recovery codes, and passkeys.

## PROJECT BACKGROUND
This project began as a solution to access and manage my audio files beyond my local network, but it grew into something else over time. And now, Minizo is open for everyone to enjoy.

Minizo was rebuilt from the ground up on **Livewire**: the Inertia + Vue 3 frontend is gone,
replaced by Livewire 4 single-file components and Flux UI, and the backend was reorganised
around services, enums, policies and queued jobs. The rebuild ships with static analysis
(PHPStan/Larastan), a formatter (Pint) and a feature test suite.

## BUILT WITH 
- [Laravel 13](https://laravel.com/) on PHP 8.3+
- [Livewire 4](https://livewire.laravel.com/) + [Flux UI](https://fluxui.dev/)
- [Tailwind CSS 4](https://tailwindcss.com/)
- [yt-dlp](https://github.com/yt-dlp/yt-dlp) + [ffmpeg](https://ffmpeg.org/) + [flac](https://xiph.org/flac/)
- [MusicBrainz](https://musicbrainz.org/) & [Cover Art Archive](https://coverartarchive.org/) for metadata
- [TIDAL API](https://developer.tidal.com/) for the artist feed

## DOCKER
***Nightly releases** come with the latest source but are unstable and not recommended for production use.*

The container generates its own `APP_KEY` and runs migrations on first boot, and supervises
the web server, two queue workers, two dedicated download workers and the scheduler — so
there is nothing to run by hand. `yt-dlp`, `ffmpeg` and `metaflac` are baked into the image.

```yml
services:
  app:
    image: 'rakma/minizo:nightly'
    container_name: minizo
    restart: unless-stopped
    ports:
      - '3010:80'
    env_file:
      - .env
    environment:
      DB_HOST: mysql
    volumes:
      - minizo_storage:/var/www/html/storage
      - .env:/var/www/html/.env
      - /music-collection:/var/www/html/storage/app/private/music:rw
    depends_on:
      - mysql
    networks:
      - minizo

  mysql:
    image: mariadb:11
    container_name: minizo_db
    restart: unless-stopped
    env_file:
      - .env
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - minizo_mariadb:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 5
    ports:
      - "${DB_PORT:-3306}:3306"
    networks:
      - minizo

networks:
  minizo:
    driver: bridge

volumes:
  minizo_mariadb:
    driver: local
  minizo_storage:
    driver: local
```

It does **not** seed any accounts, so create the first administrator yourself once the stack
is up — then sign in and manage everyone else from the Users screen:

```terminal
docker exec -it -u sail minizo php artisan minizo:make-admin you@example.com --name="Your Name"
```

If something is not working, `minizo:doctor` reports on the binaries, the library disk and
the configured integrations:

```terminal
docker exec -it -u sail minizo php artisan minizo:doctor
```

If you encounter permission issues, you can run these commands on your host machine in the music directory:
```terminal
sudo chown -R $(whoami):$(whoami) .
sudo find . -type d -exec chmod 775 {} \;
sudo find . -type f -exec chmod 664 {} \;
sudo find . -type d -exec chmod g+s {} \;
```

## CONFIGURATION
Copy `.env.example` to `.env` — it documents every Minizo-specific key inline. The ones worth
knowing about:

| Key | What it does |
| --- | --- |
| `TIDAL_CLIENT_ID` / `TIDAL_CLIENT_SECRET` | Powers the Feed. Without them the Feed screen explains itself and the rest of the app is unaffected. Register at [developer.tidal.com](https://developer.tidal.com). |
| `TIDAL_COUNTRY` | Required by every catalogue call, and decides which releases are visible — availability is licensed per territory. |
| `MUSICBRAINZ_TOKEN` | Optional; only raises the rate limit. Minizo holds itself to one request per second either way. |
| `MUSICBRAINZ_USER_AGENT` | MusicBrainz answers a request without one with a 503 that looks like rate limiting. Change the URL if you run a fork. |
| `TRUSTED_PROXIES` | Set this when Minizo sits behind a reverse proxy. Until you do, Laravel sees the proxy as the client: every visitor shares one rate-limit bucket, URLs come out `http://` behind TLS, and passkey login fails on an origin mismatch. |
| `SESSION_SECURE_COOKIE` | Set to `true` when you serve over HTTPS. Left `false` by default so a plain-HTTP LAN install can log in at all. |
| `APP_REGISTER` / `APP_FORGOTPASS` | Turn public registration and password reset on or off. |
| `MINIZO_SHARING_ENABLED` | Whether public sharing starts on for a **fresh** install. Once an admin flips the toggle on the Users screen, the stored value wins. |

Everything else — cache TTLs, download retries and stall timeout, FLAC compression level,
rate limits, share retention, feed sync batching — lives in [config/minizo.php](config/minizo.php),
where each option is documented next to the reasoning for its default.

## COMMANDS
| Command | Purpose |
| --- | --- |
| `minizo:doctor` | Check the binaries, disks and integrations Minizo depends on. Run this first when something is not working. |
| `minizo:make-admin {email}` | Create an administrator, or promote an existing user to one. |
| `minizo:library:audit [--prune]` | Find database references to library folders that no longer exist. |
| `minizo:mail:test [email]` | Send a test email and report what the transport did. |
| `minizo:tidal:probe` | Fetch a real TIDAL response and show how Minizo maps it. |
| `minizo:downloads:reap` | Fail downloads whose progress has gone stale, and prune old history. *(scheduled every 5 min)* |
| `minizo:feed:sync [--all]` | Queue release refreshes for followed artists. *(scheduled hourly)* |

## SCREENSHOTS
| [![](https://i.imgur.com/mmiT6lI.png)](https://i.imgur.com/mmiT6lI.png) | [![](https://i.imgur.com/wcmWtAn.png)](https://i.imgur.com/wcmWtAn.png) | [![](https://i.imgur.com/NJbSzXM.png)](https://i.imgur.com/NJbSzXM.png) |
| :---: | :---: | :---: |
| [![](https://i.imgur.com/CqIIV0W.png)](https://i.imgur.com/CqIIV0W.png) | [![](https://i.imgur.com/51bYsAc.png)](https://i.imgur.com/51bYsAc.png) | [![](https://i.imgur.com/I1Sl2Zh.png)](https://i.imgur.com/I1Sl2Zh.png) |
| [![](https://i.imgur.com/q8d6Yf1.png)](https://i.imgur.com/q8d6Yf1.png) | [![](https://i.imgur.com/IVy7fDH.png)](https://i.imgur.com/IVy7fDH.png) | [![](https://i.imgur.com/jU6WIXu.png)](https://i.imgur.com/jU6WIXu.png) |

## CONTRIBUTING
We welcome contributions from the community! Whether you're fixing a bug, adding a new feature, or improving documentation, your help is greatly appreciated.