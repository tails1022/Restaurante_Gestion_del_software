<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config\Database;
use App\Controllers\AuthController;
use App\Controllers\MenuController;
use App\Controllers\ReservationController;
use App\Controllers\OrderController;

// Configuración de Cabeceras
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// NOTA ACADEMICA OWASP ZAP (Security Headers - A05 Security Misconfiguration):
// En modo seguro/mitigado, agregamos las cabeceras recomendadas por OWASP.
// En modo vulnerable, las omitimos para que ZAP reporte las alertas de configuración faltante.
if (!Database::isVulnerableMode()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net https://images.unsplash.com; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline';");
}

// Manejo de peticiones preflight CORS OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parsear URL de la petición
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// Extraer la ruta base relativa a /api
$basePath = str_replace('\\', '/', dirname($scriptName));
$path = parse_url($requestUri, PHP_URL_PATH);

if (str_starts_with($path, $basePath)) {
    $route = substr($path, strlen($basePath));
} else {
    // Si se reescribió mediante .htaccess
    $route = $path;
}

// Limpiar slashes y prefijos
$route = '/' . trim($route, '/');
if (str_starts_with($route, '/api/')) {
    $route = substr($route, 4);
} elseif ($route === '/api') {
    $route = '/';
}

$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// Tabla de Rutas REST
// ==========================================
try {
    // --- Estado / Configuración ---
    if ($method === 'GET' && ($route === '/' || $route === '')) {
        echo json_encode([
            'status' => 'online',
            'app' => 'API Gestión de Restaurante',
            'version' => '1.0.0',
            'vulnerable_mode' => Database::isVulnerableMode()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Autenticación ---
    if ($method === 'POST' && $route === '/auth/register') {
        (new AuthController())->register();
        exit;
    }

    if ($method === 'POST' && $route === '/auth/login') {
        (new AuthController())->login();
        exit;
    }

    if ($method === 'GET' && $route === '/auth/me') {
        (new AuthController())->me();
        exit;
    }

    // --- Menú & Búsqueda ---
    if ($method === 'GET' && $route === '/menu') {
        (new MenuController())->getMenu();
        exit;
    }

    if ($method === 'GET' && $route === '/menu/search') {
        (new MenuController())->search();
        exit;
    }

    // --- Reservas ---
    if ($method === 'GET' && $route === '/reservas') {
        (new ReservationController())->index();
        exit;
    }

    if ($method === 'POST' && $route === '/reservas') {
        (new ReservationController())->store();
        exit;
    }

    if (preg_match('#^/reservas/(\d+)$#', $route, $matches)) {
        $id = (int)$matches[1];
        if ($method === 'GET') {
            (new ReservationController())->show($id);
            exit;
        }
        if ($method === 'DELETE') {
            (new ReservationController())->delete($id);
            exit;
        }
    }

    if (preg_match('#^/reservas/(\d+)/estado$#', $route, $matches) && $method === 'PUT') {
        (new ReservationController())->updateStatus((int)$matches[1]);
        exit;
    }

    // --- Pedidos ---
    if ($method === 'GET' && $route === '/pedidos') {
        (new OrderController())->index();
        exit;
    }

    if ($method === 'POST' && $route === '/pedidos') {
        (new OrderController())->store();
        exit;
    }

    if (preg_match('#^/pedidos/(\d+)$#', $route, $matches) && $method === 'GET') {
        (new OrderController())->show((int)$matches[1]);
        exit;
    }

    if (preg_match('#^/pedidos/(\d+)/estado$#', $route, $matches) && $method === 'PUT') {
        (new OrderController())->updateStatus((int)$matches[1]);
        exit;
    }

    // Ruta no encontrada
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => "Endpoint no encontrado: $method $route"
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno en el servidor: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
