<?php
/**
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */

namespace League\OAuth2\Server\Grant;

use League\OAuth2\Server\CodeChallengeVerifiers\CodeChallengeVerifierInterface;
use League\OAuth2\Server\CodeChallengeVerifiers\PlainVerifier;
use League\OAuth2\Server\CodeChallengeVerifiers\S256Verifier;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\ResponseTypes\RedirectResponse;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthCodeGrant extends AbstractAuthorizeGrant
{
    /**
     * @var \DateInterval
     */
    private $authCodeTTL;

    /**
     * @var bool
     */
    private $requireCodeChallengeForPublicClients = true;

    /**
     * @var CodeChallengeVerifierInterface[]
     */
    private $codeChallengeVerifiers = [];

    /**
     * @param AuthCodeRepositoryInterface     $authCodeRepository
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     * @param \DateInterval                   $authCodeTTL
     */
    public function __construct(
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        \DateInterval $authCodeTTL
    ) {
        $this->setAuthCodeRepository($authCodeRepository);
        $this->setRefreshTokenRepository($refreshTokenRepository);
        $this->authCodeTTL = $authCodeTTL;
        $this->refreshTokenTTL = new \DateInterval('P1M');

        // SHOULD ONLY DO THIS IS SHA256 is supported
        $s256Verifier = new S256Verifier();
        $plainVerifier = new PlainVerifier();

        $this->codeChallengeVerifiers = [
            $s256Verifier->getMethod() => $s256Verifier,
            $plainVerifier->getMethod() => $plainVerifier,
        ];
    }

    /**
     * Disable the requirement for a code challenge for public clients.
     */
    public function disableRequireCodeChallengeForPublicClients()
    {
        $this->requireCodeChallengeForPublicClients = false;
    }

    /**
     * Respond to an access token request.
     *
     * @param ServerRequestInterface $request
     * @param ResponseTypeInterface  $responseType
     * @param \DateInterval          $accessTokenTTL
     *
     * @throws OAuthServerException
     *
     * @return ResponseTypeInterface
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL
    ) {
        $clientId = $this->getRequestParameter('client_id', $request, null);

        if ($clientId === null) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        $client = $this->clientRepository->getClientEntity($clientId);

        // Only validate the client if it is confidential
        if ($client->isConfidential()) {
            $this->validateClient($request);
        }

        $encryptedAuthCode = $this->getRequestParameter('code', $request, null);

        if ($encryptedAuthCode === null) {
            throw OAuthServerException::invalidRequest('code');
        }

        // Validate the authorization code
        try {
            $authCodePayload = json_decode($this->decrypt($encryptedAuthCode));

            if (time() > $authCodePayload->expire_time) {
                throw OAuthServerException::invalidRequest('code', 'Authorization code has expired');
            }

            if ($this->authCodeRepository->isAuthCodeRevoked($authCodePayload->auth_code_id) === true) {
                throw OAuthServerException::invalidRequest('code', 'Authorization code has been revoked');
            }

            if ($authCodePayload->client_id !== $client->getIdentifier()) {
                throw OAuthServerException::invalidRequest('code', 'Authorization code was not issued to this client');
            }

            // The redirect URI is required in this request
            $redirectUri = $this->getRequestParameter('redirect_uri', $request, null);

            if (empty($authCodePayload->redirect_uri) === false && $redirectUri === null) {
                throw OAuthServerException::invalidRequest('redirect_uri');
            }

            if ($authCodePayload->redirect_uri !== $redirectUri) {
                throw OAuthServerException::invalidRequest('redirect_uri', 'Invalid redirect URI');
            }

            $scopes = [];

            foreach ($authCodePayload->scopes as $scopeId) {
                $scope = $this->scopeRepository->getScopeEntityByIdentifier($scopeId);

                if ($scope instanceof ScopeEntityInterface === false) {
                    // @codeCoverageIgnoreStart
                    throw OAuthServerException::invalidScope($scopeId);
                    // @codeCoverageIgnoreEnd
                }

                $scopes[] = $scope;
            }

            // Finalize the requested scopes
            $scopes = $this->scopeRepository->finalizeScopes(
                $scopes,
                $this->getIdentifier(),
                $client,
                $authCodePayload->user_id
            );
        } catch (\LogicException  $e) {
            throw OAuthServerException::invalidRequest('code', 'Cannot decrypt the authorization code');
        }

        // Validate code challenge
        if (!empty($authCodePayload->code_challenge)) {
            $codeVerifier = $this->getRequestParameter('code_verifier', $request, null);

            if ($codeVerifier === null) {
                throw OAuthServerException::invalidRequest('code_verifier');
            }

            // Validate code_verifier according to RFC-7636
            // @see: https://tools.ietf.org/html/rfc7636#section-4.1
            if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeVerifier) !== 1) {
                throw OAuthServerException::invalidRequest(
                    'code_verifier',
                    'Code Verifier must follow the specifications of RFC-7636.'
                );
            }

            if (isset($this->codeChallengeVerifiers[$authCodePayload->code_challenge_method])) {
                $codeChallengeVerifier = $this->codeChallengeVerifiers[$authCodePayload->code_challenge_method];

                if ($codeChallengeVerifier->verifyCodeChallenge($codeVerifier, $authCodePayload->code_challenge) === false) {
                    throw OAuthServerException::invalidGrant('Failed to verify `code_verifier`.');
                }
            } else {
                throw OAuthServerException::serverError(
                    sprintf(
                        'Unsupported code challenge method `%s`',
                        $authCodePayload->code_challenge_method
                    )
                );
            }
        }

        // Issue and persist access + refresh tokens
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $authCodePayload->user_id, $scopes);
        $refreshToken = $this->issueRefreshToken($accessToken);

        // Send events to emitter
        $this->getEmitter()->emit(new RequestEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request));
        $this->getEmitter()->emit(new RequestEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request));

        // Inject tokens into response type
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        // Revoke used auth code
        $this->authCodeRepository->revokeAuthCode($authCodePayload->auth_code_id);

        return $responseType;
    }

    /**
     * Return the grant identifier that can be used in matching up requests.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return 'authorization_code';
    }

    /**
     * {@inheritdoc}
     */
    public function canRespondToAuthorizationRequest(ServerRequestInterface $request)
    {
        return (
            array_key_exists('response_type', $request->getQueryParams())
            && $request->getQueryParams()['response_type'] === 'code'
            && isset($request->getQueryParams()['client_id'])
        );
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request)
    {
        $clientId = $this->getQueryStringParameter(
            'client_id',
            $request,
            $this->getServerParameter('PHP_AUTH_USER', $request)
        );

        if (is_null($clientId)) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        $client = $this->clientRepository->getClientEntity($clientId);

        if ($client instanceof ClientEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));
            throw OAuthServerException::invalidClient($request);
        }

        $redirectUri = $this->getQueryStringParameter('redirect_uri', $request);

        if ($redirectUri !== null) {
            $this->validateRedirectUri($redirectUri, $client, $request);
        } elseif (is_array($client->getRedirectUri()) && count($client->getRedirectUri()) !== 1
            || empty($client->getRedirectUri())) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));
            throw OAuthServerException::invalidClient($request);
        } else {
            $redirectUri = is_array($client->getRedirectUri())
                ? $client->getRedirectUri()[0]
                : $client->getRedirectUri();
        }

        $scopes = $this->validateScopes(
            $this->getQueryStringParameter('scope', $request, $this->defaultScope),
            $redirectUri
        );

        $stateParameter = $this->getQueryStringParameter('state', $request);

        $authorizationRequest = new AuthorizationRequest();
        $authorizationRequest->setGrantTypeId($this->getIdentifier());
        $authorizationRequest->setClient($client);
        $authorizationRequest->setRedirectUri($redirectUri);

        if ($stateParameter !== null) {
            $authorizationRequest->setState($stateParameter);
        }

        $authorizationRequest->setScopes($scopes);

        $codeChallenge = $this->getQueryStringParameter('code_challenge', $request);

        if ($codeChallenge !== null) {
            $codeChallengeMethod = $this->getQueryStringParameter('code_challenge_method', $request, 'plain');

            if (array_key_exists($codeChallengeMethod, $this->codeChallengeVerifiers) === false) {
                throw OAuthServerException::invalidRequest(
                    'code_challenge_method',
                    'Code challenge method must be one of ' . implode(', ', array_map(
                        function ($method) {
                            return '`' . $method . '`';
                        },
                        array_keys($this->codeChallengeVerifiers)
                    ))
                );
            }

            // Validate code_challenge according to RFC-7636
            // @see: https://tools.ietf.org/html/rfc7636#section-4.2
            if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeChallenge) !== 1) {
                throw OAuthServerException::invalidRequest(
                    'code_challenged',
                    'Code challenge must follow the specifications of RFC-7636.'
                );
            }

            $authorizationRequest->setCodeChallenge($codeChallenge);
            $authorizationRequest->setCodeChallengeMethod($codeChallengeMethod);
        } elseif ($this->requireCodeChallengeForPublicClients && !$client->isConfidential()) {
            throw OAuthServerException::invalidRequest('code_challenge', 'Code challenge must be provided for public clients');
        }

        return $authorizationRequest;
    }

    /**
     * {@inheritdoc}
     */
    public function completeAuthorizationRequest(AuthorizationRequest $authorizationRequest)
    {
        if ($authorizationRequest->getUser() instanceof UserEntityInterface === false) {
            throw new \LogicException('An instance of UserEntityInterface should be set on the AuthorizationRequest');
        }

        $finalRedirectUri = ($authorizationRequest->getRedirectUri() === null)
            ? is_array($authorizationRequest->getClient()->getRedirectUri())
                ? $authorizationRequest->getClient()->getRedirectUri()[0]
                : $authorizationRequest->getClient()->getRedirectUri()
            : $authorizationRequest->getRedirectUri();

        // The user approved the client, redirect them back with an auth code
        if ($authorizationRequest->isAuthorizationApproved() === true) {
            $authCode = $this->issueAuthCode(
                $this->authCodeTTL,
                $authorizationRequest->getClient(),
                $authorizationRequest->getUser()->getIdentifier(),
                $authorizationRequest->getRedirectUri(),
                $authorizationRequest->getScopes()
            );

            $payload = [
                'client_id'             => $authCode->getClient()->getIdentifier(),
                'redirect_uri'          => $authCode->getRedirectUri(),
                'auth_code_id'          => $authCode->getIdentifier(),
                'scopes'                => $authCode->getScopes(),
                'user_id'               => $authCode->getUserIdentifier(),
                'expire_time'           => (new \DateTime())->add($this->authCodeTTL)->format('U'),
                'code_challenge'        => $authorizationRequest->getCodeChallenge(),
                'code_challenge_method' => $authorizationRequest->getCodeChallengeMethod(),
            ];

            $response = new RedirectResponse();
            $response->setRedirectUri(
                $this->makeRedirectUri(
                    $finalRedirectUri,
                    [
                        'code'  => $this->encrypt(
                            json_encode(
                                $payload
                            )
                        ),
                        'state' => $authorizationRequest->getState(),
                    ]
                )
            );

            return $response;
        }

        // The user denied the client, redirect them back with an error
        throw OAuthServerException::accessDenied(
            'The user denied the request',
            $this->makeRedirectUri(
                $finalRedirectUri,
                [
                    'state' => $authorizationRequest->getState(),
                ]
            )
        );
    }
}
