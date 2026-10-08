# Documentación Técnica: Premiero Admin Toolkit (`premiero-admin-toolkit`)

> Versión analizada: **3.8.0** · `Requires PHP: 7.4` · Text domain `premiero-admin` · Constante global `PREMIERO_ATK_*`.
> Plugin de código mayoritariamente **procedimental** (funciones globales) + **8 clases `final` estáticas** en `includes/`. Es el cliente del ecosistema Premiero (Consola de Mantenimientos).

## 1. Propósito y Lógica de Negocio

Concentra en una sola pestaña de `wp-admin` (slug `premiero-admin`) todo el mantenimiento delegado de una web de cliente:

1. **Personalización del admin:** agrupación/renombrado de menús de terceros bajo el menú «Premiero», CSS propio del panel, ocultación de avisos (`Premiero_Admin_Notices`), tablas y copias de seguridad estéticas (`assets/admin-appearance.css`).
2. **Código inyectado:** CSS personalizado, HTML en `wp_head`, HTML en `wp_body_open` (con fallback en `wp_footer`) y **PHP snippets** escritos a un **mu-plugin** (`premiero_ensure_mu_dir()` + `premiero_validate_php_snippets()` + `premiero_write_mu_snippets()`).
3. **Login y white-label:** fondo, logo, ancho y crédito del formulario de login; renombrado del propio plugin y logo de marca si `premiero_white_label_enabled` está activo.
4. **Repositorio de instalación:** instalación de plugins/temas desde ZIPs locales (`assets/plugins`, `assets/themes`) o desde WordPress.org con `Plugin_Upgrader`/`Theme_Upgrader` + activación (`Premiero_Admin_Toolkit_Repository`).
5. **Backups SFTP a Hetzner Storage Box (núcleo del módulo `remote-backups`):** detecta los archivos de UpdraftPlus Free **sin tocar su historial**, los encola en una tabla propia, los sube por SFTP con reanudación y verificación de tamaño, y aplica retención espejo de la de UpdraftPlus.
6. **Consola de Mantenimientos (telemetría + comandos):** envía instantáneas firmadas (protocolo **PMC1**) al endpoint `/wp-json/premiero-console/v1/telemetry` y ejecuta por *polling* comandos remotos (`update_plugin`, `run_backup`, `check_backup_status`, `set_exclusion`).
7. **Diagnóstico:** herramientas de diagnóstico/reparación y captura del registro temporal de PHP vía AJAX (solo si `premiero_diag_enabled`).

- **CPTs / taxonomías:** ninguno.
- **Tabla personalizada:** `{prefix}premiero_backup_sync_queue` (`Premiero_Backup_Sync_Queue`, DB_VERSION 2).
- **Pestañas del admin** (`premiero_tabs_nav()`): `info`, `menuwp`, `notices`, `code`, `remote-backups`, `repository`, `adminui`, `branding`, `appearance`, `monitoring`, `mcp` y `diagnostic` (condicional).

### Flujo del módulo de backups remotos

```
UpdraftPlus genera backup
   └─ filtro updraftplus_save_last_backup  → Detector::capture_last_backup()  (guarda candidato + tamaño/mtime)
   └─ filtro updraftplus_backup_complete   → Detector::confirm_files() + schedule_scan_soon(60s)
Cron cada 15 min (CRON_SCAN)  → Detector::scan()  → enqueue() en tabla (fingerprint único)
                              → Reconciler::run() → retención / limpieza / recuperación de "uploading" huérfanos
Si hay items listos        → schedule_worker(5s) → Worker::run()
   Worker: lock → claim_next() → verify_local(size+mtime) → runtime_config() → SFTP open
           → upload a <archivo>.part (con resume si parcial < local) → verify remoto → rename al nombre final
           → verify final → mark_synced()
           → finally: release_lock(); si hay más items → schedule_worker(30s); si la cola queda limpia → Reconciler::run()
```

## 2. Arquitectura y Archivos Clave

