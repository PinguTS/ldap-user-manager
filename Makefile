# Makefile for ldap-user-manager
# This file contains build targets for Docker images and code quality tools

# =============================================================================
# Configuration
# =============================================================================

# Application name
APP_NAME ?= ldap-user-manager

# Docker registry configuration
REGISTRY_HOST ?= $(shell git config --get registry.host)
REGISTRY_USER ?= $(shell git config --get registry.user)
LOCAL_REGISTRY := $(REGISTRY_HOST)

# Docker Hub repository
DOCKERHUB_USER ?= $(shell git config --get dockerhub.user)
DOCKERHUB_REPO ?= $(DOCKERHUB_USER)/$(APP_NAME)

# GitHub repository
GITHUB_USER ?= $(shell git config --get github.user)
GITHUB_REPO ?= $(GITHUB_USER)/$(APP_NAME)

# Git information
DATE_UTC := $(shell date -u +%Y-%m-%dT%H:%M:%SZ)
GIT_SHA := $(shell git rev-parse --short HEAD)
GIT_BRANCH := $(shell git rev-parse --abbrev-ref HEAD)
GIT_REV  ?= $(GIT_SHA)
GIT_URL  ?= $(GITHUB_REPO)


# Version (set via VERSION=1.2.3)
VERSION ?= 0.0.0

# Build platforms
PLATFORMS ?= linux/amd64,linux/arm64

# BuildKit configuration
BUILDKIT_CONFIG := .buildkit.toml
BUILDER_NAME ?= $(APP_NAME)-builder

# Portainer webhook for notifications
PORTAINER_STACK_WEBHOOK ?= $(shell git config --get registry.webhook)

# =============================================================================
# Docker Compose Commands
# =============================================================================

# Start all services
.PHONY: up
up:
	docker-compose up -d

# Stop all services
.PHONY: down
down:
	docker-compose down

# View logs
.PHONY: logs
logs:
	docker-compose logs -f

# Restart services
.PHONY: restart
restart:
	docker-compose restart

# Check service status
.PHONY: status
status:
	docker-compose ps

# Validate configuration
.PHONY: validate
validate:
	@echo "Validating Docker Compose configuration..."
	docker-compose config
	@echo "Configuration is valid!"

# Clean up volumes and networks
#.PHONY: clean
#clean:
#	docker-compose down -v --remove-orphans

# =============================================================================
# Docker Build Commands
# =============================================================================

# Set up Docker buildx builder
.PHONY: builder
builder:
	@docker buildx inspect $(BUILDER_NAME) >/dev/null 2>&1 || \
		docker buildx create --name $(BUILDER_NAME) --driver docker-container --config "$(BUILDKIT_CONFIG)" --use
	@docker buildx use $(BUILDER_NAME)
	@docker buildx inspect --bootstrap >/dev/null

# Build and push dev version to private registry
.PHONY: dev
dev: builder
	@if [ "$(GIT_BRANCH)" != "dev" ]; then \
		echo "Aborted. This task is only for the dev branch. Current: $(GIT_BRANCH)"; exit 1; \
	fi
	docker buildx build \
		--platform $(PLATFORMS) \
		-t $(LOCAL_REGISTRY)/$(APP_NAME):dev \
		-t $(LOCAL_REGISTRY)/$(APP_NAME):dev-$(GIT_SHA) \
		--label org.opencontainers.image.created=$(DATE_UTC) \
		--label org.opencontainers.image.revision=$(GIT_REV) \
		--label org.opencontainers.image.source=$(GIT_URL) \
		--label org.opencontainers.image.version=dev-$(GIT_SHA) \
		--output=type=image,name=$(LOCAL_REGISTRY)/$(APP_NAME):dev,push=true,oci-mediatypes=false \
		--provenance=false \
		--push \
		.

# Local test for amd64 without push
.PHONY: load-amd64
load-amd64: builder
	docker buildx build --platform linux/amd64 -t $(APP_NAME):test --load .

# Release to Docker Hub
.PHONY: release
release: builder
	@if [ "$(VERSION)" = "0.0.0" ]; then echo "Please set VERSION=1.2.3"; exit 1; fi
	docker buildx build \
		--platform $(PLATFORMS) \
		-t $(DOCKERHUB_REPO):$(VERSION) \
		-t $(DOCKERHUB_REPO):latest \
		--push \
		.

