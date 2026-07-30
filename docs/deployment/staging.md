# Staging reproducible

## Alcance

El entorno usa `compose.staging.yaml` como stack independiente. Crea su propia
red y sus propios volúmenes para PostgreSQL, Redis y storage. No comparte datos
con desarrollo ni publica PostgreSQL o Redis en el host.

Servicios:

- PostgreSQL 17;
- Redis 7.4 con contraseña;
- backend Laravel;
- worker Redis;
- scheduler;
- frontend Nginx;
- Mailpit para correo no productivo.

## Preparación

1. Copiar `.env.staging.example` a `.env.staging`.
2. Generar `APP_KEY` con `php artisan key:generate --show`.
3. Asignar contraseñas distintas y aleatorias a PostgreSQL, Redis y usuarios
   demo. No reutilizar secretos de desarrollo o producción.
4. Reemplazar `staging.example.invalid` por el dominio real.
5. Mantener `.env.staging`, certificados y credenciales fuera del repositorio.
6. Crear un registro DNS y configurar HTTPS antes de permitir acceso externo.

Variables obligatorias:

- `APP_KEY`;
- `APP_URL`;
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`;
- `REDIS_PASSWORD`;
- `SESSION_DOMAIN`;
- `MAIL_FROM_ADDRESS`;
- `DEMO_USER_PASSWORD`.

ARCA y Mercado Pago permanecen en `fake`. Cambiar sus drivers requiere
credenciales de sandbox y el runbook específico de la integración.

## HTTPS

Nginx escucha HTTP únicamente dentro del host de staging. El puerto publicado
se enlaza a `127.0.0.1` por defecto. Un balanceador o reverse proxy administrado
debe:

1. terminar TLS con un certificado válido;
2. redirigir HTTP público a HTTPS;
3. reenviar al puerto `STAGING_HTTP_PORT`;
4. preservar `Host`, `X-Forwarded-For` y `X-Forwarded-Proto`;
5. limitar el acceso al equipo piloto.

La configuración Nginx conserva `X-Forwarded-Proto` recibido y agrega cabeceras
de seguridad. `SESSION_SECURE_COOKIE=true` impide enviar la sesión fuera de
HTTPS. Para una prueba sólo local por HTTP debe usarse un archivo temporal con
`SESSION_SECURE_COOKIE=false`; ese valor no se publica.

## Despliegue

Validar sin iniciar servicios:

```powershell
.\scripts\staging-deploy.ps1 -Action config
```

Construir y desplegar una imagen etiquetada con el SHA actual:

```powershell
.\scripts\staging-deploy.ps1 -Action up
```

El entrypoint ejecuta migraciones con `--force`. No ejecuta seeders. Los datos
demo son opt-in:

```powershell
.\scripts\staging-deploy.ps1 -Action seed-demo -AllowDemoSeed
```

El seeder sólo se permite en esta base aislada y nunca en producción.

## Smoke test

Crear una credencial interactiva y ejecutar:

```powershell
$credential = Get-Credential
.\scripts\staging-smoke.ps1 -BaseUrl https://staging.example.com -Credential $credential
```

El script verifica health, shell, manifiesto, service worker, CSRF, login,
dashboard, clientes, lotes y alertas. No imprime la contraseña ni la almacena.
Para automatización, también acepta `STAGING_SMOKE_EMAIL` y
`STAGING_SMOKE_PASSWORD` como variables efímeras del proceso.

## Operación

Backup, restauración y ensayo verificable:

- [Backup y restauración](backup-restore.md)
- [Verificación de integridad](integrity.md)

Estado:

```powershell
.\scripts\staging-deploy.ps1 -Action status
```

Logs recientes:

```powershell
.\scripts\staging-deploy.ps1 -Action logs
```

Los logs se leen con Docker Compose y el storage de Laravel permanece en un
volumen persistente. No deben contener tokens, secretos ni payloads sensibles.

## Rollback

Cada despliegue etiqueta las imágenes con el SHA corto y registra el tag en
`.staging-release`, archivo ignorado por Git.

1. Identificar el último tag de imagen aprobado.
2. Confirmar que las migraciones nuevas son compatibles hacia atrás.
3. Ejecutar:

```powershell
.\scripts\staging-deploy.ps1 -Action rollback -ImageTag <sha-aprobado>
```

4. Repetir el smoke test.
5. Si una migración exige reversión de datos, detenerse y preparar un
   procedimiento específico con backup verificado. El script nunca ejecuta
   `migrate:rollback` automáticamente.

No usar `docker compose down -v`: eliminaría los volúmenes de staging.
