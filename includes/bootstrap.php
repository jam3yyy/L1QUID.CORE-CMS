<?php
#
#/includes/bootstrap.php
#
declare(strict_types=1);

if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '127.0.0.1') {
    // We are on local environment
    require_once  '/var/www/lighttpd/ld/vendor/autoload.php';
    $configPath =  '/var/www/lighttpd/ld/config.php';
} else {
    // We are on production environment
    require_once '/home/public/vendor/autoload.php';
    $configPath = '/home/protected/config.php';
}

// Then load the config
require_once $configPath;

if (!file_exists($configPath)) {
    die('Configuration file not found. Please check the path.');
}
require_once $configPath;

spl_autoload_register(function ($className) {
    // Use CMS_ROOT instead of __DIR__
    $baseDir = CMS_ROOT . '/includes/classes/';
    // Handle `Classes\` namespace
    if (str_starts_with($className, 'Classes\\')) {
        $className = substr($className, strlen('Classes\\'));
    }

    // Convert namespace to a file path
    $file = $baseDir . str_replace('\\', '/', $className) . '.php';
    // Debugging (optional)
    // error_log("Autoloader: Resolving {$className} to {$file}");

    if (file_exists($file)) {
        require_once $file;
    } else {
        error_log("Autoloader: Class file not found for {$className} at {$file}");
    }
});


// Start the session if it isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Import core CMS classes
use Cms\CmsManager;
use Cms\UserManager;
use Cms\SecurityManager;
use Cms\NavigationManager;
use Cms\HookManager;
use Cms\RouteManager;
use Cms\PluginManager;
use Cms\DebugManager;


// Ensure required dependencies
if (!isset($db)) {
    die('Database connection not found. Please ensure $db is properly configured in config.php.');
}
if (!defined('BASE_URL')) {
    die('BASE_URL is not defined in config.php. Please ensure it is set.');
}

try {
    // Initialize core managers with dependency injection
    $cmsManager = new CmsManager($db, BASE_URL);
    $userManager = new UserManager($db, $cmsManager);
    $securityManager = new SecurityManager($cmsManager, $db);
    $navManager = new NavigationManager($db, $cmsManager, $userManager);
    $hookManager = new HookManager($cmsManager, $securityManager, $db); // Corrected order
    $pluginManager = new Admin\PluginManager($db, $cmsManager); // Ensure this points to Admin\PluginManager
    $routeManager = new RouteManager();

    // Set managers in RouteManager
    RouteManager::setCmsManager($cmsManager);
    RouteManager::setSecurityManager($securityManager);
    // Dynamically load active plugins using PluginManager
    $activePlugins = $pluginManager->listPlugins();

    foreach ($activePlugins as $plugin) {
        if ($plugin['status'] === 'active') {
            $pluginMainFile = $pluginManager->getPluginFile($plugin['name']);
            if ($pluginMainFile && file_exists($pluginMainFile)) {
                include_once $pluginMainFile;
                $cmsManager->logCmsEvent("Plugin loaded: {$plugin['name']}");
            } else {
                $cmsManager->logCmsEvent("Failed to load plugin: {$plugin['name']} - Main file missing.", 'error');
            }
        }
    }
} catch (Exception $e) {
   # $cmsManager->logCmsEvent("Error loading plugins: " . $e->getMessage(), 'error');
    die('Error loading plugins: ' . htmlspecialchars($e->getMessage()));
}

// Load active plugins
    $pluginManager->loadActivePlugins();
	    // Load hooks, widgets, and routes
    $routeManager->loadRoutes($db);

// Define a debug route for testing
function testDebugRoute()
{
    global $routeManager,$hookManager,$pluginManager;
    // Path to save the debug report
    $filePath = '/home/public/cms_debug_report.html';

    // Generate and save the debug report to an HTML file
    DebugManager::logEverything($routeManager, $hookManager, $pluginManager);
}

RouteManager::registerRoute(
    '/cmshelp',
    function () {
       CmsManager::cmsHelp();
       
    },
    'public',
    'cmshelp'
);

RouteManager::registerRoute(
    '/login',
    function () use ($userManager) {
        $userManager->renderLoginForm();
    },
    'public',
    'login'
);

RouteManager::registerRoute(
    '/register',
    function () use ($userManager) {
        $userManager->renderRegisterForm();
    },
    'public',
    'register'
);

