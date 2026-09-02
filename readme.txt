=== Premiero Admin Toolkit ===
Contributors: andres-nmg
Tags: admin, tools, snippets, repository, login, dark mode, branding
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 3.6.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Herramientas de administración, personalización e instalación para WordPress.

== Description ==

Premiero Admin Toolkit reúne en una única interfaz herramientas para gestionar código personalizado, organizar el menú de administración, instalar plugins y temas y personalizar la pantalla de acceso.

La pestaña Identidad permite adaptar el nombre y el logo del plugin para cada cliente sin crear versiones separadas. La configuración permanece guardada al actualizar.

La pestaña Apariencia permite personalizar visualmente el administrador de WordPress: menú lateral, barra superior, colores de énfasis, fondos, botones principales y secundarios, colores independientes para títulos y textos, tipografías diferenciadas y un modo oscuro editable. Incluye Maven Pro e IBM Plex Mono como fuentes locales, vista previa y restauración segura de los estilos originales.

La pestaña Monitorización permite emparejar una instalación con Premiero Maintenance Console. La comunicación siempre parte desde WordPress y está firmada. Además de enviar el resumen técnico, el toolkit consulta una cola cerrada de órdenes para comprobar o crear copias con UpdraftPlus Free, reflejar exclusiones y actualizar plugins mediante la API nativa de WordPress. No admite código, hooks, rutas, URLs ni comandos arbitrarios.

La pestaña Copias de Seguridad detecta las copias terminadas de UpdraftPlus y las sincroniza por SFTP con cualquier servidor compatible. Las transferencias se verifican por tamaño, se reanudan cuando quedan incompletas y se reintentan automáticamente. Opcionalmente, la retención remota replica los conjuntos conservados por UpdraftPlus sin modificar UpdraftPlus ni Premiero Maintenance Console.

La pestaña Avisos registra los mensajes mostrados por WordPress y otros plugins en la administración. El administrador puede ocultar avisos concretos, restaurarlos posteriormente y consultar su origen, tipo y frecuencia sin aplicar bloqueos automáticos.

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

= ¿La subida SFTP se inicia automáticamente al terminar una copia? =

Sí. Premiero escucha la finalización correcta de UpdraftPlus, espera al menos 60 segundos sin cambios y encola todos los archivos del conjunto. Un escaneo cada 15 minutos sirve de respaldo si el hook no puede procesarse en ese momento. La instalación debe tener WP-Cron operativo.

= ¿Puede el servidor SFTP conservar exactamente las mismas copias que UpdraftPlus? =

Sí. Activa la retención remota en Premiero y configura en UpdraftPlus cuántos conjuntos quieres conservar. Premiero vuelve a subir los archivos remotos que falten y elimina del servidor SFTP los conjuntos que UpdraftPlus retire. Solo se eliminan archivos registrados previamente por Premiero, después de varias comprobaciones y cuando no existen transferencias pendientes.

== Changelog ==

= 3.6.4 =

* Si un comando se ejecuta desde una visita administrativa, el plugin se reactiva de forma controlada cuando estaba activo antes de actualizar.
* La operación falla de forma explícita si WordPress no puede completar esa reactivación.

= 3.6.3 =

* El polling de comandos pasa a ejecutarse cada minuto y las visitas administrativas consultan también los comandos pendientes.
* Después de actualizar se refrescan los metadatos nativos de WordPress y se envía inmediatamente una nueva instantánea a la consola.

= 3.6.2 =

* El actualizador remoto carga expresamente la API de archivos de WordPress cuando se ejecuta desde WP-Cron.
* Corregida la excepción inmediata al iniciar `Plugin_Upgrader` fuera de una petición administrativa.

= 3.6.1 =

* La finalización de una copia iniciada remotamente se reconcilia inmediatamente al devolver el control UpdraftPlus.
* Las visitas administrativas y «Enviar estado ahora» permiten reanudar una operación si WP-Cron se retrasa.
* La antigüedad predeterminada admitida para reutilizar una copia correcta pasa a siete días.

= 3.5.13 =

