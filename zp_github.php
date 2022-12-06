<?php

/**
 * A plugin to read and display some info from a user and its repos on GitHub via its REST API v3. Additionally the plugin optionally 
 * can create Zenpage pages for each user's repo and Zenpage articles for each repo's releases/tags.
 *
 * NOTE: The plugin does use unauthenticated access to the GitHub API and is really only meant to show general public static info 
 * like your repositories on your website and or direct links. It is not meant for advanced actions. 
 * Since the access is limited to 60 requests per hour, the plugin caches all request results in the plugin_storgage table 
 * to limit http requests to the GitHub server. Default is auto update once a day.
 * 
 * Requirements: PHP 5.3+, cURL and JSON PHP server extensions.
 * 
 * It also includes the Parsedown and ParsedownExtra libaries to convert Markdown formatted text into HTML. It supports GitHub flavourde Markdown.
 * http://parsedown.org/https://github.com/erusev/parsedown by Emanuil Rusev http://erusev.com, 
 * License: MIT license
 * 
 * @author Malte Müller (acrylian) <info@maltem.de>
 * @copyright 2017 Malte Müller
 * @license GPL v3 or later
 * @package plugins
 * @subpackage tools
 */
//clear cache
if (!defined('OFFSET_PATH')) {
	define('OFFSET_PATH', 1);
	require_once(dirname(dirname(__FILE__)) . '/zp-core/admin-functions.php');
	if (isset($_GET['action'])) {
		$action = sanitize($_GET['action']);
		if (!zp_loggedin(ADMIN_RIGHTS)) {
			// prevent nefarious access to this page.
			header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?from=' . currentRelativeURL());
			exitZP();
		}
		if ($action == 'clear_zpgithub_cache') {
			XSRFdefender('zp_github');
			$action = sanitize($action);
			query("DELETE FROM " . prefix('plugin_storage') . " WHERE `type` = 'zpgithub'");
			header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?action=external&msg=' . gettext('The GitHub db cache has been cleared.'));
			exitZP();
		}
	}
}
$plugin_is_filter = 9 | THEME_PLUGIN | ADMIN_PLUGIN;
$plugin_description = gettext('A plugin to read some public info from a user/organization and its repos on GitHub via GitHub REST API v3. Includes the Parsedown libary to convert Markdown formatted text to HTML.');
$plugin_author = 'Malte Müller (acrylian)';
$plugin_version = '2.1';
$option_interface = 'zpgithubOptions';

zp_register_filter('content_macro', 'zpGitHub::zpgithub_macro');
zp_register_filter('admin_utilities_buttons', 'zpGitHub::overviewbuttons');

require_once(SERVERPATH . '/' . USER_PLUGIN_FOLDER . '/zp_github/Parsedown.php');
require_once(SERVERPATH . '/' . USER_PLUGIN_FOLDER . '/zp_github/ParsedownExtra.php');

/**
 * Option class for zpGitHub
 */
class zpgithubOptions {

	function __construct() {
		setOptionDefault('zpgithub_cache_expire', 86400); //TODO: Add cache clear utility button
	}

	function getOptionsSupported() {
		$options = array(gettext('Cache expire') => array('key' => 'zpgithub_cache_expire', 'type' => OPTION_TYPE_TEXTBOX,
						'order' => 0,
						'desc' => gettext("When the cache should expire in seconds. Default is 86400 seconds (1 day  = 24 hrs * 60 min * 60 sec).")),
				gettext('Release text') => array('key' => 'zpgithub_releasetext', 'type' => OPTION_TYPE_TEXTBOX,
						'order' => 0,
						'desc' => gettext("This text is the intro if release articles are created automatically. It is followed by the description of the repo and the zip/tar download links."))
		);
		return $options;
	}

}

