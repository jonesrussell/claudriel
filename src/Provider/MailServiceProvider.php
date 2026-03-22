<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Mail\Transport\LocalTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

final class MailServiceProvider extends ServiceProvider
{
    private ?MailerInterface $mailer = null;

    public function __construct(
        private ?TransportInterface $transport = null,
    ) {}

    public function register(): void
    {
        // DI registration when resolver supports set()
    }

    public function getMailer(): MailerInterface
    {
        if ($this->mailer === null) {
            $transport = $this->transport ?? $this->createDefaultTransport();
            $fromAddress = $_ENV['MAIL_FROM'] ?? getenv('MAIL_FROM') ?: 'noreply@claudriel.ai';

            $this->mailer = new Mailer($transport, $fromAddress);
        }

        return $this->mailer;
    }

    private function createDefaultTransport(): TransportInterface
    {
        $storageDir = dirname(__DIR__, 2).'/storage';
        if (! is_dir($storageDir) && ! mkdir($storageDir, 0o755, true)) {
            error_log('Mail: could not create storage/ directory');
        }

        return new LocalTransport($storageDir.'/mail.log');
    }
}
