-- audit_log: admin actions, content-level access, and operational failures.
-- status=error rows carry a "reason" and an
-- "errors" array in metadata so one failed request can capture more than
-- one contributing cause in a single row.
CREATE TABLE audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type  TEXT NOT NULL CHECK (actor_type IN ('admin', 'app')),
    actor_id    TEXT NOT NULL,
    action      TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'success' CHECK (status IN ('success', 'error')),
    target_id   TEXT,
    metadata    TEXT, -- JSON
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX idx_audit_log_actor ON audit_log(actor_type, actor_id);
CREATE INDEX idx_audit_log_status ON audit_log(status);
