FROM node:26-alpine@sha256:6bd9ab43c0ec0190ca7b2d193906cb8b7edf6e7f4afa43ddeccd6452710bffb5
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
# mod_synascriber.lua for Jitsi's Prosody: an init container copies it from this image (synaplan-charts)
COPY prosody ./prosody
USER node
EXPOSE 8095
CMD ["node", "src/index.js"]
