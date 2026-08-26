# Publicación de Premiero Admin Toolkit

- **Versión:** `3.5.13`
- **Etiqueta:** `v3.5.13`
- **Título de la Release:** `Premiero Admin Toolkit 3.5.13`

## Texto para la Release

## Premiero Admin Toolkit 3.5.13

Esta versión completa la adaptación oscura de las pantallas nativas de WordPress y de los componentes visuales compartidos por los plugins Premiero.

Incluye además la corrección específica del panel lateral de metadatos en el detalle individual de medios.

La pestaña Apariencia incorpora ahora el ajuste rápido «Usar colores Premiero», inspirado en la identidad crema, granate, verde y gris cálido de premiero.es. La paleta funciona como punto de partida y todos sus valores continúan siendo editables.

La paleta se ha refinado para reproducir mejor la jerarquía real de la web: granate como color protagonista y verde reservado a estados residuales de éxito o destacado.

Esta revisión elimina los estados azules heredados, convierte las pestañas y los bordes de botones al lenguaje granate de la marca y presenta los ajustes nativos en cajas blancas sobre el lienzo crema.

Los botones secundarios del preset Premiero quedan ahora sin relleno: muestran el blanco o el crema de la superficie sobre la que se encuentran, conservan el borde granate y comparten el redondeado de la web. Los botones principales mantienen el relleno granate.

Las pestañas reproducen ahora el patrón de navegación de premiero.es: una barra blanca continua, sin recuadros individuales, con hover blanco y una línea inferior granate para señalar la sección activa. También se elimina el último foco azul heredado de WordPress.

La navegación queda unida físicamente al panel inferior para formar una sola superficie. Las pantallas de plugins, perfil y usuarios abandonan los fondos azules y la presentación plana, y el selector nativo de esquemas de WordPress se oculta mientras el Toolkit gobierna la apariencia.

La navegación del Toolkit adopta el comportamiento exacto de las pestañas de premiero.es: las opciones inactivas descansan sobre crema, pasan a blanco en hover y conservan la línea inferior granate; la activa se abre visualmente hacia el panel blanco inferior, sin línea ni separación.

La revisión final adopta el patrón más ligero de Premiero Control Financiero para todas las paletas: navegación directamente sobre el lienzo, una línea inferior continua y un único subrayado de énfasis para hover y sección activa.

Este patrón queda incorporado al propio Premiero Admin Toolkit y se mantiene aunque la personalización visual esté desactivada.

La pestaña Apariencia cierra la revisión visual con selectores coherentes en todos los modos, muestras de color sin bordes duplicados y una composición adaptable específica para tablet y móvil.

### Cambios principales

- Nueva pestaña Apariencia situada junto a Identidad y construida con los mismos patrones visuales del Toolkit.
- Personalización del menú lateral, barra superior, elementos activos, color de énfasis, fondo general, paneles, texto y botones.
- Colores independientes para el fondo y el texto de botones principales y secundarios, reflejados en la vista previa.
- Modos claro personalizado y oscuro con paletas recomendadas que pueden ajustarse color por color.
- Colores y tipografías independientes para títulos, texto principal y texto secundario.
- Maven Pro e IBM Plex Mono incluidas en el plugin con sus licencias OFL y sin descargar recursos externos durante el uso.
- Vista previa en tiempo real para comprobar colores y tipografía antes de guardar.
- Activación reversible y botón para restaurar por completo la apariencia original de WordPress.
- Validación de colores y opciones antes de guardarlos, con protección mediante permisos y nonce.
- Estilos limitados a `wp-admin`, sin modificar el frontal, el tema activo ni el núcleo de WordPress.
- Integración específica del modo oscuro con Premiero Admin Toolkit, LinkedIn CRM, Control Financiero y Maintenance Console.
- Contraste reforzado en Código, Avisos, Menú, la ficha de leads, tablas financieras, ajustes, disponibilidad y cajas nativas de publicación.
- Pantallas nativas de Ajustes presentadas como paneles con borde, separación y profundidad visual.
- Modales de medios, ventanas emergentes y fondos superpuestos adaptados íntegramente al modo oscuro.
- Columnas calculadas del libro de movimientos, flujo mensual y filtros de Maintenance Console sin superficies claras residuales.

### Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.
- OpenSSL para almacenar la contraseña cifrada.

### Instalación

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de esta Release e instálalo desde `Plugins > Añadir plugin > Subir plugin`.

Las instalaciones existentes recibirán esta versión mediante el actualizador normal de WordPress.

> El ZIP instalable se adjunta automáticamente cuando finaliza el workflow **Build WordPress plugin release**.
