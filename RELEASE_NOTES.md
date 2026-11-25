# Publicación de Premiero Admin Toolkit

- **Versión:** `3.9.2`
- **Etiqueta:** `v3.9.2`
- **Título de la Release:** `Premiero Admin Toolkit 3.9.2`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.9.2

Restricciones de acceso para administradores, un menú más flexible y el preset «Hub».

### Usuarios

- **Nueva pestaña «Usuarios».** Eliges qué administradores tienen acceso total. El resto deja de ver y de poder abrir lo que marques. Viene desactivada por defecto.
- **Menú de administración detectado automáticamente.** Muestra todos los elementos con sus subelementos, el plugin del que vienen y si están agrupados. Incluye un buscador, «Marcar todos / Desmarcar todos» y un botón para reescanear.
- **Ocultar y bloquear.** Lo marcado desaparece del menú y su URL directa responde con un 403. Al ocultar un elemento se ocultan también sus subelementos.
- **Plugins ocultos.** Los plugins marcados desaparecen de la lista de Plugins y no se pueden activar, desactivar, borrar, actualizar ni editar por URL.
- **Bloqueo opcional de la gestión de plugins** para los administradores restringidos, sin tocar los permisos de temas.
- **Slugs adicionales** para ocultar pantallas que no estén en el menú.
- **Sin riesgo de quedarse fuera.** No se puede activar sin usuarios con acceso total, quien guarda tiene que seguir en la lista y, si ninguno de los usuarios elegidos sigue siendo administrador, las restricciones dejan de aplicarse.
- **La barra superior tampoco delata lo oculto:** los administradores restringidos no ven enlaces a pantallas bloqueadas, ni en el admin ni viendo la web.
- **Perfil y cuenta siempre disponibles:** aunque se oculte «Usuarios», cada administrador puede editar su perfil y cerrar sesión.
- **El menú del toolkit también se puede ocultar** a los administradores restringidos. Para ellos, lo agrupado dentro vuelve a su sitio original del menú.
- No afecta a otros roles, al cron, a WP-CLI ni a la API REST.

### Menú

- **Nuevo aspecto**, igual que la pestaña «Usuarios»: elementos con sus subelementos, el plugin del que vienen, un buscador, filtros y un botón para reescanear.
- **Subelementos sueltos.** Se puede llevar al menú del toolkit solo un subelemento, como la página de ajustes de un plugin, sin mover el resto. En su sitio original deja de verse.
- **Panel desplegable.** Un elemento agrupado entero muestra sus subelementos al pasar el ratón. En el móvil aparecen debajo.
- **Nombres personalizados también para los subelementos.**
- Al abrir una pantalla agrupada se resalta dentro del menú del toolkit, y los cambios se ven nada más guardar.
- Los elementos agrupados de un plugin desactivado se conservan sin crear enlaces vacíos.

### Preset «Hub»

- **Apariencia:** nueva opción «Activar Hub», alternativa a la personalización actual. Panel de gestión claro con menú lateral en tarjetas, barra superior limpia con botón para plegar el menú, avatares con inicial, escritorio sin noticias de WordPress y botón «Añadir» con accesos directos.
- **Login:** nueva opción «Activar Hub» con una pantalla de acceso de dos columnas y panel de marca. El crédito sigue su casilla y usa el nombre de Identidad.
- **Botón «Añadir» configurable:** eliges qué se puede crear (entradas, páginas, productos, otros tipos de contenido, medios o usuarios) de una lista detectada de lo instalado.
- **Botón «Ver web»** junto a «Añadir», que abre la portada (o la URL que indiques) en una pestaña nueva.
- **Barra superior a tu medida:** eliges qué elementos de WordPress y de los plugins se muestran (se detectan al cargar la barra) y puedes añadir enlaces personalizados.
- **La cuenta siempre a la vista:** con muchos elementos en la barra, la cuenta se mantiene a la derecha y lo que no cabe queda por debajo.
- Iconos y etiquetas de otros plugins (Rank Math, Elementor) y el buscador alineados y legibles en la barra clara.
- Tipografías incluidas en el plugin, sin conexiones externas.

### Login

- **Formulario moderno** para el login normal: el formulario toma el estilo del Hub, conserva tu logo y tu fondo, y el botón admite color y texto propios.
