# Compute contract fixtures (`protocol: 1`)

Vendored byte-for-byte from `sidecars/synaplan-compute/tests/fixtures/compute-contract/`.

A change to any file in this directory is a `protocol: 2` decision. Record it
in `_devextras/planning/202609_secure_compute/STATUS.md`. Do not edit a
fixture for client convenience.

`CHECKSUMS.sha256` is asserted by `ComputeContractFixtureTest` (PHP) and
`TestFixtureChecksums` (Go). Both sides must stay identical.

Source tree at vendor time: `sidecars/synaplan-compute` on the Wave 5 A3
branch (sidecar A0–A2 landed in #1774).
