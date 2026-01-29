# Features Overview

Everything Synaplan can do.

## AI Chat

Multi-provider AI conversations:

- **Local**: Ollama (gpt-oss, llama, mistral, etc.)
- **Cloud**: OpenAI, Anthropic, Groq, Google Gemini
- **Switching**: Change models per conversation
- **Context**: Maintains conversation history

## RAG System

Semantic document search and retrieval:

- Upload any document format
- Automatic text extraction (Tika)
- Vector embeddings (bge-m3, 1024 dim)
- Cosine similarity search
- AI-augmented answers

→ [Full RAG documentation](RAG.md)

## Chat Widget

Embeddable chat for any website:

- Single script tag embed
- Customizable appearance
- Lazy loading
- Rate limiting
- Domain whitelisting

→ [Full Widget documentation](WIDGET.md)

## WhatsApp Integration

Meta Business API support:

- Send and receive messages
- Multi-phone number support
- Media handling
- Audio transcription
- Anonymous usage

→ [Full WhatsApp documentation](WHATSAPP.md)

## Email Channel

AI-powered email responses:

- Topic-based routing
- Chat context management
- Spam protection
- Rate limiting

→ [Full Email documentation](EMAIL.md)

## Document Processing

Extract content from:

| Format | Engine |
|--------|--------|
| PDF, Word, Excel, PowerPoint | Apache Tika |
| Images (PNG, JPEG, etc.) | Tesseract OCR |
| Audio (MP3, WAV, etc.) | Whisper.cpp |

## File Management

- Upload and organize files
- Private by default
- Public sharing with tokens
- Expiry dates
- Group organization

## User Management

- JWT authentication
- Role-based access
- Subscription tiers
- Rate limiting
- API keys for integrations

## App Modes

- **Easy Mode**: Simplified interface for casual users
- **Advanced Mode**: Full features for power users

## Optional: AI Memories

Connect [synaplan-memories](https://github.com/metadist/synaplan-memories) for:

- User profiling
- Conversation memory
- Qdrant vector storage
