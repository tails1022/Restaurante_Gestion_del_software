<?php
$pageTitle = 'Módulo de Facturación - SIGR';
$activeNav = 'caja';

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/includes/header.php';

use App\Controllers\CashRegisterController;
use App\Config\Database;
use App\Utils\Security;

// Acceso solo a personal operativo
if (!$currentUser || !in_array($currentUser->rol ?? '', ['admin', 'mesero'], true)) {
    $_SESSION['flash_error'] = 'Acceso denegado: Se requiere rol de Administrador o Mesero/Cajero.';
    header('Location: login.php');
    exit;
}

$cajaCtrl = new CashRegisterController();
$db = Database::getConnection();

// Procesar Emisión de Factura POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = 'Error de seguridad CSRF.';
        header('Location: facturacion.php');
        exit;
    }

    $pedidoId = (int)($_POST['pedido_id'] ?? 0);
    $metodoPago = $_POST['metodo_pago'] ?? 'efectivo';
    $clienteNombre = trim($_POST['cliente_nombre'] ?? 'Cliente General');
    $clienteId = trim($_POST['cliente_identificacion'] ?? '222222222222');

    try {
        $factura = $cajaCtrl->issueInvoice($pedidoId, $metodoPago, $clienteNombre, $clienteId);
        $_SESSION['flash_success'] = '¡Factura ' . $factura['numero_factura'] . ' emitida exitosamente por $' . number_format((float)$factura['total'], 2) . '!';
    } catch (Exception $e) {
        $_SESSION['flash_error'] = 'Error al emitir factura: ' . $e->getMessage();
    }

    header('Location: facturacion.php');
    exit;
}

// Consultar pedidos disponibles para facturar (que no tengan factura emitida aún)
$pedidosPendientes = $db->query("
    SELECT p.*, u.nombre as cliente_nombre, u.telefono as cliente_telefono
    FROM pedidos p
    LEFT JOIN usuarios u ON p.usuario_id = u.id
    LEFT JOIN facturas f ON p.id = f.pedido_id
    WHERE f.id IS NULL AND p.estado IN ('listo', 'entregado', 'pendiente', 'en_preparacion')
    ORDER BY p.creado_en DESC
")->fetchAll();

// Consultar facturas recientes
$facturasRecientes = $db->query("
    SELECT f.*, p.mesa, p.tipo, p.total as pedido_total
    FROM facturas f
    JOIN pedidos p ON f.pedido_id = p.id
    ORDER BY f.id DESC
    LIMIT 20
")->fetchAll();
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-receipt text-primary me-2"></i>Módulo de Facturación</h2>
            <p class="text-muted mb-0">Emisión de facturas electrónicas/comprobantes de pago y enlace al arqueo de caja.</p>
        </div>
        <div>
            <a href="cierre_caja.php" class="btn btn-outline-success rounded-pill shadow-sm">
                <i class="bi bi-cash-stack me-1"></i> Ir a Control de Caja
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Columna de Pedidos por Facturar -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-hourglass-split text-warning me-2"></i>Pedidos Listos para Facturar</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($pedidosPendientes)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-1"></i> No hay pedidos pendientes de facturación.
                        </div>
                    <?php else: ?>
                        <div class="accordion" id="accordionPedidos">
                            <?php foreach ($pedidosPendientes as $idx => $p): ?>
                                <div class="accordion-item mb-2 border rounded">
                                    <h2 class="accordion-header" id="heading<?= $p['id'] ?>">
                                        <button class="accordion-button <?= $idx === 0 ? '' : 'collapsed' ?> py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $p['id'] ?>">
                                            <div class="d-flex justify-content-between w-100 me-3 align-items-center">
                                                <div>
                                                    <span class="fw-bold">Pedido #<?= $p['id'] ?></span>
                                                    <span class="badge bg-secondary ms-1"><?= Security::escape($p['mesa']) ?></span>
                                                </div>
                                                <span class="fw-bold text-success">$<?= number_format((float)$p['total'], 2) ?></span>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse<?= $p['id'] ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>" data-bs-parent="#accordionPedidos">
                                        <div class="accordion-body bg-light">
                                            <form method="POST" action="facturacion.php">
                                                <?= Security::csrfField() ?>
                                                <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">

                                                <div class="mb-2">
                                                    <label class="form-label small fw-bold mb-1">Nombre del Cliente:</label>
                                                    <input type="text" name="cliente_nombre" class="form-control form-control-sm" value="<?= Security::escape($p['cliente_nombre'] ?? 'Cliente General') ?>" required>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label small fw-bold mb-1">Cédula / NIT / Documento:</label>
                                                    <input type="text" name="cliente_identificacion" class="form-control form-control-sm" value="222222222222" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold mb-1">Método de Pago:</label>
                                                    <select name="metodo_pago" class="form-select form-select-sm" required>
                                                        <option value="efectivo">Efectivo (Caja)</option>
                                                        <option value="tarjeta">Tarjeta Débito / Crédito</option>
                                                        <option value="transferencia">Transferencia / Nequi / Daviplata</option>
                                                    </select>
                                                </div>

                                                <div class="p-2 border rounded bg-white mb-3 small">
                                                    <div class="d-flex justify-content-between">
                                                        <span>Subtotal (Base):</span>
                                                        <span>$<?= number_format(round((float)$p['total'] / 1.19, 2), 2) ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between text-danger">
                                                        <span>IVA Estimado (19%):</span>
                                                        <span>$<?= number_format(round((float)$p['total'] - round((float)$p['total'] / 1.19, 2), 2), 2) ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1 text-dark">
                                                        <span>Total a Pagar:</span>
                                                        <span class="text-success">$<?= number_format((float)$p['total'], 2) ?></span>
                                                    </div>
                                                </div>

                                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                                                    <i class="bi bi-printer me-1"></i> Generar y Emitir Factura
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Columna de Facturas Emitidas -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-clock-history text-success me-2"></i>Historial de Facturas Emitidas</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th># Factura</th>
                                    <th>Pedido</th>
                                    <th>Cliente</th>
                                    <th>Método</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($facturasRecientes)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">Aún no se han emitido facturas.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($facturasRecientes as $f): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= Security::escape($f['numero_factura']) ?></td>
                                            <td>#<?= (int)$f['pedido_id'] ?> (<?= Security::escape($f['mesa']) ?>)</td>
                                            <td><?= Security::escape($f['cliente_nombre']) ?></td>
                                            <td><span class="badge bg-secondary text-capitalize"><?= Security::escape($f['metodo_pago']) ?></span></td>
                                            <td class="text-end fw-bold text-success">$<?= number_format((float)$f['total'], 2) ?></td>
                                            <td class="text-center">
                                                <button class="btn btn-outline-dark btn-sm rounded-pill" onclick="alert('Factura Fiscal <?= Security::escape($f['numero_factura']) ?>\nSubtotal: $<?= number_format((float)$f['subtotal'], 2) ?>\nIVA (19%): $<?= number_format((float)$f['impuesto_iva'], 2) ?>\nTotal: $<?= number_format((float)$f['total'], 2) ?>\nMétodo: <?= Security::escape($f['metodo_pago']) ?>\nEmisión: <?= $f['fecha_emision'] ?>')">
                                                    <i class="bi bi-eye"></i> Ver
                                                </button>
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
