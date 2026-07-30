# Modelo de amenazas — operaciones financieras

## Activos

- efectivo esperado y contado;
- evidencia de cobros y egresos;
- saldos y vencimientos de clientes;
- obligaciones y pagos a proveedores;
- referencias de conciliación;
- identidad del operador y del aprobador.

## Amenazas y controles

| Amenaza | Control |
|---|---|
| Operar una caja ajena | contexto de organización/sucursal, asignación explícita y comprobación en cada mutación |
| Dos aperturas simultáneas | bloqueo pesimista de la caja y comprobación de estados activos dentro de transacción |
| Alterar o borrar un movimiento | modelos inmutables, sin endpoints de edición y reverso único |
| Reutilizar una solicitud con otro importe | `Idempotency-Key`, hash del payload y ámbito por organización/recurso |
| Redondeo silencioso | conversión decimal exacta a entero de centavos |
| Editar directamente un saldo | saldo calculado como suma del ledger |
| Pagar o cobrar de más | bloqueo de fila y comparación exacta contra saldo |
| Conciliar evidencia de otro tenant | resolución del movimiento por organización y sucursal |
| Marcar coincidencia con diferencia | `matched` rechazado cuando `difference_cents != 0` |
| Ocultar faltante de caja | estado con diferencia, alerta y aprobación con usuario/fecha |
| Enumerar IDs sin rol | autorización previa a validaciones y `404` fuera del tenant |
| Reintento ambiguo | el frontend conserva la clave idempotente hasta éxito o descarte |

## Datos sensibles

No se guardan números completos de tarjeta, tokens, secretos ni credenciales. Las referencias externas son identificadores operativos y nunca otorgan por sí solas autoridad para alterar saldos.

## Riesgos aceptados

- La unicidad de sesión activa se garantiza con bloqueo aplicativo portable.
- El vencimiento inicial de obligaciones es de 15 días mientras las condiciones del proveedor sean texto libre.
- La conciliación actual es manual; un adaptador futuro deberá validar firma y consultar estado al proveedor.
