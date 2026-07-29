# App Store release build for the "music_radio" Nextcloud app.
#
# Local use (build tooling needs the container's Linux binaries — see the app
# README's immutable-cache/native-binary notes):
#   ddev exec -d /var/www/html/app/music_radio make appstore
#
# Integrity signing (appinfo/signature.json) is applied only when a certificate
# is available and OCC is set, e.g.:
#   ddev exec -d /var/www/html/app/music_radio make appstore \
#       OCC="php /var/www/html/nextcloud/occ" cert_dir=/var/www/html/app/music_radio/.cert

app_name=music_radio
project_dir=$(CURDIR)
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
artifact_dir=$(build_dir)/artifacts
cert_dir=$(HOME)/.nextcloud/certificates

# occ command used for integrity signing; empty = skip signing.
OCC?=

.PHONY: all assets appstore appstore-sign clean

all: appstore

# Build the production frontend bundles into js/.
assets:
	npm ci
	npm run build

# Assemble a clean, optionally integrity-signed release tarball at
# build/artifacts/music_radio.tar.gz (top-level folder: music_radio/).
appstore: assets
	rm -rf $(sign_dir) $(artifact_dir)
	mkdir -p $(sign_dir)/$(app_name) $(artifact_dir)
	# Copy the app, dropping dev files (.nextcloudignore) and the dev composer/ dir;
	# a fresh production composer/ is generated in the staging copy below.
	rsync -a --exclude-from=$(project_dir)/.nextcloudignore --exclude=composer \
		$(project_dir)/ $(sign_dir)/$(app_name)/
	cd $(sign_dir)/$(app_name) && composer install --no-dev --optimize-autoloader --no-interaction
ifneq ($(OCC),)
	@if [ -f "$(cert_dir)/$(app_name).key" ] && [ -f "$(cert_dir)/$(app_name).crt" ]; then \
		echo "Integrity-signing $(app_name)…"; \
		$(OCC) integrity:sign-app \
			--privateKey="$(cert_dir)/$(app_name).key" \
			--certificate="$(cert_dir)/$(app_name).crt" \
			--path="$(sign_dir)/$(app_name)"; \
	else \
		echo "No $(app_name).key/.crt in $(cert_dir) — skipping integrity signing."; \
	fi
else
	@echo "OCC not set — skipping integrity signing (appinfo/signature.json)."
endif
	tar -czf $(artifact_dir)/$(app_name).tar.gz -C $(sign_dir) $(app_name)
	@echo "Built $(artifact_dir)/$(app_name).tar.gz"

# Print the base64 App Store release signature for the built tarball.
appstore-sign:
	@openssl dgst -sha512 -sign "$(cert_dir)/$(app_name).key" \
		"$(artifact_dir)/$(app_name).tar.gz" | openssl base64 -A; echo

clean:
	rm -rf $(build_dir)
