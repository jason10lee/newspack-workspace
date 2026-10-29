---
name: autofix-secure
description: Supervised sibling of `autofix` for security-labeled Linear issues. Same reproduce→fix→verify→draft-PR machine, but every outward write pauses for operator go and all public artifacts follow disclosure hygiene. Use only when explicitly asked to "autofix-secure NPPM-XXXX".
---

# `autofix-secure` — supervised, disclosure-hardened sibling

This is a **thin** skill. It does **not** re-describe the stage machine — that
lives in, and is governed by, the base `autofix` skill and its spec:

- Stage machine + all mechanical rules: `.claude/skills/autofix/SKILL.md`
- Governing spec (source of truth):
  `~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-10-autofix-skill-spec.md`
- This skill's design + decision record:
  `~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-22-autofix-secure-mode-amendment.md`

**Run the base `autofix` skill's Stages 0–7 exactly as written**, using the same
`tools/autofix/bin/` scripts and the same run ledger, with **only** the outward
layer and Stage 5's triage mode replaced by the overrides below. Where a stage's mechanical behavior is
unchanged, follow the base skill verbatim; do not fork it here. If anything in
this file appears to conflict with a *safety* rule in the base skill, the
stricter rule wins.

The deliverable is unchanged: **a draft PR + evidence trail.** Never `pr-ready`,
never merge, never a non-draft PR.

## What makes this skill different (and nothing else does)

Base `autofix` bars Security-labeled issues at Stage 0 (`intake.sh check` exit
2, non-bypassable by any flag). `autofix-secure` is the single deliberately-
invoked entry point where such an issue is eligible — and in exchange it (1)
inverts that one eligibility rule, (2) confirm-gates every *disclosing* write,
(3) applies disclosure hygiene to every public artifact, (4) runs Stage 5's
self-review interactively, with the operator triaging, and (5) has a blind
reader check the PR and each prose write after it for what it gives away.
Everything else — worktree isolation, the red/green signal, root-phpcs,
impact-review, the ≥2-AI-reviewer floor, the resumable ledger — is inherited
unchanged.

Start the run with the dedicated secure entry point, which requires the
`AUTOFIX_SECURE=1` **entry ack** and initializes a ledger with `.mode="secure"`
/ `.secure=true`:

```
AUTOFIX_SECURE=1 tools/autofix/bin/autofix run-secure <ISSUE-ID>
```

**The three secure signals, and what each is (not defense-in-depth — all three
are agent-settable):**
- `AUTOFIX_SECURE=1` (env) — the **entry ack**; without it `run-secure` refuses.
  It gates *entry only*; it does **not** drive per-write enforcement.
- `.secure=true` (ledger) — the **enforcement** source of truth. Every gated
  script reads it from the run's ledger (fail-closed), so the gates stay active
  after a `resume` in a fresh shell with no env var.
- `--confirmed=<digest>` (flag) — **operator approval of one specific artifact**
  (see Override 2). You pass it only after the operator has seen the preview.

## Override 1 — Eligibility inversion (Stage 0)

- Requires an **explicit issue ID** — there is no queued or scanned path into
  this skill, ever. Only operator-named invocation.
- A Security label is **eligible here and only here**. The base `autofix run`
  path still exit-2's on a Security label; `run-secure` uses the dedicated
  `intake.sh check-secure` path so the base tool's guarantee stays literally
  intact. (For a Security issue that already has an open PR, `check-secure`
  requires both `AUTOFIX_SECURE_ALLOW_EXISTING_PR=1` and `--allow-existing-pr`
  — a deliberate double-signal.)
- Every **other** Stage-1 no-go rubric criterion (a–f) and every hard safety
  rule bind **unchanged**. Secure mode relaxes the Security-label bar and
  nothing else.

## Override 2 — Confirm-gate the disclosing writes (not local commits, not assign/status)

The gate covers **disclosing writes only**: the branch **push**, the **draft PR**
open + body, the **self-review summary comment** on the PR (Override 5),
**every Linear comment** (claim, bail, closeout — Overrides 3 & 4), and the
**Copilot** request. It does **not** cover local `git commit`s (they
disclose nothing until a push — keep the TDD loop fast; their messages are
approved in the PR preview, which lists every message and trailer) or claim-time
assign-self / move-to-In-Progress (working-state transitions the operator
authorized by invoking `run-secure`; their activity-feed notification is an
accepted, documented signal).

