<?php

declare(strict_types=1);

namespace Wehrkalender\Import;

use RuntimeException;

/** Fehler beim Lesen einer iCal-Datei bzw. eines Feeds; Meldung ist für Admins verständlich formuliert */
final class IcalError extends RuntimeException
{
}
