# Drivecraft · instalación local

Drivecraft es una aplicación web de gestión de archivos con carpetas, álbumes de fotos, bibliotecas musicales, colecciones de video, notas Markdown y permisos por usuario. Esta distribución instala únicamente Drivecraft: no incluye datos de la instalación de desarrollo ni otros proyectos del autor.

## Requisitos

- Docker Engine con Compose v2 en Linux, o Docker Desktop con **contenedores Linux** en Windows/macOS.
- Internet para la primera construcción de la imagen. No hace falta instalar PHP, MariaDB, FFmpeg, yt-dlp o Python en el equipo anfitrión.
- Arquitecturas: **Raspberry Pi con sistema operativo de 64 bits (`linux/arm64`)** o **PC x86_64 (`linux/amd64`)**. No se ofrece ARM de 32 bits.
- Espacio para las imágenes de Docker y los archivos que se vayan a guardar. La primera compilación puede tardar varios minutos, especialmente en Raspberry Pi.

## Instalar

Desde una copia del repositorio o del paquete descomprimido:

**Raspberry Pi / Linux / macOS:**

```bash
cd drivecraft/deploy
bash install.sh
```

**Windows:** abre `deploy/Instalar.cmd`, o desde PowerShell:

```powershell
cd drivecraft\deploy
.\Instalar.cmd
```

El instalador pregunta el nombre del despliegue, la arquitectura, el puerto HTTP, si se permite acceso desde la red local, el usuario administrador y su contraseña inicial. Enter en la contraseña genera una aleatoria. Detecta la arquitectura del motor Docker para proponer la opción adecuada.

La configuración se guarda en **`deploy/.env`**, que no se incluye en Git. Las contraseñas internas de la base de datos se generan automáticamente. La contraseña inicial elegida/generada puede consultarse en ese archivo; no hay una contraseña de administrador universal.

Por defecto se abre **http://localhost:8088/drive/** y el usuario propuesto es **`admin`**. Si elegiste otro puerto o nombre, utiliza esos valores. Para acceder desde otro equipo debes haber elegido «red local» y usar `http://IP_DEL_SERVIDOR:PUERTO/drive/`.

El primer inicio crea las tablas vacías y la cuenta administradora, y obliga a cambiar su contraseña al entrar. Ejecutar de nuevo el instalador conserva `.env`, datos y cuentas. **Cambiar `DC_ADMIN_PASSWORD` después no restablece la contraseña de una cuenta existente.**

Para preparar `.env` sin construir ni iniciar contenedores:

```bash
bash install.sh --configure-only
```

```powershell
.\Instalar.cmd -ConfigureOnly
```

## Almacenamiento y deduplicación

Este despliegue está pensado para almacenamiento **Linux con soporte real de enlaces duros**, como **ext3, ext4, XFS o Btrfs**. La deduplicación mediante hardlinks exige además que origen y destino estén en **el mismo sistema de archivos/volumen** y que los permisos permitan crearlos. El instalador de la aplicación realiza una prueba de enlace duro, comparando dispositivo, inodo y número de enlaces; no se limita a confiar en el nombre del sistema de archivos.

Por eso se usan **volúmenes nombrados de Docker**. En Docker Desktop se encuentran dentro de su máquina Linux, aunque el anfitrión sea Windows o macOS. No sustituyas `/drive` por una carpeta NTFS, exFAT, FAT, SMB o una unidad compartida de Windows esperando conservar las garantías de deduplicación de esta distribución. La detección por hash de contenido y el ahorro físico mediante hardlinks son funciones diferentes.

Los volúmenes son propios de cada nombre de despliegue: `storage` conserva archivos, miniaturas, sesiones, temporales y datos privados de mantenimiento; `database` conserva MariaDB. La base de datos no publica un puerto al anfitrión.

## Qué incluye y qué se retiró

