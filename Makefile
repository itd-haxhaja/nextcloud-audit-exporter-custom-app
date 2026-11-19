SHELL := /bin/bash

APP_ID     := audit_export
DIST_DIR   := dist
BUILD_FILE := $(DIST_DIR)/$(APP_ID).tar.gz

# Compose project + host port: run several stacks side by side, e.g.
#   make test-integration NC_IMAGE=nextcloud:34 PROJECT=nc34 NC_PORT=8084
PROJECT    ?= audit-export-test
NC_PORT    ?= 8080

# Path to a running Nextcloud instance for integration tests (override via env)
NC_URL     ?= http://localhost:$(NC_PORT)
NC_USER    ?= admin
NC_PASS    ?= adminpassword

# Compose command: docker compose when docker exists, podman compose otherwise
COMPOSE_BIN  ?= $(shell command -v docker >/dev/null 2>&1 && echo "docker compose" || echo "podman compose")
COMPOSE      := $(COMPOSE_BIN) -p $(PROJECT)
COMPOSE_FILE := tests/docker/docker-compose.yml

# Nextcloud image for the test stack (default = the production major). Supported range: see appinfo/info.xml.
NC_IMAGE     ?= nextcloud:32

# Path to the Python exporter repo (for the exporter end-to-end targets)
EXPORTER_DIR ?= ../../nextcloud-exporter/audit.nextcloud-exporter

.DEFAULT_GOAL := help

.PHONY: help install lint test test-integration build clean start-nc stop-nc wait-nc seed \
        test-exporter test-exporter-noapp

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies (dev)
	composer install --no-interaction

lint: ## Syntax-check every PHP file
	@for f in $$(find appinfo lib templates tests -name '*.php'); do php -l $$f >/dev/null || exit 1; done; echo "lint ok"

test: install ## Run PHPUnit unit tests
	./vendor/bin/phpunit --configuration phpunit.xml

test-integration: install start-nc wait-nc seed ## Start Docker Nextcloud, seed it, run the integration suite, tear down
	NC_URL=$(NC_URL) NC_USER=$(NC_USER) NC_PASS=$(NC_PASS) \
		./vendor/bin/phpunit --configuration phpunit.integration.xml
	$(MAKE) stop-nc

start-nc: ## Start the Docker Nextcloud instance (app repo mounted as custom app)
	@if $(COMPOSE_BIN) ls --format '{{.Name}}' 2>/dev/null | grep -qx '$(PROJECT)'; then :; \
	elif podman volume ls --format '{{.Name}}' 2>/dev/null | grep -qx '$(PROJECT)_db_data'; then \
	  echo "WARNING: volume $(PROJECT)_db_data exists from an earlier run — run 'make stop-nc PROJECT=$(PROJECT)' first when switching NC_IMAGE"; fi
	NC_PORT=$(NC_PORT) NC_IMAGE=$(NC_IMAGE) $(COMPOSE) -f $(COMPOSE_FILE) up -d
	@echo "Nextcloud ($(NC_IMAGE)) starting at $(NC_URL)..."

wait-nc: ## Wait until the Nextcloud instance answers on NC_URL
	@echo "Waiting for Nextcloud to become ready..."
	@timeout 240 bash -c 'until curl -sf $(NC_URL)/status.php | grep -q installed; do sleep 3; done' \
	  || { echo "Nextcloud did not come up — container log:"; $(COMPOSE) -f $(COMPOSE_FILE) logs --tail 30 nextcloud; exit 1; }
	@echo "Nextcloud is up."

seed: ## Seed the running instance (10 users, 5 groups, 50 shares, 2 group folders, 1 ext mount)
	NC_URL=$(NC_URL) NC_USER=$(NC_USER) NC_PASS=$(NC_PASS) COMPOSE="$(COMPOSE)" \
		tests/docker/seed.sh

stop-nc: ## Stop and remove the Docker Nextcloud instance (incl. the DB volume)
	NC_PORT=$(NC_PORT) NC_IMAGE=$(NC_IMAGE) $(COMPOSE) -f $(COMPOSE_FILE) down -v

test-exporter: ## E2E: fresh Nextcloud WITH the app, seed, run the Python exporter, verify CSVs, tear down
	-$(MAKE) stop-nc
	$(MAKE) start-nc wait-nc seed
	MODE=app NC_URL=$(NC_URL) NC_USER=$(NC_USER) NC_PASS=$(NC_PASS) EXPORTER_DIR=$(EXPORTER_DIR) \
		tests/exporter/exporter_e2e.sh
	$(MAKE) stop-nc

test-exporter-noapp: ## E2E: fresh vanilla Nextcloud WITHOUT the app, seed, verify the exporter's partial mode, tear down
	-$(MAKE) stop-nc
	$(MAKE) start-nc wait-nc NC_IMAGE=$(NC_IMAGE)
	SEED_WITH_APP=0 NC_URL=$(NC_URL) NC_USER=$(NC_USER) NC_PASS=$(NC_PASS) COMPOSE="$(COMPOSE)" \
		tests/docker/seed.sh
	MODE=noapp NC_URL=$(NC_URL) NC_USER=$(NC_USER) NC_PASS=$(NC_PASS) EXPORTER_DIR=$(EXPORTER_DIR) \
		tests/exporter/exporter_e2e.sh
	$(MAKE) stop-nc

build: ## Build the distributable .tar.gz (everything minus the patterns in .dockerignore)
	rm -rf $(DIST_DIR)/$(APP_ID) $(BUILD_FILE)
	mkdir -p $(DIST_DIR)/$(APP_ID)
	rsync -a --exclude-from=.dockerignore ./ $(DIST_DIR)/$(APP_ID)/
	tar -C $(DIST_DIR) -czf $(BUILD_FILE) $(APP_ID)
	@echo "Built: $(BUILD_FILE) ($$(du -h $(BUILD_FILE) | cut -f1))"; tar -tzf $(BUILD_FILE) | grep -E '^[^/]+/[^/]+/?$$'

clean: ## Remove build artefacts
	rm -rf $(DIST_DIR) .phpunit.result.cache .phpunit.integration.result.cache
	rm -rf vendor
