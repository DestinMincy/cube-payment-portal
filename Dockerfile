# WordPress with PHP 8.2 + SSL for Cube Payment Portal Development
FROM wordpress:6.4-php8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    openssl \
    unzip \
    git \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod ssl rewrite headers

# Generate self-signed SSL certificate
RUN mkdir -p /etc/apache2/ssl && \
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/apache2/ssl/localhost.key \
    -out /etc/apache2/ssl/localhost.crt \
    -subj "/C=US/ST=Dev/L=Local/O=Dev/CN=localhost"

# Create SSL Apache config
RUN echo '<IfModule mod_ssl.c>\n\
    <VirtualHost *:443>\n\
    ServerAdmin webmaster@localhost\n\
    DocumentRoot /var/www/html\n\
    SSLEngine on\n\
    SSLCertificateFile /etc/apache2/ssl/localhost.crt\n\
    SSLCertificateKeyFile /etc/apache2/ssl/localhost.key\n\
    <Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
    </VirtualHost>\n\
    </IfModule>' > /etc/apache2/sites-available/default-ssl.conf

# Enable SSL site
RUN a2ensite default-ssl

# Configure PHP for development
RUN echo "display_errors = On\nerror_reporting = E_ALL\nlog_errors = On" > /usr/local/etc/php/conf.d/dev.ini

# Copy custom entrypoint
COPY docker-entrypoint-custom.sh /usr/local/bin/docker-entrypoint-custom.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-custom.sh

# Expose both HTTP and HTTPS
EXPOSE 80 443

ENTRYPOINT ["docker-entrypoint-custom.sh"]
