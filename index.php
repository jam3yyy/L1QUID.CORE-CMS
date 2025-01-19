<?php

define('DEBUG_ERRORS', true); // true or false

if (DEBUG_ERRORS) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

require_once __DIR__ . '/includes/bootstrap.php';

use Cms\RouteManager;

#$securityManager->enforceSsl();

// Fetch site options and user information
$siteTitle = $cmsManager->getSiteOption('site_title', 'L1QUID.CORE CMS') ?: 'L1QUID.CORE CMS';
$maintenanceMode = $cmsManager->getSiteOption('maintenance_mode', 0);
$templateName = $cmsManager->getSiteOption('active_template', 'default');
$recaptchaEnabled = $cmsManager->getSiteOption('recaptcha_enabled', '0');
$recaptchaSiteKey = $cmsManager->getSiteOption('recaptcha_site_key', '');

// Construct the template path
$templatePath = CMS_ROOT . '/templates/' . $templateName . '/layout.php';

$currentUser = $userManager->getCurrentUser();
$isAdmin = $currentUser && $userManager->hasAccessLevel($currentUser['username'], 'admin');

// Determine the current route
$basePath = dirname($_SERVER['SCRIPT_NAME']);
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = preg_replace("/^" . preg_quote($basePath, '/') . "/", '', $requestUri);
$requestUri = ltrim($requestUri, '/');
$currentRoute = $requestUri === '' ? '/' : '/' . $requestUri;

// Determine the title for the current route
$routeTitle = RouteManager::getRouteTitle($currentRoute) ?? $siteTitle;

// Handle maintenance mode
if ($maintenanceMode && !$isAdmin) {
    $cmsManager->logCmsEvent('info', "Maintenance mode active. User denied access to $currentRoute.");
    $cmsManager->displayMaintenancePage($currentUser);
    exit();
}

// Display maintenance banner for admin users
if ($maintenanceMode && $isAdmin) {
    $cmsManager->logCmsEvent('info', "Admin accessed site in maintenance mode at $currentRoute.");
    echo "<div class='maintenance-banner'>";
    echo "<strong>Maintenance Mode:</strong> Regular users cannot see the site.";
    echo "<button onclick=\"this.parentElement.style.display='none'\">Dismiss</button>";
    echo "</div>";
}

// Start output buffering for route content
ob_start();
try {
    // Attempt to handle the route
    $routeMatched = RouteManager::handleRoutes($currentRoute, $db);

    if (!$routeMatched) {
        $cmsManager->logCmsEvent('404', "No route matched for $currentRoute.");
    }
} catch (Exception $e) {
    $cmsManager->logCmsEvent('error', "Routing error: " . $e->getMessage());
    http_response_code(500); // Internal server error
    echo "<p>500 - Internal Server Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    error_log("Routing error: " . $e->getMessage());
    exit;
}
$content = ob_get_clean(); // Capture route output as content

// Include the layout
if (file_exists($templatePath)) {
    include $templatePath;
} else {
    $cmsManager->logCmsEvent('error', "Template path not found: $templatePath");
    echo "<p>Template not found. Please check your configuration.</p>";
    exit();
}

?>
