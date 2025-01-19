<?php

declare(strict_types=1);

namespace Cms;

use PDO;

class NavigationManager
{
    private PDO $db;
    private CmsManager $cmsManager;
    private UserManager $userManager;

    public function __construct(PDO $db, CmsManager $cmsManager, UserManager $userManager)
    {
        $this->db = $db;
        $this->cmsManager = $cmsManager;
        $this->userManager = $userManager;
    }

    /**
     * Check if a user has access level. 
     * 
     * This is a placeholder. Adjust logic as needed.
     * 
     * @param string $required The required access level
     * @param string $userAccessLevel The user's access level
     * @return bool True if the user has the required access
     */
    private function hasAccessLevel(string $required, string $userAccessLevel): bool
    {
        // This is a simple placeholder logic:
        $levels = [
            'public' => 1,
            'registered' => 2,
            'editor' => 3,
            'admin' => 4
        ];

        return ($levels[$userAccessLevel] ?? 0) >= ($levels[$required] ?? 0);
    }

    /**
     * Render the admin menu.
     */
    public function renderAdminMenu(array $menuItems, string $userAccessLevel = 'public'): void
    {
        echo "<ul class='admin-menu'>";
        foreach ($menuItems as $item) {
            if ($this->hasAccessLevel($item['access_level'], $userAccessLevel) && $item['visible_in_menu']) {
                $link = $this->determineMenuLink($item);

                // Determine if the link should open in a new window
                $targetAttribute = !empty($item['open_in_new_window']) ? ' target="_blank" rel="noopener noreferrer"' : '';

                echo "<li><a href='" . $this->cmsManager->escape($link) . "'" . $targetAttribute . ">" . $this->cmsManager->escape($item['name']) . "</a></li>";
            }
        }
        echo "</ul>";
    }

