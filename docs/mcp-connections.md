# MCP connections

`.mcp.json` connects Claude Code to Elementor's MCP endpoint on each site. Credentials never live in this repository; each entry reads its `Authorization` header from an environment variable.

| Server | Site | Environment variable |
|---|---|---|
| `central-ohio-leather-beyond-elementor` | colandb.com (live) | `COLANDB_WP_AUTH` |
| `central-ohio-leather-beyond-elementor-staging` | stg-mtbrmv.elementor.cloud (staging) | `COLANDB_STG_WP_AUTH` |

Each variable holds the base64 of `username:application-password` for a WordPress user on that site (Users → Profile → Application Passwords). Create a separate application password on staging; don't reuse the live one.

```sh
printf '%s' 'username:xxxx xxxx xxxx xxxx xxxx xxxx' | base64
```

Set the variables in your shell or in the Claude Code cloud environment's secrets, never in a committed file. Try changes on staging first; colandb.com is production (see `CLAUDE.md`).
