# Real Client Fixtures

Store captured Geometry Dash client contracts by game version:

```text
tests/client-fixtures/
├── 1.9/
│   └── endpoints.json
├── 2.0/
│   └── endpoints.json
├── 2.1/
│   └── endpoints.json
└── 2.2/
    └── endpoints.json
```

Each `endpoints.json` is generated from a real client trace and contains the endpoint path, method and request metadata required by the contract test.

Do not mark protocol support as verified from router aliases alone. A version is considered verified only after its real-client fixture passes in CI.
