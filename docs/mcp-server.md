# MCP server

SolidTime answers MCP clients (Claude, ChatGPT, Codex and others that speak
remote MCP) at `POST /mcp`: Streamable HTTP, stateless JSON, built on
[`laravel/mcp`](https://github.com/laravel/mcp). It is for the internal
network only.

## Access

- **Who**: every call needs a Passport bearer token (`auth:api`) of a
  verified user. Tools act as that user, with that user's role in the
  organization. Each tool sends its work through the regular `/api/v1` routes
  (`App\Mcp\InternalApi`), so permissions, validation, overlap checks and
  idempotency are exactly the API's.
- **Connecting**:
  - A personal API token (profile page) works as a bearer header.
  - OAuth clients register themselves at `POST /oauth/register`, then use
    Passport's consent screen and token endpoint. Discovery is at
    `/.well-known/oauth-protected-resource` and
    `/.well-known/oauth-authorization-server`.
  - Registered redirect URIs must be on `MCP_REDIRECT_DOMAINS`
    (default `http://localhost,http://127.0.0.1`).
- **Where**: `/mcp` and the OAuth registration and discovery routes answer 404
  unless every address the request carries is inside `MCP_ALLOWED_NETWORKS`
  (default loopback and private ranges). That covers the connecting address and
  each hop in `X-Forwarded-For`, `Forwarded` and `X-Real-IP`, whether or not
  the proxy is trusted. So a public client behind a reverse proxy that
  forwards its address is refused, even if it claims an internal one. Requests
  relayed by Cloudflare (`Cf-Ray` or `Cf-Connecting-Ip`) are refused outright.
  Do not publish `/mcp` through a tunnel, or through a proxy that does not
  forward the client's address.
- **On switch**: MCP is off until `MCP_ENABLED=true`.

## Tools

| Tool | Change |
| --- | --- |
| `get_context` | no |
| `list_time_entries` | no |
| `summarize_time` | no |
| `list_active_timers` | no |
| `list_projects` | no |
| `get_project` | no |
| `list_tasks` | no |
| `list_clients` | no |
| `list_members` | no |
| `list_tags` | no |
| `start_timer` | yes |
| `stop_timer` | yes |
| `create_time_entry` | yes |
| `update_time_entry` | yes |
| `delete_time_entry` | yes, destructive |
| `create_project` | yes |
| `update_project` | yes |
| `delete_project` | yes, destructive |
| `create_task` | yes |
| `update_task` | yes |
| `delete_task` | yes, destructive |

Read tools carry `readOnlyHint`. Creations, `start_timer` and `stop_timer`
carry `destructiveHint: false`. Updates and deletes keep MCP's default of
destructive. Stop and update tools carry `idempotentHint`.

**A change is two calls.**

1. Without `confirm`, a change tool answers with a preview (`summary`,
   `before`, `after`) and changes nothing.
2. Only the same call with `confirm: true` (the boolean) makes the change. The
   AI may set it only after the user approved that preview.

Creations accept an optional `request_id`. It is sent as the API's
`Idempotency-Key`, so a retried call returns the first result.

**Every call checks its arguments.**

- Unknown arguments are refused.
- The rest are validated before anything runs.

**Every call is logged** in `mcp_tool_calls`:

- who, which OAuth client and token, and which tool;
- the outcome (`ok`, `preview`, `error`, `denied` or `rate_limited`), the
  error and the duration;
- the arguments, redacted: identifiers, numbers, dates and settings are kept,
  and other text is stored as its length.

The row is written before the tool runs, so no change goes unlogged. Rows are
pruned after `MCP_AUDIT_RETENTION_DAYS` (default 180) by the daily
`model:prune`.

**Rate limits**, per user per minute:

- `MCP_READ_CALLS_PER_MINUTE`: read tool calls (default 120);
- `MCP_WRITE_CALLS_PER_MINUTE`: change tool calls (default 30);
- all MCP messages: 240.

## API additions used by MCP and the ERP

- `POST /v1/organizations/{organization}/idempotent-time-entries` requires an
  `Idempotency-Key`. A plain `POST .../time-entries` with the header behaves
  the same way: a retry with the same key and body replays the first
  response. This is the project and task mechanism
  (`ApiCreationIdempotency`).
- With a key, a timer (`end` null) may leave out `start`. It then starts when
  it is created, and a retry sends the same body, so it replays that timer.
- With `Idempotency-Replay-Only` as well, a keyed creation returns only an
  earlier result for that key and creates nothing (404 with
  `X-Solidtime-Idempotency: none`). A client that lost an answer uses it to
  recover a write it can no longer prepare afresh.
- A key reused with another body answers 409 with
  `X-Solidtime-Idempotency: mismatch`. Keys are unique per change, so this
  means the key's first request already succeeded.
- `GET /v1/organizations/{organization}/time-entries/{timeEntry}` returns one
  entry with an `ETag`:
  - your own entries need `time-entries:view:own`;
  - other members' entries need `time-entries:view:all`.
- `PUT .../time-entries/{timeEntry}` accepts the same headers, applied under a
  row lock:
  - `If-Match: <ETag>` refuses the update with 412 if the entry changed after
    it was read, rather than overwriting someone else's change;
  - `Idempotency-Key` replays the first response on retry.
