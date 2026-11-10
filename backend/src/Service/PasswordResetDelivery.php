<?php
declare(strict_types=1);

namespace Vantiq\Service;

interface PasswordResetDelivery
{
    public function isConfigured(): bool;

    public function send(string $email, string $resetUrl): void;
}
