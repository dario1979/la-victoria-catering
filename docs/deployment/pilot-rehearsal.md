# Ensayo técnico del piloto

Estado: **implementado y automatizado**. No sustituye el piloto humano.

## Alcance

El escenario crea una organización y sucursal aisladas, dos usuarios ficticios y datos maestros mínimos. El ensayo atraviesa por la API real pedido, FEFO, faltante, compra, recepción parcial/final, producción, caja, cobro, cuenta corriente, cuenta por pagar, conciliación, alerta, notificación, idempotencia y un permiso negativo. Finaliza con `bakery:integrity-check` focalizado.

Mercado Pago, ARCA y push permanecen desactivados. No se crean credenciales conocidas ni se contactan proveedores externos.

## Uso

```bash
php artisan bakery:seed-pilot-scenario --scenario=pilot-technical --confirm
php artisan bakery:pilot-rehearsal --scenario=pilot-technical --confirm
php artisan bakery:pilot-rehearsal --scenario=pilot-technical --confirm --format=json
```

El comando es idempotente: un escenario aprobado se informa como replay y no duplica operaciones. Genera informes JSON y Markdown privados bajo `storage/app/private/pilot-rehearsals`.

La limpieza es explícita y sólo actúa sobre la organización cuyo nombre conserva el marcador exacto del escenario:

```bash
php artisan bakery:pilot-rehearsal --scenario=pilot-technical --confirm --cleanup
```

La limpieza es lógica: desactiva la sucursal —el middleware rechaza entonces todo acceso operativo— y elimina los informes privados. Conserva membresías para la integridad histórica de notificaciones, además de movimientos, ledgers y auditoría inmutables.

## Interpretación

- `passed`: el recorrido y todas las invariantes automatizables aprobaron.
- `failed`: la transacción del ensayo se revirtió y el motivo quedó asociado al escenario.
- Los IDs y tiempos del informe son evidencia técnica del dataset y hardware indicados, no un SLA.
- La aceptación operativa, fiscal y de usuarios sigue requiriendo validación humana.
