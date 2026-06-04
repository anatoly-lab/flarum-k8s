# syntax=docker/dockerfile:1
# =============================================================================
# Flarum 2.0 for Kubernetes (Keycloak SSO smoke test)
# =============================================================================
#
# A single, immutable image that serves Flarum 2.0 (RC line) on PostgreSQL with
# Keycloak SSO via FriendsOfFlarum/oauth + a baked-in custom Keycloak provider.
#
# Serving model: FrankenPHP (Caddy + PHP, single container) in CLASSIC mode
# (one PHP execution per request — NOT worker/Octane mode, which Flarum does not
# support and which would leak request state across the long-lived process).
#
# The SAME image serves both the web server AND one-off CLI tasks via a command
# override from the Helm chart. The FrankenPHP base ENTRYPOINT is the php-image
# docker-php-entrypoint, rewritten by FrankenPHP so that `php` -> `frankenphp run`.
# Its logic: if the first arg starts with "-" it prepends `frankenphp run`,
# otherwise it execs the args verbatim. Consequences for the chart:
#   - Web server (this image's CMD): ["--config", "/etc/caddy/Caddyfile"]
#       -> entrypoint sees a leading "-" and runs `frankenphp run --config ...`
#   - CLI (chart overrides `command`, first arg has NO leading dash, runs as-is):
#       Install (day-0): ["php", "flarum", "install", "--file=/config/install.yml"]
#       Migrate:         ["php", "flarum", "migrate"]
#       Publish assets:  ["php", "flarum", "assets:publish"]
#       Cache clear:     ["php", "flarum", "cache:clear"]
# The Flarum root is the WORKDIR (/app) and `php`, `composer`, and the `flarum`
# CLI (/app/flarum) are all on PATH / resolvable from the workdir.
#
# Target arch: linux/amd64 only.
#
# Build:
#   docker build --platform linux/amd64 -t ghcr.io/anatoly-lab/flarum-k8s:latest .
#   docker build --platform linux/amd64 \
#     --build-arg FLARUM_VERSION=^2.0.0 \
#     -t ghcr.io/anatoly-lab/flarum-k8s:2.0 .
# =============================================================================

ARG BASE_IMAGE=dunglas/frankenphp:1-php8.3-bookworm
# Composer is NOT included in the FrankenPHP base image; pull the binary from
# the official composer image (pinned major) and COPY it in below.
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer

FROM ${BASE_IMAGE}

# Bring the Composer binary into the build image (base image ships none).
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Re-declare after FROM so they are available in RUN/LABEL instructions.
ARG BASE_IMAGE
# Flarum 2.0 is a pre-release (RC) line; resolved at the beta stability set
# below. Override to pin a specific 2.x constraint.
ARG FLARUM_VERSION=^2.0.0
# 2.0-compatible fof/oauth is a beta pre-release.
ARG FOF_OAUTH_VERSION=^2.0@beta
# fof/upload handles user-uploaded images/attachments; its S3 adapter lets them
# live in S3-compatible storage (MinIO/R2/B2) served via a CDN URL. 2.0 is beta.
ARG FOF_UPLOAD_VERSION=^2.0@beta

LABEL org.opencontainers.image.title="Flarum (anatoly-lab)" \
      org.opencontainers.image.description="Flarum 2.0 on PostgreSQL with Keycloak SSO (FoF OAuth + custom Keycloak provider), baked at build time for Kubernetes" \
      org.opencontainers.image.version="${FLARUM_VERSION}" \
      org.opencontainers.image.source="https://github.com/anatoly-lab/flarum-k8s" \
      org.opencontainers.image.base.name="${BASE_IMAGE}" \
      org.opencontainers.image.licenses="MIT"

# =============================================================================
# PHP extensions
# =============================================================================
# Flarum 2.0 requires: curl, dom, fileinfo, gd, json, mbstring, openssl,
# pdo (+ a driver), tokenizer, zip, session. The base FrankenPHP image already
# ships curl/dom/fileinfo/json/mbstring/openssl/tokenizer/session/phar.
#
# CRITICAL: this deployment runs on PostgreSQL, so pdo_pgsql is the driver that
# actually carries the DB connection. We ALSO install pdo_mysql even though it is
# never used: Flarum's `flarum install` prerequisite check hardcodes pdo_mysql as
# "required" regardless of the chosen driver, and aborts the install without it.
# (Known Flarum quirk for PostgreSQL installs — pdo_mysql is present-but-unused.)
#
# install-php-extensions is provided by the FrankenPHP base image and pulls the
# right system libs for each extension.
# - gd, intl, zip: Flarum / common extension requirements
# - exif: image metadata handling for avatars/uploads
# - opcache: production PHP performance
# =============================================================================
RUN install-php-extensions \
        pdo_pgsql \
        pdo_mysql \
        gd \
        intl \
        zip \
        exif \
        opcache

# =============================================================================
# Install Flarum 2.0 (RC) at build time
# =============================================================================
# Web root for Flarum is the public/ subdirectory. The FrankenPHP default
# Caddyfile serves /app/public, so installing Flarum into /app means the
# default Caddyfile already points at /app/public — no custom Caddyfile needed.
#
# The FrankenPHP base image ships a placeholder /app/public/index.php (phpinfo),
# so /app is NOT empty and `create-project .` would refuse it. Install into a
# clean staging dir, then replace /app's contents with the Flarum project
# (dotfiles included).
#
# --stability=beta is required: Flarum 2.0 is a beta/RC line and the root
# composer.json otherwise defaults to stable, which resolves to 1.x or fails.
# =============================================================================
WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN composer create-project "flarum/flarum:${FLARUM_VERSION}" /usr/src/flarum --stability=beta \
        --no-dev --prefer-dist \
    && rm -rf /app/public/index.php \
    && cp -a /usr/src/flarum/. /app/ \
    && rm -rf /usr/src/flarum \
    && composer clear-cache

