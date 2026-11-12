# Premiero Admin Toolkit: guía para Claude

Plugin de WordPress **público** (GPL) que se actualiza desde las Releases de este repositorio. Todo lo que entra aquí, incluido el historial de git, lo puede leer cualquiera.

## Privacidad (obligatorio)

- No escribir en código, comentarios, docs, changelog, commits ni Releases:
  - nombres de clientes ni de marcas blancas;
  - nombres de otros productos internos de Premiero;
  - proveedores de infraestructura (hosting, almacenamiento de backups…);
  - correos personales, credenciales, hosts, IPs, rutas de servidores.
- Los commits se firman con `Andres-Premiero <79188333+andres-nmg@users.noreply.github.com>`. Nunca con un correo personal.
- La documentación técnica interna va en `README-TECNICO.md`, que está en `.gitignore`. No subirla nunca.
- No añadir huellas públicas del plugin: comentarios HTML, IDs o clases `premiero-*` en el front o en el login, cabeceras HTTP con el nombre del plugin, ni archivos con nombre fijo en zonas accesibles por URL.

## Decisiones de diseño que hay que mantener

- **Migración desde el Admin Toolkit anterior:** se detecta por `PREMIERO_ATK_LEGACY_HASH` (SHA-256 del nombre de la carpeta). El nombre, el logo y el MU-plugin antiguos se derivan de esa carpeta. No escribir el nombre en claro.
- **Carpeta del plugin oculta:** `premiero_hide_rules()` escribe un bloque con marcadores en el `.htaccess` raíz (no en la carpeta del plugin, porque un `.htaccess` propio respondería 403 y lo delataría). Sin sesión devuelve el 404 de WordPress. El logo sigue accesible con Referer de la consola emparejada. El bloque se vacía al desactivar.
- **MU-plugins:** llevan un sufijo aleatorio por web (`premiero_mu_token()`). La copia anterior de los snippets va en `mu-plugins/premiero-backup-<token>/` como PHP que termina con `exit`. Nunca se guarda como `.bak` descargable. Al migrar se **renombra** el archivo, nunca se copia, para que no se carguen dos veces.
- El ZIP de la Release excluye `README*.md`, `RELEASE_NOTES.md`, `CLAUDE.md` y `composer.*` (ver `.github/workflows/release.yml`).

## Publicar una versión

1. Subir `Version` y `PREMIERO_ATK_VER` en `premiero-admin-toolkit.php`.
2. Subir `Stable tag` y añadir la entrada al changelog de `readme.txt`.
3. Actualizar `RELEASE_NOTES.md` con el texto de la Release.
4. Hacer commit y push a `main`.
5. Crear la Release `vX.Y.Z` desde `main`. El workflow adjunta `premiero-admin-toolkit.zip`.

El actualizador del plugin solo usa la última Release.

## Historial

El historial se reescribió en la versión 3.8.7 para retirar información sensible. Nunca hacer push desde un clon anterior a esa reescritura.
