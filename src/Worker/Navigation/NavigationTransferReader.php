<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Navigation;

/** @internal Strict cursor for navigation worker transfer formats. */
final class NavigationTransferReader
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function byte(): int
    {
        return ord($this->take(1));
    }

    public function unsignedShort(): int
    {
        $decoded = unpack('nvalue', $this->take(2));
        $value = is_array($decoded) ? ($decoded['value'] ?? null) : null;
        if (!is_int($value)) {
            throw new NavigationTransferException('Navigation transfer integer is malformed.');
        }

        return $value;
    }

    public function unsignedInt(): int
    {
        $decoded = unpack('Nvalue', $this->take(4));
        $value = is_array($decoded) ? ($decoded['value'] ?? null) : null;
        if (!is_int($value)) {
            throw new NavigationTransferException('Navigation transfer integer is malformed.');
        }

        return $value;
    }

    public function signedInt(): int
    {
        $value = $this->unsignedInt();

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function bytes(int $length): string
    {
        return $this->take($length);
    }

    public function finish(): void
    {
        if ($this->offset !== strlen($this->bytes)) {
            throw new NavigationTransferException('Navigation transfer contains trailing bytes.');
        }
    }

    private function take(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->bytes)) {
            throw new NavigationTransferException('Navigation transfer is truncated.');
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }
}
