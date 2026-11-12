<?php

namespace App\Controllers;

use App\Config\Database;
use App\Utils\JWTHandler;
use App\Middleware\AuthMiddleware;
use PDO;

class AuthController {
    /**
     * Registro de nuevo usuario
     * POST /api/auth/register
     */
    public function register(): void {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $nombre = trim($data['nombre'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = trim($data['password'] ?? '');
        $telefono = trim($data['telefono'] ?? '');

        if (empty($nombre) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Los campos nombre, email y contraseña son obligatorios.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El formato del correo electrónico no es válido.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();

        // Verificar si el correo ya existe
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'El correo electrónico ya se encuentra registrado.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Crear usuario
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $insert = $db->prepare("
            INSERT INTO usuarios (nombre, email, password, rol, telefono)
            VALUES (:nombre, :email, :password, 'cliente', :telefono)
            RETURNING id, nombre, email, rol, telefono
        ");
        $insert->execute([
            'nombre' => $nombre,
            'email' => $email,
            'password' => $hash,
            'telefono' => $telefono
        ]);

        $user = $insert->fetch();

        // Generar JWT
        $token = JWTHandler::generateToken($user);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Usuario registrado exitosamente.',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'nombre' => $user['nombre'],
                'email' => $user['email'],
                'rol' => $user['rol'],
                'telefono' => $user['telefono']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Inicio de sesión
     * POST /api/auth/login
     */
    public function login(): void {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $email = strtolower(trim($data['email'] ?? ''));
        $password = trim($data['password'] ?? '');

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Debe ingresar su correo y contraseña.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Credenciales inválidas. Compruebe su correo o contraseña.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userData = [
            'id' => $user['id'],
            'nombre' => $user['nombre'],
            'email' => $user['email'],
            'rol' => $user['rol'],
            'telefono' => $user['telefono']
        ];

        $token = JWTHandler::generateToken($userData);

        echo json_encode([
            'success' => true,
            'message' => 'Autenticación exitosa.',
            'token' => $token,
            'user' => $userData
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Información del usuario actual autenticado
     * GET /api/auth/me
     */
    public function me(): void {
        $authUser = AuthMiddleware::authenticate();
        echo json_encode([
            'success' => true,
            'user' => $authUser
        ], JSON_UNESCAPED_UNICODE);
    }
}
