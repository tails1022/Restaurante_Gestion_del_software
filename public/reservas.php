<?php
/**
 * Módulo de Reservas de Mesas
 * 100% PHP del lado del servidor
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Utils\JWTHandler;

$db = Database::getConnection();

// 1. Validar autenticación mediante JWT
$jwtToken = $_COOKIE['jwt_token'] ?? $_SESSION['jwt_token'] ?? null;
$decoded = $jwtToken ? JWTHandler::verifyToken($jwtToken) : null;
$currentUser = ($decoded && isset($decoded->data)) ? $decoded->data : (!empty($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null);

if (!$currentUser) {
    $_SESSION['flash_error'] = "Debes iniciar sesión para gestionar tus reservas.";
    header('Location: login.php?redirect=reservas.php');
    exit;
}

$action = $_GET['action'] ?? '';

// ==========================================
// 2. Procesamiento de Acciones (POST / GET)
// ==========================================

// Crear Reserva
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!\App\Utils\Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = "Validación CSRF fallida. Inténtelo nuevamente.";
        header('Location: reservas.php');
        exit;
    }
    $nombreContacto = trim($_POST['nombre_contacto'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $fecha = trim($_POST['fecha'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $comensales = (int)($_POST['comensales'] ?? 1);
    $notas = trim($_POST['notas'] ?? '');

    if (empty($nombreContacto) || empty($telefono) || empty($fecha) || empty($hora) || $comensales < 1) {
        $_SESSION['flash_error'] = "Por favor completa todos los campos requeridos.";
        header('Location: reservas.php');
        exit;
    }

    // NOTA ACADEMICA OWASP ZAP (Cross-Site Scripting - XSS A03:2021):
    // Si VULNERABLE_MODE=true, no se sanitizan notas ni campos de texto, permitiendo que ZAP
    // detecte Stored XSS (por ejemplo, inyectando <script> o <img src=x onerror=...>).
    // Si VULNERABLE_MODE=false, se neutraliza con htmlspecialchars.
    if (!Database::isVulnerableMode()) {
        $nombreContacto = htmlspecialchars($nombreContacto, ENT_QUOTES, 'UTF-8');
        $telefono = htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8');
        $notas = htmlspecialchars($notas, ENT_QUOTES, 'UTF-8');
    }

    $stmt = $db->prepare("
        INSERT INTO reservas (usuario_id, nombre_contacto, telefono, fecha, hora, comensales, notas, estado)
        VALUES (:uid, :nombre, :tel, :fecha, :hora, :comensales, :notas, 'pendiente')
        RETURNING id
    ");

    $stmt->execute([
        'uid' => $currentUser->id,
        'nombre' => $nombreContacto,
        'tel' => $telefono,
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => $comensales,
        'notas' => $notas
    ]);

    $reservaId = $stmt->fetchColumn();
    $_SESSION['flash_success'] = "¡Reserva #$reservaId solicitada exitosamente! El estado actual es Pendiente.";
    header('Location: reservas.php');
    exit;
}

// Cancelar Reserva
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservaId = (int)($_POST['reserva_id'] ?? 0);

    // Si es cliente, solo cancela su propia reserva
    if (($currentUser->rol ?? '') === 'admin') {
        $upd = $db->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = :id");
        $upd->execute(['id' => $reservaId]);
    } else {
        $upd = $db->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = :id AND usuario_id = :uid");
        $upd->execute(['id' => $reservaId, 'uid' => $currentUser->id]);
    }

    $_SESSION['flash_success'] = "Reserva #$reservaId cancelada.";
    header('Location: reservas.php');
    exit;
}

// ==========================================
// 3. Consulta de Reservas del Usuario
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM reservas 
    WHERE usuario_id = :uid 
    ORDER BY fecha DESC, hora DESC
");
$stmt->execute(['uid' => $currentUser->id]);
$misReservas = $stmt->fetchAll();

$pageTitle = 'Reservas de Mesas - Sabor Gourmet';
$activeNav = 'reservas';
require_once __DIR__ . '/includes/header.php';
?>

<div class="bg-dark text-white py-4 mb-4 border-bottom border-warning">
    <div class="container">
        <h2 class="fw-bold mb-1"><i class="bi bi-calendar-check text-warning me-2"></i> Reservas de Mesas</h2>
        <p class="text-light small mb-0">Planifica tu visita en nuestro restaurante y asegura tu lugar.</p>
    </div>
</div>

<main class="container my-4">
    <div class="row g-4">
        <!-- Formulario Clásico de Solicitud de Reserva -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <h4 class="fw-bold mb-3 text-dark">
                    <i class="bi bi-pencil-square text-warning me-2"></i> Solicitar Reserva
                </h4>
                
                <form action="reservas.php?action=create" method="POST">
                    <?= \App\Utils\Security::csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nombre de Contacto *</label>
                        <input type="text" name="nombre_contacto" class="form-control" required 
                               value="<?= htmlspecialchars($currentUser->nombre, ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Teléfono *</label>
                        <input type="tel" name="telefono" class="form-control" required 
                               value="<?= htmlspecialchars($currentUser->telefono ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="300 123 4567">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Fecha *</label>
                            <input type="date" name="fecha" class="form-control" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Hora *</label>
                            <input type="time" name="hora" class="form-control" required min="12:00" max="23:00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Número de Comensales *</label>
                        <select name="comensales" class="form-select" required>
                            <option value="1">1 Persona</option>
                            <option value="2" selected>2 Personas (Pareja)</option>
                            <option value="3">3 Personas</option>
                            <option value="4">4 Personas (Familia)</option>
                            <option value="5">5 Personas</option>
                            <option value="6">6 Personas</option>
                            <option value="8">8 Personas (Grupo)</option>
                            <option value="10">10+ Personas</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Ocasión o Notas Especiales</label>
                        <textarea name="notas" class="form-control" rows="3" 
                                  placeholder="Ej: Aniversario, mesa en terraza..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-warning w-100 fw-bold py-2 rounded-pill shadow-sm">
                        <i class="bi bi-calendar-plus me-1"></i> Confirmar Solicitud
                    </button>
                </form>
            </div>
        </div>

        <!-- Listado de Mis Reservas -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <h4 class="fw-bold mb-3 text-dark">
                    <i class="bi bi-clock-history text-warning me-2"></i> Mis Reservas
                </h4>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Personas</th>
                                <th>Notas</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($misReservas)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        No tienes reservas registradas. Diligencia el formulario para agendar una.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($misReservas as $r): ?>
                                    <tr>
                                        <td class="fw-bold">#<?= $r['id'] ?></td>
                                        <td><?= htmlspecialchars($r['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= substr($r['hora'], 0, 5) ?></td>
                                        <td><span class="badge bg-secondary"><?= $r['comensales'] ?></span></td>
                                        <td>
                                            <!-- NOTA OWASP ZAP (XSS Stored): En modo vulnerable imprimimos directo sin escapar -->
                                            <?php if (Database::isVulnerableMode()): ?>
                                                <small class="text-muted"><?= $r['notas'] ?: '-' ?></small>
                                            <?php else: ?>
                                                <small class="text-muted"><?= htmlspecialchars($r['notas'] ?? '', ENT_QUOTES, 'UTF-8') ?: '-' ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= htmlspecialchars($r['estado'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= strtoupper(htmlspecialchars($r['estado'], ENT_QUOTES, 'UTF-8')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($r['estado'] === 'pendiente' || $r['estado'] === 'confirmada'): ?>
                                                <form action="reservas.php?action=cancel" method="POST" class="m-0" 
                                                      onsubmit="return confirm('¿Seguro que deseas cancelar esta reserva?');">
                                                    <input type="hidden" name="reserva_id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2">
                                                        Cancelar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">No editable</span>
                                            <?php endif; ?>
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
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
