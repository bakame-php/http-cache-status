<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Item;
use Bakame\Http\StructuredFields\StructuredFieldProvider;
use Bakame\Http\StructuredFields\Token;
use Bakame\Http\StructuredFields\Type;
use Bakame\Http\StructuredFields\Validation\ItemValidator;
use Bakame\Http\StructuredFields\Validation\ValidatedItem;
use Stringable;

/**
 * A single handled request cache as per RFC9211.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9211.html
 */
final readonly class HandledRequestCache implements StructuredFieldProvider, Stringable
{
    private function __construct(
        public Token|string $servedBy,
        public bool $hit,
        public ?Forward $forward,
        public ?int $ttl,
        public ?string $key,
        public Token|string|null $detail,
    ) {
        match (true) {
            !Type::Token->supports($this->servedBy) && !Type::String->supports($this->servedBy) => throw new Exception('The handled request cache identifier must be a Token or a string.'),
            null !== $this->forward && $this->hit  => throw new Exception('The handled request cache can not be both a hit and forwarded.'),
            null === $this->forward && !$this->hit => throw new Exception('The handled request cache must be a hit or forwarded.'),
            null !== $this->key && !Type::String->supports($this->key) => throw new Exception('The `key` parameter must be a string or null.'),
            null !== $this->ttl && !Type::Integer->supports($this->ttl) => throw new Exception('The `ttl` parameter must be a integer or null.'),
            null !== $this->detail && !Type::String->supports($this->detail) && !Type::Token->supports($this->detail)  => throw new Exception('The `detail` parameter must be a string or a Token when present.'),
            default => null,
        };
    }

    /**
     * Returns a new instance with a server identifier as a string which is already hit.
     */
    public static function serverIdentifierAsString(string $identifier): self
    {
        return new self($identifier, hit: true, forward: null, ttl: null, key: null, detail: null);
    }

    /**
     * Returns a new instance with a server identifier as a Token which is already hit.
     */
    public static function serverIdentifierAsToken(Token|string $identifier): self
    {
        if (!$identifier instanceof Token) {
            $identifier = Token::tryFromString($identifier) ?? throw new Exception('The handled request cache identifier must be a valid Token.');
        }

        return new self($identifier, hit: true, forward: null, ttl:null, key:null, detail: null);
    }

    /**
     * Returns an instance from a Header Line and the optional response status code.
     */
    public static function fromHttpValue(StructuredFieldProvider|Item|Stringable|string $item, ?int $statusCode = null): self
    {
        if (null !== $statusCode && ($statusCode < 100 || $statusCode > 599)) {
            throw new Exception('The default forward status code must be a valid HTTP status code when present.');
        }

        if ($item instanceof StructuredFieldProvider) {
            $className = $item::class;
            $item = $item->toStructuredField();
            if (!$item instanceof Item) {
                throw new Exception('The structured field provider `'.$className.'` must return an '.Item::class.' data type.');
            }
        }

        if (!$item instanceof Item) {
            $item = Item::fromHttpValue($item);
        }

        $validation = self::validator()->validate($item);
        if ($validation->isFailed()) {
            throw new Exception('The submitted item is an invalid handled request cache status', previous: $validation->errors->toException());
        }

        /** @var ValidatedItem $validatedItem */
        $validatedItem = $validation->data;
        /** @var Token|string $servedBy */
        $servedBy = $validatedItem->value;
        /**
         * @var array{
         *    hit: bool,
         *    ttl: ?int,
         *    key: ?string,
         *    detail: Token|string|null,
         *    fwd: ?Token,
         *    fwd-status: ?int,
         *    collapsed: bool,
         *    stored: bool
         * } $parameters
         */
        $parameters = $validatedItem->parameters->all();

        $forward = null === $parameters[Properties::Forward->value] ? null : Forward::fromReason($parameters[Properties::Forward->value])
            ->statusCode($parameters[Properties::ForwardStatusCode->value] ?? $statusCode)
            ->collapsed($parameters[Properties::Collapsed->value])
            ->stored($parameters[Properties::Stored->value]);

        return new self(
            servedBy: $servedBy,
            hit: $parameters[Properties::Hit->value],
            forward: $forward,
            ttl: $parameters[Properties::TimeToLive->value],
            key: $parameters[Properties::Key->value],
            detail: $parameters[Properties::Detail->value],
        );
    }

    private static function validator(): ItemValidator
    {
        /** @var ItemValidator|null $validator */
        static $validator;

        $validator ??= ItemValidator::new()
            ->value(fn (mixed $value): bool|string => match (true) {
                Type::fromVariable($value)->isOneOf(Type::String, Type::Token) => true,
                default => 'The cache name must be a HTTP structured field token or string.',
            })
            ->parameters(Properties::validator());

        return $validator;
    }

    public function toStructuredField(): Item
    {
        return Item::new($this->servedBy)
            ->when($this->hit, fn (Item $item) => $item->appendParameter(Properties::Hit->value, $this->hit))
            ->when(null !== $this->forward, fn (Item $item) => $item->mergeParametersByPairs($this->forward))  /* @phpstan-ignore-line */
            ->when(null !== $this->ttl, fn (Item $item) => $item->appendParameter(Properties::TimeToLive->value, $this->ttl))  /* @phpstan-ignore-line */
            ->when(null !== $this->key, fn (Item $item) => $item->appendParameter(Properties::Key->value, $this->key))  /* @phpstan-ignore-line */
            ->when(null !== $this->detail, fn (Item $item) => $item->appendParameter(Properties::Detail->value, $this->detail));  /* @phpstan-ignore-line */
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

    public function __toString(): string
    {
        return $this->toStructuredField()->toHttpValue();
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
            default => new self($this->servedBy, true, null, $this->ttl, $this->key, $this->detail),
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
    public function wasForwarded(Forward|ForwardedReason|Token|string $forward): self
    {
        $forward = match (true) {
            is_string($forward) => Forward::fromReason(ForwardedReason::tryFrom($forward) ?? throw new Exception('The submitted string is not a valid server identifier.')),
            $forward instanceof Token => Forward::fromReason(ForwardedReason::fromToken($forward)),
            $forward instanceof ForwardedReason => Forward::fromReason($forward),
            default => $forward,
        };

        return match (true) {
            $forward->equals($this->forward) => $this,
            default => new self($this->servedBy, false, $forward, $this->ttl, $this->key, $this->detail),
        };
    }

    /**
     * Change the response's remaining freshness lifetime as calculated by the cache.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withTtl(?int $ttl): self
    {
        return match ($ttl) {
            $this->ttl => $this,
            default => new self($this->servedBy, $this->hit, $this->forward, $ttl, $this->key, $this->detail),
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
            default => new self($this->servedBy, $this->hit, $this->forward, $this->ttl, $this->key, $detail),
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
        if (is_string($detail)) {
            $detail = Token::tryFromString($detail) ?? throw new Exception('The handled request cache detail must be a valid Token.');
        }

        return match (true) {
            $this->detail === $detail,
            $detail?->equals($this->detail) => $this,
            default => new self($this->servedBy, $this->hit, $this->forward, $this->ttl, $this->key, $detail),
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
            default => new self($this->servedBy, $this->hit, $this->forward, $this->ttl, $key, $this->detail),
        };
    }
}
