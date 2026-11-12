# AVSF Image Upload — Original Filename Fix (v3.2.4)

This patch preserves the original uploaded image filename. It does not generate MD5/hex/random names and does not append numeric suffixes. Thai characters, spaces, and the original extension case are retained.

## Files included
- `avsf-core/functions.php` — validates and stores images using their original filename.
- `avsf-core/security/security_lib.php` — aligns the security upload helper with the same filename policy.
- `avsf-core/lang/en/core-frontend.php` — English messages for filename and upload errors.
- `avsf-admin/index.php` — Media Library can manage/delete ordinary original filenames as well as older hash-named files.

## Install
1. Make a copy of the four existing files.
2. Extract this ZIP into the AVSF document root, preserving folder paths and allowing overwrite.
3. Clear PHP/opcache and browser cache if enabled.
4. Test an upload with a unique name such as `AVSF-BANNER-01.jpg`. The stored file should keep that exact basename under `avsf-storage/uploads/`. The public URL will URL-encode spaces and Thai characters; that does not change the physical filename.

## Collision policy
- If the same name already exists with identical content, AVSF reuses the existing file.
- If the same name exists with different content, AVSF refuses the upload and tells the user. It will not silently rename or overwrite an existing image, because other posts/pages may already reference it. Choose a unique source filename or manage the old file first.

## Supported formats and limits
JPG, JPEG, PNG, GIF, and WebP up to 5 MB. HEIC/HEIF is not accepted by this build; convert it to JPEG first. The server must also allow PHP file uploads, have `upload_max_filesize` and `post_max_size` high enough, and make `avsf-storage/uploads/` writable by PHP.

This is a source-code patch only. It does not include config, database, or user credentials.
