# Corrección de identidad comercial

## Alcance

Reemplazar la identidad visible anterior por **La Victoria Bakery** en frontend,
documentación, OpenAPI, configuración, Docker y datos de demostración.
El slug es `la-victoria-bakery`, el identificador de paquetes es
`lavictoria-bakery` y la cuenta comercial es `lavictoria.bakery`.

## Compatibilidad

Se conservan rutas, contratos, migraciones e identificadores persistidos neutrales
como `la_victoria` y `la_victoria_session`. El proyecto de Docker Compose adopta
el slug `la-victoria-bakery`.

## Criterios de salida

- No quedan referencias a la identidad comercial anterior.
- La aplicación compila y las pruebas relevantes continúan pasando.
- No se alteran contratos HTTP ni datos existentes.
