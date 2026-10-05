# Data Speak frontend - Vite dev server (local development image)
FROM node:24-alpine
WORKDIR /app
ENV LANG=C.UTF-8 LC_ALL=C.UTF-8
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]
