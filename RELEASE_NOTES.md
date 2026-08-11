# Publicación de Premiero Admin Toolkit

- **Versión:** `3.4.3`
- **Etiqueta:** `v3.4.3`
- **Título de la Release:** `Premiero Admin Toolkit 3.4.3`

## Texto para la Release

## Premiero Admin Toolkit 3.4.3

Esta versión es un parche de estabilidad para el gestor de avisos incorporado en 3.4.2. Corrige la detección y la ocultación entre distintas pantallas del administrador y elimina por completo los avisos utilizados durante las pruebas.

### Cambios principales

- Eliminados todos los avisos de demostración; no se mostrarán en instalaciones nuevas ni actualizadas.
- Limpieza automática de las demostraciones que hubieran quedado registradas con la versión 3.4.2.
- Nueva firma estable para reconocer el mismo aviso en Escritorio, Plugins y otras secciones.
- Unificación automática de registros duplicados conservando el estado oculto y el historial.
- La ocultación se aplica en cualquier pantalla donde vuelva a aparecer el mismo mensaje.
- Detección de avisos añadidos dinámicamente por JavaScript después de cargar la página.
- Captura ampliada en administración normal, administración de red, administración de usuario y paneles dinámicos de plugins.
- Detección de avisos nativos, banners personalizados y promociones aunque aparezcan dentro del contenido de una pantalla.
- Exclusión de los mensajes operativos temporales del editor de bloques, como guardado, publicación o confirmaciones tipo *snackbar*.
- Persistencia reforzada al usar la X nativa, incluso si se cambia de pantalla inmediatamente después.
- Los paneles dinámicos que reutilizan un mismo contenedor recalculan la identidad del mensaje y no arrastran ocultaciones anteriores.
- Corregido el tamaño del buscador y rediseñadas las fichas responsive en tablet y móvil.
- Sin cambios en Premiero Control, copias SFTP ni el resto de funciones existentes.

### Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.
- OpenSSL para almacenar la contraseña cifrada.

### Instalación

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de esta Release e instálalo desde `Plugins > Añadir plugin > Subir plugin`.

Las instalaciones existentes recibirán esta versión mediante el actualizador normal de WordPress.

> El ZIP instalable se adjunta automáticamente cuando finaliza el workflow **Build WordPress plugin release**.
