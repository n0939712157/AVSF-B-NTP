# AVSF v3.2.4 — Uniform Cover Frames

- Main post cards now use a fixed image viewport ratio per device: 4:5 mobile, 4:3 tablet, 16:10 desktop.
- Original portrait, square, and landscape covers are fully visible with `object-fit: contain`; no distortion or cropping.
- A blurred copy of each real cover fills the remaining frame area for a polished edge-to-edge appearance.
- No-cover cards continue to use the supplied neon fallback art within the same fixed viewport.
- If an image fails to load, its image backdrop is removed and the no-cover fallback is activated.
- Recommended carousel cards retain their landscape dimensions, while actual portrait/landscape images are now fully visible with a blurred fill.
- Card metadata, links, taxonomy, carousel controls, player, and database records are unchanged.
