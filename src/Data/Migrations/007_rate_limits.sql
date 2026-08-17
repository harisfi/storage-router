-- rate_limits: fixed-window per-app request counters.
-- One row per (app_id, endpoint, window_start); window_start is the start
-- of a 60-second bucket (unix timestamp, floored to the minute).
CREATE TABLE rate_limits (
    app_id       TEXT NOT NULL,
    endpoint     TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (app_id, endpoint, window_start)
);

CREATE INDEX idx_rate_limits_window ON rate_limits(window_start);
