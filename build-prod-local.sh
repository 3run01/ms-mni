#!/bin/bash

# Script para construir a imagem Docker de produção localmente
# Uso: ./build-prod-local.sh [tag]
# Exemplo: ./build-prod-local.sh sim-mni:prod-local

set -e

# Tag padrão da imagem
IMAGE_TAG="${1:-sim-mni:prod-local}"

# Caminho do Dockerfile
DOCKERFILE_PATH="docker/prod/8.4/Dockerfile"

echo "🐳 Construindo imagem Docker de produção..."
echo "📦 Tag: $IMAGE_TAG"
echo "📄 Dockerfile: $DOCKERFILE_PATH"
echo ""

# Verificar se o Dockerfile existe
if [ ! -f "$DOCKERFILE_PATH" ]; then
    echo "❌ Erro: Dockerfile não encontrado em $DOCKERFILE_PATH"
    exit 1
fi

# Verificar se os arquivos auxiliares existem
REQUIRED_FILES=(
    "docker/prod/8.4/start-container"
    "docker/prod/8.4/supervisord.conf"
    "docker/prod/8.4/php.ini"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        echo "❌ Erro: Arquivo necessário não encontrado: $file"
        exit 1
    fi
done

# Construir a imagem
echo "🔨 Iniciando build da imagem..."
docker build \
    -f "$DOCKERFILE_PATH" \
    -t "$IMAGE_TAG" \
    --build-arg WWWGROUP=1000 \
    --build-arg POSTGRES_VERSION=17 \
    .

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Build concluído com sucesso!"
    echo "📋 Imagem criada: $IMAGE_TAG"
    echo ""
    echo "💡 Para testar localmente, execute:"
    echo "   ./test-prod-local.sh $IMAGE_TAG"
    echo ""
    echo "💡 Para ver a imagem criada:"
    echo "   docker images | grep sim-mni"
else
    echo ""
    echo "❌ Erro ao construir a imagem"
    exit 1
fi
