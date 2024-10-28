<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Ietf;
use Bakame\Http\StructuredFields\Item;
use Bakame\Http\StructuredFields\StructuredField;
use Bakame\Http\StructuredFields\StructuredFieldError;
use Bakame\Http\StructuredFields\StructuredFieldProvider;
use Bakame\Http\StructuredFields\Token;
use Bakame\Http\StructuredFields\Type;
use Bakame\Http\StructuredFields\ValidationError;
use Stringable;

/**
 * A single handled request cache as per RFC9211.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9211.html
 */
final class HandledRequestCache implements StructuredFieldProvider, Stringable
{
    private function __construct(
        public readonly Token|string $servedBy,
        public readonly bool $hit = true,
        public readonly ?ForwardedReason $forwardReason = null,
        public readonly ?int $forwardStatusCode = null,
        public readonly bool $stored = false,
        public readonly bool $collapsed = false,
        public readonly ?int $ttl = null,
        public readonly ?string $key = null,
        public readonly Token|string|null $detail = null,
    ) {
        match (true) {
            !Type::Token->supports($this->servedBy) && !Type::String->supports($this->servedBy) => throw new ValidationError('The handled request cache identifier must be a Token or a string.'),
            (null !== $this->forwardReason && $this->hit) || (null === $this->forwardReason && !$this->hit) => throw new ValidationError('The handled request cache must be a hit or forwarded.'),
            null !== $this->key && !Type::String->supports($this->key) => throw new ValidationError('The `key` parameter must be a string or null.'),
            null !== $this->ttl && !Type::Integer->supports($this->ttl) => throw new ValidationError('The `ttl` parameter must be a integer or null.'),
            null !== $this->detail && !Type::String->supports($this->detail) && !Type::Token->supports($this->detail)  => throw new ValidationError('The `detail` parameter must be a string or a Token when present.'),
            null !== $this->forwardStatusCode && ($this->forwardStatusCode < 100 || $this->forwardStatusCode >= 600) => throw new ValidationError('The `forwardStatusCode` must be a valid HTTP status code when present.'),
            null === $this->forwardReason && (null !== $this->forwardStatusCode || $this->stored || $this->collapsed) => throw new ValidationError('The cache `forwardReason` dependent parameters must not be set if the handled request cache is a hit.'),
            default => null,
        };
    }

    /**
     * Returns an instance from a Header Line and the optional response status code.
     */
    public static function fromHttpValue(string $value, ?int $statusCode = null): self
    {
        return self::fromStructuredField(Item::fromHttpValue($value), $statusCode);
    }

    /**
     * Returns an instance from a Structured Field Item and the optional response status code.
     */
    public static function fromStructuredField(Item $item, ?int $statusCode = null): self
    {
        if (null !== $statusCode && ($statusCode < 100 || $statusCode > 599)) {
            throw new ValidationError('the default forward status code must be a valid HTTP status code when present.');
        }

        /** @var Token|string $identifier */
        $identifier = $item->value(
            fn (mixed $value) => match (true) {
                is_string($value),
                $value instanceof Token => null,
                default => 'The cache name must be a Token or a string.',
            },
        );

        if (!$item->parameters()->only('hit', 'fwd', 'fwd-status', 'stored', 'collapsed', 'ttl', 'key', 'detail')) {
            throw new ValidationError('The cache contains invalid parameters.');
        }

        /** @var bool $hit */
        $hit = $item->parameter(
            key: 'hit',
            validate: fn (mixed $value) => is_bool($value) ? null : 'The hit parameter must be a boolean.',
            default: false,
        );

        /** @var ?int $ttl */
        $ttl = $item->parameter(
            key: 'ttl',
            validate: fn (mixed $value) => is_int($value) ? null : 'The ttl parameter must be a int.',
        );

        /** @var ?string $key */
        $key = $item->parameter(
            key: 'key',
            validate: fn (mixed $value) => is_string($value) ? null : 'The key parameter must be a string.',
        );

        /** @var Token|string|null $detail */
        $detail = $item->parameter(
            key: 'detail',
            validate: fn (mixed $value) => match (true) {
                is_string($value),
                !$value instanceof Token => null,
                default => 'The detail parameter must be a string or a Token.',
            }
        );

        /** @var ?Token $fwd */
        $fwd = $item->parameter(
            key: 'fwd',
            validate: fn (mixed $value) => match (true) {
                !$value instanceof Token => 'The fwd parameter must be a Token.',
                null === ForwardedReason::tryFromToken($value) => 'The fwd parameter value is invalid.',
                default => null,
            }
        );

        /** @var ?int $forwardStatus */
        $forwardStatus = $item->parameter(
            key: 'fwd-status',
            validate: fn (mixed $value) => is_int($value) ? null : 'The fwd-status parameter must be a integer.',
            default: null !== $fwd ? $statusCode : null,
        );

        /** @var bool $stored */
        $stored = $item->parameter(
            key: 'stored',
            validate: fn (mixed $value) => is_bool($value) ? null : 'The stored parameter must be a boolean.',
            default: false,
        );

        /** @var bool $collapsed */
        $collapsed = $item->parameter(
            key: 'collapsed',
            validate: fn (mixed $value) => is_bool($value) ? null : 'The collapsed parameter must be a boolean.',
            default: false,
        );

        return new self(
            $identifier,
            $hit,
            null !== $fwd ? ForwardedReason::fromToken($fwd) : null,
            $forwardStatus,
            $stored,
            $collapsed,
            $ttl,
            $key,
            $detail
        );
    }

