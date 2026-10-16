CREATE TABLE shares (
  id VARCHAR(22) NOT NULL PRIMARY KEY,
  status VARCHAR(16) NOT NULL,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  closed_at BIGINT NOT NULL DEFAULT 0,
  ttl_seconds BIGINT NOT NULL,
  kdf_v INTEGER NOT NULL,
  salt VARCHAR(64) NOT NULL,
  auth_verifier VARCHAR(64) NOT NULL,
  enc_meta TEXT NOT NULL,
  sealed_name TEXT NOT NULL,
  chunk_count BIGINT NOT NULL,
  max_fails INTEGER NOT NULL,
  fail_action VARCHAR(16) NOT NULL,
  fail_count INTEGER NOT NULL DEFAULT 0,
  frozen_until BIGINT NOT NULL DEFAULT 0,
  burn_after_complete INTEGER NOT NULL,
  max_completions INTEGER NOT NULL,
  completions INTEGER NOT NULL DEFAULT 0,
  upload_token_hash VARCHAR(64) NOT NULL
);
CREATE INDEX idx_shares_status_expires ON shares (status, expires_at);
CREATE INDEX idx_shares_closed ON shares (closed_at);
CREATE TABLE settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
);
CREATE TABLE admin (
  id INTEGER NOT NULL PRIMARY KEY,
  pub TEXT NOT NULL,
  wrapped_priv TEXT NOT NULL,
  wrap_salt VARCHAR(64) NOT NULL,
  login_verifier VARCHAR(64) NOT NULL,
  totp_secret_enc TEXT NOT NULL,
  created_at BIGINT NOT NULL
)
