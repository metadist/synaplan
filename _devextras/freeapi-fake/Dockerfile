FROM node:26-alpine@sha256:c6ad9e9c1d80f6eb607c26b4b0a132ff7a2344fad1c78ea3baa2a7d4c8de6d94

WORKDIR /app
COPY server.mjs ./

ENV PORT=8787 \
    CONTRACT_DIR=/contract

EXPOSE 8787
USER node
CMD ["node","server.mjs"]
