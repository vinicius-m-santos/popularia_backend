#!/bin/sh
# Script de inicialização para Railway (e qualquer runtime de contêiner).
# 1. Ajusta o endereço de escuta do Caddy ao PORT injetado pelo host.
# 2. Aplica migrations pendentes (falha rápida se o banco estiver inacessível).
# 3. Entrega o processo ao FrankenPHP.
set -e

# Railway injeta PORT (padrão 8080). Caddy lê SERVER_NAME para o endereço de escuta.
export SERVER_NAME=":${PORT:-80}"

echo "[start] Aplicando migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[start] Iniciando FrankenPHP em ${SERVER_NAME}..."
exec frankenphp run --config /etc/frankenphp/Caddyfile
