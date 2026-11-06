# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.5`
- **Etiqueta:** `v3.8.5`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.5`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.5

Expone las funciones del Toolkit como herramientas MCP mediante la Abilities
API de WordPress 6.9.

### Cambios principales

- 14 abilities en la categoría `premiero`. Easy MCP AI las publica como
  `wp_ability_premiero_<nombre>` una vez marcadas en sus ajustes.
- **Telegram:** `telegram-status` y `telegram-send`.
- **Código:** `code-get` y `code-update`. Por defecto añade el código al final;
  también sustituye un fragmento exacto o reemplaza el bloque. El PHP se valida
  antes de guardarse y se conserva la copia `.bak` del MU-plugin.
- **Monitorización:** `monitoring-status`, solo lectura.
- **Diagnóstico:** `diagnostics-list`, `diagnostics-run`, `diagnostics-repair`
  (con vista previa) y `php-run` (consola PHP personalizada). Todo queda en el
  historial marcado como «(MCP)».
- **Web y SEO:** `http-check` (redirecciones, tiempos y certificado SSL, con
  protección SSRF), `verify-page`, `seo-head` y `seo-update` (Rank Math o Yoast).
- **Redirecciones:** `redirect-manage` con el plugin Redirection o, si no está
  activo, con almacenamiento propio aplicado en `template_redirect`.

### Después de actualizar

- Las herramientas solo aparecen con WordPress 6.9 o posterior y un plugin MCP
  que exponga la Abilities API. En Easy MCP AI hay que marcarlas en sus ajustes.
- Las de diagnóstico requieren el modo diagnóstico activo.
- La consola PHP y las reparaciones requieren además el nuevo interruptor
  **Premiero > Acerca de > Permitir consola PHP y reparaciones por MCP**,
  apagado por defecto.

### Limitaciones conocidas

- Probado con Yoast SEO, Rank Math y Redirection 5.10.1.
- Rank Math no genera salida en el frontend hasta completar su asistente
  (conectar la cuenta u omitir el registro); `seo-head` lo indica.
- Rank Math solo publica `og:image` si la imagen está en la biblioteca de
  medios; con una URL externa `seo-update` la guarda y devuelve un aviso.
- Si Redirection está activo pero sin su asistente completado (sin tablas),
  `redirect-manage` devuelve un error en lugar de usar el almacenamiento propio.
- `verify-page` y `seo-head` analizan el HTML servido, así que reflejan la caché
  de página si la hay.

### Compatibilidad

- Requiere WordPress 5.8 o posterior y PHP 7.4 o posterior. Las herramientas MCP
  necesitan WordPress 6.9 o posterior; en versiones anteriores no se registran.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.8.5`.
2. Crea y publica la etiqueta `v3.8.5`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.