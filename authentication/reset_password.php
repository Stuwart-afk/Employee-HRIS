<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$userModel = new User($pdo);

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$account = null;
$error = '';
$success = false;

// Prevent caching of password reset pages.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

// Validate token format.
if (
    $token !== '' &&
    preg_match('/\A[a-f0-9]{64}\z/', $token)
) {
    $account = $userModel->findValidPasswordResetToken($token);
}

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF.
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {

        $error = 'Invalid request. Please refresh the page and try again.';

    } elseif (!$account) {

        $error = 'This password reset link is invalid, expired, or already used.';

    } else {

        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($password) || !is_string($confirmPassword)) {

            $error = 'Invalid password submission.';

        } elseif (strlen($password) < 12) {

            $error = 'Your password must contain at least 12 characters.';

        } elseif (strlen($password) > 72) {

            $error = 'Your password must not exceed 72 bytes.';

        } elseif ($password !== $confirmPassword) {

            $error = 'Passwords do not match.';

        } else {

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            try {

                $success = $userModel->completePasswordReset(
                    (int) $account['token_id'],
                    (int) $account['user_id'],
                    $passwordHash
                );

                if ($success) {

                    // Rotate the CSRF token.
                    $_SESSION['csrf_token'] = bin2hex(
                        random_bytes(32)
                    );

                } else {

                    $error = 'This reset link is no longer valid. Please request another one.';
                }

            } catch (Throwable $e) {

                error_log(
                    'Password reset error: ' . $e->getMessage()
                );

                $error = 'Unable to reset your password. Please try again later.';
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

    <title>Reset Password | HRIS</title>

    <link rel="stylesheet"
        href="../assets/css/forgot-password.css">

</head>

<body>

<div class="hris-login">

    <div class="forgot-card">

        <?php if ($success): ?>

            <div class="forgot-header">

                <h1>Password Updated!</h1>

                <p>
                    Your HRIS password has been successfully changed.
                    You can now sign in using your new password.
                </p>

            </div>

            <a class="login-link" href="login.php">
                Continue to Login
            </a>

        <?php elseif (!$account): ?>

            <div class="forgot-header">

                <h1>Invalid Reset Link</h1>

                <p>
                    This link may have expired or already been used.
                    Please request another password reset link.
                </p>

            </div>

            <a class="login-link" href="forgot_password.php">
                Request New Reset Link
            </a>

        <?php else: ?>

            <div class="forgot-header">

                <h1>Create New Password</h1>

                <p>
                    Enter your new HRIS account password below.
                </p>

            </div>

            <?php if ($error !== ''): ?>

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
                    name="token"
                    value="<?= htmlspecialchars(
                        $token,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION['csrf_token'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <label for="password">
                    New Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter new password"
                    minlength="12"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <label for="confirm_password">
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Confirm new password"
                    minlength="12"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">
                    Reset Password
                </button>

            </form>

        <?php endif; ?>

    </div>

</div>

</body>

</html>
