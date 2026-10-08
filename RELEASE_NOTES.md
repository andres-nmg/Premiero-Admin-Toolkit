# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.0`
- **Etiqueta:** `v3.8.0`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.0`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.0

Esta versión incorpora el panel de administración de StifLi Flex MCP como una
pestaña nativa del Toolkit bajo el nombre «Servidor MCP». El menú independiente
de Flex MCP desaparece del lateral y sus páginas, sub-pestañas, enlaces internos
y acciones se integran dentro del Toolkit sin modificar el plugin original, que
sigue instalándose y actualizándose con normalidad.

### Cambios principales

- Nueva pestaña «Servidor MCP» dentro del Toolkit (debajo de «Ajustes») que
  renderiza la interfaz completa de administración de StifLi Flex MCP: servidor,
  AI Chat Agent, multimedia, SEO, automatizaciones, eventos, copiloto y logs.
- El menú lateral independiente de Flex MCP se oculta y se sustituye por el
  submenú «Servidor MCP», conservando el acceso a cada sección.
- Los enlaces internos de Flex MCP (páginas `sflmcp-*` y sus sub-pestañas) se
  reescriben automáticamente hacia la pestaña embebida, incluidos los enlaces
  relativos y los formularios que redirigen después de guardar.
- Los assets de Flex MCP (CSS y JavaScript) se cargan únicamente en la pestaña
  embebida, invocando solo sus callbacks de `admin_enqueue_scripts` con la página
  y la pestaña correspondientes.
- Si Flex MCP no está instalado, el Toolkit lo instala y activa automáticamente
  desde un paquete local incluido en el repositorio. Una vez activo, conserva su
  ruta normal de actualización desde la pantalla de Plugins.
- El asistente de incorporación (onboarding) independiente de Flex MCP se
  suprime para que no escape del panel embebido; la selección de complementos
  sigue disponible en «Servidor MCP → Add-ons».

### Seguridad y límites

- Flex MCP no se modifica: se conserva su código original y su ruta normal de
  actualización.
- La integración se limita a reescribir enlaces, incrustar el renderizado y
  cargar sus assets; no altera los datos ni las capacidades de Flex MCP.
- Las acciones de Flex MCP mantienen sus propias protecciones (capabilities y
  nonces), ya que se ejecutan en su contexto original.
- La instalación automática solo actúa cuando Flex MCP no está presente y se
  limita al paquete local distribuido con esta versión.

### Compatibilidad

- Requiere WordPress 5.8 o posterior y PHP 7.4 o posterior.
- Mantiene las funcionalidades existentes de personalización, mantenimiento,
  copias de seguridad, repositorio y conexión con Premiero Maintenance Console.
- Compatible con el sistema de actualización mediante GitHub Releases utilizado
  por versiones anteriores.
- No incluye soporte Multisite.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.8.0`.
2. Crea y publica la etiqueta `v3.8.0`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.