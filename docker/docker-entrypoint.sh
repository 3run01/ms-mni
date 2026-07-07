#!/bin/bash
set -e

# Adicionar no início do arquivo, após o shebang
echo "🚀 Iniciando aplicação no ECS..."

if [ -z "$APP_URL" ]; then
    echo "❌ APP_URL não definida"
    exit 1
fi

# Verificar se as variáveis de ambiente necessárias estão definidas
echo "Verificando variáveis de ambiente..."
required_vars=("DB_HOST" "DB_DATABASE" "DB_USERNAME" "DB_PASSWORD" "APP_KEY")
missing_vars=()

for var in "${required_vars[@]}"; do
  if [ -z "${!var}" ]; then
    missing_vars+=("$var")
  fi
done

if [ ${#missing_vars[@]} -ne 0 ]; then
  echo "ERRO: As seguintes variáveis de ambiente obrigatórias não estão definidas:"
  printf "  - %s\n" "${missing_vars[@]}"
  echo "Estas variáveis devem ser configuradas no ECS Task Definition."
  exit 1
fi

# Gerar .env somente se não existir
if [ ! -f ".env" ]; then
  echo "Gerando arquivo .env..."
  env | grep -E '^(APP_|DB_|MAIL_|QUEUE_|REDIS_|AWS_|LOG_)' > .env
fi

# Executar comandos de inicialização do Laravel
if [ -f "artisan" ]; then
    echo "Executando comandos do Laravel..."
    php artisan migrate --force
    php artisan cache:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan storage:link
fi

echo "Inicialização concluída. Iniciando aplicação..."

# Executar o comando original (supervisord)
exec "$@"
