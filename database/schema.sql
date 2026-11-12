-- ==========================================================
-- Esquema de Base de Datos para Sistema Integral de Gestión de Restaurante (SIGR)
-- Motor: PostgreSQL 16
-- Base de Datos: restaurante_db
-- Versión de Línea Base: v1.0.0-baseline
-- ==========================================================

-- Limpieza preventiva en orden referencial inverso
DROP TABLE IF EXISTS facturas CASCADE;
DROP TABLE IF EXISTS cajas_turnos CASCADE;
DROP TABLE IF EXISTS pedido_items CASCADE;
DROP TABLE IF EXISTS pedidos CASCADE;
DROP TABLE IF EXISTS reservas CASCADE;
DROP TABLE IF EXISTS platos CASCADE;
DROP TABLE IF EXISTS categorias CASCADE;
DROP TABLE IF EXISTS usuarios CASCADE;

-- 1. Tabla de Usuarios y Roles (RBAC)
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL DEFAULT 'cliente' CHECK (rol IN ('cliente', 'mesero', 'admin')),
    telefono VARCHAR(30),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_usuarios_email ON usuarios(email);
CREATE INDEX idx_usuarios_rol ON usuarios(rol);

-- 2. Tabla de Categorías del Menú
CREATE TABLE categorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    descripcion TEXT
);

-- 3. Tabla de Platos (Menú Digital)
CREATE TABLE platos (
    id SERIAL PRIMARY KEY,
    categoria_id INTEGER REFERENCES categorias(id) ON DELETE SET NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    precio NUMERIC(10, 2) NOT NULL,
    imagen_url TEXT,
    disponible BOOLEAN DEFAULT TRUE,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_platos_categoria ON platos(categoria_id);
CREATE INDEX idx_platos_disponible ON platos(disponible);

-- 4. Tabla de Reservas de Mesas
CREATE TABLE reservas (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    nombre_contacto VARCHAR(100) NOT NULL,
    telefono VARCHAR(30) NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    comensales INTEGER NOT NULL CHECK (comensales > 0),
    notas TEXT,
    estado VARCHAR(20) DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'confirmada', 'cancelada')),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_reservas_fecha_hora ON reservas(fecha, hora);
CREATE INDEX idx_reservas_usuario ON reservas(usuario_id);

