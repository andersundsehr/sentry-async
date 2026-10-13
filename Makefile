SHELL := /bin/bash
.DEFAULT_GOAL := ci

ACT_IMAGE ?= efrecon/act:v0.2.89
WORKDIR ?= /work
UID_GID := $(shell id -u):$(shell id -g)
DOCKER_SOCKET ?= /var/run/docker.sock
DOCKER_GID = $(shell stat -c '%g' "$(DOCKER_SOCKET)")
PLATFORM ?= -P ubuntu-latest=ghcr.io/catthehacker/ubuntu:act-24.04
SECRETS ?= $(if $(wildcard .secrets),--secret-file .secrets,)
ACT_ARGS ?=

define DOCKER_RUN
docker run --rm -i \
  -u $(UID_GID) \
  --group-add $(DOCKER_GID) \
  -e HOME=/home/act \
  -e XDG_CACHE_HOME=/home/act/.cache \
  -v "$(DOCKER_SOCKET):/var/run/docker.sock" \
  -v "$(CURDIR):$(WORKDIR)" -w "$(WORKDIR)" \
  -v "$$HOME/.cache:/home/act/.cache" \
  $(ACT_IMAGE)
endef

.PHONY: all ci

all: ci

ci: ## Run the GitHub Actions PHP/Symfony matrix locally.
	@status=0; \
	output="$$($(DOCKER_RUN) push --workflows .github/workflows/tasks.yml \
	  --action-cache-path /home/act/.cache/act --rm \
	  $(PLATFORM) $(SECRETS) $(ACT_ARGS) 2>&1 | tee /dev/stderr; \
	  printf '\n__ACT_STATUS__=%s' "$${PIPESTATUS[0]}")"; \
	status="$${output##*__ACT_STATUS__=}"; \
	output="$${output%__ACT_STATUS__=*}"; \
	echo; \
	echo "=== Summary ==="; \
	if [ "$$status" -eq 0 ]; then \
	  echo "No matrix failures detected."; \
	else \
	  printf '%s\n' "$$output" | awk 'tolower($$0) ~ /(failure|failed|exitcode|exit code|job failed|error: process completed)/ { line=$$0; gsub(/\033\[[0-9;]*[[:alpha:]]/, "", line); if (match(line, /\[[^]]+\]/)) { job=substr(line, RSTART + 1, RLENGTH - 2); sub(/^[^/]+\//, "", job); sub(/[[:space:]]+$$/, "", job); sub(/-[0-9]+$$/, "", job); seen[job]=1; found=1 } } END { for (job in seen) print "- " job; if (!found) exit 1 }' \
	    || echo "No act failure marker found; see log above."; \
	fi; \
	exit "$$status"
