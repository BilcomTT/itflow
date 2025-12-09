<?php

// Unified login (Agent + Client) using one email & password

// Enforce a Content Security Policy for security against cross-site scripting
header("Content-Security-Policy: default-src 'self'");

// Check if the config.php file exists
if (!file_exists('config.php')) {
    // Redirect to the setup page if config.php doesn't exist
    header("Location: /setup"); // Must use header as functions aren't included yet
    exit();
}

require_once "config.php";
require_once "functions.php";
require_once "plugins/totp/totp.php";

// Sessions & cookies
if (session_status() === PHP_SESSION_NONE) {
    // HTTP-Only cookies
    ini_set("session.cookie_httponly", true);

    // Tell client to only send cookie(s) over HTTPS
    if ($config_https_only || !isset($config_https_only)) {
        ini_set("session.cookie_secure", true);
    }

    session_start();
}

// Check if setup mode is enabled or the variable is missing
if (!isset($config_enable_setup) || $config_enable_setup == 1) {
    // Redirect to the setup page
    header("Location: /setup");
    exit();
}

// Check if the application is configured for HTTPS-only access
if ($config_https_only && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') && (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https')) {
    echo "Login is restricted as ITFlow defaults to HTTPS-only for enhanced security. To login using HTTP, modify the config.php file by setting config_https_only to false. However, this is strongly discouraged, especially when accessing from potentially unsafe networks like the internet.";
    exit;
}

// Set Timezone after session_start
require_once "includes/inc_set_timezone.php";

// IP & User Agent for logging
$session_ip = sanitizeInput(getIP());
$session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');

// Block brute force password attacks - check recent failed login attempts for this IP
// Block access if more than 15 failed login attempts have happened in the last 10 minutes
$row = mysqli_fetch_assoc(mysqli_query(
    $mysqli,
    "SELECT COUNT(log_id) AS failed_login_count
     FROM logs
     WHERE log_ip = '$session_ip'
       AND log_type = 'Login'
       AND log_action = 'Failed'
       AND log_created_at > (NOW() - INTERVAL 10 MINUTE)"
));
$failed_login_count = intval($row['failed_login_count']);

if ($failed_login_count >= 15) {

    logAction("Login", "Blocked", "$session_ip was blocked access to login due to IP lockout");

    // Inform user & quit processing page
    header("HTTP/1.1 429 Too Many Requests");
    exit("<h2>$config_app_name</h2>Your IP address has been blocked due to repeated failed login attempts. Please try again later. <br><br>This action has been logged.");
}

