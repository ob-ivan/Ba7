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

Bootstrap
---------

Bootstrap is what everything begins with. An entry point if you want
to get the framework as whole. If you don't, you can include each of
its utilities on its own, but beware of undocumented dependencies!

Config
------

This utility allows you to create and access immutable config files
in a simple nginx.conf-like form. See doc/Config.md for details.

Filter
------

Filter in brief is a function that takes a single argument, performs
some kind of conversion on it and returns the result. Typical
examples of filters would be: case converter, character escaper,
default value supplier (to avoid empty values). Filters can be
chained with `append` method which performs superposition.

Some filters are built-in and ready for use in Autoload path
templates. Filters can be used in other ways too, e.g. for form
validation. You can also define your own filters by putting them
into the `Ba7\Framework\Filter` namespace.
