<?php

declare(strict_types=1);

namespace Cms;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


class CmsManager
{
    private PDO $db;
    private string $baseUrl;

    /**
     * Constructor
     *
     * @param PDO $db       The database connection
     * @param string $baseUrl The base URL of the application (e.g., "https://example.com")
     */
    public function __construct(PDO $db, string $baseUrl)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Create a new CmsManager instance using default configuration.
     *
     * @param PDO $db
     * @return CmsManager
     */
    public static function createFromConfig(PDO $db): self
    {
        return new self($db, BASE_URL);
    }

    public function getSiteOption(string $optionName, $default = null)
    {
        $stmt = $this->db->prepare('SELECT option_value FROM site_options WHERE option_name = ? LIMIT 1');
        $stmt->execute([$optionName]);
        $option = $stmt->fetchColumn();

        return $option !== false ? $option : $default;
    }

    public function setSiteOption(string $optionName, $optionValue): bool
    {
        $stmt = $this->db->prepare('INSERT INTO site_options (option_name, option_value) VALUES (:name, :value)
                                    ON DUPLICATE KEY UPDATE option_value = :value');
        return $stmt->execute([':name' => $optionName, ':value' => $optionValue]);
    }

    public function isErrorLoggingEnabled(): bool
    {
        return $this->getSiteOption('error_logging_enabled', '0') === '1';
    }

    public function logError(string $message): void
    {
        if ($this->isErrorLoggingEnabled()) {
            error_log($message);
        }
    }
	
	public function getActiveTemplate(): string
{
    return $this->getSiteOption('active_template', 'default');
}


    public function baseUrl(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function redirect(string $url, int $statusCode = 302): void
    {
        header('Location: ' . $url, true, $statusCode);
        exit();
    }

    public function escape(?string $string): string
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }

    public function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function debug(mixed $data): void
    {
        if (defined('DEBUG') && DEBUG) {
            echo '<pre>' . print_r($data, true) . '</pre>';
        }
    }

    /**
     * Log a CMS event into the cms_logs table.
     */
public function logCmsEvent(string $type, string $message): void
{
    try {
        // Fetch the enabled log types from site_options
        $stmt = $this->db->prepare("SELECT option_value FROM site_options WHERE option_name = 'cms_log_types' LIMIT 1");
        $stmt->execute();
        $enabledLogTypes = $stmt->fetchColumn();

        // Parse the enabled log types or use defaults
        $logTypes = $enabledLogTypes ? explode(',', $enabledLogTypes) : ['error', 'info', 'warning'];

        // Check if the log type is enabled
        if (!in_array($type, $logTypes, true)) {
            return; // Skip logging if the type is not enabled
        }

        // Proceed to log the event
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $referrer = $_SERVER['HTTP_REFERER'] ?? 'direct';

        $stmt = $this->db->prepare("INSERT INTO cms_logs (type, message, ip_address, referrer, timestamp) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$type, $message, $ipAddress, $referrer]);
    } catch (\Throwable $e) {
        // Log to the PHP error log if the CMS logging fails
        error_log("Error logging CMS event: " . $e->getMessage());
    }
}




    /**
     * Send an email using a specified template.
     *
     * @param string $template The name of the email template (without extension).
     * @param array $data Data to populate in the template.
     * @param array $recipients Array of recipients. Each recipient should have 'email' and optionally 'name'.
     * @param array $options Additional options like subject, CC, BCC, etc.
     * @return bool True on success, false on failure.
     */
    public function sendEmail(string $template, array $data, array $recipients, array $options = []): bool
    {
        // Email configurations
        $smtpHost = SMTP_HOST;
        $smtpPort = SMTP_PORT;
        $smtpUser = SMTP_USER;
        $smtpPassword = SMTP_PASSWORD;
        $fromEmail = FROM_EMAIL;
        $fromName = FROM_NAME;

        // Directory paths
        $templatesDir = __DIR__ . '/email-templates/';
        $htmlTemplatePath = $templatesDir . $template . '.html';
        $textTemplatePath = $templatesDir . $template . '.txt';

        try {
            if (!file_exists($htmlTemplatePath)) {
                throw new Exception("HTML template file for '{$template}' not found at {$htmlTemplatePath}");
            }

            // Load templates
            $htmlBody = file_get_contents($htmlTemplatePath);
            $textBody = file_exists($textTemplatePath) ? file_get_contents($textTemplatePath) : strip_tags($htmlBody);

            // Replace placeholders
            foreach ($data as $key => $value) {
                $htmlBody = str_replace("{{{$key}}}", $value, $htmlBody);
                $textBody = str_replace("{{{$key}}}", $value, $textBody);
            }

            // Initialize PHPMailer
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $smtpHost;
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpUser;
            $mailer->Password = $smtpPassword;
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port = $smtpPort;

            // Set sender information
            $mailer->setFrom($fromEmail, $fromName);

            // Add recipients
            foreach ($recipients as $recipient) {
                $email = $recipient['email'] ?? null;
                $name = $recipient['name'] ?? '';
                if ($email) {
                    $mailer->addAddress($email, $name);
                }
            }

            // Add optional CC and BCC
            if (!empty($options['cc'])) {
                foreach ($options['cc'] as $ccEmail) {
                    $mailer->addCC($ccEmail);
                }
            }
            if (!empty($options['bcc'])) {
                foreach ($options['bcc'] as $bccEmail) {
                    $mailer->addBCC($bccEmail);
                }
            }

            // Set email content
            $mailer->isHTML(true);
            $mailer->Subject = $options['subject'] ?? 'No Subject';
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $textBody;

            // Send email
            return $mailer->send();
        } catch (Exception $e) {
            $this->logError("Email Error: " . $e->getMessage());
            return false;
        }
    }
	
	
    
public static function cmshelp(): void
{
    $manager = $_GET['manager'] ?? '';
    $manager = htmlspecialchars($manager, ENT_QUOTES, 'UTF-8'); // Sanitize the input for safe output

    $helpDirectory = __DIR__ . '/../Help/';
    $helpFiles = glob($helpDirectory . '*.json');

    echo "<div class='site-main'>";
    echo "<h1>L1QUID.CORE CMS Help</h1>";

    // Sidebar index of help topics
    echo "<aside class='help-sidebar'>";
    echo "<ul>";
    if ($helpFiles) {
        foreach ($helpFiles as $helpFile) {
            $fileName = basename($helpFile, '.json');
            $link = htmlspecialchars("?manager=" . urlencode($fileName)); // Escape URLs
            echo "<li><a href='{$link}'>" . ucfirst(htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8')) . "</a></li>";
        }
    } else {
        echo "<li>No help files found.</li>";
    }
    echo "</ul>";
    echo "</aside>";

    // Main content section
    echo "<div class='help-content'>";
    if ($manager) {
        $managerClass = strtolower($manager);
        $filePath = $helpDirectory . "{$managerClass}.json";

        if (file_exists($filePath)) {
            $helpContent = json_decode(file_get_contents($filePath), true);

            if ($helpContent) {
                echo "<h2>" . htmlspecialchars($helpContent['title'], ENT_QUOTES, 'UTF-8') . "</h2>";
                echo "<p>" . htmlspecialchars($helpContent['description'], ENT_QUOTES, 'UTF-8') . "</p>";

                if (!empty($helpContent['sections'])) {
                    foreach ($helpContent['sections'] as $section) {
                        echo "<h3>" . htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') . "</h3>";
                        echo "<pre><code>" . htmlspecialchars($section['content'], ENT_QUOTES, 'UTF-8') . "</code></pre>";
                    }
                }

                if (!empty($helpContent['notes'])) {
                    echo "<h3>Notes</h3>";
                    echo "<p>" . htmlspecialchars($helpContent['notes'], ENT_QUOTES, 'UTF-8') . "</p>";
                }
            } else {
                echo "<p>The help file for '{$managerClass}' could not be loaded.</p>";
            }
        } else {
            echo "<p>Help file for '{$managerClass}' not found.</p>";
        }
    } else {
        // Default help content if no manager is specified
        echo "<h2>Welcome to the L1QUID.CORE CMS Help System</h2>";
        echo "<p>Select a topic from the Help Index to get started.</p>";
    }
    echo "</div>"; // End of help-content

    echo "</div>"; // End of site-main
}


}

?>