-- 5. Tabla de Pedidos (Comandas y Domicilios / Sala)
CREATE TABLE pedidos (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    mesero_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    tipo VARCHAR(20) DEFAULT 'sala' CHECK (tipo IN ('sala', 'llevar', 'domicilio')),
    mesa VARCHAR(20) DEFAULT 'Mesa 1',
    total NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    estado VARCHAR(20) DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado')),
    direccion_entrega TEXT,
    telefono_contacto VARCHAR(30),
    notas TEXT,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_pedidos_estado ON pedidos(estado);
CREATE INDEX idx_pedidos_fecha ON pedidos(creado_en);

-- 6. Detalle de Ítems del Pedido
CREATE TABLE pedido_items (
    id SERIAL PRIMARY KEY,
    pedido_id INTEGER REFERENCES pedidos(id) ON DELETE CASCADE,
    plato_id INTEGER REFERENCES platos(id) ON DELETE RESTRICT,
    cantidad INTEGER NOT NULL CHECK (cantidad > 0),
    precio_unitario NUMERIC(10, 2) NOT NULL,
    subtotal NUMERIC(10, 2) NOT NULL
);

CREATE INDEX idx_pedido_items_pedido ON pedido_items(pedido_id);

-- 7. Tabla de Control y Cierre de Caja (Arqueo Diario)
CREATE TABLE cajas_turnos (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER REFERENCES usuarios(id),
    monto_inicial NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    monto_ventas_efectivo NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    monto_ventas_tarjeta NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    monto_ventas_transferencia NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    monto_total_esperado NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    monto_cierre_real NUMERIC(10, 2),
    diferencia NUMERIC(10, 2) DEFAULT 0.00,
    estado VARCHAR(20) DEFAULT 'abierta' CHECK (estado IN ('abierta', 'cerrada')),
    observaciones TEXT,
    fecha_apertura TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre TIMESTAMP
);

CREATE INDEX idx_cajas_turnos_estado ON cajas_turnos(estado);

-- 8. Tabla de Facturación y Comprobantes
CREATE TABLE facturas (
    id SERIAL PRIMARY KEY,
    pedido_id INTEGER UNIQUE REFERENCES pedidos(id) ON DELETE CASCADE,
    caja_turno_id INTEGER REFERENCES cajas_turnos(id) ON DELETE SET NULL,
    numero_factura VARCHAR(50) UNIQUE NOT NULL,
    subtotal NUMERIC(10, 2) NOT NULL,
    impuesto_iva NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    total NUMERIC(10, 2) NOT NULL,
    metodo_pago VARCHAR(20) NOT NULL CHECK (metodo_pago IN ('efectivo', 'tarjeta', 'transferencia')),
    cliente_nombre VARCHAR(100) NOT NULL,
    cliente_identificacion VARCHAR(30) DEFAULT '222222222222',
    fecha_emision TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_facturas_numero ON facturas(numero_factura);
CREATE INDEX idx_facturas_fecha ON facturas(fecha_emision);

-- ==========================================================
-- Datos Semilla (Seed Data)
-- Contraseñas hasheadas con bcrypt ($2y$12$)
-- admin: admin123
-- mesero: mesero123
-- cliente: cliente123
-- ==========================================================

INSERT INTO usuarios (nombre, email, password, rol, telefono) VALUES
('Administrador SIGR', 'admin@restaurante.com', '$2y$12$XZKqH61wPWHfLR8ZuIQceequzhQBAArjYmf2LI/iU.OkQWfqHCqdW', 'admin', '3001112233'),
('Carlos Perez (Mesero Sala)', 'mesero@restaurante.com', '$2y$12$gI.r1K/5fSsq2E8qS00sVOZkLhFq51.Wv1vA3q6qC30r/Z37e0wX2', 'mesero', '3004445566'),
('Ana Gomez (Cliente Frecuente)', 'cliente@correo.com', '$2y$12$6EpeZ56D5T3sGd1jS3RkbOwYMRivhYNIt6yNCDKJA771WHpzE9JoS', 'cliente', '3109998877');

-- Categorías
INSERT INTO categorias (nombre, descripcion) VALUES
('Entradas', 'Deliciosos aperitivos para abrir el apetito'),
('Platos Fuertes', 'Especialidades culinarias gourmet y tradicionales'),
('Bebidas', 'Bebidas refrescantes, coctelería y jugos naturales'),
('Postres', 'Dulces y delicias artesanales');

-- Platos y Bebidas
INSERT INTO platos (categoria_id, nombre, descripcion, precio, imagen_url) VALUES
-- Entradas
(1, 'Bruschettas Tradicionales', 'Pan rústico tostado con tomate fresco, albahaca, ajo y aceite de oliva virgen extra', 14500.00, 'https://images.unsplash.com/photo-1572695157366-5e585ab2b69f?w=600&auto=format&fit=crop'),
(1, 'Ceviche Mixto Costero', 'Pescado blanco y camarones marinados en zumo de lima con cebolla morada y cilantro', 26000.00, 'https://images.unsplash.com/photo-1535399831218-d5bd36d1a6b3?w=600&auto=format&fit=crop'),
(1, 'Empanaditas Gourmet (x3)', 'Masa crocante rellena de carne desmechada premium con ají de la casa', 12000.00, 'https://images.unsplash.com/photo-1628106235973-b684b06d4e17?w=600&auto=format&fit=crop'),

-- Platos Fuertes
(2, 'Lomo al Trapo en Salsa de Champiñones', 'Corte fino de res sellado a fuego lento acompañado de papas rústicas y ensalada fresca', 42000.00, 'https://images.unsplash.com/photo-1544025162-d76694265947?w=600&auto=format&fit=crop'),
(2, 'Salmón a las Finas Hierbas', 'Filete de salmón fresco sobre cama de puré trufado y vegetales salteados', 48500.00, 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=600&auto=format&fit=crop'),
(2, 'Risotto de Setas Silvestres', 'Arroz arborio cremoso con variedad de hongos silvestres, vino blanco y parmesano reggiano', 36000.00, 'https://images.unsplash.com/photo-1633964913295-ceb43826e7c9?w=600&auto=format&fit=crop'),
(2, 'Hamburguesa Gourmet de la Casa', '200g de carne angus, queso cheddar madurado, tocineta caramelizada y pan brioche artesanal', 29000.00, 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=600&auto=format&fit=crop'),

-- Bebidas
(3, 'Limonada de Coco Artesanal', 'Bebida cremosa y refrescante con leche de coco fresca y zumo de lima', 11500.00, 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=600&auto=format&fit=crop'),
(3, 'Jugo Natural de Maracuyá', 'Preparado con fruta 100% natural, refrescante y cítrico', 9000.00, 'https://images.unsplash.com/photo-1621263764928-df1444c5e859?w=600&auto=format&fit=crop'),
(3, 'Copa de Vino Tinto Reserva', 'Selección de la casa con notas amaderadas y cuerpo balanceado', 22000.00, 'https://images.unsplash.com/photo-1510812431401-41d2bd2722f3?w=600&auto=format&fit=crop'),

-- Postres
(4, 'Tiramisú Clásico Italiano', 'Capas de bizcocho soletilla empapadas en espresso con crema de mascarpone y cacao fino', 16000.00, 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=600&auto=format&fit=crop'),
(4, 'Volcán de Chocolate con Helado', 'Bizcocho tibio de chocolate amargo con centro líquido y helado de vainilla de Madagascar', 18500.00, 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=600&auto=format&fit=crop');

-- Reservas de Ejemplo
INSERT INTO reservas (usuario_id, nombre_contacto, telefono, fecha, hora, comensales, notas, estado) VALUES
(3, 'Ana Gomez', '3109998877', CURRENT_DATE + INTERVAL '1 day', '20:00:00', 2, 'Mesa cerca a la ventana por aniversario', 'confirmada'),
(3, 'Ana Gomez', '3109998877', CURRENT_DATE + INTERVAL '3 days', '13:30:00', 4, 'Almuerzo familiar de cumpleaños', 'pendiente');

-- Apertura inicial de Turno de Caja para Pruebas
INSERT INTO cajas_turnos (usuario_id, monto_inicial, monto_ventas_efectivo, monto_ventas_tarjeta, monto_ventas_transferencia, monto_total_esperado, estado, observaciones)
VALUES (1, 200000.00, 0.00, 0.00, 0.00, 200000.00, 'abierta', 'Apertura de turno de caja inicial para la línea base');
