<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Utils\Security;
use PDO;
use Exception;

class CashRegisterController {
    /**
     * Obtiene el turno de caja actualmente abierto o el último registrado
     */
    public function getActiveShift(): ?array {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT ct.*, u.nombre as cajero_nombre
            FROM cajas_turnos ct
            LEFT JOIN usuarios u ON ct.usuario_id = u.id
            WHERE ct.estado = 'abierta'
            ORDER BY ct.id DESC
            LIMIT 1
        ");
        $shift = $stmt->fetch();
        return $shift ?: null;
    }

    /**
     * Abrir un nuevo turno de caja
     */
    public function openShift(float $montoInicial, string $observaciones = ''): array {
        $user = AuthMiddleware::requireStaff();
        $db = Database::getConnection();

        // Verificar si ya hay un turno abierto
        $active = $this->getActiveShift();
        if ($active) {
            throw new Exception("Ya existe un turno de caja abierto (Turno #" . $active['id'] . "). Debe cerrarlo antes de abrir uno nuevo.");
        }

        $stmt = $db->prepare("
            INSERT INTO cajas_turnos (usuario_id, monto_inicial, monto_total_esperado, estado, observaciones)
            VALUES (:usuario_id, :monto_inicial, :monto_inicial, 'abierta', :observaciones)
            RETURNING *
        ");
        $stmt->execute([
            'usuario_id' => $user->id,
            'monto_inicial' => $montoInicial,
            'observaciones' => Security::escape($observaciones)
        ]);

        return $stmt->fetch();
    }

    /**
     * Cerrar turno de caja con arqueo
     */
    public function closeShift(int $shiftId, float $montoReal, string $observaciones = ''): array {
        AuthMiddleware::requireStaff();
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM cajas_turnos WHERE id = :id AND estado = 'abierta'");
        $stmt->execute(['id' => $shiftId]);
        $shift = $stmt->fetch();

        if (!$shift) {
            throw new Exception("El turno #$shiftId no existe o ya se encuentra cerrado.");
        }

        $esperado = (float)$shift['monto_total_esperado'];
        $diferencia = $montoReal - $esperado;

        $update = $db->prepare("
            UPDATE cajas_turnos
            SET monto_cierre_real = :monto_real,
                diferencia = :diferencia,
                estado = 'cerrada',
                observaciones = CASE WHEN :obs <> '' THEN observaciones || ' | Cierre: ' || :obs ELSE observaciones END,
                fecha_cierre = CURRENT_TIMESTAMP
            WHERE id = :id
            RETURNING *
        ");
        $update->execute([
            'monto_real' => $montoReal,
            'diferencia' => $diferencia,
            'obs' => Security::escape($observaciones),
            'id' => $shiftId
        ]);

        return $update->fetch();
    }

    /**
     * Generar y emitir factura para un pedido entregado/listo
     */
    public function issueInvoice(int $pedidoId, string $metodoPago, string $clienteNombre, string $clienteId = '222222222222'): array {
        AuthMiddleware::requireStaff();
        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            // 1. Obtener pedido
            $pedidoStmt = $db->prepare("SELECT * FROM pedidos WHERE id = :id");
            $pedidoStmt->execute(['id' => $pedidoId]);
            $pedido = $pedidoStmt->fetch();

            if (!$pedido) {
                throw new Exception("El pedido #$pedidoId no existe.");
            }

            // Verificar si ya tiene factura
            $facturaCheck = $db->prepare("SELECT * FROM facturas WHERE pedido_id = :pedido_id");
            $facturaCheck->execute(['pedido_id' => $pedidoId]);
            if ($facturaCheck->fetch()) {
                throw new Exception("El pedido #$pedidoId ya tiene una factura emitida.");
            }

            // 2. Obtener turno activo
            $activeShift = $this->getActiveShift();
            $shiftId = $activeShift ? (int)$activeShift['id'] : null;

            // 3. Cálculos fiscales (IVA 19% incluido en total)
            $total = (float)$pedido['total'];
            $subtotal = round($total / 1.19, 2);
            $iva = round($total - $subtotal, 2);

            // Generar número consecutivo
            $countStmt = $db->query("SELECT COUNT(*) FROM facturas");
            $consecutivo = (int)$countStmt->fetchColumn() + 1;
            $numeroFactura = 'FAC-' . date('Ymd') . '-' . str_pad((string)$consecutivo, 4, '0', STR_PAD_LEFT);

            // 4. Insertar factura
            $insertFactura = $db->prepare("
                INSERT INTO facturas (pedido_id, caja_turno_id, numero_factura, subtotal, impuesto_iva, total, metodo_pago, cliente_nombre, cliente_identificacion)
                VALUES (:pedido_id, :caja_turno_id, :numero_factura, :subtotal, :impuesto_iva, :total, :metodo_pago, :cliente_nombre, :cliente_identificacion)
                RETURNING *
            ");
            $insertFactura->execute([
                'pedido_id' => $pedidoId,
                'caja_turno_id' => $shiftId,
                'numero_factura' => $numeroFactura,
                'subtotal' => $subtotal,
                'impuesto_iva' => $iva,
                'total' => $total,
                'metodo_pago' => $metodoPago,
                'cliente_nombre' => Security::escape($clienteNombre),
                'cliente_identificacion' => Security::escape($clienteId)
            ]);
            $factura = $insertFactura->fetch();

            // 5. Actualizar totales del turno de caja activo si existe
            if ($shiftId) {
                $campoMetodo = match ($metodoPago) {
                    'efectivo' => 'monto_ventas_efectivo',
                    'tarjeta' => 'monto_ventas_tarjeta',
                    default => 'monto_ventas_transferencia'
                };

                $updateShift = $db->prepare("
                    UPDATE cajas_turnos
                    SET $campoMetodo = $campoMetodo + :monto,
                        monto_total_esperado = monto_total_esperado + :monto
                    WHERE id = :id
                ");
                $updateShift->execute([
                    'monto' => $total,
                    'id' => $shiftId
                ]);
            }

            // Marcar pedido como entregado si no lo estaba
            $db->prepare("UPDATE pedidos SET estado = 'entregado' WHERE id = :id")->execute(['id' => $pedidoId]);

            $db->commit();
            return $factura;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Generar consolidado de reporte de ventas diario
     */
    public function getDailySalesReport(?string $fecha = null): array {
        $db = Database::getConnection();
        $targetDate = $fecha ?: (string)$db->query("SELECT CURRENT_DATE")->fetchColumn();

        // Totales generales
        $stmtTotales = $db->prepare("
            SELECT 
                COUNT(f.id) as cantidad_facturas,
                COALESCE(SUM(f.subtotal), 0) as subtotal_acumulado,
                COALESCE(SUM(f.impuesto_iva), 0) as iva_acumulado,
                COALESCE(SUM(f.total), 0) as total_ventas,
                COALESCE(SUM(CASE WHEN f.metodo_pago = 'efectivo' THEN f.total ELSE 0 END), 0) as total_efectivo,
                COALESCE(SUM(CASE WHEN f.metodo_pago = 'tarjeta' THEN f.total ELSE 0 END), 0) as total_tarjeta,
                COALESCE(SUM(CASE WHEN f.metodo_pago = 'transferencia' THEN f.total ELSE 0 END), 0) as total_transferencia
            FROM facturas f
            WHERE CAST(f.fecha_emision AS DATE) = :fecha
        ");
        $stmtTotales->execute(['fecha' => $targetDate]);
        $totales = $stmtTotales->fetch();

        // Ventas por categoría
        $stmtCategorias = $db->prepare("
            SELECT 
                c.nombre as categoria_nombre,
                COALESCE(SUM(pi.cantidad), 0) as unidades_vendidas,
                COALESCE(SUM(pi.subtotal), 0) as total_categoria
            FROM categorias c
            LEFT JOIN platos p ON c.id = p.categoria_id
            LEFT JOIN pedido_items pi ON p.id = pi.plato_id
            LEFT JOIN pedidos ped ON pi.pedido_id = ped.id
            LEFT JOIN facturas f ON ped.id = f.pedido_id
            WHERE CAST(f.fecha_emision AS DATE) = :fecha
            GROUP BY c.id, c.nombre
            ORDER BY total_categoria DESC
        ");
        $stmtCategorias->execute(['fecha' => $targetDate]);
        $categorias = $stmtCategorias->fetchAll();

        // Listado de facturas del día
        $stmtFacturas = $db->prepare("
            SELECT f.*, p.mesa, p.tipo as tipo_pedido
            FROM facturas f
            JOIN pedidos p ON f.pedido_id = p.id
            WHERE CAST(f.fecha_emision AS DATE) = :fecha
            ORDER BY f.id DESC
        ");
        $stmtFacturas->execute(['fecha' => $targetDate]);
        $facturas = $stmtFacturas->fetchAll();

        return [
            'fecha' => $targetDate,
            'totales' => $totales,
            'categorias' => $categorias,
            'facturas' => $facturas
        ];
    }
}
