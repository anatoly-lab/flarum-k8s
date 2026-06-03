# flarum-k8s

Custom Docker image and Helm chart for deploying [Flarum 2.0](https://flarum.org)
on Kubernetes (built and tested against [Talos](https://www.talos.dev)) with
**Keycloak SSO**.

This is a feasibility / smoke-test deployment: Flarum 2.0 (RC line) served by
FrankenPHP against an **external PostgreSQL** database, with Single Sign-On via
[FriendsOfFlarum/oauth](https://github.com/FriendsOfFlarum/oauth) plus a small
**custom Keycloak provider** baked into the image. No maintained Flarum-2.0
Keycloak/OIDC extension exists on Packagist today, which is why this repo ships
its own (`anatolylab/flarum-keycloak-provider`).

## Architecture

```
+--------------------------------------------------------------------------+
|  Pod (single replica, strategy: Recreate)                                |
|                                                                          |
|  initContainers (run in order):                                          |
|  +-------------+ +------------------+ +-----------+ +---------+ +-------+ |
|  |link-config  |>|wait-for-postgres |>| install   |>| migrate |>|assets-| |
|  |symlink      | | (busybox nc)     | | (GUARDED, | |(idempo- || publish| |
|  |config.php   | | optional         | |  once)    | | tent)   ||(idempo)| |
|  +-------------+ +------------------+ +-----------+ +---------+ +-------+ |
|                                                                          |
|  containers:                                                             |
|  +----------------------------------+                                    |
|  |  web                             |                                    |
|  |  FrankenPHP classic mode :8080   |   (image DEFAULT command)          |
|  +----------------------------------+                                    |
|         |                                                                |
+---------|----------------------------------------------------------------+
          |  Service :80 -> targetPort 8080      (TLS terminates at ingress)
   +------v-------+
   |  PostgreSQL   |   (external, NOT deployed by this chart)
   +--------------+
```

The pod runs **one** container (`web`) from the custom image plus a chain of
init containers built from the **same image** with a `command` override. There
is no separate database, Redis, or queue worker — Flarum's queue runs in the
`sync` driver (in-request) for this smoke test.

### The image ↔ chart contract

The image (`ghcr.io/anatoly-lab/flarum-k8s`, see [`docker/`](docker/)) is the
authoritative half of the contract:

- Runs as **uid/gid 1000**, non-root, PSA-`restricted` compatible. The chart
  sets `podSecurityContext.fsGroup: 1000` so the RWO PVC mounts are writable.
- **FrankenPHP classic mode, container port 8080.** The web container uses the
  image's **default command** (the chart deliberately sets *no* `command` on
  it). Auto-HTTPS is disabled (`SERVER_NAME=:8080`), so **TLS is terminated at
  the ingress**.
- The same image runs one-off CLI tasks when the chart overrides `command`
  (first arg has no leading dash, so the entrypoint execs it verbatim):
  `["php","flarum","install","--file=..."]`, `["php","flarum","migrate"]`,
  `["php","flarum","assets:publish"]`.

### Init flow (the make-or-break part)

1. **link-config** — Makes `config.php` durable. Flarum reads `/app/config.php`
   on the ephemeral rootfs; this step symlinks it onto the config PVC
   (`ln -sfn /flarum-config/config.php /app/config.php`). `install` writes
   through the symlink, so config.php lands on the PVC and survives restarts.
   Idempotent.
2. **wait-for-postgres** *(optional, `initContainers.waitForPostgres.enabled`)*
   — busybox `nc` loop until PostgreSQL accepts TCP.
3. **install** — **Guarded, runs once.** `flarum install` has no built-in
   "already installed" guard and `config.php` is not a reliable sentinel, so the
   step guards on **DB state**: it queries the `settings` table for the `version`
   row (via PHP PDO / `pdo_pgsql`, which is in the image) and **skips** install
   if present. On a fresh DB it renders the install file (substituting secrets
   from env, see below) and runs `php flarum install --file=...`, which in one
   shot writes config.php, migrates core, seeds **all settings** (including the
   Keycloak SSO rows), creates the admin user, and enables the listed
   extensions. After a successful install the version row exists, so every
   subsequent boot skips this step.
4. **migrate** — `php flarum migrate`. Idempotent; catches new core/extension
   migrations on image upgrades.
5. **assets-publish** — `php flarum assets:publish`. **Mandatory on every
   boot:** the assets PVC mounted at `/app/public/assets` starts empty and
   shadows the assets baked into the image, so without re-publishing the forum
   renders with **no CSS**. Idempotent.

### Persistence (three RWO PVCs)

The image bakes the app into `/app`, so the chart never mounts a volume over
`/app` itself — only over the runtime-state sub-paths:

| PVC | Mount | Purpose |
| --- | ----- | ------- |
| `storage` | `/app/storage` | logs, cache, sessions, formatter cache |
| `assets` | `/app/public/assets` | uploads (logos, avatars) + published assets |
| `config` | `/flarum-config` (dir) | durable `config.php` (symlinked from `/app/config.php`) |

**Why a dedicated config PVC + symlink (and not a subPath file mount)?**
`flarum install` writes `config.php` exactly once; on an ephemeral filesystem it
is lost on restart and the web pod boots into the web installer. The cleanest
durable option is to let `install` write the real file onto a persistent volume
(option (a) in the design notes). A `subPath` *file* mount can't be used here
because a freshly provisioned PVC is an **empty directory** — there is no
`config.php` in it to bind-mount, and a subPath that doesn't exist is created as
a *directory*, which would shadow Flarum's file. So the chart mounts the config
PVC at a **directory** (`/flarum-config`) and the `link-config` init container
symlinks `/app/config.php` → `/flarum-config/config.php`. `install` writes
through the symlink onto the PVC; the guard ensures it happens once.

> **Talos note:** Talos ships **no default StorageClass**. You **must** set
> `persistence.*.storageClass` (or configure a cluster-default StorageClass) or
> the PVCs stay `Pending`.

### Secret handling

The Flarum install file carries three secrets — the DB password, the admin
password, and the Keycloak `client_secret` (which FoF stores **plaintext** in
the `settings` table). The chart renders `install.yml` into a **Secret** (never
a ConfigMap) with the secret fields written as placeholders
(`__DB_PASSWORD__`, `__ADMIN_PASSWORD__`, `__KEYCLOAK_CLIENT_SECRET__`). The
`install` init container substitutes them at runtime in PHP (literal
`str_replace` with YAML escaping — not `sed` — so passwords containing `/ & |`
or quotes are injected verbatim), sourcing each value from env via
`secretKeyRef`.

Each secret supports the discourse-style two-mode pattern:

- **Inline** (`password` / `clientSecret`): the chart-managed Secret holds the
  value. Convenient for testing; never put it in a git-committed values file.
- **existingSecret** + **secretKey**: reference a pre-existing Secret (Vault,
  External Secrets Operator, etc.). When set, nothing secret is rendered into
  the chart manifests.

## Prerequisites

- **Kubernetes** with a usable StorageClass (Talos: provide one explicitly).
- **External PostgreSQL** — the database must already exist and the configured
  user must own it (Flarum runs migrations as that user).
- **Helm 3** (or 4).
- A **Keycloak** realm if you want SSO (optional).
- **Docker** (only to build the image yourself).

## Quick Start

### 1. Provision an external PostgreSQL database

```sql
CREATE DATABASE flarum;
CREATE USER flarum WITH PASSWORD 'your-db-password';
GRANT ALL PRIVILEGES ON DATABASE flarum TO flarum;
-- Flarum uses the `public` schema by default (database.searchPath).
```

### 2. Write `my-values.yaml`

```yaml
image:
  repository: ghcr.io/anatoly-lab/flarum-k8s
  tag: 2.0.0-rc.2          # optional; defaults to Chart.appVersion

flarum:
  url: https://forum.example.com
  admin:
    username: admin
    email: admin@example.com
    password: "change-me-please"      # or use existingSecret
  database:
    host: postgres.db.svc.cluster.local
    name: flarum
    username: flarum
    password: "your-db-password"      # or use existingSecret
  keycloak:
    enabled: true
    clientId: flarum
    clientSecret: "from-keycloak"     # or use existingSecret
    authServerUrl: https://keycloak.example.com   # no trailing /auth
    realm: myrealm

persistence:
  storage:
    storageClass: your-storage-class
  assets:
    storageClass: your-storage-class
  config:
    storageClass: your-storage-class

ingress:
  enabled: true
  className: traefik
  hosts:
    - host: forum.example.com
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: forum-tls
      hosts:
        - forum.example.com
```

### 3. Install

**From a local checkout:**

```bash
helm install flarum chart/ -f my-values.yaml -n flarum --create-namespace
```

**From GHCR (OCI):**

```bash
helm install flarum oci://ghcr.io/anatoly-lab/helm-charts/flarum \
  --version 0.1.0 -f my-values.yaml -n flarum --create-namespace
```

### 4. Watch the init flow

```bash
kubectl logs deploy/flarum -c install        -n flarum   # one-time install (guarded)
kubectl logs deploy/flarum -c migrate        -n flarum
kubectl logs deploy/flarum -c assets-publish -n flarum
kubectl logs -f deploy/flarum -c web         -n flarum
```

Once ready:

```bash
kubectl port-forward svc/flarum 8080:80 -n flarum
# open http://localhost:8080  (or your ingress host)
```

With SSO enabled, the login button hits `https://<forum-host>/auth/keycloak`.

## Parameters

### Image parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `image.repository` | Flarum container image repository | `ghcr.io/anatoly-lab/flarum-k8s` |
| `image.tag` | Image tag (defaults to `Chart.appVersion` if empty) | `""` |
| `image.pullPolicy` | Image pull policy | `IfNotPresent` |
| `imagePullSecrets` | Docker registry secret names | `[]` |
| `nameOverride` | Partially override the release name | `""` |
| `fullnameOverride` | Fully override the release name | `""` |

### Flarum core parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `flarum.url` | Public base URL incl. scheme (required) | `""` |
| `flarum.debug` | Flarum debug mode | `false` |
| `flarum.admin.username` | Admin username (install only) | `admin` |
| `flarum.admin.email` | Admin email (required) | `""` |
| `flarum.admin.password` | Admin password (ignored if existingSecret set) | `""` |
| `flarum.admin.existingSecret` | Existing Secret holding the admin password | `""` |
| `flarum.admin.secretKey` | Key in the existing admin Secret | `admin-password` |
| `flarum.extensions` | Flarum extension IDs enabled at install (comma-joined) | `[fof-oauth, anatolylab-keycloak-provider]` |
| `flarum.extraSettings` | Extra `key: value` settings rows seeded at install | `{}` |

### Database parameters (external PostgreSQL)

| Name | Description | Value |
| ---- | ----------- | ----- |
| `flarum.database.host` | PostgreSQL host (required) | `""` |
| `flarum.database.port` | PostgreSQL port | `5432` |
| `flarum.database.name` | Database name | `flarum` |
| `flarum.database.username` | Database user | `flarum` |
| `flarum.database.searchPath` | Schema / search_path | `public` |
| `flarum.database.prefix` | Table name prefix | `""` |
| `flarum.database.password` | DB password (ignored if existingSecret set) | `""` |
| `flarum.database.existingSecret` | Existing Secret holding the DB password | `""` |
| `flarum.database.secretKey` | Key in the existing DB Secret | `db-password` |

### Keycloak SSO parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `flarum.keycloak.enabled` | Seed Keycloak SSO settings at install | `false` |
| `flarum.keycloak.clientId` | Keycloak client ID (required when enabled) | `""` |
| `flarum.keycloak.clientSecret` | Client secret (ignored if existingSecret set) | `""` |
| `flarum.keycloak.existingSecret` | Existing Secret holding the client secret | `""` |
| `flarum.keycloak.secretKey` | Key in the existing Keycloak Secret | `keycloak-client-secret` |
| `flarum.keycloak.authServerUrl` | Keycloak base URL, no `/auth` (required when enabled) | `""` |
| `flarum.keycloak.realm` | Keycloak realm (required when enabled) | `""` |
| `flarum.keycloak.ssoOnly` | SSO-only mode: seed `allow_sign_up=0` (disable local self-registration) when keycloak.enabled | `false` |

> Every secret field supports inline (`password`/`clientSecret`) **or**
> `existingSecret` + `secretKey`. With `existingSecret`, no secret value is
> rendered into the chart manifests.

#### SSO-only mode (`flarum.keycloak.ssoOnly`) — behaviour & limitations

Setting `ssoOnly: true` (with `keycloak.enabled`) seeds `allow_sign_up = "0"`,
which closes the public Sign-Up form and makes the core user-create endpoint
admin-only. This was verified against Flarum 2.0 core source. Understand the
two limitations before relying on it:

1. **New Keycloak users cannot auto-provision.** When a brand-new user logs in
   via Keycloak, fof/oauth routes them through Flarum core's user-create
   endpoint, which `allow_sign_up=0` gates to admins only — and the OAuth
   registration token does **not** bypass that gate
   (`Api/Resource/UserResource` `Create.visible()`). There is no fof/oauth or
   core setting to allow "register via OAuth while local sign-up is closed"
   without a heavier, questionable extension (deliberately not added). To
   onboard new members, either pre-create their Flarum accounts (matching the
   Keycloak email) or temporarily flip `ssoOnly: false`.
2. **The local password login form is not hidden.** Core has no setting to
   remove it. Local admin login therefore remains as an intentional fallback —
   and because **existing** users (login-provider match or email match) are
   logged in directly without touching the gated create endpoint, the
   install-time admin (whose email matches its Keycloak account) auto-links on
   first SSO login and stays usable.

### Email / SMTP parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `flarum.mail.enabled` | Seed SMTP mail settings at install | `false` |
| `flarum.mail.driver` | Mail driver | `smtp` |
| `flarum.mail.host` | SMTP host (required when enabled) | `""` |
| `flarum.mail.port` | SMTP port (587 STARTTLS / 465 SSL / 25 plain) | `587` |
| `flarum.mail.encryption` | `tls` (STARTTLS), `ssl` (implicit), `""` (none) | `tls` |
| `flarum.mail.from` | Sender From address (required when enabled) | `""` |
| `flarum.mail.existingSecret` | Existing in-namespace Secret with SMTP creds | `smtp-credentials` |
| `flarum.mail.usernameKey` | Key in that Secret for the SMTP username | `username` |
| `flarum.mail.passwordKey` | Key in that Secret for the SMTP password | `password` |

> **Seeded at install time only.** Like the Keycloak block, mail and `ssoOnly`
> settings are written by the guarded one-shot `install` step (when the DB has
> no `settings.version` row). Flipping `mail.enabled`/`ssoOnly`/changing SMTP
> host on an **already-installed** forum is a no-op — change those in the admin
> UI, or re-seed via a direct `settings` upsert (`allow_sign_up` → `"0"`,
> `mail_*`). The install-file values win over core defaults
> (`WriteSettings`: `$custom + $defaults`, left-precedence).
>
> SMTP credentials are **existing-secret-only** (no inline fallback): create a
> Secret in the release namespace (default name `smtp-credentials`) with
> `username`/`password` keys. The chart injects them into `install.yml` at
> runtime via placeholders — they are never rendered into a chart Secret or a
> ConfigMap. Flarum 2.0 has no separate from-name setting; the sender display
> name is the `forum_title` (set it via `flarum.extraSettings.forum_title`).
> Verified core keys: `mail_driver`, `mail_host`, `mail_port`,
> `mail_encryption`, `mail_username`, `mail_password`, `mail_from`.

### Init container parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `initContainers.waitForPostgres.enabled` | Wait for PostgreSQL before install | `true` |
| `initContainers.waitForPostgres.image.repository` | wait-for-postgres image repo | `busybox` |
| `initContainers.waitForPostgres.image.tag` | wait-for-postgres image tag | `"1.37"` |
| `initContainers.waitForPostgres.image.pullPolicy` | wait-for-postgres pull policy | `IfNotPresent` |

### Persistence parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `persistence.storage.enabled` | Persist `/app/storage` | `true` |
| `persistence.storage.size` | Size of the storage PVC | `5Gi` |
| `persistence.storage.storageClass` | StorageClass (required on Talos) | `""` |
| `persistence.storage.accessModes` | Access modes | `["ReadWriteOnce"]` |
| `persistence.storage.existingClaim` | Use an existing PVC instead | `""` |
| `persistence.assets.enabled` | Persist `/app/public/assets` | `true` |
| `persistence.assets.size` | Size of the assets PVC | `5Gi` |
| `persistence.assets.storageClass` | StorageClass | `""` |
| `persistence.assets.accessModes` | Access modes | `["ReadWriteOnce"]` |
| `persistence.assets.existingClaim` | Use an existing PVC instead | `""` |
| `persistence.config.enabled` | Persist `config.php` (durability) | `true` |
| `persistence.config.size` | Size of the config PVC | `64Mi` |
| `persistence.config.storageClass` | StorageClass | `""` |
| `persistence.config.accessModes` | Access modes | `["ReadWriteOnce"]` |
| `persistence.config.existingClaim` | Use an existing PVC instead | `""` |

PVCs carry `helm.sh/resource-policy: keep` so a `helm uninstall` does not delete
your data; use `existingClaim` to bind to a pre-created volume.

### Network parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `service.type` | Service type | `ClusterIP` |
| `service.port` | Service port | `80` |
| `service.targetPort` | Container port (FrankenPHP) | `8080` |
| `service.annotations` | Service annotations | `{}` |
| `ingress.enabled` | Create an Ingress | `false` |
| `ingress.className` | Ingress class | `""` |
| `ingress.annotations` | Ingress annotations | `{}` |
| `ingress.hosts` | Host/path rules | `[]` |
| `ingress.tls` | TLS config (terminates TLS here) | `[]` |

### Resource / scheduling / security parameters

| Name | Description | Value |
| ---- | ----------- | ----- |
| `resources` | Web container resources | requests `256Mi`/`100m`, limit `512Mi` |
| `serviceAccount.create` | Create a ServiceAccount | `false` |
| `serviceAccount.name` | ServiceAccount name | `""` |
| `serviceAccount.annotations` | ServiceAccount annotations | `{}` |
| `commonLabels` | Labels applied to every resource | `{}` |
| `commonAnnotations` | Annotations applied to every resource | `{}` |
| `nodeSelector` | Node selector | `{}` |
| `tolerations` | Tolerations | `[]` |
| `affinity` | Affinity | `{}` |
| `priorityClassName` | PriorityClass (must exist) | `""` |
| `extraVolumes` | Extra pod volumes | `[]` |
| `extraVolumeMounts` | Extra mounts on the web container | `[]` |
| `podAnnotations` | Pod annotations | `{}` |
| `podLabels` | Pod labels | `{}` |
| `podSecurityContext` | Pod security context (sets `fsGroup: 1000`) | see values.yaml |
| `securityContext` | Container security context (PSA-restricted) | see values.yaml |
| `restartPolicy` | Pod restart policy | `Always` |
| `terminationGracePeriodSeconds` | Grace period | `30` |

## Keycloak / OIDC setup

1. In the target realm, create a **confidential** client (Client authentication
   **ON**, Standard flow enabled).
2. **Valid redirect URI:** `https://<forum-host>/auth/keycloak`
   (FoF/oauth routes `/auth/{provider}`, and this provider's `name()` is
   `keycloak`).
3. **Client scopes:** the defaults `openid email profile`. The custom provider
   also forces `openid email profile` from its `options()` override and pins the
   Keycloak `version` to belt-and-suspenders against stevenmaguire's scope gate,
   so the `openid` scope is always requested.
4. Copy the client **secret** and set the chart values:

```yaml
flarum:
  keycloak:
    enabled: true
    clientId: flarum
    clientSecret: "<from keycloak>"        # or existingSecret
    authServerUrl: https://keycloak.example.com   # NO trailing /auth
    realm: myrealm
```

These map to the `fof-oauth.keycloak.*` rows seeded into the Flarum `settings`
table at install (`fof-oauth.keycloak` = `"1"` enables it). The `client_secret`
is stored **plaintext** in the DB by FoF — that is a Flarum property; keep the
DB on a trusted volume and source the secret from a k8s Secret, never git.

## Building the image

```bash
docker build --platform linux/amd64 -t ghcr.io/anatoly-lab/flarum-k8s:latest docker/

# Pin versions:
docker build --platform linux/amd64 \
  --build-arg FLARUM_VERSION=^2.0.0 \
  --build-arg FOF_OAUTH_VERSION=^2.0@beta \
  -t ghcr.io/anatoly-lab/flarum-k8s:2.0 docker/
```

| Build arg | Default | Description |
| --------- | ------- | ----------- |
| `BASE_IMAGE` | `dunglas/frankenphp:1-php8.3-bookworm` | FrankenPHP base |
| `FLARUM_VERSION` | `^2.0.0` | Flarum version constraint (resolved at beta stability) |
| `FOF_OAUTH_VERSION` | `^2.0@beta` | FriendsOfFlarum/oauth version constraint |

The custom Keycloak provider (`docker/extensions/flarum-keycloak-provider`) is
COPYed in as a Composer **path repository** and required at build time, so the
image is fully self-contained — no runtime extension manager, no network install
on boot.

## CI/CD

Both artifacts are built and pushed by GitHub Actions, triggered by tags.

### Docker image

- **Trigger:** push a tag matching `docker/v*` (e.g. `docker/v0.1.0`)
- **Registry:** `ghcr.io/anatoly-lab/flarum-k8s`
- **Workflow:** [`.github/workflows/docker.yml`](.github/workflows/docker.yml)

```bash
git tag docker/v0.1.0
git push origin docker/v0.1.0
```

### Helm chart

- **Trigger:** push a tag matching `chart/v*` (e.g. `chart/v0.1.0`)
- **Registry:** `oci://ghcr.io/anatoly-lab/helm-charts`
- **Workflow:** [`.github/workflows/helm.yml`](.github/workflows/helm.yml)

```bash
git tag chart/v0.1.0
git push origin chart/v0.1.0
```

### Consuming the chart from GHCR

```bash
# Pull and inspect
helm pull oci://ghcr.io/anatoly-lab/helm-charts/flarum --version 0.1.0

# Install directly
helm install flarum oci://ghcr.io/anatoly-lab/helm-charts/flarum \
  --version 0.1.0 -f my-values.yaml -n flarum --create-namespace
```

### ArgoCD (multi-source pattern)

Keep the chart in GHCR and your values in a git repo:

```yaml
spec:
  sources:
    - repoURL: https://github.com/anatoly314/<infra-repo>.git
      targetRevision: HEAD
      ref: values
    - repoURL: ghcr.io/anatoly-lab/helm-charts
      chart: flarum
      targetRevision: 0.1.0
      helm:
        valueFiles:
          - $values/apps/flarum/values.yaml
```

## Troubleshooting

**Pod boots into the Flarum web installer.** `config.php` was not durable. Check
that the `config` PVC is bound and that the `link-config` init container created
the symlink (`kubectl logs deploy/flarum -c link-config -n flarum`).

**Forum renders with no CSS / unstyled HTML.** `assets:publish` did not run or
the assets PVC is empty. It runs on every boot — check
`kubectl logs deploy/flarum -c assets-publish -n flarum`.

**PVCs stuck Pending.** On Talos there is no default StorageClass — set
`persistence.*.storageClass`.

**`install` keeps re-running / errors on a populated DB.** The guard queries the
`settings.version` row via PDO. Verify the DB user can read the `settings`
table; check `kubectl logs deploy/flarum -c install -n flarum`.

**Login redirect fails.** The Keycloak client's Valid Redirect URI must be
exactly `https://<forum-host>/auth/keycloak`, and `authServerUrl` must have **no**
trailing `/auth`.

## License

MIT