# =============================================================================
# Bake in extensions at build time (no runtime extension-manager)
# =============================================================================
# Copy the local custom Keycloak provider package into the image so Composer can
# resolve it as a `path` repository.
COPY extensions/flarum-keycloak-provider /opt/flarum-extensions/flarum-keycloak-provider

# Stability ordering matters: --stability=beta on create-project only governed
# THAT resolution. Apply minimum-stability/prefer-stable to the CREATED project's
# composer.json BEFORE requiring beta deps, or `composer require fof/oauth`
# (a beta) can fail to resolve even with the inline @beta flag.
#
# repositories.keycloak path: makes Composer treat the COPY'd dir as a real
# package (symlink/copy). The provider package's composer.json carries an
# explicit "version", so requiring it by name resolves cleanly; it pulls in
# fof/oauth + stevenmaguire/oauth2-keycloak transitively, but we also require
# fof/oauth explicitly to pin its constraint.
#
# league/flysystem-aws-s3-v3 is required EXPLICITLY: it is only a require-dev of
# fof/upload, so a --no-dev image would omit it and fof/upload's S3 adapter would
# fail at runtime. Requiring it here bakes the S3 driver into the image.
RUN composer config minimum-stability beta \
    && composer config prefer-stable true \
    && composer config repositories.keycloak path /opt/flarum-extensions/flarum-keycloak-provider \
    && composer require \
        "fof/oauth:${FOF_OAUTH_VERSION}" \
        "anatolylab/flarum-keycloak-provider:*" \
        "fof/upload:${FOF_UPLOAD_VERSION}" \
        "league/flysystem-aws-s3-v3:^3.0" \
        --update-no-dev --optimize-autoloader \
    && composer clear-cache

# =============================================================================
# Permissions & non-root user
# =============================================================================
# Flarum needs write access to:
#   - the install root /app        (config.php is written here at install time)
#   - /app/storage                 (logs, cache, sessions, formatter cache)
#   - /app/public/assets           (uploaded logos, avatars, published assets)
# These (except baked code) are mounted from PVCs at runtime; chart sets fsGroup
# so the mounts are group-writable by the runtime GID.
#
# Run as a fixed non-root uid/gid (1000:1000) so the image works under a k8s
# `restricted` Pod Security profile / runAsNonRoot. SERVER_NAME=:8080 (below)
# binds a NON-privileged port, so no CAP_NET_BIND_SERVICE is needed.
# =============================================================================
RUN groupadd --gid 1000 flarum \
    && useradd --uid 1000 --gid 1000 --no-create-home --shell /usr/sbin/nologin flarum \
    && chown -R 1000:1000 /app \
    # config.php must live on the config PVC (mounted at /flarum-config) so it
    # survives restarts AND is visible to every container. Bake the symlink into
    # the IMAGE: an init container's symlink would only exist on that container's
    # ephemeral /app and never reach install/migrate/web. `flarum install` writes
    # through this symlink, landing config.php on the PVC; the symlink is dangling
    # until then, which Flarum correctly reads as "not installed".
    && mkdir -p /flarum-config \
    && ln -sfn /flarum-config/config.php /app/config.php \
    && chown -h 1000:1000 /app/config.php \
    && chown 1000:1000 /flarum-config \
    # FrankenPHP/Caddy writes data + config under these paths at runtime.
    && mkdir -p /data/caddy /config/caddy \
    && chown -R 1000:1000 /data /config

# =============================================================================
# Strip file capabilities from the frankenphp binary
# =============================================================================
# The FrankenPHP base image sets cap_net_bind_service on /usr/local/bin/frankenphp
# so it can bind :80/:443 as non-root. We bind the unprivileged :8080, so we don't
# need it — and crucially, a binary carrying file capabilities CANNOT be exec'd
# when the pod sets allowPrivilegeEscalation=false (kernel no_new_privs), failing
# with "exec: frankenphp: Operation not permitted". Strip the capability so the
# image runs under a PSA-restricted / runAsNonRoot security context.
# =============================================================================
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && (setcap -r /usr/local/bin/frankenphp 2>/dev/null || true) \
    && apt-get purge -y libcap2-bin \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# =============================================================================
# Runtime configuration
# =============================================================================
# SERVER_NAME=:8080  — port-only (no hostname). This (a) binds the non-priv
#                      port 8080 so the non-root user can listen, and (b)
#                      DISABLES Caddy automatic HTTPS, so the pod never tries to
#                      provision Let's Encrypt certs. TLS terminates at the
#                      k8s ingress, not here. The Caddyfile reads $SERVER_NAME.
# SERVER_ROOT default is the relative `public/`, which resolves against WORKDIR
# /app -> /app/public (Flarum's webroot). The stock Caddyfile uses php_server
# (CLASSIC mode); we do NOT set FRANKENPHP_CONFIG, so no worker is started.
# =============================================================================
ENV SERVER_NAME=:8080

EXPOSE 8080

USER 1000:1000

# Default command = the FrankenPHP web server (classic mode). The args start
# with "-", so the rewritten docker-php-entrypoint runs them as
# `frankenphp run --config /etc/caddy/Caddyfile`. The chart overrides `command`
# (NOT just args) for one-off CLI Jobs, e.g. ["php","flarum","migrate"].
CMD ["--config", "/etc/caddy/Caddyfile"]