* Los selectores, radios, casillas, focos y contornos utilizan el color de énfasis configurado en todos los modos.
* Corregida la muestra visual del selector de color y unificados sus bordes, estados hover y foco.
* Revisada la respuesta adaptable completa de la pestaña Apariencia para tablet y móvil.

= 3.5.12 =

* El diseño de pestañas inspirado en Control Financiero forma parte ahora del propio Toolkit y no depende de activar Apariencia.
* El granate Premiero se utiliza como énfasis predeterminado y se sustituye por el color configurado cuando corresponde.

= 3.5.11 =

* Unificado el diseño de pestañas con la navegación de Premiero Control Financiero.
* Las pestañas se muestran directamente sobre el lienzo, con una línea base fina y subrayado exclusivo para hover y estado activo.
* El nuevo patrón se aplica a cualquier paleta mientras la personalización visual esté activada.

= 3.5.10 =

* Reproducido el patrón real de pestañas de premiero.es: fondo crema en las inactivas, hover blanco y pestaña activa fundida con el contenido.
* La línea granate aparece bajo las pestañas inactivas y desaparece bajo la activa.
* Eliminado definitivamente el espacio entre la navegación y el panel del Toolkit.

= 3.5.9 =

* Integradas las pestañas del Toolkit con el panel de contenido inferior, sin separación visual entre ambos.
* Sustituidos los fondos azules de plugins activos, controles seleccionados, avisos y focos por la paleta Premiero.
* Perfil y gestión de usuarios utilizan paneles blancos con borde y profundidad sobre el fondo crema.
* Ocultado el selector nativo de esquemas de color cuando la personalización del Toolkit está activa.

= 3.5.8 =

* Rediseñadas las pestañas del preset Premiero como una barra blanca continua, sin cajas individuales.
* La pestaña activa se identifica mediante una línea inferior granate y el hover deja de utilizar el azul de WordPress.

= 3.5.7 =

* Los botones secundarios del preset Premiero no tienen relleno y heredan visualmente el fondo blanco o crema de la superficie donde aparecen.
* Todos los botones y pestañas utilizan el redondeado de la identidad visual Premiero.
* El preset Premiero se conserva internamente al personalizar después alguno de sus colores.

= 3.5.6 =

* Eliminados los estados azules heredados de WordPress en el hover del menú y la barra superior.
* Rediseñadas las pestañas con borde granate y estado activo relleno según la identidad Premiero.
* Aplicado el color de énfasis a los bordes de botones secundarios sin fondo principal.
* Organizadas las pantallas nativas de Ajustes en cajas blancas con profundidad sobre el fondo crema.

= 3.5.5 =

* Ajustada la paleta Premiero para que el granate protagonice enlaces, pestañas, selecciones y botones secundarios.
* El verde deja de utilizarse como color global y queda reservado a estados semánticos puntuales como éxito o destacado.

= 3.5.4 =

* Añadido el ajuste rápido «Usar colores Premiero» con la identidad crema, granate, verde y gris cálido de la marca.
* La paleta Premiero se aplica como una base clara y mantiene todos sus colores editables individualmente.

= 3.5.3 =

* Corregido el fondo claro residual del panel lateral en el detalle individual de medios.

= 3.5.2 =

* Añadida profundidad visual a las pantallas nativas de Ajustes cuando se utiliza el modo oscuro.
* Adaptados al modo oscuro los modales de medios de WordPress y las ventanas emergentes de los plugins Premiero.
* Corregidas las columnas calculadas del libro de movimientos y las franjas claras del flujo de Maintenance Console.
* Reforzado el contraste de filtros, etiquetas, contadores, cabeceras y estados secundarios únicamente en modo oscuro.

= 3.5.1 =

* Añadidos colores independientes para botones principales y secundarios, incluido el color de sus textos.
* Corregido el contraste oscuro en Código, Avisos, Menú y zonas nativas de edición y publicación de WordPress.
* Reforzado el modo oscuro de las fichas de LinkedIn CRM, las hojas y ajustes de Control Financiero y la disponibilidad de Maintenance Console.
* Ajustadas cabeceras, pies de tabla, campos, etiquetas, estados neutros y CodeMirror para evitar superficies claras o textos ilegibles.

