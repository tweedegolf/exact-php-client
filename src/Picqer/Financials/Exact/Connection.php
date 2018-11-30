<?php

namespace Picqer\Financials\Exact;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7;

/**
 * Class Connection
 *
 * @package Picqer\Financials\Exact
 *
 */
class Connection
{
    /**
     * @var string
     */
    private $baseUrl = 'https://start.exactonline.nl';

    /**
     * @var string
     */
    private $apiUrl = '/api/v1';

    /**
     * @var string
     */
    private $authUrl = '/api/oauth2/auth';

    /**
     * @var string
     */
    private $tokenUrl = '/api/oauth2/token';

    /**
     * @var string
     */
    private $lockFilePath = 'exact-refresh-lock';

    /**
     * @var
     */
    private $exactClientId;

    /**
     * @var
     */
    private $exactClientSecret;

    /**
     * @var
     */
    private $authorizationCode;

    /**
     * @var
     */
    private $accessToken;

    /**
     * @var
     */
    private $tokenExpires;

    /**
     * @var
     */
    private $refreshToken;

    /**
     * @var
     */
    private $redirectUrl;

    /**
     * @var
     */
    private $division;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var callable(Connection)
     */
    private $preTokenUpdateCallback;

    /**
     * @var callable(Connection)
     */
    private $tokenUpdateCallback;

    /**
     * @var callable(Connection);
     */
    private $updateTokensCallback;

    /**
     * @var array
     */
    protected $middleWares = [];

    /**
     * @var string|null
     */
    public $nextUrl = null;

    /**
     * @var callable(Connection)
     */
    private $requestCallback;

