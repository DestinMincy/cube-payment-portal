#!/bin/bash
# Generate self-signed SSL certificate for local development

# Create ssl directory if it doesn't exist
mkdir -p /etc/apache2/ssl

# Generate private key and certificate
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/apache2/ssl/localhost.key \
    -out /etc/apache2/ssl/localhost.crt \
    -subj "/C=US/ST=Dev/L=Local/O=Dev/CN=localhost"

# Enable SSL module and site
a2enmod ssl
a2enmod rewrite

# Create SSL virtual host configuration
cat > /etc/apache2/sites-available/default-ssl.conf << 'EOF'
<IfModule mod_ssl.c>
    <VirtualHost *:443>
        ServerAdmin webmaster@localhost
        DocumentRoot /var/www/html
        
        SSLEngine on
        SSLCertificateFile /etc/apache2/ssl/localhost.crt
        SSLCertificateKeyFile /etc/apache2/ssl/localhost.key
        
        <Directory /var/www/html>
            Options Indexes FollowSymLinks
            AllowOverride All
            Require all granted
        </Directory>
        
        ErrorLog ${APACHE_LOG_DIR}/error.log
        CustomLog ${APACHE_LOG_DIR}/access.log combined
    </VirtualHost>
</IfModule>
EOF

# Enable the SSL site
a2ensite default-ssl

echo "SSL certificate generated and Apache configured for HTTPS"
