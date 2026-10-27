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

    /** @test */
    public function it_passes_5xx_through(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new \RuntimeException('DB down')]);

        self::assertNotNull($fn($event, $hint));
    }

    /** @test */
    public function it_discards_404(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new NotFoundHttpException()]);

        self::assertNull($fn($event, $hint));
    }

    /** @test */
    public function it_discards_401(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new UnauthorizedHttpException('Bearer')]);

        self::assertNull($fn($event, $hint));
    }

    /** @test */
    public function it_discards_api_problem_4xx(): void
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

    /** @test */
    public function it_passes_503_through(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => ApiProblem::serviceUnavailable()]);

        self::assertNotNull($fn($event, $hint));
    }

    /** @test */
    public function it_adds_request_id_tag(): void
    {
        $fn = $this->makeBeforeSend($this->requestWithId('test-req-id-123'));
        $event = Event::createEvent();

        $result = $fn($event, null);

        self::assertNotNull($result);
        self::assertSame('test-req-id-123', $result->getTags()['request_id'] ?? null);
    }

    /** @test */
    public function it_passes_event_without_request(): void
    {
        $fn = $this->makeBeforeSend();
        $event = Event::createEvent();

        $result = $fn($event, null);

        self::assertNotNull($result);
        self::assertArrayNotHasKey('request_id', $result->getTags());
    }
}
