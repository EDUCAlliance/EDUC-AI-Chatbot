# EDUC AI TalkBot

This is a project within the EDUC ThinkLab that connects Nextcloud Talk with AI capabilities, including Retrieval Augmented Generation (RAG).

## Installation Guide

First of all, you have to install a bot. The term "register" would almost be more appropriate here, as you simply tell Nextcloud to send a webhook to a specific URL after each message (as soon as this bot is activated in the chat) - It is also important to remember the secret token, because the script must encrypt the message with this token in order to be able to respond back.

```bash
cd /path/to/nextcloud-occ-file && sudo -u www-data php occ talk:bot:install -f webhook,response "Name of the Bot" "XXXX-Secrect-Token-XXXX" "https://Domain-of-Nextcloud.de/connect.php"
```

If you want to check if the "installation" of the bot is correct, you can see a list of all bots with this command (you should now also be able to activate the bot in the Conversation settings of a Nextcloud Talk Chat):

```bash
sudo -u www-data php occ talk:bot:list
```

More OCC Commands can you find here: https://nextcloud-talk.readthedocs.io/en/latest/occ/#talkbotinstall

## Project Structure and Implementation

### Object-Oriented Architecture

The project uses a object-oriented approach with the following key components:

1. **Core Components**
   - `Environment`: Manages environment variables
   - `Config`: Handles configuration loading and access
   - `Chatbot`: Main class that processes messages and coordinates other components

2. **API Integration**
   - `LLMClient`: Handles communication with the AI API endpoint

3. **Database Layer**
   - `Database`: Manages SQLite database connections
   - `EmbeddingRepository`: Stores and retrieves vector embeddings

4. **RAG Implementation**
   - `Retriever`: Manages semantic retrieval of relevant information
   - `DataProcessor`: Processes data for RAG functionality

### Webhook Flow

1. Nextcloud Talk sends a webhook to `connect.php`
2. The script verifies the signature using the shared secret
3. If the bot is mentioned, the message is processed
4. The script uses the AI API to generate a response
5. A reply is sent back to the conversation in Nextcloud Talk

### RAG Implementation Details

The Retrieval Augmented Generation implementation:

- Uses SQLite to store document embeddings
- Generates embeddings for user queries to find relevant context
- Augments the LLM prompt with retrieved information
- Configurable parameters such as top-k results and embedding model
- Supports separate embedding endpoints if needed

## Setup and Configuration

### Requirements

- PHP 7.4 or higher
- SQLite extension for PHP
- cURL extension for PHP
- Composer for dependency management
- A Nextcloud instance with Talk app installed
- Access to an AI API (GWDG AI API or compatible)

### Installation Steps

1. Clone the repository:
```bash
git clone https://github.com/your-repo/educ-ai-talkbot.git
cd educ-ai-talkbot
```

2. Install dependencies:
```bash
composer install
```

3. Create the `.env` file (copy from `.env.example` or create manually):
```dotenv
BOT_TOKEN=XXXXX                                            # The token from Nextcloud bot registration
AI_API_KEY=XXXXXXXXX                                       # Your AI API key
NC_URL=domain-of-nextcloud-server.de                       # Your Nextcloud domain (without https://)
AI_API_ENDPOINT=https://chat-ai.academiccloud.de/v1/chat/completions  # LLM API endpoint
# AI_CONFIG_FILE=llm_config.json                           # Optional: Path to legacy config file (see Database Setup)
DB_PATH=/app/code/public/database/chatbot.sqlite           # Full path to SQLite database
ADMIN_PASSWORD=your_secure_admin_password                 # Password for the admin panel login

# RAG Configuration (if USE_RAG is effectively true)
USE_RAG=true                                               # Enable/disable RAG (functionality check, not direct env var)
RAG_TOP_K=5                                                # Number of documents to retrieve
EMBEDDING_MODEL=e5-mistral-7b-instruct                     # Model for embeddings
EMBEDDING_API_ENDPOINT=https://chat-ai.academiccloud.de/v1/embeddings # Embedding API endpoint

# The following RAG variables are used by ingest-data.php script
# RAG_CHUNK_SIZE=500                                         # Size of text chunks
# RAG_CHUNK_OVERLAP=100                                      # Overlap between chunks
```
*   **Important:** Set a strong `ADMIN_PASSWORD`. If left empty, the `BOT_TOKEN` will be used as the admin password, which is less secure.

4.  **(Optional) Create Initial LLM Configuration (`llm_config.json`)**:
    If the `settings` table in your database is empty *and* you provide this file, the bot will use it to populate the initial System Prompt, Model, and Bot Mention Name settings on its first run. This is useful for initial setup but not required afterwards as settings are managed via the admin panel.
    ```json
    {
      "model": "meta-llama-3.1-8b-instruct",
      "botMention": "AI Assistant",
      "systemPrompt": "You are a helpful AI assistant for the EDUC project.",
      "welcomeMessage": "Welcome to the EDUC AI TalkBot! I'm here to help you with your questions."
    }
    ```
    *   Place this file in the location specified by `AI_CONFIG_FILE` in your `.env` (or `/app/code/public/llm_config.json` if `AI_CONFIG_FILE` is not set).
    *   The `llm_config.json` can also optionally include a `welcomeMessage` field.
    *   **You can safely remove `llm_config.json` after the settings appear in the admin panel.**

