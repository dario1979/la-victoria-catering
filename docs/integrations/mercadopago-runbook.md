# Runbook de Mercado Pago

## Estado actual

La integración está implementada contra un puerto de dominio, un adaptador fake contractual y un adaptador HTTP para sandbox. `MERCADOPAGO_ENABLED` y `MERCADOPAGO_WEBHOOKS_ENABLED` permanecen en `false` por defecto.

Esto no acredita una integración real: en este incremento no hubo credenciales sandbox, secreto de webhook ni una URL HTTPS pública para ejecutar la certificación.

## Contrato operativo

- Cada cobro conserva un estado interno separado de `external_status`.
- La creación envía `X-Idempotency-Key` al proveedor.
- Un webhook es sólo una señal: se verifica, reduce y deduplica, pero el estado se confirma consultando al proveedor.
- Los payloads persistidos admiten sólo identificadores y metadatos operativos. No se guardan tarjetas, tokens ni firmas.
- Los estados pendientes se reconsultan por jobs cada cinco minutos, con cuatro intentos y esperas de 1, 5 y 15 minutos.
- Al agotar reintentos, la transacción pasa a `manual_review`, genera una alerta para Finanzas y deja auditoría.
- El reproceso manual requiere rol financiero, tenant/sucursal coincidentes e `Idempotency-Key`.

## Habilitación en sandbox

1. Configurar secretos fuera del repositorio:
   - `MERCADOPAGO_DRIVER=sandbox`
   - `MERCADOPAGO_ENDPOINT`
   - `MERCADOPAGO_ACCESS_TOKEN`
   - `MERCADOPAGO_WEBHOOK_SECRET`
2. Mantener ambos feature flags apagados durante el despliegue.
3. Verificar HTTPS y que el proxy preserve `X-Signature` y `X-Request-Id`.
4. Ejecutar creación idempotente, consulta, pago aprobado/rechazado/pendiente, timeout, reintento y devolución parcial/total.
5. Implementar y validar el algoritmo de firma vigente del proveedor en `MercadoPagoSandboxAdapter`. El adaptador falla cerrado mientras eso no exista.
6. Confirmar que logs, errores, base de datos y APM no contienen credenciales ni datos de tarjeta.
7. Habilitar primero `MERCADOPAGO_ENABLED`; habilitar webhooks sólo después de aprobar las pruebas de firma.
8. Conciliar importe y estado contra la consulta al proveedor.

## Criterio para producción

No habilitar hasta tener sandbox aprobado, webhook HTTPS con firma válida, reconciliación observada, workers y scheduler saludables, alertas probadas y un responsable de guardia.

## Rollback

Poner `MERCADOPAGO_WEBHOOKS_ENABLED=false` y luego `MERCADOPAGO_ENABLED=false`, reiniciar configuración y workers, conservar transacciones/webhooks/auditoría y procesar pendientes mediante conciliación manual. Nunca borrar ni reescribir evidencia.
