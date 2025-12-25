<?php

/*
 * ITFlow - Webhook POST request handler (wrapper for backward compatibility)
 * This file is included by admin/post.php when the referer is admin/webhooks.php
 * All webhook logic has been consolidated into admin/post/webhook.php
 */

require_once __DIR__ . '/webhook.php';
