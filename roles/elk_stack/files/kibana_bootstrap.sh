#!/bin/bash
# Kibana Bootstrap: Data View + Saved Searches + Dashboards
# Managed by Ansible — idempotent (safe to run multiple times)
set -euo pipefail

KIBANA="http://127.0.0.1:5601"
H_XSRF="kbn-xsrf: true"
H_JSON="Content-Type: application/json"
DV_ID="filebeat-data-view"

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
  echo "  Data View already exists, updating..."
  curl -s -o /dev/null \
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
    }'
  echo "  Data View updated OK"
else
  echo "  WARNING: Data View creation returned HTTP $HTTP_CODE"
fi

# ── 2. Saved Searches ────────────────────────────────────────
create_search() {
  local ID="$1"
  local TITLE="$2"
  local KQL="$3"

  echo "=== Creating Saved Search: $TITLE ==="

  # Escape quotes in KQL for JSON embedding
  local KQL_ESCAPED
  KQL_ESCAPED=$(echo "$KQL" | sed 's/"/\\"/g')

  local SEARCH_SOURCE="{\"query\":{\"query\":\"${KQL_ESCAPED}\",\"language\":\"kuery\"},\"filter\":[],\"indexRefName\":\"kibanaSavedObjectMeta.searchSourceJSON.index\"}"

  # Escape for JSON-in-JSON: first backslashes, then quotes
  local SS_ESCAPED
  SS_ESCAPED=$(echo "$SEARCH_SOURCE" | sed 's/\\/\\\\/g; s/"/\\"/g')

  curl -s -o /dev/null -w "  HTTP %{http_code}\n" \
    -X POST "$KIBANA/api/saved_objects/search/$ID?overwrite=true" \
    -H "$H_XSRF" -H "$H_JSON" \
    -d '{
      "attributes": {
        "title": "'"$TITLE"'",
        "columns": ["host.name", "message", "log.file.path"],
        "sort": [["@timestamp", "desc"]],
        "kibanaSavedObjectMeta": {
          "searchSourceJSON": "'"$SS_ESCAPED"'"
        }
      },
      "references": [
        {"id": "'"$DV_ID"'", "name": "kibanaSavedObjectMeta.searchSourceJSON.index", "type": "index-pattern"}
      ]
    }'
}

create_search "infra-all" \
  "Infra: All logs (latest)" \
  ""

create_search "infra-errors" \
  "Infra: Errors & Warnings" \
  'message: ("ERROR" or "FATAL" or "PANIC" or "WARN")'

create_search "patroni" \
  "DB: Patroni logs" \
  'log.file.path: "/pgdata/patroni/patroni.log"'

create_search "postgres-errors" \
  'DB: PostgreSQL ERROR/FATAL/PANIC' \
  'log.file.path: "/pgdata/18/data/log/*" and message: ("ERROR" or "FATAL" or "PANIC")'

create_search "auth-all" \
  "Auth: /var/log/auth.log" \
  'log.file.path: "/var/log/auth.log"'

create_search "auth-failed" \
  "Auth: Failed password" \
  'log.file.path: "/var/log/auth.log" and message: "Failed password"'

# ── 3. Dashboards ────────────────────────────────────────────
create_dashboard() {
  local ID="$1"
  local TITLE="$2"
  local SEARCH1="$3"
  local SEARCH2="$4"

  echo "=== Creating Dashboard: $TITLE ==="

  local PANELS='[{"gridData":{"x":0,"y":0,"w":24,"h":15,"i":"1"},"panelIndex":"1","panelRefName":"panel_0","type":"search","embeddableConfig":{"enhancements":{}},"version":"8.17.0"},{"gridData":{"x":24,"y":0,"w":24,"h":15,"i":"2"},"panelIndex":"2","panelRefName":"panel_1","type":"search","embeddableConfig":{"enhancements":{}},"version":"8.17.0"}]'

  curl -s -o /dev/null -w "  HTTP %{http_code}\n" \
    -X POST "$KIBANA/api/saved_objects/dashboard/$ID?overwrite=true" \
    -H "$H_XSRF" -H "$H_JSON" \
    -d '{
      "attributes": {
        "title": "'"$TITLE"'",
        "panelsJSON": "'"$(echo "$PANELS" | sed 's/"/\\"/g')"'",
        "timeRestore": false,
        "version": 1
      },
      "references": [
        {"name": "panel_0", "type": "search", "id": "'"$SEARCH1"'"},
        {"name": "panel_1", "type": "search", "id": "'"$SEARCH2"'"}
      ]
    }'
}

create_dashboard "infra-overview" \
  "Infra Logs Overview" \
  "infra-all" "infra-errors"

create_dashboard "db-logs" \
  "PostgreSQL / Patroni Logs" \
  "patroni" "postgres-errors"

create_dashboard "auth-ssh" \
  "Auth / SSH Logs" \
  "auth-all" "auth-failed"

# ── 4. Verification ──────────────────────────────────────────
echo ""
echo "=== Verification ==="
DASH_COUNT=$(curl -s "$KIBANA/api/saved_objects/_find?type=dashboard" \
  -H "$H_XSRF" | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2)

echo "  Dashboards found: ${DASH_COUNT:-0}"
if [ "${DASH_COUNT:-0}" -ge 3 ]; then
  echo "  Bootstrap completed successfully!"
else
  echo "  WARNING: Expected 3 dashboards, found ${DASH_COUNT:-0}"
  exit 1
fi
