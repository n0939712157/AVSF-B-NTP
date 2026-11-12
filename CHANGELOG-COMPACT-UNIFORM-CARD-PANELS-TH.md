# AVSF v3.2.4 — Compact Uniform Card Panels

- Removed forced fixed 88px/94px post-card information-panel heights that left excess blank space under the date.
- Information panels now size to their content using compact padding.
- Kept a fixed two-line title slot and one-line category/date row so card pairs remain aligned.
- Disabled equal-height stretching of grid items; all cards keep the same image viewport and same content slots, so rows remain visually consistent without stretching a card because another item is taller.
- Applied to the shared postCardHtml() views: home latest posts, all/archive, search, actor, studio, tag, category and related posts.
- Recommendation carousel keeps its 3:2 landscape frame and gets a tighter two-line title overlay and a consistent bottom fade.
- Existing cover/no-cover frames, blurred fill, fallback artwork, image rounding, post data, links, taxonomy and player are unchanged.
