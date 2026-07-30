# Runbook de homologación ARCA

## Estado actual

El módulo ofrece puerto de dominio, adaptador fake determinista, adaptador sandbox que falla cerrado, persistencia inmutable, estados separados, idempotencia, jobs de reintento, alertas y reproceso manual auditado. `ARCA_ENABLED=false` por defecto.

No se infirieron CUIT, condición fiscal, punto de venta, tipo de comprobante, alícuotas ni domicilio. Esos datos deben ser definidos y validados por la organización y su asesor fiscal. No hubo certificados ni acceso a homologación, por lo que no se declara facturación real.

## Estados

`pending`, `processing`, `authorized`, `rejected`, `retrying`, `manual_review`, `cancelled_or_credited`.

Un comprobante no se elimina. Toda corrección de un documento autorizado debe realizarse con el documento fiscal compensatorio que corresponda, una vez definido por asesoramiento fiscal.

## Preparación de homologación

1. Definir por escrito CUIT emisor, condición fiscal, puntos de venta, tipos de comprobante, monedas y tratamiento impositivo.
2. Obtener certificados de homologación y montarlos como secreto de runtime, nunca dentro de la imagen o repositorio.
3. Completar el adaptador sandbox con autenticación y servicio vigente de ARCA.
4. Configurar:
   - `ARCA_DRIVER=sandbox`
   - `ARCA_ENDPOINT`
   - `ARCA_CERTIFICATE_PATH`
5. Mantener `ARCA_ENABLED=false` durante el despliegue.
6. Probar autorización, rechazo, timeout, consulta posterior, idempotencia, caída temporal, reintentos agotados y revisión manual.
7. Verificar exactitud de neto + impuesto = total en centavos y la conservación segura de solicitud/respuesta.
8. Revisar la muestra de comprobantes con el asesor fiscal y documentar su aprobación.

## Habilitación y rollback

Habilitar sólo después de homologación aprobada, backup verificado, workers saludables y monitoreo de rechazos. Para rollback, poner `ARCA_ENABLED=false`, reiniciar configuración/workers, conservar todos los documentos y continuar con revisión manual. Nunca corregir borrando o editando evidencia autorizada.
