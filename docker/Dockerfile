FROM node:18

LABEL maintainer="mattiasghodsian"
LABEL version="0.2.0"
LABEL description="Docker image for Minizo"
LABEL org.opencontainers.image.url="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.source="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.description="Docker image for Minizo"
LABEL org.opencontainers.image.licenses="GPL-3.0"

ARG YTDLP_VERSION=2025.01.15

# Update and install needed packages
RUN apt-get update && \
    apt-get install -y git make python3 python3-pip python3-venv zip pandoc ffmpeg && \
    apt-get clean

# Create a symlink to specify the Python interpreter
RUN ln -s /usr/bin/python3 /usr/bin/python

# Install yt-dlp
RUN wget -O /usr/bin/yt-dlp "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" && \
    chmod a+rx /usr/bin/yt-dlp

# Create a virtual environment
RUN python3 -m venv /venv
ENV VENV=/venv
RUN /venv/bin/pip install --upgrade pip

# Install & Config beets & plugins
ENV PATH="/venv/bin:$PATH"
RUN /venv/bin/pip install beets requests pylast

# Create/Copy/Permissions
COPY beet.config.yaml /root/.config/beets/config.yaml
RUN mkdir /music
RUN chown -R $(id -u):$(id -g) /music

# Create srv directory and set user
RUN mkdir -p /srv && \
chown -R node:node /srv

# Change user
USER node

# Clone Minizo and install packages
RUN git clone https://github.com/mattiasghodsian/Minizo.git /srv
# RUN chown -R $(id -u):$(id -g) /srv
RUN cd /srv && npm install && cp .env.sample .env && npm run build-only

# Last actions
WORKDIR /srv
EXPOSE 3000

CMD ["npm", "run", "serve"]
