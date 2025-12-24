<?php
require_once "../includes/inc_all_admin.php";

// Check if the user has permission to access this page
if (!hasPermission('admin', $_SESSION['user_role_id'])) {
    header("Location: /agent/$config_start_page");
    exit();
}

// Handle actions (e.g., enable/disable webhooks)
if (isset($_POST['action'])) {
    $action = sanitizeInput($_POST['action']);
    $webhook_id = intval($_POST['webhook_id']);

    if ($action == 'enable' || $action == 'disable') {
        $status = $action == 'enable' ? 1 : 0;
        mysqli_query($mysqli, "UPDATE webhooks SET webhook_enabled = $status WHERE webhook_id = $webhook_id");
        logAction("Webhook", "Updated", "Webhook $webhook_id status changed to $action");
    } elseif ($action == 'delete') {
        mysqli_query($mysqli, "DELETE FROM webhooks WHERE webhook_id = $webhook_id");
        logAction("Webhook", "Deleted", "Webhook $webhook_id deleted");
    }

    header("Location: webhooks.php");
    exit();
}

// Get all webhooks
$webhooks = [];
$result = mysqli_query($mysqli, "SELECT * FROM webhooks ORDER BY webhook_name ASC");
while ($row = mysqli_fetch_assoc($result)) {
    $webhooks[] = $row;
}

// Get webhook statistics
$stats = [];
foreach ($webhooks as $webhook) {
    $webhook_id = $webhook['webhook_id'];
    $stats[$webhook_id] = getWebhookStats($webhook_id);
}

// Get clients for filtering
$clients = [];
$result = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
while ($row = mysqli_fetch_assoc($result)) {
    $clients[] = $row;
}

// Get client tags for filtering
$client_tags = [];
$result = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags WHERE tag_type = 'client' ORDER BY tag_name ASC");
while ($row = mysqli_fetch_assoc($result)) {
    $client_tags[] = $row;
}

// Handle filtering
$filter_client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$filter_tag_id = isset($_GET['tag_id']) ? intval($_GET['tag_id']) : 0;

if ($filter_client_id > 0) {
    $webhooks = array_filter($webhooks, function($webhook) use ($filter_client_id) {
        return $webhook['webhook_client_id'] == $filter_client_id;
    });
}

if ($filter_tag_id > 0) {
    $webhooks = array_filter($webhooks, function($webhook) use ($filter_tag_id) {
        return $webhook['webhook_tag_id'] == $filter_tag_id;
    });
}

// Calculate health score for each webhook
foreach ($webhooks as &$webhook) {
    $webhook_id = $webhook['webhook_id'];
    $stats = getWebhookStats($webhook_id);
    $total = $stats['total'];
    $success = $stats['success'];
    
    if ($total > 0) {
        $webhook['health_score'] = ($success / $total) * 100;
    } else {
        $webhook['health_score'] = 100; // Default to 100% if no logs
    }
}