**How the gate works mechanically** — the two-phase, digest-bound protocol the
tooling enforces:

1. **Preview.** Invoke the gated command *without* `--confirmed`. In a secure
   run it does not perform the write; it writes the full artifact to a run-dir
   preview file, prints `GATED: <digest> <preview-file>` to stdout, and exits
   `7`. For a PR the artifact is the resolved PR body **plus the commit diff**
   that would be pushed — the real disclosure, not a filename.
2. **Halt** (see *Skill-side obligations*) and surface the preview file to the
   operator.
3. **Confirm.** Only after the operator approves, re-invoke with
   `--confirmed=<digest>` (the digest from the `GATED:` line). The tooling
   recomputes the digest and refuses if the artifact changed since preview, so
   approval binds to the exact bytes.

Gate decisions (preview / confirmed) are logged to `stage_history`
automatically. Decline Copilot with `--no-copilot` on `pr.sh create`.

## Override 3 — Claim comment is operator-gated (Stage 0)

The base claim protocol is: assign-self → move **In Progress** → post
`🤖 autofix run <run-id> started`. Here:

- **assign-self and move-to-In-Progress happen automatically** — they carry no
  exploit content and establish ownership.
- **`run-secure` defers the claim comment**: it does not post one, records
  `claim_comment=deferred`, writes a preview, and verifies ownership by assignee
  alone (accepting a weaker cross-machine race check — the run's acknowledged
  weakest point). If the operator later wants the claim comment posted, route it
  through `claim.sh comment <ISSUE> <RUN> --body-file <f> --confirmed=<digest>`
  like any other gated comment; it must satisfy the disclosure checklist below.

## Override 4 — Disclosure hygiene on every public artifact

The base redaction gate (`redact.sh`) scans for **secrets** and still runs,
unchanged, before Stage 5 review and before every push. Layer this orthogonal
discipline — **don't telegraph the vulnerability** — on top:

- **Stage 1/2 bail briefs are NOT auto-posted.** `claim.sh release` in a secure
  run performs the conditional state restore (ungated) but **gates the
  `np-agent-failed` label and the brief**: invoked without `--confirmed` it
  restores state, previews the brief, and exits `7`. Surface the preview to the
  operator; post the approved brief (and label) via a confirmed re-invocation
  (`release … --confirmed=<digest>`). Route any standalone comment through
  `claim.sh comment` so redaction + gate + audit apply — never raw MCP.
- **Stage 4 adjacent-input follow-ups are drafted, not auto-filed.** A
  cross-repo sibling found by the adjacent-input probe becomes a *draft*
  follow-up for the operator (an issue's title and text can leave Linear, by a
  sync to GitHub or by being quoted elsewhere, and telegraph the sibling flaw),
  not an auto-created issue. The draft holds a neutral title, the sibling repo
  and a short description; the full account goes to the run report, and
  Override 6 probes the draft before the operator sees it.
- **Stage 6 PR + closeout** wording is neutral (below); PoC/exploit payloads
  and any fuzzing harness stay under the run dir, **never committed**. The
  regression test that lands is the minimal behavioral assertion under a
  **neutral name**.
- **The self-review summary comment** gets the checklist below before its
  preview. Its Accepted lines ("what changed and why") are where a review's
  account of the flaw leaks into a public comment. Edit the run-dir copy, never
  self-review's own `summary.md`, and keep its closing marker line.
- **The Declined and deferred findings** base Stage 6 cuts from that comment go
  to the Stage 7 run report, not the Linear closeout: on a Security issue they
  are the same kind of content as a sibling flaw, and the closeout can leave
  Linear the same way.
  The neutral line left in their place is a bare count, with no location: no
  reviewer can open the run report.
- **The closeout's urgency assessment** is one fixed line, "Points to the
  <flow> flow.", with no location, for the reason given in the bullet above.
  Every signal the base skill lists goes to the run report instead. On a
  Security issue each signal can mark the change as a security fix, which
  Override 6 reads as standing: the priority follows severity, and the
  provenance follows the priority or the report's source. The wording stays
  fixed so that no closeout hints by what it leaves out. The issue page shows
  its own labels and priority.
