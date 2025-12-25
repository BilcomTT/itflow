<?php
require_once "../../../includes/modal_header.php";
ob_start();

$webhook_id = intval($_GET['webhook_id']);
// Webhook functions included via functions.php (modal_header.php)
// require_once "../../../includes/webhook_functions.php";

$sql = mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id");
$webhook = mysqli_fetch_assoc($sql);

if (!$webhook) {
    echo "<div class='alert alert-danger m-3'>Webhook not found</div>";
    exit;
}

$webhook_name = nullable_htmlentities($webhook['webhook_name']);
$logs = getWebhookLogs($webhook_id, 100);
$stats = getWebhookStats($webhook_id);

$total = intval($stats['total']);
$success = intval($stats['success']);
$rate = $total > 0 ? round(($success / $total) * 100, 1) : 0;

// Health Logic - Simplified for the slim bar
$health_color = 'bg-danger';
if ($rate > 80) { $health_color = 'bg-success'; } 
elseif ($rate >= 50) { $health_color = 'bg-warning'; }
?>

<style>
    /* Fixed Table Header Styling */
    .table-responsive {
        max-height: 500px;
        overflow-y: auto;
        border-bottom: 1px solid #dee2e6;
    }
    thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #343a40 !important; /* ITFlow Dark */
        color: white;
        border: none !important;
        box-shadow: 0 2px 2px -1px rgba(0,0,0,0.4);
    }

    /* Top Row Metrics */
    .stat-label { font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #888; }
    .stat-value { font-size: 2rem; font-weight: 800; line-height: 1; margin: 5px 0; }
    .stat-icon-sub { font-size: 1rem; opacity: 0.5; }

    /* Compact Health Strip */
    .health-strip {
        padding: 8px 15px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        font-weight: 700;
        font-size: 0.85rem;
    }

    /* Event & HTTP Styling */
    .event-name { font-size: 1.05rem; font-weight: 700; color: #212529; }
    .http-badge {
        border: 2px solid;
        border-radius: 5px;
        padding: 2px 8px;
        font-family: 'Courier New', monospace;
        font-weight: 800;
        font-size: 0.9rem;
        min-width: 45px;
        display: inline-block;
    }

    .payload-box {
        background: #2b2b2b;
        color: #a9b7c6;
        padding: 12px;
        border-radius: 5px;
        font-size: 11px;
    }
</style>

<div class="modal-header bg-white border-bottom py-2">
    <h5 class="modal-title d-flex align-items-center">
        <i class="fas fa-fw fa-plug mr-2 text-primary"></i>
        <span class="font-weight-bold"><?php echo $webhook_name; ?></span>
    </h5>
    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body bg-light pt-3">
    
    <div class="row no-gutters text-center mb-3">
        <?php
        $items = [
            ['Total', $total, 'fa-paper-plane', 'text-dark'],
            ['Success', $success, 'fa-check-circle', 'text-success'],
            ['Failed', $stats['failed'], 'fa-exclamation-circle', 'text-danger'],
            ['Pending', $stats['pending'], 'fa-hourglass-half', 'text-warning']
        ];
        foreach ($items as $i): ?>
        <div class="col-3">
            <div class="stat-label"><?php echo $i[0]; ?></div>
            <div class="stat-value <?php echo $i[3]; ?>"><?php echo number_format($i[1]); ?></div>
            <div class="stat-icon-sub"><i class="fas <?php echo $i[2]; ?>"></i></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 px-1">
        <div class="health-strip <?php echo $health_color; ?> text-white shadow-sm">
            <i class="fas fa-heartbeat mr-2"></i>
            HEALTH SCORE: <?php echo $rate; ?>%
        </div>
        <div class="text-muted small font-italic">
            Last 100 Deliveries
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="py-2 pl-3">Event Type</th>
                        <th class="text-center py-2">Status</th>
                        <th class="text-center py-2">HTTP</th>
                        <th class="text-center py-2">Tries</th>
                        <th class="py-2">Timestamp</th>
                        <th class="text-right py-2 pr-3">Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center py-5">No logs available.</td></tr>
                    <?php else: foreach ($logs as $log): 
                        $code = intval($log['webhook_log_response_code']);
                        
                        // Border Color Logic
                        if ($code >= 200 && $code < 300) { $http_c = 'border-success text-success'; }
                        elseif ($code >= 400 && $code < 500) { $http_c = 'border-warning text-warning'; }
                        else { $http_c = 'border-danger text-danger'; }

                        $status = $log['webhook_log_status'];
                        $badge = ($status == 'success') ? 'badge-success' : (($status == 'failed') ? 'badge-danger' : 'badge-warning');
                    ?>
                    <tr>
                        <td class="align-middle pl-3">
                            <span class="event-name"><?php echo $log['webhook_log_event_type']; ?></span>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge <?php echo $badge; ?> px-2 py-1 text-uppercase" style="font-size: 10px;"><?php echo $status; ?></span>
                        </td>
                        <td class="text-center align-middle">
                            <span class="http-badge <?php echo $http_c; ?>">
                                <?php echo $code ?: 'ERR'; ?>
                            </span>
                        </td>
                        <td class="text-center align-middle font-weight-bold text-muted"><?php echo $log['webhook_log_attempt_count']; ?>/5</td>
                        <td class="align-middle small text-muted"><?php echo date('M d, H:i:s', strtotime($log['webhook_log_created_at'])); ?></td>
                        <td class="text-right align-middle pr-3">
                            <button class="btn btn-xs btn-outline-primary" onclick="toggleLog(<?php echo $log['webhook_log_id']; ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <tr id="log-<?php echo $log['webhook_log_id']; ?>" style="display:none;">
                        <td colspan="6" class="bg-white p-3">
                            <div class="payload-box shadow-sm">
                                <pre class="m-0 text-white"><code><?php echo nullable_htmlentities(json_encode(json_decode($log['webhook_log_payload']), JSON_PRETTY_PRINT)); ?></code></pre>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-footer bg-white border-top py-2">
    <a href="post.php?clear_webhook_logs=<?php echo $webhook_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-sm btn-outline-danger confirm-link"><i class="fas fa-trash mr-1"></i>Clear</a>
    <button type="button" class="btn btn-secondary btn-sm px-4" data-dismiss="modal">Close</button>
</div>

<script>
function toggleLog(id) {
    var x = document.getElementById('log-' + id);
    x.style.display = (x.style.display === 'none') ? 'table-row' : 'none';
}
</script>

<?php require_once "../../../includes/modal_footer.php"; ?>
