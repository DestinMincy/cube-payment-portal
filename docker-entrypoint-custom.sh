#!/bin/bash
set -e

# Wait for database
echo "Waiting for database connection..."
until mysql -h"db" -u"wordpress" -p"wordpress" -e "SELECT 1" &> /dev/null; do
    sleep 2
done
echo "Database is ready!"

# Run the original WordPress entrypoint
docker-entrypoint.sh apache2-foreground &

# Wait for WordPress to be installed
sleep 10

# Check if WordPress is installed
if ! wp core is-installed --allow-root 2>/dev/null; then
    echo "Installing WordPress..."
    
    wp core install \
        --url="http://localhost:8080" \
        --title="Cube Payment Portal Dev" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="admin@example.com" \
        --skip-email \
        --allow-root

    echo "WordPress installed!"

    # Activate the plugin
    wp plugin activate cube-payment-portal --allow-root 2>/dev/null || echo "Plugin not yet available"
    
    # Install WooCommerce for integration testing
    wp plugin install woocommerce --activate --allow-root
    
    echo "Development environment ready!"
fi

# Keep container running
wait
