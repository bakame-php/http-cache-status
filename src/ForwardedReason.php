<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Token;
use Stringable;

use function array_map;
use function in_array;

/**
 * The possible reason for forwarded cache.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9211.html
 */
enum ForwardedReason: string
{
    case Bypass = 'bypass';
    case Method = 'method';
    case UriMiss = 'uri-miss';
    case VaryMiss = 'vary-miss';
    case Miss = 'miss';
    case Request = 'request';
    case Stale = 'stale';
    case Partial = 'partial';

    public function toToken(): Token
    {
        return Token::fromString($this->value);
    }

    public static function fromToken(Token|Stringable|string $token): self
    {
        return self::tryFromToken($token) ?? throw new InvalidSyntax('The token represents an invalid value.');
    }

    public static function tryFromToken(Token|Stringable|string|null $token): ?self
    {
        $token = match (true) {
            $token instanceof Token => $token->toString(),
            default => (string) $token,
        };

        return match (true) {
            in_array($token, self::list(), true) => self::from($token),
            default => null,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bypass => 'The cache was configured to not handle this request.',
            self::Method => 'The request method\'s semantics require the request to be forwarded.',
            self::UriMiss => 'The cache did not contain any responses that matched the request URI.',
            self::VaryMiss => 'The cache contained a response that matched the request URI, but it could not select a response based upon this request\'s header fields and stored Vary header fields.',
            self::Miss => 'The cache did not contain any responses that could be used to satisfy this request (to be used when an implementation cannot distinguish between uri-miss and vary-miss).',
            self::Request => 'The cache was able to select a fresh response for the request, but the request\'s semantics (e.g., Cache-Control request directives) did not allow its use.',
            self::Stale => 'The cache was able to select a response for the request, but it was stale.',
            self::Partial => 'The cache was able to select a partial response for the request, but it did not contain all of the requested ranges (or the request was for the complete response).',
        };
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other === $this;
    }

    public function isOneOf(mixed ...$other): bool
    {
        foreach ($other as $item) {
            if ($this->equals($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function list(): array
    {
        /** @var list<string>|null $list */
        static $list;

        $list ??= array_map(fn (self $case): string => $case->value, self::cases());

        return $list;
    }
}
