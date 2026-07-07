#!/bin/bash
set -e

echo "🚀 Setting up deployment environment..."

# Tornar todos os scripts executáveis
chmod +x .github/scripts/*.sh

# Carregar configurações do deploy-config.json
CONFIG_FILE=".github/config/deploy-config.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "❌ Configuration file not found: $CONFIG_FILE"
    exit 1
fi

echo "📋 Loading deployment configuration..."

# Exportar configurações como variáveis de ambiente
export ECR_REPOSITORY=$(jq -r '.ecr.repository' "$CONFIG_FILE")
export ECR_REGION=$(jq -r '.ecr.region' "$CONFIG_FILE")
export ECS_CLUSTER=$(jq -r '.ecs.cluster' "$CONFIG_FILE")
export ECS_SERVICE=$(jq -r '.ecs.service' "$CONFIG_FILE")
export ECS_TASK_DEFINITION=$(jq -r '.ecs.taskDefinition' "$CONFIG_FILE")
export CONTAINER_NAME=$(jq -r '.ecs.containerName' "$CONFIG_FILE")
export DOCKERFILE=$(jq -r '.docker.dockerfile' "$CONFIG_FILE")
export BUILD_CONTEXT=$(jq -r '.docker.buildContext' "$CONFIG_FILE")

# Salvar configurações em arquivos para outros steps
cat > .github-env <<EOF
ECR_REPOSITORY=$ECR_REPOSITORY
ECR_REGION=$ECR_REGION
ECS_CLUSTER=$ECS_CLUSTER
ECS_SERVICE=$ECS_SERVICE
ECS_TASK_DEFINITION=$ECS_TASK_DEFINITION
CONTAINER_NAME=$CONTAINER_NAME
DOCKERFILE=$DOCKERFILE
BUILD_CONTEXT=$BUILD_CONTEXT
EOF

# Adicionar ao GitHub Environment
cat .github-env >> $GITHUB_ENV

echo "✅ Configuration loaded:"
echo "  ECR Repository: $ECR_REPOSITORY"
echo "  ECS Cluster: $ECS_CLUSTER"
echo "  ECS Service: $ECS_SERVICE"

# Determinar versão
echo "🏷️  Determining version..."
VERSION=$(.github/scripts/version-manager.sh 2>/dev/null | tail -n1)

if [ -z "$VERSION" ]; then
    echo "❌ Failed to determine version"
    exit 1
fi

# Validar formato da versão
if [[ ! "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "❌ Invalid version format: $VERSION"
    exit 1
fi

echo "VERSION=$VERSION" >> $GITHUB_ENV
echo "✅ Version determined: $VERSION"

# Gerar tags adicionais
DATE_TAG=$(date +%Y-%m-%d)
HASH_TAG=$(git rev-parse --short HEAD)

echo "DATE_TAG=$DATE_TAG" >> $GITHUB_ENV
echo "HASH_TAG=$HASH_TAG" >> $GITHUB_ENV

echo "📅 Date tag: $DATE_TAG"
echo "🔢 Hash tag: $HASH_TAG"

echo "🎉 Setup completed successfully!"