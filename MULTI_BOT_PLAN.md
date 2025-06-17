# Multi-Bot Architecture Implementation Plan ✅ COMPLETED

This document outlined the steps required to refactor the EDUC AI TalkBot from a single-bot system to a multi-bot architecture. **The implementation is now complete and fully functional.**

## ✅ Implementation Status: COMPLETE

The multi-bot architecture has been successfully implemented with all planned features:

## 1. Objective ✅ ACHIEVED

The goal was to enable the creation and management of multiple, independent chatbots within the same application instance. Each bot is now uniquely identified by its `@mention_name` and has distinct configuration for:

-   ✅ **System Prompt (Personality)** - Each bot has its own system prompt and behavior
-   ✅ **Knowledge Base (Uploaded documents for RAG)** - Bot-specific document libraries with scoped embeddings  
-   ✅ **Onboarding Questions** - Separate onboarding flows for group chats and direct messages per bot
-   ✅ **AI Models** - Independent chat and embedding model selection for each bot
-   ✅ **RAG Settings** - Bot-specific retrieval settings (top_k, chunk_size, chunk_overlap)

Different bots now serve different purposes or user groups from the same Nextcloud integration.

## 2. Phase 1: Database Schema Changes & Automatic Migration ✅ COMPLETED

The current singleton `bot_settings` table has been successfully replaced by a new `bots` table. Other tables have been updated to link to specific bots.

### 2.1. New Table Schema

A new table `bots` will be created to store the configuration for each bot.

**`bots` table:**
```sql
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
```

### 2.2. Modifications to Existing Tables

-   **`bot_docs`**: Add a `bot_id` foreign key to associate each document with a specific bot.
    ```sql
    ALTER TABLE bot_docs ADD COLUMN IF NOT EXISTS bot_id INTEGER;
    -- A foreign key constraint will be added after data migration.
    ```
-   **`bot_room_config`**: Add a `bot_id` foreign key to link a room's configuration to the bot that was onboarded.
    ```sql
    ALTER TABLE bot_room_config ADD COLUMN IF NOT EXISTS bot_id INTEGER;
    ```
-   **`bot_conversations`**: Add a `bot_id` foreign key to scope conversation history to a specific bot.
    ```sql
    ALTER TABLE bot_conversations ADD COLUMN IF NOT EXISTS bot_id INTEGER;
    ```

### 2.3. Automatic Migration Strategy ✅ IMPLEMENTED

An automatic migration script has been implemented in `admin/index.php`. This script runs on page load and performs a one-time migration from the old schema to the new multi-bot schema.

**Migration Logic:**

1.  **Check for Migration**: The script will first check if the `bots` table exists. If it doesn't, the migration process will begin.
2.  **Create `bots` Table**: Execute the `CREATE TABLE` statement for the `bots` table.
3.  **Add Columns**: Add the new `bot_id` columns to `bot_docs`, `bot_room_config`, and `bot_conversations` using `ALTER TABLE ... ADD COLUMN IF NOT EXISTS ...`.
4.  **Migrate Data from `bot_settings`**:
    a. Read the single row of data from the `bot_settings` table.
    b. Create the first entry in the new `bots` table using this data. The `bot_name` can be a default like "Default Bot", and `mention_name` will be the existing `mention_name`.
    c. Get the `id` of this newly created bot (it will be `1`).
5.  **Update Existing Records**:
    a. Update all records in `bot_docs` to set `bot_id = 1`.
    b. Update all records in `bot_room_config` to set `bot_id = 1`.
    c. Update all records in `bot_conversations` to set `bot_id = 1`.
6.  **Add Foreign Key Constraints**: After the data is migrated, add the foreign key constraints to ensure data integrity.
    ```sql
    ALTER TABLE bot_docs ADD CONSTRAINT fk_bot_docs_bot_id FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE;
    ALTER TABLE bot_room_config ADD CONSTRAINT fk_bot_room_config_bot_id FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE;
    ALTER TABLE bot_conversations ADD CONSTRAINT fk_bot_conversations_bot_id FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE;
    ```
7.  **Deprecate `bot_settings`**: The `bot_settings` table will no longer be used for bot-specific configurations. It can be removed in a future cleanup phase.

## 3. Phase 2: Admin Panel Overhaul

The admin panel will be redesigned to manage multiple bots.

1.  **New Main View (`/bots`)**:
    -   Create a new route `/bots` that lists all configured bots.
    -   This view will show `bot_name`, `mention_name`, and provide actions (Edit, Delete).
    -   The sidebar navigation will be updated to link to this page as the central hub, replacing links to `Models`, `Prompt`, `Onboarding`, and `RAG Settings`.
2.  **Bot CRUD Operations**:
    -   Implement routes and views for creating, editing, and deleting bots.
    -   **Create**: A form to define `bot_name` and `mention_name`.
    -   **Edit**: A settings page for each bot.
3.  **Bot-Specific Settings**:
    -   The existing settings pages will be nested under a bot's context, e.g., `/bots/{id}/settings`.
    -   A tabbed interface on the bot's settings page will provide access to:
        -   **General**: `default_model`.
        -   **Prompt**: `system_prompt`.
        -   **Onboarding**: `onboarding_..._questions`.
        -   **RAG**: `embedding_model`, `rag_top_k`, etc.
