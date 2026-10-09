FROM node:24-bookworm-slim@sha256:d6aa754f16b3197301076f047b5def2f02ea1dbbc2ca920407d46d7ec7f87b20
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
