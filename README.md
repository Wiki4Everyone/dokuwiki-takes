# dokuwiki-takes

A DokuWiki action and syntax plugin for hot pages that collect numbered versions.

Page text stays in DokuWiki's normal `.txt` files. SQLite stores only the assignment, the votes, and the hide flag.

## Install

Copy this directory to `lib/plugins/takes` on a DokuWiki that already has the sqlite plugin. The schema in `db/` is applied by that plugin.

## Use

A moderator or an administrator adds `~~TAKES~~` to a page.

- The page text moves to `page.0`. Nobody can edit that original. Copy it into your own version.
- People who already edited the page get `.1`, `.2`, … in alphabetical order of username.
- Later, a logged-in user presses the button on the page and receives the next number. They can edit and delete only that page.
- Numbers stop at 60000.
- The list on the base page is ordered by score, then by newer number.
- Votes are signed. Counting stops at one billion either way.
- At a total of -20 the version is hidden once and its owner is notified. The owner can show it again. The notice is not sent a second time.
- A logged-in user can ask to be notified when a version reaches a chosen number of upvotes (15 to 5000) or rank 1. That request uses one watch slot in the separate site mailbox, if that plugin is installed.

Discussion pages named `talk.topic` are not numbered.

## License

GPL-2.0-only, same as DokuWiki.
