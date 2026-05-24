<?php

namespace App\Services\SystemUpdate;

use RuntimeException;

class SystemUpdateEnvironmentNotReadyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        private readonly array $report,
        string $message = 'System update environment is not ready.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report;
    }
}
