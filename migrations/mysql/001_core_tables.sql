CREATE TABLE IF NOT EXISTS api_policy (
    api_id VARCHAR(255) NOT NULL,
    plugin_id VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    visibility VARCHAR(32) NOT NULL DEFAULT 'private',
    required_scopes JSON NOT NULL,
    `constraints` JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (api_id),
    INDEX idx_api_policy_plugin (plugin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_key (
    kid VARCHAR(255) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    scopes JSON NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kid),
    INDEX idx_api_key_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
