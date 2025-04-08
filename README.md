# EDUC AI TalkBot

This is a project within the EDUC ThinkLab that connects Nextcloud Talk with AI capabilities, including Retrieval Augmented Generation (RAG).

## Installation Guide

First, register the bot with Nextcloud using the OCC command. This tells Nextcloud to send a webhook to your `connect.php` script for messages in chats where the bot is active. Remember the secret token you define here.

```bash
# Replace placeholders with your actual values
cd /path/to/nextcloud/occ && \\
sudo -u www-data php occ talk:bot:install "My EDUC Bot" "YOUR_STRONG_SECRET_TOKEN" "https://yourdomain.com/path/to/connect.php" --features webhook,response
```

Check the installation:

```bash
sudo -u www-data php occ talk:bot:list
```

More OCC Commands: https://nextcloud-talk.readthedocs.io/en/latest/occ/#talkbotinstall

## Project Structure and Implementation

### Key Components

- **`connect.php`**: Entry point that receives webhooks from Nextcloud Talk.
- **`admin/`**: Contains a simple web-based admin panel to configure the bot.
- **`src/Core/`**: Core classes like `Chatbot`, `Config`, `Environment`, `ConfigRepository`.
- **`src/API/`**: `LLMClient` for interacting with the AI API.
- **`src/Database/`**: `Database` connection handler, `UserMessageRepository` (for chat history), `EmbeddingRepository` (for RAG document embeddings).
- **`src/RAG/`**: `Retriever` and `DataProcessor` for RAG functionality.
- **`ingest-data.php`**: Script to process documents and store embeddings for RAG.
- **`data/`**: (You create this) Directory to store documents for RAG ingestion.

### Workflow

1. Nextcloud Talk sends a webhook to `connect.php`.
2. The script verifies the signature using the `BOT_TOKEN`.
3. If the bot is mentioned (using the name configured in the admin panel), the message is processed.
4. The `Chatbot` class fetches configuration from the database (via `ConfigRepository`).
5. It retrieves recent chat history (`role`/`content` pairs) for the user from the database.
6. If RAG is enabled (`USE_RAG=true`), the `Retriever` finds relevant document chunks based on the user message.
7. The system prompt (from DB config) is combined with RAG context (if any) and user info.
8. The system prompt and chat history are sent to the LLM API via `LLMClient`.
9. The LLM response is received and logged to the chat history database.
10. The response is sent back to the Nextcloud Talk conversation.

## Setup and Configuration

### Requirements

- PHP 7.4 or higher (8.x recommended)
- PDO SQLite extension for PHP
- cURL extension for PHP
- Composer for dependency management
- A Nextcloud instance with the Talk app installed
- Access to an LLM API (e.g., GWDG Chat-AI, OpenAI compatible) with chat completions and optionally embeddings endpoints.

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

3. Create and configure the `.env` file (copy from `.env.example`):
```dotenv
BOT_TOKEN=YOUR_STRONG_SECRET_TOKEN     # MUST match the token used in occ talk:bot:install
NC_URL=your.nextcloud.instance.url     # Your Nextcloud domain (without https://)
ADMIN_PASSWORD=                      # Optional: Set a specific password for /admin login.
                                         # If empty, BOT_TOKEN is used as the admin password.

# Core AI Configuration
AI_API_KEY=your_llm_api_key_here
AI_API_ENDPOINT=https://your.llm.api/v1/chat/completions
DB_PATH=database/chatbot.sqlite      # Path to the SQLite database file
DEBUG=false                          # Set true for verbose debug output in chat

# RAG Configuration
USE_RAG=true                         # Set false to disable RAG
EMBEDDING_API_ENDPOINT=https://your.embedding.api/v1/embeddings # Can be same as AI_API_ENDPOINT
EMBEDDING_MODEL=e5-mistral-7b-instruct  # Embedding model name
RAG_TOP_K=5                          # How many document chunks to retrieve
```

