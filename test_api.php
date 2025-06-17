<?php
// If this is a POST request with JSON and contains a bot reply, handle as callback endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    // Store the last reply in a temp file for display
    file_put_contents(__DIR__ . '/.last_bot_reply.json', json_encode([
        'received_at' => date('c'),
        'data' => $data
    ], JSON_PRETTY_PRINT));
    // Respond with 200 OK
    header('Content-Type: application/json');
    echo json_encode(['status' => 'received', 'data' => $data]);
    exit;
}
// If not a callback POST, show the HTML form and last reply if available
$lastReply = null;
$lastReplyFile = __DIR__ . '/.last_bot_reply.json';
if (file_exists($lastReplyFile)) {
    $lastReply = json_decode(file_get_contents($lastReplyFile), true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EDUC AI TalkBot API Tester</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        label { display: block; margin-top: 1em; }
        input, textarea { width: 100%; padding: 0.5em; margin-top: 0.2em; }
        button { margin-top: 1em; padding: 0.7em 2em; }
        .result { margin-top: 2em; padding: 1em; border: 1px solid #ccc; background: #f9f9f9; }
        .callback { margin-top: 2em; padding: 1em; border: 1px solid #4caf50; background: #e8f5e9; }
    </style>
</head>
<body>
    <h1>EDUC AI TalkBot API Tester</h1>
    <form id="apiForm">
        <label>API Endpoint (api.php URL)
            <input type="text" name="api_url" value="/api.php" required />
        </label>
        <label>API Key (BOT_TOKEN)
            <input type="text" name="api_key" required />
        </label>
        <label>Message
            <textarea name="message" required>Hello, bot!</textarea>
        </label>
        <label>User ID
            <input type="text" name="user_id" value="user-123" required />
        </label>
        <label>User Name
            <input type="text" name="user_name" value="Alice" />
        </label>
        <label>Room Token
            <input type="text" name="room_token" value="room-abc" required />
        </label>
        <label>Callback URL
            <input type="text" name="callback_url" value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" required />
        </label>
        <label>Message ID (optional)
            <input type="number" name="message_id" value="1" />
        </label>
        <button type="submit">Send to API</button>
    </form>
    <div class="result" id="result" style="display:none;"></div>
    <div style="margin-top:2em; color:#555;">
        <strong>Note:</strong> The bot's reply will be sent asynchronously to the <code>callback_url</code> you provide. This page can now act as the callback URL and will display the last received bot reply below.<br>
        To test, use this page's URL as your <code>callback_url</code>.
    </div>
    <?php if ($lastReply): ?>
        <div class="callback">
            <strong>Last Bot Reply Received (<?php echo htmlspecialchars($lastReply['received_at']); ?>):</strong><br>
            <pre><?php echo htmlspecialchars(json_encode($lastReply['data'], JSON_PRETTY_PRINT)); ?></pre>
        </div>
    <?php endif; ?>
    <script>
    document.getElementById('apiForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = e.target;
        const apiUrl = form.api_url.value;
        const apiKey = form.api_key.value;
        const payload = {
            message: form.message.value,
            user_id: form.user_id.value,
            user_name: form.user_name.value,
            room_token: form.room_token.value,
            callback_url: form.callback_url.value
        };
        if (form.message_id.value) {
            payload.message_id = parseInt(form.message_id.value, 10);
        }
        const resultDiv = document.getElementById('result');
        resultDiv.style.display = 'block';
        resultDiv.textContent = 'Sending...';
        try {
            const res = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': apiKey
                },
                body: JSON.stringify(payload)
            });
            const text = await res.text();
            resultDiv.innerHTML = `<strong>Status:</strong> ${res.status}<br><strong>Response:</strong> <pre>${text}</pre>`;
        } catch (err) {
            resultDiv.innerHTML = `<span style='color:red'>Error: ${err}</span>`;
        }
    });
    </script>
</body>
</html> 