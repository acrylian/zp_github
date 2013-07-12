<?php
/**
 * A plugin to read and display some info from a user and its repos on GitHub
 *
 * The plugin caches all request results in the plugin_storgage table to limit http requests to the GitHub server. 
 * To limit db requests it is also a good idea to use the static_html_cache plugin additionally.
 * Note it is not possible to delete this cache manually currently. 
 * 
 * Requirements: PHP 5.3+, cURL and JSON PHP server extensions.
 * 
 * Usage:
 * Class methods: 
 * $obj = new zpGitHub('<username>');
 * echo $obj->getReposListHTML(); // prints a html list of all repos of the user <username>
 * $data = $obj->fetchData($url); // Gets array info of any GitHub api url. See http://developer.github.com/v3/ for details
 * 
 * Template function: echo getGitHub_repos($user,$exclude); // prints a html list of all repos of the user <username>
 * Content macro: [GITHUBREPOS <username> <exclude>]
 * Prints a html of the GitHub user <username> (like  echo $obj->getReposListHTML() above would do)
 * <exclude> is optionally an array to exclude specific repos, e.g. array("repo1","repo2")
 *
 * Template function: echo getGitHub_raw($url);
 * Content macro: [GITHUBRAW <url>]
 * Prints the raw file content of the file referenced and a link to the single file page.
 * <url> is the url to a GitHub single file page like:
 * https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
 *
 * @license GPL v3 
 * @author Malte Müller (acrylian)
 *
 * @package plugins
 * @subpackage misc
 */
 /** clear cache  ********************************************************** */
/* * ***************************************************************************** */
/* if (!defined('OFFSET_PATH')) {
	define('OFFSET_PATH', 3);
	require_once(dirname(dirname(__FILE__)) . '/zp-core/admin-functions.php');
	if (isset($_GET['action'])) {
		if (sanitize($_GET['action']) == 'clear_zpgithub_cache') {
			if (!zp_loggedin(ADMIN_RIGHTS)) {
				// prevent nefarious access to this page.
				header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?from=' . currentRelativeURL());
				exitZP();
			}
			zp_session_start();
			XSRFdefender('zpgithub');
			//query("DELETE FROM " . prefix('plugin_storage') . " WHERE `type` = 'zpgithub'");
			header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?action=external&msg=' . gettext('The GitHub db cache has been cleared.'));
			exitZP();
		}
	}
} */
$plugin_is_filter = 9|THEME_PLUGIN|ADMIN_PLUGIN;
$plugin_description = gettext('A plugin to read some info from a user and its repos on GitHub.');
$plugin_author = 'Malte Müller (acrylian)';
$plugin_version = '1.0';
$option_interface = 'zpgithubOptions';

zp_register_filter('content_macro','zpGitHub::zpgithub_macro');
//zp_register_filter('admin_utilities_buttons', 'zpGitHub::overviewbutton');

class zpgithubOptions {

	function __construct() {
		setOptionDefault('zpgithub_cache_expire', 86400); //TODO: Add cache clear utility button
	}
	
	function getOptionsSupported() {
		$options = array(gettext('Cache expire') => array('key' => 'zpgithub_cache_expire', 'type' => OPTION_TYPE_TEXTBOX,
										'order' => 0,
										'desc' => gettext("When the cache should expire in seconds. Default is 86400 seconds (1 day  = 24 hrs * 60 min * 60 sec)."))
		);
		return $options;
	}
}

class zpGitHub {
	public $user = '';
	public $user_baseurl = 'https://api.github.com/users';
	public $user_basedata = '';
	public $user_url = '';
	public $repos_baseurl = 'https://api.github.com/repos';
	public $lastupdate = false;
	public $today = '';
	public $cache_expire = '';
	
