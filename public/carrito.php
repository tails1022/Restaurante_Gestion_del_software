<?php
/**
 * Gestión del Carrito de Compras y Checkout
 * 100% PHP del lado del servidor utilizando $_SESSION['cart']
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Utils\JWTHandler;

$db = Database::getConnection();

// Inicializar estructura de carrito si no existe
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$action = $_GET['action'] ?? '';

// ==========================================
// 1. Acciones del Carrito (Procesamiento en PHP)
// ==========================================

// Agregar plato al carrito
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $platoId = (int)($_POST['plato_id'] ?? 0);

    if ($platoId > 0) {
        $stmt = $db->prepare("SELECT id, nombre, precio, imagen_url FROM platos WHERE id = :id AND disponible = true");
        $stmt->execute(['id' => $platoId]);
        $plato = $stmt->fetch();

        if ($plato) {
            if (isset($_SESSION['cart'][$platoId])) {
                $_SESSION['cart'][$platoId]['cantidad'] += 1;
            } else {
                $_SESSION['cart'][$platoId] = [
                    'id' => (int)$plato['id'],
                    'nombre' => $plato['nombre'],
                    'precio' => (float)$plato['precio'],
                    'imagen_url' => $plato['imagen_url'],
                    'cantidad' => 1
                ];
            }
            $_SESSION['flash_success'] = "¡'{$plato['nombre']}' añadido a tu carrito!";
        }
    }

    // Redirigir de regreso
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'carrito.php';
    header("Location: $redirect");
    exit;
}

// Cambiar cantidad (+1 / -1)
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $platoId = (int)($_POST['plato_id'] ?? 0);
    $delta = (int)($_POST['delta'] ?? 0);

    if (isset($_SESSION['cart'][$platoId])) {
        $_SESSION['cart'][$platoId]['cantidad'] += $delta;
        if ($_SESSION['cart'][$platoId]['cantidad'] <= 0) {
            unset($_SESSION['cart'][$platoId]);
        }
    }
    header('Location: carrito.php');
    exit;
}

// Eliminar plato del carrito
if ($action === 'remove') {
    $platoId = (int)($_REQUEST['plato_id'] ?? 0);
    if (isset($_SESSION['cart'][$platoId])) {
        unset($_SESSION['cart'][$platoId]);
        $_SESSION['flash_success'] = "Plato eliminado del carrito.";
    }
    header('Location: carrito.php');
    exit;
}

// Vaciar carrito completo
if ($action === 'clear') {
    $_SESSION['cart'] = [];
    $_SESSION['flash_success'] = "Carrito vaciado.";
    header('Location: carrito.php');
    exit;
}

// Checkout: Confirmar Pedido
if ($action === 'checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!\App\Utils\Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = "Error de validación CSRF. Por favor intente nuevamente.";
        header('Location: carrito.php');
        exit;
    }
    // Validar token JWT en cookie/sesión
    $jwtToken = $_COOKIE['jwt_token'] ?? $_SESSION['jwt_token'] ?? null;
    $decoded = $jwtToken ? JWTHandler::verifyToken($jwtToken) : null;
    $authUser = ($decoded && isset($decoded->data)) ? $decoded->data : (!empty($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null);

    if (!$authUser) {
        $_SESSION['flash_error'] = "Debes iniciar sesión para confirmar tu pedido.";
        header('Location: login.php?redirect=carrito.php');
        exit;
    }

    if (empty($_SESSION['cart'])) {
        $_SESSION['flash_error'] = "Tu carrito de compras está vacío.";
        header('Location: carrito.php');
        exit;
    }

    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $notas = trim($_POST['notas'] ?? '');

    if (empty($direccion)) {
        $_SESSION['flash_error'] = "Debe indicar una dirección de entrega.";
        header('Location: carrito.php');
        exit;
    }

    // Sanitización en modo seguro
    if (!Database::isVulnerableMode()) {
        $direccion = htmlspecialchars($direccion, ENT_QUOTES, 'UTF-8');
        $telefono = htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8');
        $notas = htmlspecialchars($notas, ENT_QUOTES, 'UTF-8');
    }

    try {
        $db->beginTransaction();

        // 1. Recalcular total verificando precios en la base de datos
        $total = 0.0;
        $itemsValidados = [];
        $platoStmt = $db->prepare("SELECT id, nombre, precio FROM platos WHERE id = :id AND disponible = true");

        foreach ($_SESSION['cart'] as $item) {
            $platoStmt->execute(['id' => $item['id']]);
            $p = $platoStmt->fetch();
            if (!$p) continue;

            $precio = (float)$p['precio'];
            $subtotal = $precio * (int)$item['cantidad'];
            $total += $subtotal;

            $itemsValidados[] = [
                'plato_id' => (int)$p['id'],
                'cantidad' => (int)$item['cantidad'],
                'precio_unitario' => $precio,
                'subtotal' => $subtotal
            ];
        }

        // 2. Insertar pedido en PostgreSQL
        $insPedido = $db->prepare("
            INSERT INTO pedidos (usuario_id, total, estado, direccion_entrega, telefono_contacto, notas)
            VALUES (:uid, :total, 'pendiente', :dir, :tel, :notas)
            RETURNING id
        ");
        $insPedido->execute([
            'uid' => $authUser->id,
            'total' => $total,
            'dir' => $direccion,
            'tel' => $telefono,
            'notas' => $notas
        ]);
        $nuevoPedidoId = $insPedido->fetchColumn();

        // 3. Insertar items del pedido
        $insItem = $db->prepare("
            INSERT INTO pedido_items (pedido_id, plato_id, cantidad, precio_unitario, subtotal)
            VALUES (:pid, :plato_id, :cant, :precio, :subtotal)
        ");

        foreach ($itemsValidados as $iv) {
            $insItem->execute([
                'pid' => $nuevoPedidoId,
                'plato_id' => $iv['plato_id'],
                'cant' => $iv['cantidad'],
                'precio' => $iv['precio_unitario'],
                'subtotal' => $iv['subtotal']
            ]);
        }

        $db->commit();

        // Vaciar carrito
        $_SESSION['cart'] = [];
        $_SESSION['flash_success'] = "¡Pedido #$nuevoPedidoId realizado exitosamente! Nuestro equipo comenzará a prepararlo.";
        header('Location: carrito.php?order_success=' . $nuevoPedidoId);
        exit;

    } catch (\Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_error'] = "Error al procesar el pedido: " . $e->getMessage();
        header('Location: carrito.php');
        exit;
    }
}

// ==========================================
// 2. Renderizado de la Vista del Carrito
// ==========================================
$pageTitle = 'Carrito de Compras - Sabor Gourmet';
require_once __DIR__ . '/includes/header.php';

$totalCarrito = 0;
foreach ($_SESSION['cart'] as $item) {
    $totalCarrito += ($item['precio'] * $item['cantidad']);
}
?>

<main class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0">
            <i class="bi bi-cart3 text-warning me-2"></i> Carrito de Compras
        </h2>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill">
            <i class="bi bi-arrow-left me-1"></i> Seguir Comprando
        </a>
    </div>

    <?php if (!empty($_GET['order_success'])): ?>
        <div class="card border-success shadow-sm mb-4">
            <div class="card-body text-center py-4">
                <i class="bi bi-check-circle-fill text-success fs-1 d-block mb-2"></i>
                <h4 class="fw-bold">¡Tu Pedido #<?= (int)$_GET['order_success'] ?> fue confirmado!</h4>
                <p class="text-muted">Hemos recibido tu solicitud y se encuentra en estado <span class="badge bg-warning text-dark">PENDIENTE</span>.</p>
                <a href="index.php" class="btn btn-warning fw-bold rounded-pill px-4">Volver al Menú Principal</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($_SESSION['cart'])): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
            <i class="bi bi-cart-x fs-1 text-muted d-block mb-3"></i>
            <h4 class="fw-bold">Tu carrito está vacío</h4>
            <p class="text-muted">Explora nuestra carta gastronómica y añade tus platos favoritos.</p>
            <div>
                <a href="index.php" class="btn btn-warning rounded-pill px-4 fw-bold">Ver Menú</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <!-- Tabla de Items del Carrito -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">Platos Seleccionados</h5>
                        <a href="carrito.php?action=clear" class="btn btn-outline-danger btn-sm rounded-pill" 
                           onclick="return confirm('¿Seguro que deseas vaciar el carrito?');">
                            <i class="bi bi-trash me-1"></i> Vaciar Carrito
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Plato</th>
                                    <th>Precio</th>
                                    <th class="text-center">Cantidad</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_SESSION['cart'] as $item): ?>
                                    <?php $sub = $item['precio'] * $item['cantidad']; ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="<?= htmlspecialchars($item['imagen_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                     class="cart-item-img me-3" alt="<?= htmlspecialchars($item['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                                <div class="fw-semibold"><?= htmlspecialchars($item['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </td>
                                        <td>$<?= number_format($item['precio'], 0, ',', '.') ?></td>
                                        <td class="text-center">
                                            <!-- Formularios POST sin JavaScript para cambiar cantidades -->
                                            <div class="d-inline-flex align-items-center gap-1">
                                                <form action="carrito.php?action=update" method="POST" class="d-inline m-0">
                                                    <input type="hidden" name="plato_id" value="<?= $item['id'] ?>">
                                                    <input type="hidden" name="delta" value="-1">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2">-</button>
                                                </form>
                                                <span class="fw-bold px-2"><?= $item['cantidad'] ?></span>
                                                <form action="carrito.php?action=update" method="POST" class="d-inline m-0">
                                                    <input type="hidden" name="plato_id" value="<?= $item['id'] ?>">
                                                    <input type="hidden" name="delta" value="1">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2">+</button>
                                                </form>
                                            </div>
                                        </td>
                                        <td class="fw-bold text-dark">$<?= number_format($sub, 0, ',', '.') ?></td>
                                        <td>
                                            <a href="carrito.php?action=remove&plato_id=<?= $item['id'] ?>" class="text-danger" title="Eliminar">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Resumen y Formulario de Checkout Tradicional -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                    <h5 class="fw-bold mb-3">Finalizar Pedido</h5>
                    <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                        <span class="text-muted">Total a Pagar:</span>
                        <span class="fs-4 fw-bold text-warning">$<?= number_format($totalCarrito, 0, ',', '.') ?></span>
                    </div>

                    <?php if (!$currentUser): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-info-circle me-1"></i>
                            Debes <a href="login.php?redirect=carrito.php" class="fw-bold">iniciar sesión</a> para completar tu pedido.
                        </div>
                    <?php endif; ?>

                    <form action="carrito.php?action=checkout" method="POST">
                        <?= \App\Utils\Security::csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Dirección de Entrega *</label>
                            <input type="text" name="direccion" class="form-control form-control-sm" 
                                   placeholder="Ej: Carrera 15 # 85-30 Apto 402" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Teléfono de Contacto</label>
                            <input type="tel" name="telefono" class="form-control form-control-sm" 
                                   placeholder="300 123 4567" 
                                   value="<?= htmlspecialchars($currentUser->telefono ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Notas / Instrucciones</label>
                            <textarea name="notas" class="form-control form-control-sm" rows="2" 
                                      placeholder="Instrucciones para el repartidor..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 fw-bold py-2 rounded-pill shadow-sm"
                                <?= !$currentUser ? 'disabled' : '' ?>>
                            <i class="bi bi-credit-card me-1"></i> Confirmar y Pedir
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
