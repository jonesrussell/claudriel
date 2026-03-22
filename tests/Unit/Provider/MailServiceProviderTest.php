<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\MailServiceProvider as ClaudrielMailServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Mail\Transport\ArrayTransport;

final class MailServiceProviderTest extends TestCase
{
    public function test_get_mailer_returns_mailer_interface(): void
    {
        $provider = new ClaudrielMailServiceProvider;
        $mailer = $provider->getMailer();

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    public function test_send_records_envelope(): void
    {
        $transport = new ArrayTransport;
        $provider = new ClaudrielMailServiceProvider($transport);
        $mailer = $provider->getMailer();

        $envelope = new Envelope(
            to: ['test@example.com'],
            from: 'claudriel@claudriel.ai',
            subject: 'Test',
            textBody: 'Hello',
        );

        $mailer->send($envelope);

        self::assertCount(1, $transport->getSent());
    }

    public function test_singleton_returns_same_instance(): void
    {
        $provider = new ClaudrielMailServiceProvider;
        self::assertSame($provider->getMailer(), $provider->getMailer());
    }
}
