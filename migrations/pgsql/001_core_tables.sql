CREATE TABLE IF NOT EXISTS api_policy (
    api_id VARCHAR(255) PRIMARY KEY,
    plugin_id VARCHAR(255) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    visibility VARCHAR(32) NOT NULL DEFAULT 'private',
    required_scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    "constraints" JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_policy_plugin ON api_policy (plugin_id);

CREATE TABLE IF NOT EXISTS api_key (
    kid VARCHAR(255) PRIMARY KEY,
    secret_hash VARCHAR(255) NOT NULL,
    scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    description TEXT,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_key_active ON api_key (active);
