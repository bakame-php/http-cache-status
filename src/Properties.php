<?php

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Parameters;
use Bakame\Http\StructuredFields\Token;
use Bakame\Http\StructuredFields\Type;
use Bakame\Http\StructuredFields\Validation\ParametersValidator;

/**
 * @phpstan-import-type SfParameterNameRule from ParametersValidator
 */
enum Properties: string
{
    case Hit = 'hit';
    case Forward = 'fwd';
    case ForwardStatusCode = 'fwd-status';
    case Stored = 'stored';
    case Collapsed = 'collapsed';
    case TimeToLive = 'ttl';
    case Key = 'key';
    case Detail = 'detail';

    public static function validator(): ParametersValidator
    {
        /** @var ?ParametersValidator $validator */
        static $validator;

        if (null === $validator) {
            /** @var array<string, SfParameterNameRule> $filters */
            $filters = array_reduce(
                self::cases(),
                fn (array $rules, self $property): array => [...$rules, ...[$property->value => $property->validate()]],
                []
            );

            $validator = ParametersValidator::new()
                ->filterByCriteria(function (Parameters $parameters): bool|string {
                    if (!$parameters->allowedNames(array_map(fn (self $case) => $case->value, self::cases()))) {
                        return 'The cache contains invalid parameters.';
                    }

                    $hit = !in_array($parameters->valueByName(self::Hit->value, default: false), [null, false], true);
                    $fwd = $parameters->valueByName(self::Forward->value);

                    return match (true) {
                        !$hit && null !== $fwd,
                        $hit && null === $fwd => true,
                        default => "The '".self::Hit->value."' and '".self::Forward->value."' parameters are mutually exclusive.",
                    };
                })
                ->filterByNames($filters);
        }

        return $validator;
    }

    /**
     * @return SfParameterNameRule
     */
    public function validate(): array
    {
        return match ($this) {
            self::Hit => ['validate' => fn (mixed $value): bool|string => is_bool($value) ? true : "The '{name}' parameter must be a boolean.", 'default' => false],
            self::TimeToLive => ['validate' => fn (mixed $value): bool|string => is_int($value) ? true : "The '{name}' parameter must be an integer."],
            self::Key => ['validate' => fn (mixed $value): bool|string => is_string($value) ? true : "The '{name}' parameter must be a string."],
            self::Detail => ['validate' => fn (mixed $value): bool|string => Type::fromVariable($value)->isOneOf(Type::String, Type::Token) ? true : "The '{name}' parameter must be a string."],
            self::Forward => ['validate' => fn (mixed $value): bool|string => match (true) {
                !$value instanceof Token => "The '{name}' parameter must be a Token.",
                null === ForwardedReason::tryFromToken($value) => "The '{name}' parameter Token value '{value}' is unknown or unsupported.",
                default => true,
            }],
            self::ForwardStatusCode => ['validate' => fn (mixed $value): bool|string => match (true) {
                !is_int($value) => "The '{name}' parameter must be an integer.",
                $value < 100 || $value > 599 => "The '{name}' parameter value '{value}' must be a valid HTTP status code",
                default => true,
            }],
            self::Stored => ['validate' => fn (mixed $value): bool|string => is_bool($value) ? true : "The '{name}' parameter must be a boolean.", 'default' => false],
            self::Collapsed => ['validate' => fn (mixed $value): bool|string => is_bool($value) ? true : "The '{name}' parameter must be a boolean.", 'default' => false],
        };
    }
}
