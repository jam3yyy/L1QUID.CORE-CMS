<?php

declare(strict_types=1);

namespace Cms;

use PDO;
use Exception;

class UserManager
{
    private PDO $db;
    private CmsManager $cmsManager;
    private ?array $currentUser = null;
    private array $ignoredColumns = ['id', 'password', 'verification_token', 'reverification_token', 'reset_token','soft_deleted_at','two_factor_secret','status','failed_logins','password_updated_at','reset_requested_at'];

    public function __construct(PDO $db, CmsManager $cmsManager)
    {
        $this->db = $db;
        $this->cmsManager = $cmsManager;
    }

    // ------------------- CONFIGURATION HELPERS -------------------

    public function getSiteOption(string $key, $default = null)
    {
        try {
            $stmt = $this->db->prepare('SELECT option_value FROM site_options WHERE option_name = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (Exception $e) {
            $this->logError("Error fetching site option '$key': " . $e->getMessage());
            return $default;
        }
    }

    // ------------------- PASSWORD MANAGEMENT -------------------

    public function validatePasswordStrength(string $password): bool
    {
        $minLength = (int)$this->getSiteOption('password_min_length', 8);
        $requireUppercase = (bool)$this->getSiteOption('password_require_uppercase', true);
        $requireNumber = (bool)$this->getSiteOption('password_require_number', true);
        $requireSpecial = (bool)$this->getSiteOption('password_require_special', true);

        $regex = '/^';
        $regex .= $requireUppercase ? '(?=.*[A-Z])' : '';
        $regex .= $requireNumber ? '(?=.*\d)' : '';
        $regex .= $requireSpecial ? '(?=.*[@$!%*?&#])' : '';
        $regex .= ".{{$minLength},}$/";

        return preg_match($regex, $password) === 1;
    }

    public function updatePassword(int $userId, string $newPassword): bool
    {
        if (!$this->validatePasswordStrength($newPassword)) {
            throw new Exception('Password does not meet strength requirements.');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->updateUser($userId, [
            'password' => $passwordHash,
            'password_updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    // ------------------- USER CRUD OPERATIONS -------------------

    public function addUser(string $username, string $email, string $password, string $accessLevel = 'public'): bool
    {
        if (!$this->validatePasswordStrength($password)) {
            throw new Exception('Password does not meet strength requirements.');
        }

        $existingUserByUsername = $this->getAttributes('username', $username);
        $existingUserByEmail = $this->getAttributes('email', $email);

        if ($existingUserByUsername) {
            throw new Exception('Username is already taken.');
        }
        if ($existingUserByEmail) {
            throw new Exception('Email is already taken.');
        }

        $verificationToken = bin2hex(random_bytes(32));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $status = 'unverified';

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, password, access_level, status, verification_token, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $result = $stmt->execute([$username, $email, $passwordHash, $accessLevel, $status, $verificationToken]);

            if ($result) {
                $verificationLink = $this->cmsManager->baseUrl("/verify?token={$verificationToken}");
                $recipients = [['email' => $email, 'name' => $username]];
                $emailSent = sendEmail('verification', ['verificationLink' => $verificationLink], $recipients, ['subject' => 'Verify Your Email']);

                if (!$emailSent) {
                    throw new Exception("Failed to send verification email.");
                }

                return true;
            }
            return false;
        } catch (Exception $e) {
            $this->logError("Error adding user: " . $e->getMessage());
            throw new Exception("Failed to add user.");
        }
    }

    public function deleteUser(int $userId): bool
    {
        if ($userId === 1) return false;

        try {
            $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            $this->logError("Error deleting user ID $userId: " . $e->getMessage());
            return false;
        }
    }

    public function updateUser(int $userId, array $userData): bool
    {
        try {
            $columns = implode(', ', array_map(fn($key) => "`$key` = ?", array_keys($userData)));
            $values = array_values($userData);
            $values[] = $userId;

            $stmt = $this->db->prepare("UPDATE users SET {$columns} WHERE id = ?");
            return $stmt->execute($values);
        } catch (Exception $e) {
            $this->logError("Error updating user ID $userId: " . $e->getMessage());
            return false;
        }
    }

    public function getAttributes(string $filterColumn, $filterValue, array $attributes = ['*']): ?array
    {
        try {
            $columns = ($attributes === ['*']) ? '*' : implode(', ', array_map(fn($attr) => "`$attr`", $attributes));
            $stmt = $this->db->prepare("SELECT {$columns} FROM users WHERE `{$filterColumn}` = ?");
            $stmt->execute([$filterValue]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            $this->logError("Error fetching attributes: " . $e->getMessage());
            return null;
        }
    }

    // ------------------- LOGIN/LOGOUT MANAGEMENT -------------------
    public function login(string $usernameOrEmail, string $password): void
{
    try {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1');
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            throw new Exception('Invalid username/email or password.');
        }

        if ($user['status'] !== 'verified') {
            throw new Exception('Account is not verified. Please check your email.');
        }

        // Update last login time
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$user['id']]);

        // Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_data'] = $user;

        // Log the login activity
        $this->logActivity($user['id'], 'login', 'User logged in successfully.');
    } catch (Exception $e) {
        $this->logError("Login error: " . $e->getMessage());
        throw $e;
    }
}

public function logActivity(int $userId, string $action, ?string $details = null): bool
{
    try {
        $stmt = $this->db->prepare(
            'INSERT INTO user_activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())'
        );
        return $stmt->execute([$userId, $action, $details]);
    } catch (Exception $e) {
        $this->logError("Error logging activity for user ID $userId: " . $e->getMessage());
        return false;
    }
}



public function logout(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Unset session variables
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destroy the session
        session_destroy();
    }

    // Optional: Redirect to login or home page
    header("Location: " . $this->cmsManager->baseUrl('/login'));
    exit();
}


    public function getCurrentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            $this->currentUser = null;
            return null;
        }

