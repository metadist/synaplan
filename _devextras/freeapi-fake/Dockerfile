FROM node:24-alpine@sha256:44fce696f38ba8c987c4db89954051000bbd05ffd8d8659ba15ed4a8602e937f

WORKDIR /app
COPY server.mjs ./

ENV PORT=8787 \
    CONTRACT_DIR=/contract

EXPOSE 8787
USER node
CMD ["node","server.mjs"]
