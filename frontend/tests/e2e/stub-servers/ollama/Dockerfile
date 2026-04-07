FROM node:22-alpine
WORKDIR /app
COPY ollama-stub-server.ts .
ENV PORT=11434
EXPOSE 11434
CMD ["node", "ollama-stub-server.ts"]
