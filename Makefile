# ============================================================
# Carbon Tracker: perintah operasional
#   Server (Docker, lihat docs/DEPLOY.md): setup, deploy, backup, restore, logs, ...
#   Development (host/Laragon): test, lint, analyse, ci
# HTTPS: isi COMPOSE_FILE=compose.yml:compose.tls.yml di .env (dibaca docker compose).
# ============================================================

SHELL := bash
.SHELLFLAGS := -eu -o pipefail -c
MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

.DEFAULT_GOAL := help

# ----- Konfigurasi -----

COMPOSE        ?= docker compose
FRONTEND_PATH  ?= ./carbon-tracker-frontend
FRONTEND_REPO  ?= https://github.com/arianto-sketch/carbon-tracker-frontend.git
BACKUP_PATH    ?= ./backups

# exec tanpa TTY bila tidak dijalankan dari terminal (script, cron, CI)
EXEC_TTY := $(shell [ -t 0 ] || echo -T)

# Parameter target (diisi lewat command line, mis. make restore FILE=...)
EMAIL   ?=
NAME    ?= Administrator
FILE    ?=
SERVICE ?=
CONFIRM ?=

# Warna dimatikan di CI atau bila output bukan terminal
ifeq ($(origin CI),undefined)
	BLUE := \033[34m
	GREEN := \033[32m
	YELLOW := \033[33m
	RED := \033[31m
	RESET := \033[0m
else
	BLUE :=
	GREEN :=
	YELLOW :=
	RED :=
	RESET :=
endif

.PHONY: help setup frontend key pull build up down restart ps logs shell tinker \
        migrate seed-master create-admin deploy \
        backup backups restore restore-storage \
        compose-check test lint format analyse ci

# ----- Bantuan -----

help: ## Tampilkan daftar perintah
	@echo ""
	@printf "$(BLUE)Carbon Tracker$(RESET)\n"
	@awk 'BEGIN {FS = ":.*?## "} \
		/^[a-zA-Z_-]+:.*?## / {printf "  $(GREEN)%-18s$(RESET) %s\n", $$1, $$2} \
		/^# ----- / {printf "\n$(YELLOW)%s$(RESET)\n", substr($$0, 9, length($$0) - 14)}' $(MAKEFILE_LIST)
	@echo ""

# ----- Setup server (sekali) -----

.env:
	@cp .env.production.example .env
	@chmod 600 .env
	@printf "$(YELLOW)→ .env dibuat dari .env.production.example. Isi nilai <...>, lalu jalankan: make key$(RESET)\n"
	@exit 1

$(FRONTEND_PATH)/package.json:
	git clone $(FRONTEND_REPO) $(FRONTEND_PATH)

frontend: $(FRONTEND_PATH)/package.json ## Clone repo frontend bila belum ada

key: ## Buat APP_KEY baru (salin hasilnya ke .env)
	@echo "APP_KEY=base64:$$(openssl rand -base64 32)"

setup: .env frontend build migrate up ## Instalasi pertama: build, migrate, start semua service
	@printf "$(GREEN)✓ Stack berjalan.$(RESET) Berikutnya:\n"
	@printf "  1. make create-admin EMAIL=<email> NAME=\"Nama\"\n"
	@printf "  2. make seed-master   (kategori dan faktor emisi bawaan; butuh admin)\n"

# ----- Deploy -----

pull: ## Ambil kode terbaru backend dan frontend (fast-forward saja)
	git pull --ff-only
	git -C $(FRONTEND_PATH) pull --ff-only

build: frontend ## Build image api dan web
	$(COMPOSE) build

up: ## Start semua service dan tunggu sampai sehat
	$(COMPOSE) up -d --wait
	@printf "$(GREEN)✓ Semua service berjalan$(RESET)\n"

migrate: ## Jalankan migration dengan image terbaru (aman diulang)
	$(COMPOSE) up -d --wait mysql
	$(COMPOSE) run --rm -T app php artisan migrate --force

seed-master: ## Isi kategori dan faktor emisi bawaan (setelah create-admin; aman diulang)
	$(COMPOSE) run --rm -T app php artisan db:seed --class=EmissionCategorySeeder --force
	$(COMPOSE) run --rm -T app php artisan db:seed --class=EmissionFactorSeeder --force

deploy: pull build migrate up ## Rilis versi terbaru: pull, build, migrate, restart service
	@printf "$(GREEN)✓ Deploy selesai$(RESET)\n"

create-admin: ## Buat admin. Pakai: make create-admin EMAIL=<email> [NAME="Nama"]
	@if [ -z "$(EMAIL)" ]; then printf "$(RED)Pakai: make create-admin EMAIL=<email> NAME=\"Nama\"$(RESET)\n"; exit 1; fi
	$(COMPOSE) exec $(EXEC_TTY) app php artisan app:create-admin "$(EMAIL)" --name="$(NAME)"

# ----- Operasional -----

down: ## Hentikan semua service (data tetap ada)
	$(COMPOSE) down

restart: ## Restart service. Pakai: make restart [SERVICE=queue]
	$(COMPOSE) restart $(SERVICE)

ps: ## Status service dan healthcheck
	$(COMPOSE) ps

logs: ## Ikuti log. Pakai: make logs [SERVICE=app]
	$(COMPOSE) logs -f --tail=100 $(SERVICE)

shell: ## Shell di container app
	$(COMPOSE) exec $(EXEC_TTY) app sh

tinker: ## Laravel Tinker di container app
	$(COMPOSE) exec $(EXEC_TTY) app php artisan tinker

compose-check: ## Validasi konfigurasi compose (termasuk .env)
	$(COMPOSE) config --quiet
	@printf "$(GREEN)✓ Konfigurasi compose valid$(RESET)\n"

# ----- Backup -----

backup: ## Backup database dan lampiran sekarang (selain jadwal harian)
	$(COMPOSE) run --rm -T backup run

backups: ## Daftar file backup
	$(COMPOSE) run --rm -T backup list

restore: ## Pulihkan database. Pakai: make restore FILE=db-YYYYmmdd-HHMMSS.sql.gz
	@if [ -z "$(FILE)" ]; then printf "$(RED)Pakai: make restore FILE=<db-*.sql.gz> (lihat: make backups)$(RESET)\n"; exit 1; fi
	@if [ "$(CONFIRM)" != "YA" ]; then \
		read -r -p "Database akan ditimpa dengan $(FILE). Ketik YA untuk lanjut: " answer; \
		[ "$$answer" = "YA" ] || { echo "Dibatalkan."; exit 1; }; \
	fi
	$(COMPOSE) stop app queue scheduler
	$(COMPOSE) run --rm -T backup restore "$(FILE)"
	$(COMPOSE) up -d --wait app queue scheduler
	@printf "$(GREEN)✓ Database dipulihkan dari $(FILE)$(RESET)\n"

restore-storage: ## Pulihkan lampiran. Pakai: make restore-storage FILE=storage-YYYYmmdd-HHMMSS.tar.gz
	@if [ -z "$(FILE)" ]; then printf "$(RED)Pakai: make restore-storage FILE=<storage-*.tar.gz>$(RESET)\n"; exit 1; fi
	@test -f "$(BACKUP_PATH)/$(FILE)" || { printf "$(RED)Tidak ditemukan: $(BACKUP_PATH)/$(FILE)$(RESET)\n"; exit 1; }
	$(COMPOSE) exec -T app tar -xzf - -C /var/www/html/storage/app < "$(BACKUP_PATH)/$(FILE)"
	@printf "$(GREEN)✓ Lampiran dipulihkan dari $(FILE)$(RESET)\n"

# ----- Development (host) -----

test: ## Jalankan PHPUnit
	php artisan test

lint: ## Cek gaya kode (Pint, tanpa mengubah file)
	composer lint

format: ## Rapikan gaya kode dengan Pint
	vendor/bin/pint

analyse: ## Analisis statis (Larastan)
	composer analyse

ci: lint analyse test ## Semua pemeriksaan CI
	@printf "$(GREEN)✓ Semua pemeriksaan lolos$(RESET)\n"