    /**
     * @var string|null
     */
    private $randomName;

    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Every instance of the Connection gets a unique name
        // to distinguish between PHP sessions.
        $this->randomName = uniqid();
    }

    /**
     * @return Client
     */
    private function client()
    {
        if ($this->client) {
            return $this->client;
        }

        $handlerStack = HandlerStack::create();
        foreach ($this->middleWares as $middleWare) {
            $handlerStack->push($middleWare);
        }

        $this->client = new Client([
            'http_errors' => true,
            'handler' => $handlerStack,
            'expect' => false,
        ]);

        return $this->client;
    }

    public function insertMiddleWare($middleWare)
    {
        $this->middleWares[] = $middleWare;
    }

    /**
     * Another process is fetching new refresh tokens,
     * so wait for a while and check is new tokens have been
     * fetched in the meantime. Otherwise throw an Exception.
     */
    private function waitForTokens()
    {
        // if it is, wait a second
        // and check again if the token is good.
        for ($x = 0; $x <= 5; $x +=1) {
            $this->logToFile('Locked. Retry ' . $x);
            sleep(5);

            $data = call_user_func($this->updateTokensCallback, $this);
            if (isset($data['accesstoken'])) {
                $this->logToFile('Access token: ' . substr($data['accesstoken'], -4));
            }
            if (isset($data['refreshtoken'])) {
                $this->logToFile('Refresh token: ' . substr($data['refreshtoken'], -4));
            }

            $end1 = substr($this->accessToken, -4);
            $end2 = substr($this->refreshToken, -4);
            $this->logToFile("Tokens are now: access: {$end1}, refresh: {$end2}");

            if (!empty($this->accessToken) && !$this->tokenHasExpired()) {
                $this->logToFile('New token found, stop waiting.');
                break;
            }
        }

        // Try it just one more time
        if (empty($this->accessToken) && $this->tokenHasExpired()) {
            call_user_func($this->updateTokensCallback, $this);
            $end1 = substr($this->accessToken, -4);
            $end2 = substr($this->refreshToken, -4);
            $this->logToFile("Tokens are now: access: {$end1}, refresh: {$end2}");
        }

        if (empty($this->accessToken) || $this->tokenHasExpired()) {
            $this->logToFile('Timeout while waiting for refresh token.');
            throw new ApiException('Timout while waiting for the refresh request to unlock.');
        }
    }

    public function connect()
    {
        // Redirect for authorization if needed (no access token or refresh token given)
        if ($this->needsAuthentication()) {
            $this->redirectForAuthorization();
        }

        // If access token is not set or token has expired, acquire new token
        if (empty($this->accessToken) || $this->tokenHasExpired()) {

            // Check if token refresh is locked
            if (!$this->refreshRequestIsLocked()) {
                $this->logToFile('Getting token.');

                $lock = $this->lockRefreshRequest();
                if ($lock) {
                    $this->acquireAccessToken();
                } else {
                    $this->waitForTokens();
                }

            } else {
                $this->waitForTokens();
            }
        }

        $client = $this->client();

        return $client;
    }

    /**
     * Check if the refresh request is locked. This is done
     * by checking if a temporary lock file exists.
     *
     * @return bool
     */
    private function refreshRequestIsLocked()
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exact-refresh-lock';

        if (file_exists($path)) {

            // Check the file age
            clearstatcache();
            $modTime = filemtime($path);
            $now = new \DateTime();

            $this->logToFile('Lock file time: ' . date('Y-m-d H:i:s', $modTime));
            $this->logTofile('Current time: ' . date('Y-m-d H:i:s', $now));
            $this->logToFile('Diff: ' . $now->diff($modTime)->format('i') . ' minutes');

            // Check
            $contents = file_get_contents($path);
            if (!isset($_SESSION['exact-file-lock'])) {
                return true;
            }

            $this->logToFile("Session: " . $_SESSION['exact-file-lock']);

            return $contents !== $_SESSION['exact-file-lock'];
        }

        return false;
    }

    /**
     * Lock the refresh request. Make sure we can get an exclusive lock
     * on the file, to prevent another process from also writing to this
     * file at the same time.
     */
    private function lockRefreshRequest()
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exact-refresh-lock';

        if (!file_exists($path)) {

            $file = fopen($path, 'w+');
            if (flock($file, LOCK_EX|LOCK_NB)) {

                fwrite($file, $this->randomName);
                $this->logToFile('Locking request');
                $_SESSION['exact-file-lock'] = $this->randomName;
                flock($file, LOCK_UN);
                fclose($file);

            } else {
                return false;
            }

        } else {
            return false;
        }

        return true;
    }

    /**
     * Reset the refresh lock, to clear the lock in the
     * even of an exception, like a timeout other error while connecting.
     */
    public function resetRefreshLock()
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exact-refresh-lock';
        $this->logToFile('Unlocking request.');
        unset($_SESSION['exact-file-lock']);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Unlock the refresh request.
     */
    private function unlockRefreshRequest()
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exact-refresh-lock';
        $this->logToFile('Unlocking request.');
        unset($_SESSION['exact-file-lock']);

        if (file_exists($path)) {
            unlink($path);
        } else {
            throw new \Exception('Lockfile does not exist.');
        }
    }

    /**
     * For debugging purposes, write to an exact log file
     * in the temporary path.
     *
     * TODO: Remove after extensive testing.
     */
    private function logToFile($message)
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exact.log';

        $now = date('[Y-m-d H:i:s]');

        $lockNumber = '?';
        if (isset($_SESSION['exact-file-lock'])) {
            $lockNumber = $_SESSION['exact-file-lock'];
        }

        $message = $now . '[' . $this->randomName. '][' . $lockNumber . ']: ' . $message . "\r\n";

        $file = fopen($path, 'a');
        fwrite($file, $message);
        fclose($file);
    }

    /**
     * @param string $method
     * @param $endpoint
     * @param mixed $body
     * @param array $params
     * @param array $headers
     * @return Request
     */
    private function createRequest($method = 'GET', $endpoint, $body = null, array $params = [], array $headers = [])
    {
        // Add default json headers to the request
        $headers = array_merge($headers, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Prefer' => 'return=representation'
        ]);

        // If access token is not set or token has expired, acquire new token
        if (empty($this->accessToken) || $this->tokenHasExpired()) {

            // Check if token refresh is locked
            if (!$this->refreshRequestIsLocked()) {
                $this->logToFile('Getting token.');

                $lock = $this->lockRefreshRequest();
                if ($lock) {
                    $this->acquireAccessToken();
                } else {
                    $this->waitForTokens();
                }

            } else {
                $this->waitForTokens();
            }
        }

        // If we have a token, sign the request
        if (!$this->needsAuthentication() && !empty($this->accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        // Create param string
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }

        if (is_callable($this->requestCallback)) {
            call_user_func($this->requestCallback, $method, $endpoint, $headers, $params);
        }

        // Create the request
        $request = new Request($method, $endpoint, $headers, $body);

        return $request;
    }

    /**
     * @param $url
     * @param array $params
     * @param array $headers
     * @return mixed
     * @throws ApiException
     */
    public function get($url, array $params = [], array $headers = [])
    {
        $url = $this->formatUrl($url, $url !== 'current/Me', $url == $this->nextUrl);

        try {
            $request = $this->createRequest('GET', $url, null, $params, $headers);
            $response = $this->client()->send($request);

            return $this->parseResponse($response, $url != $this->nextUrl);
        } catch (Exception $e) {
            $this->parseExceptionForErrorMessages($e);
        }

        return null;
    }

    /**
     * @param $url
     * @param $body
     * @return mixed
     * @throws ApiException
     */
    public function post($url, $body)
    {
        $url = $this->formatUrl($url);

        try {
            $request  = $this->createRequest('POST', $url, $body);
            $response = $this->client()->send($request);

            return $this->parseResponse($response);
        } catch (Exception $e) {
            $this->parseExceptionForErrorMessages($e);
        }

        return null;
    }

    /**
     * @param $url
     * @param $body
     * @return mixed
     * @throws ApiException
     */
    public function put($url, $body)
    {
        $url = $this->formatUrl($url);

        try {
            $request  = $this->createRequest('PUT', $url, $body);
            $response = $this->client()->send($request);

            return $this->parseResponse($response);
        } catch (Exception $e) {
            $this->parseExceptionForErrorMessages($e);
        }

        return null;
    }

    /**
     * @param $url
     * @return mixed
     * @throws ApiException
     */
    public function delete($url)
    {
        $url = $this->formatUrl($url);

        try {
            $request  = $this->createRequest('DELETE', $url);
            $response = $this->client()->send($request);

            return $this->parseResponse($response);
        } catch (Exception $e) {
            $this->parseExceptionForErrorMessages($e);
        }

        return null;
    }

    /**
     * @return string
     */
    public function getAuthUrl()
    {
        return $this->baseUrl . $this->authUrl . '?' . http_build_query(array(
                'client_id' => $this->exactClientId,
                'redirect_uri' => $this->redirectUrl,
                'response_type' => 'code'
            ));
    }

    /**
     * @param mixed $exactClientId
     */
    public function setExactClientId($exactClientId)
    {
        $this->exactClientId = $exactClientId;
    }

    /**
     * @param mixed $exactClientSecret
     */
    public function setExactClientSecret($exactClientSecret)
    {
        $this->exactClientSecret = $exactClientSecret;
    }

    /**
     * @param mixed $authorizationCode
     */
    public function setAuthorizationCode($authorizationCode)
    {
        $this->authorizationCode = $authorizationCode;
    }

    /**
     * @param mixed $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @param mixed $refreshToken
     */
    public function setRefreshToken($refreshToken)
    {
        $this->refreshToken = $refreshToken;
    }

    /**
     *
     */
    public function redirectForAuthorization()
    {
        $authUrl = $this->getAuthUrl();
        header('Location: ' . $authUrl);
        exit;
    }

    /**
     * @param mixed $redirectUrl
     */
    public function setRedirectUrl($redirectUrl)
    {
        $this->redirectUrl = $redirectUrl;
    }

    /**
     * @return bool
     */
    public function needsAuthentication()
    {
        return empty($this->refreshToken) && empty($this->authorizationCode);
    }

    /**
     * @param Response $response
     * @param bool $returnSingleIfPossible
     * @return mixed
     * @throws ApiException
     */
    private function parseResponse(Response $response, $returnSingleIfPossible = true)
    {
        try {

            if ($response->getStatusCode() === 204) {
                return [];
            }

            Psr7\rewind_body($response);
            $json = json_decode($response->getBody()->getContents(), true);
            if (array_key_exists('d', $json)) {
                if (array_key_exists('__next', $json['d'])) {
                    $this->nextUrl = $json['d']['__next'];
                }
                else {
                    $this->nextUrl = null;
                }

                if (array_key_exists('results', $json['d'])) {
                    if ($returnSingleIfPossible && count($json['d']['results']) == 1) {
                        return $json['d']['results'][0];
                    }

                    return $json['d']['results'];
                }

                return $json['d'];
            }

            return $json;
        } catch (\RuntimeException $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return mixed
     */
    private function getCurrentDivisionNumber()
    {
        if (empty($this->division)) {
            $me             = new Me($this);
            $this->division = $me->find()->CurrentDivision;
        }

        return $this->division;
    }

    /**
     * @return mixed
     */
    public function getRefreshToken()
    {
        return $this->refreshToken;
    }

    /**
     * @return mixed
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    private function acquireAccessToken()
    {
        // The file was locked before entering
        // this function.

        // If refresh token not yet acquired, do token request
        if (empty($this->refreshToken)) {
            $body = [
                'form_params' => [
                    'redirect_uri' => $this->redirectUrl,
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->exactClientId,
                    'client_secret' => $this->exactClientSecret,
                    'code' => $this->authorizationCode
                ]
            ];
        } else { // else do refresh token request

            $body = [
                'form_params' => [
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->exactClientId,
                    'client_secret' => $this->exactClientSecret,
                ]
            ];
        }

        if (is_callable($this->preTokenUpdateCallback)) {
            call_user_func($this->preTokenUpdateCallback, $this, $body);
        }

        if (is_callable($this->requestCallback)) {
            call_user_func($this->requestCallback, 'POST', $this->getTokenUrl(), [], []);
        }

        $response = $this->client()->post($this->getTokenUrl(), $body);

        if ($response->getStatusCode() == 200) {
            Psr7\rewind_body($response);
            $body = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->accessToken  = $body['access_token'];
                $this->refreshToken = $body['refresh_token'];
                $this->tokenExpires = $this->getDateTimeFromExpires($body['expires_in']);

                if (is_callable($this->tokenUpdateCallback)) {
                    call_user_func($this->tokenUpdateCallback, $this);
                }

                $access = substr($this->accessToken, -4);
                $refresh = substr($this->refreshToken, -4);
                $this->logToFile("OK, new tokens ready, access: {$access}, refresh: {$refresh}");

                // Clear the lock file.
                $this->unlockRefreshRequest();
            } else {

                // Clear the lock file.
                $this->logToFile('ERROR: JSON decode failed');
                $this->unlockRefreshRequest();
                throw new ApiException('Could not acquire tokens, json decode failed. Got response: ' . $response->getBody()->getContents());
            }
        } else {

            // Clear the lock file.
            $this->logToFile('ERROR: Could not acquire / refresh the token.');
            $this->unlockRefreshRequest();
            throw new ApiException('Could not acquire or refresh tokens');
        }
    }

    private function getDateTimeFromExpires($expires)
    {
        if (!is_numeric($expires)) {
            throw new \InvalidArgumentException('Function requires a numeric expires value');
        }

        return time() + 600;
    }

    /**
     * @return mixed
     */
    public function getTokenExpires()
    {
        return $this->tokenExpires;
    }

    /**
     * @param mixed $tokenExpires
     */
    public function setTokenExpires($tokenExpires)
    {
        $this->tokenExpires = $tokenExpires;
    }

    private function tokenHasExpired()
    {
        if (empty($this->tokenExpires)) {
            return true;
        }

        return $this->tokenExpires <= time() + 10;
    }

    private function formatUrl($endPoint, $includeDivision = true, $formatNextUrl = false)
    {
        if ($formatNextUrl) {
            return $endPoint;
        }

        if ($includeDivision) {
            return implode('/', [
                $this->getApiUrl(),
                $this->getCurrentDivisionNumber(),
                $endPoint
            ]);
        }

        return implode('/', [
            $this->getApiUrl(),
            $endPoint
        ]);
    }


    /**
     * @return mixed
     */
    public function getDivision()
    {
        return $this->division;
    }


    /**
     * @param mixed $division
     */
    public function setDivision($division)
    {
        $this->division = $division;
    }

    /**
     * @param callable $callback
     */
    public function setTokenUpdateCallback($callback) {
        $this->tokenUpdateCallback = $callback;
    }

    /**
     * @param callable $callback
     */
    public function setPreTokenUpdateCallback($callback) {
        $this->preTokenUpdateCallback = $callback;
    }

    /**
     * @param callable $callback
     */
    public function setUpdateTokensCallback($callback) {
        $this->updateTokensCallback = $callback;
    }

    /**
     * Parse the reponse in the Exception to return the Exact error messages
     * @param Exception $e
     * @throws ApiException
     */
    private function parseExceptionForErrorMessages(Exception $e)
    {
        if (! $e instanceof BadResponseException) {
            throw new ApiException($e->getMessage());
        }

        $response = $e->getResponse();
        Psr7\rewind_body($response);
        $responseBody = $response->getBody()->getContents();
        $decodedResponseBody = json_decode($responseBody, true);

        if (! is_null($decodedResponseBody) && isset($decodedResponseBody['error']['message']['value'])) {
            $errorMessage = $decodedResponseBody['error']['message']['value'];
        } else {
            $errorMessage = $responseBody;
        }

        throw new ApiException('Error ' . $response->getStatusCode() .': ' . $errorMessage);
    }

    /**
     * @return string
     */
    protected function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @return string
     */
    private function getApiUrl()
    {
        return $this->baseUrl . $this->apiUrl;
    }

    /**
     * @return string
     */
    private function getTokenUrl()
    {
        return $this->baseUrl . $this->tokenUrl;
    }

    /**
     * Set base URL for different countries according to
     * https://developers.exactonline.com/#Exact%20Online%20sites.html
     *
     * @param string $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param string $apiUrl
     */
    public function setApiUrl($apiUrl)
    {
        $this->apiUrl = $apiUrl;
    }

    /**
     * @param string $authUrl
     */
    public function setAuthUrl($authUrl)
    {
        $this->authUrl = $authUrl;
    }

    /**
     * @param string $tokenUrl
     */
    public function setTokenUrl($tokenUrl)
    {
        $this->tokenUrl = $tokenUrl;
    }

    /**
     * @return callable
     */
    public function getRequestCallback(): ?callable
    {
        return $this->requestCallback;
    }

    /**
     * @param callable $requestCallback
     */
    public function setRequestCallback(callable $requestCallback): void
    {
        $this->requestCallback = $requestCallback;
    }
}
