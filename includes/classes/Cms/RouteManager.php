<?php

namespace Cms;

use PDO;
use Exception;

class RouteManager
{
    private static array $routes = []; // All registered routes
    private static array $constraints = []; // Route constraints
    private static array $routeNames = []; // Named routes
    private static SecurityManager $securityManager;
    private static CmsManager $cmsManager;

    /**
     * Set the SecurityManager instance for access control.
     */
    public static function setSecurityManager(SecurityManager $securityManager): void
    {
        self::$securityManager = $securityManager;
    }

    /**
     * Set the CmsManager instance for logging and utilities.
     */
    public static function setCmsManager(CmsManager $cmsManager): void
    {
        self::$cmsManager = $cmsManager;
    }

    /**
     * Register a route with the system.
     */
public static function registerRoute(
    string $path,
    callable|string|null $callback = null,
    string $accessLevel = 'public',
    ?string $name = null,
    string $source = 'hardcoded'
): void {
    if (!is_callable($callback) && !is_null($callback) && !is_string($callback)) {
        throw new \InvalidArgumentException("Callback for route {$path} must be callable, string, or null.");
    }

    if (isset(self::$routes[$path])) {
        self::$cmsManager->logCmsEvent('warning', "Route {$path} is being overwritten.");
    }

    self::$routes[$path] = [
        'callback' => $callback,
        'access' => $accessLevel,
        'source' => $source,
    ];

    if ($name) {
        self::$routeNames[$name] = $path;
    }
}



    /**
     * Add constraints to a route.
     */
    public static function addConstraint(string $path, array $constraints): void
    {
        self::$constraints[$path] = $constraints;
    }

    /**
     * Get all registered routes.
     */
    public static function getAllRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Get constraints for a specific route.
     */
    public static function getConstraints(string $path): ?array
    {
        return self::$constraints[$path] ?? null;
    }

    /**
     * Load active routes from the `cms_menu` table.
     */
public static function loadActiveRoutes(PDO $db): void
{
    try {
        $stmt = $db->query("SELECT path, callback, access_level FROM cms_menu WHERE menu_type = 'route' AND status = 'active'");
        $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routes as $route) {
            $originalCallback = $route['callback'];

            // Validate the callback
            if (!empty($route['path']) && self::isValidCallback($originalCallback)) {
                // Wrap the callback in a closure to inject parameters
                $callback = function () use ($originalCallback, $db) {
                    call_user_func($originalCallback, $db, self::$securityManager);
                };

                // Register the route
                self::registerRoute(
                    $route['path'],
                    $callback,
                    $route['access_level'],
                    null,
                    'cms_menu'
                );
            } else {
                self::$cmsManager->logCmsEvent('error', "Invalid callback for route: {$route['path']} in cms_menu.");
            }
        }
    } catch (Exception $e) {
        self::$cmsManager->logCmsEvent('error', "Error loading active routes: " . $e->getMessage());
    }
}

/**
 * Validate if a given callback is callable.
 */
private static function isValidCallback($callback): bool
{
    if (is_string($callback)) {
        // Check if it's a plain function or static method
        return function_exists($callback) || strpos($callback, '::') !== false && is_callable($callback);
    }

    if (is_array($callback) && isset($callback[0], $callback[1])) {
        // Check if it's a class-method callback
        return method_exists($callback[0], $callback[1]) || (is_string($callback[0]) && method_exists($callback[0], $callback[1]));
    }

    if ($callback instanceof \Closure) {
        // Check if it's a closure
        return true;
    }

    return false;
}




    /**
     * Load content routes from the `content` table.
     */
    public static function loadContentRoutes(PDO $db): void
    {
        try {
            $stmt = $db->query("SELECT slug, access_level FROM content WHERE status = 'published'");
            $contents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($contents as $content) {
                if (!empty($content['slug'])) {
                    $slug = '/' . $content['slug'];
                    self::registerRoute(
                        $slug,
                        function () use ($content, $db) {
                            self::displayContentBySlug($content['slug'], $db);
                        },
                        $content['access_level'],
                        null,
                        'content'
                    );
                }
            }
        } catch (Exception $e) {
            self::$cmsManager->logCmsEvent('error', "Error loading content routes: " . $e->getMessage());
        }
    }

    /**
     * Display content by its slug.
     */
