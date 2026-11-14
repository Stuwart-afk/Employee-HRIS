<?php

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../classes/Mailer.php';

$account = require_access(
    'user_management',
    'edit',
    'all'
);

require_once __DIR__ . '/../classes/User.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

csrf_check($account['role']);

$userId = (int)($_POST['user_id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['user_error'] = 'Invalid account selected.';
    header('Location: /admin/users.php');
    exit;
}

try {

    $userModel = new User($pdo);

    $sent = $userModel->resendSetupInvitation($userId);

    if ($sent) {
        $_SESSION['user_message'] =
            'A new password setup invitation was successfully sent.';
    } else {
        $_SESSION['user_error'] =
            'Please wait for the 10mins to Resend an Invitation';
    }

} catch (Throwable $e) {

    error_log(
        'Resend invitation error: ' . $e->getMessage()
    );

    $_SESSION['user_error'] =
        'Could not resend invitation. Please check the server logs.';
}

header('Location: /admin/users.php');
exit;
