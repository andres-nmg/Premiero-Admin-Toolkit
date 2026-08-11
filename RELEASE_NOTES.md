# Publicación de Premiero Admin Toolkit

- **Versión:** `3.4.2`
- **Etiqueta:** `v3.4.2`
- **Título de la Release:** `Premiero Admin Toolkit 3.4.2`

## Texto para la Release

## Premiero Admin Toolkit 3.4.2

Esta versión incorpora un gestor de avisos para recuperar el control del panel de WordPress. Premiero registra los avisos que muestran WordPress y otros plugins y permite ocultar individualmente promociones, mensajes repetitivos o recordatorios que no aporten valor.

### Cambios principales

- Nueva pestaña **Avisos** integrada en Premiero Admin Toolkit.
- Registro automático de los avisos mostrados en las pantallas de administración.
- Identificación del origen, tipo, pantalla, primera y última aparición.
- Contador de frecuencia para detectar avisos repetitivos.
- Acciones para ocultar, volver a mostrar o eliminar avisos del registro.
- Búsqueda, filtros y acciones en bloque.
- Ocultación reversible aplicada solo a los avisos seleccionados por el administrador.
- La X nativa de los avisos también guarda la ocultación de forma persistente.
- Ningún aviso se bloquea automáticamente.
- Dos avisos de prueba persistentes, uno de actualización y otro de versión Pro, mostrados en la administración como los de un plugin real.
- Interfaz responsive coherente con el resto de pestañas del Toolkit.
- Sin cambios en Premiero Control, copias SFTP ni el resto de funciones existentes.

### Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.
- OpenSSL para almacenar la contraseña cifrada.

### Instalación

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de esta Release e instálalo desde `Plugins > Añadir plugin > Subir plugin`.

Las instalaciones existentes recibirán esta versión mediante el actualizador normal de WordPress.

> El ZIP instalable se adjunta automáticamente cuando finaliza el workflow **Build WordPress plugin release**.
