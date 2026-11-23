# dokuwiki-takes

A DokuWiki action and syntax plugin for hot pages that collect numbered versions.

Page text stays in DokuWiki's normal `.txt` files. SQLite stores the assignment, the votes, the hide flag, whether a version is public, and a cached title and snippet.

## Install

Copy this directory to `lib/plugins/takes` on a DokuWiki that already has the sqlite plugin. The schema in `db/` is applied by that plugin.

## Use

A moderator or an administrator adds `~~TAKES~~` to a page.

- The page text moves to `page.0`. Nobody can edit that original. Copy it into your own version.
- People who already edited the page get `.1`, `.2`, … in alphabetical order of username. Those pages are not public yet. The text tells that person to copy from `page.0`, or to write their own text and save. Saving their own text makes the page public. If they do not need it, they can delete it.
- Deleting a version does not free the number. It stays bound to that username. Asking again restores the same number.
- Later, a logged-in user presses the button on the page and receives the next number. They can edit and delete only that page.
- Numbers are integers and stop at 100 million. A freed number is not given to someone else.
- The list on the base page is ordered by score, then by newer number.
- Votes are signed. Counting stops at one billion either way.
- At a total of -20 the version is hidden once and its owner is notified. The owner can show it again. The notice is not sent a second time.
- A logged-in user can ask to be notified when a version reaches a chosen number of upvotes (15 to 5000) or rank 1. That request uses one watch slot in the separate site mailbox, if that plugin is installed.

Discussion pages named `talk.topic` are not numbered.

## License

GPL-2.0-only, same as DokuWiki.
