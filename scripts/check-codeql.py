"""Gate the actual CodeQL SARIF output; successful upload alone is insufficient."""
import json
import pathlib
import sys

reports = list(pathlib.Path(sys.argv[1]).glob('*.sarif'))
if not reports:
    raise SystemExit('CodeQL did not produce a SARIF report')
findings = 0
blocked = 0
for report in reports:
    data = json.loads(report.read_text())
    for run in data['runs']:
        driver = run['tool']['driver']
        rules = {rule['id']: rule for rule in driver.get('rules', [])}
        for result in run.get('results', []):
            findings += 1
            rule = rules.get(result.get('ruleId'), {})
            severity = float(rule.get('properties', {}).get('security-severity', 0))
            level = result.get('level', rule.get('defaultConfiguration', {}).get('level', 'warning'))
            critical = severity >= 7 or level == 'error'
            blocked += int(critical)
            print(json.dumps({'rule': result.get('ruleId'), 'security_severity': severity,
                              'level': level, 'blocked': critical, 'locations': result.get('locations', [])}))
print(f'CodeQL: {findings} findings, {blocked} high severity/error findings')
raise SystemExit(1 if blocked else 0)
