<p align="center">
  <a href="https://github.com/mattiasghodsian/Minizo/">
    <img alt="Iroh" src="https://imgur.com/25ISXaS.png" height="150">
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

- **File Management:** A fully working file manager.
- **Fast search:** YouTube Music fast search.
- **Download:** Download videos from various sources and effortlessly convert to audio files.
- **Meta data:** Retrieve metadata from MusicBrainz and write to file.
- and much more.

## PROJECT BACKGROUND
This project began as a solution to access and manage my audio files beyond my local network, but it grew into something else over time. And now, Minizo is open for everyone to enjoy.

## BUILT WITH 
- [Laravel](https://laravel.com/)
- [Tailwind](https://tailwindcss.com/)
- [Vue.js](https://vuejs.org/)
- [yt-dlp](https://github.com/yt-dlp/yt-dlp)
- [ffmpeg](https://ffmpeg.org/)

## DOCKER
***Nightly releases** come with the latest source but are unstable and not recommended for production use.*

Don't forget to run `php artisan migrate` & `php artisan db:seed` inside the container.

```yml
version: '3'

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
    image: mariadb:10.11
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

If you encounter permission issues, you can run these commands on your host machine in the music directory:
```terminal
sudo chown -R $(whoami):$(whoami) .
sudo find . -type d -exec chmod 775 {} \;
sudo find . -type f -exec chmod 664 {} \;
sudo find . -type d -exec chmod g+s {} \;
```

## SCREENSHOTS
| [![](https://i.imgur.com/AdYjfaX.png)](https://i.imgur.com/AdYjfaX.png) | [![](https://i.imgur.com/6qe45Th.png)](https://i.imgur.com/6qe45Th.png) | [![](https://i.imgur.com/YgpmizI.png)](https://i.imgur.com/YgpmizI.png)
| :-----------------------------------------------------------------: | :-----------------------------------------------------------------: | :-----------------------------------------------------------------: | 

## CONTRIBUTING
We welcome contributions from the community! Whether you're fixing a bug, adding a new feature, or improving documentation, your help is greatly appreciated.