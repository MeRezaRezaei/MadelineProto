# madeline-mcp

MCP (Model Context Protocol) stdio server exposing **every** Telegram bot and
user method through MadelineProto. Connect it to Claude Code, Cursor, or any
MCP-capable AI client.

## Setup

1. Install MadelineProto dependencies at the repo root:
   `composer install`
2. Export your Telegram API credentials:
   - `API_ID` — app api_id (required)
   - `API_HASH` — app api_hash (required)
   - `BOT_TOKEN` — optional bot token; if present, auto-logs in the bot
   - `SESSION` — optional session file name (default `madeline-mcp`)
   - `LOG_LEVEL` — optional MadelineProto log level
3. Run the server:
   `php madeline-mcp/bin/madeline-mcp`

For a user account, log in interactively on first run (QR code or phone code);
the session is persisted in the session file.

## MCP wiring

The transport is newline-delimited JSON-RPC 2.0 over stdio, so any MCP client
can launch it directly:

```json
{
  "mcpServers": {
    "telegram": {
      "command": "php",
      "args": ["/absolute/path/to/madeline-mcp/bin/madeline-mcp"],
      "env": {
        "API_ID": "12345",
        "API_HASH": "abcdef",
        "BOT_TOKEN": "123:ABC"
      }
    }
  }
}
```

## Tools

High-level, ergonomic tools:

| Tool | Purpose |
| --- | --- |
| `get_login_state` | Login state and account info |
| `get_me` | Logged-in account details |
| `list_dialogs` | Recent chats (peer ids) |
| `send_message` | Send text to any peer |
| `read_history` | Recent messages of a chat |
| `resolve_peer` | Resolve id / username / @username / phone |
| `search_messages` | Full-text search inside a chat |
| `get_full_chat_info` | Chat metadata |

Raw layer — covers the entire MTProto surface (~799 methods):

| Tool | Purpose |
| --- | --- |
| `list_methods` | Dump the full method catalog with parameter shapes; optional `namespace` filter (`messages`, `users`, `account`, ...) |
| `call_method` | Call **any** method by dotted name, e.g. `messages.sendMessage`, `users.getFullUser`, `account.updateProfile` |

Example `call_method` invocation:

```
call_method {"method": "messages.sendMessage", "args": {"peer": "@user", "message": "hi"}}
```

## Tests

```
vendor/bin/phpunit -c madeline-mcp/phpunit.xml
```

Integration tests run only when `API_ID` and `API_HASH` are set.
