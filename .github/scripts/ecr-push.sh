#!/bin/bash
set -e

echo "📤 Starting ECR push process..."

# Carregar variáveis de ambiente
if [ -f .github-env ]; then
    source .github-env
fi

# Re-fazer login no ECR para garantir autenticação
echo "🔐 Logging in to ECR..."
aws ecr get-login-password --region ${ECR_REGION:-us-east-1} | docker login --username AWS --password-stdin $ECR_REGISTRY

# Validar variáveis de ambiente necessárias
required_vars=("ECR_REGISTRY" "ECR_REPOSITORY" "VERSION" "DATE_TAG" "HASH_TAG")
for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        echo "❌ Error: Missing required environment variable: $var"
        exit 1
    fi
done

# Construir nomes completos das imagens
VERSION_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:$VERSION"
LATEST_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:latest"
DATE_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:$DATE_TAG"
HASH_IMAGE="$ECR_REGISTRY/$ECR_REPOSITORY:$HASH_TAG"

echo "📤 Pushing images to ECR..."
echo "🎯 Registry: $ECR_REGISTRY"
echo "📦 Repository: $ECR_REPOSITORY"

# Função para fazer push com retry
push_with_retry() {
    local image="$1"
    local max_attempts=3
    local attempt=1
    
    while [ $attempt -le $max_attempts ]; do
        echo "🔄 Pushing $image (attempt $attempt/$max_attempts)..."
        
        if docker push "$image"; then
            echo "✅ Successfully pushed $image"
            return 0
        else
            echo "⚠️  Failed to push $image (attempt $attempt/$max_attempts)"
            if [ $attempt -eq $max_attempts ]; then
                echo "❌ Failed to push $image after $max_attempts attempts"
                return 1
            fi
            attempt=$((attempt + 1))
            sleep 5
        fi
    done
}

# Push de todas as tags
echo "🚀 Starting push process..."

push_with_retry "$VERSION_IMAGE"
push_with_retry "$LATEST_IMAGE"
push_with_retry "$DATE_IMAGE"
push_with_retry "$HASH_IMAGE"

# Comentado temporariamente - debug de região
echo "🔍 Verifying pushed images..."
echo "DEBUG: Repository = $ECR_REPOSITORY"
echo "DEBUG: Region = ${ECR_REGION}"
echo "DEBUG: Version = $VERSION"

aws ecr describe-images \
    --repository-name "$ECR_REPOSITORY" \
    --image-ids imageTag="$VERSION" \
    --region "${ECR_REGION}" \
    --query 'imageDetails[0].imagePushedAt' \
    --output text

echo "🎉 All images pushed successfully!"
echo "📋 Pushed tags:"
echo "  - $VERSION (version)"
echo "  - latest"
echo "  - $DATE_TAG (date)"
echo "  - $HASH_TAG (commit hash)"

echo "✅ ECR push completed!"