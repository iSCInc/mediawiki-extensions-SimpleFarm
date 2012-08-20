<?php

/**
 * Contains functions for 'Simple Farm' farm management.
 *
 * @file
 * @ingroup SimpleFarm
 *
 * @since 0.1
 * @author Daniel Werner < danweetz@web.de >
 */
class SimpleFarm {
	/**
	 * If set to a farm member within '$egSimpleFarmMembers' array (see settings file) it means that
	 * the wiki is not in maintenance mode right now.
	 */
	const MAINTAIN_OFF = false;
	/**
	 * Block simple browser access to the wiki but allow accessing the wiki with 'maintain' url parameter
	 */
	const MAINTAIN_SIMPLE = 1;
	/**
	 * Block all attempts to access wiki except for command-line based maintenance
	 */
	const MAINTAIN_STRICT = 2;
	/**
	 * Block all attempts to access wiki, even command-line
	 */
	const MAINTAIN_TOTAL = 3;
	
	private static $activeMember = null;
	public static $maintenanceIsRunning = false;
	
	/**
	 * Returns the defined main member of the wiki farm.
	 * If $wgSimpleFarmMainMemberDB has not been set yet, this will set $wgSimpleFarmMainMemberDB
	 * to the first member in $wgSimpleFarmMembers
	 * @return SimpleFarmMember|null null if no match could be found
	 */
	public static function getMainMember() {
		global $egSimpleFarmMainMemberDB, $egSimpleFarmMembers;

		// if variable was not set in config, fill it with first farm member or return null if none is defined:
		if( $egSimpleFarmMainMemberDB === null ) {
			if( ! empty( $egSimpleFarmMembers ) ) {
				$egSimpleFarmMainMemberDB = $egSimpleFarmMembers[0]['db'];
			} else {
				return null;
			}
		}
		return SimpleFarmMember::loadFromDatabaseName( $egSimpleFarmMainMemberDB );
	}
	
