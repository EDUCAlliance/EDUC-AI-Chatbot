# EDUC AI TalkBot Enhanced

A sophisticated AI-powered chatbot for Nextcloud Talk with RAG (Retrieval-Augmented Generation) capabilities, built for the EDUC Alliance. This enhanced version provides comprehensive document processing, vector embeddings, and intelligent conversation management.

## 🌟 Features

### Core Chatbot Features
- **Nextcloud Talk Integration**: Native webhook support for seamless chat integration
- **Multi-Model Support**: Compatible with GWDG SAIA API (Llama, Gemma, Mistral, Qwen models)
- **Context-Aware Conversations**: Maintains conversation history and context
- **Group & Individual Chat Support**: Handles both private messages and group discussions
- **Onboarding System**: Intelligent user/group onboarding with customizable questions

### RAG (Retrieval-Augmented Generation)
- **Document Processing**: Support for PDF, DOCX, TXT, MD, HTML, CSV, JSON files
- **Vector Embeddings**: PostgreSQL with pgvector extension for efficient similarity search
- **Intelligent Chunking**: Smart text segmentation with overlap for better context
- **Semantic Search**: Find relevant information using vector similarity
- **Real-time Document Processing**: Upload and process documents through admin interface

### Admin Panel
- **Modern Bootstrap UI**: Responsive, professional admin interface
- **Dynamic Model Management**: Live fetching and selection of available AI models
- **Settings Management**: Configure system prompts, onboarding questions, and bot behavior
- **RAG Management**: Upload, process, and manage documents for knowledge base
- **Real-time Monitoring**: View logs, statistics, and system performance
- **File Upload Interface**: Drag-and-drop document upload with progress tracking

### Technical Features
- **Cloudron Compatible**: Designed for Cloudron deployment environment
- **PostgreSQL Database**: Robust database with pgvector support for embeddings
- **Comprehensive Logging**: Detailed logging with security sanitization
- **Error Handling**: Production-ready error handling and recovery
- **Security Features**: CSRF protection, input validation, secure authentication
- **Environment Flexibility**: Supports both .env files and Cloudron environment variables

## 🚀 Quick Start

### Prerequisites

- PHP 8.1+
- PostgreSQL with pgvector extension
- Composer for dependency management
- Nextcloud Talk instance
- GWDG SAIA API access

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/EDUCAlliance/EDUC-AI-TalkBot-Enhanced.git
   cd EDUC-AI-TalkBot-Enhanced
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment variables**
   ```bash
   cp env.example .env
   # Edit .env with your configuration
   ```

4. **Set up the database**
   - The system automatically creates required tables on first run
   - Ensure PostgreSQL has pgvector extension enabled

5. **Configure Nextcloud webhook**
   - Point your Nextcloud Talk bot webhook to `/connect.php`
   - Set the shared secret in your environment variables

### Configuration

#### Required Environment Variables

```bash
# Database Configuration (automatically configured in Cloudron)
DATABASE_URL=postgres://user:password@host:port/database

# AI API Configuration (GWDG SAIA)
AI_API_KEY=your_gwdg_api_key
AI_API_ENDPOINT=https://chat.hpc.gwdg.de/v1
EMBEDDING_API_ENDPOINT=https://chat.hpc.gwdg.de/v1
MODELS_API_ENDPOINT=https://chat.hpc.gwdg.de/v1

# Nextcloud Integration
BOT_TOKEN=your_nextcloud_bot_token
NC_URL=your-nextcloud-domain.com

# Admin Panel
ADMIN_USERNAME=admin
ADMIN_PASSWORD=secure_password

# RAG Configuration
USE_RAG=true
RAG_TOP_K=5

# Logging
LOG_LEVEL=INFO
```

#### Optional Environment Variables

```bash
# Application Settings
APP_NAME=EDUC-AI-Chatbot
DEBUG_MODE=false
ALLOWED_DOMAINS=your-domain.com

# Rate Limiting
RATE_LIMIT_REQUESTS=100
RATE_LIMIT_WINDOW=3600
```

## 🏗️ Architecture

### Directory Structure

```
educ-ai-talkbot/
├── admin/                  # Admin panel interface
│   ├── index.php          # Main admin dashboard
│   ├── login.php          # Admin authentication
│   └── logout.php         # Session management
├── src/                   # Core application code
│   ├── API/              # API integrations
│   │   └── LLMClient.php # GWDG SAIA API client
│   ├── Core/             # Core application logic
│   │   ├── Chatbot.php   # Main chatbot class
│   │   └── Environment.php # Environment management
│   ├── Database/         # Database layer
│   │   ├── Database.php  # PostgreSQL connection & schema
│   │   └── EmbeddingRepository.php # Vector embeddings
│   ├── RAG/              # RAG system components
│   │   ├── DocumentProcessor.php # Document processing
│   │   └── Retriever.php # Semantic search
│   └── Utils/            # Utility classes
│       ├── Logger.php    # Logging system
│       └── Security.php  # Security utilities
├── connect.php           # Webhook endpoint
├── composer.json         # Dependencies
├── env.example          # Environment template
└── auto-include.php     # Cloudron compatibility
```

### Database Schema

The system uses PostgreSQL with the following key tables:

