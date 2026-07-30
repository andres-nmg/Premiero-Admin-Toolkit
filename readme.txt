=== Premiero Admin Toolkit ===
Contributors: andres-nmg
Tags: admin, tools, snippets, repository, login
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 3.3.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Herramientas de administración, personalización e instalación para WordPress.

== Description ==

Premiero Admin Toolkit reúne en una única interfaz herramientas para gestionar código personalizado, organizar el menú de administración, instalar plugins y temas y personalizar la pantalla de acceso.

La pestaña Identidad permite adaptar el nombre y el logo del plugin para cada cliente sin crear versiones separadas. La configuración permanece guardada al actualizar.

La pestaña Monitorización permite emparejar una instalación con Premiero Maintenance Console. La comunicación siempre parte desde WordPress, está firmada y solo envía un resumen técnico; no admite acciones remotas ni transmite credenciales.

Las actualizaciones estables se distribuyen mediante GitHub Releases desde:

https://github.com/andres-nmg/premiero-admin-toolkit/

== Installation ==

1. Descarga `premiero-admin-toolkit.zip` desde la última Release del repositorio.
2. Sube el archivo desde `Plugins > Añadir plugin > Subir plugin`.
3. Activa Premiero Admin Toolkit.
4. Abre `Premiero` en el menú de administración.

Si el sitio utiliza TecnoDerecho Admin Toolkit, instala y activa Premiero Admin Toolkit sin desactivar manualmente el anterior. Durante la activación se migrarán su configuración, identidad, logo y snippets. TecnoDerecho quedará inactivo y podrá eliminarse después de comprobar el aviso de migración.

== Frequently Asked Questions ==

= ¿Puedo modificar y redistribuir el plugin? =

Sí. El proyecto se distribuye bajo GPLv3 o posterior. Conserva los avisos de licencia y autoría e indica claramente los cambios realizados.

= ¿Cómo se reciben las actualizaciones? =

WordPress consulta la última Release estable del repositorio público de GitHub. Cuando existe una versión superior, aparece como una actualización normal del plugin.

== Changelog ==

= 3.3.5 =

* Wordfence informa por separado el total de incidencias y los avisos que corresponden únicamente a actualizaciones.
* La consola puede filtrar centralmente las actualizaciones sin ocultar vulnerabilidades, malware u otros hallazgos.

= 3.3.4 =

* Evitado que los avisos de actualización de Wordfence se dupliquen como incidencias de seguridad en Premiero Maintenance Console.
* Se siguen informando las vulnerabilidades, el malware, los archivos alterados y el resto de hallazgos de seguridad de Wordfence.

= 3.3.3 =

* Renovada la pestaña Acerca de con una presentación responsive, accesos rápidos y soporte integrado.
* Conservado el funcionamiento de las herramientas, la identidad personalizada y la monitorización.
* Añadida la licencia GPL completa y documentación preparada para distribución pública.

= 3.3.2 =

* El fondo oscuro de logos y favicons se aplica únicamente a imágenes predominantemente claras.

= 3.3.1 =

* Añadido el favicon de la instalación a los datos visuales enviados a la consola.

= 3.3.0 =

* Añadida conexión de solo lectura con Premiero Maintenance Console.
* Incorporados emparejamiento de un solo uso, firma HMAC, sincronización cada doce horas y reintentos con backoff.
* Añadidos estados resumidos de WordPress, PHP, actualizaciones, UpdraftPlus y Wordfence.
* Añadido cálculo semanal y cacheado del tamaño de la instalación.

= 3.2.2 =

* Versión de prueba para validar la detección y distribución automática de actualizaciones desde GitHub Releases.

= 3.2.1 =

* Añadida validación de sintaxis, copia de seguridad y escritura atómica para los snippets PHP.
* Reducida la carga del plugin en el frontend y en las páginas generales del administrador.
* Añadida caché temporal para errores al consultar las actualizaciones de GitHub.
* Corregido el fallback del HTML insertado al inicio del body.
* La biblioteca multimedia solo se carga en las pestañas Login e Identidad.

= 3.2.0 =

* Añadida migración automática desde TecnoDerecho Admin Toolkit.
* Se conservan las opciones existentes y se activa automáticamente la identidad TecnoDerecho.
* Se importa el logo anterior y se migra el MU-plugin de snippets evitando ejecuciones duplicadas.
* TecnoDerecho queda inactivo después de la migración para que pueda eliminarse de forma segura.

= 3.1.0 =

* Añadida configuración de identidad personalizada con activación, nombre y logo del cliente.
* La identidad configurada se aplica al menú, la cabecera, la ficha del plugin, los textos y la pantalla de acceso.
* La configuración de cada cliente se conserva durante las actualizaciones.

= 3.0.1 =

* Añadido soporte para actualizaciones desde GitHub Releases.
* Añadida documentación para distribución como proyecto de código abierto.

= 3.0.0 =

* Revisión visual y responsive.
* Unificación de las herramientas de inyección de código.
* Mejoras de experiencia en Menú, Repositorio y Login.
