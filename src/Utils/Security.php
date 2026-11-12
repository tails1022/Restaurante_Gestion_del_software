<?php

namespace App\Utils;

class Security {
    /**
     * Inicia una sesión con directivas de cookies seguras (OWASP)
     */
    public static function startSecureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                $cookieParams = [
                    'lifetime' => 0,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ];
                session_set_cookie_params($cookieParams);
            }
            @session_start();
        }
    }

    /**
     * Envía encabezados HTTP de endurecimiento de seguridad
     */
    public static function sendSecurityHeaders(): void {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https://images.unsplash.com https://fonts.googleapis.com https://fonts.gstatic.com; img-src 'self' data: https:;");
        }
    }

    /**
     * Genera o retorna el token CSRF de la sesión actual
     */
    public static function getCsrfToken(): string {
        self::startSecureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Retorna el input HTML oculto con el token CSRF
     */
    public static function csrfField(): string {
        $token = self::getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Valida el token CSRF enviado en peticiones POST
     */
    public static function validateCsrfToken(?string $token): bool {
        self::startSecureSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Escapa cadenas para prevenir Cross-Site Scripting (XSS)
     */
    public static function escape(?string $data): string {
        return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
    }
}