	function __construct($user) {
		$this->user = $user;
		$this->today = time();
		$this->cache_expire = getOption('zpgithub_cache_expire');
		$lastupdate = query_single_row("SELECT `data` FROM ".prefix('plugin_storage')." WHERE `type` = 'zpgithub' AND `aux` = 'lastupdate_".$this->user."'");
		if($lastupdate) {
			$this->lastupdate = $lastupdate['data'];
		} else {
			$this->lastupdate = $this->today;
			$query2 = query("INSERT INTO ".prefix('plugin_storage')." (`type`,`data`,`aux`) VALUES ('zpgithub',".$this->today.",'lastupdate_".$this->user."')");
		}
		$this->user_basedata = $this->getUserBaseData();
	}
	/*
	* Gets the requested repo data either via cURL http request or from the database cache
	* @param string $url an GitHub api v3 url, e.g. a user like 
	* return array
	*/
	function fetchData($url) {
		$array = array();
		$db = query_single_row("SELECT `data` FROM ".prefix('plugin_storage')." WHERE `type` = 'zpgithub' AND `aux` = ".db_quote($url));
		if($db) {
			if($this->today - $this->lastupdate < $this->cache_expire) { 
				$array =  unserialize($db['data']);
			} else { // if exists and not outdated use it
				$array = $this->getDataCurl($url);
				if(is_array($array) && !array_key_exists('message',$array)) { // catch error message from GitHub
					$data = serialize($array);
					$this->updateDBEntry($url,$data);
				} 
			}
		} else { // if not exisiting create db entry
			$array = $this->getDataCurl($url);
			if(is_array($array) && !array_key_exists('message',$array)) { 
				$data = serialize($array);
				$this->createDBEntry($url,$data);
			}
		}
	  return $array;
	}
	
	private function updateDBEntry($url,$data) {
		$query = query("UPDATE ".prefix('plugin_storage')." SET `data` = ".db_quote($data)." WHERE `type` = 'zpgithub' AND `aux` = ".db_quote($url));
		$query2 = query("UPDATE ".prefix('plugin_storage')." SET `data` = '".$this->today."' AND `aux` = 'lastupdate_".$this->user."' WHERE `type` = 'zpgithub' AND `aux` = 'lastupdate_".$this->user."'");
		$this->lastupdate = $this->today;
	}
	
	private function createDBEntry($url,$data) {
		$query = query("INSERT INTO ".prefix('plugin_storage')." (`type`,`data`,`aux`) VALUES ('zpgithub',".db_quote($data).",".db_quote($url).")");
		//$query2 = query("UPDATE ".prefix('plugin_storage')." SET `data` = '".$this->today."' AND `aux` = 'lastupdate_".$this->user."' WHERE `type` = 'zpgithub' AND `aux` = 'lastupdate_".$this->user."' AND `data` = '".$this->today."'");
		$this->lastupdate = $this->today;
	}
	
