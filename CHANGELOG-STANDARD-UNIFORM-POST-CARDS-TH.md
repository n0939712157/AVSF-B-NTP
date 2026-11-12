# AVSF v3.2.4 — Standard Uniform Post Cards

- Standardized every `postCardHtml()` listing card used on home latest posts, archive/all posts, search, actor, studio, tag, category and related-post sections.
- All listing covers use the same 16:10 landscape media viewport on mobile, tablet and desktop.
- Real portrait/landscape covers are fully visible via `object-fit: contain`; a blurred copy of the same image fills the unused background.
- Missing-cover artwork uses the exact same viewport dimensions.
- Post cards use a flex column layout and grids use equal row sizing, so real-cover and no-cover cards have matching overall dimensions.
- Information panel uses a fixed-height two-line title slot and a consistent category/date row; prevents blank oversized cards.
- Rounded top image corners and a soft bottom fade into the data panel are retained.
- Featured carousel cards keep their dedicated 3:2 layout with the same real-cover/no-cover consistency.
- Does not change post content, metadata, category/tag records, routes, player, database or existing uploads.
