<?php
$pageTitle = 'Seguimiento de Pedidos - SIGR';
$activeNav = 'pedidos';

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/includes/header.php';

use App\Config\Database;
use App\Utils\Security;

if (!$currentUser) {
    $_SESSION['flash_error'] = 'Debe iniciar sesión para consultar sus pedidos.';
    header('Location: login.php');
    exit;
}

$db = Database::getConnection();

// Si es admin o mesero ve todos, si es cliente solo los suyos
if (in_array($currentUser->rol ?? '', ['admin', 'mesero'], true)) {
    $stmt = $db->query("
        SELECT p.*, u.nombre as cliente_nombre, f.numero_factura, f.metodo_pago,
               STRING_AGG(CONCAT(p2.nombre, ' (x', pi.cantidad, ')'), ', ') as detalle_platos
        FROM pedidos p
        LEFT JOIN usuarios u ON p.usuario_id = u.id
        LEFT JOIN facturas f ON p.id = f.pedido_id
        LEFT JOIN pedido_items pi ON p.id = pi.pedido_id
        LEFT JOIN platos p2 ON pi.plato_id = p2.id
        GROUP BY p.id, u.nombre, f.numero_factura, f.metodo_pago
        ORDER BY p.creado_en DESC
    ");
} else {
    $stmt = $db->prepare("
        SELECT p.*, u.nombre as cliente_nombre, f.numero_factura, f.metodo_pago,
               STRING_AGG(CONCAT(p2.nombre, ' (x', pi.cantidad, ')'), ', ') as detalle_platos
        FROM pedidos p
        LEFT JOIN usuarios u ON p.usuario_id = u.id
        LEFT JOIN facturas f ON p.id = f.pedido_id
        LEFT JOIN pedido_items pi ON p.id = pi.pedido_id
        LEFT JOIN platos p2 ON pi.plato_id = p2.id
        WHERE p.usuario_id = :uid
        GROUP BY p.id, u.nombre, f.numero_factura, f.metodo_pago
        ORDER BY p.creado_en DESC
    ");
    $stmt->execute(['uid' => $currentUser->id]);
}

$pedidos = $stmt->fetchAll();
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-primary me-2"></i>Seguimiento de Pedidos</h2>
            <p class="text-muted mb-0">Trazabilidad del estado de preparación y facturación de pedidos.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-warning rounded-pill fw-bold shadow-sm">
                <i class="bi bi-plus-circle me-1"></i> Hacer Nuevo Pedido
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th># Pedido</th>
                            <th>Fecha</th>
                            <th>Mesa / Modalidad</th>
                            <th>Platos</th>
                            <th>Estado</th>
                            <th>Factura Fiscal</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pedidos)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No tienes pedidos registrados actualmente.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pedidos as $p): ?>
                                <?php
                                $badgeClass = match ($p['estado']) {
                                    'pendiente' => 'bg-warning text-dark',
                                    'en_preparacion' => 'bg-info text-dark',
                                    'listo' => 'bg-primary',
                                    'entregado' => 'bg-success',
                                    default => 'bg-danger'
                                };
                                ?>
                                <tr>
                                    <td class="fw-bold">#<?= (int)$p['id'] ?></td>
                                    <td><small><?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?></small></td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= Security::escape($p['mesa']) ?></span>
                                        <span class="badge bg-secondary"><?= Security::escape($p['tipo']) ?></span>
                                    </td>
                                    <td class="small" style="max-width: 300px;">
                                        <?= Security::escape($p['detalle_platos'] ?: 'Sin detalle') ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?> text-uppercase"><?= Security::escape($p['estado']) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['numero_factura'])): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i><?= Security::escape($p['numero_factura']) ?></span>
                                            <small class="d-block text-muted text-capitalize"><?= Security::escape($p['metodo_pago']) ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pendiente facturación</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-success fs-6">$<?= number_format((float)$p['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