4.  **Document Management**:
    -   The `/documents` page will be updated to be bot-specific.
    -   A dropdown menu will allow the admin to select a bot, and the table will display only the documents associated with that bot.
    -   The document upload process will now associate the uploaded file with the currently selected bot.

## 4. Phase 3: Core Logic Adaptation (`connect.php`)

The webhook handler needs to be updated to identify and use the correct bot.

1.  **Bot Detection**:
    -   On receiving a webhook, the `message` content will be scanned.
    -   The script will query the `bots` table to find a `mention_name` that is present in the message.
2.  **Room & Bot Association**:
    -   The logic will check `bot_room_config` for an existing entry for the current `room_token`.
    -   **If a config exists**: The room is already configured for a specific bot (`bot_id`). The script will only process messages that mention *that specific bot*. Mentions of other bots will be ignored.
    -   **If no config exists**: This is a new room. The *first valid bot mention* found in the message will trigger the onboarding process for that bot. A new row will be created in `bot_room_config` linking the `room_token` to the detected `bot_id`.
3.  **Contextual Operations**:
    -   Once the active bot for the request is identified, all subsequent operations (fetching system prompt, RAG context, onboarding questions, etc.) will use the configuration from that bot's record in the `bots` table.

## 5. Phase 4: Service Layer Updates

Service classes will be modified to operate within the context of a specific bot.

-   **`OnboardingManager`**: Methods like `getNextQuestion` will need the `bot` object or `bot_id` to retrieve the correct set of custom onboarding questions.
-   **`EmbeddingService` / `VectorStore`**: The `generateAndStoreEmbeddings` and `findSimilar` methods must be scoped by `bot_id` to ensure each bot only has access to its own knowledge base.
-   **`ApiClient`**: This can remain largely unchanged, but the models used for API calls will be determined by the active bot's settings.

## 6. Edge Cases & Open Questions

-   **Multiple Bot Mentions in one Message**:
    -   **Proposal**: In a new room, the first valid mention found will claim the room. In an existing room, only the configured bot's mention is respected.
-   **Switching Bots in a Room**:
    -   **Proposal**: A room is tied to one bot after onboarding. To switch bots, a user must use the `((RESET))` command, which will clear the `bot_room_config` for that room, allowing a new bot to be onboarded.
-   **No Bot Mentioned**:
    -   **Proposal**: The behavior remains the same. If a mention is required by the room's configuration, the message is ignored.

## ✅ IMPLEMENTATION COMPLETE

### What Has Been Delivered

The multi-bot architecture has been **fully implemented** and is ready for production use:

#### 🗄️ Database Layer
- ✅ New `bots` table with full configuration support
- ✅ Automatic migration from single-bot to multi-bot schema  
- ✅ Foreign key relationships with cascade delete
- ✅ Bot-specific scoping for all related data

#### 🎛️ Admin Interface  
- ✅ **Modern Bot Management UI** - Create, edit, delete, and configure multiple bots
- ✅ **Tabbed Settings Interface** - General, System Prompt, Onboarding, and RAG settings per bot
- ✅ **Bot-Specific Document Management** - Upload and manage knowledge base per bot
- ✅ **Intuitive Navigation** - Reorganized menu structure focused on multi-bot workflow
- ✅ **Smart Empty States** - Helpful onboarding guidance for new users

#### 🤖 Core Logic  
- ✅ **Bot Detection & Room Assignment** - Rooms are claimed by the first bot mentioned
- ✅ **Mention-Based Routing** - Each bot only responds to its unique @mention  
- ✅ **Bot-Specific Processing** - All AI operations use the detected bot's settings
- ✅ **Isolated Knowledge Bases** - RAG retrieval scoped by bot_id
- ✅ **Independent Onboarding** - Custom flows per bot with reuse detection

#### 🔧 Service Layer
- ✅ **Enhanced EmbeddingService** - Bot-specific embedding models and settings
- ✅ **Scoped Vector Search** - Embeddings filtered by bot ownership  
- ✅ **Multi-Bot OnboardingManager** - Bot-aware question flows
- ✅ **Background Processing** - Async document processing with progress tracking

### Key Features

1. **🎯 Unique Bot Identities** - Each bot has a distinct @mention name and personality
2. **📚 Specialized Knowledge** - Separate document libraries with bot-scoped embeddings
3. **🧠 Custom Behavior** - Independent system prompts, models, and RAG settings  
4. **🔄 Smart Room Management** - Automatic bot assignment with room claiming
5. **⚡ Seamless Migration** - Zero-downtime upgrade from single-bot to multi-bot
6. **🎨 Modern Admin UI** - Intuitive interface designed for multi-bot management

### Usage Examples

Users can now create specialized bots like:
- `@supportbot` - Customer service with FAQ knowledge
- `@teacher` - Educational assistant with course materials  
- `@codehelper` - Programming assistant with technical docs
- `@hrbot` - HR assistant with company policies

The implementation provides a comprehensive roadmap for robust multi-bot architecture. The phased approach ensured that changes were manageable and the automatic database migration was handled safely. 