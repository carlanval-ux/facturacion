FROM node:24-alpine
# openssl: el servicio inspecciona los PKCS#12 con la CLI (sin dependencias npm)
RUN apk add --no-cache openssl && mkdir -p /data && chown node:node /data
WORKDIR /app
COPY package.json ./
COPY src ./src
USER node
ENV HOST=0.0.0.0 PORT=8787 DB_PATH=/data/verifactu.sqlite
EXPOSE 8787
CMD ["node", "src/server.js"]
