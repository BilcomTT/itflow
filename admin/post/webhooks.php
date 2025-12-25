<?php

/*
 * ITFlow - GET/POST request handler for Webhooks
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Debug logging
file_put_contents(__DIR__ . "/../../uploads/debug_log.txt", "admin/post/webhooks.php loaded. POST: " . print_r($_POST, true) . "\n", FILE_APPEND);
if (function_exists('generateWebhookSecret')) {
    file_put_contents(__DIR__ . "/../../uploads/debug_log.txt", "generateWebhookSecret exists\n", FILE_APPEND);
} else {
    file_put_contents(__DIR__ . "/../../uploads/debug_log.txt", "generateWebhookSecret MISSING. Including functions manually.\n", FILE_APPEND);
    // Emergency include
    require_once dirname(__FILE__) . "/../../includes/webhook_functions.php";
}

// Webhook functions are now in includes/webhook_functions.php
// require_once dirname(__FILE__) . "/../../includes/webhook_functions.php";

// Add Webhook
if (isset($_POST['add_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    $name = sanitizeInput($_POST['name']);
    $url = sanitizeInput($_POST['url']);
    $description = sanitizeInput($_POST['description']);
    $client_id = intval($_POST['client_id']);
    $tag_id = 0;
    if ($client_id == -1) {
        $tag_id = isset($_POST['tag_id']) ? intval($_POST['tag_id']) : 0;
    }
    $rate_limit = intval($_POST['rate_limit']);
    $queuing_enabled = isset($_POST['queuing_enabled']) ? 1 : 0;
    $max_retries = intval($_POST['max_retries']);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $event_types = isset($_POST['webhook_events']) ? $_POST['webhook_events'] : [];
    $event_types_json = mysqli_real_escape_string($mysqli, json_encode($event_types));

    // Generate secret or use provided one
    if (!empty($_POST['secret'])) {
        $secret = sanitizeInput($_POST['secret']);
    } else {
        $secret = generateWebhookSecret();
    }

    mysqli_query($mysqli, "INSERT INTO webhooks SET
        webhook_name = '$name',
        webhook_url = '$url',
        webhook_secret = '$secret',
        webhook_description = '$description',
        webhook_client_id = $client_id,
        webhook_tag_id = $tag_id,
        webhook_rate_limit = $rate_limit,
        webhook_queuing_enabled = $queuing_enabled,
        webhook_max_retries = $max_retries,
        webhook_enabled = $enabled,
        webhook_event_types = '$event_types_json'
    ");

    $webhook_id = mysqli_insert_id($mysqli);

    logAction("Webhook", "Create", "$session_name created webhook $name", 0, $webhook_id);

    flash_alert("Webhook <strong>$name</strong> created. Secret: <code>$secret</code> (save this, it won't be shown again in full)");

    redirect("webhooks.php");
}

// Edit Webhook
if (isset($_POST['edit_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    $webhook_id = intval($_POST['webhook_id']);
    $name = sanitizeInput($_POST['name']);
    $url = sanitizeInput($_POST['url']);
    $description = sanitizeInput($_POST['description']);
    $client_id = intval($_POST['client_id']);
    $tag_id = 0;
    if ($client_id == -1) {
        $tag_id = isset($_POST['tag_id']) ? intval($_POST['tag_id']) : 0;
    }
    $rate_limit = intval($_POST['rate_limit']);
    $queuing_enabled = isset($_POST['queuing_enabled']) ? 1 : 0;
    $max_retries = intval($_POST['max_retries']);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $event_types = isset($_POST['webhook_events']) ? $_POST['webhook_events'] : [];
    $event_types_json = mysqli_real_escape_string($mysqli, json_encode($event_types));

    mysqli_query($mysqli, "UPDATE webhooks SET 
        webhook_name = '$name',
        webhook_url = '$url',
        webhook_description = '$description',
        webhook_client_id = $client_id,
        webhook_tag_id = $tag_id,
        webhook_rate_limit = $rate_limit,
        webhook_queuing_enabled = $queuing_enabled,
        webhook_max_retries = $max_retries,
        webhook_enabled = $enabled,
        webhook_event_types = '$event_types_json'
        WHERE webhook_id = $webhook_id
    ");

    logAction("Webhook", "Update", "$session_name updated webhook $name", 0, $webhook_id);

    flash_alert("Webhook <strong>$name</strong> updated");

    redirect("webhooks.php");
}

// Delete Webhook
if (isset($_GET['delete_webhook'])) {

    validateCSRFToken($_GET['csrf_token']);
    validateAdminRole();

    $webhook_id = intval($_GET['delete_webhook']);

    // Get webhook name for logging
    $row = mysqli_fetch_array(mysqli_query($mysqli, "SELECT webhook_name FROM webhooks WHERE webhook_id = $webhook_id"));
    $webhook_name = sanitizeInput($row['webhook_name']);

    mysqli_query($mysqli, "DELETE FROM webhooks WHERE webhook_id = $webhook_id");

    logAction("Webhook", "Delete", "$session_name deleted webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> deleted", 'error');

    redirect("webhooks.php");
}

// Toggle Webhook
if (isset($_GET['toggle_webhook'])) {

    validateCSRFToken($_GET['csrf_token']);
    validateAdminRole();

    $webhook_id = intval($_GET['toggle_webhook']);
    $status = intval($_GET['status']);

    // Get webhook name for logging
    $row = mysqli_fetch_array(mysqli_query($mysqli, "SELECT webhook_name FROM webhooks WHERE webhook_id = $webhook_id"));
    $webhook_name = sanitizeInput($row['webhook_name']);

    mysqli_query($mysqli, "UPDATE webhooks SET webhook_enabled = $status WHERE webhook_id = $webhook_id");

    $action = $status ? "enabled" : "disabled";
    logAction("Webhook", "Toggle", "$session_name $action webhook $webhook_name", 0, $webhook_id);

    flash_alert("Webhook <strong>$webhook_name</strong> $action");

    redirect("webhooks.php");
}

// Test Webhook
if (isset($_GET['test_webhook'])) {

    validateCSRFToken($_GET['csrf_token']);
    validateAdminRole();

    $webhook_id = intval($_GET['test_webhook']);

    $result = sendTestWebhook($webhook_id);

    if ($result['success']) {
        flash_alert($result['message']);
    } else {
        flash_alert($result['message'], 'error');
    }

    redirect("webhooks.php");
}

// Regenerate Webhook Secret
if (isset($_GET['regenerate_webhook_secret'])) {

    validateCSRFToken($_GET['csrf_token']);
    validateAdminRole();

    $webhook_id = intval($_GET['regenerate_webhook_secret']);

    // Get webhook name for logging
    $row = mysqli_fetch_array(mysqli_query($mysqli, "SELECT webhook_name FROM webhooks WHERE webhook_id = $webhook_id"));
    $webhook_name = sanitizeInput($row['webhook_name']);

    // Generate new secret
    $new_secret = generateWebhookSecret();

    mysqli_query($mysqli, "UPDATE webhooks SET webhook_secret = '$new_secret' WHERE webhook_id = $webhook_id");

    logAction("Webhook", "Regenerate Secret", "$session_name regenerated secret for webhook $webhook_name", 0, $webhook_id);

    flash_alert("New secret for <strong>$webhook_name</strong>: <code>$new_secret</code> (save this, it won't be shown again in full)");

    redirect("webhooks.php");
}

// Clear Webhook Logs
if (isset($_GET['clear_webhook_logs'])) {

    validateCSRFToken($_GET['csrf_token']);
    validateAdminRole();

    $webhook_id = intval($_GET['clear_webhook_logs']);

    // Get webhook name for logging
    $row = mysqli_fetch_array(mysqli_query($mysqli, "SELECT webhook_name FROM webhooks WHERE webhook_id = $webhook_id"));
    $webhook_name = sanitizeInput($row['webhook_name']);

    mysqli_query($mysqli, "DELETE FROM webhook_logs WHERE webhook_log_webhook_id = $webhook_id");

    logAction("Webhook", "Clear Logs", "$session_name cleared logs for webhook $webhook_name", 0, $webhook_id);

    flash_alert("Logs cleared for <strong>$webhook_name</strong>");

    redirect("webhooks.php");
}

// Bulk Enable Webhooks
if (isset($_POST['bulk_enable_webhooks'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    if (isset($_POST['webhook_ids'])) {
        $count = count($_POST['webhook_ids']);

        foreach ($_POST['webhook_ids'] as $webhook_id) {
            $webhook_id = intval($webhook_id);
            mysqli_query($mysqli, "UPDATE webhooks SET webhook_enabled = 1 WHERE webhook_id = $webhook_id");
        }

        logAction("Webhook", "Bulk Enable", "$session_name enabled $count webhook(s)");

        flash_alert("Enabled <strong>$count</strong> webhook(s)");
    }

    redirect("webhooks.php");
}

// Bulk Disable Webhooks
if (isset($_POST['bulk_disable_webhooks'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    if (isset($_POST['webhook_ids'])) {
        $count = count($_POST['webhook_ids']);

        foreach ($_POST['webhook_ids'] as $webhook_id) {
            $webhook_id = intval($webhook_id);
            mysqli_query($mysqli, "UPDATE webhooks SET webhook_enabled = 0 WHERE webhook_id = $webhook_id");
        }

        logAction("Webhook", "Bulk Disable", "$session_name disabled $count webhook(s)");

        flash_alert("Disabled <strong>$count</strong> webhook(s)", 'warning');
    }

    redirect("webhooks.php");
}

// Bulk Delete Webhooks
if (isset($_POST['bulk_delete_webhooks'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    if (isset($_POST['webhook_ids'])) {
        $count = count($_POST['webhook_ids']);

        foreach ($_POST['webhook_ids'] as $webhook_id) {
            $webhook_id = intval($webhook_id);
            mysqli_query($mysqli, "DELETE FROM webhooks WHERE webhook_id = $webhook_id");
        }

        logAction("Webhook", "Bulk Delete", "$session_name deleted $count webhook(s)");

        flash_alert("Deleted <strong>$count</strong> webhook(s)", 'error');
    }

    redirect("webhooks.php");
}
