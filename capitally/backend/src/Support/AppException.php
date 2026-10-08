<?php
declare(strict_types=1);

namespace CapiTally\Support;

use RuntimeException;
use Throwable;

/**
 * An error whose message is safe to show to a normal user.
 * Anything that is NOT an AppException is internal and is replaced with a
 * generic message before it leaves the server.
 */
final class AppException extends RuntimeException
{
    /**
     * @param string $errorCode Stable machine code, e.g. INSUFFICIENT_STOCK
     * @param array  $details   Safe structured data for the client (e.g. duplicate candidates)
     */
    public function __construct(
        string $userMessage,
        private readonly string $errorCode = 'APP_ERROR',
        private readonly int $httpStatus = 400,
        ?Throwable $previous = null,
        private readonly array $details = []
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function details(): array
    {
        return $this->details;
    }
}
