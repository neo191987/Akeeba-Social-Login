<?php
/**
 * @package   AkeebaSocialLogin
 * @copyright Copyright (c)2016-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

use Akeeba\SocialLogin\Google\OAuth2;
use Akeeba\SocialLogin\Google\OpenID;
use Akeeba\SocialLogin\Library\Data\PluginConfiguration;
use Akeeba\SocialLogin\Library\Data\UserData;
use Akeeba\SocialLogin\Library\Exception\Login\GenericMessage;
use Akeeba\SocialLogin\Library\Exception\Login\LoginError;
use Akeeba\SocialLogin\Library\Helper\Integrations;
use Akeeba\SocialLogin\Library\Helper\Joomla;
use Akeeba\SocialLogin\Library\Helper\Login;
use Akeeba\SocialLogin\Library\OAuth\OAuth2Client;
use Akeeba\SocialLogin\Library\Plugin\AbstractPlugin;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

if (!class_exists('Akeeba\\SocialLogin\\Library\\Plugin\\AbstractPlugin', true))
{
	return;
}

/**
 * Akeeba Social Login plugin for Google integration
 */
class plgSocialloginGoogle extends AbstractPlugin
{
	/**
	 * Google Client ID
	 *
	 * @var   string
	 */
	private $clientId = '';

	/**
	 * Google Client Secret
	 *
	 * @var   string
	 */
	private $clientSecret = '';

	/**
	 * Google OAUth connector object
	 *
	 * @var   OAuth2
	 */
	private $connector;

	/**
	 * The OAuth2 client object used by the Google OAuth connector
	 *
	 * @var   OAuth2Client
	 */
	private $oAuth2Client;

