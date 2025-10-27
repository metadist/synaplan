# Synaplan User Guide

## 1. What is Synaplan?

**Synaplan** is a unified platform that connects, manages, and interacts with multiple AI models—like ChatGPT, Claude, and Gemini—in one interface. It allows you to:
- Chat with different models
- Compare responses
- Organize prompts
- Control model behavior

All while keeping your data secure and your workflows organized. Synaplan also **tracks and documents your communications**, giving you a clear log of AI interactions across tools.

---

## 2. Meet the Chat Console

![Chat Console Screenshot](https://www.synaplan.com/assets/member-screen.png)

### Key Interface Elements:
- **Chat Area**: Displays your conversation history based on selected time span.
- **Message Box**: Type, upload a file, or voice record to begin a conversation.
- **Active Mode**: Shows the selected AI mode (e.g., `AnalyzeFile`, `mediamaker`, etc.).
- **Dashboard**: The main navigation to switch pages and settings.

### Chat Walkthrough

1. **Start a Chat**  
   - Click “Chat” on the dashboard.
   - Choose an AI mode (e.g., `mediamaker`, `officemaker`) or create your own under **AI Config > Task Prompts**.

2. **Type Your Question**  
   - Type, record, or upload a file.
   - Examples: “Summarize this PDF”, “Make a short video script.”  
   - Use ↑ / ↓ keys to cycle through prompt history.

3. **Get Answers**  
   - Receive responses from the model.
   - Tweak prompts, compare different model outputs, and download files.

---

## Keyboard Shortcuts

| Command      | Description                                 | Example |
|--------------|---------------------------------------------|---------|
| `/list`      | Show all available commands                 | `/list` |
| `/pic [text]`| Create an AI-generated image               | `/pic A futuristic city skyline at sunset` |
| `/vid [text]`| Generate a short video                     | `/vid A robot dancing in a neon-lit alley` |
| `/search`    | Perform real-time web search               | `/search Latest AI trends in 2025` |
| `/lang [code] [text]` | Translate text to a language     | `/lang fr Hello, how are you?` |
| `/web [URL]` | Screenshot a webpage (Beta)                | `/web https://openai.com` |
| `/docs [text]`| Search uploaded files                     | `/docs refund policy` |
| `/link`      | Generate secure login link                 | `/link` |

---

## 3. Configuration 101: Presets & Profiles

- A **Prompt**: Instructions (e.g., summarize, analyze).
- An **Assistant**: A collection of prompts for a use case.

Find them under: **AI Config > Task Prompts**

### Customizing Prompts

Within each prompt, you can:
- Set or update system messages.
- Assign a specific AI model or allow auto-selection.
- Enable/disable tools like File Search, Internet Search.
- Filter accessible files.

### Advanced Config: Prompt Logic, RAG & AIEngine Tuning

Control the logic flow of prompts, models, and output delivery:

- **Sorting Prompt**: Routes input based on tags like “support”.
- **Task Prompt**: Executes the main logic or action.
- **Output Manager**: Delivers the response to the user/system.

### AI Model Selection
- **Manual**: Choose a specific model per prompt.
- **Automated**: Synaplan picks the best model for the task.

### Default Assistants

| Assistant        | Purpose                                                       |
|------------------|---------------------------------------------------------------|
| Default AI       | General-purpose assistant for any task                        |
| Mediamaker       | Create images, videos, or sound                               |
| General          | Text-based content creation: poems, tips, travel, etc.        |
| AnalyzeFile      | Examine PDFs, DOCX, media files                               |
| Officemaker      | Create Word, Excel, or PowerPoint from prompts                |

---

## Tools You Can Enable

Each Task Prompt can use these optional tools:

- **Internet Search**: Real-time search integration.
- **Files Search (RAG)**: Document retrieval and context processing.
- **URL Screenshot**: Visual webpage capture.

All tools are independently toggleable.

---

## Quick Tips for Using Synaplan

1. **Be Clear**: Start with a direct request.
   > "Create a PowerPoint on sales trends"

2. **Combine Tasks**: Just make them clear.
   > "Summarize this PDF and create an Excel table"

3. **Upload When Needed**: Upload files with clear intent.
   > "Check this onboarding doc for clarity"

4. **Specify Tone or Format**:
   > "Make it sound formal and use bullet points"

---

## Help, Troubleshooting & FAQ

### 1. File Upload Error

**Problem**: Unsupported format or too large.  
**Fix**: Use supported types (PDF, DOCX, TXT), stay under size limit.

### 2. Video/Image Generation Failed

**Problem**: Complex prompt or incorrect command.  
**Fix**:
- Simplify the prompt.
- Use correct format like `/vid` or `/pic`.
- Reduce resolution/duration if needed.

---

For further assistance, visit your **Dashboard > Help Center** or contact **support@synaplan.ai**.