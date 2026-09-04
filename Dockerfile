# ============================================================
# Capstone Mobile Quiz System — Render deployment image
# Base: official PHP 8.2 + Apache image (Render runs PHP via Docker)
# ============================================================
FROM php:8.2-apache

# --- Required PHP extensions (verified against local XAMPP 8.2.12) ---
# pdo_mysql : PDO MySQL driver (all queries)
# mbstring  : multibyte string functions used by exam grading
#           : (mb_strtolower / mb_* in exam_grading.php, results.php)
# json      : bundled and enabled by default in this image
# openssl   : bundled by default (password_hash uses bcrypt)
# mbstring is NOT bundled in the official php:8.2-apache image, so it must be
# compiled. Compiling mbstring requires the oniguruma dev headers (libonig-dev)
# in the build image; without them docker-php-ext-install mbstring fails with a
# configure error. Install build deps, compile, then clean up in one layer.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# --- Apache modules used by the API ---
# rewrite : .htaccess (Options -Indexes, Require all denied on config/)
# headers : AllowOverride + Force JSON content-type headers
RUN a2enmod rewrite headers

# --- Copy application bundle into Apache document root ---
# Context root is the deploy/ folder. The API lives under api/ so that
# requests to /api/login.php (the Android BASE_URL) resolve correctly.
COPY . /var/www/html/

# --- .htaccess support (needed for config/ + database/ protection) ---
# Ensure AllowOverride is enabled in the default Apache site.
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# --- Listen on Render's $PORT (default 10000) instead of hardcoded 80 ---
# Render injects the PORT environment variable at runtime. The official
# php:apache image ships stock Debian config that listens on 80. We
# replace ports.conf and the default vhost so Apache binds to $PORT
# (belt-and-suspenders: the Listen directive lives in BOTH files so that
# whichever includes are active, Apache always listens on $PORT). A small
# entrypoint substitutes the real port at container start, then hands off
# to the official entrypoint.
COPY apache/ports.conf /etc/apache2/ports.conf
COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY apache/docker-entrypoint.sh /usr/local/bin/quiz-render-entrypoint.sh

RUN chmod +x /usr/local/bin/quiz-render-entrypoint.sh \
    && a2dissite 000-default \
    && a2ensite 000-default \
    && mkdir -p /var/lib/php/sessions

# Redirect Apache/PHP logs to stdout/stderr so Render captures them.
RUN ln -sf /dev/stdout /var/log/apache2/access.log \
    && ln -sf /dev/stderr /var/log/apache2/error.log

# --- Security: never expose dev/test scripts at runtime ---
# The .dockerignore prevents them from even being copied into the image.
# Extra runtime guard: deny direct web access to PHP files that perform
# local-only DB setup, in case a stray copy ever lands in the image.
RUN rm -f /var/www/html/database/setup.php

EXPOSE ${PORT:-10000}

# Entrypoint configures Apache for $PORT then runs apache2-foreground.
ENTRYPOINT ["/usr/local/bin/quiz-render-entrypoint.sh"]
CMD ["apache2-foreground"]
