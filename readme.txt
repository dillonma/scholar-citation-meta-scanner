=== Scholar Citation Meta Scanner ===
Contributors: dillonma
Tags: zotero, doi, coins, citation, crossref
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds every DOI on a post or page and exposes the full citations to Zotero as a pick-list (COinS). Off by default, enabled per page.

== Description ==

Use this plugin for a page that lists many works (a publications list, syllabus or bibliography). For a page about a single work, use the companion plugin Scholar Citation Meta (https://github.com/dillonma/scholar-citation-meta); the two work together.

Examples:

* A publications list: https://dillonm.io/papers/
* With Scholar Citation Meta on the same page: https://dillonm.io/papers/doing-critical-gis/

Finds every DOI on a post or page and exposes the full citations to Zotero as a pick-list (COinS). Off by default, enabled per page.

Full documentation: https://github.com/dillonma/scholar-citation-meta-scanner

== Installation ==

1. Download the latest `scholar-citation-meta-scanner-<version>.zip` from https://github.com/dillonma/scholar-citation-meta-scanner/releases/latest
2. Plugins → Add New → Upload Plugin, choose the zip, and activate.
3. In the editor sidebar, tick "Scan this page for DOIs" in the Zotero DOI list box and click Update.

Later versions update from GitHub Releases through the normal WordPress Updates screen.

== Changelog ==

= 1.4.1 =
* Update checks fall back to github.com when GitHub's API is rate-limited (common on shared hosting).

= 1.4.0 =
* Updates from GitHub Releases: new versions appear on the Plugins screen like any other plugin.

= 1.3.0 =
* Handles publisher links with a title slug after the DOI; treats tags and line breaks as spaces so text is not glued onto a DOI; keeps a chapter DOI over its book DOI.

= 1.2.0 =
* Checks the DataCite API directly when Crossref has no record, before falling back to doi.org.

= 1.1.0 =
* Uses metadata from your own Scholar Citation Meta posts first; page-field cleanup.

= 1.0.0 =
* First release.