```
premiero-admin-toolkit/
├── premiero-admin-toolkit.php        # Bootstrap + casi TODA la UI del panel, opciones y hooks WP
├── includes/
│   ├── class-premiero-console-client.php        # Telemetría firmada PMC1 + pairing + snapshot + tamaños
│   ├── class-premiero-command-client.php        # Polling 1 min, validación de comandos, nonces, política
│   ├── class-premiero-command-runner.php        # Máquina de estados de UN comando activo (fases + reanudación)
│   ├── class-premiero-plugin-updater.php        # validate_plugin_file / preflight / upgrade
│   ├── class-premiero-updraft-free-adapter.php  # Adaptador fail-closed a UpdraftPlus 1.26.x
│   ├── class-premiero-admin-appearance.php      # Presets visuales de wp-admin
│   ├── class-premiero-admin-notices.php         # Registro/captura de avisos de admin
│   ├── class-premiero-diagnostics.php           # AJAX de diagnóstico, log temporal, historial
│   ├── class-premiero-diagnostics-ui.php        # UI de la pestaña Diagnóstico
│   ├── premiero-flex-mcp-integration.php        # Integración de StifLi Flex MCP como pestaña «Servidor MCP»
│   └── remote-backups/
│       ├── class-premiero-remote-backups.php        # Coordinador: cron, scheduling, activación
│       ├── class-premiero-backup-detector.php       # Detección/estabilización de archivos de Updraft
│       ├── class-premiero-backup-sync-queue.php     # Tabla + cola persistente (estados y claim)
│       ├── class-premiero-backup-verifier.php       # Verificación local (size/mtime) y remota (size)
│       ├── class-premiero-backup-worker.php         # Transferencia SFTP secuencial con lock
│       ├── class-premiero-backup-reconciler.php     # Retención, huérfanos, limpieza remota
│       ├── class-premiero-sftp-client.php           # Envoltorio phpseclib3 + TOFU de host key
│       └── class-premiero-remote-backup-settings.php# UI/validación/cifrado de credenciales
├── assets/                            # Logo, fuentes (IBM Plex Mono, Maven Pro), ZIPs de plugins/temas
└── vendor/                            # composer: phpseclib/phpseclib ^3.0 (SFTP)
```

**Dependencias:**

- `phpseclib/phpseclib ^3.0` (Composer, `vendor/autoload.php`) para SFTP.
- **UpdraftPlus Free 1.26.x** (adaptador con rango de versión cerrado: `< 1.26.0` o `>= 1.27.0` → `premiero_updraft_version_unverified`).
- APIs de WP: `Plugin_Upgrader`, `Theme_Upgrader`, `plugins_api()`, `dbDelta()`, Cron, `wp_remote_post/get`.
- Sin Multisite (`Premiero_Updraft_Free_Adapter::availability()` devuelve error en multisite).

## 3. Modelo de Datos y Persistencia

### Tabla propia

`{prefix}premiero_backup_sync_queue` — creada con `dbDelta()` en `install()`; versión de esquema en la opción `premiero_remote_backups_db_version` (`DB_VERSION = 2`).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint unsigned AI | PK |
| `fingerprint` | char(64) | UNIQUE; `sha256(backup_id + '|' + filename)` |
| `backup_id` | varchar(80) | `{timestamp}_{hash12}` extraído del nombre |
| `filename` | varchar(255) | Nombre del archivo de UpdraftPlus |
| `local_size` / `local_mtime` | bigint unsigned | Fijados por el detector para detectar mutaciones |
| `remote_file` / `remote_size` | text / bigint null | Ruta y tamaño efectivos en el Storage Box |
| `remote_target` | char(64) | `Premiero_Remote_Backup_Settings::target_key()` (host+puerto+usuario+ruta, **sin** secretos) |
| `status` | varchar(20) | `pending`, `uploading`, `retry`, `synced`, `missing`, `pruned`, `orphaned` |
| `attempts` | smallint unsigned | Intentos acumulados (retry con backoff) |
| `next_attempt_at` | bigint unsigned | Programación del reintento |
| `local_missing_since` | bigint unsigned | Marca temporal para la ventana de gracia de borrado |
| `last_error` | text | Último error saneado (máx. 1000 caracteres) |
| `created_at` / `updated_at` / `synced_at` | bigint unsigned | Auditoría |
| Índice | `KEY status_next (status,next_attempt_at)` | Cola y barridos por estado |

### Opciones en `wp_options` (por módulo)

