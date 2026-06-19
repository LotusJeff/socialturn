# DEVELOPMENT_PROCESS.md

> Claude Code: read CLAUDE.md before every session to orient on project state, active phase, and open issues.

---

## Role Boundaries

**Claude Chat — defines what, never how**
- Understands data model and behavior before any change is designed
- Identifies architectural impact, risk, and interactions with existing code
- Writes instructions at intent or pseudocode level only — never production code, never over-specified implementation detail
- Makes decisions; approves or rejects Code's proposed implementation
- Never approves a change until the full behavioral picture is understood

**Claude Code — implements what was approved, proposes how**
- Reads files; reports findings accurately and completely
- Proposes implementation for Chat's approval before writing anything
- Writes exactly what was approved — nothing more
- Flags conflicts or impacts observed before writing
- Waits for explicit approval at every stage boundary
- Never assumes a decision — asks

**If discovery reveals incomplete information: more discovery before any design. A partial picture is not a basis for a decision.**

---

## Process: Plan → Review → Confirm → Build

### Stage 1: Feature Definition (Chat)
Define before touching code:
- Problem and who it affects
- Edge cases and fragile assumptions
- Fit with CLAUDE.md architecture — conflicts?
- Dependencies — what must exist first? v1 or v2?
- Scope — what's included, what's explicitly excluded
- Candidate approaches with tradeoffs

Produce a written feature description. Get explicit approval before Stage 2.

---

### Stage 2: Section Planning (Chat)
Break work into sections (buildable units within a phase).

Each section must:
- Be independently usable
- Have clear input/output boundaries
- Build dependencies before dependents

Produce a numbered list with scope boundaries. No code yet.

---

### Stage 3: Build Cycle (repeats per section)

**Step 0 — Full Behavioral Discovery (before designing any fix)**

Code must report all four before Chat designs anything:
1. Target location and its current behavior
2. Every other location that touches the same data, table, or side effect
3. Any existing mechanisms that already handle the behavior, even incidentally
4. All callers, dependents, and downstream consumers of the target code

**Step 1 — Write Instructions (Chat)**

Every instruction set must include:
- "Present [plan/schema/structure] for review before writing any code."
- "Do not proceed to [next step] until I confirm [current step]."
- "Do not change anything else in [file] — only the specified changes."

**Step 2 — Code Presents Plan (Code)**

Read relevant files. Present — never write:
- Full proposed structure, schema, or implementation plan
- Architectural conflicts or observations
- Open questions requiring decisions
- All files to be created or modified

**Step 3 — Plan Review (Chat)**

Check: matches intent? schema correct? security respected? edge cases covered?

- **Approved** — proceed as presented
- **Approved with changes:** [specifics]
- **Revise:** [issues]

**Step 4 — Code Writes (Code)**

Implement exactly what was approved. Then:
- Confirm file locations
- Summarize what was built
- Note implementation decisions made
- Flag anything needing attention
- Wait for confirmation before anything else

**Step 5 — Work Review (Chat)**

Check: all approved changes made? unexpected files touched? follow-on items?

Explicit confirmation: `[Section X] confirmed.`

**Step 6 — Document and Commit (Code)**

Before committing, update documentation:
- **CHANGELOG.md** — every bug fix, feature addition, and migration. Always.
- **CLAUDE.md** — any architectural change: new pattern, data model change, constraint, or rule that affects future decisions. If it changes how something works, where something lives, or what is forbidden — it's architectural. A change may require both.

Documentation commits with the code, not after.

```
[Section X] confirmed. Commit all changes to master with descriptive message.
Confirm branch is master.
```

---

### Revert Protocol

When a written change must be reverted:
1. Stop all forward work immediately
2. Chat confirms the exact original state to be restored
3. Code presents the restored version for review — never writes without presenting first
4. Chat approves the restored version explicitly
5. Code writes the revert; confirms what changed
6. Root cause identified before any new design begins

---

### Stage 4: Phase Completion
- Update CLAUDE.md — mark phase complete, add architectural notes
- Commit with phase summary
- Review next phase scope before starting
- If ending session: note exactly where stopped and what's next

---

## Standard Templates

### Open New Session
```
Read CLAUDE.md and confirm you understand the project and current build status.
What branch are we on?
```

### Start New Phase or Section
```
Begin [Phase N / Section N] — [Name].
Before writing any code, present the complete [plan/schema/structure].
Do not write any files until I approve.
Do not proceed to [next section] until I confirm [current section].
```

### Approve Plan
```
[Plan] approved [with additions: ...].
Write [files] now.
After writing, confirm file locations and provide a brief summary.
Do not begin [next step] until I confirm.
```

### Targeted File Changes
```
Make exactly these changes to [file]. Do not change anything else.
[List changes]
Present changes before writing.
```

### Schema Changes
```
Before writing, present complete schema changes:
- Column names and types
- Primary/foreign keys
- Indexes and constraints
- Default values
- Comment per column
Do not write until I approve.
```

### Confirm and Continue
```
[Section/Phase] confirmed.
Commit to master with descriptive message.
Then present [next section] plan before writing any code.
```

---

## Decision Framework

1. State options with tradeoffs
2. Give a recommendation
3. Wait for explicit decision — never assume
4. Document in CLAUDE.md if architecture-affecting

Scope filter: v1 or v2? This phase or later? Required or nice-to-have?

---

## Quality Gates

**Before approving a plan:**
- Matches CLAUDE.md architecture?
- Dependencies covered?
- Security respected?
- Scope bounded?
- Migrations needed?
- All existing mechanisms that touch the same data or side effect identified?
- Does this change interact with working functionality? If yes, is that interaction safe?
- Data model fully confirmed — field names, tables, which actions read and write them?

**Before confirming written work:**
- All approved changes made?
- Unexpected files touched?
- New constants added to config?
- CLAUDE.md update needed?

**Before marking phase complete:**
- Clean install works?
- Migrations numbered?
- Migrations executed against live database? (File present ≠ migration run — verify both.)
- schema.sql current?
- CLAUDE.md current?
- Commit clean?

---

## Session Management

**Starting:** confirm branch → read CLAUDE.md → state current position

**Ending:** all work committed → note exactly where stopped and what's next

**CLAUDE.md is the persistent memory between sessions. Keep it current.**
