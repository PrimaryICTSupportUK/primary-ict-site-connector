# Primary ICT Support Site Connector

Install the release ZIP on WordPress, then open **Primary ICT Support → Site Connector**.
Pair using the dashboard's service URL, site ID and one-time code. Replacing an installed
plugin preserves its pairing. No dashboard source, credentials or tenant data are included here.

This plugin shares the branded menu with other Primary ICT Support plugins and uses normal
WordPress controls for its own public GitHub release updates. Automatic updates are optional.
The server-side dashboard still excludes connector self-updates from its maintenance jobs.

## Publish a release

1. Change the main file Version header/code constant, jobs.php version and readme Stable tag
   together. Confirm the paired service accepts that connector version before distributing it.
2. Commit and tag the release as `vMAJOR.MINOR.PATCH`; push the tag.
3. Run `python3 scripts/build-release.py` to build the exact installable asset.
4. From this repository, run:
   `gh release create vVERSION dist/primary-ict-site-connector-VERSION.zip --verify-tag --title "Site Connector VERSION" --notes-file RELEASE_NOTES.md`
5. Check the published asset has its GitHub SHA-256 digest and install/test it on the pilot.

Native discovery caches metadata for six hours; failures for fifteen minutes. Stable releases
only; use Check connector updates now for a bounded immediate refresh. Last/next check and failure status are visible. A GitHub source archive is not a substitute for the installable ZIP. Both the URL and
download checksum are verified. Repository access control protects release authenticity.

License: GPL-2.0-or-later.
