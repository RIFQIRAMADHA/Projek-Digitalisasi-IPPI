FROM php:8.2-fpm

# 1. Instal dependensi sistem & ekstensi PHP yang dibutuhkan Laravel
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl

# 2. Instal ekstensi pdo_mysql (database) dan gd (gambar/grafik dashboard)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip

# 3. Tentukan direktori kerja di dalam kontainer
WORKDIR /var/www

# 4. Salin semua file project Bapak ke dalam kontainer
COPY . .

# 5. Ambil binary Composer terbaru
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. MODIFIKASI DI SINI: Tambah flag ignore platform agar tidak error exit code 2
RUN composer install --no-interaction --no-dev --optimize-autoloader --ignore-platform-reqs

# 7. Atur izin folder agar Laravel bisa nulis log dan cache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 8080

CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
