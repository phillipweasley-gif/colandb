# SEO and AI-search setup (colandb.com)

Set up 2026-10-22 at the owner's request. Everything here is on live.

## Tools
- **Yoast SEO (free)** handles titles, descriptions, share previews, organization data, the sitemap (`/sitemap_index.xml`) and `/llms.txt`.
- **Google Site Kit** connects **Search Console only** (plus PageSpeed Insights). The sitemap was submitted there on 2026-10-22.
- **No Google Analytics, or any other analytics.** The Privacy Policy promises "No analytics". Site Kit turned Analytics on when it was first connected, and the owner disconnected it. Don't turn it on unless the owner first changes the Privacy Policy and adds consent.

## Settings (backups in options `_colandb_backup_*_20261022`)
- **Home page** (page 5): Yoast title "Central Ohio Leather & Beyond (COL&B) – Columbus, Ohio" and the owner-approved description.
- **Organization:** "Central Ohio Leather & Beyond", alternate name "COL&B", logo attachment 64.
- **Hidden from search** (Yoast noindex, so also out of the sitemap):
  - Log In (586), Create an Account (595), Calendar Admin (605), Manage My Submission (615) and Meeting Notes Admin (1069);
  - the member page, through code in CMP 0.38.1.
- **Archives:** author and date archives are off, and `llms.txt` is on.
- **Event descriptions:** event pages use the event excerpt (`metadesc-cec_event` = `%%excerpt%%`). The Hello Elementor theme's own description tag is off (`hello_elementor_settings_description_meta_tag`), so there's only one description.
- **`robots.txt`** is a real file on the server. It has a `Sitemap:` line pointing to `/sitemap_index.xml`.
- **Stray page:** the empty page 795 ("https://colandb.com/privacy-policy/") is a draft.

## Usernames
CMP 0.38.1 (`CMP_Username_Privacy`) hides account usernames from visitors and members:
- no users or author sitemap;
- `/author/` pages and `?author=` show "not found";
- `/wp-json/wp/v2/users` is closed to them;
- oEmbed carries no author name.

Tested by `tests/seo-privacy-e2e.sh`, which runs with and without Yoast.

## Checking it
- **Read-only checks:** view the page source for `<title>`, `meta name="description"` and `og:` tags, and the `yoast-schema-graph` block. Also load `/sitemap_index.xml`, `/llms.txt` and `/robots.txt`.
- **Google's Rich Results Test** on an event page should find an Event.
- **Search Console:** the Pages and Performance reports. Bing Webmaster Tools is not set up yet.