    /**
     * Returns a new instance with a server identifier as a string which is already hit.
     */
    public static function serverIdentifierAsString(string $serverIdentifier): self
    {
        return new self($serverIdentifier);
    }

    /**
     * Returns a new instance with a server identifier as a Token which is already hit.
     */
    public static function serverIdentifierAsToken(Token|string $identifier): self
    {
        return new self(match (true) {
            $identifier instanceof Token => $identifier,
            default => Token::fromString($identifier)
        });
    }

    /**
     * The server identifier as a string.
     */
    public function servedBy(): string
    {
        return match (true) {
            $this->servedBy instanceof Token => $this->servedBy->toString(),
            default => $this->servedBy,
        };
    }

    /**
     * Tells whether the handled request cache is a hit or is forwarded.
     * Both states are mutually exclusive.
     */
    public function isHit(): bool
    {
        return $this->hit;
    }

    /**
     * @throws StructuredFieldError
     */
    public function __toString(): string
    {
        return $this->toStructuredField()->toHttpValue(Ietf::Rfc9651);
    }

    public function toStructuredField(): StructuredField
    {
        return Item::fromPair([
            $this->servedBy,
            array_filter([
                ['hit', $this->hit],
                ['fwd', $this->forwardReason?->toToken()],
                ['fwd-status', $this->forwardStatusCode],
                ['stored', $this->stored],
                ['collapsed', $this->collapsed],
                ['ttl', $this->ttl],
                ['key', $this->key],
                ['detail', $this->detail],
            ], fn (array $pair): bool => null !== $pair[1] && false !== $pair[1]),
        ]);
    }

    /**
     * Indicates that the request was satisfied by the cache.
     *
     * The request was not forwarded, and the response was obtained from the cache.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function wasHit(): self
    {
        return match (true) {
            $this->hit => $this,
            default => new self($this->servedBy, true, null, null, false, false, $this->ttl, $this->key, $this->detail),
        };
    }

    /**
     * Indicates that the request went forward towards the origin.
     *
     * The request was not forwarded, and the response was obtained from the cache.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function wasForwarded(ForwardedReason $forwardReason, ?int $forwardStatus = null, bool $stored = false, bool $collapsed = false): self
    {
        return new self($this->servedBy, false, $forwardReason, $forwardStatus, $stored, $collapsed, $this->ttl, $this->key, $this->detail);
    }

    /**
     * Change the response's remaining freshness lifetime as calculated by the cache
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withTtl(?int $ttl): self
    {
        return match ($ttl) {
            $this->ttl => $this,
            default => new self($this->servedBy, $this->hit, $this->forwardReason, $this->forwardStatusCode, $this->stored, $this->collapsed, $ttl, $this->key, $this->detail),
        };
    }

    /**
     * Change the additional information not captured in other parameters as Structured field string.
     *
     * It can be implementation-specific states or other caching-related metrics
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withDetailAsString(?string $detail): self
    {
        return match ($detail) {
            $this->detail => $this,
            default => new self($this->servedBy, $this->hit, $this->forwardReason, $this->forwardStatusCode, $this->stored, $this->collapsed, $this->ttl, $this->key, $detail),
        };
    }

    /**
     * Change the additional information not captured in other parameters as Structured field Token.
     *
     * It can be implementation-specific states or other caching-related metrics
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withDetailAsToken(Token|string|null $detail): self
    {
        $detail = match (true) {
            $detail instanceof Token,
            null === $detail => $detail,
            default => Token::fromString($detail),
        };

        return match (true) {
            $this->detail === $detail,
            $detail?->equals($this->detail) => $this,
            default => new self($this->servedBy, $this->hit, $this->forwardReason, $this->forwardStatusCode, $this->stored, $this->collapsed, $this->ttl, $this->key, $detail),
        };
    }

    /**
     * Change the cache key representation as a Structured field string.
     *
     * The cache key representation can be implementation-specific
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withKey(?string $key): self
    {
        return match ($key) {
            $this->key => $this,
            default => new self($this->servedBy, $this->hit, $this->forwardReason, $this->forwardStatusCode, $this->stored, $this->collapsed, $this->ttl, $key, $this->detail),
        };
    }
}
