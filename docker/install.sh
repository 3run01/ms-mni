 ### instalar o ghostscript
 apt-get update && apt-get install -y \
    ghostscript \
    && rm -rf /var/lib/apt/lists/*

### instalar o intl
docker-php-ext-install intl

### instalar o soap
docker-php-ext-install soap

### instalar o sockets
docker-php-ext-install sockets

### instalar bibiliotecas para o funcionamento do snappy-pdf
apt-get install libxrender1 libxext-dev

# Instalar NVM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash

# Carregar NVM imediatamente
export NVM_DIR="$([ -z "${XDG_CONFIG_HOME-}" ] && printf %s "${HOME}/.nvm" || printf %s "${XDG_CONFIG_HOME}/nvm")"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

# Garantir que o NVM está disponível no shell atual
source ~/.bashrc

# Instalar Node.js v22 e definir como padrão
nvm install 22
nvm use 22
nvm alias default 22

# Verificar instalação
echo "Node version: $(node --version)"
echo "NPM version: $(npm --version)"

# Aguardar um momento para garantir que o npm está disponível
sleep 2

### instalar pm2
npm install -g pm2 || {
    echo "Erro ao instalar PM2. Tentando novamente..."
    # Segunda tentativa após recarregar o PATH
    export PATH="$NVM_DIR/versions/node/v22/bin:$PATH"
    npm install -g pm2
}

npm install --save-dev chokidar

# Verificar instalação do PM2
echo "PM2 version: $(pm2 --version)"
