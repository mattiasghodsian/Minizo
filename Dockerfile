FROM node:18

LABEL maintainer="mattiasghodsian"
LABEL version="0.1.0"
LABEL description="Docker image for Minizo"
LABEL org.opencontainers.image.url="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.source="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.description="Docker image for Minizo"
LABEL org.opencontainers.image.licenses="GPL-3.0"

# Update and install needed packages
RUN apt-get update && \
    apt-get install -y git make python3 zip pandoc ffmpeg && \
    apt-get clean

# Create a symlink to specify the Python interpreter
RUN ln -s /usr/bin/python3 /usr/bin/python

# Clone & Compile youtube-dl
RUN git clone https://github.com/ytdl-org/youtube-dl.git /tmp/youtube-dl
RUN cd /tmp/youtube-dl && python setup.py install && make
RUN cp /tmp/youtube-dl/bin/youtube-dl /usr/bin/youtube-dl
RUN chmod a+rx /usr/bin/youtube-dl
RUN youtube-dl --version

# Expose port & set WORKDIR
WORKDIR /srv
# COPY . .
EXPOSE 3000

# Create music folder and set permissions
RUN mkdir /music
RUN chown -R $(id -u):$(id -g) /music

CMD ["npm", "run", "serve"]