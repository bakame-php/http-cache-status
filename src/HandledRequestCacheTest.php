<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Item;
use Bakame\Http\StructuredFields\Token;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandledRequestCacheTest extends TestCase
{
    #[Test]
    public function it_can_create_a_cache_status(): void
    {
        $value = HandledRequestCache::serverIdentifierAsToken('ExampleCache')
            ->wasHit()
            ->withTtl(3600);

        self::assertSame('ExampleCache;hit;ttl=3600', (string) $value);
    }

    #[Test]
    public function it_can_create_a_cache_status_with_ttl(): void
    {
        $value = HandledRequestCache::fromHttpValue('ExampleCache; hit; ttl=3600');

        self::assertInstanceOf(Token::class, $value->servedBy);
        self::assertEquals('ExampleCache', $value->servedBy->toString());
        self::assertEquals('ExampleCache', $value->servedBy());
        self::assertTrue($value->hit);
        self::assertSame(3600, $value->ttl);
    }

    #[Test]
    public function it_can_create_a_cache_status_progressively(): void
    {
        $cacheForwarded = HandledRequestCache::serverIdentifierAsString(serverIdentifier: '10.0.0.7')
            ->withDetailAsString('This is a detail')
            ->withTtl(376)
            ->wasForwarded(forwardReason: ForwardedReason::Miss, forwardStatus: 304, collapsed: true)
            ->withKey(null)
        ;

        self::assertNotInstanceOf(Token::class, $cacheForwarded->servedBy);
        self::assertSame('10.0.0.7', $cacheForwarded->servedBy);
        self::assertSame('10.0.0.7', $cacheForwarded->servedBy());
        self::assertFalse($cacheForwarded->hit);
        self::assertSame(376, $cacheForwarded->ttl);
        self::assertSame(ForwardedReason::Miss, $cacheForwarded->forwardReason);
        self::assertSame(304, $cacheForwarded->forwardStatusCode);
        self::assertTrue($cacheForwarded->collapsed);
        self::assertSame('"10.0.0.7";fwd=miss;fwd-status=304;collapsed;ttl=376;detail="This is a detail"', (string) $cacheForwarded);
        self::assertEquals($cacheForwarded, HandledRequestCache::fromStructuredField(Item::fromRfc9651($cacheForwarded)));

        $cacheHit = $cacheForwarded->wasHit();
        self::assertNull($cacheHit->forwardReason);
        self::assertNull($cacheHit->forwardStatusCode);
        self::assertFalse($cacheHit->collapsed);
        self::assertFalse($cacheHit->stored);
    }

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

        self::assertNull($closestToOrigin->forwardStatusCode);
        self::assertSame($response->getStatusCode(), $intermediary->forwardStatusCode);
        self::assertSame($response->getStatusCode(), $closestToClient->forwardStatusCode);
        self::assertFalse(isset($fieldList[42]));
        self::assertFalse($fieldList->contains('Foobar'));
        self::assertTrue($fieldList->contains(Token::fromString('BrowserCache')));
        self::assertFalse($fieldList->contains('BrowserCache'));

        self::assertTrue($closestToOrigin->hit);
        self::assertFalse($intermediary->hit);
        self::assertFalse($closestToClient->hit);

        self::assertNull($closestToOrigin->forwardReason);
        self::assertSame(ForwardedReason::UriMiss, $intermediary->forwardReason);
        self::assertSame(ForwardedReason::UriMiss, $closestToClient->forwardReason);

        self::assertSame('ReverseProxyCache', $closestToOrigin->servedBy());
        self::assertSame('ForwardProxyCache', $intermediary->servedBy());
        self::assertSame('BrowserCache', $closestToClient->servedBy());
    }
}
