<?php

namespace AnatolyLab\FlarumKeycloak;

use Flarum\Forum\Auth\Registration;
use FoF\OAuth\Provider;
use League\OAuth2\Client\Provider\AbstractProvider;
use Stevenmaguire\OAuth2\Client\Provider\Keycloak;

/**
 * Keycloak OIDC provider, wrapping stevenmaguire/oauth2-keycloak.
 *
 * Extends FoF\OAuth\Provider (the abstract contract every fof/oauth provider
 * implements). The abstract methods name()/link()/fields()/provider()/
 * pkceEnabled() are required; icon()/options()/suggestions() are overrides.
 *
 * Settings are read from the Flarum `settings` table under the key prefix
 * derived from name(): `fof-oauth.keycloak.{field}` (plus the bare
 * `fof-oauth.keycloak` enabled flag). getSetting() and the SettingsRepository
 * are provided by the base class.
 */
class KeycloakProvider extends Provider
{
    /**
     * Provider id. This is BOTH the `/auth/{name}` callback URL slug AND the
     * `fof-oauth.{name}.*` settings-key prefix. The Keycloak client's valid
     * redirect URI must therefore be `https://<forum-host>/auth/keycloak`.
     */
    public function name(): string
    {
        return 'keycloak';
    }

    /**
     * Override: there is no `fab fa-keycloak` Font Awesome brand glyph, so the
     * base default `fab fa-{name}` would render nothing. Use a solid key icon.
     */
    public function icon(): string
    {
        return 'fas fa-key';
    }

    public function link(): string
    {
        return 'https://www.keycloak.org/';
    }

    /**
     * Drives the admin settings form AND defines which `fof-oauth.keycloak.{field}`
     * keys exist. Keep this in lockstep with the GitOps settings-seeding (the
     * install `--file` settings map / day-2 upsert).
     *
     * auth_server_url: the Keycloak base URL with NO trailing /auth (KC 17+),
     * e.g. https://keycloak.example.com
     */
    public function fields(): array
    {
        return [
            'client_id'       => 'required',
            'client_secret'   => 'required',
            'auth_server_url' => 'required',
            'realm'           => 'required',
        ];
    }

    public function pkceEnabled(): bool
    {
        return true;
    }

    /**
     * Options passed to the League provider's getAuthorizationUrl($options).
     *
     * Force `openid` explicitly: stevenmaguire's getDefaultScopes() only adds
     * `openid` when the configured `version` is >= 20.0.0, and without `openid`
     * the OIDC userinfo/ID-token flow breaks. Belt-and-suspenders with the
     * `version` passed in provider() below. Scope separator is a space.
     */
    public function options(): array
    {
        return [
            'scope' => ['openid', 'email', 'profile'],
        ];
    }

    public function provider(string $redirectUri): ?AbstractProvider
    {
        return new Keycloak([
            'authServerUrl' => $this->getSetting('auth_server_url'),
            'realm'         => $this->getSetting('realm'),
            'clientId'      => $this->getSetting('client_id'),
            'clientSecret'  => $this->getSetting('client_secret'),
            'redirectUri'   => $redirectUri,
            // Drives the default-scope gate (>= 20.0.0 => openid included).
            // options() also forces openid, so this is defensive.
            'version'       => '26.0.0',
        ]);
    }

    /**
     * Map the Keycloak resource owner onto the Flarum registration.
     *
     * $user is a Stevenmaguire KeycloakResourceOwner (ResourceOwnerInterface).
     * It exposes getId/getEmail/getName/getUsername/getFirstName/getLastName/
     * toArray() — but NO getAvatar(); pull the `picture` claim from toArray()
     * if Keycloak provides it.
     *
     * provideTrustedEmail() trusts the IdP's email (no Flarum email
     * confirmation). Only safe because Keycloak is the source of truth for
     * verified emails in this deployment.
     */
    public function suggestions(Registration $registration, mixed $user, string $token): void
    {
        $this->verifyEmail($email = $user->getEmail());

        $registration
            ->provideTrustedEmail($email)
            ->suggestUsername($user->getName() ?: $user->getUsername())
            ->setPayload($user->toArray());

        $data = $user->toArray();
        $this->provideAvatar($registration, $data['picture'] ?? null);
    }
}
