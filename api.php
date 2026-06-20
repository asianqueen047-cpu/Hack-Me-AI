<?php
header('Content-Type: application/json');



$apiKeys = [
    'PASTE_GEMINI_API_KEY_1_HERE',
    'PASTE_GEMINI_API_KEY_2_HERE',
    'PASTE_GEMINI_API_KEY_3_HERE',
    'PASTE_GEMINI_API_KEY_4_HERE',
    'PASTE_GEMINI_API_KEY_5_HERE'
];

$model = 'gemini-2.5-flash';
$logFile = __DIR__ . '/error.log';

function writeLog($message) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    error_log($line, 3, $logFile);
}

function sendJson($reply) {
    echo json_encode(['reply' => $reply]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
$userMessage = trim($input['message'] ?? '');

if ($userMessage === '') {
    sendJson('Please type a message first.');
}

// Remove empty placeholder keys.
$apiKeys = array_values(array_filter($apiKeys, function($key) {
    return !empty($key) && strpos($key, 'PASTE_GEMINI_API_KEY') === false;
}));

if (count($apiKeys) === 0) {
    writeLog('No valid Gemini API key found in api.php');
    sendJson('API key is not configured yet. Please add your Gemini API key in api.php.');
}

// Randomize keys for fallback attempts.
shuffle($apiKeys);

$systemInstruction = "You are a helpful AI tutor. Explain answers clearly and simply. Keep answers useful for students.";

$payload = [
    'systemInstruction' => [
        'parts' => [
            ['text' => $systemInstruction]
        ]
    ],
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => $userMessage]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 800
    ]
];

$lastError = '';

foreach ($apiKeys as $index => $apiKey) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        $lastError = 'cURL error using key #' . ($index + 1) . ': ' . $curlError;
        writeLog($lastError);
        continue;
    }

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        sendJson($result['candidates'][0]['content']['parts'][0]['text']);
    }

    $apiMessage = $result['error']['message'] ?? 'Unknown API error';
    $lastError = 'HTTP ' . $httpCode . ' using key #' . ($index + 1) . ': ' . $apiMessage;
    writeLog($lastError);

    // Continue fallback for rate limit, server errors, or unavailable service.
    if (in_array($httpCode, [429, 500, 502, 503, 504])) {
        continue;
    }

    // For invalid request/key errors, try next key too, but all may fail if setup is wrong.
    if (in_array($httpCode, [400, 401, 403])) {
        continue;
    }
}

writeLog('All API keys failed. Last error: ' . $lastError);
sendJson('The AI service is busy or unavailable right now. Please try again after a few minutes.');
?>
