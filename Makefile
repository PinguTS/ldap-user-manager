# Makefile für ldap-user-manager
APP_NAME ?= ldap-user-manager
PLATFORMS ?= linux/amd64,linux/arm64

# 1. local.env
ifneq ("$(wildcard local.env)","")
include local.env
export
endif

# 2. git config
REGISTRY_HOST ?= $(shell git config --get registry.host)
PORTAINER_STACK_WEBHOOK ?= $(shell git config --get registry.webhook)


# 3. Umgebungsvariablen schon gesetzt behalten
# 4. Default nur wenn alles leer
REGISTRY_HOST ?= REGISTRY_HOST_FEHLT

LOCAL_REGISTRY ?= $(REGISTRY_HOST)
DOCKERHUB_USER ?= pinguts
DOCKERHUB_REPO ?= $(DOCKERHUB_USER)/$(APP_NAME)

BUILDKIT_CONFIG ?= .buildkit.toml
#BUILDKIT_CONFIG ?= .buildkit.$(shell echo $(REGISTRY_HOST) | tr : _).toml

GIT_BRANCH := $(shell git rev-parse --abbrev-ref HEAD)
GIT_SHA := $(shell git rev-parse --short HEAD)


VERSION ?= 0.0.0

.PHONY: builder
builder:
	@if [ ! -f "$(BUILDKIT_CONFIG)" ]; then \
	  echo '[registry."$(REGISTRY_HOST)"]' > $(BUILDKIT_CONFIG); \
	  echo '  http = true' >> $(BUILDKIT_CONFIG); \
	fi
	@docker buildx rm $(BUILDER_NAME) >/dev/null 2>&1 || true
	@docker buildx create --name $(BUILDER_NAME) --driver docker-container --config "$(BUILDKIT_CONFIG)" --use
	@docker buildx inspect --bootstrap >/dev/null


# Dev Branch in private Registry pushen
.PHONY: dev
dev: builder
	@if [ "$(GIT_BRANCH)" != "dev" ]; then \
		echo "Abbruch. Dieser Task ist nur für den dev Branch. Aktuell: $(GIT_BRANCH)"; exit 1; \
	fi
	docker buildx build \
		--platform $(PLATFORMS) \
		-t $(LOCAL_REGISTRY)/$(APP_NAME):dev \
		-t $(LOCAL_REGISTRY)/$(APP_NAME):dev-$(GIT_SHA) \
		--push \
		.

# Lokaler Test für amd64 ohne Push
.PHONY: load-amd64
load-amd64: builder
	docker buildx build --platform linux/amd64 -t $(APP_NAME):test --load .

# Release zu Docker Hub
.PHONY: release
release: builder
	@if [ "$(VERSION)" = "0.0.0" ]; then echo "Bitte VERSION=1.2.3 setzen"; exit 1; fi
	docker buildx build \
		--platform $(PLATFORMS) \
		-t $(DOCKERHUB_REPO):$(VERSION) \
		-t $(DOCKERHUB_REPO):latest \
		--push \
		.

# Prüfen der aktuell berechneten Tags
.PHONY: echo
echo:
	@echo "Branch: $(GIT_BRANCH)"
	@echo "SHA: $(GIT_SHA)"
	@echo "Local dev tags: $(LOCAL_REGISTRY)/$(APP_NAME):dev und dev-$(GIT_SHA)"
	@echo "Docker Hub repo: $(DOCKERHUB_REPO)"

.PHONY: check
check:
	@echo "REGISTRY_HOST=$(REGISTRY_HOST)"
	@echo "REGISTRY_USER=$(REGISTRY_USER)"

.PHONY: builder
builder:
	@docker buildx inspect mybuilder >/dev/null 2>&1 || \
	  docker buildx create --name mybuilder --driver docker-container --config $(BUILDKIT_CONFIG) --use
	@docker buildx use mybuilder
	@docker buildx inspect --bootstrap >/dev/null

.PHONY: notify
notify:
	@curl -fsSL -X POST "$(PORTAINER_STACK_WEBHOOK)"
