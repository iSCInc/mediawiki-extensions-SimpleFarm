<?php

/**
 * Represents a SimpleFarm member wiki.
 *
 * @file
 * @ingroup SimpleFarm
 *
 * @since 0.1
 * @author Daniel Werner < danweetz@web.de >
 */
class SimpleFarmMember {

	private $siteOpt;

	const CFG_MODE_NONE = 0;
	const CFG_MODE_ADDRESS = 1;
	const CFG_MODE_SCRIPTPATH = 2;

	public function __construct( $siteOptions ) {
		$this->siteOpt = $siteOptions;
	}

	public function __toString() {
		return $this->getDB(); // the members unique qualifier
	}

	/**
	 * Load new SimpleFarmMember from its database name.
	 * If not successful null will be returned.
	 *
	 * @param $dbName string name of the database, case-insensitive
	 *
	 * @return SimpleFarmMember|null
	 */
	public static function loadFromDatabaseName( $dbName ) {
		global $egSimpleFarmMembers;

		foreach( $egSimpleFarmMembers as $siteOpt ) {
			if( strtolower( $siteOpt['db'] ) === strtolower( trim( $dbName ) ) ) {
				return new SimpleFarmMember( $siteOpt );
			}
		}
		return null;
	}

	/**
	 * Load new SimpleFarmMember from one of its addresses.
	 *
	 * @ToDo: More flexible and allowing other ways of forking farm members!
	 *
	 * @param $url String url or domain to datermine one of the registered server names/url for
	 *        this wiki-farm member. For example 'www.farm1.wikifarm.org' @ToDo: or 'http://foo.org/wiki1'.
	 *        Value is case-insensitive.
	 * @param $scriptPath String farm member script path as second criteria in addition to address
	 *
	 * @return SimpleFarmMember if not successful null will be returned
	 */
	public static function loadFromAddress( $url, $scriptPath = null ) {
		global $egSimpleFarmMembers, $egSimpleFarmIgnoredDomainPrefixes;

		if( $scriptPath !== null ) {
			$scriptPath = str_replace( "\\", "/", trim( $scriptPath ) );
		}

		// url to domain name. Trim url scheme,
		$pref = implode( '|', $egSimpleFarmIgnoredDomainPrefixes ); // no escaping necessary
		$url = str_replace( "\\", "/", strtolower( $url ) ); // for windows-style paths
		$address = preg_replace( '%^(?:.*://)?(?:(?:' . $pref . ')\.)?%', '', $url );
		$address = preg_replace( '%/.*%', '', $address );

		$members = SimpleFarm::getMembers();
		foreach( $members as $member ) {
			// if url matches:
			if( in_array( $address, $member->getAddresses() ) ) {
				// if script path is required, then check for it too:
				if( $scriptPath !== null ) {
					if( trim( $scriptPath ) === $member->getScriptPath() ) {
						return $member;
					}
				}
				else {
					return $member;
				}
			}
		}
		return null;
	}

	/**
	 * Load new SimpleFarmMember from its 'scriptpath' config value.
	 *
	 * @param $scriptPath String configured script path of the farm member which should be returend.
	 *
	 * @return SimpleFarmMember if not successful null will be returned
	 */
	public static function loadFromScriptPath( $scriptPath ) {
		$members = SimpleFarm::getMembers();
		foreach( $members as $member ) {
			if( $scriptPath !== null ) {
				if( trim( $scriptPath ) === $member->getScriptPath() ) {
					return $member;
				}
			} else {
				return $member;
			}
		}
	}

	/**
	 * Whether the wiki is set to maintaining mode right now.
	 * Returns the maintaining strictness.
	 *
	 * @return integer|false
	 */
	public function isInMaintainMode() {
		if( empty( $this->siteOpt['maintain'] ) ) {
			return SimpleFarm::MAINTAIN_OFF;
		} else {
			return $this->siteOpt['maintain'];
		}
	}

	/**
	 * Returns whether the user is a maintainer or not. A maintainer is the current user
	 * if he accesses the wiki via command-line or if he has the 'maintainer' url parameter set.
	 *
	 * @param User $user the user we want to know whether he is a maintainer right now.
	 *        If not set, the information will be returned for the current user.
	 *        This will only work after 'LocalSettings.php' since $wgUser is undefined earlier!
	 *
	 * @return boolean
	 */
	public function userIsMaintaining( User $user = null ) {
		global $wgUser, $wgCommandLineMode;

		// if $user is not the current user, he can't be maintaining anything right now
		if( $user === null ) {
			// $wgUser still null during localsettings.php config
			$user = $wgUser;
		}
		if( $wgUser !== null && $user->getId() !== $wgUser->getId() ) {
			return false;
		}

		// commandline usually is maintainer, so is the user if maintainer parameter is set in url
		switch( $this->isInMaintainMode() ) {
			// no break, step by step!
			case SimpleFarm::MAINTAIN_SIMPLE:
				if( isset( $_GET['maintainer'] ) || isset( $_GET['maintain'] ) ) {
					return true;
				}
			// no break!

			case SimpleFarm::MAINTAIN_STRICT:
				if( $wgCommandLineMode ) {
					return true;
				}
			// no break!

			case SimpleFarm::MAINTAIN_TOTAL:
			default:
				return false;
		}
	}

