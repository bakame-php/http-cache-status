<?php

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\ItemValidator;
use Bakame\Http\StructuredFields\Token;
use Bakame\Http\StructuredFields\Type;

/**
 * @phpstan-import-type  SfParameterKeyRule from ItemValidator
 */
enum Parameter: string
{
    case Hit = 'hit';
    case Forward = 'fwd';
    case ForwardStatusCode = 'fwd-status';
    case Stored = 'stored';
    case Collapsed = 'collapsed';
    case TimeToLive = 'ttl';
    case Key = 'key';
    case Detail = 'detail';

    /**
     * @return SfParameterKeyRule
     */
    public function rule(): array
    {
        return match ($this) {
            self::Hit => ['validate' => fn (mixed $value): bool|string => is_bool($value) ? true : "The '{key}' parameter must be a boolean.", 'default' => false],
            self::TimeToLive => ['validate' => fn (mixed $value): bool|string => is_int($value) ? true : "The '{key}' parameter must be an integer."],
            self::Key => ['validate' => fn (mixed $value): bool|string => is_string($value) ? true : "The '{key}' parameter must be a string."],
            self::Detail => ['validate' => fn (mixed $value): bool|string => Type::fromVariable($value)->isOneOf(Type::String, Type::Token) ? true : "The '{key}' parameter must be a string."],
            self::Forward => ['validate' => fn (mixed $value): bool|string => match (true) {
                !$value instanceof Token => "The '{key}' parameter must be a Token.",
                null === ForwardedReason::tryFromToken($value) => "The '{key}' parameter Token value '{value}' is unknown or unsupported.",
                default => true,
            }],
            self::ForwardStatusCode => ['validate' => fn (mixed $value): bool|string => match (true) {
                !is_int($value) => "The '{key}' parameter must be an integer.",
                $value < 100 || $value > 599 => "The '{key}' parameter value '{value}' must be a valid HTTP status code",
                default => true,
            }],
            self::Stored => ['validate' => fn (mixed $value): bool|string => is_bool($value) ? true : "The '{key}' parameter must be a boolean.", 'default' => false],
            self::Collapsed => ['validate' => fn (mixed $value): bool|string => is_bool($value) ? true : "The '{key}' parameter must be a boolean.", 'default' => false],
        };
    }

    /**
     * @return array<string, SfParameterKeyRule>
     */
    public static function rules(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $rules, self $parameter): array => [...$rules, ...[$parameter->value => $parameter->rule()]],
            []
        );
    }
}
