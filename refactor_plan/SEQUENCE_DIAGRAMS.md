# Synaplan Symfony 7 Migration – Sequenzdiagramme

## Übersicht

Diese Dokumentation enthält **Mermaid-Sequenzdiagramme** für alle kritischen Request-Flows im Synaplan-System.

---

## 1. Request → Pre → AI → Post → Output (Haupt-Flow)

**Kontext**: Ein User sendet eine Chat-Message über Widget oder Vue.js Frontend.

```mermaid
sequenceDiagram
    actor User
    participant Widget as Widget/Vue.js
    participant Nginx as Reverse Proxy
    participant API as Symfony API
    participant Service as MessageService
    participant Queue as Redis Queue
    participant Worker as Queue Worker
    participant Pre as PreProcessor
    participant AI as AiFacade
    participant Provider as AI Provider
    participant Post as PostProcessor
    participant DB as MariaDB
    participant S3 as Object Storage

    User->>Widget: "Was ist AI?"
    Widget->>Nginx: POST /api/v1/messages
    Nginx->>API: Forward Request
    
    rect rgb(200, 220, 255)
        Note over API,Service: Fast-Ack Phase (< 300ms)
        API->>API: Validate DTO
        API->>Service: enqueueMessage()
        Service->>DB: INSERT message (status=queued)
        Service->>Queue: Dispatch ProcessMessageCommand
        Service-->>API: tracking_id: msg_abc123
        API-->>Nginx: 202 Accepted
        Nginx-->>Widget: {tracking_id, status: queued}
    end
    
    Widget->>User: "Verarbeite..."
    
    rect rgb(255, 240, 220)
        Note over Queue,Post: Async Processing Phase
        Queue->>Worker: Consume ProcessMessageCommand
        Worker->>Service: processMessage(msg_abc123)
        
        alt Has File Attachment
            Service->>Pre: extract(file)
            Pre->>S3: Download File
            S3-->>Pre: file_data
            Pre->>Pre: Tika Extract Text
            alt Text empty (Image/PDF)
                Pre->>AI: extractTextFromImage(url)
                AI->>Provider: Vision API
                Provider-->>AI: extracted_text
                AI-->>Pre: text
            end
            Pre->>AI: embed(text_chunks)
            AI->>Provider: Embedding API
            Provider-->>AI: vectors[]
            AI-->>Pre: vectors
            Pre->>DB: INSERT into BRAG (vectors)
            Pre->>DB: UPDATE message (file_text)
        end
        
        Service->>AI: chat(prompt, thread)
        
        rect rgb(220, 255, 220)
            Note over AI,Provider: Provider Selection & Fallback
            AI->>AI: ProviderRegistry.getChatProvider()
            AI->>AI: RateLimiter.consume()
            AI->>AI: CircuitBreaker.execute()
            
            alt Primary Provider (Anthropic)
                AI->>Provider: POST /messages
                Provider-->>AI: response_text
            else Rate Limited / Timeout
                AI->>Provider: Fallback to OpenAI
                Provider-->>AI: response_text
            else All External Fail
                AI->>Provider: Fallback to Ollama (local)
                Provider-->>AI: response_text
            end
        end
        
        AI-->>Service: ai_response
        Service->>DB: UPDATE message (response, status=completed)
        
        Service->>Post: route(message)
        Post->>Post: Format Response (Markdown/HTML)
        Post->>Post: Content Moderation
        
        alt Output: Widget
            Post->>DB: Store for Polling
        else Output: WhatsApp
            Post->>Post: Send via WhatsApp API
        else Output: Email
            Post->>Post: Queue Email
        else Output: Webhook
            Post->>Post: HTTP POST to Partner
        end
    end
    
    Widget->>API: GET /api/v1/messages/msg_abc123 (Polling)
    API->>DB: SELECT * WHERE tracking_id
    DB-->>API: message (status=completed, response)
    API-->>Widget: {status: completed, response: "..."}
    Widget->>User: AI-Antwort anzeigen
```

**Input** (POST `/api/v1/messages`):
```json
{
  "text": "Was ist AI?",
  "user_id": 2,
  "widget_id": 1,
  "files": []
}
```

**Output** (Fast-Ack):
```json
{
  "tracking_id": "msg_abc123",
  "status": "queued",
  "estimated_time_seconds": 10
}
```

