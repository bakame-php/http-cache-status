<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use ArrayAccess;
use Bakame\Http\StructuredFields\InnerList;
use Bakame\Http\StructuredFields\Item;
use Bakame\Http\StructuredFields\OuterList;
use Bakame\Http\StructuredFields\StructuredFieldError;
use Bakame\Http\StructuredFields\StructuredFieldProvider;
use Bakame\Http\StructuredFields\Token;
use Closure;
use Countable;
use Iterator;
use IteratorAggregate;
use LogicException;
use OutOfBoundsException;
use Stringable;

/**
 * The Cache-Status HTTP Response Header Field.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9211.html
 *
 * @implements ArrayAccess<int, HandledRequestCache>
 * @implements IteratorAggregate<int, HandledRequestCache>
 */
class Field implements ArrayAccess, IteratorAggregate, Countable, StructuredFieldProvider, Stringable
{
    public const NAME = 'cache-status';

    /** @var array<HandledRequestCache> */
    private array $caches;

    public function __construct(HandledRequestCache|Stringable|string ...$caches)
    {
        $this->caches = array_map(fn (HandledRequestCache|Stringable|string $value) => match (true) {
            $value instanceof HandledRequestCache => $value,
            default => HandledRequestCache::fromHttpValue((string) $value),
        }, $caches);
    }

    /**
     * Returns an instance from PHP SAPI.
     *
     * @param array{'HTTP_CACHE_STATUS'?:string} $server
     */
    public static function fromSapi(array $server = []): self
    {
        return self::fromHttpValue($server['HTTP_CACHE_STATUS'] ?? '');
    }

    /**
     * Returns an instance from a Header Line and the optional response status code.
     */
    public static function fromHttpValue(Stringable|string $httpHeaderLine = '', ?int $statusCode = null): self
    {
        return self::fromStructuredField(OuterList::fromHttpValue($httpHeaderLine), $statusCode);
    }

    /**
     * Returns an instance from a Structured Field List and the optional response status code.
     */
    private static function fromStructuredField(OuterList $structuredField, ?int $statusCode = null): self
    {
        return new self(...$structuredField->map(
            fn (Item|InnerList $item, int $offset): HandledRequestCache => match (true) {
                $item instanceof Item => HandledRequestCache::fromStructuredField($item, $statusCode),
                default => throw new LogicException('The list must only contain Items.'),
            }
        ));
    }

    /**
     * @return OuterList<int, Item>
     */
    public function toStructuredField(): OuterList
    {
        return OuterList::new(...$this->caches);
    }

    /**
     * @throws StructuredFieldError
     */
    public function __toString(): string
    {
        return $this->toStructuredField()->toHttpValue();
    }

    /**
     * Iterate over the handled request cache from the origin server to the user.
     *
     * @return Iterator<int, HandledRequestCache>
     */
    public function getIterator(): Iterator
    {
        return yield from $this->caches;
    }

    /**
     * Iterate backward over the handled request cache from the user to the origin server.
     *
     * The handled request cache index are preserved
     *
     * @return Iterator<int, HandledRequestCache>
     */
    public function reverseIterate(): Iterator
    {
        return yield from array_reverse($this->caches, true);
    }

    /**
     * Returns the number of handled requests cache.
     */
    public function count(): int
    {
        return count($this->caches);
    }

    /**
     * Tells whether there are some handled requests in header.
     */
    public function hasRequests(): bool
    {
        return [] !== $this->caches;
    }

    /**
     * Tells whether there is no handled requests in header.
     */
    public function hasNoRequest(): bool
    {
        return ! $this->hasRequests();
    }

    /**
     * Return the handled request cache closest to the origin if it exists; null otherwise.
     */
    public function closestToOrigin(): ?HandledRequestCache
    {
        return $this->nth(0);
    }

    /**
     * Return the handled request cache closest to the user if it exists; null otherwise.
     */
    public function closestToUser(): ?HandledRequestCache
    {
        return $this->nth(-1);
    }

    private function nth(int $offset): ?HandledRequestCache
    {
        return $this->caches[$this->filterIndex($offset)] ?? null;
    }

    private function filterIndex(int $offset, ?int $max = null): int|null
    {
        $max ??= count($this->caches);

        return match (true) {
            [] === $this->caches,
            0 > $max + $offset,
            0 > $max - $offset - 1 => null,
            0 > $offset => $max + $offset,
            default => $offset,
        };
    }

    /**
     * Tells whether all the indices given are associated with a handled request cache.
     */
    public function has(int ...$indexes): bool
    {
        $count = count($this->caches);
        foreach ($indexes as $index) {
            if (null === $this->filterIndex($index, $count)) {
                return false;
            }
        }

        return [] !== $indexes;
    }

    /**
     * Tells whether a handled request cache exists with the provided server identifier.
     */
    public function contains(Token|string $serverIdentifier): bool
    {
        $validate = fn (Token|string $token): bool => match (true) {
            $token instanceof Token => $serverIdentifier instanceof Token && $serverIdentifier->equals($token),
            default => $token === $serverIdentifier,
        };

        foreach ($this->caches as $member) {
            if ($validate($member->servedBy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * @param int $offset
     */
    public function offsetGet(mixed $offset): HandledRequestCache
    {
        return $this->nth($offset) ?? throw new OutOfBoundsException('No request cache found for the given offset.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException(self::class.' instance can not be updated using '.ArrayAccess::class.' methods.');
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException(self::class.' instance can not be updated using '.ArrayAccess::class.' methods.');
    }

    /**
     * Append a new handled request cache at the end of the field.
     */
    public function push(HandledRequestCache|Stringable|string ...$values): self
    {
        return match ($values) {
            [] => $this,
            default => new self(...$this->caches, ...$values),
        };
    }

    /**
     * Filter the handled request caches.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     *
     * @param Closure(HandledRequestCache, int): bool $callback
     */
    public function filter(Closure $callback): self
    {
        return new self(...array_filter($this->caches, $callback, ARRAY_FILTER_USE_BOTH));
    }
}
