FROM node:26-alpine@sha256:40cd011b55415bc894e45b02fdd5a5dfde056921b7d23a64c74f80497af48797

WORKDIR /app
COPY server.mjs ./

ENV PORT=8787 \
    CONTRACT_DIR=/contract

EXPOSE 8787
USER node
CMD ["node","server.mjs"]
