<?php
declare(strict_types=1);

namespace Vantiq\Service;

use Vantiq\Config\Config;
use Vantiq\Http\ApiException;

final class PhpMailPasswordResetDelivery implements PasswordResetDelivery
{
    public function __construct(private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->get('mail_transport') === 'php_mail'
            && filter_var((string) $this->config->get('mail_from'), FILTER_VALIDATE_EMAIL) !== false
            && filter_var((string) $this->config->get('reset_url'), FILTER_VALIDATE_URL) !== false;
    }

    public function send(string $email, string $resetUrl): void
    {
        if (!$this->isConfigured()) {
            throw new ApiException(503, 'mail_unavailable', 'Password reset delivery is not configured.');
        }

        $from = (string) $this->config->get('mail_from');
        $subject = 'VANTIQ password reset';
        $body = "A password reset was requested for your VANTIQ account.\r\n\r\n"
            . "Use this single-use link within 30 minutes:\r\n" . $resetUrl
            . "\r\n\r\nIf you did not request this change, you can ignore this message.";
        $headers = [
            'From' => $from,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff'
        ];

        if (!mail($email, $subject, $body, $headers)) {
            throw new ApiException(503, 'mail_delivery_failed', 'Password reset delivery could not be completed.');
        }
    }
}
