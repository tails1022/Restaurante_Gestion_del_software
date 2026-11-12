<?php
$pageTitle = 'Control de Caja y Cierre Diario - SIGR';
$activeNav = 'caja';

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/includes/header.php';

use App\Controllers\CashRegisterController;
use App\Utils\Security;
use App\Middleware\AuthMiddleware;

// Requiere rol de admin o mesero
if (!$currentUser || !in_array($currentUser->rol ?? '', ['admin', 'mesero'], true)) {
    $_SESSION['flash_error'] = 'Acceso denegado: Se requiere rol de Administrador o Personal de Caja.';
    header('Location: login.php');
    exit;
}

$cajaCtrl = new CashRegisterController();
$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');

// Procesamiento de Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = 'Error de validación de seguridad (Token CSRF inválido).';
        header('Location: cierre_caja.php');
        exit;
    }

    try {
        if ($action === 'abrir_turno') {
            $montoInicial = (float)($_POST['monto_inicial'] ?? 0);
            $observaciones = trim($_POST['observaciones'] ?? '');
            $cajaCtrl->openShift($montoInicial, $observaciones);
            $_SESSION['flash_success'] = '¡Turno de caja abierto exitosamente con $' . number_format($montoInicial, 2) . '!';
        } elseif ($action === 'cerrar_turno') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $montoReal = (float)($_POST['monto_cierre_real'] ?? 0);
            $observaciones = trim($_POST['observaciones'] ?? '');
            $cerrado = $cajaCtrl->closeShift($shiftId, $montoReal, $observaciones);
            $dif = (float)$cerrado['diferencia'];
            $estadoDif = ($dif === 0.0) ? 'Cuadre exacto' : (($dif > 0) ? "Sobrante de $$dif" : "Faltante de $$dif");
            $_SESSION['flash_success'] = "Turno #$shiftId cerrado. Resultado del arqueo: $estadoDif.";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = 'Error en operación de caja: ' . $e->getMessage();
    }

    header('Location: cierre_caja.php');
    exit;
}

$turnoActivo = $cajaCtrl->getActiveShift();
$reporte = $cajaCtrl->getDailySalesReport($fechaFiltro);
?>

