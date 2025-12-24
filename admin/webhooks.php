<?php

// Default Column Sortby Filter
$sort = "webhook_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

// Include webhook functions
// Webhook functions are now merged into functions.php

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS w.*, c.client_name,
    (SELECT COUNT(*) FROM webhook_logs wl WHERE wl.webhook_log_webhook_id = w.webhook_id AND wl.webhook_log_status = 'success') as success_count,
    (SELECT COUNT(*) FROM webhook_logs wl WHERE wl.webhook_log_webhook_id = w.webhook_id AND wl.webhook_log_status = 'failed') as failed_count
    FROM webhooks w
    LEFT JOIN clients c ON w.webhook_client_id = c.client_id
    WHERE (w.webhook_name LIKE '%$q%' OR w.webhook_url LIKE '%$q%' OR w.webhook_description LIKE '%$q%')
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-plug mr-2"></i>Webhooks</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal"
                data-modal-url="modals/webhook/webhook_add_modal.php"><i class="fas fa-plus mr-2"></i>New
                Webhook</button>
        </div>
    </div>

    <div class="card-body">

        <form autocomplete="off">
            <div class="row">

                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) {
                            echo stripslashes(nullable_htmlentities($q));
                        } ?>" placeholder="Search webhooks">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="btn-group float-right">
                        <div class="dropdown ml-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group mr-2"></i>Bulk Action (<span
                                    id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <button class="dropdown-item text-success" type="submit" form="bulkActions"
                                    name="bulk_enable_webhooks">
                                    <i class="fas fa-fw fa-check mr-2"></i>Enable
                                </button>
                                <button class="dropdown-item text-warning" type="submit" form="bulkActions"
                                    name="bulk_disable_webhooks">
                                    <i class="fas fa-fw fa-ban mr-2"></i>Disable
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold" type="submit" form="bulkActions"
                                    name="bulk_delete_webhooks">
                                    <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </form>
        <hr>

        <div class="table-responsive-sm">

            <form id="bulkActions" action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) {
                        echo "d-none";
                    } ?>">
                        <tr>
                            <td class="pr-0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" onclick="checkAll(this)">
                                </div>
                            </td>
                            <th>
                                <a class="text-dark"
                                    href="?<?php echo $url_query_strings_sort; ?>&sort=webhook_name&order=<?php echo $disp; ?>">
                                    Name <?php if ($sort == 'webhook_name') {
                                        echo $order_icon;
                                    } ?>
                                </a>
                            </th>
                            <th>URL</th>
                            <th>
                                <a class="text-dark"
                                    href="?<?php echo $url_query_strings_sort; ?>&sort=webhook_client_id&order=<?php echo $disp; ?>">
                                    Client <?php if ($sort == 'webhook_client_id') {
                                        echo $order_icon;
                                    } ?>
                                </a>
                            </th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Deliveries</th>
                            <th>
                                <a class="text-dark"
                                    href="?<?php echo $url_query_strings_sort; ?>&sort=webhook_created_at&order=<?php echo $disp; ?>">
                                    Created <?php if ($sort == 'webhook_created_at') {
                                        echo $order_icon;
                                    } ?>
                                </a>
                            </th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php

                        while ($row = mysqli_fetch_array($sql)) {
                            $webhook_id = intval($row['webhook_id']);
                            $webhook_name = nullable_htmlentities($row['webhook_name']);
                            $webhook_url = nullable_htmlentities($row['webhook_url']);
                            $webhook_url_display = strlen($webhook_url) > 50 ? substr($webhook_url, 0, 50) . '...' : $webhook_url;
                            $webhook_secret = nullable_htmlentities($row['webhook_secret']);
                            $webhook_secret_masked = "************" . substr($webhook_secret, -4);
                            $webhook_enabled = intval($row['webhook_enabled']);
                            $webhook_client_id = intval($row['webhook_client_id']);
                            $webhook_rate_limit = intval($row['webhook_rate_limit']);
                            $webhook_description = nullable_htmlentities($row['webhook_description']);
                            $webhook_created_at = nullable_htmlentities($row['webhook_created_at']);
                            $webhook_event_types = $row['webhook_event_types'];

                            $success_count = intval($row['success_count']);
                            $failed_count = intval($row['failed_count']);

                            if ($webhook_client_id == 0) {
                                $webhook_client = "<i>All Clients</i>";
                            } else {
                                $webhook_client = nullable_htmlentities($row['client_name']);
                            }

                            ?>
                            <tr>
                                <td class="pr-0">
                                    <div class="form-check">
                                        <input class="form-check-input bulk-select" type="checkbox" name="webhook_ids[]"
                                            value="<?php echo $webhook_id ?>">
                                    </div>
                                </td>
                                <td>
                                    <a href="#" class="ajax-modal"
                                        data-modal-url="modals/webhook/webhook_edit_modal.php?webhook_id=<?php echo $webhook_id; ?>">
                                        <span class="text-bold"><?php echo $webhook_name; ?></span>
                                    </a>
                                    <?php if ($webhook_description) { ?>
                                        <br><small class="text-muted"><?php echo $webhook_description; ?></small>
                                    <?php } ?>
                                </td>
                                <td>
                                    <span title="<?php echo $webhook_url; ?>"><?php echo $webhook_url_display; ?></span>
                                </td>
                                <td><?php echo $webhook_client; ?></td>
                                <td class="text-center">
                                    <?php if ($webhook_enabled) { ?>
                                        <span class="badge badge-success p-2"><i class="fas fa-check mr-1"></i>Enabled</span>
                                    <?php } else { ?>
                                        <span class="badge badge-secondary p-2"><i class="fas fa-ban mr-1"></i>Disabled</span>
                                    <?php } ?>
                                </td>
                                <td class="text-center">
                                    <a href="#" class="ajax-modal"
                                        data-modal-url="modals/webhook/webhook_logs_modal.php?webhook_id=<?php echo $webhook_id; ?>">
                                        <span class="badge badge-success p-2"><?php echo $success_count; ?></span>
                                        <?php if ($failed_count > 0) { ?>
                                            <span class="badge badge-danger p-2"><?php echo $failed_count; ?></span>
                                        <?php } ?>
                                    </a>
                                </td>
                                <td><?php echo $webhook_created_at; ?></td>
                                <td>
                                    <div class="dropdown dropleft text-center">
                                        <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item ajax-modal" href="#"
                                                data-modal-url="modals/webhook/webhook_edit_modal.php?webhook_id=<?php echo $webhook_id; ?>">
                                                <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                            </a>
                                            <a class="dropdown-item ajax-modal" href="#"
                                                data-modal-url="modals/webhook/webhook_logs_modal.php?webhook_id=<?php echo $webhook_id; ?>">
                                                <i class="fas fa-fw fa-list mr-2"></i>View Logs
                                            </a>
                                            <a class="dropdown-item confirm-link"
                                                href="post.php?test_webhook=<?php echo $webhook_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-paper-plane mr-2"></i>Send Test
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <?php if ($webhook_enabled) { ?>
                                                <a class="dropdown-item text-warning"
                                                    href="post.php?toggle_webhook=<?php echo $webhook_id; ?>&status=0&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                    <i class="fas fa-fw fa-ban mr-2"></i>Disable
                                                </a>
                                            <?php } else { ?>
                                                <a class="dropdown-item text-success"
                                                    href="post.php?toggle_webhook=<?php echo $webhook_id; ?>&status=1&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                    <i class="fas fa-fw fa-check mr-2"></i>Enable
                                                </a>
                                            <?php } ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger text-bold confirm-link"
                                                href="post.php?delete_webhook=<?php echo $webhook_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                        <?php } ?>


                    </tbody>
                </table>

            </form>

        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";
