#!/bin/bash
set -e

echo "🐳 Starting Docker build process..."

# Carregar variáveis de ambiente do setup
if [ -f .github-env ]; then
    source .github-env
fi

# Validar variáveis de ambiente necessárias
required_vars=("ECR_REPOSITORY" "VERSION" "DATE_TAG" "HASH_TAG" "DOCKERFILE" "BUILD_CONTEXT")
for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        echo "❌ Error: Missing required environment variable: $var"
        exit 1
    fi
done

# Obter ECR registry do AWS CLI
ECR_REGISTRY=$(aws sts get-caller-identity --query Account --output text).dkr.ecr.${ECR_REGION:-sa-east-1}.amazonaws.com

# Construir nomes completos das imagens
VERSION_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:$VERSION"
LATEST_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:latest"
DATE_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:$DATE_TAG"
HASH_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:$HASH_TAG"

echo "🐳 Building Docker image with multiple tags..."
echo "📦 Repository: $ECR_REPOSITORY"
echo "📄 Dockerfile: $DOCKERFILE"
echo "🏷️  Version tag: $VERSION"
echo "📅 Date tag: $DATE_TAG"
echo "🔢 Hash tag: $HASH_TAG"

# Build da imagem principal
echo "🔨 Building image..."
docker build \
    -f "$DOCKERFILE" \
    --build-arg WWWGROUP=1000 \
    --build-arg POSTGRES_VERSION=17 \
    -t "$VERSION_IMAGE" \
    "$BUILD_CONTEXT"

# Criar tags adicionais
echo "🏷️  Creating additional tags..."
docker tag "$VERSION_IMAGE" "$LATEST_IMAGE"
docker tag "$VERSION_IMAGE" "$DATE_IMAGE"
docker tag "$VERSION_IMAGE" "$HASH_IMAGE"

# Verificar se as imagens foram criadas
echo "✅ Verifying created images..."
docker images | grep "$ECR_REPOSITORY"

# Salvar informações das tags para próximos steps
echo "ECR_REGISTRY=$ECR_REGISTRY" >> $GITHUB_ENV
echo "VERSION_IMAGE=$VERSION_IMAGE" >> $GITHUB_ENV

echo "🎉 Docker build completed successfully!"
echo "📋 Tags created:"
echo "  - $VERSION_IMAGE"
echo "  - $LATEST_IMAGE"
echo "  - $DATE_IMAGE"
echo "  - $HASH_IMAGE"