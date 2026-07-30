# Backup y restauración de staging

Estado: **mecanismo implementado y restauración ensayada**. La programación, el almacenamiento externo y el cifrado aún requieren infraestructura de staging.

## Objetivos propuestos

| Control | Objetivo inicial |
| --- | --- |
| RPO | 4 horas durante el piloto; backup adicional antes de cada despliegue |
| RTO | 60 minutos desde la decisión de restaurar |
| Retención | 14 copias diarias y 8 semanales |
| Almacenamiento | repositorio externo al host de staging, con acceso restringido |
| Cifrado | pendiente de seleccionar el mecanismo administrado del almacenamiento |
| Responsable de ejecución | responsable técnico de guardia |
| Responsable de aprobación | responsable operativo del piloto |

Estos valores son objetivos de operación, no un SLA contractual.

## Backup

Unix:

```bash
bash scripts/staging-backup.sh \
  --env-file .env.staging \
  --compose-file compose.staging.yaml \
  --output-dir /ruta/segura/backups
```

Windows:

```powershell
.\scripts\staging-backup.ps1 -EnvFile .env.staging -OutputDirectory D:\Backups\LaVictoria
```

El script ejecuta `pg_dump` en formato custom dentro del contenedor PostgreSQL. El nombre incorpora timestamp UTC y SHA desplegado. Produce un `.dump` y un manifest JSON con versiones cliente/servidor, SHA-256, tamaño y duración. Rechaza archivos existentes y dumps vacíos. Las contraseñas permanecen en el archivo de entorno y no se incorporan a argumentos ni informes.

## Restauración

La restauración crea por defecto otra base en el mismo servidor; nunca restaura sobre `DB_DATABASE`.

Unix:

```bash
bash scripts/staging-restore.sh \
  --manifest /ruta/backup.manifest.json \
  --env-file .env.staging \
  --target-database la_victoria_restore_20260730
```

Windows:

```powershell
.\scripts\staging-restore.ps1 `
  -Manifest D:\Backups\backup.manifest.json `
  -EnvFile .env.staging `
  -TargetDatabase la_victoria_restore_20260730
```

Antes de cargar datos valida checksum, base destino inexistente y misma versión mayor de PostgreSQL. Después ejecuta migraciones pendientes, `bakery:integrity-check`, estado de migraciones, rutas y un smoke HTTP efímero sobre `/up` y `/api/v1/auth/csrf`, todos apuntando a la base restaurada. El informe JSON no contiene secretos.

Si el archivo de entorno o `--target-environment` indican producción, el proceso falla salvo `--allow-production`/`-AllowProduction` explícito. Ese flag sólo evita el bloqueo técnico: una restauración real de producción sigue fuera del alcance de este runbook.

## Ensayo aislado

```bash
bash scripts/staging-restore-test.sh
```

```powershell
.\scripts\staging-restore-test.ps1
```

El ensayo:

1. crea un proyecto Compose y secretos efímeros propios;
2. siembra datos controlados de pedidos, stock, compra, recepción, producción, caja, ledgers, conciliación, alertas y notificaciones;
3. ejecuta integridad y toma el backup;
4. destruye la base y los volúmenes fuente;
5. levanta PostgreSQL limpio;
6. restaura en una base nueva;
7. ejecuta migraciones, integridad y smoke;
8. compara conteos, saldos, inventario y trazabilidad;
9. ejecuta PHPUnit focalizado;
10. destruye únicamente el proyecto temporal.

Los informes quedan bajo `storage/app/restore-tests` y CI los publica durante 14 días.

## Evidencia 2026-07-30

Ensayo local `20260730T212810Z-98f1c5`:

- SHA registrado en manifest: `a1e3ddf12979ca95e108e7d62c0ddbad1d28fabc`;
- PostgreSQL origen/destino: 17.10;
- dump custom: 243.596 bytes;
- creación del backup: 1,613 s;
- restauración, migraciones, integridad y smoke HTTP: 7,049 s;
- ensayo completo: 68,588 s;
- checksum: aprobado;
- 25 verificaciones de integridad: aprobadas;
- métricas de origen y destino: idénticas;
- PHPUnit focalizado: aprobado;
- contenedores, volúmenes y archivo de secretos temporales: eliminados.

La evidencia demuestra restaurabilidad del mecanismo. Antes del piloto faltan programar backups, seleccionar almacenamiento cifrado y asignar formalmente responsables.
