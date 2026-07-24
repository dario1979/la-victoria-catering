# Contrato de listados server-side

Todo listado persistido nuevo debe reutilizar `App\Support\ServerDataTable` en el
backend y `ServerDataTable.vue` en el frontend. No se permite descargar una
colección completa para paginarla, buscarla u ordenarla en Vue.

## Consulta HTTP

- `page`: entero desde 1.
- `per_page`: `10`, `20`, `50` o `100`.
- `search`: texto de hasta 200 caracteres.
- `sort`: clave pública incluida en la allowlist del endpoint.
- `direction`: `asc` o `desc`.
- `filters[key]`: filtros incluidos en la allowlist del endpoint.
- `export=xlsx`: descarga el mismo conjunto filtrado y ordenado.

La respuesta JSON usa:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "from": null,
    "last_page": 1,
    "per_page": 20,
    "to": null,
    "total": 0
  }
}
```

La consulta base siempre debe aplicar primero el alcance de organización y,
cuando corresponda, sucursal. Los parámetros nunca pueden seleccionar un tenant.
Las columnas de orden, búsqueda, filtros y exportación son allowlists explícitas.

La exportación genera un archivo XLSX real por streaming y conserva búsqueda,
filtros, orden, permisos y aislamiento del listado. No exporta columnas sensibles
ni ignora el tenant activo.

Exportar comparte exactamente el permiso de lectura del listado: no amplía
acceso ni habilita un conjunto alternativo. Si un dominio futuro exige una
capacidad adicional, debe pasar `canExport=false` al componente y reforzar esa
misma capacidad en el endpoint.

## Componentes frontend

La familia reutilizable comprende `ServerDataTable`, `DataTableToolbar`,
`DataTablePagination`, `DataTableColumnHeader`, `DataTableFilters`,
`DataTableExportButton`, `DataTableEmptyState` y `DataTableSkeleton`.

Las acciones por fila se implementan con el slot `actions` y se renderizan solo
si el permiso del usuario lo permite. La selección y el slot `bulk-actions`
permanecen desactivados por defecto; cada dominio debe habilitarlos expresamente.
