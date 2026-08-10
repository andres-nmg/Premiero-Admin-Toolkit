# Publicación de Premiero Admin Toolkit

- **Versión:** `3.4.0`
- **Etiqueta:** `v3.4.0`
- **Título de la Release:** `Premiero Admin Toolkit 3.4.0`

## Texto para la Release

## Premiero Admin Toolkit 3.4.0

Esta versión sustituye la subida remota de UpdraftPlus por una sincronización SFTP propia hacia cualquier servidor compatible. UpdraftPlus continúa generando, programando y restaurando las copias, mientras Premiero se ocupa exclusivamente de su transporte remoto.

### Cambios principales

- Nueva pestaña **Backups remotos** con activación, credenciales, estado y actividad de la cola.
- Detección automática de conjuntos terminados de UpdraftPlus.
- Cola persistente por archivo con deduplicación y reintentos progresivos.
- Transferencias SFTP reanudables mediante phpseclib 3.
- Publicación mediante archivos temporales `.part` y verificación del tamaño remoto.
- Reconciliación periódica de las copias locales y remotas.
- Actividad agrupada por copias completas, con progreso por archivos y un historial de retención separado.
- Recuperación de estados de subida interrumpidos cuando el archivo definitivo ya está verificado en el servidor SFTP.
- Reconciliación inmediata al terminar el último archivo para que la retención no quede esperando al siguiente escaneo periódico.
- Recuperación automática de archivos que falten en el servidor SFTP mientras sigan conservados por UpdraftPlus.
- Retención remota opcional que elimina únicamente conjuntos previamente gestionados por Premiero y retirados del historial de UpdraftPlus.
- Acciones **Probar conexión** y **Sincronizar ahora**, con actividad agrupada para el uso diario.
- Cifrado local de la contraseña mediante las salts de WordPress.
- Registro y comprobación de la clave pública SSH del servidor.
- Premiero Maintenance Console y el estado original de UpdraftPlus permanecen sin cambios.

### Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.
- OpenSSL para almacenar la contraseña cifrada.

### Instalación

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de esta Release e instálalo desde `Plugins > Añadir plugin > Subir plugin`.

Las instalaciones existentes recibirán esta versión mediante el actualizador normal de WordPress.

> El ZIP instalable se adjunta automáticamente cuando finaliza el workflow **Build WordPress plugin release**.
