<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use PDO;

class ReservationController {
    /**
     * Listar reservas
     * GET /api/reservas
     */
    public function index(): void {
        $authUser = AuthMiddleware::authenticate();
        $db = Database::getConnection();

        if (($authUser->rol ?? '') === 'admin') {
            // El administrador ve todas las reservas con datos del usuario
            $stmt = $db->query("
                SELECT r.*, u.nombre as usuario_nombre, u.email as usuario_email 
                FROM reservas r 
                LEFT JOIN usuarios u ON r.usuario_id = u.id 
                ORDER BY r.fecha DESC, r.hora DESC
            ");
            $reservas = $stmt->fetchAll();
        } else {
            // El cliente solo ve sus propias reservas
            $stmt = $db->prepare("
                SELECT r.* 
                FROM reservas r 
                WHERE r.usuario_id = :user_id 
                ORDER BY r.fecha DESC, r.hora DESC
            ");
            $stmt->execute(['user_id' => $authUser->id]);
            $reservas = $stmt->fetchAll();
        }

        echo json_encode([
            'success' => true,
            'reservas' => $reservas
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Crear una nueva reserva
     * POST /api/reservas
     */
    public function store(): void {
        $authUser = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $nombreContacto = trim($data['nombre_contacto'] ?? $authUser->nombre);
        $telefono = trim($data['telefono'] ?? '');
        $fecha = trim($data['fecha'] ?? '');
        $hora = trim($data['hora'] ?? '');
        $comensales = (int)($data['comensales'] ?? 1);
        $notas = trim($data['notas'] ?? '');

        if (empty($nombreContacto) || empty($telefono) || empty($fecha) || empty($hora) || $comensales < 1) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Por favor complete todos los campos obligatorios (nombre, teléfono, fecha, hora, comensales).'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // NOTA ACADEMICA OWASP ZAP (XSS):
        // En modo mitigado, sanitizamos notas y campos de texto para evitar Stored XSS.
        if (!Database::isVulnerableMode()) {
            $nombreContacto = htmlspecialchars($nombreContacto, ENT_QUOTES, 'UTF-8');
            $telefono = htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8');
            $notas = htmlspecialchars($notas, ENT_QUOTES, 'UTF-8');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO reservas (usuario_id, nombre_contacto, telefono, fecha, hora, comensales, notas, estado)
            VALUES (:usuario_id, :nombre_contacto, :telefono, :fecha, :hora, :comensales, :notas, 'pendiente')
            RETURNING *
        ");

        $stmt->execute([
            'usuario_id' => $authUser->id,
            'nombre_contacto' => $nombreContacto,
            'telefono' => $telefono,
            'fecha' => $fecha,
            'hora' => $hora,
            'comensales' => $comensales,
            'notas' => $notas
        ]);

        $nuevaReserva = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Reserva creada exitosamente.',
            'reserva' => $nuevaReserva
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Consultar detalle de una reserva
     * GET /api/reservas/{id}
     * 
     * NOTA ACADEMICA OWASP ZAP (IDOR / Broken Access Control - A01):
     * En VULNERABLE_MODE=true, no se verifica si la reserva pertenece al usuario autenticado,
     * permitiendo que cualquier usuario vea datos de otros clientes cambiando el ID.
     * En VULNERABLE_MODE=false, se valida rigurosamente la pertenencia o rol de admin.
     */
    public function show(int $id): void {
        $authUser = AuthMiddleware::authenticate();
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM reservas WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $reserva = $stmt->fetch();

        if (!$reserva) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!Database::isVulnerableMode()) {
            // Validación estricta de propiedad
            if (($authUser->rol ?? '') !== 'admin' && (int)$reserva['usuario_id'] !== (int)$authUser->id) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Acceso denegado: No tiene autorización para consultar esta reserva.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        echo json_encode([
            'success' => true,
            'vulnerable_mode' => Database::isVulnerableMode(),
            'reserva' => $reserva
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Cambiar estado de una reserva (confirmar o cancelar)
     * PUT /api/reservas/{id}/estado
     */
    public function updateStatus(int $id): void {
        $authUser = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $nuevoEstado = $data['estado'] ?? '';

        if (!in_array($nuevoEstado, ['pendiente', 'confirmada', 'cancelada'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Estado no válido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM reservas WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $reserva = $stmt->fetch();

        if (!$reserva) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Si es cliente, solo puede cancelar sus propias reservas
        if (($authUser->rol ?? '') !== 'admin') {
            if ((int)$reserva['usuario_id'] !== (int)$authUser->id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No tiene permiso para modificar esta reserva.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($nuevoEstado !== 'cancelada') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Los clientes solo pueden cancelar sus reservas.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $update = $db->prepare("UPDATE reservas SET estado = :estado WHERE id = :id RETURNING *");
        $update->execute(['estado' => $nuevoEstado, 'id' => $id]);

        echo json_encode([
            'success' => true,
            'message' => "Estado de la reserva actualizado a '$nuevoEstado'.",
            'reserva' => $update->fetch()
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Eliminar reserva
     * DELETE /api/reservas/{id}
     */
    public function delete(int $id): void {
        $authUser = AuthMiddleware::authenticate();
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM reservas WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $reserva = $stmt->fetch();

        if (!$reserva) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (($authUser->rol ?? '') !== 'admin' && (int)$reserva['usuario_id'] !== (int)$authUser->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tiene permiso para eliminar esta reserva.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $del = $db->prepare("DELETE FROM reservas WHERE id = :id");
        $del->execute(['id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Reserva eliminada con éxito.'], JSON_UNESCAPED_UNICODE);
    }
}
