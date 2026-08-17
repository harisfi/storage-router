-- Records which KEK version actually wrapped this file's DEK, so a file
-- uploaded before a KEK rotation can still be decrypted correctly even
-- if bin/rotate-kek.php hasn't (yet) re-wrapped it. Defaults to 1 for
-- consistency with apps.kek_version's default.
ALTER TABLE files ADD COLUMN kek_version INTEGER NOT NULL DEFAULT 1;