**Output** (Polling GET `/api/v1/messages/msg_abc123`):
```json
{
  "tracking_id": "msg_abc123",
  "status": "completed",
  "response": "AI (Artificial Intelligence) ist...",
  "provider": "anthropic",
  "model": "claude-3-5-sonnet-20241022",
  "duration_ms": 1234
}
```

---

## 2. Login Fast-Ack (< 300ms)

**Kontext**: User loggt sich ein, Backend muss schnell antworten.

```mermaid
sequenceDiagram
    actor User
    participant Vue as Vue.js Frontend
    participant API as Symfony API
    participant Security as SecurityService
    participant DB as MariaDB (Read)
    participant Queue as Redis Queue
    participant Worker as Queue Worker
    participant DBWrite as MariaDB (Write)

    User->>Vue: Email + Password eingeben
    Vue->>API: POST /api/v1/auth/login
    
    rect rgb(200, 255, 200)
        Note over API,DB: Fast-Ack Phase (< 300ms)
        API->>API: Validate Input (< 10ms)
        API->>Security: authenticate(email, password)
        Security->>DB: SELECT * FROM BUSER WHERE BMAIL
        DB-->>Security: user_record
        Security->>Security: password_verify() (< 50ms)
        
        alt Credentials Valid
            Security->>Security: Generate JWT Token (< 20ms)
            Security-->>API: token, user_data
            
            API->>Queue: Dispatch UserLoginCommand (async)
            Note over Queue: Last-Login Update, Logging, Analytics
            
            API-->>Vue: 200 OK {token, user}
        else Invalid Credentials
            API-->>Vue: 401 Unauthorized
        end
    end
    
    Vue->>User: Dashboard anzeigen
    
    rect rgb(255, 240, 220)
        Note over Queue,DBWrite: Async Post-Login Processing
        Queue->>Worker: Consume UserLoginCommand
        Worker->>DBWrite: UPDATE BUSER SET BLASTLOGIN
        Worker->>DBWrite: INSERT into BUSELOG (login_event)
        Worker->>Worker: Send Analytics Event
    end
```

**Input**:
```json
{
  "email": "synaplan@synaplan.com",
  "password": "synaplan"
}
```

**Output** (< 300ms):
```json
{
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "user": {
    "id": 2,
    "email": "synaplan@synaplan.com",
    "level": "NEW",
    "details": {
      "firstName": "Test",
      "lastName": "User"
    }
  },
  "_debug": {
    "response_time_ms": 245
  }
}
```

**Performance-Breakdown**:
- Input Validation: 10 ms
- DB Query (Read Replica): 50 ms
- Password Verify: 50 ms
- JWT Generation: 20 ms
- Queue Dispatch: 10 ms
- HTTP Response: 15 ms
- **Total**: ~155 ms (P50)

---

## 3. Widget-Kompatibilität (Legacy `action` → Neuer Endpoint)

**Kontext**: Bestehende Widget-Embeds nutzen `/api.php?action=messageNew`, müssen auf Symfony gemappt werden.

```mermaid
sequenceDiagram
    participant Widget as Widget (External Site)
    participant Nginx as Reverse Proxy
    participant Legacy as LegacyApiController
    participant Service as MessageService
    participant NewAPI as Modern API Endpoint
    participant Queue as Redis Queue

    Widget->>Nginx: POST /api.php?action=messageNew
    Note over Widget: Legacy Format:<br/>{action: "messageNew", message: "..."}
    
    rect rgb(255, 220, 220)
        Note over Nginx,Legacy: Legacy-Compat Layer
        Nginx->>Legacy: Route to Symfony
        Legacy->>Legacy: Parse action parameter
        
        alt action=messageNew
            Legacy->>Service: enqueueMessage(DTO)
            Service->>Queue: Dispatch Command
            Service-->>Legacy: tracking_id
            Legacy-->>Nginx: Legacy Response Format
        else action=againOptions
            Legacy->>Service: getAgainOptions()
            Service-->>Legacy: options[]
            Legacy-->>Nginx: Legacy Response Format
        else action=getProfile
            Legacy->>Service: getUserProfile()
            Service-->>Legacy: profile_data
            Legacy-->>Nginx: Legacy Response Format
        else Unknown action
            Legacy-->>Nginx: 404 {error: "Unknown action"}
        end
    end
    
    Nginx-->>Widget: Response (Legacy Format)
    Widget->>Widget: Process Response
```

**Legacy Input** (POST `/api.php`):
```
action=messageNew
message=Hello
user_id=2
widget_id=1
```

