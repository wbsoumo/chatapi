#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Database;
use App\Services\FCMService;
use App\Utils\Logger;
use Dotenv\Dotenv;

if (!getenv('DB_HOST')) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

echo "Starting WhatsApp Backend background worker...\n";

$db = Database::getConnection();

// 1. Process background queues
processQueue($db);

// 2. Perform cleanup jobs
performCleanup($db);

echo "Worker tasks completed successfully.\n";

function processQueue(PDO $db): void {
    echo "Processing pending background jobs...\n";
    
    // Fetch pending jobs
    $stmt = $db->prepare("
        SELECT id, job_type, payload, attempts 
        FROM queues 
        WHERE status = 'pending' AND (run_at IS NULL OR run_at <= NOW()) 
        LIMIT 10
    ");
    $stmt->execute();
    $jobs = $stmt->fetchAll();

    if (empty($jobs)) {
        echo "No pending jobs found.\n";
        return;
    }

    $updateStmt = $db->prepare("
        UPDATE queues 
        SET status = :status, attempts = :attempts, error_message = :err, updated_at = CURRENT_TIMESTAMP 
        WHERE id = :id
    ");

    foreach ($jobs as $job) {
        $jobId = $job['id'];
        $type = $job['job_type'];
        $payload = json_decode($job['payload'], true);
        $attempts = $job['attempts'] + 1;

        echo "Running job #$jobId (Type: $type, Attempt: $attempts)...\n";

        try {
            $success = false;

            if ($type === 'fcm_retry') {
                $success = FCMService::sendNotification(
                    $payload['tokens'] ?? [],
                    $payload['title'] ?? '',
                    $payload['body'] ?? '',
                    $payload['data'] ?? []
                );
            } else {
                throw new \Exception("Unknown job type: $type");
            }

            if ($success) {
                $updateStmt->execute([
                    'status' => 'completed',
                    'attempts' => $attempts,
                    'err' => null,
                    'id' => $jobId
                ]);
                echo "Job #$jobId completed successfully.\n";
            } else {
                throw new \Exception("Job execution failed.");
            }

        } catch (\Exception $e) {
            Logger::error("Worker job #$jobId failed: " . $e->getMessage());
            
            $status = $attempts >= 3 ? 'failed' : 'pending';
            // Schedule retry 60 seconds from now
            $runAt = date('Y-m-d H:i:s', time() + 60);

            $stmtRetry = $db->prepare("
                UPDATE queues 
                SET status = :status, attempts = :attempts, error_message = :err, run_at = :run_at 
                WHERE id = :id
            ");
            $stmtRetry->execute([
                'status' => $status,
                'attempts' => $attempts,
                'err' => $e->getMessage(),
                'run_at' => $runAt,
                'id' => $jobId
            ]);
            echo "Job #$jobId failed. Rescheduled for retry.\n";
        }
    }
}

function performCleanup(PDO $db): void {
    echo "Running scheduled database and system cleanups...\n";

    try {
        // 1. Delete expired OTP records (older than 24 hours)
        $deletedOtps = $db->exec("DELETE FROM otp WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        echo "- Cleaned up $deletedOtps expired OTP records.\n";

        // 2. Clean up file-based rate limits (older than 1 hour)
        $rateLimitsDir = dirname(__DIR__) . '/storage/rate_limits';
        $cleanedFiles = 0;
        if (is_dir($rateLimitsDir)) {
            $files = glob($rateLimitsDir . '/*');
            $now = time();
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 3600) {
                    unlink($file);
                    $cleanedFiles++;
                }
            }
        }
        echo "- Cleaned up $cleanedFiles file-based rate limit logs.\n";

        // 3. Clear audit logs (older than 30 days)
        $deletedAudits = $db->exec("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        echo "- Cleaned up $deletedAudits audit logs.\n";

    } catch (\Exception $e) {
        Logger::error("Cleanup job failed: " . $e->getMessage());
        echo "Error during cleanups: " . $e->getMessage() . "\n";
    }
}
