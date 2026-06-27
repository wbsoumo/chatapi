<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Database;
use App\Database\Schema;

$installOutput = [];
$installSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = Database::getConnection();
        
        // Dynamic logs collector
        $installOutput[] = "• Database connection established successfully.";
        
        $tables = Schema::getTables();
        $installOutput[] = "• Found " . count($tables) . " tables to install.";
        
        // Execute DDLs
        foreach ($tables as $name => $sql) {
            $pdo->exec($sql);
            $installOutput[] = "  ✔ Table '$name' created or verified.";
        }
        
        // Execute indexes
        $indexes = Schema::getIndexes();
        foreach ($indexes as $sql) {
            $pdo->exec($sql);
        }
        $installOutput[] = "  ✔ Created database search and foreign-key indices.";
        
        $installOutput[] = "• All database tables and indexes imported successfully.";
        $installSuccess = true;
        
    } catch (\Exception $e) {
        $installOutput[] = "❌ Installation failed: " . $e->getMessage();
        $installSuccess = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Schema Installer | WhatsApp Backend</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b141a;
            --card-bg: #111b21;
            --accent-color: #00a884;
            --accent-hover: #008f72;
            --text-color: #e9edef;
            --text-muted: #8696a0;
            --error-color: #f15c6d;
            --success-color: #00a884;
            --border-color: #222e35;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            overflow-y: auto;
        }

        .container {
            width: 100%;
            max-width: 580px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            width: 60px;
            height: 60px;
            background: rgba(0, 168, 132, 0.1);
            color: var(--accent-color);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 28px;
            font-weight: 800;
        }

        h1 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        p.subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }

        .alert {
            background: rgba(241, 92, 109, 0.1);
            border-left: 4px solid var(--error-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 13.5px;
            line-height: 1.5;
            color: #ff8f9c;
        }

        .alert strong {
            color: #fff;
        }

        .btn {
            display: block;
            width: 100%;
            background-color: var(--accent-color);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 16px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(0, 168, 132, 0.25);
        }

        .btn:hover {
            background-color: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 168, 132, 0.35);
        }

        .btn:active {
            transform: translateY(0);
        }

        .console {
            margin-top: 30px;
            background: #090e11;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            max-height: 250px;
            overflow-y: auto;
            color: #aebac1;
            line-height: 1.6;
        }

        .console-line {
            margin-bottom: 6px;
            white-space: pre-wrap;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 20px;
        }

        .status-success {
            background: rgba(0, 168, 132, 0.15);
            color: #00ffca;
            border: 1px solid rgba(0, 168, 132, 0.3);
        }

        .status-fail {
            background: rgba(241, 92, 109, 0.15);
            color: var(--error-color);
            border: 1px solid rgba(241, 92, 109, 0.3);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo">W</div>
        <h1>Database Schema Installer</h1>
        <p class="subtitle">Import all tables & indexes for the WhatsApp Chat API</p>
    </div>

    <div class="alert">
        <strong>SECURITY WARNING:</strong> This installer executes structural database migrations. Once migrations run successfully, you <strong>MUST delete</strong> this <code>install.php</code> file from your server to prevent unauthorized overrides.
    </div>

    <form method="POST">
        <button type="submit" class="btn">Run DB Migrations</button>
    </form>

    <?php if ($installSuccess !== null): ?>
        <div style="text-align: center;">
            <?php if ($installSuccess): ?>
                <span class="status-badge status-success">Installation Successful ✔</span>
            <?php else: ?>
                <span class="status-badge status-fail">Installation Failed ❌</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($installOutput)): ?>
        <div class="console">
            <?php foreach ($installOutput as $line): ?>
                <div class="console-line"><?php echo htmlspecialchars($line); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
