# CLI Reference

```bash
./vendor/bin/hybrid-id <command> [options]
```

## Commands

### `generate`

Generate one or more IDs.

| Flag | Short | Description | Default |
|---|---|---|---|
| `--profile <name>` | `-p` | compact, standard, extended | standard |
| `--count <number>` | `-n` | Number of IDs | 1 |
| `--node <XX>` | | 2 base62 chars | auto-detected |
| `--prefix <name>` | | Stripe-style prefix | none |
| `--blind` | | Enable blind mode | false |

```bash
./vendor/bin/hybrid-id generate
./vendor/bin/hybrid-id generate -p compact -n 10
./vendor/bin/hybrid-id generate -p extended --node A1 --prefix txn
./vendor/bin/hybrid-id generate --blind
```

### `inspect`

Inspect an existing ID.

```bash
./vendor/bin/hybrid-id inspect usr_0VBFDQz4A1Rtntu09sbf
```

```
  ID:         usr_0VBFDQz4A1Rtntu09sbf
  Prefix:     usr
  Profile:    standard (20 chars)
  Timestamp:  1739750400000
  DateTime:   2026-02-17 00:00:00.000
  Node:       A1
  Random:     Rtntu09sbf
  Entropy:    59.5 bits
  Valid:      yes
```

### `profiles`

List available profiles.

```bash
./vendor/bin/hybrid-id profiles
```

```
  Profile     Length   Structure              Random bits   vs UUID v7
  -------     ------   ---------              -----------   ----------
  compact     16       8ts + 8rand            47.6 bits     < UUID v7
  standard    20       8ts + 2node + 10rand   59.5 bits     ~ UUID v7
  extended    24       8ts + 2node + 14rand   83.4 bits     > UUID v7
```

### `help`

```bash
./vendor/bin/hybrid-id help
```

## Exit Codes

| Code | Meaning |
|---|---|
| 0 | Success |
| 1 | Error (invalid input, unknown command, generation failure) |

The CLI binary rejects non-CLI SAPI execution.
