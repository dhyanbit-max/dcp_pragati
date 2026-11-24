<?php

namespace App\Domain\Import;

use RuntimeException;

/** A problem with one row; the row is skipped and the message shown in the report. */
class RowError extends RuntimeException {}
