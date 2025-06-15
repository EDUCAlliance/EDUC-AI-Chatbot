# EDUC AI TalkBot Enhanced - Cloudron Setup Guide

## 🚀 Quick Start

Follow these steps to get your EDUC AI TalkBot running in Cloudron:

### 1. **Deploy the Application**

Your app has been deployed to Cloudron at: `https://ai.cloudron.myownapp.net/apps/educ-ai/`

### 2. **Configure Environment Variables**

The admin panel currently shows "API Configuration Required" because environment variables need to be set.

#### **Required Environment Variables**

Configure these in your Cloudron app settings:

| Variable | Value | Description |
|----------|-------|-------------|
| `AI_API_KEY` | Your GWDG SAIA API key | Required for AI model access |
| `AI_API_ENDPOINT` | `https://chat.hpc.gwdg.de/v1/chat/completions` | GWDG chat endpoint |
| `EMBEDDING_API_ENDPOINT` | `https://chat.hpc.gwdg.de/v1/embeddings` | GWDG embeddings endpoint |
| `MODELS_API_ENDPOINT` | `https://chat.hpc.gwdg.de/v1/models` | GWDG models endpoint |
| `BOT_TOKEN` | Your Nextcloud bot token | For Nextcloud Talk integration |
| `NC_URL` | `your-nextcloud-domain.com` | Your Nextcloud domain |
| `ADMIN_USERNAME` | `admin` | Admin panel username |
| `ADMIN_PASSWORD` | `secure_password` | Admin panel password |

### 3. **How to Set Environment Variables in Cloudron**

1. Go to your Cloudron dashboard
2. Click on your EDUC AI TalkBot app
3. Go to **Settings** → **Environment Variables**
4. Add each variable with its value
5. **Restart the app** after adding variables

### 4. **Verify Setup**

1. **Access Admin Panel**: `https://ai.cloudron.myownapp.net/apps/educ-ai/admin/`
2. **Login** with your configured admin credentials
3. **Check Models Tab** - should show available GWDG models
4. **Run Diagnostics**: `https://ai.cloudron.myownapp.net/apps/educ-ai/debug.php?debug_key=educ-debug-2024`

---

## 🔧 Current Status

Based on your diagnostic report:

✅ **Working:**
- PostgreSQL database connection
- pgvector extension
- All PHP extensions loaded
- File system permissions
- Application classes loaded

❌ **Needs Configuration:**
- AI API environment variables
- Nextcloud integration variables
- Admin panel credentials

---

## 📋 Environment Variables Reference

### **Core API Settings**
```bash
AI_API_KEY=your_gwdg_saia_api_key
AI_API_ENDPOINT=https://chat.hpc.gwdg.de/v1/chat/completions
EMBEDDING_API_ENDPOINT=https://chat.hpc.gwdg.de/v1/embeddings
MODELS_API_ENDPOINT=https://chat.hpc.gwdg.de/v1/models
```

### **Nextcloud Integration**
```bash
BOT_TOKEN=your_nextcloud_bot_token
NC_URL=your-nextcloud-domain.com
```

### **Admin Panel Access**
```bash
ADMIN_USERNAME=admin
ADMIN_PASSWORD=your_secure_password
```

### **Optional Settings**
```bash
DEBUG_MODE=false
LOG_LEVEL=INFO
USE_RAG=true
RAG_TOP_K=5
MAX_FILE_SIZE=10485760
ALLOWED_FILE_TYPES=txt,md,pdf,docx,html,csv,json
```

---

## 🌐 Getting GWDG SAIA API Access

