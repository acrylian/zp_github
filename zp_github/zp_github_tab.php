<?php
/**
 * Detailed Gallery Statistics
 *
 * This plugin shows statistical graphs and info about your gallery\'s images and albums
 *
 * This plugin is dependent on the css of the gallery_statistics utility plugin!
 *
 * @package admin
 */
define('OFFSET_PATH', 3);
require_once(dirname(dirname(dirname(__FILE__))).'/zp-core/admin-globals.php');

admin_securityChecks(ADMIN_RIGHTS, currentRelativeURL());

if (!zp_loggedin(OVERVIEW_RIGHTS)) { // prevent nefarious access to this page.
	header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?from=' . currentRelativeURL());
	exitZP();
}
$webpath = WEBPATH . '/' . ZENFOLDER . '/';

printAdminHeader('overview');
?>
<style>
  fieldset {
    width: 95%;
  }
  fieldset legend {
    font-weight: bold;
  }
</style>
</head>
<body>
	<?php printLogoAndLinks(); ?>
	<div id="main">
		<span id="top">
			<?php printTabs(); ?>
		</span>
		<div id="content">
			<?php printSubtabs(); ?>
			<div class="tabbox">
				
     <?php
     if (isset($_POST['githubuser'])) {
       XSRFdefender('create_items');
       $note = '';
       if (isset($_POST['clear-cache'])) {
         $db = query("DELETE FROM " . prefix('plugin_storage') . " WHERE `type` = 'zpgithub'");
         if ($db) {
           $note .= '<p>' . gettext('The GitHub cache has been cleared.') . '</p>';
         }
       }
       $user = sanitize($_POST['githubuser']);
       
       //one must be set or it does not make any sense!
       if (isset($_POST['pages-for-repos']) || isset($_POST['articles-for-releases'])) {
         $gh = new zpGitHub($user);
         $markdown = false;
         if (isset($_POST['convert-markdown'])) {
           $markdown = true;
         }
         if (isset($_POST['pages-for-repos'])) {
           $updatepages = false;
           if (isset($_POST['update-pages'])) {
             $updatepages = true;
           }
           $addreleases = false;
           if (isset($_POST['add-releases'])) {
             $addreleases = true;
           }
           $gh->createRepoPages($markdown, $updatepages, $addreleases, true);
           $note .= '<p>' . gettext('Pages for each repo have been created.') . '</p>';
         }
         if (isset($_POST['articles-for-releases'])) {
           $createcats = false;
           if (isset($_POST['create-cats'])) {
             $createcats = true;
           }
           $updatearticles = false;
           if (isset($_POST['update-articles'])) {
             $updatearticles = true;
           }
           $gh->createRepoReleaseArticles($createcats, $updatearticles);
           $note .= '<p>' . gettext('Articles for each release/tag of each repo have been created.') . '</p>';
         }
         echo '<div class="messagebox fade-message">' . $note . '</div>';
       }
     }
     ?> 
				<h1><?php echo gettext("Create Zenpage items for GitHub repos"); ?></h1>
				<p><?php echo gettext("Check the option you wish to create items for. Note that the content is served from cache if you have called it before. If you want fresh contents be sure to clear the cache first, but mind the access limits as this plugin uses unauthorized access."); ?></p>
    <p><?php echo gettext("All items are created unpublished for further manual editing."); ?></p>
    <form name="create-repo-items" action="zp_github_tab.php" method="post">
      <?php XSRFToken("create_items"); ?>
      <p><label for="githubuser"><input type="text" name="githubuser" id="githubuser" value=""> <strong><?php echo gettext('The GitHub user name <em>(required)</em>'); ?></strong></label></p>
      <fieldset class="githubfieldset">
        <legend><?php echo gettext("Pages for each repo"); ?></legend>
        <p><label for="pages-for-repos"><input type="checkbox" name="pages-for-repos" id="pages-for-repos" value="createpages"><?php echo gettext('Create Zenpage pages for each repo of the user'); ?></label></p> 
        <p><label for="convert-markdown"><input type="checkbox" name="convert-markdown" id="convert-markdown" value="convert-markdown"><?php echo gettext('If checked the README.md file (it must exists, mind the uppercase) of the repo is used and its markdown converted to HTML, otherwise the short description is used.'); ?></label></p>       
        <p><label for="add-releases"><input type="checkbox" name="add-releases" id="add-releases" value="add-releases"><?php echo gettext('If checked a list of the releases is appended'); ?></label></p>       
        <p><label for="update-pages"><input type="checkbox" name="update-pages" id="update-pages" value="update-pages"><?php echo gettext('If checked existing pages are updated (overwritten), otherwise only new ones are created. Note: Updates are served from cache if existing, unless you check the cache clearing below.'); ?></label></p> 
      </fieldset>
      <fieldset class="githubfieldset">
        <legend><?php echo gettext("Articles for each repo"); ?></legend>
        <p><label for="articles-for-releases"><input type="checkbox" name="articles-for-releases" id="articles-for-releases" value="createarticles"><?php echo gettext('Create Zenpage articles for the each release (tag) of every repo of the user.'); ?></label></p>
        <p><label for="update-articles"><input type="checkbox" name="update-articles" id="update-articles" value="update-articles"><?php echo gettext('If checked existing articles are updated (overwritten), otherwise only new ones are created. Note: Updates are served from cache if existing, unless you check the cache clearing below.'); ?></label></p> 
        <p><label for="create-cats"><input type="checkbox" name="create-cats" id="create-cats" value="create-cats"><?php echo gettext('If checked a category for each repo is created and the articles assigned to them.'); ?></label></p> 
      </fieldset>
      <p><label for="clear-cache"><input type="checkbox" name="clear-cache" id="clear-cache" value="clear-cache"><?php echo gettext('If checked the GitHub cache is cleared before above actions. Mind the limited access rate on GitHub, so doing this too often may cause problems.'); ?></label></p> 
      <p class="buttons"><button type="submit"><img src="<?php echo $webpath; ?>/images/add.png" alt=""><?php echo gettext("Create"); ?></button></p>
      <br style="clear:both">
    </form>
			</div>
		</div><!-- content -->
		<?php printAdminFooter(); ?>
	</div><!-- main -->
</body>
<?php echo "</html>"; ?>
