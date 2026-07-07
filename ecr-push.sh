#!/bin/bash
set -euo pipefail

AWS_REGION="sa-east-1"
AWS_ACCOUNT_ID="019701076927"
REPO="vendor/sim-mni"
IMAGE="$AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/$REPO:latest"

# Credentials are NOT hardcoded. Provide them via one of:
#   - `aws configure` (default or named profile)
#   - environment variables exported before running:
#       export AWS_ACCESS_KEY_ID=...
#       export AWS_SECRET_ACCESS_KEY=...
#       export AWS_SESSION_TOKEN=...   # only for temporary/STS credentials
#   - an EC2/ECS instance role (no config needed)

aws ecr get-login-password --region $AWS_REGION | docker login --username AWS --password-stdin $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com
docker build -f ./docker/Dockerfile.aws.prod -t $REPO . --no-cache
docker tag $REPO:latest $IMAGE
docker push $IMAGE
