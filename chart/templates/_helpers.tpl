{{/*
Expand the name of the chart.
*/}}
{{- define "flarum.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
Truncated at 63 chars because some Kubernetes name fields are limited to this.
*/}}
{{- define "flarum.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "flarum.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels.
.Values.commonLabels are merged in here (but NOT into selectorLabels, which
are immutable on an existing Deployment).
*/}}
{{- define "flarum.labels" -}}
helm.sh/chart: {{ include "flarum.chart" . }}
{{ include "flarum.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- with .Values.commonLabels }}
{{ toYaml . }}
{{- end }}
{{- end }}

{{/*
Common annotations.
Renders the YAML body of .Values.commonAnnotations (no leading "annotations:"
key) so callers can merge it under an existing or new metadata.annotations.
Emits nothing when commonAnnotations is empty.
*/}}
{{- define "flarum.commonAnnotations" -}}
{{- with .Values.commonAnnotations }}
{{- toYaml . }}
{{- end }}
{{- end }}

{{/*
Selector labels.
*/}}
{{- define "flarum.selectorLabels" -}}
app.kubernetes.io/name: {{ include "flarum.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Service account name.
*/}}
{{- define "flarum.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "flarum.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
Flarum container image reference.
Requires image.repository to be set (defaulted in values.yaml). image.tag
defaults to Chart.AppVersion when empty.
*/}}
{{- define "flarum.image" -}}
{{- $repo := required "image.repository is required -- set it to your Flarum image" .Values.image.repository -}}
{{- $tag := default .Chart.AppVersion .Values.image.tag -}}
{{- printf "%s:%s" $repo $tag }}
{{- end }}

{{/*
Chart-managed Secret name. This Secret always holds the rendered install.yml
(which carries the DB password, admin password and Keycloak client_secret), so
it is created whenever the chart renders.
*/}}
{{- define "flarum.secretName" -}}
{{- printf "%s-secret" (include "flarum.fullname" .) }}
{{- end }}

{{/*
Resolve the database password Secret reference (name + key).
Returns "name:key". When the user supplies database.existingSecret we read the
password from there; otherwise it lives in the chart-managed Secret rendered
from database.password.
*/}}
{{- define "flarum.dbPasswordSecret" -}}
{{- if .Values.flarum.database.existingSecret -}}
{{- printf "%s:%s" .Values.flarum.database.existingSecret .Values.flarum.database.secretKey -}}
{{- else -}}
{{- printf "%s:%s" (include "flarum.secretName" .) "db-password" -}}
{{- end -}}
{{- end }}

{{/*
PVC name for storage (/app/storage).
*/}}
{{- define "flarum.storagePvcName" -}}
{{- if .Values.persistence.storage.existingClaim }}
{{- .Values.persistence.storage.existingClaim }}
{{- else }}
{{- printf "%s-storage" (include "flarum.fullname" .) }}
{{- end }}
{{- end }}

{{/*
PVC name for assets (/app/public/assets).
*/}}
{{- define "flarum.assetsPvcName" -}}
{{- if .Values.persistence.assets.existingClaim }}
{{- .Values.persistence.assets.existingClaim }}
{{- else }}
{{- printf "%s-assets" (include "flarum.fullname" .) }}
{{- end }}
{{- end }}

{{/*
PVC name for config (durable config.php directory).
*/}}
{{- define "flarum.configPvcName" -}}
{{- if .Values.persistence.config.existingClaim }}
{{- .Values.persistence.config.existingClaim }}
{{- else }}
{{- printf "%s-config" (include "flarum.fullname" .) }}
{{- end }}
{{- end }}

{{/*
Directory inside the container where the config PVC is mounted. Flarum reads
/app/config.php; we keep config.php on a PVC mounted at this directory and
symlink /app/config.php -> {dir}/config.php at boot (see the link-config init
container). A directory mount (not a subPath file mount) is used because a
fresh PVC is an empty directory and a subPath file mount cannot create the file
that does not yet exist.
*/}}
{{- define "flarum.configDir" -}}
/flarum-config
{{- end }}
