# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Administración, ventas, producción, inventario, compras y cobranzas de una
panadería. Se utiliza durante la operación diaria, principalmente en escritorio
y tablet, con acceso móvil para tareas puntuales.

## Product Purpose

La Victoria Bakery centraliza pedidos, producción, inventario por lote,
cobranzas, entregas y alertas. Su éxito consiste en que cada rol pueda detectar
qué requiere atención y completar su tarea sin perder trazabilidad.

## Positioning

Una mesa operativa conectada de punta a punta: el pedido, la receta, los lotes
FEFO, la producción, el stock terminado y la cobranza comparten el mismo rastro.

## Operating Context

La interfaz se usa en administración, mostrador, depósito y área de producción.
Debe ser legible con sesiones breves, interrupciones frecuentes y uso táctil en
tablet. Las cantidades, estados y vencimientos importan más que la decoración.

## Capabilities and Constraints

- Vue 3, TypeScript, Pinia y PWA conectada primero.
- Backend Laravel y contratos `/api/v1` existentes son fuente de verdad.
- Las mutaciones críticas requieren conexión e idempotencia.
- Organización y sucursal se seleccionan dentro de la sesión.
- No se modifican reglas de negocio desde el frontend.

## Brand Commitments

La marca y el nombre visible son **La Victoria Bakery**. El usuario comercial es
`lavictoria.bakery`. La personalidad es cálida, artesanal, familiar, confiable,
limpia y moderna sin adoptar apariencia de SaaS genérico.

## Evidence on Hand

El repositorio no contiene el logo botánico mencionado en el brief. Se conserva
un monograma tipográfico `LV` como marcador neutral hasta recibir el asset real;
no debe presentarse como un logotipo nuevo.

## Product Principles

1. Mostrar primero lo que requiere acción.
2. Hacer visibles estado, sucursal y conectividad.
3. Mantener densidad operativa sin sacrificar lectura.
4. Explicar errores y su recuperación con lenguaje concreto.
5. No simular certeza cuando una operación tiene resultado desconocido.

## Accessibility & Inclusion

Objetivo mínimo WCAG 2.1 AA: navegación por teclado, foco visible, contraste,
estructura semántica, objetivos táctiles de 44 px y anuncios accesibles para
resultados asíncronos.
