#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COLLECTION_DIR="$ROOT_DIR/bruno/betalent-payments"
BASE_URL="${BRUNO_BASE_URL:-http://127.0.0.1:8000}"
auth_token=""
gateway_id=""
gateway_initial_is_active=""

restore_gateway_status() {
  if [[ -z "$auth_token" || -z "$gateway_id" || -z "$gateway_initial_is_active" ]]; then
    return
  fi

  curl -fsS -X PATCH "$BASE_URL/api/gateways/$gateway_id/status" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $auth_token" \
    -d "{\"is_active\":$gateway_initial_is_active}" >/dev/null || true
}

trap restore_gateway_status EXIT

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_command docker
require_command curl
require_command php

if [[ ! -d "$COLLECTION_DIR" ]]; then
  echo "Bruno collection directory not found: $COLLECTION_DIR" >&2
  exit 1
fi

echo "Checking API health at $BASE_URL/up ..."
curl -fsS "$BASE_URL/up" >/dev/null

echo "Resolving dynamic variables for Bruno collection ..."

login_response="$(
  curl -fsS -X POST "$BASE_URL/api/login" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -d '{"email":"admin@betalent.local","password":"password123"}'
)"

auth_token="$(
  printf '%s' "$login_response" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    $token = $json["data"]["token"] ?? null;
    if (! is_string($token) || $token === "") {
        fwrite(STDERR, "Could not resolve auth token from /api/login response.\n");
        exit(1);
    }
    echo $token;
  '
)"

user_id="$(
  printf '%s' "$login_response" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    $userId = $json["data"]["user"]["id"] ?? null;
    if (! is_int($userId) && ! is_string($userId)) {
        fwrite(STDERR, "Could not resolve userId from /api/login response.\n");
        exit(1);
    }
    echo $userId;
  '
)"

gateways_response="$(
  curl -fsS "$BASE_URL/api/gateways" \
    -H 'Accept: application/json' \
    -H "Authorization: Bearer $auth_token"
)"

gateway_metadata="$(
  printf '%s' "$gateways_response" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    $gateways = $json["data"]["gateways"] ?? null;
    $selectedGateway = null;
    if (is_array($gateways) && isset($gateways[0]["id"])) {
        $selectedGateway = $gateways[0];
    }
    if (is_array($gateways) && isset($gateways["data"][0]["id"])) {
        $selectedGateway = $gateways["data"][0];
    }
    $gatewayId = $selectedGateway["id"] ?? null;
    $isActive = $selectedGateway["is_active"] ?? null;
    if (! is_int($gatewayId) && ! is_string($gatewayId)) {
        fwrite(STDERR, "Could not resolve gatewayId from /api/gateways response.\n");
        exit(1);
    }
    if (! is_bool($isActive)) {
        fwrite(STDERR, "Could not resolve gateway is_active from /api/gateways response.\n");
        exit(1);
    }
    echo $gatewayId . PHP_EOL . ($isActive ? "true" : "false");
  '
)"

gateway_id="$(printf '%s' "$gateway_metadata" | sed -n '1p')"
gateway_initial_is_active="$(printf '%s' "$gateway_metadata" | sed -n '2p')"

products_response="$(
  curl -fsS "$BASE_URL/api/products?per_page=1" \
    -H 'Accept: application/json' \
    -H "Authorization: Bearer $auth_token"
)"

product_id="$(
  printf '%s' "$products_response" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    $productId = $json["data"]["products"]["data"][0]["id"] ?? null;
    if (! is_int($productId) && ! is_string($productId)) {
        fwrite(STDERR, "Could not resolve productId from /api/products response.\n");
        exit(1);
    }
    echo $productId;
  '
)"

client_email="bruno.$(date +%s).$RANDOM@betalent.local"
idempotency_key="bruno-$(date +%s)-$RANDOM"
purchase_payload="$(
  printf '{"client":{"name":"Bruno Runner","email":"%s"},"payment":{"card_number":"5569000000006063","cvv":"010"},"items":[{"product_id":%s,"quantity":1}]}' \
    "$client_email" \
    "$product_id"
)"

purchase_response="$(
  curl -fsS -X POST "$BASE_URL/api/purchases" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -H "Idempotency-Key: $idempotency_key" \
    -d "$purchase_payload"
)"

transaction_and_client="$(
  printf '%s' "$purchase_response" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    $transaction = $json["data"]["transaction"] ?? null;
    if (! is_array($transaction)) {
        fwrite(STDERR, "Could not resolve transaction object from /api/purchases response.\n");
        exit(1);
    }
    $transactionId = $transaction["id"] ?? null;
    $clientId = $transaction["client_id"] ?? ($transaction["client"]["id"] ?? null);
    if (! is_string($transactionId) || $transactionId === "") {
        fwrite(STDERR, "Could not resolve transactionId from /api/purchases response.\n");
        exit(1);
    }
    if (! is_int($clientId) && ! is_string($clientId)) {
        fwrite(STDERR, "Could not resolve clientId from /api/purchases response.\n");
        exit(1);
    }
    echo $transactionId . PHP_EOL . $clientId;
  '
)"

transaction_id="$(printf '%s' "$transaction_and_client" | sed -n '1p')"
client_id="$(printf '%s' "$transaction_and_client" | sed -n '2p')"
new_user_email="bruno.user.$(date +%s).$RANDOM@betalent.local"

echo "Executing Bruno collection ..."
echo "baseUrl=$BASE_URL gatewayId=$gateway_id productId=$product_id clientId=$client_id transactionId=$transaction_id"

docker run --rm \
  --network container:betalent-app \
  -v "$COLLECTION_DIR:/collection" \
  -w /collection \
  alpine/bruno \
  run . -r --env local \
  --env-var "baseUrl=$BASE_URL" \
  --env-var "authToken=$auth_token" \
  --env-var "gatewayId=$gateway_id" \
  --env-var "userId=$user_id" \
  --env-var "productId=$product_id" \
  --env-var "clientId=$client_id" \
  --env-var "transactionId=$transaction_id" \
  --env-var "newUserEmail=$new_user_email"
