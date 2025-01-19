<?php

namespace Admin\Cogs;

use PDO;

class MenuManagerEditor
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get route details for the Menu Manager Editor.
     */
    public function getRoute(): array
    {
        return [
            'path' => '/admin/menus',
            'callback' => 'render',
            'name' => 'Menu Editor',
        ];
    }

    /**
     * Render the Menu Manager Editor interface.
     */
    public function render(): void
    {
        echo '<div class="site-main">';
        echo '<h1>Menu Manager</h1>';

        try {
            // Handle form submissions
            $this->handleFormSubmission();

            // Retrieve all menu items
            $menus = $this->getMenus();

            // Display the menu table
            echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
            echo '<thead><tr><th>ID</th><th>Name</th><th>Path</th><th>Type</th><th>Actions</th></tr></thead>';
            echo '<tbody>';

            foreach ($menus as $menu) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($menu['id']) . '</td>';
                echo '<td>' . htmlspecialchars($menu['name']) . '</td>';
                echo '<td>' . htmlspecialchars($menu['path']) . '</td>';
                echo '<td>' . htmlspecialchars($menu['menu_type']) . '</td>';
                echo '<td>';
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="id" value="' . htmlspecialchars($menu['id']) . '">';
                echo '<button type="submit" name="delete" onclick="return confirm(\'Are you sure?\');">Delete</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

            // Render Add Form
            $this->renderAddForm();
        } catch (\Exception $e) {
            echo '<p class="error">Error loading menus: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Handle form submissions (add, delete).
     */
    private function handleFormSubmission(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['delete'])) {
                $this->deleteMenu((int)$_POST['id']);
            } elseif (isset($_POST['add'])) {
                $this->addMenu($_POST);
            }
        }
    }

    /**
     * Retrieve all menu items.
     */
    private function getMenus(): array
    {
        $stmt = $this->db->query("SELECT * FROM cms_menu ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add a new menu item.
     */
    private function addMenu(array $menuData): void
    {
        $sql = "INSERT INTO cms_menu (name, path, menu_type, callback, target_url, access_level, visible_in_menu, open_in_new_window, template_location, sort_order, status, created_at)
        VALUES (:name, :path, :menu_type, :callback, :target_url, :access_level, :visible_in_menu, :open_in_new_window, :template_location, :sort_order, :status, NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $menuData['name'] ?? '',
            ':path' => $menuData['path'] ?? '',
            ':menu_type' => $menuData['menu_type'] ?? '',
            ':callback' => $menuData['menu_type'] === 'route' ? $menuData['callback'] ?? null : null,
            ':target_url' => $menuData['menu_type'] === 'external' ? $menuData['target_url'] ?? null : null,
            ':access_level' => $menuData['access_level'] ?? 'public',
            ':visible_in_menu' => $menuData['visible_in_menu'] ?? 1,
            ':open_in_new_window' => $menuData['menu_type'] === 'external' ? ($menuData['open_in_new_window'] ?? 0) : 0,
                       ':template_location' => $menuData['template_location'] ?? 'sidebar',
                       ':sort_order' => $menuData['sort_order'] ?? 0,
                       ':status' => $menuData['status'] ?? 'active',
        ]);
    }

    /**
     * Delete a menu item by ID.
     */
    private function deleteMenu(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM cms_menu WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Render the Add Menu Item form.
     */
    private function renderAddForm(): void
    {
        $menuTypes = ['route' => 'Route', 'external' => 'External', 'slug' => 'Slug', 'folder' => 'Folder'];
        $accessLevels = ['public' => 'Public', 'registered' => 'Registered', 'editor' => 'Editor', 'admin' => 'Admin'];

        echo '<h2>Add Menu Item</h2>';
        echo '<form method="post">';
        echo '<label>Name: <input type="text" name="name" required></label><br>';
        echo '<label>Path: <input type="text" name="path" required></label><br>';
        echo '<label>Menu Type: <select name="menu_type" id="menu_type" onchange="toggleMenuTypeFields()" required>';
        foreach ($menuTypes as $key => $label) {
            echo "<option value='{$key}'>{$label}</option>";
        }
        echo '</select></label><br>';

        echo '<div id="route_fields" style="display:none;">';
        echo '<label>Callback: <input type="text" name="callback"></label><br>';
        echo '</div>';

        echo '<div id="external_fields" style="display:none;">';
        echo '<label>Target URL: <input type="url" name="target_url"></label><br>';
        echo '<label>Open in New Window: <input type="checkbox" name="open_in_new_window" value="1"></label><br>';
        echo '</div>';

        echo '<label>Access Level: <select name="access_level">';
        foreach ($accessLevels as $key => $label) {
            echo "<option value='{$key}'>{$label}</option>";
        }
        echo '</select></label><br>';

        echo '<label>Visible in Menu: <input type="checkbox" name="visible_in_menu" value="1" checked></label><br>';
        echo '<label>Sort Order: <input type="number" name="sort_order" min="0" value="0"></label><br>';
        echo '<label>Template Location: <input type="text" name="template_location" value="sidebar"></label><br>';
        echo '<button type="submit" name="add">Add Menu</button>';
        echo '</form>';

        // JavaScript for toggling fields
        echo '<script>
        function toggleMenuTypeFields() {
        const menuType = document.getElementById("menu_type").value;
        document.getElementById("route_fields").style.display = menuType === "route" ? "block" : "none";
        document.getElementById("external_fields").style.display = menuType === "external" ? "block" : "none";
    }
    toggleMenuTypeFields(); // Initial call
    </script>';
    }
}