- **Write those signals to `<run-dir>/signals.md` before the closeout posts**,
  one line each, with the flow they point to. The run report is written at
  Stage 7, so this file is what carries them there (see *Stage 7 — what the
  run report gathers*), and whoever routes at pr-ready reads them in the
  report.
- **Exploit detail lives in the Stage 7 run report**, which `autofix report
  <RUN_ID> --init` creates in operator-local state, outside every repository,
  stamped `internal: true` — the honest technical record goes there, not on
  GitHub/Linear.

### Disclosure-hygiene checklist

Applies to every artifact that is public or can become public: code comments,
commit subjects/bodies, branch names, PR title/description, changelog entries,
test/fixture names, and any Linear text: comments, and the titles and
descriptions of drafted issues. Linear itself is internal, but
its text can leave it, by a sync to GitHub or by being quoted elsewhere, and
once out it cannot be pulled back. `run-secure` names the branch
from the issue ID alone (`nppm-1234-<4hex>`), because Linear's `branchName` is
slugged from the title; to use a descriptive stem instead, rewrite the
`branch_stem` decision before `env.sh create`, since the worktree path is
derived from it. (`newspack-plugin` and most product plugins are
**public** repos — their artifacts are the most sensitive; treat the private
`newspack-manager-admin` the same.)