	/**
	 * Constructor. Loads the language files as well.
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   array   $config    An optional associative array of configuration settings.
	 *                             Recognized key values include 'name', 'group', 'params', 'language'
	 *                             (this list is not meant to be comprehensive).
	 */
	public function __construct($subject, array $config = array())
	{
		parent::__construct($subject, $config);

		// Register the autoloader
		JLoader::registerNamespace('Akeeba\\SocialLogin\\Google', __DIR__ . '/Google', false, false, 'psr4');

// Per-plugin customization
		$this->buttonImage = 'plg_sociallogin_google/google.png';
		$this->customCSS = /** @lang CSS */
			<<< CSS
.akeeba-sociallogin-link-button-google, .akeeba-sociallogin-unlink-button-google, .akeeba-sociallogin-button-google { background-color: #4285F4; color: #ffffff; transition-duration: 0.33s; background-image: none; border-color: #4285F4; padding: 8px 8px; }
.akeeba-sociallogin-link-button-google:hover, .akeeba-sociallogin-unlink-button-google:hover, .akeeba-sociallogin-button-google:hover { background-color: #3c63cc; color: #ffffff; transition-duration: 0.33s; border-color: #3c63cc; }
.akeeba-sociallogin-link-button-google img, .akeeba-sociallogin-unlink-button-google img, .akeeba-sociallogin-button-google img { display: inline-block; width: 18px; height: 18px; margin: 0 24px 0 0; padding: 0 }

CSS;

		// Load the plugin options into properties
		$this->clientId            = $this->params->get('appid', '');
		$this->clientSecret        = $this->params->get('appsecret', '');
	}

	/**
	 * Is this integration properly set up and ready for use?
	 *
	 * @return  bool
	 */
	protected function isProperlySetUp()
	{
		return !(empty($this->clientId) || empty($this->clientSecret));
	}

	/**
	 * Returns a OAuth2 object
	 *
	 * @return  OAuth2
	 *
	 * @throws  Exception
	 */
	private function getConnector()
	{
		if (is_null($this->connector))
		{
			$options = array(
				'authurl'       => 'https://accounts.google.com/o/oauth2/auth',
				'tokenurl'      => 'https://accounts.google.com/o/oauth2/token',
				'clientid'      => $this->clientId,
				'clientsecret'  => $this->clientSecret,
				'redirecturi'   => JUri::base() . 'index.php?option=com_ajax&group=sociallogin&plugin=' . $this->integrationName . '&format=raw',
				/**
				 * Authorization scopes, space separated.
				 *
				 * @see https://developers.google.com/+/web/api/rest/oauth#authorization-scopes
				 */
				'scope'         => 'profile email',
				'requestparams' => array(
					'access_type'            => 'online',
					'include_granted_scopes' => 'true',
					'prompt'                 => 'select_account',
				),
			);

			$app                = Joomla::getApplication();
			$httpClient         = Joomla::getHttpClient();
			$this->oAuth2Client = new OAuth2Client($options, $httpClient, $app->input, $app);
			$this->connector    = new OAuth2($options, $this->oAuth2Client);
		}

		return $this->connector;
	}

	/**
	 * Returns the OAuth2Client we use to authenticate to Google
	 *
	 * @return  OAuth2Client
	 *
	 * @throws Exception
	 */
	private function getClient()
	{
		if (is_null($this->oAuth2Client))
		{
			$this->getConnector();
		}

		return $this->oAuth2Client;
	}

	/**
	 * Return the URL for the login button
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 */
	protected function getLoginButtonURL()
	{
		return $this->getClient()->createUrl();
	}

	/**
	 * Return the URL for the link button
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 */
	protected function getLinkButtonURL()
	{
		return $this->getLoginButtonURL();
	}

	/**
	 * Get the OAuth / OAuth2 token from the social network. Used in the onAjax* handler.
	 *
	 * @return  array|bool  False if we could not retrieve it. Otherwise [$token, $connector]
	 *
	 * @throws  Exception
	 */
	protected function getToken()
	{
		$connector    = $this->getConnector();

		/**
		 * I have to do this because Joomla's Google OAuth2 connector is buggy :@ The googlize() method assumes that
		 * the requestparams option is an array. However, when you construct the object Joomla! will "helpfully" convert
		 * your original array into an object. Therefore trying to later access it as an array causes a PHP Fatal Error
		 * about trying to access an stdClass object as an array...!
		 */
		$connector->setOption('requestparams', array(
			'access_type'            => 'online',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'select_account',
		));

		return [$connector->authenticate(), $connector];
	}

	/**
	 * Get the raw user profile information from the social network.
	 *
	 * @param   object  $connector  The internal connector object.
	 *
	 * @return  array
	 *
	 * @throws  Exception
	 */
	protected function getSocialNetworkProfileInformation($connector)
	{
		/** @var OAuth2 $connector */
		$options       = new Registry();
		$googleUserApi = new OpenID($options, $connector);
		$openIDProfile = $googleUserApi->getOpenIDProfile();

		return $openIDProfile;
	}

	/**
	 * Maps the raw social network profile fields retrieved with getSocialNetworkProfileInformation() into a UserData
	 * object we use in the Social Login library.
	 *
	 * @param   array $socialProfile The raw social profile fields
	 *
	 * @return  UserData
	 */
	protected function mapSocialProfileToUserData(array $socialProfile)
	{
		$userData           = new UserData();
		$userData->name     = isset($socialProfile['name']) ? isset($socialProfile['name']) : '';
		$userData->id       = $socialProfile['sub'];
		$userData->email    = isset($socialProfile['email']) ? $socialProfile['email'] : '';
		$userData->verified = isset($socialProfile['email_verified']) ? $socialProfile['email_verified'] : false;
		$userData->timezone = isset($socialProfile['zoneinfo']) ? $socialProfile['zoneinfo'] : 'GMT';

		return $userData;
	}

	/**
	 * Return the user's profile picture URL given the social network profile fields retrieved with
	 * getSocialNetworkProfileInformation(). Return null if no such thing is supported.
	 *
	 * @param   array $socialProfile The raw social profile fields
	 *
	 * @return  string|null
	 */
	protected function getPictureUrl(array $socialProfile)
	{
		return null;
	}

	/**
	 * Processes the authentication callback from Google.
	 *
	 * Note: this method is called from Joomla's com_ajax, not com_sociallogin itself
	 *
	 * @return  void
	 *
	 * @throws  Exception
	 */
	public function onAjaxGoogle()
	{
		$this->onSocialLoginAjax();
	}

}
