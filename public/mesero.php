<?php
$pageTitle = 'Módulo de Meseros y Comandas - SIGR';
$activeNav = 'mesero';

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/includes/header.php';

use App\Config\Database;
use App\Utils\Security;
use App\Controllers\OrderController;

// Requiere rol de mesero o admin
if (!$currentUser || !in_array($currentUser->rol ?? '', ['mesero', 'admin'], true)) {
    $_SESSION['flash_error'] = 'Acceso denegado: Se requiere rol de Mesero o Administrador.';
    header('Location: login.php');
    exit;
}

$db = Database::getConnection();
$orderCtrl = new OrderController();

// Procesar Actualización de Estado de Pedido POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = 'Token de seguridad inválido.';
        header('Location: mesero.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'cambiar_estado') {
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        $nuevoEstado = $_POST['nuevo_estado'] ?? '';

        if (in_array($nuevoEstado, ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'], true)) {
            $stmt = $db->prepare("UPDATE pedidos SET estado = :estado WHERE id = :id");
            $stmt->execute(['estado' => $nuevoEstado, 'id' => $pedidoId]);
            $_SESSION['flash_success'] = "Pedido #$pedidoId actualizado a estado '$nuevoEstado'.";
        }
    } elseif ($action === 'crear_comanda') {
        $mesa = trim($_POST['mesa'] ?? 'Mesa 1');
        $platoId = (int)($_POST['plato_id'] ?? 0);
        $cantidad = (int)($_POST['cantidad'] ?? 1);
        $notas = trim($_POST['notas'] ?? '');

        if ($platoId > 0 && $cantidad > 0) {
            $platoStmt = $db->prepare("SELECT * FROM platos WHERE id = :id AND disponible = true");
            $platoStmt->execute(['id' => $platoId]);
            $plato = $platoStmt->fetch();

            if ($plato) {
                $precio = (float)$plato['precio'];
                $subtotal = $precio * $cantidad;

                $db->beginTransaction();
                $pedStmt = $db->prepare("
                    INSERT INTO pedidos (usuario_id, mesero_id, tipo, mesa, total, estado, direccion_entrega, notas)
                    VALUES (:uid, :mid, 'sala', :mesa, :total, 'pendiente', 'Atención en sala', :notas)
                    RETURNING id
                ");
                $pedStmt->execute([
                    'uid' => $currentUser->id,
                    'mid' => $currentUser->id,
                    'mesa' => Security::escape($mesa),
                    'total' => $subtotal,
                    'notas' => Security::escape($notas)
                ]);
                $pedId = $pedStmt->fetchColumn();

                $db->prepare("
                    INSERT INTO pedido_items (pedido_id, plato_id, cantidad, precio_unitario, subtotal)
                    VALUES (:pid, :plid, :cant, :pu, :sub)
                ")->execute([
                    'pid' => $pedId,
                    'plid' => $platoId,
                    'cant' => $cantidad,
                    'pu' => $precio,
                    'sub' => $subtotal
                ]);
                $db->commit();
                $_SESSION['flash_success'] = "¡Comanda registrada para $mesa (Pedido #$pedId)!";
            }
        }
    }

    header('Location: mesero.php');
    exit;
}

// Obtener platos para la toma de comandas
$platosDisponibles = $db->query("SELECT * FROM platos WHERE disponible = true ORDER BY nombre ASC")->fetchAll();

