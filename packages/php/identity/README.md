# Shared identity directory

Business services use `UserIdentityDirectory` instead of passing a gateway
request object across service boundaries. `BusinessActor::fromContext()` reads
only trusted `x-user-id` metadata; request body user IDs and persona IDs never
become authorization input.

`resolve()` validates an authenticated account's selected MAIN or internal
ALIAS for an operation. `validate()` validates an explicit owned persona.
`main()` resolves an active public MAIN persona by its own id, so a peer target
is never checked against the caller's account. `UserServiceIdentityClient`
uses an independent service token for every UserService RPC.
