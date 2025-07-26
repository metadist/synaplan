# Synaplan - AI Communication Management Platform

Synaplan is an open-source communication management platform that enables seamless interaction with various AI services through multiple channels. Built with modern PHP and leveraging vector search capabilities, it provides a robust foundation for AI-powered communication.

## 🌟 Key Features

- **Multi-AI Integration**: Connect with leading AI services:
  - Google Gemini
  - OpenAI ChatGPT
  - Ollama (local deployment)
  - Groq (high-performance AI)
  - And more to come!

- **Communication Channels**:
  - WhatsApp integration
  - Web widget for easy embedding
  - Gmail business integration
  - Extensible architecture for additional channels

- **Advanced Search & Storage**:
  - Built-in RAG (Retrieval-Augmented Generation)
  - Vector search powered by MariaDB 11.7+
  - Efficient data management and retrieval

- **Local Audio Processing**:
  - **Whisper.cpp Integration**: Local, offline speech-to-text transcription
  - Support for MP3 and MP4 audio files
  - High-performance audio processing with multiple model options
  - Automatic fallback to external services

## 🚀 Technical Requirements

- PHP 8.4
- MariaDB 11.7 or higher (required for vector search)
- Composer for dependency management
- Local Ollama installation (for local AI processing)
- **Whisper.cpp**: Local audio transcription (included in Docker setup)
- Various API keys for integrated services

## 🛠️ Installation

1. Clone the repository
2. Install dependencies via Composer
3. Configure your database (MariaDB 11.7+)
4. Set up required API keys
5. Configure your communication channels
6. **Whisper.cpp models are automatically downloaded during Docker build**

Detailed installation instructions coming soon!

## 🔌 API Integration

Synaplan provides multiple integration methods:
- RESTful API endpoints
- Web widget for easy embedding
- WhatsApp business integration
- Gmail business account integration

## 🎵 Audio Processing

### Whisper.cpp Integration
Synaplan includes local audio transcription capabilities using [whisper.cpp](https://github.com/ggerganov/whisper.cpp):

- **Local Processing**: No external API calls required for audio transcription
- **Multiple Models**: Support for base, small, medium, and large models
- **High Performance**: Optimized C++ implementation with multi-threading
- **Format Support**: MP3 and MP4 audio files
- **Automatic Fallback**: Graceful fallback to external services if needed

#### Model Options
- **medium** (1.5 GiB): Recommended for production use
- **base** (142 MiB): Faster alternative for development
- **small** (466 MiB): Good balance of speed and accuracy
- **large** (2.9 GiB): Best accuracy for critical applications

#### Testing
Access the whisper test page: `http://localhost/devtests/testwhisper.php`

## 🤝 Contributing

We welcome contributions! Whether it's:
- Bug fixes
- New AI service integrations
- Additional communication channels
- Documentation improvements
- Performance optimizations

Please read our contributing guidelines (coming soon) before submitting pull requests.

## 📝 License

[License details to be added]

## 🔮 Roadmap

- [ ] Additional AI service integrations
- [ ] Enhanced RAG capabilities
- [ ] More communication channels
- [ ] Improved documentation
- [ ] Community-driven features
- [x] Local audio transcription with whisper.cpp

## 📞 Support

For support, feature requests, or to report issues, please:
- Open an issue on GitHub
- Join our community discussions
- Contact our team

## 🌐 Links

- [Documentation](https://docs.synaplan.com) (coming soon)
- [Community Forum](https://community.synaplan.com) (coming soon)
- [API Documentation](https://api.synaplan.com) (coming soon)

---

Built with ❤️ by the Synaplan Team


