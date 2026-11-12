# Sistema Integral de Gestión de Restaurante (SIGR)
**Línea Base Inicial de Software (Baseline v1.0.0-baseline)**

Sistema web empresarial y modular desarrollado con **PHP 8.4** y **PostgreSQL 16**, diseñado bajo principios de arquitectura limpia, control de acceso basado en roles (RBAC), **enrutamiento relativo portable** y endurecimiento de seguridad según las pautas **OWASP ASVS**.

---

## 📋 Información del Proyecto y Gestión de Configuración

- **Nombre del Sistema:** Sistema Integral de Gestión de Restaurante (SIGR)
- **Autor / Integrante:** Juan Pablo López Diaz
- **Asignatura:** Gestión del Software
- **Fecha de Establecimiento de Línea Base:** 11 de Noviembre de 2026
- **Identificador de Línea Base:** `LB-01`
- **Etiqueta de Versión Git:** `v1.0.0-baseline`
- **Repositorio Oficial:** [https://github.com/tails1022/Restaurante_Gestion_del_software](https://github.com/tails1022/Restaurante_Gestion_del_software)
- **Rama Principal:** `main`
- **Servidor Web:** Apache HTTP Server 2.4 en **puerto 8080** (`C:\Apache24\htdocs\restaurante`)
- **Motor de Base de Datos:** PostgreSQL 16 x64 (puerto 5432)
- **Estrategia de Enrutamiento:** **100% Rutas Relativas**, garantizando total portabilidad ante cambios de puerto (8080, 80, 8000), host (`localhost`, IP LAN o dominio) o estructura de directorios.

---

## 🚀 Componentes y Módulos de la Línea Base

1. **Módulo de Autenticación y Autorización (RBAC):**
   - Control de acceso por roles: `administrador`, `mesero` y `cliente`.
   - Autenticación criptográfica con `password_hash` (Bcrypt, costo 12) y tokens JWT (HS256).
   - Manejo de sesiones seguras con directivas `HttpOnly`, `SameSite=Lax` y regeneración de ID.

2. **Módulo de Menú Digital y Catálogo (CRUD):**
   - Gestión integral de platos (creación, edición, precios, disponibilidad y eliminación preventiva).
   - Categorización del menú (Entradas, Platos Fuertes, Bebidas, Postres).

3. **Módulo de Registro y Seguimiento de Pedidos:**
   - Carrito de compras con recálculo atómico del lado del servidor para evitar alteración de precios.
   - Máquina de estados en tiempo real: `pendiente` ➔ `en_preparacion` ➔ `listo` ➔ `entregado` (o `cancelado`).
   - Trazabilidad de mesa y modalidad (sala, llevar, domicilio).

4. **Módulo de Reservas de Mesas:**
   - Agendamiento con validación de fecha, hora, número de comensales y requerimientos especiales.
   - Gestión de estados de reserva (`pendiente`, `confirmada`, `cancelada`).

5. **Módulo de Facturación:**
   - Emisión de comprobantes fiscales con numeración correlativa (`FAC-AAAAMMDD-XXXX`).
   - Discriminación tributaria: Subtotal base, IVA (19%) y total consolidado.
   - Registro de métodos de pago (`efectivo`, `tarjeta`, `transferencia`).

6. **Módulo de Cierre de Caja y Arqueo Diario:**
   - Apertura de turno con fondo inicial en efectivo.
   - Conciliación acumulativa automática de ventas por cada medio de pago.
   - Arqueo físico de caja al cierre de turno, calculando discrepancias (cuadre exacto, sobrante o faltante).
   - Reporte consolidado de ventas diarias filtrable por fecha y categoría de producto.

---

## 🛠️ Requisitos Técnicos del Entorno

- **PHP:** Versión 8.4 o superior (con extensiones activas: `pdo_pgsql`, `pgsql`, `session`, `json`, `openssl`).
- **PostgreSQL:** Versión 16 (puerto 5432).
- **Apache:** Apache 2.4 en **puerto 8080** con módulo `mod_rewrite` activo.
- **Git:** Versión 2.40+.
- **Composer:** Gestor de paquetes PHP.

---

## ⚙️ Guía de Instalación y Despliegue

### 1. Clonar el Repositorio
```bash
git clone https://github.com/tails1022/Restaurante_Gestion_del_software.git
cd Restaurante_Gestion_del_software
```

### 2. Configurar Variables de Entorno
Copia o edita el archivo `.env`:
```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=restaurante_db
DB_USER=postgres
DB_PASS=tu_contraseña_postgres

JWT_SECRET=f7a94e1b8c3d2f0a6e5b4c1d9a8f7e2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f
JWT_EXPIRATION=86400
VULNERABLE_MODE=false
```

### 3. Inicializar Base de Datos PostgreSQL 16
Ejecuta el script automatizado para crear la base de datos, las 8 tablas, los índices y los datos semilla:
```bash
php database/setup.php
```

### 4. Despliegue en Apache (Puerto 8080)
El aplicativo se encuentra desplegado en `C:\Apache24\htdocs\restaurante`.
Accede desde tu navegador web a la dirección con el puerto 8080 configurado:
```
http://localhost:8080/restaurante/public/index.php
```
> **Nota de Portabilidad:** Todas las vistas, formularios y controladores operan con **rutas relativas**, por lo que la navegación, redirecciones y recursos estáticos funcionan de manera homogénea independientemente del puerto o dominio configurado en Apache.

---

## 🔑 Credenciales Predeterminadas (Línea Base)

| Rol | Correo Electrónico | Contraseña | Permisos Principales | Enlace Relativo |
| :--- | :--- | :--- | :--- | :--- |
| **Administrador** | `admin@restaurante.com` | `admin123` | Control total del sistema, CRUD menú, reservas, cierre de caja y auditoría. | `public/admin.php` |
| **Mesero** | `mesero@restaurante.com` | `mesero123` | Toma de comandas en sala, cambio de estados de cocina y emisión de facturas. | `public/mesero.php` |
| **Cliente** | `cliente@correo.com` | `cliente123` | Consulta de menú, pedidos con carrito, reservas y seguimiento en tiempo real. | `public/index.php` |

---

## 🧪 Ejecución de Pruebas de Integración Automatizadas

Para validar la integridad funcional y técnica de la línea base:
```bash
php tests/integration_test.php
```

Resultado verificado: `10 APROBADAS / 0 FALLIDAS - ESTADO: TOTALMENTE ESTABLE (HITO APROBADO)`.
