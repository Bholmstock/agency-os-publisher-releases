# Agency OS Publisher 0.5.6

Repairs the native WordPress update-discovery response by supplying the required `version` field alongside `new_version`. WordPress previously discarded the update response before scheduling installation. Retains the scoped automatic-update filter and SHA-256 verification, metadata restoration, article publishing, category handling and deferred image processing.

Affected 0.5.4 and 0.5.5 installations require a one-time bootstrap update through WordPress or hosting management. Future native automatic updates remain subject to WordPress scheduling and hosting policy.

Validation: 73 tests passed, including the actual PHP update callback, native discovery requirements, checksum refusal and fail-closed publication regression tests.

Package SHA-256: `5d94029fbd983af5a1661d88e7e8e61dd28a0d2b80c043f190ec289d440059b9`.
