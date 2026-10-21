# Agency OS Publisher 0.5.7

Adds a read-only route, `/rank-math-score`, that reports Rank Math's official score for a published post once Rank Math has calculated it. Rank Math calculates the score inside WordPress, normally when the post is opened in the WordPress editor; until then the route reports "pending". The route never changes the website, never triggers an analysis, and refuses addresses on other websites and posts the caller cannot edit.

No change to publishing, checking or updating. Retains the scoped automatic-update filter and SHA-256 verification, metadata restoration, article publishing, category handling and deferred image processing. The protocol version stays 3, so sites on 0.5.6 and Agency OS keep working together; those sites simply keep showing "pending" until they update.

Validation: the required publishing gate (58 tests) and 104 plugin, updater, packaging, score and activity tests passed, including the new route running in a real PHP engine, the actual PHP update callback, checksum refusal and the fail-closed publication regression tests.

Package SHA-256: `58ad88d8edea6a20a8a1564479f78c84332f8686bfc34334d5489a3e40999ca2`.
