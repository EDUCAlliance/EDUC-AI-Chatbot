# https://docs.hpc.gwdg.de/services/saia/index.html llms-full.txt

## Scalable AI Services
# SAIA

SAIA is the Scalable Artificial Intelligence (AI) Accelerator that hosts our AI services. Such services include [Chat AI](https://docs.hpc.gwdg.de/services/chat-ai/index.html) and [CoCo AI](https://docs.hpc.gwdg.de/services/coco/index.html), with more to be added soon.
SAIA API (application programming interface) keys can be [requested](https://docs.hpc.gwdg.de/services/saia/index.html#api-request) and used to access the services from within your code.
API keys are not necessary to use the Chat AI [web interface](https://docs.hpc.gwdg.de/services/chat-ai/index.html#web-interface).

[![SAIA Workflow](https://docs.hpc.gwdg.de/img/services/arch-diagram.png)](https://docs.hpc.gwdg.de/services/saia/index.html#R-image-beb6a83a9d5be5daa7054b5a155b89b3)[![SAIA Workflow](https://docs.hpc.gwdg.de/img/services/arch-diagram.png)](javascript:history.back();)

## API Request

If a user has an API key, they can use the available models from within their terminal or python scripts.
To get access to an API key, go to the [KISSKI LLM Service page](https://kisski.gwdg.de/en/leistungen/2-02-llm-service) and click on “Book”.
There you will find a form to fill out with your credentials and intentions with the API key.
Please use the same email address as is assigned to your AcademicCloud account. Once received,
DO NOT share your API key with other users!

[![API Booking](https://docs.hpc.gwdg.de/img/services/api-booking.png)](https://docs.hpc.gwdg.de/services/saia/index.html#R-image-2a84b1d91c5408b46001e998382786a8)[![API Booking](https://docs.hpc.gwdg.de/img/services/api-booking.png)](javascript:history.back();)

## API Usage

The API service is compatible with the OpenAI [API standard](https://platform.openai.com/docs/api-reference/chat).
We provide the following endpoints:

- `/chat/completions`
- `/completions`
- `/embeddings`
- `/models`
- `/documents`

## API Minimal Example

You can use your API key to access Chat AI directly from your terminal. Here is an example of how to do text completion with the API.

```bash copy-to-clipboard-code copy-to-clipboard
curl -i -X POST \
  --url https://chat-ai.academiccloud.de/v1/completions \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <api_key>' \
  --header 'Content-Type: application/json'\
  --data '{
  "model": "meta-llama-3.1-8b-instruct",
  "messages":[{"role":"system","content":"You are an assistant."},{"role":"user","content":"What is the weather today?"}],
  "max_tokens": 7,
  "temperature": 0.5,
  "top_p": 0.5
}'
```

Ensure to replace `<api_key>` with your own API key.

## API Model Names

For more information on the respective models see the [model list](https://docs.hpc.gwdg.de/services/chat-ai/models/index.html).

| Model Name | Capabilities |
| --- | --- |
| meta-llama-3.1-8b-instruct | text |
| meta-llama-3.1-8b-rag | text, arcana |
| llama-3.1-sauerkrautlm-70b-instruct | text, arcana |
| llama-3.3-70b-instruct | text |
| gemma-3-27b-it | text, image |
| mistral-large-instruct | text |
| qwen3-32b | text |
| qwen3-235b-a22b | text |
| qwen2.5-coder-32b-instruct | text, code |
| codestral-22b | text, code |
| internvl2.5-8b | text, image |
| qwen-2.5-vl-72b-instruct | text, image |
| qwq-32b | reasoning |
| deepseek-r1 | reasoning |
| e5-mistral-7b-instruct | embeddings |

A complete up-to-date list of available models can be retrieved via the following command:

```bash copy-to-clipboard-code copy-to-clipboard
  curl -X POST \
  --url https://chat-ai.academiccloud.de/v1/models \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <api_key>' \
  --header 'Content-Type: application/json'
```

## API Usage

The OpenAI (external) models are not available for API usage. For configuring your own requests in greater detail, such as setting the `frequency_penalty`, `seed`,`max_tokens` and more, refer to the [openai API reference page](https://platform.openai.com/docs/api-reference/chat).

### Chat

It is possible to import an entire conversation into your command. This conversation can be from a previous session with the same model or another, or between you and a friend/colleague if you would like to ask them more questions (just be sure to update your system prompt to say “You are a friend/colleague trying to explain something you said that was confusing”).

```bash copy-to-clipboard-code copy-to-clipboard
curl -i -N -X POST \
  --url https://chat-ai.academiccloud.de/v1/chat/completions \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <api_key>' \
  --header 'Content-Type: application/json'\
  --data '{
  "model": "meta-llama-3.1-8b-instruct",
  "messages": [{"role":"system","content":"You are a helpful assistant"},{"role":"user","content":"How tall is the Eiffel tower?"},{"role":"assistant","content":"The Eiffel Tower stands at a height of 324 meters (1,063 feet) above ground level. However, if you include the radio antenna on top, the total height is 330 meters (1,083 feet)."},{"role":"user","content":"Are there restaurants?"}],
  "temperature": 0
}'
```

For ease of usage, you can access the Chat AI models by executing a Python file, for example, by pasting the below code into the file.

```python copy-to-clipboard-code copy-to-clipboard
from openai import OpenAI

# API configuration
api_key = '<api_key>' # Replace with your API key
base_url = "https://chat-ai.academiccloud.de/v1"
model = "meta-llama-3.1-8b-instruct" # Choose any available model

# Start OpenAI client
client = OpenAI(
    api_key = api_key,
    base_url = base_url
)

# Get response
chat_completion = client.chat.completions.create(
        messages=[{"role":"system","content":"You are a helpful assistant"},{"role":"user","content":"How tall is the Eiffel tower?"},{"role":"assistant","content":"The Eiffel Tower stands at a height of 324 meters (1,063 feet) above ground level. However, if you include the radio antenna on top, the total height is 330 meters (1,083 feet)."},{"role":"user","content":"Are there restaurants?"}],
        model= model,
    )

# Print full response as JSON
print(chat_completion) # You can extract the response text from the JSON object
```

In certain cases, a long response can be expected from the model, which may take long with the above method, since the entire response gets generated first and then printed to the screen. Streaming could be used instead to retrieve the response proactively as it is being generated.

```python copy-to-clipboard-code copy-to-clipboard
from openai import OpenAI

# API configuration
api_key = '<api_key>' # Replace with your API key
base_url = "https://chat-ai.academiccloud.de/v1"
model = "meta-llama-3.1-8b-instruct" # Choose any available model

# Start OpenAI client
client = OpenAI(
    api_key = api_key,
    base_url = base_url
)

# Get stream
stream = client.chat.completions.create(
    messages=[\
        {\
            "role": "user",\
            "content": "Name the capital city of each country on earth, and describe its main attraction",\
        }\
    ],
    model = model ,
    stream = True
)

# Print out the response
for chunk in stream:
    print(chunk.choices[0].delta.content or "", end="")
```

If you use [Visual Studio Code](https://code.visualstudio.com/download) or [Jetbrains](https://www.jetbrains.com/idea/download/?fromIDE=&section=linux) as your IDE, the recommended way to maximise your API key ease of usage, particularly for code completion, is to install the Continue plugin and set the configurations accordingly. Refer to [CoCo AI](https://docs.hpc.gwdg.de/services/coco/index.html) for further details.

### Image

The API specification is compatible with the [OpenAI Image API](https://platform.openai.com/docs/guides/vision).
However, fetching images from the web is not supported and must be uploaded as part of the requests.

See the following minimal example in Python.

```python copy-to-clipboard-code copy-to-clipboard
import base64
from openai import OpenAI

# API configuration
api_key = '<api_key>' # Replace with your API key
base_url = "https://chat-ai.academiccloud.de/v1"
model = "internvl2.5-8b" # Choose any available model

# Start OpenAI client
client = OpenAI(
    api_key = api_key,
    base_url = base_url,
)

# Function to encode the image
def encode_image(image_path):
  with open(image_path, "rb") as image_file:
    return base64.b64encode(image_file.read()).decode('utf-8')

# Path to your image
image_path = "test-image.png"

# Getting the base64 string
base64_image = encode_image(image_path)

response = client.chat.completions.create(
  model = model,
  messages=[\
    {\
      "role": "user",\
      "content": [\
        {\
          "type": "text",\
          "text": "What is in this image?",\
        },\
        {\
          "type": "image_url",\
          "image_url": {\
            "url":  f"data:image/jpeg;base64,{base64_image}"\
          },\
        },\
      ],\
    }\
  ],
)
print(response.choices[0])
```

### Embeddings

Embeddings are only available via the API and support the same API as the [OpenAI Embeddings API](https://platform.openai.com/docs/guides/embeddings).

See the following minimal example.

```bash copy-to-clipboard-code copy-to-clipboard
curl https://chat-ai.academiccloud.de/v1/embeddings \
  -H "Authorization: Bearer <api_key>" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "The food was delicious and the waiter...",
    "model": "e5-mistral-7b-instruct",
    "encoding_format": "float"
  }'
```

See the following code example for developing RAG applications with llamaindex:
[https://gitlab-ce.gwdg.de/hpc-team-public/chat-ai-llamaindex-examples](https://gitlab-ce.gwdg.de/hpc-team-public/chat-ai-llamaindex-examples)

### RAG/Arcanas

[Arcanas](https://docs.hpc.gwdg.de/services/arcana/index.html) are also accessible via the API interface. A minimal example using `curl` is this one:

```bash copy-to-clipboard-code copy-to-clipboard
curl -i -X POST \
  --url https://chat-ai.academiccloud.de/v1/chat/completions \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <api_key>' \
  --header 'Content-Type: application/json'\
  --data '{
  "model": "meta-llama-3.1-8b-rag",
  "messages":[{"role":"system","content":"You are an assistant."},{"role":"user","content":"What is the weather today?"}],
  "arcana" : {
      "id": "<the Arcana ID>",
      "key": "<the Arcana Key>"
      },
  "temperature": 0.0,
  "top_p": 0.05
}'
```

### Docling

SAIA provides [Docling](https://docling-project.github.io/docling/) as a service via the API interface on this endpoint:

```copy-to-clipboard-code copy-to-clipboard
https://chat-ai.academiccloud.de/v1/documents
```

A minimal example using curl is:

```bash copy-to-clipboard-code copy-to-clipboard
curl -X POST "https://chat-ai.academiccloud.de/v1/documents/convert" \
    -H "accept: application/json" \
    -H 'Authorization: Bearer <api_key>' \
    -H "Content-Type: multipart/form-data" \
    -F "document=@/path/to/your/file.pdf"
```

The result is a JSON response like:

```json copy-to-clipboard-code copy-to-clipboard
{
  "response_type": "MARKDOWN",
  "filename": "example_document",
  "images": [\
    {\
      "type": "picture",\
      "filename": "image1.png",\
      "image": "data:image/png;base64, xxxxxxx..."\
    },\
    {\
      "type": "table",\
      "filename": "table1.png",\
      "image": "data:image/png;base64, xxxxxxx..."\
    }\
  ],
  "markdown": "#Your Markdown File",
}
```

To extract only the “markdown” field from the response, you can use the `jq` tool in the command line (can be installed with `sudo apt install jq`). You can also store the output in a file by simply appending `> <output-file-name>` to the command.

Here is an example to convert a PDF file to markdown and store it in `output.md`:

```bash copy-to-clipboard-code copy-to-clipboard
curl -X POST "https://chat-ai.academiccloud.de/v1/documents/convert" \
    -H "accept: application/json" \
    -H 'Authorization: Bearer <api_key>' \
    -H "Content-Type: multipart/form-data" \
    -F "document=@/path/to/your/file.pdf" \
    | jq -r '.markdown' \
    > output.md
```

You can use advanced settings in your request by adding query parameters:

| Parameter | Values | Description |
| --- | --- | --- |
| `response_type` | `markdown`, `html`, `json` or `tokens` | The output file type |
| `extract_tables_as_images` | `true` or `false` | Whether tables should be returned as images |
| `image_resolution_scale` | `1`, `2`, `3`, `4` | Scaling factor for image resolution |

For example, in order to extract tables as images, scale image resolution by 4, and convert to HTML, you can call:

```copy-to-clipboard-code copy-to-clipboard
https://chat-ai.academiccloud.de/v1/documents/convert?response_type=json&extract_tables_as_images=false&image_resolution_scale=4
```

which will result in an output like:

```json copy-to-clipboard-code copy-to-clipboard
{
  "response_type": "HTML",
  "filename": "example_document",
  "images": [\
    ...\
  ],
  "html": "#Your HTML data",
}
```

## Developer reference

The GitHub repositories [SAIA-Hub](https://github.com/gwdg/saia-hub), [SAIA-HPC](https://github.com/gwdg/saia-hpc) and of [Chat AI](https://github.com/gwdg/chat-ai) provide all the components for the architecture in the diagram above.

## Further services

If you have more questions, feel free to contact us at [support@gwdg.de](mailto:support@gwdg.de).

