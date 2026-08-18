=== Compact IMG: Compress & Convert Images to WebP/AVIF ===
Contributors: compactimg
Donate link: https://compactimg.com/
Tags: image optimization, webp, avif, image compression, convert images, media library, page speed, webp converter, avif converter, seo
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.2
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Optimize Media Library images as WebP, AVIF, or JPG, compare storage savings, and optionally delete original files. 100% free and on-server.

== Description ==

Compact IMG is a powerful, 100% free, on-server WordPress image optimization and format conversion plugin developed by CompactIMG.com ( https://compactimg.com/ ).

It converts raster images (PNG, JPEG/JPG, GIF) in your WordPress Media Library to modern next-generation formats (WebP, AVIF, or optimized JPG). It processes the full upload hierarchy including the primary upload, all theme and plugin registered thumbnail sizes, and WordPress pre-scaled "-scaled" originals.

### Why Use Compact IMG?

* 100% Free and Unlimited: No subscriptions, no credit cards, no monthly quotas, and no API keys required.
* On-Server Local Processing: Uses your server's native GD or ImageMagick (Imagick) library. Your images never leave your server, ensuring complete data privacy and zero latency.
* Next-Gen WebP and AVIF Support: Reduce image file sizes by 30% to 90% without visible quality loss, boosting Google Core Web Vitals, PageSpeed Insights scores, and SEO rankings.
* Non-Destructive and Safe: Retains source files and only updates attachment pointers once all image sizes have been successfully generated.
* Resumable Database URL Migration: Multi-stage background updater replaces image URLs in posts, pages, excerpts, comments, post meta, and options while safely preserving serialized data.
* Storage Savings Dashboard: Live before-and-after metrics calculate disk space savings in Megabytes (MB) and percentage (%).
* Safe Stop and Resume: Pause optimization anytime between batches and resume seamlessly without reprocessing already converted images.
* Optional Original File Cleanup: Once your site is verified, optionally clean up retained original files to reclaim hosting disk space.

Visit the official website at https://compactimg.com/ for more tools and documentation.

---

== Key Features & Functions ==

### 1. Next-Generation Format Conversion (WebP, AVIF, JPG)
* WebP Conversion: Generates lightweight WebP images supported by over 97% of modern browsers, achieving 25% to 45% smaller file sizes than JPEG/PNG.
* AVIF Conversion: Generates cutting-edge AVIF images offering superior compression (up to 50% smaller than WebP) with crisp fidelity when supported by your host's ImageMagick/GD.
* JPG Optimization: Re-compresses standard JPEG images with customizable quality targets for legacy workflows.
* Quality Slider (40-100): Fine-tune your preferred balance between compression ratio and visual fidelity (recommended default: 82).

### 2. Comprehensive Media and Thumbnail Coverage
* Converts the original master image.
* Converts every registered intermediate size (thumbnail, medium, medium_large, large, plus all custom sizes registered by themes and plugins).
* Converts WordPress "-scaled" full-size images while maintaining correct relationship metadata in wp_get_attachment_metadata.

### 3. Adaptive Batch Processing Engine
* Automatically adapts batch chunk sizes (1 to 8 attachments per AJAX cycle) to prevent PHP execution timeouts and memory limit exhaustion.
* Employs transient locking (_wmfc_processing_lock) to protect against concurrent duplicate execution.

### 4. Resumable Database URL Migration
* Scans and updates image URLs across 7 database stages:
  1. posts.post_content
  2. posts.post_excerpt
  3. postmeta.meta_value (with safe serialization handling)
  4. comments.comment_content
  5. options.option_value (with safe serialization handling)
  6. Final state cleanup
* Processes rows in batches (default: 1,000 rows per cycle) and tracks table offsets so operations can resume seamlessly if interrupted.

### 5. Disk Space and Storage Analytics
* Real-time counters display:
  - Total number of optimized images
  - Original combined storage size (MB)
  - Optimized combined storage size (MB)
  - Net disk space saved (MB and %)

### 6. Safe and Confirmed Original Cleanup
* Retains original file backups in attachment metadata (_wmfc_original_files).
* Deletion of source files is completely optional and locked until database URL updates finish.
* Requires explicit administrator confirmation.

### 7. Privacy and Security Built-in
* Zero third-party API calls.
* Zero user tracking or telemetry.
* Strict WordPress nonce validation and capability checks (manage_options and upload_files).
* Sanitized inputs, prepared SQL statements, and native WordPress filesystem abstractions.

---

== Installation ==

### Automatic Installation
1. Log in to your WordPress Dashboard.
2. Go to Plugins > Add New.
3. Search for Compact IMG or click Upload Plugin and select compact-img-compress-convert-images-to-webpavif.zip.
4. Click Install Now and then Activate.

### Manual Installation
1. Download compact-img-compress-convert-images-to-webpavif.zip from https://compactimg.com/ or GitHub.
2. Unzip the file and upload the compact-img-compress-convert-images-to-webpavif folder to the /wp-content/plugins/ directory.
3. Activate the plugin through the Plugins menu in WordPress.

### How to Use Compact IMG
1. Navigate to Tools > Compact IMG in your WordPress admin menu.
2. Select your target format (WebP, AVIF, or JPG) and adjust the Image Quality slider (82 recommended).
3. Click Scan Images to analyze your Media Library.
4. Click Optimize Images to begin conversion. Keep the browser tab open while batches run.
5. Allow the automated Image Link Update to finish updating your database references.
6. Review your website frontend to confirm image display.
7. (Optional) Click Delete Retained Originals if you wish to reclaim server disk space.

---

== Frequently Asked Questions ==

= Does Compact IMG require any external service or API key? =
No. Compact IMG runs 100% locally on your WordPress hosting server using GD or ImageMagick (Imagick). No API keys, subscriptions, or external accounts are needed.

= Are original source files deleted automatically? =
No. Compact IMG retains your original source files safely on your server. Original files are only removed if you explicitly trigger the "Delete Retained Originals" cleanup action.

= Can I pause and resume image optimization? =
Yes. You can click "Stop safely" at any time. When you click "Optimize Images" again, Compact IMG automatically skips attachments that have already been converted to the target format.

= What happens to image links inside blog posts and pages? =
After image conversion finishes, Compact IMG runs an automated database migration pass to update old image URLs (.jpg, .png) to the new format (.webp, .avif) across posts, pages, excerpts, comments, and meta.

= Does converting PNG to JPG preserve transparency? =
No. The JPG format does not support transparency. If you have transparent PNG images, we recommend converting them to WebP or AVIF to maintain full alpha transparency while reducing file size.

= What happens to animated GIFs? =
Animated GIFs are automatically detected and skipped to prevent losing animation frames during conversion.

= What are the server requirements? =
* WordPress 6.5 or higher (tested up to 7.0)
* PHP 7.4 or higher (PHP 8.0, 8.1, 8.2, 8.3 fully supported)
* PHP GD extension or ImageMagick (Imagick) extension with WebP/AVIF support enabled.

= Is Compact IMG free? =
Yes! Compact IMG is completely free and open-source under the Apache License 2.0. Learn more at https://compactimg.com/.

---

== Screenshots ==

1. Optimization Dashboard: Select target format, adjust quality, scan library, and monitor real-time compression progress with live storage savings metrics.

---

== Changelog ==

= 1.6.2 =
* Added Apache-2.0 open source license documentation and attribution to CompactIMG.com.
* Added translator guidance for progress messages containing placeholders.
* Enhanced nonce verification before every AJAX request.
* Replaced direct GIF stream reads with WordPress filesystem API.
* Reworked stored URL migration into explicit, prepared, allowlisted database stages.
* Replaced direct post and comment writes with native WordPress update APIs.
* Added Plugin Check compliance documentation for uncached migration queries.

= 1.6.1 =
* Aligned plugin directory slug, main filename, and text domain for WordPress.org standards.

= 1.6.0 =
* Renamed plugin to Compact IMG: Compress & Convert Images to WebP/AVIF.
* Updated package slug, text domain, admin labels, and artwork assets.

= 1.3.0 =
* Added AVIF as a fully validated output format.
* Refreshed interface with improved metrics display and responsive progress meters.

= 1.0.0 =
* Initial release with WebP conversion, adaptive batching, and resumable URL rewriting.

== Upgrade Notice ==

= 1.6.2 =
Updated with Apache 2.0 license, official CompactIMG.com links, and hardened database migration handlers.
