<?php

require_once(__DIR__ . '/vendor/autoload.php');
$myGeminiApiKey = file_get_contents('.keys/.googlegemini.txt');

// ------------------------------------------------------ include files
// basic config and system tools
require_once(__DIR__ . '/inc/_confsys.php');
require_once(__DIR__ . '/inc/_confdb.php');
require_once(__DIR__ . '/inc/_mail.php');
require_once(__DIR__ . '/inc/_tools.php');
// the AI classes
require_once(__DIR__ . '/inc/_aiollama.php');
require_once(__DIR__ . '/inc/_aigroq.php');
require_once(__DIR__ . '/inc/_aianthropic.php');
require_once(__DIR__ . '/inc/_aithehive.php');
require_once(__DIR__ . '/inc/_aiopenai.php');
require_once(__DIR__ . '/inc/_aigoogle.php');
// incoming tools
require_once(__DIR__ . '/inc/_wasender.php');
require_once(__DIR__ . '/inc/_xscontrol.php');
// central tool
require_once(__DIR__ . '/inc/_central.php');
// basic ai tools
require_once(__DIR__ . '/inc/_basicai.php');
// frontend tools
require_once(__DIR__ . '/inc/_frontend.php');
// Load utility classes
require_once(__DIR__ . '/inc/_curler.php');
require_once(__DIR__ . '/inc/_listtools.php');
require_once(__DIR__ . '/inc/_processmethods.php');

$msgArr = [];
$msgArr['BUSERID'] = 2;
$msgArr['BTEXT'] = 'Panning wide shot of a dog in the fairy tales of 1001 nights. With Persian backgrounds like ancient Persia.';

// ******************************************************************************************************
// Google Gemini Video Generation
echo "Starting video generation...\n";

// Start the video generation operation
$response = Curler::callJson(
    'https://generativelanguage.googleapis.com/v1beta/models/veo-2.0-generate-001:predictLongRunning?key=' . trim($myGeminiApiKey),
    [
        'Content-Type: application/json'
    ],
    [
        'instances' => [
            [
                'prompt' => $msgArr['BTEXT']
            ]
        ],
        'parameters' => [
            'aspectRatio' => '16:9',
            'personGeneration' => 'dont_allow'
        ]
    ]
);

// Check if we got an operation name
if (!isset($response['name'])) {
    echo "Error: No operation name received\n";
    print_r($response);
    exit;
}

$operationName = $response['name'];
echo 'Operation started: ' . $operationName . "\n";

// Poll for completion
$maxAttempts = 120; // Wait up to 10 minutes (120 * 5 seconds)
$attempt = 0;

while ($attempt < $maxAttempts) {
    echo 'Checking operation status (attempt ' . ($attempt + 1) . ")...\n";

    // Check operation status
    try {
        $statusResponse = Curler::callJson(
            'https://generativelanguage.googleapis.com/v1beta/' . $operationName . '?key=' . trim($myGeminiApiKey),
            [
                'Content-Type: application/json'
            ],
            null
        );
    } catch (Exception $e) {
        echo 'Error checking status: ' . $e->getMessage() . "\n";

        // Let's try to get the raw response for debugging
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://generativelanguage.googleapis.com/v1beta/' . $operationName . '?key=' . trim($myGeminiApiKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo 'HTTP Code: ' . $httpCode . "\n";
        echo 'Raw Response: ' . $rawResponse . "\n";
        exit;
    }

    if (isset($statusResponse['done']) && $statusResponse['done'] === true) {
        echo "Video generation completed!\n";

        // Check for errors
        if (isset($statusResponse['error'])) {
            echo "Error in video generation:\n";
            print_r($statusResponse['error']);
            exit;
        }

        // Save the video
        if (isset($statusResponse['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'])) {
            $videoUri = $statusResponse['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'];
            echo 'Video URI: ' . $videoUri . "\n";

            // Download the video from the URI
            $videoUri .= '&key=' . trim($myGeminiApiKey); // Add API key for authentication

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $videoUri);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $videoData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 200 && $videoData !== false) {
                $filename = 'up/gemini_video_' . date('Y-m-d_H-i-s') . '.mp4';

                if (file_put_contents($filename, $videoData)) {
                    echo 'Video saved successfully as: ' . $filename . "\n";
                    echo 'File size: ' . number_format(strlen($videoData)) . " bytes\n";

                    // Check if there are multiple videos generated
                    $totalVideos = count($statusResponse['response']['generateVideoResponse']['generatedSamples']);
                    echo 'Generated ' . $totalVideos . " video(s). Saved the first one.\n";

                    if ($totalVideos > 1) {
                        echo "Additional video URIs:\n";
                        for ($i = 1; $i < $totalVideos; $i++) {
                            echo 'Video ' . ($i + 1) . ': ' . $statusResponse['response']['generateVideoResponse']['generatedSamples'][$i]['video']['uri'] . "\n";
                        }
                    }
                } else {
                    echo "Error: Could not save video file\n";
                }
            } else {
                echo 'Error downloading video. HTTP Code: ' . $httpCode . "\n";
            }
        } else {
            echo "Error: No video URI found in response\n";
            print_r($statusResponse);
        }

        break;
    } else {
        echo "Video generation still in progress...\n";
        $attempt++;

        if ($attempt < $maxAttempts) {
            sleep(5); // Wait 5 seconds before checking again
        }
    }
}

if ($attempt >= $maxAttempts) {
    echo "Timeout: Video generation took too long\n";
}
