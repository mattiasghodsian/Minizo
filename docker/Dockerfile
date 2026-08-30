FROM ubuntu:24.04

LABEL maintainer="Mattias Ghodsian"
LABEL description="Development Docker image for Minizo"
LABEL org.opencontainers.image.url="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.source="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.description="Development Docker image for Minizo"
LABEL org.opencontainers.image.licenses="GPL-3.0"

ARG WWWGROUP
ARG NODE_VERSION=24
ARG MYSQL_CLIENT="mariadb-client"
ARG YTDLP_VERSION=2026.07.04

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=CET
ENV LANG=C.UTF-8
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80"
ENV SUPERVISOR_PHP_USER="sail"

# Seed a brand new database on first boot. Dev only — DatabaseSeeder creates
# minizo@minizo.com with the password "minizo", which must never be created
# automatically in production. See docker/start-container.
ENV MINIZO_AUTO_SEED="true"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN echo "Acquire::http::Pipeline-Depth 0;" > /etc/apt/apt.conf.d/99custom && \
    echo "Acquire::http::No-Cache true;" >> /etc/apt/apt.conf.d/99custom && \
    echo "Acquire::BrokenProxy    true;" >> /etc/apt/apt.conf.d/99custom

# System packages.
#
# The media tooling is exactly two things: **ffmpeg**, which yt-dlp shells out to for the
# FLAC extraction, and **flac**, which supplies `metaflac` — the only thing that writes tags.
#
# Deliberately absent, all carried over from the legacy image and none of them reachable:
#
#   atomicparsley   the legacy m4a tagging branch, which was unreachable code even there
#   python3-pip     installed solely to `pip install eyeD3`, the legacy mp3 tagger
#   eyeD3           FLAC-only means there is no mp3 branch left to tag
#   librsvg2-bin    rsvg-convert; nothing rasterises SVG — Fortify's 2FA QR code uses
#                   BaconQrCode's SvgImageBackEnd, which is pure PHP
#   php8.4-imagick  no image manipulation anywhere; covers are read and served as bytes
#
# python3 is no longer installed explicitly but is still in the image: `supervisor` declares
# `Depends: python3:any, python3-pkg-resources`, and supervisor runs every process here.
# Removing the explicit install is still worth it — it is what drops pip and eyeD3 with it.
#
# Nothing in Minizo calls python: the yt-dlp_linux asset fetched below is a 39 MB
# self-contained PyInstaller bundle with its own interpreter embedded.
RUN apt-get update && apt-get upgrade -y \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y \
        gnupg \
        gosu \
        curl \
        wget \
        ca-certificates \
        zip \
        unzip \
        git \
        nano \
        supervisor \
        sqlite3 \
        libcap2-bin \
        libpng-dev \
        dnsutils \
        fswatch \
        ffmpeg \
        flac \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y \
        libgd3 \
        php8.4-cli \
        php8.4-dev \
        php8.4-bcmath \
        php8.4-curl \
        php8.4-gd \
        php8.4-igbinary \
        php8.4-intl \
        php8.4-mbstring \
        php8.4-msgpack \
        php8.4-mysql \
        php8.4-readline \
        php8.4-redis \
        php8.4-sqlite3 \
        php8.4-xml \
        php8.4-zip \
        php8.4-pcov \
        php8.4-xdebug \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && apt-get install -y $MYSQL_CLIENT \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN wget -O /usr/bin/yt-dlp "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" \
    && chmod a+rx /usr/bin/yt-dlp

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.4

RUN userdel -r ubuntu
RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail
RUN git config --global --add safe.directory /var/www/html

COPY start-container /usr/local/bin/start-container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php.ini /etc/php/8.4/cli/conf.d/99-sail.ini
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]
