zp_github
=========
A plugin to read and display some info from a user and its repos on GitHub. Additionally the plugin optionally 
can create Zenpage pages for each user's repo and Zenpage articles for each repo's releases/tags.
 
NOTE: The plugin does use unauthenticated access to the GitHub API and is really only meant to show general static info like your repositories on your website and or direct links. It is not meant for advanced actions. Since the access is limited to 60 requests per hour, the plugin caches all request results in the plugin_storgage table 
to limit http requests to the GitHub server. Default is auto update once a day.
  
It is also not meant as a full API libary for all the sophisticated things you can do with the GitHub API. If you need that take a look at the resounces here: https://developer.github.com/libraries/
  
Requirements: PHP 5.3+, cURL and JSON PHP server extensions.
License: GPL v3
  
It also includes the Parsedown and ParsedownExtra libaries to convert Markdown formatted text into HTML. It supports GitHub flavoured Markdown.
http://parsedown.org/https://github.com/erusev/parsedown by Emanuil Rusev http://erusev.com, 
License: MIT license
  
Usage ways:
-----------
###a) Class : 

```php
$obj = new zpGitHub('<username>');
echo $obj->getReposListHTML(); 
``

Prints a html list of all repos of the user <username>

```php 
$data = $obj->fetchData($url); 
```

Gets array info of any GitHub api url. See http://developer.github.com/v3/ for details
  
###b) Template functions: 
echo getGitHub_repos('<username>',$showtags,$showbranches,$exclude);
Prints a html list of all repos of the user <username> (like echo $obj->getReposListHTML() above would do)
<exclude> is optionally an array to exclude specific repos, e.g. array("repo1","repo2")

```php 
echo getGitHub_raw($url,$convertMarkdown);
```

Prints the raw file content of the file referenced and a link to the single file page.
<url> is the url to a GitHub single file page like:
https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
You can also have it convert markdown to HTML
 
###c) Content macros: 

``
[GITHUBREPOS <username> <reponame> <releases> <branches>]
[GITHUBREPO <username> <reponame> <releases> <branches>]
[GITHUBRAW <url> <convertMarkdown>]
```

The macros work the same as the template functions on b).
  
  
###d) Separate markdown conversion

```php
$html = zpGitHub::convertMarkdown($markdown);
```

Please also see the file comments on each method and function below for more details on the parameters and usages.


