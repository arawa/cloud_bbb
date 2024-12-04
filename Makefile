# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
app_name=$(notdir $(CURDIR))
build_tools_directory=$(CURDIR)/build/tools
source_build_directory=$(CURDIR)/build/artifacts/source
source_package_name=$(source_build_directory)/$(app_name)
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
composer=$(shell which composer 2> /dev/null)

all: dev-setup lint build-js-production test

build: install-composer-deps build-js

# Dev env management
dev-setup: clean clean-dev install-composer-deps-dev js-init

composer.phar:
	curl -sS https://getcomposer.org/installer | php

install-composer-deps: composer.phar
	php composer.phar install --no-dev -o

install-composer-deps-dev: composer.phar
	php composer.phar install -o

js-init:
	yarn install

yarn-update:
	yarn update

# Building
build-js: js-init
	yarn run dev

build-js-production: js-init
	yarn run build

watch-js: js-init
	yarn run watch

# Linting
lint: js-init
	yarn run lint

lint-fix: js-init
	yarn run lint:fix

# Style linting
stylelint: js-init
	yarn run stylelint

stylelint-fix: js-init
	yarn run stylelint:fix

phplint:
	./vendor/bin/php-cs-fixer fix --dry-run

phplint-fix:
	./vendor/bin/php-cs-fixer fix

# Cleaning
clean:
	rm -rf js/*

clean-dev:
	rm -rf node_modules
	git checkout composer.json
	git checkout composer.lock
	rm -rf vendor

pack: install-composer-deps
	mkdir -p archive
	tar --exclude='./Makefile' --exclude='./webpack*' --exclude='./.*' --exclude='./ts' --exclude='./tests' --exclude='./node_modules' --exclude='./archive' -zcvf ./archive/cloud_bbb.tar.gz . --transform s/^./bbb/

# Tests
test:
	./vendor/phpunit/phpunit/phpunit -c phpunit.xml
	./vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml


######################################################
#
# Everything from here relates to building a valid app
# for Nextcloud's official appstore
#
######################################################

# Builds the source and appstore package
.PHONY: dist
dist:
	make source
	make appstore

# Builds the source package
.PHONY: source
source:
	rm -rf $(source_build_directory)
	mkdir -p $(source_build_directory)
	git status -u --porcelain | grep "??" | cut -c 4- >$(source_build_directory)/exclude_files.list
	tar cvzf ${source_package_name}.tar.gz \
	--exclude-vcs \
	--exclude-vcs-ignores \
	-X $(source_build_directory)/exclude_files.list \
	--transform 's,^,$(app_name)/,S' \
	.
	rm -f $(source_build_directory)/exclude_files.list

# Builds the source package for the app store, ignores php and js tests
.PHONY: appstore
appstore:
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)
	git status -u --porcelain | grep "??" | cut -c 4- >$(appstore_build_directory)/exclude_files.list
	tar cvzf "$(appstore_package_name).tar.gz" \
	--exclude-vcs \
	--exclude-vcs-ignores \
	--exclude="./tests" \
	--exclude="Makefile" \
	--exclude="phpunit*xml" \
	--exclude="composer.*" \
	--exclude="*.json" \
	--exclude="./\.*" \
	--exclude="*.config.*" \
	--exclude="./scripts" \
	--exclude="psalm.*" \
	--exclude="webpack.*" \
	--exclude="yarn.*" \
	--exclude="declarations.*" \
	-X $(appstore_build_directory)/exclude_files.list \
	--transform 's,^,$(app_name)/,S' \
	.
	# use tar to build zip
	cd $(appstore_build_directory) && tar zxf $(app_name).tar.gz && zip -r $(app_name).zip $(app_name) && rm -rf $(app_name)
	rm -f $(appstore_build_directory)/exclude_files.list