        if (!isset($_SESSION['user_data'])) {
            $_SESSION['user_data'] = $this->getAttributes('id', $_SESSION['user_id']);
        }

        $this->currentUser = $_SESSION['user_data'] ?? null;
        return $this->currentUser;
    }

    public function hasAccessLevel(string $requiredLevel): bool
    {
        if (!$this->currentUser) {
            return false;
        }

        $levels = ['public' => 1, 'registered' => 2, 'editor' => 3, 'admin' => 4];
        $userLevel = $levels[$this->currentUser['access_level']] ?? 0;
        $requiredLevelValue = $levels[$requiredLevel] ?? 0;

        return $userLevel >= $requiredLevelValue;
    }

    // ------------------- LOGIN/REGISTER FORMS -------------------

    public function renderLoginForm(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $usernameOrEmail = $_POST['username_or_email'] ?? '';
            $password = $_POST['password'] ?? '';
            try {
                $this->login($usernameOrEmail, $password);
                echo '<div class="site-main">';
                echo "Login successful! Redirecting...";
                header("Location: " . $this->cmsManager->baseUrl('/home'));
                exit();
            } catch (Exception $e) {
                echo '<div class="site-main">';
                echo "<p class='custom-error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
        echo '<div class="site-main">';
        echo '<div class="login-form">';
        echo '<h2>Login</h2>';
        echo '<form method="post">';
        echo '<label>Username or Email: <input type="text" name="username_or_email" required></label>';
        echo '<label>Password: <input type="password" name="password" required></label>';
        echo '<button type="submit">Login</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    public function renderRegisterForm(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = $_POST['username'] ?? '';
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            try {
                $this->register($username, $email, $password);
                 echo '<div class="site-main">';
                echo "Registration successful! Please check your email for verification.";
                header("Location: " . $this->cmsManager->baseUrl('/login'));
                exit();
            } catch (Exception $e) {
                echo '<div class="site-main">';
                echo "<p class='custom-error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
        echo '<div class="site-main">';
        echo '<div class="register-form">';
        echo '<h2>Register</h2>';
        echo '<form method="post">';
        echo '<label>Username: <input type="text" name="username" required></label>';
        echo '<label>Email: <input type="email" name="email" required></label>';
        echo '<label>Password: <input type="password" name="password" required></label>';
        echo '<button type="submit">Register</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    public function verifyEmail(string $token): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE verification_token = ? AND status = ? LIMIT 1');
            $stmt->execute([$token, 'unverified']);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $stmt = $this->db->prepare('UPDATE users SET status = ? WHERE id = ?');
                return $stmt->execute(['verified', $user['id']]);
            }
        } catch (Exception $e) {
            $this->logError("Error verifying email with token $token: " . $e->getMessage());
        }

        return false;
    }
    
     public function register(string $username, string $email, string $password): void
    {
        try {
            // Validate the password strength
            if (!$this->validatePasswordStrength($password)) {
                throw new \Exception('Password does not meet strength requirements.');
            }

            // Check for existing user by username or email
            $existingUserByUsername = $this->getAttributes('username', $username);
            $existingUserByEmail = $this->getAttributes('email', $email);

            if ($existingUserByUsername) {
                throw new \Exception('Username is already taken.');
            }
            if ($existingUserByEmail) {
                throw new \Exception('Email is already taken.');
            }

            // Create a verification token
            $verificationToken = bin2hex(random_bytes(32));
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Add the user to the database
            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, password, access_level, status, verification_token, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $username,
                $email,
                $passwordHash,
                'registered', // Default access level for new users
                'unverified',
                $verificationToken
            ]);

            // Send the verification email using cmsManager
            $verificationLink = $this->cmsManager->baseUrl("/verify?token={$verificationToken}");
            $recipients = [['email' => $email, 'name' => $username]];

            if (!$this->cmsManager->sendEmail(
                'verification',
                ['verificationLink' => $verificationLink],
                $recipients,
                ['subject' => 'Verify Your Email']
            )) {
                throw new \Exception("Failed to send verification email.");
            }

        } catch (\Exception $e) {
            $this->logError("Registration error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Fetch column names from the users table.
     */
    public function getUserColumns(): array
    {
        $stmt = $this->db->query("DESCRIBE users");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_diff($columns, $this->ignoredColumns);
    }

    /**
     * Display the user profile in a table with Gravatar and form controls.
     */
  public function renderUserProfileForm(int $userId): void
{
    $user = $this->getAttributes('id', $userId);
    if (!$user) {
        echo "<p>User not found.</p>";
        return;
    }

    $columns = $this->getUserColumns();
    $gravatarUrl = $this->getGravatarUrl($user['email']);

    echo "<div class='site-main'>";
    
    // Gravatar Section
    echo "<div class='gravatar' style='text-align:center;'>";
    echo "<img src='{$gravatarUrl}' alt='Gravatar' style='border-radius:50%; width:100px; height:100px;' />";
    echo "</div>";

    // User Details Table
    echo "<h3>User Details</h3>";
    echo "<table class='user-details-table' style='width:100%; border-collapse:collapse;'>";
    echo "<thead><tr><th>Field</th><th>Value</th></tr></thead>";
    echo "<tbody>";
    foreach ($columns as $column) {
        $value = isset($user[$column]) ? htmlspecialchars((string)$user[$column]) : '';
        echo "<tr>";
        echo "<td><strong>" . ucfirst(str_replace('_', ' ', $column)) . ":</strong></td>";
        echo "<td>{$value}</td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    // Editable Form
    echo "<hr />";
    echo "<h3>Edit Profile</h3>";
    echo "<form method='post' action=''>";
    
    // Editable Email
    echo "<label for='email'>New Email:</label>";
    echo "<input type='email' id='email' name='email' value='" . htmlspecialchars($user['email']) . "' required />";
    
    // Editable Password
    echo "<label for='new_password'>New Password:</label>";
    echo "<input type='password' id='new_password' name='new_password' required />";
    
    echo "<label for='confirm_password'>Confirm Password:</label>";
    echo "<input type='password' id='confirm_password' name='confirm_password' required />";
    
        // Old Password for Verification
    echo "<label for='old_password'>Current Password:</label>";
    echo "<input type='password' id='old_password' name='old_password' required />";

    // Submit Button
    echo "<button type='submit' name='update_profile'>Update Email and Password</button>";
    echo "</form>";

    // Account Management Section
    echo "<hr />";
    echo "<h3>Account Management</h3>";
    echo "<form method='post' action='' style='display:inline;'>";
    echo "<button type='submit' name='delete_account'>Delete Account</button>";
    echo "</form>";
    echo "<form method='post' action='' style='display:inline; margin-left:10px;'>";
    echo "<button type='submit' name='reset_password'>Reset Password</button>";
    echo "</form>";

    echo "</div>";
}
public function sendPasswordResetLink(int $userId): bool
{
    $user = $this->getAttributes('id', $userId);
    if (!$user) {
        throw new Exception('User not found.');
    }

    $verificationToken = bin2hex(random_bytes(32));
    $this->updateUser($userId, ['verification_token' => $verificationToken]);

    $resetLink = $this->cmsManager->baseUrl("/reset-password?token={$verificationToken}");
    $recipients = [['email' => $user['email']]];

    return $this->cmsManager->sendEmail(
        'password_reset',
        ['resetLink' => $resetLink],
        $recipients,
        ['subject' => 'Reset Your Password']
    );
}
public function handlePasswordReset(string $token): bool
{
    $stmt = $this->db->prepare('SELECT id, email FROM users WHERE verification_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('Invalid or expired token.');
    }

    // Generate a new password
    $newPassword = bin2hex(random_bytes(4)); // Example: 8-character random password
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update the password in the database
    $this->updateUser($user['id'], [
        'password' => $passwordHash,
        'verification_token' => null // Invalidate the token
    ]);

    // Email the new password to the user
    $recipients = [['email' => $user['email']]];

    return $this->cmsManager->sendEmail(
        'new_password',
        ['newPassword' => $newPassword],
        $recipients,
        ['subject' => 'Your New Password']
    );
}

public function handleAccountDeletion(string $token): bool
{
    $stmt = $this->db->prepare('SELECT id, email FROM users WHERE verification_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('Invalid or expired token.');
    }

    // Delete the user from the database
    $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
    if ($stmt->execute([$user['id']])) {
        // Send a confirmation email
        $recipients = [['email' => $user['email']]];
        $this->cmsManager->sendEmail(
            'account_deleted',
            ['email' => $user['email']],
            $recipients,
            ['subject' => 'Account Deleted Successfully']
        );
        return true;
    }

    throw new Exception('Failed to delete user account.');
}






    /**
     * Handle profile update submission.
     */
    public function handleProfileUpdate(int $userId, array $postData): void
    {
        $columns = $this->getUserColumns();
        $updateData = [];
        foreach ($columns as $column) {
            if (isset($postData[$column])) {
                $updateData[$column] = $postData[$column];
            }
        }
        if (!empty($updateData)) {
            $this->updateUser($userId, $updateData);
        }
    }

    /**
     * Change the user's password.
     */
    public function changePassword(int $userId, string $newPassword, string $confirmPassword): bool
    {
        if ($newPassword !== $confirmPassword) {
            throw new Exception('Passwords do not match.');
        }
        return $this->updatePassword($userId, $newPassword);
    }

    /**
     * Change the user's email and send verification.
     */
    public function changeEmail(int $userId, string $newEmail): bool
    {
        $verificationToken = bin2hex(random_bytes(32));
        $this->updateUser($userId, ['email' => $newEmail, 'status' => 'unverified', 'verification_token' => $verificationToken]);

        $verificationLink = $this->cmsManager->baseUrl("/verify?token={$verificationToken}");
        $recipients = [['email' => $newEmail]];

        return $this->cmsManager->sendEmail(
            'email_verification',
            ['verificationLink' => $verificationLink],
            $recipients,
            ['subject' => 'Verify Your New Email']
        );
    }

    /**
     * Send account deletion email verification.
     */
    public function sendAccountDeletionVerification(int $userId): bool
    {
        $user = $this->getAttributes('id', $userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        $verificationToken = bin2hex(random_bytes(32));
        $this->updateUser($userId, ['verification_token' => $verificationToken]);

        $verificationLink = $this->cmsManager->baseUrl("/delete-account?token={$verificationToken}");
        $recipients = [['email' => $user['email']]];

        return $this->cmsManager->sendEmail(
            'account_deletion',
            ['verificationLink' => $verificationLink],
            $recipients,
            ['subject' => 'Confirm Account Deletion']
        );
    }

    /**
     * Get Gravatar URL.
     */
    private function getGravatarUrl(string $email): string
    {
        $hash = md5(strtolower(trim($email)));
        return "https://www.gravatar.com/avatar/{$hash}";
    }



    // ------------------- HELP FUNCTION -------------------

    public static function help(): void
    {
        echo "<h2>UserManager Help</h2>";
        echo "<p>This class manages user accounts, authentication, and security features.</p>";
        echo "<h3>Examples:</h3>";

        echo "<h4>1. Register a New User</h4>";
        echo "<pre><code>\$userManager->register('username', 'email@example.com', 'Password123!');</code></pre>";

        echo "<h4>2. Login</h4>";
        echo "<pre><code>\$userManager->login('username', 'Password123!');</code></pre>";

        echo "<h4>3. Logout</h4>";
        echo "<pre><code>\$userManager->logout();</code></pre>";

        echo "<h4>4. Verify Email</h4>";
        echo "<pre><code>\$userManager->verifyEmail('token_here');</code></pre>";
    }

    // ------------------- ERROR LOGGING -------------------

    private function logError(string $message): void
    {
        if ($this->cmsManager->isErrorLoggingEnabled()) {
            error_log($message);
        }
    }
}
