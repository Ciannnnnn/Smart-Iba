FROM dunglas/frankenphp:latest-bookworm

# Install MySQL PDO driver
RUN install-php-extensions pdo_mysql

# Copy application files
COPY . /app

# Set working directory
WORKDIR /app

# Expose port
EXPOSE 80

# Start FrankenPHP
CMD ["frankenphp", "run", "--bind", "0.0.0.0:80"]

