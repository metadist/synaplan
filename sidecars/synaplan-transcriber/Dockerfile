FROM node:26-alpine@sha256:c6ad9e9c1d80f6eb607c26b4b0a132ff7a2344fad1c78ea3baa2a7d4c8de6d94
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
# mod_synascriber.lua for Jitsi's Prosody: an init container copies it from this image (synaplan-charts)
COPY prosody ./prosody
USER node
EXPOSE 8095
CMD ["node", "src/index.js"]
