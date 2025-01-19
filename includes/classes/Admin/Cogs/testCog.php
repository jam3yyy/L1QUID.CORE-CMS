<?php

namespace Admin\Cogs;

class TestCog {
    public function getRoute(): array {
        return [
            'path' => '/admin/test-cog',
            'callback' => 'render',
            'name' => 'Test Cog',
        ];
    }

    public function render(): void {
        echo '<div class="site-main">';
        echo '<h1>Test Cog</h1>';
        echo '<p>This is a test cog for debugging the dashboard.</p>';
        echo '</div>';
    }
}
?>
