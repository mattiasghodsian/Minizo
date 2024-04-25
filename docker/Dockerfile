FROM node:18

LABEL maintainer="mattiasghodsian"
LABEL description="Docker image for Minizo"
LABEL org.opencontainers.image.url="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.source="https://github.com/mattiasghodsian/Minizo"
LABEL org.opencontainers.image.description="Docker image for Minizo"
LABEL org.opencontainers.image.licenses="GPL-3.0"

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
EXPOSE 3000

CMD ["npm", "run", "serve"]