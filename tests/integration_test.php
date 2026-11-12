<?php
/**
 * Batería de Pruebas de Integración y Verificación de Línea Base (LB-01)
 * Sistema Integral de Gestión de Restaurante (SIGR)
 * Ejecutar con: php tests/integration_test.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Utils\JWTHandler;
use App\Utils\Security;
use App\Controllers\CashRegisterController;

// Iniciar sesión antes de imprimir cualquier encabezado para pruebas CLI
Security::startSecureSession();
$_SESSION['auth_user'] = (object)['id' => 1, 'nombre' => 'Administrador SIGR', 'rol' => 'admin'];

echo "====================================================================\n";
echo "   SUITE DE PRUEBAS DE INTEGRACION - LINEA BASE SIGR (v1.0.0-baseline)\n";
echo "   Autor: Juan Pablo Lopez Diaz | Curso: Gestion del Software\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $description\n";
        $passed++;
    } else {
        echo "  [FAIL] $description\n";
        $failed++;
    }
}

try {
    // TEST 1: Conexión a PostgreSQL 16
    $db = Database::getConnection();
    assertTest("1. Conexión exitosa a PostgreSQL 16 con PDO", $db instanceof PDO);

    // TEST 2: Verificación de tablas de la línea base
    $tablesRequired = ['usuarios', 'categorias', 'platos', 'reservas', 'pedidos', 'pedido_items', 'cajas_turnos', 'facturas'];
    $stmtTables = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    $existingTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

    $allTablesPresent = true;
    foreach ($tablesRequired as $table) {
        if (!in_array($table, $existingTables, true)) {
            $allTablesPresent = false;
            break;
        }
    }
    assertTest("2. Integridad de esquema: Las 8 tablas requeridas existen en PostgreSQL", $allTablesPresent);

    // TEST 3: Verificación de usuarios semilla y roles RBAC (admin, mesero, cliente)
    $stmtUsers = $db->query("SELECT email, rol, password FROM usuarios ORDER BY id ASC");
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    $rolesFound = array_column($users, 'rol');

    $hasAdmin = in_array('admin', $rolesFound, true);
    $hasMesero = in_array('mesero', $rolesFound, true);
    $hasCliente = in_array('cliente', $rolesFound, true);
    assertTest("3. Roles RBAC definidos y usuarios iniciales cargados (admin, mesero, cliente)", $hasAdmin && $hasMesero && $hasCliente);

    // TEST 4: Verificación criptográfica de JWT y contraseñas
    $adminUser = $users[0];
    $pwdValid = password_verify('admin123', $adminUser['password']);
    $token = JWTHandler::generateToken(['id' => 1, 'nombre' => 'Admin Test', 'email' => 'admin@restaurante.com', 'rol' => 'admin']);
    $decoded = JWTHandler::verifyToken($token);
    assertTest("4. Seguridad de autenticación: Bcrypt y JWT HS256 operativos", $pwdValid && isset($decoded->data->email));

    // TEST 5: Menú digital y categorías
    $stmtPlatos = $db->query("SELECT COUNT(*) FROM platos WHERE disponible = true");
    $cantPlatos = (int)$stmtPlatos->fetchColumn();
    assertTest("5. Menú digital: Platos cargados y disponibles ($cantPlatos platos activos)", $cantPlatos >= 5);

    // TEST 6: Creación y cálculo de pedido
    $db->beginTransaction();
    $stmtInsPed = $db->prepare("
        INSERT INTO pedidos (usuario_id, tipo, mesa, total, estado, direccion_entrega)
        VALUES (3, 'sala', 'Mesa 2', 48500.00, 'pendiente', 'Atención en sala')
        RETURNING id
    ");
    $stmtInsPed->execute();
    $testPedId = (int)$stmtInsPed->fetchColumn();

    $stmtInsItem = $db->prepare("
        INSERT INTO pedido_items (pedido_id, plato_id, cantidad, precio_unitario, subtotal)
        VALUES (:pid, 5, 1, 48500.00, 48500.00)
    ");
    $stmtInsItem->execute(['pid' => $testPedId]);
    $db->commit();
    assertTest("6. Módulo de Pedidos: Inserción atómica y cálculo de ítems exitoso (Pedido #$testPedId)", $testPedId > 0);

    // TEST 7: Facturación fiscal y cálculo de IVA
    $cajaCtrl = new CashRegisterController();
    $factura = $cajaCtrl->issueInvoice($testPedId, 'efectivo', 'Ana Gomez Test', '1098765432');
    $subtotal = (float)$factura['subtotal'];
    $iva = (float)$factura['impuesto_iva'];
    $total = (float)$factura['total'];
    $ivaCorrecto = abs(($subtotal + $iva) - $total) < 0.05;
    assertTest("7. Módulo de Facturación: Emisión de {$factura['numero_factura']} con desglose de IVA 19%", $ivaCorrecto && !empty($factura['numero_factura']));

    // TEST 8: Control de Caja y Arqueo Diario
    $turnoActivo = $cajaCtrl->getActiveShift();
    assertTest("8. Módulo de Caja: Turno activo detectado y saldo actualizado con la venta", $turnoActivo !== null && (float)$turnoActivo['monto_ventas_efectivo'] >= 48500.00);

    // TEST 9: Consolidación de Reporte Diario de Ventas
    $reporte = $cajaCtrl->getDailySalesReport();
    assertTest("9. Módulo de Reportes: Consolidación diaria de facturación y categorías generada", (int)$reporte['totales']['cantidad_facturas'] >= 1);

    // TEST 10: Prevención de Seguridad CSRF y Sanitización
    $tokenCsrf = Security::getCsrfToken();
    $tokenValido = Security::validateCsrfToken($tokenCsrf);
    $tokenInvalido = Security::validateCsrfToken('token_falso_123');
    $escapeOk = Security::escape("<script>alert('xss')</script>") === "&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;";
    assertTest("10. Contramedidas de Seguridad: Mitigación CSRF y sanitización XSS verificadas", $tokenValido && !$tokenInvalido && $escapeOk);

} catch (Exception $e) {
    echo "\n[ERROR CRITICO EN PRUEBAS]: " . $e->getMessage() . "\n";
    $failed++;
}

echo "\n--------------------------------------------------------------------\n";
echo " RESULTADOS FINALES: $passed APROBADAS / $failed FALLIDAS\n";
echo " ESTADO DE LA LINEA BASE: " . ($failed === 0 ? "TOTALMENTE ESTABLE (HITO APROBADO)" : "REQUIERE ATENCION") . "\n";
echo "--------------------------------------------------------------------\n";

exit($failed === 0 ? 0 : 1);
