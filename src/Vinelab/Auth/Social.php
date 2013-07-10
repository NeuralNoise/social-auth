<?php namespace Vinelab\Auth;

use Vinelab\Auth\Social\Network as SocialNetwork;
use Vinelab\Auth\Exception\AuthenticationException;
use Vinelab\Auth\Exception\SocialAccountException;
use Vinelab\Http\Client as HttpClient;

use Vinelab\Auth\Contracts\UserInterface;
use Vinelab\Auth\Contracts\SocialAccountInterface;

use Illuminate\Config\Repository as Config;
use Illuminate\Cache\CacheManager as Cache;
use Illuminate\Http\Response as Response;
use Illuminate\Routing\Redirector as Redirector;

class Social {

	/**
	 * Instance
	 *
	 * @var Vinelab\Auth\Social\Network
	 */
	public $_Network;

	/**
	 * Instance
	 *
	 * @var Illuminate\Config\Repository
	 */
	protected $_Config;

	/**
	 * Instance
	 *
	 * @var Illuminate\Cache\CacheManager
	 */
	protected $_Cache;

	/**
	 * Instance
	 *
	 * @var  Illuminate\Routing\Redirector
	 */
	protected $_Redirect;

	/**
	 * Instance
	 *
	 * @var Vinelab\Http\Client
	 */
	protected $_HttpClient;

	/**
	 * Instance
	 *
	 * @var Vinelab\Auth\Contracts\UserInterface
	 */
	protected $_users;

	/**
	 * Instance
	 *
	 * @var Vinelab\Auth\Contracts\SocialAccountInterface
	 */
	protected $_socialAccounts;

	/**
	 * Keeps track of the request
	 *
	 * @var string
	 */
	public $state;

	protected $stateCacheKeyPrefix = 'auth_social_state_';

	function __construct(Config $config,
						 Cache $cache,
						 Redirector $redirector,
						 HttpClient $httpClient,
						 UserInterface $userRepository,
						 SocialAccountInterface $socialAccountRepository)
	{
		$this->_Config              = $config;
		$this->_Cache               = $cache;
		$this->_Redirect            = $redirector;
		$this->_HttpClient          = $httpClient;
		$this->_users 		    	= $userRepository;
		$this->_socialAccounts		= $socialAccountRepository;
	}

	/**
	 *
	 * @param  string $service
	 * @return  Illuminate\Routing\Redirector
	 */
	public function authenticate($service)
	{
		$this->_Network = $this->networkInstance($service);

		$this->state = $this->state ?: $this->makeState();

		$apiKey = $this->_Network->settings('api_key');
		$redirectURI = $this->_Network->settings('redirect_uri');

		$this->_Cache->put($this->stateCacheKey($this->state), ['api_key'=>$apiKey, 'redirect_uri'=>$redirectURI], 5);

		$url = $this->_Network->authenticationURL();

		$url = $url.'&'.http_build_query(['state' => $this->state]);

		return $this->_Redirect->to($url);
	}

	public function authenticationCallback($service, $input)
	{
		$this->_Network = $this->networkInstance($service);

		// check for state
		if (!isset($input['state']) or empty($input['state']))
		{
			throw new AuthenticationException('state', 'not found');
		}

		$state = $input['state'];
		$stateCacheKey = $this->stateCacheKey($state);

		// verify state existance
		if(!$this->_Cache->has($stateCacheKey))
		{
			throw new AuthenticationException('Timeout', 'Authentication has taken too long, please try again.');
		}

		$accessToken = $this->_Network->authenticationCallback($input);

		// add access token to cached data and extend to another 5 min
		$cachedStateData = $this->_Cache->get($this->stateCacheKey($state));
		$cachedStateData['access_token'] = $accessToken;
		$this->_Cache->put($stateCacheKey, $cachedStateData, 5);

		$this->saveUser($this->_Network->profile());
		return ['state'=>$state];
	}

	protected function saveUser($profile)
	{
		if ($profile and isset($profile->email))
		{
			$userFound = $this->_users->findByEmail($profile->email);

			if (count($userFound) === 0)
			{
				$user = $this->_users->fillAndSave((array) $profile);
				return $this->_Network->name;
				$socialAccount = $this->_socialAccounts->create($this->_Network->name, $profile->id, $user->id, $profile->access_token);
			}
		} else {
			throw new SocialAccountException('Profile', 'Invalid type or structure');
		}
	}

	public function makeState()
	{
		return md5(uniqid(microtime(), true));
	}

	protected function networkInstance($service)
	{
		return new SocialNetwork($service, $this->_Config, $this->_HttpClient);
	}

	protected function stateCacheKey($state)
	{
		return $this->stateCacheKeyPrefix.$state;
	}
}