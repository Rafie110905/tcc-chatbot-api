FROM php:8.2-cli

# Aktifkan ekstensi curl (dipakai buat manggil Gemini API)
RUN docker-php-ext-install curl

WORKDIR /app
COPY . /app

# Render otomatis kasih env var PORT, PHP built-in server harus bind ke situ
ENV PORT=10000
EXPOSE 10000

CMD php -S 0.0.0.0:$PORT -t /app
