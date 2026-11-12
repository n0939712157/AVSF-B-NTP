# AVSF v3.2.4 — Instant Touch Selection & Gallery Preview

- Replaced touch long-press activation with immediate first-tap card selection.
- First tap adds a visible pink selection frame and immediately starts the Gallery slideshow.
- Second tap on the same selected card follows the original post destination.
- Tapping another card transfers the selection frame and preview to that card.
- Desktop mouse hover continues to start preview immediately and leaving restores the cover.
- Keyboard focus also highlights and previews a card; Enter/Space retains normal activation.
- Existing 750ms Gallery interval, orientation handling, fixed media frame, full-image rules, and fallback art are preserved.
- No database, post data, Gallery records, image names, routes, or player behavior changed.
