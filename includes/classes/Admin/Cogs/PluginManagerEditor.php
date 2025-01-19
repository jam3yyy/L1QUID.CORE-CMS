<?php

namespace Admin\Cogs;

use Admin\PluginManager;

/**
 * PluginManagerEditor
 *
 * Manages plugin actions (install, uninstall, activate, deactivate)
 * while leaving listing/loading tasks to the PluginManager.
 */
class PluginManagerEditor
{
    private PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Route info to display the plugin list UI (table of plugins).
     */
    public function getRoute(): array
    {
        return [
            'path'     => '/admin/plugins',
            'callback' => 'renderPluginList',
            'access'   => 'admin',
            'name'     => 'Plugin Manager',
        ];
    }

    /**
     * Route info to handle plugin actions (install/uninstall/activate/deactivate).
     */
    public function getProcessRoute(): array
    {
        return [
            'path'     => '/admin/plugins/process',
            'callback' => 'processPluginAction',
            'access'   => 'admin',
            'name'     => 'Process Plugin Action',
        ];
    }

    /**
     * Renders the plugin list table with action buttons.
     */
    public function renderPluginList(): void
    {
        try {
            // Let PluginManager handle listing
            $pluginList = $this->pluginManager->listPlugins();

            echo '<div class="site-main">';
            echo '<h1>Plugin Manager</h1>';
            echo '<table>';
            echo '<thead><tr><th>Name</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>';
            echo '<tbody>';

            foreach ($pluginList as $plugin) {
                $name        = htmlspecialchars($plugin['name'] ?? 'Unknown');
                $description = htmlspecialchars($plugin['description'] ?? '');
                $status      = htmlspecialchars($plugin['status'] ?? 'not_installed');
                $directory   = htmlspecialchars($plugin['directory'] ?? '');

                echo '<tr>';
                echo "<td>{$name}</td>";
                echo "<td>{$description}</td>";
                echo "<td>" . ucfirst($status) . "</td>";
                echo "<td>" . $this->renderActions($plugin) . "</td>";
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        } catch (\Exception $e) {
            echo '<p class="error">Error loading plugins: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    /**
     * Renders the action buttons for each plugin row.
     */
    private function renderActions(array $plugin): string
    {
        $actions   = [];
        $status    = $plugin['status'] ?? 'not_installed';
        $directory = htmlspecialchars($plugin['directory'] ?? '', ENT_QUOTES, 'UTF-8');
        $nameAttr  = htmlspecialchars($plugin['name'] ?? '', ENT_QUOTES, 'UTF-8');

        switch ($status) {
            case 'inactive':
                // Show Activate + Uninstall
                $actions[] = $this->actionButton('activate', 'Activate', $directory, $nameAttr);
                $actions[] = $this->actionButton('uninstall', 'Uninstall', $directory, $nameAttr);
                break;

            case 'active':
                // Show Deactivate
                $actions[] = $this->actionButton('deactivate', 'Deactivate', $directory, $nameAttr);
                break;

            case 'not_installed':
            default:
                // Show Install
                $actions[] = $this->actionButton('install', 'Install', $directory, $nameAttr);
                break;
        }

        return implode(' ', $actions);
    }

    /**
     * Helper to generate a form button for each action.
     * Submits to /admin/plugins/process via POST.
     */
    private function actionButton(string $action, string $label, string $directory, string $nameAttr): string
    {
        // Safely combine BASE_URL with the path /admin/plugins/process
        // (Avoid double slashes by trimming)
        $baseUrl   = rtrim(BASE_URL, '/');
        $routePath = '/admin/plugins/process';
        $actionUrl = $baseUrl . $routePath;

        return sprintf('
        <form method="POST" action="%s" style="display:inline;">
        <input type="hidden" name="action" value="%s">
        <input type="hidden" name="directory" value="%s">
        <button type="submit">%s</button>
        </form>
        ',
        htmlspecialchars($actionUrl),
                       htmlspecialchars($action),
                       htmlspecialchars($directory),
                       htmlspecialchars($label)
        );
    }


    /**
     * Processes plugin actions (Install, Uninstall, Activate, Deactivate).
     */
    public function processPluginAction(): void
    {
        $action    = $_POST['action']    ?? '';
        $directory = $_POST['directory'] ?? '';

        try {
            switch ($action) {
                case 'install':
                    $this->installPlugin($directory);
                    break;
                case 'uninstall':
                    $this->uninstallPlugin($directory);
                    break;
                case 'activate':
                    $this->activatePlugin($directory);
                    break;
                case 'deactivate':
                    $this->deactivatePlugin($directory);
                    break;
                default:
                    throw new \Exception("Unknown action: {$action}");
            }

            // Re-render the plugin list so the user sees updated statuses
            $this->renderPluginList();
        } catch (\Exception $e) {
            echo '<p class="error">Error processing plugin action: ' . htmlspecialchars($e->getMessage()) . '</p>';
            $this->renderPluginList();
        }
    }

    /**
     * =========================
     * INSTALL A PLUGIN
     * =========================
     * - Ensure plugin is currently not_installed
     * - Run install.php => install() if it exists
     * - Set status to 'inactive' in nfo.json
     */
    private function installPlugin(string $directory): void
    {
        // 1) Check current status to prevent re-install
        $status = $this->getPluginStatus($directory);
        if ($status !== 'not_installed') {
            throw new \Exception("Plugin {$directory} is not 'not_installed'; cannot install.");
        }

        // 2) Run install script if present
        $this->runInstallScript($directory, 'install');

        // 3) Update status to 'inactive'
        $this->updatePluginStatus($directory, 'inactive');
    }

    /**
     * =========================
     * UNINSTALL A PLUGIN
     * =========================
     * - Run install.php => uninstall() if exists
     * - Set status to 'not_installed'
     * - Remove plugin folder from disk
     */
    private function uninstallPlugin(string $directory): void
    {
        // 1) Check status (skip if already not_installed)
        $status = $this->getPluginStatus($directory);
        if ($status === 'not_installed') {
            throw new \Exception("Plugin {$directory} is already 'not_installed'.");
        }

        // 2) Run uninstall script
        $this->runInstallScript($directory, 'uninstall');

        // 3) Update status to 'not_installed'
        $this->updatePluginStatus($directory, 'not_installed');

        // 4) Remove plugin folder
        $this->removePluginFolder($directory);
    }

    /**
     * =========================
     * ACTIVATE A PLUGIN
     * =========================
     * - Ensure status is 'inactive'
     * - Set status to 'active'
     */
    private function activatePlugin(string $directory): void
    {
        $status = $this->getPluginStatus($directory);
        if ($status !== 'inactive') {
            throw new \Exception("Plugin {$directory} must be 'inactive' to activate.");
        }

        $this->updatePluginStatus($directory, 'active');
    }

    /**
     * =========================
     * DEACTIVATE A PLUGIN
     * =========================
     * - Ensure status is 'active'
     * - Set status to 'inactive'
     */
    private function deactivatePlugin(string $directory): void
    {
        $status = $this->getPluginStatus($directory);
        if ($status !== 'active') {
            throw new \Exception("Plugin {$directory} must be 'active' to deactivate.");
        }

        $this->updatePluginStatus($directory, 'inactive');
    }

    /**
     * Load and call install() or uninstall() from install.php if exists.
     */
    private function runInstallScript(string $directory, string $functionName): void
    {
        $pluginPath  = realpath(PLUGIN_DIRECTORY);
        $installFile = $pluginPath . '/' . $directory . '/install.php';

        if (!file_exists($installFile)) {
            // No install file => skip
            return;
        }

        // Load the file
        require_once $installFile;

        // If function is defined, call it
        if (function_exists($functionName)) {
            // Expose $db if needed for plugin's global $db;
            global $db;
            $db = $this->pluginManager->db; // if you made $db public or have a getter

            $functionName(); // call install() or uninstall()
        }
    }

    /**
     * Reads the plugin's nfo.json to get current status (active/inactive/not_installed).
     */
    private function getPluginStatus(string $directory): string
    {
        $pluginPath = realpath(PLUGIN_DIRECTORY);
        $nfoFile    = $pluginPath . '/' . $directory . '/nfo.json';

        if (!file_exists($nfoFile)) {
            return 'not_installed'; // default if no nfo.json
        }

        $data = json_decode(file_get_contents($nfoFile), true);
        return $data['status'] ?? 'not_installed';
    }

    /**
     * Updates the plugin's status in its nfo.json.
     */
    private function updatePluginStatus(string $directory, string $newStatus): void
    {
        $pluginPath = realpath(PLUGIN_DIRECTORY);
        $nfoFile    = $pluginPath . '/' . $directory . '/nfo.json';

        if (!file_exists($nfoFile)) {
            throw new \Exception("nfo.json not found for plugin directory: {$directory}");
        }

        $data = json_decode(file_get_contents($nfoFile), true);
        if (!is_array($data) || !isset($data['name'])) {
            throw new \Exception("Invalid nfo.json for plugin: {$directory}");
        }

        $data['status'] = $newStatus;
        file_put_contents($nfoFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Removes the plugin folder from the filesystem (destructive).
     */
    private function removePluginFolder(string $directory): void
    {
        $pluginPath  = realpath(PLUGIN_DIRECTORY);
        $targetPath  = $pluginPath . '/' . $directory;

        if (!is_dir($targetPath)) {
            return; // no folder
        }

        // Recursively delete
        $this->deleteDirectoryRecursive($targetPath);
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private function deleteDirectoryRecursive(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}


