# Publicación de Premiero Admin Toolkit

- **Versión:** `3.7.1`
- **Etiqueta:** `v3.7.1`
- **Título de la Release:** `Premiero Admin Toolkit 3.7.1`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.7.1

Esta versión refina la sección Diagnóstico introducida en 3.7.0 a partir del uso
real. No incorpora grandes funcionalidades nuevas: reduce el ruido del análisis
heurístico, mejora la clasificación de algunos hallazgos y completa la
integración visual de la pestaña con el modo oscuro del Toolkit.

### Cambios principales

- El análisis heurístico de patrones sospechosos deja de recorrer `wp-admin`,
  `wp-includes`, las librerías de dependencias (`/vendor/` y `/node_modules/`)
  y la propia carpeta del Toolkit, cuyas funciones de diagnóstico contienen
  llamadas de riesgo por diseño y aparecían siempre en el listado.
- El resultado indica expresamente qué queda fuera de esta revisión heurística,
  sin verificarlo ni afirmar que esté limpio.
- Los comentarios de bloque y de línea se ignoran antes de buscar patrones: las
  funciones citadas en documentación, anotaciones `phpcs` o prosa dejan de
  producir avisos. Las cadenas con URLs (`://`) y los `#` que no abren línea se
  respetan para no alterar el código analizado.
- Cada archivo aparece una sola vez, con sus patrones agrupados, y sólo se marca
  «Prioridad alta» cuando el mismo archivo combina decodificación, descompresión
  y ejecución dinámica (`base64_decode` + `gzinflate` + `eval`), o cuando una
  superglobal (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) se ejecuta dentro de
  la propia llamada a `eval` o `assert`. El uso de superglobales en otro punto
  del archivo ya no eleva la relevancia por sí solo.
- Las coincidencias de baja relevancia (`base64_decode`, `gzinflate`, `hex2bin`,
  `assert`, `move_uploaded_file` aislados) se contabilizan aparte y dejan de
  llenar el listado: en esta instalación pasan de 83 archivos listados a 0, y las
  26 coincidencias restantes corresponden a patrones legítimos de Elementor,
  Pro Elements, UpdraftPlus y Premiero Maintenance Console.
- La ubicación del archivo eleva el resultado a «Revisar» sin clasificarlo como
  malware: PHP inesperado en la raíz de WordPress o PHP bajo
  `wp-content/uploads/`.
- Las coincidencias se siguen mostrando como «coincidencias a revisar» y nunca
  como malware confirmado.
- La ausencia de `DISALLOW_FILE_EDIT` pasa a mostrarse como recomendación de
  seguridad y deja de contabilizarse como aviso o problema.
- Las redirecciones externas detectadas en `.htaccess` se muestran como
  información a revisar, sin clasificarlas automáticamente como sospechosas ni
  modificar ninguna regla.
- La pestaña Diagnóstico respeta el modo oscuro del Toolkit: información del
  sistema, visor de logs, fichas de estado, resultados, selector de diagnósticos,
  reparaciones, historial, informe y paneles auxiliares dejan de mostrar fondos
  blancos y mantienen contraste suficiente.
- «Historial e informe» se presenta en dos fichas: el historial reciente con su
  acción de limpieza y el informe con acción de copia al portapapeles, con el
  texto del informe en tipografía monoespaciada y desplazamiento propio.
- «Información básica del sistema» se presenta ahora como fichas compactas, con
  el mismo lenguaje visual que el registro temporal de errores.
- La consola PHP incorpora dos acciones discretas en su barra superior: «Limpiar
  editor» y «Limpiar salida». Actúan sólo sobre el editor o la salida, sin AJAX
  y sin modificar el historial.

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
- `wp-admin`, `wp-includes`, las librerías de dependencias y la propia carpeta
  del Toolkit quedan fuera de esa revisión heurística: no se comprueban sumas del
  Core ni se realizan llamadas nuevas a WordPress.org.
- El análisis se aplica al código ejecutable: los comentarios se descartan, por lo
  que una función de riesgo citada sólo en documentación no se reporta.
- El listado de patrones no incluye el uso de superglobales por sí solo; sólo
  cuenta cuando aparece dentro de una llamada a `eval` o `assert`.
- La ausencia de `DISALLOW_FILE_EDIT` y las redirecciones externas de `.htaccess`
  son informativas: el Toolkit no modifica `wp-config.php` ni las reglas de
  `.htaccess`.
- Los botones de limpieza de la consola actúan sólo sobre la interfaz, sin
  peticiones al servidor y sin alterar el historial de diagnósticos.
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
- El modo oscuro de la pestaña Diagnóstico reutiliza el mecanismo existente del
  Toolkit (`body.premiero-admin-dark` y sus variables), sin introducir un segundo
  sistema de temas.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.7.1`.
2. Crea y publica la etiqueta `v3.7.1`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.