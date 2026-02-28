CREATE TABLE IF NOT EXISTS plugin_ecdict_entry (
    id BIGSERIAL PRIMARY KEY,
    word VARCHAR(255) NOT NULL,
    definition TEXT NOT NULL,
    phonetic VARCHAR(255),
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_plugin_ecdict_word ON plugin_ecdict_entry(word);
