# Centro de notificaciones

## Alcance

El centro personal reúne entregas producidas por alertas abiertas y las dirige según el rol del usuario. Los canales definidos son:

- `internal`: registro visible en la PWA;
- `email`: correo enviado por la cola configurada;
- `pwa_push`: suscripción cifrada y contrato preparado, desactivado hasta instalar un transporte real.

WhatsApp queda fuera de alcance.

## Entrega

Los estados son `pending`, `sent`, `delivered`, `failed` y `cancelled`. La clave `alert:{id}:occurrence:{occurrences}` evita duplicados por usuario y canal. Los destinatarios se resuelven desde el rol vigente de la organización.

Cada usuario puede habilitar canales y definir inicio, fin y zona horaria de horas silenciosas. Una ventana que cruza medianoche se posterga correctamente hasta su fin. El scheduler encola alertas y procesa hasta 100 entregas disponibles por minuto. Los fallos se reintentan tres veces con espera creciente; luego quedan visibles como `failed`.

## Privacidad y límites

- Las preferencias son personales y el listado exige organización, sucursal y sesión válidas.
- Las suscripciones push guardan endpoint y claves cifrados; sólo se conserva un hash para deduplicar.
- No se expone un endpoint de envío arbitrario.
- `PWA_PUSH_ENABLED=false` impide registrar o encolar push mientras no exista transporte VAPID.
- El correo se considera `sent` al ser aceptado por el mailer; confirmar entrega final requiere eventos del proveedor, aún no integrados.

## Operación

Workers de cola y `schedule:work` deben estar activos. Monitorear entregas `pending` antiguas y `failed`; antes de reintentar manualmente, confirmar que el evento sigue siendo accionable y que no se duplicará por fuera de la clave de deduplicación.
