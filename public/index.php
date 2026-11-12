<?php
/**
 * Página Principal del Restaurante - Menú y Catálogo
 * 100% PHP del lado del servidor
 */
$pageTitle = 'Menú - Sabor Gourmet';
$activeNav = 'menu';

require_once __DIR__ . '/includes/header.php';

use App\Config\Database;

$db = Database::getConnection();

// 1. Obtener Categorías
$catStmt = $db->query("SELECT * FROM categorias ORDER BY id ASC");
$categorias = $catStmt->fetchAll();

// 2. Filtrado y Búsqueda
$selectedCat = $_GET['cat'] ?? 'all';
$searchQuery = trim($_GET['q'] ?? '');

$platos = [];

if (!empty($searchQuery)) {
    // NOTA ACADEMICA OWASP ZAP (Inyección SQL - A03:2021 Injection):
    // Si VULNERABLE_MODE=true, se concatena la entrada directamente para que ZAP la detecte.
    if (Database::isVulnerableMode()) {
        $sql = "SELECT p.*, c.nombre as categoria_nombre 
                FROM platos p 
                LEFT JOIN categorias c ON p.categoria_id = c.id 
                WHERE (p.nombre ILIKE '%$searchQuery%' OR p.descripcion ILIKE '%$searchQuery%') 
                AND p.disponible = true 
                ORDER BY p.id ASC";
        try {
            $stmt = $db->query($sql);
            $platos = $stmt ? $stmt->fetchAll() : [];
        } catch (\Exception $e) {
            $sqlError = $e->getMessage();
        }
    } else {
        // Modo Seguro: Consulta preparada con PDO
        $sql = "SELECT p.*, c.nombre as categoria_nombre 
                FROM platos p 
                LEFT JOIN categorias c ON p.categoria_id = c.id 
                WHERE (p.nombre ILIKE :q1 OR p.descripcion ILIKE :q2) 
                AND p.disponible = true 
                ORDER BY p.id ASC";
        $stmt = $db->prepare($sql);
        $term = '%' . $searchQuery . '%';
        $stmt->execute(['q1' => $term, 'q2' => $term]);
        $platos = $stmt->fetchAll();
    }
} elseif ($selectedCat !== 'all' && is_numeric($selectedCat)) {
    $stmt = $db->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM platos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        WHERE p.categoria_id = :cat_id AND p.disponible = true 
        ORDER BY p.id ASC
    ");
    $stmt->execute(['cat_id' => (int)$selectedCat]);
    $platos = $stmt->fetchAll();
} else {
    $stmt = $db->query("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM platos p 
        LEFT JOIN categorias c ON p.categoria_id = c.id 
        WHERE p.disponible = true 
        ORDER BY p.categoria_id, p.id ASC
    ");
    $platos = $stmt->fetchAll();
}
?>

<!-- Banner Hero -->
<header class="hero-section shadow-sm">
    <div class="container">
        <h1 class="display-4 fw-bold mb-3">Experiencia Gastronómica Excepcional</h1>
        <p class="lead mb-4 text-light">Pide tus platos favoritos a domicilio o reserva tu mesa en segundos.</p>
        <div class="d-flex justify-content-center gap-3">
            <a href="#menu-catalog" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold text-dark">
                <i class="bi bi-arrow-down-circle me-1"></i> Ver Menú
            </a>
            <a href="reservas.php" class="btn btn-outline-light btn-lg rounded-pill px-4">
                <i class="bi bi-calendar-plus me-1"></i> Reservar Mesa
            </a>
        </div>
    </div>
</header>

<main class="container my-4" id="menu-catalog">
    <!-- Buscador Clásico con formulario GET tradicional (Fácil de escanear por ZAP) -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md-6">
            <form action="index.php" method="GET" class="d-flex">
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control border-start-0" 
                           placeholder="Buscar plato o ingrediente..." 
                           value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="btn btn-warning fw-semibold" type="submit">Buscar</button>
                    <?php if (!empty($searchQuery) || $selectedCat !== 'all'): ?>
                        <a href="index.php" class="btn btn-outline-secondary" title="Limpiar filtro">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Pestañas de Categoría con enlaces GET normales -->
        <div class="col-md-6">
            <div class="d-flex gap-2 flex-wrap justify-content-md-end">
                <a href="index.php" class="btn btn-outline-secondary category-btn <?= $selectedCat === 'all' && empty($searchQuery) ? 'active' : '' ?>">
                    Todos
                </a>
                <?php foreach ($categorias as $cat): ?>
                    <a href="index.php?cat=<?= $cat['id'] ?>" 
                       class="btn btn-outline-secondary category-btn <?= (string)$selectedCat === (string)$cat['id'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat['nombre'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Alerta en caso de error SQL en modo vulnerable -->
    <?php if (isset($sqlError)): ?>
        <div class="alert alert-danger shadow-sm">
            <h6 class="fw-bold"><i class="bi bi-bug-fill me-2"></i> Error en la Base de Datos (Detectado por ZAP):</h6>
            <code><?= htmlspecialchars($sqlError, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
    <?php endif; ?>

    <!-- Cuadrícula de Platos -->
    <div class="row">
        <?php if (empty($platos)): ?>
            <div class="col-12 text-center py-5 text-muted">
                <i class="bi bi-search fs-2 d-block mb-2"></i>
                <p>No se encontraron platos que coincidan con la búsqueda.</p>
                <a href="index.php" class="btn btn-sm btn-outline-warning">Ver catálogo completo</a>
            </div>
        <?php else: ?>
            <?php foreach ($platos as $plato): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="dish-card">
                        <img src="<?= htmlspecialchars($plato['imagen_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($plato['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0 fw-bold"><?= htmlspecialchars($plato['nombre'], ENT_QUOTES, 'UTF-8') ?></h5>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($plato['categoria_nombre'] ?? 'Plato', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p class="card-text text-muted small flex-grow-1"><?= htmlspecialchars($plato['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                            
                            <!-- Formulario tradicional POST para agregar al carrito (100% PHP) -->
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                <span class="dish-price">$<?= number_format((float)$plato['precio'], 0, ',', '.') ?></span>
                                <form action="carrito.php?action=add" method="POST" class="m-0">
                                    <input type="hidden" name="plato_id" value="<?= (int)$plato['id'] ?>">
                                    <button type="submit" class="btn btn-warning text-dark fw-bold btn-sm rounded-pill px-3 shadow-sm">
                                        <i class="bi bi-cart-plus me-1"></i> Agregar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
