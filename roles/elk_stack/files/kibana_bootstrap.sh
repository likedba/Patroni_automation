#!/bin/bash
# Kibana Bootstrap: Data View + Saved Searches + Dashboards
# Managed by Ansible — idempotent (safe to run multiple times)
#
# Uses Python for JSON generation + Kibana Import API for proper migration
set -euo pipefail

KIBANA="http://127.0.0.1:5601"
H_XSRF="kbn-xsrf: true"
H_JSON="Content-Type: application/json"
DV_ID="filebeat-data-view"
NDJSON="/tmp/kibana_objects.ndjson"

# ── 1. Data View ──────────────────────────────────────────────
echo "=== Creating Data View: filebeat-* ==="
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST "$KIBANA/api/data_views/data_view" \
  -H "$H_XSRF" -H "$H_JSON" \
  -d '{
    "data_view": {
      "id": "'"$DV_ID"'",
      "title": "filebeat-*",
      "timeFieldName": "@timestamp",
      "name": "Filebeat Logs"
    },
    "override": true
  }')

if [ "$HTTP_CODE" = "200" ]; then
  echo "  Data View created/updated OK"
elif [ "$HTTP_CODE" = "409" ]; then
  echo "  Data View already exists (OK)"
else
  echo "  WARNING: Data View creation returned HTTP $HTTP_CODE"
fi

# ── 2. Generate NDJSON via Python (bulletproof JSON escaping) ──
echo "=== Generating saved objects NDJSON ==="

python3 << 'PYEOF' > "$NDJSON"
import json

DV_ID = "filebeat-data-view"
REF_NAME = "kibanaSavedObjectMeta.searchSourceJSON.index"

def make_search(sid, title, kql):
    search_source = json.dumps({
        "query": {"query": kql, "language": "kuery"},
        "filter": [],
        "indexRefName": REF_NAME
    })
    return json.dumps({
        "id": sid,
        "type": "search",
        "attributes": {
            "title": title,
            "columns": ["host.name", "message", "log.file.path"],
            "sort": [["@timestamp", "desc"]],
            "hideChart": False,
            "isTextBasedQuery": False,
            "kibanaSavedObjectMeta": {
                "searchSourceJSON": search_source
            }
        },
        "references": [
            {"id": DV_ID, "name": REF_NAME, "type": "index-pattern"}
        ]
    })

def make_dashboard(did, title, search1, search2):
    panels = json.dumps([
        {
            "gridData": {"x": 0, "y": 0, "w": 24, "h": 15, "i": "1"},
            "panelIndex": "1",
            "panelRefName": "panel_0",
            "type": "search",
            "embeddableConfig": {"enhancements": {}},
            "version": "8.17.0"
        },
        {
            "gridData": {"x": 24, "y": 0, "w": 24, "h": 15, "i": "2"},
            "panelIndex": "2",
            "panelRefName": "panel_1",
            "type": "search",
            "embeddableConfig": {"enhancements": {}},
            "version": "8.17.0"
        }
    ])
    return json.dumps({
        "id": did,
        "type": "dashboard",
        "attributes": {
            "title": title,
            "panelsJSON": panels,
            "timeRestore": False,
            "version": 1
        },
        "references": [
            {"name": "panel_0", "type": "search", "id": search1},
            {"name": "panel_1", "type": "search", "id": search2}
        ]
    })

# ── Saved Searches ──
searches = [
    ("infra-all",       "Infra: All logs (latest)",         ""),
    ("infra-errors",    "Infra: Errors & Warnings",         'message: ("ERROR" or "FATAL" or "PANIC" or "WARN")'),
    ("patroni",         "DB: Patroni logs",                 'log.file.path: "/pgdata/patroni/patroni.log"'),
    ("postgres-errors", "DB: PostgreSQL ERROR/FATAL/PANIC", 'log.file.path: "/pgdata/18/data/log/*" and message: ("ERROR" or "FATAL" or "PANIC")'),
    ("auth-all",        "Auth: /var/log/auth.log",          'log.file.path: "/var/log/auth.log"'),
    ("auth-failed",     "Auth: Failed password",            'log.file.path: "/var/log/auth.log" and message: "Failed password"'),
]

for sid, title, kql in searches:
    print(make_search(sid, title, kql))

# ── Dashboards ──
dashboards = [
    ("infra-overview", "Infra Logs Overview",          "infra-all",  "infra-errors"),
    ("db-logs",        "PostgreSQL / Patroni Logs",    "patroni",    "postgres-errors"),
    ("auth-ssh",       "Auth / SSH Logs",              "auth-all",   "auth-failed"),
]

for did, title, s1, s2 in dashboards:
    print(make_dashboard(did, title, s1, s2))
PYEOF

OBJ_COUNT=$(wc -l < "$NDJSON")
echo "  Generated $OBJ_COUNT objects"

# ── 3. Import via Kibana Import API ───────────────────────────
echo "=== Importing saved objects ==="
IMPORT_RESULT=$(curl -s \
  -X POST "$KIBANA/api/saved_objects/_import?overwrite=true" \
  -H "$H_XSRF" \
  --form file=@"$NDJSON")

# Parse import result
SUCCESS=$(echo "$IMPORT_RESULT" | python3 -c "import sys,json; r=json.load(sys.stdin); print(r.get('successCount', 0))" 2>/dev/null || echo "0")
ERRORS=$(echo "$IMPORT_RESULT" | python3 -c "import sys,json; r=json.load(sys.stdin); errs=r.get('errors',[]); [print(f'  ERROR: {e[\"id\"]} — {e.get(\"error\",{}).get(\"message\",\"unknown\")}') for e in errs]" 2>/dev/null || true)

echo "  Imported: $SUCCESS objects"
if [ -n "$ERRORS" ]; then
  echo "$ERRORS"
fi

# Cleanup
rm -f "$NDJSON"

# ── 4. Verification ──────────────────────────────────────────
echo ""
echo "=== Verification ==="
DASH_COUNT=$(curl -s "$KIBANA/api/saved_objects/_find?type=dashboard" \
  -H "$H_XSRF" | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2)
SEARCH_COUNT=$(curl -s "$KIBANA/api/saved_objects/_find?type=search" \
  -H "$H_XSRF" | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2)

echo "  Dashboards: ${DASH_COUNT:-0}"
echo "  Saved searches: ${SEARCH_COUNT:-0}"

if [ "${DASH_COUNT:-0}" -ge 3 ] && [ "${SEARCH_COUNT:-0}" -ge 6 ]; then
  echo "  Bootstrap completed successfully!"
else
  echo "  WARNING: Expected 3 dashboards + 6 searches"
  exit 1
fi
