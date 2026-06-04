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
use Flarum\Extend\Frontend;
use Flarum\Extend\Locales;
use FoF\OAuth\Extend\RegisterProvider;

return [
    new RegisterProvider(KeycloakProvider::class),
    // Provides the SSO button label + provider display name + admin field labels.
    new Locales(__DIR__ . '/locale'),
    // SSO-only: hide the local username/password login form (Keycloak button kept).
    (new Frontend('forum'))->css(__DIR__ . '/resources/less/forum.less'),
];
