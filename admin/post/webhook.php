<?php
require_once "../../includes/inc_all_admin.php";

// Handle actions
if (isset($_GET['action'])) {
    $action = sanitizeInput($_GET['action']);

    if ($action == 'generate_secret') {
        echo generateWebhookSecret();
        exit();
    } elseif ($action == 'get_webhook') {
        $webhook_id = intval($_GET['webhook_id']);
        $result = mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id");
        $webhook = mysqli_fetch_assoc($result);
        
        if ($webhook) {
            $event_types = json_decode($webhook['webhook_event_types'], true);
            $event_types_json = json_encode($event_types);
            
            // Get clients
            $clients = [];
            $result_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
            while ($row = mysqli_fetch_assoc($result_clients)) {
                $clients[] = $row;
            }
            
            // Get client tags
            $client_tags = [];
            $result_tags = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags WHERE tag_type = 'client' ORDER BY tag_name ASC");
            while ($row = mysqli_fetch_assoc($result_tags)) {
                $client_tags[] = $row;
            }
            
            // Get all event types
            $all_event_types = getWebhookEventTypes();
            
            ob_start();
            ?>
            <div class="form-group">
                <label for="edit_webhook_name">Name</label>
                <input type="text" class="form-control" id="edit_webhook_name" name="webhook_name" value="<?php echo nullable_htmlentities($webhook['webhook_name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="edit_webhook_description">Description</label>
                <textarea class="form-control" id="edit_webhook_description" name="webhook_description" rows="3"><?php echo nullable_htmlentities($webhook['webhook_description']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="edit_webhook_url">URL</label>
                <input type="url" class="form-control" id="edit_webhook_url" name="webhook_url" value="<?php echo nullable_htmlentities($webhook['webhook_url']); ?>" required>
            </div>
            <div class="form-group">
                <label for="edit_webhook_secret">Secret</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="edit_webhook_secret" name="webhook_secret" value="<?php echo nullable_htmlentities($webhook['webhook_secret']); ?>" required>
                    <div class="input-group-append">
                        <button type="button" class="btn btn-secondary" id="edit_generateSecret">Generate</button>
                        <button type="button" class="btn btn-secondary" id="edit_copySecret">Copy</button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="edit_webhook_client_id">Client</label>
                <select class="form-control" id="edit_webhook_client_id" name="webhook_client_id">
                    <option value="0">All Clients</option>
                    <?php foreach ($clients as $client) { ?>
                        <option value="<?php echo $client['client_id']; ?>" <?php echo $webhook['webhook_client_id'] == $client['client_id'] ? 'selected' : ''; ?>>
                            <?php echo nullable_htmlentities($client['client_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="edit_webhook_tag_id">Client Tag</label>
                <select class="form-control" id="edit_webhook_tag_id" name="webhook_tag_id">
                    <option value="0">No Tag</option>
                    <?php foreach ($client_tags as $tag) { ?>
                        <option value="<?php echo $tag['tag_id']; ?>" <?php echo $webhook['webhook_tag_id'] == $tag['tag_id'] ? 'selected' : ''; ?>>
                            <?php echo nullable_htmlentities($tag['tag_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="edit_webhook_rate_limit">Rate Limit (per hour, 0 for unlimited)</label>
                <input type="number" class="form-control" id="edit_webhook_rate_limit" name="webhook_rate_limit" value="<?php echo $webhook['webhook_rate_limit']; ?>">
            </div>
            <div class="form-group">
                <label for="edit_webhook_max_retries">Max Retries</label>
                <input type="number" class="form-control" id="edit_webhook_max_retries" name="webhook_max_retries" value="<?php echo $webhook['webhook_max_retries']; ?>">
            </div>
            <div class="form-group">
                <label>Event Types</label>
                <div class="card">
                    <div class="card-body">
                        <?php foreach ($all_event_types as $category => $events) { ?>
                            <div class="mb-3">
                                <h6><?php echo $category; ?></h6>
                                <button type="button" class="btn btn-sm btn-info edit-select-all" data-category="<?php echo md5($category); ?>">Select All</button>
                                <div class="mt-2">
                                    <?php foreach ($events as $event_key => $event_name) { ?>
                                        <div class="form-check">
                                            <input class="form-check-input edit-event-checkbox" type="checkbox" name="webhook_event_types[]" value="<?php echo $event_key; ?>" id="edit_event_<?php echo md5($event_key); ?>" data-category="<?php echo md5($category); ?>" <?php echo in_array($event_key, $event_types) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="edit_event_<?php echo md5($event_key); ?>">
                                                <?php echo $event_name; ?>
                                            </label>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <script>
                // Generate a random secret
                document.getElementById('edit_generateSecret').addEventListener('click', function() {
                    fetch('/admin/post/webhook.php?action=generate_secret')
                        .then(response => response.text())
                        .then(secret => {
                            document.getElementById('edit_webhook_secret').value = secret;
                        });
                });

                // Copy secret to clipboard
                document.getElementById('edit_copySecret').addEventListener('click', function() {
                    var secretInput = document.getElementById('edit_webhook_secret');
                    secretInput.select();
                    document.execCommand('copy');
                    alert('Secret copied to clipboard!');
                });

                // Select all events in a category
                document.querySelectorAll('.edit-select-all').forEach(button => {
                    button.addEventListener('click', function() {
                        var category = this.getAttribute('data-category');
                        var checkboxes = document.querySelectorAll('.edit-event-checkbox[data-category="' + category + '"]');
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = true;
                        });
                    });
                });
            </script>
            <?php
            $html = ob_get_clean();
            echo $html;
            exit();
        }
    } elseif ($action == 'get_logs') {
        $webhook_id = intval($_GET['webhook_id']);
        $logs = getWebhookLogs($webhook_id);
        
        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Event Type</th>
                        <th>Status</th>
                        <th>Response Code</th>
                        <th>Attempts</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) { ?>
                        <tr>
                            <td colspan="5" class="text-center">No logs found.</td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($logs as $log) { ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($log['webhook_log_event_type']); ?></td>
                                <td>
                                    <?php
                                    $status_class = 'badge-success';
                                    if ($log['webhook_log_status'] == 'failed') {
                                        $status_class = 'badge-danger';
                                    } elseif ($log['webhook_log_status'] == 'pending') {
                                        $status_class = 'badge-warning';
                                    } elseif ($log['webhook_log_status'] == 'retried') {
                                        $status_class = 'badge-info';
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>">
                                        <?php echo ucfirst($log['webhook_log_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $log['webhook_log_response_code']; ?></td>
                                <td><?php echo $log['webhook_log_attempt_count']; ?></td>
                                <td><?php echo $log['webhook_log_created_at']; ?></td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();
        echo $html;
        exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['webhook_id'])) {
        // Edit webhook
        $webhook_id = intval($_POST['webhook_id']);
        $webhook_name = sanitizeInput($_POST['webhook_name']);
        $webhook_description = sanitizeInput($_POST['webhook_description']);
        $webhook_url = sanitizeInput($_POST['webhook_url']);
        $webhook_secret = sanitizeInput($_POST['webhook_secret']);
        $webhook_client_id = intval($_POST['webhook_client_id']);
        $webhook_tag_id = intval($_POST['webhook_tag_id']);
        $webhook_rate_limit = intval($_POST['webhook_rate_limit']);
        $webhook_max_retries = intval($_POST['webhook_max_retries']);
        $webhook_event_types = isset($_POST['webhook_event_types']) ? $_POST['webhook_event_types'] : [];
        
        mysqli_query($mysqli, "UPDATE webhooks SET
            webhook_name = '$webhook_name',
            webhook_description = '$webhook_description',
            webhook_url = '$webhook_url',
            webhook_secret = '$webhook_secret',
            webhook_client_id = $webhook_client_id,
            webhook_tag_id = $webhook_tag_id,
            webhook_rate_limit = $webhook_rate_limit,
            webhook_max_retries = $webhook_max_retries,
            webhook_event_types = '" . mysqli_real_escape_string($mysqli, json_encode($webhook_event_types)) . "'
            WHERE webhook_id = $webhook_id
        ");
        
        logAction("Webhook", "Updated", "Webhook $webhook_id updated");
    } else {
        // Add webhook
        $webhook_name = sanitizeInput($_POST['webhook_name']);
        $webhook_description = sanitizeInput($_POST['webhook_description']);
        $webhook_url = sanitizeInput($_POST['webhook_url']);
        $webhook_secret = sanitizeInput($_POST['webhook_secret']);
        $webhook_client_id = intval($_POST['webhook_client_id']);
        $webhook_tag_id = intval($_POST['webhook_tag_id']);
        $webhook_rate_limit = intval($_POST['webhook_rate_limit']);
        $webhook_max_retries = intval($_POST['webhook_max_retries']);
        $webhook_event_types = isset($_POST['webhook_event_types']) ? $_POST['webhook_event_types'] : [];
        
        mysqli_query($mysqli, "INSERT INTO webhooks SET
            webhook_name = '$webhook_name',
            webhook_description = '$webhook_description',
            webhook_url = '$webhook_url',
            webhook_secret = '$webhook_secret',
            webhook_client_id = $webhook_client_id,
            webhook_tag_id = $webhook_tag_id,
            webhook_rate_limit = $webhook_rate_limit,
            webhook_max_retries = $webhook_max_retries,
            webhook_event_types = '" . mysqli_real_escape_string($mysqli, json_encode($webhook_event_types)) . "'
        ");
        
        logAction("Webhook", "Created", "New webhook created: $webhook_name");
    }

    header("Location: /admin/webhooks.php");
    exit();
}

header("Location: /admin/webhooks.php");
exit();
?>