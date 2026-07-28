# La Victoria Bakery

Plataforma integral para la operación de panadería en Argentina.

Identidad comercial: `lavictoria.bakery`.

## Base tecnológica

Laravel, PostgreSQL, Redis, Vue 3 + TypeScript PWA, Docker y storage compatible
con S3. La arquitectura es un monolito modular con API REST/OpenAPI.

## Desarrollo con Docker

Requiere Docker Desktop:

```bash
docker compose up --build
```

La aplicación queda en `http://localhost:8080`, la API en
`http://localhost:8000/api/v1` y Mailpit en `http://localhost:8025`. El seeder
crea usuarios por rol; su contraseña se toma de `DEMO_USER_PASSWORD`.
El valor `123456` es únicamente para demo local. El contenedor rechaza el
arranque con `APP_ENV=production` si esa contraseña sigue activa o si
`APP_DEBUG` está habilitado.

El enlace **Olvidé mi contraseña** envía el correo de recuperación a Mailpit en
el entorno local. El token vence a los 60 minutos y sólo puede utilizarse una vez.

Para instalación local sin Docker se requiere PHP 8.4+, Composer y Node 22+.
Ejecutar `scripts/install.ps1` en Windows o `scripts/install.sh` en Unix.

Crear pedidos, ajustar stock, cambiar estados, producir, cobrar y entregar
requiere `Idempotency-Key`. La organización y sucursal se derivan de la sesión y
se envían mediante `X-Organization-ID` y `X-Branch-ID`.

La PWA es *connected-first*: las mutaciones usan red exclusivamente y las
acciones financieras se deshabilitan cuando el backend no está disponible.
El service worker controla `/`, pero no cachea respuestas de `/api/v1`.

## Documentación

- [Contexto del proyecto](GEMINI.md)
- [Estrategia](STRATEGY.md)
- [Plan del flujo vertical](docs/plans/first-operable-vertical-slice.md)
- [Mapa de módulos](docs/domain/module-map.md)
- [Contrato HTTP](docs/contracts/openapi.yaml)
- [Decisiones](docs/decisions/)

No incluir secretos, credenciales ni datos personales reales en el repositorio.