private static function displayContentBySlug(string $slug, PDO $db): bool
{
    try {
        $stmt = $db->prepare("SELECT * FROM content WHERE slug = ? AND status = 'published'");
        $stmt->execute([$slug]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($content) {
            echo '<div class="site-main">';
            echo '<h1>' . htmlspecialchars($content['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
            if (!filter_var($content['source_link'], FILTER_VALIDATE_URL)) {
                $content['source_link'] = '#';
            }
            echo '<div>' . $content['body'] . '</div><div class="post-source"><a href="'. $content['source_link'] .'" target="_blank">Source Link</a></div>';
            echo '</div>';
            return true;
        }
    } catch (Exception $e) {
        self::$cmsManager->logCmsEvent('error', "Error displaying content by slug: " . $e->getMessage());
    }

    return false;
}

    /**
     * Handle routing for the current request URI.
     */
public static function handleRoutes(string $requestUri, PDO $db): bool
{
    self::loadRoutes($db); // Ensure all routes are loaded
    $parsedUri = parse_url($requestUri, PHP_URL_PATH);
    $normalizedUri = rtrim($parsedUri, '/'); // Normalize the URI by trimming trailing slashes

    // 1. Exact match
    if (isset(self::$routes[$normalizedUri])) {
        return self::executeRoute(self::$routes[$normalizedUri], $db);
    }

    // 2. Match the last segment of the path
    $segments = explode('/', trim($normalizedUri, '/'));
    $lastSegment = end($segments);

    if ($lastSegment && self::matchLastSegment($lastSegment, $db)) {
        return true;
    }
	
	if ($normalizedUri === '') {
        return self::displayDefaultContent($db, false); // Load default content without logging a 404 error
    }

    // 3. Default content for unmatched routes
    return self::displayDefaultContent($db, false);
}

 /**
     * Get the title of the route.
     */
    public static function getRouteTitle(string $currentRoute): ?string
    {
        return self::$routes[$currentRoute]['title'] ?? null;
    }

/**
 * Match the last segment of the URI with CMS menu slugs.
 */
private static function matchLastSegment(string $segment, PDO $db): bool
{
    try {
        // Match the last segment in cms_menu
        $stmt = $db->prepare("SELECT * FROM cms_menu WHERE path LIKE ? AND menu_type = 'slug' AND status = 'active'");
        $stmt->execute(["%/{$segment}"]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($route) {
            // Check for a valid callback
            if (!empty($route['callback']) && function_exists($route['callback'])) {
                call_user_func($route['callback']);
                return true;
            }

            // If no callback is defined, load content by the slug
            return self::displayContentBySlug($segment, $db);
        }

        // Match in content table as a fallback
        return self::displayContentBySlug($segment, $db);
    } catch (Exception $e) {
        self::$cmsManager->logCmsEvent('error', "Error matching last segment: " . $e->getMessage());
    }

    return false;
}


/**
 * Execute the given route.
 */
private static function executeRoute(array $route, PDO $db): bool {
    // Check access level
    if (!self::$securityManager->hasAccess($route['access'])) {
        http_response_code(403);
        echo "<div class='site-main'><p class='custom-error'>Access Denied.</p></div>";
        return false;
    }

    $callback = $route['callback'];

    try {
        // Determine if the callback is a method or a function
        if (is_array($callback)) {
            // Handle method callbacks (e.g., [$object, 'method'])
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
        } else {
            // Handle function or closure callbacks
            $reflection = new \ReflectionFunction($callback);
        }

        // Get the number of parameters for the callback
        $numArgs = $reflection->getNumberOfParameters();

        // Prepare arguments based on the number of required parameters
        $args = [$db];
        if ($numArgs > 1) {
            $args[] = self::$securityManager;
        }

        // Call the callback with arguments
        call_user_func_array($callback, $args);
        return true;
    } catch (\ReflectionException $e) {
        self::$cmsManager->logCmsEvent('error', "Error executing route callback: " . $e->getMessage());
        http_response_code(500);
        echo "<p class='custom-error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        return false;
    }
}



    /**
     * Display default content or 404 message.
     */
    private static function displayDefaultContent(PDO $db, bool $logError = true): bool
    {
        if ($logError) {
            self::$cmsManager->logCmsEvent('404', "Route not found: {$_SERVER['REQUEST_URI']}");
        }

        try {
            $stmt = $db->prepare("SELECT * FROM content WHERE slug = ? AND status = 'published'");
            $stmt->execute(['home']);
            $content = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($content) {
                echo '<div class="site-main">';
                echo '<div>' . $content['body'] . '</div>';
                echo '</div>';
            } else {
                echo '<div class="site-main">';
                echo '<h1>Welcome!</h1>';
                echo '<p>No homepage content is available. Please create a page with the slug "home".</p>';
                echo '</div>';
            }
        } catch (Exception $e) {
            self::$cmsManager->logCmsEvent('error', "Error loading default content: " . $e->getMessage());
        }

        return false;
    }
    
      /**
     * Load all routes from sources.
     */
    public static function loadRoutes(PDO $db): void
    {
        self::loadActiveRoutes($db);
        self::loadContentRoutes($db);
    }
}
