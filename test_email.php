<?php

require_once __DIR__ . '/classes/Mailer.php';

try {
    $mailer = new Mailer();

    $sent = $mailer->send(
        'bnbsshi@gmail.com',
        'HRIS Email Test',
        '
            <div style="font-family:Arial,sans-serif;padding:20px;">
                <h2 style="color:#16a085;">
                    HRIS Email Test Successful!
                </h2>
                <p>Your Gmail SMTP configuration is working.</p>
                <p>You can now proceed with password reset testing.</p>
            </div>
        ',
        'HRIS Email Test Successful! Your Gmail SMTP configuration is working.'
    );

    if ($sent) {
        echo 'SUCCESS: Test email sent successfully!';
    } else {
        echo 'ERROR: Email could not be sent. Check your PHP error log.';
    }

} catch (Throwable $e) {

    error_log('HRIS email test failed: ' . $e->getMessage());

    echo 'ERROR: Check your PHP error log.';
}
