# Serve the music-catalog example over FrankenPHP (https://frankenphp.dev) — a
# self-contained demo of the library answering real JSON:API requests over HTTP.
#
# Build from the repository ROOT (the build context is the whole repo):
#   docker build -f examples/music-catalog/Dockerfile -t json-api-example .
#   docker run --rm -p 8080:80 json-api-example
#   curl -H 'Accept: application/vnd.api+json' http://localhost:8080/albums
#
# Or, from this directory, just: docker compose up
FROM dunglas/frankenphp

# intl backs the reference-data (countries) resource; everything else is bundled.
RUN install-php-extensions intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
# The worked example autoloads under Composer's dev section, so install WITH dev.
RUN composer install --no-interaction --prefer-dist --no-progress

# Serve the example's front controller on all interfaces (:80, not the default
# `localhost` — which a published port cannot reach). SERVER_ROOT + SERVER_NAME
# drive the image's built-in Caddyfile, so no custom config is needed.
ENV SERVER_NAME=:80
ENV SERVER_ROOT=/app/examples/music-catalog/public

EXPOSE 80
