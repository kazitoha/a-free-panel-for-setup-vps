<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * PowerDNS answered but rejected the request (validation error, unknown zone,
 * bad API key…). The message is safe to show to the panel user.
 */
class PowerDnsError extends RuntimeException {}
