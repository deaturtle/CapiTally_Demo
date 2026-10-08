<?php
/**
 * CapiTally entry point.
 * Redirects the browser to the built frontend at /CapiTally/app/
 * 
 * If you see this page, the frontend has not been built yet.
 * Run the following commands in the frontend/ directory:
 *   pnpm install
 *   pnpm build
 * Then copy frontend/dist/ to CapiTally/app/
 */

$appPath = __DIR__ . '/app/index.html';

if (file_exists($appPath)) {
    // Redirect to the built frontend
    header('Location: /CapiTally/app/');
    exit;
} else {
    // Frontend not built yet — show a helpful setup page
    http_response_code(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CapiTally — Setup Required</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f2f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: white; border-radius: 12px; padding: 40px; max-width: 600px; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        h1 { color: #0879e6; margin-top: 0; }
        code { background: #f0f4f8; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
        pre { background: #192b42; color: #e6f0ff; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 14px; }
        .step { margin-bottom: 20px; }
        .step-num { display: inline-block; background: #0879e6; color: white; border-radius: 50%; width: 28px; height: 28px; text-align: center; line-height: 28px; font-weight: bold; margin-right: 8px; }
        .ok { color: #16a34a; }
        .warn { color: #d97706; }
    </style>
</head>
<body>
<div class="card">
    <h1>CapiTally</h1>
    <p>The PHP backend is running correctly. The frontend needs to be built before the application is accessible.</p>

    <h2>Setup Steps</h2>

    <div class="step">
        <span class="step-num">1</span>
        <strong>Build the frontend</strong>
        <pre>cd frontend
pnpm install
pnpm build</pre>
        Then move <code>frontend/dist/</code> to <code>CapiTally/app/</code>:
        <pre>xcopy /E /I /Y frontend\dist app</pre>
    </div>

    <div class="step">
        <span class="step-num">2</span>
        <strong>Import the database</strong><br>
        Open <a href="http://localhost/phpmyadmin">phpMyAdmin</a> and import:
        <code>backend/database/schema.sql</code>
    </div>

    <div class="step">
        <span class="step-num">3</span>
        <strong>Backend check</strong><br>
        <?php
        // Quick backend health check
        $configFile = __DIR__ . '/backend/config/config.local.php';
        $bootstrapFile = __DIR__ . '/backend/src/bootstrap.php';
        if (file_exists($configFile)) {
            echo '<span class="ok">✓ config.local.php found</span><br>';
        } else {
            echo '<span class="warn">⚠ config.local.php missing — copy backend/config/config.local.sample.php to config.local.php</span><br>';
        }
        if (extension_loaded('pdo_mysql')) {
            echo '<span class="ok">✓ PDO MySQL extension loaded</span><br>';
        } else {
            echo '<span class="warn">⚠ PDO MySQL extension not loaded</span><br>';
        }
        if (extension_loaded('mbstring')) {
            echo '<span class="ok">✓ mbstring extension loaded</span><br>';
        } else {
            echo '<span class="warn">⚠ mbstring extension not loaded</span><br>';
        }
        echo '<span class="ok">✓ PHP version: ' . phpversion() . '</span>';
        ?>
    </div>

    <div class="step">
        <span class="step-num">4</span>
        <strong>Open the app</strong><br>
        After building, go to: <a href="/CapiTally/app/">http://localhost/CapiTally/app/</a>
    </div>
</div>
</body>
</html>
<?php
}