**Internal Mapping**:
```php
// LegacyApiController
$action = $request->get('action');

$dto = match($action) {
    'messageNew' => new CreateMessageRequest(
        text: $request->get('message'),
        userId: (int)$request->get('user_id'),
        widgetId: (int)$request->get('widget_id')
    ),
    // ...
};

return $this->messageService->enqueueMessage($dto);
```

**Legacy Output** (kompatibel mit altem Format):
```json
{
  "tracking_id": "msg_abc123",
  "status": "queued",
  "success": true
}
```

---

## 4. File Upload mit Extract & Vectorize

**Kontext**: User lädt PDF hoch, Backend extrahiert Text und vectorisiert für RAG.

```mermaid
sequenceDiagram
    actor User
    participant Vue as Vue.js
    participant API as Symfony API
    participant Service as FileService
    participant S3 as Object Storage
    participant Queue as Redis Queue
    participant Worker as Queue Worker
    participant Tika as Tika Server
    participant AI as AiFacade
    participant DB as MariaDB

    User->>Vue: Wählt PDF-Datei
    Vue->>API: POST /api/v1/files/upload (multipart/form-data)
    
    rect rgb(200, 220, 255)
        Note over API,S3: Upload Phase (< 2s)
        API->>API: Validate File (size, type)
        API->>Service: uploadFile(file, user_id)
        Service->>S3: PUT /files/user_2/file_abc.pdf
        S3-->>Service: url
        Service->>DB: INSERT into BRAG (file_path, status=pending)
        Service->>Queue: Dispatch ExtractFileCommand
        Service-->>API: file_id, status=pending
        API-->>Vue: 202 Accepted {file_id: 123, status: pending}
    end
    
    Vue->>User: "Verarbeite Datei..."
    
    rect rgb(255, 240, 220)
        Note over Queue,DB: Async Extract & Vectorize
        Queue->>Worker: Consume ExtractFileCommand
        Worker->>S3: GET /files/user_2/file_abc.pdf
        S3-->>Worker: file_data
        
        Worker->>Tika: PUT /tika (file_data)
        Tika->>Tika: Extract Text
        Tika-->>Worker: extracted_text
        
        alt Text empty (Image-PDF)
            Worker->>AI: explainImage(pdf_pages)
            AI->>AI: Vision Provider (OCR)
            AI-->>Worker: ocr_text
            Worker->>Worker: Merge OCR into text
        end
        
        Worker->>Worker: Split into Chunks (500 tokens each)
        
        loop For each chunk
            Worker->>AI: embed(chunk_text)
            AI->>AI: Embedding Provider
            AI-->>Worker: vector[1536]
            Worker->>DB: INSERT into BRAG (chunk_text, vector, start_line, end_line)
        end
        
        Worker->>DB: UPDATE BRAG SET status=completed
    end
    
    Vue->>API: GET /api/v1/files/123 (Polling)
    API->>DB: SELECT * WHERE file_id
    DB-->>API: file_record (status=completed)
    API-->>Vue: {file_id: 123, status: completed, chunks: 45}
    Vue->>User: "Datei bereit für RAG-Suche"
```

**Input**:
```
POST /api/v1/files/upload
Content-Type: multipart/form-data

------WebKitFormBoundary
Content-Disposition: form-data; name="file"; filename="document.pdf"
Content-Type: application/pdf

[PDF Binary Data]
------WebKitFormBoundary--
```

**Output** (Fast-Ack):
```json
{
  "file_id": 123,
  "filename": "document.pdf",
  "size_bytes": 524288,
  "status": "pending",
  "estimated_time_seconds": 30
}
```

**Output** (After Processing):
```json
{
  "file_id": 123,
  "filename": "document.pdf",
  "status": "completed",
  "chunks": 45,
  "total_tokens": 22500,
  "vector_dimensions": 1536,
  "group_key": "user_2_documents"
}
```

---

## 5. Provider-Fallback-Chain

**Kontext**: Anthropic ist rate-limited, System fällt automatisch auf OpenAI zurück.

