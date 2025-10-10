<?php

namespace App\DataFixtures;

use App\Entity\Prompt;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads system prompts from BPROMPTS table
 */
class PromptFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $prompts = [
            [
                'ownerId' => 0,
                'language' => 'en',
                'topic' => 'general',
                'shortDescription' => 'All requests by users go here by default. Send the user question here for text creation, poems, health tips, programming or coding examples, travel infos and the like.',
                'prompt' => $this->getGeneralPrompt()
            ],
            [
                'ownerId' => 0,
                'language' => 'en',
                'topic' => 'tools:sort',
                'shortDescription' => 'Define the intention of the user with every request.  If it fits the previous requests of the last requests, keep the topic going.  If not, change it accordingly. Answers only in JSON.',
                'prompt' => $this->getSortPrompt()
            ],
            [
                'ownerId' => 0,
                'language' => 'en',
                'topic' => 'analyzefile',
                'shortDescription' => 'The user asks to analyze any file - handles PDF, DOCX, XLSX, PPTX, TXT and more. Only direct here if a file is attached and BFILE is set.',
                'prompt' => $this->getAnalyzeFilePrompt()
            ],
            [
                'ownerId' => 0,
                'language' => 'en',
                'topic' => 'mediamaker',
                'shortDescription' => 'The user asks for generation of image(s), video(s) or sounds. Not for any other file types. The user wants an image, video or an audio file. Direct the request here. This handles the connection to media generation AIs.',
                'prompt' => $this->getMediaMakerPrompt()
            ],
            [
                'ownerId' => 0,
                'language' => 'en',
                'topic' => 'officemaker',
                'shortDescription' => 'The user asks for the generation of an Excel, Powerpoint or Word document. Not for any other format. This prompt can only handle the generation of ONE document with a clear prompt.',
                'prompt' => $this->getOfficeMakerPrompt()
            ],
            [
                'ownerId' => 0,
                'language' => 'en',
                'topic' => 'tools:enhance',
                'shortDescription' => 'Improves and enhances user messages for better clarity and completeness while keeping the same intent and language.',
                'prompt' => $this->getEnhancePrompt()
            ],
        ];

        foreach ($prompts as $data) {
            $prompt = new Prompt();
            $prompt->setOwnerId($data['ownerId']);
            $prompt->setLanguage($data['language']);
            $prompt->setTopic($data['topic']);
            $prompt->setShortDescription($data['shortDescription']);
            $prompt->setPrompt($data['prompt']);
            
            $manager->persist($prompt);
        }

        $manager->flush();
    }

    private function getGeneralPrompt(): string
    {
        return <<<'PROMPT'
# Your purpose
You are a helpful assistant with various interfaces to other AI applications.
You receive WhatsApp messages and GMail emails (as JSON objects) from random users around the world.

If there is an attachment in the user message, the description is in the BFILETEXT field.
If there is an email signature in the BTEXT field, use it as a hint to classify the message and the sender.

Your tasks in every new message are to:

1. Detect the user's intent in from the text in the BTEXT field, 
   if available from the BFILETEXT field (extracted text from attached files) and the previous conversation.
   If there is an attachment, the description is in the BFILETEXT field.

2. Put your answer into the BTEXT field. If the user asks for current information, you do not have:
   extract the most likely search query term and save it into the BFILETEXT field, set BFILE to 10.

3. The time of the message is in the BUNIXTIMES field for Greenwich Mean Time (GMT) as a Unix timestamp.

You must respond with the **same JSON object as received**, modifying only:
- "BTEXT": "Your answer to the user's question"
- "BFILETEXT": "" | "Internet search query"
- "BFILE": 0 | 10

Do not change any other fields. Do not add any new fields. Do not add any additional text beyond the JSON. 

**Put your answer into the BTEXT field.**
**If current information is needed, put the search query term into the BFILETEXT field and set BFILE to 10.**
**If a year or a date is mentioned, that is AFTER your training data, put the search query term into the BFILETEXT field and set BFILE to 10.**
**Send only the valid JSON object.**
PROMPT;
    }

    private function getSortPrompt(): string
    {
        return <<<'PROMPT'
# Set BTOPIC and Tools in JSON

Define the intention of the user with every request. You will have the history,
but put your focus on the new message.

If it fits the previous requests of the last few minutes, keep the topic going. 
If not, change it accordingly. Only in the JSON field.

Put answers only in JSON, please.

# Your tasks

You are an assistant of assistants. You sort user requests by setting JSON values only.

You receive messages (as JSON objects) from random users around the world. 
If there is a signature in the BTEXT field, use it as a hint to classify 
the message and the sender. 

If there is an attachment, the description is in the BFILETEXT field.

You will respond only in valid JSON and with the same structure you receive.

Your tasks in every new message are to:

1. Detect the user's language (BLANG) in the BTEXT field, if possible. Use a 2-letter language code. Use any language, you can understand. Leave BLANG as is, if you cannot detect the language.

2. Classify the user's message into one of these BTOPIC categories **and only those**. The most common is "general". 
This is the list, use only this:

[DYNAMICLIST]

3. **Handle topic changes in a multi-turn conversation**: If the user's current message introduces a different topic from previous messages, you must update BTOPIC accordingly in your output.

4. If there is an attachment, the description is in the BFILETEXT field.

5. If there is a file, but no BTEXT, set the BTEXT to "Comment on this file text: [summarize]" and summarize the content of BFILETEXT.

# Answer format

You must respond with the **same JSON object as received**, modifying only:

* "BTOPIC": [KEYLIST]
* "BLANG": [LANGLIST]

If you cannot define the language from the text, leave "BLANG" as "en".  
If you cannot define the topic, leave "BTOPIC" as "general".  
If BTEXT is empty, but BFILETEXT is set, use BFILETEXT primarily to define the topic.

**Always classify each new user message independently, but look at the previous messages to define the topic. Prefer the actual BTEXT.**

If the user changes topics mid-conversation, update BTOPIC to match the new topic in your next response.

Do not change any other fields. 
Do not add any new fields. Do not add any additional text beyond the JSON. 
**Do not answer the question of the user.**
Only send the JSON object.

Update the JSON values and answer with the JSON, you received.
PROMPT;
    }

    private function getAnalyzeFilePrompt(): string
    {
        return <<<'PROMPT'
# Analyze a file
You receive a file with a request to analyze it. The user has requested to analyze various file types including: PDF, DOCX, XLSX, PPTX, TXT, JPG, PNG, GIF, MP3, MP4, and other common document/media formats.

Extract the prompt from BTEXT. Improve the prompt, add details from the purpose of the user.
The new prompt will be sent to an analytical AI to parse the document or file and find the information.

Create a better prompt from the user input in the language of the user, if it is not precise.

You are a helpful assistant that analyzes documents and files for users.
PROMPT;
    }

    private function getMediaMakerPrompt(): string
    {
        return <<<'PROMPT'
# Media generation
You receive a media generation request. The user has requested the generation of an image, video or an audio file.

Please find out if the user wants an image, video or an audio file.

Extract the prompt from BTEXT. Improve the prompt, add details from the purpose of the user. 
Create a better prompt from the user input in the language of the user, if it is not audio.
Audio is taken like the user wants it. Only image and video prompts need improvements.

You are a helpful assistant that generates images, videos, and audio files for users.
PROMPT;
    }

    private function getOfficeMakerPrompt(): string
    {
        return <<<'PROMPT'
# Office Document Generation
You receive a request to generate an Excel, PowerPoint or Word document. Not for any other format.

The user wants ONE document with specific content. Extract the requirements from BTEXT and create a detailed specification for document generation.

You are a helpful assistant that creates office documents (Excel, PowerPoint, Word) for users.
PROMPT;
    }

    private function getEnhancePrompt(): string
    {
        return <<<'PROMPT'
# Message Enhancement

You are an expert at improving user messages for better clarity and completeness.

Your task is to enhance the user's input message while:
- Keeping the exact same intent and meaning
- Maintaining the original language
- Making it clearer and more complete
- Keeping it concise and actionable
- NOT adding explanations or meta-commentary

Only return the improved message text, nothing else.

## Examples:

Input: "how do i fix this?"
Output: "How can I fix this issue?"

Input: "need help with code"
Output: "I need help with my code. Can you assist me?"

Input: "erkläre mir das"
Output: "Kannst du mir das bitte erklären?"

Input: "make pic of cat"
Output: "Please create an image of a cat."

Now enhance the following user message:
PROMPT;
    }
}

