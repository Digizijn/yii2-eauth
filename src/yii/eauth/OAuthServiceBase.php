<?php
/**
 * OAuthService class file.
 *
 * @author Maxim Zemskov <nodge@yandex.ru>
 * @link http://github.com/Nodge/yii2-eauth/
 * @license http://www.opensource.org/licenses/bsd-license.php
 */

namespace yii\eauth;

use Yii;
use OAuth\Common\Http\Uri\Uri;
use OAuth\Common\Http\Client\StreamClient;
use OAuth\Common\Http\Client\ClientInterface;
use OAuth\Common\Token\TokenInterface;
use OAuth\Common\Storage\TokenStorageInterface;

/**
 * EOAuthService is a base class for all OAuth providers.
 *
 * @package application.extensions.eauth
 */
abstract class OAuthServiceBase extends ServiceBase implements IAuthService {

	/** @var \yii\eauth\OAuth1\ServiceProxy|\yii\eauth\OAuth2\ServiceProxy */
	protected $proxy;

	/**
	 * @var string Base url for API calls.
	 */
	protected $baseApiUrl;

	/**
	 * @var int Default token lifetime. Used when service wont provide expires_in param.
	 */
	protected $tokenDefaultLifetime = TokenInterface::EOL_UNKNOWN;

	/**
	 * Initialize the component.
	 *
	 * @param EAuth $component the component instance.
	 * @param array $options properties initialization.
	 */
//	public function init($component, $options = array()) {
//		parent::init($component, $options);
//	}

	/**
	 * @return string the current url
	 */
	protected function getCallbackUrl() {
		$request = Yii::$app->getRequest();
		return $request->getHostInfo().$request->getBaseUrl().'/'.$request->getPathInfo();
	}

	/**
	 * @return TokenStorageInterface
	 */
	protected function getTokenStorage() {
		// todo: cache instance?
		// todo: use Yii adapter
		return new \OAuth\Common\Storage\Session();
	}

	/**
	 * @return ClientInterface
	 */
	protected function getHttpClient() {
		// todo: cache instance?
		// todo: own client with logging
		return new StreamClient();
	}

	/**
	 * @return int
	 */
	public function getTokenDefaultLifetime() {
		return $this->tokenDefaultLifetime;
	}

	/**
	 * Returns the protected resource.
	 *
	 * @param string $url url to request.
	 * @param array $options HTTP request options. Keys: query, data, referer.
	 * @param boolean $parseResponse Whether to parse response.
	 * @return mixed the response.
	 * @throws ErrorException
	 */
	public function makeSignedRequest($url, $options = array(), $parseResponse = true) {
		if (!$this->getIsAuthenticated()) {
			throw new ErrorException(Yii::t('eauth', 'Unable to complete the signed request because the user was not authenticated.'), 401);
		}

		if (stripos($url, 'http') !== 0) {
			$url = $this->baseApiUrl . $url;
		}

		$url = new Uri($url);
		if (isset($options['query'])) {
			foreach ($options['query'] as $key => $value) {
				$url->addToQuery($key, $value);
			}
		}

		$data = isset($options['data']) ? $options['data'] : array();
		$method = !empty($data) ? 'POST' : 'GET';
		$headers = isset($options['headers']) ? $options['headers'] : array();

		$response = $this->proxy->request($url, $method, $data, $headers);

		if ($parseResponse) {
			$response = $this->parseResponseInternal($response);
		}

		return $response;
	}


	/**
	 * Parse response and check for errors.
	 * @param string $response
	 * @return mixed
	 * @throws ErrorException
	 */
	protected function parseResponseInternal($response) {
		try {
			$result = $this->parseResponse($response);
			if (!isset($result)) {
				throw new ErrorException(Yii::t('eauth', 'Invalid response format.'), 500);
			}

			$error = $this->fetchResponseError($result);
			if (isset($error) && !empty($error['message'])) {
				throw new ErrorException($error['message'], $error['code']);
			}

			return $result;
		}
		catch (\Exception $e) {
			throw new ErrorException($e->getMessage(), $e->getCode());
		}
	}

	/**
	 * @param string $response
	 * @return mixed
	 */
	protected function parseResponse($response) {
		return json_decode($response, true);
	}

	/**
	 * Returns the error array.
	 * @param array $response
	 * @return array the error array with 2 keys: code and message. Should be null if no errors.
	 */
	protected function fetchResponseError($response) {
		if (isset($response['error'])) {
			return array(
				'code' => 500,
				'message' => 'Unknown error occurred.',
			);
		}
		return null;
	}

	/**
	 * @return array|null An array with valid access_token information.
	 */
	protected function getAccessTokenData() {
		if (!$this->getIsAuthenticated()) {
			return null;
		}

		$token = $this->proxy->getAccessToken();
		return array(
			'access_token' => $token->getAccessToken(),
			'refresh_token' => $token->getRefreshToken(),
			'expires' => $token->getEndOfLife(),
			'params' => $token->getExtraParams(),
		);
	}
}