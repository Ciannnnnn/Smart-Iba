FROM dunglas/frankenphp:latest-bookworm

# Install MySQL PDO driver
RUN install-php-extensions pdo_mysql

# Copy application files
COPY . /app

# Set working directory
WORKDIR /app

# Expose port
EXPOSE 80

# Start FrankenPHP (correct syntax - no --bind flag)
CMD ["frankenphp", "run"]