RouteManager::registerRoute(
    '/verify',
    function () use ($userManager) {
        $token = $_GET['token'] ?? '';define('PLUGIN_DIRECTORY', '/plugins/');
        if ($token && $userManager->verifyEmail($token)) {
            echo "Email verified successfully. You can now <a href='/login'>login</a>.";
        } else {
            echo "Invalid or expired verification token.";
        }
    },
    'public',
    'verify'
);

RouteManager::registerRoute('/logout', function () use ($userManager) {
    $userManager->logout();
}, 'public', 'logout');

// Register the /profile route
RouteManager::registerRoute('/profile', function (PDO $db) {
    // Get the logged-in user's ID
    $userId = $_SESSION['user_id'];

    // Initialize dependencies
    $cmsManager = CmsManager::createFromConfig($db);
    $userManager = new UserManager($db, $cmsManager);

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (isset($_POST['update_profile'])) {
                $userManager->handleProfileUpdate($userId, $_POST);
                echo "<p class='success'>Profile updated successfully.</p>";
            } elseif (isset($_POST['change_password'])) {
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                $userManager->changePassword($userId, $newPassword, $confirmPassword);
                echo "<p class='success'>Password changed successfully.</p>";
            } elseif (isset($_POST['change_email'])) {
                $newEmail = $_POST['new_email'] ?? '';
                $userManager->changeEmail($userId, $newEmail);
                echo "<p class='success'>Email change request submitted. Please check your email for verification.</p>";
            } elseif (isset($_POST['delete_account'])) {
                $userManager->sendAccountDeletionVerification($userId);
                echo "<p class='success'>Account deletion request sent. Please check your email for confirmation.</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    // Render the profile form
    $userManager->renderUserProfileForm($userId);
});

RouteManager::registerRoute('/reset-password', function (PDO $db) {
    $token = $_GET['token'] ?? '';
    if (!$token) {
        echo "<p>Invalid request.</p>";
        return;
    }

    try {
        $cmsManager = CmsManager::createFromConfig($db);
        $userManager = new UserManager($db, $cmsManager);
        $userManager->handlePasswordReset($token);
        echo "<p>Your password has been reset. Check your email for the new password.</p>";
    } catch (Exception $e) {
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
});
RouteManager::registerRoute('/delete-account', function (PDO $db) {
    $token = $_GET['token'] ?? '';
    if (!$token) {
        echo "<p>Invalid request.</p>";
        return;
    }

    try {
        $cmsManager = CmsManager::createFromConfig($db);
        $userManager = new UserManager($db, $cmsManager);
        $userManager->handleAccountDeletion($token);
        echo "<p>Your account has been deleted successfully.</p>";
    } catch (Exception $e) {
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
});

use Admin\Dashboard;

//Initialize the Dashboard and pass required dependencies
if ($securityManager->hasAccess('admin')) {
    // Make sure you already have a valid $db (PDO) instance here
    $dashboard = new Admin\Dashboard(
        $db,
        $hookManager,
        $userManager,
        $cmsManager,
        $routeManager,
        $pluginManager,
        $navManager
    );

    RouteManager::registerRoute('/admin/dashboard', function () use ($dashboard) {
        $dashboard->render();
    }, 'admin', 'dashboard');
}

$pluginManagerEditor = new Admin\Cogs\PluginManagerEditor($pluginManager);

// 1) Register the "list" route
$routeInfo = $pluginManagerEditor->getRoute();
RouteManager::registerRoute(
    $routeInfo['path'],                          // "/admin/plugins"
    [$pluginManagerEditor, $routeInfo['callback']], // [ $pluginManagerEditor, "renderPluginList" ]
    $routeInfo['access'] ?? 'admin',             // "admin"
    $routeInfo['name'] ?? 'Plugin Manager'       // "Plugin Manager"
);

// 2) Register the "process" route
$processInfo = $pluginManagerEditor->getProcessRoute();
RouteManager::registerRoute(
    $processInfo['path'],                           // "/admin/plugins/process"
    [$pluginManagerEditor, $processInfo['callback']], // [ $pluginManagerEditor, "processPluginAction" ]
    $processInfo['access'] ?? 'admin',              // "admin"
    $processInfo['name'] ?? 'Process Plugin Actions' // "Process Plugin Actions"
);



RouteManager::registerRoute('/test-debug', 'testDebugRoute');

?>
