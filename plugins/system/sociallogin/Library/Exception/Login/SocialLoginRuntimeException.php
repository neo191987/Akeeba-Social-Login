<?php
/**
 * @package   AkeebaSocialLogin
 * @copyright Copyright (c)2016-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\SocialLogin\Library\Exception\Login;

use RuntimeException;

// Protect from unauthorized access
defined('_JEXEC') or die();

class SocialLoginRuntimeException extends RuntimeException {}
{

}