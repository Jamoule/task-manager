# Étape 1 : builder
FROM composer:2 AS builder

WORKDIR /app

# Copier les fichiers nécessaires pour Composer et le code source
COPY composer.json composer.lock ./
COPY . .

# Installer les dépendances en utilisant l'environnement de production pour les scripts
RUN APP_ENV=prod composer install --no-dev --optimize-autoloader

# Étape 2 : image finale
FROM php:8.2-fpm-alpine

# Installer les extensions PHP requises et pcov pour la couverture de code
RUN apk add --no-cache $PHPIZE_DEPS \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && docker-php-ext-install \
    intl \
    pdo \
    pdo_pgsql \
    pgsql \
    opcache \
    mbstring \
    xml \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# Copier l’app optimisée
WORKDIR /app
COPY --from=builder /app /app

# Droits d’écriture pour var et cache
RUN chown -R www-data:www-data var

# Exposer le port FPM
EXPOSE 9000

CMD ["php-fpm"]
