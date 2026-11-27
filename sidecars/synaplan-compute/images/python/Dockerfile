FROM python:3.15-slim-bookworm@sha256:498829c717b1e59fc42f0e7865eafe4e04ca0f70a3769d4ae70ba218df54507c
# Production pins this image by digest in internal/images/map.go (A3).
RUN sed -i 's|^mozilla/emSign_|!mozilla/emSign_|' /etc/ca-certificates.conf \
    && update-ca-certificates \
    && (useradd --uid 65534 --gid 65534 --no-create-home --shell /usr/sbin/nologin nobody || true)
RUN pip install --no-cache-dir \
    pandas numpy matplotlib openpyxl python-docx python-pptx pypdf pillow
WORKDIR /work
USER 65534:65534
# No package-manager network at run time; the T1 HostConfig has NetworkMode none.
