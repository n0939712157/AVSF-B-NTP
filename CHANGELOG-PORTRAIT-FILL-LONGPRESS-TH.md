# AVSF v3.2.4 — Portrait Fill & Long-Press Gallery Preview

- Portrait cover and Gallery images fill the existing image viewport using object-fit: cover; no distorted source or card growth.
- Portrait detection applies to original cover images and each Gallery preview frame.
- Landscape images keep contain + blurred fill.
- Increased no-cover neon artwork zoom to 128% and removed CSS inset outlines from fallback art.
- Desktop mouse/pen hover starts Gallery preview immediately.
- Touch/pen hold starts preview after 380ms; moving to scroll cancels the hold.
- Releasing after a long press keeps the slideshow visible; the next tap opens the post. A short tap still navigates normally.
- Gallery images rotate at 750ms by default; broken images are skipped.
- No post data, Gallery records, DB schema, filenames, URLs, or player code changed.
