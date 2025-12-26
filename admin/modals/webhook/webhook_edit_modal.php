<?php

require_once "../../../includes/modal_header.php";
// Webhook functions included via functions.php (modal_header.php)
// require_once "../../../includes/webhook_functions.php";

ob_start();

$webhook_id = intval($_GET['webhook_id']);

// Get webhook data
$sql = mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id");
$webhook = mysqli_fetch_assoc($sql);

if (!$webhook) {
    echo "<div class='alert alert-danger'>Webhook not found</div>";
    exit;
}

$webhook_name = nullable_htmlentities($webhook['webhook_name']);
$webhook_url = nullable_htmlentities($webhook['webhook_url']);
$webhook_secret = nullable_htmlentities($webhook['webhook_secret']);
$webhook_secret_masked = substr($webhook_secret, 0, 8) . "****" . substr($webhook_secret, -4);
$webhook_enabled = intval($webhook['webhook_enabled']);
$webhook_client_id = intval($webhook['webhook_client_id']);
$webhook_rate_limit = intval($webhook['webhook_rate_limit']);
$webhook_description = nullable_htmlentities($webhook['webhook_description']);
$webhook_event_types_json = $webhook['webhook_event_types'];
$webhook_selected_events = json_decode($webhook_event_types_json, true) ?: [];

$event_types = getWebhookEventTypes();

?>

