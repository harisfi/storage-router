-- files: metadata index, the source of truth for where a file actually lives.
-- Content itself is never stored here — only encrypted-DEK + provider reference.
CREATE TABLE files (
    id                   TEXT PRIMARY KEY,           -- UUID, the only id ever exposed to clients
    app_id               TEXT NOT NULL REFERENCES apps(id) ON DELETE RESTRICT,
    user_id              TEXT,                        -- app-defined, opaque, optional
    storage_id           TEXT NOT NULL REFERENCES storage_backends(id) ON DELETE RESTRICT,
    provider_ref         TEXT NOT NULL,               -- Drive file id, or local relative path
    encrypted_dek         TEXT NOT NULL,               -- DEK wrapped under the app's KEK
    stream_header          TEXT NOT NULL,               -- streaming AEAD nonce/init data
    size_bytes            INTEGER NOT NULL,
    mime_type             TEXT NOT NULL,
    checksum_plaintext      TEXT NOT NULL,
    status                TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted')),
    created_at            TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX idx_files_app_user ON files(app_id, user_id);
CREATE INDEX idx_files_storage ON files(storage_id);
