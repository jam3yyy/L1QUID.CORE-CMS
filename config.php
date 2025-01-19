<?php
// config.php
date_default_timezone_set('America/New_York');
define('DEBUG', false); 
define('DB_HOST', 'localhost');
define('DB_NAME', 'main');
define('DB_USER', 'USER');
define('DB_PASS', 'PASSWORD');

// SMTP Settings
define('SMTP_HOST', 'smtp.server.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'USER');
define('SMTP_PASSWORD', 'PASSWORD');
define('FROM_EMAIL', 'no-reply@example.com');
define('FROM_NAME', 'Website Admin');
define('CMS_ROOT', '/var/www/html');
define('PLUGIN_DIRECTORY', CMS_ROOT . '/plugins');
define('BASE_URL', 'http://example.com');

// Connect DB

try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    // Set PDO error mode to exception
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Log error and display a generic message
    error_log('Database connection error: ' . $e->getMessage());
    die('Database connection error. Please try again later.');
}

?>
