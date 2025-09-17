-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 17, 2025 at 09:50 AM
-- Server version: 11.8.2-MariaDB-ubu2404-log
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
  `BSERVICE` varchar(32) NOT NULL DEFAULT '',
  `BNAME` varchar(48) NOT NULL DEFAULT '',
  `BTAG` varchar(24) NOT NULL DEFAULT '',
  `BSELECTABLE` int(11) NOT NULL DEFAULT 0 COMMENT 'User can pick this model for a prompt.',
  `BPROVID` varchar(96) NOT NULL DEFAULT '',
  `BPRICEIN` float NOT NULL DEFAULT 0.2 COMMENT 'Always US$',
  `BINUNIT` varchar(24) NOT NULL DEFAULT 'per1M',
  `BPRICEOUT` float NOT NULL DEFAULT 0.05 COMMENT 'Always US$',
  `BOUTUNIT` varchar(24) NOT NULL DEFAULT 'per1M',
  `BQUALITY` float NOT NULL DEFAULT 7,
  `BRATING` float NOT NULL DEFAULT 0.5,
  `BJSON` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}' CHECK (json_valid(`BJSON`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `BMODELS`
--

INSERT INTO `BMODELS` (`BID`, `BSERVICE`, `BNAME`, `BTAG`, `BSELECTABLE`, `BPROVID`, `BPRICEIN`, `BINUNIT`, `BPRICEOUT`, `BOUTUNIT`, `BQUALITY`, `BRATING`, `BJSON`) VALUES
(1, 'Ollama', 'deepseek-r1:14b', 'chat', 1, 'deepseek-r1:14b', 0.092, 'per1M', 0.46, 'per1M', 6, 0.5, '{\"description\":\"Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM.\"}'),
(2, 'Ollama', 'Llama 3.3 70b', 'chat', 1, 'llama3.3:70b', 0.54, 'per1M', 0.73, 'per1M', 9, 1, '{\"description\":\"Local model on synaplans company server in Germany. Metas Llama Model Version 3.3 with 70b parameters. Heavy load model and relatively slow, even on a dedicated NVIDIA card. Yet good quality!\"}'),
(3, 'Ollama', 'deepseek-r1:32b', 'chat', 1, 'deepseek-r1:32b', 0.69, 'per1M', 0.91, '-', 8, 1, '{\"description\":\"Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM. This is the bigger version with 32b parameters. A bit slower, but more accurate!\"}'),
(6, 'Ollama', 'mistral', 'chat', 1, 'mistral', 0.095, 'per1M', 0.475, '-', 5, 0, '{\"description\":\"Local model on synaplans company server in Germany. Mistral 8b model - internally used for RAG retrieval.\"}'),
(9, 'Groq', 'Llama 3.3 70b versatile', 'chat', 1, 'llama-3.3-70b-versatile', 0.59, 'per1M', 0.79, 'per1M', 9, 1, '{\"description\":\"Fast API service via groq\",\"params\":{\"model\":\"llama-3.3-70b-versatile\",\"reasoning_format\":\"hidden\",\"messages\":[]}}'),
(13, 'Ollama', 'bge-m3', 'vectorize', 0, 'bge-m3', 0.19, 'per1M', 0, '-', 6, 1, '{\"description\":\"Vectorize text into synaplans MariaDB vector DB (local) for RAG\",\"params\":{\"model\":\"bge-m3\",\"input\":[]}}'),
(17, 'Groq', 'llama-4-scout-17b-16e-instruct', 'pic2text', 1, 'meta-llama/llama-4-scout-17b-16e-instruct', 0.11, 'per1M', 0.34, 'per1M', 8, 0, '{\"description\":\"Groq image processing and text extraction\",\"prompt\":\"Describe image! List the texts in the image, if possible. If not, describe the image in short.\",\"params\":{\"model\":\"llama-3.2-90b-vision-preview\"}}'),
(21, 'Groq', 'whisper-large-v3', 'sound2text', 1, 'whisper-large-v3', 0.111, 'perhour', 0, '-', 7, 1, '{\"description\":\"Groq whisper model to extract text from a sound file.\",\"params\":{\"file\":\"*LOCALFILEPATH*\",\"model\":\"whisper-large-v3\",\"response_format\":\"text\"}}'),
(25, 'OpenAI', 'dall-e-3', 'text2pic', 1, 'dall-e-3', 0, '-', 0.12, 'perpic', 7, 1, '{\"description\":\"Open AIs famous text to image model on OpenAI cloud. Costs are 1:1 funneled.\"}'),
(29, 'OpenAI', 'gpt-image-1', 'text2pic', 1, 'gpt-image-1', 5, '-', 0, 'per1M', 9, 1, '{\"description\":\"Open AIs powerful image generation model on OpenAI cloud. Costs are 1:1 funneled.\"}'),
(30, 'OpenAI', 'gpt-4.1', 'chat', 1, 'gpt-4.1', 2, 'per1M', 8, 'per1M', 10, 1, '{\"description\":\"Open AIs text model\"}'),
(33, 'Google', 'ImaGen 3.0', 'text2pic', 1, 'imagen-3.0-generate-002', 0, '-', 0.3, 'perpic', 9, 1, '{\"description\":\"Google Imagen 3.0\"}'),
(37, 'Google', 'Gemini 2.0 Flash', 'text2sound', 1, 'gemini-2.0-flash', 0.1, 'per1M', 0.4, 'per1M', 8, 1, '{\"description\":\"Google Speech Generation with Gemini 2.0 Flash\"}'),
(41, 'OpenAI', 'tts-1 with Nova', 'text2sound', 1, 'tts-1', 0.015, 'per1000chars', 0, '-', 8, 1, '{\"description\":\"Open AIs text to speech, defaulting on voice NOVA.\"}'),
(45, 'Google', 'Veo 2.0', 'text2vid', 1, 'veo-2.0-generate-001', 0, '-', 0.35, 'persec', 9, 1, '{\"description\":\"Google Video Generation model Veo2\"}'),
(49, 'Groq', 'llama-4-maverick-17b-128e-instruct', 'chat', 1, 'meta-llama/llama-4-maverick-17b-128e-instruct', 0.2, 'per1M', 0.6, 'per1M', 7, 0, '{\"description\":\"Groq Llama4 128e processing and text extraction\",\"prompt\":\"\",\"params\":{\"model\":\"meta-llama/llama-4-maverick-17b-128e-instruct\"}}'),
(53, 'Groq', 'deepseek-r1-distill-llama-70b', 'chat', 1, 'deepseek-r1-distill-llama-70b', 0.75, 'per1M', 0.99, 'per1M', 7, 0, '{\"description\":\"Groq DeepSeek R1 Distill on Llama\",\"prompt\":\"\",\"params\":{\"model\":\"deepseek-r1-distill-llama-70b\"}}'),
(57, 'OpenAI', 'o3', 'chat', 1, 'o3', 2, 'per1M', 8, 'per1M', 8, 1, '{\"description\":\"Open AIs actual reasoning model.\"}'),
(61, 'Google', 'Gemini 2.5 Pro', 'chat', 1, 'gemini-2.5-pro-preview-06-05', 2.5, 'per1M', 15, 'per1M', 9, 1, '{\"description\":\"Googles Answer to the other LLM models\"}'),
(65, 'Google', 'Gemini 2.5 Pro', 'pic2text', 1, 'gemini-2.5-pro-preview-06-05', 2.5, 'per1M', 15, 'per1M', 9, 1, '{\"description\":\"Googles Powerhouse can also process images, not just text\"}'),
(69, 'Anthropic', 'Claude Opus 4', 'chat', 1, 'claude-opus-4-20250514', 15, 'per1M', 75, 'per1M', 7, 0.5, '{\"description\":\"Claude Opus 4 of Anthropic as the alternative chat method.\"}'),
(70, 'OpenAI', 'gpt-5', 'chat', 1, 'gpt-5', 1.25, 'per1M', 10, 'per1M', 10, 1, '{\"description\":\"Open AIs GPT 5 model - latest release\"}'),
(71, 'Google', 'Gemini 2.5 Flash Image', 'text2pic', 1, 'gemini-2.5-flash-image-preview', 0.3, '-', 0.039, 'perpic', 9, 1, '{\"description\":\"Google newest image generation and editing model (aka Nano-Banana). Low latency, multi-image fusion, character consistency and precise editing. All outputs are watermarked with SynthID.\",\"params\":{\"model\":\"gemini-2.5-flash-image-preview\"}}'),
(72, 'OpenAI', 'o3-pro', 'chat', 1, 'o3-pro', 20, 'per1M', 80, 'per1M', 10, 1, '{\"description\":\"OpenAI premium reasoning model. More compute than o3 with higher reliability. API id: o3-pro. Pricing: $20 per 1M input tokens, $80 per 1M output tokens.\",\"params\":{\"model\":\"o3-pro\"}}'),
(73, 'OpenAI', 'gpt-4o-mini', 'chat', 1, 'gpt-4o-mini', 0.15, 'per1M', 0.6, 'per1M', 8, 1, '{\"description\":\"OpenAI lightweight GPT-4o-mini model for fast and cost-efficient chat and reasoning tasks. Optimized for lower latency and cheaper throughput.\",\"params\":{\"model\":\"gpt-4o-mini\"}}'),
(74, 'Anthropic', 'Claude Sonnet 4', 'chat', 1, 'claude-sonnet-4-20250514', 3, 'per1M', 15, 'per1M', 9, 1, '{\"description\":\"Anthropic Claude Sonnet 4 model. Mid-tier reasoning and coding performance with large context window. Balanced between quality and cost.\",\"params\":{\"model\":\"claude-sonnet-4-20250514\"}}'),
(75, 'Groq', 'gpt-oss-20b', 'chat', 1, 'openai/gpt-oss-20b', 0.1, 'per1M', 0.5, 'per1M', 9, 0, '{\"description\":\"Groq GPT Open Source 20b\",\"prompt\":\"\",\"params\":{\"model\":\"openai/gpt-oss-20b\"}}'),
(76, 'Triton', 'mistral-streaming', 'chat', 1, 'mistral-streaming', 0.01, 'per1M', 0.01, 'per1M', 9, 0, '{\"description\":\"Triton Mistral Streaming\",\"prompt\":\"\",\"params\":{\"model\":\"mistral-streaming\"}}');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `BMODELS`
--
ALTER TABLE `BMODELS`
  ADD PRIMARY KEY (`BID`),
  ADD KEY `BTAG` (`BTAG`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `BMODELS`
--
ALTER TABLE `BMODELS`
  MODIFY `BID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