	/**
	 * Returns the SimpleFarmMember object selected to be loaded for this instance of the farm
	 * or the object that has already been loaded.
	 * If initialisation has not been kicked off yet, this will find the wiki which would be
	 * chosen by self::int(). The result of the function could change after self::intWiki()
	 * was called to initialise another wiki instead.
	 * $wgSimpleFarmWikiSelectEnvVarName should contain its final value when first calling this.
	 * 
	 * @return SimpleFarmMember|null null if no match could be found
	 */
	public static function getActiveMember() {
		global $wgCommandLineMode;
		global $egSimpleFarmWikiSelectEnvVarName, $egSimpleFarmMainMemberDB;

		// return last initialised farm member if available:
		if( self::$activeMember !== null ) {
			return self::$activeMember;
		}
		
		if( ! defined( 'SIMPLEFARM_ENVVAR' ) ) {
			define( 'SIMPLEFARM_ENVVAR', $egSimpleFarmWikiSelectEnvVarName );
		}
		// in commandline mode we check for environment variable to select a wiki:
		if( $wgCommandLineMode ) {
			/*
			 * if we are in command-line mode but no wiki was selected
			 * and this is not just the initial wiki call to run maintenance
			 * on several wikis
			 */
			$wikiEvn = getenv( SIMPLEFARM_ENVVAR );

			if( $wikiEvn === false ) {
				$member = self::getMainMember();
				if( ! $member ) {
					if( $egSimpleFarmMainMemberDB !== null ) {
						echo "~~\n~~ Simple Farm ERROR:";
						echo "~~\n~~   The configured main farm member \"" . $egSimpleFarmMainMemberDB .
							"\" does not exist.\n";
						echo "~~   You can change the main farm member in \$egSimpleFarmWikiSelectEnvVarName\n";
					}
					return null; // no main member defined, probably no members defined at all
				}
				// No member set, choose main member
				echo "~~\n~~ Simple Farm NOTE: No farm member selected in '" . SIMPLEFARM_ENVVAR . '\' environment var.'
					. "\n~~                   Auto-selected main member '" . $member->getDB() . "'.\n~~\n";
				return $member;
			}
			return SimpleFarmMember::loadFromDatabaseName( $wikiEvn );
		}
		// farm member called via browser, find out which member via original address:
		else {
			// only interesting if redirect_url is set, otherwise unlikely to be used anyway
			$currScriptPath = isset( $_SERVER['REDIRECT_URL'] ) ? $_SERVER['REDIRECT_URL'] : $_SERVER['SCRIPT_NAME'];

			// in case of scriptpath, there could be several matching paths, including more specific deep paths.
			// we have to get the most specific then and return it in the end
			$qualifiedMembers = array();

			// walk all farm members and see whether they fulfil criteria to be the loaded one right now:
			foreach( self::getMembers() as $member ) {
				
				switch( $member->getCfgMode() ) {
					
					// configuration uses script path for this one to identify as selected:
					case SimpleFarmMember::CFG_MODE_SCRIPTPATH:
						$memberScriptPath = $member->getScriptPath();
						// member is qualified if its path is part of current path or equal:
						if( strpos( $currScriptPath . '/', $memberScriptPath . '/' ) === 0 ) {
							// remember member as qualified. The more specific (longer) the matching path, the
							// more qualified that member is
							$qualifiedMembers[ strlen( $memberScriptPath ) ] = $member;
						}
						break;
						
					// configuration uses a set of addresses to identify as selected:
					case SimpleFarmMember::CFG_MODE_ADDRESS:
						if( in_array( $_SERVER['HTTP_HOST'], $member->getAddresses(), true ) ) {
							return $member;
						}
						break;
						
					// if not set up properly:
					case SimpleFarmMember::CFG_MODE_NONE:
						continue;
				}
			}
			if( !empty( $qualifiedMembers ) ) {
				// return member with the longest (most specific) path matching the current path:
				return $qualifiedMembers[ max( array_keys( $qualifiedMembers ) ) ];
			}
			return null; // no match with configuration array!
		}
	}
	
	/**
	 * Initialises the selected member wiki of the wiki farm. This is only possible
	 * once and must be done during localsettings configuration	 * 
	 * This will also modify some global variables, see SimpleFarm::initWiki() for details
	 * 
	 * @return boolean true
	 */
	public static function init() {
		// don't allow multiple calls!
		if( self::$activeMember !== null )
			return true; // for hook use!
		
		global $egSimpleFarmMainMemberDB, $wgCommandLineMode;
		
		// set some main member if not set in config and farm has members:
		if( $egSimpleFarmMainMemberDB === null ) {
			$egSimpleFarmMainMemberDB = self::getMainMember()->getDB();
		}		
		// get selected member for this farm call:
		$wiki = self::getActiveMember();
		
		// if wiki is not in farm list:
		if( $wiki === null ) {
			if( $wgCommandLineMode ) {
				// environment var not set properly (or no farm members)
				$options = '';
				foreach( self::getMembers() as $member ) {
					$options .= '~~   * \'' . $member . '\' for \'' . $member->getName() . "'\n";
				}
				self::dieEarly(
					"~~\n~~ Simple Farm ERROR:\n" .
					'~~   Environment variable \'' . SIMPLEFARM_ENVVAR . "' not set to a valid farm member.\n" .
					"~~   Valid members are:\n" . $options . "~~\n"
				);
			}
			else {
				// wiki not found, try to call user defined callback function and try return value:
				// (can't use hook-system here since it probably isn't loaded at this sage!)
				global $egSimpleFarmErrorNoMemberFoundCallback;
				
				if( is_callable( $egSimpleFarmErrorNoMemberFoundCallback ) ) {
					$wiki = call_user_func( $egSimpleFarmErrorNoMemberFoundCallback );
				}

				if( ! ( $wiki instanceof SimpleFarmMember ) ) {
					header( $_SERVER["SERVER_PROTOCOL"] . " 404 Not Found" );
					self::dieEarly( 'No wiki farm member found here!' );
				}
			}
		}
		self::initWiki( $wiki );
		return true; // for hook use!
	}
	
