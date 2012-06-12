Ba7
===

Getting on with Git, while writing a PHP framework for my future sites.

Ba7 is read "banana" and is dedicated to my love to bananas.

Autoload
--------

I have started off with an Autoload utility which allows you to
declare classes and their files to be loaded on request in a bit more
intelligent way than the old good `__autoload` function in PHP.
Please refer to doc/Autoload.md on what it is useful for and how to run it.

Autoload TODO list:
- Add support for class name templates with alternatives: [Utils|Database|Model]
- Add filters to path placeholders: [1|default:astract], [2|case:lower].

Config
------

This utility allows you to create and access immutable config files
in a simple nginx.conf-like form. See doc/Config.md for details.
