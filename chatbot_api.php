<?php
session_start();
require_once 'config.php';

header("Content-Type: application/json");

// Pull the token from your updated config.php file automatically
$githubToken = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';

if (empty($githubToken) || $githubToken === 'YOUR_GITHUB_PERSONAL_ACCESS_TOKEN_HERE') {
    echo json_encode(['reply' => 'GitHub token not configured. Please set GITHUB_TOKEN in config.php']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$message = trim($data['message'] ?? '');

if (empty($message)) {
    echo json_encode(["reply" => "Please type a message."]);
    exit;
}

$userName = $_SESSION['uname'] ?? 'User';

$systemPrompt = "You are AssetEase AI Assistant. You help users reserve rooms, request equipment, understand booking policies, understand the approval process, and explain reservation steps. Keep replies short, friendly, and professional.";

$postData = [
    // Ensure this matches the exact model name allowed by your GitHub token tier
    "model" => "gpt-4o-mini", 
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $message]
    ],
    "temperature" => 0.7,
    "max_tokens" => 150
];

$ch = curl_init();

// CORRECT GITHUB MODELS ENDPOINT
curl_setopt($ch, CURLOPT_URL, "https://models.inference.ai.azure.com/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $githubToken
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode([
        "reply" => "AI server connection failed. cURL Error: " . curl_error($ch)
    ]);
    exit;
}

curl_close($ch);

$result = json_decode($response, true);
$reply = $result['choices'][0]['message']['content'] ?? "Sorry, I could not process that response.";

echo json_encode(["reply" => $reply]);
?>