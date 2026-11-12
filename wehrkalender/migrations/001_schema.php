<?php

/**
 * Grundschema. Platzhalter werden vom Migrator je Datenbank ersetzt:
 *   {PK}      Primärschlüssel id mit Autowert
 *   {TABLE}   Tabellenoptionen (MariaDB: InnoDB, utf8mb4)
 *   {LONGTEXT} großer Text (MariaDB: MEDIUMTEXT)
 * Alle Zeitstempel sind UTC ("Y-m-d H:i:s").
 */
return [
    "CREATE TABLE users (
        id {PK},
        mp_id VARCHAR(64) NOT NULL,
        username VARCHAR(100) NOT NULL,
        first_name VARCHAR(150) NOT NULL,
        last_name VARCHAR(150) NOT NULL,
        email VARCHAR(254) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        password_enc VARCHAR(255) NOT NULL,
        password_changed_at DATETIME NULL,
        is_admin SMALLINT NOT NULL DEFAULT 0,
        welcome_sent_for VARCHAR(254) NULL,
        welcome_sent_at DATETIME NULL,
        welcome_used_at DATETIME NULL,
        first_web_login_at DATETIME NULL,
        last_web_login_at DATETIME NULL,
        last_dav_at DATETIME NULL,
        last_dav_client VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT uq_users_mp_id UNIQUE (mp_id),
        CONSTRAINT uq_users_username UNIQUE (username)
    ) {TABLE}",
    "CREATE INDEX ix_users_email ON users (email)",

    "CREATE TABLE calendars (
        id {PK},
        slug VARCHAR(100) NOT NULL,
        display_name VARCHAR(150) NOT NULL,
        color VARCHAR(9) NOT NULL DEFAULT '#B91C1C',
        description TEXT NULL,
        sync_token INTEGER NOT NULL DEFAULT 1,
        active SMALLINT NOT NULL DEFAULT 1,
        all_members SMALLINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT uq_calendars_slug UNIQUE (slug)
    ) {TABLE}",

    "CREATE TABLE calendar_sources (
        id {PK},
        mp_key VARCHAR(100) NOT NULL,
        calendar_id INTEGER NOT NULL,
        created_at DATETIME NOT NULL,
        CONSTRAINT uq_calendar_sources_key UNIQUE (mp_key),
        CONSTRAINT fk_cs_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id) ON DELETE CASCADE
    ) {TABLE}",

    "CREATE TABLE memberships (
        id {PK},
        user_id INTEGER NOT NULL,
        mp_key VARCHAR(100) NOT NULL,
        label VARCHAR(200) NOT NULL,
        CONSTRAINT fk_m_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_memberships_user ON memberships (user_id)",
    "CREATE INDEX ix_memberships_key ON memberships (mp_key)",

    "CREATE TABLE user_calendars (
        user_id INTEGER NOT NULL,
        calendar_id INTEGER NOT NULL,
        since DATETIME NOT NULL,
        PRIMARY KEY (user_id, calendar_id),
        CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_uc_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_uc_calendar ON user_calendars (calendar_id)",

    "CREATE TABLE events (
        id {PK},
        mp_event_id VARCHAR(100) NOT NULL,
        calendar_id INTEGER NOT NULL,
        uid VARCHAR(255) NOT NULL,
        summary VARCHAR(500) NOT NULL,
        location VARCHAR(500) NULL,
        description TEXT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        all_day SMALLINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'CONFIRMED',
        ics {LONGTEXT} NOT NULL,
        etag VARCHAR(64) NOT NULL,
        content_hash VARCHAR(64) NOT NULL,
        sequence_no INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT uq_events_mp UNIQUE (mp_event_id),
        CONSTRAINT uq_events_uid UNIQUE (uid),
        CONSTRAINT fk_e_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_events_cal_start ON events (calendar_id, starts_at)",
    "CREATE INDEX ix_events_cal_end ON events (calendar_id, ends_at)",

    "CREATE TABLE calendar_changes (
        id {PK},
        calendar_id INTEGER NOT NULL,
        sync_token INTEGER NOT NULL,
        uri VARCHAR(300) NOT NULL,
        operation SMALLINT NOT NULL,
        changed_at DATETIME NOT NULL,
        CONSTRAINT fk_cc_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_cc_cal_token ON calendar_changes (calendar_id, sync_token)",

    "CREATE TABLE feed_tokens (
        id {PK},
        user_id INTEGER NOT NULL,
        calendar_id INTEGER NOT NULL,
        token VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        last_access_at DATETIME NULL,
        last_client VARCHAR(255) NULL,
        CONSTRAINT uq_feed_token UNIQUE (token),
        CONSTRAINT fk_ft_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_ft_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_ft_user_cal ON feed_tokens (user_id, calendar_id)",

    "CREATE TABLE login_tokens (
        id {PK},
        user_id INTEGER NOT NULL,
        token_hash CHAR(64) NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        CONSTRAINT uq_login_token UNIQUE (token_hash),
        CONSTRAINT fk_lt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_lt_user ON login_tokens (user_id, purpose)",

    "CREATE TABLE import_runs (
        id {PK},
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        status VARCHAR(20) NOT NULL,
        stats TEXT NULL,
        source_files TEXT NULL
    ) {TABLE}",
    "CREATE INDEX ix_ir_started ON import_runs (started_at)",

    "CREATE TABLE import_messages (
        id {PK},
        run_id INTEGER NOT NULL,
        level VARCHAR(10) NOT NULL,
        type VARCHAR(50) NOT NULL,
        user_id INTEGER NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        CONSTRAINT fk_im_run FOREIGN KEY (run_id) REFERENCES import_runs (id) ON DELETE CASCADE,
        CONSTRAINT fk_im_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) {TABLE}",
    "CREATE INDEX ix_im_run ON import_messages (run_id, level, type)",

    "CREATE TABLE mail_queue (
        id {PK},
        user_id INTEGER NULL,
        type VARCHAR(30) NOT NULL,
        recipient VARCHAR(254) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body_text TEXT NOT NULL,
        body_html {LONGTEXT} NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        next_attempt_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        CONSTRAINT fk_mq_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_mq_status ON mail_queue (status, next_attempt_at)",

    "CREATE TABLE login_attempts (
        id {PK},
        throttle_key VARCHAR(100) NOT NULL,
        attempted_at DATETIME NOT NULL,
        info VARCHAR(100) NULL
    ) {TABLE}",
    "CREATE INDEX ix_la_key ON login_attempts (throttle_key, attempted_at)",

    "CREATE TABLE username_history (
        id {PK},
        user_id INTEGER NOT NULL,
        old_username VARCHAR(100) NOT NULL,
        new_username VARCHAR(100) NOT NULL,
        changed_by INTEGER NULL,
        changed_at DATETIME NOT NULL,
        CONSTRAINT fk_uh_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) {TABLE}",
    "CREATE INDEX ix_uh_old ON username_history (old_username)",

    "CREATE TABLE app_state (
        name VARCHAR(100) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL
    ) {TABLE}",
];
