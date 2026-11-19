<?php

namespace App\Services\Payments;

use RuntimeException;

/** The gateway could not be reached or refused the request. The message is safe to show; details go to the log. */
final class GatewayException extends RuntimeException {}