	/**
	 * Returns the Database name
	 *
	 * @return string
	 */
	public function getDB() {
		return $this->siteOpt['db'];
	}

	/**
	 * Returns the wiki name
	 *
	 * @return string
	 */
	public function getName() {
		return $this->siteOpt['name'];
	}

	/**
	 * Returns all domains of the wiki (either from $wgSimpleFarmMembers or in case
	 * 'scriptpath' is used, from the server directly.
	 *
	 * @return string[] or null in case of command-line access and missing 'address'
	 *         key in $wgSimpleFarmMembers config array.
	 */
	public function getAddresses() {
		// if addresses are not configured, return the server name:
		if( isset( $this->siteOpt['addresses'] ) ) {
			$addr = $this->siteOpt['addresses'];
		}
		elseif( isset( $_SERVER['HTTP_HOST'] ) ) {
			return array( $_SERVER['HTTP_HOST'] );
		}
		else {
			// in case arr option is not set and we are in commandline-mode!
			return null;
		}

		if( is_array( $addr ) ) {
			return $addr;
		} else {
			return array( $addr );
		}
	}

	/**
	 * Convenience function to just return the first defined address instead of all
	 * addresses as self::getAddresses() would return it.
	 *
	 * @return string
	 */
	public function getFirstAddress() {
		$addr = $this->getAddresses();
		return $addr[0];
	}

	/**
	 * returns the configured script path if set.
	 * Otherwise the value of $wgScriptPath
	 *
	 * @return string
	 */
	public function getScriptPath() {
		if( isset( $this->siteOpt['scriptpath'] ) ) {
			$scriptPath = $this->siteOpt['scriptpath'];
		} else {
			global $wgScriptPath;
			$scriptPath = $wgScriptPath;
		}

		// we don't want to tread Windows '\' differently, so replace
		$scriptPath = str_replace( '\\', '/', trim( $scriptPath ) );

		// ignore '/' in the end
		return preg_replace( '%/$%', '', $scriptPath );
	}

	/**
	 * returns the config mode this farm member uses to be selected as active wiki.
	 *
	 * @return integer flag self::CFG_MODE_SCRIPTPATH, self::CFG_MODE_ADDRESS or
	 *         self::CFG_MODE_NONE if not set up properly.
	 */
	public function getCfgMode() {
		if( isset( $this->siteOpt['scriptpath'] ) ) {
			return self::CFG_MODE_SCRIPTPATH;
		}
		elseif( isset( $this->siteOpt['addresses'] ) ) {
			return self::CFG_MODE_ADDRESS; //address can be given even if 'scriptpath' is
		}
		else {
			return self::CFG_MODE_NONE;
		}
	}

	/**
	 * Whether or not this member wiki has been declared the main member.
	 * The main member is important for maintenance reasons only.
	 *
	 * @return boolean
	 */
	public function isMainMember() {
		return ( $this->getDB() === SimpleFarm::getMainMember()->getDB() );
	}

	/**
	 * Whether the farm member wiki is the wiki currently accessed in this run.
	 *
	 * @return boolean
	 */
	public function isActive() {
		return $this->getDB() === SimpleFarm::getActiveMember()->getDB();
	}

	/**
	 * Returns an value previously set for this object via $wgSimpleFarmMembers configuration.
	 *
	 * @param $name string name of the array key representing an option
	 *        within the $wgSimpleFarmMembers sub-array for this object
	 * @param $default mixed default value if config key $name was not set for farm member
	 *
	 * @return mixed
	 */
	public function getCfgOption( $name, $default = false ) {
		if( array_key_exists( $name, $this->siteOpt ) ) {
			return $this->siteOpt[ $name ];
		} else {
			return $default;
		}
	}

	/**
	 * Same as SimpleFarmMember::getCfgOption
	 * This requires PHP 5.3!
	 *
	 * @return mixed
	 */
	public function __invoke( $name, $default = false ) {
		return $this->getCfgOption( $name, $default );
	}
}
