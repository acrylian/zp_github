zp_github
=========

A Zenphoto plugin to read and display some info from a GitHub user and its repos via the GitHub Api. 

It also can optionally create Zenpage pages for each repository, news articles for each repository's releases and assign them to a category for each repository.

It also supports the new content macros. See the in-file comments for usage information.

NOTE: The plugin does use unauthenticated access to the GitHub API and is really only meant to show general static info like your repositories on your website and or direct links. It is not meant for advanced actions. Since the access is limited to 60 requests per hour, the plugin caches all request results in the plugin_storgage table to limit http requests to the GitHub server. Default is auto update once a day.

Place in your `/plugins` folder and enable it.

Requirements: PHP 5.3+, cURL and JSON PHP server extensions.

##NOTE: 
The plugin does use unauthenticated access to the GitHub API and is really only meant to show general static info like your repositories on your website and or direct links. It is not meant for advanced actions. Since the access is limited to 60 requests per hour, the plugin caches all request results in the plugin_storgage table to limit http requests to the GitHub server. Default is auto update once a day.

It is also not meant as a full API libary for all the sophisticated things you can do with the GitHub API. If you need that take a look at the resounces here: https://developer.github.com/libraries/
