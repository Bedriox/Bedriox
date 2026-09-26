<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Navigation;

use Bedriox\Server\Entity\Navigation\NavigationPath;
use Bedriox\Server\Entity\Navigation\NavigationPathStatus;
use Bedriox\Server\Entity\Navigation\NavigationPoint;
use Throwable;

final class NavigationPathCodec
{
    public const int MAXIMUM_ENCODED_BYTES = 6_219;
    private const string MAGIC = "BXNP\x00\x01";

    public function encode(NavigationPath $path): string
    {
        $revision = hex2bin($path->snapshotRevision);
        if ($revision === false) {
            throw new NavigationTransferException('Navigation path revision is invalid.');
        }
        $body = $revision
            . chr($path->status->value)
            . pack('n2', $path->visitedNodes, count($path->points));
        foreach ($path->points as $point) {
            $body .= pack('N3', $point->x, $point->y, $point->z);
        }
        $encoded = self::MAGIC . $body . hash('sha256', $body, true);
        if (strlen($encoded) > self::MAXIMUM_ENCODED_BYTES) {
            throw new NavigationTransferException('Navigation path exceeds its byte limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded): NavigationPath
    {
        if (strlen($encoded) < 87 || strlen($encoded) > self::MAXIMUM_ENCODED_BYTES
            || substr($encoded, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new NavigationTransferException('Navigation path header or size is invalid.');
        }
        $body = substr($encoded, strlen(self::MAGIC), -32);
        if (!hash_equals(hash('sha256', $body, true), substr($encoded, -32))) {
            throw new NavigationTransferException('Navigation path checksum does not match.');
        }

        try {
            $reader = new NavigationTransferReader($body);
            $revision = bin2hex($reader->bytes(32));
            $status = NavigationPathStatus::tryFrom($reader->byte());
            if ($status === null) {
                throw new NavigationTransferException('Navigation path status is unknown.');
            }
            $visitedNodes = $reader->unsignedShort();
            $pointCount = $reader->unsignedShort();
            if ($pointCount < 1 || $pointCount > NavigationPath::MAXIMUM_POINTS) {
                throw new NavigationTransferException('Navigation path point count is outside its bound.');
            }
            $points = [];
            for ($index = 0; $index < $pointCount; ++$index) {
                $points[] = new NavigationPoint(
                    $reader->signedInt(),
                    $reader->signedInt(),
                    $reader->signedInt(),
                );
            }
            $reader->finish();

            return new NavigationPath($points, $status, $revision, $visitedNodes);
        } catch (NavigationTransferException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new NavigationTransferException('Navigation path is invalid.', previous: $error);
        }
    }
}