**Código / HTML / snippets (registradas en `premiero_code_settings_group` y `premiero_snippets_settings_group`)**
`premiero_custom_css`, `premiero_head_html`, `premiero_body_html`, `premiero_php_snippets`.

**Menús e identidad (`premiero_menu_settings_group`, `premiero_login_settings_group`, `premiero_branding_settings_group`)**
`premiero_menu_group` (array de slugs agrupados), `premiero_menu_labels` (slug→label con `wp_kses_post`), `premiero_login_bg`, `premiero_login_credit`, `premiero_login_logo_id`, `premiero_login_logo_w`, `premiero_white_label_enabled`, `premiero_white_label_name`, `premiero_white_label_logo_id`.

**Migración legacy:** `premiero_atk_legacy_migration_pending`, `premiero_atk_legacy_migration_notice` (autoload `true`, valores `0`/`1`).

**Diagnóstico:** `premiero_diag_enabled`, `premiero_diag_history` (máx. 50 entradas, `MAX_HISTORY`), `premiero_diag_temp_log`, `premiero_diag_log_enabled`, `premiero_diag_log_rotated` (fichero en `WP_CONTENT_DIR . '/premiero-debug.log'`, lectura limitada a 2 MiB).

**Workers/locks:** `premiero_remote_backups_worker_lock` (TTL 2 h), `premiero_console_sync_lock`, `premiero_console_size_lock`, `premiero_console_command_lock`, `premiero_remote_backups_last_reconcile`.

**Backups remotos:** `premiero_remote_backups_config` (`enabled`, `sync_deletions`, `host`, `port`, `username`, `remote_path`), `premiero_remote_backups_password` (**cifrada**, ver `encrypt_password()`/`get_password()`/`master_key()`), `premiero_remote_backups_host_keys` (TOFU host key), `premiero_remote_backups_last_test`, `premiero_remote_backups_db_version`, `premiero_remote_backups_stability`, `premiero_remote_backups_candidate`, `premiero_remote_backups_confirmed_files` (máx. 1000 entradas).

**Consola:** `premiero_console_enabled`, `premiero_console_api_base`, `premiero_console_installation_id`, `premiero_console_remote_installation_id`, `premiero_console_key_id`, `premiero_console_secret`, `premiero_console_last_sent`, `premiero_console_last_http_code`, `premiero_console_last_error`, `premiero_console_failures`, `premiero_console_next_attempt`, `premiero_console_payload_hash`, `premiero_console_size_cache`, `premiero_console_last_admin_tick`.

**Comandos:** `premiero_console_command_nonces` (índice de nonces), `premiero_console_command_nonce_<sha256>` (opciones individuales, anti-replay), `premiero_console_processed_commands` (máx. 2000), `premiero_console_active_command`, `premiero_console_command_resume` (cron), `premiero_console_update_exclusions` (plugin_file → true), `premiero_console_response_nonces`.

**Transients:** `premiero_atk_github_release` (`get_site_transient()`, caché del último release de GitHub con marca `_premiero_error`).

> ⚠️ `premiero_remote_backups_config`, `premiero_console_*` y las opciones de nonces se guardan como **no autoload** (`update_option(..., false)`), lo cual es correcto para rendimiento, pero **hay centenares de opciones individuales de nonce** (`premiero_console_command_nonce_*`) que se purgan a 2000 por índice: en sitios con mucha actividad de comandos esto genera filas huérfanas si el índice se corrompe.

## 4. Logs, Hooks y Puntos de Extensión

### Filtros de terceros a los que se acopla (crítico)

| Filtro externo | Callback | Uso |
|---|---|---|
| `updraftplus_save_last_backup` (prio **500**) | `Premiero_Backup_Detector::capture_last_backup()` | Guarda candidato + `observe_files()` (size/mtime). Devuelve el valor intacto |
| `updraftplus_backup_complete` (prio **500**) | `Premiero_Backup_Detector::backup_completed()` | `confirm_files()` + `schedule_scan_soon(60)` |
| `updraftplus_save_last_backup` (prio **999**) | `Premiero_Console_Client::updraft_backup_saved()` | Marca *dirty* para reenviar telemetría |