/**
 * Class for accessing (public) GitHub data via the REST API v3
 * See general usage instruction at the beginning of this file
 * 
 * Usage ways:
 * a) Class : 
 * $obj = new zpGitHub('<username>');
 * echo $obj->getReposListHTML(); 
 * Prints a html list of all repos of the user <username>
 *
 * $data = $obj->fetchData($url); //
 * Gets array info of any GitHub api url. See http://developer.github.com/v3/ for details
 * 
 * b) Template functions: 
 * Here use the static class methods provided (procedural template functions have been removed with 2.0)
 * echo zpGitHub::getGitHub_repos('<username>', $showreleases, $showbranches, $exclude);
 * Prints a html list of all repos of the user <username> (like echo $obj->getReposListHTML() above would do)
 * <exclude> is optionally an array to exclude specific repos, e.g. array("repo1","repo2")
 *
 * echo zpGitHub::getGitHub_raw($url,$convertMarkdown);
 * Prints the raw file content of the file referenced and a link to the single file page.
 * <url> is the url to a GitHub single file page like:
 * https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
 * You can also have it convert markdown to HTML
 *
 * c) Content macros: 
 * [GITHUBREPOS <username> <reponame> <releases> <branches>]
 * [GITHUBREPOS_ORG <organizationname> <reponame> <releases> <branches>]
 * [GITHUBREPO <username> <reponame> <releases> <branches>]
 * [GITHUBREPO_ORG <organizationname> <reponame> <releases> <branches>]
 * [GITHUBRAW <url> <convertMarkdown>]
 * The macros work the same as the template functions on b).
 * 
 * Please the the comments on each method and function below for more details on the parameters and usages.
 * 
 * d) Separate markdown conversion:
 * $html = zpGitHub::convertMarkdown($markdown);
 */
class zpGitHub {

	public $user = '';
	public $user_baseurl = '';
	public $user_basedata = '';
	public $user_url = '';
	public $user_allrepos_url = '';
	public $user_repo_baseurl = '';
	public $lastupdate = false;
	public $today = '';
	public $cache_expire = '';

	public function __construct($user) {
		global $_zp_db;
		$this->user = $user;
		$this->user_allrepos_url = 'https://api.github.com/users/' . $this->user . '/repos';
		$this->user_repo_baseurl = 'https://api.github.com/repos/' . $this->user;
		$this->user_baseurl = 'https://api.github.com/users';
		$this->user_url = $this->user_baseurl . '/' . $this->user;
		$this->today = time();
		$this->cache_expire = getOption('zpgithub_cache_expire');
		$lastupdate = $_zp_db->querySingleRow("SELECT `data` FROM " . $_zp_db->prefix('plugin_storage') . " WHERE `type` = 'zpgithub' AND `aux` = 'lastupdate_" . $this->user . "'");
		if ($lastupdate) {
			$this->lastupdate = $lastupdate['data'];
		} else {
			$this->lastupdate = $this->today;
			$query2 = $_zp_db->query("INSERT INTO " . $_zp_db->prefix('plugin_storage') . " (`type`,`data`,`aux`) VALUES ('zpgithub'," . $this->today . ",'lastupdate_" . $this->user . "')");
		}
		$this->user_basedata = $this->getUserBaseData();
	}

	/**
	 * Gets the requested repo data either via cURL http request or from the database cache
	 * @param string $url an GitHub api v3 url, e.g. a user like 
	 * return array
	 */
	function fetchData($url) {
		global $_zp_db;
		$array = array();
		$db = $_zp_db->querySingleRow("SELECT `data` FROM " . $_zp_db->prefix('plugin_storage') . " WHERE `type` = 'zpgithub' AND `aux` = " . $_zp_db->quote($url));
		if ($db) {
			if ($this->today - $this->lastupdate < $this->cache_expire) {
				$array = unserialize($db['data']);
			} else { // if exists and not outdated use it
				$array = $this->getDataCurl($url);
				if (is_array($array) && !array_key_exists('message', $array)) { // catch error message from GitHub
					$data = serialize($array);
					$this->updateDBEntry($url, $data);
				}
			}
		} else { // if not exisiting create db entry
			$array = $this->getDataCurl($url);
			if (is_array($array) && !array_key_exists('message', $array)) {
				$data = serialize($array);
				$this->createDBEntry($url, $data);
			}
		}
		return $array;
	}

