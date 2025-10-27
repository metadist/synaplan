// gemini.js
import 'dotenv/config';
import { GoogleGenAI } from "@google/genai";

// Get API key from environment variables
const API_KEY = process.env.GOOGLE_GEMINI_API_KEY;
if (!API_KEY) {
  throw new Error('GOOGLE_GEMINI_API_KEY is not defined in environment variables');
}

const genAI = new GoogleGenAI({apiKey: API_KEY});

// Example async function to handle a prompt
async function chatWithGemini(prompt) {
  const response = await genAI.models.generateContent(
    {
      model: "gemini-2.5-flash-preview-04-17",
      contents: prompt
    }
  );
  return response.text;
}

// Example call
chatWithGemini("What's the capital of Germany?")
  .then(answer => console.log(answer))
  .catch(err => console.error(err));