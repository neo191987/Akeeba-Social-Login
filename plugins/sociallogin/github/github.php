<?php
/**
 * @package   AkeebaSocialLogin
 * @copyright Copyright (c)2016-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

use Akeeba\SocialLogin\GitHub\OAuth as GitHubOAuth;
use Akeeba\SocialLogin\GitHub\UserQuery;
use Akeeba\SocialLogin\Library\Data\UserData;
use Akeeba\SocialLogin\Library\Helper\Joomla;
use Akeeba\SocialLogin\Library\Plugin\AbstractPlugin;
use Joomla\Registry\Registry;

if (!class_exists('Akeeba\\SocialLogin\\Library\\Plugin\\AbstractPlugin', true))
{
	return;
}

/**
 * Akeeba Social Login plugin for GitHub integration
 */
class plgSocialloginGithub extends AbstractPlugin
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
		JLoader::registerNamespace('Akeeba\\SocialLogin\\GitHub', __DIR__ . '/GitHub', false, false, 'psr4');

		// Per-plugin customization
		$this->buttonImage = 'plg_sociallogin_github/gh_white_32.png';
	}

	/**
	 * Returns a GitHubOAuth object
	 *
	 * @return  GitHubOAuth
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
			$this->connector = new GitHubOAuth($options, $httpClient, $app->input, $app);
			$this->connector->setScope('user');
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
		$ghUserQuery  = new UserQuery($client, $tokenArray['access_token']);
		$ghUserFields = $ghUserQuery->getUserInformation();

		return (array)$ghUserFields;
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
		$userData->name     = isset($socialProfile['name']) ? $socialProfile['name'] : '';
		$userData->id       = isset($socialProfile['id']) ? $socialProfile['id'] : '';
		$userData->email    = isset($socialProfile['email']) ? $socialProfile['email'] : '';
		$userData->verified = true;

		return $userData;
	}

	/**
	 * Processes the authentication callback from GitHub.
	 *
	 * Note: this method is called from Joomla's com_ajax, not com_sociallogin itself
	 *
	 * @return  void
	 *
	 * @throws  Exception
	 */
	public function onAjaxGithub()
	{
		$this->onSocialLoginAjax();
	}
}
