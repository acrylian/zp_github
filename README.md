zp_github
=========
A [Zenphoto](http://www.zenphoto.org) plugin to read and display some info from a user and its repos on GitHub via content macros within text content. Additionally the plugin optionally 
can create Zenpage pages for each user's repo and Zenpage articles for each repo's releases/tags.
 
NOTE: The plugin does use unauthenticated access to the GitHub API and is really only meant to show general static info like your repositories on your website and or direct links. It is not meant for advanced actions. Since the access is limited to 60 requests per hour, the plugin caches all request results in the plugin_storgage table 
to limit http requests to the GitHub server. Default is auto update once a day.
  
It is also not meant as a full API libary for all the sophisticated things you can do with the GitHub API. If you need that take a look at the resounces here: https://developer.github.com/libraries/
  
Requirements: PHP 5.3+, cURL and JSON PHP server extensions.
License: GPL v3
  
It also includes the Parsedown and ParsedownExtra libaries to convert Markdown formatted text into HTML. It supports GitHub flavoured Markdown.
http://parsedown.org/https://github.com/erusev/parsedown by Emanuil Rusev http://erusev.com, 
License: MIT license

## Installation

Put the file `zp_github.php` and the folder of the same name into your `/plugins` folder and enable it.

  
## Usage:

###On the theme:

####Class: 

```php
$obj = new zpGitHub('<username>');
echo $obj->getReposListHTML(); 
```

Prints a html list of all repos of the user `<username>`

```php 
$data = $obj->fetchData($url); 
```

Gets array info of any GitHub api url. See http://developer.github.com/v3/ for details
  
####Template functions: 

```php
echo getGitHub_repos('<username>',$showtags,$showbranches,$exclude);
```

Prints a html list of all repos of the user `<username>` (like `echo $obj->getReposListHTML()` above would do)
`<exclude>` is optionally an array to exclude specific repos, e.g. `array("repo1","repo2")`

```php 
echo getGitHub_raw($url,$convertMarkdown);
```

Prints the raw file content of the file referenced and a link to the single file page.
`<url>` is the url to a GitHub single file page like:
https://github.com/zenphoto/DevTools/blob/master/demo_plugin-and-theme/demo_plugin/zenphoto_demoplugin.php
The content is html encoded so printed as text. You can use it to convert Markdown formatted files to HTML files.
 
###Content macros: 

```
[GITHUBREPOS <username> <reponame> <releases> <branches>]
[GITHUBREPO <username> <reponame> <releases> <branches>]
[GITHUBRAW <url> <convertMarkdown>]
```

The macros work the same as the template functions on b).
  
###Utility for Zenpage items

The plugin additionally features a backend utility accessible via teh main admin overview page. This can do several things:

- Create a Zenpage page for each of your repos. You can choose what it includes like the readme file as text or a list of releases.
- Create Zenpage news articles for each release from the repo (it uses the new release API and not the tags API from GitHub so older tags might not be covered). On request the utility also create a news category per repo.

  
###Separate markdown conversion

YOu can also use it to convert Mrkdown formatted text.

```php
$html = zpGitHub::convertMarkdown($markdown);
```

So if you don't like to use the Zenphoto default text editor TinyMCE you can use it to add Markdown support to Zenphoto. That requires some theme modifications though. You would have to use above method with the plain text field contents. Here an example of the album description:

```php
echo zpGitHub::convertMarkdown(getAlbumDesc()); //Album description
echo zpGitHub::convertMarkdown(getImageDesc()); //Image description
echo zpGitHub::convertMarkdown(getPageContent()); //Zenpage page content
echo zpGitHub::convertMarkdown(getNewsContent()); //Zenpage news article content
```

If the default text editor TinyMCE is enabled there are conflicts as it already adds HTML and also encodes special chars. If you want to use Markdown to format it is recommend to disable the editor. Here an example with the content of a Zenpage page:

```php
//First remove all html tags - we allow images though here - and revert htmlspecialchar encoding
$pagecontent = htmlspecialchars_decode(strip_tags(getPageContent(), '<img>'));
echo zpGitHub::convertMarkdown($pagecontent);
```

This will also not be perfect as ZEnphoto by default does not recognize (invisible) linebreaks (`\n`) entered in text fields.

Please also see the file comments on each method and function below for more details on the parameters and usages.
