# Publicación de Premiero Admin Toolkit

- **Versión:** `3.3.4`
- **Etiqueta:** `v3.3.4`
- **Título de la Release:** `Premiero Admin Toolkit 3.3.4`

## Texto para la Release

## Premiero Admin Toolkit 3.3.4

Esta versión evita que los avisos de actualización detectados por Wordfence aparezcan duplicados como incidencias de seguridad en Premiero Maintenance Console.

### Cambios principales

- Los avisos de actualización de plugins, temas y WordPress se muestran únicamente en el bloque de actualizaciones de la consola.
- Wordfence continúa analizando y mostrando esos avisos dentro de su propio panel.
- Las vulnerabilidades, el malware, los archivos alterados y los demás hallazgos de seguridad continúan informándose a la consola.
- Se mantiene un fallback compatible con versiones de Wordfence que no permitan enumerar sus incidencias.

### Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.

### Instalación

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de esta Release e instálalo desde `Plugins > Añadir plugin > Subir plugin`.

Las instalaciones existentes recibirán esta versión mediante el actualizador normal de WordPress.

> El ZIP instalable se adjunta automáticamente cuando finaliza el workflow **Build WordPress plugin release**.
