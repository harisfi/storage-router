-- storage_backends: Google Drive accounts and local disk paths
CREATE TABLE storage_backends (
    id                   TEXT PRIMARY KEY,
    label                TEXT NOT NULL,
    provider_type        TEXT NOT NULL CHECK (provider_type IN ('google_drive', 'local')),
    provider_config      TEXT NOT NULL, -- JSON; shape depends on provider_type
    quota_used_bytes     INTEGER NOT NULL DEFAULT 0,
    quota_total_bytes    INTEGER NOT NULL DEFAULT 0,
    status               TEXT NOT NULL DEFAULT 'enabled' CHECK (status IN ('enabled', 'disabled')),
    last_quota_check_at  TEXT,
    created_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
