FROM node:26-alpine@sha256:40cd011b55415bc894e45b02fdd5a5dfde056921b7d23a64c74f80497af48797
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
# mod_synascriber.lua for Jitsi's Prosody: an init container copies it from this image (synaplan-charts)
COPY prosody ./prosody
USER node
EXPOSE 8095
CMD ["node", "src/index.js"]
