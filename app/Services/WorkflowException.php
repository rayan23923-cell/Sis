<?php

namespace App\Services;

use RuntimeException;

/** A business rule stopped the action. The message is shown to the user as is (Arabic). */
class WorkflowException extends RuntimeException {}
