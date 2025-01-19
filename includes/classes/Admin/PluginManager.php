<?php

namespace Admin;

use Cms\CmsManager;
use PDO;

class PluginManager
{
    private PDO $db;
    private CmsManager $cmsManager;

    /**
     * Constructor to initialize the PluginManager.
     */
    public function __construct(PDO $db, CmsManager $cmsManager)
    {
        $this->db = $db;
        $this->cmsManager = $cmsManager;
    }

    /**
     * List all plugins in the plugins directory.
     * Reads nfo.json for metadata, including 'status'.
     */
    public function listPlugins(): array
    {
        $pluginsPath = realpath(PLUGIN_DIRECTORY);

        if (!$pluginsPath) {
            throw new \Exception("Plugins directory not found at the expected location: " . PLUGIN_DIRECTORY);
        }

        $plugins = [];
        foreach (glob($pluginsPath . '/*', GLOB_ONLYDIR) as $pluginDir) {
            $nfoFile = $pluginDir . '/nfo.json';
            if (file_exists($nfoFile)) {
                $pluginData = $this->getPluginMetaFromNfo($nfoFile);

                if ($pluginData) {
                    // Directory name (e.g., "myPlugin")
                    $pluginData['directory'] = basename($pluginDir);

                    // Make sure 'status' is set, default to 'inactive' if missing
                    if (!isset($pluginData['status'])) {
                        $pluginData['status'] = 'inactive';
                    }

                    $plugins[] = $pluginData;
                }
            }
        }

        return $plugins;
    }

    /**
     * Get plugin metadata from its nfo.json file.
     * We expect at least name, description, version, and (optionally) status.
     */
    private function getPluginMetaFromNfo(string $nfoFile): ?array
    {
        $pluginMeta = json_decode(file_get_contents($nfoFile), true);

        // Ensure required fields are present
        if (
            isset($pluginMeta['name']) &&
            isset($pluginMeta['description']) &&
            isset($pluginMeta['version'])
        ) {
            return $pluginMeta;
        }

        return null;
    }

    /**
     * Load all plugins that are marked "active" in their nfo.json.
     */
    public function loadActivePlugins(): void
    {
        $pluginsPath = realpath(PLUGIN_DIRECTORY);
        if (!$pluginsPath) {
            $this->cmsManager->logCmsEvent('error', "Plugins directory not found at: " . PLUGIN_DIRECTORY);
            return;
        }

        // First, get the list of all plugins from nfo.json
        $allPlugins = $this->listPlugins();

        // Filter for those marked 'active'
        $activePlugins = array_filter($allPlugins, function ($plugin) {
            return isset($plugin['status']) && $plugin['status'] === 'active';
        });

        // Load each active plugin
        foreach ($activePlugins as $pluginData) {
            $pluginDir = $pluginData['directory'];
            $pluginMainFile = $pluginsPath . '/' . $pluginDir . '/main.php';
            $nfoFile = $pluginsPath . '/' . $pluginDir . '/nfo.json';

            if (!file_exists($pluginMainFile) || !file_exists($nfoFile)) {
                $this->cmsManager->logCmsEvent(
                    'error',
                    "Missing main.php or nfo.json for plugin: {$pluginDir}"
                );
                continue;
            }

            // Parse plugin dependencies (from nfo.json)
            $pluginDependencies = $this->getPluginDependencies($nfoFile);

            // Prepare required dependencies
            $dependencyMap = $this->prepareDependencies($pluginDependencies);

            try {
                // Extract dependencies into the scope for the plugin
                extract($dependencyMap);

                // Include the plugin's main file
                include_once $pluginMainFile;

                // Log success
                $this->cmsManager->logCmsEvent('info', "Plugin loaded: {$pluginDir}");
            } catch (\Exception $e) {
                $this->cmsManager->logCmsEvent(
                    'error',
                    "Error loading plugin: {$pluginDir}. " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Get plugin dependencies from nfo.json.
     */
    private function getPluginDependencies(string $nfoFile): array
    {
        $pluginMeta = json_decode(file_get_contents($nfoFile), true);
        return $pluginMeta['dependencies'] ?? [];
    }

    /**
     * Prepare required dependencies for a plugin.
     */
    private function prepareDependencies(array $dependencies): array
    {
        $dependencyMap = [];

        // Map available dependencies
        $availableDependencies = [
            'db'            => $this->db,
            'cmsManager'    => $this->cmsManager,
            'hookManager'   => $GLOBALS['hookManager'] ?? null,
            'routeManager'  => $GLOBALS['routeManager'] ?? null,
            'securityManager' => $GLOBALS['securityManager'] ?? null,
        ];

        // Check requested dependencies
        foreach ($dependencies as $dependency) {
            if (array_key_exists($dependency, $availableDependencies)) {
                $dependencyMap[$dependency] = $availableDependencies[$dependency];
            } else {
                throw new \Exception("Dependency '{$dependency}' is not available.");
            }
        }

        return $dependencyMap;
    }

    /**
     * Get the path to a plugin's main file (if needed).
     */
    public function getPluginFile(string $pluginName): ?string
    {
        $pluginsPath = realpath(PLUGIN_DIRECTORY);
        $pluginFile = $pluginsPath . '/' . $pluginName . '/main.php';

        return file_exists($pluginFile) ? $pluginFile : null;
    }
}
