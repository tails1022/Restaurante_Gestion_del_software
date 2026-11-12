<?php
/**
 * Redirección automática hacia la carpeta public/
 * Permite acceder directamente como: http://localhost/restaurante/
 */
header('Location: public/index.php');
exit;