> El detector es **pasivo por diseño**: no altera el historial de UpdraftPlus ni sus ficheros; solo lee nombres vía el filtro y luego escanea el directorio de backups.
> `Premiero_Backup_Detector::completed_filenames()` y `retained_backup_ids()` leen `UpdraftPlus_Backup_History::get_history()` (o `UpdraftPlus_Options::get_updraft_option('updraft_backup_history')`) como **fuente de verdad de la retención**.

### Hooks nativos propios

- **Cron propio:** `cron_schedules` añade `premiero_remote_backups_15_minutes` (15 min) y `premiero_console_12_hours` / `premiero_console_weekly` / `premiero_console_one_minute`.
- **Eventos:** `premiero_remote_backups_scan`, `premiero_remote_backups_scan_soon`, `premiero_remote_backups_worker`, `premiero_console_sync`, `premiero_console_sync_soon`, `premiero_console_collect_sizes`, `premiero_console_collect_sizes_soon`, `premiero_console_command_poll`, `premiero_console_command_resume`.
- **Sincronización diferida:** `upgrader_process_complete`, `automatic_updates_complete`, `_core_updated_successfully`, `update_option_{branding}` / `add_option_{branding}` → `Premiero_Console_Client::mark_dirty()` (nunca envía dentro del mismo request).
- **Backend público:** `wp_head` (prio 99) para `premiero_head_html` + `<style id='premiero-custom-css'>`; `wp_body_open` (1) y fallback `wp_footer` (1) para `premiero_body_html`; `login_enqueue_scripts` / `login_footer` para la personalización del login.
- **Admin:** `admin_menu` (prios **20**, **998**, **999**) para crear el menú, renombrar top-level no agrupados y mover páginas bajo Premiero; `admin_head` para el separador; `admin_init` (prios 0–40) para migración, registro de opciones, formularios y reparación de cron.
- **`all_plugins`:** renombra el propio plugin cuando el white-label está activo.
- **`update_plugins_github.com` + `plugins_api`:** actualizaciones desde `https://api.github.com/repos/andres-nmg/premiero-admin-toolkit/releases/latest` (asset `premiero-admin-toolkit.zip`), con caché en `premiero_atk_github_release`.
- **Hooks de activación/desactivación:** `Premiero_Console_Client`, `Premiero_Command_Client`, `Premiero_Remote_Backups` y `premiero_schedule_legacy_migration` (desactivación del legacy `tecnoderecho-admin-toolkit` con `deactivate_plugins()`).

### Protocolo con la Consola (`Premiero_Console_Client`)

- **Namespace REST de la Consola:** `premiero-console/v1`; el Toolkit **no registra endpoints propios**, es **cliente saliente**.
- Rutas canónicas firmadas: `/wp-json/premiero-console/v1/telemetry`, `/commands/poll`, `/commands/report`.
- Firma **PMC1**: `hash('sha256', implode("\n", [SIGNATURE_VERSION, 'POST', $canonical_path, $installation_id, $key_id, $timestamp, $request_nonce, sha256($body)]))` con HMAC del `premiero_console_secret`; las respuestas se validan igual con `'RESPONSE'` y `hash_equals()` (ver `signed_command_request()`).
- Emparejamiento en dos fases (`pair` → `installation_id` local + `remote_installation_id`).

### AJAX registrado (solo `admin`)

`wp_ajax_premiero_diag_run`, `premiero_diag_repair`, `premiero_diag_php`, `premiero_diag_clear_history`, `premiero_diag_read_log`, `premiero_diag_clear_log`, `premiero_diag_toggle_log`, `premiero_diag_test_log`, más `premiero_capture_admin_notices` y `premiero_dismiss_admin_notice` (`Premiero_Admin_Notices`). Todos con `NONCE_ACTION` propio y `check_ajax_referer` + `current_user_can('manage_options')`.

### Comandos remotos aceptados (`Premiero_Command_Client::validate_command()`)

`update_plugin` · `run_backup` · `check_backup_status` · `set_exclusion`
Validaciones: `wp_is_uuid($id)`, acción en lista blanca, nonce de 48 hex, `expires_at` futuro y `issued_at` no más de 300 s en el futuro, anti-replay por opción individual, `Premiero_Plugin_Updater::validate_plugin_file()` para rutas de plugin, y motivo obligatorio si `excluded === true`. La política remota acota `backup_max_age_hours` (1–168) y `backup_timeout_minutes` (5–180).

