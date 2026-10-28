# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.2`
- **Etiqueta:** `v3.8.2`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.2`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.2

Incluye la migración de la consola a `hub.premiero.es` (3.8.1) y corrige los
emparejamientos rechazados con HTTP 409.

### Cambios principales

- Las instalaciones que apuntan a
  `https://www.premiero.es/cms/wp-json/premiero-console/v1` pasan a usar
  automáticamente `https://hub.premiero.es/wp-json/premiero-console/v1`, sin
  volver a emparejar.
- El error de emparejamiento muestra el motivo y el código que devuelve la
  consola, en lugar de solo el estado HTTP.
- Si la consola indica que la instancia pertenece a otro dominio (sitio
  clonado), el Toolkit regenera su identidad y reintenta una vez con el mismo
  token, creando una ficha nueva.
- Para conservar una ficha existente, genera el token de reemparejamiento
  desde esa ficha. Requiere Premiero Maintenance Console 1.10.1.

### Compatibilidad

- Sin cambios de requisitos respecto a 3.8.0. Requiere WordPress 5.8 o posterior y PHP 7.4 o
  posterior.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.8.2`.
2. Crea y publica la etiqueta `v3.8.2`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.