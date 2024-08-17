<p align="center">
  <a href="https://github.com/mattiasghodsian/Minizo/">
    <img alt="Iroh" src="https://imgur.com/25ISXaS.png" height="150">
  </a>
  <p  align="center">Minizo is a sleek web app built with Node.js, Express.js, and Vue.js. <br> Minizo lets users effortlessly browse and manage their music collection and obtain tracks.</p>
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

- **Browsing by folder:** Dive into folders to view the files stored within them.
- **File Management:** Easily delete or relocate files within your collection.
- **Fast search:** YouTube Music fast search.
- **Grab video:** Capture videos from various sources and effortlessly convert them into audio files.
- **Authentication:** Enable optional basic authentication for the project.
- **MusicBrainz:** Retrieve metadata from MusicBrainz (token required).
- and much more.

## PROJECT BACKGROUND
This project began as a solution to access and manage my audio files beyond my local network, but it grew into something else over time. And now, Minizo is open for everyone to enjoy.

## REQUIREMENTS
- [Node.js](https://nodejs.org/en)
- [yt-dlp](https://github.com/yt-dlp/yt-dlp)
- [ffmpeg](https://ffmpeg.org/)
- [beets](https://github.com/beetbox/beets)

## HOW TO
- Clone down the project `git clone git@github.com:mattiasghodsian/Minizo.git`
- Install all dependencies `npm install` and create a new .env file `cp .env.example .env` and fill in all fields
- Run the project with `npm run serve` and visit `http://localhost:3000`

## DOCKER
***Nightly releases** come with the latest source but are unstable and not recommended for production use.*
```yml
version: "3"
services:
  minizo:
    image: rakma/minizo:latest
    ports:
      - 3000:3000
    volumes:
      - .env:/srv/.env
      - /home/user/music:/music:rw
    user: 1000:1000
    restart: always

networks:
  default:
    name: minizo
    external: true
```

## SCREENSHOTS
| [![](https://imgur.com/3aVswPa.png)](https://imgur.com/3aVswPa.png) | [![](https://imgur.com/7YsyUZb.png)](https://imgur.com/7YsyUZb.png) | [![](https://imgur.com/1P8DRpg.png)](https://imgur.com/1P8DRpg.png) | [![](https://imgur.com/L0xoRWb.png)](https://imgur.com/L0xoRWb.png) |
| :-----------------------------------------------------------------: | :-----------------------------------------------------------------: | :-----------------------------------------------------------------: | :-----------------------------------------------------------------: |

## CONTRIBUTING
We welcome contributions from the community! Whether you're fixing a bug, adding a new feature, or improving documentation, your help is greatly appreciated.