// Query Settings for company
$sql_settings = mysqli_query($mysqli, "
    SELECT settings.*, companies.company_name, companies.company_logo
    FROM settings
    LEFT JOIN companies ON settings.company_id = companies.company_id
    WHERE settings.company_id = 1
");
$row = mysqli_fetch_array($sql_settings);

// Company info
$company_name          = $row['company_name'];
$company_logo          = $row['company_logo'];
$config_start_page     = nullable_htmlentities($row['config_start_page']);
$config_login_message  = nullable_htmlentities($row['config_login_message']);

// Mail
$config_smtp_host       = $row['config_smtp_host'];
$config_smtp_port       = intval($row['config_smtp_port']);
$config_smtp_encryption = $row['config_smtp_encryption'];
$config_smtp_username   = $row['config_smtp_username'];
$config_smtp_password   = $row['config_smtp_password'];
$config_mail_from_email = sanitizeInput($row['config_mail_from_email']);
$config_mail_from_name  = sanitizeInput($row['config_mail_from_name']);

// Client Portal Enabled
$config_client_portal_enable     = intval($row['config_client_portal_enable']);
$config_login_remember_me_expire = intval($row['config_login_remember_me_expire']);

// Azure / Entra for client
$azure_client_id = $row['config_azure_client_id'] ?? null;

$response          = null;
$token_field       = null;
$show_role_choice  = false;
$email             = '';
$password          = '';

// Handle POST login request (normal login or role choice)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['login']) || isset($_POST['role_choice']))) {

    $email        = sanitizeInput($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $role_choice  = $_POST['role_choice'] ?? null; // 'agent' or 'client'

    // Basic validation
    if (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("HTTP/1.1 401 Unauthorized");
        $response = "
          <div class='alert alert-danger'>
            Incorrect username or password.
            <button class='close' data-dismiss='alert'>&times;</button>
          </div>";
    } else {

        /*
         * Unified lookup:
         * - user_type = 1 → Agent
         * - user_type = 2 → Client (must not be archived, client not archived)
         * We fetch all possible matches for this email, then verify password per row.
         * If both an agent and a client match with the same password:
         *   - First, show choice buttons (Agent / Client).
         *   - When user clicks a choice, we honor role_choice.
         */
        $sql = mysqli_query($mysqli, "
            SELECT users.*,
                   user_settings.*,
                   contacts.*,
                   clients.*
            FROM users
            LEFT JOIN user_settings ON users.user_id = user_settings.user_id
            LEFT JOIN contacts       ON users.user_id = contacts.contact_user_id
            LEFT JOIN clients        ON contacts.contact_client_id = clients.client_id
            WHERE user_email = '$email'
              AND user_archived_at IS NULL
              AND user_status = 1
              AND (
                    user_type = 1
                    OR (user_type = 2 AND client_archived_at IS NULL)
                  )
        ");

        $agentRow  = null;
        $clientRow = null;

        while ($r = mysqli_fetch_assoc($sql)) {
            if (!password_verify($password, $r['user_password'])) {
                continue;
            }
            if (intval($r['user_type']) === 1 && $agentRow === null) {
                $agentRow = $r;
            }
            if (intval($r['user_type']) === 2 && $clientRow === null) {
                $clientRow = $r;
            }
        }

        $selectedRow  = null;
        $selectedType = null; // 1 = agent, 2 = client

        if ($agentRow === null && $clientRow === null) {

            // No matching user/password combo
            header("HTTP/1.1 401 Unauthorized");
            logAction("Login", "Failed", "Failed login attempt using $email");

            $response = "
              <div class='alert alert-danger'>
                Incorrect username or password.
                <button class='close' data-dismiss='alert'>&times;</button>
              </div>";

        } elseif ($agentRow !== null && $clientRow !== null) {

            // Both agent and client accounts share same email + password
            if ($role_choice === 'agent') {
                $selectedRow  = $agentRow;
                $selectedType = 1;
            } elseif ($role_choice === 'client') {
                $selectedRow  = $clientRow;
                $selectedType = 2;
            } else {
                // First time we realise this is a dual-role account: ask user to pick
                $show_role_choice = true;
                $response = "
                    <div class='alert alert-info'>
                        This login can be used as either an Agent account or a Client Portal account.
                        Please choose how you want to continue.
                        <button class='close' data-dismiss='alert'>&times;</button>
                    </div>";
            }

        } else {
            // Only one valid row (agent OR client)
            if ($agentRow !== null) {
                $selectedRow  = $agentRow;
                $selectedType = 1;
            } else {
                $selectedRow  = $clientRow;
                $selectedType = 2;
            }
        }

        // If we have a specific user selected, proceed with actual login
        if ($selectedRow !== null && $selectedType !== null) {

            $user_id         = intval($selectedRow['user_id']);
            $user_email      = sanitizeInput($selectedRow['user_email']);
            $session_user_id = $user_id; // to pass the user_id to logAction function

            // =========================
            // AGENT LOGIN FLOW
            // =========================
            if ($selectedType === 1) {

                $user_name                  = sanitizeInput($selectedRow['user_name']);
                $token                      = sanitizeInput($selectedRow['user_token']);
                $force_mfa                  = intval($selectedRow['user_config_force_mfa']);
                $user_role_id               = intval($selectedRow['user_role_id']);
                $user_encryption_ciphertext = $selectedRow['user_specific_encryption_ciphertext'];
                $user_extension_key         = $selectedRow['user_extension_key'];

                $current_code = 0;
                if (isset($_POST['current_code'])) {
                    $current_code = intval($_POST['current_code']);
                }

                $mfa_is_complete = false;
                $extended_log    = '';

                if (empty($token)) {
                    // MFA is not configured
                    $mfa_is_complete = true;
                }

                // Validate MFA via a remember-me cookie
                if (isset($_COOKIE['rememberme'])) {
                    $remember_tokens = mysqli_query($mysqli, "
                        SELECT remember_token_token
                        FROM remember_tokens
                        WHERE remember_token_user_id = $user_id
                          AND remember_token_created_at > (NOW() - INTERVAL $config_login_remember_me_expire DAY)
                    ");
                    while ($remember_row = mysqli_fetch_assoc($remember_tokens)) {
                        if (hash_equals($remember_row['remember_token_token'], $_COOKIE['rememberme'])) {
                            $mfa_is_complete = true;
                            $extended_log    = 'with 2FA remember-me cookie';
                            break;
                        }
                    }
                }

                // Validate MFA code
                if (!empty($current_code) && TokenAuth6238::verify($token, $current_code)) {
                    $mfa_is_complete = true;
                    $extended_log    = 'with MFA';
                }

                if ($mfa_is_complete) {
                    // FULL AGENT LOGIN SUCCESS

                    // Create a remember me token, if requested
                    if (isset($_POST['remember_me'])) {
                        $newRememberToken = bin2hex(random_bytes(64));
                        setcookie(
                            'rememberme',
                            $newRememberToken,
                            time() + 86400 * $config_login_remember_me_expire,
                            "/",
                            null,
                            true,
                            true
                        );
                        mysqli_query($mysqli, "
                            INSERT INTO remember_tokens
                            SET remember_token_user_id = $user_id,
                                remember_token_token   = '$newRememberToken'
                        ");

                        $extended_log .= ", generated a new remember-me token";
                    }

                    // Check this login isn't suspicious
                    $sql_ip_prev_logins = mysqli_fetch_assoc(mysqli_query($mysqli, "
                        SELECT COUNT(log_id) AS ip_previous_logins
                        FROM logs
                        WHERE log_type = 'Login'
                          AND log_action = 'Success'
                          AND log_ip = '$session_ip'
                          AND log_user_id = $user_id
                    "));
                    $ip_previous_logins = sanitizeInput($sql_ip_prev_logins['ip_previous_logins']);

                    $sql_ua_prev_logins = mysqli_fetch_assoc(mysqli_query($mysqli, "
                        SELECT COUNT(log_id) AS ua_previous_logins
                        FROM logs
                        WHERE log_type = 'Login'
                          AND log_action = 'Success'
                          AND log_user_agent = '$session_user_agent'
                          AND log_user_id = $user_id
                    "));
                    $ua_prev_logins     = sanitizeInput($sql_ua_prev_logins['ua_previous_logins']);

                    // Notify if both the user agent and IP are different
                    if (!empty($config_smtp_host) && $ip_previous_logins == 0 && $ua_prev_logins == 0) {
                        $subject = "$config_app_name new login for $user_name";
                        $body    = "Hi $user_name, <br><br>A recent successful login to your $config_app_name account was considered a little unusual. If this was you, you can safely ignore this email!<br><br>IP Address: $session_ip<br> User Agent: $session_user_agent <br><br>If you did not perform this login, your credentials may be compromised. <br><br>Thanks, <br>ITFlow";

                        $data = [
                            [
                                'from'           => $config_mail_from_email,
                                'from_name'      => $config_mail_from_name,
                                'recipient'      => $user_email,
                                'recipient_name' => $user_name,
                                'subject'        => $subject,
                                'body'           => $body
                            ]
                        ];
                        addToMailQueue($data);
                    }

                    logAction("Login", "Success", "$user_name successfully logged in $extended_log", 0, $user_id);

                    // Session info
                    $_SESSION['user_id']    = $user_id;
                    $_SESSION['csrf_token'] = randomString(156);
                    $_SESSION['logged']     = true;

                    // Forcing MFA
                    if ($force_mfa == 1 && $token == NULL) {
                        $config_start_page = "user/mfa_enforcement.php";
                    }

                    // Setup encryption session key
                    if (!empty($user_encryption_ciphertext)) {
                        $site_encryption_master_key = decryptUserSpecificKey($user_encryption_ciphertext, $password);
                        generateUserSessionKey($site_encryption_master_key);
                    }

                    // Redirect to last visited or config home
                    if (isset($_GET['last_visited']) && (str_starts_with(base64_decode($_GET['last_visited']), '/agent') || str_starts_with(base64_decode($_GET['last_visited']), '/admin'))) {

                        redirect($_SERVER["REQUEST_SCHEME"] . "://" . $config_base_url . base64_decode($_GET['last_visited']));

                    } else {
                        redirect("agent/$config_start_page");
                    }

                } else {

                    // MFA is configured and needs to be confirmed, or was unsuccessful

                    // HTML code for the token input field
                    $token_field = "
                        <div class='input-group mb-3'>
                            <input type='text' inputmode='numeric' pattern='[0-9]*' maxlength='6'
                                   class='form-control' placeholder='Enter your 2FA code'
                                   name='current_code' required autofocus>
                            <div class='input-group-append'>
                              <div class='input-group-text'>
                                <span class='fas fa-key'></span>
                              </div>
                            </div>
                        </div>";

                    if ($current_code !== 0) {
                        // Logging
                        logAction("Login", "MFA Failed", "$user_email failed MFA", 0, $user_id);

                        // Email the tech to advise their credentials may be compromised
                        if (!empty($config_smtp_host)) {
                            $subject = "Important: $config_app_name failed 2FA login attempt for $user_name";
                            $body    = "Hi $user_name, <br><br>A recent login to your $config_app_name account was unsuccessful due to an incorrect 2FA code. If you did not attempt this login, your credentials may be compromised. <br><br>Thanks, <br>ITFlow";
                            $data    = [
                                [
                                    'from'           => $config_mail_from_email,
                                    'from_name'      => $config_mail_from_name,
                                    'recipient'      => $user_email,
                                    'recipient_name' => $user_name,
                                    'subject'        => $subject,
                                    'body'           => $body
                                ]
                            ];
                            addToMailQueue($data);
                        }

                        $response = "
                              <div class='alert alert-warning'>
                                Please Enter 2FA Code!
                                <button class='close' data-dismiss='alert'>&times;</button>
                              </div>";
                    }
                }

            // =========================
            // CLIENT LOGIN FLOW
            // =========================
            } elseif ($selectedType === 2) {

                if ($config_client_portal_enable != 1) {
                    // Client portal disabled
                    header("HTTP/1.1 401 Unauthorized");
                    logAction("Client Login", "Failed", "Client portal disabled; login attempt using $email");
                    $response = "
                      <div class='alert alert-danger'>
                        Incorrect username or password.
                        <button class='close' data-dismiss='alert'>&times;</button>
                      </div>";
                } else {

                    $client_id        = intval($selectedRow['contact_client_id']);
                    $contact_id       = intval($selectedRow['contact_id']);
                    $user_auth_method = sanitizeInput($selectedRow['user_auth_method']);

                    if ($client_id && $contact_id && $user_auth_method === 'local') {

                        $_SESSION['client_logged_in'] = true;
                        $_SESSION['client_id']        = $client_id;
                        $_SESSION['user_id']          = $user_id;
                        $_SESSION['user_type']        = 2;
                        $_SESSION['contact_id']       = $contact_id;
                        $_SESSION['login_method']     = "local";

                        logAction("Client Login", "Success", "Client contact $user_email successfully logged in locally", $client_id, $user_id);

                        header("Location: client/index.php");
                        exit();

                    } else {

                        // Not allowed or invalid
                        logAction("Client Login", "Failed", "Failed client portal login attempt using $email (invalid auth method or missing contact/client)", $client_id ?? 0, $user_id);

                        header("HTTP/1.1 401 Unauthorized");
                        $response = "
                          <div class='alert alert-danger'>
                            Incorrect username or password.
                            <button class='close' data-dismiss='alert'>&times;</button>
                          </div>";
                    }
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo nullable_htmlentities($company_name); ?> | Login</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">

    <!-- Favicon -->
    <?php if(file_exists('uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="/uploads/favicon.ico">
    <?php } ?>

    <!-- Theme style -->
    <link rel="stylesheet" href="plugins/adminlte/css/adminlte.min.css">

</head>
<body class="hold-transition login-page">

<div class="login-box">
    <div class="login-logo">
        <?php if (!empty($company_logo)) { ?>
            <img alt="<?=nullable_htmlentities($company_name)?> logo" height="110" width="380" class="img-fluid" src="<?php echo "uploads/settings/$company_logo"; ?>">
        <?php } else { ?>
            <span class="text-primary text-bold"><i class="fas fa-paper-plane mr-2"></i>IT</span>Flow
        <?php } ?>
    </div>

    <div class="card">
        <div class="card-body login-card-body">

            <?php if (!empty($config_login_message)){ ?>
            <p class="login-box-msg px-0"><?php echo nl2br($config_login_message); ?></p>
            <?php } ?>

            <?php if (isset($response)) { ?>
            <p><?php echo $response; ?></p>
            <?php } ?>

            <form method="post">

                <div class="input-group mb-3" <?php if (isset($token_field) && $token_field) { echo "hidden"; } ?>>
                    <input type="text" class="form-control" placeholder="Email" name="email"
                           value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES); ?>"
                           required <?php if (!isset($token_field) || !$token_field) { echo "autofocus"; } ?> >
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-envelope"></span>
                        </div>
                    </div>
                </div>

                <div class="input-group mb-3" <?php if (isset($token_field) && $token_field) { echo "hidden"; } ?>>
                    <input type="password" class="form-control" placeholder="Password" name="password"
                           value="<?php echo isset($token_field) && $token_field ? htmlspecialchars($password ?? '', ENT_QUOTES) : ''; ?>"
                           required>
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-lock"></span>
                        </div>
                    </div>
                </div>

                <?php
                // If agent needs MFA, show 2FA field + remember me
                if (isset($token_field) && $token_field) {

                    echo $token_field;
                ?>
                <div class="form-group mb-3">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="remember_me" name="remember_me">
                        <label class="custom-control-label" for="remember_me">Remember Me</label>
                    </div>
                </div>
                <?php } ?>

                <?php if ($show_role_choice): ?>
                    <!-- When both agent & client accounts exist with same email/password -->
                    <div class="mb-2 text-center">
                        <button type="submit" class="btn btn-primary btn-block mb-2" name="role_choice" value="agent">
                            Log in as Agent
                        </button>
                        <button type="submit" class="btn btn-success btn-block" name="role_choice" value="client">
                            Log in as Client
                        </button>
                    </div>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-block mb-3" name="login">Sign In</button>
                <?php endif; ?>

            </form>

            <?php if($config_client_portal_enable == 1){ ?>
                <hr>
                <?php if (!empty($config_smtp_host)) { ?>
                    <h5 class="text-center"><a href="client/login_reset.php">Forgot password?</a></h5>
                <?php } ?>
                <?php if (!empty($azure_client_id)) { ?>
                    <div class="col text-center mt-2">
                        <a href="client/login_microsoft.php">
                            <button type="button" class="btn btn-secondary">Login with Microsoft Entra</button>
                        </a>
                    </div>
                <?php } ?>
            <?php } ?>

        </div>
    </div>
</div>

<?php
if (isset($config_whitelabel_enabled) && !$config_whitelabel_enabled) {
    echo '<small class="text-muted">Powered by ITFlow</small>';
}
?>

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>

<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- AdminLTE App -->
<script src="plugins/adminlte/js/adminlte.min.js"></script>

<!-- Prevents resubmit on refresh or back -->
<script src="js/login_prevent_resubmit.js"></script>

</body>
</html>