# Show currently calculated tags
.PHONY: echo
echo:
	@echo "Branch: $(GIT_BRANCH)"
	@echo "SHA: $(GIT_SHA)"
	@echo "Local dev tags: $(LOCAL_REGISTRY)/$(APP_NAME):dev and dev-$(GIT_SHA)"
	@echo "Docker Hub repo: $(DOCKERHUB_REPO)"

.PHONY: check
check:
	@echo "REGISTRY_HOST=$(REGISTRY_HOST)"
	@echo "REGISTRY_USER=$(REGISTRY_USER)"

.PHONY: notify
notify:
	@curl -fsSL -X POST "$(PORTAINER_STACK_WEBHOOK)"

# =============================================================================
# Code Quality Commands (New additions)
# =============================================================================

.PHONY: help install test cs cs-fix stan fix rector clean docker-build docker-run docker-stop

help: ## Show this help message
	@echo "LDAP User Manager - Available Commands:"
	@echo ""
	@echo "Docker Build Commands:"
	@echo "  dev          Build and push dev version to local registry (dev branch only)"
	@echo "  release      Build and push release to Docker Hub (requires VERSION=x.x.x)"
	@echo "  load-amd64   Build local test version for amd64"
	@echo "  builder      Set up Docker buildx builder"
	@echo "  echo         Show current git branch and registry info"
	@echo "  check        Show registry configuration"
	@echo "  notify       Send notification to Portainer webhook"
	@echo ""
	@echo "Code Quality Commands:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | grep -v "help:" | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies
	composer install

test: ## Run tests (if available)
	@if [ -f "vendor/bin/phpunit" ]; then \
		vendor/bin/phpunit; \
	else \
		echo "PHPUnit not available. Run 'make install' first."; \
	fi

cs: ## Check coding standards with PHPCS
	@if [ -f "vendor/bin/phpcs" ]; then \
		vendor/bin/phpcs --standard=.phpcs.xml; \
	else \
		echo "PHPCS not available. Run 'make install' first."; \
	fi

cs-fix: ## Auto-fix coding standards with PHPCBF
	@if [ -f "vendor/bin/phpcbf" ]; then \
		vendor/bin/phpcbf --standard=.phpcs.xml; \
	else \
		echo "PHPCBF not available. Run 'make install' first."; \
	fi

stan: ## Run static analysis with PHPStan
	@if [ -f "vendor/bin/phpstan" ]; then \
		vendor/bin/phpstan analyse --configuration=phpstan.neon; \
	else \
		echo "PHPStan not available. Run 'make install' first."; \
	fi

fix: ## Auto-fix code style with PHP-CS-Fixer
	@if [ -f "vendor/bin/php-cs-fixer" ]; then \
		vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php; \
	else \
		echo "PHP-CS-Fixer not available. Run 'make install' first."; \
	fi

rector: ## Run Rector for code modernization (dry-run)
	@if [ -f "vendor/bin/rector" ]; then \
		vendor/bin/rector process www/ --dry-run; \
	else \
		echo "Rector not available. Run 'make install' first."; \
	fi

quality: ## Run all quality checks
	@echo "Running all quality checks..."
	@make cs
	@make stan

fix-all: ## Fix all code style issues
	@echo "Fixing all code style issues..."
	@make fix
	@make cs-fix

clean: ## Clean up generated files
	rm -f .php-cs-fixer.cache
	rm -rf vendor/
	rm -f composer.lock
	docker-compose down -v --remove-orphans

# Docker commands (OIDC setup)
docker-build: ## Build Docker containers
	docker-compose build

docker-run: ## Start Docker containers
	docker-compose up -d

docker-stop: ## Stop Docker containers
	docker-compose down

docker-logs: ## Show Docker logs
	docker-compose logs -f

# Development setup
setup-dev: ## Set up development environment
	@echo "Setting up development environment..."
	@make install
	@echo "Development environment ready!"
	@echo "Run 'make quality' to check code quality"
	@echo "Run 'make fix-all' to fix code style issues"

# Quick quality check
quick-check: ## Quick quality check (CS only)
	@make cs

# Full quality check with fixes
full-check: ## Full quality check and auto-fix
	@make quality
	@make fix-all
	@make quality
