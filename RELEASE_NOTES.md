# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.1`
- **Etiqueta:** `v3.8.1`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.1`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.1

Parche de mantenimiento: la consola Premiero Maintenance Console se ha trasladado
de `https://www.premiero.es/cms/` a `https://hub.premiero.es/`.

### Cambios principales

- Las instalaciones ya emparejadas que apuntan a
  `https://www.premiero.es/cms/wp-json/premiero-console/v1` pasan a usar
  automáticamente `https://hub.premiero.es/wp-json/premiero-console/v1`.
- La migración se guarda la primera vez que el Toolkit lee la URL (siguiente
  sincronización por cron o visita a la pestaña de la consola). No hace falta
  volver a emparejar: las credenciales y la firma no dependen del host.
- Si se introduce la URL antigua al emparejar, o está fijada con
  `PREMIERO_CONSOLE_API_BASE` en `wp-config.php`, también se redirige al nuevo
  host (la constante no se modifica, solo se reinterpreta).

### Compatibilidad

- Sin cambios respecto a 3.8.0. Requiere WordPress 5.8 o posterior y PHP 7.4 o
  posterior.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.8.1`.
2. Crea y publica la etiqueta `v3.8.1`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.