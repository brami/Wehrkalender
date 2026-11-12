<?php

declare(strict_types=1);

namespace Wehrkalender\Import;

use RuntimeException;

/** DNS ist nicht erreichbar; der Import muss abbrechen. */
final class DnsUnavailable extends RuntimeException
{
}
