# AVSF v3.2.4 — Gallery Hover/Tap Preview

- Added hover-to-preview on mouse/trackpad and first-tap-to-preview on touch devices.
- A second tap follows the normal post URL; desktop click remains normal navigation.
- Uses the existing `normalizeGalleryImages()` function and the post's saved Gallery URLs; no database migration or re-upload is needed.
- The current cover/poster remains the first preview frame, followed by unique Gallery images.
- Images rotate at 750ms by default (clamped to 500–2000ms), preloaded only after interaction.
- Failed Gallery images are skipped; preview resets to the original cover when leaving a card, navigating, scrolling the card out of view, or hiding the tab.
- Main listing cards and homepage recommended carousel share the same preview interaction.
- Every image remains inside the existing fixed media frame with `object-fit:contain`; a blurred copy of the same image fills the remaining area so portrait/landscape changes do not stretch the card or expose empty bars.
- No post data, Gallery records, database schema, image filenames, routes, or player behavior changed.
