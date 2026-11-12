# Changelog (Historial de Cambios)
Todas las modificaciones notables a este proyecto serán documentadas en este archivo.
El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y este proyecto se adhiere a [Semantic Versioning](https://semver.org/lang/es/).

## [1.0.0-baseline] - 2026-11-11
### Hito de Línea Base Inicial (LB-01) - Entregable Académico Gestión del Software
Este hito marca la congelación y aprobación de la primera versión estable y verificada del Sistema Integral de Gestión de Restaurante (SIGR), sirviendo como punto de referencia inmutable para la gestión de configuración y control de versiones.

### Añadido (Added)
- **Módulo de Usuarios y RBAC:**
  - Definición de 3 roles formales en base de datos: `admin`, `mesero` y `cliente`.
  - Hashing seguro de contraseñas mediante Bcrypt (`cost = 12`).
  - Autenticación unificada por tokens JWT (HS256) y sesiones seguras en PHP.
- **Módulo de Menú Digital y Catálogo:**
  - CRUD administrativo completo para platos (nombre, categoría, precio, descripción, imagen, disponibilidad).
  - CRUD para categorías de productos (Entradas, Platos Fuertes, Bebidas, Postres).
  - Vistas públicas responsivas para la visualización del menú.
- **Módulo de Pedidos:**
  - Carrito de compras con persistencia en sesión y validación atómica de precios en el servidor.
  - Soporte de comandas de salón con asignación de mesas y mesero.
  - Flujo de estados del pedido (`pendiente`, `en_preparacion`, `listo`, `entregado`, `cancelado`).
  - Panel operativo para meseros (`mesero.php`) con actualización ágil de estados.
- **Módulo de Reservas de Mesas:**
  - Sistema de reservas por fecha, hora, comensales y observaciones.
  - Gestión y aprobación de reservas desde el panel administrativo.
- **Módulo de Facturación Fiscal:**
  - Generación de comprobantes consecutivos (`FAC-AAAAMMDD-XXXX`).
  - Cálculo formal de base imponible, impuesto sobre las ventas (IVA 19%) y total.
  - Registro de formas de pago: Efectivo, Tarjeta y Transferencia.
- **Módulo de Cierre de Caja y Arqueo Diario:**
  - Tabla relacional `cajas_turnos` para trazabilidad de turnos.
  - Apertura con fondo inicial, acumulación en tiempo real de cobros y arqueo con cálculo de discrepancias.
  - Consolidación y reporte diario de ventas filtrable por fecha y categoría.
- **Seguridad y Endurecimiento:**
  - Implementación de `Security.php` con tokens anti-CSRF criptográficos (`random_bytes`).
  - Inyección de cabeceras HTTP de seguridad (`CSP`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`).
  - Sanitización contextual con `htmlspecialchars` contra vulnerabilidades XSS.
  - Consultas preparadas con PDO PostgreSQL para erradicar inyecciones SQL.
- **Gestión de Configuración y Aseguramiento:**
  - Batería de pruebas de integración automatizadas (`tests/integration_test.php`) con 10 aserciones clave.
  - Documento técnico entregable `Taller_LineaBase_SIGR.pdf` según estructura universitaria.
  - Licencia de software MIT formal en `LICENSE.txt`.
