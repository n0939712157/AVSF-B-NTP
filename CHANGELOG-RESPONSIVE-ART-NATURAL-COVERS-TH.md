# AVSF v3.2.4 — Responsive Artwork & Natural Post Covers

- Taxonomy card text is explicitly layered above the artwork on desktop, iPad/tablet, and mobile.
- Taxonomy artwork's sharp layer uses `contain`, stays centered, and is never cropped; the blurred layer blends the artwork into each card.
- Existing assigned actor photos/studio logos remain visible and fully contained.
- Post cards with a real cover now render the image at 100% width and natural intrinsic height, preserving the complete source aspect ratio.
- Removed the fixed mobile thumbnail ratio from real cover cards to eliminate the top letterbox gap.
- Post cards without a real cover keep the supplied no-cover artwork in a 16:9 frame.
- Added a longer bottom gradient fade between the cover and the information panel.
- Kept the small rounded top corners of the cover frame.
- Did not change post text, code, date, category/tags, views/duration, taxonomy IDs, routes, database or player behavior.
