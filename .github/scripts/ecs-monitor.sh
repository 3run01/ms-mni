#!/bin/bash

# ECS Deployment Monitor Script
# Monitora o deployment ECS e exibe logs para verificar se o container subiu com sucesso

set -e

echo "🔍 Monitoring ECS deployment and container logs..."

# Carregar variáveis de ambiente do setup
if [ -f .github-env ]; then
    source .github-env
fi

# Configurações - usando o mesmo padrão dos outros scripts
CLUSTER_NAME="${ECS_CLUSTER:-$1}"
SERVICE_NAME="${ECS_SERVICE:-$2}"
LOG_GROUP_NAME="${ECS_LOG_GROUP_NAME:-/ecs/$SERVICE_NAME}"
WAIT_TIMEOUT="${ECS_WAIT_TIMEOUT:-600}"
LOG_MINUTES="${ECS_LOG_MINUTES:-10}"

# Validações
if [ -z "$CLUSTER_NAME" ]; then
  echo "❌ ECS_CLUSTER not set. Make sure setup.sh ran successfully."
  exit 1
fi

if [ -z "$SERVICE_NAME" ]; then
  echo "❌ ECS_SERVICE not set. Make sure setup.sh ran successfully."
  exit 1
fi

echo "📋 Cluster: $CLUSTER_NAME"
echo "📋 Service: $SERVICE_NAME"
echo "📋 Log Group: $LOG_GROUP_NAME"
echo "📋 ECR Region: ${ECR_REGION:-sa-east-1}"

# Função para obter logs
get_container_logs() {
  local log_group=$1
  local log_minutes=${2:-10}
  
  echo "📝 Searching for log streams in $log_group..."
  
  # Busca o log stream mais recente
  LOG_STREAM=$(aws logs describe-log-streams \
    --log-group-name "$log_group" \
    --order-by LastEventTime \
    --descending \
    --limit 1 \
    --region "${ECR_REGION:-sa-east-1}" \
    --query 'logStreams[0].logStreamName' \
    --output text 2>/dev/null || echo "")
  
  if [ -n "$LOG_STREAM" ] && [ "$LOG_STREAM" != "None" ]; then
    echo "📝 Fetching recent logs from stream: $LOG_STREAM"
    echo "==================== CONTAINER LOGS ===================="
    
    # Obtém logs dos últimos X minutos
    START_TIME=$(date -d "$log_minutes minutes ago" +%s)000
    
    aws logs get-log-events \
      --log-group-name "$log_group" \
      --log-stream-name "$LOG_STREAM" \
      --start-time $START_TIME \
      --region "${ECR_REGION:-sa-east-1}" \
      --query 'events[*].[timestamp,message]' \
      --output text 2>/dev/null | while IFS=
        if [ -n "$timestamp" ] && [ -n "$message" ]; then
          # Converte timestamp para formato legível
          readable_time=$(date -d "@$(echo $timestamp | cut -c1-10)" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "Unknown")
          echo "[$readable_time] $message"
        fi
      done
    
    echo "==================== END OF LOGS ======================="
  else
    echo "⚠️  Could not find log stream for $log_group"
    echo "💡 Make sure your ECS task definition has logging configured"
    return 1
  fi
}

# Função para verificar health da aplicação (customize conforme necessário)
check_application_health() {
  local health_url="${APPLICATION_HEALTH_URL}"
  
  if [ -n "$health_url" ]; then
    echo "🏥 Checking application health at $health_url..."
    sleep 30  # Aguarda a aplicação inicializar
    
    if curl -f -s --max-time 10 "$health_url" >/dev/null 2>&1; then
      echo "✅ Application health check passed!"
      return 0
    else
      echo "❌ Application health check failed"
      return 1
    fi
  fi
  
  return 0  # Skip health check if URL not provided
}

# Aguarda o deployment completar
echo "⏳ Waiting for deployment to complete (timeout: ${WAIT_TIMEOUT}s)..."
if ! aws ecs wait services-stable \
  --cluster "$CLUSTER_NAME" \
  --services "$SERVICE_NAME" \
  --region "${ECR_REGION:-sa-east-1}" \
  --cli-read-timeout $WAIT_TIMEOUT \
  --cli-connect-timeout 60; then
  echo "❌ Deployment wait timed out or failed"
  exit 1
fi

echo "✅ Deployment completed successfully"

# Obtém informações da task mais recente
echo "🔍 Getting latest task information..."
TASK_ARN=$(aws ecs list-tasks \
  --cluster "$CLUSTER_NAME" \
  --service-name "$SERVICE_NAME" \
  --desired-status RUNNING \
  --region "${ECR_REGION:-sa-east-1}" \
  --query 'taskArns[0]' \
  --output text)

if [ "$TASK_ARN" = "None" ] || [ -z "$TASK_ARN" ]; then
  echo "❌ No running tasks found for service $SERVICE_NAME"
  exit 1
fi

echo "📋 Task ARN: $TASK_ARN"

# Obtém detalhes da task
TASK_DETAILS=$(aws ecs describe-tasks \
  --cluster "$CLUSTER_NAME" \
  --tasks "$TASK_ARN" \
  --region "${ECR_REGION:-sa-east-1}")

