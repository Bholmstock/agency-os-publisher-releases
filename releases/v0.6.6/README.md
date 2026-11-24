# Publisher 0.6.6

Re-preparing a native Divi page no longer stops on Atlas's own earlier private draft. Each preparation creates a new private draft, so an earlier one could still hold the page address. When Atlas prepares the page again, it now names its earlier preparations of the same task. The Publisher moves a draft to the WordPress Trash only when all of these hold:

- It is still this plugin's own unpublished draft for one of those preparations.
- It is at the very same address.
- The connected user may delete it.

Drafts are never deleted and can be restored from the Trash. If creating the new draft fails for any reason, the earlier draft is put back exactly as it was. A replaced draft can never be previewed or published. Publishing and the WordPress check never replace anything, and published pages, other users' drafts and drafts at other addresses are never touched.

The capabilities endpoint advertises `nativeDraftReplacement` (version 1), so Atlas only sends the request to sites that support it. The protocol version is unchanged.

Retains bounded mobile layout repairs, the native Divi page workflow, approved-image integrity and scaled-image URL corrections, saved authors, metadata, Rank Math and checksum-verified updates. This release does not publish or approve any article or page wording.

SHA-256: 8a8edf31b84641b598de9610fc18ad8eb548097f174af95d4978e5be27447a9f
