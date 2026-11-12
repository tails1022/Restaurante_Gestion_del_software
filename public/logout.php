<?php
/**
 * Cierre de Sesión y Eliminación de Token JWT
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['jwt_token']);
unset($_SESSION['auth_user']);
setcookie('jwt_token', '', time() - 3600, '/');

$_SESSION['flash_success'] = "Has cerrado sesión correctamente.";
header('Location: index.php');
exit;
