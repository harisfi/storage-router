-- Adds versioned KEK support: apps.kek_version is
-- the CURRENT version new uploads wrap their DEK under. Rotation bumps
-- this and re-wraps existing files' DEKs (see bin/rotate-kek.php) rather
-- than leaving old files permanently tied to a retired key.
ALTER TABLE apps ADD COLUMN kek_version INTEGER NOT NULL DEFAULT 1;