1. **Visit**: [GWDG SAIA Documentation](https://docs.hpc.gwdg.de/services/saia/index.html)
2. **Request Access** to the SAIA API service
3. **Get API Key** from GWDG
4. **Test Access** with the provided endpoints

### **Available Models**
GWDG SAIA provides access to various AI models:
- Meta Llama 3.1/3.3 (8B, 70B)
- Google Gemma 3 27B
- Mistral Large
- Qwen 2.5/3 (32B, 235B)
- Codestral 22B (for code)
- DeepSeek R1 (reasoning)

---

## 🔗 Nextcloud Talk Integration

### **Setting up the Bot**

1. **Create a Nextcloud App Password**:
   - Go to Nextcloud Settings → Security
   - Generate an app password for the bot

2. **Configure Webhook**:
   - In Nextcloud Talk, go to bot settings
   - Set webhook URL: `https://ai.cloudron.myownapp.net/apps/educ-ai/connect.php`
   - Set the bot token (BOT_TOKEN environment variable)

3. **Test Integration**:
   - Message the bot in a Nextcloud Talk chat
   - Check logs in admin panel

---

## 🛠️ Troubleshooting

### **Admin Panel Shows "Internal Server Error"**

**Solution**: Set the required environment variables and restart the app.

**Check**: Access the diagnostic tool to see what's missing:
```
https://ai.cloudron.myownapp.net/apps/educ-ai/debug.php?debug_key=educ-debug-2024
```

### **"No Models Available" Message**

**Cause**: API configuration missing or incorrect

**Solutions**:
1. Verify `AI_API_KEY` is set correctly
2. Check `AI_API_ENDPOINT` URL
3. Test API connection in admin panel

### **Database Connection Issues**

**Status**: ✅ Already working in your setup

Your PostgreSQL connection is working correctly with these settings:
- Host: `postgresql`
- Database: `db0d71c7b509cd46439b2717758060060e`
- pgvector: Available

### **File Upload Issues**

**Solutions**:
1. Check file permissions: `chmod 755 uploads cache logs`
2. Verify `MAX_FILE_SIZE` setting
3. Check `ALLOWED_FILE_TYPES` configuration

---

## 📊 Monitoring & Maintenance

### **Admin Panel Features**
- **Dashboard**: System statistics and activity
- **Settings**: Configure AI models and behavior
- **RAG Management**: Upload and process documents
- **Models**: View available AI models
- **Logs**: Monitor system activity
- **System**: Performance and maintenance

### **Log Locations**
- Application logs: `/app/code/apps/educ-ai/logs/`
- Access via admin panel: Logs tab
- Debug info: Use the diagnostic script

### **Backup Considerations**
- Database: Handled by Cloudron PostgreSQL service
- Uploaded files: `/app/code/apps/educ-ai/uploads/`
- Configuration: Environment variables in Cloudron

---

## 🔒 Security Notes

### **Admin Panel Security**
- Uses session-based authentication
- CSRF protection on all forms
- Secure password hashing
- Debug endpoints protected in production

### **API Security**
- Webhook signature verification
- Input validation and sanitization
- Rate limiting support
- Secure environment variable handling

### **File Upload Security**
- File type validation
- Size limits
- Secure file naming
- Virus scanning (if configured)

---

## 📞 Support

### **Logs and Diagnostics**
```bash
# Admin panel logs
https://ai.cloudron.myownapp.net/apps/educ-ai/admin/ → Logs tab

# Detailed diagnostic
https://ai.cloudron.myownapp.net/apps/educ-ai/debug.php?debug_key=educ-debug-2024

# Test database connection
https://ai.cloudron.myownapp.net/apps/educ-ai/test-db.php?debug_key=educ-debug-2024
```

### **Common Issues**
1. **Environment variables not set** → Configure in Cloudron settings
2. **API key invalid** → Check GWDG SAIA access
3. **Nextcloud integration** → Verify webhook URL and bot token
4. **File permissions** → Run `composer install` to fix

### **Contact**
- GitHub: [EDUCAlliance/EDUC-AI-TalkBot-Enhanced](https://github.com/EDUCAlliance/EDUC-AI-TalkBot-Enhanced)
- Documentation: See README.md for detailed technical information

---

**Version**: 2.0.0  
**Cloudron Compatible**: ✅  
**Database**: PostgreSQL with pgvector  
**AI Provider**: GWDG SAIA API 