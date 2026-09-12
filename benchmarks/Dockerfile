# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM ghcr.io/lucasacoutinho/ext-clickhouse-pdo:php8.5-v1.4.1

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /package

COPY composer.json composer.json
COPY src src

WORKDIR /opt/native

COPY benchmarks/composer.native.json composer.json

RUN composer install \
        --classmap-authoritative \
        --no-dev \
        --no-progress \
        --prefer-dist

WORKDIR /opt/http

COPY benchmarks/composer.http.json composer.json

RUN composer install \
        --classmap-authoritative \
        --no-dev \
        --no-progress \
        --prefer-dist

COPY benchmarks /benchmark

WORKDIR /benchmark
