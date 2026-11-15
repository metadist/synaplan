FROM node:26-alpine@sha256:6bd9ab43c0ec0190ca7b2d193906cb8b7edf6e7f4afa43ddeccd6452710bffb5

WORKDIR /app
COPY server.mjs ./

ENV PORT=8787 \
    CONTRACT_DIR=/contract

EXPOSE 8787
USER node
CMD ["node","server.mjs"]
