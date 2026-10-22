# ---------- base ----------
FROM node:22-alpine AS base
WORKDIR /app
RUN chown node:node /app

# ---------- dev (used by docker-compose.yml) ----------
FROM base AS dev
# Pre-create node_modules owned by "node" so the named volume inherits correct ownership
RUN mkdir -p /app/node_modules && chown -R node:node /app
USER node
EXPOSE 5173
CMD ["sh", "-c", "npm install && exec npm run dev -- --host 0.0.0.0 --port 5173"]

# ---------- build ----------
FROM base AS build
ARG VITE_API_URL
ENV VITE_API_URL=${VITE_API_URL}
COPY package.json package-lock.json* ./
RUN npm ci || npm install
COPY . .
RUN npm run build

# ---------- prod (static files served by nginx) ----------
FROM nginx:1.27-alpine AS prod
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80