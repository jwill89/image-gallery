#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Build and deploy the Gallery (PHP API + Vue SPA) to the DigitalOcean droplet.

.DESCRIPTION
    The Gallery repo is split into backend/ (PHP) and frontend/ (Vue), but the
    droplet's webroot is FLAT — the project root IS the Apache document root
    (index.php serves the built SPA from dist/, .htaccess routes /api to the Slim
    app and serves media/ files directly). So deployment flattens backend/* and the
    built SPA (frontend/dist) into one payload that mirrors that layout, then runs
    Composer and database migrations on the host.

    Steps:
      1. Build the frontend (vue-tsc + vite) -> frontend/dist  (skip with -SkipBuild).
      2. Pack a tarball of *code only*, mapping the split repo onto the flat webroot:
           from backend/  -> index.php, .htaccess, api/, includes/, db/ [migrations +
                             setup.php, NOT gallery.db], phinx.php, openapi.json,
                             composer.json, composer.lock, scripts/
           from frontend/ -> dist/
         Data dirs (vendor/, .env, cache/, logs/, dupes/, media/, db/gallery.db) are
         never included.
      3. Upload the tarball in a single pscp stream.
      4. On the host (one plink connection):
           - back up current code to .deploy-backup (rollback; skip with -NoBackup),
           - extract the new code over the webroot (leaving .env, db/gallery.db,
             media/, cache/, dupes/, logs/, vendor/ untouched),
           - composer install (incl. dev deps: Phinx, the migration tool, is a dev
             dependency)                                   (skip with -SkipComposer),
           - run migrations via db/setup.php, which baselines a legacy (pre-Phinx)
             database before applying pending migrations    (skip with -SkipMigrate),
           - restore ownership to www-data and clear the API response cache,
           - health-check the API.
         If any step fails, the previous code is automatically restored.

    Uses PuTTY's pscp/plink so the DigitalOcean .ppk key works directly. They run
    in batch mode, so a passphrase-protected .ppk must be loaded into Pageant first:
        pageant.exe "<KeyPath>"   (enter the passphrase once per Windows session).

    The droplet runs `ufw limit ssh` (drops the IP after ~6 connections in 30s), so
    this uses just TWO SSH connections (one upload, one remote run). The upload is
    retried once after a 35s wait if it looks rate-limited.

.PARAMETER VpsHost      Droplet IP/hostname. Resolved from -VpsHost, then $env:DEPLOY_VPS_HOST, then scripts/deploy.config.ps1 ($DeployVpsHost).
.PARAMETER VpsUser      SSH user. Resolved from -VpsUser, then $env:DEPLOY_VPS_USER, then $DeployVpsUser.
.PARAMETER KeyPath      PuTTY .ppk private key. Resolved from -KeyPath, then $env:DEPLOY_KEY_PATH, then $DeployKeyPath.
.PARAMETER WebRoot      Apache DocumentRoot / app root. Resolved from -WebRoot, then $env:DEPLOY_WEB_ROOT, then $DeployWebRoot.
.PARAMETER PublicHost   Host header used for the post-deploy health check.
.PARAMETER SkipBuild    Deploy the existing frontend/dist without rebuilding.
.PARAMETER SkipComposer Don't run `composer install` on the host.
.PARAMETER SkipMigrate  Don't run database migrations on the host.
.PARAMETER NoBackup     Don't keep a .deploy-backup rollback copy on the host.

.EXAMPLE
    .\scripts\deploy.ps1                       # full build + deploy + composer + migrate
.EXAMPLE
    .\scripts\deploy.ps1 -SkipBuild            # redeploy current dist without rebuilding
.EXAMPLE
    .\scripts\deploy.ps1 -SkipMigrate -SkipComposer   # code-only push
#>
[CmdletBinding()]
param(
    # Server-specific settings are NOT baked into this (now-tracked) script.
    # Each is resolved below from its -param, then $env:DEPLOY_* (the default),
    # then the untracked scripts/deploy.config.ps1. PublicHost is the public site
    # domain (not a secret), so it stays a default. See deploy.config.example.ps1.
    [string]$VpsHost = $env:DEPLOY_VPS_HOST,
    [string]$VpsUser = $env:DEPLOY_VPS_USER,
    [string]$KeyPath = $env:DEPLOY_KEY_PATH,
    [string]$WebRoot = $env:DEPLOY_WEB_ROOT,
    [string]$PublicHost = "gallery.mathdad.me",
    [switch]$SkipBuild,
    [switch]$SkipComposer,
    [switch]$SkipMigrate,
    [switch]$NoBackup
)

$ErrorActionPreference = 'Stop'
# PowerShell 7.4+ makes a non-zero native exit throw under 'Stop', bypassing our
# own $LASTEXITCODE checks. Disable so our checks/messages always run. (No-op on 5.1.)
$PSNativeCommandUseErrorActionPreference = $false

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "    $msg" -ForegroundColor Green }
function Fail($msg)       { Write-Host "`nERROR: $msg" -ForegroundColor Red; exit 1 }
trap { Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }

# ── Server settings resolution ────────────────────────────────────────────────
# Every environment-specific value lives OUTSIDE this tracked script. Resolution
# order for each: its -param, then the matching $env:DEPLOY_* variable (already
# applied as the param default), then the untracked scripts/deploy.config.ps1.
# Copy scripts/deploy.config.example.ps1 to deploy.config.ps1 and fill it in once.
$configPath = Join-Path $PSScriptRoot 'deploy.config.ps1'
if (Test-Path $configPath) { . $configPath }
if (-not $VpsHost) { $VpsHost = $DeployVpsHost }
if (-not $VpsUser) { $VpsUser = $DeployVpsUser }
if (-not $KeyPath) { $KeyPath = $DeployKeyPath }
if (-not $WebRoot) { $WebRoot = $DeployWebRoot }

$missing = @()
if (-not $VpsHost) { $missing += 'VpsHost (-VpsHost / $env:DEPLOY_VPS_HOST / $DeployVpsHost)' }
if (-not $VpsUser) { $missing += 'VpsUser (-VpsUser / $env:DEPLOY_VPS_USER / $DeployVpsUser)' }
if (-not $KeyPath) { $missing += 'KeyPath (-KeyPath / $env:DEPLOY_KEY_PATH / $DeployKeyPath)' }
if (-not $WebRoot) { $missing += 'WebRoot (-WebRoot / $env:DEPLOY_WEB_ROOT / $DeployWebRoot)' }
if ($missing.Count -gt 0) {
    Fail "Missing deploy settings:`n  - $($missing -join "`n  - ")`nSet these via -params, `$env:DEPLOY_* variables, or scripts/deploy.config.ps1 (copy scripts/deploy.config.example.ps1)."
}

# ── Resolve paths & tools ─────────────────────────────────────────────────────
$RepoRoot    = Split-Path $PSScriptRoot -Parent
$BackendDir  = Join-Path $RepoRoot "backend"       # PHP payload source
$FrontendDir = Join-Path $RepoRoot "frontend"
$DistDir     = Join-Path $FrontendDir "dist"       # vite outDir is frontend/dist

if (-not (Test-Path $KeyPath)) { Fail "SSH key not found: $KeyPath" }

function Resolve-PuttyTool($name) {
    $cmd = Get-Command $name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $candidate = Join-Path "C:\Program Files\PuTTY" "$name.exe"
    if (Test-Path $candidate) { return $candidate }
    Fail "$name not found. Install PuTTY (winget install PuTTY.PuTTY) or add it to PATH."
}
$pscp  = Resolve-PuttyTool "pscp"
$plink = Resolve-PuttyTool "plink"
if (-not (Get-Command tar -ErrorAction SilentlyContinue)) {
    Fail "tar not found. Windows 10+ ships tar.exe; ensure C:\Windows\System32 is on PATH."
}

$remoteTarget  = "$VpsUser@$VpsHost"
$remoteTarball = "$WebRoot/deploy.tgz"

# Upload one local file to a remote path, retrying once if it looks rate-limited.
function Send-File([string]$localPath, [string]$remotePath) {
    & $pscp -batch -i $KeyPath "$localPath" "${remoteTarget}:$remotePath"
    if ($LASTEXITCODE -eq 0) { return $true }
    Write-Host "    Upload failed - possibly the SSH rate limit (ufw limit ssh). Waiting 35s, then retrying once..." -ForegroundColor Yellow
    Start-Sleep -Seconds 35
    & $pscp -batch -i $KeyPath "$localPath" "${remoteTarget}:$remotePath"
    return ($LASTEXITCODE -eq 0)
}

Write-Host "Deploying Gallery -> ${remoteTarget}:$WebRoot" -ForegroundColor Yellow

# ── 1. Build the frontend ─────────────────────────────────────────────────────
if ($SkipBuild) {
    Write-Step "Skipping frontend build (-SkipBuild); using existing dist/"
} else {
    Write-Step "Building frontend (vue-tsc + vite)..."
    Push-Location $FrontendDir
    try {
        npm run build
        if ($LASTEXITCODE -ne 0) { Fail "Frontend build failed (exit $LASTEXITCODE). Live site untouched." }
    } finally { Pop-Location }
}
if (-not (Test-Path (Join-Path $DistDir "index.html"))) {
    Fail "dist/index.html is missing - nothing to deploy. (Run without -SkipBuild.)"
}
Write-Ok "Build present: $DistDir"

# ── 2. Pack the deploy tarball (code only; never data) ────────────────────────
# The repo is split into backend/ + frontend/, but the webroot is flat, so we pack
# both source roots into one archive whose members mirror that flat layout: the
# backend payload lands at the top level and the built SPA lands in dist/.
Write-Step "Packing deploy tarball..."
$tarball = Join-Path $env:TEMP ("gallery-deploy-{0}.tgz" -f [guid]::NewGuid())
# Backend payload — code only (never vendor/, .env, cache/, logs/, or the live DB).
$backendItems = @('index.php', '.htaccess', 'api', 'includes', 'db', 'phinx.php',
                  'openapi.json', 'composer.json', 'composer.lock', 'scripts')
foreach ($p in $backendItems) {
    if (-not (Test-Path (Join-Path $BackendDir $p))) { Fail "Expected path missing: backend/$p" }
}
# Two -C roots flatten backend/* and frontend/dist into one archive; the --exclude
# keeps the live SQLite DB (and its WAL/SHM sidecars) out of the bundle.
& tar -czf $tarball --exclude="db/gallery.db*" -C $BackendDir $backendItems -C $FrontendDir 'dist'
if ($LASTEXITCODE -ne 0) { Fail "tar failed (exit $LASTEXITCODE)." }
$sizeMB = (Get-Item $tarball).Length / 1MB
Write-Ok ("Tarball ready: {0:N1} MB" -f $sizeMB)

# ── 3. Upload the tarball (1 connection) ──────────────────────────────────────
Write-Step "Uploading tarball to $remoteTarball ..."
try {
    if (-not (Send-File $tarball $remoteTarball)) {
        Fail @"
Upload failed. Live site untouched.
  - If the .ppk has a passphrase, load it into Pageant first, then re-run:
        pageant.exe "$KeyPath"
  - First-ever connection also needs the host key cached once:
        plink -i "$KeyPath" $remoteTarget   (accept the fingerprint)
  - Otherwise verify host ($VpsHost), user ($VpsUser), and that $WebRoot exists.
"@
    }
} finally {
    Remove-Item $tarball -Force -ErrorAction SilentlyContinue
}
Write-Ok "Upload finished."

# ── 4. Build the remote deploy script ─────────────────────────────────────────
# Single-quoted here-strings keep bash $vars literal; {{WEBROOT}}/{{HOST}} tokens
# are substituted by PowerShell afterwards. Closing '@ must be at column 0.

$header = @'
set -e
cd "{{WEBROOT}}" || { echo "ERROR: cannot cd to {{WEBROOT}}" >&2; exit 2; }
if [ ! -f index.php ] && [ ! -f .env ]; then echo "ERROR: {{WEBROOT}} does not look like the gallery app" >&2; exit 2; fi
if [ ! -f deploy.tgz ]; then echo "ERROR: deploy.tgz not found (upload failed?)" >&2; exit 3; fi
export COMPOSER_ALLOW_SUPERUSER=1
SUCCESS=0
'@

if ($NoBackup) {
    $backup = @'
echo "==> -NoBackup: skipping rollback snapshot"
'@
} else {
    $backup = @'
echo "==> backing up current code to .deploy-backup"
BACKUP=.deploy-backup
rm -rf "$BACKUP"; mkdir -p "$BACKUP"
for p in index.php .htaccess api includes dist phinx.php openapi.json composer.json composer.lock scripts db/migrations db/setup.php; do
  if [ -e "$p" ]; then cp -a --parents "$p" "$BACKUP/"; fi
done
restore() {
  rc=$?
  if [ "$SUCCESS" = "1" ]; then exit 0; fi
  echo "ERROR: deploy failed (rc=$rc) - restoring previous code from $BACKUP" >&2
  cp -a "$BACKUP/." ./ 2>/dev/null || true
  exit $rc
}
trap restore EXIT
'@
}

$extract = @'
echo "==> extracting new code (data dirs untouched)"
tar xzf deploy.tgz
rm -f deploy.tgz
'@

if ($SkipComposer) {
    $composer = @'
echo "==> -SkipComposer: skipping composer install"
'@
} else {
    $composer = @'
echo "==> composer install (incl. dev deps: Phinx is the migration tool)"
composer install --no-interaction --prefer-dist --optimize-autoloader
'@
}

if ($SkipMigrate) {
    $migrate = @'
echo "==> -SkipMigrate: skipping database migrations"
'@
} else {
    $migrate = @'
echo "==> running migrations (baselines a legacy DB, then applies pending)"
php db/setup.php
'@
}

$finish = @'
echo "==> restoring ownership and clearing API response cache"
chown -R www-data:www-data index.php .htaccess api includes dist phinx.php openapi.json composer.json composer.lock scripts db vendor
for d in cache logs dupes; do [ -d "$d" ] && chown -R www-data:www-data "$d" || true; done
rm -f cache/api/*.cache 2>/dev/null || true
SUCCESS=1
code=$(curl -sL -o /dev/null -w "%{http_code}" https://{{HOST}}/api/media/count/ 2>/dev/null || echo "000")
echo "==> health check: GET https://{{HOST}}/api/media/count -> HTTP $code"
echo "DEPLOY_OK"
'@

$remoteScript = ($header, $backup, $extract, $composer, $migrate, $finish) -join "`n"
$remoteScript = $remoteScript.Replace('{{WEBROOT}}', $WebRoot).Replace('{{HOST}}', $PublicHost)

# ── 5. Run the remote deploy (1 connection) ───────────────────────────────────
Write-Step "Running remote deploy (extract -> composer -> migrate -> fixups)..."
$out = & $plink -batch -i $KeyPath $remoteTarget $remoteScript 2>&1
foreach ($line in $out) { if ("$line".Trim()) { Write-Host "    $line" } }

if ($LASTEXITCODE -ne 0 -or -not ($out -match 'DEPLOY_OK')) {
    Fail @"
Remote deploy failed (exit $LASTEXITCODE).
  The host automatically restored the previous code from .deploy-backup (unless -NoBackup).
  Inspect logs:  plink -i "$KeyPath" $remoteTarget "tail -n 50 $WebRoot/logs/gallery*.log"
"@
}

Write-Host "`n[OK] Gallery deployed to ${remoteTarget}:$WebRoot" -ForegroundColor Green
if (-not $NoBackup) {
    Write-Host "     Previous code kept at $WebRoot/.deploy-backup" -ForegroundColor DarkGray
    Write-Host "     Rollback: plink -i `"$KeyPath`" $remoteTarget `"cd '$WebRoot' && cp -a .deploy-backup/. ./ && chown -R www-data:www-data .`"" -ForegroundColor DarkGray
}
Write-Host "     SPA assets are content-hashed and the service worker auto-updates; no manual cache bust needed." -ForegroundColor DarkGray