- Describe the **what** neutrally — frame as ordinary correctness/validation
  (e.g. "validate the OAuth access token was issued to this site's configured
  Google client"), never the **threat**.
- Avoid the words *exploit, attacker, victim, takeover, vulnerability, proof of
  identity* on any public surface.
- Internal/gitignored artifacts (`.agent-knowledge/` plan docs, and the run
  report, which lives outside every repository)
  may stay fully detailed.
- Issue refs (`NPPM-XXXX`) are fine — the autolink is not a disclosure; keep the
  surrounding human-readable text neutral.
- Coordinated-disclosure rationale: telegraphing the flaw before it is patched
  everywhere (and the publisher-plugin update long tail has caught up) hands
  attackers a roadmap.

## Override 5 — Stage 5 self-review is interactive; its comment goes through the gate

Base Stage 5 runs `/newspack:self-review --auto`, because nobody is watching.
Here the operator is present at every gate, so run self-review interactively and
let the operator triage each round:

```
/newspack:self-review <worktree> --base origin/main
```

- Everything else in base Stage 5 holds: redaction before the first round,
  and after each fix commit a redaction re-scan and a Stage 4 re-run before the
  next round. Only blocker fixes count against the shared loop bound, as in
  base; accepting suggestions or nits here cannot escalate a clean branch.
  Self-review commits locally and never pushes, which Override 2 leaves ungated.
- Self-review asks "Keep going?" after round 3. Answering is the operator's
  call, and the shared loop bound still applies.
- **Never let self-review or `/newspack:pr-create` post the summary.** Both
  offer `gh pr comment` (or a `gh api` edit) on a yes, and neither passes the
  gate, the redaction scan or this checklist. Stage 5 runs before the PR exists,
  so self-review takes its "no PR yet" branch and posts nothing. A later round,
  after a resume or after Copilot feedback, finds the PR and makes the offer.
  Decline it every time.
- Stage 6 posts the summary with `pr.sh comment`, which gates it in a secure
  run: the preview names the PR and whether this is a new comment or an edit of
  the one already posted, so an approval covers where the text goes as well as
  what it says. After the confirmed post, hand the printed id to
  `$SR_STATE set-comment` as base Stage 5 and 6 describe.

## Override 6 — Probe the PR and later comments for disclosure before their gates

The checklist above catches the words, not the information: a neutral sentence
such as "editors can change the URL as well as administrators" names who could
abuse a flaw without any word on the list. So before the PR gate, before each
gated write after it that carries prose, before any escalation note, and on
each drafted follow-up issue, a
reader that knows nothing about the run says what weakness it can see, once
from what is already public and once with the new prose added. What the second reader finds and the first does
not came from the prose.

The PR probe reads the local diff, before `pr.sh create` pushes anything. A
claim comment gets no probe, since it says only that the run started. A Stage
1/2 bail brief gets none either: it ends the run before any fix exists, so it
reports on the issue rather than on a change. The checklist and the operator's
gate cover both. An escalation note is different, since the run can resume
after it and push a fix.

An escalation note always gets a probe, at whatever stage the run escalates.
Check whether the branch is on the remote with `git ls-remote --exit-code
--heads origin <branch>`, because a `pr.sh create` that failed may still have
pushed it. Only exit 0 shows the branch there, and then the note gets the
later probe below. Exit 2 means it is not there. Any other failure means the
check did not run, so treat the branch as not pushed: the later probe
discounts what the diff shows, which is safe only once the diff is public,
while the note probe below at worst over-reports. Either way the diff may not
be public yet, but a claim comment or an earlier note may be, so the note is
read beside those: write `weakness.md` (below) if it is not there yet, and
build `<run-dir>/probe/note-<n>/` with `public-text.md` holding the claim
comment and every escalation note already posted, and `new-text.md` holding
this note. Run one reader on that directory with the prompt below and compare
its reply with `weakness.md`. Any of the four points below that it names,
quoting `new-text.md` for it,
is **prose discloses**, handled as for the PR within the same two-rewrite
limit. Anything else is clean. Record the reading in the ledger as for the PR.

Write the run's own account of the weakness first, if the run report does not
already hold it: `<run-dir>/weakness.md`, with who could abuse it, with what
input, and to what effect. You compare the replies against it. It sits beside
the readers' directories, so only their prompt keeps them from it.

**Each drafted follow-up issue** (Stage 4) describes a sibling flaw that is
not fixed anywhere, so there is no diff to read it against and no point at
which it becomes public. Its title travels furthest, since a cited issue ID
expands to it, and a sync to GitHub carries the description too. Write the full account first, as
`<run-dir>/follow-up-<n>.md`: the sibling repo and where the flaw is, and who
could abuse it, with what input, to what effect. Then build
`<run-dir>/probe/follow-up-<n>/draft.md` with only the draft's title, the
sibling repo and its short description, and run one reader on that directory
with this prompt instead of the one below:

> You are looking at the title and description of an issue in a public
> open-source project's tracker. Read only the files in `<dir>`. Do not read
> any other file or directory, do not run git or any other command against a
> repository, and do not search the web. Question: does this text point to a
> security weakness? If you think it does, say what someone with less access
> than a site administrator could do, and how: who, what input, what effect.
> Give your best guess even if you are unsure. Also say whether the text calls
> this security work: a vulnerability, where a report came from, or how severe
> it is. Reply with: a verdict (security weakness / probably / probably not /
> not); the weakness in two or three sentences, or "none"; what the text says
> about it being security work, quoting the words you rely on, or "nothing";
> your confidence, 0–100; and up to five short quotes that led you to it.

Save the reply verbatim as `<run-dir>/probe/follow-up-<n>-<k>.md` and compare
it with `follow-up-<n>.md` on the four points below. Any point it names,
quoting the draft for it, is **prose discloses**: rewrite the title or
description and run the reader again, within the run's two-rewrite limit.
After the limit, the draft goes to the operator with the point that still
shows. Anything else is clean. Record the reading in the ledger as for the PR.

**Before the PR gate** (Stage 6, once the body is written and before the fresh
test, so that the fresh tester follows "How to test" as the probe left it),
build two probe directories under the run dir:

- `<run-dir>/probe/diff/change.diff`: `git -C <worktree> diff origin/main...HEAD`.
- `<run-dir>/probe/prose/`: the same `change.diff`, plus `public-text.md` with
  the branch name, the PR title, every commit message (`git -C <worktree> log
  --format='%s%n%n%b' origin/main..HEAD`), the PR body, and any escalation
  note already posted.

Dispatch one subagent per directory, each with only this prompt and its
directory's path. Only the prompt keeps a reader inside its directory, as with
the fresh tester; a reply that quotes anything from outside it is void, so
dispatch that reader again.

> You are looking at a change to a public open-source WordPress plugin
> repository. Read only the files in `<dir>`. Do not read any other file or
> directory, do not run git or any other command against a repository, and do
> not search the web. Question: does this change close a security weakness? If
> you think it does, say what someone with less access than a site
> administrator could have done before the change, and how: who, what input,
> what effect. Give your best guess even if you are unsure. Also say whether
> any text calls this a security change: that it fixes a vulnerability, where a
> report came from, or how severe it is. Reply with: a verdict (security fix /
> probably security / probably not / not security); the weakness in two or
> three sentences, or "none"; what the text says about it being a security
> change, quoting the words you rely on, or "nothing"; your confidence, 0–100;
> and up to five short quotes that led you to it, each tagged code, code
> comment, test name, or prose.

Save each reply verbatim, before you compare or summarize it, as
`<run-dir>/probe/<dir>-<n>.md`. A later probe uses it as its baseline, and a
condensed copy drops what the next comparison needs. Compare each with
`weakness.md` on three points: who, what input, what effect. A fourth point,
**standing**, is what the reply quotes the text for in saying this is a security
change. `weakness.md` does not hold it, and a reader names it only by quoting
prose, a code comment or a test name; a verdict reached from code alone does
not. Then:

| Diff-only reader | With-prose reader | Reading |
|---|---|---|
| any | names a point the diff-only reader missed, quoting prose for it | **prose discloses** |
| any | names a point the diff-only reader missed, quoting only code, code comments or test names | **diff discloses** |
| names a point | adds nothing | **diff discloses** |
| names nothing | adds nothing | **clean** |

- **Prose discloses** → rewrite the quoted sentences where they have not been
  published yet: the PR title or body, or the note or comment in `new-text.md`
  when a note or later probe found them. Then rebuild that probe's directory
  and run its with-prose reader again. Two rewrites is the limit for the whole
  run, not for each probe; after the second, go to the gate anyway and say
  which point still shows. A quoted commit message is not yours to reword,
  because that moves HEAD past the commit self-review passed: name it to the
  operator at the gate, who decides whether to reword it and run self-review
  again. An escalation note quoted from `public-text.md` is already posted, so
  name it to the operator too. When commit messages and posted notes are the
  only quotes, go to the gate without a rewrite.
- **Diff discloses** is not a wording problem: a regression test has to
  contain the input that triggers the bug. A quoted code comment or test name
  goes to the operator at the gate, like a commit message, since rewording it
  also moves HEAD past the pass. The finding is for the operator, who decides
  when the push should happen.
- **Any with-prose input changes after the probe** (the PR title or body, the
  branch, or the diff: a *steps wrong* rewrite, a "Not checked on a local
  site" line, a Stage 3 re-entry) → probe
  again before `pr.sh create`: both readers if the diff changed, otherwise the
  with-prose reader alone. If that reading sends you to rewrite steps, the
  fresh test runs again on them. A fresh test that reruns only because of a
  disclosure rewrite does not count toward `.loop_counts.fresh_tests`; the
  two-rewrite limit above is what bounds it.
- Record each reading with
  `ledger.sh set <RUN_ID> '.decisions += [{key:"disclosure_probe", value:$v, basis:$b}]' --arg v '<reading>' --arg b '<reply path>'`. Quote the reading: two of them are two words.

At the `GATED:` halt, give the operator the reading and the points each reader
named, next to the preview file. Neither goes into the preview or any public
artifact.

**Before each later gated write that carries prose** (the self-review summary
comment, the Linear closeout, and an escalation note after the push), copy
the PR's last `<run-dir>/probe/prose/` to `<run-dir>/probe/later-<n>/` and
add the new text as `new-text.md`. Then rebuild the rest from what is public:

- If no PR was opened, check the remote first, as for an escalation note.
  Unless the check shows the branch there, probe the text as an escalation
  note before the push (above) instead, and stop here.
- Fetch the branch into a ref of its own: `git -C <worktree> fetch origin
  +refs/heads/<branch>:refs/probe/<branch>`. If the fetch fails, the run cannot
  tell what is public, so probe the text as an escalation note before the
  push instead.
- `change.diff`: `git -C <worktree> diff origin/main...refs/probe/<branch>`,
  since a local commit not yet pushed is not public.
- `public-text.md`: the branch name, the PR title and body as they now stand
  (none if no PR was opened), the commit messages of the pushed range only
  (`git -C <worktree> log --format='%s%n%n%b' origin/main..refs/probe/<branch>`),
  and every comment or note the run has already posted, such as the
  self-review summary before the Linear closeout.

The baseline is the PR's last with-prose reply, or the last diff-only reply if
no PR was opened. Run one reader on the directory with the prompt above and
compare it with the baseline. Any of the four points that it names and the
baseline missed, quoting `new-text.md` for it, is **prose discloses**, handled
as above within the same two-rewrite limit. Anything else is clean. Record the reading in the
ledger as for the PR.

Save the note and later readers' replies verbatim too, as
`<run-dir>/probe/<dir>-<n>.md`, where `<n>` counts that directory's readers,
so a rerun never overwrites the reply an earlier reading rests on. Every probe
reply is internal detail: it stays in
the run dir, and the Stage 7 run report lists each reading.

## Stage 7 — what the run report gathers

Base Stage 7 does not name what the overrides above hold back for the report,
so a secure run's report adds each of these:

- A `## Urgency signals` section, copied from `<run-dir>/signals.md`
  (Override 4).
- The declined and deferred findings cut from the self-review summary comment
  (Override 4).
- The exploit detail, and the run's account of the weakness from
  `<run-dir>/weakness.md` (Overrides 4 and 6).
- Each probe reading, with the path of the saved reply it rests on
  (Override 6).
- A `## Follow-up drafts` section: each drafted follow-up issue as the probe
  left it, its reading, and the full account from `<run-dir>/follow-up-<n>.md`
  for the operator to add once the issue is filed (Overrides 4 and 6).

A run that stops on a bail or an escalation still writes its report, as base
Stage 7 requires, and gathers whichever of these exist by then. A run that
reaches Stage 7 with the report already written, such as one that resumes after
an escalation, brings every section up to date with the run dir and
the ledger, since a probe reading or a self-review result can postdate the
first write, and rewrites
`## Friction` from `.friction`, keeping it last.

## Hard rules (unchanged from base — never override)

All base-skill hard rules bind here in full: no `pr-ready`, no merge, no
non-draft PR; worktree isolation with the root checkout on `main`; no upstream
pushes of the tooling; no run report in any repository; the
resumable ledger is the only supported re-entry point (`autofix resume`). This
skill **adds** pauses and hygiene; it never adds authority.

## Skill-side obligations (the halt the tooling cannot enforce)

The confirm-gate is **audit + friction for a cooperative agent under
supervision, not a control** — the same agent that runs a gated command owns
the shell and could pass `--confirmed` unprompted. These obligations are the
load-bearing steps the tooling cannot enforce; honoring them is what makes the
gate real:

1. **Halt for a real operator turn on `GATED:` / exit 7.** Stop, surface the
   preview file, and get explicit approval. **Never self-issue
   `--confirmed=<digest>` in the same autonomous step** — the digest is passed
   only after the operator has seen the actual artifact.
2. **Route every Linear comment through `claim.sh comment`**, never raw MCP, so
   redaction + gate + audit apply uniformly. (Unenforced by tooling — an
   obligation, not a guarantee.)
3. **Never weaken the preview.** Surface the actual preview-file bytes to the
   operator; do not paraphrase or summarize the artifact in its place. The
   disclosure probe's reading (Override 6) goes beside them, never instead.

## Tooling reference (built — see the bin spec)

Secure behavior lives in `tools/autofix/bin/` (design + review record:
`~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-22-autofix-secure-bin-tooling-spec.md`).
Key commands: `autofix run-secure <ISSUE>` (entry), `claim.sh comment … [--confirmed=<d>]`,
`claim.sh release … [--confirmed=<d>]`, `pr.sh create … [--confirmed=<d>] [--no-copilot]`,
`pr.sh comment <RUN> --body-file <f> [--confirmed=<d>]`.
Enforcement is ledger-driven (`.secure`), fail-closed; gated writes emit
`GATED: <digest> <preview-file>` and exit 7 until confirmed.
