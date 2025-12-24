<?php

require_once "../../../includes/modal_header.php";
require_once "../../../includes/webhook_functions.php";

ob_start();

$event_types = getWebhookEventTypes();

?>

<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-plug mr-2"></i>New Webhook</h5>
    <button type="button" class="close" data-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Webhook Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="My Webhook" required>
            </div>
        </div>

        <div class="form-group">
            <label>Webhook URL <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-link"></i></span>
                </div>
                <input type="url" class="form-control" name="url" placeholder="https://example.com/webhook" required>
            </div>
            <small class="form-text text-muted">The URL that will receive POST requests when events
                occur</small>
        </div>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-comment"></i></span>
                </div>
                <input type="text" class="form-control" name="description" placeholder="Optional description">
            </div>
        </div>

        <div class="form-group">
            <label>Client Filter</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                </div>
                <select class="form-control select2" name="client_id" id="webhookClientSelect" onchange="toggleTagSelect()">
                    <option value="0">All Clients</option>
                    <option value="-1">Filter by Tag</option>
                    <?php
                    $clients_sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                    while ($client = mysqli_fetch_array($clients_sql)) {
                        $client_id = intval($client['client_id']);
                        $client_name = nullable_htmlentities($client['client_name']);
                        echo "<option value='$client_id'>$client_name</option>";
                    }
                    ?>
                </select>
            </div>
            <small class="form-text text-muted">Only trigger this webhook for events related to a specific
                client</small>
        </div>

        <div class="form-group" id="webhookTagDiv" style="display:none;">
            <label>Tag Filter</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <select class="form-control select2" name="tag_id">
                    <option value="0">Select Tag...</option>
                    <?php
                    $tags_sql = mysqli_query($mysqli, "SELECT tag_id, tag_name, tag_color FROM tags WHERE tag_type = 1 ORDER BY tag_name ASC");
                    while ($tag = mysqli_fetch_array($tags_sql)) {
                        $tag_id = intval($tag['tag_id']);
                        $tag_name = nullable_htmlentities($tag['tag_name']);
                        echo "<option value='$tag_id'>$tag_name</option>";
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
                <input type="number" class="form-control" name="rate_limit" value="100" min="0" max="10000">
            </div>
            <small class="form-text text-muted">Maximum number of webhook deliveries per hour. Set to 0 for
                unlimited.</small>
        </div>

        <div class="form-row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Queuing & Retries</label>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" name="queuing_enabled" id="webhookQueuing" value="1" checked>
                        <label class="custom-control-label" for="webhookQueuing">Enable Queuing</label>
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
                        <input type="number" class="form-control" name="max_retries" value="5" min="0" max="10">
                    </div>
                    <small class="form-text text-muted">Number of retry attempts</small>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Events to Subscribe <strong class="text-danger">*</strong></label>
            
            <div class="mb-2">
                <div class="btn-group btn-group-toggle" data-toggle="buttons">
                    <label class="btn btn-outline-primary active" onclick="toggleEventView('basic')">
                        <input type="radio" name="event_view" id="view_basic" autocomplete="off" checked> Basic
                    </label>
                    <label class="btn btn-outline-primary" onclick="toggleEventView('advanced')">
                        <input type="radio" name="event_view" id="view_advanced" autocomplete="off"> Advanced
                    </label>
                </div>
                <small class="text-muted ml-2">Basic: Tier 1 & 2 only. Advanced: All Tiers.</small>
            </div>

            <div class="card">
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="selectAllEvents"
                            onclick="toggleAllEvents(this)">
                        <label class="form-check-label font-weight-bold" for="selectAllEvents">
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
                        $display_class = ($tier > 2) ? 'advanced-tier' : 'basic-tier';
                        $style = ($tier > 2) ? 'display:none;' : '';
                    ?>
                        <div class="tier-section <?php echo $display_class; ?>" style="<?php echo $style; ?>">
                            <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                                <h6 class="text-secondary mb-0"><?php echo $category; ?></h6>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input category-select-all" 
                                           id="cat_<?php echo $tier; ?>" 
                                           onclick="toggleCategoryEvents(this, '<?php echo $tier; ?>')">
                                    <label class="custom-control-label" for="cat_<?php echo $tier; ?>">Select All</label>
                                </div>
                            </div>
                            <?php foreach ($events as $event_key => $event_name): ?>
                                <div class="form-check ml-2">
                                    <input class="form-check-input event-checkbox tier-<?php echo $tier; ?>" type="checkbox" name="event_types[]"
                                        value="<?php echo $event_key; ?>" id="event_<?php echo $event_key; ?>">
                                    <label class="form-check-label" for="event_<?php echo $event_key; ?>">
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
            <label>Webhook Secret</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                </div>
                <input type="text" class="form-control" name="secret" id="webhookSecret"
                    placeholder="Click generate or leave blank for auto-generation">
                <div class="input-group-append">
                    <button type="button" class="btn btn-warning" onclick="generateSecret()"
                        title="Generate new secret">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button type="button" class="btn btn-info" onclick="copySecret()" title="Copy to clipboard">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            <small class="form-text text-muted">Used to sign webhook payloads. Leave blank to auto-generate.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_webhook" class="btn btn-primary text-bold"><i
                class="fas fa-check mr-2"></i>Create Webhook</button>
        <button type="button" class="btn btn-light" data-dismiss="modal"><i
                class="fas fa-times mr-2"></i>Cancel</button>
    </div>
</form>

<script>
    function generateSecret() {
        const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        let retVal = "";
        for (let i = 0, n = charset.length; i < 32; ++i) {
            retVal += charset.charAt(Math.floor(Math.random() * n));
        }
        document.getElementById("webhookSecret").value = retVal;
    }

    function copySecret() {
        const copyText = document.getElementById("webhookSecret");
        if (copyText.value === "") {
            alert("Nothing to copy! Generate a secret first.");
            return;
        }
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value).then(() => {
            toastr.success("Secret copied to clipboard");
        });
    }

    function toggleAllEvents(checkbox) {
        // Only select currently visible checkboxes
        var visibleCheckboxes = document.querySelectorAll('.event-checkbox:not([style*="display: none"])');
        
        // If advanced is hidden, we need to be careful not to select hidden ones if we were selecting all
        // But the previous implementation selected ALL .event-checkbox
        // Let's refine: Select all checkboxes that are currently visible
        // However, the tier-section is what gets hidden, not the checkbox itself directly.
        
        // Get current view mode
        var isAdvanced = document.getElementById('view_advanced').parentElement.classList.contains('active');
        
        var selector = '.event-checkbox';
        if (!isAdvanced) {
            // Only select basic tiers (1 & 2)
            selector = '.tier-1, .tier-2';
        }
        
        var eventCheckboxes = document.querySelectorAll(selector);
        eventCheckboxes.forEach(function (cb) {
            // Check if parent tier-section is visible (double check)
            if (cb.offsetParent !== null) {
                cb.checked = checkbox.checked;
            }
        });
    }

    function toggleCategoryEvents(checkbox, tier) {
        var eventCheckboxes = document.querySelectorAll('.tier-' + tier);
        eventCheckboxes.forEach(function (cb) {
            cb.checked = checkbox.checked;
        });
    }

    function toggleEventView(view) {
        var advancedSections = document.querySelectorAll('.advanced-tier');
        advancedSections.forEach(function(section) {
            if (view === 'advanced') {
                section.style.display = 'block';
            } else {
                section.style.display = 'none';
                // Optional: Uncheck hidden items? Usually better to keep selection but just hide
            }
        });
        
        // Update labels
        if (view === 'advanced') {
            document.getElementById('view_advanced').parentElement.classList.add('active');
            document.getElementById('view_basic').parentElement.classList.remove('active');
        } else {
            document.getElementById('view_basic').parentElement.classList.add('active');
            document.getElementById('view_advanced').parentElement.classList.remove('active');
        }
    }

    function toggleTagSelect() {
        var clientSelect = document.getElementById('webhookClientSelect');
        var tagDiv = document.getElementById('webhookTagDiv');
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