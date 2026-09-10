FROM node:22-bookworm-slim
# Production pins this image by digest in internal/images/map.go (A3).
RUN useradd --uid 65534 --gid 65534 --no-create-home --shell /usr/sbin/nologin nobody || true
WORKDIR /work
RUN npm install --omit=dev xlsx docx pdf-lib sharp \
 && chown -R 65534:65534 /work
USER 65534:65534
