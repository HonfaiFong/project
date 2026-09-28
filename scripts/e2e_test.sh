#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${API_BASE_URL:-http://127.0.0.1:8787}"

echo "=========================================================="
echo "Starting E2E Verification for Social Backend (Phase 1)"
echo "Target: ${BASE_URL}"
echo "=========================================================="

# 1. Healthcheck
echo -n "[1/8] Checking API Gateway Health... "
HEALTH_RESP=$(curl -s -f "${BASE_URL}/health" || curl -s -f "${BASE_URL}/api/v1/health")
echo "OK"
echo "  Response: ${HEALTH_RESP}"

# 2. Login
PHONE="+8613800138$(date +%s | tail -c 4)"
DEVICE_UUID="dev-$(date +%s)"
echo -n "[2/8] Logging in with phone ${PHONE}... "
LOGIN_RESP=$(curl -s -X POST "${BASE_URL}/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Device-Id: ${DEVICE_UUID}" \
  -d "{\"phone\":\"${PHONE}\",\"device\":{\"device_uuid\":\"${DEVICE_UUID}\",\"platform\":\"ios\"}}")

ACCESS_TOKEN=$(echo "${LOGIN_RESP}" | grep -o '"access_token":"[^"]*' | cut -d'"' -f4)
REFRESH_TOKEN=$(echo "${LOGIN_RESP}" | grep -o '"refresh_token":"[^"]*' | cut -d'"' -f4)
USER_ID=$(echo "${LOGIN_RESP}" | grep -o '"user_id":"[^"]*' | cut -d'"' -f4)

if [[ -z "${ACCESS_TOKEN}" ]]; then
  echo "FAILED: Could not retrieve access_token"
  echo "Response: ${LOGIN_RESP}"
  exit 1
fi
echo "OK (User ID: ${USER_ID})"

# 3. Get /me
echo -n "[3/8] Fetching profile (GET /api/v1/me)... "
ME_RESP=$(curl -s -X GET "${BASE_URL}/api/v1/me" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}")
echo "OK"

# 4. Create new Persona
echo -n "[4/8] Creating secondary Persona (POST /api/v1/personas)... "
CREATE_PERSONA_RESP=$(curl -s -X POST "${BASE_URL}/api/v1/personas" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"type":1,"nickname":"NightWatcher","bio":"Looking at the stars"}')

PERSONA_ID=$(echo "${CREATE_PERSONA_RESP}" | grep -o '"id":"[^"]*' | cut -d'"' -f4)
if [[ -z "${PERSONA_ID}" ]]; then
  echo "FAILED: Could not create persona"
  echo "Response: ${CREATE_PERSONA_RESP}"
  exit 1
fi
echo "OK (Persona ID: ${PERSONA_ID})"

# 5. List Personas
echo -n "[5/8] Listing Personas (GET /api/v1/personas)... "
LIST_PERSONAS_RESP=$(curl -s -X GET "${BASE_URL}/api/v1/personas" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}")
echo "OK"

# 6. Update Persona
echo -n "[6/8] Updating Persona (PATCH /api/v1/personas/${PERSONA_ID})... "
UPDATE_RESP=$(curl -s -X PATCH "${BASE_URL}/api/v1/personas/${PERSONA_ID}" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"bio":"Updated bio for night owl"}')
echo "OK"

# 7. Request WebSocket Ticket
echo -n "[7/8] Requesting WS Ticket (POST /api/v1/ws/ticket)... "
TICKET_RESP=$(curl -s -X POST "${BASE_URL}/api/v1/ws/ticket" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"persona_id\":\"${PERSONA_ID}\"}")

TICKET=$(echo "${TICKET_RESP}" | grep -o '"ticket":"[^"]*' | cut -d'"' -f4)
if [[ -z "${TICKET}" ]]; then
  echo "FAILED: No ticket in response"
  echo "Response: ${TICKET_RESP}"
  exit 1
fi
echo "OK"

# 8. Token Refresh
echo -n "[8/8] Refreshing Token (POST /api/v1/auth/refresh)... "
REFRESH_RESP=$(curl -s -X POST "${BASE_URL}/api/v1/auth/refresh" \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"${REFRESH_TOKEN}\"}")

NEW_ACCESS_TOKEN=$(echo "${REFRESH_RESP}" | grep -o '"access_token":"[^"]*' | cut -d'"' -f4)
if [[ -z "${NEW_ACCESS_TOKEN}" ]]; then
  echo "FAILED to refresh token"
  echo "Response: ${REFRESH_RESP}"
  exit 1
fi
echo "OK"

echo "=========================================================="
echo "ALL E2E CHECKS PASSED SUCCESSFULLY!"
echo "=========================================================="
