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

-- Fix bot_room_config table structure if needed
-- The table should match the schema in database.sql
-- Check if we need to rename columns from old structure
DO $$
BEGIN
    -- Check if old 'room_type' column exists and rename it
    IF EXISTS (SELECT 1 FROM information_schema.columns 
               WHERE table_name = 'bot_room_config' AND column_name = 'room_type') THEN
        ALTER TABLE bot_room_config RENAME COLUMN room_type TO is_group_temp;
        ALTER TABLE bot_room_config ADD COLUMN is_group BOOLEAN DEFAULT true;
        UPDATE bot_room_config SET is_group = (is_group_temp = 'group');
        ALTER TABLE bot_room_config DROP COLUMN is_group_temp;
    END IF;
    
    -- Check if old 'onboarding_state' column exists and convert it
    IF EXISTS (SELECT 1 FROM information_schema.columns 
               WHERE table_name = 'bot_room_config' AND column_name = 'onboarding_state') THEN
        ALTER TABLE bot_room_config ADD COLUMN onboarding_done BOOLEAN DEFAULT false;
        UPDATE bot_room_config SET onboarding_done = (onboarding_state = 'completed');
        ALTER TABLE bot_room_config DROP COLUMN onboarding_state;
    END IF;
    
    -- Add missing columns
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'bot_room_config' AND column_name = 'mention_mode') THEN
        ALTER TABLE bot_room_config ADD COLUMN mention_mode TEXT DEFAULT 'on_mention' 
        CHECK (mention_mode IN ('always', 'on_mention'));
    END IF;
END $$; 