CREATE TABLE IF NOT EXISTS rag_eval_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(80) NOT NULL UNIQUE,
  kb_id BIGINT UNSIGNED NOT NULL,
  dataset_id BIGINT UNSIGNED NOT NULL,
  query TEXT NOT NULL,
  query_modality ENUM('text','image','multimodal') NOT NULL DEFAULT 'text',
  expected_chunk_ids JSON NOT NULL,
  status ENUM('draft','active','disabled') NOT NULL DEFAULT 'draft',
  source_feedback_id VARCHAR(80) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rag_eval_cases_dataset (dataset_id, status),
  INDEX idx_rag_eval_cases_kb (kb_id, status),
  INDEX idx_rag_eval_cases_feedback (source_feedback_id)
);

CREATE TABLE IF NOT EXISTS rag_eval_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id VARCHAR(80) NOT NULL UNIQUE,
  dataset_ids JSON NULL,
  top_k_values JSON NULL,
  case_count INT NOT NULL DEFAULT 0,
  recall_at_1 DECIMAL(6,2) NOT NULL DEFAULT 0,
  recall_at_5 DECIMAL(6,2) NOT NULL DEFAULT 0,
  recall_at_10 DECIMAL(6,2) NOT NULL DEFAULT 0,
  recall_at_20 DECIMAL(6,2) NOT NULL DEFAULT 0,
  mrr DECIMAL(8,4) NOT NULL DEFAULT 0,
  failed_cases INT NOT NULL DEFAULT 0,
  status ENUM('running','succeeded','failed') NOT NULL DEFAULT 'running',
  latency_ms INT NOT NULL DEFAULT 0,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_rag_eval_runs_created (created_at),
  INDEX idx_rag_eval_runs_status (status)
);

CREATE TABLE IF NOT EXISTS rag_eval_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  case_id BIGINT UNSIGNED NOT NULL,
  query TEXT NOT NULL,
  expected_chunk_ids JSON NOT NULL,
  hit_rank INT NULL,
  passed TINYINT(1) NOT NULL DEFAULT 0,
  sources JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rag_eval_results_run (run_id, passed),
  INDEX idx_rag_eval_results_case (case_id)
);
