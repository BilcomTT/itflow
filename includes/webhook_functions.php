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

    // Ensure database connection is valid
    if (!$mysqli || !($mysqli instanceof mysqli)) {
        if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
            $mysqli = $GLOBALS['mysqli'];
        } else {
            error_log("Webhook Error: Database connection not available in triggerWebhook for event: $event_type");
            return;
        }
    }

    // Build query to get matching webhooks
    $event_type_escaped = mysqli_real_escape_string($mysqli, $event_type);
    $client_id = intval($client_id);

    $sql = "SELECT * FROM webhooks 
            WHERE webhook_enabled = 1 
            AND (webhook_event_types LIKE '%\"$event_type_escaped\"%' OR webhook_event_types LIKE '%\"*\"%')";

    if ($client_id > 0) {
        // Match: Global OR Specific Client OR Tag match
        $sql .= " AND (
            webhook_client_id = 0 
            OR webhook_client_id = $client_id 
            OR (webhook_client_id = -1 AND webhook_tag_id IN (SELECT tag_id FROM client_tags WHERE client_id = $client_id))
        )";
    } else {
        // System events only trigger global webhooks
        $sql .= " AND webhook_client_id = 0";
    }

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

        // Filter payload for Least Privilege and Minimalist standards
        $filtered_data = filterMinimalistPayload($data, $event_type);
        
        // Send the webhook
        sendWebhook($webhook, $event_type, $filtered_data);
    }
}

/**
 * Sanitize webhook payload to remove PII for GDPR/SOC2 compliance
 *
 * This function recursively removes personally identifiable information (PII) from
 * the webhook payload while preserving structural IDs and non-sensitive data.
 *
 * @param array $data The data to sanitize
 * @return array The sanitized data
 */
function sanitizeWebhookPayload($data)
{
    // PII field patterns to remove (blacklist approach)
    // These are field names that typically contain personal information
    $pii_patterns = [
        // Contact information
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_mobile',
        'contact_fax',
        'contact_address',
        'contact_city',
        'contact_state',
        'contact_zip',
        'contact_country',
        'contact_notes',
        
        // User information
        'user_name',
        'user_email',
        'user_phone',
        'user_avatar',
        'user_display_name',
        'user_full_name',
        'user_first_name',
        'user_last_name',
        
        // Client information (potential PII)
        'client_name',
        'client_email',
        'client_phone',
        'client_fax',
        'client_address',
        'client_city',
        'client_state',
        'client_zip',
        'client_country',
        'client_notes',
        'client_website',
        'client_tax_id',
        'client_ssn',
        'client_account_number',
        
        // Vendor information (potential PII)
        'vendor_name',
        'vendor_email',
        'vendor_phone',
        'vendor_fax',
        'vendor_address',
        'vendor_city',
        'vendor_state',
        'vendor_zip',
        'vendor_country',
        'vendor_contact_name',
        'vendor_contact_email',
        'vendor_contact_phone',
        'vendor_account_number',
        
        // Audit fields that may contain names
        'created_by',
        'deleted_by',
        'assigned_to_name',
        'assigned_to_email',
        
        // Security credentials
        'password',
        'password_hash',
        'secret',
        'api_key',
        'access_token',
        'refresh_token',
        'private_key',
        'public_key',
        'certificate_pem',
        'ssh_key',
        
        // Document content
        'document_content',
        'document_body',
        'file_content',
        
        // Notes and descriptions (may contain PII)
        'notes',
        'description',
        'comments',
        'remarks',
        
        // Financial details (potential PII)
        'credit_card_number',
        'credit_card_cvv',
        'credit_card_expiry',
        'bank_account_number',
        'bank_routing_number',
        'iban',
        'swift_code',
    ];
    
    // Recursively sanitize the data
    $sanitized_data = _sanitizeRecursive($data, $pii_patterns);

    // Replace updated_by with user_id in the payload
    if (isset($sanitized_data['updated_by'])) {
        $sanitized_data['user_id'] = $sanitized_data['updated_by'];
        unset($sanitized_data['updated_by']);
    }

    return $sanitized_data;
}

/**
 * Recursive helper function to sanitize data
 *
 * @param mixed $data The data to sanitize
 * @param array $pii_patterns List of PII field patterns
 * @return mixed The sanitized data
 */
