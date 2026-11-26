FROM node:26-bookworm@sha256:893ea48106e988a5f91e69629d6472600daa9d0494adf7db87eb0b886ce82255
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
# mod_synascriber.lua for Jitsi's Prosody: an init container copies it from this image (synaplan-charts)
COPY prosody ./prosody
USER node
EXPOSE 8095
CMD ["node", "src/index.js"]
