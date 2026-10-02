FROM it-requests-browser:local
USER root
RUN apt-get update && apt-get install -y --no-install-recommends openssl libnss3-tools \
    && rm -rf /var/lib/apt/lists/*
COPY --chown=10001:10001 docker/verification /verification
ENV HOME=/tmp/browser-home
USER 10001:10001
CMD ["node", "/verification/browser.mjs"]
