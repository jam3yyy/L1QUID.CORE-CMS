<!DOCTYPE html>
<html lang="en">
<?php
if (isset($hookManager)) {
    $hookManager->executeHooks('beforeHead', $currentRoute);
} else {
    echo '<!-- HookManager is not initialized -->';
}
?>
<head>
    <meta charset="UTF-8">
    <title>
        <?= isset($pageTitle) ? ' - ' . htmlspecialchars($pageTitle) : ''; ?>
    </title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?= htmlspecialchars($cmsManager->getSiteOption('seo_description', 'Welcome to L1QUID.CORE CMS')); ?>">
    <meta name="keywords" content="<?= htmlspecialchars($cmsManager->getSiteOption('seo_keywords', 'CMS, L1QUID.CORE, Content Management')); ?>">
    <meta name="author" content="<?= htmlspecialchars($cmsManager->getSiteOption('seo_author', 'L1QUID.CORE Team')); ?>">
	<!-- Favicons -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $cmsManager->baseUrl('assets/icons/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $cmsManager->baseUrl('assets/icons/favicon-16x16.png'); ?>">
    <link rel="apple-touch-icon" href="<?= $cmsManager->baseUrl('assets/icons/apple-touch-icon.png'); ?>">
    <link rel="manifest" href="<?= $cmsManager->baseUrl('assets/icons/site.webmanifest'); ?>">

    <link rel="stylesheet" href="<?= $cmsManager->baseUrl('templates/' . $templateName . '/style.css'); ?>">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Dynamically Load reCAPTCHA v3 -->
    <?php if ($recaptchaEnabled && !empty($recaptchaSiteKey)): ?>
        <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptchaSiteKey); ?>"></script>
        <script>
            grecaptcha.ready(function() {
                document.querySelectorAll('.recaptcha-action').forEach(function(element) {
                    const action = element.getAttribute('data-action');
                    grecaptcha.execute('<?= htmlspecialchars($recaptchaSiteKey); ?>', { action: action }).then(function(token) {
                        const inputField = document.createElement('input');
                        inputField.type = 'hidden';
                        inputField.name = 'g-recaptcha-response';
                        inputField.value = token;
                        element.appendChild(inputField);
                    });
                });
            });
        </script>
    <?php endif; ?>

    <?php $hookManager->executeHooks('inHead', $currentRoute, 'public', [
        'template' => $templateName
    ]); ?>
</head>
<body>
    <header class="site-header">
        <div class="header-content">
            <div class="header-text">
                <?php $hookManager->executeHooks('headerText', $currentRoute, 'public', [
                    'template' => $templateName
                ]); ?>
            </div>
        </div>
    </header>
    <?php $hookManager->executeHooks('afterHeader', $currentRoute, 'public', [
        'template' => $templateName
    ]); ?>

    <div class="content-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <a href="<?= $cmsManager->baseUrl(); ?>">
                    <img src="<?= $cmsManager->baseUrl('templates/' . $templateName . '/assets/images/logo.png'); ?>" alt="L1QUID.CORE CMS Logo">
                </a>
            </div>
            <nav>
                <ul class="sidebar-menu">
                    <?php
                    if (method_exists($navManager, 'renderMenu')) {
                        $navManager->renderMenu('sidebar', $currentUser ?? null);
                    }
                    ?>
                </ul>
            </nav>
<div class="sidebar-widgets">
    <?php $hookManager->executeHooks('sidebar', $currentRoute); ?>
</div>
        </aside>


        <main class="main-content">
		        <!-- Main Content -->
            <?= $content; ?>
        </main>
    </div>

    <?php if (!defined('HIDE_FOOTER') || !HIDE_FOOTER) { ?>
        <footer class="site-footer">
            <p>&copy; <?= date('Y'); ?> <a href="https://l1quid.design">L1QUID.CORE CMS</a>. All Rights Reserved.</p>
            <?php if (isset($currentUser['username']) && isset($cmsManager)): ?>
                <p class="footer-user">
                    Logged in as:
                    <a href="<?= $cmsManager->baseUrl('profile'); ?>" class="footer-user-link">
                        <?= $securityManager->escape($currentUser['username']); ?>
                    </a>
                </p>
            <?php endif; ?>
        </footer>
    <?php } ?>
</body>
</html>
