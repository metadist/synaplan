FROM python:3.15-slim-bookworm@sha256:96599df822d0e1c6bb700014a05c7f3c8cabc6e0f7ff3c4f9f69cee033b4b5d9
# Production pins this image by digest in internal/images/map.go (A3).
RUN sed -i 's|^mozilla/emSign_|!mozilla/emSign_|' /etc/ca-certificates.conf \
    && update-ca-certificates \
    && (useradd --uid 65534 --gid 65534 --no-create-home --shell /usr/sbin/nologin nobody || true)
RUN pip install --no-cache-dir \
    pandas numpy matplotlib openpyxl python-docx python-pptx pypdf pillow
WORKDIR /work
USER 65534:65534
# No package-manager network at run time; the T1 HostConfig has NetworkMode none.
