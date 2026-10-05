<?php

namespace App\Exceptions;

/** Planned capability (AI Gateway, claim validation) that has no implementation yet. */
class ServiceNotConfiguredException extends ApiException
{
    public function __construct(string $service)
    {
        parent::__construct('SERVICE_NOT_CONFIGURED', "{$service} is not configured in this deployment.", 501);
    }
}
