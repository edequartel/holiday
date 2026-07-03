<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';

$config = holiday_config();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

$destination = trim($input['destination'] ?? '');
$interests = trim($input['interests'] ?? 'culture, food, nature, accessible travel');
$days = (int)($input['days'] ?? 3);

if ($destination === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Destination is required.']);
    exit;
}

$apiKey = $config['openai_api_key'] ?? '';
$model = $config['openai_model'] ?? 'gpt-5.5';

if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY') {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key is missing in the private secrets file.']);
    exit;
}

$prompt = "Suggest points of interest for a holiday planner.\n" .
    "Destination: {$destination}\n" .
    "Travel days: {$days}\n" .
    "Interests: {$interests}\n\n" .
    "Return strict JSON only, no markdown. Format:\n" .
    "{\"points\":[{\"name\":\"...\",\"type\":\"poi|restaurant|transport|hotel|parking|other\",\"city\":\"...\",\"latitude\":25.0330,\"longitude\":121.5654,\"reason\":\"short reason\"}]}\n" .
    "Use real approximate coordinates when you know them. Keep it to 8 useful suggestions.";

$payload = [
    'model' => $model,
    'input' => $prompt,
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    echo json_encode([
        'error' => 'OpenAI request failed.',
        'status' => $status,
        'details' => $error ?: $response,
    ]);
    exit;
}

$data = json_decode($response, true);
$text = $data['output_text'] ?? null;

if (!$text && isset($data['output'][0]['content'][0]['text'])) {
    $text = $data['output'][0]['content'][0]['text'];
}

if (!$text) {
    http_response_code(502);
    echo json_encode(['error' => 'No text returned by OpenAI.', 'raw' => $data]);
    exit;
}

$text = trim($text);
$text = preg_replace('/^```json\s*|\s*```$/', '', $text);
$decoded = json_decode($text, true);

if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI did not return valid JSON.', 'text' => $text]);
    exit;
}

echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
