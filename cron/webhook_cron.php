<?php

// ITFlow - Webhook Retry Cron Job
// 
// This script processes failed webhook deliveries and retries them
// with exponential backoff. Should be run every 5 minutes.
// 
// Add to crontab:
// */5 * * * * php /path/to/itflow/cron/webhook_cron.php

// Include required files
require_once dirname(__FILE__) . "/../config.php";
require_once dirname(__FILE__) . "/../functions.php";
require_once dirname(__FILE__) . "/../includes/webhook_functions.php";

// Optional: Check cron key if enabled
if (isset($config_cron_key) && !empty($config_cron_key)) {
    if (!isset($_GET['key']) || $_GET['key'] !== $config_cron_key) {
        // Allow CLI execution without key
        if (php_sapi_name() !== 'cli') {
            http_response_code(403);
            die("Unauthorized");
        }
    }
}

// Process pending webhook retries
$processed = processWebhookRetries();

// Log the results
if ($processed > 0) {
    // Log to logs table
    $now = date('Y-m-d H:i:s');
    $log_description = "Processed $processed webhook retry(ies)";
    mysqli_query($mysqli, "INSERT INTO logs SET 
        log_type = 'Webhook',
        log_action = 'Process',
        log_description = '$log_description',
        log_ip = '127.0.0.1',
        log_user_agent = 'Cron',
        log_created_at = '$now'
    ");
}

// Cleanup old webhook logs (older than 30 days)
mysqli_query($mysqli, "DELETE FROM webhook_logs WHERE webhook_log_created_at < CURDATE() - INTERVAL 30 DAY");

// Output for CLI
if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . " - Processed $processed webhook retries\n";
}
