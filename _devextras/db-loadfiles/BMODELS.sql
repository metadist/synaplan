/*M!999999\- enable the sandbox mode */
-- MariaDB dump 10.19-11.8.2-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: synaplan
-- ------------------------------------------------------
-- Server version	11.8.2-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `BMODELS`
--

DROP TABLE IF EXISTS `BMODELS`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `BMODELS` (
  `BID` bigint(20) NOT NULL AUTO_INCREMENT,
  `BSERVICE` varchar(32) NOT NULL,
  `BNAME` varchar(48) NOT NULL,
  `BTAG` varchar(24) NOT NULL,
  `BSELECTABLE` int(11) NOT NULL,
  `BPROVID` varchar(96) NOT NULL,
  `BPRICEIN` double NOT NULL,
  `BINUNIT` varchar(24) NOT NULL,
  `BPRICEOUT` double NOT NULL,
  `BOUTUNIT` varchar(24) NOT NULL,
  `BQUALITY` double NOT NULL,
  `BRATING` double NOT NULL,
  `BISDEFAULT` int(11) NOT NULL DEFAULT 0,
  `BACTIVE` int(11) NOT NULL DEFAULT 1,
  `BDESCRIPTION` longtext DEFAULT NULL,
  `BJSON` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`BJSON`)),
  PRIMARY KEY (`BID`),
  KEY `idx_tag` (`BTAG`),
  KEY `idx_service` (`BSERVICE`)
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `BMODELS`
--

