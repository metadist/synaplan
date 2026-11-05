FROM python:3.12-slim

# linux amd64 only. The release tarball also contains sample private keys.
# Extract the binary and nothing else.
ARG C2PATOOL_VERSION=0.28.4
ARG C2PATOOL_SHA256=097e9efaff765baf7738a40c640012419cad419ce3e1aa36cf68e0576309c971

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl openssl \
    && arch="$(dpkg --print-architecture)" \
    && if [ "$arch" = "amd64" ]; then \
         curl -fsSL -o /tmp/c2patool.tar.gz \
           "https://github.com/contentauth/c2patool/releases/download/v${C2PATOOL_VERSION}/v${C2PATOOL_VERSION}-x86_64-unknown-linux-gnu.tar.gz" \
         && echo "${C2PATOOL_SHA256}  /tmp/c2patool.tar.gz" | sha256sum -c - \
         && mkdir -p /tmp/c2pa-src \
         && tar -xzf /tmp/c2patool.tar.gz -C /tmp/c2pa-src c2patool/c2patool \
         && install -m 0755 /tmp/c2pa-src/c2patool/c2patool /usr/local/bin/c2patool \
         && rm -rf /tmp/c2pa-src /tmp/c2patool.tar.gz; \
       fi \
    && apt-get purge -y curl \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system --gid 65532 c2pa \
    && useradd --system --uid 65532 --gid 65532 --create-home --shell /usr/sbin/nologin c2pa \
    && mkdir -p /certs \
    && chown 65532:65532 /certs

WORKDIR /app
COPY server.py /app/server.py
ENV C2PA_SIGNER_ADDR=:8787
ENV C2PA_ALG=ps256
EXPOSE 8787
VOLUME ["/certs"]
USER 65532:65532
CMD ["python", "-u", "/app/server.py"]
