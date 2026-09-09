# Feature Unique

A WordPress plugin that flags when an image is used as the featured image on more than one post, so you can catch accidental duplicates.

Scope is limited to the `post` post type.

## What it does

- **Featured Image box (classic editor):** shows a warning under the Featured Image box, with links to every other post already using the selected image.
- **Featured Image panel (block editor):** same warning, rendered under the Featured Image panel in the sidebar once an image is set.
- **"Set/Replace Featured Image" picker (block editor):** badges reused thumbnails in the grid, and shows a full warning with linked posts in the details pane when you select one.
- **Media modal attachment details:** adds a "Featured Image Use" field to the details sidebar shown when browsing/inserting media.
- **Posts list table:** adds a "Featured Image" column — None / Unique / Duplicate (with links to the other posts).
- **Media Library list table:** adds a "Used as Featured Image On" column listing every post using that attachment as its featured image.
- **Tools → Find Duplicate Featured Images:** a report page listing every image used as a featured image on more than one post, with thumbnails and links.

## Installation

1. Copy this directory into `wp-content/plugins/`.
2. Activate **Feature Unique** from the Plugins screen in wp-admin.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## How it works

The plugin builds a per-request map of `attachment_id => posts using it as a featured image` (querying `_thumbnail_id` postmeta for the `post` post type, excluding trashed/auto-draft posts) and reuses that map everywhere above. No data is written or cached outside of a single request.
