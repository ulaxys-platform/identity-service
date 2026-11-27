#!/usr/bin/env bash
set -euo pipefail
stack=${IDENTITY_R0_STACK:-identity-cloud}
[[ "$stack" =~ ^identity-[a-z0-9-]+$ ]]
kc="$stack-keycloak"
app="$stack-app"
probe() {
    docker exec -i "$app" php -r '
        require "/app/vendor/autoload.php";
        $client = new GuzzleHttp\Client(["connect_timeout" => 2, "timeout" => 3, "proxy" => ""]);
        $issuer = "http://identity-cloud-keycloak:8080/realms/master";
        try {
            $response = $client->get("$issuer/.well-known/openid-configuration");
            $found = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            exit($argv[1] === "ready" && $found["issuer"] === $issuer ? 0 : 1);
        } catch (GuzzleHttp\Exception\ConnectException) {
            exit($argv[1] === "down" ? 0 : 1);
        }
    ' "$1"
}
test "$(docker inspect -f '{{.Config.Image}}' "$kc")" = \
    quay.io/keycloak/keycloak:26.8.2@sha256:7e66a96e4739239070aeaf72e4c5decf0f7f682cfc725b9f7ea2b336eaba38b3
probe ready
trap 'docker start "$kc" >/dev/null 2>&1 || true' EXIT
docker stop "$kc" >/dev/null
probe down
docker start "$kc" >/dev/null
for _ in {1..90}; do
    if probe ready; then
        echo "PASS provider connection fails during outage and discovery recovers after restart"
        exit 0
    fi
    sleep 1
done
echo 'Provider did not recover within readiness window' >&2
exit 1
