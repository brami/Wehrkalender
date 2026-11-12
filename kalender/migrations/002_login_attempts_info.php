<?php

/**
 * Hinweis zu jedem Fehlversuch, damit Administratoren gesperrte Anmeldungen
 * erkennen und zurücksetzen können: bei Benutzern der Benutzername, bei
 * IP-Adressen nur eine gekürzte Form (IPv4 /24, IPv6 /48).
 */
return [
    "ALTER TABLE login_attempts ADD COLUMN info VARCHAR(100) NULL",
];
