# AVSF v3.2.4 — Media Fallback & Cover Overlay Fix

- Removed the floating play-icon overlay from post cards so real cover images remain unobstructed.
- Kept the play symbol only inside the no-image fallback artwork.
- Made no-image post-card fallback backgrounds follow the post's category (subThai, HD, new, popular, or default).
- Taxonomy directory cards now keep type-specific actor/studio/tag artwork behind images; if a stored image URL fails, the matching fallback artwork becomes visible.
- Category directory cards now use their own ambient color treatment, with optional palette variants derived from category names.
- Preserved routes, taxonomy IDs, image references, player template, post data, and responsive grid rules.
