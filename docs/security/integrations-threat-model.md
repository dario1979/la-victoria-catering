# Modelo de amenazas — integraciones y notificaciones

| Amenaza | Control |
|---|---|
| Activación accidental sin credenciales | feature flags apagados por defecto y adaptadores sandbox que fallan cerrado |
| Webhook falsificado | verificación obligatoria; el sandbox no acepta webhooks hasta implementar la firma vigente |
| Webhook repetido | identificador externo único y respuesta idempotente |
| Webhook usado como verdad contable | sólo dispara evidencia; el estado se confirma consultando al proveedor |
| Filtración de tarjeta o token | whitelist de payload, firma no persistida, secretos sólo por entorno |
| Reutilización de clave con otro payload | hash y rechazo de `Idempotency-Key` reutilizada |
| Mezcla de tenants | consultas y rutas privadas limitadas por organización y sucursal; `404` para IDs ajenos |
| Estado externo ambiguo | estado externo separado y mapeo desconocido a `manual_review` |
| Reintentos infinitos | intentos acotados, backoff, alerta y revisión manual |
| Corrección fiscal destructiva | modelo sin borrado, sin endpoint de edición y reproceso auditado sólo antes de autorización |
| Inferencia fiscal incorrecta | campos fiscales explícitos y runbook que exige definición profesional |
| Spam o duplicación | deduplicación por ocurrencia, preferencias por canal y horas silenciosas |
| Robo de suscripción push | endpoint y claves cifrados, hash separado y feature flag |
| Exposición entre usuarios | centro filtrado por tenant y `user_id` autenticado |

## Riesgos abiertos

- La firma real de Mercado Pago no está implementada ni probada sin credenciales y documentación sandbox vigente.
- El adaptador ARCA real requiere certificados, definición fiscal y homologación.
- Push PWA no tiene transporte; la capacidad permanece deshabilitada.
- El mailer actual confirma aceptación local, no entrega final del proveedor.
- Los webhooks recibidos antes de asociar una referencia interna quedan globales y no se exponen por API; la asociación multi-tenant debe validarse en sandbox antes de habilitarlos.
