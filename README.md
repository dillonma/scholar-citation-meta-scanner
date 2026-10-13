# Scholar Citation Meta Scanner

Finds every DOI on a post or page and exposes the full citations to Zotero as a pick-list (COinS). Off by default, enabled per page.

[![Latest release](https://img.shields.io/github/v/release/dillonma/scholar-citation-meta-scanner)](https://github.com/dillonma/scholar-citation-meta-scanner/releases/latest) ![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-blue) ![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

When this is switched on for a post or page, the plugin finds every DOI on it, looks each one up, and embeds the results as hidden COinS tags. The Zotero browser button then shows a folder icon. Clicking it opens a pick-list of every cited article, with full citations.

It's off by default and switched on per post or page. It works on its own or alongside Scholar Citation Meta.

## Install
1. Download `scholar-citation-meta-scanner-<version>.zip` from the [latest release](https://github.com/dillonma/scholar-citation-meta-scanner/releases/latest). Download the release asset, not GitHub's "Source code" archive.
2. In WordPress, go to Plugins → Add New → Upload Plugin, choose the zip, and activate. If an older copy is installed, choose **Replace current with uploaded**.
3. In the editor sidebar, open the **Zotero DOI list** box, tick **Scan this page for DOIs**, and click Update.
4. Reload the editor to see what was found, including any DOIs with no metadata. The page is scanned again every time you update it.

If [Scholar Citation Meta](https://github.com/dillonma/scholar-citation-meta) is active, a second option puts the page's own article first in the list (on by default).

## What it finds
- Links to `doi.org` / `dx.doi.org`
- Publisher links that contain a DOI (e.g. `tandfonline.com/doi/full/10.1080/...`)
- `doi:10.xxxx/...` and bare `10.xxxx/...` text
- Duplicates are removed, so the same DOI linked twice is listed once.
- Title slugs after a DOI in publisher links are dropped.
- If a book DOI and one of its chapter DOIs both appear (10.1201/9781003480167 and 10.1201/9781003480167-9), only the chapter is kept.

## Where the metadata comes from
1. Your own posts: if a post has this DOI in Scholar Citation Meta, its metadata is used, including any BibTeX fixes. It is re-read every time the page loads, so later fixes show up without rescanning.
2. Crossref.
3. DataCite's API, if Crossref has no record (Zenodo, ICPSR, figshare, and many OJS journals such as ACME).
4. `doi.org`, for any other registration agency (mEDRA, JaLC, ...).

Page fields that aren't page numbers (e.g. "Vol. 21 No. 4 (2022): ...") are dropped, and the volume and issue are taken from that text.

Lookups are cached for 30 days and shared across posts. Failed lookups are retried after 24 hours. Each save spends up to 15 seconds on new lookups; anything left over finishes in the background via WP-Cron, about 30 seconds later.

## How Zotero behaves
- Zotero prefers COinS over the single-article `citation_*` tags. On pages where the scanner is on, the browser button shows the list (with your article first, if that option is ticked).
- On pages where the scanner is off, Zotero still saves the single article.
- Google Scholar ignores COinS, so the `citation_*` tags still control Scholar.
- Zotero's COinS reader attaches a snapshot of the page to each saved item. Users can turn this off with the automatic-attachments option in Zotero's General settings.

## Settings (optional, wp-config.php)
    define( 'SCMS_POST_TYPES', 'post,page' );
    define( 'SCMS_MAX_DOIS', 200 );

## Updates
Version 1.4.0 and later update from this repository's Releases. When a new release is published, WordPress shows it on the Plugins and Updates screens, and you update with one click (or let auto-updates handle it). WordPress checks GitHub at most every 6 hours.

Versions before 1.4.0 don't have the updater, so upload 1.4.0 once by hand.

## Releasing a new version (maintainers)
1. Bump the version in the plugin header (`Version:`) and in `readme.txt` (`Stable tag:`), and add a changelog entry to `readme.txt`.
2. Commit and push, then create a release whose tag is `v<version>`, e.g. `gh release create v1.2.3 --generate-notes`.
3. The **Release** workflow checks that the tag matches both version numbers, builds `scholar-citation-meta-scanner-<version>.zip`, and attaches it to the release. Sites running the plugin will then see the update.

## Changelog
- **1.4.0**: Updates from GitHub Releases: new versions appear on the Plugins screen like any other plugin.
- **1.3.0**: Handles publisher links with a title slug after the DOI; treats tags and line breaks as spaces so text is not glued onto a DOI; keeps a chapter DOI over its book DOI.
- **1.2.0**: Checks the DataCite API directly when Crossref has no record, before falling back to doi.org.
- **1.1.0**: Uses metadata from your own Scholar Citation Meta posts first; page-field cleanup.
- **1.0.0**: First release.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).
