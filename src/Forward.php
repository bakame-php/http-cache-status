<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Token;
use InvalidArgumentException;
use LogicException;

final class Forward
{
    public function __construct(
        public readonly ForwardedReason $reason,
        public readonly ?int $statusCode = null,
        public readonly bool $collapsed = false,
        public readonly bool $stored = false,
    ) {
        if (null !== $this->statusCode && ($this->statusCode < 100 || $this->statusCode >= 600)) {
            throw new LogicException('The forward statusCode must be a valid HTTP status code when present.');
        }
    }

    public static function fromReason(ForwardedReason|Token|string $reason): self
    {
        return new self(self::filterReason($reason));
    }

    private static function filterReason(ForwardedReason|Token|string $reason): ForwardedReason
    {
        if (is_string($reason)) {
            $reason = Token::tryFromString($reason);
        }

        if ($reason instanceof Token) {
            $reason = ForwardedReason::tryFromToken($reason);
        }

        if (!$reason instanceof ForwardedReason) {
            throw new InvalidArgumentException('Invalid forward reason.');
        }

        return $reason;
    }

    public function reason(ForwardedReason|Token|string $reason): self
    {
        $reason = self::filterReason($reason);
        if ($reason->equals($this->reason)) {
            return $this;
        }

        return new self($reason, $this->statusCode, $this->collapsed, $this->stored);
    }

    public function statusCode(?int $statusCode): self
    {
        if (null !== $statusCode && ($statusCode < 100 || $statusCode >= 600)) {
            throw new LogicException('The forward statusCode must be a valid HTTP status code.');
        }

        if ($statusCode === $this->statusCode) {
            return $this;
        }

        return new self($this->reason, $statusCode, $this->collapsed, $this->stored);
    }

    public function collapsed(bool $collapsed): self
    {
        if ($collapsed === $this->collapsed) {
            return $this;
        }

        return new self($this->reason, $this->statusCode, $collapsed, $this->stored);
    }

    public function stored(bool $stored): self
    {
        if ($stored === $this->stored) {
            return $this;
        }

        return new self($this->reason, $this->statusCode, $this->collapsed, $stored);
    }
}
