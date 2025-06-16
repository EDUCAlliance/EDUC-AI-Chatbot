# Nextcloud AI Chatbot with RAG Support

A production-ready AI-powered chatbot for Nextcloud Talk that integrates with the SAIA (Scalable AI Accelerator) API, featuring:

- 🤖 **OpenAI-compatible AI Integration** via SAIA/GWDG
- 📚 **Retrieval-Augmented Generation (RAG)** using PostgreSQL + pgvector
- 🎯 **Guided Onboarding** for optimal room configuration
- 🎨 **Beautiful Admin Panel** with Slim + Twig
- 🔧 **Production-Ready** with comprehensive logging and monitoring
- 🔐 **Enterprise Security** with HMAC webhook verification
- 📊 **Analytics Dashboard** with conversation insights

## Features

### Core Capabilities
- **Smart Conversations**: Context-aware responses using conversation history
- **Document Knowledge**: Upload documents for RAG-powered question answering
- **Flexible Deployment**: Works with both group chats and direct messages
- **Configurable Behavior**: Respond to all messages or only when mentioned
- **Multi-Model Support**: Choose from various SAIA models (Llama, Mistral, Qwen, etc.)

### Admin Features
- **Web-based Admin Panel**: Complete configuration management
- **Real-time Monitoring**: API usage statistics and performance metrics
- **Document Management**: Upload and manage RAG knowledge base
- **Conversation Analytics**: Track usage patterns and user engagement
- **System Logs**: Comprehensive logging with filterable views
- **Settings Management**: Dynamic configuration without restarts

### Technical Features
- **Vector Search**: High-performance similarity search using pgvector
- **HMAC Security**: Cryptographic webhook verification
- **Database Optimization**: Efficient queries with proper indexing
- **Error Handling**: Graceful degradation and comprehensive error logging
- **Session Management**: Secure admin authentication with CSRF protection

## Quick Start

### Prerequisites

