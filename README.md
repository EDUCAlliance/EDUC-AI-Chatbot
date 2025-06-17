# EDUC AI TalkBot

A sophisticated multi-bot AI-powered chatbot system for Nextcloud Talk with Retrieval-Augmented Generation (RAG) capabilities, featuring a comprehensive admin panel for bot management, configuration, and document knowledge bases.

## Table of Contents

- [Overview](#overview)
- [Multi-Bot Architecture](#multi-bot-architecture)
- [RAG System](#rag-system)
- [Features](#features)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin Panel](#admin-panel)
- [Bot Management](#bot-management)
- [Architecture](#architecture)
- [API Integration](#api-integration)
- [Database Schema](#database-schema)
- [Development](#development)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)
- [Security](#security)
- [Contributing](#contributing)

## Overview

The EDUC AI TalkBot is a comprehensive multi-bot chatbot solution designed to integrate seamlessly with Nextcloud Talk. It provides intelligent responses using SAIA/OpenAI's language models enhanced with Retrieval-Augmented Generation (RAG) to answer questions based on your organization's documents and knowledge base.

### Key Capabilities

- **Multi-Bot Architecture**: Create and manage multiple specialized bots with unique personalities and knowledge bases
- **Bot-Specific Knowledge**: Each bot has its own document collection and embeddings for targeted expertise
- **AI-Powered Conversations**: Utilizes SAIA API (GWDG) models for natural language understanding and generation
- **Advanced RAG Integration**: Bot-specific semantic search and document retrieval for contextually relevant answers
- **Intelligent Bot Detection**: Automatic bot assignment based on mention names (@educai, @supportbot, etc.)
- **Room-Bot Association**: Persistent bot assignments to chat rooms with reset capability
- **Document Management**: Upload, process, and manage bot-specific knowledge base documents
- **Configurable Onboarding**: Bot-specific welcome messages, questions, and interaction modes
- **Multi-Room Support**: Different configurations for group chats vs. direct messages
- **Admin Dashboard**: Comprehensive web interface for multi-bot system management
- **Conversation History**: Maintains context across chat sessions with bot-specific storage
- **Vector Search**: Advanced semantic search using pgvector and embedding models with bot isolation

## Multi-Bot Architecture

The system supports multiple specialized bots, each with unique characteristics:

### Bot Isolation & Management
- **Independent Knowledge Bases**: Each bot maintains separate document collections and embeddings
- **Unique Personalities**: Individual system prompts, onboarding flows, and interaction styles
- **Mention-Based Activation**: Users trigger specific bots using custom mention names
- **Room Assignment**: Rooms are assigned to bots on first interaction, preventing conflicts
- **Reset Capability**: Special `((RESET))` command clears room association for fresh bot selection

### Bot Detection Flow
1. **Existing Rooms**: Respond only to the assigned bot's mention name
2. **New Rooms**: Scan for any bot mention and assign first detected bot
3. **Cross-Bot Mentions**: Guide users to use reset command if trying to access different bot
4. **Mention Validation**: Ignore messages without proper bot mentions to prevent spam

### Example Multi-Bot Scenarios
- **@educai**: General educational assistant with course materials and academic resources
- **@supportbot**: Technical support specialist with IT documentation and troubleshooting guides
- **@researchbot**: Research assistant with scientific papers and methodology documents
- **@adminbot**: Administrative helper with policies, procedures, and institutional knowledge

## RAG System

The Retrieval-Augmented Generation system provides bot-specific, contextual document search and knowledge integration.

### Bot-Specific RAG Architecture
```
User Query → Embedding Generation → Bot-Filtered Vector Search → Context Assembly → LLM Generation
```

### How It Works
1. **Query Processing**: User message is converted to embeddings using bot's configured embedding model
2. **Bot-Filtered Search**: Vector similarity search limited to documents belonging to the specific bot
3. **Context Retrieval**: Top-K most relevant document chunks retrieved based on bot's RAG settings
4. **Response Generation**: LLM generates response using bot's system prompt + retrieved context + conversation history

### Key RAG Features
- **Bot Isolation**: Each bot only searches its own document collection
- **Configurable Models**: Different embedding models per bot (e5-mistral-7b-instruct, etc.)
- **Tunable Parameters**: Bot-specific top-K results, chunk size, and overlap settings
- **Semantic Search**: Meaning-based document retrieval, not just keyword matching
- **Source Attribution**: Responses reference specific documents when appropriate

### RAG Configuration Per Bot
- **Embedding Model**: Model used for vectorizing documents and queries
- **Top-K Results**: Number of relevant chunks to retrieve (default: 3)
- **Chunk Size**: Document chunk size in tokens (default: 250)
- **Chunk Overlap**: Overlap between chunks for context continuity (default: 25)

### Vector Search Implementation
```php
// Bot-specific embedding search
$similarChunks = $vectorStore->findSimilar(
    $embeddingResponse['data'][0]['embedding'], 
    $topK, 
    $currentBotId  // Filters to bot's documents only
);
```

The search uses PostgreSQL's pgvector extension with cosine similarity for efficient semantic matching within each bot's knowledge domain.

## Features

### Core Functionality

- **Multi-Bot Intelligence**: Create and manage multiple specialized AI assistants
- **Bot-Specific Knowledge**: Each bot maintains isolated document collections and embeddings
- **Intelligent Bot Detection**: Automatic room-bot assignment based on mention patterns
- **Document-Based Answers**: Bot-filtered document search for accurate, contextual responses
- **Conversation Memory**: Maintains context within chat sessions with bot-specific storage
- **Flexible Model Support**: Compatible with various SAIA/OpenAI models per bot
- **Real-time Processing**: Immediate response to Nextcloud Talk webhooks
- **Room Reset Capability**: Users can reset room-bot associations with `((RESET))` command

### Admin Panel Features

- **Multi-Bot Dashboard**: Overview of all bots with individual statistics
- **Bot Management**: Create, configure, and delete bots with comprehensive settings
- **Bot-Specific Documents**: Upload and manage knowledge base documents per bot
- **Individual Bot Settings**: Unique system prompts, models, and RAG configurations per bot
- **Advanced Onboarding**: Bot-specific welcome messages, questions, and interaction modes
- **Mention Name Control**: Configure custom bot mentions (@educai, @supportbot, etc.)
- **RAG Fine-Tuning**: Per-bot retrieval and generation parameter optimization
- **User Authentication**: Secure access with password protection and CSRF protection
- **Responsive Design**: Works seamlessly on desktop and mobile devices
- **Real-time Processing**: Async document processing with progress tracking

### Advanced Features

- **Bot Isolation**: Complete separation of bot knowledge bases and configurations
- **Vector Embeddings**: Bot-specific document vectorization with configurable models
- **Semantic Search**: Bot-filtered similarity search for contextually relevant content
- **Intelligent Chunking**: Optimized document splitting with configurable parameters
- **Duplicate Prevention**: Bot-specific duplicate detection for document uploads
- **Conversation Analytics**: Track usage and performance metrics per bot
- **Automatic Migration**: Seamless database schema updates for multi-bot architecture
- **Progress Tracking**: Real-time embedding generation status with cancellation support

## System Requirements

### Server Requirements

- **PHP**: 8.1 or higher
- **PostgreSQL**: 12 or higher with pgvector extension
- **Web Server**: Apache or Nginx
- **Memory**: Minimum 512MB RAM (1GB+ recommended)
- **Storage**: 1GB+ for documents and embeddings

### PHP Extensions

- `pdo_pgsql` - PostgreSQL database connectivity
- `curl` - HTTP client functionality
- `json` - JSON processing
- `mbstring` - Multi-byte string handling
- `openssl` - Encryption and security
- `zip` - Archive handling

### External Services

- **SAIA API (GWDG)**: For language models and embeddings (compatible with OpenAI API standard)
- **Nextcloud Talk**: For chat integration via webhooks
- **Embedding API**: For document vectorization (e5-mistral-7b-instruct model)

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/educ-ai-talkbot.git
cd educ-ai-talkbot
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Database Setup

#### Install pgvector Extension

```sql
-- Connect as superuser
CREATE EXTENSION IF NOT EXISTS vector;
```

#### Create Database and Tables

```bash
# Create database
createdb educ_ai_talkbot

# Run schema setup
psql educ_ai_talkbot < schema.sql
```

### 4. Environment Configuration

Create a `.env` file in the project root:

```env
# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_NAME=educ_ai_talkbot
DB_USER=your_db_user
DB_PASS=your_db_password

# SAIA API Configuration (GWDG)
AI_API_KEY=your_saia_api_key
AI_API_ENDPOINT=https://chat-ai.academiccloud.de/v1
EMBEDDING_API_URL=https://chat-ai.academiccloud.de/v1
EMBEDDING_API_KEY=your_saia_api_key

# Nextcloud Integration
BOT_TOKEN=your_webhook_secret_token
NC_URL=your-nextcloud-domain.com
NEXTCLOUD_WEBHOOK_SECRET=your_webhook_secret
ADMIN_PASSWORD_HASH=$2y$10$your_bcrypt_hash

# Application Settings
APP_DEBUG=false
LOG_LEVEL=INFO
```

### 5. Web Server Configuration

#### Apache (.htaccess)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"

# Block access to sensitive files
<Files ~ "^\.">
    Require all denied
</Files>
<FilesMatch "\.(env|log|sql)$">
    Require all denied
</FilesMatch>
```

#### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}

# Security
location ~ /\. {
    deny all;
}
location ~* \.(env|log|sql)$ {
    deny all;
}
```

## Configuration

### Initial Setup

1. **Access Admin Panel**: Navigate to `https://your-domain/admin/`
2. **Complete Setup Wizard**: Follow the guided setup process
3. **Configure Basic Settings**: Set system prompt, onboarding messages
4. **Upload Documents**: Add your knowledge base files
5. **Test Integration**: Verify Nextcloud webhook connectivity

### Nextcloud Talk Integration

#### 1. Bot Registration
Register the bot with Nextcloud using the `occ` command:

```bash
cd /path/to/nextcloud && sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "EDUC AI TalkBot" \
  "your-webhook-secret-token" \
  "https://your-domain.com/connect.php"
```

#### 2. Verify Installation
Check that the bot was registered successfully:

```bash
sudo -u www-data php occ talk:bot:list
```

#### 3. Bot Activation
- Navigate to any Nextcloud Talk conversation
- Go to conversation settings (⚙️ icon)
- Find "EDUC AI TalkBot" in the bot list
- Click to activate the bot for that conversation

#### 4. Environment Variables
Configure the following variables to match your bot registration:

```env
BOT_TOKEN=your-webhook-secret-token     # Same as used in occ command
NC_URL=your-nextcloud-domain.com        # Your Nextcloud domain
```

#### 5. Webhook URL Structure
The webhook endpoint must be accessible at:
```
https://your-domain.com/connect.php
```

#### 6. Bot Mention Configuration
The bot mention can be customized in the admin panel:

1. Navigate to the **Onboarding Configuration** page
2. Set the **Bot Mention Name** field (e.g., `@educai`, `@assistant`, `@help`)
3. Save the configuration

**Usage Examples:**
- `@educai explain machine learning` ✓
- `Can you @educai help me with this?` ✓  
- `@EDUCAI what is AI?` ✓ (case insensitive)
- `Hello everyone` ✗ (no mention - bot ignores)

This prevents the bot from responding to every message in busy chat rooms.

### Advanced Configuration

#### RAG Settings

- **EMBEDDING_MODEL**: Model used for document vectorization
- **RAG_TOP_K**: Number of relevant documents to retrieve (default: 3)
- **RAG_CHUNK_SIZE**: Size of document chunks in tokens (default: 250)
- **RAG_CHUNK_OVERLAP**: Overlap between chunks in tokens (default: 50)

#### Model Selection

Support for multiple SAIA models via GWDG:

**Text Generation Models:**
- `meta-llama-3.1-8b-instruct` - General purpose conversations
- `meta-llama-3.1-8b-rag` - Optimized for RAG applications
- `llama-3.1-sauerkrautlm-70b-instruct` - German-optimized model
- `llama-3.3-70b-instruct` - Latest Llama model
- `mistral-large-instruct` - High-performance instruction following
- `qwen3-32b` - General purpose
- `qwen3-235b-a22b` - Large context model

**Code Generation Models:**
- `qwen2.5-coder-32b-instruct` - Code generation and assistance
- `codestral-22b` - Code-focused conversations

**Multimodal Models (Text + Image):**
- `gemma-3-27b-it` - Text and image understanding
- `internvl2.5-8b` - Vision-language model
- `qwen-2.5-vl-72b-instruct` - Advanced vision-language

**Reasoning Models:**
- `qwq-32b` - Enhanced reasoning capabilities
- `deepseek-r1` - Advanced reasoning

**Embedding Model:**
- `e5-mistral-7b-instruct` - Document vectorization

## Bot Management

The admin panel provides comprehensive bot creation, configuration, and management capabilities.

### Bot Creation & Configuration

#### Creating New Bots
1. Navigate to **Bots** section in admin panel
2. Click **Create New Bot**
3. Configure basic settings:
   - **Bot Name**: Display name for admin identification
   - **Mention Name**: User-facing mention (e.g., @educai, @supportbot)
   - **Default Model**: AI model for text generation
   - **System Prompt**: Bot personality and behavior instructions

#### Bot Settings Management
Each bot has comprehensive configuration options organized in tabs:

**General Settings**:
- Bot name and mention configuration
- AI model selection from available SAIA models
- Custom system prompt for personality and behavior

**Onboarding Configuration**:
- Group chat welcome questions and flow
- Direct message onboarding sequence
- Completion messages and interaction modes

**RAG Settings**:
- Embedding model selection
- Retrieval parameters (top-K results, chunk size, overlap)
- Document processing configuration

### Bot-Specific Document Management

#### Document Upload Process
1. Select target bot from dropdown
2. Upload documents (PDF, DOC, DOCX, TXT, MD)
3. System processes documents with bot-specific settings
4. Embeddings generated using bot's configured embedding model
5. Documents become searchable only by that specific bot

#### Document Isolation
- Each bot maintains completely separate document collections
- No cross-bot document access or contamination
- Independent embedding generation and storage
- Bot-specific duplicate detection

### Room-Bot Assignment

#### Automatic Assignment
- **New Rooms**: First bot mention automatically assigns bot to room
- **Existing Rooms**: Room responds only to assigned bot's mentions
- **Persistent Association**: Room-bot relationship maintained across sessions

#### Manual Reset
Users can reset room-bot assignment:
```
((RESET))
```
This command:
- Clears room configuration and bot assignment
- Deletes conversation history
- Allows fresh bot selection on next interaction

## Admin Panel

The admin panel provides a comprehensive interface for managing the multi-bot AI system.

### Dashboard

- **Multi-Bot Statistics**: Individual bot metrics and system overview
- **Health Monitoring**: API connectivity, database status, per-bot performance
- **Recent Activity**: Latest conversations and document uploads across all bots
- **Quick Actions**: Direct access to bot management and configuration tasks

### Document Management

#### Bot-Specific Document Management:
- **Bot Selection**: Choose target bot from dropdown for document upload
- **Isolated Collections**: Each bot maintains separate document libraries
- **File Upload**: Drag-and-drop or click to upload with async processing
- **Supported Formats**: PDF, DOC, DOCX, TXT, MD
- **Bot-Specific Duplicates**: Prevents uploading identical files per bot (same document can exist for different bots)
- **Processing Status**: Real-time embedding generation progress with cancellation
- **Document Preview**: View content and metadata with bot association
- **Bulk Operations**: Delete multiple documents with embedding cleanup

#### Upload Process:
1. **Bot Selection**: Choose target bot for document association
2. **File Validation**: Files validated and checked for bot-specific duplicates  
3. **Content Extraction**: Text content extracted and preprocessed
4. **Bot-Specific Chunking**: Documents split using bot's configured chunk size/overlap
5. **Embedding Generation**: Vectorization using bot's specified embedding model
6. **Storage**: Embeddings stored with bot association for isolated search
7. **Availability**: Documents become searchable only by the assigned bot

### Model Configuration

![Models Screenshot](docs/images/models.png)

#### Features:
- **Real-time Model Fetching**: Retrieves available models from OpenAI API
- **Model Comparison**: Display capabilities, pricing, and limits
- **Selection Interface**: Easy switching between models
- **Custom Models**: Support for fine-tuned and custom models
- **Performance Metrics**: Response time and accuracy tracking

### System Prompt Editor

![System Prompt Screenshot](docs/images/system-prompt.png)

#### Capabilities:
- **Rich Text Editor**: Syntax highlighting and formatting
- **Template Variables**: Dynamic content insertion
- **Preview Mode**: Test prompt behavior
- **Version History**: Track changes and rollback
- **Validation**: Ensure prompt meets requirements

#### Default System Prompt:
```
You are EDUC AI, an intelligent assistant for educational institutions. 
You help users by answering questions based on uploaded documents and your knowledge.

Guidelines:
- Always be helpful, accurate, and professional
- Reference source documents when providing information
- If you're unsure, say so rather than guessing
- Keep responses concise but complete
- Encourage learning and exploration
```

### Onboarding Configuration

![Onboarding Screenshot](docs/images/onboarding.png)

The bot features an intelligent onboarding system that guides users through initial setup and determines conversation preferences.

#### Bot Mention Configuration
- **Mention Name**: Configure what users must type to trigger the bot (default: `@educai`)
- **Spam Prevention**: Bot only responds when explicitly mentioned, ignoring other messages
- **Flexible Positioning**: Mention can appear anywhere in the message
- **Case Insensitive**: Works with any capitalization

#### Onboarding Flow
1. **Room Type Detection**: Automatically detects if conversation is group chat or direct message
2. **Mention Requirement**: Users must include the configured bot mention in their messages
3. **Custom Questions**: Configurable question sequence specific to room type
4. **State Management**: Tracks onboarding progress per conversation room

#### Group Chat Settings:
- **Welcome Message**: First message when bot joins a room
- **Initial Questions**: Suggested conversation starters
- **Auto-activation**: Automatic onboarding triggers
- **Mention Mode**: Configure `@educai` mention requirement

#### Direct Message Settings:
- **Personal Greeting**: Customized welcome for 1-on-1 chats
- **Help Commands**: Available bot commands
- **Context Setting**: Establish conversation context
- **Always Respond**: Option to respond without requiring mentions

#### Onboarding Question Examples

**Default Group Chat Questions:**
```json
[
  "What topics should I help you with in this group?",
  "Are there any specific documents or areas of knowledge you'd like me to focus on?",
  "How formal should my responses be in this group setting?"
]
```

**Default Direct Message Questions:**
```json
[
  "What would you like to learn about today?",
  "Are you looking for help with any specific subject or task?",
  "Do you prefer detailed explanations or concise answers?"
]
```

#### Configuration via Admin Panel
- **Bot Mention**: Set the exact mention name users must use (e.g., `@educai`, `@assistant`, `@help`)
- **Question Sequences**: Edit onboarding questions for each room type
- **Spam Control**: Bot automatically ignores messages without the configured mention
- **Real-time Preview**: See exactly how mentions work with examples

### RAG Configuration

![RAG Settings Screenshot](docs/images/rag-settings.png)

#### Embedding Settings:
- **Model Selection**: Choose embedding model (e5-mistral-7b-instruct)
- **Dimension Configuration**: Vector dimension settings
- **API Endpoint**: Custom embedding service URLs

#### Retrieval Settings:
- **Top-K Results**: Number of relevant chunks to retrieve
- **Similarity Threshold**: Minimum relevance score
- **Context Window**: Maximum context size for responses

#### Processing Settings:
- **Chunk Size**: Token limit per document chunk
- **Chunk Overlap**: Overlap between consecutive chunks
- **Batch Processing**: Parallel processing settings

## Architecture

### System Overview

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Nextcloud     │    │   EDUC AI       │    │   SAIA API      │
│     Talk        │◄──►│   TalkBot       │◄──►│   (GWDG)        │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                               │
                               ▼
                       ┌─────────────────┐
                       │  PostgreSQL     │
                       │  + pgvector     │
                       └─────────────────┘
```

### Core Components

#### 1. Webhook Handler (`connect.php`)
- Receives Nextcloud Talk events
- Validates webhook signatures
- Routes messages to appropriate handlers
- Manages conversation state

#### 2. Admin Panel (`admin/`)
- Web-based management interface
- User authentication and session management
- CRUD operations for system configuration
- File upload and processing

#### 3. Services Layer (`src/Services/`)

##### ApiClient
- SAIA API integration (OpenAI-compatible)
- Model management and selection
- Response parsing and error handling
- Rate limiting and retry logic

##### VectorStore
- PostgreSQL + pgvector integration
- Vector similarity search
- Embedding storage and retrieval
- Index optimization

##### EmbeddingService
- Document processing and chunking
- Vector embedding generation
- Batch processing capabilities
- Error handling and retry logic

##### OnboardingManager
- User state management
- Welcome message delivery
- Question flow orchestration
- Room-specific configuration

#### 4. Helper Classes (`src/Helpers/`)

##### Logger
- Structured logging with levels
- File-based log storage
- Performance monitoring
- Error tracking

##### Session
- Secure session management
- CSRF protection
- User authentication
- State persistence

##### Csrf
- CSRF token generation and validation
- Form protection
- AJAX request security

### Data Flow

#### 1. Message Processing
```
Nextcloud Talk → Webhook → Signature Validation → Message Parsing → 
AI Processing → RAG Search → Response Generation → Nextcloud Talk
```

#### 2. Document Upload
```
Admin Upload → File Validation → Content Extraction → 
Text Chunking → Embedding Generation → Vector Storage
```

#### 3. RAG Query
```
User Question → Vector Search → Relevant Chunks → 
Context Assembly → AI Generation → Cited Response
```

## API Integration

### Nextcloud Talk Webhook

#### Webhook Security

The webhook endpoint validates incoming requests using HMAC signature verification:

```php
// Extract signature and random from headers
$signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
$random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';

// Generate HMAC using the random value and payload
$generatedDigest = hash_hmac('sha256', $random . $inputContent, $secret);

// Verify signature matches
if (!hash_equals($generatedDigest, strtolower($signature))) {
    http_response_code(401);
    exit;
}
```

#### Request Format
```json
{
    "type": "Create",
    "actor": {
        "type": "Person",
        "id": "users/admin",
        "name": "admin"
    },
    "object": {
        "type": "Note",
        "id": "182",
        "name": "message",
        "content": "{\"message\":\"tell me about EDUC @educai\",\"parameters\":[]}",
        "mediaType": "text/markdown"
    },
    "target": {
        "type": "Collection",
        "id": "7fxkpsy6",
        "name": "Room Name"
    }
}
```

#### Response Format
```json
{
    "message": "Your AI-generated response here",
    "referenceId": "unique-reference-id",
    "replyTo": 182
}
```

#### Response Headers
```php
'Content-Type: application/json',
'OCS-APIRequest: true',
'X-Nextcloud-Talk-Bot-Random: ' . $random,
'X-Nextcloud-Talk-Bot-Signature: ' . $hash
```

### SAIA API Integration

The bot uses GWDG's SAIA (Scalable Artificial Intelligence Accelerator) service, which is compatible with the OpenAI API standard.

#### Chat Completion
```php
$response = $apiClient->createChatCompletion([
    'model' => 'meta-llama-3.1-8b-instruct',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMessage]
    ],
    'max_tokens' => 500,
    'temperature' => 0.7
]);
```

#### Embedding Generation
```php
$embedding = $embeddingService->generateEmbedding($text, [
    'model' => 'e5-mistral-7b-instruct',
    'normalize' => true
]);
```

#### API Key Setup
To get access to SAIA API:

1. Visit the [KISSKI LLM Service page](https://kisski.gwdg.de/en/leistungen/2-02-llm-service)
2. Click "Book" and fill out the form with your credentials
3. Use the same email address as your AcademicCloud account
4. Once approved, you'll receive your API key

#### Direct API Usage Examples

**curl Example:**
```bash
curl -i -X POST \
  --url https://chat-ai.academiccloud.de/v1/chat/completions \
  --header 'Authorization: Bearer <your_api_key>' \
  --header 'Content-Type: application/json' \
  --data '{
    "model": "meta-llama-3.1-8b-instruct",
    "messages": [
      {"role": "system", "content": "You are a helpful assistant"},
      {"role": "user", "content": "Hello!"}
    ],
    "temperature": 0.7
  }'
```

**Python Example:**
```python
from openai import OpenAI

client = OpenAI(
    api_key='your_api_key',
    base_url='https://chat-ai.academiccloud.de/v1'
)

response = client.chat.completions.create(
    model='meta-llama-3.1-8b-instruct',
    messages=[
        {'role': 'system', 'content': 'You are a helpful assistant'},
        {'role': 'user', 'content': 'Hello!'}
    ]
)
```

## Database Schema

### Core Tables

#### bot_admin
```sql
CREATE TABLE bot_admin (
    id SERIAL PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### bot_settings
```sql
CREATE TABLE bot_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### bot_docs
```sql
CREATE TABLE bot_docs (
    id SERIAL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_hash VARCHAR(64) UNIQUE NOT NULL,
    content TEXT NOT NULL,
    file_size BIGINT NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed BOOLEAN DEFAULT FALSE
);
```

#### bot_embeddings
```sql
CREATE TABLE bot_embeddings (
    id SERIAL PRIMARY KEY,
    doc_id INTEGER REFERENCES bot_docs(id) ON DELETE CASCADE,
    chunk_text TEXT NOT NULL,
    chunk_index INTEGER NOT NULL,
    embedding vector(1024),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### bot_conversations
```sql
CREATE TABLE bot_conversations (
    id SERIAL PRIMARY KEY,
    room_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    response TEXT,
    context_used TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### bot_room_config
```sql
CREATE TABLE bot_room_config (
    id SERIAL PRIMARY KEY,
    room_id VARCHAR(255) UNIQUE NOT NULL,
    room_type VARCHAR(50) NOT NULL,
    onboarding_state VARCHAR(50) DEFAULT 'not_started',
    welcome_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Indexes and Performance

```sql
-- Vector similarity search optimization
CREATE INDEX bot_embeddings_embedding_idx ON bot_embeddings 
USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);

-- Conversation lookup optimization
CREATE INDEX bot_conversations_room_user_idx ON bot_conversations (room_id, user_id);
CREATE INDEX bot_conversations_created_at_idx ON bot_conversations (created_at);

-- Document hash lookup
CREATE INDEX bot_docs_file_hash_idx ON bot_docs (file_hash);
```

## Development

### Local Development Setup

1. **Environment Setup**:
   ```bash
   # Copy environment template
   cp .env.example .env
   
   # Edit configuration
   nano .env
   ```

2. **Database Setup**:
   ```bash
   # Start PostgreSQL with Docker
   docker run -d --name postgres-dev \
     -e POSTGRES_DB=educ_ai_talkbot \
     -e POSTGRES_USER=dev \
     -e POSTGRES_PASSWORD=devpass \
     -p 5432:5432 \
     ankane/pgvector
   
   # Run migrations
   psql -h localhost -U dev -d educ_ai_talkbot < schema.sql
   ```

3. **Development Server**:
   ```bash
   # Start PHP development server
   php -S localhost:8000 -t . admin/index.php
   
   # Access admin panel
   open http://localhost:8000/admin/
   ```

### Code Structure

```
educ-ai-talkbot/
├── admin/                  # Admin panel web interface
│   ├── assets/            # CSS, JS, images
│   │   ├── layouts/       # Base layouts
│   │   ├── templates/         # Page templates
│   │   └── partials/      # Reusable components
│   └── index.php         # Admin panel entry point
├── src/                   # Core application logic
│   ├── Services/         # Business logic services
│   ├── Helpers/          # Utility classes
│   └── Models/           # Data models (if needed)
├── uploads/              # Uploaded documents
├── logs/                 # Application logs
├── tests/                # Unit and integration tests
├── connect.php           # Nextcloud webhook handler
├── educ-bootstrap.php    # Application bootstrap
├── composer.json         # PHP dependencies
├── .env                  # Environment configuration
└── README.md            # This file
```

### Testing

#### Unit Tests
```bash
# Run PHPUnit tests
vendor/bin/phpunit tests/

# Run specific test suite
vendor/bin/phpunit tests/Services/
```

#### Integration Tests
```bash
# Test webhook integration
curl -X POST http://localhost:8000/connect.php \
  -H "Content-Type: application/json" \
  -H "X-Nextcloud-Talk-Signature: sha256=..." \
  -d @test-webhook-payload.json
```

#### Admin Panel Tests
```bash
# Test admin login
curl -X POST http://localhost:8000/admin/login \
  -d "username=admin&password=yourpassword"

# Test document upload
curl -X POST http://localhost:8000/admin/documents \
  -F "document=@test-document.pdf"
```

## Deployment

### Production Deployment

#### 1. Server Preparation
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y php8.1 php8.1-fpm php8.1-pgsql php8.1-curl \
  php8.1-json php8.1-mbstring php8.1-xml nginx postgresql-14 \
  postgresql-14-pgvector composer

# Configure PHP
sudo nano /etc/php/8.1/fpm/php.ini
# Set: upload_max_filesize = 50M
#      post_max_size = 50M
#      memory_limit = 256M
```

#### 2. Database Setup
```bash
# Create database user
sudo -u postgres createuser --createdb --pwprompt educ_ai_bot

# Create database
sudo -u postgres createdb -O educ_ai_bot educ_ai_talkbot

# Enable pgvector
sudo -u postgres psql educ_ai_talkbot -c "CREATE EXTENSION vector;"
```

#### 3. Application Deployment
```bash
# Clone repository
cd /var/www
sudo git clone https://github.com/your-org/educ-ai-talkbot.git
cd educ-ai-talkbot

# Install dependencies
sudo composer install --no-dev --optimize-autoloader

# Set permissions
sudo chown -R www-data:www-data uploads/ logs/
sudo chmod -R 775 uploads/ logs/

# Configure environment
sudo cp .env.example .env
sudo nano .env
```

#### 4. Web Server Configuration

**Nginx Configuration** (`/etc/nginx/sites-available/educ-ai-talkbot`):
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/educ-ai-talkbot;
    index index.php;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # Admin panel
    location /admin/ {
        try_files $uri $uri/ /admin/index.php?$query_string;
        
        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Webhook endpoint
    location /connect.php {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~* \.(env|log|sql)$ {
        deny all;
    }
}
```

#### 5. SSL Configuration
```bash
# Install Certbot
sudo apt install -y certbot python3-certbot-nginx

# Get SSL certificate
sudo certbot --nginx -d your-domain.com

# Test auto-renewal
sudo certbot renew --dry-run
```

### Cloudron Deployment

The application is optimized for Cloudron deployment:

1. **Cloudron App Package**: Create app package with proper manifests
2. **Environment Integration**: Automatic environment variable setup
3. **Database Provisioning**: PostgreSQL with pgvector extension
4. **SSL/Domain**: Automatic SSL and domain configuration
5. **Backup Integration**: Automatic data and configuration backups

### Docker Deployment

#### Docker Compose
```yaml
version: '3.8'
services:
  app:
    build: .
    ports:
      - "8080:80"
    environment:
      - DB_HOST=postgres
      - DB_NAME=educ_ai_talkbot
      - DB_USER=postgres
      - DB_PASS=password
    volumes:
      - ./uploads:/var/www/html/uploads
      - ./logs:/var/www/html/logs
    depends_on:
      - postgres

  postgres:
    image: ankane/pgvector:latest
    environment:
      - POSTGRES_DB=educ_ai_talkbot
      - POSTGRES_USER=postgres
      - POSTGRES_PASSWORD=password
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./schema.sql:/docker-entrypoint-initdb.d/schema.sql

volumes:
  postgres_data:
```

#### Dockerfile
```dockerfile
FROM php:8.1-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql

# Enable mod_rewrite
RUN a2enmod rewrite

# Copy application
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs

# Install Composer dependencies
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

EXPOSE 80
```

## Troubleshooting

### Common Issues

#### 1. Database Connection Errors
```
Error: SQLSTATE[08006] Unable to connect to PostgreSQL
```

**Solutions:**
- Verify PostgreSQL is running: `sudo systemctl status postgresql`
- Check database credentials in `.env`
- Ensure pgvector extension is installed
- Verify firewall rules allow database connections

#### 2. SAIA API Errors
```
Error: Unauthorized (401) - Invalid API key
```

**Solutions:**
- Verify API key in `.env` file (use `AI_API_KEY`)
- Check API key permissions and quotas on GWDG portal
- Ensure API endpoint is correct: `https://chat-ai.academiccloud.de/v1`
- Test API connectivity manually with curl
- Verify your GWDG AcademicCloud account is active

#### 3. File Upload Issues
```
Error: Failed to move uploaded file
```

**Solutions:**
- Check directory permissions: `chmod 775 uploads/`
- Verify ownership: `chown www-data:www-data uploads/`
- Check PHP upload limits in `php.ini`
- Ensure sufficient disk space

#### 4. Webhook Signature Validation Failures
```
Error: Invalid webhook signature
```

**Solutions:**
- Verify webhook secret matches between systems (`BOT_TOKEN` in .env)
- Check that the same secret was used in the `occ talk:bot:install` command
- Ensure proper HTTPS configuration
- Debug signature generation process

#### 5. Bot Not Responding (HTTP 400 errors)
```
POST /connect.php HTTP/1.1" 400 507
```

**Common Causes:**
- Wrong webhook payload structure - check if message is JSON-encoded
- Missing bot mention - ensure message contains the configured mention (check admin panel)
- Incorrect mention format - verify mention name in database: `SELECT mention_name FROM bot_settings WHERE id = 1`
- Database connection issues
- Missing environment variables

**Debugging Steps:**
1. Run the debug script: `php debug_bot.php`
2. Check application logs: `tail -f logs/app.log`
3. Test webhook manually: `php test_webhook.php`
4. Verify bot registration: `sudo -u www-data php occ talk:bot:list`

#### 6. Database Schema Issues
```
SQLSTATE[42703]: Undefined column: column "setting_key" does not exist
SQLSTATE[42703]: Undefined column: column "room_type" does not exist
SQLSTATE[22P02]: Invalid text representation: invalid input syntax for type boolean
```

**Solutions:**
- The database schema was updated to use the correct table structure
- PostgreSQL requires proper boolean type handling for `is_group` and `onboarding_done` columns
- Access the admin panel once to trigger automatic schema migration
- Or manually run: `psql your_database < fix_database_schema.sql`
- Verify schema: 
  - `\d bot_settings` should show `mention_name` column
  - `\d bot_room_config` should show `is_group`, `mention_mode`, `onboarding_done` columns
- If migration fails, recreate tables using `database.sql`

#### 7. Vector Dimension Mismatch
```
SQLSTATE[22000]: Data exception: 7 ERROR: expected 1024 dimensions, not 4096
```
**Cause**: The embedding model selected in the admin panel produces vectors of a certain dimension (e.g., 4096), but the database column was created with a different dimension (e.g., 1024).

**Solution (Automatic)**:
The admin panel now includes an **automatic migration feature**. When you load any admin page, it checks the vector dimension in your `bot_embeddings` table. If it's incorrect, the system will:
1.  Automatically delete any old, incompatible embeddings.
2.  Change the `embedding` column type to the correct dimension (`vector(4096)`).

Simply **redeploy your app** with the latest code and **visit the admin panel**. The issue will be resolved automatically. You can then re-upload your documents.

### Debugging

#### Enable Debug Mode
```env
APP_DEBUG=true
LOG_LEVEL=DEBUG
```

#### Check Logs
```bash
# Application logs
tail -f logs/app.log

# PHP error logs
tail -f /var/log/php8.1-fpm.log

# Nginx access logs
tail -f /var/log/nginx/access.log
```

#### Database Debugging
```sql
-- Check embedding generation status
SELECT d.filename, COUNT(e.id) as embeddings_count, d.processed
FROM bot_docs d
LEFT JOIN bot_embeddings e ON d.id = e.doc_id
GROUP BY d.id, d.filename, d.processed;

-- Check recent conversations
SELECT room_id, user_id, message, response, created_at
FROM bot_conversations
ORDER BY created_at DESC
LIMIT 10;
```

## Security

### Security Measures

#### 1. Authentication & Authorization
- BCrypt password hashing for admin accounts
- Session-based authentication with secure cookies
- CSRF protection on all forms
- Role-based access control (future enhancement)

#### 2. Input Validation & Sanitization
- File upload validation (type, size, content)
- SQL injection prevention with prepared statements
- XSS protection through output escaping
- Input length and format validation

#### 3. Data Protection
- Webhook signature verification
- Environment variable protection
- Sensitive file access blocking
- Database connection encryption

#### 4. Infrastructure Security
- HTTPS/SSL enforcement
- Security headers (CSP, HSTS, etc.)
- Directory traversal protection
- Error message sanitization

### Security Best Practices

#### 1. Environment Configuration
```env
# Use strong, unique secrets
BOT_TOKEN=your-256-bit-secret-here              # For Nextcloud webhook validation
NEXTCLOUD_WEBHOOK_SECRET=your-webhook-secret    # Alternative webhook secret
ADMIN_PASSWORD_HASH=$2y$10$strong.bcrypt.hash.here

# SAIA API credentials
AI_API_KEY=your_saia_api_key
AI_API_ENDPOINT=https://chat-ai.academiccloud.de/v1

# Disable debug in production
APP_DEBUG=false

# Use secure database connections
DB_SSL_MODE=require
```

#### 2. File System Permissions
```bash
# Application files (read-only)
chmod 644 *.php
chmod 755 admin/ src/

# Writable directories (web server access)
chmod 775 uploads/ logs/
chown www-data:www-data uploads/ logs/

# Sensitive files (restricted access)
chmod 600 .env
chown root:root .env
```

#### 3. Web Server Security

**Apache Security Headers**:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

**Nginx Security Configuration**:
```nginx
# Hide Nginx version
server_tokens off;

# Prevent embedding in frames
add_header X-Frame-Options DENY;

# Enable XSS protection
add_header X-XSS-Protection "1; mode=block";

# Prevent MIME type sniffing
add_header X-Content-Type-Options nosniff;
```

#### 4. Database Security
```sql
-- Create restricted database user
CREATE USER educ_ai_bot WITH PASSWORD 'strong_password';
GRANT CONNECT ON DATABASE educ_ai_talkbot TO educ_ai_bot;
GRANT USAGE ON SCHEMA public TO educ_ai_bot;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO educ_ai_bot;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO educ_ai_bot;
```

### Security Monitoring

#### 1. Log Monitoring
- Monitor failed authentication attempts
- Track unusual API usage patterns
- Alert on file upload anomalies
- Watch for database connection issues

#### 2. Performance Monitoring
- Monitor response times and error rates
- Track resource usage and limits
- Set up alerts for system health metrics
- Regular security audit procedures

## Contributing

### Development Workflow

1. **Fork the Repository**
2. **Create Feature Branch**: `git checkout -b feature/new-feature`
3. **Make Changes**: Follow coding standards and best practices
4. **Write Tests**: Add unit and integration tests
5. **Update Documentation**: Keep README and code comments current
6. **Submit Pull Request**: Include description and testing notes

### Coding Standards

#### PHP Standards
- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add DocBlocks for all public methods
- Implement proper error handling
- Write unit tests for new functionality

#### Frontend Standards
- Use semantic HTML5 elements
- Follow responsive design principles
- Minimize inline styles and scripts
- Optimize images and assets
- Test across different browsers

### Testing Requirements

- **Unit Tests**: Cover all business logic
- **Integration Tests**: Test API endpoints and database interactions
- **Security Tests**: Validate input sanitization and access controls
- **Performance Tests**: Ensure acceptable response times
- **Browser Tests**: Verify admin panel functionality

### Documentation

- Update README.md for new features
- Add inline code documentation
- Create user guides for complex features
- Maintain API documentation
- Document configuration changes

---

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Support

For support and questions:

- **Documentation**: Check this README and inline documentation
- **Issues**: Submit bug reports and feature requests on GitHub
- **Community**: Join our community discussions
- **Commercial Support**: Contact us for enterprise support options

---

**EDUC AI TalkBot** - Bringing intelligent conversation to your educational environment. 