FROM node:20-alpine
WORKDIR /app
COPY whatsapp-stub-server.mjs .
ENV PORT=3999 STUB_HOST=whatsapp-stub
EXPOSE 3999
CMD ["node", "whatsapp-stub-server.mjs"]
