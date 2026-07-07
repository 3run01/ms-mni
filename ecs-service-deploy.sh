#!/bin/bash

# Script para atualizar a imagem do container e fazer deploy no ECS
# As variáveis de ambiente são gerenciadas diretamente no painel da AWS.
# Uso: ./ecs-service-deploy.sh [image-tag]
# Exemplo: ./ecs-service-deploy.sh latest

set -e

AWS_REGION="sa-east-1"
AWS_ACCOUNT_ID="019701076927"
ECR_REGISTRY="$AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com"
ECR_REPO="vendor/sim-mni"
IMAGE_TAG="${1:-latest}"
IMAGE="$ECR_REGISTRY/$ECR_REPO:$IMAGE_TAG"

ECS_CLUSTER="SIM-Cluster"
ECS_SERVICE="sim-mni"
TASK_FAMILY="sim-mni-task-definition"
CONTAINER_NAME="sim-mni"

TARGET_GROUP_ARN="arn:aws:elasticloadbalancing:$AWS_REGION:$AWS_ACCOUNT_ID:targetgroup/tg-sim-mni/98dabcd37581e11f"
SECURITY_GROUP="sg-09e10312003dd56f4"
SUBNET_1A="subnet-087178847c165c86f"
SUBNET_1B="subnet-006568b30bbf9763d"

echo "Deploy ECS EC2 - sim-mni"
echo "Imagem: $IMAGE"
echo ""

# Verificar AWS CLI
if ! aws sts get-caller-identity --region "$AWS_REGION" > /dev/null 2>&1; then
    echo "AWS CLI nao configurado ou sem permissao."
    exit 1
fi

# Baixar task definition atual da AWS (fonte de verdade para env vars)
echo "Baixando task definition atual ($TASK_FAMILY)..."
aws ecs describe-task-definition \
    --task-definition "$TASK_FAMILY" \
    --region "$AWS_REGION" \
    --query taskDefinition > /tmp/sim-mni-current-task.json

TASK_DEF_FILE=$(mktemp /tmp/sim-mni-task-def-XXXXXX.json)
trap "rm -f $TASK_DEF_FILE /tmp/sim-mni-current-task.json" EXIT

# Apenas atualizar a imagem do container, mantendo todas as env vars da AWS
python3 - << PYEOF > "$TASK_DEF_FILE"
import json

with open('/tmp/sim-mni-current-task.json') as f:
    td = json.load(f)

for field in ['taskDefinitionArn','revision','status','requiresAttributes',
              'placementConstraints','compatibilities','registeredAt','registeredBy']:
    td.pop(field, None)

for c in td.get('containerDefinitions', []):
    if c['name'] == '$CONTAINER_NAME':
        c['image'] = '$IMAGE'

print(json.dumps(td, indent=2))
PYEOF

# Registrar nova task definition
echo "Registrando nova task definition (EC2)..."
NEW_TASK_ARN=$(aws ecs register-task-definition \
    --region "$AWS_REGION" \
    --cli-input-json "file://$TASK_DEF_FILE" \
    --query 'taskDefinition.taskDefinitionArn' \
    --output text)

echo "Task definition registrada: $NEW_TASK_ARN"

# Verificar se servico ja existe
SERVICE_EXISTS=$(aws ecs describe-services \
    --cluster "$ECS_CLUSTER" \
    --services "$ECS_SERVICE" \
    --region "$AWS_REGION" \
    --query 'services[?status==`ACTIVE`].serviceName' \
    --output text 2>/dev/null || echo "")

if [ -n "$SERVICE_EXISTS" ]; then
    echo "Atualizando servico existente '$ECS_SERVICE'..."
    aws ecs update-service \
        --cluster "$ECS_CLUSTER" \
        --service "$ECS_SERVICE" \
        --task-definition "$NEW_TASK_ARN" \
        --region "$AWS_REGION" \
        --output text --query 'service.serviceName' > /dev/null
    echo "Servico atualizado."
else
    echo "Criando servico '$ECS_SERVICE' no cluster '$ECS_CLUSTER'..."
    aws ecs create-service \
        --cluster "$ECS_CLUSTER" \
        --service-name "$ECS_SERVICE" \
        --task-definition "$NEW_TASK_ARN" \
        --capacity-provider-strategy "capacityProvider=sim-mni-cp,weight=1,base=1" \
        --desired-count 1 \
        --network-configuration "awsvpcConfiguration={subnets=[$SUBNET_1A,$SUBNET_1B],securityGroups=[$SECURITY_GROUP]}" \
        --load-balancers "targetGroupArn=$TARGET_GROUP_ARN,containerName=$CONTAINER_NAME,containerPort=80" \
        --health-check-grace-period-seconds 120 \
        --region "$AWS_REGION" \
        --output text --query 'service.serviceName' > /dev/null
    echo "Servico criado."
fi

echo ""
echo "Aguardando estabilizacao (ate 10 min)..."
aws ecs wait services-stable \
    --cluster "$ECS_CLUSTER" \
    --services "$ECS_SERVICE" \
    --region "$AWS_REGION" && echo "Servico estavel!" || echo "Timeout — verifique no console ECS."

echo ""
echo "Status do servico:"
aws ecs describe-services \
    --cluster "$ECS_CLUSTER" \
    --services "$ECS_SERVICE" \
    --region "$AWS_REGION" \
    --query 'services[0].{Service:serviceName,Task:taskDefinition,Running:runningCount,Desired:desiredCount,Pending:pendingCount}' \
    --output table
