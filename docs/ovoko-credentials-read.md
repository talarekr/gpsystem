# Owner-only read of saved Ovoko credentials

No shell is required. After deployment, OwnerAdmin can open
/admin/tools/ovoko-credentials directly, or follow the link from Ovoko settings.

GET renders a page without decrypting or embedding any saved credentials.
An explicit button makes a CSRF-protected POST and returns only username,
password and user_token from marketplace_accounts.api_credentials for ovoko_main.
The existing encrypted:array representation is decoded with the application's
current encryption key. The key is never returned. Missing accounts are not
created; missing fields are null.

Both methods require authentication, admin panel access and OwnerAdmin role.
Existing read-only database/session middleware applies. The controller uses one
Query Builder SELECT without models, observers, save, jobs or provider clients.
It never logs plaintext, ciphertext or exception messages. Responses are private,
no-store/no-cache, noindex, nosniff and cannot be framed.

Credentials are displayed with textContent only after the owner clicks reveal.
Hide clears the DOM; pagehide clears it before leaving the page. There are no
downloads, localStorage writes, saved files or secrets in URLs. No automatic API
test or synchronization runs. Existing credential-edit/save behavior is unchanged.

Feature tests cover the encrypted representation, GET versus POST, exact field
allowlist, SELECT-only behavior, authorization, CSRF, unchanged stored ciphertext,
missing account and safe decryption errors. Tests must be executed before merging:
the execution environment was unavailable when this change was prepared through
the GitHub connector. No deployment or production secret read was performed.
