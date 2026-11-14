<?php
// classes/Mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/mail.php';
    }

    public function getAppUrl(): string
    {
        return rtrim($this->config['app_url'], '/');
    }

    public function send(
    string $recipient,
    string $subject,
    string $htmlBody,
    string $plainBody = ''
): bool {

    try {
        $mail = new PHPMailer(true);

        // TEMPORARY SMTP DEBUGGING
        $mail->SMTPDebug = 2;

        $mail->Debugoutput = function ($str, $level) {
            error_log("PHPMailer DEBUG [$level]: $str");
        };

        $mail->isSMTP();

        $mail->Host = $this->config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];

        if (strtolower($this->config['encryption']) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = (int) $this->config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;

        $mail->setFrom(
            $this->config['from_email'],
            $this->config['from_name']
        );

        $mail->addAddress($recipient);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody ?: strip_tags($htmlBody);

        $mail->send();

        error_log('HRIS MAIL: Email successfully sent.');

        return true;

    } catch (Throwable $e) {

        error_log('HRIS MAIL ERROR: ' . $e->getMessage());

        if (isset($mail)) {
            error_log('HRIS MAILER INFO: ' . $mail->ErrorInfo);
        }

        return false;
    }
}
}