- **`educat_settings`**: Application configuration
- **`educat_messages`**: Conversation history
- **`educat_chat_configs`**: Chat-specific settings and onboarding state
- **`educat_documents`**: Uploaded document metadata
- **`educat_embeddings`**: Vector embeddings with pgvector support
- **`educat_models`**: Cached model information

### RAG Pipeline

1. **Document Upload**: Files uploaded through admin interface
2. **Content Extraction**: Text extraction from various file formats
3. **Intelligent Chunking**: Content split into overlapping chunks
4. **Embedding Generation**: Vector embeddings created using GWDG API
5. **Storage**: Embeddings stored in PostgreSQL with pgvector
6. **Retrieval**: Semantic search during chat interactions
7. **Augmentation**: Relevant content added to AI prompts

## 🔧 Usage

### Admin Panel

Access the admin panel at `/admin/` with your configured credentials.

#### Dashboard
- View system statistics (messages, chats, documents, embeddings)
- Monitor system health and performance

#### Settings Management
- Configure AI model selection
- Set system prompts and bot behavior
- Manage onboarding questions for users and groups
- Enable/disable debug mode

#### RAG Management
- Upload documents (PDF, DOCX, TXT, MD, HTML, CSV, JSON)
- Process pending documents into embeddings
- View document statistics and status
- Clear RAG data if needed

#### Model Management
- View available GWDG SAIA models
- Test API connectivity
- See model capabilities and details

#### System Monitoring
- View real-time logs with filtering
- Monitor system performance
- Access troubleshooting information

### Chatbot Interaction

#### Individual Chats
1. User starts conversation with bot
2. Bot presents onboarding questions (if configured)
3. Bot learns user preferences and context
4. Ongoing conversations use RAG and conversation history

#### Group Chats
1. Bot monitors for mentions or direct messages
2. Group-specific onboarding process
3. Context-aware responses considering group dynamics
4. RAG-enhanced responses with relevant documentation

### RAG Features

#### Document Processing
- Automatic text extraction from supported formats
- Intelligent chunking with configurable overlap
- Batch processing with rate limiting
- Error handling and retry mechanisms

#### Semantic Search
- Vector similarity search using pgvector
- Configurable result ranking and filtering
- Context-aware retrieval based on conversation
- Fallback to keyword search when needed

## 🔒 Security

### Authentication & Authorization
- Secure admin panel with session management
- CSRF protection on all forms
- Input validation and sanitization
- Rate limiting on API endpoints

### Data Protection
- Sensitive data sanitization in logs
- Secure webhook signature verification
- Environment variable protection
- SQL injection prevention

### Privacy Considerations
- Conversation data isolation by chat/user
- Configurable data retention policies
- Optional debug mode for development
- Audit logging for admin actions

## 🌩️ Cloudron Deployment

This application is designed for Cloudron deployment and follows Cloudron best practices:

### Automatic Features
- Database connection auto-configuration
- Environment variable integration
- Table prefix isolation for multi-tenancy
- File storage in appropriate directories
- Logging to Cloudron-compatible locations

### Manual Setup in Cloudron

1. **Deploy to Cloudron environment**
2. **Configure custom environment variables through admin panel**
3. **Set up Nextcloud Talk webhook pointing to your Cloudron app**
4. **Access admin panel to complete configuration**

### Environment Integration

The system automatically detects Cloudron environment and:
- Uses `CLOUDRON_POSTGRESQL_URL` for database connection
- Respects `CLOUDRON_ENVIRONMENT` for production/development modes
- Integrates with Cloudron's file storage patterns
- Follows Cloudron security and isolation practices

## 🔍 Troubleshooting

### Common Issues

#### Database Connection
- Verify PostgreSQL credentials and connection
- Ensure pgvector extension is installed
- Check table prefix configuration

#### API Integration
- Verify GWDG SAIA API credentials
- Test network connectivity to API endpoints
- Check API rate limits and quotas

#### RAG System
- Ensure pgvector extension is available
- Verify document upload permissions
- Check embedding generation logs

#### Admin Panel
- Verify admin credentials
- Check session configuration
- Review CSRF token handling

### Debug Mode

Enable debug mode for detailed logging:
```bash
DEBUG_MODE=true
LOG_LEVEL=DEBUG
```

### Log Analysis

Access logs through the admin panel or directly:
- Application logs: `/logs/YYYY-MM-DD.log`
- Error logs: PHP error log
- Webhook logs: Detailed request/response logging

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes with proper testing
4. Submit a pull request with detailed description

### Development Guidelines

- Follow PSR-4 autoloading standards
- Use proper error handling and logging
- Implement comprehensive input validation
- Write secure, production-ready code
- Document new features and APIs

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🙏 Acknowledgments

- **EDUC Alliance** for project requirements and support
- **GWDG** for providing the SAIA API infrastructure
- **Nextcloud** for the Talk integration platform
- **PostgreSQL & pgvector** for robust vector database capabilities

## 📞 Support

For support and questions:
- Create an issue in this repository
- Contact the EDUC Alliance technical team
- Refer to the comprehensive logs and admin panel for diagnostics

---

**Version**: 2.0.0  
**Last Updated**: December 2024  
**Compatibility**: PHP 8.1+, PostgreSQL 12+, Nextcloud Talk