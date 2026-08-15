-- app_storage_access: which backends an app may use
CREATE TABLE app_storage_access (
    app_id      TEXT NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
    storage_id  TEXT NOT NULL REFERENCES storage_backends(id) ON DELETE CASCADE,
    priority    INTEGER NOT NULL DEFAULT 100,
    enabled     INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    PRIMARY KEY (app_id, storage_id)
);

CREATE INDEX idx_app_storage_access_app ON app_storage_access(app_id);
