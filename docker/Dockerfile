FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor redis-server redis-tools curl unzip git libzip-dev libicu-dev libonig-dev libxml2-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j2 pdo_mysql mysqli mbstring bcmath pcntl sockets intl zip gd && pecl install redis && docker-php-ext-enable redis && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /www/wwwroot/StarCore
COPY . /www/wwwroot/StarCore
RUN cd app && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader && mkdir -p /www/wwwroot/StarCore/data /www/wwwroot/StarCore/projects /www/wwwroot/StarCore/logs /www/wwwroot/StarCore/var /www/wwwroot/StarCore/app/runtime /run/redis && chown -R www-data:www-data /www/wwwroot/StarCore
COPY docker/starcore.conf /etc/nginx/conf.d/starcore.conf
RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/redis.conf /etc/redis/redis.conf
RUN chown root:www-data /etc/redis /etc/redis/redis.conf && chmod 750 /etc/redis && chmod 640 /etc/redis/redis.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/starcore-entrypoint docker/starcore-bootstrap docker/starcore-daemon /usr/local/bin/
RUN chmod +x /usr/local/bin/starcore-entrypoint /usr/local/bin/starcore-bootstrap /usr/local/bin/starcore-daemon
EXPOSE 18081 9000
ENTRYPOINT ["/usr/local/bin/starcore-entrypoint"]
