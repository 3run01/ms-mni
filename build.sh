#!/bin/bash

# Carrega variáveis do .env
set -a
source .env
set +a

docker exec -it "$CONTAINER_NAME" composer install
docker exec -it "$CONTAINER_NAME" php artisan migrate --force
docker exec -it "$CONTAINER_NAME" php artisan cache:clear
docker exec -it "$CONTAINER_NAME" php artisan route:clear
docker exec -it "$CONTAINER_NAME" npm install
docker exec -it "$CONTAINER_NAME" npm run build
# docker exec -it "$CONTAINER_NAME" php artisan clear-compiled
# docker exec -it "$CONTAINER_NAME" php artisan optimize:clear
# docker exec -it "$CONTAINER_NAME" php artisan optimize

