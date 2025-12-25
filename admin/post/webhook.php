<?php

/*
 * ITFlow - Webhook POST redirector for backward compatibility
 */

require_once "../../config.php";
require_once "../../functions.php";
require_once "../../includes/check_login.php";

// Define a variable that we can use to only allow running post files via inclusion (prevents people/bots poking them)
define('FROM_POST_HANDLER', true);

// Include the actual webhooks post handler
include_once "webhooks.php";
