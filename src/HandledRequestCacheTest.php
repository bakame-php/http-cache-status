<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Item;
use Bakame\Http\StructuredFields\Token;
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
        $forward = new Forward(reason: ForwardedReason::Miss, statusCode: 304, collapsed: true);
        $cacheForwarded = HandledRequestCache::serverIdentifierAsString('10.0.0.7')
            ->withDetailAsString('This is a detail')
            ->withTtl(376)
            ->wasForwarded($forward)
            ->withKey(null)
        ;

        self::assertNotInstanceOf(Token::class, $cacheForwarded->servedBy);
        self::assertSame('10.0.0.7', $cacheForwarded->servedBy);
        self::assertSame('10.0.0.7', $cacheForwarded->servedBy());
        self::assertFalse($cacheForwarded->hit);
        self::assertSame(376, $cacheForwarded->ttl);

        self::assertInstanceOf(Forward::class, $cacheForwarded->forward);
        self::assertSame(ForwardedReason::Miss, $cacheForwarded->forward->reason);
        self::assertSame(304, $cacheForwarded->forward->statusCode);
        self::assertTrue($cacheForwarded->forward->collapsed);

        self::assertSame('"10.0.0.7";fwd=miss;fwd-status=304;collapsed;ttl=376;detail="This is a detail"', (string) $cacheForwarded);
        self::assertEquals($cacheForwarded, HandledRequestCache::fromHttpValue(Item::fromRfc9651($cacheForwarded)));

        $cacheHit = $cacheForwarded->wasHit();
        self::assertNull($cacheHit->forward);
    }

    #[Test]
    public function it_can_update_the_forward_parameters(): void
    {
        $cacheForwarded = HandledRequestCache::serverIdentifierAsString('10.0.0.7')
            ->wasForwarded('miss');

        self::assertInstanceOf(Forward::class, $cacheForwarded->forward);
        self::assertSame(ForwardedReason::Miss, $cacheForwarded->forward->reason);
    }
}
