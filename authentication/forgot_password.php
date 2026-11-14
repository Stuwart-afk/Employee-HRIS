<?php

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Mailer.php';

$userModel = new User($pdo);

$message = '';
$error = '';

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // STEP 1: Validate CSRF token.
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {

        $error = 'Invalid request. Please refresh the page and try again.';

    } else {

        // STEP 2: Validate email.
        $email = trim($_POST['email'] ?? '');

        if (
            $email === '' ||
            strlen($email) > 150 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {

            $error = 'Please enter a valid email address.';

        } else {

            try {

                // STEP 3: Check if email exists and account is eligible.
                $st = $pdo->prepare("
                    SELECT
                        u.id,
                        u.employee_id,
                        u.status,
                        u.setup_completed,
                        e.email

                    FROM users u

                    INNER JOIN employees e
                        ON e.id = u.employee_id

                    WHERE LOWER(TRIM(e.email)) = LOWER(TRIM(:email))

                    LIMIT 1
                ");

                $st->execute([
                    'email' => $email
                ]);

                $account = $st->fetch(PDO::FETCH_ASSOC);

                // STEP 4: Check if email exists.
                if (!$account) {

                    $error = 'This email address is not registered in our HRIS system.';

                    error_log('FORGOT PASSWORD: Email not found.');

                }

                // STEP 5: Check account status.
                elseif ($account['status'] !== 'Active') {

                    $error = 'Your account is inactive. Please contact HR.';

                }

                // STEP 6: Check account setup.
                elseif ((int) $account['setup_completed'] !== 1) {

                    $error = 'Your account setup is incomplete. Please contact HR.';

                }

                else {

                    // STEP 7: Generate secure reset token.
                    $token = $userModel->createPasswordResetToken(
                        (int) $account['id']
                    );

                    // STEP 8: Create reset link.
                    $mailer = new Mailer();

                    $resetLink = $mailer->getAppUrl()
                        . '/authentication/reset_password.php?token='
                        . urlencode($token);

                    $safeLink = htmlspecialchars(
                        $resetLink,
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    // STEP 9: Prepare HTML email.
                    $htmlBody = "
                    <div style='
                        font-family:Arial,sans-serif;
                        background:#080b18;
                        padding:30px;
                        color:#ffffff;
                    '>

                        <div style='
                            max-width:550px;
                            margin:auto;
                            background:#1e1a2e;
                            padding:35px;
                            border-radius:16px;
                        '>

                            <h2 style='color:#76f5e8;'>
                                HRIS Password Reset
                            </h2>

                            <p>
                                You requested to reset your HRIS password.
                            </p>

                            <p>
                                Click the button below to create a new password.
                            </p>

                            <p style='margin:30px 0;'>

                                <a href='{$safeLink}'
                                   style='
                                       background:#3b82f6;
                                       color:#ffffff;
                                       padding:14px 25px;
                                       border-radius:8px;
                                       text-decoration:none;
                                       font-weight:bold;
                                       display:inline-block;
                                   '>
                                    Reset Password
                                </a>

                            </p>

                            <p>
                                This link expires in 10 minutes
                                and can only be used once.
                            </p>

                            <p style='
                                color:#9ca3af;
                                font-size:13px;
                            '>
                                If you did not request this reset,
                                you can safely ignore this email.
                            </p>

                        </div>

                    </div>
                    ";

                    // STEP 10: Prepare plain text email.
                    $plainBody = "
HRIS Password Reset

You requested to reset your HRIS password.

Open this link to create a new password:

{$resetLink}

This link expires in 10 minutes.

If you did not request this, ignore this email.
                    ";

                    // STEP 11: Send email.
                    $sent = $mailer->send(
                        $account['email'],
                        'HRIS - Password Reset',
                        $htmlBody,
                        $plainBody
                    );

                    // STEP 12: Check email sending result.
                    if ($sent) {

                        $message = 'Password reset link successfully sent! Please check your inbox.';

                        error_log(
                            'FORGOT PASSWORD: Email successfully sent for user ID: '
                            . $account['id']
                        );

                    } else {

                        $error = 'Unable to send the password reset email. Please try again later.';

                        error_log(
                            'FORGOT PASSWORD: Email sending failed for user ID: '
                            . $account['id']
                        );

                    }
                }

            } catch (Throwable $e) {

                error_log(
                    'FORGOT PASSWORD ERROR: ' . $e->getMessage()
                );

                $error = 'Something went wrong. Please try again later.';

            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
        content="width=device-width, initial-scale=1.0">

    <meta name="referrer" content="no-referrer">

    <title>Forgot Password | HRIS</title>

    <link rel="stylesheet"
        href="../assets/css/forgot-password.css">

</head>

<body>

    <div class="hris-login">

        <div class="forgot-card">

            <div class="forgot-header">

                <h1>Forgot Password?</h1>

                <p>
                    No worries! Enter your registered employee email
                    and we'll send you a password reset link.
                </p>

            </div>

            <?php if ($message !== ''): ?>

                <div class="success-message" role="status">

                    <?= htmlspecialchars(
                        $message,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php elseif ($error !== ''): ?>

                <div class="error-message" role="alert">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION['csrf_token'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your registered email"
                    maxlength="150"
                    autocomplete="email"
                    required>

                <button type="submit">
                    Send Reset Link
                </button>

            </form>

            <div class="back-login">

                <a href="login.php">
                    ← Back to Login
                </a>

            </div>

        </div>

    </div>

</body>

</html>
