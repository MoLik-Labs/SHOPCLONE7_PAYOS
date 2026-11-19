# Round 13b — email campaigns: one id, batched queue, no forged recipients

Scope: the two admin handlers that create an email campaign (`email-campaign-add.php`, the add branch
of `email-campaigns.php`). No schema change, no deployment.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. What the old handler did

For a campaign sent to selected members, the loop was:

```php
foreach ($_POST["listUser"] as $user) {
    $user = $CMSNT->get_row("SELECT * FROM `users` WHERE `id` = '" . $user . "' ");   // raw post value
    $CMSNT->insert("email_sending", [
        "camp_id" => $CMSNT->get_row("SELECT `id` FROM `email_campaigns` ORDER BY id DESC LIMIT 1")["id"],
        ...
    ]);
}
```

Four problems:

1. **The id was re-read from "the newest row".** If another admin saved a campaign between this
   campaign's INSERT and the loop, every recipient was attached to *that* campaign. The same query ran
   once per member.
2. **N+2 queries.** One `SELECT` per member plus the campaign lookup, plus one `INSERT` per member.
   A shop with 20 000 members meant 40 001 round trips for one campaign.
3. **The posted value went into SQL unescaped.** Admin-only, but a forged array element was enough to
   break the query; a value that did not match any user produced `$user["id"]` on `false`.
4. **A failed campaign insert still queued recipients**, because the queue loop ran before `$isInsert`
   was checked.

## 2. What replaces it

`orbit_campaign_create($CMSNT, $campaign, $postedUserIds)` in `libs/helper.php`:

1. `orbit_campaign_selection()` accepts only positive integer ids, drops the blank "everyone"
   placeholder, deduplicates, and returns `invalid = true` for anything else (strings with quotes,
   nested arrays, scalars). An invalid selection creates **nothing** — it never silently falls back to
   "mail every customer".
2. The campaign row is inserted; `$CMSNT->insertId()` (new in `libs/db.php`) returns the id from the
   same connection, so there is no race and no extra query.
3. Selected recipients are checked for existence in `IN (…)` chunks of 500, then queued with one
   multi-row `INSERT` per 200 ids.
4. "Everyone" is a single `INSERT … SELECT` (`banned = 0 AND email IS NOT NULL AND email <> ''`) —
   the row set never enters PHP.
5. The whole write runs inside `$CMSNT->transaction()`; the campaign id is returned only if the row and
   at least one recipient were written. On InnoDB a failure leaves nothing behind; on MyISAM it
   degrades to the old non-atomic behaviour, never worse.

Both handlers now call the helper, so the two code paths cannot drift again. The stray duplicate
`views/admin/email-campaign-add (1).php` (byte-identical, unreachable, tracked by accident) was removed.

## 3. `NULL` in the database layer

`update()` used to render a PHP `NULL` as the four-character string `'NULL'`. That is why "clear the
2FA token" wrote a token literally named `NULL`, which `WHERE token_2fa IS NOT NULL` then kept
matching. `update()` and `update_value()` now render SQL `NULL`; `insert()` deliberately keeps the old
empty-string form because the schema is not shipped with the code and supplier purchases insert
`product_code => NULL` into a column whose nullability cannot be verified from here.

## 4. Tests

`tests/email_campaign_test.php` — 29 checks, 0 failures, with a DB stub (no MySQL):

- selection drops blanks, deduplicates, refuses injected strings, nested arrays, scalars, zero and
  negative ids;
- recipient lookup is one query for any selection ≤ 500 and exactly 3 for 1200 ids;
- queueing 250 ids produces two `INSERT` statements, each carrying the campaign and user ids; no
  single-row insert remains;
- "everyone" is one `INSERT … SELECT` with the banned/blank-email filter;
- `create()` returns its own id, never runs `ORDER BY id DESC`, opens and commits one transaction,
  returns 0 on a failed insert / missing id / zero recipients / forged selection, and queues nobody in
  those cases;
- both views call the helper, keep no per-user `get_row`, and no "newest campaign" lookup;
- `libs/db.php` exposes `insertId()` and renders `NULL` as `NULL` on update.

```
bash tools/dev/run.sh
  LINT 303 files, 0 parse errors
  SCAN 236 files: 0 critical, 0 high, 0 medium, 0 low
  38 suites, 1 not run (payos_service, no disposable database), 0 failures
```

## 5. Not verified

No MySQL in this sandbox: the batched `INSERT … SELECT` and the transaction were exercised against a
stub that records SQL, not against the real schema. Staging should create one campaign for "everyone"
and one for two members, then check `email_sending.camp_id` matches the new campaign ids.