    /**
     * Render a generic menu for a given location.
     */
    public function renderMenu(string $location): void
    {
        // Get the current logged-in user
        $currentUser = $this->userManager->getCurrentUser();

        // Determine the user's access level
        $userAccessLevel = $currentUser['access_level'] ?? 'public';
        $accessLevels = $this->determineAccessLevels($userAccessLevel);

        // Check if registration is allowed from site options
        $allowRegistration = (bool) $this->cmsManager->getSiteOption('allow_registration', true);

        // Check if the user is logged in
        $isLoggedIn = $currentUser !== null;

        // Use BASE_URL to prefix all routes
        $loginLogoutLink = $isLoggedIn
        ? BASE_URL . 'logout'
        : BASE_URL . 'login';

        $registerLink = ($allowRegistration && !$isLoggedIn)
        ? BASE_URL . 'register'
        : '';


        // Fetch menu items for the specified location and access level
        $placeholders = implode(',', array_fill(0, count($accessLevels), '?'));
        $sql = "
            SELECT id, name, path, menu_type, access_level, visible_in_menu, open_in_new_window, sort_order
            FROM cms_menu
            WHERE template_location = ? 
            AND access_level IN ($placeholders) 
            AND visible_in_menu = 1
            ORDER BY sort_order ASC
        ";
        $stmt = $this->db->prepare($sql);
        $params = array_merge([$location], $accessLevels);
        $stmt->execute($params);
        $menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build and render the menu tree
        $menuTree = $this->buildMenuTree($menuItems);
        $menuClass = $location === 'sidebar' ? 'sidebar-menu' : 'main-menu';

        // Get the current page URL to compare with menu items
        $currentUri = $_SERVER['REQUEST_URI'];

        // Render the menu
        echo '<ul class="' . $this->cmsManager->escape($menuClass) . '">';

        // Render the rest of the menu and add active class
        foreach ($menuTree as $menuItem) {
            $link = $this->determineMenuLink($menuItem);
            $itemName = $menuItem['name'];
            $isActive = ($currentUri === parse_url($link, PHP_URL_PATH)) ? 'active' : '';

            echo '<li class="' . $this->cmsManager->escape($isActive) . '">';
            if ($menuItem['menu_type'] === 'folder') {
                echo '<span class="menu-folder">' . $this->cmsManager->escape($itemName) . '</span>';
            } else {
                echo '<a href="' . $this->cmsManager->escape($link) . '">' . $this->cmsManager->escape($itemName) . '</a>';
            }

            // Render nested children if they exist
            if (!empty($menuItem['children'])) {
                echo '<ul class="submenu">';
                $this->renderMenuTree($menuItem['children'], $currentUri);
                echo '</ul>';
            }

            echo '</li>';
        }
        // Add login/logout link to the menu
        echo '<li class="' . ($isLoggedIn ? 'active' : '') . '">';
        echo '<a href="' . $this->cmsManager->escape($loginLogoutLink) . '">' . ($isLoggedIn ? 'logout' : 'login') . '</a>';
        echo '</li>';

        // Add registration link if allowed and not logged in
        if ($registerLink) {
            echo '<li class="' . ($currentUri === $registerLink ? 'active' : '') . '">';
            echo '<a href="' . $this->cmsManager->escape($registerLink) . '">register</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * Build a hierarchical tree of menu items based on paths.
     */
    public function buildMenuTree(array $menuItems): array
    {
        $tree = [];
        $lookup = [];

        // Initialize the lookup array with menu items
        foreach ($menuItems as $menuItem) {
            $key = $menuItem['path'] ?? '';
            $lookup[$key] = $menuItem;
            $lookup[$key]['children'] = [];
        }

        // Assign children to their parent items based on paths
        foreach ($menuItems as $menuItem) {
            if (!empty($menuItem['path'])) {
                $parentPath = dirname(rtrim($menuItem['path'], '/')); // Get the parent path

                if ($parentPath !== '/' && isset($lookup[$parentPath])) {
                    $lookup[$parentPath]['children'][] = &$lookup[$menuItem['path']];
                } else {
                    $tree[] = &$lookup[$menuItem['path']];
                }
            }
        }

        return $tree;
    }

    /**
     * Render the hierarchical menu tree.
     */
    public function renderMenuTree(array $menuTree, string $currentUri = null): void
    {
        foreach ($menuTree as $menuItem) {
            $link = $this->determineMenuLink($menuItem);
            $itemName = $menuItem['name'];

            // Check if the current item or any of its children is active
            $isActive = ($_SERVER['REQUEST_URI'] === parse_url($link, PHP_URL_PATH)) ||
                        $this->isChildActive($menuItem, $currentUri);
            $activeClass = $isActive ? 'active' : '';

            // Determine if the link should open in a new window
            $targetAttribute = !empty($menuItem['open_in_new_window']) ? ' target="_blank" rel="noopener noreferrer"' : '';

            // Start the list item
            echo '<li class="' . (!empty($menuItem['children']) ? 'has-children ' : '') . $this->cmsManager->escape($activeClass) . '">';

            // Render folder type or clickable menu item
            if ($menuItem['menu_type'] === 'folder') {
                echo '<span class="menu-folder">' . $this->cmsManager->escape($itemName) . '</span>';
            } else {
                echo '<a href="' . $this->cmsManager->escape($link) . '" class="' . $this->cmsManager->escape($activeClass) . '"' . $targetAttribute . '>' . $this->cmsManager->escape($itemName) . '</a>';
            }

            // Render nested children if they exist
            if (!empty($menuItem['children'])) {
                echo '<ul class="submenu">';
                $this->renderMenuTree($menuItem['children'], $currentUri);
                echo '</ul>';
            }

            // Close the list item
            echo '</li>';
        }
    }

    /**
     * Check if any child menu items are active.
     */
    private function isChildActive(array $menuItem, ?string $currentUri): bool
    {
        if (!empty($menuItem['children'])) {
            foreach ($menuItem['children'] as $child) {
                $childLink = $this->determineMenuLink($child);

                if ($currentUri === parse_url($childLink, PHP_URL_PATH)) {
                    return true;
                }

                if ($this->isChildActive($child, $currentUri)) {
                    return true;
                }
            }
        }
        return false;
    }
    public function getMenus(): array {
        $stmt = $this->db->query("SELECT id, name, path, menu_type, access_level FROM cms_menu ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Determine the link for a menu item based on its type.
     */
    public function determineMenuLink(array $menuItem): string
    {
        if (empty($menuItem['menu_type'])) {
            throw new \InvalidArgumentException("Missing 'menu_type' for menu item.");
        }

        switch ($menuItem['menu_type']) {
            case 'external':
                if (!empty($menuItem['path']) && filter_var($menuItem['path'], FILTER_VALIDATE_URL)) {
                    return $menuItem['path'];
                }
                throw new \InvalidArgumentException("Invalid URL for external menu item: " . htmlspecialchars($menuItem['path']));

            case 'slug':
                if (!empty($menuItem['path'])) {
                    return $this->cmsManager->baseUrl('/' . ltrim($menuItem['path'], '/'));
                }
                throw new \InvalidArgumentException("Missing or invalid slug for menu item.");

            case 'route':
                if (!empty($menuItem['path'])) {
                    return $this->cmsManager->baseUrl($menuItem['path']);
                }
                throw new \InvalidArgumentException("Missing or invalid route path for menu item.");

            case 'folder':
                return '#'; // Non-clickable folder

            default:
                throw new \InvalidArgumentException("Unknown menu type: " . htmlspecialchars($menuItem['menu_type']));
        }
    }

    /**
     * Determine access levels based on user role.
     */
    private function determineAccessLevels(string $userAccessLevel): array
    {
        $accessLevels = ['public'];

        if (in_array($userAccessLevel, ['registered', 'editor', 'admin'])) {
            $accessLevels[] = 'registered';
        }
        if (in_array($userAccessLevel, ['editor', 'admin'])) {
            $accessLevels[] = 'editor';
        }
        if ($userAccessLevel === 'admin') {
            $accessLevels[] = 'admin';
        }

        return $accessLevels;
    }

    /**
     * Generate breadcrumbs.
     */
    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [['name' => 'home', 'link' => $this->cmsManager->baseUrl()]];
        $currentPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

        if ($currentPath) {
            $segments = explode('/', $currentPath);
            $currentLink = $this->cmsManager->baseUrl();

            foreach ($segments as $segment) {
                $currentLink .= $segment . '/';
                $breadcrumbs[] = ['name' => $segment, 'link' => $currentLink];
            }
        }

        return $breadcrumbs;
    }
}
