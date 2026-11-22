# Verificación del despliegue limpio

Verificado el 21 de noviembre de 2026 con Docker Desktop, contenedores Linux x86_64 y volúmenes desechables independientes.

- Construcción completa de la imagen PHP, Imagick, FFmpeg, yt-dlp y Deno: correcta.
- Arranque de MariaDB y aplicación: ambos saludables.
- Creación de 31 tablas vacías, administrador inicial y prueba real de hardlinks: correcta.
- Inicio de sesión, rechazo de contraseña incorrecta y cambio obligatorio de contraseña: correctos.
- Creación de carpeta y nota, descarga exacta del archivo, enlaces públicos y recepción: correctos mediante HTTP local.
- Configuración, capacidades multimedia y catálogo inglés: respuestas correctas.
- Acceso directo a configuración y recursos internos: bloqueado.
- Reinicio: conserva la contraseña cambiada, la cuenta y el archivo de prueba.
- Sintaxis del instalador PowerShell y Bash: correcta.

La opción ARM64 está preparada para Raspberry Pi de 64 bits. Esta revisión no incluye una construcción nativa ARM64 del paquete nuevo; no debe interpretarse la validación de Compose como una prueba de esa arquitectura. Tampoco se ha probado una descarga real desde YouTube en este entorno limpio.

El entorno de prueba utiliza nombre de proyecto, puerto y volúmenes propios. Nunca importa una copia de los datos de producción. Las credenciales de prueba se generan fuera de los archivos distribuibles y se excluyen del exportador.
