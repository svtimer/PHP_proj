## __PHP educational project with Docker__

This project is a PHP application running in a Docker-based development environment. Docker provides a consistent setup for running the application and its dependencies.

## Docker Images are used:
- [mysql:8](https://hub.docker.com/_/mysql)
- [httpd:2.4](https://hub.docker.com/_/httpd)
- [php:8.3-fpm](https://hub.docker.com/_/php)
- [phpmyadmin:latest](https://hub.docker.com/_/phpmyadmin)

php:8.3-fpm also includes: composer, git, unzip, php-xdebug, pdo_mysql.

## Project Structure

```text
.
├── build                   # Docker files | application image definition
    ├── apache2-2.4/
    ├── mysql-8/
    ├── php-8.3-fpm/
    ├── phpmyadmin-latest/       
├── etc/                    # Configuration files
├── env/                    # Environment definitions
├── src/                    # Application source code
    └── index.php           # Application entry point
├── secrets/                # Sensitive data storage
├── volumes/                # Data storages
    ├── dbvol               # Mysql database storage
├── docker-compose.yml      # Local services and configuration
└── README.md
```

The structure may change as the project grows. Keep application code separate from Docker and configuration files.

## Requirements

- Docker Engine 20.10 or later
- Docker Compose v2 or later

## Getting Started

Clone the repository and open the project directory:

```bash
git clone https://github.com/svtimer/2PHP_proj
cd PHP_proj
```
Create files with passwords:

- ./secrets/mysql_db_pass
- ./secrets/mysql_root_pass

Build and start the containers:

```bash
docker-compose up --build
```

Open the application at [http://localhost:8080](http://localhost:8080). If the project exposes a different port, use the port defined in `docker-compose.yml`.

## Common Commands

```bash
# Start services in the background
docker-compose up -d

# View service logs
docker-compose logs -f

# Open a shell in the application container
docker-compose exec app sh

# Stop and remove containers
docker-compose down

# Rebuild images without using the cache
docker-compose build --no-cache
```

## Configuration

Use a local `.env` file for environment variables and never commit secrets or credentials.  Update the port mapping in `docker-compose.yml` if port `8080` is already in use.

## Development

Application files can be mounted into the container for live development from src/. After changing the `Dockerfile` or installed dependencies, rebuild the image:

```bash
docker-compose up --build
```

To remove containers and associated volumes, use the following command:

```bash
docker-compose down -v
```
