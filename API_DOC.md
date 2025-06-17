# EDUC AI TalkBot API

This document describes how to use the generic webhook API endpoint (`api.php`) to interact with the EDUC AI TalkBot from any client, not just Nextcloud.

## Authentication

- All requests must include an API key in the `X-Api-Key` HTTP header.
- The API key is the value of your `BOT_TOKEN` environment variable.

## Endpoint

```
POST /api.php
```

## Request Format

Send a JSON payload with the following fields:

| Field         | Type   | Required | Description                                      |
|-------------- |--------|----------|--------------------------------------------------|
| `message`     | string | Yes      | The user's message to the bot.                   |
| `user_id`     | string | Yes      | Unique identifier for the user.                  |
| `user_name`   | string | No       | (Optional) User's display name.                  |
| `room_token`  | string | Yes      | Unique identifier for the conversation/session.   |
| `callback_url`| string | Yes      | URL where the bot's reply should be sent (POST). |
| `message_id`  | int    | No       | (Optional) Message ID for threading.             |

### Example Request

```
curl -X POST https://yourdomain.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: <YOUR_BOT_TOKEN>" \
  -d '{
    "message": "Hello, bot!",
    "user_id": "user-123",
    "user_name": "Alice",
    "room_token": "room-abc",
    "callback_url": "https://client.example.com/bot-reply",
    "message_id": 1
  }'
```

## Response

- The API will immediately return HTTP 200 if the request is accepted and valid.
- The actual bot reply will be sent asynchronously to the `callback_url` you provided.

## Callback Format

The bot will POST a JSON payload to your `callback_url`:

```
{
  "message": "Hello, Alice! How can I help you today?",
  "replyTo": 1,
  "success": true
}
```

- `message`: The bot's reply.
- `replyTo`: The original `message_id` (if provided).
- `success`: Always `true` if the reply was sent successfully.

## Onboarding

If you are interacting with a bot in a `room_token` for the first time, the bot will initiate an onboarding process to configure itself for that room.

- The first message you send to a new `room_token` will trigger the onboarding.
- The bot will reply with a series of questions.
- Your subsequent messages should be direct answers to those questions.
- Once you have answered all the questions, the onboarding is complete, and the bot will switch to its normal conversational mode.

The onboarding flow is interactive and happens via the same request/callback mechanism. Your client should be prepared to handle a multi-step conversation to complete the setup.

### Example Onboarding Exchange

1.  **Client -> API:** `{"message": "Hello", "room_token": "new-room-456", ...}`
2.  **API -> Callback:** `{"message": "Is this a group chat or a direct message? (Please answer with 'group' or 'dm')", ...}`
3.  **Client -> API:** `{"message": "group", "room_token": "new-room-456", ...}`
4.  **API -> Callback:** `{"message": "Should I respond to every message, or only when I'm mentioned? (Please answer with 'always' or 'on_mention')", ...}`
5.  ...and so on, until completion.
6.  **API -> Callback:** `{"message": "Thanks for setting me up! I'm ready to help.", ...}`

After the final message, the next message from the client will be treated as a regular conversation starter.

## Error Handling

- If authentication fails, you will receive HTTP 401 with `Invalid API key.`
- If required fields are missing, you will receive HTTP 400 with `Missing required fields.`
- If the bot cannot process the request, you may receive HTTP 500 with an error message.

## Tutorial: Integrating with Your Client

1. **Prepare your client to send a POST request to `/api.php` with the required fields.**
2. **Set up an endpoint on your client to receive POST requests from the bot (the `callback_url`).**
3. **Send a message to the bot using the API.**
4. **Wait for the bot's reply to be delivered to your `callback_url`.**

### Example: Minimal Python Client

```python
import requests

API_URL = 'https://yourdomain.com/api.php'
BOT_TOKEN = 'your-bot-token'
CALLBACK_URL = 'https://yourclient.com/bot-reply'

payload = {
    'message': 'Hello, bot!',
    'user_id': 'user-123',
    'user_name': 'Alice',
    'room_token': 'room-abc',
    'callback_url': CALLBACK_URL,
    'message_id': 1
}
headers = {
    'Content-Type': 'application/json',
    'X-Api-Key': BOT_TOKEN
}
response = requests.post(API_URL, json=payload, headers=headers)
print('Status:', response.status_code)
```

### Example: Minimal Node.js Express Callback Receiver

```js
const express = require('express');
const app = express();
app.use(express.json());

app.post('/bot-reply', (req, res) => {
  console.log('Bot replied:', req.body);
  res.sendStatus(200);
});

app.listen(3000, () => console.log('Listening for bot replies on port 3000'));
```

## Notes

- The conversation state, including onboarding progress, is tied to the `room_token`. Use a unique `room_token` for each distinct conversation or chat room.
- The API is stateless from the perspective of the HTTP connection, but maintains conversation state on the server based on the `room_token`.
- For advanced usage, refer to the source code in `api.php`. 