# AVSF v3.2.4 — Poster Natural Fit & Borderless Frame

- Poster wrapper now follows the actual image's intrinsic aspect ratio.
- Removed forced 100%-height image behavior, fixed poster max-heights, stretched frame height, blurred duplicate background, outer border and frame background.
- Poster image uses width:100% and height:auto, so its block height is derived from the real image ratio.
- No crop or distortion is applied to portrait or landscape source images.
- Existing orientation detector and responsive grid behavior remain: portrait/landscape classes still choose their existing layout at phone/tablet/desktop breakpoints.
- In side-by-side layouts the information panel may be taller than the poster; the poster block itself remains exactly as tall as its image, without an empty bordered area.
- Preserved the existing information panel, label icons, tag/category buttons, Gallery, preview behavior, post content and database.
- Static validation performed; real-device visual QA still required.
