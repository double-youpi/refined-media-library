=== Double Youpï Refined Media Library – Folders, Collections & WebP ===
Contributors: doubleyoupi
Tags: media library, folders, collections, media organizer, webp
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Nested folders with drag-and-drop, collections, duplicate and orphan cleanup, and WebP/AVIF for the WordPress media library.

== Description ==

The WordPress media library hasn't really changed in years. A few hundred uploads in, you spend more time scrolling than working. Refined Media Library turns it into a real asset manager — folders, collections, and a detail panel that finally feels designed — without adding a single per-image setting for you to babysit.

**Folders that actually feel like folders**

* **Nest them as deep as you like.** Create subfolders, drag a folder into another to re-parent it, or drop one between two to reorder — with live drop indicators, no guesswork.
* **Right-click for everything.** New subfolder, rename, move, delete — in clean designed dialogs, not the browser's grey pop-ups.
* **Collections that overlap.** They ignore folder boundaries, so the same image can sit in several at once.
* **Drag straight onto a folder.** From the grid or the list — and upload right into the folder you're looking at.
* **Builder-agnostic.** It sits on top of the native media library, so it behaves the same whether you build with Bricks, Elementor, Gutenberg or Divi.

**A detail panel that does the work**

Click any file and a panel slides in from the right — no more full-screen modal. Edit the alt text and title, move it to a folder, drop it into collections, copy its URL, and see exactly which posts and pages use it (WooCommerce galleries included). Arrow keys walk you through the library without ever leaving the panel.

**Find anything, fast**

Filter by type — images, SVG, PDF, video — or by name, folder or collection. What you need stops hiding behind 2,000 thumbnails.

**Less clutter, automatically**

* **Alt text, written for you.** Filled in from the filename on upload, but only when you left it blank. Runs on your server, talks to nobody.
* **Duplicates, flagged.** Re-upload the same file and it gets caught, with a link to the original.
* **Dead weight, found.** An unused-media scanner spots files no post actually references (featured images, content, custom fields, WooCommerce galleries) and lets you bulk-delete them behind a deliberate double confirmation. If it can't be sure, it leaves the file alone.

**WebP and AVIF on upload**

Convert images to WebP and/or AVIF the moment they land, after a quick check that your server can actually do it. Quality is one slider, not a wizard. If another plugin already owns image conversion, Refined Media Library steps aside.

**Switching from another plugin?**

One-click import from FileBird, Real Media Library, HappyFiles, Folders (Premio) and Enhanced Media Library. It reads their data directly, even if the plugin is already deactivated, never touches it, and the import is reversible. Preview first, undo anytime.

**Free, and actually complete**

*No locked features. No "upgrade to unlock folders." No nag screens.*

This is the whole plugin — every folder, collection and cleanup tool, working today. It's the standalone edition of the Double Youpï suite: free for good, and actively maintained.

**No per-image settings**

That's the whole point. A few toggles in one place, sensible defaults everywhere else.

== Installation ==

1. Install it from the Plugins screen, or upload it to `/wp-content/plugins/doubleyoupi-refined-media-library` by hand if that's your thing.
2. Activate it.
3. Open Double Youpï Refined Media Library > Settings and switch on what you want. There isn't much to switch on, which is rather the point.

== Frequently Asked Questions ==

= Does it send my images anywhere? =

No. Alt text is built from the filename, right there on your server. Conversion uses Imagick or GD, also local. Nothing about your media leaves your site.

= Will it work with my page builder? =

Yes. It improves the native media library rather than replacing it, so Bricks, Elementor, Gutenberg, Divi and everything else all see the same thing.

= Can I import my folders from FileBird, Real Media Library or HappyFiles? =

Yes. One-click import is supported for FileBird, Real Media Library, HappyFiles, Folders (Premio) and Enhanced Media Library. It reads the source directly (even if that plugin is deactivated), never modifies it, and the whole import can be undone.

= Is the unused-media scanner safe to use? =

It only flags files it can't find referenced anywhere, and deleting them is a separate step with its own confirmation. The caution is on purpose. Deleting the wrong image is the kind of thing you do exactly once.

= Does it replace the media library? =

No, it enhances the one WordPress already gives you. Disable Refined Media Library and you're back to the stock library, nothing lost.

= What is the catch with "free"? =

There isn't one. This is the standalone version, no strings attached. If it saves you an afternoon and you feel like it, there's a coffee button somewhere. Entirely optional.

== Screenshots ==

1. Nested folders and collections, in a panel that slots right into the native media library.
2. Drag folders to reorganise — re-parent or reorder, with live drop indicators.
3. Right-click (or the ⋯ button) for folder actions: new subfolder, rename, move, delete.
4. Drag media straight onto a folder or collection to file it.
5. A Finder-style detail panel: edit metadata, pick a folder from the tree, and see where the file is used.
6. Search and filter the library by type, name, folder or collection.
7. The unused-media scanner finds files no post references, with a safe bulk delete.
8. One-click import from FileBird, Real Media Library, HappyFiles, Folders and Enhanced Media Library.

== Changelog ==

= 0.1.1 =
* Detail panel: the image preview now scales proportionally as you widen the panel, a drag handle underneath resizes the image, and clicking it opens a full-screen lightbox gallery with keyboard navigation.
* Buttons added to the media screen by other plugins (such as Elementor) now move neatly into the folder panel instead of crowding the page title.

= 0.1.0 =
* First public release. Nested folders with drag-to-reorganise and a right-click menu, collections, a Finder-style detail panel, duplicate and orphan cleanup, automatic alt text and titles, WebP/AVIF conversion on upload, and one-click import from FileBird, Real Media Library, HappyFiles, Folders and Enhanced Media Library.

== Upgrade Notice ==

= 0.1.1 =
A resizable, proportionally-scaling image preview with a new full-screen lightbox gallery, plus tidier handling of buttons added by other plugins.