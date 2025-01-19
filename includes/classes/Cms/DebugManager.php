<?php

namespace Cms;

use ReflectionClass;
use ReflectionFunction;
use Admin\PluginManager;

class DebugManager
{
    /**
     * Log all registered routes in a table.
     */
    public static function logRoutes(): void
    {
        $routes = RouteManager::getAllRoutes();

        echo "<h3>Registered Routes</h3>";

        if (empty($routes)) {
            echo "<p>No routes found.</p>";
            return;
        }

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<thead>";
        echo "<tr><th>Path</th><th>Callback</th><th>Access Level</th><th>Constraints</th><th>Source</th></tr>";
        echo "</thead><tbody>";

        foreach ($routes as $path => $route) {
            $callback = self::formatCallback($route['callback'] ?? 'N/A');
            $access = htmlspecialchars($route['access'] ?? 'N/A');
            $constraints = json_encode(RouteManager::getConstraints($path) ?? [], JSON_PRETTY_PRINT);
            $source = htmlspecialchars($route['source'] ?? 'unknown');

            echo "<tr>";
            echo "<td>" . htmlspecialchars($path) . "</td>";
            echo "<td>{$callback}</td>";
            echo "<td>{$access}</td>";
            echo "<td><pre>{$constraints}</pre></td>";
            echo "<td>{$source}</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
    }

    /**
     * Log all loaded plugins in a table.
     */
    public static function logPlugins(PluginManager $pluginManager): void
    {
        echo "<h3>Loaded Plugins</h3>";

        // Fetch all plugins
        $plugins = $pluginManager->listPlugins();

        if (empty($plugins)) {
            echo "<p>No plugins are currently installed.</p>";
            return;
        }

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<thead>";
        echo "<tr><th>Name</th><th>Version</th><th>Description</th><th>Status</th></tr>";
        echo "</thead><tbody>";

        foreach ($plugins as $plugin) {
            $name = htmlspecialchars($plugin['name'] ?? 'Unknown');
            $version = htmlspecialchars($plugin['version'] ?? 'Unknown');
            $description = htmlspecialchars($plugin['description'] ?? 'No description');
            $status = htmlspecialchars($plugin['status'] ?? 'Unknown');

            echo "<tr>";
            echo "<td>{$name}</td>";
            echo "<td>{$version}</td>";
            echo "<td>{$description}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
    }

    /**
     * Log all registered hooks and widgets in a table.
     */
    public static function logHooks(HookManager $hookManager): void
    {
        echo "<h3>Registered Hooks and Widgets</h3>";

        $hooks = $hookManager->exportHooks();

        if (empty($hooks)) {
            echo "<p>No hooks or widgets registered.</p>";
            return;
        }

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<thead>";
        echo "<tr><th>Type</th><th>Name</th><th>Callback</th><th>Priority</th><th>Description</th><th>Options</th></tr>";
        echo "</thead><tbody>";

        foreach ($hooks as $hookName => $hookDetails) {
            foreach ($hookDetails as $detail) {
                $type = htmlspecialchars($detail['options']['type'] ?? 'hook');
                $callback = self::formatCallback($detail['callback']);
                $priority = htmlspecialchars($detail['priority'] ?? 'N/A');
                $description = htmlspecialchars($detail['description'] ?? 'No description');
                $options = json_encode($detail['options'], JSON_PRETTY_PRINT);

                echo "<tr>";
                echo "<td>{$type}</td>";
                echo "<td>" . htmlspecialchars($hookName) . "</td>";
                echo "<td>{$callback}</td>";
                echo "<td>{$priority}</td>";
                echo "<td>{$description}</td>";
                echo "<td><pre>{$options}</pre></td>";
                echo "</tr>";
            }
        }

        echo "</tbody></table>";
    }

    /**
     * Log all declared CMS classes in a table.
     */
    public static function logClasses(): void
    {
        $classes = get_declared_classes();
        $cmsClasses = array_filter($classes, function ($class) {
            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();
            return $file && strpos($file, '/Cms/') !== false;
        });

        echo "<h3>Declared CMS Classes</h3>";

        if (empty($cmsClasses)) {
            echo "<p>No CMS classes found.</p>";
            return;
        }

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<thead><tr><th>Class Name</th></tr></thead><tbody>";

        foreach ($cmsClasses as $class) {
            echo "<tr><td>{$class}</td></tr>";
        }

        echo "</tbody></table>";
    }

    /**
     * Log all declared CMS functions in a table.
     */
    public static function logFunctions(): void
    {
        $functions = get_defined_functions();
        $cmsFunctions = array_filter($functions['user'], function ($function) {
            $reflection = new ReflectionFunction($function);
            $file = $reflection->getFileName();
            return strpos($file, '/Cms/') !== false;
        });

        echo "<h3>Declared CMS Functions</h3>";

        if (empty($cmsFunctions)) {
            echo "<p>No CMS functions found.</p>";
            return;
        }

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<thead><tr><th>Function Name</th></tr></thead><tbody>";

        foreach ($cmsFunctions as $function) {
            echo "<tr><td>{$function}</td></tr>";
        }

        echo "</tbody></table>";
    }

    /**
     * Log performance metrics in a table.
     */
    public static function logPerformanceMetrics(): void
    {
        echo "<h3>Performance Metrics</h3>";

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>";
        echo "<tr><td>Memory Usage</td><td>" . self::formatBytes(memory_get_usage(true)) . "</td></tr>";
        echo "<tr><td>Peak Memory Usage</td><td>" . self::formatBytes(memory_get_peak_usage(true)) . "</td></tr>";
        echo "<tr><td>Execution Time</td><td>" . round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']), 4) . " seconds</td></tr>";
        echo "</tbody></table>";
    }

    /**
     * Log everything for debugging purposes.
     */
    public static function logEverything(
        RouteManager $routeManager,
        HookManager $hookManager,
        PluginManager $pluginManager
    ): void {
        echo "<div class='site-main'>";
        echo "<h1>CMS Debug Report</h1>";

        self::logRoutes();
        self::logPlugins($pluginManager);
        self::logHooks($hookManager);
        self::logClasses();
        self::logFunctions();
        self::logPerformanceMetrics();

        echo "</div>";
    }

private static function formatCallback($callback): string {
    if (is_string($callback)) {
        // Handle function name or closure as string
        return $callback;
    }

    if (is_array($callback)) {
        // Handle class-method callbacks
        $className = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
        $methodName = $callback[1];
        return "{$className}::{$methodName}";
    }

    if ($callback instanceof \Closure) {
        // Handle closures
        return 'Closure';
    }

    // Fallback for other types
    return 'Unknown Callback';
}


    private static function formatBytes(int $bytes): string
    {
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = (int) floor(log($bytes, 1024));
        return sprintf("%.2f %s", $bytes / (1024 ** $factor), $sizes[$factor]);
    }
}