	/**
	 * Updates am existing db entry
	 * @param string $url The GitHub url
	 * @param string $data The data stored as passed
	 */
	private function updateDBEntry($url, $data) {
		global $_zp_db;
		$query = $_zp_db->query("UPDATE " . $_zp_db->prefix('plugin_storage') . " SET `data` = " . $_zp_db->quote($data) . " WHERE `type` = 'zpgithub' AND `aux` = " . $_zp_db->quote($url));
		if ($query) {
			$query2 = $_zp_db->query("UPDATE " . $_zp_db->prefix('plugin_storage') . " SET `data` = '" . $this->today . "' AND `aux` = 'lastupdate_" . $this->user . "' WHERE `type` = 'zpgithub' AND `aux` = 'lastupdate_" . $this->user . "'");
			if ($query2) {
				$this->lastupdate = $this->today;
				return true;
			}
		}
	}

	/**
	 * Creates a db entry
	 * @param string $url The GitHub url
	 * @param string $data The data stored as passed
	 */
	private function createDBEntry($url, $data) {
		global $_zp_db;
		$query = $_zp_db->query("INSERT INTO " . $_zp_db->prefix('plugin_storage') . " (`type`,`data`,`aux`) VALUES ('zpgithub'," . $_zp_db->quote($data) . "," . $_zp_db->quote($url) . ")");
		if ($query) {
			$this->lastupdate = $this->today;
			return true;
		}
	}