<div class="container py-4">
    <!-- Encabezado del Módulo -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-cash-stack text-success me-2"></i>Control de Caja y Facturación</h2>
            <p class="text-muted mb-0">Gestión de turnos de caja, conciliación de pagos y reportes de ventas diarios.</p>
        </div>
        <div>
            <a href="facturacion.php" class="btn btn-primary rounded-pill shadow-sm">
                <i class="bi bi-receipt me-1"></i> Emitir Facturas
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill shadow-sm ms-2">
                <i class="bi bi-printer me-1"></i> Imprimir Reporte
            </button>
        </div>
    </div>

    <!-- Estado del Turno de Caja -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-clock-history text-primary me-2"></i>Estado del Turno de Caja
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($turnoActivo): ?>
                        <div class="alert alert-success d-flex align-items-center mb-3">
                            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i>
                            <div>
                                <span class="fw-bold">Turno Abierto #<?= $turnoActivo['id'] ?></span><br>
                                <small>Responsable: <?= Security::escape($turnoActivo['cajero_nombre'] ?? 'Administrador') ?> | Apertura: <?= date('d/m/Y H:i', strtotime($turnoActivo['fecha_apertura'])) ?></small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-3">
                                <tbody>
                                    <tr>
                                        <th class="bg-light">Fondo Inicial en Efectivo:</th>
                                        <td class="text-end fw-bold">$<?= number_format((float)$turnoActivo['monto_inicial'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Ventas en Efectivo:</th>
                                        <td class="text-end text-success fw-bold">+$<?= number_format((float)$turnoActivo['monto_ventas_efectivo'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Ventas con Tarjeta (Datáfono):</th>
                                        <td class="text-end text-primary fw-bold">+$<?= number_format((float)$turnoActivo['monto_ventas_tarjeta'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Ventas por Transferencia:</th>
                                        <td class="text-end text-info fw-bold">+$<?= number_format((float)$turnoActivo['monto_ventas_transferencia'], 2) ?></td>
                                    </tr>
                                    <tr class="table-warning">
                                        <th class="fw-bold">Total Esperado en Caja:</th>
                                        <td class="text-end fw-bold fs-5 text-dark">$<?= number_format((float)$turnoActivo['monto_total_esperado'], 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Formulario de Arqueo y Cierre -->
                        <h6 class="fw-bold text-danger mb-2"><i class="bi bi-box-arrow-down-right me-1"></i>Realizar Arqueo y Cierre de Turno</h6>
                        <form method="POST" action="cierre_caja.php" class="border rounded p-3 bg-light">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="action" value="cerrar_turno">
                            <input type="hidden" name="shift_id" value="<?= $turnoActivo['id'] ?>">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Total Físico Contado en Caja ($):</label>
                                <input type="number" step="0.01" name="monto_cierre_real" class="form-control" placeholder="Ingrese el total real contado" required>
                                <small class="text-muted">El sistema calculará automáticamente la diferencia con el total esperado.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Observaciones del Cierre:</label>
                                <textarea name="observaciones" rows="2" class="form-control" placeholder="Observaciones o justificaciones de descuadre..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100 fw-bold" onclick="return confirm('¿Confirmas el cierre definitivo de este turno de caja?')">
                                <i class="bi bi-lock-fill me-1"></i> Cerrar Turno y Guardar Arqueo
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-1"></i> Actualmente no hay ningún turno de caja abierto.
                        </div>
                        <h6 class="fw-bold mb-2">Apertura de Nuevo Turno</h6>
                        <form method="POST" action="cierre_caja.php" class="border rounded p-3 bg-light">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="action" value="abrir_turno">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Fondo de Caja Inicial ($):</label>
                                <input type="number" step="0.01" name="monto_inicial" class="form-control" value="200000.00" required>
                                <small class="text-muted">Monto en billetes y monedas para cambio inicial.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Observaciones:</label>
                                <input type="text" name="observaciones" class="form-control" placeholder="Ej. Turno de almuerzo">
                            </div>
                            <button type="submit" class="btn btn-success w-100 fw-bold">
                                <i class="bi bi-unlock-fill me-1"></i> Abrir Turno de Caja
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tarjetas Métricas del Reporte Diario -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-calendar3 text-primary me-2"></i>Reporte Diario: <?= Security::escape($fechaFiltro) ?>
                    </h5>
                    <form method="GET" class="d-flex gap-2">
                        <input type="date" name="fecha" value="<?= Security::escape($fechaFiltro) ?>" class="form-control form-control-sm">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block text-uppercase fw-bold">Facturas Emitidas</small>
                                <span class="fs-3 fw-bold text-dark"><?= $reporte['totales']['cantidad_facturas'] ?></span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block text-uppercase fw-bold">Total Facturado</small>
                                <span class="fs-3 fw-bold text-success">$<?= number_format((float)$reporte['totales']['total_ventas'], 2) ?></span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block text-uppercase fw-bold">Subtotal Sin IVA</small>
                                <span class="fs-5 fw-bold text-dark">$<?= number_format((float)$reporte['totales']['subtotal_acumulado'], 2) ?></span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block text-uppercase fw-bold">IVA Recaudado (19%)</small>
                                <span class="fs-5 fw-bold text-danger">$<?= number_format((float)$reporte['totales']['iva_acumulado'], 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Desglose por Método de Pago -->
                    <h6 class="fw-bold mb-2">Desglose por Método de Pago:</h6>
                    <div class="list-group mb-3">
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-cash me-2 text-success"></i>Efectivo</span>
                            <span class="badge bg-success rounded-pill fs-6">$<?= number_format((float)$reporte['totales']['total_efectivo'], 2) ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-credit-card me-2 text-primary"></i>Tarjeta</span>
                            <span class="badge bg-primary rounded-pill fs-6">$<?= number_format((float)$reporte['totales']['total_tarjeta'], 2) ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-phone me-2 text-info"></i>Transferencia</span>
                            <span class="badge bg-info text-dark rounded-pill fs-6">$<?= number_format((float)$reporte['totales']['total_transferencia'], 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de Ventas por Categoría -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-pie-chart-fill text-warning me-2"></i>Rendimiento por Categoría de Menú (Fecha: <?= Security::escape($fechaFiltro) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Categoría</th>
                            <th class="text-center">Unidades Vendidas</th>
                            <th class="text-end">Total Generado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reporte['categorias'])): ?>
                            <tr><td colspan="3" class="text-center py-3 text-muted">No hay ventas registradas para la fecha seleccionada.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reporte['categorias'] as $cat): ?>
                                <tr>
                                    <td class="fw-semibold"><?= Security::escape($cat['categoria_nombre']) ?></td>
                                    <td class="text-center"><?= (int)$cat['unidades_vendidas'] ?></td>
                                    <td class="text-end fw-bold text-success">$<?= number_format((float)$cat['total_categoria'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Listado de Facturas del Día -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Facturas Emitidas el <?= Security::escape($fechaFiltro) ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th># Factura</th>
                            <th>Pedido</th>
                            <th>Mesa / Tipo</th>
                            <th>Cliente</th>
                            <th>Método</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">IVA (19%)</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reporte['facturas'])): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">Sin facturas emitidas para esta jornada.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reporte['facturas'] as $fac): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= Security::escape($fac['numero_factura']) ?></td>
                                    <td>#<?= (int)$fac['pedido_id'] ?></td>
                                    <td><span class="badge bg-secondary"><?= Security::escape($fac['mesa']) ?> (<?= Security::escape($fac['tipo_pedido']) ?>)</span></td>
                                    <td><?= Security::escape($fac['cliente_nombre']) ?></td>
                                    <td><span class="badge bg-info text-dark text-capitalize"><?= Security::escape($fac['metodo_pago']) ?></span></td>
                                    <td class="text-end">$<?= number_format((float)$fac['subtotal'], 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)$fac['impuesto_iva'], 2) ?></td>
                                    <td class="text-end fw-bold text-success">$<?= number_format((float)$fac['total'], 2) ?></td>
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
