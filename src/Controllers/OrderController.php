<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Utils\Security;
use PDO;
use Exception;

class OrderController {
    /**
     * Listar pedidos
     * GET /api/pedidos
     */
    public function index(): void {
        $authUser = AuthMiddleware::authenticate();
        $db = Database::getConnection();
        $userRole = $authUser->rol ?? '';

        if (in_array($userRole, ['admin', 'mesero'], true)) {
            $stmt = $db->query("
                SELECT p.*, u.nombre as usuario_nombre, u.email as usuario_email,
                       COUNT(pi.id) as total_items,
                       f.numero_factura, f.id as factura_id
                FROM pedidos p
                LEFT JOIN usuarios u ON p.usuario_id = u.id
                LEFT JOIN pedido_items pi ON p.id = pi.pedido_id
                LEFT JOIN facturas f ON p.id = f.pedido_id
                GROUP BY p.id, u.nombre, u.email, f.numero_factura, f.id
                ORDER BY p.creado_en DESC
            ");
            $pedidos = $stmt->fetchAll();
        } else {
            $stmt = $db->prepare("
                SELECT p.*, COUNT(pi.id) as total_items,
                       f.numero_factura, f.id as factura_id
                FROM pedidos p
                LEFT JOIN pedido_items pi ON p.id = pi.pedido_id
                LEFT JOIN facturas f ON p.id = f.pedido_id
                WHERE p.usuario_id = :user_id
                GROUP BY p.id, f.numero_factura, f.id
                ORDER BY p.creado_en DESC
            ");
            $stmt->execute(['user_id' => $authUser->id]);
            $pedidos = $stmt->fetchAll();
        }

        echo json_encode([
            'success' => true,
            'pedidos' => $pedidos
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Crear un nuevo pedido
     * POST /api/pedidos
     */
    public function store(): void {
        $authUser = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $tipo = in_array($data['tipo'] ?? '', ['sala', 'llevar', 'domicilio'], true) ? $data['tipo'] : 'sala';
        $mesa = trim($data['mesa'] ?? 'Mesa 1');
        $direccion = trim($data['direccion_entrega'] ?? 'En sala');
        $telefono = trim($data['telefono_contacto'] ?? ($authUser->telefono ?? ''));
        $notas = trim($data['notas'] ?? '');
        $items = $data['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Debe indicar al menos un plato en el pedido.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            $total = 0.0;
            $itemsValidados = [];
            $platoStmt = $db->prepare("SELECT id, nombre, precio FROM platos WHERE id = :id AND disponible = true");

            foreach ($items as $item) {
                $platoId = (int)($item['plato_id'] ?? 0);
                $cantidad = (int)($item['cantidad'] ?? 0);

                if ($platoId <= 0 || $cantidad <= 0) {
                    continue;
                }

                $platoStmt->execute(['id' => $platoId]);
                $plato = $platoStmt->fetch();

                if (!$plato) {
                    throw new Exception("El plato con ID $platoId no existe o no se encuentra disponible.");
                }

                $precio = (float)$plato['precio'];
                $subtotal = $precio * $cantidad;
                $total += $subtotal;

                $itemsValidados[] = [
                    'plato_id' => $platoId,
                    'nombre' => $plato['nombre'],
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'subtotal' => $subtotal
                ];
            }

            if (empty($itemsValidados)) {
                throw new Exception("No se encontraron productos válidos en el pedido.");
            }

            $meseroId = ($authUser->rol === 'mesero') ? $authUser->id : null;

            $pedidoStmt = $db->prepare("
                INSERT INTO pedidos (usuario_id, mesero_id, tipo, mesa, total, estado, direccion_entrega, telefono_contacto, notas)
                VALUES (:usuario_id, :mesero_id, :tipo, :mesa, :total, 'pendiente', :direccion, :telefono, :notas)
                RETURNING id, usuario_id, total, estado, mesa, tipo, creado_en
            ");
            $pedidoStmt->execute([
                'usuario_id' => $authUser->id,
                'mesero_id' => $meseroId,
                'tipo' => $tipo,
                'mesa' => Security::escape($mesa),
                'total' => $total,
                'direccion' => Security::escape($direccion),
                'telefono' => Security::escape($telefono),
                'notas' => Security::escape($notas)
            ]);
            $pedidoCreado = $pedidoStmt->fetch();
            $pedidoId = $pedidoCreado['id'];

            $itemInsertStmt = $db->prepare("
                INSERT INTO pedido_items (pedido_id, plato_id, cantidad, precio_unitario, subtotal)
                VALUES (:pedido_id, :plato_id, :cantidad, :precio_unitario, :subtotal)
            ");

            foreach ($itemsValidados as $item) {
                $itemInsertStmt->execute([
                    'pedido_id' => $pedidoId,
                    'plato_id' => $item['plato_id'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'subtotal' => $item['subtotal']
                ]);
            }

            $db->commit();

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => '¡Pedido registrado exitosamente!',
                'pedido' => $pedidoCreado,
                'items' => $itemsValidados
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Consultar detalle de un pedido
     * GET /api/pedidos/{id}
     */
    public function show(int $id): void {
        $authUser = AuthMiddleware::authenticate();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT p.*, u.nombre as cliente_nombre, u.email as cliente_email,
                   m.nombre as mesero_nombre,
                   f.numero_factura, f.subtotal as factura_subtotal, f.impuesto_iva, f.metodo_pago
            FROM pedidos p
            LEFT JOIN usuarios u ON p.usuario_id = u.id
            LEFT JOIN usuarios m ON p.mesero_id = m.id
            LEFT JOIN facturas f ON p.id = f.pedido_id
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pedido no encontrado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $itemsStmt = $db->prepare("
            SELECT pi.*, p.nombre as plato_nombre, p.imagen_url
            FROM pedido_items pi
            LEFT JOIN platos p ON pi.plato_id = p.id
            WHERE pi.pedido_id = :pedido_id
        ");
        $itemsStmt->execute(['pedido_id' => $id]);
        $items = $itemsStmt->fetchAll();

        echo json_encode([
            'success' => true,
            'pedido' => $pedido,
            'items' => $items
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Actualizar estado del pedido (Admin o Mesero)
     * PUT /api/pedidos/{id}/estado
     */
    public function updateStatus(int $id, ?string $nuevoEstado = null): void {
        $authUser = AuthMiddleware::requireStaff();
        if ($nuevoEstado === null) {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $nuevoEstado = $data['estado'] ?? '';
        }

        if (!in_array($nuevoEstado, ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Estado de pedido no válido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $update = $db->prepare("UPDATE pedidos SET estado = :estado WHERE id = :id RETURNING *");
        $update->execute(['estado' => $nuevoEstado, 'id' => $id]);

        $pedido = $update->fetch();
        if (!$pedido) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pedido no encontrado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => "Estado del pedido actualizado a '$nuevoEstado'.",
            'pedido' => $pedido
        ], JSON_UNESCAPED_UNICODE);
    }
}
