<?php

/*
 * Webhook Functions
 */

/**
 * Trigger webhooks for a specific event
 * 
 * @param string $event_type The type of event (e.g. 'client.created')
 * @param array $data The data to send in the webhook payload
 * @param int $client_id Optional client ID to filter webhooks
 */
function triggerWebhook($event_type, $data, $client_id = 0)
{
    global $mysqli;

    // Build query to get matching webhooks
    $event_type_escaped = mysqli_real_escape_string($mysqli, $event_type);

    $sql = "SELECT * FROM webhooks 
            WHERE webhook_enabled = 1 
            AND (webhook_client_id = 0 OR webhook_client_id = $client_id)
            AND (webhook_event_types LIKE '%\"$event_type_escaped\"%' OR webhook_event_types LIKE '%\"*\"%')";

    $result = mysqli_query($mysqli, $sql);

    while ($webhook = mysqli_fetch_assoc($result)) {
        // Check rate limit before sending
        if (!checkWebhookRateLimit($webhook['webhook_id'])) {
            logWebhookDelivery(
                $webhook['webhook_id'],
                $event_type,
                json_encode($data),
                0,
                'Rate limit exceeded',
                'failed'
            );
            continue;
        }

        // Send the webhook
        sendWebhook($webhook, $event_type, $data);
    }
}

/**
 * Send a webhook to the configured endpoint
 * 
 * @param array $webhook The webhook configuration from database
 * @param string $event_type The event type
 * @param array $data The payload data
 * @param int $retry_count Current retry attempt number
 * @return bool Success status
 */
function sendWebhook($webhook, $event_type, $data, $retry_count = 1)
{
    global $mysqli;

    $webhook_id = intval($webhook['webhook_id']);
    $url = $webhook['webhook_url'];
    $secret = $webhook['webhook_secret'];

    // Build payload
    $payload = [
        'event' => $event_type,
        'timestamp' => date('c'),
        'webhook_id' => $webhook_id,
        'data' => $data
    ];

    $payload_json = json_encode($payload);

    // Generate HMAC signature
    $signature = generateWebhookSignature($payload_json, $secret);

    // Initialize cURL
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload_json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Webhook-Signature: ' . $signature,
            'X-Webhook-Event: ' . $event_type,
            'X-Webhook-Delivery: ' . uniqid('wh_', true),
            'User-Agent: ITFlow-Webhook/1.0'
        ]
    ]);

    // Execute request
    $response_body = curl_exec($ch);
    $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    // Determine success (2xx status codes)
    $success = ($response_code >= 200 && $response_code < 300);

    // Truncate response body for logging
    $response_body_truncated = substr($response_body ?: $curl_error, 0, 1000);

    // Get webhook settings for queuing and retries
    $queuing_enabled = intval($webhook['webhook_queuing_enabled'] ?? 1);
    $max_retries = intval($webhook['webhook_max_retries'] ?? 5);

    // Log the delivery attempt
    $log_id = logWebhookDelivery(
        $webhook_id,
        $event_type,
        $payload_json,
        $response_code,
        $response_body_truncated,
        $success ? 'success' : ($queuing_enabled ? 'pending' : 'failed'),
        $retry_count
    );

    // If failed, queuing is enabled, and under retry limit, schedule for retry
    if (!$success && $queuing_enabled && $retry_count < $max_retries) {
        queueWebhookForRetry($log_id, $retry_count);
    } elseif (!$success && $queuing_enabled) {
        // Mark as permanently failed after max attempts
        mysqli_query($mysqli, "UPDATE webhook_logs SET webhook_log_status = 'failed' WHERE webhook_log_id = $log_id");
    }

    return $success;
}

/**
 * Generate HMAC-SHA256 signature for webhook payload
 * 
 * @param string $payload The JSON payload
 * @param string $secret The webhook secret
 * @return string The HMAC signature
 */
function generateWebhookSignature($payload, $secret)
{
    return hash_hmac('sha256', $payload, $secret);
}

/**
 * Log a webhook delivery attempt
 * 
 * @param int $webhook_id The webhook ID
 * @param string $event_type The event type
 * @param string $payload The JSON payload
 * @param int $response_code HTTP response code
 * @param string $response_body Response body (truncated)
 * @param string $status Status: 'success', 'failed', 'pending'
 * @param int $attempt_count Current attempt number
 * @return int The inserted log ID
 */