LOCK TABLES `BMODELS` WRITE;
/*!40000 ALTER TABLE `BMODELS` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `BMODELS` VALUES
(1,'Ollama','deepseek-r1:14b','chat',1,'deepseek-r1:14b',0.092,'per1M',0.46,'per1M',7,8,0,1,NULL,'{\"description\":\"Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM with reasoning capabilities.\",\"features\":[\"reasoning\"]}'),
(2,'Ollama','Llama 3.3 70b','chat',1,'llama3.3:70b',0.54,'per1M',0.73,'per1M',9,1,0,1,NULL,'{\"description\":\"Local model on synaplans company server in Germany. Metas Llama Model Version 3.3 with 70b parameters. Heavy load model and relatively slow, even on a dedicated NVIDIA card. Yet good quality!\"}'),
(3,'Ollama','deepseek-r1:32b','chat',1,'deepseek-r1:32b',0.69,'per1M',0.91,'-',8,8,0,1,NULL,'{\"description\":\"Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM. This is the bigger version with 32b parameters. A bit slower, but more accurate!\",\"features\":[\"reasoning\"]}'),
(6,'Ollama','mistral','chat',1,'mistral:7b',0.095,'per1M',0.475,'-',5,0,0,1,NULL,'{\"description\":\"Local model on synaplans company server in Germany. Mistral 8b model - internally used for RAG retrieval.\"}'),
(9,'Groq','Llama 3.3 70b versatile','chat',1,'llama-3.3-70b-versatile',0.59,'per1M',0.79,'per1M',9,1,0,1,NULL,'{\"description\":\"Fast API service via groq\",\"params\":{\"model\":\"llama-3.3-70b-versatile\",\"reasoning_format\":\"hidden\",\"messages\":[]}}'),
(13,'Ollama','bge-m3','vectorize',0,'bge-m3',0.19,'per1M',0,'-',6,1,0,1,NULL,'{\"description\":\"Vectorize text into synaplans MariaDB vector DB (local) for RAG\",\"params\":{\"model\":\"bge-m3\",\"input\":[]}}'),
(17,'Groq','Llama 4 Scout Vision','pic2text',1,'meta-llama/llama-4-scout-17b-16e-instruct',0.11,'per1M',0.34,'per1M',8,0,0,1,NULL,'{\"description\":\"Groq Llama 4 Scout vision model - 128K context, up to 5 images, supports tool use and JSON mode\",\"params\":{\"model\":\"meta-llama\\/llama-4-scout-17b-16e-instruct\",\"max_completion_tokens\":1024}}'),
(21,'Groq','whisper-large-v3','sound2text',1,'whisper-large-v3',0.111,'perhour',0,'-',8,1,0,1,NULL,'{\"description\":\"Groq Whisper Large V3 - Best accuracy for multilingual transcription and translation. Supports 50+ languages.\",\"params\":{\"file\":\"*LOCALFILEPATH*\",\"model\":\"whisper-large-v3\",\"response_format\":\"verbose_json\"}}'),
(50,'Groq','whisper-large-v3-turbo','sound2text',1,'whisper-large-v3-turbo',0.04,'perhour',0,'-',7,1,0,1,NULL,'{\"description\":\"Groq Whisper Large V3 Turbo - Fast and cost-effective transcription. 3x cheaper than V3. No translation support.\",\"params\":{\"file\":\"*LOCALFILEPATH*\",\"model\":\"whisper-large-v3-turbo\",\"response_format\":\"verbose_json\"}}'),
(25,'OpenAI','dall-e-3','text2pic',1,'dall-e-3',0,'-',0.12,'perpic',7,1,0,1,NULL,'{\"description\":\"Open AIs famous text to image model on OpenAI cloud. Costs are 1:1 funneled.\",\"params\":{\"model\":\"dall-e-3\",\"size\":\"1024x1024\",\"quality\":\"standard\",\"style\":\"vivid\"}}'),
(29,'OpenAI','gpt-image-1','text2pic',1,'gpt-image-1',5,'-',0,'per1M',9,1,0,1,NULL,'{\"description\":\"Open AIs powerful image generation model on OpenAI cloud. Costs are 1:1 funneled.\",\"params\":{\"model\":\"gpt-image-1\"}}'),
(30,'OpenAI','gpt-4.1','chat',1,'gpt-4.1',2,'per1M',8,'per1M',10,1,0,1,NULL,'{\"description\":\"Open AIs text model\",\"params\":{\"model\":\"gpt-4.1\"}}'),
(37,'Google','Gemini 2.5 Flash TTS','text2sound',1,'gemini-2.5-flash-preview-tts',0.1,'per1M',0.4,'per1M',9,1,0,1,NULL,'{\"description\":\"Google Gemini 2.5 Flash Preview TTS (native speech generation)\",\"params\":{\"model\":\"gemini-2.5-flash-preview-tts\",\"voice\":\"Kore\"},\"features\":[\"tts\",\"audio\"]}'),
(41,'OpenAI','tts-1 with Nova','text2sound',1,'tts-1',0.015,'per1000chars',0,'-',8,1,0,1,NULL,'{\"description\":\"Open AIs text to speech, defaulting on voice NOVA.\",\"params\":{\"model\":\"tts-1\",\"voice\":\"nova\"}}'),
(45,'Google','Veo 3.1','text2vid',1,'veo-3.1-generate-preview',0,'-',0.35,'persec',10,1,0,1,NULL,'{\"description\":\"Google Video Generation model Veo 3.1 - 8 second videos with audio\",\"params\":{\"model\":\"veo-3.1-generate-preview\"}}'),
(49,'Groq','llama-4-maverick-17b-128e-instruct','chat',1,'meta-llama/llama-4-maverick-17b-128e-instruct',0.2,'per1M',0.6,'per1M',7,0,0,1,NULL,'{\"description\":\"Groq Llama4 128e processing and text extraction\",\"params\":{\"model\":\"meta-llama\\/llama-4-maverick-17b-128e-instruct\"}}'),
(53,'Groq','Qwen3 32B (Reasoning)','chat',1,'qwen/qwen3-32b',0.15,'per1M',0.6,'per1M',9,5,0,1,NULL,'{\"description\":\"\\ud83e\\udde0 Groq Qwen3 32B mit Reasoning - 32B-Parameter Reasoning-Modell von Qwen. Zeigt Denkprozess mit <think> Tags. Optimiert f\\u00fcr logisches Denken und Probleml\\u00f6sung. Sehr schnell durch Groq Hardware.\",\"params\":{\"model\":\"qwen\\/qwen3-32b\"},\"features\":[\"reasoning\"],\"meta\":{\"context_window\":\"32768\",\"reasoning_format\":\"raw\"}}'),
(57,'OpenAI','o1-preview','chat',0,'o1-preview',15,'per1M',60,'per1M',9,1,0,0,NULL,'{\"description\":\"OpenAI o1-preview reasoning model (REQUIRES API TIER 5 - Not available for most accounts)\",\"params\":{\"model\":\"o1-preview\"},\"features\":[\"reasoning\"],\"supportsStreaming\":false}'),
(59,'OpenAI','o3','chat',0,'o3',2,'per1M',8,'per1M',8,1,0,0,NULL,'{\"description\":\"OpenAI o3 reasoning model (NOT YET AVAILABLE - Limited Preview Only)\",\"params\":{\"model\":\"o3\",\"reasoning_effort\":\"high\"},\"features\":[\"reasoning\"]}'),
(61,'Google','Gemini 2.5 Pro','chat',1,'gemini-2.5-pro-preview-06-05',2.5,'per1M',15,'per1M',9,1,0,1,NULL,'{\"description\":\"Googles Answer to the other LLM models\",\"params\":{\"model\":\"gemini-2.5-pro-preview-06-05\"}}'),
(65,'Google','Gemini 2.5 Pro','pic2text',1,'gemini-2.5-pro-preview-06-05',2.5,'per1M',15,'per1M',9,1,0,1,NULL,'{\"description\":\"Googles Powerhouse can also process images, not just text\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"gemini-2.5-pro-preview-06-05\"}}'),
(69,'Anthropic','Claude 3 Opus','chat',1,'claude-3-opus-20240229',15,'per1M',75,'per1M',10,1,0,1,NULL,'{\"description\":\"Claude 3 Opus - Anthropic\'s most powerful model for complex tasks, analysis, and high-quality outputs. Excellent at reasoning and following instructions.\",\"params\":{\"model\":\"claude-3-opus-20240229\"},\"features\":[\"vision\"],\"meta\":{\"context_window\":\"200000\",\"max_output\":\"4096\"}}'),
(70,'OpenAI','gpt-5','chat',1,'gpt-5',1.25,'per1M',10,'per1M',10,1,0,1,NULL,'{\"description\":\"Open AIs GPT 5 model - latest release\",\"params\":{\"model\":\"gpt-5\"}}'),
(72,'OpenAI','o3-pro','chat',0,'o3-pro',20,'per1M',80,'per1M',10,1,0,0,NULL,'{\"description\":\"OpenAI premium reasoning model (NOT AVAILABLE - API Error). More compute than o3 with higher reliability.\",\"params\":{\"model\":\"o3-pro\",\"reasoning_effort\":\"high\"}}'),
(73,'OpenAI','gpt-4o-mini','chat',1,'gpt-4o-mini',0.15,'per1M',0.6,'per1M',8,1,0,1,NULL,'{\"description\":\"OpenAI lightweight GPT-4o-mini model for fast and cost-efficient chat tasks. Optimized for lower latency and cheaper throughput.\",\"params\":{\"model\":\"gpt-4o-mini\"}}'),
(75,'Groq','gpt-oss-20b','chat',1,'openai/gpt-oss-20b',0.1,'per1M',0.5,'per1M',9,3,0,1,NULL,'{\"description\":\"Groq GPT-OSS 20B - 21B-Parameter MoE-Modell. Optimiert f\\u00fcr niedrige Latenz und schnelle Inferenz. Sehr schnell durch Groq Hardware.\",\"params\":{\"model\":\"openai\\/gpt-oss-20b\"},\"meta\":{\"context_window\":\"131072\",\"license\":\"Apache-2.0\",\"quantization\":\"TruePoint Numerics\"}}'),
(76,'Groq','gpt-oss-120b','chat',1,'openai/gpt-oss-120b',0.15,'per1M',0.75,'per1M',10,4,0,1,NULL,'{\"description\":\"Groq GPT-OSS 120B - 120B-Parameter MoE-Modell. F\\u00fcr anspruchsvolle agentische Anwendungen. Schnelle Inferenz dank Groq Hardware.\",\"params\":{\"model\":\"openai\\/gpt-oss-120b\"},\"meta\":{\"context_window\":\"131072\",\"license\":\"Apache-2.0\",\"quantization\":\"TruePoint Numerics\"}}'),
(78,'Ollama','gpt-oss:20b','chat',1,'gpt-oss:20b',0.12,'per1M',0.6,'per1M',9,1,0,1,NULL,'{\"description\":\"Local model on synaplans company server in Germany. OpenAIs open-weight GPT-OSS (20B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools\\/agentic use cases.\",\"params\":{\"model\":\"gpt-oss:20b\"},\"meta\":{\"context_window\":\"128k\",\"license\":\"Apache-2.0\",\"quantization\":\"MXFP4\"}}'),
(79,'Ollama','gpt-oss:120b','chat',1,'gpt-oss:120b',0.05,'per1M',0.25,'per1M',9,1,0,1,NULL,'{\"description\":\"Local model on synaplans company server in Germany. OpenAIs open-weight GPT-OSS (120B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools\\/agentic use cases.\",\"params\":{\"model\":\"gpt-oss:120b\"},\"meta\":{\"context_window\":\"128k\",\"license\":\"Apache-2.0\",\"quantization\":\"MXFP4\"}}'),
(80,'OpenAI','gpt-4o','chat',1,'gpt-4o',2.5,'per1M',10,'per1M',9,1,0,1,NULL,'{\"description\":\"OpenAI GPT-4o - Omni model with vision, audio and text capabilities.\",\"params\":{\"model\":\"gpt-4o\"},\"features\":[\"vision\",\"audio\"]}'),
(81,'OpenAI','gpt-4o (Vision)','pic2text',1,'gpt-4o',2.5,'per1M',10,'per1M',9,1,0,1,NULL,'{\"description\":\"OpenAI GPT-4o for image analysis and vision tasks.\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"gpt-4o\"}}'),
(82,'OpenAI','whisper-1','sound2text',1,'whisper-1',0.006,'permin',0,'-',9,1,0,1,NULL,'{\"description\":\"OpenAI Whisper model for audio transcription. Supports 50+ languages.\",\"params\":{\"model\":\"whisper-1\",\"response_format\":\"verbose_json\"},\"features\":[\"multilingual\",\"translation\"]}'),
(83,'OpenAI','tts-1-hd','text2sound',1,'tts-1-hd',0.03,'per1000chars',0,'-',9,1,0,1,NULL,'{\"description\":\"OpenAI high-quality text-to-speech.\",\"params\":{\"model\":\"tts-1-hd\"}}'),
(86,'OpenAI','dall-e-2','text2pic',1,'dall-e-2',0,'-',0.02,'perpic',6,1,0,1,NULL,'{\"description\":\"OpenAI DALL-E 2 for cost-effective image generation.\",\"params\":{\"model\":\"dall-e-2\",\"size\":\"1024x1024\"}}'),
(87,'OpenAI','text-embedding-3-small','vectorize',1,'text-embedding-3-small',0.02,'per1M',0,'-',8,1,0,1,NULL,'{\"description\":\"OpenAI text embedding model (1536 dimensions) for RAG and semantic search.\",\"params\":{\"model\":\"text-embedding-3-small\"},\"meta\":{\"dimensions\":1536}}'),
(88,'OpenAI','text-embedding-3-large','vectorize',1,'text-embedding-3-large',0.13,'per1M',0,'-',9,1,0,1,NULL,'{\"description\":\"OpenAI large text embedding model (3072 dimensions) for high-accuracy RAG.\",\"params\":{\"model\":\"text-embedding-3-large\"},\"meta\":{\"dimensions\":3072}}'),
(89,'OpenAI','o1-mini','chat',0,'o1-mini',3,'per1M',12,'per1M',8,1,0,0,NULL,'{\"description\":\"OpenAI o1-mini reasoning model (REQUIRES HIGHER API TIER - Not available for most accounts)\",\"params\":{\"model\":\"o1-mini\"},\"features\":[\"reasoning\"],\"supportsStreaming\":false}'),
(92,'Anthropic','Claude 3 Haiku','chat',1,'claude-3-haiku-20240307',0.25,'per1M',1.25,'per1M',7,2,0,1,NULL,'{\"description\":\"Claude 3 Haiku - Fast and cost-effective model for everyday tasks. Great for quick responses and simple queries.\",\"params\":{\"model\":\"claude-3-haiku-20240307\"},\"features\":[\"vision\"],\"meta\":{\"context_window\":\"200000\",\"max_output\":\"4096\"}}'),
(93,'Anthropic','Claude 3 Opus (Vision)','pic2text',1,'claude-3-opus-20240229',15,'per1M',75,'per1M',10,1,0,1,NULL,'{\"description\":\"Claude 3 Opus for image analysis and vision tasks. Excellent at understanding complex images, charts, diagrams, and extracting text.\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"claude-3-opus-20240229\"},\"meta\":{\"supports_images\":true}}'),
-- HuggingFace Inference Providers (added 2026-02-04)
-- Chat models
(125,'HuggingFace','DeepSeek R1','chat',1,'deepseek-ai/DeepSeek-R1',0.55,'per1M',2.19,'per1M',10,1,0,1,NULL,'{\"description\":\"DeepSeek R1 reasoning model via HuggingFace. Excellent for logic, math, and coding.\",\"params\":{\"model\":\"deepseek-ai/DeepSeek-R1\",\"provider_strategy\":\"fastest\"},\"features\":[\"reasoning\"]}'),
(128,'HuggingFace','Qwen2.5 Coder 32B','chat',1,'Qwen/Qwen2.5-Coder-32B-Instruct',0.20,'per1M',0.80,'per1M',9,1,0,1,NULL,'{\"description\":\"Qwen2.5 Coder - Specialized model for code generation and debugging.\",\"params\":{\"model\":\"Qwen/Qwen2.5-Coder-32B-Instruct\"}}'),
-- Image generation (works via hf-inference provider)
(126,'HuggingFace','Stable Diffusion XL','text2pic',1,'stabilityai/stable-diffusion-xl-base-1.0',0,'-',0.02,'perpic',9,1,0,1,NULL,'{\"description\":\"Stable Diffusion XL - High quality image generation via HuggingFace.\",\"params\":{\"model\":\"stabilityai/stable-diffusion-xl-base-1.0\",\"provider\":\"hf-inference\"}}'),
-- Video generation via fal.ai (requires HF prepaid credits)
(127,'HuggingFace','LTX-Video','text2vid',1,'ltx-video',0,'-',0.25,'pervid',9,1,0,1,NULL,'{\"description\":\"LTX-Video - Fast and high-quality video generation via fal.ai.\",\"params\":{\"model\":\"ltx-video\",\"num_frames\":65,\"num_inference_steps\":25}}'),
-- Embeddings (free tier)
(129,'HuggingFace','Multilingual E5 Large','vectorize',1,'intfloat/multilingual-e5-large',0.01,'per1M',0,'-',9,1,0,1,NULL,'{\"description\":\"Multilingual E5 embedding model - supports 100+ languages. Free tier available.\",\"params\":{\"model\":\"intfloat/multilingual-e5-large\",\"provider\":\"hf-inference\"},\"meta\":{\"dimensions\":1024}}');
/*!40000 ALTER TABLE `BMODELS` ENABLE KEYS */;
UNLOCK TABLES;
commit;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2025-12-17  9:37:27
