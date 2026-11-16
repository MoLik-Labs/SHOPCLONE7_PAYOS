# Skills for SHOPCLONE7_PAYOS

This folder keeps the AI "skills" this project uses, so a future session can pick them up without
re-cloning anything. A skill is a folder with a `SKILL.md`: YAML frontmatter (name + when to use it)
and the instructions themselves.

Two kinds live here:

| Folder | Kind | What it is for |
| --- | --- | --- |
| `anthropic/` | Upstream, vendored unchanged | General design and browser-testing guidance from Anthropic's public skills repository |
| `project/` | Written for this repository | The rules and commands that actually work on this PHP monolith |

**When they disagree, `project/` wins.** The upstream skills assume a modern JS toolchain; this shop is
plain PHP with no package manager, no PHP CLI on the build host and a database that must never be touched
by a test.

---

## 1. Vendored upstream skills

Source: <https://github.com/anthropics/skills> at commit `e4bb5d960d87126ff3b3a8af1a104b7db67560ba`
(2026-11-16). Licence: **Apache License 2.0** — the full text is kept next to each skill in
`LICENSE.txt`, as the licence requires. Files are unmodified.

| Skill | What was kept | Why it fits this shop |
| --- | --- | --- |
| `frontend-design` | `SKILL.md` | The design review pass: it names the "generated page" tells (cream + serif + clay accent, all-caps eyebrows, the card kit) and forces a subject-specific choice before code. Used to critique every Orbit page. |
| `webapp-testing` | `SKILL.md`, `examples/`, `scripts/with_server.py` | The reconnaissance-then-action browser loop: load → wait for network idle → screenshot/inspect → act. We run the same loop with our own Playwright/Puppeteer harness (see `project/php-monolith-debug`), because the helper's Python Playwright is not installed here. |
| `skill-creator` | `SKILL.md`, `references/schemas.md` | How to write and evaluate a new skill. The runnable evaluation tooling (`scripts/`, `eval-viewer/`, `assets/`, `agents/`) is **not** vendored: it needs the Claude Code CLI and would be dead weight in this repo. |

Deliberately **not** vendored: `docx`, `pdf`, `pptx`, `xlsx` (document generation, several MB each),
`canvas-design`, `algorithmic-art`, `theme-factory`, `brand-guidelines`, `slack-gif-creator`
(not this project's medium), `claude-api`, `mcp-builder`, `web-artifacts-builder` (stack mismatch),
`internal-comms`, `doc-coauthoring`, `academy-guide`, `discernment-nudge` (answer-style guidance, not
build work).

## 2. Project skills

| Skill | Use it when |
| --- | --- |
| [`project/orbit-storefront`](project/orbit-storefront/SKILL.md) | Building or reviewing a customer-facing page on the Orbit shell |
| [`project/php-monolith-debug`](project/php-monolith-debug/SKILL.md) | Reproducing, testing or debugging any PHP view, AJAX endpoint or service |
| [`project/security-review`](project/security-review/SKILL.md) | Auditing an endpoint or page for auth, CSRF, SQL injection, XSS, uploads, secrets or redirects |

## 3. Using a skill

- **Claude Code** discovers skills in `.claude/skills/<name>/SKILL.md`. To make these available without
  copying them, link the folder once:

  ```bash
  mkdir -p .claude/skills
  ln -sfn ../../docs/skills/project/orbit-storefront .claude/skills/orbit-storefront
  ln -sfn ../../docs/skills/project/php-monolith-debug .claude/skills/php-monolith-debug
  ln -sfn ../../docs/skills/project/security-review .claude/skills/security-review
  ```

  (`.claude/` is not committed; the link is a local convenience.)
- **Any other agent or a human**: read the `SKILL.md` before starting the task. The frontmatter says when
  the skill applies; the body is a checklist, not background reading.

## 4. Keeping this folder honest

- Do not edit files under `anthropic/`. If upstream changes, re-vendor the folder and update the commit
  in this README.
- When a project skill stops matching reality (a command changed, a page was rewritten), fix the skill in
  the same commit that changes the code. A stale checklist is worse than none.
- Skills contain no secrets, no customer data and no production hosts.
