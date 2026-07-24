# Reglas de dominio

- La lógica de negocio vive en módulos de dominio, no en controllers ni Vue.
- No usar “stock” sin tipo, unidad, ubicación, lote, disponibilidad y reserva.
- Todo estado de pedido valida transición, actor, motivo cuando aplique y efectos.
- Los movimientos financieros se revierten mediante nuevos movimientos auditables, nunca se editan silenciosamente.