## 5. Guía de Mantenimiento y Futuras Ampliaciones

**Dónde tocar:**

| Necesidad | Punto exacto |
|---|---|
| Añadir una pestaña al panel | `premiero_tabs_nav()` (`$tabs`) + el `switch` de `premiero_render_settings_page()` (~línea 1826) |
| Añadir un ajuste nuevo | `register_setting()` en el bloque `admin_init` de la línea 1235 y el formulario `options.php` de la pestaña correspondiente |
| Cambiar el intervalo de escaneo | `Premiero_Remote_Backups::cron_schedules()` + `CRON_SCAN` / `SCHEDULE_SCAN` |
| Cambiar el nº de archivos subidos por ejecución | `Premiero_Backup_Worker::run()` (procesa **un** item por ejecución y se reprograma cada 30 s) |
| Ampliar/reducir la ventana de recuperación de subidas | `Premiero_Backup_Sync_Queue::claim_next()` (recupera `uploading` > 2 h) y `Premiero_Backup_Worker::LOCK_TTL` |
| Cambiar la política de borrado remoto | `Premiero_Backup_Reconciler::run()` + `DELETION_GRACE` (30 min) + opción `sync_deletions` |
| Cambiar la detección de nombres de archivo | `Premiero_Backup_Detector::is_updraft_filename()` (regex) y `file_identity()` |
| Cambiar el rango de versión de Updraft soportado | `Premiero_Updraft_Free_Adapter::availability()` (hoy `>= 1.26.0` y `< 1.27.0`) |
| Cambiar el cifrado de la contraseña SFTP | `Premiero_Remote_Backup_Settings::encrypt_password()` / `get_password()` / `master_key()` |
| Añadir un comando remoto nuevo | `Premiero_Command_Client::validate_command()` (`$allowed`) + `Premiero_Command_Runner::advance()` |
| Ajustar la firma con la Consola | `Premiero_Console_Client::SIGNATURE_VERSION` + `signed_command_request()` y la validación de respuestas |
| Añadir snippet PHP al mu-plugin | `premiero_write_mu_snippets()` y `premiero_validate_php_snippets()` |
| Cambiar la URL de release/update | Constantes `PREMIERO_ATK_RELEASE_API` y `PREMIERO_ATK_RELEASE_ASSET` |

**Advertencias / deuda técnica detectada:**

1. **`premiero-admin-toolkit.php` es un monolito de ~2.700 líneas** que mezcla bootstrap, registro de opciones, UI de 10 pestañas, JS inline (`markDirty`, `initializeEditor`, `refreshBrandingPreview`) y migración legacy. Cualquier cambio en el panel implica riesgo de regresión; debería dividirse por pestañas.
2. **PHP snippets en mu-plugin:** `premiero_write_mu_snippets()` escribe código PHP ejecutable en disco desde contenido guardado en `wp_options`. Es una **superficie de RCE deliberada**: quien tenga `manage_options` ejecuta código arbitrario. `premiero_validate_php_snippets()` es una validación sintáctica, no de capacidades.
3. **`wp_head` (prio 99) imprime `premiero_custom_css`, `premiero_head_html` y `premiero_body_html` sin escapado** (por diseño): esos valores deben tratarse como código de confianza, no como datos de usuario.
4. **Rendimiento del cron de consola:** `premiero_console_one_minute` dispara un *polling* HTTP firmado **cada minuto** en cada web de cliente; con WP-Cron basado en visitas eso añade tráfico saliente constante, además del heartbeat de 12 h y la recolección semanal de tamaños. Es el mayor coste operativo del plugin.
5. **`collect_sizes()` recorre el árbol de archivos** y cachea en `premiero_console_size_cache`: debe seguir restringido a cron (no a peticiones de usuario) para no penalizar el TTFB.
6. **Patrón `.part` + rename:** correcto (evita publicar archivos incompletos), pero si el rename falla tras un upload válido el worker reintenta con backoff; ante una cola atascada hay que revisar `attempts` / `last_error` en la pestaña Copias de Seguridad.
7. **Bloqueo de dos niveles sin transacción:** el `worker_lock` (opción, TTL 2 h) es el que evita concurrencia; `claim_next()` no usa transacción ni `SELECT ... FOR UPDATE`. Si el cron se lanza además por WP-CLI o cron del sistema pueden existir dos consumidores.
8. **Migración legacy con `deactivate_plugins()` al arrancar** (líneas 22–40): si `tecnoderecho-admin-toolkit` sigue activo, se desactiva y **se aborta la carga con `return`**, dejando el toolkit inoperativo hasta la siguiente petición. Es un puente temporal a eliminar cuando ningún cliente use el legacy.
9. **`get_config()` se invoca varias veces por request** (`is_enabled()`, `sync_deletions_enabled()`, `target_key()` leen `get_option()` cada una). Un `static` cache reduciría consultas en los hooks de encolado.
10. **`premiero_atk_get_latest_release()` usa `wp_remote_get` sin token** y cachea en `get_site_transient`; un fallo de red se cachea con `_premiero_error` y envenena el updater hasta que expire el transient.
11. **Superficie amplia sobre el admin:** agrupa menús de otros plugins, renombra ítems y captura avisos. Cambios en el array global `$menu` o en las clases CSS de `wp-admin` (`.notice-dismiss`) rompen `Premiero_Admin_Notices`, que además inyecta un `MutationObserver` + `fetch` en el footer de **todo** el admin.
12. **`premiero_diag_php` ejecuta herramientas PHP por AJAX**: debe permanecer desactivado por defecto (`premiero_diag_enabled = false`) y con doble validación de nonce y capacidad (así está implementado).



