<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Attaches PrivacyProcessor to any channel via the "tap" option, which every built-in driver
 * honours (the "processors" option only applies to the monolog driver).
 */
class PrivacyTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new PrivacyProcessor);
    }
}
