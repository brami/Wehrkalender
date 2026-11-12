<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

/** Client-IP, X-Forwarded-For nur von vertrauenswürdigen Proxys (Caddy) */
final class ClientIp
{
    /** @param list<string> $trustedProxies CIDR-Liste */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '' || !self::inAny($remote, $trustedProxies)) {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($hops as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !self::inAny($ip, $trustedProxies)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** @param list<string> $cidrs */
    public static function inAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }
}
