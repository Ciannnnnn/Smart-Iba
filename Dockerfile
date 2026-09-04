FROM dunglas/frankenphp:latest-bookworm

# Install MySQL PDO driver
RUN install-php-extensions pdo_mysql

# Copy application files
COPY . /app

# Copy Caddyfile for FrankenPHP configuration
COPY Caddyfile /app/Caddyfile

# Set working directory
WORKDIR /app

# Expose port
EXPOSE 80

# Start FrankenPHP with Caddyfile
CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]