## 6. Diccionario de Clases y Funciones

### Funciones globales (`premiero-admin-toolkit.php`)

| Clase/Función | Parámetros Clave | Descripción Técnica |
|---|---|---|
| `premiero_is_white_label()` | — | `true` si el white-label está activo y hay nombre de marca |
| `premiero_get_brand_name()` / `premiero_get_toolkit_name()` | — | Devuelven «Premiero» o el nombre personalizado (y su variante `… Admin Toolkit`) |
| `premiero_get_brand_logo_url($size)` | `$size` | URL del logo: attachment del white-label o `assets/premiero-logo.png` |
| `premiero_schedule_legacy_migration()` | — | Hook de activación: decide si hay migración legacy pendiente |
| `premiero_initialize_runtime_state()` | — | Crea con autoload las opciones de migración si no existen |
| `premiero_atk_get_latest_release()` / `_release_version()` / `_release_package()` | `$release` | Consulta cacheada a GitHub Releases y normalización del asset |
| `premiero_render_repository()` | — | Instancia y renderiza `Premiero_Admin_Toolkit_Repository` embebido |
| `premiero_ensure_mu_dir()` | — | Crea `mu-plugins/` si falta y devuelve la ruta |
| `premiero_validate_php_snippets($content)` | `$content` | Validación sintáctica del PHP del snippet antes de escribirlo |
| `premiero_write_mu_snippets($php_code)` | `$php_code` | Escribe/borra el mu-plugin con los snippets |
| `premiero_get_login_logo_url()` | — | URL del logo del login según ajustes |
| `premiero_import_legacy_brand_logo()` / `premiero_run_legacy_migration()` | — | Migración de ajustes/logo desde `tecnoderecho-admin-toolkit` |
| `premiero_handle_branding_submit()` | `$_POST` | Guarda branding/white-label (hook `admin_init`) |
| `premiero_admin_header($active_tab)` / `premiero_tabs_nav($active)` | `$active_tab` | Cabecera del panel y navegación de pestañas |
| `premiero_render_settings_page()` | `$_GET['tab']` | Render de las 10–11 pestañas del panel |
| `premiero_render_support_inner()` | — | Bloque de soporte/contacto del panel |

### Clases (`includes/`)

