<?php
/*
 * ITFlow - Webhook Database Patch (Updatable)
// Check if config * 
 * This file creates or updates the webhook tables using ITFlow's existing database connection.
 * It is designed to be safe to run multiple times. 
 * 
 * Usage: Navigate to https://your-itflow-domain/webhook_patch.php
 * Delete this file after running! 
 */

// Load ITFlow configuration (this includes database connection)
// Check if config.php exists in current or parent directory
if (file_exists("config.php")) {
    require_once "config.php";
} elseif (file_exists("../config.php")) {
    require_once "../config.php";
} else {
    die("<h2 style='color:red;'>Configuration file (config.php) not found.</h2>");
}

// Connect to database (uses variables from config.php)
$mysqli = mysqli_connect($dbhost, $dbusername, $dbpassword, $database);

if (!$mysqli) {
    die("<h2 style='color:red;'>Database connection failed: " . mysqli_connect_error() . "</h2>");
}

/**
 * Function to check if a table exists
 */
function tableExists($mysqli, $table) {
    $result = mysqli_query($mysqli, "SHOW TABLES LIKE '$table'");
    return $result && mysqli_num_rows($result) > 0;
}

/**
 * Function to check if a column exists in a table
 */
function columnExists($mysqli, $table, $column) {
    $result = mysqli_query($mysqli, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && mysqli_num_rows($result) > 0;
}

/**
 * Function to check if a constraint exists
 */
function constraintExists($mysqli, $table, $constraint) {
    $result = mysqli_query($mysqli, "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_NAME = '$table' AND CONSTRAINT_NAME = '$constraint' AND CONSTRAINT_SCHEMA = DATABASE()");
    return $result && mysqli_num_rows($result) > 0;
}

echo "<h1>ITFlow Webhook Patch</h1>";
echo "<pre>";

$errors = 0;
$success = 0;

// ============================================
// 1. Create/Update 'webhooks' table
// ============================================
if (!tableExists($mysqli, 'webhooks')) {
    echo "Creating 'webhooks' table... ";
    $sql = "CREATE TABLE `webhooks` (
        `webhook_id` int(11) NOT NULL AUTO_INCREMENT,
        `webhook_name` varchar(255) NOT NULL,
        `webhook_description` varchar(500) DEFAULT NULL,
        `webhook_url` varchar(500) NOT NULL,
        `webhook_secret` varchar(255) NOT NULL,
        `webhook_enabled` tinyint(1) NOT NULL DEFAULT 1,
        `webhook_event_types` text NOT NULL COMMENT 'JSON array of event types',
        `webhook_client_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = all clients',
        `webhook_rate_limit` int(11) NOT NULL DEFAULT 100 COMMENT 'Max calls per hour, 0 = unlimited',
        `webhook_queuing_enabled` tinyint(1) NOT NULL DEFAULT 1,
        `webhook_max_retries` int(11) NOT NULL DEFAULT 5,
        `webhook_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `webhook_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`webhook_id`),
        KEY `idx_webhook_enabled` (`webhook_enabled`),
        KEY `idx_webhook_client_id` (`webhook_client_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    if (mysqli_query($mysqli, $sql)) {
        echo "<span style='color:green;'>SUCCESS</span>\n";
        $success++;
    } else {
        echo "<span style='color:red;'>FAILED: " . mysqli_error($mysqli) . "</span>\n";
        $errors++;
    }
} else {
    echo "Table 'webhooks' already exists. Checking for updates...\n";
    
    $updates = [
        'webhook_queuing_enabled' => "ALTER TABLE `webhooks` ADD COLUMN `webhook_queuing_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `webhook_rate_limit`",
        'webhook_max_retries' => "ALTER TABLE `webhooks` ADD COLUMN `webhook_max_retries` int(11) NOT NULL DEFAULT 5 AFTER `webhook_queuing_enabled`",
        'webhook_tag_id' => "ALTER TABLE `webhooks` ADD COLUMN `webhook_tag_id` int(11) NOT NULL DEFAULT 0 AFTER `webhook_client_id`"
    ];
    
    foreach ($updates as $column => $sql) {
        echo "Checking for column '$column'... ";
        if (!columnExists($mysqli, 'webhooks', $column)) {
            if (mysqli_query($mysqli, $sql)) {
                echo "<span style='color:green;'>ADDED</span>\n";
                $success++;
            } else {
                echo "<span style='color:red;'>FAILED: " . mysqli_error($mysqli) . "</span>\n";
                $errors++;
            }
        } else {
            echo "<span style='color:orange;'>EXISTS</span>\n";
        }
    }
}

// ============================================
// 2. Create/Update 'webhook_logs' table
// ============================================
if (!tableExists($mysqli, 'webhook_logs')) {
    echo "Creating 'webhook_logs' table... ";
    $sql = "CREATE TABLE `webhook_logs` (
        `webhook_log_id` int(11) NOT NULL AUTO_INCREMENT,
        `webhook_log_webhook_id` int(11) NOT NULL,
        `webhook_log_event_type` varchar(100) NOT NULL,
        `webhook_log_payload` text NOT NULL,
        `webhook_log_response_code` int(11) NOT NULL DEFAULT 0,
        `webhook_log_response_body` text DEFAULT NULL,
        `webhook_log_status` enum('success','failed','pending','retried') NOT NULL DEFAULT 'pending',
        `webhook_log_attempt_count` int(11) NOT NULL DEFAULT 1,
        `webhook_log_next_retry_at` datetime DEFAULT NULL,
        `webhook_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`webhook_log_id`),
        KEY `idx_webhook_log_webhook_id` (`webhook_log_webhook_id`),
        KEY `idx_webhook_log_status` (`webhook_log_status`),
        KEY `idx_webhook_log_next_retry` (`webhook_log_next_retry_at`),
        KEY `idx_webhook_log_created` (`webhook_log_created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    if (mysqli_query($mysqli, $sql)) {
        echo "<span style='color:green;'>SUCCESS</span>\n";
        $success++;
    } else {
        echo "<span style='color:red;'>FAILED: " . mysqli_error($mysqli) . "</span>\n";
        $errors++;
    }
} else {
    echo "Table 'webhook_logs' already exists. Checking for updates...\n";
    // Check if 'retried' is in the enum definition
    $result = mysqli_query($mysqli, "SHOW COLUMNS FROM `webhook_logs` LIKE 'webhook_log_status'");
    $row = mysqli_fetch_assoc($result);
    $type = $row['Type'];
    
    if (strpos($type, "'retried'") === false) {
        echo "Updating webhook_log_status enum... ";
        if (mysqli_query($mysqli, "ALTER TABLE `webhook_logs` MODIFY COLUMN `webhook_log_status` enum('success','failed','pending','retried') NOT NULL DEFAULT 'pending'")) {
            echo "<span style='color:green;'>UPDATED</span>\n";
            $success++;
        } else {
            echo "<span style='color:red;'>FAILED: " . mysqli_error($mysqli) . "</span>\n";
            $errors++;
        }
    } else {
        echo "Column 'webhook_log_status' is already up to date.\n";
    }
}

// ============================================
// 3. Add foreign key constraint
// ============================================
echo "Checking for foreign key constraint 'fk_webhook_logs_webhook'... ";
if (!constraintExists($mysqli, 'webhook_logs', 'fk_webhook_logs_webhook')) {
    $sql_fk = "ALTER TABLE `webhook_logs` 
        ADD CONSTRAINT `fk_webhook_logs_webhook` 
        FOREIGN KEY (`webhook_log_webhook_id`) 
        REFERENCES `webhooks` (`webhook_id`) 
        ON DELETE CASCADE";
    
    if (mysqli_query($mysqli, $sql_fk)) {
        echo "<span style='color:green;'>ADDED</span>\n";
        $success++;
    } else {
        echo "<span style='color:red;'>FAILED: " . mysqli_error($mysqli) . "</span>\n";
        $errors++;
    }
} else {
    echo "<span style='color:orange;'>EXISTS</span>\n";
}

echo "\n</pre>";

// Summary
echo "<hr>";
if ($errors == 0) {
    echo "<h2 style='color:green;'>✓ Patch completed successfully!</h2>";
    echo "<p>The database is up to date with the latest webhook system requirements.</p>";
    echo "<p style='color:red;'><strong>⚠ IMPORTANT:</strong> Delete this file (webhook_patch.php) now for security!</p>";
    echo "<p><a href='admin/webhooks.php'>Go to Webhooks Admin →</a></p>";
} else {
    echo "<h2 style='color:red;'>Patch completed with $errors error(s)</h2>";
    echo "<p>Check the errors above and try again.</p>";
}

mysqli_close($mysqli);
?>