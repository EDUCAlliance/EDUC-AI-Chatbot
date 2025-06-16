-- PostgreSQL schema for the Nextcloud AI Chatbot
-- This schema is based on the NEXTCLOUD_AI_CHATBOT_IMPLEMENTATION_PLAN.md

-- Ensure the pgvector extension is available. The deployment system should have this enabled.
CREATE EXTENSION IF NOT EXISTS vector;

-- Stores admin user credentials for the bot's admin panel.
CREATE TABLE IF NOT EXISTS bot_admin (
  id SERIAL PRIMARY KEY,
  password_hash TEXT NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores global settings for the chatbot.
-- It's a singleton table, ensuring only one row of settings exists.
CREATE TABLE IF NOT EXISTS bot_settings (
  id INTEGER PRIMARY KEY CHECK (id=1),
  mention_name TEXT DEFAULT '@educai',
  default_model TEXT DEFAULT 'meta-llama-3.1-8b-instruct',
  system_prompt TEXT,
  onboarding_group_questions JSONB,
  onboarding_dm_questions JSONB
);

-- Stores metadata about uploaded documents for RAG.
CREATE TABLE IF NOT EXISTS bot_docs (
  id SERIAL PRIMARY KEY,
  filename TEXT NOT NULL,
  path TEXT NOT NULL,
  checksum CHAR(64) UNIQUE NOT NULL, -- SHA-256 checksum to prevent duplicates
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores vector embeddings for document chunks.
-- The embedding dimension is set to 1024 as a common default.
CREATE TABLE IF NOT EXISTS bot_embeddings (
  doc_id INT REFERENCES bot_docs(id) ON DELETE CASCADE,
  chunk_index INT NOT NULL,
  embedding VECTOR(1024) NOT NULL,
  text TEXT,
  PRIMARY KEY (doc_id, chunk_index)
);

-- Stores the history of all conversations.
CREATE TABLE IF NOT EXISTS bot_conversations (
  id BIGSERIAL PRIMARY KEY,
  room_token TEXT NOT NULL,
  user_id TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('user', 'assistant')), -- Enforces valid roles
  content TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores configuration for each Nextcloud Talk room.
CREATE TABLE IF NOT EXISTS bot_room_config (
  room_token TEXT PRIMARY KEY,
  is_group BOOLEAN NOT NULL,
  mention_mode TEXT NOT NULL CHECK (mention_mode IN ('always', 'on_mention')),
  onboarding_done BOOLEAN DEFAULT FALSE,
  meta JSONB -- For storing onboarding answers and other state.
); 