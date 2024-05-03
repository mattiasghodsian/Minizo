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
    apt-get install -y git make python3 python3-pip python3-venv zip pandoc ffmpeg && \
    apt-get clean

# Create a symlink to specify the Python interpreter
RUN ln -s /usr/bin/python3 /usr/bin/python

# Clone & Compile youtube-dl
RUN git clone https://github.com/ytdl-org/youtube-dl.git /tmp/youtube-dl
RUN cd /tmp/youtube-dl && python setup.py install && make
RUN cp /tmp/youtube-dl/bin/youtube-dl /usr/bin/youtube-dl
RUN chmod a+rx /usr/bin/youtube-dl
RUN youtube-dl --version

# Create a virtual environment
RUN python3 -m venv /venv
ENV VENV=/venv
RUN /venv/bin/pip install --upgrade pip

# Install & Config beets & plugins
ENV PATH="/venv/bin:$PATH"
RUN /venv/bin/pip install beets requests pylast

# Expose port & set WORKDIR
WORKDIR /srv
EXPOSE 3000

# Create/Copy/Permissions
COPY beet.config.yaml /root/.config/beets/config.yaml
COPY scripts /scripts
RUN chmod -R +x /scripts
RUN mkdir /music
RUN chown -R $(id -u):$(id -g) /music

CMD ["npm", "run", "serve"]