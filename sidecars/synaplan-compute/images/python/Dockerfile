FROM python:3.12-slim-bookworm
# Production pins this image by digest in internal/images/map.go (A3).
RUN useradd --uid 65534 --gid 65534 --no-create-home --shell /usr/sbin/nologin nobody || true
RUN pip install --no-cache-dir \
    pandas numpy matplotlib openpyxl python-docx python-pptx pypdf pillow
WORKDIR /work
USER 65534:65534
# No package-manager network at run time; the T1 HostConfig has NetworkMode none.