<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-plug mr-2"></i>Edit Webhook</h5>
    <button type="button" class="close" data-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="webhook_id" value="<?php echo $webhook_id; ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Webhook Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" value="<?php echo $webhook_name; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Webhook URL <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-link"></i></span>
                </div>
                <input type="url" class="form-control" name="url" value="<?php echo $webhook_url; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Webhook Secret</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                </div>
                <input type="text" class="form-control" id="webhookSecretEdit"
                    value="<?php echo $webhook_secret_masked; ?>" data-full-secret="<?php echo $webhook_secret; ?>"
                    data-masked-secret="<?php echo $webhook_secret_masked; ?>" readonly>
                <div class="input-group-append">
                    <button type="button" class="btn btn-light" onclick="toggleSecretVisibility()"
                        title="Show/Hide secret">
                        <i class="fas fa-eye" id="secretEyeIcon"></i>
                    </button>
                    <button type="button" class="btn btn-info" onclick="copySecretEdit()" title="Copy to clipboard">
                        <i class="fas fa-copy"></i>
                    </button>
                    <a href="post.php?regenerate_webhook_secret=<?php echo $webhook_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                        class="btn btn-warning confirm-link" title="Generate new secret">
                        <i class="fas fa-sync-alt"></i>
                    </a>
                </div>
            </div>
            <small class="form-text text-muted">Used to sign webhook payloads. Format: 64-character hex string. Regenerating will invalidate the old secret.</small>
        </div>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-comment"></i></span>
                </div>
                <input type="text" class="form-control" name="description" value="<?php echo $webhook_description; ?>"
                    placeholder="Optional description">
            </div>
        </div>

        <div class="form-group">
            <label>Client Filter</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                </div>
                <select class="form-control select2" name="client_id" id="webhookClientSelectEdit" onchange="toggleTagSelectEdit()">
                    <option value="0" <?php if ($webhook_client_id == 0) echo 'selected'; ?>>All Clients</option>
                    <option value="-1" <?php if ($webhook_client_id == -1) echo 'selected'; ?>>Filter by Tag</option>
                    <?php
                    $clients_sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                    while ($client = mysqli_fetch_array($clients_sql)) {
                        $client_id = intval($client['client_id']);
                        $client_name = nullable_htmlentities($client['client_name']);
                        $selected = ($client_id == $webhook_client_id) ? 'selected' : '';
                        echo "<option value='$client_id' $selected>$client_name</option>";
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="form-group" id="webhookTagDivEdit" style="<?php echo ($webhook_client_id == -1) ? '' : 'display:none;'; ?>">
            <label>Tag Filter</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <select class="form-control select2" name="tag_id">
                    <option value="0">Select Tag...</option>
                    <?php
                    $webhook_tag_id = intval($webhook['webhook_tag_id'] ?? 0);
                    $tags_sql = mysqli_query($mysqli, "SELECT tag_id, tag_name, tag_color FROM tags WHERE tag_type = 1 ORDER BY tag_name ASC");
                    while ($tag = mysqli_fetch_array($tags_sql)) {
                        $tag_id = intval($tag['tag_id']);
                        $tag_name = nullable_htmlentities($tag['tag_name']);
                        $selected = ($tag_id == $webhook_tag_id) ? 'selected' : '';
                        echo "<option value='$tag_id' $selected>$tag_name</option>";
                    }
                    ?>
                </select>
            </div>
            <small class="form-text text-muted">Only trigger for clients with this tag</small>
        </div>

        <div class="form-group">
            <label>Rate Limit (requests/hour)</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tachometer-alt"></i></span>
                </div>
                <input type="number" class="form-control" name="rate_limit" value="<?php echo $webhook_rate_limit; ?>"
                    min="0" max="10000">
            </div>
        </div>

        <div class="form-row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Queuing & Retries</label>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" name="queuing_enabled" id="webhookQueuingEdit" value="1" 
                            <?php echo (isset($webhook['webhook_queuing_enabled']) && $webhook['webhook_queuing_enabled'] == 1) ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="webhookQueuingEdit">Enable Queuing</label>
                    </div>
                    <small class="form-text text-muted">Queue failed webhooks for retry</small>
                </div>
            </div>
            <div class="col-md-6">
                 <div class="form-group">
                    <label>Max Retries</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-redo"></i></span>
                        </div>
                        <input type="number" class="form-control" name="max_retries" value="<?php echo isset($webhook['webhook_max_retries']) ? intval($webhook['webhook_max_retries']) : 5; ?>" min="0" max="10">
                    </div>
                    <small class="form-text text-muted">Number of retry attempts</small>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Events to Subscribe <strong class="text-danger">*</strong></label>
            
            <div class="mb-2">
                <div class="btn-group btn-group-toggle" data-toggle="buttons">
                    <label class="btn btn-outline-primary active" onclick="toggleEventViewEdit('basic')">
                        <input type="radio" name="event_view" id="view_basic_edit" autocomplete="off" checked> Basic
                    </label>
                    <label class="btn btn-outline-primary" onclick="toggleEventViewEdit('advanced')">
                        <input type="radio" name="event_view" id="view_advanced_edit" autocomplete="off"> Advanced
                    </label>
                </div>
                <small class="text-muted ml-2">Basic: Tier 1 & 2 only. Advanced: All Tiers.</small>
            </div>

            <div class="card">
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="selectAllEventsEdit"
                            onclick="toggleAllEventsEdit(this)">
                        <label class="form-check-label font-weight-bold" for="selectAllEventsEdit">
                            Select All Events
                        </label>
                    </div>

                    <hr>

                    <?php 
                    $tier_map = [
                        'Tier 1: Revenue & Client Lifecycle' => 1,
                        'Tier 2: Service Delivery & SLA' => 2,
                        'Tier 3: Business Operations' => 3,
                        'Tier 4: Automation & Integration' => 4
                    ];
                    foreach ($event_types as $category => $events): 
                        $tier = $tier_map[$category] ?? 4;
                        $display_class = ($tier > 2) ? 'advanced-tier-edit' : 'basic-tier-edit';
                        $style = ($tier > 2) ? 'display:none;' : '';
                    ?>
                        <div class="tier-section-edit <?php echo $display_class; ?>" style="<?php echo $style; ?>">
                            <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                                <h6 class="text-secondary mb-0"><?php echo $category; ?></h6>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input category-select-all-edit" 
                                           id="cat_edit_<?php echo $tier; ?>" 
                                           onclick="toggleCategoryEventsEdit(this, '<?php echo $tier; ?>')">
                                    <label class="custom-control-label" for="cat_edit_<?php echo $tier; ?>">Select All</label>
                                </div>
                            </div>
                            <?php foreach ($events as $event_key => $event_name): ?>
                                <?php $checked = in_array($event_key, $webhook_selected_events) ? 'checked' : ''; ?>
                                <div class="form-check ml-2">
                                    <input class="form-check-input event-checkbox-edit tier-edit-<?php echo $tier; ?>" type="checkbox" name="webhook_events[]"
                                        value="<?php echo $event_key; ?>" id="event_edit_<?php echo $event_key; ?>" <?php echo $checked; ?>>
                                    <label class="form-check-label" for="event_edit_<?php echo $event_key; ?>">
                                        <?php echo $event_name; ?>
                                        <small class="text-muted">(<?php echo $event_key; ?>)</small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" name="enabled" id="webhookEnabledEdit" value="1"
                    <?php if ($webhook_enabled)
                        echo 'checked'; ?>>
                <label class="custom-control-label" for="webhookEnabledEdit">Enabled</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_webhook" class="btn btn-primary text-bold"><i
                class="fas fa-check mr-2"></i>Save Changes</button>
        <button type="button" class="btn btn-light" data-dismiss="modal"><i
                class="fas fa-times mr-2"></i>Cancel</button>
    </div>
</form>

<script>
    function toggleSecretVisibility() {
        const input = document.getElementById("webhookSecretEdit");
        const icon = document.getElementById("secretEyeIcon");
        if (input.value === input.getAttribute("data-masked-secret")) {
            input.value = input.getAttribute("data-full-secret");
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.value = input.getAttribute("data-masked-secret");
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }

    function copySecretEdit() {
        const input = document.getElementById("webhookSecretEdit");
        const fullSecret = input.getAttribute("data-full-secret");
        navigator.clipboard.writeText(fullSecret).then(() => {
            toastr.success("Full secret copied to clipboard");
        });
    }

    function toggleAllEventsEdit(checkbox) {
        // Get current view mode
        var isAdvanced = document.getElementById('view_advanced_edit').parentElement.classList.contains('active');
        
        // Select appropriate checkboxes based on view mode
        var eventCheckboxes;
        if (isAdvanced) {
            // Select all event checkboxes in advanced view
            eventCheckboxes = document.querySelectorAll('.event-checkbox-edit');
        } else {
            // Select only basic tier checkboxes (1 & 2) in basic view
            eventCheckboxes = document.querySelectorAll('.tier-edit-1, .tier-edit-2');
        }
        
        // Check/uncheck all visible checkboxes
        eventCheckboxes.forEach(function (cb) {
            // Only check boxes that are actually visible (parent is visible)
            if (cb.offsetParent !== null) {
                cb.checked = checkbox.checked;
            }
        });
    }

    function toggleCategoryEventsEdit(checkbox, tier) {
        var eventCheckboxes = document.querySelectorAll('.tier-edit-' + tier);
        eventCheckboxes.forEach(function (cb) {
            cb.checked = checkbox.checked;
        });
    }

    function toggleEventViewEdit(view) {
        var advancedSections = document.querySelectorAll('.advanced-tier-edit');
        advancedSections.forEach(function(section) {
            if (view === 'advanced') {
                section.style.display = 'block';
            } else {
                section.style.display = 'none';
            }
        });
        
        // Update labels
        if (view === 'advanced') {
            document.getElementById('view_advanced_edit').parentElement.classList.add('active');
            document.getElementById('view_basic_edit').parentElement.classList.remove('active');
        } else {
            document.getElementById('view_basic_edit').parentElement.classList.add('active');
            document.getElementById('view_advanced_edit').parentElement.classList.remove('active');
        }
    }

    function toggleTagSelectEdit() {
        var clientSelect = document.getElementById('webhookClientSelectEdit');
        var tagDiv = document.getElementById('webhookTagDivEdit');
        if (clientSelect.value == '-1') {
            tagDiv.style.display = 'block';
        } else {
            tagDiv.style.display = 'none';
        }
    }
</script>

<?php

require_once "../../../includes/modal_footer.php";

?>