# Verifica o status da task
TASK_STATUS=$(echo "$TASK_DETAILS" | jq -r '.tasks[0].lastStatus')
echo "📊 Task Status: $TASK_STATUS"

# Obtém e exibe logs
get_container_logs "$LOG_GROUP_NAME" "$LOG_MINUTES"

# Verifica se a task está rodando
if [ "$TASK_STATUS" = "RUNNING" ]; then
  echo "✅ Task is running successfully!"
  
  # Verifica health da aplicação se configurado
  if ! check_application_health; then
    echo "⚠️  Task is running but application health check failed"
    exit 1
  fi
  
  echo "🎉 Deployment completed successfully! Container is healthy and running."
  
else
  echo "❌ Task is not in RUNNING state. Current status: $TASK_STATUS"
  
  # Mostra motivos de falha se houver
  STOP_REASON=$(echo "$TASK_DETAILS" | jq -r '.tasks[0].stoppedReason // empty')
  if [ -n "$STOP_REASON" ]; then
    echo "🔍 Stop reason: $STOP_REASON"
  fi
  
  # Mostra detalhes dos containers
  echo "📋 Container details:"
  echo "$TASK_DETAILS" | jq -r '.tasks[0].containers[] | "  • Container: \(.name) - Status: \(.lastStatus) - Reason: \(.reason // "N/A")"'
  
  exit 1
fi\t' read -r timestamp message; do
        if [ -n "$timestamp" ] && [ -n "$message" ]; then
          # Converte timestamp para formato legível
          readable_time=$(date -d "@$(echo $timestamp | cut -c1-10)" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "Unknown")
          echo "[$readable_time] $message"
        fi
      done
    
    echo "==================== END OF LOGS ======================="
  else
    echo "⚠️  Could not find log stream for $log_group"
    echo "💡 Make sure your ECS task definition has logging configured"
    return 1
  fi
}

# Função para verificar health da aplicação (customize conforme necessário)
check_application_health() {
  local health_url="${APPLICATION_HEALTH_URL}"
  
  if [ -n "$health_url" ]; then
    echo "🏥 Checking application health at $health_url..."
    sleep 30  # Aguarda a aplicação inicializar
    
    if curl -f -s --max-time 10 "$health_url" >/dev/null 2>&1; then
      echo "✅ Application health check passed!"
      return 0
    else
      echo "❌ Application health check failed"
      return 1
    fi
  fi
  
  return 0  # Skip health check if URL not provided
}

# Aguarda o deployment completar
echo "⏳ Waiting for deployment to complete (timeout: ${WAIT_TIMEOUT}s)..."
if ! aws ecs wait services-stable \
  --cluster "$CLUSTER_NAME" \
  --services "$SERVICE_NAME" \
  --region "${ECR_REGION:-sa-east-1}" \
  --cli-read-timeout $WAIT_TIMEOUT \
  --cli-connect-timeout 60; then
  echo "❌ Deployment wait timed out or failed"
  exit 1
fi

echo "✅ Deployment completed successfully"

# Obtém informações da task mais recente
echo "🔍 Getting latest task information..."
TASK_ARN=$(aws ecs list-tasks \
  --cluster "$CLUSTER_NAME" \
  --service-name "$SERVICE_NAME" \
  --desired-status RUNNING \
  --region "${ECR_REGION:-sa-east-1}" \
  --query 'taskArns[0]' \
  --output text)

if [ "$TASK_ARN" = "None" ] || [ -z "$TASK_ARN" ]; then
  echo "❌ No running tasks found for service $SERVICE_NAME"
  exit 1
fi

echo "📋 Task ARN: $TASK_ARN"

# Obtém detalhes da task
TASK_DETAILS=$(aws ecs describe-tasks \
  --cluster "$CLUSTER_NAME" \
  --tasks "$TASK_ARN" \
  --region "${ECR_REGION:-sa-east-1}")

# Verifica o status da task
TASK_STATUS=$(echo "$TASK_DETAILS" | jq -r '.tasks[0].lastStatus')
echo "📊 Task Status: $TASK_STATUS"

# Obtém e exibe logs
get_container_logs "$LOG_GROUP_NAME" "$LOG_MINUTES"

# Verifica se a task está rodando
if [ "$TASK_STATUS" = "RUNNING" ]; then
  echo "✅ Task is running successfully!"
  
  # Verifica health da aplicação se configurado
  if ! check_application_health; then
    echo "⚠️  Task is running but application health check failed"
    exit 1
  fi
  
  echo "🎉 Deployment completed successfully! Container is healthy and running."
  
else
  echo "❌ Task is not in RUNNING state. Current status: $TASK_STATUS"
  
  # Mostra motivos de falha se houver
  STOP_REASON=$(echo "$TASK_DETAILS" | jq -r '.tasks[0].stoppedReason // empty')
  if [ -n "$STOP_REASON" ]; then
    echo "🔍 Stop reason: $STOP_REASON"
  fi
  
  # Mostra detalhes dos containers
  echo "📋 Container details:"
  echo "$TASK_DETAILS" | jq -r '.tasks[0].containers[] | "  • Container: \(.name) - Status: \(.lastStatus) - Reason: \(.reason // "N/A")"'
  
  exit 1
fi