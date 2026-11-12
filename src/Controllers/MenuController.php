<?php

namespace App\Controllers;

use App\Config\Database;
use PDO;

class MenuController {
    /**
     * Listar categorías y sus platos
     * GET /api/menu
     */
    public function getMenu(): void {
        $db = Database::getConnection();

        $catStmt = $db->query("SELECT * FROM categorias ORDER BY id ASC");
        $categorias = $catStmt->fetchAll();

        $platosStmt = $db->query("
            SELECT p.*, c.nombre as categoria_nombre 
            FROM platos p 
            LEFT JOIN categorias c ON p.categoria_id = c.id 
            WHERE p.disponible = true 
            ORDER BY p.categoria_id, p.id ASC
        ");
        $platos = $platosStmt->fetchAll();

        echo json_encode([
            'success' => true,
            'categorias' => $categorias,
            'platos' => $platos
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Búsqueda de platos en el catálogo
     * GET /api/menu/search?q=...
     * 
     * NOTA ACADEMICA OWASP ZAP:
     * Si VULNERABLE_MODE=true, este endpoint es vulnerable a SQL Injection (A03)
     * mediante concatenación directa, permitiendo que ZAP Active Scanner detecte la falla.
     * Si VULNERABLE_MODE=false, utiliza consultas preparadas seguras (Prepared Statements).
     */
    public function search(): void {
        $query = $_GET['q'] ?? '';
        $db = Database::getConnection();

        if (Database::isVulnerableMode()) {
            // VULNERABLE: Inyección SQL deliberada para pruebas con OWASP ZAP
            // Payload de prueba ZAP: ' OR '1'='1 --
            $sql = "SELECT p.*, c.nombre as categoria_nombre 
                    FROM platos p 
                    LEFT JOIN categorias c ON p.categoria_id = c.id 
                    WHERE p.nombre ILIKE '%$query%' OR p.descripcion ILIKE '%$query%'";
            try {
                $stmt = $db->query($sql);
                $platos = $stmt ? $stmt->fetchAll() : [];
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Error en consulta SQL: ' . $e->getMessage()
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        } else {
            // MITIGADO: Consulta preparada segura con parámetros bind
            $sql = "SELECT p.*, c.nombre as categoria_nombre 
                    FROM platos p 
                    LEFT JOIN categorias c ON p.categoria_id = c.id 
                    WHERE p.nombre ILIKE :q1 OR p.descripcion ILIKE :q2";
            $stmt = $db->prepare($sql);
            $searchParam = '%' . $query . '%';
            $stmt->execute(['q1' => $searchParam, 'q2' => $searchParam]);
            $platos = $stmt->fetchAll();
        }

        echo json_encode([
            'success' => true,
            'vulnerable_mode' => Database::isVulnerableMode(),
            'total' => count($platos),
            'platos' => $platos
        ], JSON_UNESCAPED_UNICODE);
    }
}
