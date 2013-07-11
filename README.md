zp_github
=========

A Zenphoto plugin to read and display some info from a user and its repos on GitHub. It also supports 
the new content macros. See the in-file comments for usage information.

Place in your `/plugins` folder and enable it.

The plugin caches all request results in the plugin_storgage table to limit http requests to the GitHub server. 
To limit db requests it is also a good idea to use the static_html_cache plugin additionally.

Requirements: PHP 5.3+, cURL and JSON PHP server extensions.
