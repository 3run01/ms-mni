#!/bin/bash
set -e

echo "🚀 Starting ECS deployment..."

# Carregar variáveis de ambiente
if [ -f .github-env ]; then
    source .github-env
fi

# Validar variáveis de ambiente necessárias
required_vars=("ECS_CLUSTER" "ECS_SERVICE" "ECS_TASK_DEFINITION" "CONTAINER_NAME" "VERSION_IMAGE")
for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        echo "❌ Error: Missing required environment variable: $var"
        exit 1
    fi
done

IMAGE_URI="$VERSION_IMAGE"
# ECR_REGISTRY="019701076927.dkr.ecr.sa-east-1.amazonaws.com"
# IMAGE_URI="$ECR_REGISTRY/vendor/sim:latest"

echo "🚀 Starting ECS deployment..."
echo "🎯 Cluster: $ECS_CLUSTER"
echo "🔧 Service: $ECS_SERVICE"
echo "📋 Task Definition: $ECS_TASK_DEFINITION"
echo "🐳 Container: $CONTAINER_NAME"
echo "🖼️  New Image: $IMAGE_URI"

# Função para aguardar estabilidade do serviço
wait_for_service_stability() {
    local cluster="$1"
    local service="$2"
    local max_wait_time=900 # 15 minutos
    
    echo "⏳ Waiting for service to stabilize..."
    
    if aws ecs wait services-stable \
        --cluster "$cluster" \
        --services "$service" \
        --cli-read-timeout "$max_wait_time" \
        --cli-connect-timeout 60; then
        echo "✅ Service is stable!"
        return 0
    else
        echo "⚠️  Service did not stabilize within expected time"
        return 1
    fi
}

# Baixar a task definition atual do AWS (fonte de verdade)
echo "📥 Downloading current task definition..."
aws ecs describe-task-definition \
    --task-definition "$ECS_TASK_DEFINITION" \
    --region "${ECR_REGION}" \
    --query taskDefinition > /tmp/current-task-definition.json

# Verificar se o download foi bem-sucedido
if [ ! -s /tmp/current-task-definition.json ]; then
    echo "❌ Failed to download task definition"
    exit 1
fi

echo "✅ Task definition downloaded successfully"

# Atualizar a task definition com a nova imagem
echo "🔄 Updating task definition with new image..."

# Usar jq para atualizar a imagem do container específico e remover campos gerenciados pelo AWS
jq --arg IMAGE_URI "$IMAGE_URI" --arg CONTAINER_NAME "$CONTAINER_NAME" \
    '(.containerDefinitions[] | select(.name == $CONTAINER_NAME) | .image) = $IMAGE_URI
     | del(.taskDefinitionArn, .revision, .status, .requiresAttributes, .placementConstraints, .compatibilities, .registeredAt, .registeredBy)' \
    /tmp/current-task-definition.json > final-task-definition.json

# Registrar a nova task definition
echo "📝 Registering new task definition..."
NEW_TASK_DEF_ARN=$(aws ecs register-task-definition \
    --region "${ECR_REGION}" \
    --cli-input-json file://final-task-definition.json \
    --query 'taskDefinition.taskDefinitionArn' \
    --output text)

if [ -z "$NEW_TASK_DEF_ARN" ]; then
    echo "❌ Failed to register new task definition"
    exit 1
fi

echo "✅ New task definition registered: $NEW_TASK_DEF_ARN"

# Atualizar o serviço ECS
echo "🔄 Updating ECS service..."
UPDATE_RESULT=$(aws ecs update-service \
    --region "${ECR_REGION}" \
    --cluster "$ECS_CLUSTER" \
    --service "$ECS_SERVICE" \
    --task-definition "$NEW_TASK_DEF_ARN" \
    --query 'service.taskDefinition' \
    --output text)

if [ "$UPDATE_RESULT" != "$NEW_TASK_DEF_ARN" ]; then
    echo "❌ Failed to update ECS service"
    exit 1
fi

echo "✅ ECS service update initiated"

# Aguardar estabilidade do serviço
if wait_for_service_stability "$ECS_CLUSTER" "$ECS_SERVICE"; then
    echo "🎉 ECS deployment completed successfully!"
else
    echo "⚠️  Deployment completed but service stability check timed out"
    echo "Please check the ECS console to verify deployment status"
fi

# Obter informações do deployment
echo "📊 Deployment Summary:"
aws ecs describe-services \
    --region "${ECR_REGION}" \
    --cluster "$ECS_CLUSTER" \
    --services "$ECS_SERVICE" \
    --query 'services[0].{
        ServiceName: serviceName,
        TaskDefinition: taskDefinition,
        RunningCount: runningCount,
        PendingCount: pendingCount,
        DesiredCount: desiredCount
    }' \
    --output table

# Limpeza dos arquivos temporários
rm -f /tmp/current-task-definition.json final-task-definition.json

echo "✅ ECS deployment process completed!"