	/*
	* Gets the requested data via cURL
	* @param string $url an GitHub api v3 url, e.g. a user like https://api.github.com/users/zenphoto
	* return array
	*/
	private function getDataCurl($url) {
		$array = array();
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT,"Safari");
		$r = curl_exec($ch);
		curl_close($ch);
		$array = json_decode($r, true); 
		return $array;
	}
	
	/*
	* Gets a raw file from a GitHub repo
	* @param string $url Url to the single file page ("blob"), e.g. https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
	* return string
	*/
	function getRawFile($url) {
		$file = '';
		$db = query_single_row("SELECT `data` FROM ".prefix('plugin_storage')." WHERE `type` = 'zpgithub' AND `aux` = ".db_quote($url));
		$rawurl = str_replace('/blob/','/',$url);
		$rawurl = str_replace('https://github.com/','https://raw.github.com/',$rawurl);
		if($db) {
			if($this->today - $this->lastupdate < $this->cache_expire) {
				$file = $db['data'];
			} else {
				$file = file_get_contents($rawurl);
				$this->updateDBEntry($url,$file);
			}
		} else {
			$file = file_get_contents($rawurl);
			$this->createDBEntry($url,$file);
		}
		return $file;
	}
	
	/*
	* Gets a raw file ready made for printing wrapped in <pre> element with a link to the single file page
	* @param string $rawfile The rawfile as fetched by getRawFile()
	* return string
	*/
	function getRawFileHTML($rawfile,$url) {
		$html = '';
		if($rawfile) {
			$html .= '<div class="githubraw-link">'."\n";
			$html .= '<p><a href="'.$url.'" target="_blank">View file on GitHub</a></p>'."\n";
			$html .= '<pre class="githubraw-code">'."\n";
			$html .= html_encode($rawfile);
			$html .= '</pre>'."\n";
			$html .= '</div>'."\n";
		}
		return $html;
	}
	/*
	* Gets the user base data
	* return array
	*/
	function getUserBaseData() {
		$this->user_url = $this->user_baseurl.'/'.$this->user;
		$basedata = $this->fetchData($this->user_url);
		return $basedata;
	}
	
	/*
	* Gets a list of all repos of the user
	* return array
	*/
	function getRepos() {
	  $url = $this->user_basedata['repos_url'];
	  $array = $this->fetchData($url);
	  return $array;
	}
	
	/* 
	* Gets the data of one repo of the user, internally uses getRepos() to get all so additional repos are available as well.
	* @param string $repo the pure name of the repo
	* return array
	*/
	function getRepo($repo) {
		$url = $this->repos_baseurl.'/'.$this->user.'/'.$repo;
	  $array = $this->fetchData($url);
	  return $array;
	}
	/*
	* Gets the extra data chosen (tags, issues, branches currently) of one repo of the user
	* @param string $repo the pure name of the repo
	* @param string $type What data to get: issues, tags, branches (limited currently to save unneeded url request)
	* return array
	*/
	function getRepoExtraData($repo,$type) {
		$array = array();
		$url = '';
		// Yes, this is quite limiting but to protect from unwanted url requests to GitHub as they are limited unauthorized.
		// We could use getRepo() to get the array of supported items but that of course would be 
		// an additional request as well
		switch($type) {
			case 'tags':
			case 'issues':
			case 'branches':
				$url = $this->repos_baseurl.'/'.$this->user.'/'.$repo.'/'.$type;
				break;
		}
		if(!empty($url)) {
			$array = $this->fetchData($url);
		}
		if(count($array) != 0) {
			return $array;
		}
	}
	
	/*
	* Example function to get HTML of a list of all repos of a user that can be echoed (so it is usable as a macro later on as well)
	* @param array $repos The repos as returned by getRepos()
	* @param array $exclude array with repo names to exclude from the list (Example: array('repo1','repo2'))
	* @param bool $showtags True or false to show links to the tagged releases
	* @param bool $showbranches True or false to show links the branches
	* return string
	*/
	function getReposListHTML($repos,$exclude='',$showtags,$showbranches) {
		if(!is_array($repos)) {
			return false;
		}
		if(!is_array($exclude)) {
			$exclude = array();
		}
		if(array_key_exists('message',$repos)) { // catch error message from GitHub
			return '<p>'.$repos['message'].'</p>';
		}
		//echo "<pre>";print_r($data); echo "</pre>";
		$html = '<ol class="githubrepos">';
		foreach($repos as $repo) {
			if(!in_array($repo['name'],$exclude)) {
				$html .= '<li>'.$this->getRepoHTML($repo,$showtags,$showbranches).'</li>';
			}
		}
		$html .= '</ol>';
		return $html;
	}
	
	/*
	* Example function to get HTML for one repo that can be echoed (so it is usable as a macro later on as well)
	* @param array $repo Array of a repo as fetched by getRepo().
	* @param bool $showtags True or false to show links to the tagged releases
	* @param bool $showbranches True or false to show links the branches
	* return string
	*/
	function getRepoHTML($repo,$showtags=true,$showbranches=true) {
		$html = '';
		if(!is_array($repo)) {
			return gettext('No valid data submitted.');
		}
		$tags = array();
		$branches = array();
		$html .= '<h3 class="repoheadline"><a href="'.$repo['html_url'].'">'.$repo['name'].'</a></h3>';
		$html .= '<p class="repodesc">'.$repo['description'].'</p>';
		$issues = $repo['open_issues'];
		if($issues != 0) {
			$issues = '<a href="http://github.com/'.$this->user.'/'.$repo['name'].'/issues?state=open">'.$issues.'</a>';
		} 
		$html .= '<p class="repometadata">'.gettext('Last update: ').$repo['updated_at'].' | '.gettext('Language: ').$repo['language'].' | '.gettext('Open issues: ').$issues.'</p>';
		if($showtags) {
			$tags = $this->getRepoExtraData($repo['name'],'tags');		
			if(is_array($tags) && !array_key_exists('message',$tags)) { // catch error message from GitHub
				$html .= '<h4 class="repotags">'.gettext('Releases').'</h4>';
				$html .= '<ul class="repotags">';
				foreach($tags as $tag) {
					$html .= '<li>'.$tag['name'].': <a href="'.$tag['zipball_url'].'">zip</a> | <a href="'.$tag['tarball_url'].'">tar</a></li>';
				}
				$html .= '</ul>';
			} else {
				$html .= '<p>'.$tags['message'].'</p>';
			}
		}
		if($showbranches) {
			$branches = $this->getRepoExtraData($repo['name'],'branches');		
			if(is_array($branches) && !array_key_exists('message',$branches)) { // catch error message from GitHub
				$html .= '<h4 class="repobranches">'.gettext('Branch downloads').'</h4>';
				$html .= '<ul class="repobranches">';
				foreach($branches as $branch) {
					$html .= '<li>'.$branch['name'].': ';
					$html .= '<a href="'.$this->repos_baseurl.'/'.$this->user.'/'.$repo['name'].'/zipball/'.$branch['name'].'">zip</a> | ';
					$html .= '<a href="'.$this->repos_baseurl.'/'.$this->user.'/'.$repo['name'].'/tarball/'.$branch['name'].'">tar</a>';
					$html .= '</li>';
				}
				$html .= '</ul>';
			} else {
				$html .= '<p>'.$branches['message'].'</p>';
			}
		}
		return $html;
	}
	
 /*
	* GitHub macro definition
	* @param array $macros
	* return array
	*/
	static function zpgithub_macro($macros) {
		$macros['GITHUBREPOS'] = array(
					'class'=>'function',
					'params'=> array('string','bool*','bool*','array*'), 
					'value'=>'getGitHub_repos',
					'owner'=>'zpgithub',
					'desc'=>gettext('The GitHub user to print the repos in a nested html list from the user (%1). Optionally array of the names of repos to exclude from the list (%2), show tagged release downloads (%3) and the branches (%4).')
				);
				$macros['GITHUBREPO'] = array(
					'class'=>'function',
					'params'=> array('string','string','bool*','bool*'), 
					'value'=>'getGitHub_repo',
					'owner'=>'zpgithub',
					'desc'=>gettext('The GitHub user (%1) and the repo name to get (%2). Optionally true/false to show tagged release downloads (%3) and the branches (%4). The macro will print html formatted data of the repo.')
				);
		$macros['GITHUBRAW'] = array(
					'class'=>'function',
					'params'=> array('string'), 
					'value'=>'getGitHub_raw',
					'owner'=>'zpgithub',
					'desc'=>gettext('Enter the url to a single file page on a GitHub repo (%1) and the macro returns the raw file contents wrappred pre element code and a link to the the single file page.')
				);
		return $macros;
	}
	
	static function overviewbutton($buttons) {
		$buttons[] = array(
									'XSRFTag' => 'zpgithub',
									'category'=>gettext('Cache'),
									'enable'=>true,
									'button_text'=>gettext('Clear GitHub cache'),
									'formname'=>'zpgithub_button',
									'action'=>WEBPATH.'/'.USER_PLUGIN_FOLDER.'/zp_github.php?action=clear_zpgithub_cache',
									'icon'=>'images/cache.png',
									'alt'=> gettext('Deletes the GitHub data cached by the zp_github plugin in the database.'),
									'hidden'=>'',
									'rights'=>ADMIN_RIGHTS,
									'title'=> gettext('Deletes the GitHub data cached by the zp_github plugin in the database.')
									);
		return $buttons;
	}

} // class end

