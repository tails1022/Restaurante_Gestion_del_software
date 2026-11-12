<?php
/**
 * Panel Administrativo del Sistema Integral de Gestión de Restaurante (SIGR)
 * Incluye CRUD de Platos, CRUD de Categorías, Gestión de Reservas y Pedidos
 */
use App\Config\Database;
use App\Utils\JWTHandler;
use App\Utils\Security;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Security::startSecureSession();
Security::sendSecurityHeaders();

$db = Database::getConnection();

// Verificación estricta de Rol Administrador
$currentUser = !empty($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null;
if (!$currentUser && !empty($_COOKIE['jwt_token'])) {
    $decoded = JWTHandler::verifyToken($_COOKIE['jwt_token']);
    if ($decoded && isset($decoded->data)) {
        $currentUser = $decoded->data;
        $_SESSION['auth_user'] = $currentUser;
    }
}

if (!$currentUser || ($currentUser->rol ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Acceso restringido: Se requieren permisos de Administrador.";
    header('Location: login.php');
    exit;
}

$action = $_GET['action'] ?? '';

// ==========================================
// Procesamiento de Acciones POST con CSRF
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = "Validación CSRF fallida.";
        header('Location: admin.php');
        exit;
    }

    // 1. Estado de Reserva
    if ($action === 'reserva_estado') {
        $reservaId = (int)($_POST['reserva_id'] ?? 0);
        $nuevoEstado = $_POST['estado'] ?? '';
        if (in_array($nuevoEstado, ['pendiente', 'confirmada', 'cancelada'], true)) {
            $upd = $db->prepare("UPDATE reservas SET estado = :est WHERE id = :id");
            $upd->execute(['est' => $nuevoEstado, 'id' => $reservaId]);
            $_SESSION['flash_success'] = "Reserva #$reservaId actualizada a '$nuevoEstado'.";
        }
        header('Location: admin.php#reservas');
        exit;
    }

    // 2. Estado de Pedido
    if ($action === 'pedido_estado') {
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        $nuevoEstado = $_POST['estado'] ?? '';
        if (in_array($nuevoEstado, ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'], true)) {
            $upd = $db->prepare("UPDATE pedidos SET estado = :est WHERE id = :id");
            $upd->execute(['est' => $nuevoEstado, 'id' => $pedidoId]);
            $_SESSION['flash_success'] = "Pedido #$pedidoId actualizado a '$nuevoEstado'.";
        }
        header('Location: admin.php#pedidos');
        exit;
    }

    // 3. Crear Plato
    if ($action === 'crear_plato') {
        $nombre = trim($_POST['nombre'] ?? '');
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $precio = (float)($_POST['precio'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $imagenUrl = trim($_POST['imagen_url'] ?? '');

        if (!empty($nombre) && $precio > 0) {
            $ins = $db->prepare("
                INSERT INTO platos (categoria_id, nombre, descripcion, precio, imagen_url, disponible)
                VALUES (:cat, :nom, :desc, :prec, :img, true)
            ");
            $ins->execute([
                'cat' => $categoriaId ?: null,
                'nom' => Security::escape($nombre),
                'desc' => Security::escape($descripcion),
                'prec' => $precio,
                'img' => Security::escape($imagenUrl ?: 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=600')
            ]);
            $_SESSION['flash_success'] = "Plato '$nombre' agregado al menú exitosamente.";
        } else {
            $_SESSION['flash_error'] = "El nombre y el precio del plato son obligatorios.";
        }
        header('Location: admin.php#menu');
        exit;
    }

    // 4. Cambiar Disponibilidad de Plato
    if ($action === 'toggle_disponibilidad') {
        $platoId = (int)($_POST['plato_id'] ?? 0);
        $db->prepare("UPDATE platos SET disponible = NOT disponible WHERE id = :id")->execute(['id' => $platoId]);
        $_SESSION['flash_success'] = "Disponibilidad del plato #$platoId actualizada.";
        header('Location: admin.php#menu');
        exit;
    }

    // 5. Eliminar Plato
    if ($action === 'eliminar_plato') {
        $platoId = (int)($_POST['plato_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM platos WHERE id = :id")->execute(['id' => $platoId]);
            $_SESSION['flash_success'] = "Plato #$platoId eliminado del catálogo.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "No se puede eliminar el plato porque ya cuenta con pedidos históricos.";
        }
        header('Location: admin.php#menu');
        exit;
    }

    // 6. Crear Categoría
    if ($action === 'crear_categoria') {
        $nombreCat = trim($_POST['nombre_categoria'] ?? '');
        $descCat = trim($_POST['descripcion_categoria'] ?? '');
        if (!empty($nombreCat)) {
            $db->prepare("INSERT INTO categorias (nombre, descripcion) VALUES (:nom, :desc)")->execute([
                'nom' => Security::escape($nombreCat),
                'desc' => Security::escape($descCat)
            ]);
            $_SESSION['flash_success'] = "Categoría '$nombreCat' creada.";
        }
        header('Location: admin.php#menu');
        exit;
    }
}

// Consultas
$todasReservas = $db->query("
    SELECT r.*, u.nombre as usuario_nombre, u.email as usuario_email 
    FROM reservas r 
    LEFT JOIN usuarios u ON r.usuario_id = u.id 
    ORDER BY r.fecha DESC, r.hora DESC
")->fetchAll();

$todosPedidos = $db->query("
    SELECT p.*, u.nombre as usuario_nombre, u.email as usuario_email,
           COUNT(pi.id) as total_items, f.numero_factura
    FROM pedidos p
    LEFT JOIN usuarios u ON p.usuario_id = u.id
    LEFT JOIN pedido_items pi ON p.id = pi.pedido_id
    LEFT JOIN facturas f ON p.id = f.pedido_id
    GROUP BY p.id, u.nombre, u.email, f.numero_factura
    ORDER BY p.creado_en DESC
")->fetchAll();

$platos = $db->query("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM platos p 
    LEFT JOIN categorias c ON p.categoria_id = c.id 
    ORDER BY p.id DESC
")->fetchAll();

$categorias = $db->query("SELECT * FROM categorias ORDER BY id ASC")->fetchAll();

$pageTitle = 'Panel de Administración - SIGR';
$activeNav = 'admin';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Header Admin -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-sliders text-warning me-2"></i>Panel de Administración SIGR</h2>
            <p class="text-muted mb-0">Gestión de menú digital, categorías, pedidos, reservas y auditoría.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="cierre_caja.php" class="btn btn-outline-success rounded-pill">
                <i class="bi bi-cash-stack me-1"></i> Control de Caja
            </a>
            <a href="mesero.php" class="btn btn-outline-info rounded-pill">
                <i class="bi bi-person-badge me-1"></i> Comandas Mesero
            </a>
        </div>
    </div>

    <!-- Navegación de Pestañas -->
    <ul class="nav nav-pills mb-4 gap-2" id="adminTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active fw-bold" data-bs-toggle="pill" href="#tab-menu">
                <i class="bi bi-book-half me-1"></i> Menú y Categorías
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-bold" data-bs-toggle="pill" href="#tab-pedidos">
                <i class="bi bi-bag-check me-1"></i> Pedidos (<?= count($todosPedidos) ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-bold" data-bs-toggle="pill" href="#tab-reservas">
                <i class="bi bi-calendar2-check me-1"></i> Reservas (<?= count($todasReservas) ?>)
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- PESTAÑA 1: CRUD MENÚ Y CATEGORÍAS -->
        <div class="tab-pane fade show active" id="tab-menu">
            <div class="row g-4 mb-4">
                <!-- Formulario Agregar Plato -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-circle me-1"></i> Agregar Nuevo Plato</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="admin.php?action=crear_plato">
                                <?= Security::csrfField() ?>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Nombre del Plato:</label>
                                    <input type="text" name="nombre" class="form-control form-control-sm" required placeholder="Ej. Lomo al trapo">
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small fw-bold">Categoría:</label>
                                        <select name="categoria_id" class="form-select form-select-sm" required>
                                            <option value="">Seleccione...</option>
                                            <?php foreach ($categorias as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"><?= Security::escape($cat['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-bold">Precio ($):</label>
                                        <input type="number" step="100" name="precio" class="form-control form-control-sm" required placeholder="35000">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Descripción:</label>
                                    <textarea name="descripcion" rows="2" class="form-control form-control-sm" placeholder="Ingredientes y notas del plato..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">URL de Imagen (Opcional):</label>
                                    <input type="url" name="imagen_url" class="form-control form-control-sm" placeholder="https://images.unsplash.com/...">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                                    <i class="bi bi-save me-1"></i> Guardar Plato en Catálogo
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Formulario Nueva Categoría -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="fw-bold mb-0 text-success"><i class="bi bi-tag me-1"></i> Nueva Categoría</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="admin.php?action=crear_categoria">
                                <?= Security::csrfField() ?>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Nombre de la Categoría:</label>
                                    <input type="text" name="nombre_categoria" class="form-control form-control-sm" required placeholder="Ej. Coctelería de Autor">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Descripción:</label>
                                    <input type="text" name="descripcion_categoria" class="form-control form-control-sm" placeholder="Breve reseña de la categoría">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold">
                                    <i class="bi bi-plus-lg me-1"></i> Crear Categoría
                                </button>
                            </form>

                            <hr>
                            <h6 class="fw-bold small mb-2">Categorías Existentes:</h6>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($categorias as $c): ?>
                                    <span class="badge bg-secondary p-2"><?= Security::escape($c['nombre']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de Platos Registrados (CRUD) -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-list-check me-2 text-warning"></i>Catálogo de Platos del Menú (<?= count($platos) ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Plato</th>
                                    <th>Categoría</th>
                                    <th class="text-end">Precio</th>
                                    <th class="text-center">Disponibilidad</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($platos as $p): ?>
                                    <tr>
                                        <td>#<?= $p['id'] ?></td>
                                        <td>
                                            <div class="fw-bold"><?= Security::escape($p['nombre']) ?></div>
                                            <small class="text-muted"><?= Security::escape($p['descripcion'] ?: '') ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= Security::escape($p['categoria_nombre'] ?? 'Sin categoría') ?></span></td>
                                        <td class="text-end fw-bold text-success">$<?= number_format((float)$p['precio'], 2) ?></td>
                                        <td class="text-center">
                                            <form method="POST" action="admin.php?action=toggle_disponibilidad" class="d-inline">
                                                <?= Security::csrfField() ?>
                                                <input type="hidden" name="plato_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $p['disponible'] ? 'btn-outline-success' : 'btn-outline-secondary' ?> rounded-pill py-0 px-2">
                                                    <?= $p['disponible'] ? '<i class="bi bi-check-circle"></i> Activo' : '<i class="bi bi-slash-circle"></i> Inactivo' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" action="admin.php?action=eliminar_plato" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar este plato?');">
                                                <?= Security::csrfField() ?>
                                                <input type="hidden" name="plato_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Eliminar">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- PESTAÑA 2: GESTIÓN DE PEDIDOS -->
        <div class="tab-pane fade" id="tab-pedidos">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-bag-check text-warning me-2"></i>Todos los Pedidos</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Cliente / Mesa</th>
                                    <th>Ítems</th>
                                    <th class="text-end">Total</th>
                                    <th>Factura</th>
                                    <th>Estado</th>
                                    <th class="text-center">Cambiar Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todosPedidos as $ped): ?>
                                    <tr>
                                        <td class="fw-bold">#<?= $ped['id'] ?></td>
                                        <td><small><?= date('d/m H:i', strtotime($ped['creado_en'])) ?></small></td>
                                        <td>
                                            <div class="fw-semibold"><?= Security::escape($ped['usuario_nombre'] ?? 'Cliente General') ?></div>
                                            <small class="badge bg-secondary"><?= Security::escape($ped['mesa']) ?></small>
                                        </td>
                                        <td><?= (int)$ped['total_items'] ?> ítems</td>
                                        <td class="text-end fw-bold text-success">$<?= number_format((float)$ped['total'], 2) ?></td>
                                        <td>
                                            <?= !empty($ped['numero_factura']) ? '<span class="badge bg-success">' . Security::escape($ped['numero_factura']) . '</span>' : '<span class="badge bg-light text-dark border">Sin factura</span>' ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary text-uppercase"><?= Security::escape($ped['estado']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" action="admin.php?action=pedido_estado" class="d-inline-flex gap-1">
                                                <?= Security::csrfField() ?>
                                                <input type="hidden" name="pedido_id" value="<?= $ped['id'] ?>">
                                                <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
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
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- PESTAÑA 3: GESTIÓN DE RESERVAS -->
        <div class="tab-pane fade" id="tab-reservas">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-calendar2-check text-warning me-2"></i>Todas las Reservas de Mesas</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Contacto</th>
                                    <th>Fecha</th>
                                    <th>Hora</th>
                                    <th>Comensales</th>
                                    <th>Notas</th>
                                    <th>Estado</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todasReservas as $r): ?>
                                    <tr>
                                        <td class="fw-bold">#<?= $r['id'] ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= Security::escape($r['nombre_contacto']) ?></div>
                                            <small class="text-muted"><?= Security::escape($r['telefono']) ?></small>
                                        </td>
                                        <td><?= Security::escape($r['fecha']) ?></td>
                                        <td><?= substr($r['hora'], 0, 5) ?></td>
                                        <td><span class="badge bg-secondary"><?= $r['comensales'] ?> pers.</span></td>
                                        <td class="small"><?= Security::escape($r['notas'] ?? '-') ?></td>
                                        <td><span class="badge bg-info text-dark text-uppercase"><?= Security::escape($r['estado']) ?></span></td>
                                        <td class="text-center">
                                            <form method="POST" action="admin.php?action=reserva_estado" class="d-inline-flex gap-1">
                                                <?= Security::csrfField() ?>
                                                <input type="hidden" name="reserva_id" value="<?= $r['id'] ?>">
                                                <?php if ($r['estado'] !== 'confirmada'): ?>
                                                    <button type="submit" name="estado" value="confirmada" class="btn btn-outline-success btn-sm py-0 px-2" title="Confirmar">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($r['estado'] !== 'cancelada'): ?>
                                                    <button type="submit" name="estado" value="cancelada" class="btn btn-outline-danger btn-sm py-0 px-2" title="Cancelar">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Script Bootstrap Tabs Trigger -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
