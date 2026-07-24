# ADR-001: stack y monolito modular

**Estado:** aceptada.

Usar Laravel con PostgreSQL, Redis, Vue 3 + TypeScript PWA, Docker y storage S3. Se adopta un monolito modular API-first con eventos internos.

Esto acelera el desarrollo con límites claros y evita la complejidad operativa de microservicios prematuros. La extracción futura de un módulo exige evidencia de carga, autonomía o despliegue independiente.
