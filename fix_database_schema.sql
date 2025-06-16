-- Fix database schema for bot_settings table
-- This ensures the table has the correct structure as defined in database.sql

-- Add missing columns if they don't exist
ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS mention_name TEXT DEFAULT '@educai';
ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS embedding_model TEXT DEFAULT 'e5-mistral-7b-instruct';
ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS rag_top_k INTEGER DEFAULT 3;
ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS rag_chunk_size INTEGER DEFAULT 250;
ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS rag_chunk_overlap INTEGER DEFAULT 25;

-- Ensure we have exactly one settings row
INSERT INTO bot_settings (id, mention_name) VALUES (1, '@educai') 
ON CONFLICT (id) DO NOTHING;

-- Update mention_name if it's still null
UPDATE bot_settings SET mention_name = '@educai' WHERE id = 1 AND mention_name IS NULL; 