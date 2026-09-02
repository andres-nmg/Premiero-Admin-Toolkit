# Publicación de Premiero Admin Toolkit

- **Versión:** `3.6.4`
- **Etiqueta:** `v3.6.4`
- **Título de la Release:** `Premiero Admin Toolkit 3.6.4`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.6.4

Esta versión incorpora el canal seguro de operaciones remotas utilizado por
Premiero Maintenance Console para gestionar copias y actualizaciones de plugins.

### Cambios principales

- Polling saliente firmado mediante HMAC, timestamp, nonce, expiración y
  protección contra repetición.
- Catálogo cerrado de comandos: consulta y ejecución de copias, actualización
  individual de plugins y gestión de exclusiones.
- Integración encapsulada con UpdraftPlus Free 1.26.x.
- Reutilización de una copia correcta realizada durante los siete días
  anteriores.
- Creación obligatoria de una copia nueva cuando no existe una copia reciente
  verificable.
- Cierre seguro: una copia fallida, parcial, desconocida o no confirmada impide
  la actualización.
- Actualizaciones uno a uno mediante `Plugin_Upgrader::upgrade()` y la API de
  archivos de WordPress.
- Verificación de la versión instalada y conservación del estado activo del
  plugin.
- Reactivación controlada cuando una actualización iniciada desde el
  administrador desactiva temporalmente el plugin.
- Reanudación de copias terminadas aunque WP-Cron se retrase.
- Consulta de comandos cada minuto y procesamiento alternativo durante visitas
  administrativas.
- Envío inmediato de una nueva instantánea después de una copia o actualización
  correcta.
- Registro de versión anterior, versión instalada, duración y resultado.

### Compatibilidad y límites

- Requiere WordPress 5.8 o posterior y PHP 7.4 o posterior.
- La automatización de copias está validada para UpdraftPlus Free 1.26.x.
- No incluye soporte Multisite.
- No ejecuta URLs, rutas, hooks, código ni comandos arbitrarios recibidos desde
  la consola.
- El tiempo efectivo de inicio depende de WP-Cron, del tráfico de la web y de
  la respuesta del servidor remoto.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.6.4`.
2. Crea y publica la etiqueta `v3.6.4`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.
