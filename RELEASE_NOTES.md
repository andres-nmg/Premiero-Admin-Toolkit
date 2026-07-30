# Publicación de Premiero Admin Toolkit

- **Versión:** `3.3.5`
- **Etiqueta:** `v3.3.5`
- **Título de la Release:** `Premiero Admin Toolkit 3.3.5`

## Texto para la Release

## Premiero Admin Toolkit 3.3.5

Esta versión permite que Premiero Maintenance Console filtre centralmente los avisos de actualización detectados por Wordfence.

### Cambios principales

- El agente envía por separado el total de incidencias y las que corresponden únicamente a actualizaciones.
- La consola decide cuáles retirar del estado de seguridad.
- Las vulnerabilidades, el malware, los archivos alterados y los demás hallazgos continúan informándose.
- Las versiones de Wordfence que no permitan clasificar los avisos conservan el total completo como medida de seguridad.

### Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.

### Instalación

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de esta Release e instálalo desde `Plugins > Añadir plugin > Subir plugin`.

Las instalaciones existentes recibirán esta versión mediante el actualizador normal de WordPress.

> El ZIP instalable se adjunta automáticamente cuando finaliza el workflow **Build WordPress plugin release**.
