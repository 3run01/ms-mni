#!/bin/bash
set -e

# Função para extrair versão do commit message
extract_version_from_commit() {
    local commit_msg="$1"
    # Procura por padrão [tag:vX.X.X] no commit message
    if echo "$commit_msg" | grep -qE '\[tag:v[0-9]+\.[0-9]+\.[0-9]+\]'; then
        echo "$commit_msg" | sed -n 's/.*\[tag:\(v[0-9]\+\.[0-9]\+\.[0-9]\+\)\].*/\1/p'
        return 0
    fi
    return 1
}

# Função para verificar se existe arquivo de override
check_version_override() {
    if [ -f ".version-override" ]; then
        local override_version=$(cat .version-override | tr -d '[:space:]')
        if [[ $override_version =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo "$override_version"
            # Remove o arquivo após usar
            rm -f .version-override
            return 0
        fi
    fi
    return 1
}

# Função para incrementar versão patch
increment_patch_version() {
    local version="$1"
    # Remove o 'v' prefix se existir
    version=${version#v}
    
    # Split version into array
    IFS='.' read -ra VERSION_PARTS <<< "$version"
    local major=${VERSION_PARTS[0]}
    local minor=${VERSION_PARTS[1]}
    local patch=${VERSION_PARTS[2]}
    
    # Increment patch
    patch=$((patch + 1))
    
    echo "v${major}.${minor}.${patch}"
}

# Função para obter a última tag
get_latest_tag() {
    # Pega todas as tags que seguem o padrão vX.X.X
    local latest_tag=$(git tag -l "v*.*.*" | sort -V | tail -n1)
    
    if [ -z "$latest_tag" ]; then
        echo "v0.0.0"
    else
        echo "$latest_tag"
    fi
}

# Função principal
main() {
    echo "🏷️  Determining version..." >&2
    
    # 1. Verificar se há override em arquivo
    if version=$(check_version_override); then
        echo "📁 Using version from override file: $version" >&2
        echo "$version"
        return 0
    fi
    
    # 2. Verificar se há tag especificada no commit message
    local commit_msg=$(git log -1 --pretty=%B)
    if version=$(extract_version_from_commit "$commit_msg"); then
        echo "💬 Using version from commit message: $version" >&2
        echo "$version"
        return 0
    fi
    
    # 3. Auto-incrementar versão patch
    local latest_tag=$(get_latest_tag)
    local new_version=$(increment_patch_version "$latest_tag")
    
    echo "🔄 Auto-incrementing from $latest_tag to $new_version" >&2
    
    # Criar a nova tag
    git config user.name "GitHub Actions"
    git config user.email "actions@github.com"
    git tag -a "$new_version" -m "Auto-generated version $new_version"
    git push origin "$new_version" >&2
    
    echo "✅ Created and pushed new tag: $new_version" >&2
    echo "$new_version"
}

# Executar função principal
main