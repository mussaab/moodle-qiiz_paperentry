# Paper Entry — Quiz Report Plugin for Moodle

Enables paper-based quiz administration in Moodle 5.0+. Students sit a regular paper
exam. Afterwards, a manager (or assigned graders) exports a grading sheet,
fills it in with the students' answers from the collected papers, then imports it to
automatically create graded quiz attempts in Moodle.

📖 **Full documentation:** https://your-org.github.io/moodle-quiz-paperentry/

---

## Two workflows

### Workflow A — Direct import (single manager)

The simplest path: the manager grades the papers themselves and imports the results
directly without involving other teachers.

1. **Exam** — students sit their regular paper exam.
2. **Export** — download the grading sheet from the *Paper Entry* tab.
3. **Fill** — enter student answers from the collected paper sheets into the CSV.
4. **Import** — use the *Direct Import — Admin Override* section to upload and commit
   the CSV immediately to the gradebook.

Best for: small classes, single-teacher setups, or emergency re-grades.

---

### Workflow B — Multi-grader with comparison (recommended for exams)

Assigns two or more teachers to grade independently. The plugin highlights every
disagreement before anything is committed to the gradebook.

1. **Configure** — choose extra student profile columns (ID number, class, etc.).
2. **Assign graders** — add independent teachers in the *Graders* panel.
3. **Exam** — students sit their regular paper exam.
4. **Grade** — each grader independently downloads the grading sheet, fills it in with
   student answers from the collected paper sheets, and uploads it via *Submit Your Answers*.
5. **Compare** — graders see their own conflict status (colour-coded, without seeing
   other graders' answers) and re-upload until all cells are green. The manager
   monitors the full comparison table with all graders' answers side-by-side.
6. **Submit** — once all graders agree, click *Submit to Gradebook* to create graded
   quiz attempts for every student in one click.

Best for: formal exams, quality-assurance double-marking, moderated assessments.

---

## Features

- **Grading sheet export** — a pre-populated CSV with all enrolled students and
  configurable profile columns (email, ID number, custom profile fields); graders
  just fill in the answer columns.
- **Direct import override** — managers can bypass the grader workflow and import a
  CSV immediately (Workflow A).
- **Multi-grader workflow** — managers assign independent graders; each grader
  uploads their own filled sheet; the plugin shows a colour-coded comparison table
  highlighting every disagreement (Workflow B).
- **Gated submission** — the "Submit to Gradebook" button is enabled only when all
  assigned graders have uploaded and every answer agrees.
- **Value substitutions** — managers can define shorthand mappings (e.g. `1 → a`,
  `T → True`) before submitting so graders can use any notation they prefer.
- **Edit Answer** — managers can correct a single student's answer in-place without
  re-uploading a full CSV.
- **Shuffle-answer detection** — the export button is disabled if any multichoice
  question has "Shuffle answers" turned on, preventing answer-label mismatches.
- **Two-pass validation** — all CSV rows are validated before anything is written to
  the database; a single bad answer aborts the entire import cleanly.
- **Privacy API compliance** — full GDPR support: data export and deletion for
  students (attempt tracking), graders (assignment records and uploaded files).
- **Moodle Component Library** — all JavaScript uses AMD modules; all output uses
  Mustache templates; fully CSP-compliant.
- **Cross-DB compatible** — all database access uses the Moodle DBAL (`$DB` API);
  no raw SQL with vendor-specific syntax.

---

## Requirements

| Requirement | Version |
|---|---|
| Moodle | 5.0 or later |
| Quiz questions | `multichoice` and/or `truefalse` only |

---

## Installation

1. Place the `paperentry` directory at `mod/quiz/report/paperentry/` inside your
   Moodle root.
2. Log in as a site administrator and go to **Site administration → Notifications**.
3. Follow the on-screen upgrade steps to create the required database tables.

No additional site-level configuration is needed.

---

## Capabilities

| Capability | Default roles | Description |
|---|---|---|
| `quiz/paperentry:view` | teacher, editingteacher, manager | View the report tab and download grading sheets |
| `quiz/paperentry:submit` | *(none — assigned per-quiz by manager)* | Upload a grader answer sheet (Workflow B) |
| `quiz/paperentry:manage` | manager | Full control: settings, graders, comparison, import |

---

## Backup & Restore

> **Note — known architectural limitation of Moodle quiz report plugins:**
>
> Moodle's quiz backup engine only invokes `quizaccess` subplugins during backup;
> quiz **report** subplugins (including this one) are not called. This is a gap in
> Moodle core, not something this plugin can work around without patching core.
>
> **What IS backed up** (by Moodle's standard quiz backup):
> - All student attempts and grades created by this plugin — they are stored as
>   standard `quiz_attempts` records and are fully included in every quiz backup.
>
> **What is NOT backed up:**
> - Grader assignments (`quiz_paperentry_graders`)
> - Plugin settings — extra export fields (`quiz_paperentry_settings`)
> - Grader CSV submission files
>
> After restoring a quiz, student grades are intact. A manager needs to
> re-assign graders and re-configure export settings (a few minutes of work).

---

## Security

- All actions require `confirm_sesskey()`.
- Capabilities enforce role separation between managers and graders.
- CSV exports use `csv_safe()` to prevent formula injection (Excel/LibreOffice).
- All JavaScript is served through `$PAGE->requires->js_call_amd()` — no inline
  scripts; fully CSP-compliant.
- Grader CSV files are stored via the Moodle File API, not as raw text in the DB.

---

## Privacy (GDPR)

This plugin implements `core_privacy\local\request\plugin\provider` and
`core_userlist_provider`. It handles:

- **Students** — attempt tracking records (which attempts were created by this tool).
- **Graders** — grader assignment records and uploaded CSV files.

Data is exported and deleted on request via Moodle's Privacy API.

---

## Automated Testing

```bash
# PHPUnit
vendor/bin/phpunit mod/quiz/report/paperentry/tests/

# Behat
php admin/tool/behat/cli/run.php --tags=@quiz_paperentry
```

---

## Contributing

Pull requests are welcome. Please run the Moodle code checker before submitting:

```bash
php local/codechecker/cli/check.php mod/quiz/report/paperentry
```

---

## License

GNU General Public License v3 or later —
see <https://www.gnu.org/copyleft/gpl.html>.

---

## Author

[Mossaab Mohamed Ali](https://mussaab.com) — <mosab@mussaab.com>
