<?php

declare(strict_types=1);

namespace Velk;

/**
 * Minimal mail sender. Uses SMTP when SMTP_HOST/SMTP_USER/SMTP_PASS are set,
 * falls back to PHP's mail() otherwise.
 *
 * No dependencies — talks SMTP directly over a stream socket. Supports both
 * implicit SSL (port 465) and STARTTLS (default port 587).
 */
final class Mailer
{
    private const TIMEOUT = 30;

    public function __construct(
        public readonly ?string $host = null,
        public readonly int $port = 587,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly string $from = '',
    ) {}

    public static function fromEnv(): self
    {
        $host = (string) Env::get('SMTP_HOST', '');
        $user = (string) Env::get('SMTP_USER', '');
        $pass = (string) Env::get('SMTP_PASS', '');
        $defaultFrom = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return new self(
            host:     $host !== '' ? $host : null,
            port:     (int) Env::get('SMTP_PORT', 587),
            username: $user !== '' ? $user : null,
            password: $pass !== '' ? $pass : null,
            from:     (string) Env::get('SMTP_FROM', 'noreply@' . $defaultFrom),
        );
    }

    public function isSmtp(): bool
    {
        return $this->host !== null && $this->username !== null && $this->password !== null;
    }

    /**
     * Send a mail. Throws RuntimeException on failure.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        $textBody ??= self::htmlToText($htmlBody);

        if ($this->isSmtp()) {
            $this->sendSmtp($to, $subject, $htmlBody, $textBody);
            return;
        }

        $this->sendPhpMail($to, $subject, $htmlBody, $textBody);
    }

    // ─────────── SMTP ───────────

    private function sendSmtp(string $to, string $subject, string $html, string $text): void
    {
        $implicitSsl = $this->port === 465;
        $protocol = $implicitSsl ? 'ssl' : 'tcp';
        $endpoint = sprintf('%s://%s:%d', $protocol, $this->host, $this->port);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("SMTP connect failed ({$endpoint}): {$errstr} [{$errno}]");
        }

        stream_set_timeout($socket, self::TIMEOUT);

        try {
            $this->expect($socket, 220);

            $localHost = (string) ($_SERVER['HTTP_HOST'] ?? gethostname() ?: 'localhost');
            $this->command($socket, "EHLO {$localHost}");
            $this->expect($socket, 250);

            if (!$implicitSsl) {
                $this->command($socket, 'STARTTLS');
                $this->expect($socket, 220);
                $crypto = stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if ($crypto !== true) {
                    throw new \RuntimeException('STARTTLS upgrade failed.');
                }
                $this->command($socket, "EHLO {$localHost}");
                $this->expect($socket, 250);
            }

            // AUTH LOGIN
            $this->command($socket, 'AUTH LOGIN');
            $this->expect($socket, 334);
            $this->command($socket, base64_encode((string) $this->username));
            $this->expect($socket, 334);
            $this->command($socket, base64_encode((string) $this->password));
            $this->expect($socket, 235);

            $fromAddr = self::extractAddress($this->from);
            $this->command($socket, "MAIL FROM:<{$fromAddr}>");
            $this->expect($socket, 250);

            $this->command($socket, "RCPT TO:<{$to}>");
            $this->expect($socket, [250, 251]);

            $this->command($socket, 'DATA');
            $this->expect($socket, 354);

            $message = $this->buildMessage($to, $subject, $html, $text);
            // RFC 5321 dot-stuffing
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT');
            // Don't strictly require 221 — some servers close early.
        } finally {
            @fclose($socket);
        }
    }

    private function buildMessage(string $to, string $subject, string $html, string $text): string
    {
        $boundary = bin2hex(random_bytes(16));
        $headers = [
            'From: ' . $this->from,
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::extractDomain($this->from) . '>',
        ];

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n"
            . "--{$boundary}--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function command($socket, string $cmd): void
    {
        fwrite($socket, $cmd . "\r\n");
    }

    /**
     * @param int|list<int> $expected
     */
    private function expect($socket, int|array $expected): string
    {
        $expected = (array) $expected;
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) break;
            $response .= $line;
            // Multi-line responses use '-' at position 3; final line uses ' '.
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(
                "SMTP expected " . implode('/', $expected) . ", got: " . trim($response)
            );
        }
        return $response;
    }

    // ─────────── PHP mail() fallback ───────────

    private function sendPhpMail(string $to, string $subject, string $html, string $text): void
    {
        $boundary = bin2hex(random_bytes(16));
        $headers = [
            'From: ' . $this->from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n"
            . "--{$boundary}--";

        $ok = @mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers));
        if ($ok !== true) {
            throw new \RuntimeException('PHP mail() returned false. Configure SMTP_* env vars to use SMTP instead.');
        }
    }

    // ─────────── Helpers ───────────

    private static function extractAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return $m[1];
        }
        return trim($from);
    }

    private static function extractDomain(string $from): string
    {
        $addr = self::extractAddress($from);
        $at = strrpos($addr, '@');
        return $at === false ? 'localhost' : substr($addr, $at + 1);
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|div|h[1-6]|li|tr)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
