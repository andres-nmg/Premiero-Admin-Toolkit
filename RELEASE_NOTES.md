# Publicación de Premiero Admin Toolkit

- **Versión:** `3.8.6`
- **Etiqueta:** `v3.8.6`
- **Título de la Release:** `Premiero Admin Toolkit 3.8.6`
- **Asset generado:** `premiero-admin-toolkit.zip`

## Texto para la Release

## Premiero Admin Toolkit 3.8.6

Corrige el módulo de copias remotas cuando la sincronización se detiene sin avisar.

### Cambios principales

- **Sin fallos silenciosos:** si falta la clave SSH, la configuración no es válida o no se puede conectar, la conciliación guarda el error en su informe y la pestaña lo muestra. Antes terminaba sin guardar nada y la pantalla seguía enseñando el último informe correcto.
- **Aviso por Telegram** cuando la sincronización se detiene (repetido como mucho cada 12 horas) y aviso de recuperación cuando vuelve a funcionar.
- **La retención ya no se bloquea indefinidamente:** los archivos pendientes o en reintento cuyo original ya borró UpdraftPlus pasan a «No disponible» y dejan de contar como trabajo sin terminar.
- **Archivos que nunca se subieron:** se cierran sin bloquear el borrado del resto del conjunto.
- **Avisos en pantalla:** clave SSH sin registrar, sincronización detenida y retención en pausa por archivos pendientes.
- El contador de intentos se pone a cero cuando el archivo se sincroniza.
