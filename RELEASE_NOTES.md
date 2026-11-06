# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.4`
- **Etiqueta:** `v3.8.4`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.4`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.4

Retira el Servidor MCP integrado y actualiza el catálogo del Repositorio.

### Cambios principales

- Eliminada la pestaña y el submenú **Servidor MCP**. StifLi Flex MCP deja de
  ir incluido en el Toolkit y ya no se instala ni se activa automáticamente.
- **Repositorio > Locales:** retirado «Servidor MCP (Flex MCP)».
- **Repositorio > WordPress.org (plugins):** añadidos StifLi Flex MCP,
  Easy MCP AI y Greenshift; retirado reSmush.it.
- **Repositorio > WordPress.org (temas):** añadido GreenShift.

### Después de actualizar

- En las webs donde Flex MCP ya estaba instalado, el plugin no se desinstala:
  sigue activo y vuelve a mostrar su propio menú de administración. Si no se
  necesita, puede desactivarse y eliminarse desde **Plugins**.
- Las webs que tengan reSmush.it instalado lo conservan; solo desaparece del
  catálogo del Repositorio.

### Compatibilidad

- Sin cambios de requisitos. Requiere WordPress 5.8 o posterior y PHP 7.4 o
  posterior.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.8.4`.
2. Crea y publica la etiqueta `v3.8.4`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.