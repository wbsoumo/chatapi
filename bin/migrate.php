#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Database;
use App\Database\Schema;

$options = getopt('', ['fresh']);

try {
    $pdo = Database::getConnection();
    
    if (isset($options['fresh'])) {
        echo "Dropping all tables...\n";
        Schema::dropTables($pdo);
        echo "All tables dropped successfully.\n";
    }
    
    echo "Running migrations...\n";
    Schema::createTables($pdo);
    echo "Database migrations completed successfully.\n";
    
} catch (\Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
