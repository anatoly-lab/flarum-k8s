<?php

/*
 * Keycloak OIDC provider for FriendsOfFlarum OAuth (Flarum 2.0).
 *
 * Registers a single custom provider with fof/oauth via its public extender.
 * RegisterProvider resolves the class from the container, asserts it is a
 * FoF\OAuth\Provider, guards against a duplicate name(), and tags it into the
 * `fof-oauth.providers` container tag alongside the bundled providers.
 */

use AnatolyLab\FlarumKeycloak\KeycloakProvider;
use FoF\OAuth\Extend\RegisterProvider;

return [
    new RegisterProvider(KeycloakProvider::class),
];
