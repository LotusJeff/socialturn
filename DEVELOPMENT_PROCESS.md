
# DEVELOPMENT_PROCESS.md

> Claude Code sessions: always read CLAUDE.md first to orient on current project state, active phase, and any open bugs before beginning work.

A structured workflow for building SocialTurn features—planning before code, review at every stage, and explicit confirmation before moving forward.

## Role Boundaries

Claude Chat operates above the code level. Its responsibilities are:
- Understanding the data model and application behavior before any
  change is designed
- Identifying architectural impact, risk, and interactions with
  existing mechanisms
- Making decisions and writing precise instructions for Claude Code
- Never approving a code change until the full behavioral picture
  is understood

Claude Code operates at the code level. Its responsibilities are:
- Reading files and reporting findings accurately
- Writing exactly what was approved — nothing more
- Flagging conflicts or impacts it observes before writing
- Waiting for explicit approval at every stage

If discovery reveals incomplete information, more discovery happens
before any design or approval occurs. A partial picture is not a
basis for a decision.

## Four Stages: Plan → Review → Confirm → Build

### Stage 1: Feature Definition (Chat)
Before touching code, define the feature in conversation:

- **Problem & Users** — What problem does this solve? Who benefits?
- **Edge Cases** — What breaks easily? What assumptions are fragile?
- **Architecture Fit** — Does it align with CLAUDE.md? Any conflicts?
- **Dependencies** — What must exist first? v1 or v2?
- **Scope** — What's included? What's explicitly excluded?
- **Approach Options** — Pros and cons of candidate designs

**Output:** Written feature description approved in chat before proceeding.

---

### Stage 2: Phase/Section Planning (Chat)
Break work into phases (major milestones) and sections (buildable units).

Each section must:
- Be independently usable
- Have clear input/output boundaries
- Build dependencies first

**Output:** Numbered list with scope boundaries. No code yet.

---

### Stage 3: Build Cycle (Chat ↔ Code, Repeats Per Section)

#### Step 0: Full Behavioral Discovery (before designing any fix)

Before designing a fix or writing instructions, Claude Chat must
understand the complete behavioral picture. Discovery questions must
be broad enough to reveal all related mechanisms — not just the
target location.

Every discovery instruction must ask Claude Code to report:
- The target location and its current behavior
- Every other location that touches the same data, table, or
  side effect
- Any existing mechanisms that already handle the behavior,
  even incidentally
- Any callers, dependents, or downstream consumers of the
  target code

A fix must not be designed until all four are answered. Narrow
discovery questions that return incomplete pictures are the leading
cause of changes that break existing working functionality.

#### Step 1: Write Instructions (Chat)
Instructions always include:
- "Present [plan/schema/structure] for review before writing any code."
- "Do not proceed to [next step] until I confirm [current step]."
- "Do not change anything else in [file] — only specified changes."

#### Step 2: Code Presents Plan (Code)
Read relevant files. Present (never write):
- Full structure, schema, or plan
- Architectural conflicts or observations
- Open questions needing decisions
- All files to be created/modified

#### Step 3: Plan Review (Chat)
Check: Matches intent? Schema issues? Security? Missing edge cases?

Response options:
- **Approved** — proceed as presented
- **Approved with changes:** [specifics]
- **Revise:** [issues]

#### Step 4: Code Writes (Code)
Implement exactly what was approved. After writing:
- Confirm file locations
- Summarize what was built
- Note implementation decisions
- Flag anything needing attention
- Wait for confirmation

#### Step 5: Work Review (Chat)
Check: All approved changes made? Unexpected changes? Follow-on items?

Explicit confirmation: `[Section X] confirmed.`

#### Step 6: Commit (Code)
```
[Section X] confirmed. Commit all changes to master with descriptive message.
Confirm branch is master.
```

#### Revert Protocol

When an approved and written change needs to be reverted:
1. Stop all forward work immediately
2. Claude Chat confirms the exact original state to be restored
3. Claude Code presents the restored version for review — never
   writes a revert without presenting it first
4. Claude Chat approves the restored version explicitly
5. Claude Code writes the revert and confirms what was changed
6. Root cause is identified before any new design work begins —
   understand why the change was wrong before designing a replacement

---

### Stage 4: Phase Completion
- Update CLAUDE.md — mark phase complete, add architectural notes
- Commit with phase summary
- Review next phase scope before starting
- If ending session: generate memory log

---

## Standard Templates

### Open New Code Session
```
Read CLAUDE.md and confirm you understand the project and current build status.
What branch are we on?
```

### Start New Phase
```
Begin [Phase N] — [Name].
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

## Rules for Code

**Always:**
- Present before writing (plans, schemas, structures)
- Wait for explicit confirmation at each stage boundary
- Scope changes to only what's specified
- State explicit file locations for new files
- Apply relevant security constraints from CLAUDE.md

**Never:**
- Write without presenting a plan first
- Proceed without explicit confirmation
- Make schema changes without a migration file
- Touch files outside stated scope
- Assume a decision — ask

**Lost context?** Say so. User will paste relevant plan or describe current position. Always read CLAUDE.md first when re-orienting.

---

## Decision Framework

1. State options with pros/cons
2. Give recommendation with reasoning
3. Wait for explicit decision — never assume
4. Document in CLAUDE.md if architecture-affecting
5. Apply consistently once decided

**Scope filter:** v1 or v2? This phase or later? Required or nice-to-have? Does it break the small-footprint goal?

---

## Quality Gates

**Before approving a plan:**
- Matches CLAUDE.md architecture?
- Dependencies covered?
- Security respected?
- Scope bounded?
- Migrations needed?
- Have all existing mechanisms that touch the same data or produce
  the same side effect been identified? A correct behavior achieved
  incidentally by general-purpose code is still a correct behavior —
  do not replace or duplicate it with targeted logic until its full
  scope is understood.
- Does this change interact with any functionality that is already
  working correctly? If yes, is that interaction safe?
- Is the data model fully understood? Field names, which table they
  live in, and which controller actions read and write them must be
  confirmed before any change is approved — never assumed.

**Before confirming written work:**
- All approved changes made?
- Unexpected files touched?
- New constants in config?
- CLAUDE.md update needed?

**Before marking phase complete:**
- Clean install works?
- Migrations numbered?
- schema.sql current?
- CLAUDE.md current?
- Commit clean?

---

## Session Management

**Starting:**
- Confirm branch → read CLAUDE.md → state current position

**Ending:**
- All work committed → memory log if significant → note exactly where stopped and what's next

**CLAUDE.md is the persistent memory.** Keep it current. Code has no memory between sessions.
