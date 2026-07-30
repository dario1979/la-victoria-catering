# Verificación de integridad operativa

Estado: **implementado y probado**.

`bakery:integrity-check` inspecciona la base sin modificarla. Está diseñado para ejecutarse después de un despliegue, antes y después de una restauración, y durante diagnóstico operativo.

## Uso

```bash
php artisan bakery:integrity-check
php artisan bakery:integrity-check --format=json
php artisan bakery:integrity-check --organization=1
php artisan bakery:integrity-check --organization=1 --branch=2 --format=json
```

El código de salida es `0` cuando todas las verificaciones pasan, `1` cuando encuentra violaciones y `2` ante opciones o alcances inválidos. La salida sólo contiene nombres de verificaciones, conteos e IDs de muestra; no expone payloads ni datos personales.

## Invariantes cubiertas

- cantidades, reservas, movimientos y tenant de inventario;
- totales y máquina de estados de pedidos;
- estados, consumos y lote elaborado de producción;
- pagos, saldo pagado, caja, sesiones duplicadas y ledger de clientes;
- obligaciones y pagos a proveedores;
- conciliaciones;
- recepciones y cantidades aceptadas;
- documentos fiscales y transacciones externas;
- alertas, notificaciones y membresías;
- forma mínima de registros idempotentes;
- referencias de organización y sucursal entre las entidades anteriores.

Las restricciones físicas de claves foráneas y unicidad siguen siendo la primera defensa. El comando agrega comprobaciones semánticas que una FK aislada no puede expresar.

## Interpretación

Una fila `FAIL` exige detener migraciones destructivas, restauraciones o habilitación del piloto. Los IDs de muestra sirven para localizar registros; deben investigarse con acceso administrativo y sin editar movimientos o ledgers originales.

CI conserva la salida JSON del comando como evidencia del job backend. El smoke Docker también lo ejecuta sobre PostgreSQL 17 sembrado.
