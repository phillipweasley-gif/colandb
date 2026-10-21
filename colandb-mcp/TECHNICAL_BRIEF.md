# COL&B Claude Connector — Technical Brief

**Version documented:** 0.1.0
**Read first:** `docs/mcp-server-plan.md` (the approved plan and decisions M1–M5).

## 1. Boundaries

- **Read-only until the owner approves more.** Phase 2 (event drafts, staging updater check) needs its own approval. A new tool that changes anything must create drafts only, never publish, delete, or touch users, plugins or settings.
- **No member data.** Never read Community Member Planning tables, user meta or REST routes. Events are read through `cec_get_public_event()` / `cec_get_public_events()`; events that aren't public yet are read from the post itself, without submitter emails, guest-edit links or RSVP data.
- **No secrets in output.** `site_health` reports whether the updater has a token, never the token.
- **Least privilege.** Each tool checks its own capability. The `colandb_agent` role holds `read` plus the connector's capabilities only; don't add core capabilities such as `edit_posts` to it, because the same application password works across the whole REST API.

## 2. Layout

```
colandb-mcp/
├── colandb-mcp.php                       header, COLANDB_MCP_VERSION, bootstrap, updater filter
├── uninstall.php                         removes role, capabilities, log
└── includes/
    ├── class-colandb-mcp-role.php        colandb_agent role + capabilities (re-applied when the version changes)
    ├── class-colandb-mcp-abilities.php   the tools, as abilities (category "colandb")
    ├── class-colandb-mcp-server.php      MCP over HTTP at /wp-json/colandb/mcp
    ├── class-colandb-mcp-log.php         tool-call log (option colandb_mcp_log, newest 200)
    └── class-colandb-mcp-admin.php       Tools → Claude Connector
```

## 3. Adding a tool

1. Register the ability in `COLANDB_MCP_Abilities::register()` as `colandb/<verb-noun>` with an `input_schema` (type object, `additionalProperties: false`, a `default` of `array()` if every property is optional), an `output_schema`, a `permission_callback` that checks `COLANDB_MCP_Role::CAP_CONNECT` plus a specific capability, and `meta.annotations`. Don't set `meta.public` or `show_in_rest`.
2. Add its name to `COLANDB_MCP_Abilities::NAMES`. The MCP tool name is the part after `colandb/` with dashes as underscores.
3. A new capability goes in `COLANDB_MCP_Role` (constant + `caps()`) and in `uninstall.php`.
4. Add checks to `tests/mcp-e2e.sh`, including a refusal without the capability.

## 4. Protocol notes

- Stateless: no `Mcp-Session-Id`, no server-sent events. `GET`/`DELETE` return 405.
- Notifications get 202. Batches get -32600. WordPress itself answers malformed JSON with HTTP 400 before the server runs.
- Tool failures (bad input, not found, no permission) are tool results with `isError: true`, as the spec asks; unknown tools and methods are JSON-RPC errors.
- Empty JSON Schema `properties` are sent as `{}` (PHP would encode `[]`).

## 5. Testing

`WPTEST=<folder> bash tests/mcp-e2e.sh` after `tests/setup.sh` and `tests/seed.php` (needs `jq`). The test site is plain HTTP, so the script adds a must-use plugin that allows application passwords while it runs and removes it afterwards.
