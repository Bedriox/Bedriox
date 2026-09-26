<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Navigation;

use Bedriox\Server\Entity\Navigation\NavigationPoint;
use Bedriox\Server\Entity\Navigation\NavigationSnapshot;
use Throwable;

final class NavigationSearchRequestCodec
{
    public const int MAXIMUM_ENCODED_BYTES = 32_892;
    private const string MAGIC = "BXNQ\x00\x01";

    public function encode(NavigationSearchRequest $request): string
    {
        $revision = hex2bin($request->snapshot->revision);
        if ($revision === false) {
            throw new NavigationTransferException('Navigation snapshot revision is invalid.');
        }
        $bits = $request->snapshot->bits();
        $body = self::signedInt($request->snapshot->minimumX)
            . self::signedInt($request->snapshot->minimumY)
            . self::signedInt($request->snapshot->minimumZ)
            . pack('n3', $request->snapshot->sizeX, $request->snapshot->sizeY, $request->snapshot->sizeZ)
            . $revision
            . self::point($request->start)
            . self::point($request->target)
            . pack(
                'n4',
                $request->maximumDistanceBlocks,
                $request->maximumVerticalRange,
                $request->maximumVisitedNodes,
                $request->maximumRuntimeMilliseconds,
            )
            . pack('N', strlen($bits))
            . $bits;
        $encoded = self::MAGIC . $body . hash('sha256', $body, true);
        if (strlen($encoded) > self::MAXIMUM_ENCODED_BYTES) {
            throw new NavigationTransferException('Navigation search request exceeds its byte limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded): NavigationSearchRequest
    {
        if (strlen($encoded) < 125 || strlen($encoded) > self::MAXIMUM_ENCODED_BYTES
            || substr($encoded, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new NavigationTransferException('Navigation search request header or size is invalid.');
        }
        $body = substr($encoded, strlen(self::MAGIC), -32);
        if (!hash_equals(hash('sha256', $body, true), substr($encoded, -32))) {
            throw new NavigationTransferException('Navigation search request checksum does not match.');
        }

        try {
            $reader = new NavigationTransferReader($body);
            $minimumX = $reader->signedInt();
            $minimumY = $reader->signedInt();
            $minimumZ = $reader->signedInt();
            $sizeX = $reader->unsignedShort();
            $sizeY = $reader->unsignedShort();
            $sizeZ = $reader->unsignedShort();
            $revision = bin2hex($reader->bytes(32));
            $start = self::decodePoint($reader);
            $target = self::decodePoint($reader);
            $maximumDistanceBlocks = $reader->unsignedShort();
            $maximumVerticalRange = $reader->unsignedShort();
            $maximumVisitedNodes = $reader->unsignedShort();
            $maximumRuntimeMilliseconds = $reader->unsignedShort();
            $bitLength = $reader->unsignedInt();
            if ($bitLength < 1 || $bitLength > intdiv(NavigationSnapshot::MAXIMUM_CELLS + 7, 8)) {
                throw new NavigationTransferException('Navigation snapshot bitset length is outside its bound.');
            }
            $snapshot = new NavigationSnapshot(
                $minimumX,
                $minimumY,
                $minimumZ,
                $sizeX,
                $sizeY,
                $sizeZ,
                $reader->bytes($bitLength),
                $revision,
            );
            $reader->finish();

            return new NavigationSearchRequest(
                $snapshot,
                $start,
                $target,
                $maximumDistanceBlocks,
                $maximumVerticalRange,
                $maximumVisitedNodes,
                $maximumRuntimeMilliseconds,
            );
        } catch (NavigationTransferException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new NavigationTransferException('Navigation search request is invalid.', previous: $error);
        }
    }

    private static function point(NavigationPoint $point): string
    {
        return self::signedInt($point->x) . self::signedInt($point->y) . self::signedInt($point->z);
    }

    private static function decodePoint(NavigationTransferReader $reader): NavigationPoint
    {
        return new NavigationPoint($reader->signedInt(), $reader->signedInt(), $reader->signedInt());
    }

    private static function signedInt(int $value): string
    {
        return pack('N', $value);
    }
}