	/**
	 * Gets the requested data via cURL
	 * @param string $url an GitHub api v3 url, e.g. a user like https://api.github.com/users/zenphoto
	 * return array
	 */
	private function getDataCurl($url) {
		$array = array();
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, "Safari");
		$r = curl_exec($ch);
		curl_close($ch);
		$array = json_decode($r, true);
		return $array;
	}

	/**
	 * Gets a raw file from a GitHub repo
	 * @param string $url Url to the single file page ("blob"), e.g. https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
	 * @param bool $convertMarkdown True if the raw file is a Markdown formatted file (e.g. README.md) and should be converted to HTML
	 * return string
	 */
	function getRawFile($url, $convertMarkdown = false) {
		global $_zp_db;
		$file = '';
		$db = $_zp_db->querySingleRow("SELECT `data` FROM " . $_zp_db->prefix('plugin_storage') . " WHERE `type` = 'zpgithub' AND `aux` = " . $_zp_db->quote($url));
		$rawurl = str_replace('/blob/', '/', $url);
		$rawurl = str_replace('https://github.com/', 'https://raw.githubusercontent.com/', $rawurl);
		if ($db) {
			if ($this->today - $this->lastupdate < $this->cache_expire) {
				$file = $db['data'];
			} else {
				$file = file_get_contents($rawurl);
				if ($convertMarkdown) {
					$file = zpGitHub::convertMarkdown($file);
				}
				$this->updateDBEntry($url, html_encodeTagged($file));
			}
		} else {
			$file = file_get_contents($rawurl);
			if ($convertMarkdown) {
				$file = zpGitHub::convertMarkdown($file);
			}
			$this->createDBEntry($url, html_encodeTagged($file));
		}
		return $file;
	}

	/**
	 * Gets a raw file ready made for printing wrapped in <pre> element with a link to the single file page
	 * No Markdown conversion!
	 * @param string $rawfile The rawfile as fetched by getRawFile()
	 * return string
	 */
	function getRawFileHTML($rawfile, $url) {
		$html = '';
		if ($rawfile) {
			$html .= '<div class="githubraw">' . "\n";
			$html .= '<p><a href="' . $url . '" target="_blank">' . gettext('View file on GitHub') . '</a></p>' . "\n";
			$html .= '<pre>' . "\n";
			$html .= '<div>' . "\n";
			$html .= html_encode($rawfile);
			$html .= '</div>' . "\n";
			$html .= '</pre>' . "\n";
			$html .= '</div>' . "\n";
		}
		return $html;
	}

	/**
	 * Gets the user base data
	 * return array
	 */
	function getUserBaseData() {
		$basedata = $this->fetchData($this->user_url);
		return $basedata;
	}

	/**
	 * Gets a list of all repos of the user
	 * return array
	 */
	function getRepos() {
		$array = $this->fetchData($this->user_allrepos_url);
		return $array;
	}

	/**
	 * Gets the data of one repo of the user, internally uses getRepos() so we always use the same base data stored once.
	 * @param string $repo the pure name of the repo
	 * return array
	 */
	function getRepo($repo) {
		$repos = $this->getRepos();
		$array = '';
		if (is_array($repos)) {
			foreach ($repos as $r) {
				if ($r['name'] == $repo) {
					$array = $r;
				}
			}
			return $array;
		}
		return array();
	}

	/**
	 * Gets the extra data chosen (tags, issues, branches, releases currently) of one repo of the user
	 * @param string $repo the pure name of the repo
	 * @param string $type What data to get: issues, tags, branches, releases (limited currently to save unneeded url request)
	 * return array
	 */
	function getRepoExtraData($repo, $type) {
		$array = array();
		$url = '';
		// Yes, this is quite limiting but to protect from unwanted url requests to GitHub as they are limited unauthorized.
		// We could use getRepo() to get the array of supported items but that of course would be 
		// an additional request as well
		switch ($type) {
			case 'tags':
			case 'issues':
			case 'branches':
			case 'releases':
				$url = $this->user_repo_baseurl . '/' . $repo . '/' . $type;
				break;
		}
		if (!empty($url)) {
			$array = $this->fetchData($url);
		}
		if (count($array) != 0) {
			return $array;
		}
	}

	/**
	 * Example function to get HTML of a list of all repos of a user that can be echoed (so it is usable as a macro later on as well)
	 * @param array $repos The repos as returned by getRepos()
	 * @param array $exclude array with repo names to exclude from the list (Example: array('repo1','repo2'))
	 * @param bool $showname True or false to show the repo name
	 * @param bool $showdesc True or false to show the short description
	 * @param bool $showmeta True or false to show meta info like last update, language and open issues
	 * @param bool $showreleases True or false to show links to the tagged releases
	 * @param bool $showbranches True or false to show links the branches
	 * @param bool $showreleasedesc True or false to show the description of each release if releases are listed
	 * return string
	 */
	function getReposListHTML($repos, $exclude = '', $showname = '', $showdesc = '', $showmeta = '', $showreleases = '', $showbranches = '', $showreleasedesc = '') {
		if (!is_array($repos)) {
			return false;
		}
		if (!is_array($exclude)) {
			$exclude = array();
		}
		if (array_key_exists('message', $repos)) { // catch error message from GitHub
			return '<p>' . $repos['message'] . '</p>';
		}
		//echo "<pre>";print_r($data); echo "</pre>";
		$html = '<ol class="githubrepos">';
		foreach ($repos as $repo) {
			if (!in_array($repo['name'], $exclude)) {
				$html .= '<li>' . $this->getRepoHTML($repo, $showname, $showdesc, $showmeta, $showreleases, $showbranches, $showreleasedesc) . '</li>';
			}
		}
		$html .= '</ol>';
		return $html;
	}

	/**
	 * Example function to get HTML for one repo that can be echoed (so it is usable as a macro later on as well)
	 * @param array $repo Array of a repo as fetched by getRepo().
	 * @param bool $showname True or false to show the repo name
	 * @param bool $showdesc True or false to show the short description
	 * @param bool $showmeta True or false to show meta info like last update, language and open issues
	 * @param bool $showreleases True or false to show links to the tagged releases
	 * @param bool $showbranches True or false to show links the branches
	 * @param bool $repolink True or false to show a link to the repo (in case you don't show the linked title)
	 * @param bool $showreleasedesc True or false to show the description of each release if releases are listed
	 * return string
	 */
	function getRepoHTML($repo, $showname = true, $showdesc = true, $showmeta = true, $showreleases = true, $showbranches = true, $repolink = true, $showreleasedesc = true) {
		$html = '';
		if (!is_array($repo)) {
			return gettext('No valid data submitted.');
		}
		$tags = array();
		$branches = array();
		if ($showname) {
			$html .= '<h3 class="repoheadline"><a href="' . $repo['html_url'] . '">' . $repo['name'] . '</a></h3>';
		}
		if ($showdesc) {
			$html .= '<p class="repodesc">' . $repo['description'] . '</p>';
		}
		if ($showmeta) {
			$issues = $repo['open_issues'];
			if ($issues != 0) {
				$issues = '<a href="http://github.com/' . $this->user . '/' . $repo['name'] . '/issues?state=open">' . $issues . '</a>';
			}
			$created = zpFormattedDate(DATE_FORMAT, strtotime($repo['created_at']));
			$lastupdate = zpFormattedDate(DATE_FORMAT, strtotime($repo['updated_at']));
			$html .= '<p class="repometadata"><span class="created">' . gettext('Created: ') . $created . '</span> | <span class="lastupdate">' . gettext('Last update: ') . $lastupdate . '</span> | <span class="language">' . gettext('Language: ') . $repo['language'] . '</span> | <span class="openissues">' . gettext('Open issues: ') . $issues . '</span></p>';
		}
		if ($showreleases) {
			$releases = $this->getRepoExtraData($repo['name'], 'releases');
			if (is_array($releases) && !array_key_exists('message', $releases)) { // catch error message from GitHub
				$html .= '<h4 class="reporeleases">' . gettext('Releases') . '</h4>';
				$html .= '<ul class="reporeleases">';
				foreach ($releases as $release) {
					$html .= '<li>';
					$published = zpFormattedDate(DATE_FORMAT, strtotime($release['published_at']));
					$html .= '<h5>' . $release['tag_name'] . ' <small>(' . $published . ')</small></h5>';
					$releasebody = $release['body']; //release description
					if ($showreleasedesc && !empty($releasebody)) {
						$html .= html_encodeTagged($this->convertMarkdown($releasebody));
					}
					$html .= '<p><a class="zipdownload" href="' . $release['zipball_url'] . '">zip</a> | <a class="tardownload" href="' . $release['tarball_url'] . '">tar</a></p>';
					$html .= '</li>';
				}
				$html .= '</ul>';
			}
		}
		if ($showbranches) {
			$branches = $this->getRepoExtraData($repo['name'], 'branches');
			if (is_array($branches) && !array_key_exists('message', $branches)) { // catch error message from GitHub
				$html .= '<h4 class="repobranches">' . gettext('Branches') . '</h4>';
				$html .= '<ul class="repobranches">';
				foreach ($branches as $branch) {
					$html .= '<li>' . $branch['name'] . ': ';
					$html .= '<a href="' . $this->user_repo_baseurl . '/' . $repo['name'] . '/zipball/' . $branch['name'] . '">zip</a> | ';
					$html .= '<a href="' . $this->user_repo_baseurl . '/' . $repo['name'] . '/tarball/' . $branch['name'] . '">tar</a>';
					$html .= '</li>';
				}
				$html .= '</ul>';
			} else {
				$html .= '<p>' . $branches['message'] . '</p>';
			}
		}
		if ($repolink) {
			$html .= '<p class="viewongithub"><a href="' . $repo['html_url'] . '">' . gettext('View on GitHub') . '</a></p>';
		}
		return $html;
	}

	/**
	 * Method to create pages for each public repository. It also adds macros for displaying the general info like releases.
	 * The pages are unpublished for further editing.
	 * @param bool $useReadme If true uses the README.md (note the cases!) file of the repo and converts Mardown to HTML (default). Otherwise the short description is used.
	 * @param bool $update True if the pages' content should  be updated (overwritten!), false if only new pages should be created.
	 * @param bool $addreleases True if the list of releases should be appended
	 * @param bool $repolink True if the repo link should be appended
	 */
	function createRepoPages($useReadme = true, $update = false, $addreleases = true, $repolink = true) {
		$repos = $this->getRepos();
		if (is_array($repos)) {
			foreach ($repos as $repo) {
				$titlelink = sanitize($repo['name']);
				$page = new ZenpagePage($titlelink, false);
				if (!$page->loaded || $update) {
					$page->setPermalink(1);
					$page->set('title', $titlelink);
					$date = str_replace(array('T', 'Z'), array(' ', ''), $repo['created_at']);
					$page->setDateTime($date);
					if ($useReadme) {
						$rmurl = 'https://github.com/' . $this->user . '/' . $repo['name'] . '/blob/master/README.md';
						$html = $this->getRawFile($rmurl, true);
						if (!$html) {
							$html = html_encode($repo['description']);
						}
					} else {
						$html = html_encode($repo['description']);
					}
					if ($repolink) {
						$html .= '<p class="viewongithub"><a href="' . $repo['html_url'] . '">' . gettext('View on GitHub') . '</a></p>';
					}
					if ($addreleases) {
						$releases = $this->getRepoExtraData($repo['name'], 'releases');
						if (is_array($releases) && !array_key_exists('message', $releases)) { // catch error message from GitHub
							$html .= '<h2>' . gettext('Releases') . '</h2>';
							$html .= '<ul class="reporeleases">';
							foreach ($releases as $release) {
								$published = zpFormattedDate(DATE_FORMAT, strtotime($release['published_at']));
								$html .= '<li>';
								$html .= '<h3>' . $release['tag_name'] . ' <small>(' . $published . ')</small></h3>';
								$releasebody = $release['body']; //release description
								if (!empty($releasebody)) {
									$html .= html_encodeTagged($this->convertMarkdown($releasebody));
								}
								$html .= '<p><a class="zipdownload" href="' . $release['zipball_url'] . '">zip</a> | <a class="tardownload" href="' . $release['tarball_url'] . '">tar</a></p>';
								$html .= '</li>';
							}
							$html .= '</ul>';
						}
					}
					$page->setContent($html);
					$page->setShow(0);
					$page->save();
				}
			}
		}
	}

	/**
	 * Method to create articles for each tagged release with the zip/tar links and 
	 * the repo short description preceeded by $releasetext as article content. 
	 * It also creates and assigns all articles  to a category with the name of the repo.
	 *
	 * Both the articles and the categories are created unpublished for further editing.
	 * As soon as the GitHub releases api is completely available more options will be added.
	 * 
	 * This will be reachable via an admin overview utility
	 * 
	 * @param bool $createcats True a category for each repository should be created and the articles assigned to them
	 * @param bool $update True if the articles' content should  be updated (overwritten!), false if only new articles and categories should be created.
	 */
	function createRepoReleaseArticles($createcats = false, $update = false) {
		global $_zp_db;
		$releasetext = getOption('zpgithub_releasetext');
		$date = date('Y-m-d H:i:s'); //The release api is not available yet so we can't use the actual date
		$repos = $this->getRepos();
		if (is_array($repos)) {
			foreach ($repos as $repo) {
				if ($createcats) {
					//create own category for this repo
					$titlelink = $repo['name'];
					$sql = 'SELECT `id` FROM ' . prefix('news_categories') . ' WHERE `titlelink`=' . $_zp_db->quote($titlelink);
					$rslt = $_zp_db->querySingleRow($sql, false);
					if (!$rslt) {
						$cat = new ZenpageCategory($titlelink, false);
						if (!$cat->loaded) {
							$cat->setPermalink(1);
							$cat->set('title', $titlelink);
							$cat->setShow(0);
							$cat->save();
						}
					}
				}
				$releases = $this->getRepoExtraData($repo['name'], 'releases');
				if (is_array($releases)) {
					foreach ($releases as $release) {
						$titlelink = sanitize($release['tag_name']);
						$article = new ZenpageNews($titlelink, false);
						if (!$article->loaded || $update) {
							$article->setTitle($titlelink);
							$html = '<p>' . html_encode($releasetext) . html_encode(sanitize($repo['description'])) . '</p>';
							$publishdate = str_replace(array('T', 'Z'), array(' ', ''), $release['published_at']);
							$releasebody = $release['body']; //release description
							if (!empty($releasebody)) {
								$html .= html_encodeTagged($this->convertMarkdown($releasebody));
							}
							$html .= '<p><a href="' . html_encode(sanitize($release['zipball_url'])) . '">zip</a> | <a href="' . html_encode(sanitize($release['tarball_url'])) . '">tar</a></p>';
							$article->setContent($html);
							$article->setShow(0);
							$article->setDateTime($publishdate);
							if ($createcats) {
								$article->setCategories(array($repo['name']));
							}
							$article->save();
						}
					}
				}
			}
		}
	}

	/**
	 * Generate the button for the overview utilities 
	 * @param array $buttons
	 * @return string
	 */
	static function overviewbuttons($buttons) {
		$buttons[] = array(
				'XSRFTag' => 'zp_github',
				'category' => gettext('Cache'),
				'enable' => true,
				'button_text' => gettext('Purge zp_github cache'),
				'formname' => 'zpgithub',
				'action' => WEBPATH . '/' . USER_PLUGIN_FOLDER . '/zp_github.php?action=clear_zpgithub_cache',
				'icon' => 'images/edit-delete.png',
				'alt' => '',
				'title' => gettext('Resets the zp_github database cache'),
				'hidden' => '<input type="hidden" name="action" value="clear_zpgithub_cache" />',
				'rights' => ADMIN_RIGHTS
		);
		if (extensionEnabled('zenpage')) {
			$buttons[] = array(
					'XSRFTag' => 'zp_github',
					'category' => gettext('Admin'),
					'enable' => true,
					'button_text' => gettext('Create GitHub items'),
					'formname' => 'zpgithub',
					'action' => WEBPATH . '/' . USER_PLUGIN_FOLDER . '/zp_github/zp_github_tab.php',
					'icon' => 'images/add.png',
					'alt' => '',
					'title' => gettext('Create Zenpage items for your GitHub repos.'),
					'hidden' => '',
					'rights' => ADMIN_RIGHTS
			);
		}
		return $buttons;
	}

	/**
	 * Get the repo info as a nested html list to print via macro or theme function
	 * @param string $user GitHub user name
	 * @param bool $showname True or false to show the repo name
	 * @param bool $showdesc True or false to show the short description
	 * @param bool $showmeta True or false to show meta info like last update, language and open issues
	 * @param bool $showreleases True or false to show links to the tagged releases
	 * @param bool $showbranches True or false to show links the branches
	 * @param array $exclude array of repo names to exclude
	 * @param bool $repolink True or false to show a link to the repo (in case you don't show the linked title)
	 * @param bool $showreleasedesc True or false to show the description of each release if releases are listed
	 * return string
	 */
	public static function getGitHub_repos($user, $showname = true, $showdesc = true, $showmeta = true, $showreleases = true, $showbranches = true, $exclude = null, $repolink = false, $showreleasedesc = true) {
		$obj = new self($user);
		$repos = $obj->getRepos();
		$html = $obj->getReposListHTML($repos, $exclude, $showname, $showdesc, $showmeta, $showreleases, $showbranches, $repolink, $showreleasedesc);
		return $html;
	}

	/**
	 * Get the repo info from a user as a nested html list to print via macro or theme function
	 * @param string $user GitHub user name
	 * @param string $repo name of the repo to get
	 * @param bool $showname True or false to show the repo name
	 * @param bool $showdesc True or false to show the short description
	 * @param bool $showmeta True or false to show meta info like last update, language and open issues
	 * @param bool $showreleases True or false to show links to the tagged releases
	 * @param bool $showbranches True or false to show links the branches
	 * @param bool $repolink True or false (default) to show a link to the repo (in case you don't show the linked title)
	 * @param bool $showreleasedesc True or false to show the description of each release if releases are listed
	 * return string
	 */
	public static function getGitHub_repo($user, $repo, $showname = true, $showdesc = true, $showmeta = true, $showreleases = true, $showbranches = true, $repolink = false, $showreleasedesc = true) {
		$obj = new self($user);
		$repo_to_get = $obj->getRepo($repo);
		$html = $obj->getRepoHTML($repo_to_get, $showname, $showdesc, $showmeta, $showreleases, $showbranches, $repolink, $showreleasedesc);
		return $html;
	}

	/**
	 * Get raw file of a GitHub stored single file ready made with html markup for printing on a page
	 * @param string $url Url to the single file page ("blob"), e.g. https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
	 * @param bool $convertMarkdown True if the raw file is a Markdown formatted file (e.g. readme.md) and should be converted to HTML, otherwise plain file wrapped in <pre><code>
	 * 
	 * return string
	 */
	public static function getGitHub_raw($url, $convertMarkdown = false) {
		$html = '';
		// get user name from the url itself to save a parameter
		$explode = explode('/', $url);
		$user = $explode[3];
		$obj = new self($user);
		if ($convertMardown) {
			$rawfile = $obj->getRawFile($url, $convertMarkdown);
			$html = $rawfile;
		} else {
			$html = $obj->getRawFileHTML($rawfile, $url);
		}
		return $html;
	}

	/**
	 * Converts Markdown formatted text into HTML using Parsedown
	 * @param string $text Markdown formatted text
	 * @return string
	 */
	public static function convertMarkdown($text) {
		$pd = new ParsedownExtra();
		$html = $pd->text($text);
		return $html;
	}

	/**
	 * GitHub macro definition
	 * @param array $macros
	 * return array
	 */
	static function zpgithub_macro($macros) {
		$macros['GITHUBREPOS'] = array(
				'class' => 'function',
				'params' => array('string', 'bool*', 'bool*', 'bool*', 'bool*', 'bool*', 'array*'),
				'value' => 'zpGitHub::getGitHub_repos',
				'owner' => 'zp_github',
				'desc' => gettext('The GitHub user to print the repos in a nested html list (%1). Optionally true or false to show name (%2), description (%3), meta info (%4), tagged release downloads (%5) and the branches (%6) and array of the names of repos to exclude from the list (%7). The macro will print html formatted data of the repo.')
		);
		$macros['GITHUBREPO'] = array(
				'class' => 'function',
				'params' => array('string', 'string', 'bool*', 'bool*', 'bool*', 'bool*', 'bool*', 'bool*', 'bool*'),
				'value' => 'zpGitHub::getGitHub_repo',
				'owner' => 'zp_github',
				'desc' => gettext('The GitHub user (%1) and the repo name to get (%2). Optionally true/false to show name (%3), description (%4), meta info (%5), tagged release downloads (%6), the branches (%7), the repo link (%8) and individual release descriptions (%9). The macro will print html formatted data of the repo.')
		);
		$macros['GITHUBRAW'] = array(
				'class' => 'function',
				'params' => array('string', 'bool*'),
				'value' => 'zpGitHub::getGitHub_raw',
				'owner' => 'zp_github',
				'desc' => gettext('Enter the url to a single file page on a GitHub repo (%1). Set (%2) to TRUE if the file is a markdown file and should be converted to HTML. Otherwise the macro returns the raw file contents (e.g. code files) wrapped in pre and code elements. Below there is always a link to the the single file page on GitHub.')
		);
		return $macros;
	}

}

// class end