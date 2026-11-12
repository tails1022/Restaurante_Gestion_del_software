<?php

namespace App\Utils;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JWTHandler {
    private static function getSecret(): string {
        \App\Config\Database::loadEnv();
        $secret = getenv('JWT_SECRET');
        if ($secret !== false && $secret !== '') {
            return $secret;
        }
        return $_ENV['JWT_SECRET'] ?? 'clave_secreta_jwt_para_restaurante_owasp_2025!';
    }

    private static function getExpiration(): int {
        \App\Config\Database::loadEnv();
        $exp = getenv('JWT_EXPIRATION');
        return (int)($exp ?: ($_ENV['JWT_EXPIRATION'] ?? 86400));
    }

    /**
     * Genera un token JWT firmado con HS256
     */
    public static function generateToken(array $userData): string {
        $issuedAt = time();
        $expirationTime = $issuedAt + self::getExpiration();

        $payload = [
            'iss' => 'sigr-restaurante',
            'aud' => 'sigr-frontend',
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'data' => [
                'id' => $userData['id'] ?? 0,
                'nombre' => $userData['nombre'] ?? 'Usuario',
                'email' => $userData['email'] ?? '',
                'rol' => $userData['rol'] ?? 'cliente'
            ]
        ];

        return JWT::encode($payload, self::getSecret(), 'HS256');
    }

    /**
     * Valida y decodifica un token JWT
     */
    public static function verifyToken(string $jwt): ?object {
        try {
            return JWT::decode($jwt, new Key(self::getSecret(), 'HS256'));
        } catch (Exception $e) {
            return null;
        }
    }
}
