# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.3`
- **Etiqueta:** `v3.8.3`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.3`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.3

Añade avisos por Telegram configurables en cada instalación.

### Cambios principales

- Nueva pestaña **Telegram**: formularios de Contact Form 7 y Elementor Pro,
  actualizaciones, errores fatales, seguridad y comentarios pendientes, cada
  aviso con su propia casilla.
- El plugin no incluye credenciales. El token y el Chat ID se escriben en cada
  instalación; el token se guarda cifrado y no vuelve a mostrarse.
  Opcionalmente se pueden definir con `PRM_TG_TOKEN` y `PRM_TG_CHAT_ID` en
  `wp-config.php`.
- El Toolkit gestiona el mu-plugin `00-premiero-telegram-recovery.php`, que avisa
  también de los errores fatales ocurridos antes de cargar el Toolkit. Los
  fatales fuera del admin se avisan sin enlace de recuperación, como máximo una
  vez por hora para cada error.
- Opción de desactivar emails duplicados de WordPress, solo con Telegram activo,
  credenciales válidas y los avisos equivalentes marcados. El email de
  restablecimiento de contraseña solo se omite para administradores y con la
  entrega confirmada. El email de recuperación y los de formularios no se pueden
  desactivar.

### Después de actualizar

- Los avisos no funcionan hasta configurar cada web en **Premiero > Telegram**.
  Hasta entonces no se envía nada y los emails de WordPress siguen igual.
- Todas las opciones de desactivar emails están apagadas por defecto.
- Si se migra una web a una instalación con otras salts de WordPress, hay que
  volver a escribir el token. Mientras tanto los emails siguen enviándose.

### Limitaciones conocidas

- En `wp-admin` y en el login, WordPress solo genera el aviso de recuperación si
  el error viene de un plugin o un tema, y por defecto lo limita a uno al día. No
  funciona en multisite.
- Los errores fatales anteriores a cargar los mu-plugins no se pueden avisar.
- Al eliminar el plugin, los ajustes y el token cifrado permanecen en la base de
  datos, porque no hay `uninstall.php`.

### Compatibilidad

- Sin cambios de requisitos. Requiere WordPress 5.8 o posterior y PHP 7.4 o
  posterior. Desactivar el email de nuevo usuario requiere WordPress 6.1; en
  versiones anteriores la casilla aparece deshabilitada y el email se envía.

### Publicación

1. Confirma que `Version`, `PREMIERO_ATK_VER` y `Stable tag` indican `3.8.3`.
2. Crea y publica la etiqueta `v3.8.3`.
3. GitHub Actions generará y adjuntará automáticamente el archivo
   `premiero-admin-toolkit.zip`.
4. No adjuntes el ZIP versionado local con otro nombre: el actualizador del
   Toolkit busca exactamente `premiero-admin-toolkit.zip`.

### Instalación manual

Descarga `premiero-admin-toolkit.zip` de los archivos adjuntos de la Release e
instálalo desde **Plugins > Añadir plugin > Subir plugin**. WordPress solicitará
confirmar la sustitución de la versión instalada.