| Clase/Función | Parámetros Clave | Descripción Técnica |
|---|---|---|
| `Premiero_Admin_Appearance::init()` / `render_tab()` | — | Presets visuales de `wp-admin` (colores, densidad, `assets/admin-appearance.css`) |
| `Premiero_Admin_Notices::init()` | — | Captura, registra y permite descartar avisos de otros plugins |
| `Premiero_Console_Client::init()` | — | Registra cron, hooks `mark_dirty` y filtro de UpdraftPlus |
| `…::pair($console_url, $token)` | URL + token | Emparejamiento en dos fases; guarda `installation_id`, `key_id` y `secret` |
| `…::disconnect()` | — | Borra credenciales y limpia eventos programados |
| `…::send_snapshot($force)` / `build_snapshot()` | `$force` | Construye y firma (PMC1) la telemetría; payload de sitio, branding, updates, UpdraftPlus, Wordfence y tamaños |
| `…::collect_sizes()` / `get_cached_sizes_for_payload()` | — | Recolecta tamaños de disco en cron y los recupera de `premiero_console_size_cache` |
| `…::signed_command_request($path, $payload)` | ruta canónica + payload | Firma HMAC-SHA256 PMC1 y valida la firma de la respuesta |
| `…::mark_dirty()` | `$a1,$a2,$a3` | Marca la telemetría como sucia tras updates de core/plugins/temas |
| `…::process_admin_forms()` / `render_tab()` | — | Formularios PRG y UI de la pestaña Consola |
| `Premiero_Command_Client::init()` | — | Cron de *polling* cada minuto y envío de informes |
| `…::validate_command($command)` | `$command` | Lista blanca de acciones, UUID, nonce, expiración, anti-replay y política remota |
| `…::backup_max_age_hours($command)` | `$command` | Antigüedad máxima de copia aceptada (1–168 h) |
| `Premiero_Command_Runner::accept($command)` / `resume()` | `$command` | Máquina de estados de un único comando activo con lock y reanudación por cron |
| `…::advance($state)` | estado | Dispatcher: `set_exclusion`, `check_backup_status`, `run_backup`, `update_plugin` |
| `…::advance_update($state)` | estado | Preflight → copia reciente → upgrade → verificación → informe |
| `…::finish()` / `finish_with_result()` / `finish_error()` | estado, mensaje | Cierran el comando y envían el informe final a la Consola |
| `Premiero_Plugin_Updater::preflight($plugin_file)` | archivo de plugin | Valida ruta, versión origen/destino y estado activo antes de actualizar |
| `…::validate_plugin_file($plugin_file)` | archivo de plugin | Sanidad de la ruta dentro de `WP_PLUGIN_DIR` |
| `Premiero_Updraft_Free_Adapter::availability()` | — | *Fail-closed*: exige UpdraftPlus activo y versión `>= 1.26.0 < 1.27.0` |
| `…::status()` / `recent_success($max_age_hours)` | `$max_age_hours` | Normaliza `updraft_last_backup` y exige copia completa y reciente |
| `…::start()` | — | Lanza copia completa con `do_action('updraft_backupnow_backup_all')` |
| `Premiero_Diagnostics::init()` | — | Herramientas de diagnóstico, log temporal de PHP e historial |
### Módulo `remote-backups/`

