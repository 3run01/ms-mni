
# CI/CD Configuration

Este diretório contém toda a configuração de CI/CD para deploy automatizado no AWS ECS.

## 📁 Estrutura

```
.github/
├── workflows/
│   └── deploy.yml          # GitHub Actions workflow principal
├── scripts/
│   ├── setup.sh           # Setup inicial e carregamento de configs
│   ├── version-manager.sh  # Gerenciamento de versões e tags
│   ├── docker-build.sh    # Build da imagem Docker
│   ├── ecr-push.sh        # Push das imagens para ECR
│   ├── ecs-deploy.sh      # Deploy no ECS
│   └── summary.sh         # Geração do resumo do deploy
├── config/
│   └── deploy-config.json # Configurações centralizadas
└── README.md              # Este arquivo
```

## 🚀 Como Funciona

### Trigger Automático
O deploy é executado automaticamente a cada push para a branch `main`.

### Fluxo de Execução
1. **Setup** - Carrega configurações e determina versão
2. **Build** - Cria imagem Docker com múltiplas tags
3. **Push** - Envia imagens para ECR
4. **Deploy** - Atualiza serviço ECS
5. **Summary** - Gera resumo do deploy

### Tags Automáticas
Para cada deploy, são criadas as seguintes tags:
- `v1.0.1` - Versão semântica
- `latest` - Última versão
- `2025-06-04` - Data do deploy
- `abc1234` - Hash do commit

## 🏷️ Controle de Versão

### Auto-incremento (Padrão)
```bash
git commit -m "feat: nova funcionalidade"
git push origin main
# Resultado: v1.0.0 → v1.0.1
```

### Tag Personalizada via Commit
```bash
git commit -m "feat: nova funcionalidade [tag:v2.0.0]"
git push origin main
# Resultado: v2.0.0
```

### Tag Personalizada via Arquivo
```bash
echo "v2.0.0" > .version-override
git add .version-override
git commit -m "feat: nova funcionalidade"
git push origin main
# Resultado: v2.0.0
```

## ⚙️ Configuração

### 1. Secrets e Variáveis no GitHub

#### Secrets (Obrigatórios)
Vá em **Settings** → **Secrets and variables** → **Actions** → **Secrets**:

| Nome | Descrição | Exemplo |
|------|-----------|---------|
| `AWS_ACCESS_KEY_ID` | Access Key ID do usuário IAM | `AKIAIOSFODNN7EXAMPLE` |
| `AWS_SECRET_ACCESS_KEY` | Secret Access Key do usuário IAM | `wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY` |

#### Variables (Opcionais)
Vá em **Settings** → **Secrets and variables** → **Actions** → **Variables**:

| Nome | Descrição | Valor Padrão |
|------|-----------|--------------|
| `AWS_REGION` | Região AWS para deploy | `sa-east-1` |

> **📝 Nota:** As demais configurações (ECR_REPOSITORY, ECS_CLUSTER, etc.) são definidas no arquivo `.github/config/deploy-config.json` em vez de variáveis do GitHub. Isso facilita o versionamento e controle das configurações.

#### Como Configurar:
1. No GitHub, vá para seu repositório
2. **Settings** → **Secrets and variables** → **Actions**
3. Clique em **New repository secret**
4. Adicione cada secret conforme a tabela acima
5. Para variables, use a aba **Variables** e clique **New repository variable**

### 2. Arquivo de Configuração
Edite `.github/config/deploy-config.json` com seus recursos AWS:

```json
{
    "ecr": {
      "repository": "vendor/sim",
      "region": "sa-east-1"
    },
    "ecs": {
      "cluster": "SIM-Cluster",
      "service": "sim-task-definition-service",
      "taskDefinition": "sim-task-definition",
      "containerName": "sim"
    }
}
```

#### Onde encontrar esses valores:
- **ECR Repository**: AWS Console → ECR → Repositories
- **ECS Cluster**: AWS Console → ECS → Clusters  
- **ECS Service**: AWS Console → ECS → Clusters → [seu-cluster] → Services
- **Task Definition**: AWS Console → ECS → Task Definitions
- **Container Name**: Nome do container na task definition (geralmente igual ao repository)

### 3. Permissões IAM
Crie um usuário IAM no AWS com as seguintes permissões:

#### Policy JSON (Recomendada):
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "ecr:GetAuthorizationToken",
                "ecr:BatchCheckLayerAvailability",
                "ecr:GetDownloadUrlForLayer",
                "ecr:BatchGetImage",
                "ecr:PutImage",
                "ecr:InitiateLayerUpload",
                "ecr:UploadLayerPart",
                "ecr:CompleteLayerUpload",
                "ecr:DescribeImages"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "ecs:DescribeTaskDefinition",
                "ecs:RegisterTaskDefinition",
                "ecs:UpdateService",
                "ecs:DescribeServices"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "sts:GetCallerIdentity"
            ],
            "Resource": "*"
        }
    ]
}
```

#### Como Criar o Usuário:
1. AWS Console → **IAM** → **Users** → **Create user**
2. Nome: `mp-github-actions-deploy`
3. **Attach policies directly** → **Create policy** → Cole o JSON acima
4. **Create user** e anote as credenciais
5. Use as credenciais nos secrets do GitHub

## 🐛 Troubleshooting

### Logs do Deploy
Os logs detalhados estão disponíveis na aba **Actions** do GitHub.

### Teste Local dos Scripts
```bash
# Tornar executáveis
chmod +x .github/scripts/*.sh

# Testar individualmente
.github/scripts/version-manager.sh
```

### Verificar Configuração
```bash
# Validar JSON
jq '.' .github/config/deploy-config.json
```

## 📚 Recursos Úteis

- [AWS ECS Console](https://console.aws.amazon.com/ecs/)
- [AWS ECR Console](https://console.aws.amazon.com/ecr/)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)