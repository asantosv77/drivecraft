#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
umask 077
configure_only=0
if [[ ${1:-} == --configure-only ]]; then configure_only=1; elif [[ -n ${1:-} ]]; then echo 'Uso: bash install.sh [--configure-only]' >&2; exit 2; fi
command -v docker >/dev/null || { echo 'Instala Docker Engine y Docker Compose v2 antes de continuar.' >&2; exit 1; }
docker compose version >/dev/null
architecture=$(docker info --format '{{.Architecture}}')
ask() { local answer; read -r -p "$1 [$2]: " answer; printf '%s' "${answer:-$2}"; }
secret() { od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; }
envquote() { local value=$1; [[ $value != *$'\n'* && $value != *$'\r'* ]] || return 1; value=${value//\'/\\\'}; printf "'%s'" "$value"; }
echo 'Drivecraft local: Raspberry Pi de 64 bits o PC x86_64.'
echo 'Deduplicación: almacenamiento Linux con hardlinks (ext3/ext4/XFS/Btrfs, dentro del mismo volumen).'
echo 'Se usarán volúmenes de Docker. No cambies a montajes Windows/exFAT/SMB para deduplicar.'
if [[ ! -e .env ]]; then
 project=$(ask 'Nombre del despliegue' 'drivecraft-local')
 [[ $project =~ ^[a-z0-9][a-z0-9_-]{1,50}$ ]] || { echo 'Nombre inválido.' >&2; exit 2; }
 default=2; [[ $architecture == *arm64* || $architecture == *aarch64* ]] && default=1
 choice=$(ask 'Arquitectura: 1=Raspberry Pi ARM64, 2=PC x86_64' "$default")
 case $choice in 1) platform=linux/arm64;;2) platform=linux/amd64;;*) echo 'Elige 1 o 2.' >&2;exit 2;;esac
 port=$(ask 'Puerto HTTP local (1024–65535)' '8088')
 [[ $port =~ ^[0-9]{4,5}$ ]] && ((10#$port>=1024 && 10#$port<=65535)) || { echo 'Puerto inválido.' >&2;exit 2; }
 access=$(ask 'Acceso: 1=solo este equipo, 2=red local' '1')
 case $access in 1) bind=127.0.0.1;;2) bind=0.0.0.0;;*) echo 'Elige 1 o 2.' >&2;exit 2;;esac
 admin=$(ask 'Usuario administrador inicial' 'admin')
 [[ $admin =~ ^[A-Za-z0-9_.-]{3,64}$ ]] || { echo 'Usuario inválido.' >&2;exit 2; }
 read -r -s -p 'Contraseña inicial (mínimo 12 caracteres; Enter para generar una): ' password; printf '\n'
 [[ -n $password ]] || password=$(secret)
 ((${#password}>=12 && ${#password}<=256)) || { echo 'La contraseña debe tener de 12 a 256 caracteres.' >&2;exit 2; }
 {
  echo '# Configuración local: NO subir este archivo a GitHub.'
  for pair in "DC_PROJECT_NAME=$project" "DC_PLATFORM=$platform" "DC_BIND_ADDRESS=$bind" "DC_PORT=$port" "DC_TIMEZONE=America/Mexico_City" "DC_IMAGE=$project:local" 'DC_YTDLP_VERSION=2026.10.26' "DC_ADMIN_USER=$admin" "DC_ADMIN_PASSWORD=$password" "DC_DB_PASSWORD=$(secret)" "DC_DB_ROOT_PASSWORD=$(secret)";do
   printf '%s=' "${pair%%=*}";envquote "${pair#*=}";printf '\n'
  done
 } > .env
 chmod 600 .env
 echo "Usuario inicial: $admin. Contraseña elegida/generada en deploy/.env; cambio obligatorio al entrar."
else echo 'Se conserva el .env existente. No se restablecerán cuentas ni contraseñas.';fi
docker compose --env-file .env -f docker-compose.yaml config --quiet
if ((configure_only));then echo 'Configuración preparada; no se iniciaron contenedores.';exit 0;fi
docker compose --env-file .env -f docker-compose.yaml up -d --build --wait --wait-timeout 240
echo 'Drivecraft listo: http://localhost:8088/drive/ por defecto (usa el puerto elegido).'
echo 'En otro equipo de la red, usa la IP del servidor y tu puerto. Esta instalación usa HTTP local.'
