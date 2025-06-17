-- PostgreSQL schema for the Nextcloud AI Chatbot
-- This schema is based on the MULTI_BOT_PLAN.md

-- Ensure the pgvector extension is available. The deployment system should have this enabled.
CREATE EXTENSION IF NOT EXISTS vector;

-- Stores admin user credentials for the bot's admin panel.
CREATE TABLE IF NOT EXISTS bot_admin (
  id SERIAL PRIMARY KEY,
  password_hash TEXT NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores configuration for each individual bot.
CREATE TABLE IF NOT EXISTS bots (
  id SERIAL PRIMARY KEY,
  bot_name TEXT NOT NULL,
  mention_name TEXT UNIQUE NOT NULL,
  default_model TEXT DEFAULT 'meta-llama-3.1-8b-instruct',
  system_prompt TEXT,
  onboarding_group_questions JSONB,
  onboarding_dm_questions JSONB,
  embedding_model TEXT DEFAULT 'e5-mistral-7b-instruct',
  rag_top_k INTEGER DEFAULT 3,
  rag_chunk_size INTEGER DEFAULT 250,
  rag_chunk_overlap INTEGER DEFAULT 25,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores metadata about uploaded documents for RAG.
CREATE TABLE IF NOT EXISTS bot_docs (
  id SERIAL PRIMARY KEY,
  bot_id INTEGER NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
  filename TEXT NOT NULL,
  path TEXT NOT NULL,
  checksum CHAR(64) UNIQUE NOT NULL, -- SHA-256 checksum to prevent duplicates
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores vector embeddings for document chunks.
-- The embedding dimension is set to 4096 as a common default.
CREATE TABLE IF NOT EXISTS bot_embeddings (
  id SERIAL PRIMARY KEY,
  doc_id INTEGER NOT NULL REFERENCES bot_docs(id) ON DELETE CASCADE,
  chunk_index INTEGER NOT NULL,
  embedding vector(4096),
  text TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(doc_id, chunk_index)
);

-- Stores the history of all conversations.
CREATE TABLE IF NOT EXISTS bot_conversations (
  id BIGSERIAL PRIMARY KEY,
  bot_id INTEGER NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
  room_token TEXT NOT NULL,
  user_id TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('user', 'assistant')), -- Enforces valid roles
  content TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores configuration for each Nextcloud Talk room.
CREATE TABLE IF NOT EXISTS bot_room_config (
  room_token TEXT PRIMARY KEY,
  bot_id INTEGER NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
  is_group BOOLEAN NOT NULL,
  mention_mode TEXT NOT NULL CHECK (mention_mode IN ('always', 'on_mention')),
  onboarding_done BOOLEAN DEFAULT FALSE,
  meta JSONB -- For storing onboarding answers and other state.
); 