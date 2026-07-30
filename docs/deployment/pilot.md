# Plan de piloto operativo

## Alcance

El piloto valida el flujo conectado de una sucursal con datos controlados: pedido, producción, stock, compra, recepción, cobro, caja, cuenta corriente, cuenta por pagar, conciliación, alerta y notificación interna. Mercado Pago, ARCA y push permanecen apagados hasta completar sus certificaciones independientes.

## Condiciones de entrada

- CI verde sobre el commit exacto desplegado.
- Backup y restauración ensayados.
- Migraciones ejecutadas desde una copia de staging.
- HTTPS, sesión, CSRF, workers, scheduler, correo y monitoreo saludables.
- Roles y sucursal revisados con responsables reales.
- Ninguna contraseña demo ni `APP_DEBUG` en el entorno.
- Runbook de rollback disponible para quien coordina el piloto.

## Participantes mínimos

- una persona de ventas;
- una de producción;
- una de inventario/compras;
- una de finanzas;
- una administradora que no ejecute las tareas cotidianas y observe permisos/auditoría.

## Guion

1. Crear un cliente, un producto y una receta aprobada.
2. Crear y confirmar un pedido con fecha requerida.
3. Validar reserva FEFO y faltantes.
4. Crear, aprobar y enviar una orden de compra.
5. Registrar recepción parcial y final con lote, discrepancia y costo.
6. Iniciar y completar producción; revisar consumo, rendimiento, merma y lote producido.
7. Abrir caja, registrar cobro en efectivo y un movimiento manual.
8. Cerrar caja con coincidencia; en otro caso controlado, generar y aprobar una diferencia.
9. Revisar cuenta del cliente, obligación del proveedor y conciliación manual.
10. Confirmar alerta, notificación interna, email y preferencia de horas silenciosas.
11. Repetir cada mutación con la misma clave idempotente y confirmar que no duplica.
12. Intentar con un rol y tenant no autorizados y confirmar `403`/`404`.

## Evidencia

Registrar commit, fecha, sucursal, participantes, IDs de entidades de prueba, capturas sin datos sensibles, resultado esperado/obtenido, auditoría, tiempos de respuesta y cualquier intervención manual.

## Criterios de detención

Detener nuevas mutaciones si aparece mezcla de tenants, saldo incorrecto, doble movimiento, pérdida de trazabilidad, error de autorización, migración irreversible no prevista, cola acumulada o imposibilidad de restaurar. No “arreglar” evidencia editando filas.

## Rollback

Desactivar acceso al piloto, detener workers antes de restaurar si hay mutaciones en curso, conservar logs y auditoría, restaurar el backup verificado o desplegar la versión anterior según el incidente, ejecutar comprobaciones de integridad y documentar la decisión. Los feature flags externos permanecen apagados durante todo el procedimiento.

## Salida

El piloto se aprueba sólo con todos los escenarios obligatorios, cero defectos críticos/altos abiertos, reconciliación de saldos, revisión de permisos, backup restaurable y firma explícita de cada responsable funcional.
# Ensayo automatizado

Antes del piloto humano, ejecutar el [ensayo técnico reproducible](pilot-rehearsal.md). Su resultado complementa este plan; no reemplaza la aceptación de los operadores.