5.  **Set Up Web Server Permissions**: Ensure your web server user (e.g., `www-data`, `apache`) has write permissions for the directory containing your SQLite database.
    ```bash
    # Example assuming DB_PATH is /app/code/database/chatbot.sqlite
    DB_DIR=$(dirname $(grep DB_PATH .env | cut -d '=' -f2))
    DB_FILE=$(basename $(grep DB_PATH .env | cut -d '=' -f2))
    mkdir -p "$DB_DIR"
    touch "$DB_DIR/$DB_FILE"
    chown -R www-data:www-data "$DB_DIR" # Use your web server user/group
    chmod -R u+rwX,g+rX,o-rwx "$DB_DIR"  # Adjust permissions as needed
    ```

6.  **Access the Admin Panel**:
    *   Navigate to `https://yourdomain.com/path/to/admin/` (where `admin/` is located in your web root).
    *   Log in using the `ADMIN_PASSWORD` set in your `.env` file (or `BOT_TOKEN` if `ADMIN_PASSWORD` was left empty).
    *   Configure the **System Prompt**, **AI Model**, **Bot Mention Name**, **Welcome Message**, and **Debug Mode**. These settings are saved directly to the database.

### Database Setup

The SQLite database (path defined by `DB_PATH` in `.env`) will be automatically created and initialized on the first run of `connect.php` or access to the admin panel.

Key Tables:
-   `settings`: Stores configuration managed via the admin panel (System Prompt, Model, Bot Mention, Debug Mode, Welcome Message). Initial values might be populated from `llm_config.json` if the table is empty and the file exists (see Installation Step 4).
-   `user_messages`: Stores chat history (role, message, timestamp).
-   `embeddings`: Stores document embeddings for RAG.
-   `documents`: Stores metadata about ingested documents.

### RAG Data Ingestion

To use the RAG functionality, you need to ingest data into the system. This process transforms your documents into vector embeddings that can be retrieved when users ask questions.

#### Document Storage

1. Create a `/data` directory in your project root:
```bash
mkdir -p data
chmod 755 data
```

2. Place your knowledge base documents in this directory. The system supports:
   - Plain text files (.txt)
   - Markdown files (.md)
   - JSON files (.json)
   - CSV files (.csv)

#### Document Processing

The system includes a dedicated data ingestion script (`ingest-data.php`) that processes your documents and creates embeddings for the RAG system.

```bash
php ingest-data.php [options]
```

Options:
- `--data-dir=PATH` - Specify custom data directory (default: ./data)
- `--force` - Force reprocess all documents
- `--verbose` - Show detailed output
- `--help` - Display help message

Example:
```bash
# Process all documents in the default data directory (with the embeddings api rate limt)
php ingest-data.php  --rate-limit=10

# Process documents in a custom directory with verbose output
php ingest-data.php --data-dir=./custom-data --verbose

# Force reprocessing of all documents
php ingest-data.php --force
```

This script will:
1. Scan the data directory for supported files
2. Generate embeddings for each document
3. Store the embeddings in the SQLite database
4. Report processing status for each file

#### Chunking Strategy

The system automatically splits documents into semantic chunks for better retrieval:

- Text chunks are configured by the RAG_CHUNK_SIZE environment variable (default: 500 tokens)
- Chunks have overlap to prevent information loss at boundaries (configurable via RAG_CHUNK_OVERLAP)
- The system processes documents in batches for efficiency (configurable via RAG_BATCH_SIZE)

#### Updating the Knowledge Base

Whenever you add new documents or update existing ones, run the ingestion script again. The system will:
- Add embeddings for new documents
- Update embeddings for modified documents (when using the --force option)

The RAG system doesn't automatically ingest documents when the bot receives a webhook call. You must manually run the ingestion process whenever your knowledge base changes.

## Features

The Talkbot has the following features:

- **Admin Panel**: A simple web interface (`/admin`) for configuring core bot settings:
    - System Prompt
    - AI Model name
    - Bot Mention Name (how users trigger the bot in chat)
    - Debug Mode toggle (overrides `.env` setting, adds debug info to responses)
    - Welcome Message (sent if the last chat message is older than 24 hours)
- Connection to LLM APIs (e.g., GWDG Chat-AI).
- Persistent user history tracking via SQLite
- Retrieval Augmented Generation for context-aware responses
- Configurable AI model and parameters via the admin panel.
- Support for different embedding models
- RAG data ingestion script (`ingest-data.php`).
- Debug mode (controllable via admin panel) for troubleshooting.

## Troubleshooting

- Check the PHP error logs for detailed error messages
- Ensure all required permissions are set for the database directory
- Verify that your API keys and endpoints are correct
- Ensure the web server user has write access to the directory specified in `DB_PATH`.
- Verify all required variables are set correctly in `.env`. Check `ADMIN_PASSWORD` if login fails.
- Make sure the bot token matches the one registered in Nextcloud
- **Admin Panel Access**: Check your web server configuration if you cannot access `/admin`. Ensure PHP is running. Verify the `ADMIN_PASSWORD` (or `BOT_TOKEN`).
- **Settings Not Saving**: Check web server logs and PHP error logs. Ensure database permissions are correct.
- If RAG functionality is not working, ensure `EMBEDDING_API_ENDPOINT` is correct, run `php ingest-data.php` after placing files in `data/`, and check the `embeddings` table. Use the Debug Mode toggle in the admin panel to see retrieval details.
- Check PHP error logs (`error_log`) and web server logs for detailed error messages.

## Contributing

Contributions to the EDUC AI TalkBot are welcome. Please follow the standard guidelines for contributing.
