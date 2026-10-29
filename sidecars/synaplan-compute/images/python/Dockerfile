FROM python:3.15-slim-bookworm@sha256:4e03260adeeebafccd084f3584e6b84823aba5651cfb53ef8a078ed441ddf9cd
# Production pins this image by digest in internal/images/map.go (A3).
RUN sed -i 's|^mozilla/emSign_|!mozilla/emSign_|' /etc/ca-certificates.conf \
    && update-ca-certificates \
    && (useradd --uid 65534 --gid 65534 --no-create-home --shell /usr/sbin/nologin nobody || true)
RUN pip install --no-cache-dir \
    pandas numpy matplotlib openpyxl python-docx python-pptx pypdf pillow
WORKDIR /work
USER 65534:65534
# No package-manager network at run time; the T1 HostConfig has NetworkMode none.
