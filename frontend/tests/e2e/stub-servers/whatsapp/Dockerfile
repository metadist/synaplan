FROM node:22-alpine
WORKDIR /app
COPY whatsapp-stub-server.ts .
ENV PORT=3999 STUB_HOST=whatsapp-stub
EXPOSE 3999
CMD ["node", "whatsapp-stub-server.ts"]
