<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Token;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    #[Test]
    public function parsing_a_response_header(): void
    {
        $factory = new HttpFactory();
        $response = $factory->createResponse()
            ->withStatus(302)
            ->withHeader('cache-status', 'ReverseProxyCache; hit')
            ->withAddedHeader('Cache-Status', 'ForwardProxyCache; fwd=uri-miss; collapsed; stored')
            ->withAddedHeader('cache-Status', 'BrowserCache; fwd=uri-miss; key="Hello Boy!"');

        $fieldList = Field::fromHttpValue($response->getHeaderLine('cache-Status'), $response->getStatusCode());

        self::assertCount(3, $fieldList);

        $closestToOrigin = $fieldList->closestToOrigin();
        $closestToClient = $fieldList->closestToUser();
        $intermediary = $fieldList[1];

        self::assertInstanceOf(HandledRequestCache::class, $closestToOrigin);
        self::assertInstanceOf(HandledRequestCache::class, $closestToClient);
        self::assertSame($fieldList[-1], $closestToClient);
        self::assertFalse($fieldList->isEmpty());

        self::assertNull($closestToOrigin->forward);

        self::assertInstanceOf(Forward::class, $intermediary->forward);
        self::assertSame($response->getStatusCode(), $intermediary->forward->statusCode);

        self::assertInstanceOf(Forward::class, $closestToClient->forward);
        self::assertSame($response->getStatusCode(), $closestToClient->forward->statusCode);
        self::assertFalse(isset($fieldList[42]));
        self::assertFalse($fieldList->contains('Foobar'));
        self::assertTrue($fieldList->contains(Token::fromString('BrowserCache')));
        self::assertFalse($fieldList->contains('BrowserCache'));
        self::assertSame(2, $fieldList->indexOf(Token::fromString('BrowserCache')));
        self::assertNull($fieldList->indexOf('foobar'));
        self::assertTrue($closestToOrigin->hit);
        self::assertFalse($intermediary->hit);
        self::assertFalse($closestToClient->hit);

        self::assertNull($closestToOrigin->forward);
        self::assertSame(ForwardedReason::UriMiss, $intermediary->forward->reason);
        self::assertSame(ForwardedReason::UriMiss, $closestToClient->forward->reason);

        self::assertSame('ReverseProxyCache', $closestToOrigin->servedBy());
        self::assertSame('ForwardProxyCache', $intermediary->servedBy());
        self::assertSame('BrowserCache', $closestToClient->servedBy());
    }

    #[Test]
    public function parsing_a_response_header_with_invalid_members(): void
    {
        $factory = new HttpFactory();
        $response = $factory->createResponse()
            ->withStatus(302)
            ->withHeader('cache-status', 'ReverseProxyCache; hit')
            ->withAddedHeader('Cache-Status', 'ForwardProxyCache; hit; fwd=uri-miss; collapsed; stored')
            ->withAddedHeader('Cache-Status', '(foo bar "baz")')
            ->withAddedHeader('cache-Status', 'BrowserCache; fwd=uri-miss; key="Hello Boy!"');

        $fieldList = Field::fromHttpValue($response->getHeaderLine('cache-Status'), $response->getStatusCode());

        self::assertCount(2, $fieldList);
        self::assertFalse($fieldList->contains('ForwardProxyCache'));
    }

    #[Test]
    public function pushing_an_invalid_request_cache_will_fail(): void
    {
        $this->expectException(Exception::class);

        (new Field())->push('ForwardProxyCache; hit; fwd=uri-miss; collapsed; stored');
    }

    #[Test]
    public function creating_a_new_instance_with_an_invalid_request_cache_will_fail(): void
    {
        $this->expectException(Exception::class);

        new Field('ForwardProxyCache; hit; fwd=uri-miss; collapsed; stored');
    }
}