/* Theme functions */

 /*
	* Get the repo info as a nested html list to print via macro
	* @param string $user GitHub user name
	* @param bool $showtags True or false to show links to the tagged releases
	* @param bool $showbranches True or false to show links the branches
	* @param array $exclude array of repo names to exclude
	* return string
	*/
	function getGitHub_repos($user,$showtags,$showbranches,$exclude) {
		$obj = new zpGitHub($user);	
		$repos = $obj->getRepos();
		$html = $obj->getReposListHTML($repos,$exclude);
		return $html;
	}
	
	 /*
	* Get the repo info as a nested html list to print via macro
	* @param string $user GitHub user name
	* @param string $repo name of the repo to get
	* @param bool $showtags True or false to show links to the tagged releases
	* @param bool $showbranches True or false to show links the branches
	* return string
	*/
	function getGitHub_repo($user,$repo,$showtags,$showbranches) {
		$obj = new zpGitHub($user);	
		$repo = $obj->getRepo($repo);
		$html = $obj->getRepoHTML($repo,$showtags,$showbranches);
		return $html;
	}
	
 /*
	* Get raw file of a GitHub stored single file ready made with html markup for printing on a page
	* @param string $url Url to the single file page ("blob"), e.g. https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
	* return string
	*/
	function getGitHub_raw($url) {
		$html = ''; 
		// get user name from the url itself to avoid a parameter
		$explode = explode('/',$url); 
		$user = $explode[3];
		$obj = new zpGitHub($user);	
		$rawfile = $obj->getRawFile($url);
		$html = $obj->getRawFileHTML($rawfile,$url);
		return $html;
	} 