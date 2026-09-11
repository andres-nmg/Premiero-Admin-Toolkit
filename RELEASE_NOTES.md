# Publicación de Premiero Admin Toolkit

- **Versión:** `3.7.0`
- **Etiqueta:** `v3.7.0`
- **Título de la Release:** `Premiero Admin Toolkit 3.7.0`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.7.0

Esta versión incorpora un nuevo entorno avanzado de diagnóstico y reparación para
facilitar la investigación de errores, problemas de rendimiento, configuraciones
anómalas y posibles incidencias de seguridad en instalaciones WordPress.

### Cambios principales

- Nuevo modo de diagnóstico avanzado, activable expresamente desde la pantalla
  Acerca de del Toolkit.
- Nueva pestaña Diagnóstico integrada en la interfaz de administración existente.
- Información técnica de WordPress, PHP, memoria, entorno y configuración de
  depuración.
- Revisión de `wp-config.php` con ocultación automática de contraseñas, salts,
  claves y otros valores sensibles.
- Análisis de `.htaccess`, administradores, plugins activos, MU-plugins y drop-ins.
- Revisión de WP-Cron y detección orientativa de tareas potencialmente sospechosas.
- Visor de `debug.log` y registro temporal de errores PHP sin modificar
  `wp-config.php`.
- Controles para generar entradas de prueba, actualizar y vaciar el registro de
  errores.
- Localización de archivos PHP modificados recientemente.
- Búsqueda heurística de patrones de código potencialmente sospechosos, mostrando
  siempre las coincidencias como elementos a revisar y no como malware confirmado.
- Reparaciones controladas con análisis previo, selección explícita y
  confirmación antes de aplicar cambios.
- Herramientas para regenerar reglas de enlaces permanentes, eliminar eventos
  concretos de WP-Cron y eliminar transients seleccionados.
- Verificación posterior de las reparaciones para confirmar que el cambio se ha
  aplicado correctamente.
- Nueva consola PHP avanzada para ejecutar código puntual dentro de WordPress sin
  escribirlo en `functions.php`, MU-plugins ni otros archivos persistentes.
- La consola PHP ejecuta el código una única vez y no lo almacena después de la
  petición.
- Historial limitado de diagnósticos y reparaciones con fecha, usuario, resultado
  y cambios realizados.
- Generación de un informe resumido y copiable con el estado de la instalación y
  las reparaciones recientes.
- Nueva interfaz compacta en dos columnas para trabajar con diagnósticos,
  reparaciones, logs, consola, historial e informe sin crecimiento excesivo de
  la página.
- Mejoras visuales del visor de logs y nueva presentación de la consola PHP con
  aspecto de terminal técnico.

### Seguridad y límites

- El modo diagnóstico debe activarse expresamente y sólo está disponible para
  usuarios con permisos administrativos.
- Todas las acciones sensibles están protegidas mediante capabilities y nonces.
- Las reparaciones nunca se ejecutan automáticamente como resultado de un
  diagnóstico.
- Los valores sensibles detectados en archivos de configuración o logs se
  redactan antes de mostrarse.
- La búsqueda de patrones sospechosos es heurística y no sustituye a una
  herramienta de seguridad o antivirus como Wordfence.
- La consola PHP no dispone de un sandbox real: el código ejecutado puede
  modificar archivos o datos con los permisos disponibles para WordPress.
- El código introducido en la consola PHP no se guarda ni se vuelve a ejecutar
  automáticamente.
- Los escaneos de archivos sólo se ejecutan bajo demanda y disponen de límites
  para reducir el impacto sobre el servidor.
- El registro temporal de errores empieza a actuar cuando WordPress carga el
  Toolkit y no puede capturar errores producidos antes de ese punto del bootstrap.
- No incluye soporte Multisite.

### Compatibilidad

- Requiere WordPress 5.8 o posterior y PHP 7.4 o posterior.
- Mantiene las funcionalidades existentes de personalización, mantenimiento,
  copias de seguridad, repositorio y conexión con Premiero Maintenance Console.
- Compatible con el sistema de actualización mediante GitHub Releases utilizado
  por versiones anteriores.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.7.0`.
2. Crea y publica la etiqueta `v3.7.0`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.