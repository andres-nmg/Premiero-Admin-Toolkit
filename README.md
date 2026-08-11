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
- Monitorización saliente y de solo lectura mediante la consola privada.
- Sincronización automática por SFTP de los backups de UpdraftPlus con cualquier servidor compatible.
- Registro y ocultación reversible de avisos del administrador de WordPress.
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

## Copias de Seguridad por SFTP

1. Mantén en UpdraftPlus la generación, la programación y la restauración, y selecciona **Ninguno** como almacenamiento remoto.
2. En `Premiero > Copias de Seguridad`, introduce los datos del servidor SFTP de destino.
3. Pulsa **Probar conexión** una vez para comprobar escritura, tamaño y clave SSH.
4. Activa la sincronización y guarda la configuración.

Cuando UpdraftPlus termina correctamente una copia, Premiero registra sus archivos y espera al menos 60 segundos sin cambios antes de subirlos. Cada archivo se publica desde un temporal `.part`, se verifica por tamaño y queda marcado en una cola persistente para no volver a enviarlo. Los fallos se conservan como pendientes y se reintentan con espera progresiva. Un escaneo cada 15 minutos actúa como respaldo del hook de finalización, por lo que WP-Cron debe funcionar en la instalación.

La opción **Mantener el servidor SFTP sincronizado con las copias conservadas por UpdraftPlus** convierte el historial de UpdraftPlus en la fuente de verdad: un archivo remoto ausente se vuelve a subir y un conjunto retirado por la política de retención se elimina también del servidor. Premiero solo puede borrar archivos que haya sincronizado y registrado previamente, nunca borra copias locales, espera al menos 30 minutos, comprueba nuevamente el historial y no elimina nada mientras exista una transferencia pendiente.

El módulo no cambia la programación ni la retención configuradas en UpdraftPlus y no escribe en los datos utilizados por Premiero Control.

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
4. Crea una etiqueta y una Release con el mismo número, por ejemplo `v3.4.2`.
5. Al publicar la Release, GitHub Actions generará y adjuntará automáticamente `premiero-admin-toolkit.zip`.

Después de publicar, espera a que el workflow `Build WordPress plugin release` termine correctamente antes de anunciar o instalar la versión.

El título, la etiqueta y el texto preparados para la publicación actual están en [`RELEASE_NOTES.md`](RELEASE_NOTES.md).

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
- phpseclib 3: MIT.

## Soporte

- Web: <https://premiero.es>
- Correo: <hola@premiero.es>

## Consola de mantenimiento

Desde la versión 3.3.0, la pestaña **Monitorización** permite emparejar cada
WordPress con una instalación de Premiero Maintenance Console mediante un token
de un solo uso. El Toolkit solo realiza envíos salientes firmados: la consola
no puede ejecutar acciones ni iniciar sesión en la web cliente.

La sincronización ligera se programa cada 12 horas. El cálculo de tamaño se
ejecuta aparte, semanalmente, y su resultado queda cacheado para no recorrer
archivos durante el tráfico normal.

El botón **Desconectar** elimina únicamente la clave guardada en esa instalación.
Si también se quiere invalidar la clave que conserva la consola, debe usarse
**Revocar conexión** en la ficha correspondiente de la consola.

En producción la URL de la consola debe utilizar HTTPS. Para facilitar las
pruebas, HTTP se admite únicamente cuando WordPress está configurado como
`local` o `development` y la consola utiliza `localhost`, un dominio `.local`
o `.test`, o una dirección IP privada.
