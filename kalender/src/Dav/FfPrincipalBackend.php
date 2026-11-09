<?php

declare(strict_types=1);

namespace Kalender\Dav;

use Closure;
use Kalender\Database\Db;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/**
 * Principals principals/<username> für aktive Benutzer, nur lesend.
 *
 * Datenschutz: Es wird immer nur der Principal des angemeldeten Benutzers
 * geliefert. Andere Benutzer lassen sich weder auflisten noch suchen; auch
 * /dav/calendars/<fremder-name>/ ergibt dadurch 404.
 */
final class FfPrincipalBackend implements BackendInterface
{
    /** @param Closure():?string $currentPrincipal liefert "principals/<username>" des angemeldeten Benutzers */
    public function __construct(private Db $db, private Closure $currentPrincipal)
    {
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        if (trim((string) $prefixPath, '/') !== 'principals') {
            return [];
        }
        $p = $this->getPrincipalByPath((string) ($this->currentPrincipal)());
        return $p === null ? [] : [$p];
    }

    public function getPrincipalByPath($path)
    {
        $path = trim((string) $path, '/');
        $current = ($this->currentPrincipal)();
        if ($current === null || $path !== $current || !str_starts_with($path, 'principals/')) {
            return null;
        }
        $username = substr($path, strlen('principals/'));
        $user = $this->db->one("SELECT username, first_name, last_name, email FROM users WHERE username = ? AND status = 'active'", [$username]);
        if ($user === null) {
            return null;
        }
        return [
            'uri' => 'principals/' . $user['username'],
            '{DAV:}displayname' => trim($user['first_name'] . ' ' . $user['last_name']),
            '{http://sabredav.org/ns}email-address' => $user['email'],
        ];
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        // nur lesend: alle Änderungen ablehnen
        $propPatch->handleRemaining(fn () => false);
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        return [];
    }

    public function findByUri($uri, $principalPrefix)
    {
        $current = ($this->currentPrincipal)();
        if ($current === null) {
            return null;
        }
        if (str_starts_with((string) $uri, 'mailto:')) {
            $email = strtolower(substr((string) $uri, 7));
            $user = $this->getPrincipalByPath($current);
            if ($user !== null && strtolower((string) $user['{http://sabredav.org/ns}email-address']) === $email) {
                return $current;
            }
        }
        return null;
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new Forbidden('Nur lesender Zugriff');
    }
}
