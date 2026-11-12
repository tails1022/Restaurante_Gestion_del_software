<?php

namespace App\Middleware;

use App\Utils\JWTHandler;
use App\Utils\Security;

class AuthMiddleware {
    /**
     * Obtiene el token Bearer del encabezado Authorization
     */
    public static function getBearerToken(): ?string {
        $headers = null;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } else {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }

        $authHeader = null;
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $authHeader = trim($value);
                    break;
                }
            }
        }

        if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Autentica la petición a través de Token JWT o Sesión PHP activa.
     */
    public static function authenticate(): object {
        // 1. Probar por Token JWT
        $token = self::getBearerToken();
        if ($token) {
            $decoded = JWTHandler::verifyToken($token);
            if ($decoded && isset($decoded->data)) {
                return (object)$decoded->data;
            }
        }

        // 2. Probar por Sesión PHP activa
        Security::startSecureSession();
        if (!empty($_SESSION['auth_user'])) {
            return (object)$_SESSION['auth_user'];
        }
        if (!empty($_SESSION['user'])) {
            return (object)$_SESSION['user'];
        }

        if (!headers_sent()) {
            http_response_code(401);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Acceso no autorizado: Debe iniciar sesión o proporcionar un token JWT válido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Requiere que el usuario tenga uno de los roles autorizados.
     */
    public static function requireRoles(array $allowedRoles): object {
        $user = self::authenticate();
        $userRole = $user->rol ?? '';

        if (!in_array($userRole, $allowedRoles, true)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo json_encode([
                'success' => false,
                'message' => 'Permiso denegado: Su rol (' . $userRole . ') no tiene autorización para esta operación.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $user;
    }

    /**
     * Requiere rol de administrador.
     */
    public static function requireAdmin(): object {
        return self::requireRoles(['admin']);
    }

    /**
     * Requiere rol de personal operativo (admin o mesero).
     */
    public static function requireStaff(): object {
        return self::requireRoles(['admin', 'mesero']);
    }
}
