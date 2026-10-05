<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

final class SmtpMailer
{
    public function send(string $to, string $subject, string $text, string $html): void
    {
        $host = trim(getenv('SMTP_HOST') ?: '');
        $port = filter_var(getenv('SMTP_PORT') ?: '587', FILTER_VALIDATE_INT);
        $username = getenv('SMTP_USERNAME') ?: '';
        $password = getenv('SMTP_PASSWORD') ?: '';
        $encryption = strtolower(trim(getenv('SMTP_ENCRYPTION') ?: 'starttls'));
        $from = trim(getenv('MAIL_FROM_ADDRESS') ?: '');
        $fromName = trim(getenv('MAIL_FROM_NAME') ?: 'Obong University');

        if (
            $host === '' || str_contains($host, "\r") || str_contains($host, "\n")
            || $port === false || $port < 1 || $port > 65535
            || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !filter_var($to, FILTER_VALIDATE_EMAIL)
            || !in_array($encryption, ['starttls', 'ssl'], true)
            || (($username === '') !== ($password === ''))
        ) {
            throw new \RuntimeException('SMTP configuration is incomplete or invalid.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
            ],
        ]);
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new \RuntimeException("Could not connect to configured SMTP server ({$errorCode}).");
        }

        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, [220]);
            $domain = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
            $this->command($socket, "EHLO {$domain}", [250]);

            if ($encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Could not establish TLS with the SMTP server.');
                }
                $this->command($socket, "EHLO {$domain}", [250]);
            }

            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->write($socket, $this->message($to, $from, $fromName, $subject, $text, $html));
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function message(string $to, string $from, string $fromName, string $subject, string $text, string $html): string
    {
        foreach ([$to, $from, $fromName, $subject] as $headerValue) {
            if (str_contains($headerValue, "\r") || str_contains($headerValue, "\n")) {
                throw new \RuntimeException('Email header contains an invalid line break.');
            }
        }

        $boundary = '=_obong_' . bin2hex(random_bytes(16));
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . '--' . $boundary . "--\r\n";

        $message = "From: {$encodedName} <{$from}>\r\n"
            . "To: <{$to}>\r\n"
            . "Subject: {$encodedSubject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
            . $body;
        $lines = preg_split('/\r\n|\r|\n/', rtrim($message, "\r\n"));
        $lines = array_map(static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line, $lines);

        return implode("\r\n", $lines) . "\r\n.\r\n";
    }

    private function command($socket, string $command, array $expectedCodes): void
    {
        $this->write($socket, $command . "\r\n");
        $this->expect($socket, $expectedCodes);
    }

    private function expect($socket, array $expectedCodes): void
    {
        do {
            $line = fgets($socket, 515);
            if ($line === false || !preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                throw new \RuntimeException('SMTP server returned an invalid response.');
            }
            $code = (int) $matches[1];
            $more = $matches[2] === '-';
        } while ($more);

        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException("SMTP server rejected a command with response code {$code}.");
        }
    }

    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write to the SMTP server.');
            }
            $offset += $written;
        }
    }
}
