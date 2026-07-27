# Premiero Admin Toolkit

Plugin de código abierto para centralizar tareas habituales de administración y personalización de WordPress.

## Funciones

- Gestión de snippets PHP mediante un MU-plugin.
- Inyección de HTML en `head` y al inicio de `body`.
- CSS personalizado.
- Organización y renombrado del menú de administración.
- Instalación de plugins y temas desde WordPress.org o desde paquetes locales.
- Personalización de la pantalla de acceso.
- Identidad personalizada por cliente, con nombre y logo propios.
- Actualizaciones desde las Releases de este repositorio.

## Requisitos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.
- Permisos de administrador para utilizar las herramientas de instalación.

## Instalación

1. Descarga `premiero-admin-toolkit.zip` desde la última Release.
2. En WordPress, abre `Plugins > Añadir plugin > Subir plugin`.
3. Selecciona el ZIP, instálalo y actívalo.
4. Abre `Premiero` en el menú de administración.

Las versiones posteriores aparecerán en el sistema de actualizaciones de WordPress.

### Migración desde TecnoDerecho

Si un sitio tiene instalado TecnoDerecho Admin Toolkit:

1. Instala `premiero-admin-toolkit.zip` como un plugin nuevo.
2. Pulsa **Activar** en Premiero Admin Toolkit.
3. Premiero desactivará TecnoDerecho y migrará automáticamente las opciones compartidas, la identidad, el logo y el MU-plugin de snippets.
4. Espera al aviso de migración correcta antes de eliminar el plugin TecnoDerecho antiguo.

## Publicación de versiones

1. Actualiza `Version` y `PREMIERO_ATK_VER` en `premiero-admin-toolkit.php`.
2. Actualiza `Stable tag` y el registro de cambios de `readme.txt`.
3. Confirma y sube los cambios a la rama `main`.
4. Crea una etiqueta y una Release con el mismo número, por ejemplo `v3.2.3`.
5. Al publicar la Release, GitHub Actions generará y adjuntará automáticamente `premiero-admin-toolkit.zip`.

Después de publicar, espera a que el workflow `Build WordPress plugin release` termine correctamente antes de anunciar o instalar la versión.

## Licencia y versiones derivadas

Premiero Admin Toolkit se distribuye bajo la licencia **GPL-3.0-or-later**.

Puedes usar, estudiar, modificar y redistribuir el proyecto respetando la licencia y los avisos de autoría. Las versiones modificadas deben indicar claramente que contienen cambios y no deben presentarse como una versión oficial de Premiero.

Si publicas una adaptación o un fork, agradeceremos que nos lo comuniques y que enlaces al proyecto original:

<https://github.com/andres-nmg/premiero-admin-toolkit/>

## Componentes de terceros

Los paquetes de terceros incluidos en `assets` conservan sus propios avisos de copyright y licencia:

- All-in-One WP Migration With Import: GPL-3.0-or-later.
- PRO Elements: GPL-3.0-or-later y términos adicionales indicados en su `license.txt`.
- Hello Elementor Child: GPL-3.0-or-later.

## Soporte

- Web: <https://premiero.es>
- Correo: <hola@premiero.es>
