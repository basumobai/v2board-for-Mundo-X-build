#!/usr/bin/env bash
set -euo pipefail
output=${SECURITY_OUTPUT_DIR:-test-results/security}
mkdir -p "$output"
tool_dir=$(mktemp -d)
trap 'rm -rf "$tool_dir"' EXIT
curl --fail --silent --show-error --location --retry 3 \
    https://github.com/aquasecurity/trivy/releases/download/v0.76.1/trivy_0.76.1_Linux-64bit.tar.gz \
    -o "$tool_dir/trivy.tar.gz"
printf '%s  %s\n' c7cbe3137fb8a48a17574bede3dd6beb16742d1e9772929940fc90082c39d9c0 "$tool_dir/trivy.tar.gz" | sha256sum -c -
tar -xzf "$tool_dir/trivy.tar.gz" -C "$tool_dir" trivy
trivy="$tool_dir/trivy"
"$trivy" --version > "$output/scanner-version.txt"
"$trivy" fs --scanners vuln --format cyclonedx --output "$output/application-sbom.cdx.json" .
"$trivy" --version > "$output/scanner-version.txt"
failed=0
HARDENED_DB_HOST=${HARDENED_DB_HOST:-host.docker.internal} docker compose -f compose.hardened.yaml build --pull gateway
php_image=${SECURITY_PHP_IMAGE:-$(docker compose config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["web"]["image"])')}
nginx_image=$(docker compose config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["gateway"]["image"])')
unprivileged_image=$(HARDENED_DB_HOST=${HARDENED_DB_HOST:-host.docker.internal} docker compose -f compose.hardened.yaml config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["gateway"]["image"])')
for entry in "php|$php_image" \
    "nginx|$nginx_image" "nginx-unprivileged|$unprivileged_image" 'redis|redis:7-alpine'; do
    name=${entry%%|*}; image=${entry#*|}
    if [[ $name == redis ]]; then docker pull "$image"; fi
    docker image inspect --format '{{.Id}} {{json .RepoDigests}}' "$image" > "$output/$name-image.txt"
    "$trivy" image --scanners vuln --format cyclonedx --output "$output/$name-sbom.cdx.json" "$image"
    # Retain unfixed findings as well. A missing vendor patch is not an exemption.
    "$trivy" image --scanners vuln --severity HIGH,CRITICAL --format json \
        --output "$output/$name-vulnerabilities.json" --exit-code 1 "$image" || failed=1
    python3 - "$output/$name-vulnerabilities.json" <<'PY'
import json, sys
with open(sys.argv[1]) as report:
    data = json.load(report)
count = sum(len(result.get('Vulnerabilities', [])) for result in data.get('Results', []))
print(json.dumps({'image': data.get('ArtifactName'), 'high_critical_findings': count}))
for result in data.get('Results', []):
    for finding in result.get('Vulnerabilities', []):
        print(result['Target'], finding['VulnerabilityID'], finding['PkgName'], finding['Severity'],
              'installed=' + finding['InstalledVersion'], 'fixed=' + finding.get('FixedVersion', 'unavailable'))
PY
done
exit "$failed"
