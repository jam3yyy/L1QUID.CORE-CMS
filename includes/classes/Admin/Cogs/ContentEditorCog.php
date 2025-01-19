<?php

namespace Admin\Cogs;

use PDO;

class ContentEditorCog
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get route details for the Content Editor.
     */
    public function getRoute(): array
    {
        return [
            'path' => '/admin/content-editor',
            'callback' => 'render',
            'name' => 'Content Editor',
        ];
    }

    /**
     * Render the Content Editor interface.
     */
    public function render(): void
    {
        echo '<div class="site-main">';
        echo '<h1>Content Editor</h1>';

        try {
            // Handle form submissions
            $this->handleFormSubmission();

            // Retrieve all content entries
            $contentEntries = $this->getContentEntries();

            // Display the content table
            echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
            echo '<thead><tr><th>ID</th><th>Title</th><th>Slug</th><th>Status</th><th>Actions</th></tr></thead>';
            echo '<tbody>';

            foreach ($contentEntries as $entry) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($entry['id']) . '</td>';
                echo '<td>' . htmlspecialchars($entry['title']) . '</td>';
                echo '<td>' . htmlspecialchars($entry['slug']) . '</td>';
                echo '<td>' . htmlspecialchars($entry['status']) . '</td>';
                echo '<td>';
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="id" value="' . htmlspecialchars($entry['id']) . '">';
                echo '<button type="submit" name="delete" onclick="return confirm(\'Are you sure?\');">Delete</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

            // Render Add/Edit Form
            $this->renderEditorForm();
        } catch (\Exception $e) {
            echo '<p class="error">Error loading content entries: ' . htmlspecialchars($e->getMessage()) . '</p>';
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
                $this->deleteContent((int)$_POST['id']);
            } elseif (isset($_POST['save'])) {
                $this->saveContent($_POST);
            }
        }
    }

    /**
     * Retrieve all content entries.
     */
    private function getContentEntries(): array
    {
        $stmt = $this->db->query("SELECT id, title, slug, status FROM content ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Save a new or edited content entry.
     */
    /**
     * Save a new or edited content entry.
     */
    /**
     * Save a new or edited content entry.
     */
    private function saveContent(array $contentData): void
    {
        if (empty($contentData['id'])) {
            // Add new content
            $sql = "INSERT INTO content (title, slug, body, meta_title, meta_description, tags, source_link, access_level, status, created_at, updated_at)
            VALUES (:title, :slug, :body, :meta_title, :meta_description, :tags, :source_link, :access_level, :status, NOW(), NOW())";
        } else {
            // Update existing content
            $sql = "UPDATE content
            SET title = :title,
            slug = :slug,
            body = :body,
            meta_title = :meta_title,
            meta_description = :meta_description,
            tags = :tags,
            source_link = :source_link,
            access_level = :access_level,
            status = :status,
            updated_at = NOW()
            WHERE id = :id";
        }

        $stmt = $this->db->prepare($sql);

        // Bind parameters
        $params = [
            ':title' => $contentData['title'] ?? '',
            ':slug' => $contentData['slug'] ?? '',
            ':body' => $contentData['body'] ?? '',
            ':meta_title' => $contentData['meta_title'] ?? '',
            ':meta_description' => $contentData['meta_description'] ?? '',
            ':tags' => $contentData['tags'] ?? '',
            ':source_link' => $contentData['source_link'] ?? '',
            ':access_level' => $contentData['access_level'] ?? 'public',
            ':status' => $contentData['status'] ?? 'draft',
        ];

        // Include `:id` only if updating an existing entry
        if (!empty($contentData['id'])) {
            $params[':id'] = $contentData['id'];
        }

        // Execute the query with parameters
        $stmt->execute($params);
    }



    /**
     * Delete a content entry by ID.
     */
    private function deleteContent(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM content WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Render the Add/Edit Content Form.
     */
    private function renderEditorForm(): void
    {
        echo '<h2>Add/Edit Content</h2>';
        echo '<form method="post">';
        echo '<label>Title: <input type="text" name="title" required></label><br>';
        echo '<label>Slug: <input type="text" name="slug" required></label><br>';
        echo '<label>Meta Title: <input type="text" name="meta_title"></label><br>';
        echo '<label>Meta Description: <input type="text" name="meta_description"></label><br>';
        echo '<label>Tags: <input type="text" name="tags"></label><br>';
        echo '<label>Source Link: <input type="url" name="source_link"></label><br>';
        echo '<label>Access Level: <select name="access_level">
        <option value="public">Public</option>
        <option value="registered">Registered</option>
        <option value="editor">Editor</option>
        <option value="admin">Admin</option>
        </select></label><br>';
        echo '<label>Status: <select name="status">
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        </select></label><br>';
        echo '<textarea id="content-editor" name="body"></textarea><br>';
        echo '<button type="submit" name="save">Save</button>';
        echo '</form>';

        // Include TinyMCE script
        echo '<script src="/vendor/tinymce/tinymce/tinymce.min.js"></script>';
        echo '<script>
        tinymce.init({
        selector: "#content-editor",
        height: 500,
        plugins: "link image media code",
        toolbar: "undo redo | formatselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media | code",
    });
    </script>';
    }
}
