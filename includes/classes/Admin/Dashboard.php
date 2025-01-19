<?php

namespace Admin;

use PDO;
use Cms\HookManager;
use Cms\UserManager;
use Cms\RouteManager;
use Cms\CmsManager;
use Cms\NavigationManager;
use Admin\PluginManager;

class Dashboard {
    private HookManager $hookManager;
    private UserManager $userManager;
    private CmsManager $cmsManager;
    private RouteManager $routeManager;
    private PluginManager $pluginManager;
    private NavigationManager $navigationManager;
    private PDO $db;
    private array $cogs = [];

    public function __construct(
        PDO $db,
        HookManager $hookManager,
        UserManager $userManager,
        CmsManager $cmsManager,
        RouteManager $routeManager,
        PluginManager $pluginManager,
        NavigationManager $navigationManager,
    ) {
        // Initialize properties
        $this->hookManager = $hookManager;
        $this->userManager = $userManager;
        $this->cmsManager = $cmsManager;
        $this->routeManager = $routeManager;
        $this->pluginManager = $pluginManager;
        $this->navigationManager = $navigationManager;
        $this->db = $db;

        // Discover and initialize cogs
        $this->discoverCogs();
    }

    /**
     * Discover all cogs and initialize them.
     */
    private function discoverCogs(): void {
        $cogsPath = realpath(__DIR__ . '/Cogs');

        if (!$cogsPath) {
            throw new \Exception("Cogs directory not found.");
        }

        foreach (glob($cogsPath . '/*.php') as $file) {
            $className = 'Admin\\Cogs\\' . basename($file, '.php');

            if (class_exists($className)) {
                $this->initializeCog($className);
            }
        }
    }

    /**
     * Initialize a cog and register its route.
     */
    private function initializeCog(string $className): void {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor ? $constructor->getParameters() : [];

        // Prepare dependencies for cog initialization
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            switch ($name) {
                case 'hookManager':
                    $dependencies[] = $this->hookManager;
                    break;
                case 'userManager':
                    $dependencies[] = $this->userManager;
                    break;
                case 'cmsManager':
                    $dependencies[] = $this->cmsManager;
                    break;
                case 'routeManager':
                    $dependencies[] = $this->routeManager;
                    break;
                case 'pluginManager':
                    $dependencies[] = $this->pluginManager;
                    break;
                case 'navigationManager':
                    $dependencies[] = $this->navigationManager;
                    break;
                case 'db':
                    $dependencies[] = $this->db;
                    break;
                default:
                    throw new \Exception("Unknown dependency '{$name}' for cog {$className}.");
            }
        }

        // Instantiate the cog with resolved dependencies
        $cogInstance = $reflection->newInstanceArgs($dependencies);

        // Register the cog's route if defined
        if (method_exists($cogInstance, 'getRoute')) {
            $route = $cogInstance->getRoute();
            $this->routeManager->registerRoute(
                $route['path'],
                [$cogInstance, $route['callback']],
                $route['access'] ?? 'admin',
                $route['name'] ?? null,
                'admin'
            );

            // Add the cog to the dashboard
            $this->cogs[] = [
                'name' => $route['name'] ?? $route['path'],
                'path' => $route['path'],
            ];
        }
    }

    /**
     * Render the admin dashboard with links to cogs.
     */
    public function render(): void
    {
        echo '<div class="site-main">';
        echo '<h1>Admin Dashboard</h1>';
        echo '<ul>';
        foreach ($this->cogs as $cog) {
            $fullUrl = rtrim(BASE_URL, '/') . '/' . ltrim($cog['path'], '/');
            echo "<li><a href='{$fullUrl}'>{$cog['name']}</a></li>";
        }
        echo '</ul>';
        echo '</div>';
    }
}