= 3.5.0 =

* Añadida la pestaña Apariencia junto a Identidad, con una interfaz visual coherente con el resto del Toolkit.
* Incorporada personalización del menú lateral, barra superior, color de énfasis, fondos, paneles y texto.
* Añadidos modos claro personalizado y oscuro con paletas recomendadas editables.
* Incluida selección de tipografía mediante fuentes locales del dispositivo, sin conexiones externas.
* Añadida una vista previa en tiempo real y controles para activar, desactivar o restaurar la apariencia original de WordPress.
* Los ajustes se validan antes de guardarse y los estilos se aplican únicamente en la administración.
* Separados los colores y las tipografías de títulos, texto principal y texto secundario.
* Incluidas localmente Maven Pro e IBM Plex Mono con sus licencias OFL y un ajuste rápido de tipografías Premiero.
* Reforzada la compatibilidad oscura de Premiero Admin Toolkit, Premiero LinkedIn CRM, Premiero Control Financiero y Premiero Maintenance Console.

= 3.4.3 =

* Eliminados por completo los avisos de demostración incluidos en 3.4.2.
* Añadida una migración automática que limpia las demostraciones ya registradas al actualizar.
* Unificada la identidad de un mismo aviso entre Escritorio, Plugins y el resto de pantallas del administrador.
* Los avisos equivalentes se consolidan en una sola ficha y conservan su estado oculto.
* Incorporada detección de avisos y banners añadidos dinámicamente después de cargar la página.
* Ampliada la captura en administración normal, administración de red, administración de usuario y paneles dinámicos de plugins.
* Excluidos los mensajes operativos temporales del editor de bloques, como guardado, publicación y confirmaciones tipo snackbar.
* La ocultación nativa se guarda aunque se cambie de pantalla inmediatamente después de pulsar la X.
* Los paneles dinámicos que reutilizan un contenedor recalculan el aviso y nunca heredan una ocultación anterior por error.
* Corregido el responsive del buscador y de las fichas de avisos en tablet y móvil.
* Sin cambios en Premiero Control, copias SFTP ni el resto de módulos.

= 3.4.2 =

* Añadida la pestaña Avisos para registrar los mensajes mostrados en la administración de WordPress.
* Incorporado control manual para ocultar, restaurar o eliminar avisos del registro.
* Los avisos conservan origen, tipo, pantalla, frecuencia y fechas de aparición.
* Incluidos búsqueda, filtros, acciones en bloque y una interfaz responsive coherente con el Toolkit.
* Ningún aviso se oculta automáticamente, evitando silenciar errores importantes sin autorización.
* La X nativa de WordPress guarda la ocultación de forma persistente y reversible.
* Añadidos dos avisos de prueba persistentes para comprobar el flujo real de registro y ocultación.

= 3.4.1 =

* Añadida sincronización automática por SFTP de los backups terminados de UpdraftPlus con cualquier servidor compatible.
* Incorporados detector de backups, cola persistente, cliente SFTP, worker y verificador independientes.
* Las transferencias incompletas se reanudan y los fallos se reintentan con espera progresiva.
* Los archivos se publican desde un temporal .part y se marcan como sincronizados únicamente después de verificar el tamaño remoto.
* Añadida reconciliación automática: recupera archivos eliminados del servidor SFTP y puede replicar de forma segura la retención configurada en UpdraftPlus.
* La actividad se agrupa por copias de UpdraftPlus, muestra el progreso de sus archivos y separa el historial ya eliminado.
* Los estados de subida interrumpidos se recuperan al verificar el archivo definitivo y la retención se revisa al terminar la cola.
* Los borrados remotos se realizan por conjuntos, con margen de seguridad, sin transferencias pendientes y únicamente sobre archivos registrados por Premiero.
* Las contraseñas se guardan cifradas y la clave SSH del servidor se verifica en conexiones posteriores.
* Añadido phpseclib 3 y actualizado el empaquetado de Releases para incluir sus dependencias.
* Premiero Control continúa leyendo sin cambios el estado original de UpdraftPlus.

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
