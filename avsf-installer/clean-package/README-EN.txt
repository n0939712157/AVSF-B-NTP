AVSF B-NTP — CLEAN SITE PACKAGE
=================================

PURPOSE
This archive is a clean AVSF source package for deploying a new website. Creating it does not modify the source website or its database.

INCLUDED
- AVSF source: Admin, Site Center, Frontend, Installer, CSS, JavaScript and language dictionaries.
- Generic config.php, site routing/security .htaccess and the canonical database-schema.sql.
- Canonical defaults from the same avsf_default_settings() function used by the first-run installer.
- Labels, help text, descriptions, examples, placeholders and Thai/English dictionaries.

EXCLUDED
- config.local.php, .env variants, database credentials and live SQL dumps.
- Existing users, password hashes, roles, permissions, 2FA secrets and activity history.
- Posts, pages, actors, studios, taxonomies, redirects, SEO overrides and site-specific settings.
- Uploaded media and runtime files including cache, logs, backups and quarantine.
- API keys, tokens, SMTP credentials, proxies, private keys and site verification files.

INSTALL ON NEW HOSTING
1. Create a new empty MySQL database and database user in your hosting control panel.
2. Extract this ZIP into the new domain's Document Root. Ensure index.php and config.php are at the root.
3. Open https://your-new-domain/db-setup.php and enter the MySQL host, port, database name, username and password.
4. AVSF creates config.local.php and initializes the schema and canonical default settings. Do not import an old database dump.
5. Open /setup.php in the same browser to create the first Owner account and complete the mandatory 2FA setup at first login.
6. Configure the site name, canonical URL, SEO, permalinks, mail and external integrations before launch.

SECURITY
- Never publish config.local.php or this ZIP. Keep the first-run endpoints restricted after setup according to your hosting policy.
- Use a dedicated empty database for the new installation.
