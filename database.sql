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
  onboarding_dm_questions JSONB,
  embedding_model TEXT DEFAULT 'e5-mistral-7b-instruct',
  rag_top_k INTEGER DEFAULT 3,
  rag_chunk_size INTEGER DEFAULT 250,
  rag_chunk_overlap INTEGER DEFAULT 25
);

-- Multi-bot architecture: stores individual bot configurations
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

-- Stores metadata about uploaded documents for RAG (now per-bot)
CREATE TABLE IF NOT EXISTS bot_docs (
  id SERIAL PRIMARY KEY,
  filename TEXT NOT NULL,
  path TEXT NOT NULL,
  checksum CHAR(64) NOT NULL, -- SHA-256 checksum
  bot_id INTEGER NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
  processing_status TEXT DEFAULT 'completed',
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(checksum, bot_id) -- Allow same document for different bots
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

-- Stores the history of all conversations (now per-bot)
CREATE TABLE IF NOT EXISTS bot_conversations (
  id BIGSERIAL PRIMARY KEY,
  room_token TEXT NOT NULL,
  user_id TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('user', 'assistant')), -- Enforces valid roles
  content TEXT,
  bot_id INTEGER REFERENCES bots(id) ON DELETE CASCADE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Stores configuration for each Nextcloud Talk room (now per-bot)
CREATE TABLE IF NOT EXISTS bot_room_config (
  room_token TEXT PRIMARY KEY,
  is_group BOOLEAN NOT NULL,
  mention_mode TEXT NOT NULL CHECK (mention_mode IN ('always', 'on_mention')),
  onboarding_done BOOLEAN DEFAULT FALSE,
  bot_id INTEGER REFERENCES bots(id) ON DELETE CASCADE,
  meta JSONB -- For storing onboarding answers and other state.
);

-- Progress tracking for document processing
CREATE TABLE IF NOT EXISTS bot_processing_progress (
  id SERIAL PRIMARY KEY,
  doc_id INTEGER REFERENCES bot_docs(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'pending',
  progress INTEGER DEFAULT 0,
  current_chunk INTEGER DEFAULT 0,
  total_chunks INTEGER DEFAULT 0,
  error_message TEXT,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP,
  UNIQUE(doc_id)
); 