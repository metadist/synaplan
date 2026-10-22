FROM node:22-alpine@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
# mod_synascriber.lua for Jitsi's Prosody: an init container copies it from this image (synaplan-charts)
COPY prosody ./prosody
USER node
EXPOSE 8095
CMD ["node", "src/index.js"]