function logWebhookDelivery($webhook_id, $event_type, $payload, $response_code, $response_body, $status, $attempt_count = 1)
{
    global $mysqli;

    $webhook_id = intval($webhook_id);
    $event_type = mysqli_real_escape_string($mysqli, $event_type);
    $payload = mysqli_real_escape_string($mysqli, $payload);
    $response_code = intval($response_code);
    $response_body = mysqli_real_escape_string($mysqli, $response_body);
    $status = mysqli_real_escape_string($mysqli, $status);
    $attempt_count = intval($attempt_count);

    mysqli_query($mysqli, "INSERT INTO webhook_logs SET 
        webhook_log_webhook_id = $webhook_id,
        webhook_log_event_type = '$event_type',
        webhook_log_payload = '$payload',
        webhook_log_response_code = $response_code,
        webhook_log_response_body = '$response_body',
        webhook_log_status = '$status',
        webhook_log_attempt_count = $attempt_count
    ");

    return mysqli_insert_id($mysqli);
}

/**
 * Queue a failed webhook for retry with exponential backoff
 * 
 * @param int $log_id The webhook log ID
 * @param int $current_attempt Current attempt number (1-5)
 * @return void
 */
function queueWebhookForRetry($log_id, $current_attempt)
{
    global $mysqli;

    $log_id = intval($log_id);

    // Exponential backoff: 1min, 5min, 30min, 2hours
    $delays = [
        1 => 60,       // 1 minute
        2 => 300,      // 5 minutes
        3 => 1800,     // 30 minutes
        4 => 7200,     // 2 hours
    ];

    $delay_seconds = $delays[$current_attempt] ?? 7200;
    $next_retry = date('Y-m-d H:i:s', time() + $delay_seconds);

    mysqli_query($mysqli, "UPDATE webhook_logs SET 
        webhook_log_next_retry_at = '$next_retry'
        WHERE webhook_log_id = $log_id
    ");
}

/**
 * Check if webhook is within rate limit
 * 
 * @param int $webhook_id The webhook ID
 * @return bool True if within limit, false if exceeded
 */
function checkWebhookRateLimit($webhook_id)
{
    global $mysqli;

    $webhook_id = intval($webhook_id);

    // Get webhook rate limit
    $result = mysqli_query($mysqli, "SELECT webhook_rate_limit FROM webhooks WHERE webhook_id = $webhook_id");
    $webhook = mysqli_fetch_assoc($result);
    $rate_limit = intval($webhook['webhook_rate_limit'] ?? 100);

    if ($rate_limit == 0) {
        return true; // No limit
    }

    // Count successful deliveries in the last hour
    $one_hour_ago = date('Y-m-d H:i:s', time() - 3600);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) as count FROM webhook_logs 
        WHERE webhook_log_webhook_id = $webhook_id 
        AND webhook_log_created_at >= '$one_hour_ago'
        AND webhook_log_status = 'success'
    ");
    $row = mysqli_fetch_assoc($result);

    return ($row['count'] <= $rate_limit);
}

/**
 * Process pending webhook retries (called by cron)
 * 
 * @return int Number of webhooks processed
 */
function processWebhookRetries()
{
    global $mysqli;

    $now = date('Y-m-d H:i:s');
    $processed = 0;

    // Get pending retries that are due
    $result = mysqli_query($mysqli, "SELECT wl.*, w.* FROM webhook_logs wl
        JOIN webhooks w ON wl.webhook_log_webhook_id = w.webhook_id
        WHERE wl.webhook_log_status = 'pending'
        AND wl.webhook_log_next_retry_at IS NOT NULL
        AND wl.webhook_log_next_retry_at <= '$now'
        AND wl.webhook_log_attempt_count < 5
        ORDER BY wl.webhook_log_next_retry_at ASC
        LIMIT 50
    ");

    while ($row = mysqli_fetch_assoc($result)) {
        $log_id = intval($row['webhook_log_id']);
        $webhook_id = intval($row['webhook_id']);
        $event_type = $row['webhook_log_event_type'];
        $payload = json_decode($row['webhook_log_payload'], true);
        $attempt_count = intval($row['webhook_log_attempt_count']) + 1;

        // Build webhook array for sendWebhook
        $webhook = [
            'webhook_id' => $row['webhook_id'],
            'webhook_url' => $row['webhook_url'],
            'webhook_secret' => $row['webhook_secret']
        ];

        // Mark current log as processed (will create new log entry)
        mysqli_query($mysqli, "UPDATE webhook_logs SET webhook_log_status = 'retried' WHERE webhook_log_id = $log_id");

        // Retry the webhook
        sendWebhook($webhook, $event_type, $payload['data'] ?? [], $attempt_count);

        $processed++;
    }

    return $processed;
}

/**
 * Send a test webhook event
 * 
 * @param int $webhook_id The webhook ID to test
 * @return array Result with success status and message
 */
function sendTestWebhook($webhook_id)
{
    global $mysqli;

    $webhook_id = intval($webhook_id);

    // Get webhook config
    $result = mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id");
    $webhook = mysqli_fetch_assoc($result);

    if (!$webhook) {
        return ['success' => false, 'message' => 'Webhook not found'];
    }

    // Build test payload
    $test_data = [
        'test' => true,
        'message' => 'This is a test webhook from ITFlow',
        'webhook_name' => $webhook['webhook_name'],
        'timestamp' => date('c')
    ];

    // Send test webhook
    $success = sendWebhook($webhook, 'webhook.test', $test_data);

    if ($success) {
        return ['success' => true, 'message' => 'Test webhook sent successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to deliver test webhook. Check logs for details.'];
    }
}

/**
 * Generate a secure webhook secret
 * 
 * @return string A random 32-character secret
 */
function generateWebhookSecret()
{
    return bin2hex(random_bytes(32));
}

/**
 * Get webhook delivery logs with pagination
 * 
 * @param int $webhook_id The webhook ID
 * @param int $limit Number of records to return
 * @param int $offset Starting offset
 * @return array Array of log entries
 */
function getWebhookLogs($webhook_id, $limit = 50, $offset = 0)
{
    global $mysqli;

    $webhook_id = intval($webhook_id);
    $limit = intval($limit);
    $offset = intval($offset);

    $result = mysqli_query($mysqli, "SELECT * FROM webhook_logs 
        WHERE webhook_log_webhook_id = $webhook_id 
        ORDER BY webhook_log_created_at DESC 
        LIMIT $offset, $limit
    ");

    $logs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }

    return $logs;
}

/**
 * Get webhook statistics
 * 
 * @param int $webhook_id The webhook ID
 * @return array Statistics including total, success, failed counts
 */
function getWebhookStats($webhook_id)
{
    global $mysqli;

    $webhook_id = intval($webhook_id);

    $result = mysqli_query($mysqli, "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN webhook_log_status = 'success' THEN 1 ELSE 0 END) as success,
        SUM(CASE WHEN webhook_log_status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN webhook_log_status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM webhook_logs 
        WHERE webhook_log_webhook_id = $webhook_id
    ");

    return mysqli_fetch_assoc($result);
}

/**
 * Get all available webhook event types
 * 
 * @return array Multi-dimensional array of categories and their events
 */
function getWebhookEventTypes()
{
    return [
        'Tier 1: Revenue & Client Lifecycle' => [
            'invoice.created' => 'Invoice Created',
            'invoice.sent' => 'Invoice Sent',
            'invoice.paid' => 'Invoice Paid',
            'invoice.overdue' => 'Invoice Overdue',
            'payment.received' => 'Payment Received',
            'quote.viewed' => 'Quote Viewed',
            'quote.sent' => 'Quote Sent',
            'quote.accepted' => 'Quote Accepted',
            'quote.declined' => 'Quote Declined',
            'quote.updated' => 'Quote Updated',
            'client.created' => 'Client Created',
            'client.status_changed' => 'Client Status Changed',
            'client.updated' => 'Client Updated',
            'client.archived' => 'Client Archived',
            'client.deleted' => 'Client Deleted'
        ],
        'Tier 2: Service Delivery & SLA' => [
            'ticket.created' => 'Ticket Created',
            'ticket.priority_changed' => 'Ticket Priority Changed',
            'ticket.status_changed' => 'Ticket Status Changed',
            'ticket.assigned' => 'Ticket Assigned',
            'ticket.resolved' => 'Ticket Resolved',
            'ticket.closed' => 'Ticket Closed',
            'ticket.reopened' => 'Ticket Reopened',
            'ticket.replied' => 'Ticket Replied',
            'ticket.scheduled' => 'Ticket Scheduled',
            'ticket.unscheduled' => 'Ticket Unscheduled',
            'ticket.deleted' => 'Ticket Deleted',
            'ticket.sla_breach' => 'Ticket SLA Breach',
            'ticket.response_overdue' => 'Ticket Response Overdue'
        ],
        'Tier 3: Business Operations' => [
            'contact.created' => 'Contact Created',
            'contact.updated' => 'Contact Updated',
            'contact.archived' => 'Contact Archived',
            'contact.deleted' => 'Contact Deleted',
            'asset.created' => 'Asset Created',
            'asset.updated' => 'Asset Updated',
            'asset.archived' => 'Asset Archived',
            'asset.deleted' => 'Asset Deleted',
            'asset.assigned' => 'Asset Assigned',
            'asset.warranty_expiring' => 'Asset Warranty Expiring',
            'scheduled_ticket.created' => 'Scheduled Ticket Created',
            'document.created' => 'Document Created',
            'document.updated' => 'Document Updated',
            'document.archived' => 'Document Archived',
            'document.deleted' => 'Document Deleted',
            'login.created' => 'Credential/Login Created',
            'password.created' => 'Password Created',
            'password.updated' => 'Password Updated',
            'password.deleted' => 'Password Deleted',
            'password.archived' => 'Password Archived',
            'network.created' => 'Network Created',
            'network.updated' => 'Network Updated',
            'network.archived' => 'Network Archived',
            'network.deleted' => 'Network Deleted',
            'certificate.created' => 'Certificate Created',
            'certificate.updated' => 'Certificate Updated',
            'certificate.archived' => 'Certificate Archived',
            'certificate.deleted' => 'Certificate Deleted',
            'vendor.created' => 'Vendor Created'
        ],
        'Tier 4: Automation & Integration' => [
            'client.note_added' => 'Client Note Added',
            'service.created' => 'Service Created',
            'service.renewed' => 'Service Renewed',
            'service.cancelled' => 'Service Cancelled',
            'project.created' => 'Project Created',
            'project.completed' => 'Project Completed',
            'expense.created' => 'Expense Created',
            'trip.logged' => 'Trip Logged'
        ]
    ];
}
