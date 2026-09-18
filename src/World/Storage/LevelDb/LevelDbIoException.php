<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use RuntimeException;

/** Native database availability, locking, checksum, read, or write failure. */
final class LevelDbIoException extends RuntimeException {}
