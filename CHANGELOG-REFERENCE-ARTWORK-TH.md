# AVSF v3.2.4 — Category & Missing-Cover Reference Artwork

- Category overview cards now use the supplied linked-collection artwork as their background.
- Main video cards without a real cover now use the supplied neon play-frame artwork.
- Both sharp images are centered with `background-size: contain` to retain all frame and icon details.
- A softened blurred duplicate fills remaining card space without cropping the main artwork.
- Real video covers remain unchanged and take priority over the missing-cover fallback.
- Existing category routes, labels, counts, layout geometry, taxonomy artwork, and mobile two-column grid are preserved.
- The previous 13px rounded top corners on post cover frames are retained.
- No post content, taxonomy records, database, or existing uploaded files are modified.

## Added/updated UI artwork
- `avsf-storage/uploads/avsf-ui/taxonomy/category.webp`: 1800×1005, 194946 bytes.
- `avsf-storage/uploads/avsf-ui/taxonomy/post-no-cover.webp`: 1800×1005, 169732 bytes.
