# Changelog — COL&B Claude Connector

Lets Claude work with the site through a small set of tools served as an MCP server. Plan: `docs/mcp-server-plan.md`. Every release: bump `Version:` and `COLANDB_MCP_VERSION`, add an entry here, and run `tests/mcp-e2e.sh`.

---

## 0.1.0

**Phase 1: read-only tools.** Built to the plan Phil approved on 2026-10-09 (decisions M1–M4).
- **MCP server at `/wp-json/colandb/mcp`** (Streamable HTTP, stateless JSON responses; protocol versions 2024-11-05 to 2025-11-25). Implements initialize, ping, tools/list and tools/call. It is written in the plugin rather than bundling the WordPress MCP Adapter library: the adapter's own install guide advises against bundling because other plugins ship their own copy (Elementor does on this site) and the classes clash. The plan's decision M3 ("one zip, nothing else installed") still holds.
- **Four tools**, registered as WordPress abilities with input/output schemas: `list_events` (published events in a date range, with search and type/venue/partner filters), `get_event` (one published event with its description), `list_pending_events` (Pending and In Review submissions, Drafts on request; title, dates, status, submitter's display name and the wp-admin review link, never contact details) and `site_health` (colandb plugin versions, updater channel and last check without the token, WordPress/PHP versions). Events are read through `cec_get_public_event(s)`; no member data is read. The abilities are not marked public, so Elementor's MCP server and the core abilities REST API don't offer them.
- **"Claude Agent" role** (`colandb_agent`): `read` plus the connector's three capabilities (`colandb_mcp_connect`, `colandb_mcp_view_pending_events`, `colandb_mcp_view_site_health`). Administrators get the same capabilities. Claude signs in as its own user with an application password; that password can't edit, publish or read private content anywhere else in the REST API either.
- **Tools → Claude Connector:** the server address, the setup steps, the Claude Agent users and their application passwords, and the last 50 tool calls (the log keeps 200: time, user, tool, input, outcome).
- Registers itself with the COL&B Plugin Updater (`colandb_updater_plugins` filter), so once installed it updates like the other plugins. Uninstalling removes the role, capabilities and log.
- **Verified** in the local WordPress 7.1.3 test site: `tests/mcp-e2e.sh` 61/61 (who may connect, the role's reach in core REST, protocol errors, each tool's results and refusals, per-tool capabilities, log, updater registration). Also connected with the official MCP TypeScript SDK client (1.32.1): initialize, tools/list and three tool calls.
