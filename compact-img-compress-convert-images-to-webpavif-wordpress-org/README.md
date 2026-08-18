# Compact IMG WordPress.org Artwork

These files are for the plugin's WordPress.org Plugin Directory page. They are not runtime plugin files and should not be added to the installable plugin ZIP.

## SVN placement

Place the `assets` directory at the top level of the plugin's WordPress.org SVN repository, beside `trunk` and `tags`:

```text
assets/
trunk/
tags/
```

Do not place these files in `trunk/assets` or inside a release tag.

## Included files

- `assets/banner-772x250.png`
- `assets/banner-1544x500.png`
- `assets/icon-128x128.png`
- `assets/icon-256x256.png`
- `assets/screenshot-1.png`

All filenames are lowercase and every image uses the exact pixel dimensions required by WordPress.org. The PNG banners are below the 4 MB banner limit, and the PNG icons are below the 1 MB icon limit.

When committing with SVN, set PNG files to the `image/png` MIME type if the client does not do this automatically.
