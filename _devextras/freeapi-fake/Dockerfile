FROM node:26-alpine@sha256:8996bb5821d62a8fd9bb27c1cb07462f3fdce6e47d640c6a881ead0b064ce216

WORKDIR /app
COPY server.mjs ./

ENV PORT=8787 \
    CONTRACT_DIR=/contract

EXPOSE 8787
USER node
CMD ["node","server.mjs"]
