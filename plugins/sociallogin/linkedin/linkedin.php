<?php
/**
 * @package   AkeebaSocialLogin
 * @copyright Copyright (c)2016-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

use Akeeba\SocialLogin\Library\Data\UserData;
use Akeeba\SocialLogin\Library\Helper\Joomla;
use Akeeba\SocialLogin\Library\Plugin\AbstractPlugin;
use Akeeba\SocialLogin\LinkedIn\OAuth as LinkedInOAuth;
use Akeeba\SocialLogin\LinkedIn\UserQuery;
use Joomla\Registry\Registry;

if (!class_exists('Akeeba\\SocialLogin\\Library\\Plugin\\AbstractPlugin', true))
{
	return;
}

/**
 * Akeeba Social Login plugin for LinkedIn integration
 */
class plgSocialloginLinkedin extends AbstractPlugin
{
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
		JLoader::registerNamespace('Akeeba\\SocialLogin\\LinkedIn', __DIR__ . '/LinkedIn', false, false, 'psr4');

		// Per-plugin customization
		$this->buttonImage = 'plg_sociallogin_linkedin/linkedin_34.png';
	}

	/**
	 * Returns a LinkedInOAuth object
	 *
	 * @return  LinkedInOAuth
	 *
	 * @throws  Exception
	 */
	protected function getConnector()
	{
		if (is_null($this->connector))
		{
			$options = array(
				'clientid'     => $this->appId,
				'clientsecret' => $this->appSecret,
				'redirecturi'  => JUri::base() . 'index.php?option=com_ajax&group=sociallogin&plugin=' . $this->integrationName . '&format=raw',
			);
			$app             = Joomla::getApplication();
			$httpClient      = Joomla::getHttpClient();
			$this->connector = new LinkedInOAuth($options, $httpClient, $app->input, $app);
			$this->connector->setScope('r_basicprofile r_emailaddress');
		}

		return $this->connector;
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
		$tokenArray   = $connector->getToken();

		$options      = new Registry(array(
			'userAgent' => 'Akeeba-Social-Login',
		));
		$client       = \Joomla\CMS\Http\HttpFactory::getHttp($options);
		$liUserQuery  = new UserQuery($client, $tokenArray['access_token']);
		$liUserFields = $liUserQuery->getUserInformation();

		return (array)$liUserFields;
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
		$nameParts = [];

		if (isset($socialProfile['firstName']))
		{
			$nameParts[] = $socialProfile['firstName'];
		}

		if (isset($socialProfile['lastName']))
		{
			$nameParts[] = $socialProfile['lastName'];
		}

		$userData           = new UserData();
		$userData->name     = implode(' ', $nameParts);
		$userData->id       = $socialProfile['id'];
		$userData->email    = isset($socialProfile['emailAddress']) ? $socialProfile['emailAddress'] : '';
		$userData->verified = true;

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
		return $socialProfile['pictureUrl'];
	}

	/**
	 * Processes the authentication callback from LinkedIn.
	 *
	 * Note: this method is called from Joomla's com_ajax, not com_sociallogin itself
	 *
	 * @return  void
	 *
	 * @throws  Exception
	 */
	public function onAjaxLinkedin()
	{
		$this->onSocialLoginAjax();
	}
}
