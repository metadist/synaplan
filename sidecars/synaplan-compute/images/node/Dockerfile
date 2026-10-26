FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205
# Production pins this image by digest in internal/images/map.go (A3).
RUN useradd --uid 65534 --gid 65534 --no-create-home --shell /usr/sbin/nologin nobody || true
# Libraries live outside /work: the run bind-mounts /work over whatever the
# image put there, so anything installed into it would vanish at run time.
WORKDIR /opt
RUN npm install --omit=dev --prefix /opt xlsx docx pdf-lib sharp \
 && chown -R root:root /opt/node_modules \
 && chmod -R a+rX /opt/node_modules
ENV NODE_PATH=/opt/node_modules
RUN mkdir -p /work /out && chown 65534:65534 /work /out
WORKDIR /work
USER 65534:65534
# No package-manager network at run time; the T1 HostConfig has NetworkMode none.