// Obtener pedidos activos en sala
$pedidosActivos = $db->query("
    SELECT p.*, u.nombre as cliente_nombre, m.nombre as mesero_nombre,
           COUNT(pi.id) as num_items,
           STRING_AGG(CONCAT(p2.nombre, ' (x', pi.cantidad, ')'), ', ') as descripcion_platos
    FROM pedidos p
    LEFT JOIN usuarios u ON p.usuario_id = u.id
    LEFT JOIN usuarios m ON p.mesero_id = m.id
    LEFT JOIN pedido_items pi ON p.id = pi.pedido_id
    LEFT JOIN platos p2 ON pi.plato_id = p2.id
    GROUP BY p.id, u.nombre, m.nombre
    ORDER BY p.id DESC
")->fetchAll();
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-person-badge text-info me-2"></i>Gestión de Comandas y Mesas</h2>
            <p class="text-muted mb-0">Control en tiempo real de pedidos de sala, cocina y cambio de estados.</p>
        </div>
        <div>
            <span class="badge bg-dark fs-6 py-2 px-3">
                <i class="bi bi-circle-fill text-success me-1"></i> Mesero: <?= Security::escape($currentUser->nombre) ?>
            </span>
        </div>
    </div>

    <div class="row g-4">
        <!-- Toma de Comanda Rápida -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-circle me-1"></i> Nueva Comanda en Mesa</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="mesero.php">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="action" value="crear_comanda">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Seleccionar Mesa:</label>
                            <select name="mesa" class="form-select" required>
                                <option value="Mesa 1">Mesa 1 (Ventana)</option>
                                <option value="Mesa 2">Mesa 2 (Principal)</option>
                                <option value="Mesa 3">Mesa 3 (Terraza)</option>
                                <option value="Mesa 4">Mesa 4 (Interior)</option>
                                <option value="Mesa 5">Mesa 5 (VIP)</option>
                                <option value="Barra">Barra de Cocteles</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Plato o Bebida:</label>
                            <select name="plato_id" class="form-select" required>
                                <option value="">-- Seleccionar producto --</option>
                                <?php foreach ($platosDisponibles as $pl): ?>
                                    <option value="<?= $pl['id'] ?>">
                                        <?= Security::escape($pl['nombre']) ?> - $<?= number_format((float)$pl['precio'], 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Cantidad:</label>
                            <input type="number" name="cantidad" class="form-control" value="1" min="1" max="20" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Observaciones / Término de Cocción:</label>
                            <textarea name="notas" rows="2" class="form-control" placeholder="Ej. Sin cebolla, término medio, etc."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-bold">
                            <i class="bi bi-send-fill me-1"></i> Enviar Comanda a Cocina
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tablero de Comandas Activas -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-kanban me-2 text-warning"></i>Comandas en Curso</h5>
                    <span class="badge bg-primary"><?= count($pedidosActivos) ?> Pedidos</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th># Pedido</th>
                                    <th>Mesa</th>
                                    <th>Detalle</th>
                                    <th>Estado Actual</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-center">Cambiar Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pedidosActivos)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">No hay comandas registradas.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pedidosActivos as $ped): ?>
                                        <?php
                                        $badgeClass = match ($ped['estado']) {
                                            'pendiente' => 'bg-warning text-dark',
                                            'en_preparacion' => 'bg-info text-dark',
                                            'listo' => 'bg-primary',
                                            'entregado' => 'bg-success',
                                            default => 'bg-danger'
                                        };
                                        ?>
                                        <tr>
                                            <td class="fw-bold">#<?= $ped['id'] ?></td>
                                            <td><span class="badge bg-secondary"><?= Security::escape($ped['mesa']) ?></span></td>
                                            <td class="small" style="max-width: 250px;">
                                                <?= Security::escape($ped['descripcion_platos'] ?: 'Sin platos') ?>
                                                <?php if (!empty($ped['notas'])): ?>
                                                    <div class="text-muted fst-italic">Nota: <?= Security::escape($ped['notas']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?= $badgeClass ?> text-uppercase"><?= Security::escape($ped['estado']) ?></span></td>
                                            <td class="text-end fw-bold text-success">$<?= number_format((float)$ped['total'], 2) ?></td>
                                            <td class="text-center">
                                                <form method="POST" action="mesero.php" class="d-inline-flex gap-1">
                                                    <?= Security::csrfField() ?>
                                                    <input type="hidden" name="action" value="cambiar_estado">
                                                    <input type="hidden" name="pedido_id" value="<?= $ped['id'] ?>">
                                                    <select name="nuevo_estado" class="form-select form-select-sm" style="width: 135px;" onchange="this.form.submit()">
                                                        <option value="pendiente" <?= $ped['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                        <option value="en_preparacion" <?= $ped['estado'] === 'en_preparacion' ? 'selected' : '' ?>>En Preparación</option>
                                                        <option value="listo" <?= $ped['estado'] === 'listo' ? 'selected' : '' ?>>Listo</option>
                                                        <option value="entregado" <?= $ped['estado'] === 'entregado' ? 'selected' : '' ?>>Entregado</option>
                                                        <option value="cancelado" <?= $ped['estado'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                                    </select>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
