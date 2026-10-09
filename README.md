## __PHP educational project with Docker__

This project is a PHP application running in a Docker-based development environment. Docker provides a consistent setup for running the application and its dependencies. 

## Docker Images are used:
- [mysql:8.0.39](https://hub.docker.com/_/mysql)
- [httpd:2.4.69-alpine3.24](https://hub.docker.com/_/httpd)
- [php:8.3.12-fpm-alpine3.20](https://hub.docker.com/_/php)
- [phpmyadmin:5.2.3-apache](https://hub.docker.com/_/phpmyadmin)
- [redis:8.10.2-alpine3.23](https://hub.docker.com/_/redis)
Note: redis runs separatly as shared service and connects to redis occurs via external network.

php:8.3-fpm-alpine also includes: composer, git, unzip, php-xdebug, pdo_mysql redis extension.

## Project Structure

```text
.
├── build/                                  # Docker files | application image definition
    ├── httpd-2.4-alpine/
    ├── mysql-8/
    ├── php-8.3-fpm/
    ├── phpmyadmin-5.2.3-apache/       
├── etc/                                    # Configuration files
├── .env.example                            # Excample of environment definitions
├── src/                                    # Application source code
    └── index.php                           # Application entry point
├── docker-compose.yml                      # Local services and configuration
├── docker-compose.shared-infra.yml         # Shared services and configuration
└── README.md
```

The structure may change as the project grows. Keep application code separate from Docker and configuration files.

## Requirements

- Docker Engine 20.10 or later
- Docker Compose v2 or later

Build and start the containers in the background:

```bash
docker-compose -f docker-compose.shared-infra.yml up -d --build && docker-compose up -d --build
```

Open the application at [http://localhost:8080](http://localhost:8080). If the project exposes a different port, use the port defined in `docker-compose.yml`.

## Common Commands

```bash
# Start services in the background
docker-compose -f docker-compose.shared-infra.yml up -d && docker-compose up -d

# Start only app`s containers in the background
docker-compose up -d
 
# View service logs
docker-compose logs -f

# Open a shell in the application container
docker-compose exec app sh

# Stop and remove app containers
docker-compose down

# Stop and remove all containers
docker-compose  down && docker-compose -f docker-compose.shared-infra.yml down

# Rebuild images without using the cache
docker-compose -f docker-compose.shared-infra.yml build --no-cache && docker-compose build --no-cache
```

## Configuration

Create and edit a local `.env` file in project directory for environment variables and never commit secrets or credentials.  Update the port mapping in `docker-compose.yml` if port `8080` is already in use.

## Development

Application files can be mounted into the container for live development from src/. After changing the `Dockerfile` or installed dependencies, rebuild the image:

```bash
docker-compose -f docker-compose.shared-infra.yml up -d --build && docker-compose up -d --build
```

To remove containers, images, associated volumes and networks, use the following command:

```bash
docker-compose  down -v --rmi all && docker-compose -f docker-compose.shared-infra.yml down -v --rmi all 
```
