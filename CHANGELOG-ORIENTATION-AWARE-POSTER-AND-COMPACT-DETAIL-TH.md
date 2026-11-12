# AVSF v3.2.4 — Orientation-Aware Poster Fill & Compact Detail Alignment

- Restored the original portrait/landscape poster classifier and class-based responsive behavior.
- Portrait poster on compact phones returns to the original side-by-side poster + information-panel layout.
- Landscape poster on compact phones and short landscape tablets uses the original full-bleed, image-above-details layout.
- Tablet/desktop keep the two-column poster/details layout, with the poster frame stretched to exactly the detail-panel height.
- Added a blurred duplicate of the same poster as a behind-image fill layer; the foreground source remains `object-fit:contain`, preserving the whole poster without distortion or cropping and eliminating empty-looking bars.
- The poster orientation class is set after image dimensions are known; original URLs and image data are unchanged.
- Reduced label-to-chip distance by approximately half: mobile landscape 82px/22% label rail + 4px gap; mobile portrait 76px/38% within its narrower panel + 3px; tablet 88px/16% + 5px; desktop 100px/16% capped at 160px + 5px.
- Value buttons retain their existing colors and remain icon-free; label icons remain beside labels only.
- No content, taxonomy values, Gallery records, database schema, filenames, URLs, or player code changed.
