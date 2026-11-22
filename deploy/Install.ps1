[CmdletBinding()]
param([switch]$ConfigureOnly)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Instala Docker Desktop con contenedores Linux y vuelve a ejecutar este script.' }
& docker compose version | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Se necesita Docker Compose v2.' }
$architectureResult = & docker info --format '{{.Architecture}}' 2>$null
if ($LASTEXITCODE -ne 0) { throw 'Inicia Docker Desktop y comprueba el acceso al motor Docker.' }
$architecture = ([string]$architectureResult).Trim()
function Ask([string]$Label,[string]$Default) {
    $answer = Read-Host "$Label [$Default]"
    if ([string]::IsNullOrWhiteSpace($answer)) { return $Default }
    return $answer.Trim()
}
function Secret {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
}
function EnvQuote([string]$Value) {
    if ($Value.Contains("`n") -or $Value.Contains("`r")) { throw 'Los valores no pueden contener saltos de línea.' }
    return "'" + $Value.Replace("'","\'") + "'"
}
Write-Host 'Drivecraft local: Raspberry Pi 64 bits o PC x86_64.'
Write-Host 'La deduplicación requiere almacenamiento Linux con hardlinks (ext3/ext4/XFS/Btrfs y el mismo volumen).'
Write-Host 'En Windows/macOS se usarán volúmenes de Docker dentro de su máquina Linux; no carpetas NTFS/exFAT/SMB.'
if (-not (Test-Path -LiteralPath '.env')) {
    $project = Ask 'Nombre del despliegue' 'drivecraft-local'
    if ($project -notmatch '^[a-z0-9][a-z0-9_-]{1,50}$') { throw 'Nombre de despliegue inválido; usa minúsculas, números, guiones o guion bajo.' }
    $defaultPlatform = if ($architecture -match 'arm64|aarch64') { '1' } else { '2' }
    $choice = Ask 'Arquitectura: 1=Raspberry Pi ARM64, 2=PC x86_64' $defaultPlatform
    $platform = switch ($choice) { '1' { 'linux/arm64' }; '2' { 'linux/amd64' }; default { throw 'Elige 1 o 2.' } }
    $portText = Ask 'Puerto HTTP local (1024–65535)' '8088'
    $portNumber = 0
    if (-not [int]::TryParse($portText,[ref]$portNumber) -or $portNumber -lt 1024 -or $portNumber -gt 65535) { throw 'Puerto inválido.' }
    $access = Ask 'Acceso: 1=solo esta PC, 2=red local' '1'
    $bind = switch ($access) { '1' { '127.0.0.1' }; '2' { '0.0.0.0' }; default { throw 'Elige 1 o 2.' } }
    $admin = Ask 'Usuario administrador inicial' 'admin'
    if ($admin -notmatch '^[A-Za-z0-9_.-]{3,64}$') { throw 'Usuario inválido: usa 3–64 letras, números, puntos, guiones o guion bajo.' }
    $securePassword = Read-Host 'Contraseña inicial (mínimo 12 caracteres; Enter para generar una)' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    if ([string]::IsNullOrEmpty($password)) { $password = Secret }
    if ($password.Length -lt 12 -or $password.Length -gt 256) { throw 'La contraseña debe tener de 12 a 256 caracteres.' }
    $values = [ordered]@{
        DC_PROJECT_NAME=$project; DC_PLATFORM=$platform; DC_BIND_ADDRESS=$bind; DC_PORT=$portText
        DC_TIMEZONE='America/Mexico_City'; DC_IMAGE=($project+':local'); DC_YTDLP_VERSION='2026.10.26'
        DC_ADMIN_USER=$admin; DC_ADMIN_PASSWORD=$password; DC_DB_PASSWORD=(Secret); DC_DB_ROOT_PASSWORD=(Secret)
    }
    $lines = @('# Configuración local: NO subir este archivo a GitHub.')
    foreach ($key in $values.Keys) { $lines += $key+'='+(EnvQuote ([string]$values[$key])) }
    [IO.File]::WriteAllText((Join-Path $PSScriptRoot '.env'),(($lines -join "`n")+"`n"),(New-Object Text.UTF8Encoding($false)))
    Write-Host "Usuario inicial: $admin. La contraseña elegida/generada está en deploy/.env. Deberás cambiarla al entrar."
} else { Write-Host 'Se conserva el .env existente. No se restablecerán cuentas ni contraseñas.' }
& docker compose --env-file .env -f docker-compose.yaml config --quiet
if ($LASTEXITCODE -ne 0) { throw 'La configuración Compose no es válida.' }
if ($ConfigureOnly) { Write-Host 'Configuración preparada; no se iniciaron contenedores.'; exit 0 }
& docker compose --env-file .env -f docker-compose.yaml up -d --build --wait --wait-timeout 240
if ($LASTEXITCODE -ne 0) { throw 'No se completó el arranque. Consulta: docker compose logs --tail 80' }
Write-Host 'Drivecraft listo. URL predeterminada: http://localhost:8088/drive/ (usa el puerto elegido).'
Write-Host 'Para otra PC de tu red, usa la IP del servidor y el puerto elegido. Esta instalación usa HTTP local.'
