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

3. Create the `.env` file:
```
BOT_TOKEN=XXXXX                                            # The token from Nextcloud bot registration
AI_API_KEY=XXXXXXXXX                                       # Your AI API key
NC_URL=domain-of-nextcloud-server.de                       # Your Nextcloud domain
AI_API_ENDPOINT=https://chat-ai.academiccloud.de/v1/chat/completions  # API endpoint
AI_CONFIG_FILE=llm_config.json                             # Path to config file
DB_PATH=/app/code/public/database/chatbot.sqlite           # Full(!!)Path to SQLite database
USE_RAG=true                                               # Enable/disable RAG
RAG_TOP_K=5                                                # Number of documents to retrieve
EMBEDDING_MODEL=e5-mistral-7b-instruct                     # Model for embeddings
RAG_CHUNK_SIZE=500                                         # Size of text chunks
RAG_CHUNK_OVERLAP=100                                      # Overlap between chunks
DEBUG=false                                                # Enable debug mode
EMBEDDING_API_ENDPOINT=https://chat-ai.academiccloud.de/v1/embeddings
```

4. Create the LLM configuration file (`llm_config.json`):
```json
{
  "model": "meta-llama-3.1-8b-instruct",
  "botMention": "AI Assistant",
  "systemPrompt": "You are a helpful AI assistant for the EDUC project.",
  "responseExamples": [
    {
      "role": "user",
      "content": "What can you help me with?"
    },
    {
      "role": "assistant",
      "content": "I can answer questions, provide information, and help with various tasks related to EDUC projects."
    }
  ]
}
```

5. Make sure your web server can write to the database directory:
```bash
mkdir -p database
touch database/chatbot.sqlite && chmod -R 755 database/chatbot.sqlite && chown -R www-data:www-data database
```

### Database Setup

The SQLite database will be automatically created on first use. The system uses several tables:

- `user_messages`: Stores chat history
- `embeddings`: Stores document embeddings for RAG
- `documents`: Stores information about ingested documents

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

- Connection to the Chat-AI API from GWDG
- Persistent user history tracking via SQLite
- Retrieval Augmented Generation for context-aware responses
- Configurable AI model and parameters
- Support for different embedding models
- Debug mode for troubleshooting

## Troubleshooting

- Check the PHP error logs for detailed error messages
- Ensure all required permissions are set for the database directory
- Verify that your API keys and endpoints are correct
- Make sure the bot token matches the one registered in Nextcloud
- If RAG functionality is not working, check if embeddings are being properly stored

## Contributing

Contributions to the EDUC AI TalkBot are welcome. Please follow the standard guidelines for contributing.
