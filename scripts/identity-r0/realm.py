"""Render the disposable Keycloak realm without storing any fixture credentials."""
import json
import sys
from pathlib import Path

values = dict(line.split("=", 1) for line in Path(sys.argv[1]).read_text().splitlines())

def service_client(client_id, secret):
    return {
        "clientId": client_id,
        "secret": secret,
        "enabled": True,
        "serviceAccountsEnabled": True,
        "standardFlowEnabled": False,
        "directAccessGrantsEnabled": False,
        "publicClient": False,
    }

json.dump({
    "realm": values["R0_REALM"],
    "enabled": True,
    "sslRequired": "none",
    "accessTokenLifespan": 60,
    "revokeRefreshToken": True,
    "refreshTokenMaxReuse": 0,
    "clients": [
        {**service_client("poc-provisioner", values["R0_ADMIN_SECRET"]), "optionalClientScopes": ["kc-admin-api"]},
        {**service_client("poc-key-operator", values["R0_KEY_SECRET"]), "optionalClientScopes": ["kc-admin-api"]},
        service_client("poc-machine", values["R0_MACHINE_SECRET"]),
        {
            "clientId": "poc-browser",
            "enabled": True,
            "standardFlowEnabled": True,
            "directAccessGrantsEnabled": False,
            "publicClient": False,
            "secret": values["R0_BROWSER_SECRET"],
            "redirectUris": ["http://127.0.0.1:8765/callback"],
            "attributes": {"pkce.code.challenge.method": "S256"},
            "protocolMappers": [{
                "name": "browser access token audience",
                "protocol": "openid-connect",
                "protocolMapper": "oidc-audience-mapper",
                "config": {
                    "included.client.audience": "poc-browser",
                    "access.token.claim": "true",
                    "introspection.token.claim": "true",
                },
            }],
        },
    ],
    "users": [{
        "username": "service-account-poc-provisioner",
        "enabled": True,
        "serviceAccountClientId": "poc-provisioner",
        "clientRoles": {"realm-management": ["manage-users", "view-users"]},
    }, {
        "username": "service-account-poc-key-operator",
        "enabled": True,
        "serviceAccountClientId": "poc-key-operator",
        "clientRoles": {"realm-management": ["manage-realm"]},
    }],
}, sys.stdout)
