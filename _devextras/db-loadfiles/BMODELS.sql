-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: web1
-- Generation Time: Mar 05, 2026 at 06:25 PM
-- Server version: 12.1.2-MariaDB-ubu2404-log
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `synaplan`
--

-- --------------------------------------------------------

--
-- Table structure for table `BMODELS`
--

DROP TABLE IF EXISTS `BMODELS`;
CREATE TABLE `BMODELS` (
  `BID` bigint(20) NOT NULL,
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
  `BJSON` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`BJSON`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `BMODELS`
--

INSERT INTO `BMODELS` (`BID`, `BSERVICE`, `BNAME`, `BTAG`, `BSELECTABLE`, `BPROVID`, `BPRICEIN`, `BINUNIT`, `BPRICEOUT`, `BOUTUNIT`, `BQUALITY`, `BRATING`, `BISDEFAULT`, `BACTIVE`, `BDESCRIPTION`, `BJSON`) VALUES
(1, 'Ollama', 'deepseek-r1:14b', 'chat', 1, 'deepseek-r1:14b', 0.092, 'per1M', 0.46, 'per1M', 7, 8, 0, 0, NULL, '{\"description\":\"Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM with reasoning capabilities.\",\"features\":[\"reasoning\"]}'),
(2, 'Ollama', 'Llama 3.3 70b', 'chat', 1, 'llama3.3:70b', 0.54, 'per1M', 0.73, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Local model on synaplans company server in Germany. Meta\\u0027s Llama Model Version 3.3 with 70b parameters. Heavy load model and relatively slow, even on a dedicated NVIDIA card. Yet good quality!\"}'),
(3, 'Ollama', 'deepseek-r1:32b', 'chat', 1, 'deepseek-r1:32b', 0.69, 'per1M', 0.91, '-', 8, 8, 0, 1, NULL, '{\"description\":\"Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM. This is the bigger version with 32b parameters. A bit slower, but more accurate!\",\"features\":[\"reasoning\"]}'),
(6, 'Ollama', 'mistral', 'chat', 1, 'mistral:7b', 0.095, 'per1M', 0.475, '-', 5, 0, 0, 1, NULL, '{\"description\":\"Local model on synaplans company server in Germany. Mistral 8b model - internally used for RAG retrieval.\"}'),
(9, 'Groq', 'Llama 3.3 70b versatile', 'chat', 1, 'llama-3.3-70b-versatile', 0.59, 'per1M', 0.79, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Fast API service via groq\",\"params\":{\"model\":\"llama-3.3-70b-versatile\",\"reasoning_format\":\"hidden\",\"messages\":[]}}'),
(13, 'Ollama', 'bge-m3', 'vectorize', 0, 'bge-m3', 0.19, 'per1M', 0, '-', 6, 1, 0, 1, NULL, '{\"description\":\"Vectorize text into synaplans MariaDB vector DB (local) for RAG\",\"params\":{\"model\":\"bge-m3\",\"input\":[]}}'),
(17, 'Groq', 'Llama 4 Scout Vision', 'pic2text', 1, 'meta-llama/llama-4-scout-17b-16e-instruct', 0.11, 'per1M', 0.34, 'per1M', 8, 0, 0, 1, NULL, '{\"description\":\"Groq Llama 4 Scout vision model - 128K context, up to 5 images, supports tool use and JSON mode\",\"params\":{\"model\":\"meta-llama/llama-4-scout-17b-16e-instruct\",\"max_completion_tokens\":1024}}'),
(21, 'Groq', 'whisper-large-v3', 'sound2text', 1, 'whisper-large-v3', 0.111, 'perhour', 0, '-', 8, 1, 0, 1, NULL, '{\"description\":\"Groq Whisper Large V3 - Best accuracy for multilingual transcription and translation. Supports 50+ languages.\",\"params\":{\"file\":\"*LOCALFILEPATH*\",\"model\":\"whisper-large-v3\",\"response_format\":\"verbose_json\"}}'),
(29, 'OpenAI', 'gpt-image-1', 'text2pic', 1, 'gpt-image-1', 5, 'per1M', 40, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"OpenAI image generation model. Costs are 1:1 funneled.\",\"params\":{\"model\":\"gpt-image-1\"}}'),
(37, 'Google', 'Gemini 2.5 Flash TTS', 'text2sound', 1, 'gemini-2.5-flash-preview-tts', 0.1, 'per1M', 0.4, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Gemini 2.5 Flash Preview TTS (native speech generation)\",\"params\":{\"model\":\"gemini-2.5-flash-preview-tts\",\"voice\":\"Kore\"},\"features\":[\"tts\",\"audio\"]}'),
(41, 'OpenAI', 'tts-1 with Nova', 'text2sound', 1, 'tts-1', 0.015, 'per1000chars', 0, '-', 8, 1, 0, 1, NULL, '{\"description\":\"OpenAI\\u0027s text to speech, defaulting on voice NOVA.\",\"params\":{\"model\":\"tts-1\",\"voice\":\"nova\"}}'),
(45, 'Google', 'Veo 3.1', 'text2vid', 1, 'veo-3.1-generate-preview', 0, '-', 0.35, 'persec', 10, 1, 0, 1, NULL, '{\"description\":\"Google Video Generation model Veo 3.1 - 8 second videos with audio\",\"params\":{\"model\":\"veo-3.1-generate-preview\"}}'),
(50, 'Groq', 'whisper-large-v3-turbo', 'sound2text', 1, 'whisper-large-v3-turbo', 0.04, 'perhour', 0, '-', 7, 1, 0, 1, NULL, '{\"description\":\"Groq Whisper Large V3 Turbo - Fast and cost-effective transcription. 3x cheaper than V3. No translation support.\",\"params\":{\"file\":\"*LOCALFILEPATH*\",\"model\":\"whisper-large-v3-turbo\",\"response_format\":\"verbose_json\"}}'),
(53, 'Groq', 'Qwen3 32B (Reasoning)', 'chat', 1, 'qwen/qwen3-32b', 0.29, 'per1M', 0.59, 'per1M', 9, 5, 0, 1, NULL, '{\"description\":\"Groq Qwen3 32B with Reasoning - 32B parameter reasoning model by Qwen. Shows thinking process with <think> tags. Very fast on Groq hardware.\",\"params\":{\"model\":\"qwen/qwen3-32b\"},\"features\":[\"reasoning\"],\"meta\":{\"context_window\":\"32768\",\"reasoning_format\":\"raw\"}}'),
(61, 'Google', 'Gemini 2.5 Pro', 'chat', 1, 'gemini-2.5-pro', 1.25, 'per1M', 10, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Gemini 2.5 Pro - advanced reasoning and coding, 1M token context window.\",\"params\":{\"model\":\"gemini-2.5-pro\"}}'),
(65, 'Google', 'Gemini 2.5 Pro (Vision)', 'pic2text', 1, 'gemini-2.5-pro', 1.25, 'per1M', 10, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Gemini 2.5 Pro for image analysis and vision tasks.\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"gemini-2.5-pro\"}}'),
(70, 'OpenAI', 'GPT-5', 'chat', 1, 'gpt-5', 1.25, 'per1M', 10, 'per1M', 10, 1, 0, 1, NULL, '{\"description\":\"OpenAI GPT-5 - intelligent reasoning model for coding and agentic tasks with configurable reasoning effort.\",\"params\":{\"model\":\"gpt-5\"}}'),
(75, 'Groq', 'gpt-oss-20b', 'chat', 1, 'openai/gpt-oss-20b', 0.075, 'per1M', 0.3, 'per1M', 9, 3, 0, 1, NULL, '{\"description\":\"Groq GPT-OSS 20B - fast, low-latency inference. Apache-2.0 open-weight model.\",\"params\":{\"model\":\"openai/gpt-oss-20b\"},\"meta\":{\"context_window\":\"131072\",\"license\":\"Apache-2.0\",\"quantization\":\"TruePoint Numerics\"}}'),
(76, 'Groq', 'gpt-oss-120b', 'chat', 1, 'openai/gpt-oss-120b', 0.15, 'per1M', 0.6, 'per1M', 10, 4, 0, 1, NULL, '{\"description\":\"Groq GPT-OSS 120B - 120B parameter MoE model for demanding agentic applications. Fast inference on Groq hardware.\",\"params\":{\"model\":\"openai/gpt-oss-120b\"},\"meta\":{\"context_window\":\"131072\",\"license\":\"Apache-2.0\",\"quantization\":\"TruePoint Numerics\"}}'),
(78, 'Ollama', 'gpt-oss:20b', 'chat', 1, 'gpt-oss:20b', 0.12, 'per1M', 0.6, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Local model on synaplans company server in Germany. OpenAI\\u0027s open-weight GPT-OSS (20B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools\\/agentic use cases.\",\"params\":{\"model\":\"gpt-oss:20b\"},\"meta\":{\"context_window\":\"128k\",\"license\":\"Apache-2.0\",\"quantization\":\"MXFP4\"}}'),
(79, 'Ollama', 'gpt-oss:120b', 'chat', 1, 'gpt-oss:120b', 0.05, 'per1M', 0.25, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Local model on synaplans company server in Germany. OpenAI\\u0027s open-weight GPT-OSS (120B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools\\/agentic use cases.\",\"params\":{\"model\":\"gpt-oss:120b\"},\"meta\":{\"context_window\":\"128k\",\"license\":\"Apache-2.0\",\"quantization\":\"MXFP4\"}}'),
(82, 'OpenAI', 'whisper-1', 'sound2text', 1, 'whisper-1', 0.006, 'permin', 0, '-', 9, 1, 0, 1, NULL, '{\"description\":\"OpenAI Whisper model for audio transcription. Supports 50+ languages.\",\"params\":{\"model\":\"whisper-1\",\"response_format\":\"verbose_json\"},\"features\":[\"multilingual\",\"translation\"]}'),
(83, 'OpenAI', 'tts-1-hd', 'text2sound', 1, 'tts-1-hd', 0.03, 'per1000chars', 0, '-', 9, 1, 0, 1, NULL, '{\"description\":\"OpenAI high-quality text-to-speech.\",\"params\":{\"model\":\"tts-1-hd\"}}'),
(87, 'OpenAI', 'text-embedding-3-small', 'vectorize', 1, 'text-embedding-3-small', 0.02, 'per1M', 0, '-', 8, 1, 0, 1, NULL, '{\"description\":\"OpenAI text embedding model (1536 dimensions) for RAG and semantic search.\",\"params\":{\"model\":\"text-embedding-3-small\"},\"meta\":{\"dimensions\":1536}}'),
(88, 'OpenAI', 'text-embedding-3-large', 'vectorize', 1, 'text-embedding-3-large', 0.13, 'per1M', 0, '-', 9, 1, 0, 1, NULL, '{\"description\":\"OpenAI large text embedding model (3072 dimensions) for high-accuracy RAG.\",\"params\":{\"model\":\"text-embedding-3-large\"},\"meta\":{\"dimensions\":3072}}'),
(100, 'triton', 'mistral-7b-instruct-v0.3', 'chat', 1, 'mistral-7b-instruct-v0.3', 0, 'per1M', 0, 'per1M', 7, 0.5, 0, 0, NULL, '{\"description\":\"Triton Inference Server with vLLM backend\",\"features\":[\"streaming\",\"gpu\"],\"supportsStreaming\":true}'),
(101, 'triton', 'gpt-oss-20b', 'chat', 1, 'gpt-oss-20b', 0, 'per1M', 0, 'per1M', 8, 0.7, 0, 0, NULL, '{\"description\":\"Triton Inference Server with vLLM backend\",\"features\":[\"streaming\",\"gpu\"],\"supportsStreaming\":true}'),
(102, 'triton', 'bge-m3', 'vectorize', 1, 'bge-m3', 0, 'per1M', 0, 'per1M', 8, 0.8, 0, 0, NULL, '{\"description\":\"BAAI/bge-m3 dense embeddings (1024-dim)\",\"features\":[\"embedding\",\"multilingual\"]}'),
(106, 'OpenAI', 'GPT-5.2', 'chat', 1, 'gpt-5.2', 1.75, 'per1M', 14, 'per1M', 10, 1, 0, 1, NULL, '{\"description\":\"OpenAI GPT-5.2 - the best model for coding and agentic tasks across industries.\",\"params\":{\"model\":\"gpt-5.2\"}}'),
(115, 'Google', 'Imagen 3.0', 'text2pic', 1, 'imagen-3.0-generate-002', 0.1, 'per1M', 0.4, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Imagen 3.0 image generation\",\"params\":{\"model\":\"imagen-3.0-generate-002\"},\"features\":[\"image\"]}'),
(118, 'Google', 'Nano Banana (Flash Image)', 'text2pic', 1, 'gemini-2.5-flash-image', 0.1, 'per1M', 0.4, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Nano Banana - native image generation and editing via Gemini 2.5 Flash.\",\"params\":{\"model\":\"gemini-2.5-flash-image\"},\"features\":[\"image\"]}'),
(124, 'Ollama', 'nemotron-3-nano', 'chat', 1, 'nemotron-3-nano', 0.092, 'per1M', 0.46, 'per1M', 8, 8, 0, 1, NULL, '{\"description\":\"NVIDIA Nemotron 3 nano\",\"features\":[\"reasoning\"]}'),
(125, 'HuggingFace', 'DeepSeek R1', 'chat', 1, 'deepseek-ai/DeepSeek-R1', 0.55, 'per1M', 2.19, 'per1M', 10, 1, 0, 1, NULL, '{\"description\":\"DeepSeek R1 reasoning model via HuggingFace. Excellent for logic, math, and coding.\",\"params\":{\"model\":\"deepseek-ai/DeepSeek-R1\",\"provider_strategy\":\"fastest\"},\"features\":[\"reasoning\"]}'),
(126, 'HuggingFace', 'Stable Diffusion XL', 'text2pic', 1, 'stabilityai/stable-diffusion-xl-base-1.0', 0, '-', 0.02, 'perpic', 9, 1, 0, 1, NULL, '{\"description\":\"Stable Diffusion XL - High quality image generation via HuggingFace.\",\"params\":{\"model\":\"stabilityai/stable-diffusion-xl-base-1.0\",\"provider\":\"hf-inference\"}}'),
(127, 'HuggingFace', 'LTX-Video', 'text2vid', 1, 'ltx-video', 0, '-', 0.25, 'pervid', 9, 1, 0, 1, NULL, '{\"description\":\"LTX-Video - Fast and high-quality video generation via fal.ai.\",\"params\":{\"model\":\"ltx-video\",\"num_frames\":65,\"num_inference_steps\":25}}'),
(128, 'HuggingFace', 'Qwen2.5 Coder 32B', 'chat', 1, 'Qwen/Qwen2.5-Coder-32B-Instruct', 0.2, 'per1M', 0.8, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Qwen2.5 Coder - Specialized model for code generation and debugging.\",\"params\":{\"model\":\"Qwen/Qwen2.5-Coder-32B-Instruct\"}}'),
(129, 'HuggingFace', 'Multilingual E5 Large', 'vectorize', 1, 'intfloat/multilingual-e5-large', 0.01, 'per1M', 0, '-', 9, 1, 0, 1, NULL, '{\"description\":\"Multilingual E5 embedding model - supports 100+ languages. Free tier available.\",\"params\":{\"model\":\"intfloat/multilingual-e5-large\",\"provider\":\"hf-inference\"},\"meta\":{\"dimensions\":1024}}'),
(130, 'TheHive', 'Flux Schnell', 'text2pic', 1, 'flux-schnell', 0, '-', 0.01, 'perpic', 7, 1, 0, 1, NULL, '{\"description\":\"TheHive Flux Schnell - Fast image generation for prototyping.\",\"params\":{\"model\":\"flux-schnell\",\"width\":1024,\"height\":1024}}'),
(131, 'TheHive', 'Flux Schnell Enhanced', 'text2pic', 1, 'flux-schnell-enhanced', 0, '-', 0.02, 'perpic', 8, 1, 0, 1, NULL, '{\"description\":\"TheHive Flux Schnell Enhanced - Photorealistic image generation with enhanced quality.\",\"params\":{\"model\":\"flux-schnell-enhanced\",\"width\":1024,\"height\":1024}}'),
(132, 'TheHive', 'SDXL', 'text2pic', 1, 'sdxl', 0, '-', 0.02, 'perpic', 8, 1, 0, 1, NULL, '{\"description\":\"TheHive SDXL - Stable Diffusion XL for general purpose high-quality image generation.\",\"params\":{\"model\":\"sdxl\",\"width\":1024,\"height\":1024}}'),
(133, 'TheHive', 'SDXL Enhanced', 'text2pic', 1, 'sdxl-enhanced', 0, '-', 0.05, 'perpic', 9, 1, 0, 1, NULL, '{\"description\":\"TheHive SDXL Enhanced - Premium quality image generation with enhanced details and photorealism.\",\"params\":{\"model\":\"sdxl-enhanced\",\"width\":1024,\"height\":1024}}'),
(134, 'TheHive', 'Custom Emoji', 'text2pic', 1, 'emoji', 0, '-', 0.01, 'perpic', 7, 1, 0, 1, NULL, '{\"description\":\"TheHive Emoji Model - Generate custom emojis with transparent backgrounds.\",\"params\":{\"model\":\"emoji\",\"width\":512,\"height\":512}}'),
(140, 'Piper', 'Piper Multi-Language', 'text2sound', 1, 'piper-multi', 0, 'free', 0, 'free', 7, 0.8, 0, 1, NULL, '{\"description\":\"Self-hosted Piper TTS via synaplan-tts. Multi-language (en, de, es, tr, ru, fa). Free, no API key required.\",\"params\":{\"voices\":[\"en_US-lessac-medium\",\"de_DE-thorsten-medium\",\"es_ES-davefx-medium\",\"tr_TR-dfki-medium\",\"ru_RU-irina-medium\",\"fa_IR-reza_ibrahim-medium\"]},\"features\":[\"multilingual\",\"self-hosted\",\"free\"]}'),
(150, 'OpenAI', 'GPT-5 mini', 'chat', 1, 'gpt-5-mini', 0.25, 'per1M', 2, 'per1M', 8, 1, 0, 1, NULL, '{\"description\":\"OpenAI GPT-5 mini - faster, cost-efficient version of GPT-5 for well-defined tasks.\",\"params\":{\"model\":\"gpt-5-mini\"}}'),
(151, 'OpenAI', 'gpt-image-1.5', 'text2pic', 1, 'gpt-image-1.5', 5, 'per1M', 10, 'per1M', 10, 1, 0, 1, NULL, '{\"description\":\"OpenAI GPT Image 1.5 - state-of-the-art image generation. 4x faster than DALL-E 3, superior text rendering, up to 4096x4096.\",\"params\":{\"model\":\"gpt-image-1.5\"}}'),
(160, 'Anthropic', 'Claude Opus 4.6', 'chat', 1, 'claude-opus-4-6', 5, 'per1M', 25, 'per1M', 10, 1, 0, 1, NULL, '{\"description\":\"Claude Opus 4.6 - Anthropic\\u0027s most intelligent model for agents and coding. 200K context, 128K output.\",\"params\":{\"model\":\"claude-opus-4-6\"},\"features\":[\"vision\",\"reasoning\"],\"meta\":{\"context_window\":\"200000\",\"max_output\":\"128000\"}}'),
(161, 'Anthropic', 'Claude Sonnet 4.6', 'chat', 1, 'claude-sonnet-4-6', 3, 'per1M', 15, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Claude Sonnet 4.6 - best combination of speed and intelligence. 200K context, 64K output.\",\"params\":{\"model\":\"claude-sonnet-4-6\"},\"features\":[\"vision\",\"reasoning\"],\"meta\":{\"context_window\":\"200000\",\"max_output\":\"64000\"}}'),
(162, 'Anthropic', 'Claude Haiku 4.5', 'chat', 1, 'claude-haiku-4-5', 1, 'per1M', 5, 'per1M', 8, 1, 0, 1, NULL, '{\"description\":\"Claude Haiku 4.5 - fastest model with near-frontier intelligence. 200K context, 64K output.\",\"params\":{\"model\":\"claude-haiku-4-5\"},\"features\":[\"vision\",\"reasoning\"],\"meta\":{\"context_window\":\"200000\",\"max_output\":\"64000\"}}'),
(163, 'Anthropic', 'Claude Sonnet 4.6 (Vision)', 'pic2text', 1, 'claude-sonnet-4-6', 3, 'per1M', 15, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Claude Sonnet 4.6 for image analysis and vision tasks.\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"claude-sonnet-4-6\"},\"meta\":{\"supports_images\":true}}'),
(164, 'Anthropic', 'Claude Opus 4.6 (Vision)', 'pic2text', 1, 'claude-opus-4-6', 5, 'per1M', 25, 'per1M', 10, 1, 0, 1, NULL, '{\"description\":\"Claude Opus 4.6 for image analysis and vision tasks. Most capable Anthropic vision model.\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"claude-opus-4-6\"},\"meta\":{\"supports_images\":true}}'),
(170, 'Google', 'Gemini 2.5 Flash', 'chat', 1, 'gemini-2.5-flash', 0.3, 'per1M', 2.5, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Gemini 2.5 Flash - best price-performance model, 1M token context, reasoning, vision, audio.\",\"params\":{\"model\":\"gemini-2.5-flash\"},\"features\":[\"reasoning\",\"vision\",\"audio\"],\"meta\":{\"context_window\":\"1000000\"}}'),
(171, 'Google', 'Gemini 2.5 Flash (Vision)', 'pic2text', 1, 'gemini-2.5-flash', 0.3, 'per1M', 2.5, 'per1M', 9, 1, 0, 1, NULL, '{\"description\":\"Google Gemini 2.5 Flash for image analysis and vision tasks.\",\"prompt\":\"Describe the image in detail. Extract any text you see.\",\"params\":{\"model\":\"gemini-2.5-flash\"}}');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `BMODELS`
--
ALTER TABLE `BMODELS`
  ADD PRIMARY KEY (`BID`),
  ADD KEY `idx_tag` (`BTAG`),
  ADD KEY `idx_service` (`BSERVICE`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `BMODELS`
--
ALTER TABLE `BMODELS`
  MODIFY `BID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=172;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
