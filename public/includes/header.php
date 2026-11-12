<?php
/**
 * Header común del Sistema Integral de Gestión de Restaurante (SIGR)
 * Renderizado del lado del Servidor en PHP 8.4 con Bootstrap 5
 */
use App\Utils\Security;
use App\Utils\JWTHandler;
use App\Config\Database;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Security::startSecureSession();
Security::sendSecurityHeaders();

$currentUser = null;
$jwtToken = $_COOKIE['jwt_token'] ?? $_SESSION['jwt_token'] ?? null;

if ($jwtToken) {
    $decoded = JWTHandler::verifyToken($jwtToken);
    if ($decoded && isset($decoded->data)) {
        $currentUser = $decoded->data;
        $_SESSION['auth_user'] = $currentUser;
    } elseif (!empty($_SESSION['auth_user'])) {
        $currentUser = (object)$_SESSION['auth_user'];
    }
} elseif (!empty($_SESSION['auth_user'])) {
    $currentUser = (object)$_SESSION['auth_user'];
}

// Conteo de ítems en carrito
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += (int)($item['cantidad'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::escape($pageTitle ?? 'SIGR - Sistema Integral de Gestión de Restaurante') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="bg-light">

    <!-- Barra de Navegación Principal -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow">
        <div class="container-fluid px-lg-5">
            <a class="navbar-brand d-flex align-items-center fw-bold text-warning" href="index.php">
                <i class="bi bi-egg-fried me-2 fs-3"></i>
                <span>SIGR Restaurante</span>
                <span class="badge bg-secondary ms-2 small fw-normal">v1.0-baseline</span>
            </a>

            <div class="collapse navbar-collapse show" id="navbarContent">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= ($activeNav ?? '') === 'menu' ? 'active text-warning fw-bold' : '' ?>" href="index.php">
                            <i class="bi bi-journal-text me-1"></i> Menú Digital
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($activeNav ?? '') === 'reservas' ? 'active text-warning fw-bold' : '' ?>" href="reservas.php">
                            <i class="bi bi-calendar-check me-1"></i> Reservas
                        </a>
                    </li>

                    <?php if ($currentUser): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($activeNav ?? '') === 'pedidos' ? 'active text-warning fw-bold' : '' ?>" href="pedidos.php">
                            <i class="bi bi-bag-check me-1"></i> Pedidos
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($currentUser && in_array($currentUser->rol ?? '', ['mesero', 'admin'], true)): ?>
                    <li class="nav-item">
                        <a class="nav-link text-info <?= ($activeNav ?? '') === 'mesero' ? 'active fw-bold' : '' ?>" href="mesero.php">
                            <i class="bi bi-person-badge me-1"></i> Comandas / Mesas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-success <?= ($activeNav ?? '') === 'caja' ? 'active fw-bold' : '' ?>" href="cierre_caja.php">
                            <i class="bi bi-cash-coin me-1"></i> Caja y Facturación
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($currentUser && ($currentUser->rol ?? '') === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link text-warning <?= ($activeNav ?? '') === 'admin' ? 'active fw-bold' : '' ?>" href="admin.php">
                            <i class="bi bi-sliders me-1"></i> Panel Admin
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <!-- Botón Carrito de Compras -->
                    <a href="carrito.php" class="btn btn-warning fw-bold text-dark rounded-pill px-3 shadow-sm position-relative">
                        <i class="bi bi-cart3 me-1"></i> Carrito
                        <?php if ($cartCount > 0): ?>
                            <span class="badge rounded-pill bg-danger ms-1"><?= $cartCount ?></span>
                        <?php endif; ?>
                    </a>

                    <!-- Estado y Perfil de Usuario -->
                    <div class="d-flex align-items-center">
                        <?php if ($currentUser): ?>
                            <span class="badge bg-secondary me-2 py-2 px-3 text-start">
                                <i class="bi bi-person-fill text-warning me-1"></i> 
                                <?= Security::escape($currentUser->nombre) ?>
                                <span class="badge bg-light text-dark ms-1 text-uppercase"><?= Security::escape($currentUser->rol) ?></span>
                            </span>
                            <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill" title="Cerrar Sesión">
                                <i class="bi bi-box-arrow-right"></i> Salir
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Iniciar Sesión
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Notificaciones Flash en Sesión -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="container mt-3">
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= Security::escape($_SESSION['flash_success']) ?>
            </div>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="container mt-3">
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= Security::escape($_SESSION['flash_error']) ?>
            </div>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
