# Claude connection for colandb.com (MCP server): plan and decisions

Approved by the owner on 2026-10-09 (all recommendations below). Working copy with comments: the "colandb MCP server plan" doc in the project.

## What it is

A plugin, **COL&B Claude Connector** (`colandb-mcp/`), that registers colandb's own WordPress abilities and serves them as a curated MCP server at `/wp-json/colandb/mcp`, separate from Elementor's MCP server. Claude signs in as a dedicated low-privilege WordPress user with a revocable application password kept in the Claude environment's secrets, never in this repository. It ships like every other plugin: pre-release to staging, live only when the owner promotes it.

## Why

On 2026-10-04 the only connection was Elementor's MCP server on **live**, signed in as an administrator account (45 abilities: page and style editing, publishing, snippets, file reads, and PHP execution, which was switched off). No colandb data (events, updater state) was reachable as tools.

## Tools

| Tool | Does | Phase |
|---|---|---|
| `list_events` | Published events in a date range, search and filters | 1 |
| `get_event` | One published event with its description | 1 |
| `list_pending_events` | Pending / In Review submissions (Drafts on request), no contact details | 1 |
| `site_health` | Plugin versions, updater channel and last check, WordPress/PHP | 1 |
| `create_event_draft`, `update_event_draft` | Drafts only, for a person to publish | 2 (needs approval) |
| `updater_check` | "Check for updates now", staging only | 2 (needs approval) |

Never exposed: member data, RSVPs/volunteers/subscribers, publishing, deleting, users, plugins, settings, PHP execution.

## Decisions

| # | Decision | Outcome |
|---|---|---|
| M1 | Phase 1 is the four read-only tools | Approved |
| M2 | Own MCP server, not abilities on Elementor's server | Approved |
| M3 | One self-contained zip, nothing else to install on the sites | Approved. Implemented as a small built-in MCP server instead of bundling the WordPress MCP Adapter library, because the adapter's install guide advises against bundling it (Elementor ships its own copy, and the classes would clash). |
| M4 | Dedicated `claude-agent` user ("Claude Agent" role) with an application password; OAuth only if wanted later for claude.ai chat | Approved |
| M5 | Move the Elementor connection off the owner's administrator account and point it at staging by default | Recommended; separate from this work, owner's call |

## Connecting (owner)

1. Upload `dist/colandb-mcp-<version>.zip` once on staging (Plugins → Add New → Upload) and activate it. After that the COL&B Plugin Updater keeps it current.
2. Users → Add New: `claude-agent`, role **Claude Agent**. On that user, add an application password named `Claude`.
3. In the Claude Code environment's settings, add the secret `COLANDB_STG_AUTH` = base64 of `claude-agent:<application password>`. `.mcp.json` already has a `colandb-staging` entry that uses it.
4. To cut access: revoke that application password. Tools → Claude Connector shows every tool call.

Live comes later, after the owner promotes the release and creates a live `claude-agent` user (`COLANDB_LIVE_AUTH`, plus a `colandb-live` entry in `.mcp.json`).