- PHP 8.1+ with extensions: `pdo_pgsql`, `curl`, `mbstring`, `json`
- PostgreSQL 12+ with `pgvector` extension
- Nextcloud instance with Talk app
- SAIA API access ([request here](https://kisski.gwdg.de/))

### Installation

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd nextcloud-ai-chatbot
   ```

2. **Install dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure environment variables**:
   ```bash
   cp .env.example .env
   # Edit .env with your configuration
   ```

4. **Set up the database**:
   The database schema is automatically initialized on first run.

5. **Register the bot with Nextcloud**:
   ```bash
   cd /path/to/nextcloud
   sudo -u www-data php occ talk:bot:install -f webhook,response \
     "EDUC AI" \
     "your-secure-bot-token" \
     "https://your-domain.com/connect.php"
   ```

6. **Access the admin panel**:
   Visit `https://your-domain.com/apps/nextcloud-bot/admin/`
   
   First-time setup will prompt you to create an admin password.

## Configuration

### Required Environment Variables

```bash
# Bot authentication
BOT_TOKEN=your-super-secure-bot-token-here
NC_URL=your-nextcloud-domain.com

# SAIA API
AI_API_KEY=your-saia-api-key-here
AI_API_ENDPOINT=https://chat-ai.academiccloud.de/v1
```

### Optional Configuration

```bash
# Model settings
DEFAULT_MODEL=meta-llama-3.1-8b-instruct
EMBEDDING_MODEL=e5-mistral-7b-instruct
MAX_TOKENS=512
TEMPERATURE=0.7

# RAG settings
RAG_TOP_K=5

# Bot behavior
BOT_MENTION=@educai
LOG_LEVEL=INFO
```

## Architecture

```
┌─────────────────────┐    ┌─────────────────────┐    ┌─────────────────────┐
│   Nextcloud Talk   │───▶│   Webhook Handler   │───▶│    SAIA API        │
│                     │    │                     │    │                     │
│ • User Messages     │    │ • Signature Verify  │    │ • Chat Completions │
│ • Bot Registration  │    │ • Message Routing   │    │ • Embeddings       │
│ • Response Display  │    │ • Context Building  │    │ • Model Selection  │
└─────────────────────┘    └─────────────────────┘    └─────────────────────┘
                                       │
                           ┌─────────────────────┐    ┌─────────────────────┐
                           │   Vector Store     │───▶│   PostgreSQL       │
                           │                     │    │                     │
                           │ • Similarity Search │    │ • pgvector Extension│
                           │ • Document Chunks   │    │ • Conversation Log │
                           │ • RAG Context      │    │ • Bot Configuration │
                           └─────────────────────┘    └─────────────────────┘
```

## Database Schema

The system uses PostgreSQL with the following key tables:

- `bot_settings` - Global bot configuration
- `bot_room_config` - Room-specific settings and onboarding state
- `bot_conversations` - Complete conversation history
- `bot_docs` - Document metadata for RAG
- `bot_embeddings` - Vector embeddings with pgvector
- `bot_logs` - System and application logs
- `bot_api_usage` - API usage analytics

## RAG (Retrieval-Augmented Generation)

The bot supports document-based question answering through:

1. **Document Upload**: Admin panel supports various file formats
2. **Text Extraction**: Automatic content extraction and preprocessing
3. **Chunking**: Smart text segmentation for optimal embedding
4. **Vectorization**: Generate embeddings using SAIA's embedding models
5. **Similarity Search**: Fast vector search using pgvector cosine similarity
6. **Context Integration**: Relevant documents are added to AI context

### Supported File Types
- PDF documents
- Text files (.txt, .md)
- Microsoft Word (.docx)
- Rich Text Format (.rtf)

## Onboarding Flow

New rooms go through a guided setup process:

1. **Room Type Detection**: Group chat vs. direct message
2. **Response Mode**: Always respond vs. mention-only (groups)
3. **Custom Questions**: Configurable context gathering
4. **Welcome Message**: Personalized introduction with capabilities

## Security Features

- **HMAC Webhook Verification**: Cryptographic signature validation
- **CSRF Protection**: Admin panel protected against cross-site attacks
- **SQL Injection Prevention**: All queries use prepared statements
- **Input Sanitization**: Comprehensive input validation and sanitization
- **Session Security**: Secure session management with proper timeouts
- **Access Control**: Role-based access to admin functionality

## Monitoring & Analytics

### Admin Dashboard
- Conversation statistics and trends
- API usage and performance metrics
- Document and embedding analytics
- Recent activity monitoring
- System health indicators

### Logging System
- Multi-level logging (DEBUG, INFO, WARNING, ERROR, CRITICAL)
- Database and file-based logging
- Automatic log rotation and cleanup
- Searchable and filterable log interface

### API Monitoring
- Request/response tracking
- Token usage analytics
- Error rate monitoring
- Performance metrics

## Development

### Project Structure
```
/
├── connect.php                 # Webhook entry point
├── composer.json              # Dependencies
├── apps/nextcloud-bot/
│   ├── src/                   # Core application code
│   │   ├── Services/          # Business logic services
│   │   ├── Models/            # Data models
│   │   ├── Helpers/           # Utility classes
│   │   ├── bootstrap.php      # Application initialization
│   │   └── schema.sql         # Database schema
│   ├── admin/                 # Admin panel
│   │   ├── index.php          # Slim application
│   │   ├── templates/         # Twig templates
│   │   └── assets/            # CSS/JS assets
│   ├── uploads/               # Document uploads
│   ├── logs/                  # Application logs
│   └── tests/                 # Unit tests
```

### Testing
```bash
# Run unit tests
composer test

# Run static analysis
composer analyse
```

### Contributing

1. Follow PSR-12 coding standards
2. Write comprehensive tests for new features
3. Update documentation for API changes
4. Use semantic commit messages

## Deployment

### Cloudron Deployment

This project is designed for [Cloudron](https://cloudron.io/) deployment:

1. The PostgreSQL database with pgvector is automatically configured
2. Environment variables are managed through the platform
3. File uploads and logs are properly isolated
4. SSL/TLS is handled by the platform

### Manual Deployment

For non-Cloudron deployments:

1. Set up PostgreSQL with pgvector extension
2. Configure web server (Apache/Nginx) with proper PHP settings
3. Set up SSL/TLS certificates
4. Configure environment variables
5. Set up log rotation and monitoring

### Production Considerations

- **Resource Allocation**: Ensure adequate memory for embedding operations
- **Database Optimization**: Regular VACUUM and index maintenance
- **Log Management**: Implement log rotation and archival
- **Backup Strategy**: Regular database and file backups
- **Monitoring**: Set up alerting for API failures and resource usage

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Verify PostgreSQL credentials in environment variables
   - Ensure pgvector extension is installed and enabled

2. **Webhook Signature Verification Failures**
   - Check that BOT_TOKEN matches the token used in bot registration
   - Verify webhook URL is accessible from Nextcloud

3. **API Authentication Errors**
   - Validate SAIA API key and endpoint configuration
   - Check API quotas and rate limits

4. **Admin Panel Access Issues**
   - Clear browser cache and cookies
   - Check admin authentication in database
   - Verify session configuration

### Debug Mode

Enable debug logging:
```bash
LOG_LEVEL=DEBUG
```

Check application logs:
```bash
tail -f apps/nextcloud-bot/logs/$(date +%Y-%m-%d).log
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For issues and questions:

1. Check the [troubleshooting section](#troubleshooting)
2. Review the application logs
3. Search existing GitHub issues
4. Create a new issue with detailed information

## Acknowledgments

- [SAIA/GWDG](https://docs.hpc.gwdg.de/services/saia/) for AI API services
- [Nextcloud](https://nextcloud.com) for the collaboration platform
- [pgvector](https://github.com/pgvector/pgvector) for vector similarity search
- The open-source community for the excellent libraries and tools 