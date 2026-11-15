=== Headlark ===
Contributors: headlark
Tags: seo, meta description, open graph, schema, alt text
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-written titles, meta descriptions, link previews, structured data and image alt text, generated once when you publish.

== Description ==

Headlark reads what each post actually says and writes its SEO: the title, meta description, Open Graph and X (Twitter) cards, schema.org JSON-LD and alt text for images that have none.

It runs when you publish or update a post, not when someone visits. The result is stored with the post and printed in the page's HTML, so Google, Bing and the link previews of Facebook, LinkedIn, Slack and X all see it. Page views make no request to Headlark.

* Your canonical URL and robots settings are never changed.
* Alt text is only added where an image has none. Alt text you wrote stays.
* With Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework active, Headlark adds only the tags that plugin doesn't print, or no head tags at all if you prefer.
* Existing posts are processed in the background in small batches. Posts whose content hasn't changed cost nothing.

= External service =

This plugin sends the HTML of your published posts and their URL to Headlark (https://headlark.com) to generate the SEO tags, authenticated with your site's secret key. It does this when you publish or update a post, when you press "Regenerate", and once for existing posts after you save a key. Nothing is sent when visitors view your pages, and the plugin adds no tracking. Terms: https://headlark.com/terms. Privacy policy: https://headlark.com/privacy.

== Installation ==

1. Sign up at https://headlark.com/app, add your site's domain and copy its secret key.
2. Install and activate the plugin.
3. Go to Settings → Headlark, paste the key and save.

Headlark needs pretty permalinks (any structure other than "Plain").

== Frequently Asked Questions ==

= Does it slow down my site? =

No. Page views only read what is stored with the post. Generation runs in WP-Cron after you publish.

= New posts take a long time to get their tags. =

Generation runs in WP-Cron, which WordPress only runs while someone visits the site. On a quiet site, or with DISABLE_WP_CRON set and no system cron, it can wait for hours. Going through existing posts also continues while Settings → Headlark is open. Run WP-Cron from a real cron job every five minutes, for example `*/5 * * * * wp cron event run --due-now --path=/var/www/html --quiet`. Settings → Headlark shows "Waiting for WP-Cron" when jobs are overdue.

= I use a page cache. =

After storing a post's SEO, Headlark clears WordPress's post cache and asks WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache and Cloudflare (APO) to purge that post. Other caches can hook into the `headlark_seo_updated` action.

= My host blocks requests from the site to itself. =

Headlark then renders the post's content without the theme and sends that instead.

= What happens when I delete the plugin? =

Its settings, the key and every post's stored SEO are removed.

== Changelog ==

= 0.1.2 =
* Security: the generated title is escaped before WordPress prints it. WordPress prints a pre_get_document_title value as is, so 0.1.1 and older let a title containing markup into the page.

= 0.1.1 =
* Review mode: a post whose new SEO waits for approval keeps its approved SEO, and the approval reaches it within an hour.
* "Regenerate all" processes every post while Settings → Headlark is open.

= 0.1.0 =
* First release.
