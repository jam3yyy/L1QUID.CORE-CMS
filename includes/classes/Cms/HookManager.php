<?php

namespace Cms;

use PDO;

class HookManager
{
    private array $hooks = [];
    private CmsManager $cmsManager;
    private SecurityManager $securityManager;
    private PDO $db;

    public function __construct(CmsManager $cmsManager, SecurityManager $securityManager, PDO $db)
    {
        $this->cmsManager = $cmsManager;
        $this->securityManager = $securityManager;
        $this->db = $db;
    }

    /**
     * Register a hook to make it available in the system.
     *
     * @param string $name The name of the hook.
     * @param callable $callback The callback function for the hook.
     * @param int $priority The priority of the hook (lower value means higher priority).
     * @param string $description An optional description for the hook.
     * @param array $options Optional metadata or configuration for the hook.
     */
    public function registerHook(string $name, callable $callback, int $priority = 10, string $description = '', array $options = []): void
    {
        if (!isset($options['location'])) {
            throw new \InvalidArgumentException("Missing 'location' in hook options for '{$name}'");
        }

        if (!is_callable($callback)) {
            $this->cmsManager->logCmsEvent('error', "Callback for hook '{$name}' is not callable.");
            throw new \TypeError("Callback for hook '{$name}' is not callable.");
        }

        $uniqueId = bin2hex(random_bytes(8)); // Generate a unique ID for this hook

        $this->hooks[$name][] = [
            'id' => $uniqueId,
            'callback' => $callback,
            'priority' => $priority,
            'description' => $description,
            'options' => $options,
        ];

        // Sort hooks by priority
        usort($this->hooks[$name], function ($a, $b) {
            return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
        });

        $this->cmsManager->logCmsEvent('debug', "Registered hook '{$name}' at location '{$options['location']}' with priority {$priority}");
    }

    /**
     * Execute all active hooks for a specific location and page.
     *
     * @param string $location The location where the hook is triggered.
     * @param string|null $currentRoute The current page (optional).
     */
   public function executeHooks(string $location, ?string $currentRoute = null): void
{
    if (empty($this->hooks)) {
        $this->cmsManager->logCmsEvent('info', "No hooks registered for location: {$location}");
        return;
    }

    $executed = false;

    foreach ($this->hooks as $hookName => $hookDetailsList) {
        foreach ($hookDetailsList as $hookDetails) {
            // Match hook location
            if (($hookDetails['options']['location'] ?? '') !== $location) {
                continue;
            }

            try {
                // Prepare context for the callback
                $context = [
                    'id' => $hookDetails['id'],
                    'location' => $location,
                    'currentRoute' => $currentRoute,
                    'options' => $hookDetails['options'],
                ];

                // Pass additional dependencies
                $args = [$this->db, $this->cmsManager, $this->securityManager, $context];

                // Execute the callback
                call_user_func_array($hookDetails['callback'], $args);

                $executed = true;
            } catch (\Throwable $e) {
                $this->cmsManager->logCmsEvent(
                    'error',
                    "Error executing hook '{$hookName}' at '{$location}': " . $e->getMessage()
                );
            }
        }
    }

    if (!$executed) {
        $this->cmsManager->logCmsEvent('info', "No hooks executed for location: {$location}");
    }
}


    /**
     * Unregister a hook by its name or specific ID.
     *
     * @param string $name The name of the hook.
     * @param string|null $id The unique ID of the specific hook to unregister.
     */
    public function unregisterHook(string $name, ?string $id = null): void
    {
        if (!isset($this->hooks[$name])) {
            $this->cmsManager->logCmsEvent('warning', "Attempted to unregister non-existent hook '{$name}'.");
            return;
        }

        if ($id === null) {
            unset($this->hooks[$name]);
            $this->cmsManager->logCmsEvent('info', "All hooks with name '{$name}' unregistered.");
        } else {
            $this->hooks[$name] = array_filter($this->hooks[$name], fn($hook) => $hook['id'] !== $id);
            $this->cmsManager->logCmsEvent('info', "Hook '{$name}' with ID '{$id}' unregistered.");
        }
    }

    /**
     * Get all registered hooks.
     *
     * @return array List of registered hook names with their IDs and descriptions.
     */
    public function getRegisteredHooks(): array
    {
        $result = [];
        foreach ($this->hooks as $name => $hooks) {
            foreach ($hooks as $hook) {
                $result[] = [
                    'name' => $name,
                    'id' => $hook['id'],
                    'description' => $hook['description'],
                    'priority' => $hook['priority'],
                ];
            }
        }
        return $result;
    }

    /**
     * Clear all registered hooks.
     */
    public function clearHooks(): void
    {
        $this->hooks = [];
        $this->cmsManager->logCmsEvent('info', 'All hooks have been cleared.');
    }

    /**
     * Export all hooks for debugging or external use.
     */
    public function exportHooks(): array
    {
        return $this->hooks;
    }
}