	/**
	 * Function to set all setup options to load a specific wiki of the wiki farm.
	 * Should only be called while 'LocalSettings.php' is running and after
	 * SimpleFarm extension has been initialised.
	 * 
	 * This will modify the following globals:
	 * 
	 *   $wgSitename        = SimpleFarmMember::getName();
	 *   $wgDBname          = SimpleFarmMember::getDB();
	 *   $wgScriptPath      = SimpleFarmMember::getScriptPath();
	 *   $wgUploadDirectory = "{$IP}/images/images_{$wgDBname}";
	 *   $wgUploadPath      = "{$wgScriptPath}/images/images_{$wgDBname}";
	 *   $wgLogo            = "{$wgScriptPath}/images/logos/{$wgDBname}.png";
	 *   $wgFavicon         = "{$wgScriptPath}/images/logos/{$wgDBname}.ico";
	 * 
	 * @param $wiki SimpleFarmMember to initialise
	 */
	public static function initWiki( SimpleFarmMember $wiki ) {
		global $IP;
		// globals to be configured:
		global $wgSitename, $wgDBname, $wgScriptPath, $wgUploadDirectory, $wgUploadPath, $wgLogo, $wgFavicon;
				
		$wgSitename = $wiki->getName();
		
		// check for maintain mode:
		if( $wiki->isInMaintainMode() && ! $wiki->userIsMaintaining() ) {
			self::dieEarly( "$wgSitename is in maintain mode currently! Please try again later." );
		}		
		self::$activeMember = $wiki;
		
		$wgScriptPath = $wiki->getScriptPath(); //in case of 'scriptpath' config and mod-rewrite, otherwise same value anyway
		$wgDBname = $wiki->getDB();
		$wgUploadDirectory = "{$IP}/images/images_{$wgDBname}";
		$wgUploadPath      = "{$wgScriptPath}/images/images_{$wgDBname}";
		if( ! is_dir( $wgUploadDirectory ) ) {
			mkdir( $wgUploadDirectory, 0777 );
		}
				
		$wgLogo    = "{$wgScriptPath}/images/logos/{$wgDBname}.png";
		$wgFavicon = "{$wgScriptPath}/images/logos/{$wgDBname}.ico";
		
		/*
		 * it's no good loading an individual config file here since it wouldn't
		 * be in the global scope and all globals had to be defined as global first...
		 * Hacking around this is too dirty (reading all globals in local scope and then
		 * transferring local scope back to global scope).
		 * 
		 * There is an easy way to allow custom config files in LocalSettings directly though:
		 * 
		 *   if( file_exists( "$IP/wikiconfigs/$wgDBname.php" ) ) {
		 *       include( "$IP/wikiconfigs/$wgDBname.php" );
		 *   }
		 *
		 * NOTE: how about a global function here?
		 */
		return true;
	}
	
	/**
	 * Return an array with all members as SimpleFarmMember objects. The key of each array item
	 * is the database name of the wiki farm member.
	 * 
	 * @return SimpleFarmMember[]
	 */
	public static function getMembers() {
		global $egSimpleFarmMembers;
		$members = array();
		foreach( $egSimpleFarmMembers as $member ) {
			$members[ $member['db'] ] = new SimpleFarmMember( $member );
		}
		return $members;
	}
	
	/**
	 * Use instead of wfDie() because global functions are loaded after localsettings.php
	 * and in some cases this could happen during localsettings is still running!
	 * 
	 * @param $dieMsg string message
	 */
	private static function dieEarly( $dieMsg = '' ) {
		echo $dieMsg;
		die( 1 );
	}
}
