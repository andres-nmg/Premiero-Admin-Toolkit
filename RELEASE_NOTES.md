# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.7`
- **Etiqueta:** `v3.8.7`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.7`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.7

Privacidad de las instalaciones.

### Cambios principales

- **Carpeta del plugin oculta** para visitantes sin sesión (Apache y LiteSpeed): reciben la página 404 de WordPress, como si el plugin no existiera. Las reglas se escriben en un bloque propio del `.htaccess` raíz y se retiran al desactivar.
- **Mu-plugins con nombre propio de cada web.** Los existentes se renombran automáticamente sin cambiar su contenido.
- **Copia de los snippets protegida.** El antiguo `.bak` se traslada a una carpeta protegida y se elimina.
- Sin marcas del plugin en el HTML público, en la pantalla de acceso ni en la cabecera de las redirecciones.
