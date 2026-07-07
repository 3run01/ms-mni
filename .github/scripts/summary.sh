#!/bin/bash
set -e

echo "📊 Generating deployment summary..."

# Carregar variáveis de ambiente
if [ -f .github-env ]; then
    source .github-env
fi

# Criar resumo para o GitHub Actions
cat >> $GITHUB_STEP_SUMMARY <<EOF
# 🚀 Deployment Summary

## ✅ Deployment Completed Successfully!

### 📋 Deployment Details
| Field | Value |
|-------|-------|
| **Version** | \`$VERSION\` |
| **Image URI** | \`$VERSION_IMAGE\` |
| **Date Tag** | \`$DATE_TAG\` |
| **Hash Tag** | \`$HASH_TAG\` |
| **ECS Cluster** | \`$ECS_CLUSTER\` |
| **ECS Service** | \`$ECS_SERVICE\` |
| **Container** | \`$CONTAINER_NAME\` |
| **Commit SHA** | \`$GITHUB_SHA\` |

### 🏷️ Docker Tags Created
- \`$VERSION\` (version)
- \`latest\` (latest)
- \`$DATE_TAG\` (date)
- \`$HASH_TAG\` (commit hash)

### 🔗 Useful Links
- [ECS Console](https://console.aws.amazon.com/ecs/home?region=${ECR_REGION}#/clusters/$ECS_CLUSTER/services/$ECS_SERVICE/details)
- [ECR Repository](https://console.aws.amazon.com/ecr/repositories/private/${ECR_REGISTRY}/$ECR_REPOSITORY?region=${ECR_REGION})

---
*Deployment completed at: $(date)*
EOF

echo "✅ Summary generated successfully!"

# Limpar arquivos temporários
rm -f .github-env task-definition.json updated-task-definition.json final-task-definition.json

echo "🧹 Cleanup completed!"