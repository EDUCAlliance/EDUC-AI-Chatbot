-- Nextcloud AI Chatbot Database Schema
-- PostgreSQL with pgvector extension for embeddings

-- Ensure pgvector extension is available
CREATE EXTENSION IF NOT EXISTS vector;

-- Admin users table
CREATE TABLE IF NOT EXISTS bot_admin (
    id SERIAL PRIMARY KEY,
    password_hash TEXT NOT NULL,
    email TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP
);

-- Bot settings and configuration
CREATE TABLE IF NOT EXISTS bot_settings (
    id INTEGER PRIMARY KEY CHECK (id=1),
    mention TEXT DEFAULT '@educai',
    default_model TEXT DEFAULT 'meta-llama-3.1-8b-instruct',
    embedding_model TEXT DEFAULT 'e5-mistral-7b-instruct',
    system_prompt TEXT DEFAULT 'You are EDUC AI, a helpful AI assistant for the EDUC Alliance. You have access to educational resources and can help with questions about online learning, educational technology, and academic collaboration.',
    max_tokens INTEGER DEFAULT 512,
    temperature REAL DEFAULT 0.7,
    top_k INTEGER DEFAULT 5,
    onboarding_group JSONB DEFAULT '["Is this a group chat (yes/no)?", "Should I respond to every message or only when mentioned?", "What type of educational content would you like help with?"]'::jsonb,
    onboarding_dm JSONB DEFAULT '["What subject area are you most interested in?", "Are you a student, teacher, or researcher?", "What type of assistance do you need most often?"]'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default settings if not exists
INSERT INTO bot_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

-- Documents for RAG
CREATE TABLE IF NOT EXISTS bot_docs (
    id SERIAL PRIMARY KEY,
    filename TEXT NOT NULL,
    original_filename TEXT,
    path TEXT,
    content_type TEXT,
    file_size INTEGER,
    checksum CHAR(64) UNIQUE,
    status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP
);

-- Document embeddings with vector storage
CREATE TABLE IF NOT EXISTS bot_embeddings (
    id SERIAL PRIMARY KEY,
    doc_id INTEGER REFERENCES bot_docs(id) ON DELETE CASCADE,
    chunk_index INTEGER NOT NULL,
    embedding VECTOR(1024), -- e5-mistral-7b-instruct uses 1024 dimensions
    text_content TEXT NOT NULL,
    chunk_metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(doc_id, chunk_index)
);

-- Create index for vector similarity search
CREATE INDEX IF NOT EXISTS idx_embeddings_vector ON bot_embeddings USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);

-- Create index for document lookups
CREATE INDEX IF NOT EXISTS idx_embeddings_doc_id ON bot_embeddings(doc_id);

-- Conversation history
CREATE TABLE IF NOT EXISTS bot_conversations (
    id BIGSERIAL PRIMARY KEY,
    room_token TEXT NOT NULL,
    user_id TEXT NOT NULL,
    user_name TEXT,
    role TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'system')),
    content TEXT NOT NULL,
    model_used TEXT,
    tokens_used INTEGER,
    processing_time_ms INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for conversation queries
CREATE INDEX IF NOT EXISTS idx_conversations_room_token ON bot_conversations(room_token);
CREATE INDEX IF NOT EXISTS idx_conversations_user_id ON bot_conversations(user_id);
CREATE INDEX IF NOT EXISTS idx_conversations_created_at ON bot_conversations(created_at);

-- Room configuration and onboarding state
CREATE TABLE IF NOT EXISTS bot_room_config (
    room_token TEXT PRIMARY KEY,
    is_group BOOLEAN DEFAULT false,
    mention_mode TEXT DEFAULT 'on_mention' CHECK (mention_mode IN ('always', 'on_mention')),
    onboarding_done BOOLEAN DEFAULT false,
    onboarding_stage INTEGER DEFAULT 0,
    custom_prompt TEXT,
    enabled BOOLEAN DEFAULT true,
    meta JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create trigger to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Apply trigger to tables with updated_at columns
CREATE TRIGGER update_bot_settings_updated_at BEFORE UPDATE ON bot_settings
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_bot_room_config_updated_at BEFORE UPDATE ON bot_room_config
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- API usage tracking
CREATE TABLE IF NOT EXISTS bot_api_usage (
    id SERIAL PRIMARY KEY,
    endpoint TEXT NOT NULL,
    model TEXT,
    tokens_used INTEGER,
    processing_time_ms INTEGER,
    success BOOLEAN DEFAULT true,
    error_message TEXT,
    user_id TEXT,
    room_token TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create index for usage analytics
CREATE INDEX IF NOT EXISTS idx_api_usage_created_at ON bot_api_usage(created_at);
CREATE INDEX IF NOT EXISTS idx_api_usage_endpoint ON bot_api_usage(endpoint);

-- System logs
CREATE TABLE IF NOT EXISTS bot_logs (
    id BIGSERIAL PRIMARY KEY,
    level TEXT NOT NULL CHECK (level IN ('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL')),
    message TEXT NOT NULL,
    context JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create index for log queries
CREATE INDEX IF NOT EXISTS idx_logs_level ON bot_logs(level);
CREATE INDEX IF NOT EXISTS idx_logs_created_at ON bot_logs(created_at);

-- Views for common queries
CREATE OR REPLACE VIEW conversation_summary AS
SELECT 
    room_token,
    COUNT(*) as total_messages,
    COUNT(CASE WHEN role = 'user' THEN 1 END) as user_messages,
    COUNT(CASE WHEN role = 'assistant' THEN 1 END) as bot_responses,
    COUNT(DISTINCT user_id) as unique_users,
    MAX(created_at) as last_activity,
    MIN(created_at) as first_activity
FROM bot_conversations
GROUP BY room_token;

CREATE OR REPLACE VIEW document_stats AS
SELECT 
    COUNT(*) as total_docs,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as processed_docs,
    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_docs,
    SUM(file_size) as total_size,
    COUNT(DISTINCT be.doc_id) as docs_with_embeddings,
    COUNT(be.id) as total_embeddings
FROM bot_docs bd
LEFT JOIN bot_embeddings be ON bd.id = be.doc_id;

-- Function to search similar content using vector similarity
CREATE OR REPLACE FUNCTION search_similar_content(
    query_embedding VECTOR(1024),
    similarity_threshold REAL DEFAULT 0.3,
    max_results INTEGER DEFAULT 5
)
RETURNS TABLE (
    doc_id INTEGER,
    chunk_index INTEGER,
    text_content TEXT,
    similarity REAL,
    filename TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        be.doc_id,
        be.chunk_index,
        be.text_content,
        1 - (be.embedding <=> query_embedding) as similarity,
        bd.filename
    FROM bot_embeddings be
    JOIN bot_docs bd ON be.doc_id = bd.id
    WHERE 1 - (be.embedding <=> query_embedding) > similarity_threshold
    ORDER BY be.embedding <=> query_embedding
    LIMIT max_results;
END;
$$ LANGUAGE plpgsql; 