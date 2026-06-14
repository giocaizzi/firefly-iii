---
description: 'Guided creation of a new transaction (withdrawal / deposit / transfer). Usage: /firefly-iii:new [type] [amount] [description...]'
---

The user wants to log a new transaction. `$ARGUMENTS` may carry partial info like:
- `5.20 latte` — bare amount + description (type defaults to withdrawal)
- `deposit 2500 salary` — type + amount + description
- `transfer 500 to savings` — type + amount + brief route

Steps:

1. **Infer or ask** for the missing pieces:
   - Type: `withdrawal` (most common) / `deposit` / `transfer`. If ambiguous, ask.
   - Amount: must be a positive decimal.
   - Date: default to today unless user named one.
   - Source account: required. If user didn't name it, look up `firefly-iii://user/accounts` and pick the most likely asset (single asset → default to it; multiple → ask).
   - Destination account: required.
     - Withdrawal: an expense account (e.g. "Coffee shop") — auto-created if name unknown
     - Deposit: an asset account of the user's
     - Transfer: another asset account of the user's (same currency!)
   - Description: required.
   - Category / Budget / Tags: optional. Ask once if relevant; don't badger.
2. **Echo the full plan** back to the user in a compact block:
   ```
   Logging:
     withdrawal: 5.20 EUR
     from: Chase Checking → to: Coffee shop (expense)
     date: 2026-05-17
     description: Latte
     category: Coffee & tea
   ```
3. **Wait for confirmation.** Don't call the create tool yet.
4. On confirm, call the matching `create-*` tool with an `idempotency_key` derived deterministically from the inputs:
   - `key = "manual-{type}-{date}-{amount}-{source}-{destination}"` (lowercase, hyphenated)
   - This way an accidental re-invocation won't duplicate.
5. After creation, surface the new transaction's id and the audit-log line ("logged to audit channel"). Offer to attach a note or tag.

If the user says something like "no, on the BoA card" mid-flow, update the source account in your plan and re-echo. Don't lose state.

For `create-transfer`, double-check both accounts have the same currency before submitting — the server will reject otherwise. Surface the cross-currency limitation to the user when relevant.