```mermaid
sequenceDiagram
    participant Service as MessageService
    participant Facade as AiFacade
    participant Registry as ProviderRegistry
    participant Limiter as RateLimiter
    participant CB as CircuitBreaker
    participant Anthropic as AnthropicProvider
    participant OpenAI as OpenAIProvider
    participant Ollama as OllamaProvider (local)
    participant Metrics as ProviderMetrics

    Service->>Facade: chat("Hello", options={})
    Facade->>Registry: getChatProvider(default)
    Registry-->>Facade: AnthropicProvider
    
    Facade->>Limiter: consume(anthropic, 1)
    Limiter-->>Facade: allowed=false (rate limit exceeded)
    
    rect rgb(255, 200, 200)
        Note over Facade: Primary Failed: Rate Limit
        Facade->>Metrics: trackRateLimitExceeded(anthropic)
        Facade->>Registry: getChatProvider("openai")
        Registry-->>Facade: OpenAIProvider
        
        Facade->>Limiter: consume(openai, 1)
        Limiter-->>Facade: allowed=true
        
        Facade->>CB: execute(() => openai.chat())
        CB->>CB: Check State (CLOSED)
        CB->>OpenAI: simplePrompt("Hello")
        
        alt OpenAI Success
            OpenAI-->>CB: "Hello! How can I help?"
            CB->>CB: onSuccess() (reset failure count)
            CB-->>Facade: response
            Facade->>Metrics: trackSuccess(openai, latency_ms)
        else OpenAI Timeout
            OpenAI--xCB: TimeoutException
            CB->>CB: onFailure() (increment counter)
            CB->>CB: Open Circuit? (threshold: 5 failures)
            
            rect rgb(255, 220, 220)
                Note over CB,Ollama: Fallback to Local Ollama
                Facade->>Registry: getChatProvider("ollama")
                Registry-->>Facade: OllamaProvider
                Facade->>Ollama: simplePrompt("Hello")
                Ollama-->>Facade: "Hi there! (from Ollama)"
            end
        end
    end
    
    Facade-->>Service: final_response
    Service->>Service: Save to DB
```

**Fallback-Konfiguration**:
```yaml
# config/packages/ai.yaml
ai:
    fallback_chain:
        - anthropic
        - openai
        - ollama
    
    rate_limits:
        anthropic: 50/min
        openai: 100/min
        ollama: unlimited
    
    circuit_breaker:
        failure_threshold: 5
        timeout_seconds: 60
```

---

## 6. Strangler-Pattern Routing

**Kontext**: Nginx routet Traffic zu Alt oder Neu basierend auf Endpoint.

```mermaid
sequenceDiagram
    participant Client
    participant Nginx as Nginx Reverse Proxy
    participant Symfony as Symfony 7 (NEU)
    participant Legacy as Legacy PHP (ALT)

    rect rgb(200, 255, 200)
        Note over Client,Symfony: Neue Endpoints → Symfony
        Client->>Nginx: POST /api/v1/messages
        Nginx->>Nginx: Match /api/v1/*
        Nginx->>Symfony: proxy_pass http://symfony
        Symfony-->>Nginx: 202 Accepted
        Nginx-->>Client: Response
    end

    rect rgb(255, 240, 200)
        Note over Client,Symfony: Legacy-Compat → Symfony
        Client->>Nginx: POST /api.php?action=messageNew
        Nginx->>Nginx: Match /api.php
        Nginx->>Symfony: proxy_pass (LegacyApiController)
        Symfony-->>Nginx: Response (Legacy Format)
        Nginx-->>Client: Response
    end

    rect rgb(255, 220, 220)
        Note over Client,Legacy: Widget (temporär) → Legacy PHP
        Client->>Nginx: GET /widget.php?uid=2&widgetid=1
        Nginx->>Nginx: Match /widget.php
        Nginx->>Legacy: fastcgi_pass php-fpm
        Legacy-->>Nginx: JavaScript Code
        Nginx-->>Client: Response
    end

    rect rgb(220, 220, 255)
        Note over Client,Nginx: Static Assets → Nginx direkt
        Client->>Nginx: GET /assets/statics/js/chat.js
        Nginx->>Nginx: Match /assets/*
        Nginx->>Nginx: Serve from disk
        Nginx-->>Client: JavaScript File
    end
```

**Nginx-Config**:
```nginx
upstream symfony_backend {
    server symfony:8000;
}

upstream legacy_php {
    server php-fpm:9000;
}

server {
    listen 80;
    server_name synaplan.local;

    # Neue API → Symfony
    location /api/v1/ {
        proxy_pass http://symfony_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Legacy-Compat → Symfony (LegacyApiController)
    location /api.php {
        proxy_pass http://symfony_backend;
    }

    # Widget (temporär Legacy, später zu Symfony)
    location ~ ^/(widget|widgetloader)\.php$ {
        root /var/www/legacy/public;
        fastcgi_pass legacy_php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Static Assets
    location /assets/ {
        root /var/www/legacy/public;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Health-Check
    location /health {
        proxy_pass http://symfony_backend/api/health;
    }
}
```

