FROM bellamy/wallos:5.11.1@sha256:182d4be4dae26834cc5cec3686bb142e78b0a139a0eb1059355d061d8c0de3e5
ARG REVISION
LABEL org.opencontainers.image.source="https://github.com/qiingshu/Wallos" org.opencontainers.image.revision=$REVISION org.opencontainers.image.version="5.11.1-qiingshu.1"
COPY --chown=82:82 *.php /var/www/html/
COPY --chown=82:82 includes/ /var/www/html/includes/
COPY --chown=82:82 endpoints/ /var/www/html/endpoints/
COPY --chown=82:82 scripts/ /var/www/html/scripts/
COPY --chown=82:82 styles/ /var/www/html/styles/
COPY --chown=82:82 api/ /var/www/html/api/
