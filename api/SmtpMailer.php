<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_i18n.php';

final class SmtpMailer
{
    private array $config;
    /** @var resource|null */
    private $socket = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function sendHtml(string $to, string $subject, string $html, string $plainText, ?string $logoPath = null, array $attachments = []): void
    {
        $host = trim((string)($this->config['host'] ?? ''));
        $port = (int)($this->config['port'] ?? 587);
        $encryption = strtolower(trim((string)($this->config['encryption'] ?? 'tls')));
        $username = trim((string)($this->config['username'] ?? ''));
        $password = (string)($this->config['password'] ?? '');
        $fromEmail = trim((string)($this->config['from_email'] ?? ''));
        $fromName = trim((string)($this->config['from_name'] ?? 'MeteoNexa'));
        $timeout = max(5, min(45, (int)($this->config['timeout_seconds'] ?? 18)));

        if ($host === '' || $fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP_NOT_CONFIGURED');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $fromEmail) === 1) {
            throw new RuntimeException('SMTP_ADDRESS_INVALID');
        }
        if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || $port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP_SERVER_INVALID');
        }
        // Credentials must never be sent over an unencrypted SMTP transport.
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            throw new RuntimeException('SMTP_INSECURE_TRANSPORT');
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        // Require TLS 1.2 or newer for both implicit TLS (465) and
        // STARTTLS. PHP versions lacking the TLS 1.2 client constant are too old
        // for this deployment and are rejected rather than silently downgrading.
        if (!defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            throw new RuntimeException('SMTP_TLS_VERSION_UNSUPPORTED');
        }
        $cryptoMethod = (int)constant('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT');
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= (int)constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
        }
        $this->socket = $this->openSocket($transport, $host, $port, $timeout, $cryptoMethod, $encryption);
        stream_set_timeout($this->socket, $timeout);

        try {
            $this->expect([220]);
            $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string)(gethostname() ?: '')) ?: 'localhost';
            $ehloResponse = $this->command('EHLO ' . $hostname, [250]);

            if ($encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
                if ($crypto !== true) {
                    throw new RuntimeException('SMTP_TLS_FAILED');
                }
                // Capabilities can change after STARTTLS, so always issue EHLO
                // again before choosing the authentication mechanism.
                $ehloResponse = $this->command('EHLO ' . $hostname, [250]);
            }

            if ($username !== '') {
                // Brevo advertises PLAIN, LOGIN and CRAM-MD5. Prefer AUTH PLAIN:
                // it was verified against smtp-relay.brevo.com on STARTTLS/587,
                // while AUTH LOGIN can be rejected with 535 for the same valid
                // credentials. The base64 payload stays inside the TLS session
                // and is never included in errors/log output.
                $authCaps = strtoupper($ehloResponse);
                if (preg_match('/(?:^|\r?\n)250[- ]AUTH(?:=|\s)([^\r\n]+)/i', $ehloResponse, $m) === 1) {
                    $methods = preg_split('/\s+/', strtoupper(trim((string)$m[1]))) ?: [];
                } else {
                    $methods = [];
                }

                if (in_array('PLAIN', $methods, true) || str_contains($authCaps, 'AUTH PLAIN')) {
                    $payload = base64_encode("\0" . $username . "\0" . $password);
                    $this->command('AUTH PLAIN ' . $payload, [235], true);
                } elseif (in_array('LOGIN', $methods, true) || str_contains($authCaps, 'AUTH LOGIN')) {
                    // Generic fallback for SMTP servers that do not advertise PLAIN.
                    $this->command('AUTH LOGIN', [334]);
                    $this->command(base64_encode($username), [334], true);
                    $this->command(base64_encode($password), [235], true);
                } else {
                    throw new RuntimeException('SMTP_AUTH_METHOD_UNSUPPORTED');
                }
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', [250], true);
            $this->command('RCPT TO:<' . $to . '>', [250, 251], true);
            $this->command('DATA', [354]);

            $message = $this->buildMessage($fromEmail, $fromName, $to, $subject, $html, $plainText, $logoPath, $attachments);
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            fwrite($this->socket, $message . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /**
     * Shared-hosting OpenSSL builds do not all accept an explicit TLS bitmask on
     * an implicit-TLS (smtps/465) stream. Try the strict context first; when the
     * failure happens before SMTP starts, retry once with OpenSSL negotiation
     * and then verify that the negotiated protocol is still TLS 1.2+.
     *
     * @return resource
     */
    private function openSocket(string $transport, string $host, int $port, int $timeout, int $cryptoMethod, string $encryption)
    {
        if ($encryption !== 'ssl') {
            $context = stream_context_create();
            $socket = @stream_socket_client(
                $transport . $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
            if (!is_resource($socket)) throw new RuntimeException('SMTP_CONNECTION_FAILED');
            return $socket;
        }

        $baseSsl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ];

        $strict = $baseSsl;
        $strict['crypto_method'] = $cryptoMethod;
        $strictContext = stream_context_create(['ssl'=>$strict]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $strictContext
        );
        if (is_resource($socket)) return $socket;

        // Compatibility retry is safe only because it occurs before any SMTP
        // command/credential is sent and certificate validation remains enabled.
        $compatContext = stream_context_create(['ssl'=>$baseSsl]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $compatContext
        );
        if (!is_resource($socket)) throw new RuntimeException('SMTP_CONNECTION_FAILED');

        $metadata = stream_get_meta_data($socket);
        $protocol = strtoupper(trim((string)($metadata['crypto']['protocol'] ?? '')));
        if (!in_array($protocol, ['TLSV1.2','TLSV1.3','TLS1.2','TLS1.3'], true)) {
            fclose($socket);
            throw new RuntimeException('SMTP_TLS_VERSION_UNSUPPORTED');
        }
        return $socket;
    }


    public static function sendNativeHtml(array $config, string $to, string $subject, string $html, string $plainText, ?string $logoPath = null, array $attachments = []): void
    {
        if (!function_exists('mail')) throw new RuntimeException('MAIL_NATIVE_UNAVAILABLE');
        $fromEmail = trim((string)($config['from_email'] ?? ''));
        $fromName = trim((string)($config['from_name'] ?? 'MeteoNexa'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP_ADDRESS_INVALID');
        if (preg_match('/[\r\n]/', $to . $fromEmail . $fromName . $subject) === 1) throw new RuntimeException('MAIL_HEADER_INVALID');

        $parts = self::buildMimeBody($html, $plainText, $logoPath, $attachments, 'Native');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $encodedFrom . ' <' . $fromEmail . '>',
            'Content-Type: multipart/mixed; boundary="' . $parts['mixed'] . '"',
            'X-Mailer: MeteoNexa PHP mail',
        ];
        $sent = @mail($to, $encodedSubject, $parts['body'], implode("\r\n", $headers));
        if ($sent !== true) throw new RuntimeException('MAIL_NATIVE_FAILED');
    }

    private function buildMessage(string $fromEmail, string $fromName, string $to, string $subject, string $html, string $plainText, ?string $logoPath, array $attachments): string
    {
        $parts = self::buildMimeBody($html, $plainText, $logoPath, $attachments, 'Smtp');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $domain = preg_replace('/[^a-z0-9.-]/i', '', substr(strrchr($fromEmail, '@') ?: '@localhost', 1)) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encodedFrom . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $parts['mixed'] . '"',
            'X-Mailer: MeteoNexa SMTP',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $parts['body'];
    }

    private static function buildMimeBody(string $html, string $plainText, ?string $logoPath, array $attachments, string $prefix): array
    {
        $mixed = '=_MeteoNexa' . $prefix . 'Mix_' . bin2hex(random_bytes(12));
        $related = '=_MeteoNexa' . $prefix . 'Rel_' . bin2hex(random_bytes(12));
        $alternative = '=_MeteoNexa' . $prefix . 'Alt_' . bin2hex(random_bytes(12));
        $body = [
            '--' . $mixed,
            'Content-Type: multipart/related; boundary="' . $related . '"',
            '',
            '--' . $related,
            'Content-Type: multipart/alternative; boundary="' . $alternative . '"',
            '',
            '--' . $alternative,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($plainText),
            '--' . $alternative,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($html),
            '--' . $alternative . '--',
        ];
        if ($logoPath && is_file($logoPath) && is_readable($logoPath)) {
            $logo = file_get_contents($logoPath);
            if ($logo !== false) {
                $body[] = '--' . $related;
                $body[] = 'Content-Type: image/png; name="meteonexa-logo.png"';
                $body[] = 'Content-Transfer-Encoding: base64';
                $body[] = 'Content-ID: <meteonexa-logo>';
                $body[] = 'Content-Disposition: inline; filename="meteonexa-logo.png"';
                $body[] = '';
                $body[] = rtrim(chunk_split(base64_encode($logo), 76, "\r\n"));
            }
        }
        $body[] = '--' . $related . '--';

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $path = (string)($attachment['path'] ?? '');
            if ($path === '' || !is_file($path) || !is_readable($path)) throw new RuntimeException('MAIL_ATTACHMENT_UNREADABLE');
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new RuntimeException('MAIL_ATTACHMENT_UNREADABLE');
            $name = self::safeAttachmentName((string)($attachment['name'] ?? 'allegato.bin'));
            $mime = strtolower(trim((string)($attachment['mime'] ?? 'application/octet-stream')));
            if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) !== 1) $mime = 'application/octet-stream';
            $encodedName = rawurlencode($name);
            $body[] = '--' . $mixed;
            $body[] = 'Content-Type: ' . $mime . '; name*=UTF-8\'\'' . $encodedName;
            $body[] = 'Content-Transfer-Encoding: base64';
            $body[] = 'Content-Disposition: attachment; filename*=UTF-8\'\'' . $encodedName;
            $body[] = '';
            $body[] = rtrim(chunk_split(base64_encode($bytes), 76, "\r\n"));
        }
        $body[] = '--' . $mixed . '--';
        $body[] = '';
        return ['mixed'=>$mixed, 'body'=>implode("\r\n", $body)];
    }

    private static function safeAttachmentName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", "\0", '/', '\\'], '_', $name));
        if ($name === '') $name = 'allegato.bin';
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 120, 'UTF-8');
        else $name = substr($name, 0, 120);
        return $name;
    }

    private function command(string $command, array $expected, bool $redact = false): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP_CONNECTION_UNAVAILABLE');
        }
        fwrite($this->socket, $command . "\r\n");
        return $this->expect($expected, $redact ? meteonexa_backend_text('api.backend.redacted') : $command);
    }

    private function expect(array $expected, string $context = ''): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP_CONNECTION_UNAVAILABLE');
        }
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $meta = stream_get_meta_data($this->socket);
        if (($meta['timed_out'] ?? false) === true) {
            throw new RuntimeException(meteonexa_backend_text('api.backend.smtp_timeout'));
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            // Never propagate provider responses or SMTP commands: they may
            // contain addresses, server banners or other deployment details.
            $safeCode = $code >= 100 && $code <= 599 ? (string)$code : 'UNKNOWN';
            throw new RuntimeException('SMTP_ERROR_' . $safeCode);
        }
        return $response;
    }
}