4. **Admin Panel Setup:**
   - Navigate to `https://yourdomain.com/path/to/admin/` in your browser.
   - Log in using your `ADMIN_PASSWORD` (or `BOT_TOKEN` if `ADMIN_PASSWORD` is not set in `.env`).
   - Configure the **System Prompt**, **LLM Model** (name expected by your API endpoint), and **Bot Mention Name**.
   - The initial configuration will be loaded from `llm_config.json` on the first visit if the database is empty. **You should delete `llm_config.json` after the first successful run.**

5. Set up web server permissions:
```bash
# Create the database directory if it doesn't exist
mkdir -p database
# Ensure the web server user (e.g., www-data, apache, nginx) can write to the database file and directory
touch database/chatbot.sqlite
chown -R www-data:www-data database
chmod -R u+rwX,g+rX,o+rX database # Adjust permissions as needed for your server setup
```

### Database Setup

The SQLite database (`database/chatbot.sqlite` by default) is automatically created and initialized when the application (`connect.php` or `admin/index.php`) runs for the first time. It contains tables for:

- `configuration`: Stores settings managed via the admin panel (system prompt, model, bot mention).
- `chat_history`: Stores conversation history as `role`/`content` pairs for each user.
- `embeddings`: Stores document chunk embeddings for RAG.
- `documents`: Stores metadata about ingested RAG documents.

### RAG Data Ingestion

If `USE_RAG=true`, you need to populate the RAG database.

1. Create a `data/` directory in the project root:
```bash
mkdir data
chmod 755 data
```
2. Place your knowledge base documents (.txt, .md, .json, .csv) into the `data/` directory.
3. Run the ingestion script:
```bash
php ingest-data.php [options]
```
**Common Options:**
- `--data-dir=PATH`: Specify a different data directory (default: `./data`).
- `--force`: Reprocess all documents, even if already in the database.
- `--rate-limit=N`: Limit processing to N requests per minute (useful for APIs with rate limits, e.g., `--rate-limit=10`).
- `--verbose`: Show detailed output.

**Example:** Process documents with rate limiting:
```bash
php ingest-data.php --rate-limit=10
```
This script reads documents, splits them into chunks (size based on environment variables in `.env.example` if you need to customize), generates embeddings using the `EMBEDDING_API_ENDPOINT`, and stores them in the `embeddings` table.

**Run `ingest-data.php` whenever you add or update documents in the `data/` directory.**

## Features

- Connects Nextcloud Talk to an LLM API.
- Simple web admin panel (`/admin`) for configuration (System Prompt, Model, Bot Name).
- Configuration stored in SQLite database.
- Maintains user-specific conversation history (role/content format) in SQLite.
- Optional Retrieval Augmented Generation (RAG) using a local SQLite vector store.
- RAG data ingestion script (`ingest-data.php`).
- Configurable RAG parameters (embedding model, top-k, chunking via env vars for ingest script).
- Debug mode (`DEBUG=true` in `.env`) for verbose output in chat.

## Troubleshooting

- **Permissions:** Ensure the web server user has write access to the `database/` directory and `database/chatbot.sqlite` file.
- **`.env` File:** Verify all required variables are set correctly in `.env` and the file is readable.
- **Admin Panel:** Check if you can log in to `/admin` and save the configuration. The database should be created on first access.
- **Webhook:** Ensure the webhook URL in Nextcloud points correctly to your `connect.php` and the `BOT_TOKEN` matches.
- **Logs:** Check your PHP error logs (`error_log` in PHP settings) and web server logs for detailed error messages.
- **RAG:** If RAG isn't working, check `USE_RAG=true`, ensure `EMBEDDING_API_ENDPOINT` is correct, and run `php ingest-data.php` after placing files in `data/`. Use `DEBUG=true` to see retrieval details.
- **Database:** You can inspect the `database/chatbot.sqlite` file using tools like `sqlite3` or DB Browser for SQLite.

## Contributing

Contributions are welcome. Please follow standard Git workflow (fork, branch, pull request).
