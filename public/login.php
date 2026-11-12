<?php
/**
 * Autenticación y Registro con Generación de JWT y Sesiones Seguras
 * Sistema Integral de Gestión de Restaurante (SIGR)
 */
use App\Config\Database;
use App\Utils\JWTHandler;
use App\Utils\Security;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Security::startSecureSession();
Security::sendSecurityHeaders();

$db = Database::getConnection();

$action = $_GET['action'] ?? '';
$redirect = $_GET['redirect'] ?? 'index.php';

// ==========================================
// 1. Procesamiento de Formularios POST
// ==========================================

// Inicio de Sesión
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = "Validación CSRF fallida. Inténtelo nuevamente.";
        header("Location: login.php?redirect=" . urlencode($redirect));
        exit;
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $_SESSION['flash_error'] = "Debe ingresar su correo y contraseña.";
        header("Location: login.php?redirect=" . urlencode($redirect));
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $_SESSION['flash_error'] = "Credenciales incorrectas. Verifique su correo o contraseña.";
        header("Location: login.php?redirect=" . urlencode($redirect));
        exit;
    }

    // Generar Token JWT
    $userData = [
        'id' => (int)$user['id'],
        'nombre' => $user['nombre'],
        'email' => $user['email'],
        'rol' => $user['rol'],
        'telefono' => $user['telefono']
    ];
    $tokenJwt = JWTHandler::generateToken($userData);

    // Guardar token en Cookie y Sesión PHP
    $_SESSION['jwt_token'] = $tokenJwt;
    $_SESSION['auth_user'] = (object)$userData;
    setcookie('jwt_token', $tokenJwt, [
        'expires' => time() + 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $_SESSION['flash_success'] = "¡Bienvenido/a, " . Security::escape($user['nombre']) . " (" . strtoupper($user['rol']) . ")!";

    if ($user['rol'] === 'admin') {
        header('Location: admin.php');
    } elseif ($user['rol'] === 'mesero') {
        header('Location: mesero.php');
    } else {
        header("Location: $redirect");
    }
    exit;
}

// Registro de Cliente
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($token)) {
        $_SESSION['flash_error'] = "Validación CSRF fallida.";
        header('Location: login.php');
        exit;
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $telefono = trim($_POST['telefono'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($nombre) || empty($email) || empty($password)) {
        $_SESSION['flash_error'] = "Todos los campos con (*) son obligatorios.";
        header('Location: login.php');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = "El formato de correo no es válido.";
        header('Location: login.php');
        exit;
    }

    // Verificar existencia
    $chk = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
    $chk->execute(['email' => $email]);
    if ($chk->fetch()) {
        $_SESSION['flash_error'] = "El correo '$email' ya se encuentra registrado.";
        header('Location: login.php');
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $ins = $db->prepare("
        INSERT INTO usuarios (nombre, email, password, rol, telefono)
        VALUES (:nombre, :email, :pass, 'cliente', :tel)
        RETURNING id, nombre, email, rol, telefono
    ");
    $ins->execute([
        'nombre' => Security::escape($nombre),
        'email' => $email,
        'pass' => $hash,
        'tel' => Security::escape($telefono)
    ]);
    $newUser = $ins->fetch();

    $userData = [
        'id' => (int)$newUser['id'],
        'nombre' => $newUser['nombre'],
        'email' => $newUser['email'],
        'rol' => $newUser['rol'],
        'telefono' => $newUser['telefono']
    ];
    $tokenJwt = JWTHandler::generateToken($userData);

    $_SESSION['jwt_token'] = $tokenJwt;
    $_SESSION['auth_user'] = (object)$userData;
    setcookie('jwt_token', $tokenJwt, [
        'expires' => time() + 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $_SESSION['flash_success'] = "¡Cuenta creada exitosamente! Sesión iniciada como cliente.";
    header("Location: $redirect");
    exit;
}

$pageTitle = 'Iniciar Sesión y Registro - SIGR';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <!-- Columna de Login -->
        <div class="col-md-5 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-box-arrow-in-right me-2 text-warning"></i>Iniciar Sesión</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="login.php?action=login&redirect=<?= urlencode($redirect) ?>">
                        <?= Security::csrfField() ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Correo Electrónico:</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="login_email" class="form-control" placeholder="admin@restaurante.com" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold small">Contraseña:</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" id="login_pass" class="form-control" placeholder="••••••••" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 fw-bold py-2 shadow-sm">
                            <i class="bi bi-unlock-fill me-1"></i> Acceder al Sistema
                        </button>
                    </form>

                    <!-- Credenciales Rápidas para Validación de la Línea Base -->
                    <div class="mt-4 pt-3 border-top">
                        <small class="text-muted fw-bold d-block mb-2 text-uppercase">Cuentas Pre-configuradas (Línea Base):</small>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger text-start" onclick="document.getElementById('login_email').value='admin@restaurante.com';document.getElementById('login_pass').value='admin123';">
                                <i class="bi bi-shield-lock-fill me-1"></i> <strong>Administrador:</strong> admin@restaurante.com (admin123)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info text-start" onclick="document.getElementById('login_email').value='mesero@restaurante.com';document.getElementById('login_pass').value='mesero123';">
                                <i class="bi bi-person-badge-fill me-1"></i> <strong>Mesero:</strong> mesero@restaurante.com (mesero123)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success text-start" onclick="document.getElementById('login_email').value='cliente@correo.com';document.getElementById('login_pass').value='cliente123';">
                                <i class="bi bi-person-fill me-1"></i> <strong>Cliente:</strong> cliente@correo.com (cliente123)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Columna de Registro -->
        <div class="col-md-5 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-secondary text-white py-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-person-plus-fill me-2"></i>Crear Cuenta de Cliente</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="login.php?action=register&redirect=<?= urlencode($redirect) ?>">
                        <?= Security::csrfField() ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Nombre Completo (*):</label>
                            <input type="text" name="nombre" class="form-control" placeholder="Ej. Juan Pérez" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Correo Electrónico (*):</label>
                            <input type="email" name="email" class="form-control" placeholder="juan@correo.com" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Teléfono de Contacto:</label>
                            <input type="text" name="telefono" class="form-control" placeholder="3001234567">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold small">Contraseña (*):</label>
                            <input type="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres" required>
                        </div>

                        <button type="submit" class="btn btn-dark w-100 fw-bold py-2 shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i> Registrarse como Cliente
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
