#!/bin/bash

docker build -f ./docker/Dockerfile.aws.prod -t vendor/sim-mni . --no-cache

# Script para executar container localmente com variáveis do .env
set -e

# Verificar se .env existe
if [ ! -f ".env" ]; then
    echo "❌ Arquivo .env não encontrado!"
    echo "💡 Copie o .env.example: cp .env.example .env"
    exit 1
fi

echo "🚀 Executando container com variáveis do .env local..."

# Parar container se estiver rodando
if docker ps -q -f name=sim-mni-container | grep -q .; then
    echo "🛑 Parando container existente..."
    docker stop sim-mni-container
    docker rm sim-mni-container
fi

# Executar container com .env
docker run -d \
    --name sim-mni-container \
    --network desenvolvimento \
    -p 8011:80 \
    --env-file .env \
    vendor/sim-mni

echo "✅ Container iniciado com sucesso!"
echo "🌐 Acesse: http://localhost:8011"
echo "📋 Logs: docker logs -f sim-mni-container"

# Mostrar logs iniciais
sleep 3
echo ""
echo "📊 Status dos serviços:"
docker logs sim-mni-container 2>&1 | tail -10