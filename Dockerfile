# Usa a imagem oficial do PHP com Apache
FROM php:8.2-apache

# Instala as dependências do sistema necessárias para o PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev

# Instala as extensões do PHP: PDO, PDO_PGSQL (para o banco) e CURL (para o Mercado Pago)
RUN docker-php-ext-install pdo pdo_pgsql

# Habilita o mod_rewrite do Apache (opcional, mas boa prática)
RUN a2enmod rewrite

# Copia os arquivos do seu projeto para a pasta pública do Apache
COPY . /var/www/html/

# Define a porta 80 como padrão (O Render vai detectar isso)
EXPOSE 80
