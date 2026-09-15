FROM php:8.2-cli

# Install library sistem yang dibutuhkan buat compile ekstensi curl
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Aktifkan ekstensi curl (dipakai buat manggil Gemini API)
RUN docker-php-ext-install curl

WORKDIR /app
COPY . /app

# Render otomatis kasih env var PORT, PHP built-in server harus bind ke situ
ENV PORT=10000
EXPOSE 10000

CMD php -S 0.0.0.0:$PORT -t /app
