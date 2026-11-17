<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Internal signal: release every root and rediscover, never extend a lower-order lock set. */
final class FinancialLockSetChanged extends RuntimeException {}
