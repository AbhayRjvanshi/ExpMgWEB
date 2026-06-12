#!/usr/bin/env python3
"""
validate_drift_resolution.py (v1.0)

Validates that a drift report resolution performed by an agent is
complete and internally consistent. Agents mutate status, answered,
user_answer, resolved_at and user_exceptions by hand - this validator
guarantees the audit trail cannot silently rot.

Usage:
    python .agents/core/validators/validate_drift_resolution.py [project_root] [report_path]

    report_path defaults to .agents/orchestration/drift_report.json
    (the latest report).

Exit codes:
    0   Resolution is valid and consistent
    1   Error (file missing / unparseable)
    2   Validation failures (listed in stdout JSON)

Checks:
    1. Required fields present; status is a known enum value.
    2. status == pending_user_response  -> resolved_at must be null.
    3. status is any resolved_* value   ->
         - every user_questions entry has answered == true and a
           non-null, non-empty user_answer
         - resolved_at is a non-empty string
    4. user_exceptions consistency:
         - status == resolved_exceptions_noted requires a non-empty
           user_exceptions list
         - a non-empty user_exceptions list requires status in
           (resolved_exceptions_noted, resolved_rerun) - mixed outcomes
           where some signals were temporary and others significant
           resolve as resolved_rerun
         - every exception entry must match a user_questions[].signal
           key in this report
    5. Archive parity: drift_reports/<report_id>.json exists and is
       structurally equal to the report being validated. The comparison
       is between PARSED dicts, so key ordering, whitespace and
       serialization formatting are irrelevant - only genuine content
       differences fail. This strictness is deliberate: comparing only
       a subset of fields would let the archive silently diverge in
       unchecked fields and undermine the audit guarantee.

Warnings (reported, never fatal):
    - status == resolved_exceptions_noted but
      project_snapshot.json accumulated_commit_weight != 0 (the policy
      says the counter resets; new commits may legitimately have
      accumulated weight again, so this is advisory only).
"""

import json
import os
import sys

VALID_STATUSES = frozenset([
    'pending_user_response',
    'resolved_rerun',
    'resolved_no_action',
    'resolved_exceptions_noted'
])

RESOLVED_STATUSES = frozenset([
    'resolved_rerun',
    'resolved_no_action',
    'resolved_exceptions_noted'
])


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def fail(message):
    print(json.dumps({'error': message}))
    sys.exit(1)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('-')]
    project_root = os.path.abspath(args[0] if args else '.')
    report_path = (os.path.abspath(args[1]) if len(args) > 1 else
                   os.path.join(project_root, '.agents',
                                'orchestration', 'drift_report.json'))
    reports_dir = os.path.join(
        project_root, '.agents', 'orchestration', 'drift_reports')
    snapshot_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'project_snapshot.json')

    try:
        report = load_json(report_path)
    except FileNotFoundError:
        fail('Report not found at {0}'.format(report_path))
    except json.JSONDecodeError as e:
        fail('Cannot parse report: {0}'.format(e))

    failures = []
    warnings = []

    # -- 1. Required fields and status enum --
    status = report.get('status')
    report_id = report.get('report_id')
    if not report_id:
        failures.append('report_id is missing or empty.')
    if status not in VALID_STATUSES:
        failures.append(
            "status '{0}' is not one of {1}.".format(
                status, sorted(VALID_STATUSES)))

    questions = report.get('user_questions', [])
    exceptions = report.get('user_exceptions', [])
    resolved_at = report.get('resolved_at')

    # -- 2 & 3. Status-dependent consistency --
    if status == 'pending_user_response':
        if resolved_at is not None:
            failures.append(
                'resolved_at must be null while status is '
                'pending_user_response.')
    elif status in RESOLVED_STATUSES:
        for i, q in enumerate(questions):
            if not q.get('answered'):
                failures.append(
                    'user_questions[{0}] (signal: {1}) is not '
                    'answered but status is resolved.'.format(
                        i, q.get('signal', '?')))
            answer = q.get('user_answer')
            if answer is None or (isinstance(answer, str)
                                  and not answer.strip()):
                failures.append(
                    'user_questions[{0}] (signal: {1}) has no '
                    'recorded user_answer.'.format(
                        i, q.get('signal', '?')))
        if not (isinstance(resolved_at, str) and resolved_at.strip()):
            failures.append(
                'resolved_at must be set when status is resolved.')

    # -- 4. Exception consistency --
    if status == 'resolved_exceptions_noted' and not exceptions:
        failures.append(
            'status is resolved_exceptions_noted but user_exceptions '
            'is empty.')
    if exceptions and status not in ('resolved_exceptions_noted',
                                     'resolved_rerun'):
        failures.append(
            'user_exceptions is non-empty but status is {0}; expected '
            'resolved_exceptions_noted or resolved_rerun.'.format(
                status))
    question_signals = {q.get('signal') for q in questions}
    for exc in exceptions:
        if exc not in question_signals:
            failures.append(
                "user_exceptions entry '{0}' does not match any "
                'user_questions[].signal key in this report.'.format(
                    exc))

    # -- 5. Archive parity --
    if report_id:
        archive_path = os.path.join(
            reports_dir, '{0}.json'.format(report_id))
        if not os.path.isfile(archive_path):
            failures.append(
                'Archived copy {0} does not exist.'.format(
                    archive_path))
        else:
            try:
                archived = load_json(archive_path)
                if archived != report:
                    failures.append(
                        'Archived copy differs from the latest report. '
                        'Apply the same resolution edits to {0}.'.format(
                            archive_path))
            except json.JSONDecodeError as e:
                failures.append(
                    'Archived copy is unparseable: {0}'.format(e))

    # -- Advisory: weight reset after exceptions --
    if status == 'resolved_exceptions_noted':
        try:
            snapshot = load_json(snapshot_path)
            weight = snapshot.get('accumulated_commit_weight')
            if weight not in (0, None):
                warnings.append(
                    'accumulated_commit_weight is {0}, expected 0 '
                    'after an exceptions-noted resolution (advisory - '
                    'new commits may have accumulated since).'.format(
                        weight))
        except (FileNotFoundError, json.JSONDecodeError):
            warnings.append(
                'Could not read project_snapshot.json to verify the '
                'weight counter reset.')

    result = {
        'valid': not failures,
        'report_id': report_id,
        'status': status,
        'failures': failures,
        'warnings': warnings
    }
    print(json.dumps(result, indent=2))
    sys.exit(0 if not failures else 2)


if __name__ == '__main__':
    main()
