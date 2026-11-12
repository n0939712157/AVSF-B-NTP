# AVSF v3.2.4 — Selection Outline Instant Gallery Preview

- The Gallery preview is now directly bound to the visible selected-card outline.
- Whenever `.avsf-gallery-preview-selected` is applied, Gallery preview starts automatically.
- First valid Gallery image is displayed immediately after selection/preload; there is no initial 750ms blank wait.
- Subsequent Gallery images rotate every 750ms by default.
- Touch first tap selects/highlights/previews; second tap on the same selected card opens the post.
- Selecting a different card transfers the outline and preview to the newly selected card.
- Desktop hover and keyboard focus also apply the selection outline and start preview.
- Original card sizing, image frames, orientation handling, fallback artwork, post data, URLs, database, and player remain unchanged.
