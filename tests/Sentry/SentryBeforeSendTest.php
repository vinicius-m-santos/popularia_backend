<?php

declare(strict_types=1);

namespace App\Tests\Sentry;

use App\Api\ApiProblem;
use App\Logging\RequestIdSubscriber;
use App\Sentry\SentryBeforeSend;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class SentryBeforeSendTest extends TestCase
{
    private function makeBeforeSend(?Request $request = null): SentryBeforeSend
    {
        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        return new SentryBeforeSend($stack);
    }

    private function requestWithId(string $id): Request
    {
        $req = Request::create('/api/v1/test');
        $req->attributes->set(RequestIdSubscriber::ATTRIBUTE, $id);

        return $req;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPasses5xxThrough(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new \RuntimeException('DB down')]);

        self::assertNotNull($fn($event, $hint));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDiscards404(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new NotFoundHttpException()]);

        self::assertNull($fn($event, $hint));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDiscards401(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new UnauthorizedHttpException('Bearer')]);

        self::assertNull($fn($event, $hint));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDiscardsApiProblem4xx(): void
    {
        $fn = $this->makeBeforeSend();

        foreach ([
            ApiProblem::invalidCredentials(),   // 401
            ApiProblem::forbidden('/e/test', 'Proibido'),   // 403
            ApiProblem::notFound(),             // 404
            ApiProblem::tooManyRequests(60),    // 429
        ] as $exception) {
            $hint = EventHint::fromArray(['exception' => $exception]);
            self::assertNull($fn(Event::createEvent(), $hint), 'Should discard '.$exception->getStatusCode());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPasses503Through(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => ApiProblem::serviceUnavailable()]);

        self::assertNotNull($fn($event, $hint));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAddsRequestIdTag(): void
    {
        $fn = $this->makeBeforeSend($this->requestWithId('test-req-id-123'));
        $event = Event::createEvent();

        $result = $fn($event, null);

        self::assertNotNull($result);
        self::assertSame('test-req-id-123', $result->getTags()['request_id'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPassesEventWithoutRequest(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();

        $result = $fn($event, null);

        self::assertNotNull($result);
        self::assertArrayNotHasKey('request_id', $result->getTags());
    }

    public function testPrivateRequestAndDiagnosticContextAreScrubbed(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://api.example.org/webhooks/brevo/secret-token?email=private@example.org', 'method' => 'POST', 'data' => ['password' => 'private'], 'headers' => ['Authorization' => 'secret']]);
        $event->setExtra(['private_content' => 'Texto político privado']);
        $event->setUser(\Sentry\UserDataBag::createFromArray(['id' => 'uuid', 'email' => 'private@example.org', 'ip_address' => '203.0.113.5']));
        $result = ($this->makeBeforeSend())($event, null);
        self::assertNotNull($result);
        self::assertSame(['url' => 'https://api.example.org/webhooks/brevo/[redacted]', 'method' => 'POST'], $result->getRequest());
        self::assertSame([], $result->getExtra());
        self::assertSame('0.0.0.0', $result->getUser()?->getIpAddress());
        self::assertNull($result->getUser()->getEmail());
    }
}