function _sanitizeRecursive($data, $pii_patterns)
{
    if (!is_array($data)) {
        return $data;
    }
    
    $sanitized = [];
    
    foreach ($data as $key => $value) {
        // Check if this key matches a PII pattern
        $is_pii = false;
        
        // Exact match
        if (in_array($key, $pii_patterns)) {
            $is_pii = true;
        }
        
        // Pattern match (e.g., ends with _email, _phone, _name)
        foreach ($pii_patterns as $pattern) {
            // Check for suffixes like _email, _phone, _name
            if (preg_match('/_(email|phone|mobile|fax|address|city|state|zip|country|name|first_name|last_name|display_name|avatar|avatar_url|avatar_file|notes|description|comments|remarks|password|secret|key|token|hash|content|body|number|account_number|tax_id|ssn|cvv|expiry|iban|swift)$/', $key)) {
                // But preserve *_id fields
                if (!preg_match('/_id$/', $key)) {
                    $is_pii = true;
                    break;
                }
            }
        }
        
        // Skip PII fields
        if ($is_pii) {
            continue;
        }
        
        // Recursively sanitize nested arrays
        if (is_array($value)) {
            $sanitized[$key] = _sanitizeRecursive($value, $pii_patterns);
        } else {
            $sanitized[$key] = $value;
        }
    }
    
    return $sanitized;
}

/**
 * Filter webhook payload for Least Privilege and Minimalist standards
 *
 * Removes data noise, empty values, and internal execution parameters.
 * Focuses on delta changes and ensures payload size is minimal.
 *
 * @param array $data The data to filter
 * @param string $event_type The event type
 * @return array The filtered minimalist data
 */
function filterMinimalistPayload($data, $event_type = '')
{
    // Internal execution parameters to remove
    $internal_params = [
        'webhookUrl',
        'webhook_url',
        'executionMode',
        'execution_mode',
        'webhook_id',
        'webhook_name',
        'webhook_secret',
        'webhook_enabled',
        'webhook_event_types',
        'webhook_client_id',
        'webhook_tag_id',
        'webhook_rate_limit',
        'webhook_max_retries',
        'webhook_queuing_enabled',
        'webhook_created_at',
        'webhook_updated_at',
        'webhook_log_id',
        'webhook_log_webhook_id',
        'webhook_log_event_type',
        'webhook_log_payload',
        'webhook_log_response_code',
        'webhook_log_response_body',
        'webhook_log_status',
        'webhook_log_attempt_count',
        'webhook_log_created_at',
        'webhook_log_next_retry_at',
        'delivery_id',
        'delivery',
        'evt_id',
        'event_id',
        'test',
        'message',
        'timestamp',
        'created',
        'updated',
    ];
    
    $filtered = [];
    
    foreach ($data as $key => $value) {
        // Skip internal execution parameters
        if (in_array($key, $internal_params)) {
            continue;
        }
        
        // Skip empty strings
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        
        // Skip null values
        if ($value === null) {
            continue;
        }
        
        // Keep *_id fields and non-zero numeric values
        // For non-ID fields, skip default zeros
        if ($value === 0 && !preg_match('/_id$/', $key)) {
            // Check if this zero is meaningful (like priority=0)
            $meaningful_zero_fields = [
                'priority', 'status', 'count', 'amount', 'balance', 'total',
                'quantity', 'qty', 'level', 'index', 'position', 'order',
                'rank', 'score', 'weight', 'height', 'width', 'depth'
            ];
            $is_meaningful = false;
            foreach ($meaningful_zero_fields as $field) {
                if (strpos($key, $field) !== false) {
                    $is_meaningful = true;
                    break;
                }
            }
            if (!$is_meaningful) {
                continue;
            }
        }
        
        // Recursively filter nested arrays
        if (is_array($value)) {
            $filtered[$key] = filterMinimalistPayload($value, $event_type);
        } else {
            $filtered[$key] = $value;
        }
    }
    
    return $filtered;
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

    // Build minimalist payload (~400 Bytes target)
    $delivery_id = uniqid('wh_', true);
    $timestamp = time();
    $payload = [
        'timestamp' => $timestamp,
        'data' => $data
    ];

    $payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Generate HMAC signature
    // Replay protection is handled separately via X-Webhook-Timestamp and X-Webhook-Delivery headers
    $signature_string = $timestamp . $payload_json;
    $signature = hash_hmac('sha256', $signature_string, $secret);

    // Initialize cURL
    $ch = curl_init($url);

    // Build headers with full HTTP header support
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload_json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ITFlow-Webhook/1.1',
            'X-Webhook-Event: ' . $event_type,
            'X-Webhook-Delivery: ' . $delivery_id,
            'X-Webhook-Timestamp: ' . $timestamp,
            'X-Webhook-Signature: ' . $signature,
        ],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        // Prevent proxy headers from being sent
        CURLOPT_PROXYHEADER => [],
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