- Una imagen de aplicación con Apache/PHP, GD/Imagick, Poppler, FFmpeg/FFprobe, yt-dlp y Deno. Las descargas y conversiones se ejecutan en el servidor del despliegue.
- MariaDB y creación automática del esquema completo vacío. No se importa ningún registro de usuarios, ajustes, enlaces públicos, sesiones o archivos.
- Supervisor de tareas integrado: importaciones de audio/video, vistas previas, conversiones y mantenimiento de servicios externos. **No hay que añadir crons al anfitrión.**
- Selector español/inglés y funciones actuales de la aplicación.
- Sin Nginx, Let’s Encrypt, dominio obligatorio, red Docker externa ni montajes de rutas de la Raspberry del desarrollador. Tampoco Docker CLI/socket, servicios Kindle o Sparrow-Fy dentro de la imagen.

El acceso local utiliza **HTTP**. Los enlaces compartidos y de recepción usan el esquema y puerto de la URL abierta en el navegador. Para publicar la aplicación en Internet, configura HTTPS en tu infraestructura y `DC_COOKIE_SECURE=1` en `.env`; este paquete no despliega certificados ni proxy público. Funciones del navegador que exijan un contexto seguro pueden requerir HTTPS fuera de `localhost`.

La revisión semanal con IA permanece sin activar y sin token en una instalación limpia. Se configura desde la aplicación si se desea utilizar. Cada copia conserva su propia clave privada para cifrar el token.

## Operación

Desde `deploy/`:

```bash
docker compose ps
docker compose logs --tail 80 app
docker compose logs --tail 80 db
docker compose stop
docker compose start
docker compose down
```

`down` conserva los volúmenes. **`down -v` elimina definitivamente la base de datos y los archivos de ese despliegue**; úsalo únicamente si deseas borrar esa copia por completo.

Las actualizaciones no se deben confundir con un despliegue vacío. Haz respaldo de **ambos volúmenes** y de `.env` antes de actualizar. Si cambia el esquema empaquetado, el arranque lo detecta y solicita una migración revisada; no intenta transformar datos existentes a ciegas. Las reparaciones del adaptador hechas en la capa del contenedor se reemplazan al recrear la imagen; sus respaldos privados quedan en el volumen para revisarlos/restaurarlos.

Para actualizar yt-dlp, cambia `DC_YTDLP_VERSION` en `.env` a una versión publicada y reconstruye con `docker compose build --pull app` y `docker compose up -d`. La revisión con IA puede proponer cambios acotados al adaptador, pero no instala dependencias del sistema.

## Preparar un paquete para GitHub

El mantenedor puede ejecutar, con Python 3:

```bash
python3 deploy/export-release.py
```

Se crea un archivo en `deploy/dist/` con código, recursos de terceros, esta guía, `.gitignore`, `.gitattributes` y un manifiesto SHA-256 de los archivos. El exportador usa una lista explícita de archivos de despliegue y tipos de código; no empaqueta `.env`, configuración del anfitrión, bases de datos, respaldos, volúmenes ni archivos subidos. También genera el SHA-256 del paquete.

Para un repositorio nuevo, descomprime **ese paquete limpio** en un directorio aparte y publica su contenido. No publiques el directorio de una instalación en uso completo. El código propio se distribuye bajo licencia MIT; consulta `LICENSE`. Las bibliotecas de `vendor/` mantienen sus licencias y avisos incluidos. 

`schema.json` contiene solamente definiciones de tablas. Para regenerarlo desde una versión de desarrollo revisada, ejecuta `deploy/export-schema.php` por CLI en el entorno de esa aplicación y revisa el resultado antes de reemplazar el esquema de la distribución.

## Referencias de dependencias

- [Imagen oficial de PHP](https://hub.docker.com/_/php)
- [Healthcheck de la imagen oficial MariaDB](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/using-healthcheck-sh)
- [yt-dlp y dependencias recomendadas](https://github.com/yt-dlp/yt-dlp#dependencies)
- [Versión de yt-dlp usada inicialmente](https://pypi.org/project/yt-dlp/2026.10.26/)
