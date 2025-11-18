# Synaplan Vue.js Frontend - Development
FROM node:22-alpine

WORKDIR /app

# Install dependencies for building
RUN apk add --no-cache bash curl

# Copy package files
COPY frontend/package*.json ./

# Install dependencies
RUN npm ci

# Copy application
COPY frontend/ ./

# Copy and setup entrypoint scripts
COPY _docker/frontend/docker-entrypoint.sh /usr/local/bin/
COPY _docker/frontend/init-env.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/init-env.sh

# Expose Vite dev server port
EXPOSE 5173

# Start with entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]

