<?php

namespace Tests\Feature;

use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Sentry hanya aktif bila SENTRY_DSN diisi; bila aktif, error yang dilaporkan aplikasi ikut terkirim.
 */
class SentryIntegrationTest extends TestCase
{
    public function test_sentry_is_inactive_without_a_dsn(): void
    {
        $this->assertNull(config('sentry.dsn'));
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn());
    }

    public function test_personal_data_is_not_sent_by_default(): void
    {
        $this->assertFalse((bool) config('sentry.send_default_pii'));
    }

    public function test_reported_exceptions_are_captured_when_sentry_is_configured(): void
    {
        $transport = new class implements TransportInterface
        {
            /** @var array<int, Event> */
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        SentrySdk::getCurrentHub()->bindClient(
            ClientBuilder::create(['dsn' => 'https://public@sentry.example.test/1'])->setTransport($transport)->getClient()
        );

        report(new RuntimeException('Gagal menghitung emisi'));

        $this->assertCount(1, $transport->events);
        $this->assertSame('Gagal menghitung emisi', $transport->events[0]->getExceptions()[0]->getValue());
    }
}