// Get webhook event types for display
$event_types = getWebhookEventTypes();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = "Webhooks";
    require_once "../includes/modal_header.php";
    ?>
    <style>
        .health-indicator {
            height: 20px;
            width: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .health-green { background-color: #28a745; }
        .health-yellow { background-color: #ffc107; }
        .health-red { background-color: #dc3545; }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <?php require_once "includes/side_nav.php"; ?>
        <?php require_once "includes/top_nav.php"; ?>

        <!-- Content Wrapper. Contains page content -->
        <div class="content-wrapper">
            <!-- Content Header (Page header) -->
            <div class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1 class="m-0">Webhooks</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="/admin/">Admin</a></li>
                                <li class="breadcrumb-item active">Webhooks</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main content -->
            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Manage Webhooks</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addWebhookModal">
                                            <i class="fas fa-plus"></i> Add Webhook
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Filtering Options -->
                                    <div class="row mb-3">
                                        <div class="col-md-3">
                                            <form method="get" action="webhooks.php">
                                                <div class="input-group">
                                                    <select name="client_id" class="form-control">
                                                        <option value="0">Filter by Client</option>
                                                        <?php foreach ($clients as $client) { ?>
                                                            <option value="<?php echo $client['client_id']; ?>" <?php echo $filter_client_id == $client['client_id'] ? 'selected' : ''; ?>>
                                                                <?php echo nullable_htmlentities($client['client_name']); ?>
                                                            </option>
                                                        <?php } ?>
                                                    </select>
                                                    <div class="input-group-append">
                                                        <button type="submit" class="btn btn-primary">Apply</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="col-md-3">
                                            <form method="get" action="webhooks.php">
                                                <div class="input-group">
                                                    <select name="tag_id" class="form-control">
                                                        <option value="0">Filter by Tag</option>
                                                        <?php foreach ($client_tags as $tag) { ?>
                                                            <option value="<?php echo $tag['tag_id']; ?>" <?php echo $filter_tag_id == $tag['tag_id'] ? 'selected' : ''; ?>>
                                                                <?php echo nullable_htmlentities($tag['tag_name']); ?>
                                                            </option>
                                                        <?php } ?>
                                                    </select>
                                                    <div class="input-group-append">
                                                        <button type="submit" class="btn btn-primary">Apply</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Webhooks Table -->
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>URL</th>
                                                    <th>Status</th>
                                                    <th>Health</th>
                                                    <th>Events</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($webhooks)) { ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center">No webhooks found.</td>
                                                    </tr>
                                                <?php } else { ?>
                                                    <?php foreach ($webhooks as $webhook) { ?>
                                                        <tr>
                                                            <td><?php echo nullable_htmlentities($webhook['webhook_name']); ?></td>
                                                            <td><?php echo nullable_htmlentities($webhook['webhook_url']); ?></td>
                                                            <td>
                                                                <?php if ($webhook['webhook_enabled']) { ?>
                                                                    <span class="badge badge-success">Enabled</span>
                                                                <?php } else { ?>
                                                                    <span class="badge badge-secondary">Disabled</span>
                                                                <?php } ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                $health_score = $webhook['health_score'];
                                                                $health_class = 'health-green';
                                                                if ($health_score < 70) {
                                                                    $health_class = 'health-red';
                                                                } elseif ($health_score < 90) {
                                                                    $health_class = 'health-yellow';
                                                                }
                                                                ?>
                                                                <span class="health-indicator <?php echo $health_class; ?>"></span>
                                                                <?php echo round($health_score, 1); ?>%
                                                            </td>
                                                            <td>
                                                                <?php
                                                                $event_types_json = json_decode($webhook['webhook_event_types'], true);
                                                                if (is_array($event_types_json)) {
                                                                    echo count($event_types_json) . ' events';
                                                                } else {
                                                                    echo '0 events';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <div class="btn-group">
                                                                    <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#editWebhookModal" data-webhook-id="<?php echo $webhook['webhook_id']; ?>">
                                                                        <i class="fas fa-edit"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#logsModal" data-webhook-id="<?php echo $webhook['webhook_id']; ?>">
                                                                        <i class="fas fa-history"></i>
                                                                    </button>
                                                                    <?php if ($webhook['webhook_enabled']) { ?>
                                                                        <form method="post" action="webhooks.php" style="display:inline;">
                                                                            <input type="hidden" name="action" value="disable">
                                                                            <input type="hidden" name="webhook_id" value="<?php echo $webhook['webhook_id']; ?>">
                                                                            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Disable this webhook?');">
                                                                                <i class="fas fa-pause"></i>
                                                                            </button>
                                                                        </form>
                                                                    <?php } else { ?>
                                                                        <form method="post" action="webhooks.php" style="display:inline;">
                                                                            <input type="hidden" name="action" value="enable">
                                                                            <input type="hidden" name="webhook_id" value="<?php echo $webhook['webhook_id']; ?>">
                                                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Enable this webhook?');">
                                                                                <i class="fas fa-play"></i>
                                                                            </button>
                                                                        </form>
                                                                    <?php } ?>
                                                                    <form method="post" action="webhooks.php" style="display:inline;">
                                                                        <input type="hidden" name="action" value="delete">
                                                                        <input type="hidden" name="webhook_id" value="<?php echo $webhook['webhook_id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this webhook?');">
                                                                            <i class="fas fa-trash"></i>
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php } ?>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <?php require_once "includes/footer.php"; ?>

        <!-- Add Webhook Modal -->
        <div class="modal fade" id="addWebhookModal" tabindex="-1" role="dialog" aria-labelledby="addWebhookModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addWebhookModalLabel">Add Webhook</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form method="post" action="post/webhook.php">
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="webhook_name">Name</label>
                                <input type="text" class="form-control" id="webhook_name" name="webhook_name" required>
                            </div>
                            <div class="form-group">
                                <label for="webhook_description">Description</label>
                                <textarea class="form-control" id="webhook_description" name="webhook_description" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="webhook_url">URL</label>
                                <input type="url" class="form-control" id="webhook_url" name="webhook_url" required>
                            </div>
                            <div class="form-group">
                                <label for="webhook_secret">Secret</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="webhook_secret" name="webhook_secret" required>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-secondary" id="generateSecret">Generate</button>
                                        <button type="button" class="btn btn-secondary" id="copySecret">Copy</button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="webhook_client_id">Client</label>
                                <select class="form-control" id="webhook_client_id" name="webhook_client_id">
                                    <option value="0">All Clients</option>
                                    <?php foreach ($clients as $client) { ?>
                                        <option value="<?php echo $client['client_id']; ?>">
                                            <?php echo nullable_htmlentities($client['client_name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="webhook_tag_id">Client Tag</label>
                                <select class="form-control" id="webhook_tag_id" name="webhook_tag_id">
                                    <option value="0">No Tag</option>
                                    <?php foreach ($client_tags as $tag) { ?>
                                        <option value="<?php echo $tag['tag_id']; ?>">
                                            <?php echo nullable_htmlentities($tag['tag_name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="webhook_rate_limit">Rate Limit (per hour, 0 for unlimited)</label>
                                <input type="number" class="form-control" id="webhook_rate_limit" name="webhook_rate_limit" value="100">
                            </div>
                            <div class="form-group">
                                <label for="webhook_max_retries">Max Retries</label>
                                <input type="number" class="form-control" id="webhook_max_retries" name="webhook_max_retries" value="5">
                            </div>
                            <div class="form-group">
                                <label>Event Types</label>
                                <div class="card">
                                    <div class="card-body">
                                        <?php foreach ($event_types as $category => $events) { ?>
                                            <div class="mb-3">
                                                <h6><?php echo $category; ?></h6>
                                                <button type="button" class="btn btn-sm btn-info select-all" data-category="<?php echo md5($category); ?>">Select All</button>
                                                <div class="mt-2">
                                                    <?php foreach ($events as $event_key => $event_name) { ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input event-checkbox" type="checkbox" name="webhook_event_types[]" value="<?php echo $event_key; ?>" id="event_<?php echo md5($event_key); ?>" data-category="<?php echo md5($category); ?>">
                                                            <label class="form-check-label" for="event_<?php echo md5($event_key); ?>">
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
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Webhook</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Webhook Modal -->
        <div class="modal fade" id="editWebhookModal" tabindex="-1" role="dialog" aria-labelledby="editWebhookModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editWebhookModalLabel">Edit Webhook</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form method="post" action="post/webhook.php">
                        <input type="hidden" name="webhook_id" id="edit_webhook_id">
                        <div class="modal-body" id="editWebhookBody">
                            <!-- Content will be loaded via AJAX -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Logs Modal -->
        <div class="modal fade" id="logsModal" tabindex="-1" role="dialog" aria-labelledby="logsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="logsModalLabel">Webhook Logs</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="logsModalBody">
                        <!-- Content will be loaded via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Generate a random secret
            document.getElementById('generateSecret').addEventListener('click', function() {
                fetch('/admin/post/webhook.php?action=generate_secret')
                    .then(response => response.text())
                    .then(secret => {
                        document.getElementById('webhook_secret').value = secret;
                    });
            });

            // Copy secret to clipboard
            document.getElementById('copySecret').addEventListener('click', function() {
                var secretInput = document.getElementById('webhook_secret');
                secretInput.select();
                document.execCommand('copy');
                alert('Secret copied to clipboard!');
            });

            // Select all events in a category
            document.querySelectorAll('.select-all').forEach(button => {
                button.addEventListener('click', function() {
                    var category = this.getAttribute('data-category');
                    var checkboxes = document.querySelectorAll('.event-checkbox[data-category="' + category + '"]');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = true;
                    });
                });
            });

            // Load edit webhook form
            $('#editWebhookModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var webhook_id = button.data('webhook-id');
                var modal = $(this);
                modal.find('.modal-body').html('<p>Loading...</p>');
                
                fetch('/admin/post/webhook.php?action=get_webhook&webhook_id=' + webhook_id)
                    .then(response => response.text())
                    .then(html => {
                        modal.find('.modal-body').html(html);
                    });
            });

            // Load logs
            $('#logsModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var webhook_id = button.data('webhook-id');
                var modal = $(this);
                modal.find('.modal-body').html('<p>Loading...</p>');
                
                fetch('/admin/post/webhook.php?action=get_logs&webhook_id=' + webhook_id)
                    .then(response => response.text())
                    .then(html => {
                        modal.find('.modal-body').html(html);
                    });
            });
        </script>
    </div>
</body>

</html>