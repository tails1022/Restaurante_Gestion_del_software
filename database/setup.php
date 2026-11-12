<?php
/**
 * Script de Inicialización de Base de Datos para PostgreSQL 16
 * Lee variables de .env, crea la base de datos 'restaurante_db' si no existe,
 * y ejecuta el esquema inicial con datos semilla para la Línea Base (LB-01).
 */

function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$envPath = dirname(__DIR__) . '/.env';
loadEnv($envPath);

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '5432';
$dbName = $_ENV['DB_NAME'] ?? 'restaurante_db';
$user = $_ENV['DB_USER'] ?? 'postgres';
$pass = $_ENV['DB_PASS'] ?? '';

echo "====================================================\n";
echo " Conectando a PostgreSQL 16 ($host:$port) como '$user'...\n";
echo "====================================================\n";

try {
    // 1. Conexión a la base de datos por defecto 'postgres'
    $dsnDefault = "pgsql:host=$host;port=$port;dbname=postgres";
    $pdoDefault = new PDO($dsnDefault, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "[OK] Conexión establecida con PostgreSQL.\n";

    // 2. Comprobar si la base de datos ya existe
    $checkStmt = $pdoDefault->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
    $checkStmt->execute(['dbname' => $dbName]);
    $exists = $checkStmt->fetchColumn();

    if (!$exists) {
        echo "[INFO] Creando base de datos '$dbName'...\n";
        $pdoDefault->exec("CREATE DATABASE \"$dbName\" WITH ENCODING 'UTF8'");
        echo "[OK] Base de datos '$dbName' creada exitosamente.\n";
    } else {
        echo "[INFO] La base de datos '$dbName' ya existe.\n";
    }

    // 3. Conectar a 'restaurante_db' y ejecutar el schema.sql
    echo "[INFO] Conectando a '$dbName' para aplicar esquema y datos semilla...\n";
    $dsnApp = "pgsql:host=$host;port=$port;dbname=$dbName";
    $pdoApp = new PDO($dsnApp, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $sqlFile = __DIR__ . '/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("No se encontró el archivo schema.sql en: $sqlFile");
    }

    $schemaSql = file_get_contents($sqlFile);
    $pdoApp->exec($schemaSql);

    echo "[OK] Tablas creadas e índices y datos semilla insertados exitosamente!\n";
    echo "\n=== USUARIOS DE PRUEBA LISTOS (LINEA BASE) ===\n";
    echo "1. Administrador:\n   Email: admin@restaurante.com\n   Password: admin123\n";
    echo "2. Mesero (Sala y Comandas):\n   Email: mesero@restaurante.com\n   Password: mesero123\n";
    echo "3. Cliente (Pedidos y Reservas):\n   Email: cliente@correo.com\n   Password: cliente123\n";
    echo "====================================================\n";

} catch (PDOException $e) {
    echo "\n[ERROR DE CONEXION PDO]:\n" . $e->getMessage() . "\n";
    echo "\nTIP: Si la contraseña no es correcta, edita el archivo .env en:\n" . realpath($envPath) . "\ny vuelve a ejecutar: php database/setup.php\n";
    exit(1);
} catch (Exception $e) {
    echo "\n[ERROR]: " . $e->getMessage() . "\n";
    exit(1);
}
