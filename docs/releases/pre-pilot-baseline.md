# Baseline pre-piloto

Fecha: 2026-07-30  
SHA: `41062ec2329a4ada3fd542a1f0a250fd8aa2ac73`  
Rama: `develop` (`origin/develop...develop = 0/0`)

## Plataforma verificada

| Componente | Versión / estado |
| --- | --- |
| Sistema operativo local | Microsoft Windows NT 10.0.26200.0 |
| PHP local | 8.5.0 NTS |
| PHP Docker / CI equivalente | 8.4 Alpine, con `pcntl`, `pdo_pgsql` y Redis |
| Composer | 2.9.2 |
| Node.js | 24.11.1 local; 22 en Docker/CI |
| npm | 11.9.0 |
| PostgreSQL | 17 Alpine |
| Redis | 7.4 Alpine |
| Docker | 29.0.1 |
| Docker Compose | 2.40.3 |
| Git | 2.52.0.windows.1 |

## Resultado de validaciones

| Gate | Resultado |
| --- | --- |
| `composer validate --strict` | aprobado |
| `composer audit` | 0 advisories, 0 paquetes abandonados |
| `php artisan migrate:fresh --seed --force` | aprobado, 9 migraciones de dominio |
| PHPUnit local | 62 aprobadas, 1 omitida por ausencia de `pcntl_fork`, 536 aserciones |
| PHPUnit Linux + PostgreSQL 17 | prueba concurrente ejecutada, 548 aserciones totales, código 0 |
| Pint | aprobado |
| `npm ci` | aprobado desde `package-lock.json` |
| Vitest | 10 archivos, 25 pruebas aprobadas |
| TypeScript | aprobado |
| build Vite/PWA | aprobado, 70 módulos, JS principal 229.10 kB (69.49 kB gzip) |
| contrato PWA | aprobado |
| `npm audit --omit=dev` | 0 vulnerabilidades productivas |
| Docker Compose config/build/up | aprobado |
| servicios Docker | backend y frontend healthy; PostgreSQL, Redis y Mailpit healthy; worker y scheduler activos |

La reproducción Linux usó una red y una base PostgreSQL efímeras y aisladas. La
prueba `ProcurementConcurrencyTest` no quedó omitida: agregó las aserciones de la
carrera real. El montaje read-only de `vendor` produjo advertencias de escritura
de `.phpunit.result.cache` y lectura de `.env`; no alteró los resultados ni se
presenta como ejecución CI limpia. CI deberá publicar logs estructurados para
evitar esa ambigüedad.

## Smoke HTTP

El stack reconstruido desde el SHA respondió:

| Superficie | Resultado |
| --- | --- |
| `/` | 200 |
| `/up` | 200 |
| `/build/manifest.webmanifest` | 200 |
| `/build/sw.js` | 200 |
| login con usuario demo local | 200 |
| dashboard | 200 JSON |
| clientes | 200 JSON |
| lotes | 200 JSON |
| órdenes de compra | 200 JSON |
| sesiones de caja | 200 JSON |
| alertas | 200 JSON |
| notificaciones | 200 JSON |
| exportación XLSX de clientes | 200, MIME XLSX, 1.669 bytes |

La contraseña demo se utilizó sólo contra el entorno local sembrado y no se
registró en artefactos. El script de staging recibe credenciales efímeras o
interactivas.

## Feature flags

Verificadas en `false` tanto en `.env.example` como en
`.env.staging.example`:

- `ARCA_ENABLED`;
- `MERCADOPAGO_ENABLED`;
- `MERCADOPAGO_WEBHOOKS_ENABLED`;
- `PWA_PUSH_ENABLED`.

## Auditorías y riesgos

- npm reporta 12 vulnerabilidades altas al incluir herramientas de desarrollo;
  el árbol productivo reporta cero. Deben clasificarse y corregirse sin
  `npm audit fix --force`.
- El workflow actual todavía no ejecuta auditorías, Vitest, PWA, smoke Docker,
  validación OpenAPI ni publica artefactos de diagnóstico.
- La validación visual depende todavía de pruebas unitarias; Playwright no está
  instalado.
- No existe un backup restaurado, comando de integridad, preflight ni matriz de
  pilot readiness.
- Staging no puede redesplegarse sin `.env.staging`; no se extraerán secretos de
  contenedores existentes.
- ARCA, Mercado Pago y push están preparados pero no certificados ni
  habilitados.

Este documento registra evidencia del SHA indicado. No constituye aprobación
de staging ni del piloto.
