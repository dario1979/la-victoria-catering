# Harness de calidad y seguridad

Antes de modificar: confirmar rama `develop`, árbol identificado y ausencia de secretos/datos reales. No ejecutar comandos destructivos ni cambiar la rama principal.

Después de modificar: ejecutar formato, análisis estático y pruebas configuradas; revisar contratos, migraciones reversibles y documentación afectada.

Invariantes: finanzas idempotentes y auditables; stock con producto, unidad, ubicación, lote, cantidad y motivo; transiciones con actor y efectos; alertas con evento, condición, severidad, destinatario, acción, deduplicación y cierre. ARCA y Mercado Pago solo por puertos/adaptadores.