| Clase/Función | Parámetros Clave | Descripción Técnica |
|---|---|---|
| `Premiero_Remote_Backups::init()` / `activate()` / `deactivate()` | — | Coordina cron, instalación de tabla y limpieza de locks |
| `…::cron_schedules($schedules)` | `$schedules` | Añade la frecuencia `premiero_remote_backups_15_minutes` |
| `…::run_scan()` | — | Ejecuta detector + reconciliador y programa el worker si hay items |
| `…::refresh_schedule()` / `schedule_scan_soon()` / `schedule_worker()` | `$delay` | Gestión de eventos cron vía `schedule_single_earliest()` |
| `Premiero_Backup_Detector::capture_last_backup($last_backup)` | array de Updraft | Filtro prio 500: guarda candidato y observa size/mtime |
| `…::backup_completed($delete_jobdata)` | filtro Updraft | Confirma archivos y programa escaneo en 60 s |
| `…::scan($force)` | `$force` | Escanea el directorio, exige estabilidad y encola archivos nuevos |
| `…::completed_filenames()` / `retained_backup_ids()` | — | Historial de Updraft como fuente de verdad de la retención |
| `…::is_updraft_filename($filename)` | `$filename` | Regex estricta de nombres `backup_YYYYMMDD-HHMMSS_*_hash12-*.zip/gz` |
| `…::file_identity($filename)` | `$filename` | `{timestamp}_{hash12}` o `sha256` truncado como identidad estable |
| `Premiero_Backup_Sync_Queue::install()` / `maybe_install()` | — | `dbDelta` de la tabla y control por `OPT_DB_VERSION` |
| `…::enqueue($file)` | archivo normalizado | `INSERT ... ON DUPLICATE KEY` que revive items `missing`/`pruned` |
| `…::claim_next()` | — | Recupera `uploading` > 2 h y reserva el siguiente item |
| `…::mark_synced()` / `mark_retry()` / `mark_missing()` / `mark_pruned()` | id, error/ruta | Transiciones de estado de la cola con backoff |
| `…::counts()` / `recent()` / `synced_items()` / `retention_items()` | `$limit` | Consultas para el panel y el reconciliador |
| `…::managed_filenames($target_key)` | destino | Nombres gestionados en el destino actual (evita borrar archivos ajenos) |
| `…::table_name()` | — | `{prefix}premiero_backup_sync_queue` |
| `Premiero_Backup_Verifier::verify_local($path,$size,$mtime)` | ruta + tamaño + mtime | Comprueba que el archivo local no mutó desde el encolado |
| `…::verify_remote($client,$remote,$size)` | cliente + ruta + tamaño | Comprueba el tamaño remoto antes y después del rename |
| `Premiero_Backup_Worker::run()` | — | Procesa **un** archivo: lock → verify → SFTP → `.part` → rename → verify → `mark_synced` |
| `…::acquire_lock()` / `release_lock()` / `has_active_lock()` | — | Lock por opción con TTL de 2 h |
| `Premiero_Backup_Reconciler::run()` | — | Retención espejo, huérfanos y borrado remoto con gracia de 30 min |
| `…::prune_group_if_remote_absent()` / `ensure_client()` | grupo, config | Verificación remota previa a la eliminación definitiva |
| `Premiero_SFTP_Client::open()` / `close()` | — | Conexión/cierre sobre `phpseclib3\Net\SFTP` |
| `…::upload_local_file($local,$remote,$resume)` | rutas + resume | `put()` con `SOURCE_LOCAL_FILE` y `RESUME` opcional |
| `…::remote_size()` / `remote_exists()` / `rename_remote()` / `delete_partial()` | ruta remota | Operaciones SFTP de bajo nivel |
| `…::list_backup_files($directory)` | directorio | Inventario remoto filtrado por `is_updraft_filename()` |
| `…::delete_managed_backup_file($remote)` | ruta remota | Borrado seguro: solo dentro de `remote_path` y con nombre válido |
| `…::probe_port($host,$port,$timeout)` | host, puerto | Sonda TCP + banner SSH para el diagnóstico de conectividad |
| `Premiero_Remote_Backup_Settings::process_forms()` | `$_POST` | PRG de `save` / `test` / `upload_pending` / `forget_key` |
| `…::render_tab()` / `render_queue()` / `render_last_test()` | — | UI de la pestaña Copias de Seguridad (sets, progreso, estados) |
| `…::is_enabled()` / `sync_deletions_enabled()` / `runtime_config()` | — | Lectura de configuración y validación en tiempo de ejecución |
| `…::target_key($config)` | config | Hash no secreto host+puerto+usuario+ruta para identificar el destino |
| `…::encrypt_password()` / `get_password()` / `master_key()` | contraseña | Cifrado simétrico de la contraseña SFTP en `wp_options` |
| `…::get_fingerprint()` / `store_fingerprint()` / `forget_fingerprint()` | host, puerto | TOFU de la clave SSH del Storage Box |
| `Premiero_Admin_Toolkit_Repository::render_embedded_page()` / `handle_form_submit()` | `$_POST` | Instalador de plugins/temas desde ZIP local o WordPress.org con activación |
| `…::install_from_wporg()` / `install_theme_from_wporg()` / `install_from_zip()` | slug / ruta ZIP | Envoltorios sobre `Plugin_Upgrader` y `Theme_Upgrader` |
| `…::get_plugin_status()` / `get_theme_status()` / `locate_plugin_main_file_by_slug()` | slug | Estado instalado/activo y localización del archivo principal |

| `Premiero_Diagnostics_UI` | — | Render de la pestaña Diagnóstico y utilidades de reparación |

