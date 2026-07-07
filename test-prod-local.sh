#!/bin/bash

# Script para testar o container de produção localmente
# Uso: ./test-prod-local.sh [tag] [porta]
# Exemplo: ./test-prod-local.sh sim-mni:prod-local 8010

set -e

# Tag da imagem (padrão: sim-mni:prod-local)
IMAGE_TAG="${1:-sim-mni:prod-local}"

# Porta do host (padrão: 8010)
HOST_PORT="${2:-8010}"

# Nome do container
CONTAINER_NAME="sim-mni-prod-test"

# Verificar se .env existe
if [ ! -f ".env" ]; then
    echo "❌ Arquivo .env não encontrado!"
    echo "💡 Copie o .env.example: cp .env.example .env"
    echo "💡 Configure as variáveis de ambiente necessárias antes de continuar"
    exit 1
fi

# Verificar se a imagem existe
if ! docker images --format "{{.Repository}}:{{.Tag}}" | grep -q "^${IMAGE_TAG}$"; then
    echo "❌ Imagem não encontrada: $IMAGE_TAG"
    echo "💡 Construa a imagem primeiro: ./build-prod-local.sh $IMAGE_TAG"
    exit 1
fi

echo "🚀 Executando container de produção localmente..."
echo "📦 Imagem: $IMAGE_TAG"
echo "🌐 Porta: $HOST_PORT"
echo "📝 Container: $CONTAINER_NAME"
echo ""

# Parar e remover container existente se estiver rodando
if docker ps -q -f name="$CONTAINER_NAME" | grep -q .; then
    echo "🛑 Parando container existente..."
    docker stop "$CONTAINER_NAME" > /dev/null 2>&1 || true
fi

if docker ps -aq -f name="$CONTAINER_NAME" | grep -q .; then
    echo "🗑️  Removendo container existente..."
    docker rm "$CONTAINER_NAME" > /dev/null 2>&1 || true
fi

# Verificar se a rede existe, criar se não existir
if ! docker network ls --format "{{.Name}}" | grep -q "^desenvolvimento$"; then
    echo "🌐 Criando rede Docker 'desenvolvimento'..."
    docker network create desenvolvimento > /dev/null 2>&1 || true
fi

# Executar container
echo "▶️  Iniciando container..."
docker run -d \
    --name "$CONTAINER_NAME" \
    --network desenvolvimento \
    -p "$HOST_PORT:80" \
    --env-file .env \
    "$IMAGE_TAG"

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Container iniciado com sucesso!"
    echo ""
    echo "📋 Informações:"
    echo "   🌐 URL: http://localhost:$HOST_PORT"
    echo "   📝 Container: $CONTAINER_NAME"
    echo ""
    echo "💡 Comandos úteis:"
    echo "   📊 Ver logs: docker logs -f $CONTAINER_NAME"
    echo "   🛑 Parar: docker stop $CONTAINER_NAME"
    echo "   🗑️  Remover: docker rm -f $CONTAINER_NAME"
    echo "   🔍 Status: docker ps | grep $CONTAINER_NAME"
    echo ""
    
    # Aguardar alguns segundos e mostrar logs iniciais
    echo "⏳ Aguardando inicialização..."
    sleep 5
    
    echo ""
    echo "📊 Últimas linhas dos logs:"
    echo "─────────────────────────────────────────────────────────────────────────"
    docker logs "$CONTAINER_NAME" 2>&1 | tail -20
    echo ""
else
    echo ""
    echo "❌ Erro ao iniciar o container"
    echo "💡 Verifique os logs: docker logs $CONTAINER_NAME"
    exit 1
fi
