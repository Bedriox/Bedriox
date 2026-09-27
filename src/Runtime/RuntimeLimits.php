<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use InvalidArgumentException;

final readonly class RuntimeLimits
{
    public function __construct(
        public int $maximumSessions = 1_024,
        public int $maximumDatagramsPerPoll = 512,
        public int $maximumSessionEventsPerPoll = 2_048,
        public int $maximumPayloadsPerPoll = 4_096,
        public int $maximumOutgoingPayloadsPerSession = 512,
        public int $maximumOutgoingBytesPerSession = 8_388_608,
        public int $maximumPacketsPerPayload = 256,
        public int $maximumCommandsPerPayload = 64,
        public int $maximumChunkRadius = 1,
        public int $preloadedChunkRadius = 1,
        public int $maximumDirectedPacketsPerPoll = 65_535,
        public int $maximumStreamingPacketsPerPoll = 8,
        public int $maximumSubChunkOffsetsPerRequest = 64,
        public int $maximumTrackedSubChunkRequests = 256,
        public int $maximumTrackedSubChunkSections = 512,
    ) {
        foreach (get_object_vars($this) as $value) {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Runtime limits must be positive integers.');
            }
        }
        if ($this->maximumSessions > 65_535
            || $this->maximumDatagramsPerPoll > 4_096
            || $this->maximumSessionEventsPerPoll > 65_535
            || $this->maximumPayloadsPerPoll > 65_535
            || $this->maximumPacketsPerPayload > 4_096
            || $this->maximumCommandsPerPayload > $this->maximumPacketsPerPayload
            || $this->maximumChunkRadius > 32
            || $this->preloadedChunkRadius > 32
            || $this->preloadedChunkRadius > $this->maximumChunkRadius
            || $this->maximumOutgoingPayloadsPerSession < $this->maximumStreamingPacketsPerPoll + 8
            || $this->maximumDirectedPacketsPerPoll > 65_535
            || $this->maximumStreamingPacketsPerPoll > 64
            || $this->maximumSubChunkOffsetsPerRequest > 256
            || $this->maximumTrackedSubChunkRequests > 4_096
            || $this->maximumTrackedSubChunkSections > 8_192) {
            throw new InvalidArgumentException('Runtime limit exceeds its hard safety ceiling.');
        }
    }
}
