
# DEVELOPMENT_PROCESS.md

> Claude Code sessions: always read CLAUDE.md first to orient on current project state, active phase, and any open bugs before beginning work.

A structured workflow for building SocialTurn features—planning before code, review at every stage, and explicit confirmation before moving forward.

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
