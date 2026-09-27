=== Kreativ Broken Image Finder ===
Contributors: anolaru
Tags: broken images, 404, seo, media, maintenance
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan your WordPress site for broken images in post content and featured images directly from the admin dashboard.

== Description ==

Kreativ Broken Image Finder scans your posts, pages and custom post types for broken images and provides a clear report directly in your WordPress dashboard.

Missing or broken images can negatively affect user experience and SEO. This plugin helps you quickly identify:

* Images that return 404 or other HTTP errors
* Broken image URLs inside post content
* Broken featured images on posts and pages

No external services, no tracking, no bloat - everything runs locally on your server using WordPress core functions.

== Features ==

* Scan all public post types (posts, pages, custom post types)
* Scan Media Library image files and generated sizes for missing local files
* Detect broken images inside post content
* Detect images in `srcset`, lazy-load attributes and CSS `url(...)` references
* Detect broken featured images
* Cache repeated image URL checks during a scan for faster results
* Optional missing featured image reporting by post type
* Ignore specific image URLs, domains, posts or media attachments
* Recheck a single affected item after fixing it
* Export the current filtered report to CSV
* WP-CLI support with `wp kbif scan`
* Summary with items scanned, images/files checked, broken images found and scan time
* Detailed report with direct links to edit affected posts
* Supports relative and protocol-relative image URLs
* Lightweight and GDPR-friendly (no cookies, no external API calls)

== Installation ==

1. Upload the plugin ZIP via *Plugins -> Add New -> Upload Plugin*.
2. Activate **Kreativ Broken Image Finder**.
3. Go to **Kreativ Broken Image Finder** in the WordPress admin menu.
4. Optionally configure the missing featured image rule for your post types.
5. Click **Run Full Scan** and wait for the results.

== Frequently Asked Questions ==

= Does this plugin automatically fix broken images? =

No. The plugin only detects and reports broken images. You can then edit the affected posts and fix or replace the image URLs.

= How does the plugin detect broken images? =

It scans `<img>` tags in post content and checks their `src` URLs using WordPress HTTP requests, with fallback handling for servers that do not respond properly to `HEAD`. It also checks featured image URLs.

= Can I control missing featured image reporting? =

Yes. You can choose whether missing featured images are reported for all post types, no post types, or only selected post types from the plugin settings screen.

= Does it support WP-CLI? =

Yes. You can run a full scan from the command line with `wp kbif scan`. You can also control the batch size with `wp kbif scan --batch-size=10`.

= Does it use any external services or APIs? =

No. All checks are performed locally using WordPress core HTTP functions.

= Will it slow down my site? =

The scan runs only when manually triggered from the admin dashboard and does not affect front-end visitors. On very large sites, the scan may take longer, but it runs in small batches to avoid timeouts.

== Screenshots ==

1. Admin page showing the scan summary and broken images report.

== Upgrade Notice ==

= 1.3.0 =
Adds expanded image detection, Media Library file checks, ignore rules, single-item recheck actions, and CSV export.

== Changelog ==

= 1.3.0 =
* Added detection for `srcset`, lazy-load image attributes, `<source>` images and CSS `url(...)` references.
* Added Media Library integrity checks for missing original files and generated image sizes.
* Added ignore rules for image URLs, domains, posts and media attachments.
* Added per-item recheck actions in the report table.
* Added CSV export for the current filtered report.

= 1.2.3 =
* Added per-scan URL caching to avoid repeating the same remote image checks across posts.
* Added admin settings to control missing featured image reporting by post type.
* Added a `wp kbif scan` command with configurable batch size.

= 1.2.2 =
* Added GET fallback when image hosts reject HEAD requests, reducing false positives.
* Improved support for document-relative image paths in post content.
* Reworked scan batching to avoid loading all published post IDs into a single option during scan startup.

= 1.2.1 =
* Updated WordPress.org readme metadata and release notes.
* Synced plugin asset versioning with the current plugin version.
* Added WordPress.org-standard banner and icon asset variants.

= 1.2.0 =
* Added top-level admin menu for easier access.
* Added filters and pagination to the results table.
* Improved handling of relative and protocol-relative image URLs.
* Improved scan stability using background batch processing.
* Security hardening for admin actions and AJAX requests.
* General UI and performance improvements.

= 1.1.0 =
* Improved scan reliability on larger sites.
* Added scan progress tracking and summary statistics.
* Improved detection of broken featured images.
* Internal performance optimizations.

= 1.0.0 =
* Initial version.
* Scan posts, pages and custom post types for broken images in content and featured images.
