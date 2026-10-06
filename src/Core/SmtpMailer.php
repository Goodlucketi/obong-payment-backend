<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer
{
    public function send(
        string $to,
        string $subject,
        string $text,
        string $html
    ): void {
        $host = trim((string) (getenv('SMTP_HOST') ?: 'smtp.hostinger.com'));
        $port = (int) (getenv('SMTP_PORT') ?: 465);
        $username = trim((string) (getenv('SMTP_USERNAME') ?: ''));
        $password = (string) (getenv('SMTP_PASSWORD') ?: '');
        $encryption = strtolower(
            trim((string) (getenv('SMTP_ENCRYPTION') ?: 'ssl'))
        );

        $from = trim(
            (string) (
                getenv('MAIL_FROM_ADDRESS')
                ?: $username
            )
        );

        $fromName = trim(
            (string) (
                getenv('MAIL_FROM_NAME')
                ?: 'Obong University'
            )
        );

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid recipient email address.');
        }

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid sender email address.');
        }

        if ($host === '') {
            throw new \RuntimeException('SMTP host is not configured.');
        }

        if ($username === '' || $password === '') {
            throw new \RuntimeException('SMTP username or password is not configured.');
        }

        if (!in_array($encryption, ['ssl', 'starttls'], true)) {
            throw new \RuntimeException(
                'SMTP encryption must be either ssl or starttls.'
            );
        }

        $mail = new PHPMailer(true);

        $mail->SMTPDebug = 2;
        $mail->Debugoutput = static function ($str, $level) {
            error_log("SMTP DEBUG [$level]: $str");
        };

        try {
            // SMTP configuration
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;

            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = $port;

            // Sender
            $mail->setFrom($from, $fromName);

            // Recipient
            $mail->addAddress($to);

            // Email content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';

            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;

            // Send
            $mail->send();

        } catch (Exception $exception) {

            error_log(
                'PHPMailer error: ' . $mail->ErrorInfo
            );

            throw new \RuntimeException(
                'Email could not be sent: ' . $mail->ErrorInfo,
                0,
                $exception
            );
        }
    }
}