---

## 7. RAG-Suche mit Vector-Similarity

**Kontext**: User fragt "Was steht in meinen Dokumenten über X?", System sucht ähnliche Chunks.

```mermaid
sequenceDiagram
    actor User
    participant Vue
    participant API
    participant Service as RagSearchService
    participant AI as AiFacade
    participant DB as MariaDB (Vector Search)
    participant Provider

    User->>Vue: "Was steht über Künstliche Intelligenz?"
    Vue->>API: POST /api/v1/rag/search
    
    API->>Service: search(query, user_id)
    Service->>AI: embed(query)
    AI->>Provider: Embedding API
    Provider-->>AI: query_vector[1536]
    AI-->>Service: query_vector
    
    Service->>DB: SELECT * FROM BRAG<br/>WHERE VEC_DISTANCE(BEMBED, query_vector) < 0.3<br/>AND BUID = user_id<br/>ORDER BY distance LIMIT 10
    
    rect rgb(220, 255, 220)
        Note over DB: MariaDB 11.7 Vector Search
        DB->>DB: Calculate Cosine Similarity
        DB->>DB: Filter by Threshold
        DB->>DB: Order by Distance (ASC)
    end
    
    DB-->>Service: chunks[10] with similarity scores
    
    Service->>Service: Build Context:<br/>"Relevante Dokumente:\n[chunk1]\n[chunk2]..."
    
    Service->>AI: chat(context + query)
    AI->>Provider: Chat Completion
    Provider-->>AI: "Basierend auf Ihren Dokumenten..."
    AI-->>Service: answer
    
    Service-->>API: {answer, sources: [chunk_ids]}
    API-->>Vue: Response
    Vue->>User: Antwort mit Quellen
```

**Input**:
```json
{
  "query": "Was steht über Künstliche Intelligenz?",
  "user_id": 2,
  "group_key": "user_2_documents",
  "limit": 10,
  "threshold": 0.3
}
```

**SQL (MariaDB 11.7 Vector Search)**:
```sql
SELECT 
    BID,
    BTEXT,
    VEC_DISTANCE(BEMBED, VEC_FromText('[0.123, -0.456, ...]')) AS distance,
    BFILEPATH,
    BSTART,
    BEND
FROM BRAG
WHERE 
    BUID = 2
    AND BGROUPKEY = 'user_2_documents'
    AND VEC_DISTANCE(BEMBED, VEC_FromText('[...]')) < 0.3
ORDER BY distance ASC
LIMIT 10;
```

**Output**:
```json
{
  "answer": "Basierend auf Ihren Dokumenten: Künstliche Intelligenz (KI) ist...",
  "sources": [
    {
      "chunk_id": 123,
      "file_path": "documents/ai_report.pdf",
      "text": "KI ist ein Teilgebiet der Informatik...",
      "similarity": 0.92,
      "lines": "45-58"
    },
    {
      "chunk_id": 456,
      "file_path": "documents/tech_overview.docx",
      "text": "Machine Learning ist...",
      "similarity": 0.88,
      "lines": "12-24"
    }
  ],
  "total_results": 2
}
```

---

## Zusammenfassung

**Kritische Flows**:
1. **Request → Pre → AI → Post** (Haupt-Pipeline)
2. **Login Fast-Ack** (< 300ms)
3. **Widget-Compat** (Legacy-Mapping)
4. **File Upload** (Extract & Vectorize)
5. **Provider-Fallback** (Resilience)
6. **Strangler-Routing** (Migration-Strategie)
7. **RAG-Suche** (Vector-Similarity)

**Performance-Ziele**:
- Login: < 300 ms (P95)
- API (CRUD): < 500 ms (P95)
- AI-Processing: Async (non-blocking)
- File-Upload-Ack: < 2s

**Resilience-Patterns**:
- Circuit-Breaker
- Fallback-Chain
- Rate-Limiting
- Retry mit Exponential-Backoff

---

**Status**: ✅ Diagramme finalisiert
**Tool**: Mermaid (GitHub/GitLab/VSCode kompatibel)
**Review**: Ende Woche 1

