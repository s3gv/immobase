# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 s3gv

.PHONY: setup check test test-db dev-user fix css watch attributions up down

setup: ## Hooks aktivieren und Abhängigkeiten installieren
	git config core.hooksPath .githooks
	composer install
	@echo "Fertig. 'make check' führt alle Prüfungen aus."

check: ## Alle Prüfungen (dasselbe, was der Pre-Push-Hook macht)
	./bin/check

test: ## Nur die Tests
	vendor/bin/phpunit

dev-user: ## Entwicklungskonto anlegen, falls es fehlt
	@printf '%s\n' "$${IMMOBASE_DEV_PASSWORD:-Nur-lokal-zum-Entwickeln-2026}" \
		| php bin/console immobase:user:create --no-interaction \
		"$${IMMOBASE_DEV_EMAIL:-dev@example.org}" \
		"$${IMMOBASE_DEV_NAME:-Dev}" "Entwicklung" --admin \
		|| echo "Konto existiert bereits — nichts zu tun."

test-db: ## Testdatenbank anlegen und migrieren (einmalig nötig)
	php bin/console doctrine:database:create --env=test --if-not-exists --no-interaction
	php bin/console doctrine:migrations:migrate --env=test --no-interaction

css: ## Tailwind einmalig bauen
	php bin/console tailwind:build

attributions: ## Attributionsliste neu erzeugen
	php tools/generate-attributions.php

watch: ## Tailwind im Beobachtungsmodus
	php bin/console tailwind:build --watch

fix: ## Formatierung automatisch korrigieren
	vendor/bin/php-cs-fixer fix

up: ## Stack starten
	docker compose up -d --build

down: ## Stack stoppen (Daten bleiben erhalten)
	docker compose down

# Bewusst kein Ziel für "docker compose down -v": das löscht das
# Datenbank-Volume und damit alle Konten und Daten. Wer das wirklich will,
# tippt es aus.
